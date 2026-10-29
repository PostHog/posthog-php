<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // PostHog's discovered provider installs scoped request context automatically.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Laravel owns reporting. The discovered PostHog provider adds a report callback.
    })
    ->create();
