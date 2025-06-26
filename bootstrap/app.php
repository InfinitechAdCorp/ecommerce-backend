<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',         // Web routes
        api: __DIR__.'/../routes/api.php',         // API routes (newly added)
        commands: __DIR__.'/../routes/console.php', // Console routes
        health: '/up'                             // Health check route
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Define any global middleware for the app here
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Define custom exception handling here if needed
    })
    ->create();
