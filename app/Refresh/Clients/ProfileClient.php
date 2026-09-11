<?php

namespace App\Refresh\Clients;

use App\Models\Profile;

/** Transport + envelope mapping for one profile source. */
interface ProfileClient
{
    public function fetch(Profile $profile): ClientResult;

    /** "ofapi" or "fixture" - drives the normalizer and the UI mode badge. */
    public function source(): string;
}
