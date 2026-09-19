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

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([OnlyfansRules::ACTIVE_KEY, OnlyfansRules::PREVIOUS_KEY, OnlyfansRules::STATUS_KEY, OnlyfansRules::REQUESTED_KEY,
            OnlyfansRules::REQUEST_COOLDOWN_KEY, OnlyfansRules::DEVICE_KEY] as $key) {
            Cache::forget($key);
        }
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
            'onlyfans.com/api2/*' => $api ?? fn () => Http::response(['id' => 15585607, 'username' => 'onlyfans', 'favoritedCount' => 10]),
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
        $this->assertSame(self::OLD['revision'], Cache::get(OnlyfansRules::PREVIOUS_KEY)['rules']['revision']);

        // The canary is signed in PHP, like every profile request.
        Http::assertSent(function (Request $request) use ($rules): bool {
            if (! str_contains($request->url(), '/api2/v2/users/onlyfans')) {
                return false;
            }
            $expected = OnlyfansSigner::headers($rules->signatureRules(), '/api2/v2/users/onlyfans', '0', (int) $request->header('time')[0]);

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
        $before = Cache::get(OnlyfansRules::ACTIVE_KEY);

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
        $this->assertSame($before, Cache::get(OnlyfansRules::ACTIVE_KEY), 'last-known-good must be untouched');
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
        $before = Cache::get(OnlyfansRules::ACTIVE_KEY);
        $this->fakeUpstream(fn () => Http::response($this->extraction()), fn () => Http::response(self::REJECTED, 400));
        $account = $this->account('A', ['source' => 'onlyfans']);
        $profile = $this->profile($account, 'onlyfans');
        $client = app(ClientFactory::class)->for($account);

        $result = $client->fetch($profile);

        $this->assertSame(Outcome::SIGNATURE_REJECTED, $result->category);
        $this->assertSame(self::OLD['revision'], $result->signingRevision);
        $this->assertSame($before, Cache::get(OnlyfansRules::ACTIVE_KEY));
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
        $run = $this->enqueue($this->profile($this->account('A', ['source' => 'onlyfans']), 'onlyfans'));

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
                ->push(['id' => 15585607, 'username' => 'onlyfans', 'favoritedCount' => 4321]),
        );
        $profile = $this->profile($this->account('A', ['source' => 'onlyfans']), 'onlyfans');
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
}
