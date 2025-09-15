<?php

use Illuminate\Support\Facades\Route;
use Modules\HRM\Http\Controllers\HRMController;
use Modules\HRM\Http\Controllers\DepartmentController;
use Modules\HRM\Http\Controllers\DesignationController;
use Modules\HRM\Http\Controllers\EmployeeController;
use Modules\HRM\Http\Controllers\HolidayController;
use Modules\HRM\Http\Controllers\AttendanceRecordController;
use Modules\HRM\Http\Controllers\AttendanceBreakController;
use Modules\HRM\Http\Controllers\SalaryComponentController;
use Modules\HRM\Http\Controllers\EmployeeSalaryComponentController;
use Modules\HRM\Http\Controllers\PaymentController;
use Modules\HRM\Http\Controllers\AdvanceController;
use Modules\HRM\Http\Controllers\AdvanceRepaymentController;
use Modules\HRM\Http\Controllers\PayrollController;
use Modules\HRM\Http\Controllers\SalarySlipController;
use Modules\HRM\Http\Controllers\EmployeeDocumentController;


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
    Route::apiResource('hrm', HRMController::class)->names('hrm');
});

Route::middleware(['auth:sanctum', '\App\Http\Middleware\subscriptionMiddleware::class','\App\Http\Middleware\CorsMiddleware::class'])->group(function () {

Route::get('/departments', [DepartmentController::class, 'index']);
Route::post('/departments', [DepartmentController::class, 'store']);
Route::get('/departments/{id}', [DepartmentController::class, 'show']);
Route::put('/departments/{id}', [DepartmentController::class, 'update']);
Route::delete('/departments/{id}', [DepartmentController::class, 'destroy']);

Route::get('/designations', [DesignationController::class, 'index']);
Route::post('/designations', [DesignationController::class, 'store']);
Route::get('/designations/{id}', [DesignationController::class, 'show']);
Route::put('/designations/{id}', [DesignationController::class, 'update']);
Route::delete('/designations/{id}', [DesignationController::class, 'destroy']);

Route::get('/employees', [EmployeeController::class, 'index']);
Route::post('/employees', [EmployeeController::class, 'store']);
Route::get('/employees/{id}', [EmployeeController::class, 'show']);
Route::put('/employees/{id}', [EmployeeController::class, 'update']);
Route::delete('/employees/{id}', [EmployeeController::class, 'destroy']);

Route::get('/holidays', [HolidayController::class, 'index']);
Route::post('/holidays', [HolidayController::class, 'store']);
Route::get('/holidays/{id}', [HolidayController::class, 'show']);
Route::put('/holidays/{id}', [HolidayController::class, 'update']);
Route::delete('/holidays/{id}', [HolidayController::class, 'destroy']);

Route::get('/attendance-records', [AttendanceRecordController::class, 'index']);
Route::post('/attendance-records', [AttendanceRecordController::class, 'store']);
Route::get('/attendance-records/{id}', [AttendanceRecordController::class, 'show']);
Route::put('/attendance-records/{id}', [AttendanceRecordController::class, 'update']);
Route::delete('/attendance-records/{id}', [AttendanceRecordController::class, 'destroy']);


Route::get('attendance-records/{recordId}/breaks', [AttendanceBreakController::class, 'index'])
    ->name('attendance-breaks.index');
Route::post('attendance-breaks', [AttendanceBreakController::class, 'store'])
    ->name('attendance-breaks.store');
Route::get('attendance-breaks/{id}', [AttendanceBreakController::class, 'show'])
    ->name('attendance-breaks.show');
Route::put('attendance-breaks/{id}', [AttendanceBreakController::class, 'update'])
    ->name('attendance-breaks.update');
Route::delete('attendance-breaks/{id}', [AttendanceBreakController::class, 'destroy'])
    ->name('attendance-breaks.destroy');

// Stage 3 Routes
    Route::apiResource('salary-components', SalaryComponentController::class);
    Route::get('employees/{employee}/salary-components', [EmployeeSalaryComponentController::class, 'index'])->name('employees.salary-components.index');
    Route::post('employees/{employee}/salary-components', [EmployeeSalaryComponentController::class, 'store'])->name('employees.salary-components.store');
    Route::put('employees/{employee}/salary-components/{salary_component}', [EmployeeSalaryComponentController::class, 'update'])->name('employees.salary-components.update');
    Route::delete('employees/{employee}/salary-components/{salary_component}', [EmployeeSalaryComponentController::class, 'destroy'])->name('employees.salary-components.destroy');

    // Stage 4 Routes
    Route::apiResource('payments', PaymentController::class);
    Route::apiResource('advances', AdvanceController::class);
    Route::get('advances/{advance}/repayments', [AdvanceRepaymentController::class, 'index'])->name('advances.repayments.index');
    Route::post('advances/{advance}/repayments', [AdvanceRepaymentController::class, 'store'])->name('advances.repayments.store');
    Route::post('advance-repayments/{repayment}/void', [AdvanceRepaymentController::class, 'void'])->name('advance-repayments.void');

    // Stage 5 Routes
    Route::post('payroll/generate', [PayrollController::class, 'generate'])->name('payroll.generate');
    Route::apiResource('salary-slips', SalarySlipController::class)->except(['store']);
    Route::post('salary-slips/{salary_slip}/pay', [SalarySlipController::class, 'pay'])->name('salary-slips.pay');

    // Stage 6 Routes
    Route::get('employees/{employee}/documents', [EmployeeDocumentController::class, 'index'])->name('employees.documents.index');
    Route::post('employees/{employee}/documents', [EmployeeDocumentController::class, 'store'])->name('employees.documents.store');
    Route::delete('documents/{document}', [EmployeeDocumentController::class, 'destroy'])->name('documents.destroy');    

});