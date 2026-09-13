<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\TopUpController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\ProfileController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::post('/transfer', [TransferController::class, 'transfer']);
    Route::get('/transfer/{transferId}', [TransferController::class, 'show']);
    Route::post('/topup', [TopUpController::class, 'topUp']);
    Route::post('/pay', [PaymentController::class, 'pay']);
    Route::post('/payment', [PaymentController::class, 'pay']);
});
