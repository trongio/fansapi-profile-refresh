'use strict';

/*
 * Internal rulegen service. Two fixed operations, no inputs:
 *   GET  /healthz
 *   POST /v1/extract   (body must be empty)
 * It never accepts a URL, a revision or code from the caller. It holds no
 * secrets and has no access to the app's database or Redis; it only returns
 * candidate rules plus proof vectors, which PHP verifies before use.
 */

const http = require('node:http');
const { discover } = require('./discover');
const { runExtraction } = require('./extract');
const proofInputs = require('../contract/proof-inputs.json');

const PORT = Number(process.env.PORT || 8080);
const OPERATION_DEADLINE_MS = 75000;
// The sandbox pretends to be an ordinary desktop browser; the sign function
// reads navigator.userAgent.
const SANDBOX_UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36';

let inflight = null;
let lastGood = null;

function log(event, fields) {
    process.stdout.write(`${JSON.stringify({ at: new Date().toISOString(), event, ...fields })}\n`);
}

async function extractCurrent({ discoverFn = discover, extractFn = runExtraction } = {}) {
    const build = await discoverFn();

    // Same build as last time: nothing new to execute.
    if (lastGood && lastGood.revision === build.revision) {
        return { ...lastGood, cached: true };
    }

    const { appToken, chunks } = await build.load();

    const successes = [];
    let lastError = { error: 'CHUNK_NOT_FOUND', detail: 'no candidate produced a signer' };
    for (const chunk of chunks) {
        const result = await extractFn(chunk.source, proofInputs, SANDBOX_UA);
        if (result.ok) {
            successes.push({ ...result, chunk: chunk.url.split('/').pop() });
        } else if (result.error !== 'CHUNK_NOT_FOUND' || lastError.error === 'CHUNK_NOT_FOUND') {
            lastError = { error: result.error, detail: result.detail };
        }
    }

    if (successes.length === 0) {
        throw Object.assign(new Error(lastError.detail), { code: lastError.error });
    }
    if (successes.length > 1) {
        throw Object.assign(new Error(`${successes.length} chunks produced a signer`), { code: 'CHUNK_AMBIGUOUS' });
    }

    const found = successes[0];
    if (found.sentry_release && found.sentry_release !== build.revision) {
        throw Object.assign(new Error(`chunk release ${found.sentry_release} != build ${build.revision}`), { code: 'REVISION_MISMATCH' });
    }

    lastGood = {
        ok: true,
        revision: build.revision,
        app_token: appToken,
        static_param: found.static_param,
        prefix: found.prefix,
        suffix: found.suffix,
        checksum_indexes: found.checksum_indexes,
        checksum_constant: found.checksum_constant,
        proof: found.proof,
        chunk: found.chunk,
        discovered_at: new Date().toISOString(),
    };

    return { ...lastGood, cached: false };
}

function withDeadline(promise, ms) {
    let timer;
    const deadline = new Promise((_, reject) => {
        timer = setTimeout(() => reject(Object.assign(new Error(`no result in ${ms} ms`), { code: 'EXTRACT_TIMEOUT' })), ms);
    });

    return Promise.race([promise, deadline]).finally(() => clearTimeout(timer));
}

/** Single flight: concurrent callers share one discovery + extraction. */
function extractOnce(deps) {
    if (!inflight) {
        inflight = withDeadline(extractCurrent(deps), OPERATION_DEADLINE_MS).finally(() => { inflight = null; });
    }

    return inflight;
}

function send(res, status, body) {
    const json = JSON.stringify(body);
    res.writeHead(status, { 'content-type': 'application/json', 'content-length': Buffer.byteLength(json), 'cache-control': 'no-store' });
    res.end(json);
}

function createServer(deps) {
    const server = http.createServer((req, res) => {
        if (req.method === 'GET' && req.url === '/healthz') {
            send(res, 200, { ok: true, revision: lastGood ? lastGood.revision : null });

            return;
        }

        if (req.method !== 'POST' || req.url !== '/v1/extract') {
            send(res, 404, { ok: false, error: 'NOT_FOUND' });

            return;
        }

        let bytes = 0;
        req.on('data', (chunk) => { bytes += chunk.length; });
        req.on('end', async () => {
            if (bytes > 0) {
                send(res, 400, { ok: false, error: 'BODY_NOT_ALLOWED' });

                return;
            }

            const started = Date.now();
            try {
                const result = await extractOnce(deps);
                log('extract_ok', { revision: result.revision, cached: result.cached, chunk: result.chunk, ms: Date.now() - started });
                send(res, 200, result);
            } catch (e) {
                const code = e.code || 'EXTRACT_FAILED';
                log('extract_failed', { error: code, detail: String(e.message).slice(0, 200), ms: Date.now() - started });
                send(res, code === 'EXTRACT_TIMEOUT' ? 504 : 502, { ok: false, error: code, detail: String(e.message).slice(0, 200) });
            }
        });
    });

    server.requestTimeout = OPERATION_DEADLINE_MS + 10000;
    server.headersTimeout = 10000;

    return server;
}

function resetState() {
    inflight = null;
    lastGood = null;
}

module.exports = { createServer, extractCurrent, resetState };

if (require.main === module) {
    createServer().listen(PORT, '0.0.0.0', () => log('listening', { port: PORT }));
    for (const signal of ['SIGTERM', 'SIGINT']) {
        process.on(signal, () => process.exit(0));
    }
}
