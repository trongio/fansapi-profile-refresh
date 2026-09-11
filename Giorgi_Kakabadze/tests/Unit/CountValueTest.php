<?php

namespace Tests\Unit;

use App\Refresh\CountValue;
use App\Refresh\InvalidPayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** The explicit likes/revision rule, including the numeric-string decision. */
class CountValueTest extends TestCase
{
    #[Test]
    public function explicit_zero_is_valid_information(): void
    {
        $this->assertSame(0, CountValue::parse(0, 'likes'));
        $this->assertSame(0, CountValue::parse('0', 'likes'));
    }

    #[Test]
    public function canonical_integers_and_integer_strings_are_accepted(): void
    {
        $this->assertSame(121000, CountValue::parse(121000, 'likes'));
        $this->assertSame(121000, CountValue::parse('121000', 'likes'));
    }

    #[Test]
    #[DataProvider('rejected')]
    public function invalid_counts_are_rejected(mixed $value): void
    {
        $this->expectException(InvalidPayload::class);
        CountValue::parse($value, 'likes');
    }

    public static function rejected(): array
    {
        return [
            'negative int' => [-1],
            'negative string' => ['-5'],
            'fractional' => [12.5],
            'float whole' => [120000.0],
            'boolean true' => [true],
            'boolean false' => [false],
            'null' => [null],
            'array' => [[1]],
            'empty string' => [''],
            'non numeric string' => ['many'],
            'leading plus' => ['+10'],
            'leading zero' => ['0120'],
            'whitespace' => [' 120 '],
            'exponent' => ['1e5'],
            'decimal string' => ['120.0'],
            'hex' => ['0x10'],
            // json_decode turns this into a float, which the float rule rejects.
            'overflow string' => ['9223372036854775808'],
        ];
    }
}
