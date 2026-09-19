<?php

namespace App\Refresh\Clients;

use App\Enums\RunStatus;
use App\Models\RefreshRun;
use App\Refresh\DeadLetters;
use App\Refresh\Outcome;
use UnexpectedValueException;

/**
 * The rotation loop for the direct route:
 *
 *   detect   refresh(): rulegen reads the current build id (x-of-rev) from
 *            the homepage; unchanged builds stop here
 *   lift     rulegen runs the build's sign function in its sandbox
 *   verify   proof vectors, then a live canary for a known profile
 *   publish  the signer is stored under its build and becomes active
 *   replay   runs held in the dead-letter queue for signature_rejected are
 *            replayed once against the new build
 *   canary   canary(): the active signer is re-checked every few minutes, so
 *            a rotation is noticed before real jobs fail
 *
 * One distributed lock keeps it to one refresh at a time. Every failure is
 * recorded; the active (last-known-good) signer is touched only by a
 * candidate that passed every gate.
 */
class OnlyfansRuleRefresher
{
    public const ACTIVATED = 'activated';

    public const UNCHANGED = 'unchanged';

    public const BUSY = 'busy';

    public function __construct(
        private readonly OnlyfansRules $rules,
        private readonly OnlyfansRulegenClient $rulegen,
        private readonly OnlyfansRuleVerifier $verifier,
        private readonly DeadLetters $deadLetters,
    ) {}

    /** @return array{outcome: string, revision: ?string, mode: ?string, error: ?string, detail: ?string, verified: ?string, replayed: int} */
    public function refresh(bool $canary): array
    {
        $lock = cache()->lock(OnlyfansRules::LOCK_KEY, (int) config('fansapi.onlyfans.rulegen_timeout_seconds') + 60);
        if (! $lock->get()) {
            return $this->result(self::BUSY);
        }

        try {
            $this->rules->clearRefreshRequest();
            $result = $this->attempt($canary);
            $this->rules->recordStatus($result + ['active_revision' => $this->rules->current()?->revision]);

            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * Recurring live check of the ACTIVE signer. A rejection counts against
     * the build and asks for a refresh right away.
     *
     * @return array{ok: bool, revision: ?string, category: string, status: ?int}
     */
    public function canary(): array
    {
        $rules = $this->rules->current();
        // No signer yet: the build watch is already retrying on its own clock;
        // asking again here would only hit a blocked homepage more often.
        if ($rules === null) {
            $result = ['ok' => false, 'revision' => null, 'category' => 'no_active_signer', 'status' => null];
            $this->rules->recordCanary($result);

            return $result;
        }

        $probe = $this->verifier->probe($rules);
        $result = [
            'ok' => $this->verifier->accepted($probe),
            'revision' => $rules->revision,
            'category' => $probe->category === 'ok' && ! $this->verifier->accepted($probe) ? 'unexpected_profile' : $probe->category,
            'status' => $probe->status,
        ];

        if (OnlyfansRuleVerifier::rejected($probe)) {
            $this->rules->recordRejection($rules->revision);
            $this->rules->forgetBrowserCode();
            $this->rules->requestRefresh("canary rejected {$rules->revision}");
        }

        $this->rules->recordCanary($result);

        return $result;
    }

    /** @return array{outcome: string, revision: ?string, mode: ?string, error: ?string, detail: ?string, verified: ?string, replayed: int} */
    private function attempt(bool $canary): array
    {
        try {
            $payload = $this->rulegen->extract();
        } catch (RulegenFailed $e) {
            return $this->result('failed', null, null, $e->reason, $e->getMessage());
        }

        try {
            $candidate = OnlyfansRuleSet::fromArray($payload, 'rulegen');
        } catch (UnexpectedValueException $e) {
            return $this->result('failed', null, null, 'RULES_INVALID', $e->getMessage());
        }

        $active = $this->rules->current();
        if ($active !== null && $active->sameRulesAs($candidate)) {
            return $this->result(self::UNCHANGED, $candidate->revision, $candidate->mode);
        }

        // A delegated signer cannot be recomputed in PHP, so only a live 200
        // can prove it.
        if ($candidate->delegated() && ! $canary) {
            return $this->result('failed', $candidate->revision, $candidate->mode, 'CANARY_REQUIRED',
                'the build changed its signing formula; a delegated signer is activated only after a live canary');
        }

        try {
            $this->verifier->verifyProof($candidate, $payload['proof'] ?? null);
            if ($canary) {
                $this->verifier->canary($candidate);
            }
        } catch (RulegenFailed $e) {
            return $this->result('failed', $candidate->revision, $candidate->mode, $e->reason, $e->getMessage());
        }

        $verified = $canary ? 'proof+canary' : 'proof';
        $this->rules->activate($candidate, $verified);

        $replayed = $active === null || $active->revision !== $candidate->revision
            ? $this->replayRejected()
            : 0;

        return $this->result(self::ACTIVATED, $candidate->revision, $candidate->mode, verified: $verified, replayed: $replayed);
    }

    /**
     * Held runs that failed only because the signer was stale are replayed
     * once, through the ordinary dead-letter replay (a new linked run, the
     * original record kept). Bounded per activation.
     */
    private function replayRejected(): int
    {
        $replayed = 0;

        $held = RefreshRun::query()
            ->whereIn('status', RunStatus::deadLetterable())
            ->where('outcome_category', Outcome::SIGNATURE_REJECTED)
            ->whereNull('resolved_at')
            ->whereDoesntHave('replays')
            ->whereHas('account', fn ($q) => $q->where('source', 'onlyfans'))
            ->orderBy('id')
            ->limit((int) config('fansapi.onlyfans.auto_replay_limit'))
            ->get();

        foreach ($held as $run) {
            if ($this->deadLetters->replay($run)['run'] !== null) {
                $replayed++;
            }
        }

        return $replayed;
    }

    /** @return array{outcome: string, revision: ?string, mode: ?string, error: ?string, detail: ?string, verified: ?string, replayed: int} */
    private function result(string $outcome, ?string $revision = null, ?string $mode = null, ?string $error = null, ?string $detail = null, ?string $verified = null, int $replayed = 0): array
    {
        return compact('outcome', 'revision', 'mode', 'error', 'detail', 'verified', 'replayed');
    }
}
