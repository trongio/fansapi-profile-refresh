'use strict';

/*
 * Finds the current build's signing chunk and app token.
 *
 * Nothing on the page is trusted as a URL. The only fixed entry point is
 * https://onlyfans.com/. Script URLs are pattern-matched and then REBUILT from
 * the captured parts, so every request we make goes to
 * https://static2.onlyfans.com/static/prod/<hex>/<revision>/<name>.js.
 * Pattern adapted from mikigoalie/onlyfans-rulegen (MIT, see NOTICE).
 *
 * Cloudflare can challenge the homepage. That is reported as
 * DISCOVERY_CHALLENGED and the caller keeps its last-known-good rules. There
 * is no challenge solving and no retry loop here.
 */

const { execFile } = require('node:child_process');
const path = require('node:path');

const HOMEPAGE = 'https://onlyfans.com/';
const STATIC_ORIGIN = 'https://static2.onlyfans.com';
const MAX_HOMEPAGE_BYTES = 2 * 1024 * 1024;
const MAX_SCRIPT_BYTES = 3 * 1024 * 1024;
const MAX_CANDIDATES = 8;
const FETCH_TIMEOUT_S = 20;

const REVISION = '20\\d{10}-[a-f0-9]{10}';
const ANY_BUILD_RE = new RegExp(`https://static2\\.onlyfans\\.com/static/prod/([a-f0-9])/(${REVISION})/`, 'g');
const SIGN_CHUNK_RE = new RegExp(`https://static2\\.onlyfans\\.com/static/prod/([a-f0-9])/(${REVISION})/([a-f0-9]{4})\\.js`, 'g');
const APP_TOKEN_RE = /,\s*[A-Za-z_$]{1,3}\s*=\s*"([a-f0-9]{32})"/g;

class DiscoveryError extends Error {
    constructor(code, detail) {
        super(detail || code);
        this.code = code;
    }
}

function staticUrl(bucket, revision, name) {
    return `${STATIC_ORIGIN}/static/prod/${bucket}/${revision}/${name}.js`;
}

/*
 * The real app shell also contains "Just a moment" (a loading placeholder) and
 * Cloudflare's passive challenge-platform script, so body text alone only
 * counts as a challenge when the page references no build at all.
 */
function isChallenge(response) {
    if (response.status === 403 || response.status === 503 || /challenge/i.test(response.cfMitigated || '')) {
        return true;
    }
    ANY_BUILD_RE.lastIndex = 0;

    return /just a moment|cf-chl-/i.test(response.body.slice(0, 20000)) && !ANY_BUILD_RE.test(response.body);
}

/** Pure: homepage HTML -> the build and candidate chunk URLs, all rebuilt. */
function parseHomepage(html) {
    const builds = new Set();
    for (const m of html.matchAll(ANY_BUILD_RE)) {
        builds.add(`${m[1]}/${m[2]}`);
    }
    if (builds.size === 0) {
        throw new DiscoveryError('DISCOVERY_SHAPE', 'no static2 build referenced on the homepage');
    }
    if (builds.size > 1) {
        throw new DiscoveryError('DISCOVERY_SHAPE', `homepage references ${builds.size} builds`);
    }
    const [bucket, revision] = [...builds][0].split('/');

    const names = [];
    for (const m of html.matchAll(SIGN_CHUNK_RE)) {
        if (!names.includes(m[3])) {
            names.push(m[3]);
        }
    }
    if (names.length === 0) {
        throw new DiscoveryError('CHUNK_NOT_FOUND', 'no candidate signing chunk on the homepage');
    }
    if (names.length > MAX_CANDIDATES) {
        throw new DiscoveryError('DISCOVERY_SHAPE', `too many candidate chunks (${names.length})`);
    }

    return {
        revision,
        bucket,
        chunkUrls: names.map((name) => staticUrl(bucket, revision, name)),
        appJsUrl: staticUrl(bucket, revision, 'app'),
    };
}

/** Pure: app.js -> the single app-token literal, or a hard failure. */
function extractAppToken(appJs) {
    const found = new Set();
    for (const m of appJs.matchAll(APP_TOKEN_RE)) {
        found.add(m[1]);
    }
    if (found.size !== 1) {
        throw new DiscoveryError('APP_TOKEN_NOT_FOUND', `expected one app-token literal, found ${found.size}`);
    }

    return [...found][0];
}

/**
 * One GET through curl-impersonate. The URL is always one we built; it is
 * passed as an argument (no shell), redirects are refused and size is capped.
 */
function impersonatedGet(url, maxBytes) {
    const bin = path.join(process.env.CURL_IMPERSONATE_DIR || '/opt/curl-impersonate',
        /^curl_chrome\d+$/.test(process.env.CURL_IMPERSONATE_PROFILE || '') ? process.env.CURL_IMPERSONATE_PROFILE : 'curl_chrome146');

    const args = [
        '--silent', '--show-error',
        '--max-time', String(FETCH_TIMEOUT_S),
        '--max-filesize', String(maxBytes),
        '--max-redirs', '0',
        '--proto', '=https',
        '--cacert', '/etc/ssl/certs/ca-certificates.crt',
        '--write-out', '%{stderr}\n@@meta %{http_code} %{content_type} |%header{cf-mitigated}|',
        url,
    ];

    return new Promise((resolve, reject) => {
        execFile(bin, args, { encoding: 'utf8', maxBuffer: maxBytes + 4096, timeout: (FETCH_TIMEOUT_S + 5) * 1000, env: { PATH: '/usr/bin:/bin' } },
            (error, stdout, stderr) => {
                const meta = /@@meta (\d{3}) ([^|\n]*?) \|([^|\n]*)\|/.exec(stderr || '');
                if (!meta) {
                    reject(new DiscoveryError('DISCOVERY_FAILED', `fetch failed: ${String(error ? error.message : stderr).slice(0, 160)}`));

                    return;
                }
                resolve({ status: Number(meta[1]), contentType: meta[2], cfMitigated: meta[3], body: stdout });
            });
    });
}

async function fetchScript(fetcher, url) {
    const response = await fetcher(url, MAX_SCRIPT_BYTES);
    if (response.status !== 200 || !/javascript/i.test(response.contentType || '')) {
        throw new DiscoveryError('DISCOVERY_FAILED', `static script ${response.status} ${response.contentType}`);
    }

    return response.body;
}

/** Network half: fetch homepage, app.js and the candidate chunks. */
async function discover(fetcher = impersonatedGet) {
    const home = await fetcher(HOMEPAGE, MAX_HOMEPAGE_BYTES);
    if (isChallenge(home)) {
        throw new DiscoveryError('DISCOVERY_CHALLENGED', `homepage answered ${home.status} with a challenge`);
    }
    if (home.status !== 200) {
        throw new DiscoveryError('DISCOVERY_FAILED', `homepage answered ${home.status}`);
    }

    const build = parseHomepage(home.body);

    return {
        ...build,
        async load() {
            const appToken = extractAppToken(await fetchScript(fetcher, build.appJsUrl));
            const chunks = [];
            for (const url of build.chunkUrls) {
                chunks.push({ url, source: await fetchScript(fetcher, url) });
            }

            return { appToken, chunks };
        },
    };
}

module.exports = { discover, parseHomepage, extractAppToken, isChallenge, DiscoveryError, HOMEPAGE };
