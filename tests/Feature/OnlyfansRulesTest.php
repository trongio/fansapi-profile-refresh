<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Jobs\RefreshProfileJob;
use App\Models\Profile;
use App\Models\RefreshRun;
use App\Refresh\Clients\ClientFactory;
use App\Refresh\Clients\OnlyfansRuleRefresher;
use App\Refresh\Clients\OnlyfansRules;
use App\Refresh\Clients\OnlyfansRuleSet;
use App\Refresh\Clients\OnlyfansSigner;
use App\Refresh\Outcome;
use App\Refresh\RefreshDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Locally extracted signing rules: validation, the cross-language proof, the
 * canary, last-known-good retention, single flight, the scheduled command,
 * and the one-retry rule for rejected profile requests.
 *
 * The rulegen answer replayed here is the committed output of the Node
 * extractor run on the synthetic chunk (services/rulegen/contract), so the
 * proof check really compares Node-produced signs with the PHP signer.
 */
class OnlyfansRulesTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_REVISION = '202609010000-0123456789';

    private const OLD = [
        'revision' => '202608010000-aaaaaaaaaa',
        'app_token' => '33d57ade8c02dbc5a333db99ff9ae26a',
        'static_param' => 'OldStaticParam',
        'prefix' => '11111',
        'suffix' => 'deadbeef',
        'checksum_indexes' => [0, 1, 2],
        'checksum_constant' => 5,
    ];

    private const REJECTED = ['error' => ['code' => 401, 'message' => 'Please refresh the page']];

    private const PROFILE = ['id' => 5140520, 'username' => 'madison420ivy', 'favoritedCount' => 606831];

    protected function setUp(): void
    {
        parent::setUp();

        // The test cache database holds nothing else worth keeping; build
        // keys, metrics and locks from other tests must not leak in.
        Cache::flush();
        // Locks live on the lock connection, which flush() does not touch.
        Cache::lock(OnlyfansRules::LOCK_KEY)->forceRelease();
        config(['fansapi.onlyfans.signature_retry_delay_seconds' => 0]);
    }

    /** @return array<string, mixed> */
    private function extraction(array $override = []): array
    {
        $extracted = json_decode((string) file_get_contents(base_path('services/rulegen/contract/synthetic-extract.json')), true);

        return $override + [
            'ok' => true,
            'revision' => self::NEW_REVISION,
            'app_token' => '33d57ade8c02dbc5a333db99ff9ae26a',
            'chunk' => '9999.js',
            'cached' => false,
        ] + $extracted;
    }

    private function enqueue(Profile $profile): RefreshRun
    {
        $run = app(RefreshDispatcher::class)->enqueue($profile, 'cli');
        // Run ids restart when the test database is rebuilt; the cache does not.
        Cache::forget(RefreshProfileJob::signatureRetryKey($run->id));

        return $run;
    }

    /** @return array{0: mixed, 1: ?array<string, mixed>} the pointer and the build record it names */
    private function activeSnapshot(): array
    {
        return [Cache::get(OnlyfansRules::ACTIVE_KEY), app(OnlyfansRules::class)->activeRecord()];
    }

    private function activateOld(): void
    {
        app(OnlyfansRules::class)->activate(OnlyfansRuleSet::fromArray(self::OLD, 'rulegen'), 'proof+canary');
    }

    /**
     * Each argument is a factory (or a sequence): a streamed body can be read
     * once, so a stub that answers twice must build a fresh response.
     */
    private function fakeUpstream(\Closure $rulegen, mixed $api = null): void
    {
        Http::fake([
            'rulegen:8080/*' => $rulegen,
            'cdn2.onlyfans.com/key/*' => fn () => Http::response(str_repeat('ab', 20)),
            'onlyfans.com/api2/*' => $api ?? fn () => Http::response(self::PROFILE),
        ]);
    }

    #[Test]
    public function a_proven_and_canaried_extraction_is_activated(): void
    {
        $this->activateOld();
        $this->fakeUpstream(fn () => Http::response($this->extraction()));

        $result = app(OnlyfansRuleRefresher::class)->refresh(canary: true);

        $this->assertSame('activated', $result['outcome']);
        $this->assertSame('proof+canary', $result['verified']);
        $rules = app(OnlyfansRules::class)->current();
        $this->assertSame(self::NEW_REVISION, $rules->revision);
        $this->assertSame(-77, $rules->checksumConstant);
        $this->assertSame(self::OLD['revision'], app(OnlyfansRules::class)->previousRevision());
        $this->assertSame(self::NEW_REVISION, Cache::get(OnlyfansRules::ACTIVE_KEY), 'active is a pointer to the build');
        $this->assertSame('proof+canary', app(OnlyfansRules::class)->buildRecord(self::NEW_REVISION)['verified']);

        // The canary is signed in PHP, like every profile request.
        Http::assertSent(function (Request $request) use ($rules): bool {
            if (! str_contains($request->url(), '/api2/v2/users/madison420ivy')) {
                return false;
            }
            $expected = OnlyfansSigner::headers($rules->signatureRules(), '/api2/v2/users/madison420ivy', '0', (int) $request->header('time')[0]);

            return $request->header('sign')[0] === $expected['sign']
                && $request->header('app-token')[0] === '33d57ade8c02dbc5a333db99ff9ae26a'
                && $request->header('x-bc')[0] === str_repeat('ab', 20)
                && ! $request->hasHeader('user-id')
                && ! $request->hasHeader('x-hash')
                && ! $request->hasHeader('x-of-rev');
        });
    }

    /** @return array<string, array{string}> */
    public static function failures(): array
    {
        return [
            'cloudflare challenge' => ['DISCOVERY_CHALLENGED'],
            'malformed answer' => ['RULEGEN_MALFORMED'],
            'invalid rules' => ['RULES_INVALID'],
            'tampered constant' => ['PROOF_MISMATCH'],
            'swapped proof input' => ['PROOF_MISMATCH:input'],
            'canary rejected' => ['CANARY_FAILED'],
        ];
    }

    #[Test]
    #[DataProvider('failures')]
    public function every_failure_keeps_the_last_known_good_rules(string $case): void
    {
        $this->activateOld();
        $before = $this->activeSnapshot();

        $proof = $this->extraction()['proof'];
        $proof[0]['path'] = '/api2/v2/users/other';

        [$rulegen, $api] = match ($case) {
            'DISCOVERY_CHALLENGED' => [Http::response(['ok' => false, 'error' => 'DISCOVERY_CHALLENGED', 'detail' => 'homepage answered 403'], 502), null],
            'RULEGEN_MALFORMED' => [Http::response('<html>oops</html>', 200), null],
            'RULES_INVALID' => [Http::response($this->extraction(['prefix' => '12:345'])), null],
            'PROOF_MISMATCH' => [Http::response($this->extraction(['checksum_constant' => -76])), null],
            'PROOF_MISMATCH:input' => [Http::response($this->extraction(['proof' => $proof])), null],
            'CANARY_FAILED' => [Http::response($this->extraction()), fn () => Http::response(self::REJECTED, 400)],
        };
        $this->fakeUpstream(fn () => $rulegen, $api);

        $result = app(OnlyfansRuleRefresher::class)->refresh(canary: true);

        $this->assertSame('failed', $result['outcome']);
        $this->assertSame(explode(':', $case)[0], $result['error']);
        $this->assertSame($before, $this->activeSnapshot(), 'last-known-good must be untouched');
        $this->assertSame($result['error'], app(OnlyfansRules::class)->status()['error']);
    }

    #[Test]
    public function the_same_build_is_unchanged_and_not_canaried_again(): void
    {
        $payload = $this->extraction();
        app(OnlyfansRules::class)->activate(OnlyfansRuleSet::fromArray($payload, 'rulegen'), 'proof+canary');
        $this->fakeUpstream(fn () => Http::response($payload));

        $this->assertSame('unchanged', app(OnlyfansRuleRefresher::class)->refresh(canary: true)['outcome']);
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/api2/'));
    }

    #[Test]
    public function a_refresh_already_in_flight_is_not_duplicated(): void
    {
        $this->fakeUpstream(fn () => Http::response($this->extraction()));
        Cache::lock(OnlyfansRules::LOCK_KEY, 60)->get();

        $this->assertSame('busy', app(OnlyfansRuleRefresher::class)->refresh(canary: true)['outcome']);
        Http::assertNothingSent();
    }

    #[Test]
    public function the_scheduled_command_only_acts_when_due_or_requested(): void
    {
        $this->fakeUpstream(fn () => Http::response(['ok' => false, 'error' => 'DISCOVERY_CHALLENGED'], 502));

        // No OnlyFans-direct account: no outbound traffic at all.
        $this->artisan('fans:onlyfans-rules')->assertExitCode(0);
        Http::assertNothingSent();

        $this->account('A', ['source' => 'onlyfans']);

        // First run is due; a challenge is an explicit failure.
        $this->artisan('fans:onlyfans-rules')->assertExitCode(1);
        Http::assertSentCount(1);

        // Within the period and nothing requested: the homepage is not hit again.
        $this->artisan('fans:onlyfans-rules')->assertExitCode(0);
        Http::assertSentCount(1);

        // A rejection asks for a refresh; the next tick runs it.
        app(OnlyfansRules::class)->requestRefresh('signature rejected');
        $this->artisan('fans:onlyfans-rules')->assertExitCode(1);
        Http::assertSentCount(2);
        $this->assertNull(app(OnlyfansRules::class)->refreshRequested());
    }

    #[Test]
    public function a_rejection_keeps_the_rules_and_requests_one_refresh(): void
    {
        $this->activateOld();
        $before = $this->activeSnapshot();
        $this->fakeUpstream(fn () => Http::response($this->extraction()), fn () => Http::response(self::REJECTED, 400));
        $account = $this->account('A', ['source' => 'onlyfans']);
        $profile = $this->profile($account, 'madison420ivy');
        $client = app(ClientFactory::class)->for($account);

        $result = $client->fetch($profile);

        $this->assertSame(Outcome::SIGNATURE_REJECTED, $result->category);
        $this->assertSame(self::OLD['revision'], $result->signingRevision);
        $this->assertSame($before, $this->activeSnapshot());
        $this->assertFalse(Cache::has(OnlyfansRules::DEVICE_KEY), 'x-bc is refetched after a rejection');
        $this->assertNotNull(app(OnlyfansRules::class)->refreshRequested());

        // A burst of rejections inside the cooldown asks only once.
        $this->assertFalse(app(OnlyfansRules::class)->requestRefresh('again'));
    }

    #[Test]
    public function a_stale_non_cdn_browser_code_is_replaced(): void
    {
        // What the previous build left in Redis: a random id, no TTL.
        Cache::forever(OnlyfansRules::DEVICE_KEY, 'xoepfvitehhhcbzvesf5omppks2kqbgvvcshjilv');
        $this->fakeUpstream(fn () => Http::response($this->extraction()));

        $this->assertSame(str_repeat('ab', 20), app(OnlyfansRules::class)->browserCode());
        $this->assertSame(str_repeat('ab', 20), Cache::get(OnlyfansRules::DEVICE_KEY));
    }

    #[Test]
    public function a_rejected_run_is_not_retried_without_a_newer_revision(): void
    {
        $this->activateOld();
        $this->fakeUpstream(fn () => Http::response($this->extraction()), fn () => Http::response(self::REJECTED, 400));
        $run = $this->enqueue($this->profile($this->account('A', ['source' => 'onlyfans']), 'madison420ivy'));

        $this->workOnce('refresh-a');
        $this->assertSame(RunStatus::Queued, $run->fresh()->status);
        $this->assertSame(Outcome::SIGNATURE_REJECTED, $run->fresh()->outcome_category);

        $this->workOnce('refresh-a');
        $run->refresh();
        $this->assertSame(Outcome::SIGNATURE_REJECTED, $run->outcome_category);
        $this->assertNotSame(RunStatus::Queued, $run->status);
        $this->assertSame(1, $run->requests_used, 'the retry spent no upstream request');
    }

    #[Test]
    public function a_rejected_run_is_retried_once_after_a_newer_revision_activates(): void
    {
        $this->activateOld();
        $this->fakeUpstream(
            fn () => Http::response($this->extraction()),
            Http::sequence()
                ->push(self::REJECTED, 400)
                ->push(['favoritedCount' => 4321] + self::PROFILE),
        );
        $profile = $this->profile($this->account('A', ['source' => 'onlyfans']), 'madison420ivy');
        $run = $this->enqueue($profile);

        $this->workOnce('refresh-a');
        $this->assertSame(RunStatus::Queued, $run->fresh()->status);

        // What the scheduler does after the rejection asked for a refresh.
        $this->assertSame('activated', app(OnlyfansRuleRefresher::class)->refresh(canary: false)['outcome']);

        $this->workOnce('refresh-a');
        $run->refresh();
        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertSame(2, $run->requests_used);
        $this->assertSame(4321, $profile->fresh()->likes);
    }

    /** @return array<string, mixed> */
    private function delegatedExtraction(): array
    {
        $extracted = json_decode((string) file_get_contents(base_path('services/rulegen/contract/synthetic-extract-delegated.json')), true);

        return [
            'ok' => true,
            'revision' => self::NEW_REVISION,
            'app_token' => '33d57ade8c02dbc5a333db99ff9ae26a',
            'chunk' => '9999.js',
            'cached' => false,
        ] + $extracted;
    }

    private function fakeDelegated(mixed $sign, mixed $api = null): void
    {
        Http::fake([
            'rulegen:8080/v1/extract' => fn () => Http::response($this->delegatedExtraction()),
            'rulegen:8080/v1/sign' => $sign,
            'cdn2.onlyfans.com/key/*' => fn () => Http::response(str_repeat('ab', 20)),
            'onlyfans.com/api2/*' => $api ?? fn () => Http::response(self::PROFILE),
        ]);
    }

    #[Test]
    public function a_changed_formula_activates_a_delegated_signer_after_the_canary(): void
    {
        $this->activateOld();
        $this->fakeDelegated(fn (Request $r) => Http::response([
            'ok' => true, 'revision' => $r['revision'], 'time' => '1790000000000', 'sign' => '12345:'.str_repeat('c', 40).':1a2:abcd1234',
        ]));

        $result = app(OnlyfansRuleRefresher::class)->refresh(canary: true);

        $this->assertSame('activated', $result['outcome']);
        $this->assertSame('delegated', $result['mode']);
        $rules = app(OnlyfansRules::class)->current();
        $this->assertTrue($rules->delegated());
        $this->assertNull($rules->staticParam);

        // The canary carried the signature the extracted function produced.
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/v1/sign')
            && $r['revision'] === self::NEW_REVISION && $r['path'] === '/api2/v2/users/madison420ivy');
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/api2/v2/users/madison420ivy')
            && $r->header('sign')[0] === '12345:'.str_repeat('c', 40).':1a2:abcd1234'
            && $r->header('time')[0] === '1790000000000'
            && $r->header('app-token')[0] === '33d57ade8c02dbc5a333db99ff9ae26a'
            && ! $r->hasHeader('user-id'));
    }

    #[Test]
    public function a_delegated_signer_is_never_activated_without_a_canary(): void
    {
        $this->activateOld();
        $before = $this->activeSnapshot();
        $this->fakeDelegated(fn () => Http::response(['ok' => false, 'error' => 'NOT_LOADED'], 409));

        $result = app(OnlyfansRuleRefresher::class)->refresh(canary: false);

        $this->assertSame('CANARY_REQUIRED', $result['error']);
        $this->assertSame($before, $this->activeSnapshot());
    }

    #[Test]
    public function a_delegated_build_rulegen_lost_is_retryable_and_reloaded(): void
    {
        app(OnlyfansRules::class)->activate(OnlyfansRuleSet::fromArray($this->delegatedExtraction(), 'rulegen'), 'proof+canary');
        $this->fakeDelegated(fn () => Http::response(['ok' => false, 'error' => 'NOT_LOADED'], 409));
        $account = $this->account('A', ['source' => 'onlyfans']);

        $result = app(ClientFactory::class)->for($account)->fetch($this->profile($account, 'madison420ivy'));

        $this->assertSame(Outcome::NETWORK_ERROR, $result->category);
        $this->assertTrue($result->retryable());
        $this->assertNotNull(app(OnlyfansRules::class)->refreshRequested());
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/api2/'));
    }

    #[Test]
    public function signers_are_kept_per_build_and_old_builds_are_pruned(): void
    {
        $store = app(OnlyfansRules::class);
        $revisions = array_map(fn (int $i): string => sprintf('20260901%04d-%010d', $i, $i), range(1, 7));
        foreach ($revisions as $revision) {
            $store->activate(OnlyfansRuleSet::fromArray(['revision' => $revision] + self::OLD, 'rulegen'), 'proof');
        }

        $this->assertSame($revisions[6], $store->current()->revision);
        $this->assertSame($revisions[5], $store->previousRevision());
        $this->assertCount(5, $store->builds());
        $this->assertNotNull($store->build($revisions[2]));
        $this->assertNull($store->build($revisions[0]), 'builds beyond the last five are dropped');
    }

    #[Test]
    public function the_pre_build_key_active_format_is_still_read(): void
    {
        Cache::forever(OnlyfansRules::ACTIVE_KEY, ['rules' => self::OLD + ['app-token' => self::OLD['app_token']], 'source' => 'rulegen']);

        $this->assertSame(self::OLD['revision'], app(OnlyfansRules::class)->current()?->revision);
        // Migrated to the per-build layout on first read.
        $this->assertSame(self::OLD['revision'], Cache::get(OnlyfansRules::ACTIVE_KEY));
        $this->assertSame(self::OLD['revision'], app(OnlyfansRules::class)->build(self::OLD['revision'])?->revision);
    }

    #[Test]
    public function the_recurring_canary_checks_the_known_profile_and_reacts_to_a_rejection(): void
    {
        $this->activateOld();
        $this->account('A', ['source' => 'onlyfans']);
        $store = app(OnlyfansRules::class);
        Cache::forever(OnlyfansRules::STATUS_KEY, ['attempted_at' => now()->toIso8601String()]);

        // Healthy first (200 with id 5140520), then the build rotates.
        $this->fakeUpstream(
            fn () => Http::response($this->extraction()),
            Http::sequence()->push(self::PROFILE)->push(self::REJECTED, 400),
        );
        $profileCalls = fn (): int => count(Http::recorded(fn (Request $r): bool => str_contains($r->url(), '/api2/')));

        $this->artisan('fans:onlyfans-rules')->assertExitCode(0);
        $this->assertTrue($store->canaryStatus()['ok']);
        $this->assertSame(1, $profileCalls());

        // Not due again inside the canary interval: no request.
        $this->artisan('fans:onlyfans-rules')->assertExitCode(0);
        $this->assertSame(1, $profileCalls());

        // Due: the rejection is counted and a refresh is requested at once.
        $this->travel(6)->minutes();
        $this->artisan('fans:onlyfans-rules')->assertExitCode(1);
        $this->assertSame(2, $profileCalls());
        $this->assertFalse($store->canaryStatus()['ok']);
        $this->assertSame(Outcome::SIGNATURE_REJECTED, $store->canaryStatus()['category']);
        $this->assertSame(1, $store->rejections(self::OLD['revision']));
        $this->assertNotNull($store->refreshRequested());
    }

    #[Test]
    public function a_canary_answered_by_a_different_profile_fails(): void
    {
        $this->activateOld();
        $this->fakeUpstream(fn () => Http::response($this->extraction()), fn () => Http::response(['id' => 1] + self::PROFILE));

        $this->assertSame('unexpected_profile', app(OnlyfansRuleRefresher::class)->canary()['category']);
    }

    #[Test]
    public function held_rejected_runs_are_replayed_once_the_new_build_is_active(): void
    {
        $this->activateOld();
        $this->fakeUpstream(
            fn () => Http::response($this->extraction()),
            Http::sequence()
                ->push(self::REJECTED, 400)
                ->push(['favoritedCount' => 777] + self::PROFILE)
                ->push(['favoritedCount' => 777] + self::PROFILE),
        );
        $profile = $this->profile($this->account('A', ['source' => 'onlyfans']), 'madison420ivy');
        $run = $this->enqueue($profile);
        $this->workOnce('refresh-a');
        $this->workOnce('refresh-a');
        $this->assertSame(Outcome::SIGNATURE_REJECTED, $run->fresh()->outcome_category);
        $this->assertContains($run->fresh()->status, RunStatus::deadLetterable());

        // The second delivery was dead-lettered without a request (same build).
        // The new build activates (canary: 2nd answer) and the held run is replayed.
        $result = app(OnlyfansRuleRefresher::class)->refresh(canary: true);
        $this->assertSame(1, $result['replayed']);
        $replay = RefreshRun::query()->where('replay_of_run_id', $run->id)->sole();

        $this->workOnce('refresh-a');
        $this->assertSame(RunStatus::Succeeded, $replay->fresh()->status);
        $this->assertSame(777, $profile->fresh()->likes);
        $this->assertNotNull($run->fresh()->resolved_at);

        // Recovery is measured: first rejection of the old build to activation.
        $this->assertSame(1, app(OnlyfansRules::class)->rejections(self::OLD['revision']));
        $this->assertNotNull(app(OnlyfansRules::class)->activeRecord()['recovery_seconds']);

        // A second activation of a newer build does not replay it again.
        Http::fake([
            'rulegen:8080/*' => fn () => Http::response($this->extraction(['revision' => '202609020000-0123456789'])),
            'cdn2.onlyfans.com/key/*' => fn () => Http::response(str_repeat('ab', 20)),
            'onlyfans.com/api2/*' => fn () => Http::response(self::PROFILE),
        ]);
        $this->assertSame(0, app(OnlyfansRuleRefresher::class)->refresh(canary: true)['replayed']);
    }

    #[Test]
    public function status_shows_the_active_build_and_its_checks(): void
    {
        $this->activateOld();

        $this->artisan('fans:onlyfans-rules --status')
            ->expectsOutputToContain(self::OLD['revision'])
            ->expectsOutputToContain('constants')
            ->assertExitCode(0);
    }
}
