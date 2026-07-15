<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CompanyAdminController;
use App\Http\Controllers\CompanyPolicyController;
use App\Http\Controllers\CompanyRegistrationController;
use App\Http\Controllers\CompanySettingsController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\HolidayPolicyController;
use App\Http\Controllers\HrManagerController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\SubscriptionPlanController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->name('auth.login')->middleware('throttle:5,1');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('auth.forgot-password')->middleware('throttle:3,1');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('auth.reset-password');
});

Route::middleware('auth:sanctum')->prefix('auth')->group(function () {
    Route::post('/complete-first-login', [AuthController::class, 'completeFirstLogin'])->name('auth.complete-first-login');
});

// Public self-registration for tenant companies (rate-limited).
Route::post('/companies/register', [CompanyRegistrationController::class, 'register'])
    ->name('companies.register')
    ->middleware('throttle:5,1');

Route::prefix('subscription-plans')->group(function () {
    Route::get('/', [SubscriptionPlanController::class, 'index']);
    Route::get('/{plan}', [SubscriptionPlanController::class, 'show']);
});
Route::middleware(['auth:sanctum', 'role:super_admin'])->group(function () {
    Route::prefix('subscription-plans')->group(function () {
        Route::post('/', [SubscriptionPlanController::class, 'store']);
        Route::put('/{plan}', [SubscriptionPlanController::class, 'update']);
        Route::delete('/{plan}', [SubscriptionPlanController::class, 'destroy']);
        Route::post('/{plan}/freeze', [SubscriptionPlanController::class, 'freeze']);
        Route::post('/{plan}/activate', [SubscriptionPlanController::class, 'activate']);
    });

    // Tenant companies management
    Route::prefix('companies')->group(function () {
        Route::get('/', [CompanyAdminController::class, 'index']);
        Route::get('/stats', [CompanyAdminController::class, 'stats']);
        Route::get('/{company}', [CompanyAdminController::class, 'show']);
        Route::post('/{company}/freeze', [CompanyAdminController::class, 'freeze']);
        Route::post('/{company}/activate', [CompanyAdminController::class, 'activate']);
        Route::delete('/{company}', [CompanyAdminController::class, 'destroy']);
    });

});

// Tenant area: company-scoped policy & setup (General Manager only, must belong to the company).
Route::middleware(['auth:sanctum', 'tenant', 'role:general_manager'])->prefix('companies')->group(function () {
    Route::get('/{company}/leave-types', [CompanyPolicyController::class, 'indexLeaveTypes']);
    Route::post('/{company}/leave-types', [CompanyPolicyController::class, 'storeLeaveType']);
    Route::put('/{company}/leave-types/{leaveType}', [CompanyPolicyController::class, 'updateLeaveType']);
    Route::post('/{company}/leave-types/{leaveType}/toggle', [CompanyPolicyController::class, 'toggleLeaveType']);
    Route::get('/{company}/salary-rules', [CompanyPolicyController::class, 'indexSalaryRules']);
    Route::post('/{company}/salary-rules', [CompanyPolicyController::class, 'storeSalaryRule']);
    Route::put('/{company}/salary-rules/{rule}', [CompanyPolicyController::class, 'updateSalaryRule']);
    Route::post('/{company}/salary-rules/{rule}/toggle', [CompanyPolicyController::class, 'toggleSalaryRule']);
    Route::put('/{company}/payroll-currency', [CompanyPolicyController::class, 'updatePayrollCurrency'])->name('companies.payroll-currency');

    Route::get('/{company}/holidays', [HolidayPolicyController::class, 'indexHolidays']);
    Route::post('/{company}/holidays', [HolidayPolicyController::class, 'storeHoliday']);
    Route::put('/{company}/holidays/{holiday}', [HolidayPolicyController::class, 'updateHoliday']);
    Route::delete('/{company}/holidays/{holiday}', [HolidayPolicyController::class, 'deleteHoliday']);
    Route::post('/{company}/holidays/defaults', [HolidayPolicyController::class, 'addDefaultSyrianHolidays']);
    Route::delete('/{company}/holidays/defaults', [HolidayPolicyController::class, 'removeDefaultSyrianHolidays']);
    Route::get('/{company}/weekly-holidays', [HolidayPolicyController::class, 'indexWeeklyHolidays']);
    Route::post('/{company}/weekly-holidays', [HolidayPolicyController::class, 'updateWeeklyHolidays']);
    Route::get('/{company}/evaluation-policy', [HolidayPolicyController::class, 'indexEvaluationPolicy']);
    Route::put('/{company}/evaluation-policy', [HolidayPolicyController::class, 'updateEvaluationPolicy']);

    Route::put('/{company}/attendance-policy', [CompanySettingsController::class, 'updateAttendancePolicy'])->name('companies.attendance-policy');

    Route::get('/{company}/hr-managers', [HrManagerController::class, 'index']);
    Route::post('/{company}/hr-managers', [HrManagerController::class, 'store']);
    Route::get('/{company}/hr-managers/{hr_manager}', [HrManagerController::class, 'show']);
    Route::put('/{company}/hr-managers/{hr_manager}', [HrManagerController::class, 'update']);
    Route::post('/{company}/hr-managers/{hr_manager}/activate', [HrManagerController::class, 'activate']);
    Route::post('/{company}/hr-managers/{hr_manager}/deactivate', [HrManagerController::class, 'deactivate']);
    Route::delete('/{company}/hr-managers/{hr_manager}', [HrManagerController::class, 'destroy']);
});

// Payment gateway webhook (signed).
Route::post('/payments/callback', [PaymentWebhookController::class, 'callback'])
    ->name('payments.callback')
    ->middleware('webhook');

// HR Dashboard area: Departments & Employees management (HR Manager only).
// Company isolation is enforced per-request from the authenticated user's company_id
// (no company_id is accepted from the client).
Route::middleware(['auth:sanctum', 'role:hr_manager'])->prefix('hr')->group(function () {
    // Departments
    Route::get('/departments', [DepartmentController::class, 'index']);
    Route::post('/departments', [DepartmentController::class, 'store']);
    Route::get('/departments/{department}', [DepartmentController::class, 'show']);
    Route::put('/departments/{department}', [DepartmentController::class, 'update']);
    Route::delete('/departments/{department}', [DepartmentController::class, 'destroy']);

    // Employees
    Route::get('/employees/import/template', [EmployeeController::class, 'downloadTemplate']);
    Route::get('/employees', [EmployeeController::class, 'index']);
    Route::post('/employees', [EmployeeController::class, 'store']);
    Route::get('/employees/{employee}', [EmployeeController::class, 'show']);
    Route::put('/employees/{employee}', [EmployeeController::class, 'update']);
    Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy']);
    Route::post('/employees/import', [EmployeeController::class, 'import']);
});
