<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/generate')->name('home');

Route::middleware('auth')->group(function () {
    Route::inertia('/generate', 'Generate')->name('generate');
});
