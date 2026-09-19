'use strict';

/*
 * Runs ONE downloaded OnlyFans signing chunk and reports the signing rules.
 *
 * This file is the only place downloaded JavaScript is ever evaluated. It runs
 * as a short-lived child process (see extract.js) under the Node permission
 * model, with an empty environment, a heap cap and a hard SIGKILL deadline.
 * node:vm is NOT a security boundary: the process and the container are.
 *
 * Method adapted from mikigoalie/onlyfans-rulegen (MIT, see NOTICE): the
 * webpack module is executed with its SHA-1 and lodash-get dependencies
 * stubbed, so the hash string is under our control.
 *   - static_param: line 1 of the hasher input ("static\ntime\npath\nuser").
 *   - prefix/suffix: parts 0 and 3 of "prefix:sha:checksum:suffix".
 *   - index multiset and constant: the checksum is
 *     abs(sum(hash[i].charCodeAt(0)) + C); probing one hash position at a time
 *     with a code point one higher reveals how often that position is used.
 * Then the REAL function is run with real SHA-1 on fixed inputs; those signs
 * are the proof vectors PHP recomputes before it activates anything.
 *
 * stdin:  {"source": "<chunk js>", "proof_inputs": [{"path","time"}...], "user_agent": "..."}
 * stdout: {"ok": true, ...rules, "proof": [...]} or {"ok": false, "error": "..."}
 */

const vm = require('node:vm');
const crypto = require('node:crypto');

const HASH_LEN = 40;
const VM_TIMEOUT_MS = 2000;
const MAX_MODULES = 64;

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

/**
 * Instantiate one module with stubbed dependencies and call its sign export.
 * hashFor(input) decides what the "SHA-1" returns for the recorded input.
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
    if (sign === null || sign.split(':').length !== 4) {
        fail('EXTRACT_FAILED', 'sign function did not return prefix:sha:checksum:suffix');
    }
    if (hashed.length !== 1) {
        fail('EXTRACT_FAILED', 'sign function must hash exactly once');
    }

    return { sign, input: hashed[0], time: result.time };
}

function checksumOf(moduleFn, hash) {
    const { sign } = callSign(moduleFn, '/api2/v2/probe', () => hash);
    const hex = sign.split(':')[2];
    if (!/^[0-9a-f]+$/.test(hex)) {
        fail('EXTRACT_FAILED', 'checksum segment is not lowercase hex');
    }

    return parseInt(hex, 16);
}

function probeChecksum(moduleFn) {
    // 500 * 40 dwarfs any plausible constant, so abs() is the identity here.
    const baseCode = 500;
    const base = String.fromCharCode(baseCode).repeat(HASH_LEN);
    const cBase = checksumOf(moduleFn, base);

    const counts = [];
    for (let j = 0; j < HASH_LEN; j++) {
        const chars = base.split('');
        chars[j] = String.fromCharCode(baseCode + 1);
        counts[j] = checksumOf(moduleFn, chars.join('')) - cBase;
        if (!Number.isInteger(counts[j]) || counts[j] < 0 || counts[j] > HASH_LEN) {
            fail('EXTRACT_FAILED', 'checksum is not a per-position character sum');
        }
    }

    const total = counts.reduce((a, b) => a + b, 0);
    if (total === 0) {
        fail('EXTRACT_FAILED', 'checksum ignores the hash');
    }
    const constant = cBase - baseCode * total;

    const indexes = [];
    counts.forEach((count, j) => {
        for (let k = 0; k < count; k++) {
            indexes.push(j);
        }
    });

    return { indexes, constant };
}

function findSignModule(modules) {
    const found = [];
    for (const fn of modules) {
        try {
            callSign(fn, '/api2/v2/probe', () => 'a'.repeat(HASH_LEN));
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

function extract({ source, proof_inputs: proofInputs, user_agent: userAgent }) {
    if (typeof source !== 'string' || source.length === 0) {
        fail('EXTRACT_FAILED', 'empty source');
    }
    if (!Array.isArray(proofInputs) || proofInputs.length === 0) {
        fail('EXTRACT_FAILED', 'no proof inputs');
    }

    const clock = makeClock();
    const { modules, release } = loadModules(source, String(userAgent || ''), clock);
    const moduleFn = findSignModule(modules);

    const probe = callSign(moduleFn, '/api2/v2/probe', () => 'a'.repeat(HASH_LEN));
    const [prefix, , , suffix] = probe.sign.split(':');
    const lines = probe.input.split('\n');
    if (lines.length !== 4 || lines[2] !== '/api2/v2/probe' || lines[3] !== '0') {
        fail('EXTRACT_FAILED', 'hasher input is not static\\ntime\\npath\\nuser');
    }
    const staticParam = lines[0];

    const { indexes, constant } = probeChecksum(moduleFn);

    // Proof: the real function, real SHA-1, fixed clock, fixed inputs.
    const sha1 = (input) => crypto.createHash('sha1').update(input, 'utf8').digest('hex');
    const proof = proofInputs.map(({ path, time }) => {
        clock.freeze(Number(time));
        const run = callSign(moduleFn, path, sha1);
        clock.unfreeze();

        if (run.input !== [staticParam, String(time), path, '0'].join('\n')) {
            fail('EXTRACT_FAILED', 'proof run hashed an unexpected input');
        }
        if (String(run.time) !== String(time)) {
            fail('EXTRACT_FAILED', 'sign function did not use the frozen clock');
        }

        return { path, time: String(time), user_id: '0', sign: run.sign };
    });

    return {
        ok: true,
        sentry_release: release,
        static_param: staticParam,
        prefix,
        suffix,
        checksum_indexes: indexes,
        checksum_constant: constant,
        proof,
    };
}

module.exports = { extract };

if (require.main === module) {
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
