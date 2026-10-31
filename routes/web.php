<?php

use App\Http\Controllers\ScanController;
use App\Http\Controllers\SubmissionController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/submissions/create')->name('home');

Route::resource('submissions', SubmissionController::class)
    ->only(['create', 'store', 'show'])
    ->middlewareFor('store', 'throttle:uploads');

// Submit and resend are the only doors to Copyleaks. Open in Slice 0 (login arrives in Slice 1).
Route::post('submissions/{submission}/scans', [ScanController::class, 'store'])
    ->middleware('throttle:scan-submit')
    ->name('submissions.scans.store');
Route::post('submissions/{submission}/scans/resend', [ScanController::class, 'resend'])
    ->name('submissions.scans.resend');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
