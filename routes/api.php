<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CompanyAdminController;
use App\Http\Controllers\CompanyPolicyController;
use App\Http\Controllers\CompanyRegistrationController;
use App\Http\Controllers\CompanySettingsController;
use App\Http\Controllers\HolidayPolicyController;
use App\Http\Controllers\HrManagerController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\SubscriptionPlanController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->name('auth.login');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('auth.forgot-password');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('auth.reset-password');
});

Route::middleware('auth:sanctum')->prefix('auth')->group(function () {
    Route::post('/complete-first-login', [AuthController::class, 'completeFirstLogin'])->name('auth.complete-first-login');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('subscription-plans')->group(function () {
        Route::get('/', [SubscriptionPlanController::class, 'index']);
        Route::post('/', [SubscriptionPlanController::class, 'store']);
        Route::get('/{plan}', [SubscriptionPlanController::class, 'show']);
        Route::put('/{plan}', [SubscriptionPlanController::class, 'update']);
        Route::delete('/{plan}', [SubscriptionPlanController::class, 'destroy']);
        Route::post('/{plan}/freeze', [SubscriptionPlanController::class, 'freeze']);
        Route::post('/{plan}/activate', [SubscriptionPlanController::class, 'activate']);
    });

    Route::prefix('companies')->group(function () {
        Route::get('/', [CompanyAdminController::class, 'index']);
        Route::get('/stats', [CompanyAdminController::class, 'stats']);
        Route::get('/{company}', [CompanyAdminController::class, 'show']);
        Route::post('/{company}/freeze', [CompanyAdminController::class, 'freeze']);
        Route::post('/{company}/activate', [CompanyAdminController::class, 'activate']);
        Route::delete('/{company}', [CompanyAdminController::class, 'destroy']);
        Route::get('/{company}/leave-types', [CompanyPolicyController::class, 'indexLeaveTypes']);
        Route::post('/{company}/leave-types', [CompanyPolicyController::class, 'storeLeaveType']);
        Route::put('/{company}/leave-types/{leaveType}', [CompanyPolicyController::class, 'updateLeaveType']);
        Route::post('/{company}/leave-types/{leaveType}/toggle', [CompanyPolicyController::class, 'toggleLeaveType']);
        Route::get('/{company}/salary-rules', [CompanyPolicyController::class, 'indexSalaryRules']);
        Route::post('/{company}/salary-rules', [CompanyPolicyController::class, 'storeSalaryRule']);
        Route::put('/{company}/salary-rules/{rule}', [CompanyPolicyController::class, 'updateSalaryRule']);
        Route::post('/{company}/salary-rules/{rule}/toggle', [CompanyPolicyController::class, 'toggleSalaryRule']);
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

        Route::get('/{company}/hr-managers', [HrManagerController::class, 'index']);
        Route::post('/{company}/hr-managers', [HrManagerController::class, 'store']);
        Route::get('/{company}/hr-managers/{hr_manager}', [HrManagerController::class, 'show']);
        Route::put('/{company}/hr-managers/{hr_manager}', [HrManagerController::class, 'update']);
        Route::post('/{company}/hr-managers/{hr_manager}/activate', [HrManagerController::class, 'activate']);
        Route::post('/{company}/hr-managers/{hr_manager}/deactivate', [HrManagerController::class, 'deactivate']);
        Route::delete('/{company}/hr-managers/{hr_manager}', [HrManagerController::class, 'destroy']);
    });
});

Route::post('/companies/register', [CompanyRegistrationController::class, 'register'])->name('companies.register');
Route::put('/companies/{company}/attendance-policy', [CompanySettingsController::class, 'updateAttendancePolicy'])->name('companies.attendance-policy');
Route::put('/companies/{company}/payroll-currency', [CompanyPolicyController::class, 'updatePayrollCurrency'])->name('companies.payroll-currency');

Route::post('/payments/callback', [PaymentWebhookController::class, 'callback'])->name('payments.callback');
