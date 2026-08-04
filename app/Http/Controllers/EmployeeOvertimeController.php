<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApplyOvertimeRequest;
use App\Models\OvertimeRequest;
use App\Services\OvertimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * @OA\Tag(
 *   name="Employee Overtime",
 *   description="Employee overtime (العمل الإضافي) self-service endpoints"
 * )
 */
class EmployeeOvertimeController extends Controller
{
    public function __construct(
        private readonly OvertimeService $overtimeService,
    ) {
    }

    /**
     * @OA\Get(
     *   path="/api/employee/overtime",
     *   summary="List the logged-in employee's overtime requests",
     *   tags={"Employee Overtime"},
     *   security={{"sanctum":{}}},
     *   @OA\Response(response=200, description="Paginated overtime requests")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        $employee = $user?->employee;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee record not found.',
            ], 403);
        }

        $perPage = max(1, min((int) $request->input('per_page', 15), 100));

        $paginator = OvertimeRequest::where('employee_id', $employee->id)
            ->where('company_id', $user->company_id)
            ->with(['employee.company'])
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->through(fn (OvertimeRequest $ot) => $this->serialize($ot));

        return response()->json([
            'success' => true,
            'data' => $paginator,
        ]);
    }

    /**
     * @OA\Get(
     *   path="/api/employee/overtime/rates",
     *   summary="Show current overtime rates per hour and per day based on company salary rules",
     *   tags={"Employee Overtime"},
     *   security={{"sanctum":{}}},
     *   @OA\Response(
     *     response=200,
     *     description="Current overtime rates",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="rate_per_hour", type="number", format="float", nullable=true),
     *         @OA\Property(property="rate_per_day", type="number", format="float", nullable=true),
     *         @OA\Property(property="currency", type="string", example="SYP")
     *       )
     *     )
     *   )
     * )
     */
    public function rates(): JsonResponse
    {
        $user = auth()->user();
        $employee = $user?->employee;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee record not found.',
            ], 403);
        }

        if (! $this->overtimeService->allowsOvertime($user->company_id)) {
            return response()->json([
                'success' => false,
                'message' => 'Overtime is not allowed by company attendance policy.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $this->overtimeService->rates($employee),
        ]);
    }

    /**
     * @OA\Get(
     *   path="/api/employee/overtime/preview",
     *   summary="Preview estimated overtime pay from company salary rules",
     *   tags={"Employee Overtime"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(name="duration_type", in="query", required=true, @OA\Schema(type="string", enum={"hour","day"})),
     *   @OA\Parameter(name="units", in="query", required=true, @OA\Schema(type="integer", minimum=1)),
     *   @OA\Response(response=200, description="Estimated overtime amount")
     * )
     */
    public function preview(Request $request): JsonResponse
    {
        $user = auth()->user();
        $employee = $user?->employee;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee record not found.',
            ], 403);
        }

        $durationType = $request->input('duration_type');
        $units = (int) $request->input('units');

        if (! in_array($durationType, [OvertimeRequest::DURATION_HOUR, OvertimeRequest::DURATION_DAY], true) || $units < 1) {
            return response()->json([
                'success' => false,
                'message' => 'duration_type (hour|day) and units (>=1) are required.',
            ], 422);
        }

        if (! $this->overtimeService->allowsOvertime($user->company_id)) {
            return response()->json([
                'success' => false,
                'message' => 'Overtime is not allowed by company attendance policy.',
            ], 422);
        }

        $preview = $this->overtimeService->preview($employee, $durationType, $units);

        if (! $preview['ok']) {
            return response()->json([
                'success' => false,
                'message' => $preview['message'],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $preview,
        ]);
    }

    /**
     * @OA\Post(
     *   path="/api/employee/overtime/apply",
     *   summary="Submit an overtime request",
     *   tags={"Employee Overtime"},
     *   security={{"sanctum":{}}},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"request_date","duration_type","units"},
     *       @OA\Property(property="request_date", type="string", format="date", example="2026-08-02"),
     *       @OA\Property(property="duration_type", type="string", enum={"hour","day"}),
     *       @OA\Property(property="units", type="integer", example=3),
     *       @OA\Property(property="reason", type="string", nullable=true)
     *     )
     *   ),
     *   @OA\Response(response=201, description="Overtime request submitted")
     * )
     */
    public function apply(ApplyOvertimeRequest $request): JsonResponse
    {
        $user = auth()->user();
        $employee = $user?->employee;
        $companyId = $user?->company_id;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee record not found.',
            ], 403);
        }

        if (! $employee->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Inactive employees cannot apply for overtime.',
            ], 422);
        }

        if (! $this->overtimeService->allowsOvertime($companyId)) {
            return response()->json([
                'success' => false,
                'message' => 'Overtime is not allowed by company attendance policy.',
            ], 422);
        }

        if (! $this->overtimeService->employeeHasDepartmentManager($employee)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot apply: your department has no assigned manager.',
            ], 422);
        }

        $durationType = $request->validated('duration_type');
        $units = (int) $request->validated('units');

        if ($durationType === OvertimeRequest::DURATION_DAY && $units > 7) {
            return response()->json([
                'success' => false,
                'message' => 'Day-based overtime cannot exceed 7 days per request.',
            ], 422);
        }

        $preview = $this->overtimeService->preview($employee, $durationType, $units);
        if (! $preview['ok']) {
            return response()->json([
                'success' => false,
                'message' => $preview['message'],
            ], 422);
        }

        $overtime = OvertimeRequest::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            'request_date' => $request->validated('request_date'),
            'duration_type' => $durationType,
            'hours_requested' => $units,
            'reason' => $request->validated('reason'),
            'status' => OvertimeRequest::STATUS_PENDING_DEPARTMENT_MANAGER,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Overtime request submitted successfully.',
            'data' => array_merge($this->serialize($overtime), [
                'estimated_amount' => $preview['estimated_amount'],
                'unit_amount' => $preview['unit_amount'],
                'rates' => $this->overtimeService->rates($employee),
            ]),
        ], 201);
    }

    private function serialize(OvertimeRequest $ot): array
    {
        $rule = $this->overtimeService->activeRuleFor($ot->company_id, $ot->duration_type);

        $unitAmount = null;
        $totalAmount = null;

        if ($rule && $ot->employee) {
            $unitAmount = $this->overtimeService->calculateAmount($ot->employee, $rule, 1);
            $totalAmount = $this->overtimeService->calculateAmount($ot->employee, $rule, $ot->approvedUnits());
        }

        return [
            'id' => $ot->id,
            'request_date' => $ot->request_date?->toDateString(),
            'duration_type' => $ot->duration_type,
            'units_requested' => $ot->hours_requested,
            'units_approved' => $ot->hours_approved,
            'reason' => $ot->reason,
            'status' => $ot->status,
            'rejection_reason' => $ot->rejection_reason,
            'calculated_amount' => $ot->calculated_amount,
            'unit_amount' => $unitAmount,
            'estimated_amount' => $totalAmount,
            'currency' => $ot->employee?->company?->payroll_currency ?? 'SYP',
            'created_at' => $ot->created_at?->toDateTimeString(),
        ];
    }
}
