<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['superadmin' => \App\Http\Middleware\RequireSuperadmin::class]);
        $middleware->web(append: [
            \App\Http\Middleware\SecurityHeaders::class,
            \Illuminate\Session\Middleware\AuthenticateSession::class,
        ]);
        // Wrap authentication failures too, once the session is available.
        $middleware->appendToPriorityList(
            \Illuminate\Session\Middleware\StartSession::class,
            \App\Http\Middleware\SecurityHeaders::class,
        );
        $middleware->redirectGuestsTo(fn () => '/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn ($request, $e) => $request->is('api/*') || $request->expectsJson());
    })->create();
