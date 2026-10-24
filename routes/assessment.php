<?php

use App\Http\Controllers\{AccountController, AttemptController, AuthController, CredentialController, ParticipantController};
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function () {
    Route::get('/bootstrap', [AuthController::class, 'bootstrap']);
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
    Route::post('/set-password/inspect', [CredentialController::class, 'inspect'])->middleware('throttle:credential-inspect');
    Route::post('/set-password', [CredentialController::class, 'store'])->middleware('throttle:credential-store');
    Route::get('/packages', [ParticipantController::class, 'packages']);
    Route::get('/packages/{package}', [ParticipantController::class, 'package']);
    Route::middleware('auth')->group(function () {
        Route::prefix('account')->group(function () {
            Route::get('/', [AccountController::class, 'show']);
            Route::put('/', [AccountController::class, 'update']);
            Route::get('/photo', [AccountController::class, 'photo']);
            Route::put('/password', [AccountController::class, 'password'])->middleware('throttle:account-password');
        });
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/dashboard', [ParticipantController::class, 'dashboard']);
        Route::put('/profile', [ParticipantController::class, 'profile'])->middleware('throttle:account-password');
        Route::put('/preferences', [AuthController::class, 'preferences']);
        Route::post('/packages/{package}/attempts', [AttemptController::class, 'store'])->middleware('throttle:10,1');
        Route::get('/attempts/{attempt}', [AttemptController::class, 'show']);
        Route::post('/attempts/{attempt}/questions/{key}/activate', [AttemptController::class, 'activateQuestion'])->middleware('throttle:180,1');
        Route::put('/attempts/{attempt}/answers', [AttemptController::class, 'answer'])->middleware('throttle:180,1');
        Route::post('/attempts/{attempt}/submit', [AttemptController::class, 'submit']);
        Route::post('/attempts/{attempt}/events', [AttemptController::class, 'event'])->middleware('throttle:60,1');
        Route::post('/attempts/{attempt}/recordings', [AttemptController::class, 'recording'])->middleware('throttle:15,1');
        Route::get('/results', [ParticipantController::class, 'results']);
        Route::get('/results/{attempt}', [ParticipantController::class, 'result']);
        Route::get('/results/{attempt}/placement-report', [\App\Http\Controllers\PlacementReportController::class, 'show']);
        Route::get('/certificates', [ParticipantController::class, 'certificates']);
    });
});
