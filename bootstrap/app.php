<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (app()->environment('testing') || $request->expectsJson()) {
                return null;
            }

            if ($exception instanceof AuthenticationException
                || $exception instanceof AuthorizationException
                || $exception instanceof ValidationException
                || $exception instanceof TokenMismatchException
                || $exception instanceof ModelNotFoundException
                || $exception instanceof HttpResponseException) {
                return null;
            }

            Log::error('Web request failed.', [
                'request_id' => $request->attributes->get('request_id'),
                'request_method' => $request->method(),
                'request_path' => $request->path(),
                'exception' => $exception,
            ]);

            $isDatabaseConnectionError = $exception instanceof QueryException
                && (in_array((int) $exception->getCode(), [2002, 2003, 2006], true)
                    || str_contains($exception->getMessage(), 'SQLSTATE[HY000] [2002]'));

            if ($isDatabaseConnectionError) {
                return response()->view('errors.database', [], 503);
            }

            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;

            if (! in_array($status, [404, 419, 429, 500, 502, 503, 504], true)) {
                return null;
            }

            $view = view()->exists("errors.{$status}") ? "errors.{$status}" : 'errors.500';

            return response()->view($view, [], $status);
        });
    })->create();
