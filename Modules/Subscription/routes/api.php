<?php

use Illuminate\Support\Facades\Route;
use Modules\Subscription\Http\Controllers\SubscriptionController;
use Modules\Subscription\Http\Controllers\AdminSubscriptionController;
use Modules\Subscription\Http\Controllers\PlanController;
use Modules\Subscription\Http\Controllers\WebhookController;

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

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('subscription', SubscriptionController::class)->names('subscription');
});


Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('subscriptions', [SubscriptionController::class, 'store']);
    Route::get('subscription', [SubscriptionController::class, 'show']);
    Route::put('changesubscription', [SubscriptionController::class, 'update']);
    Route::delete('subscription/{id}', [SubscriptionController::class, 'destroy']);
    Route::apiResource('plans', PlanController::class);
});

Route::post('webhook/razorpay', [WebhookController::class, 'handleRazorpay'])->name('webhook.razorpay');

Route::middleware(['auth:sanctum', '\App\Http\Middleware\EnsureUserIsAdmin::class'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('subscriptions', [AdminSubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::post('subscriptions', [AdminSubscriptionController::class, 'store'])->name('subscriptions.store');
    Route::post('subscriptions/{id}/cancel', [AdminSubscriptionController::class, 'cancel'])->name('subscriptions.cancel');
});
