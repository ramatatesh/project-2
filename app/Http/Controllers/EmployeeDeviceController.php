<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeDevice;
use App\Services\EmployeeDeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * @OA\Tag(
 *   name="Employee Devices",
 *   description="HR management of attendance device bindings (anti buddy-punching)"
 * )
 */
class EmployeeDeviceController extends Controller
{
    public function __construct(
        private readonly EmployeeDeviceService $employeeDeviceService,
    ) {
    }

    /**
     * @OA\Get(
     *   path="/api/hr/employee-devices",
     *   summary="List active (and optionally inactive) bound attendance devices",
     *   tags={"Employee Devices"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(name="employee_id", in="query", required=false, @OA\Schema(type="string", format="uuid")),
     *   @OA\Parameter(name="active_only", in="query", required=false, @OA\Schema(type="boolean", default=true)),
     *   @OA\Response(response=200, description="Device bindings list")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = auth()->user()?->company_id;

        $validator = Validator::make($request->all(), [
            'employee_id' => ['nullable', 'uuid'],
            'active_only' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $activeOnly = $request->boolean('active_only', true);

        $query = EmployeeDevice::query()
            ->where('company_id', $companyId)
            ->with(['employee.user', 'unboundBy:id,full_name'])
            ->orderByDesc('bound_at');

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->input('employee_id'));
        }

        $items = $query->get()->map(fn (EmployeeDevice $device) => [
            ...$this->employeeDeviceService->serialize($device),
            'employee_name' => $device->employee?->user?->full_name,
            'unbound_by_name' => $device->unboundBy?->full_name,
        ]);

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    /**
     * @OA\Get(
     *   path="/api/hr/employees/{employee}/device",
     *   summary="Get the active bound device for an employee (plus recent history)",
     *   tags={"Employee Devices"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(name="employee", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *   @OA\Response(response=200, description="Employee device binding"),
     *   @OA\Response(response=404, description="Employee not found")
     * )
     */
    public function show(Employee $employee): JsonResponse
    {
        if ($employee->company_id !== auth()->user()?->company_id) {
            abort(404);
        }

        $active = $this->employeeDeviceService->activeDevice($employee);

        $history = EmployeeDevice::query()
            ->where('employee_id', $employee->id)
            ->orderByDesc('bound_at')
            ->limit(10)
            ->get()
            ->map(fn (EmployeeDevice $device) => $this->employeeDeviceService->serialize($device));

        return response()->json([
            'success' => true,
            'data' => [
                'employee_id' => $employee->id,
                'employee_name' => $employee->user?->full_name,
                'active_device' => $this->employeeDeviceService->serialize($active),
                'history' => $history,
            ],
        ]);
    }

    /**
     * @OA\Post(
     *   path="/api/hr/employees/{employee}/device/unbind",
     *   summary="Unbind the employee's attendance device so they can bind a new phone",
     *   tags={"Employee Devices"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(name="employee", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *   @OA\RequestBody(
     *     required=false,
     *     @OA\JsonContent(
     *       @OA\Property(property="reason", type="string", example="Employee replaced lost phone")
     *     )
     *   ),
     *   @OA\Response(response=200, description="Device unbound"),
     *   @OA\Response(response=404, description="No active device / employee not found")
     * )
     */
    public function unbind(Request $request, Employee $employee): JsonResponse
    {
        if ($employee->company_id !== auth()->user()?->company_id) {
            abort(404);
        }

        $validator = Validator::make($request->all(), [
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $unbound = $this->employeeDeviceService->unbind(
            $employee,
            auth()->user(),
            $validator->validated()['reason'] ?? null
        );

        if (! $unbound) {
            return response()->json([
                'success' => false,
                'message' => 'No active device is bound to this employee.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Device unbound successfully. The employee can bind a new device on next check-in.',
            'data' => $this->employeeDeviceService->serialize($unbound),
        ]);
    }
}
