<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

// Autoloader prioritaire pour les classes locales de gep_entrep_api
spl_autoload_register(function ($class) {
    $baseDir = dirname(__DIR__);
    if (str_starts_with($class, 'App\\')) {
        $file = $baseDir . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (file_exists($file)) {
            require_once $file;
            return true;
        }
    }
    if (str_starts_with($class, 'Database\\Seeders\\')) {
        $file = $baseDir . '/database/seeders/' . str_replace('\\', '/', substr($class, 17)) . '.php';
        if (file_exists($file)) {
            require_once $file;
            return true;
        }
    }
    return false;
}, true, true);

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
