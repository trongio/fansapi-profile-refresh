<?php

namespace App\Refresh;

/**
 * The explicit count rule for likes and revisions.
 *
 * Accepted: PHP integers >= 0, and canonical non-negative integer STRINGS
 *           ("0", "121000"). That is it.
 * Rejected: null, booleans, arrays, floats/fractional values, negatives,
 *           anything with a sign, whitespace, leading zeros, exponent or
 *           decimal point, and anything above PHP_INT_MAX.
 *
 * json_decode() turns integers larger than PHP_INT_MAX into floats, so the
 * "no floats" rule is also what rejects overflowing JSON numbers.
 */
final class CountValue
{
    private const CANONICAL = '/\A(?:0|[1-9][0-9]*)\z/';

    /** @throws InvalidPayload */
    public static function parse(mixed $value, string $field): int
    {
        if (is_int($value)) {
            if ($value < 0) {
                throw InvalidPayload::schema("{$field} is negative");
            }

            return $value;
        }

        if (is_string($value)) {
            if (preg_match(self::CANONICAL, $value) !== 1) {
                throw InvalidPayload::schema("{$field} is not a canonical non-negative integer string");
            }
            // Above PHP_INT_MAX the round-trip stops matching.
            $asInt = (int) $value;
            if ((string) $asInt !== $value) {
                throw InvalidPayload::schema("{$field} overflows a 64-bit integer");
            }

            return $asInt;
        }

        if (is_float($value)) {
            throw InvalidPayload::schema("{$field} is fractional or out of integer range");
        }

        if (is_bool($value)) {
            throw InvalidPayload::schema("{$field} is a boolean");
        }

        if ($value === null) {
            throw InvalidPayload::schema("{$field} is null");
        }

        throw InvalidPayload::schema("{$field} is not a number");
    }
}
