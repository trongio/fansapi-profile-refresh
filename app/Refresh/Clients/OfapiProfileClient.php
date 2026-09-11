<?php

namespace App\Refresh\Clients;

use App\Models\Profile;

/**
 * Live managed provider (app.onlyfansapi.com).
 *
 * The provider owns request signing and session maintenance; we did not
 * implement either. fresh=true asks it to bypass its own cache, and the
 * cache/credit provenance it returns in _meta is recorded, never logged.
 */
class OfapiProfileClient extends BoundedHttpClient implements ProfileClient
{
    public function source(): string
    {
        return 'ofapi';
    }

    public function fetch(Profile $profile): ClientResult
    {
        $base = config('fansapi.ofapi.base_url');
        $token = (string) config('fansapi.ofapi.token');
        $identifier = $profile->upstream_id ?: $profile->username;

        if ($token === '') {
            return new ClientResult(\App\Refresh\Outcome::INVALID_CREDENTIALS, null, null, 0,
                message: 'OFAPI_TOKEN is not configured');
        }

        return $this->get(
            $base.'/profiles/'.rawurlencode($identifier).'?fresh=true',
            ['Authorization' => 'Bearer '.$token],
        );
    }
}
