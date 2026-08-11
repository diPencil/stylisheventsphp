<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\AuthenticationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => \App\Http\Middleware\RequireRole::class,
            'permission' => \App\Http\Middleware\RequirePermission::class,
            'any_permission' => \App\Http\Middleware\RequireAnyPermission::class,
        ]);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/login');

        // CORS Configuration
        $frontendUrls = explode(',', env('FRONTEND_URLS', env('FRONTEND_URL', 'http://localhost:3000')));
        $allowedOrigins = array_map('trim', array_filter($frontendUrls));

        // Let Laravel handle CORS using default middleware, but we can configure it in config/cors.php
        // Instead of overriding here, we will configure config/cors.php for CORS.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render 404
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::fail('Route not found', 404, ['path' => $request->path()]);
            }
        });

        // Render 405 Method Not Allowed
        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::fail('Method not allowed', 405);
            }
        });

        // Render Validation Exceptions (400 to match Node)
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $details = [
                    'formErrors' => [],
                    'fieldErrors' => $e->errors()
                ];
                return ApiResponse::fail('Validation failed', 400, $details);
            }
        });

        // Render Authentication Exceptions (401)
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::fail('Unauthenticated', 401);
            }
        });

        // Default JSON error handler for anything else
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return true;
            }
            return $request->expectsJson();
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                // If it's an HttpException, we can get the status code, otherwise 500
                $status = $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                    ? $e->getStatusCode()
                    : 500;

                $message = $status >= 500 ? 'Internal server error' : ($e->getMessage() ?: 'Request failed');
                // Use a default message for 405
                if ($e instanceof MethodNotAllowedHttpException) {
                    $message = 'Method not allowed';
                }
                $error = $status >= 500 ? $e->getMessage() : null;

                return ApiResponse::fail($message, $status, null, $error);
            }
        });

    })->create();
