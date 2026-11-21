<?php

namespace App\Services\Reports;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Credit spend, provider cost and profit per UTC day and per user, checked against
 * Pixelforge's own GET /v1/usage.
 *
 * Only succeeded generations count (failed ones cost nothing on both sides), and each
 * is placed on the UTC day it *completed*, because that is the day Pixelforge bills it.
 * All sums are integer nanodollars, so comparing with Pixelforge's 9-decimal cost_usd
 * is exact: a day either matches to the nanodollar or it doesn't.
 */
class UsageReport
{
    public const int MAX_DAYS = 92;

    private const array TOKEN_COLUMNS = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    public function __construct(private readonly PixelforgeClient $client) {}

    /**
     * @return array<string, mixed>
     */
    public function build(CarbonImmutable $start, CarbonImmutable $end): array
    {
        if ($end->lessThan($start) || $start->diffInDays($end) + 1 > self::MAX_DAYS) {
            throw new InvalidArgumentException('The range must be 1 to '.self::MAX_DAYS.' days.');
        }

        $rows = $this->ourRows($start, $end);
        $pixelforge = $this->pixelforgeUsage($start, $end);

        return [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'totals' => $this->present($this->sum($rows)),
            'days' => $this->days($rows, $pixelforge['days'] ?? null),
            'users' => $this->users($rows),
            'pixelforge' => $pixelforge === null ? ['available' => false] : [
                'available' => true,
                'total_cost' => $pixelforge['total']->toDecimal(),
                'total_generations' => $pixelforge['total_generations'],
                'matches' => $pixelforge['total']->nanos === $this->sum($rows)['cost']
                    && $pixelforge['total_generations'] === $this->sum($rows)['generations'],
            ],
        ];
    }

    /**
     * Our succeeded generations summed per (completion day, user).
     *
     * @return Collection<int, array{day: string, user_id: int, generations: int, cost: int, charged: int, tokens: array<string, int>}>
     */
    private function ourRows(CarbonImmutable $start, CarbonImmutable $end): Collection
    {

        return Generation::query()
            ->where('status', GenerationStatus::Succeeded)
            ->whereBetween('completed_at', [$start->startOfDay(), $end->endOfDay()])
            ->selectRaw('date(completed_at) as day, user_id, count(*) as generations, sum(cost_nanos) as cost, sum(charge_nanos) as charged, '
                .'sum(input_text_tokens) as input_text_tokens, sum(input_image_tokens) as input_image_tokens, '
                .'sum(output_tokens) as output_tokens, sum(reasoning_tokens) as reasoning_tokens')
            ->groupByRaw('date(completed_at), user_id')
            ->toBase()
            ->get()
            ->map(fn (object $row) => [
                'day' => substr((string) $row->day, 0, 10),
                'user_id' => (int) $row->user_id,
                'generations' => (int) $row->generations,
                'cost' => (int) $row->cost,
                'charged' => (int) $row->charged,
                'tokens' => collect(self::TOKEN_COLUMNS)->mapWithKeys(fn ($column) => [$column => (int) $row->{$column}])->all(),
            ]);
    }

    /**
     * @param  Collection<int, array{day: string, user_id: int, generations: int, cost: int, charged: int, tokens: array<string, int>}>  $rows
     * @param  array<string, array{generations: int, cost: Money, tokens: array<string, int>}>|null  $pixelforgeDays
     * @return array<int, array<string, mixed>>
     */
    private function days(Collection $rows, ?array $pixelforgeDays): array
    {
        $users = $this->userNames($rows);
        $dates = $rows->pluck('day')->merge(array_keys(array_filter(
            $pixelforgeDays ?? [],
            fn (array $day) => $day['generations'] > 0,
        )))->unique()->sortDesc()->values();

        return $dates->map(function (string $date) use ($rows, $pixelforgeDays, $users) {
            $dayRows = $rows->where('day', $date);
            $ours = $this->sum($dayRows);
            $theirs = $pixelforgeDays[$date] ?? null;

            return [
                'date' => $date,
                ...$this->present($ours),
                'pixelforge' => $pixelforgeDays === null ? null : [
                    'generations' => $theirs['generations'] ?? 0,
                    'cost' => ($theirs['cost'] ?? Money::zero())->toDecimal(),
                    'difference' => Money::nanos($ours['cost'] - ($theirs['cost'] ?? Money::zero())->nanos)->toDecimal(),
                    'matches' => ($theirs['cost'] ?? Money::zero())->nanos === $ours['cost']
                        && ($theirs['generations'] ?? 0) === $ours['generations']
                        && ($theirs['tokens'] ?? array_fill_keys(self::TOKEN_COLUMNS, 0)) === $ours['tokens'],
                ],
                'users' => $dayRows
                    ->sortByDesc('charged')
                    ->map(fn (array $row) => ['user' => $users[$row['user_id']] ?? null, ...$this->present($this->sum(collect([$row])))])
                    ->values()
                    ->all(),
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, array{day: string, user_id: int, generations: int, cost: int, charged: int, tokens: array<string, int>}>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function users(Collection $rows): array
    {
        $users = $this->userNames($rows);

        return $rows->groupBy('user_id')
            ->map(fn (Collection $userRows) => $this->sum($userRows))
            ->sortByDesc(fn (array $sum) => $sum['charged'])
            ->map(fn (array $sum, int $userId) => ['user' => $users[$userId] ?? null, ...$this->present($sum)])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array{day: string, user_id: int, generations: int, cost: int, charged: int, tokens: array<string, int>}>  $rows
     * @return array{generations: int, cost: int, charged: int, tokens: array<string, int>}
     */
    private function sum(Collection $rows): array
    {
        return [
            'generations' => (int) $rows->sum('generations'),
            'cost' => (int) $rows->sum('cost'),
            'charged' => (int) $rows->sum('charged'),
            'tokens' => collect(self::TOKEN_COLUMNS)
                ->mapWithKeys(fn ($column) => [$column => (int) $rows->sum(fn (array $row) => $row['tokens'][$column])])
                ->all(),
        ];
    }

    /**
     * @param  array{generations: int, cost: int, charged: int, tokens: array<string, int>}  $sum
     * @return array<string, mixed>
     */
    private function present(array $sum): array
    {
        return [
            'generations' => $sum['generations'],
            'charged' => Money::nanos($sum['charged'])->toDecimal(),
            'provider_cost' => Money::nanos($sum['cost'])->toDecimal(),
            'profit' => Money::nanos($sum['charged'] - $sum['cost'])->toDecimal(),
            'tokens' => $sum['tokens'],
        ];
    }

    /**
     * @param  Collection<int, array{day: string, user_id: int, generations: int, cost: int, charged: int, tokens: array<string, int>}>  $rows
     * @return array<int, array{id: int, name: string, email: string}>
     */
    private function userNames(Collection $rows): array
    {
        return User::query()
            ->whereIn('id', $rows->pluck('user_id')->unique())
            ->get(['id', 'name', 'email'])
            ->mapWithKeys(fn (User $user) => [$user->id => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]])
            ->all();
    }

    /**
     * Pixelforge's usage for the same range, cached briefly. Null when unavailable.
     *
     * @return array{days: array<string, array{generations: int, cost: Money, tokens: array<string, int>}>, total: Money, total_generations: int}|null
     */
    private function pixelforgeUsage(CarbonImmutable $start, CarbonImmutable $end): ?array
    {
        $key = "pixelforge.usage.{$start->toDateString()}.{$end->toDateString()}";

        try {
            /** @var array<array-key, mixed> $usage */
            $usage = Cache::remember($key, 60, fn () => $this->client->usage($start->toDateString(), $end->toDateString()));
        } catch (PixelforgeException) {
            return null;
        }

        $days = [];

        foreach ((array) ($usage['data'] ?? []) as $day) {
            if (! is_array($day) || ! is_string($day['date'] ?? null) || ! is_string($day['cost_usd'] ?? null)) {
                continue;
            }

            $days[$day['date']] = [
                'generations' => (int) ($day['generations'] ?? 0),
                'cost' => Money::fromDecimal($day['cost_usd']),
                'tokens' => collect(self::TOKEN_COLUMNS)->mapWithKeys(fn ($column) => [$column => (int) ($day[$column] ?? 0)])->all(),
            ];
        }

        $total = $usage['total'] ?? [];

        return [
            'days' => $days,
            'total' => is_array($total) && is_string($total['cost_usd'] ?? null) ? Money::fromDecimal($total['cost_usd']) : Money::zero(),
            'total_generations' => is_array($total) ? (int) ($total['generations'] ?? 0) : 0,
        ];
    }
}
