'use strict';

/*
 * Internal rulegen service. Three fixed operations:
 *   GET  /healthz
 *   POST /v1/extract   body must be empty
 *   POST /v1/sign      {"revision": "<build>", "path": "/api2/v2/users/<name>"}
 * It never accepts a URL or code from the caller. /v1/sign takes only a
 * profile path matching a strict pattern, and only answers for a build that
 * was extracted in "delegated" mode (its formula no longer matches the
 * constants scheme, so the real function is the signer). The time is set
 * here, not by the caller. No secrets, no database, no Redis.
 */

const http = require('node:http');
const { discover } = require('./discover');
const { runExtraction } = require('./extract');
const { createSigner } = require('./signer');
const proofInputs = require('../contract/proof-inputs.json');

const PORT = Number(process.env.PORT || 8080);
const OPERATION_DEADLINE_MS = 75000;
const MAX_SIGN_BODY = 512;
const SIGN_PATH_RE = /^\/api2\/v2\/users\/[A-Za-z0-9._~%-]{1,64}$/;
const REVISION_RE = /^20\d{10}-[a-f0-9]{10}$/;
// The sandbox pretends to be an ordinary desktop browser; the sign function
// reads navigator.userAgent.
const SANDBOX_UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36';

let inflight = null;
let lastGood = null;
// Delegated builds only: { revision, signer }. Survives a later extraction
// failure; replaced when a newer delegated build is extracted.
let delegated = null;

function log(event, fields) {
    process.stdout.write(`${JSON.stringify({ at: new Date().toISOString(), event, ...fields })}\n`);
}

function publicResult(result) {
    const { source, ...rest } = result;

    return rest;
}

async function extractCurrent({ discoverFn = discover, extractFn = runExtraction, signerFn = createSigner } = {}) {
    const build = await discoverFn();

    // Same build as last time: nothing new to execute.
    if (lastGood && lastGood.revision === build.revision) {
        return { ...publicResult(lastGood), cached: true };
    }

    const { appToken, chunks } = await build.load();

    const successes = [];
    let lastError = { error: 'CHUNK_NOT_FOUND', detail: 'no candidate produced a signer' };
    for (const chunk of chunks) {
        const result = await extractFn(chunk.source, proofInputs, SANDBOX_UA);
        if (result.ok) {
            successes.push({ ...result, source: chunk.source, chunk: chunk.url.split('/').pop() });
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

    const common = {
        ok: true,
        mode: found.mode,
        revision: build.revision,
        app_token: appToken,
        proof: found.proof,
        chunk: found.chunk,
        discovered_at: new Date().toISOString(),
    };

    if (found.mode === 'delegated') {
        if (delegated) {
            delegated.signer.close();
        }
        delegated = { revision: build.revision, signer: signerFn(found.source, SANDBOX_UA) };
        lastGood = { ...common, reason: found.reason };
    } else {
        lastGood = {
            ...common,
            static_param: found.static_param,
            prefix: found.prefix,
            suffix: found.suffix,
            checksum_indexes: found.checksum_indexes,
            checksum_constant: found.checksum_constant,
        };
    }

    return { ...publicResult(lastGood), cached: false };
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

async function handleSign(raw, res) {
    let body;
    try {
        body = JSON.parse(raw);
    } catch {
        send(res, 400, { ok: false, error: 'BAD_REQUEST' });

        return;
    }

    const revision = body && body.revision;
    const signPath = body && body.path;
    if (typeof revision !== 'string' || !REVISION_RE.test(revision) || typeof signPath !== 'string' || !SIGN_PATH_RE.test(signPath)) {
        send(res, 400, { ok: false, error: 'BAD_REQUEST' });

        return;
    }

    // Not loaded here (restart, or a constants-mode build): the caller asks
    // for an extraction, which reloads it.
    if (!delegated || delegated.revision !== revision) {
        send(res, 409, { ok: false, error: 'NOT_LOADED' });

        return;
    }

    try {
        const signed = await delegated.signer.sign(signPath, Date.now());
        send(res, 200, { ok: true, revision, time: signed.time, sign: signed.sign });
    } catch (e) {
        log('sign_failed', { revision, error: e.code || 'SIGN_FAILED' });
        send(res, 502, { ok: false, error: e.code || 'SIGN_FAILED' });
    }
}

function createServer(deps) {
    const server = http.createServer((req, res) => {
        if (req.method === 'GET' && req.url === '/healthz') {
            send(res, 200, { ok: true, revision: lastGood ? lastGood.revision : null, mode: lastGood ? lastGood.mode : null });

            return;
        }

        const route = req.method === 'POST' ? req.url : null;
        if (route !== '/v1/extract' && route !== '/v1/sign') {
            send(res, 404, { ok: false, error: 'NOT_FOUND' });

            return;
        }

        const chunks = [];
        let bytes = 0;
        let tooLarge = false;
        req.on('data', (chunk) => {
            bytes += chunk.length;
            if (bytes > MAX_SIGN_BODY) {
                tooLarge = true;
            } else {
                chunks.push(chunk);
            }
        });
        req.on('end', async () => {
            if (route === '/v1/sign') {
                if (tooLarge) {
                    send(res, 400, { ok: false, error: 'BODY_TOO_LARGE' });

                    return;
                }
                await handleSign(Buffer.concat(chunks).toString('utf8'), res);

                return;
            }

            if (bytes > 0) {
                send(res, 400, { ok: false, error: 'BODY_NOT_ALLOWED' });

                return;
            }

            const started = Date.now();
            try {
                const result = await extractOnce(deps);
                log('extract_ok', { revision: result.revision, mode: result.mode, cached: result.cached, chunk: result.chunk, ms: Date.now() - started });
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
    if (delegated) {
        delegated.signer.close();
    }
    delegated = null;
}

module.exports = { createServer, extractCurrent, resetState };

if (require.main === module) {
    createServer().listen(PORT, '0.0.0.0', () => log('listening', { port: PORT }));
    for (const signal of ['SIGTERM', 'SIGINT']) {
        process.on(signal, () => {
            resetState();
            process.exit(0);
        });
    }
}
