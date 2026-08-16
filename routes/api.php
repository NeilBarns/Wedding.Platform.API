<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\TimeZoneController;
use App\Http\Controllers\WebsiteDraftController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/events', [EventController::class, 'index']);
    Route::post('/events', [EventController::class, 'store']);
    Route::get('/events/{event}', [EventController::class, 'show']);
    Route::put('/events/{event}/timing', [EventController::class, 'updateTiming']);
    Route::get('/time-zones', [TimeZoneController::class, 'index']);

    Route::get('/events/{event}/website', [WebsiteDraftController::class, 'show']);
    Route::post('/events/{event}/website', [WebsiteDraftController::class, 'store']);
    Route::get('/events/{event}/website/templates', [WebsiteDraftController::class, 'templates']);
    Route::put('/events/{event}/website/template', [WebsiteDraftController::class, 'updateTemplate']);
    Route::put('/events/{event}/website/design', [WebsiteDraftController::class, 'updateDesign']);
    Route::put('/events/{event}/website/sections/order', [WebsiteDraftController::class, 'reorder']);
    Route::put('/events/{event}/website/sections/{section}/enabled', [WebsiteDraftController::class, 'updateSectionEnabled']);
    Route::put('/events/{event}/website/sections/{section}/appearance', [WebsiteDraftController::class, 'updateSectionAppearance']);
    Route::put('/events/{event}/website/sections/{section}', [WebsiteDraftController::class, 'updateSection']);
});
