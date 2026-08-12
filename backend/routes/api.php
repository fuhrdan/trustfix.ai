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
use App\Http\Controllers\ContractorDashboardController;
use App\Http\Controllers\DisputeController;
use App\Http\Controllers\HandymanController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\JobWorkspaceController;
use App\Http\Controllers\ProfileClaimController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\PropertyController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\JobEstimateController;
use App\Http\Controllers\EstimatePricingProfileController;
use App\Http\Controllers\MaterialPriceController;
use App\Http\Controllers\EstimateTrainingDataController;
use App\Http\Controllers\EstimateAccuracyController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');
Route::post('/email/verification-notification', [AuthController::class, 'resendVerification'])
    ->middleware('throttle:6,1');
Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
    ->middleware('throttle:6,1')
    ->name('verification.verify');

Route::get('/contractors', [ContractorProfileController::class, 'index']);
Route::get('/contractors/{id}', [ContractorProfileController::class, 'show']);
Route::get('/contractors/{id}/reviews', [ReviewController::class, 'contractorReviews']);
Route::get('/payments/config', [PaymentController::class, 'publicConfig']);
Route::post('/stripe/webhook', [PaymentController::class, 'webhook']);

// Protected routes
Route::middleware(['auth:api', 'account.active'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/me/update', [AuthController::class, 'updateMe']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);

    Route::get('/jobs/my', [JobController::class, 'myJobs']);
    Route::get('/jobs/available', [JobController::class, 'availableJobs']);
    Route::get('/jobs/nearby', [JobController::class, 'nearbyHandymen']);
    Route::get('/jobs/{id}', [JobController::class, 'show']);
    Route::get('/jobs/{id}/workspace', [JobWorkspaceController::class, 'show']);
    Route::get('/jobs/{id}/messages', [JobWorkspaceController::class, 'messages']);
    Route::post('/jobs/{id}/messages', [JobWorkspaceController::class, 'storeMessage']);
    Route::get('/jobs/{id}/activities', [JobWorkspaceController::class, 'activities']);
    Route::get('/jobs/{id}/estimate', [JobEstimateController::class, 'show']);
    Route::post('/jobs/{id}/estimate/analyze', [JobEstimateController::class, 'analyze']);
    Route::put('/jobs/{id}/estimate', [JobEstimateController::class, 'update']);
    Route::post('/jobs/{id}/estimate/quote', [JobEstimateController::class, 'quote']);
    Route::post('/jobs/{id}/estimate/accept', [JobEstimateController::class, 'accept']);
    Route::post('/jobs/{id}/estimate/actuals', [JobEstimateController::class, 'actuals']);
    Route::get('/jobs/{id}/estimate/revisions', [JobEstimateController::class, 'revisions']);
    Route::get('/estimate-pricing-profile', [EstimatePricingProfileController::class, 'show']);
    Route::put('/estimate-pricing-profile', [EstimatePricingProfileController::class, 'update']);

    // Job creation and image upload are authenticated-user actions.
    // The controller still checks ownership for update/upload/delete.
    Route::post('/jobs', [JobController::class, 'postJob']);
    Route::put('/jobs/{id}', [JobController::class, 'update']);
    Route::post('/jobs/{id}/images', [JobController::class, 'uploadImages']);
    Route::delete('/jobs/{jobId}/images/{imageId}', [JobController::class, 'deleteImage']);
    Route::post('/properties/{id}/images', [PropertyController::class, 'uploadImage']);
    Route::delete('/property-images/{imageId}', [PropertyController::class, 'deleteImage']);

    Route::get('/contractor/profile', [ContractorProfileController::class, 'myProfile']);
    Route::post('/contractor/profile', [ContractorProfileController::class, 'storeOrUpdate']);
    Route::get('/contractor/documents', [ContractorProfileController::class, 'myDocuments']);
    Route::post('/contractor/documents', [ContractorProfileController::class, 'uploadDocument']);
    Route::get('/contractor/dashboard', [ContractorDashboardController::class, 'show']);
    Route::post('/contractor/payout-account', [PaymentController::class, 'createConnectAccount']);
    Route::post('/contractor/payout-account/refresh', [PaymentController::class, 'refreshConnectAccount']);
    Route::post('/jobs/{id}/payment-intent', [PaymentController::class, 'createIntent']);

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

        Route::post('/contractors/{id}/claim', [ProfileClaimController::class, 'store']);
        Route::get('/profile-claims/my', [ProfileClaimController::class, 'myClaims']);

        Route::post('/jobs/{id}/accept', [JobController::class, 'acceptJob']);
        Route::post('/jobs/{id}/start', [JobController::class, 'startJob']);
        Route::post('/jobs/{id}/complete', [JobController::class, 'completeJob']);
    });

    Route::middleware(['role:customer,company'])->group(function () {
        Route::post('/jobs/{id}/cancel', [JobController::class, 'cancelJob']);
        Route::post('/jobs/{id}/review', [ReviewController::class, 'store']);
        Route::get('/reviews/my', [ReviewController::class, 'myReviews']);
    });

    Route::middleware(['role:customer,handyman,company,admin'])->group(function () {
        Route::post('/jobs/{id}/status', [JobController::class, 'updateStatus']);
        Route::delete('/jobs/{id}', [JobController::class, 'destroy']);
    });

    Route::middleware(['role:admin'])->group(function () {
        Route::get('/admin/dashboard/stats', [AdminDashboardController::class, 'stats']);
        Route::get('/admin/dashboard/activity', [AdminDashboardController::class, 'activity']);
        Route::get('/admin/users', [AdminDashboardController::class, 'users']);
        Route::delete('/admin/users/{id}', [AdminDashboardController::class, 'deleteUser']);
        Route::get('/admin/contractors', [AdminDashboardController::class, 'contractors']);
        Route::get('/admin/jobs', [AdminDashboardController::class, 'jobs']);
        Route::delete('/admin/jobs/{id}', [JobController::class, 'destroy']);
        
        Route::get('/admin/profile-claims/pending', [ProfileClaimController::class, 'pending']);
        Route::post('/admin/profile-claims/{id}/status', [ProfileClaimController::class, 'updateStatus']);

        Route::get('/admin/documents', [AdminDocumentController::class, 'index']);
        Route::get('/admin/documents/pending', [AdminDocumentController::class, 'pending']);
        Route::get('/admin/documents/{id}', [AdminDocumentController::class, 'show']);
        Route::post('/admin/documents/{id}/status', [AdminDocumentController::class, 'updateStatus']);
        Route::post('/admin/contractor-documents/{id}/status', [AdminDashboardController::class, 'updateContractorDocumentStatus']);

        Route::get('/admin/badges', [AdminBadgeController::class, 'index']);
        Route::post('/admin/badges', [AdminBadgeController::class, 'store']);
        Route::post('/admin/contractors/{id}/badges', [AdminBadgeController::class, 'assign']);
        Route::delete('/admin/contractors/{contractorProfileId}/badges/{badgeId}', [AdminBadgeController::class, 'remove']);

        Route::get('/admin/reviews', [ReviewController::class, 'adminIndex']);
        Route::post('/admin/reviews/{id}/visibility', [ReviewController::class, 'adminUpdateVisibility']);

        Route::get(
            '/admin/users/{id}',
            [AdminDashboardController::class, 'getUser']
        );

        Route::put(
            '/admin/users/{id}',
            [AdminDashboardController::class, 'updateUser']
        );

        Route::post(
            '/admin/users/{id}/reset-password',
            [AdminDashboardController::class, 'resetUserPassword']
        );
        
        Route::get('/admin/reports', [ReportController::class, 'adminIndex']);
        Route::post('/admin/reports/{id}/status', [ReportController::class, 'adminUpdateStatus']);
        Route::post('/admin/users/{id}/account-status', [ReportController::class, 'adminSuspendUser']);
        Route::post('/admin/contractor-profiles/{id}/status', [ReportController::class, 'adminUpdateContractorProfileStatus']);

        Route::get('/admin/disputes', [DisputeController::class, 'adminIndex']);
        Route::post('/admin/disputes/{id}/status', [DisputeController::class, 'adminUpdateStatus']);

        Route::get('/admin/material-prices', [MaterialPriceController::class, 'index']);
        Route::post('/admin/material-prices', [MaterialPriceController::class, 'store']);
        Route::put('/admin/material-prices/{id}', [MaterialPriceController::class, 'update']);
        Route::delete('/admin/material-prices/{id}', [MaterialPriceController::class, 'destroy']);
        Route::get('/admin/estimate-training-data', [EstimateTrainingDataController::class, 'index']);
        Route::get('/admin/estimate-accuracy', [EstimateAccuracyController::class, 'index']);
    });
    
    Route::middleware('auth:api')->group(function ()
    {
        Route::post('/properties/{id}/authorized-users', [PropertyController::class, 'addAuthorizedUser']);
        Route::delete('/properties/{id}/authorized-users/{userId}', [PropertyController::class, 'removeAuthorizedUser']);

        Route::apiResource(
            'properties',
            PropertyController::class
        );

        // Keep the existing property-list endpoint mapped to myProperties().
        // This returns both owned properties and properties shared with the user.
        Route::get('/properties', [PropertyController::class, 'myProperties']);
    });
});
