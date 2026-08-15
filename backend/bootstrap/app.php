<?php

use App\Http\Middleware\EnsureAdminRole;
use App\Http\Middleware\EnsureOwnerRole;
use App\Support\EnvironmentList;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Production must name the internal reverse-proxy networks explicitly.
        $middleware->trustProxies(at: EnvironmentList::parse(
            env('TRUSTED_PROXIES'),
            ['127.0.0.1', '::1'],
        ));
        $middleware->alias([
            'admin-role' => EnsureAdminRole::class,
            'owner-role' => EnsureOwnerRole::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('prices:dispatch-due')->everyMinute()->withoutOverlapping()->onOneServer();
        $schedule->command('prices:refresh-rates')->dailyAt('03:10')->withoutOverlapping()->onOneServer();
        $schedule->command('ops:snapshot --hours=24')->hourly()->withoutOverlapping()->onOneServer();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/admin/*')) {
                return null;
            }

            if ($exception instanceof ValidationException) {
                return response()->json([
                    'message' => 'Данные запроса не прошли проверку',
                    'errors' => $exception->errors(),
                ], 422);
            }

            if ($exception instanceof AuthenticationException) {
                return response()->json(['message' => 'Требуется аутентификация'], 401);
            }

            $status = $exception instanceof HttpExceptionInterface
                ? $exception->getStatusCode()
                : 500;
            $message = match ($status) {
                403 => 'Доступ запрещён',
                404 => 'Ресурс не найден',
                405 => 'Метод не поддерживается',
                429 => 'Слишком много запросов',
                default => $status >= 500 ? 'Внутренняя ошибка сервера' : 'Запрос не выполнен',
            };
            $headers = $exception instanceof HttpExceptionInterface
                ? $exception->getHeaders()
                : [];

            return response()->json(['message' => $message], $status, $headers);
        });
    })->create();
