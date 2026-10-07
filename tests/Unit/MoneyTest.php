<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public static function amounts(): array
    {
        return [
            'a decimal column' => ['12.34', 1234],
            'one decimal place' => ['0.5', 50],
            'no decimals' => ['120', 12000],
            'a trailing point' => ['7.', 700],
            'no leading digit' => ['.25', 25],
            'half a cent rounds up' => ['1.005', 101],
            'under half a cent rounds down' => ['1.004', 100],
            'negative' => ['-20.00', -2000],
            'negative half cent rounds away from zero' => ['-1.005', -101],
            'an integer' => [7, 700],
            'a float that is 1.00499... in binary' => [1.005, 101],
            'a float sum' => [0.1 + 0.2, 30],
            'null' => [null, 0],
            'blank' => ['', 0],
            'padded' => [' 4.50 ', 450],
        ];
    }

    #[DataProvider('amounts')]
    public function test_amounts_become_whole_cents(string|int|float|null $amount, int $cents): void
    {
        $this->assertSame($cents, Money::toCents($amount));
    }

    public static function notAmounts(): array
    {
        return [['1,234.50'], ['$5'], ['abc'], ['-'], ['.'], ['1.2.3']];
    }

    #[DataProvider('notAmounts')]
    public function test_anything_else_is_refused(string $amount): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::toCents($amount);
    }

    public function test_cents_go_back_to_a_decimal_string(): void
    {
        $this->assertSame('12.34', Money::fromCents(1234));
        $this->assertSame('0.05', Money::fromCents(5));
        $this->assertSame('-0.05', Money::fromCents(-5));
        $this->assertSame('-1200.00', Money::fromCents(-120000));
        $this->assertSame('0.00', Money::fromCents(0));
    }
}
