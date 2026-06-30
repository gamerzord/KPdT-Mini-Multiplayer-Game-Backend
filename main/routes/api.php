<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\MatchmakingController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\RankingController;
use App\Http\Controllers\UserController;

// ── Public routes ─────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('/register', [LoginController::class, 'register']);
    Route::post('/login',    [LoginController::class, 'login']);
});

// ── Protected routes (JWT required) ───────────────────
Route::middleware('jwt.auth')->group(function () {

    // Auth
    Route::prefix('auth')->group(function () {
        Route::post('/logout',  [LoginController::class, 'logout']);
        Route::post('/refresh', [LoginController::class, 'refresh']);
        Route::get('/me',       [LoginController::class, 'me']);
    });

    // User Service
    Route::prefix('users')->group(function () {
        Route::get('/',        [UserController::class, 'index']);
        Route::get('/{id}',    [UserController::class, 'show']);
        Route::put('/profile', [UserController::class, 'updateProfile']);
    });

    // Matchmaking Service
    Route::prefix('matchmaking')->group(function () {
        Route::post('/join',    [MatchmakingController::class, 'join']);
        Route::post('/leave',   [MatchmakingController::class, 'leave']);
        Route::get('/status',   [MatchmakingController::class, 'status']);
        Route::post('/finish',  [MatchmakingController::class, 'finish']); // ← was missing
    });

    // Chat Service
    Route::prefix('chat')->group(function () {
        Route::post('/send',     [ChatController::class, 'send']);
        Route::get('/{matchId}', [ChatController::class, 'history']);
    });

    // Ranking Service
    Route::prefix('ranking')->group(function () {
        Route::get('/leaderboard', [RankingController::class, 'leaderboard']);
        Route::get('/me',          [RankingController::class, 'myRank']);
    });
});

Route::get('/debug/which-server', function () {
    return response()->json([
        'hostname' => gethostname(), // returns container ID, different per container
        'time'     => now(),
    ]);
});
