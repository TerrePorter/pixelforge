<?php

use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;

// No auth and no CSRF (see bootstrap/app.php): requests are trusted by their signature.
Route::post('/webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');

Route::redirect('/', '/generate')->name('home');

Route::middleware('auth')->group(function () {
    Route::get('/generate', [GenerationController::class, 'index'])->name('generate');
    Route::post('/generations', [GenerationController::class, 'store'])->name('generations.store');

    Route::get('/credits', [CreditController::class, 'index'])->name('credits');
    Route::post('/credits', [CreditController::class, 'store'])->name('credits.store');

    Route::get('/admin/report', ReportController::class)->middleware('can:admin')->name('admin.report');
});
