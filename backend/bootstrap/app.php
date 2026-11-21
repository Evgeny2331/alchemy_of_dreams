<?php

use App\Modules\Auth\Middleware\SessionAuthMiddleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'player.session' => SessionAuthMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if (!($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            if ($e instanceof HttpResponseException) {
                return $e->getResponse();
            }

            $requestId = (string) Str::uuid();

            if ($e instanceof ValidationException) {
                return response()->json([
                    'error' => [
                        'code' => 'VALIDATION_ERROR',
                        'message' => $e->getMessage(),
                        'details' => $e->errors(),
                    ],
                    'meta' => [
                        'request_id' => $requestId,
                    ],
                ], 400);
            }

            if ($e instanceof AuthenticationException) {
                return response()->json([
                    'error' => [
                        'code' => 'UNAUTHENTICATED',
                        'message' => 'Unauthenticated.',
                    ],
                    'meta' => [
                        'request_id' => $requestId,
                    ],
                ], 401);
            }

            if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
                return response()->json([
                    'error' => [
                        'code' => 'RESOURCE_NOT_FOUND',
                        'message' => 'Resource not found.',
                    ],
                    'meta' => [
                        'request_id' => $requestId,
                    ],
                ], 404);
            }

            if ($e instanceof ThrottleRequestsException) {
                return response()->json([
                    'error' => [
                        'code' => 'RATE_LIMITED',
                        'message' => 'Too many requests.',
                    ],
                    'meta' => [
                        'request_id' => $requestId,
                    ],
                ], 429);
            }

            $message = $e->getMessage();
            $lower = strtolower($message);

            if ($message === 'TELEGRAM_AUTH_INVALID') {
                return response()->json([
                    'error' => [
                        'code' => 'TELEGRAM_AUTH_INVALID',
                        'message' => 'Invalid Telegram initData.',
                    ],
                    'meta' => [
                        'request_id' => $requestId,
                    ],
                ], 401);
            }

            if ($e instanceof DomainException && str_contains($lower, 'world phase schedule gap')) {
                Log::warning('World phase schedule gap detected.', [
                    'request_id' => $requestId,
                    'error' => $message,
                ]);

                return response()->json([
                    'error' => [
                        'code' => 'WORLD_PHASE_UNAVAILABLE',
                        'message' => 'World phase schedule integrity error.',
                    ],
                    'meta' => [
                        'request_id' => $requestId,
                    ],
                ], 503);
            }

            if (str_contains($lower, 'idempotency key reused')) {
                return response()->json([
                    'error' => [
                        'code' => 'IDEMPOTENCY_KEY_REUSED',
                        'message' => 'Idempotency key reused with different request payload.',
                    ],
                    'meta' => [
                        'request_id' => $requestId,
                    ],
                ], 409);
            }

            if (str_contains($lower, 'insufficient') && str_contains($lower, 'balance')) {
                return response()->json([
                    'error' => [
                        'code' => 'INSUFFICIENT_RESOURCES',
                        'message' => $message,
                    ],
                    'meta' => [
                        'request_id' => $requestId,
                    ],
                ], 409);
            }

            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();
                $code = match ($status) {
                    401 => 'UNAUTHENTICATED',
                    403 => 'FORBIDDEN',
                    404 => 'RESOURCE_NOT_FOUND',
                    409 => 'INVALID_GAME_STATE',
                    422 => 'UNSUPPORTED_OPERATION',
                    429 => 'RATE_LIMITED',
                    503 => 'WORLD_PHASE_UNAVAILABLE',
                    default => $status >= 500 ? 'INTERNAL_ERROR' : 'VALIDATION_ERROR',
                };

                return response()->json([
                    'error' => [
                        'code' => $code,
                        'message' => $status >= 500 ? 'Internal server error.' : ($e->getMessage() ?: 'Request failed.'),
                    ],
                    'meta' => [
                        'request_id' => $requestId,
                    ],
                ], $status);
            }

            return response()->json([
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'Internal server error.',
                ],
                'meta' => [
                    'request_id' => $requestId,
                ],
            ], 500);
        });
    })->create();
