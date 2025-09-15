<?php

use Illuminate\Support\Facades\Route;
use Modules\Roleandpermission\Http\Controllers\RoleandpermissionController;

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



Route::middleware(['auth:sanctum', '\App\Http\Middleware\subscriptionMiddleware::class'])->group(function () {
    //roles and permisssions
    Route::get('/roles', [RoleandpermissionController::class, 'viewRoles']);
    Route::get('/permissions', [RoleandpermissionController::class, 'viewPermissions']);
    Route::post('/roles/assign', [RoleandpermissionController::class, 'assignPermissions']);
    Route::post('/roles/revoke', [RoleandpermissionController::class, 'revokePermissions']);
    Route::get('/role/permissions', [RoleandpermissionController::class, 'checkRolePermissions']);
});