<?php

declare(strict_types=1);

use App\Providers\StandServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        StandServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
    )
    ->withMiddleware(static function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: ['*']);
        $middleware->encryptCookies(except: ['stand_token']);
    })
    ->withExceptions(static function (Exceptions $exceptions): void {})
    ->create();
