<?php

use Illuminate\Support\Facades\Route;
use Modules\Masters\Http\Controllers\MastersController;
use Modules\Masters\Http\Controllers\TransporterController;
use Modules\Masters\Http\Controllers\CompanyController;
use Modules\Masters\Http\Controllers\ProductcategoryController;
use Modules\Masters\Http\Controllers\PartyController;
use Modules\Masters\Http\Controllers\ProductController;
use Modules\Masters\Http\Controllers\VoucherController;
use Modules\Masters\Http\Controllers\FinancialyearController;
use Modules\Masters\Http\Controllers\TestController;
use Modules\Masters\Http\Controllers\LedgerController;
use Modules\Masters\Http\Controllers\ScrapController;
use Modules\Masters\Http\Controllers\ReportController;
use Modules\Masters\Http\Controllers\InvoiceController;
use Modules\Masters\Http\Controllers\QuotationController;
use Modules\Masters\Http\Controllers\PredispatchInspectionController;
use Modules\Masters\Http\Controllers\DashboardController;
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

//common masters
Route::get('/states', [MastersController::class, 'viewstates']);
Route::get('/financialyear', [FinancialyearController::class, 'getAllFinancialYears']);



Route::middleware(['auth:sanctum', '\App\Http\Middleware\subscriptionMiddleware::class','\App\Http\Middleware\CorsMiddleware::class'])->group(function () {


    
   
    //transporter
    Route::get('/transporter', [TransporterController::class, 'index']);
    Route::post('/transporter', [TransporterController::class, 'store']);
    Route::get('/transporter/{id}', [TransporterController::class, 'show']);
    Route::put('/transporter/{id}', [TransporterController::class, 'update']);
    //Route::delete('/transporter/{id}', [TransporterController::class, 'destroy']);
    Route::post('/transporter/{id}/change-status', [TransporterController::class, 'changeStatus']);

    //Company
    Route::get('/company', [CompanyController::class, 'index']);
    Route::post('/company', [CompanyController::class, 'store']);
    Route::get('/company/{id}', [CompanyController::class, 'show']);
    Route::put('/company/{id}', [CompanyController::class, 'update']);
    Route::delete('/company/{id}', [CompanyController::class, 'destroy']);
    Route::post('/company/{id}/change-status', [CompanyController::class, 'changeStatus']);
    Route::get('/getcompanydata', [CompanyController::class, 'getCompanyData']);

    //productcategory
    Route::get('/productcategory', [ProductcategoryController::class, 'index']);
    Route::post('/productcategory', [ProductcategoryController::class, 'store']);
    Route::get('/productcategory/{id}', [ProductcategoryController::class, 'show']);
    Route::put('/productcategory/{id}', [ProductcategoryController::class, 'update']);
    Route::delete('/productcategory/{id}', [ProductcategoryController::class, 'destroy']);

    //Party
    Route::get('/party', [PartyController::class, 'index']);
    Route::get('/partytrash', [PartyController::class, 'trashindex']);
    Route::post('/party', [PartyController::class, 'store']);
    Route::get('/party/{id}', [PartyController::class, 'show']);
    Route::put('/party/{id}', [PartyController::class, 'update']);
    Route::get('/partydelete/{id}', [PartyController::class, 'destroy']);
    Route::get('/partyrestore/{id}', [PartyController::class, 'restore']);
    Route::post('/party/{id}/change-status', [PartyController::class, 'changeStatus']);

    //item
    Route::get('/product', [ProductController::class, 'index']);
    Route::get('/producttrash', [ProductController::class, 'indextrash']);
    //add item
    Route::post('/item', [ProductController::class, 'store']);
    //add finished item
    Route::post('/add-finished-product', [ProductController::class, 'addFinishedItem']);
     //update finished item
     Route::post('/update-finished-product', [ProductController::class, 'updateFinishedItem']);
    //get items for a item to add meta
    Route::get('/item/getItemDetails', [ProductController::class, 'getItemDetails']);
    //add meta for a item
    Route::post('/item/addmeta', [ProductController::class, 'addItemMeta']);
    //get meta of a item
    Route::get('/item/getmeta', [ProductController::class, 'getItemMeta']);

    //update meta of a item (only parameters)
    Route::post('/item/updatemeta', [ProductController::class, 'updateItemMetaparameters']);

    //get meta of a item (only parameters)
    Route::get('/item/getItemMetaparameter', [ProductController::class, 'getItemMetaparameters']);
    
    //delete meta of a item (only parameters)
    Route::get('/item/deleteItemMetaparameters', [ProductController::class, 'deleteItemMetaparameters']);

    Route::get('/product/{id}', [ProductController::class, 'show']);
    Route::put('/product/{id}', [ProductController::class, 'update']);
    Route::get('/productdelete/{id}', [ProductController::class, 'destroy']);
    Route::get('/productrestore/{id}', [ProductController::class, 'restore']);
    Route::post('/product/{id}/change-status', [ProductController::class, 'changeStatus']);


    //voucher
   
    Route::get('/voucher', [VoucherController::class, 'index']);
    Route::get('/voucherinhouse', [VoucherController::class, 'indexinhouse']);
    Route::get('/vouchersubcontract', [VoucherController::class, 'indexsubcontract']);
    Route::post('/voucher', [VoucherController::class, 'store']);
    Route::get('/voucher/{id}', [VoucherController::class, 'show']);
    Route::get('/voucher/{id}/pdf', [VoucherController::class, 'generatePdf']);
    Route::put('/voucher/{id}', [VoucherController::class, 'update']);
    Route::delete('/voucher/{id}', [VoucherController::class, 'destroy']);
    Route::get('/checkVoucherNumber', [VoucherController::class, 'checkVoucherNumber']);
    Route::post('/vouchermetaupdate', [VoucherController::class, 'updateVoucherMetaByItem']);
    Route::get('/getPdfpreview/{id}', [VoucherController::class, 'getPdfpreview']);
    Route::get('/generateVoucherNumber', [VoucherController::class, 'generateVoucherNumber']);
    
    //get product ledger
    Route::get('/ledger', [LedgerController::class, 'getProductLedger']);
    Route::get('/supplierledger', [LedgerController::class, 'getsupplierLedger']);
    Route::get('/subcontractLedger', [LedgerController::class, 'getsubcontractLedger']);
    Route::get('/getMonthlyProductLedger', [LedgerController::class, 'getMonthlyProductLedger']);

    //get excel dump for a company
    Route::get('/export-tenant-company-data', [CompanyController::class, 'exportcompanyData']);


    //scrap transactions
    Route::get('/scrap', [ScrapController::class, 'index']);
    Route::post('/scrap', [ScrapController::class, 'store']);
    Route::get('/scrap/{id}', [ScrapController::class, 'show']);
    Route::put('/scrap/{id}', [ScrapController::class, 'update']);
    Route::get('/scrapdelete/{id}', [ScrapController::class, 'destroy']);
    Route::get('/checkscrapVoucherNumber', [ScrapController::class, 'checkscrapVoucherNumber']);



     //test bulk upload via jobs
     Route::post('/testdata', [TestController::class, 'generate']);
     //test excel download
     Route::post('/exportVoucherTransactions', [TestController::class, 'exportVoucherTransactions']);

    //reports
    Route::get('/stockreport', [ReportController::class, 'stockreportindex']);
    Route::get('/getStockBalanceReport', [ReportController::class, 'getStockBalanceReport']);
    Route::get('/downloadStockBalanceReport', [ReportController::class, 'downloadStockBalanceReport']);
    Route::get('/getStockBalancesubcontractReport', [ReportController::class, 'getStockBalancesubcontractReport']);
    Route::get('/downloadStockBalancesubcontractReport', [ReportController::class, 'downloadStockBalancesubcontractReport']);
    Route::get('/salesreportindex', [ReportController::class, 'salesreportindex']);
    Route::get('/getSalesReport', [ReportController::class, 'getSalesReport']);
    Route::get('/downloadSalesReport', [ReportController::class, 'downloadSalesReport']);
    Route::get('/purchasereportindex', [ReportController::class, 'purchasereportindex']);
    Route::get('/getpurchaseReport', [ReportController::class, 'getpurchaseReport']);
    Route::get('/downloadpurchaseReport', [ReportController::class, 'downloadpurchaseReport']);
    Route::get('/scrapreturnreportindex', [ReportController::class, 'scrapreturnreportindex']);
    Route::get('/scrapreturnreportpdf', [ReportController::class, 'scrapreturnreportpdf']);
    Route::get('/downloadscrapreturnreportpdf', [ReportController::class, 'downloadscrapreturnreportpdf']);
    Route::get('/scrapreceivablereportindex', [ReportController::class, 'scrapreceivablereportindex']);
    Route::get('/scrapreceivablereportpdf', [ReportController::class, 'scrapreceivablereportpdf']);
    Route::get('/downloadscrapreceivablereportpdf', [ReportController::class, 'downloadscrapreceivablereportpdf']);

    //Invoice api
    Route::get('/tool_invoice', [InvoiceController::class, 'index']);
    Route::post('/tool_invoice', [InvoiceController::class, 'store']);
    Route::get('/tool_invoice/{id}', [InvoiceController::class, 'show']);
    Route::put('/tool_invoice/{id}', [InvoiceController::class, 'update']);
    Route::delete('/tool_invoice/{id}', [InvoiceController::class, 'destroy']);

    //Quotation api
    Route::get('/tool_quotation', [QuotationController::class, 'index']);
    Route::post('/tool_quotation', [QuotationController::class, 'store']);
    Route::get('/tool_quotation/{id}', [QuotationController::class, 'show']);
    Route::put('/tool_quotation/{id}', [QuotationController::class, 'update']);
    Route::delete('/tool_quotation/{id}', [QuotationController::class, 'destroy']);
    Route::get('/generatequotationPdf/{id}', [QuotationController::class, 'generatequotationPdf']);

    
    //predispatch inspection api
    Route::get('/tool_predispatchinspection', [PredispatchInspectionController::class, 'predispatchIndex']);
    Route::post('/tool_predispatchinspection', [PredispatchInspectionController::class, 'predispatchStore']);
    Route::get('/tool_predispatchinspection/{id}', [PredispatchInspectionController::class, 'predispatchShow']);
    Route::put('/tool_predispatchinspection/{id}', [PredispatchInspectionController::class, 'predispatchUpdate']);
    Route::delete('/tool_predispatchinspection/{id}', [PredispatchInspectionController::class, 'predispatchDestroy']);
    Route::get('getNextPdirNo/tool_predispatchinspection/{company_id}', [PredispatchInspectionController::class, 'getNextPdirNo']);

   //Dashboard api
    Route::get('/dashboard/saleschart', [DashboardController::class, 'getSalesReportChartData']);
    Route::get('/dashboard/stockchart', [DashboardController::class, 'getDashboardStockOverview']);
    Route::get('/dashboard/stockmonthlychart', [DashboardController::class, 'getMonthlyStockReport']);
    Route::get('/dashboard/getAllTransactions', [DashboardController::class, 'getAllTransactions']);
});

