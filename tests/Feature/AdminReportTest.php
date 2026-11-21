<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config()->set('pixelforge.base_url', 'https://pixelforge.test/v1');
    config()->set('pixelforge.api_key', 'pf_test_key');
});

/**
 * A succeeded generation with fixed tokens (10 text, 1100 output, 1000 reasoning).
 */
function completedGeneration(User $user, string $completedAt, int $cost, int $charge, GenerationStatus $status = GenerationStatus::Succeeded): Generation
{
    return Generation::factory()->for($user)->submitted()->create([
        'status' => $status,
        'cost_nanos' => $cost,
        'charge_nanos' => $charge,
        'input_text_tokens' => 10,
        'input_image_tokens' => 0,
        'output_tokens' => 1100,
        'reasoning_tokens' => 1000,
        'completed_at' => $completedAt,
    ]);
}

/**
 * Two users over two days, plus generations that must not be counted.
 *
 * @return array{alice: User, bob: User}
 */
function reportFixtures(): array
{
    $alice = User::factory()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
    $bob = User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']);

    completedGeneration($alice, '2026-11-18 09:00:00', 100_000_000, 125_000_000);
    completedGeneration($alice, '2026-11-18 23:59:59', 68_382_500, 85_478_125);
    completedGeneration($alice, '2026-11-19 00:00:00', 40_000_000, 50_000_000);
    completedGeneration($bob, '2026-11-19 12:00:00', 20_000_000, 25_000_000);

    // Not counted: failed, still running, and outside the range.
    completedGeneration($bob, '2026-11-19 13:00:00', 0, 0, GenerationStatus::Failed);
    Generation::factory()->for($bob)->submitted()->create(['status' => GenerationStatus::Processing]);
    completedGeneration($alice, '2026-11-20 00:00:00', 999_000_000, 999_000_000);
    completedGeneration($alice, '2026-11-17 23:59:59', 999_000_000, 999_000_000);

    return ['alice' => $alice, 'bob' => $bob];
}

/**
 * GET /v1/usage as Pixelforge would answer for reportFixtures().
 *
 * @param  array<string, array{0: int, 1: string}>  $overrides  date => [generations, cost_usd]
 * @return array<string, mixed>
 */
function pixelforgeUsage(array $overrides = []): array
{
    $days = array_replace([
        '2026-11-18' => [2, '0.168382500'],
        '2026-11-19' => [2, '0.060000000'],
    ], $overrides);

    $data = collect($days)->map(fn (array $day, string $date) => [
        'date' => $date,
        'generations' => $day[0],
        'input_text_tokens' => 10 * $day[0],
        'input_image_tokens' => 0,
        'output_tokens' => 1100 * $day[0],
        'reasoning_tokens' => 1000 * $day[0],
        'cost_usd' => $day[1],
    ])->values()->all();

    return [
        'object' => 'usage',
        'start_date' => '2026-11-18',
        'end_date' => '2026-11-19',
        'data' => $data,
        'total' => [
            'generations' => collect($days)->sum(fn ($day) => $day[0]),
            'cost_usd' => Money::nanos(collect($days)->sum(fn ($day) => Money::fromDecimal($day[1])->nanos))->toDecimal(),
        ],
    ];
}

function admin(): User
{
    return User::factory()->create(['is_admin' => true]);
}

$range = ['start_date' => '2026-11-18', 'end_date' => '2026-11-19'];

test('guests are sent to log in and other users are refused', function () {
    $this->get(route('admin.report'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('admin.report'))->assertForbidden();
});

test('only admins see the Admin link', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('generate'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.user.is_admin', false));

    $this->actingAs(admin())
        ->get(route('generate'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.user.is_admin', true));
});

test('sums charges, provider costs and profit per UTC completion day and per user', function () use ($range) {
    reportFixtures();
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(pixelforgeUsage())]);

    $this->actingAs(admin())
        ->get(route('admin.report', $range))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Report')
            ->where('report.totals.generations', 4)
            ->where('report.totals.charged', '0.285478125')
            ->where('report.totals.provider_cost', '0.228382500')
            ->where('report.totals.profit', '0.057095625')
            ->has('report.days', 2)
            // newest first
            ->where('report.days.0.date', '2026-11-19')
            ->where('report.days.0.generations', 2)
            ->where('report.days.0.charged', '0.075000000')
            ->where('report.days.0.provider_cost', '0.060000000')
            ->where('report.days.0.profit', '0.015000000')
            ->where('report.days.0.users.0.user.name', 'Alice')
            ->where('report.days.0.users.0.charged', '0.050000000')
            ->where('report.days.0.users.1.user.name', 'Bob')
            ->where('report.days.0.users.1.charged', '0.025000000')
            ->where('report.days.1.date', '2026-11-18')
            ->where('report.days.1.charged', '0.210478125')
            ->where('report.days.1.provider_cost', '0.168382500')
            ->where('report.days.1.tokens.reasoning_tokens', 2000)
            ->has('report.users', 2)
            ->where('report.users.0.user.email', 'alice@example.com')
            ->where('report.users.0.generations', 3)
            ->where('report.users.0.charged', '0.260478125')
            ->where('report.users.0.provider_cost', '0.208382500')
            ->where('report.users.1.user.email', 'bob@example.com')
            ->where('report.users.1.profit', '0.005000000'));
});

test('matches Pixelforge to the nanodollar when the records agree', function () use ($range) {
    reportFixtures();
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(pixelforgeUsage())]);

    $this->actingAs(admin())
        ->get(route('admin.report', $range))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.pixelforge.available', true)
            ->where('report.pixelforge.matches', true)
            ->where('report.pixelforge.total_cost', '0.228382500')
            ->where('report.days.0.pixelforge.matches', true)
            ->where('report.days.1.pixelforge.matches', true));

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/v1/usage')
        && $request['start_date'] === '2026-11-18'
        && $request['end_date'] === '2026-11-19'
        && $request->header('Authorization') === ['Bearer pf_test_key']);
});

test('flags the days that differ from Pixelforge', function () use ($range) {
    reportFixtures();
    // Pixelforge billed a third generation on the 19th that we have no record of.
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(pixelforgeUsage(['2026-11-19' => [3, '0.070000000']]))]);

    $this->actingAs(admin())
        ->get(route('admin.report', $range))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.pixelforge.matches', false)
            ->where('report.pixelforge.total_cost', '0.238382500')
            ->where('report.days.0.pixelforge.matches', false)
            ->where('report.days.0.pixelforge.cost', '0.070000000')
            ->where('report.days.0.pixelforge.difference', '-0.010000000')
            ->where('report.days.1.pixelforge.matches', true));
});

test('a day only Pixelforge knows about still appears', function () use ($range) {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(pixelforgeUsage(['2026-11-18' => [0, '0.000000000'], '2026-11-19' => [1, '0.010000000']]))]);

    $this->actingAs(admin())
        ->get(route('admin.report', $range))
        ->assertInertia(fn (Assert $page) => $page
            ->has('report.days', 1)
            ->where('report.days.0.date', '2026-11-19')
            ->where('report.days.0.generations', 0)
            ->where('report.days.0.pixelforge.matches', false));
});

test('still shows our own numbers when Pixelforge is unreachable', function () use ($range) {
    reportFixtures();
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'Busy']], 503)]);

    $this->actingAs(admin())
        ->get(route('admin.report', $range))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.pixelforge.available', false)
            ->where('report.totals.provider_cost', '0.228382500')
            ->where('report.days.0.pixelforge', null));
});

test('defaults to the last 30 UTC days', function () {
    $this->travelTo('2026-11-20 15:00:00');
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(['data' => [], 'total' => ['generations' => 0, 'cost_usd' => '0.000000000']])]);

    $this->actingAs(admin())
        ->get(route('admin.report'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.start_date', '2026-10-22')
            ->where('report.end_date', '2026-11-20'));
});

test('ranges longer than Pixelforge allows are refused', function (array $query, string $field) {
    $this->actingAs(admin())
        ->get(route('admin.report', $query))
        ->assertSessionHasErrors($field);
})->with([
    'over 92 days' => [['start_date' => '2026-01-01', 'end_date' => '2026-11-19'], 'start_date'],
    'end before start' => [['start_date' => '2026-11-19', 'end_date' => '2026-11-18'], 'end_date'],
    'bad date' => [['start_date' => 'yesterday', 'end_date' => '2026-11-18'], 'start_date'],
]);

test('users:admin grants and revokes access', function () {
    $user = User::factory()->create(['email' => 'ops@example.com']);

    $this->artisan('users:admin', ['email' => 'ops@example.com'])->assertSuccessful();
    expect($user->refresh()->is_admin)->toBeTrue();

    $this->artisan('users:admin', ['email' => 'ops@example.com', '--revoke' => true])->assertSuccessful();
    expect($user->refresh()->is_admin)->toBeFalse();

    $this->artisan('users:admin', ['email' => 'nobody@example.com'])->assertFailed();
});
