<?php

//*****************************************************************************
//*8 Endpoints hum, the data flows,
//** Tokens guard where no one goes.
//** Requests arrive, responses fly,
//** APIs connect earth to sky
//*****************************************************************************

use App\Http\Controllers\AdminBadgeController;
use App\Http\Controllers\AdminDocumentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChangeOrderController;
use App\Http\Controllers\ContractorProfileController;
use App\Http\Controllers\HandymanController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\ProfileClaimController;
use App\Http\Controllers\ReviewController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::get('/contractors', [ContractorProfileController::class, 'index']);
Route::get('/contractors/{id}', [ContractorProfileController::class, 'show']);
Route::get('/contractors/{id}/reviews', [ReviewController::class, 'contractorReviews']);

// Protected routes
Route::middleware(['auth:api'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);

    Route::get('/jobs/my', [JobController::class, 'myJobs']);
    Route::get('/jobs/{id}', [JobController::class, 'show']);

    Route::post('/jobs/{id}/change-orders', [ChangeOrderController::class, 'store']);
    Route::post('/change-orders/{id}/status', [ChangeOrderController::class, 'updateStatus']);

    Route::middleware(['role:handyman'])->group(function () {
        Route::get('/handyman/profile', [HandymanController::class, 'profile']);
        Route::post('/handyman/profile', [HandymanController::class, 'updateProfile']);
        Route::post('/handyman/skills', [HandymanController::class, 'updateSkills']);
        Route::post('/handyman/documents', [HandymanController::class, 'uploadDocument']);

        Route::get('/contractor/profile', [ContractorProfileController::class, 'myProfile']);
        Route::post('/contractor/profile', [ContractorProfileController::class, 'storeOrUpdate']);

        Route::post('/contractors/{id}/claim', [ProfileClaimController::class, 'store']);
        Route::get('/profile-claims/my', [ProfileClaimController::class, 'myClaims']);

        Route::post('/jobs/{id}/accept', [JobController::class, 'acceptJob']);
        Route::post('/jobs/{id}/start', [JobController::class, 'startJob']);
        Route::post('/jobs/{id}/complete', [JobController::class, 'completeJob']);
    });

    Route::middleware(['role:customer,company'])->group(function () {
        Route::post('/jobs', [JobController::class, 'postJob']);
        Route::get('/jobs/nearby', [JobController::class, 'nearbyHandymen']);
        Route::post('/jobs/{id}/cancel', [JobController::class, 'cancelJob']);
        Route::post('/jobs/{id}/review', [ReviewController::class, 'store']);
        Route::get('/reviews/my', [ReviewController::class, 'myReviews']);
    });

    Route::middleware(['role:customer,handyman,company'])->group(function () {
        Route::post('/jobs/{id}/status', [JobController::class, 'updateStatus']);
    });

    Route::middleware(['role:admin'])->group(function () {
        Route::get('/admin/profile-claims/pending', [ProfileClaimController::class, 'pending']);
        Route::post('/admin/profile-claims/{id}/status', [ProfileClaimController::class, 'updateStatus']);

        Route::get('/admin/documents', [AdminDocumentController::class, 'index']);
        Route::get('/admin/documents/pending', [AdminDocumentController::class, 'pending']);
        Route::get('/admin/documents/{id}', [AdminDocumentController::class, 'show']);
        Route::post('/admin/documents/{id}/status', [AdminDocumentController::class, 'updateStatus']);

        Route::get('/admin/badges', [AdminBadgeController::class, 'index']);
        Route::post('/admin/badges', [AdminBadgeController::class, 'store']);
        Route::post('/admin/contractors/{id}/badges', [AdminBadgeController::class, 'assign']);
        Route::delete('/admin/contractors/{contractorProfileId}/badges/{badgeId}', [AdminBadgeController::class, 'remove']);

        Route::get('/admin/reviews', [ReviewController::class, 'adminIndex']);
        Route::post('/admin/reviews/{id}/visibility', [ReviewController::class, 'adminUpdateVisibility']);
    });
});