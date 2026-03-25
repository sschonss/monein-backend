<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CurrencyController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\RecurringTransactionController;
use App\Http\Controllers\Api\V1\TagController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\Api\V1\ImportController;
use App\Http\Controllers\Api\V1\InvestmentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);

    // Protected
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/user', [AuthController::class, 'user']);

        Route::apiResource('categories', CategoryController::class);
        Route::apiResource('tags', TagController::class);
        Route::apiResource('transactions', TransactionController::class);
        Route::apiResource('recurring-transactions', RecurringTransactionController::class);
        Route::post('/recurring-transactions/{id}/toggle', [RecurringTransactionController::class, 'toggle']);
        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/currency/rate', [CurrencyController::class, 'rate']);

        Route::post('/import/picpay', [ImportController::class, 'picpay']);
        Route::post('/import/confirm-global', [ImportController::class, 'confirmGlobal']);

        Route::get('/investments/global-account', [InvestmentController::class, 'globalAccount']);
        Route::get('/investments/summary', [InvestmentController::class, 'summary']);
        Route::get('/investments/accounts', [InvestmentController::class, 'accounts']);
        Route::get('/investments/accounts/{id}', [InvestmentController::class, 'show']);
        Route::post('/investments/import/cofrinho', [InvestmentController::class, 'importCofrinho']);
        Route::delete('/investments/accounts/{id}', [InvestmentController::class, 'destroy']);

        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::put('/profile/password', [ProfileController::class, 'updatePassword']);
        Route::delete('/profile', [ProfileController::class, 'destroy']);
    });
});
