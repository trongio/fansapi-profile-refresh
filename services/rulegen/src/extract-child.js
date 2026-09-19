'use strict';

/*
 * Runs downloaded OnlyFans signing code. This file is the only place that
 * happens. It always runs as a child process (see extract.js and signer.js)
 * under the Node permission model, with an empty environment, a heap cap and
 * a hard SIGKILL deadline. node:vm is NOT a security boundary: the process
 * and the container are.
 *
 * Two modes:
 *
 *  extract (default): stdin is one JSON document, stdout one JSON answer.
 *    Method adapted from mikigoalie/onlyfans-rulegen (MIT, see NOTICE): the
 *    webpack module runs with its SHA-1 and lodash-get dependencies stubbed.
 *      - static_param: line 1 of the hasher input ("static\ntime\npath\nuser").
 *      - prefix/suffix: parts 0 and 3 of "prefix:sha:checksum:suffix".
 *      - index multiset and constant: probe one hash position at a time.
 *    Then the REAL function signs fixed inputs with real SHA-1 (the proof).
 *    If the derived constants reproduce every proof sign, mode is
 *    "constants" and PHP signs by itself. If the formula changed so the
 *    constants cannot be derived or do not reproduce the function, but the
 *    function still signs, mode is "delegated": the function itself is the
 *    signer and rulegen serves signatures for it (serve mode below).
 *
 *  serve (--serve): line-delimited JSON. First line {source, user_agent}
 *    loads the chunk; each following line {id, path, time} is answered with
 *    {id, sign, time} computed by the real function with a frozen clock.
 */

const vm = require('node:vm');
const crypto = require('node:crypto');

const HASH_LEN = 40;
const VM_TIMEOUT_MS = 2000;
const MAX_MODULES = 64;
const PROBE_URL = '/api2/v2/probe';

function fail(code, detail) {
    const error = new Error(detail || code);
    error.code = code;
    throw error;
}

/** A Date whose "now" we control; everything else is the real Date. */
function makeClock() {
    const RealDate = Date;
    let frozen = null;
    const now = () => (frozen === null ? RealDate.now() : frozen);

    function FakeDate(...args) {
        if (!new.target) {
            return new RealDate(now()).toString();
        }

        return args.length === 0 ? new RealDate(now()) : new RealDate(...args);
    }
    FakeDate.now = now;
    FakeDate.parse = RealDate.parse;
    FakeDate.UTC = RealDate.UTC;
    FakeDate.prototype = RealDate.prototype;

    return { FakeDate, freeze: (ms) => { frozen = ms; }, unfreeze: () => { frozen = null; } };
}

function loadModules(source, userAgent, clock) {
    const sandbox = {
        window: { navigator: { userAgent } },
        navigator: { userAgent },
        Date: clock.FakeDate,
    };
    sandbox.self = sandbox;
    sandbox.global = sandbox;
    sandbox.globalThis = sandbox;

    const modules = [];
    sandbox.webpackChunkof_vue = {
        push(chunk) {
            const map = (chunk && chunk[1]) || {};
            for (const id of Object.keys(map)) {
                if (typeof map[id] === 'function' && modules.length < MAX_MODULES) {
                    modules.push(map[id]);
                }
            }
        },
    };

    vm.createContext(sandbox, { codeGeneration: { strings: true, wasm: false } });
    vm.runInContext(source, sandbox, { filename: 'of-sign-chunk.js', timeout: VM_TIMEOUT_MS });

    if (modules.length === 0) {
        fail('EXTRACT_FAILED', 'no webpack modules in chunk');
    }

    const release = sandbox.window && sandbox.window.SENTRY_RELEASE && sandbox.window.SENTRY_RELEASE.id;

    return { modules, release: typeof release === 'string' ? release : null };
}

const sha1 = (input) => crypto.createHash('sha1').update(input, 'utf8').digest('hex');

/**
 * Instantiate one module with stubbed dependencies and call its sign export.
 * hashFor(input) decides what the "SHA-1" returns for the recorded input.
 * Only requires that the export returns a non-empty string `sign`.
 */
function callSign(moduleFn, url, hashFor) {
    const hashed = [];

    const stub = (...args) => {
        if (args.length === 1 && typeof args[0] === 'string') {
            hashed.push(args[0]);

            return hashFor(args[0]);
        }
        const [obj, path, def] = args;
        if (obj == null) {
            return def;
        }
        let cur = obj;
        for (const key of String(path).split('.')) {
            if (cur == null) {
                return def;
            }
            cur = cur[key];
        }

        return cur === undefined ? def : cur;
    };

    const req = () => ({ A: {} });
    req.n = () => () => stub;

    const mod = { exports: {} };
    moduleFn(mod, mod.exports, req);
    if (!mod.exports || typeof mod.exports.A !== 'function') {
        fail('EXTRACT_FAILED', 'module does not export a sign function');
    }

    const result = mod.exports.A({ url });
    const sign = result && typeof result === 'object' && typeof result.sign === 'string' ? result.sign : null;
    if (sign === null || sign.length === 0 || sign.length > 512) {
        fail('EXTRACT_FAILED', 'sign function did not return a sign string');
    }

    return { sign, hashed, time: result.time };
}

function findSignModule(modules) {
    const found = [];
    for (const fn of modules) {
        try {
            callSign(fn, PROBE_URL, sha1);
            found.push(fn);
        } catch {
            // not the sign module
        }
    }
    if (found.length !== 1) {
        fail('CHUNK_NOT_FOUND', `expected exactly one sign module, found ${found.length}`);
    }

    return found[0];
}

/** Known formula only: "prefix:sha1(static\ntime\npath\nuser):checksum:suffix". */
function deriveConstants(moduleFn) {
    const base = callSign(moduleFn, PROBE_URL, () => 'a'.repeat(HASH_LEN));
    const parts = base.sign.split(':');
    if (parts.length !== 4 || base.hashed.length !== 1) {
        fail('FORMULA_CHANGED', 'sign is not prefix:sha:checksum:suffix over one hash');
    }
    const lines = base.hashed[0].split('\n');
    if (lines.length !== 4 || lines[2] !== PROBE_URL || lines[3] !== '0') {
        fail('FORMULA_CHANGED', 'hasher input is not static\\ntime\\npath\\nuser');
    }
    const [prefix, , , suffix] = parts;
    if (!/^[A-Za-z0-9]+$/.test(prefix) || !/^[A-Za-z0-9]+$/.test(suffix)) {
        fail('FORMULA_CHANGED', 'prefix or suffix is not alphanumeric');
    }

    const checksumOf = (hash) => {
        const { sign } = callSign(moduleFn, PROBE_URL, () => hash);
        const hex = sign.split(':')[2];
        if (!/^[0-9a-f]+$/.test(hex || '')) {
            fail('FORMULA_CHANGED', 'checksum segment is not lowercase hex');
        }

        return parseInt(hex, 16);
    };

    // 500 * 40 dwarfs any plausible constant, so abs() is the identity here.
    const baseCode = 500;
    const probeBase = String.fromCharCode(baseCode).repeat(HASH_LEN);
    const cBase = checksumOf(probeBase);
    const counts = [];
    for (let j = 0; j < HASH_LEN; j++) {
        const chars = probeBase.split('');
        chars[j] = String.fromCharCode(baseCode + 1);
        counts[j] = checksumOf(chars.join('')) - cBase;
        if (!Number.isInteger(counts[j]) || counts[j] < 0 || counts[j] > HASH_LEN) {
            fail('FORMULA_CHANGED', 'checksum is not a per-position character sum');
        }
    }
    const total = counts.reduce((a, b) => a + b, 0);
    if (total === 0) {
        fail('FORMULA_CHANGED', 'checksum ignores the hash');
    }

    const indexes = [];
    counts.forEach((count, j) => {
        for (let k = 0; k < count; k++) {
            indexes.push(j);
        }
    });

    return {
        static_param: lines[0],
        prefix,
        suffix,
        checksum_indexes: indexes,
        checksum_constant: cBase - baseCode * total,
    };
}

function formulaSign(rules, path, time) {
    const sha = sha1([rules.static_param, String(time), path, '0'].join('\n'));
    let sum = rules.checksum_constant;
    for (const i of rules.checksum_indexes) {
        sum += sha.charCodeAt(i);
    }

    return `${rules.prefix}:${sha}:${Math.abs(sum).toString(16)}:${rules.suffix}`;
}

/** The real function, real SHA-1, frozen clock. */
function realSign(moduleFn, clock, path, time) {
    clock.freeze(Number(time));
    try {
        const run = callSign(moduleFn, path, sha1);
        if (String(run.time) !== String(time)) {
            fail('EXTRACT_FAILED', 'sign function did not use the frozen clock');
        }
        // It becomes an HTTP header value.
        if (run.sign.length > 256 || !/^[\x21-\x7e]+$/.test(run.sign)) {
            fail('EXTRACT_FAILED', 'real sign is not a printable header value');
        }

        return run.sign;
    } finally {
        clock.unfreeze();
    }
}

function load(source, userAgent) {
    if (typeof source !== 'string' || source.length === 0) {
        fail('EXTRACT_FAILED', 'empty source');
    }
    const clock = makeClock();
    const { modules, release } = loadModules(source, String(userAgent || ''), clock);

    return { clock, release, moduleFn: findSignModule(modules) };
}

function extract({ source, proof_inputs: proofInputs, user_agent: userAgent }) {
    if (!Array.isArray(proofInputs) || proofInputs.length === 0) {
        fail('EXTRACT_FAILED', 'no proof inputs');
    }
    const { clock, release, moduleFn } = load(source, userAgent);

    const proof = proofInputs.map(({ path, time }) => ({
        path, time: String(time), user_id: '0', sign: realSign(moduleFn, clock, path, time),
    }));

    let constants = null;
    let reason = null;
    try {
        constants = deriveConstants(moduleFn);
        if (proof.some((p) => formulaSign(constants, p.path, p.time) !== p.sign)) {
            constants = null;
            reason = 'derived constants do not reproduce the real function';
        }
    } catch (e) {
        if (e.code !== 'FORMULA_CHANGED') {
            throw e;
        }
        reason = e.message;
    }

    return constants
        ? { ok: true, mode: 'constants', sentry_release: release, ...constants, proof }
        : { ok: true, mode: 'delegated', sentry_release: release, reason, proof };
}

module.exports = { extract, load, realSign };

function serve() {
    const readline = require('node:readline');
    const rl = readline.createInterface({ input: process.stdin });
    let signer = null;

    rl.on('line', (line) => {
        let msg;
        try {
            msg = JSON.parse(line);
        } catch {
            process.stdout.write(`${JSON.stringify({ ok: false, error: 'BAD_REQUEST' })}\n`);

            return;
        }

        try {
            if (signer === null) {
                signer = load(msg.source, msg.user_agent);
                process.stdout.write(`${JSON.stringify({ ok: true, ready: true })}\n`);

                return;
            }
            const sign = realSign(signer.moduleFn, signer.clock, String(msg.path), String(msg.time));
            process.stdout.write(`${JSON.stringify({ id: msg.id, ok: true, sign, time: String(msg.time) })}\n`);
        } catch (e) {
            const answer = signer === null ? { ready: true } : { id: msg.id };
            process.stdout.write(`${JSON.stringify({ ...answer, ok: false, error: e.code || 'SIGN_FAILED' })}\n`);
        }
    });
}

if (require.main === module) {
    if (process.argv.includes('--serve')) {
        serve();
    } else {
        const chunks = [];
        process.stdin.on('data', (c) => chunks.push(c));
        process.stdin.on('end', () => {
            let out;
            try {
                out = extract(JSON.parse(Buffer.concat(chunks).toString('utf8')));
            } catch (e) {
                out = { ok: false, error: e.code || 'EXTRACT_FAILED', detail: String(e.message).slice(0, 200) };
            }
            process.stdout.write(JSON.stringify(out));
        });
    }
}
