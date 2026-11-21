<?php

use App\Support\Money;

test('parses decimal strings exactly, without floats', function (string $input, int $nanos) {
    expect(Money::fromDecimal($input)->nanos)->toBe($nanos);
})->with([
    'whole dollars' => ['5', 5_000_000_000],
    'two decimals' => ['12.50', 12_500_000_000],
    'nine decimals' => ['0.373875000', 373_875_000],
    'one nanodollar' => ['0.000000001', 1],
    'zero' => ['0.000000000', 0],
    'negative' => ['-1.25', -1_250_000_000],
]);

test('rejects malformed or over-precise amounts', function (string $input) {
    Money::fromDecimal($input);
})->throws(InvalidArgumentException::class)->with([
    'empty' => [''],
    'letters' => ['abc'],
    'ten decimals' => ['0.0000000001'],
    'comma' => ['1,50'],
    'trailing dot' => ['1.'],
]);

test('formats as a 9-decimal string', function (int $nanos, string $expected) {
    expect(Money::nanos($nanos)->toDecimal())->toBe($expected);
})->with([
    [373_875_000, '0.373875000'],
    [1, '0.000000001'],
    [0, '0.000000000'],
    [12_500_000_000, '12.500000000'],
    [-1_250_000_000, '-1.250000000'],
]);

test('round-trips through decimal strings', function () {
    expect(Money::fromDecimal('0.169080000')->toDecimal())->toBe('0.169080000');
});

test('adds and subtracts', function () {
    $a = Money::nanos(300);
    $b = Money::nanos(120);

    expect($a->plus($b)->nanos)->toBe(420)
        ->and($a->minus($b)->nanos)->toBe(180)
        ->and($b->minus($a)->nanos)->toBe(-180);
});

test('compares amounts', function () {
    expect(Money::nanos(5)->isGreaterThan(Money::nanos(4)))->toBeTrue()
        ->and(Money::nanos(4)->isGreaterThan(Money::nanos(4)))->toBeFalse()
        ->and(Money::nanos(4)->isLessThan(Money::nanos(5)))->toBeTrue()
        ->and(Money::nanos(4)->equals(Money::nanos(4)))->toBeTrue();
});

test('a 25% markup is exact when divisible', function () {
    // $0.373875 × 1.25 = $0.46734375
    expect(Money::nanos(373_875_000)->withMarkup(2500)->nanos)->toBe(467_343_750);
});

test('a markup rounds up to the next nanodollar when not divisible', function (int $cost, int $expected) {
    expect(Money::nanos($cost)->withMarkup(2500)->nanos)->toBe($expected);
})->with([
    '1 n$ → 1.25 → 2' => [1, 2],
    '2 n$ → 2.5 → 3' => [2, 3],
    '3 n$ → 3.75 → 4' => [3, 4],
    '4 n$ → 5 exactly' => [4, 5],
]);

test('a markup never undercharges and never overcharges by a full nanodollar', function () {
    foreach ([1, 7, 999, 123_456_789, 373_875_001] as $cost) {
        $charged = Money::nanos($cost)->withMarkup(2500)->nanos;

        // charged ≥ cost × 1.25 and charged < cost × 1.25 + 1, checked in integers (× 4)
        expect($charged * 4)->toBeGreaterThanOrEqual($cost * 5)
            ->and($charged * 4)->toBeLessThan($cost * 5 + 4);
    }
});

test('a zero cost stays free after markup', function () {
    expect(Money::zero()->withMarkup(2500)->nanos)->toBe(0);
});

test('markup refuses negative amounts', function () {
    Money::nanos(-1)->withMarkup(2500);
})->throws(InvalidArgumentException::class);

test('formats amounts for people, keeping sub-cent digits', function (int $nanos, string $expected) {
    expect(Money::nanos($nanos)->format())->toBe($expected);
})->with([
    'whole dollars' => [5_000_000_000, '$5.00'],
    'cents' => [400_000_000, '$0.40'],
    'sub-cent' => [86_250_000, '$0.0863'],
    'rounds half up' => [467_343_750, '$0.4673'],
    'tiny' => [40_000, '$0.00'],
    'negative' => [-250_000_000, '-$0.25'],
    'zero' => [0, '$0.00'],
]);
