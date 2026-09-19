<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Refresh\Clients\OnlyfansRuleRefresher;
use App\Refresh\Clients\OnlyfansRules;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Refreshes the OnlyFans signing rules from the current web build.
 *
 * Scheduled every minute but acts only when a signature rejection asked for a
 * refresh or the periodic check is due, and (unless forced) only while an
 * OnlyFans-direct account exists. The periodic clock counts every attempt, so
 * a Cloudflare challenge is retried once per period, not once per minute.
 */
class OnlyfansRulesCommand extends Command
{
    protected $signature = 'fans:onlyfans-rules
        {--force : Refresh now, regardless of schedule}
        {--no-canary : Activate after the proof check only, skipping the live canary}';

    protected $description = 'Extract, verify and activate the current OnlyFans signing rules';

    public function handle(OnlyfansRules $rules, OnlyfansRuleRefresher $refresher): int
    {
        $requested = $rules->refreshRequested();

        if (! $this->option('force')) {
            if (! Account::query()->where('source', 'onlyfans')->exists()) {
                return self::SUCCESS;
            }

            if ($requested === null && ! $this->periodicCheckDue($rules)) {
                return self::SUCCESS;
            }
        }

        $canary = (bool) config('fansapi.onlyfans.rules_canary') && ! $this->option('no-canary');
        $result = $refresher->refresh($canary);

        $this->table(['field', 'value'], [
            ['trigger', $this->option('force') ? 'forced' : ($requested ?? 'periodic')],
            ['outcome', $result['outcome']],
            ['revision', $result['revision'] ?? '-'],
            ['verified', $result['verified'] ?? '-'],
            ['error', $result['error'] ?? '-'],
            ['detail', $result['detail'] ?? '-'],
            ['active revision', $rules->current()?->revision ?? 'none'],
        ]);

        return in_array($result['outcome'], [OnlyfansRuleRefresher::ACTIVATED, OnlyfansRuleRefresher::UNCHANGED, OnlyfansRuleRefresher::BUSY], true)
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function periodicCheckDue(OnlyfansRules $rules): bool
    {
        $attemptedAt = $rules->status()['attempted_at'] ?? null;
        if (! is_string($attemptedAt)) {
            return true;
        }

        return Carbon::parse($attemptedAt)->addMinutes((int) config('fansapi.onlyfans.rules_refresh_minutes'))->isPast();
    }
}
