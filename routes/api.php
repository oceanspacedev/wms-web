<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\PurchaseOrderApiController;
use App\Http\Controllers\Api\V1\TrackingOrderApiController;
use App\Http\Controllers\Api\V1\UserProfileApiController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:api-login')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/login/whatsapp', [AuthController::class, 'requestWhatsAppOtp']);
    Route::post('/login/whatsapp/verify', [AuthController::class, 'verifyWhatsAppOtp']);
});

Route::middleware('auth:sanctum')->group(function (): void {
    // WMS Profile: Wajib
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // WMS Profile: Opsional (Edit Profil, Foto, WA, Hapus Akun)
    Route::match(['put', 'patch'], '/user', [UserProfileApiController::class, 'update']);
    Route::match(['put', 'patch'], '/me', [UserProfileApiController::class, 'update']);
    Route::post('/user/photo', [UserProfileApiController::class, 'updatePhoto']);
    Route::post('/me/photo', [UserProfileApiController::class, 'updatePhoto']);
    Route::post('/user/whatsapp/request-otp', [UserProfileApiController::class, 'requestWhatsAppOtp']);
    Route::post('/me/whatsapp/request-otp', [UserProfileApiController::class, 'requestWhatsAppOtp']);
    Route::post('/user/whatsapp/verify-otp', [UserProfileApiController::class, 'verifyWhatsAppOtp']);
    Route::post('/me/whatsapp/verify-otp', [UserProfileApiController::class, 'verifyWhatsAppOtp']);
    Route::delete('/user', [UserProfileApiController::class, 'destroy']);
    Route::delete('/me', [UserProfileApiController::class, 'destroy']);

    Route::get('/courier/drivers', [TrackingOrderApiController::class, 'drivers']);
    Route::get('/courier/summary', [TrackingOrderApiController::class, 'courierSummary']);

    Route::get('/tracking-orders', [TrackingOrderApiController::class, 'index']);
    Route::post('/tracking-orders', [TrackingOrderApiController::class, 'store']);
    Route::get('/tracking-orders/by-sj/{no_sj}', [TrackingOrderApiController::class, 'findByNoSj']);
    Route::get('/tracking-orders/{trackingOrder}', [TrackingOrderApiController::class, 'show']);
    Route::post('/tracking-orders/{trackingOrder}/submit-pod', [TrackingOrderApiController::class, 'submitPod']);
    Route::post('/tracking-orders/by-sj/{no_sj}/submit-pod', [TrackingOrderApiController::class, 'submitPodBySj']);

    Route::get('/purchase-orders', [PurchaseOrderApiController::class, 'index']);
    Route::post('/purchase-orders', [PurchaseOrderApiController::class, 'store']);
    Route::get('/purchase-orders/by-po/{noPo}', [PurchaseOrderApiController::class, 'findByNoPo']);
    Route::get('/purchase-orders/by-sj/{noSj}', [PurchaseOrderApiController::class, 'findBySupplierSj']);
});
