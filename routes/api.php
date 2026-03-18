<?php

//*****************************************************************************
//*8 Endpoints hum, the data flows,
//** Tokens guard where no one goes.
//** Requests arrive, responses fly,
//** APIs connect earth to sky
//*****************************************************************************

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\HandymanController;
use App\Http\Controllers\JobController;

Route::middleware(['auth:api'])->group(function(){
    Route::get('/handyman/profile',[HandymanController::class,'profile']);
    Route::post('/handyman/profile',[HandymanController::class,'updateProfile']);
    Route::post('/handyman/skills',[HandymanController::class,'updateSkills']);
    Route::post('/handyman/documents',[HandymanController::class,'uploadDocument']);

    Route::post('/jobs',[JobController::class,'postJob']);
    Route::get('/jobs/nearby',[JobController::class,'nearbyHandymen']);
    Route::post('/jobs/{id}/accept',[JobController::class,'acceptJob']);
    Route::post('/jobs/{id}/status',[JobController::class,'updateStatus']);
});

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware(['auth.api'])->group(function () {
	Route::get('/me', [AuthController::class, 'me']);
});

