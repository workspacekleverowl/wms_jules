<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\SuperadminController;
use App\Http\Controllers\API\GoogleAuthController;

//superadmin authentication routes
Route::post('superadmin', [SuperadminController::class, 'createSuperadmin']);
Route::post('/superadminlogin', [SuperadminController::class, 'login']);
Route::middleware(['auth:sanctum', '\App\Http\Middleware\SuperadminMiddleware::class'])->group(function () {
    Route::put('superadmin/edit/{superadminId}', [SuperadminController::class, 'editSuperadmin']);
    Route::get('superadmin/show', [SuperadminController::class, 'showSuperadmin']);
    Route::delete('superadmin/delete', [SuperadminController::class, 'deleteSuperadmin']);
    Route::get('tenants-and-admins', [SuperadminController::class, 'showTenantsAndAdmins']);
    Route::post('login-as-user', [SuperadminController::class, 'loginasuser']);
});    


Route::post('/verify-google-token', [GoogleAuthController::class, 'verifyGoogleToken']);



