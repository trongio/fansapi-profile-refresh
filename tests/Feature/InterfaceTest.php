<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Models\Profile;
use App\Models\RefreshRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Group 5: the web actions enqueue and nothing more. */
class InterfaceTest extends TestCase
{
    use RefreshDatabase;

    private Profile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->profile = $this->profile($this->account('A'), 'a-01');
    }

    #[Test]
    public function the_refresh_action_only_enqueues_and_never_calls_the_upstream(): void
    {
        $this->post(route('profiles.refresh', $this->profile))->assertRedirect();

        $run = RefreshRun::sole();
        $this->assertSame(RunStatus::Queued, $run->status);
        $this->assertSame('ui', $run->trigger);
        // No HTTP happened in the request: no attempt was recorded.
        $this->assertSame(0, $run->requests_used);
        $this->assertSame(0, $run->attempts()->count());
        $this->assertSame($run->id, $this->profile->fresh()->pending_run_id);
    }

    #[Test]
    public function a_second_click_is_deduplicated_in_the_backend(): void
    {
        $this->post(route('profiles.refresh', $this->profile));
        $this->post(route('profiles.refresh', $this->profile))
            ->assertSessionHas('status', fn (string $s) => str_contains($s, 'already pending'));

        $this->assertSame(1, RefreshRun::count());
    }

    #[Test]
    public function changes_require_a_post_and_carry_a_csrf_token(): void
    {
        // Reads cannot mutate: the route is POST only.
        $this->get(route('profiles.refresh', $this->profile))->assertStatus(405);

        // Laravel's CSRF middleware short-circuits under the test harness, so
        // the check here is that every mutating form actually ships a token.
        $this->get(route('profiles.index'))
            ->assertOk()
            ->assertSee('name="_token"', false)
            ->assertSee('method="POST"', false);
    }

    #[Test]
    public function the_profile_list_shows_the_last_valid_values_and_the_source_mode(): void
    {
        $this->get(route('profiles.index'))
            ->assertOk()
            ->assertSee('120,000')
            ->assertSee('FIXTURE');
    }

    #[Test]
    public function the_activity_endpoint_returns_per_account_rows(): void
    {
        $this->post(route('profiles.refresh', $this->profile));

        $this->getJson(route('activity'))
            ->assertOk()
            ->assertJsonPath('accounts.0.account', 'A')
            ->assertJsonPath('accounts.0.valid_refreshes', 0)
            ->assertJsonStructure(['updated_at', 'mode', 'stale_leases',
                'accounts' => [['account', 'ready', 'delayed', 'reserved', 'retries_scheduled', 'dead_letters', 'oldest_waiting_seconds']]]);
    }

    #[Test]
    public function scout_searches_profiles_through_the_database_engine(): void
    {
        $this->profile($this->account('B'), 'madison420ivy', ['display_name' => 'Madison Ivy']);

        $this->get(route('profiles.index', ['q' => 'madison']))
            ->assertOk()
            ->assertSee('madison420ivy')
            ->assertDontSee('>a-01<', false);
    }
}
