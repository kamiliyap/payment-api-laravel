<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\ProfileController;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware(['api', 'auth:sanctum'])->group(function () {
                Route::put('/profile', [ProfileController::class, 'update']);
                Route::get('/transactions', [TransactionController::class, 'index']);
                Route::post('/transfer', [TransferController::class, 'transfer']);
                Route::get('/transfer/{transferId}', [TransferController::class, 'show']);
            });
            Route::middleware(['api', 'auth:sanctum'])
                ->post('/pay', [PaymentController::class, 'pay']);
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('api/*', 'pay', 'transfer', 'transfer/*', 'transactions', 'profile')) {
                return null;
            }

            return '/login';
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (
            AuthenticationException $e,
            Request $request
        ) {
            if ($request->is('api/*', 'pay', 'transfer', 'transfer/*', 'transactions', 'profile')) {
                return response()->json([
                    'message' => 'Unauthenticated'
                ], 401);
            }
        });
    })->create();
