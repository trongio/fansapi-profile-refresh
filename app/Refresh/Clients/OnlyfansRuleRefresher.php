<?php

namespace App\Refresh\Clients;

use UnexpectedValueException;

/**
 * Extract -> validate -> prove -> (canary) -> activate, under one distributed
 * lock so only one refresh runs across every scheduler and worker.
 *
 * Every failure is recorded and returned; the active (last-known-good) rules
 * are touched only by a candidate that passed every gate.
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
    ) {}

    /** @return array{outcome: string, revision: ?string, error: ?string, detail: ?string, verified: ?string} */
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

    /** @return array{outcome: string, revision: ?string, error: ?string, detail: ?string, verified: ?string} */
    private function attempt(bool $canary): array
    {
        try {
            $payload = $this->rulegen->extract();
        } catch (RulegenFailed $e) {
            return $this->result('failed', null, $e->reason, $e->getMessage());
        }

        try {
            $candidate = OnlyfansRuleSet::fromArray($payload, 'rulegen');
        } catch (UnexpectedValueException $e) {
            return $this->result('failed', null, 'RULES_INVALID', $e->getMessage());
        }

        $active = $this->rules->current();
        if ($active !== null && $active->sameRulesAs($candidate)) {
            return $this->result(self::UNCHANGED, $candidate->revision);
        }

        try {
            $this->verifier->verifyProof($candidate, $payload['proof'] ?? null);
            if ($canary) {
                $this->verifier->canary($candidate);
            }
        } catch (RulegenFailed $e) {
            return $this->result('failed', $candidate->revision, $e->reason, $e->getMessage());
        }

        $verified = $canary ? 'proof+canary' : 'proof';
        $this->rules->activate($candidate, $verified);

        return $this->result(self::ACTIVATED, $candidate->revision, verified: $verified);
    }

    /** @return array{outcome: string, revision: ?string, error: ?string, detail: ?string, verified: ?string} */
    private function result(string $outcome, ?string $revision = null, ?string $error = null, ?string $detail = null, ?string $verified = null): array
    {
        return compact('outcome', 'revision', 'error', 'detail', 'verified');
    }
}
