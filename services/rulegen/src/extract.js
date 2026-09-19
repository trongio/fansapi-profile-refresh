'use strict';

/*
 * Parent side of extraction: never evaluates downloaded code itself. Each
 * chunk goes to a fresh child process with no environment, a heap cap, the
 * Node permission model (read access to this directory only, no child
 * processes, no workers, no writes) and a SIGKILL deadline.
 */

const { spawn } = require('node:child_process');
const path = require('node:path');

const CHILD = path.join(__dirname, 'extract-child.js');
const DEADLINE_MS = 15000;
const MAX_STDOUT = 256 * 1024;

function runExtraction(source, proofInputs, userAgent, deadlineMs = DEADLINE_MS) {
    return new Promise((resolve) => {
        const child = spawn(process.execPath, [
            '--max-old-space-size=96',
            '--permission',
            `--allow-fs-read=${__dirname}`,
            CHILD,
        ], { env: {}, stdio: ['pipe', 'pipe', 'ignore'] });

        let out = '';
        let settled = false;
        const finish = (value) => {
            if (!settled) {
                settled = true;
                clearTimeout(timer);
                resolve(value);
            }
        };

        const timer = setTimeout(() => {
            child.kill('SIGKILL');
            finish({ ok: false, error: 'EXTRACT_TIMEOUT', detail: `killed after ${deadlineMs} ms` });
        }, deadlineMs);

        child.stdout.setEncoding('utf8');
        child.stdout.on('data', (chunk) => {
            out += chunk;
            if (out.length > MAX_STDOUT) {
                child.kill('SIGKILL');
                finish({ ok: false, error: 'EXTRACT_FAILED', detail: 'child output too large' });
            }
        });
        child.on('error', () => finish({ ok: false, error: 'EXTRACT_FAILED', detail: 'could not start child' }));
        child.on('close', (code, signal) => {
            try {
                finish(JSON.parse(out));
            } catch {
                finish({ ok: false, error: 'EXTRACT_FAILED', detail: `child exited ${code ?? signal} without a result` });
            }
        });

        child.stdin.on('error', () => {});
        child.stdin.end(JSON.stringify({ source, proof_inputs: proofInputs, user_agent: userAgent }));
    });
}

module.exports = { runExtraction };
