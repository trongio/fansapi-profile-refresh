<?php

namespace Database\Factories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Account> */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        $key = strtoupper($this->faker->unique()->lexify('??'));

        return [
            'key' => $key,
            'label' => "Account {$key}",
            'queue' => 'refresh-'.strtolower($key),
            'source' => 'fixture',
            'workspace' => 'test',
        ];
    }

    public function key(string $key): static
    {
        return $this->state(fn () => [
            'key' => $key,
            'label' => "Account {$key}",
            'queue' => 'refresh-'.strtolower($key),
        ]);
    }

    public function live(): static
    {
        return $this->state(fn () => ['source' => 'ofapi']);
    }
}
