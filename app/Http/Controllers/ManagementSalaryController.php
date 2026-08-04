<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Employee;
use App\Models\SalaryRecord;
use App\Services\SalaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * @OA\Tag(
 *   name="Management Salaries",
 *   description="HR salary generation and payment closing"
 * )
 */
class ManagementSalaryController extends Controller
{
    public function __construct(
        private readonly SalaryService $salaryService,
    ) {
    }

    /**
     * @OA\Get(
     *   path="/api/management/salaries",
     *   summary="List salary records for the company (HR)",
     *   tags={"Management Salaries"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(name="month", in="query", required=false, @OA\Schema(type="integer")),
     *   @OA\Parameter(name="year", in="query", required=false, @OA\Schema(type="integer")),
     *   @OA\Parameter(name="status", in="query", required=false, @OA\Schema(type="string")),
     *   @OA\Parameter(name="employee_id", in="query", required=false, @OA\Schema(type="string", format="uuid")),
     *   @OA\Response(response=200, description="Paginated salary records")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        if ($user?->role !== Role::HrManager->value) {
            return response()->json(['success' => false, 'message' => 'HR access only.'], 403);
        }

        $query = SalaryRecord::where('company_id', $user->company_id)
            ->with(['employee.user'])
            ->orderByDesc('year')
            ->orderByDesc('month');

        if ($request->filled('month')) {
            $query->where('month', (int) $request->input('month'));
        }
        if ($request->filled('year')) {
            $query->where('year', (int) $request->input('year'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->input('employee_id'));
        }

        $perPage = max(1, min((int) $request->input('per_page', 20), 100));
        $paginator = $query->paginate($perPage);

        $paginator->getCollection()->transform(function (SalaryRecord $record) {
            $summary = $this->salaryService->serializeSummary($record);
            $summary['employee_name'] = $record->employee?->user?->full_name;
            $summary['employee_id'] = $record->employee_id;

            return $summary;
        });

        return response()->json([
            'success' => true,
            'data' => $paginator,
        ]);
    }

    /**
     * @OA\Get(
     *   path="/api/management/salaries/{id}",
     *   summary="Get salary record details (HR)",
     *   tags={"Management Salaries"},
     *   security={{"sanctum":{}}},
     *   @OA\Response(response=200, description="Salary details")
     * )
     */
    public function show(string $id): JsonResponse
    {
        $user = auth()->user();
        if ($user?->role !== Role::HrManager->value) {
            return response()->json(['success' => false, 'message' => 'HR access only.'], 403);
        }

        $record = SalaryRecord::where('id', $id)
            ->where('company_id', $user->company_id)
            ->with(['employee.user', 'salaryAdjustments'])
            ->firstOrFail();

        $details = $this->salaryService->serializeDetails($record);
        $details['employee_name'] = $record->employee?->user?->full_name;
        $details['employee_id'] = $record->employee_id;

        return response()->json([
            'success' => true,
            'data' => $details,
        ]);
    }

    /**
     * @OA\Get(
     *   path="/api/management/salaries/employees/{employee}/history",
     *   summary="Get full salary history and details for a selected employee",
     *   tags={"Management Salaries"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="employee",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Response(response=200, description="Employee salary history")
     * )
     */
    public function employeeHistory(Employee $employee): JsonResponse
    {
        $user = auth()->user();
        if ($user?->role !== Role::HrManager->value) {
            return response()->json(['success' => false, 'message' => 'HR access only.'], 403);
        }

        if ($employee->company_id !== $user->company_id) {
            return response()->json(['success' => false, 'message' => 'Employee does not belong to your company.'], 403);
        }

        $records = SalaryRecord::where('employee_id', $employee->id)
            ->where('company_id', $user->company_id)
            ->with(['salaryAdjustments'])
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get()
            ->map(function (SalaryRecord $record) use ($employee) {
                $details = $this->salaryService->serializeDetails($record);
                $details['employee_id'] = $employee->id;
                $details['employee_name'] = $employee->user?->full_name;

                return $details;
            });

        return response()->json([
            'success' => true,
            'data' => [
                'employee_id' => $employee->id,
                'employee_name' => $employee->user?->full_name,
                'job_title' => $employee->job_title,
                'records' => $records,
            ],
        ]);
    }

    /**
     * @OA\Get(
     *   path="/api/management/salaries/by-month",
     *   summary="Get detailed salary records for all employees in a selected month",
     *   tags={"Management Salaries"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(name="month", in="query", required=true, @OA\Schema(type="integer")),
     *   @OA\Parameter(name="year", in="query", required=true, @OA\Schema(type="integer")),
     *   @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=100)),
     *   @OA\Response(response=200, description="Monthly salary details")
     * )
     */
    public function byMonth(Request $request): JsonResponse
    {
        $user = auth()->user();
        if ($user?->role !== Role::HrManager->value) {
            return response()->json(['success' => false, 'message' => 'HR access only.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $month = (int) $validator->validated()['month'];
        $year = (int) $validator->validated()['year'];
        $perPage = (int) ($validator->validated()['per_page'] ?? 100);

        $paginator = SalaryRecord::where('company_id', $user->company_id)
            ->where('month', $month)
            ->where('year', $year)
            ->with(['employee.user', 'salaryAdjustments'])
            ->orderBy('employee_id')
            ->paginate($perPage);

        $paginator->getCollection()->transform(function (SalaryRecord $record) {
            $details = $this->salaryService->serializeDetails($record);
            $details['employee_id'] = $record->employee_id;
            $details['employee_name'] = $record->employee?->user?->full_name;

            return $details;
        });

        return response()->json([
            'success' => true,
            'data' => array_merge($paginator->toArray(), [
                'month' => $month,
                'year' => $year,
                'period' => sprintf('%04d-%02d', $year, $month),
            ]),
        ]);
    }

    /**
     * @OA\Post(
     *   path="/api/management/salaries/generate",
     *   summary="Generate draft salary records for a month",
     *   tags={"Management Salaries"},
     *   security={{"sanctum":{}}},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"month","year"},
     *       @OA\Property(property="month", type="integer", example=8),
     *       @OA\Property(property="year", type="integer", example=2026),
     *       @OA\Property(property="employee_id", type="string", format="uuid", nullable=true)
     *     )
     *   ),
     *   @OA\Response(response=201, description="Draft salaries generated")
     * )
     */
    public function generate(Request $request): JsonResponse
    {
        $user = auth()->user();
        if ($user?->role !== Role::HrManager->value) {
            return response()->json(['success' => false, 'message' => 'HR access only.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'employee_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $month = (int) $validator->validated()['month'];
        $year = (int) $validator->validated()['year'];
        $employeeId = $validator->validated()['employee_id'] ?? null;

        $employeesQuery = Employee::where('company_id', $user->company_id)
            ->where('is_active', true);

        if ($employeeId) {
            $employeesQuery->where('id', $employeeId);
        }

        $created = 0;
        $updated = 0;
        $skippedPaid = 0;

        DB::transaction(function () use ($employeesQuery, $month, $year, &$created, &$updated, &$skippedPaid) {
            $employeesQuery->orderBy('id')->chunkById(100, function ($employees) use ($month, $year, &$created, &$updated, &$skippedPaid) {
                foreach ($employees as $employee) {
                    $existing = SalaryRecord::where('employee_id', $employee->id)
                        ->where('month', $month)
                        ->where('year', $year)
                        ->first();

                    if ($existing && $this->salaryService->isPaid($existing)) {
                        $skippedPaid++;
                        continue;
                    }

                    $wasNew = $existing === null;
                    $this->salaryService->ensureDraftRecord($employee, $month, $year);
                    $wasNew ? $created++ : $updated++;
                }
            });
        });

        return response()->json([
            'success' => true,
            'message' => 'Salary drafts generated.',
            'data' => [
                'month' => $month,
                'year' => $year,
                'created' => $created,
                'updated' => $updated,
                'skipped_paid' => $skippedPaid,
            ],
        ], 201);
    }

    /**
     * @OA\Post(
     *   path="/api/management/salaries/{id}/pay",
     *   summary="Mark a salary record as paid/received",
     *   tags={"Management Salaries"},
     *   security={{"sanctum":{}}},
     *   @OA\Response(response=200, description="Salary marked as paid")
     * )
     */
    public function pay(string $id): JsonResponse
    {
        $user = auth()->user();
        if ($user?->role !== Role::HrManager->value) {
            return response()->json(['success' => false, 'message' => 'HR access only.'], 403);
        }

        $record = SalaryRecord::where('id', $id)
            ->where('company_id', $user->company_id)
            ->firstOrFail();

        if ($this->salaryService->isPaid($record)) {
            return response()->json([
                'success' => false,
                'message' => 'Salary is already marked as paid.',
            ], 422);
        }

        $record = $this->salaryService->markPaid($record, $user->id);

        return response()->json([
            'success' => true,
            'message' => 'Salary marked as paid.',
            'data' => $this->salaryService->serializeDetails($record),
        ]);
    }
}
