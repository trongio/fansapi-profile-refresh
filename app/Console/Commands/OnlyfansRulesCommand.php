<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Refresh\Clients\OnlyfansRuleRefresher;
use App\Refresh\Clients\OnlyfansRules;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Keeps the OnlyFans signer current. Scheduled every minute; each tick is
 * cheap unless there is work:
 *
 *  - refresh when a rejection asked for one, or the build watch is due
 *    (rules_refresh_minutes; a same-build check is one homepage fetch);
 *  - canary the active signer when it is due (rules_canary_minutes).
 *
 * Both run only while an OnlyFans-direct account exists (unless forced). The
 * watch clock counts every attempt, so a Cloudflare challenge is retried once
 * per period, not once per minute.
 */
class OnlyfansRulesCommand extends Command
{
    protected $signature = 'fans:onlyfans-rules
        {--force : Refresh now, regardless of schedule}
        {--canary : Run the live canary for the active signer now}
        {--no-canary : Activate after the proof check only (refused for a changed formula)}
        {--status : Show the active build, its mode, metrics and last checks}';

    protected $description = 'Watch for new OnlyFans builds, extract and verify their signer, canary the active one';

    public function handle(OnlyfansRules $rules, OnlyfansRuleRefresher $refresher): int
    {
        if ($this->option('status')) {
            $this->showStatus($rules);

            return self::SUCCESS;
        }

        $forced = $this->option('force') || $this->option('canary');
        if (! $forced && ! Account::query()->where('source', 'onlyfans')->exists()) {
            return self::SUCCESS;
        }

        $exit = self::SUCCESS;

        $requested = $rules->refreshRequested();
        if ($this->option('force') || $requested !== null || $this->due($rules->status()['attempted_at'] ?? null, 'rules_refresh_minutes')) {
            $canary = (bool) config('fansapi.onlyfans.rules_canary') && ! $this->option('no-canary');
            $result = $refresher->refresh($canary);

            $this->table(['refresh', 'value'], [
                ['trigger', $this->option('force') ? 'forced' : ($requested ?? 'build watch')],
                ['outcome', $result['outcome']],
                ['build', $result['revision'] ?? '-'],
                ['mode', $result['mode'] ?? '-'],
                ['verified', $result['verified'] ?? '-'],
                ['replayed held runs', (string) $result['replayed']],
                ['error', $result['error'] ?? '-'],
                ['detail', $result['detail'] ?? '-'],
                ['active build', $rules->current()?->revision ?? 'none'],
            ]);

            if (! in_array($result['outcome'], [OnlyfansRuleRefresher::ACTIVATED, OnlyfansRuleRefresher::UNCHANGED, OnlyfansRuleRefresher::BUSY], true)) {
                $exit = self::FAILURE;
            }
        }

        // A signer that was just verified by an activation canary needs no
        // second check in the same tick.
        $justActivated = isset($result) && $result['outcome'] === OnlyfansRuleRefresher::ACTIVATED && $result['verified'] === 'proof+canary';
        if ($this->option('canary') || (! $justActivated && $this->due($rules->canaryStatus()['checked_at'] ?? null, 'rules_canary_minutes'))) {
            $canary = $refresher->canary();
            $this->line(sprintf('canary %s on %s: %s (%s)',
                config('fansapi.onlyfans.canary_username'),
                $canary['revision'] ?? 'no build',
                $canary['ok'] ? 'ok' : 'FAILED',
                trim($canary['category'].' '.($canary['status'] ?? '')),
            ));
            if (! $canary['ok']) {
                $exit = self::FAILURE;
            }
        }

        return $exit;
    }

    private function due(?string $last, string $intervalKey): bool
    {
        if (! is_string($last)) {
            return true;
        }

        return Carbon::parse($last)->addMinutes((int) config("fansapi.onlyfans.{$intervalKey}"))->isPast();
    }

    private function showStatus(OnlyfansRules $rules): void
    {
        $active = $rules->current();
        $record = $rules->activeRecord() ?? [];
        $status = $rules->status() ?? [];
        $canary = $rules->canaryStatus() ?? [];
        $previous = $rules->previousRevision();

        $this->table(['signer', 'value'], [
            ['active build', $active?->revision ?? 'none'],
            ['mode', $active?->mode ?? '-'],
            ['verified', $record['verified'] ?? '-'],
            ['activated at', $record['activated_at'] ?? '-'],
            ['replaced build', $record['replaced'] ?? '-'],
            ['recovery time (first rejection of the old build to activation)', isset($record['recovery_seconds']) ? $record['recovery_seconds'].' s' : '-'],
            ['rejections on active build', $active ? (string) $rules->rejections($active->revision) : '-'],
            ['previous build', $previous ?? '-'],
            ['rejections on previous build', $previous ? (string) $rules->rejections($previous) : '-'],
            ['builds kept', implode(', ', $rules->builds()) ?: '-'],
            ['last refresh attempt', trim(($status['attempted_at'] ?? '-').' '.($status['outcome'] ?? '').' '.($status['error'] ?? ''))],
            ['last canary', trim(($canary['checked_at'] ?? '-').' '.(isset($canary['ok']) ? ($canary['ok'] ? 'ok' : 'FAILED '.$canary['category']) : ''))],
            ['refresh requested', $rules->refreshRequested() ?? 'no'],
        ]);
    }
}
