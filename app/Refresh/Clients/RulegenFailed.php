<?php

namespace App\Refresh\Clients;

use RuntimeException;

/** Rule discovery, extraction or verification did not produce usable rules. */
final class RulegenFailed extends RuntimeException
{
    public function __construct(public readonly string $reason, string $detail)
    {
        parent::__construct(substr(preg_replace('/\s+/', ' ', $detail) ?? '', 0, 200));
    }
}
