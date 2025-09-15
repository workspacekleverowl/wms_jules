<?php

use Illuminate\Support\Facades\Route;
use Modules\Bookkeeping\Http\Controllers\ExpenseController;
use Modules\Bookkeeping\Http\Controllers\SupplierController;
use Modules\Bookkeeping\Http\Controllers\CustomerController;
use Modules\Bookkeeping\Http\Controllers\OrderController;
use Modules\Bookkeeping\Http\Controllers\OrderpaymentsController;
use Modules\Bookkeeping\Http\Controllers\OrderReturnController;
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


Route::middleware(['auth:sanctum', '\App\Http\Middleware\subscriptionMiddleware::class','\App\Http\Middleware\CorsMiddleware::class'])->group(function () {
    //expense type api
    Route::get('/bk_expense_type', [ExpenseController::class, 'bkexpensetypeindex']);
    Route::post('/bk_expense_type', [ExpenseController::class, 'bkexpensetypestore']);
    Route::get('/bk_expense_type/{id}', [ExpenseController::class, 'bkexpensetypeshow']);
    Route::put('/bk_expense_type/{id}', [ExpenseController::class, 'bkexpensetypeupdate']);
    Route::delete('/bk_expense_type/{id}', [ExpenseController::class, 'bkexpensetypedestroy']);
    Route::get('fetchall/bk_expense_type', [ExpenseController::class, 'bkexpensetypefetch']);


    //expense api
    Route::get('/bk_expense', [ExpenseController::class, 'bkexpenseindex']);
    Route::post('/bk_expense', [ExpenseController::class, 'bkexpensestore']);
    Route::get('/bk_expense/{id}', [ExpenseController::class, 'bkexpenseshow']);
    Route::put('/bk_expense/{id}', [ExpenseController::class, 'bkexpenseupdate']);
    Route::delete('/bk_expense/{id}', [ExpenseController::class, 'bkexpensedestroy']);

    //supplier api
    Route::get('/bk_supplier', [SupplierController::class, 'Index']);
    Route::post('/bk_supplier', [SupplierController::class, 'Store']);
    Route::get('/bk_supplier/{id}', [SupplierController::class, 'Show']);
    Route::put('/bk_supplier/{id}', [SupplierController::class, 'Update']);
    Route::delete('/bk_supplier/{id}', [SupplierController::class, 'Destroy']);

    //customer api
    Route::get('/bk_customer', [CustomerController::class, 'Index']);
    Route::post('/bk_customer', [CustomerController::class, 'Store']);
    Route::get('/bk_customer/{id}', [CustomerController::class, 'Show']);
    Route::put('/bk_customer/{id}', [CustomerController::class, 'Update']);
    Route::delete('/bk_customer/{id}', [CustomerController::class, 'Destroy']);

    // order
    Route::get('/bk_purchase', [OrderController::class, 'purchaseindex']);
    Route::get('/bk_sales', [OrderController::class, 'salesindex']);
     Route::get('/bk_purchaseOrder', [OrderController::class, 'purchaseOrderindex']);
    Route::post('/bk_order', [OrderController::class, 'store']);
    Route::get('/bk_order/{id}', [OrderController::class, 'show']);
    Route::put('/bk_order/{id}', [OrderController::class, 'update']);
    Route::delete('/bk_order/{id}', [OrderController::class, 'destroy']);
    Route::get('/bk_getData', [OrderController::class, 'getData']);
    Route::get('/bk_generatePdf/{id}', [OrderController::class, 'generatePdf']);
    

    // order payments
    Route::get('/bk_order_purchasepayments', [OrderpaymentsController::class, 'purchaseindex']);
    Route::get('/bk_order_salespayments', [OrderpaymentsController::class, 'salesindex']);
    Route::post('/bk_order_payments', [OrderpaymentsController::class, 'store']);
    Route::get('/bk_order_payments/{id}', [OrderpaymentsController::class, 'show']);
    Route::put('/bk_order_payments/{id}', [OrderpaymentsController::class, 'update']);
    Route::delete('/bk_order_payments/{id}', [OrderpaymentsController::class, 'destroy']);
    Route::get('/bk_order_payments_summary/{id}', [OrderpaymentsController::class, 'getPaymentSummary']);


    // order returns 
    Route::get('/bk_getOrderStockSummary/{id}', [OrderReturnController::class, 'getOrderStockSummary']);
    Route::post('/bk_orderreturn_store', [OrderReturnController::class, 'store']);
    Route::get('/bk_orderreturn_show/{id}', [OrderReturnController::class, 'show']);
    Route::put('/bk_orderreturn_update/{id}', [OrderReturnController::class, 'update']);
    Route::delete('/bk_orderreturn_delete/{id}', [OrderReturnController::class, 'destroy']);
    Route::get('/bk_orderreturn_purchasereturnindex', [OrderReturnController::class, 'purchasereturnindex']);
    Route::get('/bk_orderreturn_salesreturnindex', [OrderReturnController::class, 'salesreturnindex']);
});

