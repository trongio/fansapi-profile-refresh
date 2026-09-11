<?php

namespace App\Refresh\Clients;

use App\Models\Profile;

/**
 * Private local upstream used by the workload and the tests.
 *
 * It is a real HTTP server in its own container, so the demo exercises the
 * actual transport, timeouts and streaming path rather than a faked client.
 */
class FixtureProfileClient extends BoundedHttpClient implements ProfileClient
{
    public function source(): string
    {
        return 'fixture';
    }

    public function fetch(Profile $profile): ClientResult
    {
        $base = config('fansapi.fixture.base_url');

        return $this->get(sprintf(
            '%s/profiles/%s?account=%s',
            $base,
            rawurlencode($profile->username),
            rawurlencode($profile->account->key),
        ));
    }
}
