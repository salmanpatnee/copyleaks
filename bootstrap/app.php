<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Outside the web group on purpose: Copyleaks sends no session and no CSRF token.
            Route::group([], base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The post was bigger than PHP allows. The session has not started yet, so the notice travels in the URL.
        // Use respond(), not render(): behind Herd's PHP-FPM a render() callback for this error never gets its response sent, so the page hangs.
        $exceptions->respond(fn (Response $response, Throwable $e, Request $request): Response => $e instanceof PostTooLargeException && $request->is('submissions') && $request->isMethod('post')
            ? to_route('submissions.create', ['notice' => 'too-large'])
            : $response);

        $exceptions->render(fn (ThrottleRequestsException $e, Request $request): ?Response => $request->is('submissions') && $request->isMethod('post')
            ? back()->withErrors(['file' => 'Too many uploads. Please wait a minute and try again.'])
            : null);

        $exceptions->render(fn (ThrottleRequestsException $e, Request $request): ?Response => $request->is('submissions/*/scans') && $request->isMethod('post')
            ? back()->withErrors(['scan' => 'Too many tries. Please wait a minute and try again.'])
            : null);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
