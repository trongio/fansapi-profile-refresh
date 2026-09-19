'use strict';

/*
 * Delegated signing for a build whose formula no longer matches the known
 * constants scheme. The build's real sign function stays loaded in ONE
 * long-lived child process (same sandbox flags as extraction). Each request
 * has a deadline; a hung or crashed child is killed and respawned lazily.
 */

const { spawn } = require('node:child_process');
const path = require('node:path');

const CHILD = path.join(__dirname, 'extract-child.js');
const REQUEST_DEADLINE_MS = 3000;
const MAX_LINE = 4096;

function createSigner(source, userAgent, deadlineMs = REQUEST_DEADLINE_MS) {
    let child = null;
    let ready = null;
    let buffer = '';
    let nextId = 1;
    const pending = new Map();

    function failAll(error) {
        for (const { reject, timer } of pending.values()) {
            clearTimeout(timer);
            reject(error);
        }
        pending.clear();
    }

    function kill() {
        if (child) {
            child.kill('SIGKILL');
            child = null;
            ready = null;
            buffer = '';
        }
    }

    function start() {
        child = spawn(process.execPath, [
            '--max-old-space-size=96',
            '--permission',
            `--allow-fs-read=${__dirname}`,
            CHILD,
            '--serve',
        ], { env: {}, stdio: ['pipe', 'pipe', 'ignore'] });

        const current = child;
        ready = new Promise((resolve, reject) => {
            pending.set(0, { resolve, reject, timer: setTimeout(() => { kill(); reject(Object.assign(new Error('signer did not load'), { code: 'SIGN_TIMEOUT' })); }, deadlineMs * 3) });
        });

        current.stdout.setEncoding('utf8');
        current.stdout.on('data', (data) => {
            buffer += data;
            if (buffer.length > MAX_LINE * 16) {
                kill();
                failAll(Object.assign(new Error('signer output too large'), { code: 'SIGN_FAILED' }));

                return;
            }
            let nl;
            while ((nl = buffer.indexOf('\n')) >= 0) {
                const line = buffer.slice(0, nl);
                buffer = buffer.slice(nl + 1);
                let msg;
                try {
                    msg = JSON.parse(line);
                } catch {
                    continue;
                }
                const key = msg.ready ? 0 : msg.id;
                const waiter = pending.get(key);
                if (!waiter) {
                    continue;
                }
                pending.delete(key);
                clearTimeout(waiter.timer);
                if (msg.ok) {
                    waiter.resolve(msg);
                } else {
                    waiter.reject(Object.assign(new Error(msg.error || 'SIGN_FAILED'), { code: msg.error || 'SIGN_FAILED' }));
                }
            }
        });
        current.on('close', () => {
            if (child === current) {
                child = null;
                ready = null;
                failAll(Object.assign(new Error('signer exited'), { code: 'SIGN_FAILED' }));
            }
        });
        current.stdin.on('error', () => {});
        current.stdin.write(`${JSON.stringify({ source, user_agent: userAgent })}\n`);

        return ready;
    }

    async function sign(signPath, time) {
        if (!child) {
            start();
        }
        try {
            await ready;
        } catch (e) {
            kill();
            throw e;
        }

        if (!child) {
            throw Object.assign(new Error('signer restarted'), { code: 'SIGN_FAILED' });
        }

        const id = nextId++;
        const answer = new Promise((resolve, reject) => {
            const timer = setTimeout(() => {
                pending.delete(id);
                kill();
                reject(Object.assign(new Error(`no signature in ${deadlineMs} ms`), { code: 'SIGN_TIMEOUT' }));
            }, deadlineMs);
            pending.set(id, { resolve, reject, timer });
        });
        child.stdin.write(`${JSON.stringify({ id, path: signPath, time: String(time) })}\n`);
        const msg = await answer;

        return { sign: msg.sign, time: msg.time };
    }

    return { sign, close: () => { kill(); failAll(Object.assign(new Error('signer closed'), { code: 'SIGN_FAILED' })); } };
}

module.exports = { createSigner };
