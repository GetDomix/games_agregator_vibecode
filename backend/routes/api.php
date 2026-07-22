<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AdsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\HistoryController;
use App\Http\Controllers\Api\PriceController;
use App\Http\Controllers\Api\TrackingController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::post('/auth/register', [AuthController::class, 'register'])
    ->middleware('throttle:8,1');
Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:20,1');

Route::get('/search', [PriceController::class, 'search'])
    ->middleware('throttle:30,1');
Route::get('/prices', [PriceController::class, 'prices'])
    ->middleware('throttle:20,1');
Route::get('/quota', [PriceController::class, 'quota']);
Route::get('/ads/config', [AdsController::class, 'config']);
Route::get('/plans', [BillingController::class, 'plans']);
Route::post('/billing/request', [BillingController::class, 'requestCheckout'])
    ->middleware('throttle:10,1');
Route::get('/trends/popular', [DashboardController::class, 'popular'])
    ->middleware('throttle:60,1');
Route::post('/track/click', [TrackingController::class, 'click'])
    ->middleware('throttle:60,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::patch('/auth/me', [AuthController::class, 'updateMe']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/billing/promo', [BillingController::class, 'activatePromo']);

    Route::get('/me/dashboard', [DashboardController::class, 'me']);
    Route::get('/me/history', [HistoryController::class, 'index']);
    Route::delete('/me/history', [HistoryController::class, 'destroyAll']);
    Route::delete('/me/history/{id}', [HistoryController::class, 'destroy']);

    Route::get('/me/favorites', [FavoriteController::class, 'index']);
    Route::post('/me/favorites', [FavoriteController::class, 'store']);
    Route::post('/me/favorites/refresh', [FavoriteController::class, 'refresh']);
    Route::patch('/me/favorites/{appid}', [FavoriteController::class, 'update']);
    Route::delete('/me/favorites/{appid}', [FavoriteController::class, 'destroy']);

    Route::get('/admin/overview', [AdminController::class, 'overview']);
    Route::post('/admin/users/{id}/plan', [AdminController::class, 'setUserPlan']);
    Route::post('/admin/users/{id}/admin', [AdminController::class, 'setUserAdmin']);
});
