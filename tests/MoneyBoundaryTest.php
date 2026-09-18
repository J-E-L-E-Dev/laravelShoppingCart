<?php

use JeleDev\Shoppingcart\Money;
use PHPUnit\Framework\TestCase;

class MoneyBoundaryTest extends TestCase
{
    public static function comparisons(): array
    {
        $cases = [
            ['-1e-9999', 0, -1], ['1e-9999', 0, 1],
            ['99.9999999999999999999', 100, -1],
            ['100.0000000000000000001', 100, 1],
            ['1.000000000000000000001e2', 100, 1],
            ['100', 100, 0], ['100.0000000000000000000', 100, 0],
            ['1e2', 100, 0], ['10e1', 100, 0], ['1000e-1', 100, 0],
            ['-1.01', '-1', -1], ['-10', '-100', 1],
            ['.001', '1e-3', 0], ['0001.0', '1', 0],
            ['  +1.00e+2 ', '100', 0],
            ['1e-9999999999999999999999', '10e-10000000000000000000000', 0],
            ['1e9999999999999999999999', '10e9999999999999999999998', 0],
            ['1e-9999999999999999999999', '1e-9999999999999999999998', -1],
            ['9e9999999999999999999999', '1e10000000000000000000000', -1],
            [.29, '0.29', 0], [55.865, '55.865', 0],
            [1.23445, '1.234449999999999999', 1],
        ];
        foreach ([0, 0.0, '0', '-0', '+0', '-0.0', '-000.000', '-0e999', '-0.000e-9999', '-0.000e9999', '-0.000e50'] as $zero) {
            $cases[] = [$zero, 0, 0];
        }
        return $cases;
    }

    /** @dataProvider comparisons */
    public function testExactDecimalComparison($left, $right, $expected): void
    {
        self::assertSame($expected, Money::compare($left, $right));
        self::assertSame(-$expected, Money::compare($right, $left));
        self::assertSame(0, Money::compare($left, $left));
    }

    public static function invalidComparisonOperands(): array
    {
        return [['abc'], [NAN], [INF], [-INF], [true], [null], [[]], [new \stdClass()]];
    }

    /** @dataProvider invalidComparisonOperands */
    public function testComparisonRejectsInvalidOperands($value): void
    {
        foreach ([[$value, 0], [0, $value]] as [$left, $right]) {
            try { Money::compare($left, $right); self::fail('Invalid operand accepted'); }
            catch (InvalidArgumentException $e) { self::assertStringContainsString('Invalid numeric comparison', $e->getMessage()); }
        }
    }

    public static function boundaries(): array
    {
        $cases = [];
        foreach ([0, 1, 2, 3, 4] as $decimals) {
            $half = '0.'.str_repeat('0', $decimals).'5';
            $belowHalf = '0.'.str_repeat('0', $decimals).'4999999';
            $unit = $decimals === 0 ? '1.0000000' : '0.'.str_repeat('0', $decimals - 1).'10000000';
            $belowUnit = '0.'.str_repeat('0', $decimals).'9999999';
            foreach (['' => 1, '-' => -1] as $sign => $direction) {
                foreach (['half' => [$half, false, $direction], 'below half' => [$belowHalf, false, 0],
                    'unit' => [$unit, true, $direction], 'below unit' => [$belowUnit, true, 0]] as $name => [$value, $truncate, $expected]) {
                    $cases["$decimals $sign$name string"] = [$sign.$value, $truncate, $decimals, $expected];
                    $cases["$decimals $sign$name float"] = [(float) ($sign.$value), $truncate, $decimals, $expected];
                }
            }
        }
        return $cases;
    }

    /** @dataProvider boundaries */
    public function testMonetaryBoundaries($value, $truncate, $decimals, $expected): void
    {
        self::assertSame($expected, Money::minorUnits($value, $truncate, $decimals));
    }

    public static function exactStrings(): array
    {
        return [
            ['0.004999999999999999999999999999', false, 0],
            ['-0.004999999999999999999999999999', false, 0],
            ['0.009999999999999999999999999999', true, 0],
            ['-0.009999999999999999999999999999', true, 0],
            [' +000.00500000000000000000000 ', false, 1],
            ['499999999999999999999e-23', false, 0],
            ['5e-3', false, 1], ['-5E-3', false, -1],
            ['999999999999999999999e-23', true, 0],
            ['1E-2', true, 1], ['-1E-2', true, -1],
            ['.29', true, 29], ['29.e-2', true, 29],
            ['1e-999999999999999999999', false, 0],
            ['0e999999999999999999999', false, 0],
            ['1000000000.0000000000000', false, 100000000000],
        ];
    }

    /** @dataProvider exactStrings */
    public function testStringDigitsAndScientificNotationRemainExact($value, $truncate, $expected): void
    {
        self::assertSame($expected, Money::minorUnits($value, $truncate, 2));
    }

    public function testFloatNormalizationDoesNotDependOnPhpPrecisionSettings(): void
    {
        $precision = ini_get('precision');
        $serializePrecision = ini_get('serialize_precision');
        try {
            ini_set('precision', '3');
            ini_set('serialize_precision', '3');
            foreach ([1, -1] as $sign) {
                self::assertSame(29 * $sign, Money::minorUnits(.29 * $sign, true, 2));
                self::assertSame(5587 * $sign, Money::minorUnits(55.865 * $sign, false, 2));
                self::assertSame(5586 * $sign, Money::minorUnits(55.865 * $sign, true, 2));
            }
        } finally {
            ini_set('precision', $precision);
            ini_set('serialize_precision', $serializePrecision);
        }
    }

    public function testExactStringCannotExceedMonetaryLimitByAnInvisibleFloatFraction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::minorUnits('1000000000.0000000000001', false, 4);
    }
}
