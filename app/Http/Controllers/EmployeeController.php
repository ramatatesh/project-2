<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Exports\EmployeeTemplateExport;
use App\Http\Requests\ImportEmployeesRequest;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EvaluationScore;
use App\Models\Holiday;
use App\Models\HolidayPolicy;
use App\Models\LeaveRequest;
use App\Services\EmployeeService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * @OA\Tag(
 *   name="Employees",
 *   description="إدارة موظفي الشركة الحالية (متاح فقط لمدير الموارد البشرية HR Manager)"
 * )
 */
class EmployeeController extends Controller
{
    public function __construct(private readonly EmployeeService $employeeService) {}

    /**
     * @OA\Get(
     *   path="/api/hr/employees",
     *   summary="عرض موظفي الشركة الحالية فقط (مع Pagination/Search/Sort/Filter)",
     *   tags={"Employees"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="page", in="query", required=false, @OA\Schema(type="integer")),
     *   @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=15)),
     *   @OA\Parameter(name="search", in="query", required=false, @OA\Schema(type="string"), description="بحث في الاسم والإيميل والمسمى الوظيفي"),
     *   @OA\Parameter(name="department_id", in="query", required=false, @OA\Schema(type="string", format="uuid")),
     *   @OA\Parameter(name="is_active", in="query", required=false, @OA\Schema(type="boolean")),
     *   @OA\Parameter(name="sort_by", in="query", required=false, @OA\Schema(type="string", default="hire_date")),
     *   @OA\Parameter(name="sort_dir", in="query", required=false, @OA\Schema(type="string", default="desc")),
     *
     *   @OA\Response(
     *     response=200,
     *     description="قائمة الموظفين",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="current_page", type="integer"),
     *         @OA\Property(property="data", type="array", @OA\Items(type="object")),
     *         @OA\Property(property="total", type="integer"),
     *         @OA\Property(property="per_page", type="integer")
     *       )
     *     )
     *   ),
     *
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=403, description="Forbidden (HR Manager or General Manager only)")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->currentUserCompanyId();

        $sortBy = in_array($request->input('sort_by'), ['hire_date', 'created_at', 'job_title', 'base_salary'], true)
            ? $request->input('sort_by') : 'hire_date';
        $sortDir = in_array(strtolower($request->input('sort_dir')), ['asc', 'desc'], true)
            ? strtolower($request->input('sort_dir')) : 'desc';

        $query = Employee::where('company_id', $companyId)
            ->with(['user', 'department', 'document']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('job_title', 'ilike', "%{$search}%")
                    ->orWhereHas('user', function ($u) use ($search) {
                        $u->where('full_name', 'ilike', "%{$search}%")
                            ->orWhere('email', 'ilike', "%{$search}%");
                    });
            });
        }

        if ($departmentId = $request->input('department_id')) {
            $query->where('department_id', $departmentId);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = (int) ($request->input('per_page', 15));
        $employees = $query->orderBy($sortBy, $sortDir)->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => EmployeeResource::collection($employees),
        ]);
    }

    /**
     * @OA\Get(
     *   path="/api/hr/departments/{department}/employees",
     *   summary="عرض موظفي قسم محدد ضمن الشركة الحالية فقط (مع Pagination/Search/Sort/Filter)",
     *   tags={"Employees"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="department", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *   @OA\Parameter(name="page", in="query", required=false, @OA\Schema(type="integer")),
     *   @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=15)),
     *   @OA\Parameter(name="search", in="query", required=false, @OA\Schema(type="string"), description="بحث في الاسم والإيميل والمسمى الوظيفي"),
     *   @OA\Parameter(name="is_active", in="query", required=false, @OA\Schema(type="boolean")),
     *   @OA\Parameter(name="sort_by", in="query", required=false, @OA\Schema(type="string", default="hire_date")),
     *   @OA\Parameter(name="sort_dir", in="query", required=false, @OA\Schema(type="string", default="desc")),
     *
     *   @OA\Response(
     *     response=200,
     *     description="قائمة موظفي القسم (نفس شكل استجابة GET /api/hr/employees)",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *     )
     *   ),
     *
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=403, description="Forbidden (HR Manager or General Manager only)"),
     *   @OA\Response(response=404, description="Department not found / not in your company")
     * )
     */
    public function byDepartment(Request $request, Department $department): JsonResponse
    {
        $companyId = $this->currentUserCompanyId();

        if ($department->company_id !== $companyId) {
            abort(404, 'Department not found.');
        }

        $sortBy = in_array($request->input('sort_by'), ['hire_date', 'created_at', 'job_title', 'base_salary'], true)
            ? $request->input('sort_by') : 'hire_date';
        $sortDir = in_array(strtolower($request->input('sort_dir')), ['asc', 'desc'], true)
            ? strtolower($request->input('sort_dir')) : 'desc';

        $query = Employee::where('company_id', $companyId)
            ->where('department_id', $department->id)
            ->with(['user', 'department', 'document']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('job_title', 'ilike', "%{$search}%")
                    ->orWhereHas('user', function ($u) use ($search) {
                        $u->where('full_name', 'ilike', "%{$search}%")
                            ->orWhere('email', 'ilike', "%{$search}%");
                    });
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = (int) ($request->input('per_page', 15));
        $employees = $query->orderBy($sortBy, $sortDir)->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => EmployeeResource::collection($employees),
        ]);
    }

    /**
     * @OA\Post(
     *   path="/api/hr/employees",
     *   summary="إضافة موظف فردي (إنشاء user + employee داخل Transaction)",
     *   tags={"Employees"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\RequestBody(
     *     required=true,
     *
     *     @OA\JsonContent(
     *       required={"full_name","email","department_id","job_title","base_salary","hire_date"},
     *
     *       @OA\Property(property="full_name", type="string", example="Ahmad Ali"),
     *       @OA\Property(property="email", type="string", format="email", example="ahmad@example.com"),
     *       @OA\Property(property="phone", type="string", pattern="^09[0-9]{8}$", example="0999999999", description="يجب أن يبدأ بـ 09 ويتكون من 10 أرقام"),
     *       @OA\Property(property="department_id", type="string", format="uuid"),
     *       @OA\Property(property="education", type="string", example="BSc"),
     *       @OA\Property(property="job_title", type="string", example="Engineer"),
     *       @OA\Property(property="base_salary", type="number", example=1500),
     *       @OA\Property(property="hire_date", type="string", format="date", example="2026-01-01", description="لا يمكن أن يكون تاريخاً مستقبلياً"),
     *       @OA\Property(property="employment_type", type="string", example="full-time"),
     *       @OA\Property(property="is_active", type="boolean", example=true),
     *       @OA\Property(property="gender", type="string", enum={"male","female"}, nullable=true),
     *       @OA\Property(property="marital_status", type="string", enum={"single","married","divorced","widowed"}, nullable=true),
     *       @OA\Property(property="nationality", type="string", nullable=true, example="Syrian"),
     *       @OA\Property(property="residence", type="string", nullable=true, example="Damascus, Syria"),
     *       @OA\Property(property="birth_date", type="string", format="date", nullable=true, example="1995-05-20", description="لا يمكن أن يكون تاريخاً مستقبلياً")
     *     )
     *   ),
     *
     *   @OA\Response(response=201, description="تم إنشاء الموظف",
     *
     *     @OA\JsonContent(@OA\Property(property="success", type="boolean"), @OA\Property(property="data", type="object"))
     *   ),
     *
     *   @OA\Response(response=422, description="Validation failed"),
     *   @OA\Response(response=403, description="Forbidden (HR Manager only)")
     * )
     */
    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        try {
            $company = $request->user()->company;
            $result = $this->employeeService->createEmployee($request->validated(), $company);
            $this->employeeService->sendWelcomeEmail($result['user'], $result['password']);

            return response()->json([
                'success' => true,
                'message' => 'Employee created successfully. Login credentials were sent to the employee email.',
                'data' => new EmployeeResource($result['employee']->load('user', 'department')),
            ], 201);
        } catch (\Throwable $th) {
            Log::error('Employee creation failed', ['error' => $th->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to create employee.',
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *   path="/api/hr/employees/{employee}",
     *   summary="عرض تفاصيل موظف تابع للشركة الحالية",
     *   tags={"Employees"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="employee", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\Response(response=200, description="تفاصيل الموظف"),
     *   @OA\Response(response=404, description="Not found / not in your company")
     * )
     */
    public function show(Employee $employee): JsonResponse
    {
        $this->ensureBelongsToCurrentCompany($employee);
        $employee->load('user', 'department', 'document');

        return response()->json([
            'success' => true,
            'data' => new EmployeeResource($employee),
        ]);
    }

    /**
     * @OA\Get(
     *   path="/api/hr/employees/{employee}/profile-overview",
     *   summary="عرض شامل لملف الموظف: البيانات الشخصية والوثائق، تصنيف كل تقييم نهائي (بدون علامة رقمية)، وسجل الحضور/الغياب/الإجازات/العطل منذ تاريخ التعيين حتى اليوم",
     *   description="متاح فقط لمدير الموارد البشرية والمدير العام لنفس الشركة. لا يُرجع أي علامات رقمية للتقييمات، فقط تصنيف نصي (excellent/good/average/weak).",
     *   tags={"Employees"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="employee", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\Response(response=200, description="ملف الموظف الشامل"),
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=403, description="Forbidden (HR Manager or General Manager only)"),
     *   @OA\Response(response=404, description="Not found / not in your company")
     * )
     */
    public function profileOverview(Employee $employee): JsonResponse
    {
        $this->ensureBelongsToCurrentCompany($employee);
        $employee->load('user', 'department', 'document');

        return response()->json([
            'success' => true,
            'data' => [
                'personal_info' => $this->buildPersonalInfo($employee),
                'evaluation_ratings' => $this->buildEvaluationRatings($employee),
                'attendance_history' => $this->buildAttendanceHistory($employee),
            ],
        ]);
    }

    private function buildPersonalInfo(Employee $employee): array
    {
        $user = $employee->user;
        $document = $employee->document;

        return [
            'id' => $employee->id,
            'full_name' => $user?->full_name,
            'email' => $user?->email,
            'phone' => $user?->phone,
            'gender' => $user?->gender,
            'marital_status' => $user?->marital_status,
            'nationality' => $user?->nationality,
            'residence' => $user?->residence,
            'birth_date' => $user?->birth_date,
            'job_title' => $employee->job_title,
            'education' => $employee->education,
            'employment_type' => $employee->employment_type,
            'base_salary' => $employee->base_salary,
            'hire_date' => $employee->hire_date,
            'is_active' => $employee->is_active,
            'department' => $employee->department ? [
                'id' => $employee->department->id,
                'name' => $employee->department->name,
            ] : null,
            'documents' => [
                'profile_image_url' => $document?->profile_image_path
                    ? Storage::disk('public')->url($document->profile_image_path)
                    : null,
                'identity_image_url' => $document?->identity_image_path
                    ? Storage::disk('public')->url($document->identity_image_path)
                    : null,
                'university_certificate_url' => $document?->university_certificate_path
                    ? Storage::disk('public')->url($document->university_certificate_path)
                    : null,
            ],
        ];
    }

    /**
     * Only the rating label is exposed (never the numeric score), per HR's request -
     * one entry per cycle that already has a computed final_score, regardless of whether
     * the score has been finalized yet.
     */
    private function buildEvaluationRatings(Employee $employee): array
    {
        return EvaluationScore::where('employee_id', $employee->id)
            ->where('company_id', $employee->company_id)
            ->whereNotNull('final_score')
            ->with('cycle')
            ->orderByDesc('finalized_at')
            ->get()
            ->map(fn (EvaluationScore $score) => [
                'evaluation_cycle_id' => $score->evaluation_cycle_id,
                'cycle_name' => $score->cycle?->name,
                'period' => [
                    'start_date' => $score->cycle?->start_date?->toDateString(),
                    'end_date' => $score->cycle?->end_date?->toDateString(),
                ],
                'status' => $score->status,
                'rating' => $this->classifyEvaluationScore((float) $score->final_score),
            ])
            ->values()
            ->all();
    }

    /**
     * Same excellent/good/average/weak bands already used to drive the evaluation-linked
     * salary bonus/deduction in SalaryService::resolveEvaluationAdjustment(), so an employee's
     * displayed rating always matches the classification that actually affected their pay.
     */
    private function classifyEvaluationScore(float $score): string
    {
        if ($score >= 8) {
            return 'excellent';
        }
        if ($score >= 6) {
            return 'good';
        }
        if ($score >= 4) {
            return 'average';
        }

        return 'weak';
    }

    private function buildAttendanceHistory(Employee $employee): array
    {
        $from = Carbon::parse($employee->hire_date)->startOfDay();
        $to = Carbon::today();

        $attendanceRecords = AttendanceRecord::where('employee_id', $employee->id)
            ->where('company_id', $employee->company_id)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('work_date')
            ->get();

        $leaveRequests = LeaveRequest::where('employee_id', $employee->id)
            ->where('company_id', $employee->company_id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->with('leaveType')
            ->orderBy('start_date')
            ->get();

        $holidays = Holiday::where('company_id', $employee->company_id)->get();
        $holidayOccurrences = $this->expandHolidayOccurrences($holidays, $from, $to);

        $weeklyHolidayDays = HolidayPolicy::where('company_id', $employee->company_id)
            ->value('weekly_holidays') ?? [];

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'summary' => [
                'present_days' => $attendanceRecords->where('attendance_type', AttendanceRecord::TYPE_PRESENT)->count(),
                'late_days' => $attendanceRecords->where('attendance_type', AttendanceRecord::TYPE_LATE)->count(),
                'early_leave_days' => $attendanceRecords->where('attendance_type', AttendanceRecord::TYPE_EARLY_LEAVE)->count(),
                'absent_days' => $attendanceRecords->where('attendance_type', AttendanceRecord::TYPE_ABSENT)->count(),
                'leave_days' => (float) $leaveRequests->sum('requested_value'),
                'holiday_occurrences' => count($holidayOccurrences),
            ],
            'attendance_records' => $attendanceRecords->map(fn (AttendanceRecord $record) => [
                'work_date' => $record->work_date?->toDateString(),
                'attendance_type' => $record->attendance_type,
                'status' => $record->status,
                'check_in_time' => $record->check_in_time?->toDateTimeString(),
                'check_out_time' => $record->check_out_time?->toDateTimeString(),
                'late_minutes' => $record->late_minutes,
                'early_leave_minutes' => $record->early_leave_minutes,
            ])->values()->all(),
            'leave_requests' => $leaveRequests->map(fn (LeaveRequest $leaveRequest) => [
                'id' => $leaveRequest->id,
                'leave_type_name' => $leaveRequest->leaveType?->name,
                'start_date' => $leaveRequest->start_date?->toDateString(),
                'end_date' => $leaveRequest->end_date?->toDateString(),
                'duration_days' => (float) $leaveRequest->requested_value,
                'status' => $leaveRequest->status,
            ])->values()->all(),
            'holidays' => $holidayOccurrences,
            'weekly_holiday_days' => $weeklyHolidayDays,
        ];
    }

    /**
     * Expands each Holiday row into the concrete date range(s) it actually falls on within
     * [$from, $to] - annually-repeating holidays are re-anchored to every year in the range,
     * one-off holidays are checked once. Occurrences entirely outside the range are dropped.
     */
    private function expandHolidayOccurrences($holidays, Carbon $from, Carbon $to): array
    {
        $occurrences = [];

        foreach ($holidays as $holiday) {
            if ($holiday->repeats_annually) {
                for ($year = $from->year; $year <= $to->year; $year++) {
                    $start = Carbon::create($year, $holiday->start_date->month, $holiday->start_date->day);
                    $end = $holiday->end_date
                        ? Carbon::create($year, $holiday->end_date->month, $holiday->end_date->day)
                        : $start->copy();

                    $this->pushHolidayOccurrenceIfInRange($occurrences, $holiday->name, $start, $end, $from, $to);
                }
            } else {
                $start = $holiday->start_date->copy();
                $end = $holiday->end_date ? $holiday->end_date->copy() : $start->copy();

                $this->pushHolidayOccurrenceIfInRange($occurrences, $holiday->name, $start, $end, $from, $to);
            }
        }

        usort($occurrences, fn ($a, $b) => strcmp($a['start_date'], $b['start_date']));

        return $occurrences;
    }

    private function pushHolidayOccurrenceIfInRange(array &$occurrences, string $name, Carbon $start, Carbon $end, Carbon $from, Carbon $to): void
    {
        if ($end->lt($from) || $start->gt($to)) {
            return;
        }

        $occurrences[] = [
            'name' => $name,
            'start_date' => $start->max($from)->toDateString(),
            'end_date' => $end->min($to)->toDateString(),
        ];
    }

    /**
     * @OA\Put(
     *   path="/api/hr/employees/{employee}",
     *   summary="تعديل بيانات موظف (user + employee) داخل Transaction",
     *   tags={"Employees"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="employee", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\RequestBody(
     *     required=true,
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="full_name", type="string", example="Ahmad Ali Updated"),
     *       @OA\Property(property="email", type="string", format="email", example="ahmad2@example.com"),
     *       @OA\Property(property="phone", type="string", pattern="^09[0-9]{8}$", example="0999999999", description="يجب أن يبدأ بـ 09 ويتكون من 10 أرقام"),
     *       @OA\Property(property="department_id", type="string", format="uuid"),
     *       @OA\Property(property="education", type="string"),
     *       @OA\Property(property="job_title", type="string"),
     *       @OA\Property(property="base_salary", type="number"),
     *       @OA\Property(property="hire_date", type="string", format="date", description="لا يمكن أن يكون تاريخاً مستقبلياً"),
     *       @OA\Property(property="employment_type", type="string"),
     *       @OA\Property(property="is_active", type="boolean"),
     *       @OA\Property(property="gender", type="string", enum={"male","female"}, nullable=true),
     *       @OA\Property(property="marital_status", type="string", enum={"single","married","divorced","widowed"}, nullable=true),
     *       @OA\Property(property="nationality", type="string", nullable=true),
     *       @OA\Property(property="residence", type="string", nullable=true),
     *       @OA\Property(property="birth_date", type="string", format="date", nullable=true, description="لا يمكن أن يكون تاريخاً مستقبلياً")
     *     )
     *   ),
     *
     *   @OA\Response(response=200, description="تم التعديل"),
     *   @OA\Response(response=404, description="Not found / not in your company"),
     *   @OA\Response(response=422, description="Validation failed")
     * )
     */
    public function update(UpdateEmployeeRequest $request, Employee $employee): JsonResponse
    {
        try {
            $this->ensureBelongsToCurrentCompany($employee);
            $employee = $this->employeeService->updateEmployee($employee, $request->validated());
            $employee->load('department');

            return response()->json([
                'success' => true,
                'message' => 'Employee updated successfully.',
                'data' => new EmployeeResource($employee),
            ]);
        } catch (\Throwable $th) {
            Log::error('Employee update failed', ['error' => $th->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to update employee.',
            ], 500);
        }
    }

    /**
     * @OA\Delete(
     *   path="/api/hr/employees/{employee}",
     *   summary="حذف موظف مع حسابه المرتبط (لتفادي بيانات يتيمة)",
     *   description="إذا كان للموظف أي سجلات تاريخية مرتبطة (حضور، إجازات، رواتب، سُلف، إضافي، تقييمات) لا يتم الحذف إطلاقاً - يتم تجميد حسابه (is_active=false) بدلاً من ذلك ويُرجع 409.",
     *   tags={"Employees"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="employee", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\Response(response=200, description="تم الحذف"),
     *   @OA\Response(response=403, description="Cannot delete: user is a General Manager or Super Admin"),
     *   @OA\Response(response=404, description="Not found / not in your company"),
     *   @OA\Response(
     *     response=409,
     *     description="لا يمكن الحذف بسبب وجود سجلات مرتبطة - تم تجميد الموظف بدلاً من ذلك",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=false),
     *       @OA\Property(property="message", type="string", example="لا يمكن حذف الموظف لأنه يمتلك سجلات مرتبطة بالنظام. تم تجميد حسابه بدلاً من ذلك.")
     *     )
     *   )
     * )
     */
    public function destroy(Employee $employee): JsonResponse
    {
        try {
            $this->ensureBelongsToCurrentCompany($employee);

            $user = $employee->user;
            if ($user && in_array($user->role, [Role::GeneralManager->value, Role::SuperAdmin->value], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete a General Manager or Super Admin account.',
                ], 403);
            }

            $result = $this->employeeService->deleteEmployee($employee);

            if (! $result['deleted']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete this employee because they have related records. The account was frozen instead.',
                ], 409);
            }

            return response()->json([
                'success' => true,
                'message' => 'Employee deleted successfully.',
            ]);
        } catch (\Throwable $th) {
            Log::error('Employee deletion failed', ['error' => $th->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to delete employee.',
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *   path="/api/hr/employees/import",
     *   summary="استيراد موظفين من ملف Excel/CSV (All-or-nothing: إن وُجد خطأ لا يُدخل أي صف)",
     *   description="بالإضافة للأعمدة الأساسية، يدعم الملف أعمدة اختيارية إضافية تُحفظ مباشرة عند إنشاء المستخدم: gender (male/female)، marital_status (single/married/divorced/widowed)، nationality، residence، birth_date (تاريخ الميلاد، Y-m-d أو d/m/Y، لا يمكن أن يكون مستقبلياً). صور الملف الشخصي/الهوية/الشهادة الجامعية لا تُستورد من الإكسل ويرفعها الموظف لاحقاً بنفسه.",
     *   tags={"Employees"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\RequestBody(
     *     required=true,
     *
     *     @OA\MediaType(
     *       mediaType="multipart/form-data",
     *
     *       @OA\Schema(@OA\Property(property="file", type="string", format="binary"))
     *     )
     *   ),
     *
     *   @OA\Response(response=200, description="تم الاستيراد",
     *
     *     @OA\JsonContent(@OA\Property(property="success", type="boolean"), @OA\Property(property="count", type="integer"))
     *   ),
     *
     *   @OA\Response(response=422, description="أخطاء في الصفوف",
     *
     *     @OA\JsonContent(@OA\Property(property="success", type="boolean", example=false), @OA\Property(property="errors", type="object"))
     *   )
     * )
     */
    public function import(ImportEmployeesRequest $request): JsonResponse
    {
        try {
            $company = $request->user()->company;
            $result = $this->employeeService->importFromFile($request->file('file'), $company);

            if (! $result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Import failed. No employees were added.',
                    'errors' => $result['errors'],
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'Employees imported successfully.',
                'count' => $result['count'],
            ]);
        } catch (\Throwable $th) {
            Log::error('Employee import failed', ['error' => $th->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to process the import file.',
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *   path="/api/hr/employees/import/template",
     *   summary="تحميل قالب Excel جاهز لاستيراد الموظفين",
     *   tags={"Employees"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Response(response=200, description="ملف xlsx",
     *
     *     @OA\MediaType(mediaType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")
     *   )
     * )
     */
    public function downloadTemplate(): BinaryFileResponse
    {
        return Excel::download(new EmployeeTemplateExport, 'employees_template.xlsx');
    }

    /**
     * @OA\Get(
     *   path="/api/management/employees",
     *   summary="عرض موظفي القسم الذي يديره مدير القسم الحالي فقط (مع Pagination/Search/Sort/Filter)",
     *   description="متاح فقط لمدير القسم (Department Manager). يعرض حصراً الموظفين التابعين للقسم/الأقسام التي يديرها (department.manager_id = الموظف الحالي)، بدون أي إمكانية لتمرير department_id من الطلب.",
     *   tags={"Employees"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="page", in="query", required=false, @OA\Schema(type="integer")),
     *   @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=15)),
     *   @OA\Parameter(name="search", in="query", required=false, @OA\Schema(type="string"), description="بحث في الاسم والإيميل والمسمى الوظيفي"),
     *   @OA\Parameter(name="is_active", in="query", required=false, @OA\Schema(type="boolean")),
     *   @OA\Parameter(name="sort_by", in="query", required=false, @OA\Schema(type="string", default="hire_date")),
     *   @OA\Parameter(name="sort_dir", in="query", required=false, @OA\Schema(type="string", default="desc")),
     *
     *   @OA\Response(
     *     response=200,
     *     description="قائمة موظفي القسم الذي يديره مدير القسم",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *     )
     *   ),
     *
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=403, description="Forbidden (Department Manager only)")
     * )
     */
    public function myDepartmentEmployees(Request $request): JsonResponse
    {
        $user = auth()->user();
        $companyId = (string) $user->company_id;
        $managerEmployee = $user->employee;

        if (! $managerEmployee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee record not found.',
            ], 403);
        }

        $managedDepartmentIds = Department::where('company_id', $companyId)
            ->where('manager_id', $managerEmployee->id)
            ->pluck('id');

        $sortBy = in_array($request->input('sort_by'), ['hire_date', 'created_at', 'job_title', 'base_salary'], true)
            ? $request->input('sort_by') : 'hire_date';
        $sortDir = in_array(strtolower($request->input('sort_dir')), ['asc', 'desc'], true)
            ? strtolower($request->input('sort_dir')) : 'desc';

        $query = Employee::where('company_id', $companyId)
            ->whereIn('department_id', $managedDepartmentIds)
            ->with(['user', 'department', 'document']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('job_title', 'ilike', "%{$search}%")
                    ->orWhereHas('user', function ($u) use ($search) {
                        $u->where('full_name', 'ilike', "%{$search}%")
                            ->orWhere('email', 'ilike', "%{$search}%");
                    });
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = (int) ($request->input('per_page', 15));
        $employees = $query->orderBy($sortBy, $sortDir)->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => EmployeeResource::collection($employees),
        ]);
    }

    /**
     * @OA\Get(
     *   path="/api/management/employees/{employee}",
     *   summary="عرض تفاصيل موظف تابع للقسم الذي يديره مدير القسم الحالي فقط",
     *   description="متاح فقط لمدير القسم (Department Manager). يرفض الطلب (404) إذا لم يكن الموظف تابعاً لقسم يديره مدير القسم الحالي.",
     *   tags={"Employees"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="employee", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\Response(response=200, description="تفاصيل الموظف"),
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=403, description="Forbidden (Department Manager only, or no employee record)"),
     *   @OA\Response(response=404, description="Not found / not in a department you manage")
     * )
     */
    public function showMyDepartmentEmployee(Employee $employee): JsonResponse
    {
        $user = auth()->user();
        $companyId = (string) $user->company_id;
        $managerEmployee = $user->employee;

        if (! $managerEmployee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee record not found.',
            ], 403);
        }

        $isManaged = Department::where('company_id', $companyId)
            ->where('manager_id', $managerEmployee->id)
            ->where('id', $employee->department_id)
            ->exists();

        if ($employee->company_id !== $companyId || ! $isManaged) {
            abort(404, 'Employee not found.');
        }

        $employee->load('user', 'department', 'document');

        return response()->json([
            'success' => true,
            'data' => new EmployeeResource($employee),
        ]);
    }

    protected function currentUserCompanyId(): string
    {
        return (string) auth()->user()->company_id;
    }

    protected function ensureBelongsToCurrentCompany(Employee $employee): void
    {
        if ($employee->company_id !== $this->currentUserCompanyId()) {
            abort(404, 'Employee not found.');
        }
    }
}
