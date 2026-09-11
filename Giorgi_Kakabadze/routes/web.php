<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\DeadLetterController;
use App\Http\Controllers\ProfileController;
use App\Http\Middleware\EnsureLocalDemo;
use Illuminate\Support\Facades\Route;

Route::middleware(EnsureLocalDemo::class)->group(function (): void {
    Route::get('/', [ProfileController::class, 'index'])->name('profiles.index');
    Route::get('/activity.json', ActivityController::class)->name('activity');

    Route::get('/profiles/{profile}', [ProfileController::class, 'show'])->name('profiles.show');
    Route::post('/profiles/{profile}/refresh', [ProfileController::class, 'refresh'])->name('profiles.refresh');

    Route::get('/dead-letters', [DeadLetterController::class, 'index'])->name('dlq.index');
    Route::get('/dead-letters/{run}', [DeadLetterController::class, 'show'])->name('dlq.show');
    Route::post('/dead-letters/{run}/replay', [DeadLetterController::class, 'replay'])->name('dlq.replay');
});
