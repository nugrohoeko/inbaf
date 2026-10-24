<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\TestTypeController;
use App\Http\Controllers\UserManagementController;
use Illuminate\Support\Facades\Route;

Route::get('/api/branding/{kind}', [MediaController::class, 'branding'])->middleware('throttle:120,1');

Route::middleware(['auth', 'throttle:180,1'])->group(function () {
    Route::get('/api/media/{media}', [MediaController::class, 'show'])->name('media.show');
    Route::middleware('superadmin')->prefix('api/admin')->group(function () {
        Route::get('/users', [UserManagementController::class, 'index']);
        Route::post('/users', [UserManagementController::class, 'store'])->middleware('throttle:users-create');
        Route::get('/users/{id}/photo', [UserManagementController::class, 'photo']);
        Route::get('/users/{id}', [UserManagementController::class, 'show']);
        Route::put('/users/{id}', [UserManagementController::class, 'update'])->middleware('throttle:users-update');
        Route::post('/users/{id}/status', [UserManagementController::class, 'status']);
        Route::post('/users/{id}/reset-password', [UserManagementController::class, 'resetPassword'])->middleware('throttle:users-credentials');
        Route::post('/users/{id}/invite', [UserManagementController::class, 'invite'])->middleware('throttle:users-credentials');
        Route::delete('/users/{id}', [UserManagementController::class, 'destroy']);
        Route::get('/settings', [AdminController::class, 'settings']);
        Route::put('/settings', [AdminController::class, 'saveSettings']);
        Route::get('/test-types', [TestTypeController::class, 'index']);
        Route::put('/test-types/{code}', [TestTypeController::class, 'update']);
        Route::get('/questions/{id}/usage', [AdminController::class, 'questionUsage']);
        Route::get('/questions/export', [AdminController::class, 'exportQuestions']);
        Route::get('/questions/template', [AdminController::class, 'template']);
        Route::post('/questions/import', [AdminController::class, 'importQuestions']);
        Route::post('/questions/{id}/duplicate', [AdminController::class, 'duplicate']);
        Route::post('/questions/bulk', [AdminController::class, 'bulkQuestions']);
        Route::post('/reviews/{id}', [AdminController::class, 'assess']);
        Route::post('/media', [MediaController::class, 'store']);
        Route::get('/{resource}', [AdminController::class, 'index']);
        Route::get('/{resource}/{id}', [AdminController::class, 'show']);
        Route::post('/{resource}', [AdminController::class, 'store']);
        Route::put('/{resource}/{id}', [AdminController::class, 'update']);
        Route::delete('/{resource}/{id}', [AdminController::class, 'destroy']);
    });
});
