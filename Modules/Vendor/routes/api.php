<?php

use Illuminate\Support\Facades\Route;
use Modules\Vendor\Http\Controllers\VendorController;
use Modules\Vendor\Http\Controllers\StaffController;
use Modules\Vendor\Http\Controllers\SubscriptionController;
use Modules\Vendor\Http\Controllers\SubscriptionPackageController;

/*
 *--------------------------------------------------------------------------
 * API Routes
 *--------------------------------------------------------------------------
 *
 * Here is where you can register API routes for your application. These
 * routes are loaded by the RouteServiceProvider within a group which
 * is assigned the "api" middleware group. Enjoy building your API!
 *
*/


//vendor authentication routes
Route::post('/login', [VendorController::class, 'login']);
Route::post('/register', [VendorController::class, 'register']);
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/logout', [VendorController::class, 'logout']);
    Route::post('/update-profile', [VendorController::class, 'updateProfile']);
    Route::post('/change-password', [VendorController::class, 'changePassword']);
    Route::post('/change-active-company', [VendorController::class, 'changecmpstate']);
    Route::get('/getprofile', [VendorController::class, 'getprofile']);
    Route::get('/getprofilesettings', [VendorController::class, 'getprofilesettings']);
    Route::get('/getprofilecmp', [VendorController::class, 'getprofilecmp']);
    Route::post('/updateProfileSettings', [VendorController::class, 'updateProfileSettings']);
});

Route::middleware(['auth:sanctum', '\App\Http\Middleware\subscriptionMiddleware::class'])->group(function () {
    //staff
    Route::post('staff', [StaffController::class, 'createStaff']);
    Route::get('staff', [StaffController::class, 'getStaff']);
    Route::put('staff/{staffId}', [StaffController::class, 'updateStaff']);
    Route::get('staff/{staffId}', [StaffController::class, 'getsingleStaff']);
    Route::delete('staff/{staffId}', [StaffController::class, 'deleteStaff']);

});


Route::middleware(['auth:sanctum'])->group(function () {

    // GET all subscription packages (index)
    Route::get('subscription-packages', [SubscriptionPackageController::class, 'index']);
    // GET a specific subscription package by ID (show)
    Route::get('subscription-packages/{id}', [SubscriptionPackageController::class, 'show']);


    Route::middleware([ '\App\Http\Middleware\SuperadminMiddleware::class'])->group(function () {

        // POST a new subscription package (store)
        Route::post('subscription-packages', [SubscriptionPackageController::class, 'store']);
        // PUT/PATCH to update a specific subscription package by ID (update)
        Route::put('subscription-packages/{id}', [SubscriptionPackageController::class, 'update']);
        // DELETE a specific subscription package by ID (destroy)
        Route::delete('subscription-packages/{id}', [SubscriptionPackageController::class, 'destroy']);
    });
});


Route::controller(SubscriptionController::class)->group(function () {

    Route::middleware(['auth:sanctum'])->group(function () {
        // Route for a user to create a new subscription. This will initiate the recurring payment with Razorpay.
        Route::post('/subscriptions/create', 'createSubscription');

        // Route for a user to change their subscription plan.
        Route::post('/subscriptions/change-plan', 'changePlan');
    });

    Route::middleware([ '\App\Http\Middleware\SuperadminMiddleware::class'])->group(function () {
   
        // Route for a Superadmin to assign a non-recurring plan to a tenant.
        Route::post('/subscriptions/assign/{tenantId}', 'assignSubscription');

    });    

   
    // Razorpay Webhook endpoint. Razorpay will send payment events to this URL.
    // This route does not need CSRF protection as it's an API endpoint for a third-party service.
    Route::post('/razorpay/webhook', 'handleRazorpayWebhook');
});

// Protected routes requiring authentication
Route::middleware(['auth:sanctum'])->group(function () {
    
    // User subscription management routes
    Route::prefix('subscriptions')->group(function () {
        Route::post('create', [SubscriptionController::class, 'createSubscription']);
        Route::post('complete-verification', [SubscriptionController::class, 'completeVerification']);
        Route::post('cancel', [SubscriptionController::class, 'cancelSubscription']);
        Route::post('change-plan', [SubscriptionController::class, 'changePlan']);
    });
    
    // Admin routes (add middleware for admin/superadmin role check)
    Route::middleware([ '\App\Http\Middleware\SuperadminMiddleware::class'])->group(function () {
        Route::post('subscriptions/assign/{tenantId}', [SubscriptionController::class, 'assignSubscription']);
    });
});

// Webhook route (no auth required - verified by signature)
Route::post('webhooks/razorpay', [SubscriptionController::class, 'handleRazorpayWebhook']);