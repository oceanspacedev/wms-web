<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\PurchaseOrderApiController;
use App\Http\Controllers\Api\V1\TrackingOrderApiController;
use App\Http\Controllers\Api\V1\UserProfileApiController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:api-login')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])->name('auth.login');
    Route::post('/login/whatsapp', [AuthController::class, 'requestWhatsAppOtp'])->name('auth.whatsapp.request');
    Route::post('/login/whatsapp/verify', [AuthController::class, 'verifyWhatsAppOtp'])->name('auth.whatsapp.verify');
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/me', [AuthController::class, 'me'])->name('auth.me');
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');

    Route::match(['put', 'patch'], '/me', [UserProfileApiController::class, 'update'])->name('profile.update');
    Route::match(['put', 'patch'], '/user', [UserProfileApiController::class, 'update']);
    Route::post('/me/photo', [UserProfileApiController::class, 'updatePhoto'])->name('profile.photo');
    Route::post('/user/photo', [UserProfileApiController::class, 'updatePhoto']);
    Route::post('/me/whatsapp/request-otp', [UserProfileApiController::class, 'requestWhatsAppOtp'])->name('profile.whatsapp.request');
    Route::post('/user/whatsapp/request-otp', [UserProfileApiController::class, 'requestWhatsAppOtp']);
    Route::post('/me/whatsapp/verify-otp', [UserProfileApiController::class, 'verifyWhatsAppOtp'])->name('profile.whatsapp.verify');
    Route::post('/user/whatsapp/verify-otp', [UserProfileApiController::class, 'verifyWhatsAppOtp']);
    Route::delete('/me', [UserProfileApiController::class, 'destroy'])->name('profile.destroy');
    Route::delete('/user', [UserProfileApiController::class, 'destroy']);

    Route::get('/courier/drivers', [TrackingOrderApiController::class, 'drivers'])->name('courier.drivers');
    Route::get('/courier/summary', [TrackingOrderApiController::class, 'courierSummary'])->name('courier.summary');

    Route::get('/tracking-orders', [TrackingOrderApiController::class, 'index'])->name('tracking-orders.index');
    Route::post('/tracking-orders', [TrackingOrderApiController::class, 'store'])->name('tracking-orders.store');
    Route::get('/tracking-orders/by-sj/{no_sj}', [TrackingOrderApiController::class, 'findByNoSj'])->name('tracking-orders.by-sj');
    Route::get('/tracking-orders/{trackingOrder}', [TrackingOrderApiController::class, 'show'])->name('tracking-orders.show');
    Route::post('/tracking-orders/{trackingOrder}/submit-pod', [TrackingOrderApiController::class, 'submitPod'])->name('tracking-orders.submit-pod');
    Route::post('/tracking-orders/by-sj/{no_sj}/submit-pod', [TrackingOrderApiController::class, 'submitPodBySj'])->name('tracking-orders.submit-pod-by-sj');

    Route::get('/purchase-orders', [PurchaseOrderApiController::class, 'index'])->name('purchase-orders.index');
    Route::post('/purchase-orders', [PurchaseOrderApiController::class, 'store'])->name('purchase-orders.store');
    Route::get('/purchase-orders/by-po/{noPo}', [PurchaseOrderApiController::class, 'findByNoPo'])->name('purchase-orders.by-po');
    Route::get('/purchase-orders/by-sj/{noSj}', [PurchaseOrderApiController::class, 'findBySupplierSj'])->name('purchase-orders.by-sj');
});
