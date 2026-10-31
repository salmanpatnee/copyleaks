<?php

use App\Http\Controllers\SubmissionController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/submissions/create')->name('home');

Route::resource('submissions', SubmissionController::class)
    ->only(['create', 'store', 'show'])
    ->middlewareFor('store', 'throttle:uploads');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
