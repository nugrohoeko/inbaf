<?php

use App\Http\Controllers\CertificateController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function () {
    Route::get('verify/{token}', [CertificateController::class, 'verify'])->middleware('throttle:60,1');
    Route::middleware('auth')->group(function () {
        Route::get('certificates/{certificate}/pdf', [CertificateController::class, 'pdf']);
        Route::post('admin/certificates/{certificate}/revoke', [CertificateController::class, 'revoke']);
    });
});
