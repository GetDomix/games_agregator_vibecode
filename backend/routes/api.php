<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AdsController;
use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\GamePriceController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\HistoryController;
use App\Http\Controllers\Api\PriceController;
use App\Http\Controllers\Api\TelegramBotController;
use App\Http\Controllers\Api\TelegramController;
use App\Http\Controllers\Api\TrackingController;
use App\Http\Middleware\EnsureRadarServiceToken;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::post('/auth/register', [AuthController::class, 'register'])
    ->middleware('throttle:8,1');
Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:20,1');
Route::post('/auth/telegram/begin', [TelegramController::class, 'oidcBegin'])->middleware('throttle:10,1');
Route::get('/auth/telegram/callback', [TelegramController::class, 'oidcCallback'])->middleware('throttle:10,1');

Route::get('/search', [PriceController::class, 'search'])
    ->middleware('throttle:30,1');
Route::get('/prices', [PriceController::class, 'prices'])
    ->middleware('throttle:20,1');
Route::get('/games/{appid}/prices', [GamePriceController::class, 'show'])
    ->whereNumber('appid')
    ->middleware('throttle:60,1');
Route::get('/ads/config', [AdsController::class, 'config']);
Route::get('/trends/popular', [DashboardController::class, 'popular'])
    ->middleware('throttle:60,1');
Route::post('/track/click', [TrackingController::class, 'click'])
    ->middleware('throttle:60,1');

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
    Route::post('/me/favorites/{appid}/alert/rearm', [FavoriteController::class, 'rearm']);
    Route::get('/me/alerts', [AlertController::class, 'index']);
    Route::get('/me/alerts/events', [AlertController::class, 'events']);
    Route::delete('/me/favorites/{appid}', [FavoriteController::class, 'destroy']);

    Route::get('/admin/overview', [AdminController::class, 'overview']);
    Route::post('/admin/users/{id}/admin', [AdminController::class, 'setUserAdmin']);

    Route::post('/telegram/link-code', [TelegramController::class, 'createLinkCode']);
    Route::post('/telegram/oidc/begin', [TelegramController::class, 'oidcBegin'])
        ->middleware('throttle:10,1');
    Route::get('/telegram/status', [TelegramController::class, 'status']);
    Route::post('/telegram/radar', [TelegramController::class, 'updateRadar']);
    Route::delete('/telegram/link', [TelegramController::class, 'unlink']);
});

// Service token for the Python bot
Route::post('/internal/telegram/bind', [TelegramController::class, 'bind'])
    ->middleware('throttle:60,1');

Route::prefix('/internal/telegram')->middleware([EnsureRadarServiceToken::class, 'throttle:60,1'])->group(function () {
    Route::post('/session', [TelegramBotController::class, 'session']);
    Route::get('/search', [TelegramBotController::class, 'search']);
    Route::get('/games/{appid}', [TelegramBotController::class, 'card'])->whereNumber('appid');
    Route::get('/favorites', [TelegramBotController::class, 'favorites']);
    Route::put('/favorites', [TelegramBotController::class, 'saveFavorite']);
    Route::delete('/favorites/{appid}', [TelegramBotController::class, 'removeFavorite'])->whereNumber('appid');
    Route::get('/alerts', [TelegramBotController::class, 'alerts']);
    Route::post('/favorites/{appid}/alert/rearm', [TelegramBotController::class, 'rearm'])->whereNumber('appid');
});
