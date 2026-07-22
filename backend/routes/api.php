<?php

use App\Http\Controllers\Api\AdsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\HistoryController;
use App\Http\Controllers\Api\PriceController;
use App\Http\Controllers\Api\TrackingController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::get('/search', [PriceController::class, 'search']);
Route::get('/prices', [PriceController::class, 'prices']);
Route::get('/quota', [PriceController::class, 'quota']);
Route::get('/ads/config', [AdsController::class, 'config']);
Route::get('/trends/popular', [DashboardController::class, 'popular']);
Route::post('/track/click', [TrackingController::class, 'click']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::patch('/auth/me', [AuthController::class, 'updateMe']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/me/dashboard', [DashboardController::class, 'me']);
    Route::get('/me/history', [HistoryController::class, 'index']);
    Route::delete('/me/history', [HistoryController::class, 'destroyAll']);
    Route::delete('/me/history/{id}', [HistoryController::class, 'destroy']);

    Route::get('/me/favorites', [FavoriteController::class, 'index']);
    Route::post('/me/favorites', [FavoriteController::class, 'store']);
    Route::post('/me/favorites/refresh', [FavoriteController::class, 'refresh']);
    Route::patch('/me/favorites/{appid}', [FavoriteController::class, 'update']);
    Route::delete('/me/favorites/{appid}', [FavoriteController::class, 'destroy']);
});
