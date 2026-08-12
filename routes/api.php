<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CompanyAdminController;
use App\Http\Controllers\CompanyPolicyController;
use App\Http\Controllers\CompanyProfileController;
use App\Http\Controllers\CompanyRegistrationController;
use App\Http\Controllers\CompanySettingsController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DepartmentManagerController;
use App\Http\Controllers\EmployeeAdvanceController;
use App\Http\Controllers\EmployeeCompanyPolicyController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeLeaveController;
use App\Http\Controllers\EvaluationCycleController;
use App\Http\Controllers\EvaluationProgressController;
use App\Http\Controllers\EvaluationQuestionController;
use App\Http\Controllers\EvaluationResultController;
use App\Http\Controllers\EvaluationReviewController;
use App\Http\Controllers\EvaluationScoringController;
use App\Http\Controllers\EvaluationTemplateController;
use App\Http\Controllers\HolidayPolicyController;
use App\Http\Controllers\ManagementAdvanceController;
use App\Http\Controllers\ManagementAttendanceController;
use App\Http\Controllers\ManagementLeaveController;
use App\Http\Controllers\ManagementOvertimeController;
use App\Http\Controllers\EmployeeOvertimeController;
use App\Http\Controllers\EmployeeSalaryController;
use App\Http\Controllers\HrManagerController;
use App\Http\Controllers\ManagementSalaryController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SalaryAdvancePolicyController;
use App\Http\Controllers\SubscriptionPlanController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PayrollAnalyticsController;
use App\Http\Controllers\HRAnalyticsController;

Route::middleware('guest')->prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->name('auth.login')->middleware('throttle:5,1');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('auth.forgot-password')->middleware('throttle:3,1');
    Route::post('/verify-otp',[AuthController::class,'verifyOtp']);
    Route::post('/resend-otp', [AuthController::class, 'resendOtp']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('auth.reset-password')->middleware('throttle:5,1');
});

Route::middleware('auth:sanctum')->prefix('auth')->group(function () {
    Route::post('/complete-first-login', [AuthController::class, 'completeFirstLogin'])->name('auth.complete-first-login');
    Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
});

// Profile: view / update / documents upload.
// Available to any authenticated user. Employee-only fields/files require an employee record.
Route::middleware('auth:sanctum')->prefix('profile')->group(function () {
    Route::get('/', [ProfileController::class, 'show'])->name('profile.show');
    Route::put('/', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/documents', [ProfileController::class, 'uploadDocuments'])->name('profile.documents');
});

// Company profile page (logo, tagline, about, contact info).
// GET: any authenticated tenant user (General Manager, HR Manager, Department Manager, Employee) -
// always scoped to auth()->user()->company_id, never a client-supplied company_id.
// PUT: General Manager only.
Route::middleware('auth:sanctum')->prefix('company/profile')->group(function () {
    Route::get('/', [CompanyProfileController::class, 'show'])->name('company.profile.show');
    Route::put('/', [CompanyProfileController::class, 'update'])
        ->middleware(['role:general_manager', 'company.active'])
        ->name('company.profile.update');
});

// Company policies & holidays page for the mobile employee app (read-only).
// Available to Employee, HR Manager, Department Manager, and General Manager - always scoped to
// auth()->user()->company_id, never a client-supplied company_id.
Route::middleware(['auth:sanctum', 'role:employee,hr_manager,department_manager,general_manager'])->prefix('employee')->group(function () {
    Route::get('/company-policies', [EmployeeCompanyPolicyController::class, 'policies'])->name('employee.company-policies');
    Route::get('/company-holidays', [EmployeeCompanyPolicyController::class, 'holidays'])->name('employee.company-holidays');
});

// Public self-registration for tenant companies (rate-limited).
Route::post('/companies/register', [CompanyRegistrationController::class, 'register'])
    ->name('companies.register')
    ->middleware('throttle:5,1');

Route::prefix('subscription-plans')->group(function () {
      Route::get('/', [SubscriptionPlanController::class, 'index']);
      Route::middleware(['auth:sanctum', 'role:super_admin'])->group(function () {
           Route::get('/all', [SubscriptionPlanController::class, 'adminIndex']);
        });
      Route::get('/{plan}', [SubscriptionPlanController::class, 'show']);

        Route::middleware(['auth:sanctum', 'role:super_admin'])->group(function () {
        Route::post('/', [SubscriptionPlanController::class, 'store']);
        Route::put('/{plan}', [SubscriptionPlanController::class, 'update']);
        Route::delete('/{plan}', [SubscriptionPlanController::class, 'destroy']);
        Route::post('/{plan}/freeze', [SubscriptionPlanController::class, 'freeze']);
        Route::post('/{plan}/activate', [SubscriptionPlanController::class, 'activate']);
    });
});
// Tenant companies management
Route::middleware(['auth:sanctum', 'role:super_admin'])->group(function () {
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
// company.active blocks write actions (not GET) when the company is frozen/suspended; Super Admin is exempt.
Route::middleware(['auth:sanctum', 'tenant', 'role:general_manager', 'company.active'])->prefix('companies')->group(function () {
    Route::get('/{company}/leave-types', [CompanyPolicyController::class, 'indexLeaveTypes']);
    Route::post('/{company}/leave-types', [CompanyPolicyController::class, 'storeLeaveTypes']);
    Route::put('/{company}/leave-types', [CompanyPolicyController::class, 'updateLeaveTypes']);
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

    Route::get('/{company}/attendance-policy', [CompanySettingsController::class, 'showAttendancePolicy'])->name('companies.attendance-policy.show');
    Route::put('/{company}/attendance-policy', [CompanySettingsController::class, 'updateAttendancePolicy'])->name('companies.attendance-policy');
    Route::put('/{company}/attendance-location', [CompanySettingsController::class, 'updateAttendanceLocation'])->name('companies.attendance-location');

    Route::get('/{company}/advance-policy', [SalaryAdvancePolicyController::class, 'show'])->name('companies.advance-policy.show');
    Route::put('/{company}/advance-policy', [SalaryAdvancePolicyController::class, 'storeOrUpdate'])->name('companies.advance-policy.update');
    Route::post('/{company}/advance-policy', [SalaryAdvancePolicyController::class, 'storeOrUpdate'])->name('companies.advance-policy.store');

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

// Stripe webhook - verified via the real Stripe-Signature header (see StripeService::constructWebhookEvent),
// not the generic 'webhook' middleware used by the simulated gateway above.
Route::post('/stripe/webhook', [\App\Http\Controllers\StripeWebhookController::class, 'handle'])
    ->name('stripe.webhook')
    ->middleware('throttle:60,1');

// Public JSON status check for a Checkout Session, polled by the (separate) frontend after
// Stripe redirects the user back to the frontend's own success/cancel pages.
Route::get('/stripe/checkout-sessions/{session_id}', [\App\Http\Controllers\StripeCheckoutController::class, 'status'])
    ->name('stripe.checkout-session.status')
    ->middleware('throttle:30,1');

// Departments & Employees viewing (HR Manager & General Manager).
// Company isolation is enforced per-request from the authenticated user's company_id
// (no company_id is accepted from the client).s
Route::middleware(['auth:sanctum', 'role:hr_manager,general_manager'])->prefix('hr')->group(function () {
    Route::get('/departments', [DepartmentController::class, 'index']);
    Route::get('/departments/{department}', [DepartmentController::class, 'show']);
    Route::get('/departments/{department}/employees', [EmployeeController::class, 'byDepartment']);
    Route::get('/employees', [EmployeeController::class, 'index']);
    Route::get('/employees/{employee}', [EmployeeController::class, 'show']);
    Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy']);

});

// HR Dashboard area: Departments & Employees management (HR Manager only).
// Company isolation is enforced per-request from the authenticated user's company_id
// (no company_id is accepted from the client).
Route::middleware(['auth:sanctum', 'role:hr_manager'])->prefix('hr')->group(function () {
    // Departments
    Route::post('/departments', [DepartmentController::class, 'store'])->middleware('company.active');
    Route::put('/departments/{department}', [DepartmentController::class, 'update'])->middleware('company.active');
    Route::delete('/departments/{department}', [DepartmentController::class, 'destroy'])->middleware('company.active');

    // Employees
    Route::get('/employees/import/template', [EmployeeController::class, 'downloadTemplate']);
    Route::post('/employees', [EmployeeController::class, 'store']);
    Route::put('/employees/{employee}', [EmployeeController::class, 'update']);
    Route::post('/employees/import', [EmployeeController::class, 'import']);

    // Department Managers
    Route::get('/department-managers', [DepartmentManagerController::class, 'index']);
    Route::post('/department-managers', [DepartmentManagerController::class, 'store']);
    Route::get('/department-managers/{department_manager}', [DepartmentManagerController::class, 'show']);
    Route::put('/department-managers/{department_manager}', [DepartmentManagerController::class, 'update']);
    Route::post('/department-managers/{department_manager}/activate', [DepartmentManagerController::class, 'activate']);
    Route::post('/department-managers/{department_manager}/deactivate', [DepartmentManagerController::class, 'deactivate']);
    Route::delete('/department-managers/{department_manager}', [DepartmentManagerController::class, 'destroy']);

    // Evaluation Templates & Criteria
    Route::get('/evaluation-templates', [EvaluationTemplateController::class, 'index']);
    Route::post('/evaluation-templates', [EvaluationTemplateController::class, 'store']);
    Route::get('/evaluation-templates/{template}', [EvaluationTemplateController::class, 'show']);
    Route::put('/evaluation-templates/{template}', [EvaluationTemplateController::class, 'update']);
    Route::delete('/evaluation-templates/{template}', [EvaluationTemplateController::class, 'destroy']);
    Route::post('/evaluation-templates/{template}/duplicate', [EvaluationTemplateController::class, 'duplicate']);

    Route::post('/evaluation-templates/{template}/questions', [EvaluationQuestionController::class, 'store']);
    Route::put('/evaluation-templates/{template}/questions/{question}', [EvaluationQuestionController::class, 'update']);
    Route::delete('/evaluation-templates/{template}/questions/{question}', [EvaluationQuestionController::class, 'destroy']);

    // Evaluation Cycles
    Route::get('/evaluation-cycles', [EvaluationCycleController::class, 'index']);
    Route::post('/evaluation-cycles', [EvaluationCycleController::class, 'store']);
    Route::get('/evaluation-cycles/{cycle}', [EvaluationCycleController::class, 'show']);
    Route::put('/evaluation-cycles/{cycle}', [EvaluationCycleController::class, 'update']);
    Route::delete('/evaluation-cycles/{cycle}', [EvaluationCycleController::class, 'destroy']);
    Route::post('/evaluation-cycles/{cycle}/launch', [EvaluationCycleController::class, 'launch']);
    Route::post('/evaluation-cycles/{cycle}/close', [EvaluationCycleController::class, 'close']);

    // Progress & Reminders
    Route::get('/evaluation-cycles/{cycle}/progress', [EvaluationProgressController::class, 'progress']);
    Route::post('/evaluation-cycles/{cycle}/employees/{employee}/reminder', [EvaluationProgressController::class, 'sendReminder']);

    // Scoring
    Route::get('/evaluation-cycles/{cycle}/scorable-employees', [EvaluationScoringController::class, 'scorableEmployees']);
    Route::get('/evaluation-cycles/{cycle}/scoring', [EvaluationScoringController::class, 'scoringDetails']);
    Route::post('/evaluation-cycles/{cycle}/reviews/{review}/score', [EvaluationScoringController::class, 'storeScores']);

    // Final Results
    Route::get('/evaluation-cycles/{cycle}/final-results', [EvaluationResultController::class, 'index']);
    Route::get('/evaluation-cycles/{cycle}/final-results/{employee}', [EvaluationResultController::class, 'show']);
    Route::post('/evaluation-cycles/{cycle}/final-results/{employee}/finalize', [EvaluationResultController::class, 'finalize']);
});

// Reviewer-facing endpoints (self/manager/peer reviews)
Route::middleware(['auth:sanctum'])->prefix('evaluations')->group(function () {
    Route::get('/my-reviews', [EvaluationReviewController::class, 'myReviews']);
    Route::get('/my-reviews/{review}', [EvaluationReviewController::class, 'show']);
    Route::post('/my-reviews/{review}/submit', [EvaluationReviewController::class, 'submit']);
});

// Employee self-service: leave management (tenant-specific dynamic leave types).
// Department Manager also has an employee record and uses this exact self-service flow for themselves.
Route::middleware(['auth:sanctum', 'role:employee,department_manager'])->prefix('employee/leaves')->group(function () {
    Route::get('/types', [EmployeeLeaveController::class, 'types']);
    Route::get('/dashboard', [EmployeeLeaveController::class, 'dashboard']);
    Route::post('/apply', [EmployeeLeaveController::class, 'apply']);
});

// Management approval workflow for leave requests.
Route::middleware(['auth:sanctum', 'role:department_manager,hr_manager'])->prefix('management/leaves')->group(function () {
    Route::get('/inbox', [ManagementLeaveController::class, 'inbox']);
    Route::post('/{id}/action', [ManagementLeaveController::class, 'action']);
});

// Employee self-service: salary advances (السُلف المالية).
// Department Manager also has an employee record and uses this exact self-service flow for themselves.
Route::middleware(['auth:sanctum', 'role:employee,department_manager'])->prefix('employee/advances')->group(function () {
    Route::get('/', [EmployeeAdvanceController::class, 'index']);
    Route::get('/eligibility', [EmployeeAdvanceController::class, 'eligibility']);
    Route::post('/apply', [EmployeeAdvanceController::class, 'apply']);
});

// Management approval workflow for salary advances.
Route::middleware(['auth:sanctum', 'role:department_manager,hr_manager'])->prefix('management/advances')->group(function () {
    Route::get('/', [ManagementAdvanceController::class, 'index']);
    Route::get('/{id}', [ManagementAdvanceController::class, 'show']);
    Route::post('/{id}/action', [ManagementAdvanceController::class, 'action']);
    Route::post('/{id}/installments/{installmentId}/pay', [ManagementAdvanceController::class, 'markInstallmentPaid']);
});

// Employee self-service: overtime requests.
// Department Manager also has an employee record and uses this exact self-service flow for themselves.
Route::middleware(['auth:sanctum', 'role:employee,department_manager'])->prefix('employee/overtime')->group(function () {
    Route::get('/', [EmployeeOvertimeController::class, 'index']);
    Route::get('/rates', [EmployeeOvertimeController::class, 'rates']);
    Route::get('/preview', [EmployeeOvertimeController::class, 'preview']);
    Route::post('/apply', [EmployeeOvertimeController::class, 'apply']);
});

// Management approval workflow for overtime.
Route::middleware(['auth:sanctum', 'role:department_manager,hr_manager'])->prefix('management/overtime')->group(function () {
    $uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
    Route::get('/', [ManagementOvertimeController::class, 'index']);
    Route::get('/{id}', [ManagementOvertimeController::class, 'show'])->where('id', $uuid);
    Route::post('/{id}/action', [ManagementOvertimeController::class, 'action'])->where('id', $uuid);
});

// Employee self-service: salary history / payslips.
// Department Manager also has an employee record and uses this exact self-service flow for themselves.
Route::middleware(['auth:sanctum', 'role:employee,department_manager'])->prefix('employee/salaries')->group(function () {
    Route::get('/', [EmployeeSalaryController::class, 'index']);
    Route::get('/{id}', [EmployeeSalaryController::class, 'show']);
});

// Salary viewing (HR Manager & General Manager).
Route::middleware(['auth:sanctum', 'role:hr_manager,general_manager'])->prefix('management/salaries')->group(function () {
    Route::get('/', [ManagementSalaryController::class, 'index']);
    Route::get('/by-month', [ManagementSalaryController::class, 'byMonth']);
    Route::get('/employees/{employee}/history', [ManagementSalaryController::class, 'employeeHistory']);
    Route::get('/{id}', [ManagementSalaryController::class, 'show']);
});

// Salary generation and payment closing (HR Manager only).
Route::middleware(['auth:sanctum', 'role:hr_manager'])->prefix('management/salaries')->group(function () {
    Route::post('/generate', [ManagementSalaryController::class, 'generate']);
    Route::post('/{id}/pay', [ManagementSalaryController::class, 'pay']);
});

// Employee self-service: attendance check-in/check-out via rotating QR code + personal dashboard.
// Company isolation is enforced per-request from the authenticated user's company_id
// (no company_id is accepted from the client).
// Department Manager also has an employee record and uses this exact self-service flow for themselves.
Route::middleware(['auth:sanctum', 'role:employee,department_manager'])->prefix('employee/attendance')->group(function () {
    Route::post('/check-in', [AttendanceController::class, 'checkIn']);
    Route::post('/check-out', [AttendanceController::class, 'checkOut']);
    Route::get('/dashboard', [AttendanceController::class, 'dashboard']);
});

// Attendance dashboard/listing: HR Manager & General Manager see the whole company,
// Department Manager is scoped to employees in departments they manage (enforced in the controller).
Route::middleware(['auth:sanctum', 'role:hr_manager,general_manager,department_manager'])->prefix('management/attendance')->group(function () {
    Route::get('/', [ManagementAttendanceController::class, 'index']);
    Route::get('/stats', [ManagementAttendanceController::class, 'stats']);
});

// QR code display and manual adjustments: HR Manager & General Manager only.
Route::middleware(['auth:sanctum', 'role:hr_manager,general_manager'])->prefix('management/attendance')->group(function () {
    Route::get('/qr-code', [ManagementAttendanceController::class, 'qrCode']);
    Route::put('/{attendanceRecord}/adjust', [ManagementAttendanceController::class, 'adjust']);
});


Route::middleware(['auth:sanctum', 'role:hr_manager,general_manager'])->group(function () {
    Route::get('/payrolls/analytics', [PayrollAnalyticsController::class, 'index']);
});

Route::middleware(['auth:sanctum'])->prefix('analytics/hr')->group(function () {
    Route::get('/turnover-rate', [HRAnalyticsController::class, 'getTurnoverRate']);
    Route::get('/demographics', [HRAnalyticsController::class, 'getDemographics']);
    Route::get('/department-budgets', [HRAnalyticsController::class, 'getDepartmentBudgets']);
    Route::get('/daily-verification-rate', [HRAnalyticsController::class, 'getDailyVerificationRate']);
    Route::get('/realtime-headcount', [HRAnalyticsController::class, 'getRealtimeHeadcount']);
    Route::get('/performance-distribution', [HRAnalyticsController::class, 'getPerformanceDistribution']);
});
