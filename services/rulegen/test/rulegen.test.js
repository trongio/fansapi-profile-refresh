'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const { extract } = require('../src/extract-child');
const { runExtraction } = require('../src/extract');
const { parseHomepage, extractAppToken, isChallenge, discover } = require('../src/discover');
const { referenceSign } = require('../src/reference-sign');
const { createServer, resetState } = require('../src/server');

const proofInputs = require('../contract/proof-inputs.json');
const vectors = require('../contract/sign-vectors.json');
const synthetic = fs.readFileSync(path.join(__dirname, 'fixtures/synthetic-chunk.js'), 'utf8');
const UA = 'Mozilla/5.0 test';

const REV = '202609171554-a5a528bc87';
const chunkUrl = (name) => `https://static2.onlyfans.com/static/prod/f/${REV}/${name}.js`;

test('reference signer reproduces the shared cross-language vectors', () => {
    for (const { rules, vectors: list } of vectors.cases) {
        for (const v of list) {
            assert.equal(referenceSign(rules, v.path, v.time, v.user_id), v.sign);
        }
    }
});

test('extraction recovers the exact constants of the synthetic signer', () => {
    const r = extract({ source: synthetic, proof_inputs: proofInputs, user_agent: UA });

    assert.equal(r.static_param, 'SyntheticStaticParam0123456789ab');
    assert.equal(r.prefix, '12345');
    assert.equal(r.suffix, 'abcd1234');
    assert.deepEqual(r.checksum_indexes, [1, 1, 5, 9, 22, 39]);
    assert.equal(r.checksum_constant, -77);
    assert.equal(r.sentry_release, '202609010000-0123456789');
});

test('proof vectors come from the real function and match the derived rules', () => {
    const r = extract({ source: synthetic, proof_inputs: proofInputs, user_agent: UA });

    assert.equal(r.proof.length, proofInputs.length);
    r.proof.forEach((p, i) => {
        assert.equal(p.path, proofInputs[i].path);
        assert.equal(p.time, proofInputs[i].time);
        assert.equal(p.sign, referenceSign(r, p.path, p.time));
    });
});

test('a chunk without a signer is reported, not guessed', () => {
    const source = '(self.webpackChunkof_vue=self.webpackChunkof_vue||[]).push([[1],{1:function(m,e){e.A=function(){return{}}}}]);';
    assert.throws(() => extract({ source, proof_inputs: proofInputs, user_agent: UA }), { code: 'CHUNK_NOT_FOUND' });
});

test('the child process extracts the synthetic chunk', async () => {
    const r = await runExtraction(synthetic, proofInputs, UA);
    assert.equal(r.ok, true);
    assert.equal(r.checksum_constant, -77);
});

test('an endless chunk is killed at the deadline', async () => {
    const started = Date.now();
    const r = await runExtraction('(self.webpackChunkof_vue=self.webpackChunkof_vue||[]).push([[1],{1:function(m,e){e.A=function(){for(;;){}}}}]);', proofInputs, UA, 1500);
    assert.equal(r.ok, false);
    assert.equal(r.error, 'EXTRACT_TIMEOUT');
    assert.ok(Date.now() - started < 5000);
});

test('a chunk that escapes the vm still cannot write files or spawn processes', async () => {
    const marker = path.join(os.tmpdir(), `rulegen-escape-${process.pid}`);
    const source = `var p=this.constructor.constructor('return process')();
        try{p.mainModule.require('fs').writeFileSync(${JSON.stringify(marker)},'x')}catch(e){}
        try{p.mainModule.require('child_process').execSync('touch ${marker}')}catch(e){}`;
    const r = await runExtraction(source, proofInputs, UA);

    assert.equal(r.ok, false);
    assert.equal(fs.existsSync(marker), false);
});

test('homepage parsing rebuilds URLs and ignores foreign hosts', () => {
    const html = `<script src="https://evil.example/static/prod/f/${REV}/2313.js"></script>
        <script src="${chunkUrl('2313')}?x=1"></script><link href="https://static2.onlyfans.com/static/prod/f/${REV}/app.css">`;
    const build = parseHomepage(html);

    assert.equal(build.revision, REV);
    assert.deepEqual(build.chunkUrls, [chunkUrl('2313')]);
    assert.equal(build.appJsUrl, chunkUrl('app'));
});

test('homepage with two builds or no chunk is rejected', () => {
    const other = '202609180000-bbbbbbbbbb';
    assert.throws(() => parseHomepage(`${chunkUrl('2313')} https://static2.onlyfans.com/static/prod/f/${other}/x.js`), { code: 'DISCOVERY_SHAPE' });
    assert.throws(() => parseHomepage(`https://static2.onlyfans.com/static/prod/f/${REV}/app.js`), { code: 'CHUNK_NOT_FOUND' });
    assert.throws(() => parseHomepage('<html></html>'), { code: 'DISCOVERY_SHAPE' });
});

test('challenge pages are recognised', () => {
    assert.equal(isChallenge({ status: 403, body: '' }), true);
    assert.equal(isChallenge({ status: 200, cfMitigated: 'challenge', body: '' }), true);
    assert.equal(isChallenge({ status: 200, body: '<title>Just a moment...</title>' }), true);
    assert.equal(isChallenge({ status: 200, body: '<html>ok</html>' }), false);
    // The live app shell has a "Just a moment" placeholder next to its scripts.
    assert.equal(isChallenge({ status: 200, body: `<title>OnlyFans</title>Just a moment<script src="${chunkUrl('2313')}"></script>` }), false);
});

test('app token must be a single distinct literal', () => {
    assert.equal(extractAppToken(',He="33d57ade8c02dbc5a333db99ff9ae26a",x=1'), '33d57ade8c02dbc5a333db99ff9ae26a');
    assert.throws(() => extractAppToken('nothing here'), { code: 'APP_TOKEN_NOT_FOUND' });
    assert.throws(() => extractAppToken(',a="33d57ade8c02dbc5a333db99ff9ae26a",b="00000000000000000000000000000000"'), { code: 'APP_TOKEN_NOT_FOUND' });
});

test('discovery fails explicitly on a challenge and never fetches scripts', async () => {
    const seen = [];
    const fetcher = async (url) => { seen.push(url); return { status: 403, cfMitigated: 'challenge', body: 'Just a moment' }; };
    await assert.rejects(discover(fetcher), { code: 'DISCOVERY_CHALLENGED' });
    assert.deepEqual(seen, ['https://onlyfans.com/']);
});

test('discovery only requests URLs it rebuilt', async () => {
    const seen = [];
    const fetcher = async (url) => {
        seen.push(url);
        if (url === 'https://onlyfans.com/') {
            return { status: 200, body: `<script src="${chunkUrl('2313')}"></script>` };
        }
        return { status: 200, contentType: 'text/javascript', body: url.endsWith('app.js') ? ',He="33d57ade8c02dbc5a333db99ff9ae26a"' : synthetic };
    };
    const build = await discover(fetcher);
    const loaded = await build.load();

    assert.equal(loaded.appToken, '33d57ade8c02dbc5a333db99ff9ae26a');
    assert.deepEqual(seen, ['https://onlyfans.com/', chunkUrl('app'), chunkUrl('2313')]);
});

async function withServer(deps, fn) {
    resetState();
    const server = createServer(deps).listen(0, '127.0.0.1');
    await new Promise((r) => server.once('listening', r));
    const base = `http://127.0.0.1:${server.address().port}`;
    try {
        await fn(base);
    } finally {
        server.close();
    }
}

const fakeDeps = (calls) => ({
    discoverFn: async () => {
        calls.discover++;
        await new Promise((r) => setTimeout(r, 50));
        return { revision: '202609010000-0123456789', load: async () => ({ appToken: '33d57ade8c02dbc5a333db99ff9ae26a', chunks: [{ url: chunkUrl('9999'), source: synthetic }] }) };
    },
    extractFn: async (source, inputs, ua) => { calls.extract++; return extract({ source, proof_inputs: inputs, user_agent: ua }); },
});

test('server: fixed routes only, empty body only', async () => {
    const calls = { discover: 0, extract: 0 };
    await withServer(fakeDeps(calls), async (base) => {
        assert.equal((await fetch(`${base}/healthz`)).status, 200);
        assert.equal((await fetch(`${base}/v1/extract?url=https://evil.example`, { method: 'POST' })).status, 404);
        assert.equal((await fetch(`${base}/v1/extract`, { method: 'POST', body: '{"url":"x"}' })).status, 400);
        assert.equal((await fetch(`${base}/v1/extract`)).status, 404);
        assert.equal(calls.discover, 0);
    });
});

test('server: concurrent callers share one extraction, same build is cached', async () => {
    const calls = { discover: 0, extract: 0 };
    await withServer(fakeDeps(calls), async (base) => {
        const [a, b] = await Promise.all([1, 2].map(() => fetch(`${base}/v1/extract`, { method: 'POST' }).then((r) => r.json())));
        assert.equal(a.ok, true);
        assert.deepEqual(a, b);
        assert.equal(calls.discover, 1);
        assert.equal(calls.extract, 1);
        assert.equal(a.app_token, '33d57ade8c02dbc5a333db99ff9ae26a');
        assert.equal(a.proof.length, proofInputs.length);

        const again = await fetch(`${base}/v1/extract`, { method: 'POST' }).then((r) => r.json());
        assert.equal(again.cached, true);
        assert.equal(calls.extract, 1);
    });
});

test('server: failures are explicit 502s', async () => {
    const deps = { discoverFn: async () => { throw Object.assign(new Error('blocked'), { code: 'DISCOVERY_CHALLENGED' }); } };
    await withServer(deps, async (base) => {
        const res = await fetch(`${base}/v1/extract`, { method: 'POST' });
        assert.equal(res.status, 502);
        assert.equal((await res.json()).error, 'DISCOVERY_CHALLENGED');
    });
});

test('committed synthetic extraction (replayed by the PHP tests) is current', () => {
    const committed = require('../contract/synthetic-extract.json');
    assert.deepEqual(extract({ source: synthetic, proof_inputs: proofInputs, user_agent: UA }), committed);
});
