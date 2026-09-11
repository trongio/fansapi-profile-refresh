<?php

/**
 * Private fixture upstream.
 *
 * Runs as its own container under the PHP CLI server with PHP_CLI_SERVER_WORKERS
 * set, so it forks per request. That matters: if the fixture were single
 * threaded, account A's deliberately slow 2-second responses would block
 * account B inside the FIXTURE, and the workload would "prove" queue blocking
 * that never happened.
 *
 * Scenario state is one JSON file written by `php artisan fans:demo`, so the
 * same file drives the tests, the workload and the call demo.
 */

const STATE_DIR = '/var/www/html/storage/fixture';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

if ($path === '/_health') {
    respond(200, ['ok' => true]);
}

if ($path === '/_scenario' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    @mkdir(STATE_DIR, 0775, true);
    array_map('unlink', glob(STATE_DIR.'/once-*') ?: []);
    $raw = file_get_contents('php://input') ?: '{}';
    file_put_contents(STATE_DIR.'/scenario.json', $raw, LOCK_EX);
    respond(200, ['stored' => true, 'bytes' => strlen($raw)]);
}

if (! preg_match('#^/profiles/([^/]+)$#', $path, $m)) {
    respond(404, ['error' => 'not found']);
}

$username = rawurldecode($m[1]);
$account = $_GET['account'] ?? 'A';
$scenario = json_decode(@file_get_contents(STATE_DIR.'/scenario.json') ?: '{}', true) ?: [];

$spec = resolveSpec($scenario, $account, $username);

if (($spec['delay_ms'] ?? 0) > 0) {
    usleep((int) $spec['delay_ms'] * 1000);
}

emit($spec, $username);

// ---------------------------------------------------------------------------

/**
 * Rule precedence: a one-shot entry, then a per-profile rule, then the
 * account's outage window, then the account's steady-state response.
 */
function resolveSpec(array $scenario, string $account, string $username): array
{
    $elapsed = microtime(true) - (float) ($scenario['started_at'] ?? 0);

    foreach (($scenario['once'][$username] ?? []) as $index => $candidate) {
        if ($elapsed < (float) ($candidate['after_seconds'] ?? 0)) {
            continue; // e.g. "one empty 500, but only once the outage is over"
        }
        if (claimOnce($username, $index)) {
            return $candidate;
        }
    }

    if (isset($scenario['profiles'][$username])) {
        return $scenario['profiles'][$username];
    }

    $rules = $scenario['accounts'][$account] ?? [];

    if (isset($rules['outage']) && $elapsed < (float) $rules['outage']['until_seconds']) {
        return $rules['outage'];
    }

    return $rules['response'] ?? ['status' => 404];
}

/** Atomic across forked workers: exclusive create wins exactly once. */
function claimOnce(string $username, int $index): bool
{
    @mkdir(STATE_DIR, 0775, true);
    $handle = @fopen(sprintf('%s/once-%s-%d', STATE_DIR, preg_replace('/[^A-Za-z0-9_-]/', '_', $username), $index), 'x');
    if ($handle === false) {
        return false;
    }
    fclose($handle);

    return true;
}

function emit(array $spec, string $username): void
{
    $status = (int) ($spec['status'] ?? 200);
    $headers = $spec['headers'] ?? [];

    if ($status === 429 && isset($spec['retry_after'])) {
        $headers['Retry-After'] = (string) $spec['retry_after'];
    }

    if (isset($spec['raw_body'])) {
        respondRaw($status, $spec['raw_body'], $headers);
    }

    if (isset($spec['padding_bytes'])) {
        // Oversized body test: the client must cut this off while receiving it.
        respondRaw($status, json_encode([
            'revision' => $spec['revision'] ?? 11,
            'likes' => $spec['likes'] ?? 121000,
            'username' => $username,
            'padding' => str_repeat('x', (int) $spec['padding_bytes']),
        ]), $headers);
    }

    if ($status !== 200) {
        respondRaw($status, $spec['body'] ?? '', $headers);
    }

    respondRaw(200, json_encode(buildBody($spec, $username)), $headers);
}

function buildBody(array $spec, string $username): array
{
    $likes = $spec['likes'] ?? 121000;
    $revision = $spec['revision'] ?? 11;
    $identity = ['username' => $spec['username'] ?? $username, 'name' => $spec['name'] ?? $username];

    return match ($spec['format'] ?? 'nested') {
        // {"likes": 120000, "revision": 10}
        'legacy' => array_filter(['likes' => $likes, 'revision' => $revision] + $identity, fn ($v) => $v !== null),
        // {"profile": {"likes": 121000}, "revision": 11}
        'nested' => ['profile' => ['likes' => $likes] + $identity, 'revision' => $revision],
        // Both locations present and disagreeing.
        'conflict' => ['likes' => $likes, 'profile' => ['likes' => $spec['other_likes'] ?? 999] + $identity, 'revision' => $revision],
        // likes absent entirely - an invalid response, not "zero likes".
        'missing_likes' => ['revision' => $revision] + $identity,
        default => ['revision' => $revision] + $identity,
    };
}

function respond(int $status, array $body): never
{
    respondRaw($status, json_encode($body));
}

function respondRaw(int $status, string $body, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    foreach ($headers as $name => $value) {
        header($name.': '.$value);
    }
    echo $body;
    exit;
}
