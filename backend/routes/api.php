<?php

//*****************************************************************************
//*8 Endpoints hum, the data flows,
//** Tokens guard where no one goes.
//** Requests arrive, responses fly,
//** APIs connect earth to sky
//*****************************************************************************

use App\Http\Controllers\AdminBadgeController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminDocumentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChangeOrderController;
use App\Http\Controllers\ContractorProfileController;
use App\Http\Controllers\DisputeController;
use App\Http\Controllers\HandymanController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\ProfileClaimController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReviewController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);
Route::post('/jobs/{id}/images', [JobController::class, 'uploadImages']);
Route::delete('/jobs/{jobId}/images/{imageId}', [JobController::class, 'deleteImage']);
Route::delete('/admin/users/{id}',[AdminDashboardController::class,'deleteUser']);

Route::get('/contractors', [ContractorProfileController::class, 'index']);
Route::get('/contractors/{id}', [ContractorProfileController::class, 'show']);
Route::get('/contractors/{id}/reviews', [ReviewController::class, 'contractorReviews']);

// Protected routes
Route::middleware(['auth:api'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/me/update', [AuthController::class, 'updateMe']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);

    Route::get('/jobs/my', [JobController::class, 'myJobs']);
    Route::get('/jobs/nearby', [JobController::class, 'nearbyHandymen']);
    Route::get('/jobs/{id}', [JobController::class, 'show']);

    Route::post('/jobs/{id}/change-orders', [ChangeOrderController::class, 'store']);
    Route::post('/change-orders/{id}/status', [ChangeOrderController::class, 'updateStatus']);

    Route::post('/reports', [ReportController::class, 'store']);
    Route::get('/reports/my', [ReportController::class, 'myReports']);

    Route::post('/jobs/{id}/disputes', [DisputeController::class, 'store']);
    Route::get('/disputes/my', [DisputeController::class, 'myDisputes']);

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
        Route::post('/jobs/{id}/cancel', [JobController::class, 'cancelJob']);
        Route::post('/jobs/{id}/review', [ReviewController::class, 'store']);
        Route::get('/reviews/my', [ReviewController::class, 'myReviews']);
    });

    Route::middleware(['role:customer,handyman,company'])->group(function () {
        Route::post('/jobs/{id}/status', [JobController::class, 'updateStatus']);
        Route::put(
            '/jobs/{id}',
            [JobController::class, 'update']
        );
        Route::delete(
            '/jobs/{id}',
            [JobController::class, 'destroy']
        );

    Route::post('/jobs/{id}/cancel', [JobController::class, 'cancelJob']);

    Route::post('/jobs/{id}/review', [ReviewController::class, 'store']);

    Route::get('/reviews/my', [ReviewController::class, 'myReviews']);
    });

    Route::middleware(['role:admin'])->group(function () {
        Route::get('/admin/dashboard/stats', [AdminDashboardController::class, 'stats']);
        Route::get('/admin/dashboard/activity', [AdminDashboardController::class, 'activity']);
        Route::get('/admin/users', [AdminDashboardController::class, 'users']);
        Route::get('/admin/contractors', [AdminDashboardController::class, 'contractors']);
        Route::get('/admin/jobs', [AdminDashboardController::class, 'jobs']);

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

        Route::get('/admin/reports', [ReportController::class, 'adminIndex']);
        Route::post('/admin/reports/{id}/status', [ReportController::class, 'adminUpdateStatus']);
        Route::post('/admin/users/{id}/account-status', [ReportController::class, 'adminSuspendUser']);
        Route::post('/admin/contractor-profiles/{id}/status', [ReportController::class, 'adminUpdateContractorProfileStatus']);

        Route::get('/admin/disputes', [DisputeController::class, 'adminIndex']);
        Route::post('/admin/disputes/{id}/status', [DisputeController::class, 'adminUpdateStatus']);
    });
});