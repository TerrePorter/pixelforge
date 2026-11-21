<?php

use Illuminate\Support\Facades\Schedule;

// Cheap when nothing is stale: it only calls Pixelforge for generations that have gone
// PIXELFORGE_POLL_AFTER_SECONDS without an update (webhooks normally get there first).
Schedule::command('generations:reconcile')
    ->everyTenSeconds()
    ->withoutOverlapping();
