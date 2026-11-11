<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\ExpenseCommentController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\SettlementController;
use App\Http\Controllers\Api\V1\TripController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/invitations/accept', [InvitationController::class, 'accept'])->middleware('throttle:10,1');
        Route::post('/private-access/verify', [InvitationController::class, 'verifyOtp'])->middleware('throttle:5,1');
        Route::post('/trips/{trip}/private-access/request', [InvitationController::class, 'requestOtp'])->middleware('throttle:3,1');
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/trips', [TripController::class, 'index']);
        Route::get('/trips/discover', [TripController::class, 'discover']);
        Route::post('/trips', [TripController::class, 'store']);
        Route::get('/trips/{trip}', [TripController::class, 'show']);
        Route::patch('/trips/{trip}', [TripController::class, 'update']);
        Route::post('/trips/{trip}/join', [TripController::class, 'join']);
        Route::delete('/trips/{trip}/leave', [TripController::class, 'leave']);
        Route::post('/trips/{trip}/invitations', [InvitationController::class, 'store']);
        Route::get('/trips/{trip}/expenses', [ExpenseController::class, 'index']);
        Route::post('/trips/{trip}/expenses', [ExpenseController::class, 'store']);
        Route::get('/trips/{trip}/expenses/{expense}/comments', [ExpenseCommentController::class, 'index']);
        Route::post('/trips/{trip}/expenses/{expense}/comments', [ExpenseCommentController::class, 'store']);
        Route::get('/trips/{trip}/settlements', [SettlementController::class, 'index']);
        Route::post('/trips/{trip}/settlements/preview', [SettlementController::class, 'preview']);
        Route::post('/trips/{trip}/settlements', [SettlementController::class, 'store']);
    });
});
