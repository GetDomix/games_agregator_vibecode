<?php

use App\Http\Controllers\Api\AdminAuditController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AdminTeamController;
use App\Http\Controllers\Api\AdsController;
use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CurrencyController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\GamePriceController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\HistoryController;
use App\Http\Controllers\Api\PlatiRouletteController;
use App\Http\Controllers\Api\PriceController;
use App\Http\Controllers\Api\RegionController;
use App\Http\Controllers\Api\SteamDealsController;
use App\Http\Controllers\Api\SteamNewReleasesController;
use App\Http\Controllers\Api\TelegramBotController;
use App\Http\Controllers\Api\TelegramController;
use App\Http\Controllers\Api\TrackingController;
use App\Http\Middleware\EnsureRadarServiceToken;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);
Route::get('/currencies', CurrencyController::class)->middleware('throttle:api-read');
Route::get('/region', RegionController::class)->middleware('throttle:api-read');

Route::post('/auth/register', [AuthController::class, 'register'])
    ->middleware('throttle:auth-register');
Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:auth-login');
Route::post('/auth/telegram/begin', [TelegramController::class, 'oidcBegin'])->middleware('throttle:api-telegram');
Route::get('/auth/telegram/callback', [TelegramController::class, 'oidcCallback'])->middleware('throttle:api-telegram');

Route::get('/search', [PriceController::class, 'search'])
    ->middleware('throttle:api-search');
Route::get('/prices', [PriceController::class, 'prices'])
    ->middleware('throttle:api-prices');
Route::get('/games/{appid}/prices', [GamePriceController::class, 'show'])
    ->whereNumber('appid')
    ->middleware('throttle:api-read');
Route::get('/ads/config', [AdsController::class, 'config']);
Route::get('/trends/popular', [DashboardController::class, 'popular'])
    ->middleware('throttle:api-read');
Route::post('/track/click', [TrackingController::class, 'click'])
    ->middleware('throttle:api-read');
Route::get('/deals/steam', [SteamDealsController::class, 'index'])->middleware('throttle:api-deals');
Route::get('/releases/steam', SteamNewReleasesController::class)->middleware('throttle:api-deals');
Route::get('/roulette/plati', PlatiRouletteController::class)->middleware('throttle:api-read');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::patch('/auth/me', [AuthController::class, 'updateMe']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/password', [AuthController::class, 'updatePassword'])
        ->middleware('throttle:auth-password');

    Route::get('/me/dashboard', [DashboardController::class, 'me']);
    Route::get('/me/history', [HistoryController::class, 'index']);
    Route::delete('/me/history', [HistoryController::class, 'destroyAll']);
    Route::delete('/me/history/{id}', [HistoryController::class, 'destroy']);

    Route::get('/me/favorites', [FavoriteController::class, 'index']);
    Route::post('/me/favorites', [FavoriteController::class, 'store']);
    Route::post('/me/favorites/refresh', [FavoriteController::class, 'refresh']);
    Route::patch('/me/favorites/{appid}', [FavoriteController::class, 'update']);
    Route::post('/me/favorites/{appid}/alert/rearm', [FavoriteController::class, 'rearm']);
    Route::delete('/me/favorites/{appid}/alert', [FavoriteController::class, 'destroyAlert']);
    Route::get('/me/alerts', [AlertController::class, 'index']);
    Route::get('/me/alerts/events', [AlertController::class, 'events']);
    Route::delete('/me/favorites/{appid}', [FavoriteController::class, 'destroy']);

    Route::middleware(['admin-role', 'throttle:admin-read'])->group(function () {
        Route::get('/admin/overview', [AdminController::class, 'overview']);
        Route::get('/admin/users', [AdminController::class, 'users']);
        Route::get('/admin/audit', AdminAuditController::class);
    });
    Route::get('/admin/team', [AdminTeamController::class, 'index'])
        ->middleware(['owner-role', 'throttle:admin-read']);
    Route::patch('/admin/team/{user}', [AdminTeamController::class, 'update'])
        ->middleware(['owner-role', 'throttle:admin-role']);
    Route::post('/admin/games/{appid}/refresh', [AdminController::class, 'refreshGame'])
        ->whereNumber('appid')
        ->middleware(['admin-role', 'throttle:admin-action']);

    Route::post('/telegram/link-code', [TelegramController::class, 'createLinkCode']);
    Route::post('/telegram/oidc/begin', [TelegramController::class, 'oidcBegin'])
        ->middleware('throttle:api-telegram');
    Route::get('/telegram/status', [TelegramController::class, 'status']);
    Route::post('/telegram/radar', [TelegramController::class, 'updateRadar']);
    Route::delete('/telegram/link', [TelegramController::class, 'unlink']);
});

// Service token for the Python bot
Route::post('/internal/telegram/bind', [TelegramController::class, 'bind'])
    ->middleware('throttle:api-internal');

Route::prefix('/internal/telegram')->middleware([EnsureRadarServiceToken::class, 'throttle:api-internal'])->group(function () {
    Route::post('/session', [TelegramBotController::class, 'session']);
    Route::get('/search', [TelegramBotController::class, 'search']);
    Route::get('/games/{appid}', [TelegramBotController::class, 'card'])->whereNumber('appid');
    Route::get('/favorites', [TelegramBotController::class, 'favorites']);
    Route::put('/favorites', [TelegramBotController::class, 'saveFavorite']);
    Route::delete('/favorites/{appid}', [TelegramBotController::class, 'removeFavorite'])->whereNumber('appid');
    Route::get('/alerts', [TelegramBotController::class, 'alerts']);
    Route::post('/favorites/{appid}/alert/rearm', [TelegramBotController::class, 'rearm'])->whereNumber('appid');
});
