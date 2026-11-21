<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Reports\UsageReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    /**
     * Defaults to the last 30 UTC days, like GET /v1/usage.
     */
    public function __invoke(Request $request, UsageReport $report): Response
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);

        $end = isset($validated['end_date'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $validated['end_date'], 'UTC')
            : CarbonImmutable::now('UTC');
        $start = isset($validated['start_date'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $validated['start_date'], 'UTC')
            : $end->subDays(29);

        if ($start === null || $end === null || $start->startOfDay()->diffInDays($end->startOfDay()) + 1 > UsageReport::MAX_DAYS) {
            throw ValidationException::withMessages([
                'start_date' => 'Choose a range of at most '.UsageReport::MAX_DAYS.' days (the most Pixelforge reports at once).',
            ]);
        }

        return Inertia::render('admin/Report', [
            'report' => $report->build($start->startOfDay(), $end->startOfDay()),
        ]);
    }
}
