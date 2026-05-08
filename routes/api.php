<?php

//*****************************************************************************
//*8 Endpoints hum, the data flows,
//** Tokens guard where no one goes.
//** Requests arrive, responses fly,
//** APIs connect earth to sky
//*****************************************************************************

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContractorProfileController;
use App\Http\Controllers\HandymanController;
use App\Http\Controllers\JobController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::get('/contractors', [ContractorProfileController::class, 'index']);
Route::get('/contractors/{id}', [ContractorProfileController::class, 'show']);

// Protected routes
Route::middleware(['auth:api'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);

    Route::middleware(['role:handyman'])->group(function () {
        Route::get('/handyman/profile', [HandymanController::class, 'profile']);
        Route::post('/handyman/profile', [HandymanController::class, 'updateProfile']);
        Route::post('/handyman/skills', [HandymanController::class, 'updateSkills']);
        Route::post('/handyman/documents', [HandymanController::class, 'uploadDocument']);

        Route::get('/contractor/profile', [ContractorProfileController::class, 'myProfile']);
        Route::post('/contractor/profile', [ContractorProfileController::class, 'storeOrUpdate']);

        Route::post('/jobs/{id}/accept', [JobController::class, 'acceptJob']);
    });

    Route::middleware(['role:customer,company'])->group(function () {
        Route::post('/jobs', [JobController::class, 'postJob']);
        Route::get('/jobs/nearby', [JobController::class, 'nearbyHandymen']);
    });

    Route::middleware(['role:customer,handyman,company'])->group(function () {
        Route::post('/jobs/{id}/status', [JobController::class, 'updateStatus']);
    });
});