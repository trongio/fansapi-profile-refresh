<?php

namespace App\Refresh\Clients;

/**
 * The request signature the OnlyFans web client computes for every API call.
 *
 *   sign = prefix ":" sha1(static \n time \n path \n user_id) ":" checksum ":" suffix
 *
 * where checksum is the sum of the sha1 hex characters at checksum_indexes plus
 * checksum_constant, written as lowercase hex of its absolute value. The inputs
 * are the "dynamic rules" OnlyFans rotates; they are extracted locally from
 * the current web build (see OnlyfansRuleRefresher).
 *
 * Pure function, no I/O, so it is unit-testable against a fixed vector.
 */
final class OnlyfansSigner
{
    /**
     * @param  array<string,mixed>  $rules
     * @return array<string,string>
     */
    public static function headers(array $rules, string $pathWithQuery, string $userId = '0', ?int $timeMs = null): array
    {
        $time = (string) ($timeMs ?? (int) (microtime(true) * 1000));
        $sha = sha1(implode("\n", [$rules['static_param'], $time, $pathWithQuery, $userId]));

        $sum = (int) $rules['checksum_constant'];
        foreach ($rules['checksum_indexes'] as $index) {
            $sum += ord($sha[$index]);
        }

        return [
            'app-token' => $rules['app-token'],
            'sign' => sprintf('%s:%s:%x:%s', $rules['prefix'], $sha, abs($sum), $rules['suffix']),
            'time' => $time,
            'user-id' => $userId,
        ];
    }
}
