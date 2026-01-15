<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\UnitController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    // Units
    Route::get('/units', [UnitController::class, 'index']);
    Route::post('/units', [UnitController::class, 'store']);
    Route::get('/units/{unit}', [UnitController::class, 'show']);
    Route::put('/units/{unit}', [UnitController::class, 'update']);
    Route::delete('/units/{unit}', [UnitController::class, 'destroy']);

    // Customers
    Route::get('/customers', [CustomerController::class, 'index']);
    Route::post('/customers', [CustomerController::class, 'store']);
    Route::get('/customers/search-phone', [CustomerController::class, 'searchByPhone']);
    Route::get('/customers/{customer}', [CustomerController::class, 'show']);
    Route::put('/customers/{customer}', [CustomerController::class, 'update']);

    // Bookings
    Route::get('/bookings', [BookingController::class, 'index']);
    Route::post('/bookings', [BookingController::class, 'store']);
    Route::get('/bookings/search-phone', [BookingController::class, 'searchByPhone']);
    Route::get('/bookings/today-check-ins', [BookingController::class, 'todayCheckIns']);
    Route::get('/bookings/today-check-outs', [BookingController::class, 'todayCheckOuts']);
    Route::get('/bookings/{booking}', [BookingController::class, 'show']);
    Route::put('/bookings/{booking}', [BookingController::class, 'update']);
    Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel']);
    Route::post('/bookings/{booking}/no-show', [BookingController::class, 'markNoShow']);
    Route::get('/bookings/{booking}/whatsapp-url', [BookingController::class, 'whatsappUrl']);

    // Reports (admin only)
    Route::middleware('admin')->group(function () {
        Route::get('/reports/summary', [ReportController::class, 'summary']);
        Route::get('/reports/occupancy', [ReportController::class, 'occupancy']);
        Route::get('/reports/export', [ReportController::class, 'export']);
        Route::get('/reports/agent-activity', [ReportController::class, 'agentActivity']);
        Route::get('/reports/cancellations', [ReportController::class, 'cancellations']);
        Route::get('/reports/today-dashboard', [ReportController::class, 'todayDashboard']);
    });

    // Settings
    Route::get('/settings', [SettingController::class, 'index']);
    Route::get('/settings/whatsapp-templates', [SettingController::class, 'whatsappTemplates']);
    Route::middleware('admin')->group(function () {
        Route::put('/settings', [SettingController::class, 'update']);
        Route::put('/settings/whatsapp-templates', [SettingController::class, 'updateWhatsappTemplates']);
    });
});
