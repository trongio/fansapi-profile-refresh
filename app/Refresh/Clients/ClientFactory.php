<?php

namespace App\Refresh\Clients;

use App\Models\Account;

/**
 * The source is a property of the ACCOUNT (its upstream access context), so a
 * live account and a fixture account can coexist in the same database and the
 * same worker without a global switch.
 */
class ClientFactory
{
    public function __construct(private readonly OnlyfansRules $rules) {}

    public function for(Account $account): ProfileClient
    {
        return match ($account->source) {
            'onlyfans' => new OnlyfansDirectClient($this->rules),  // direct, the default live route
            'ofapi' => new OfapiProfileClient,                     // managed provider, the fallback
            default => new FixtureProfileClient,
        };
    }
}
