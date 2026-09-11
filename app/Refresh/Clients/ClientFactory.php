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
    public function for(Account $account): ProfileClient
    {
        return $account->source === 'ofapi' ? new OfapiProfileClient : new FixtureProfileClient;
    }
}
