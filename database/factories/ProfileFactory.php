<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Profile> */
class ProfileFactory extends Factory
{
    protected $model = Profile::class;

    /** The incident's last valid state: 120000 likes at revision 9. */
    public function definition(): array
    {
        $username = $this->faker->unique()->userName();

        return [
            'account_id' => Account::factory(),
            'username' => $username,
            'display_name' => $username,
            'likes' => 120000,
            'revision' => 9,
            'snapshot' => ['likes' => 120000],
            'snapshot_source' => 'seed',
        ];
    }

    public function due(): static
    {
        return $this->state(fn () => ['next_refresh_at' => now()->subHour()]);
    }

    public function notDue(): static
    {
        return $this->state(fn () => ['next_refresh_at' => now()->addDay()]);
    }
}
