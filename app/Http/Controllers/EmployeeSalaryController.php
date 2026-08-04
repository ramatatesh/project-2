<?php

namespace App\Http\Controllers;

use App\Models\SalaryRecord;
use App\Services\SalaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(
 *   name="Employee Salaries",
 *   description="Employee self-service salary history and monthly payslip details"
 * )
 */
class EmployeeSalaryController extends Controller
{
    public function __construct(
        private readonly SalaryService $salaryService,
    ) {
    }

    /**
     * @OA\Get(
     *   path="/api/employee/salaries",
     *   summary="List monthly salary records for the logged-in employee",
     *   tags={"Employee Salaries"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(name="year", in="query", required=false, @OA\Schema(type="integer")),
     *   @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=12)),
     *   @OA\Response(response=200, description="Salary history with last received salary")
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

        $perPage = max(1, min((int) $request->input('per_page', 12), 100));

        $query = SalaryRecord::where('employee_id', $employee->id)
            ->where('company_id', $user->company_id)
            ->orderByDesc('year')
            ->orderByDesc('month');

        if ($request->filled('year')) {
            $query->where('year', (int) $request->input('year'));
        }

        // Employees mainly care about finalized/received slips; drafts still listed
        // so they can see in-progress months after overtime/advances are applied.
        $paginator = $query->paginate($perPage);
        $paginator->getCollection()->transform(
            fn (SalaryRecord $record) => $this->salaryService->serializeSummary($record)
        );

        $lastReceived = $this->salaryService->lastReceived($employee);

        return response()->json([
            'success' => true,
            'data' => [
                'last_received_salary' => $lastReceived
                    ? [
                        'amount' => (float) $lastReceived->net_salary,
                        'month' => (int) $lastReceived->month,
                        'year' => (int) $lastReceived->year,
                        'period' => sprintf('%04d-%02d', $lastReceived->year, $lastReceived->month),
                        'received_at' => $lastReceived->closed_at?->toDateTimeString(),
                        'payment_summary' => $this->salaryService->paymentSummary($lastReceived),
                        'salary_record_id' => $lastReceived->id,
                    ]
                    : null,
                'records' => $paginator,
            ],
        ]);
    }

    /**
     * @OA\Get(
     *   path="/api/employee/salaries/{id}",
     *   summary="Get detailed payslip for a salary month",
     *   tags={"Employee Salaries"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="id",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Response(response=200, description="Salary month details"),
     *   @OA\Response(response=404, description="Not found")
     * )
     */
    public function show(string $id): JsonResponse
    {
        $user = auth()->user();
        $employee = $user?->employee;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee record not found.',
            ], 403);
        }

        $record = SalaryRecord::where('id', $id)
            ->where('employee_id', $employee->id)
            ->where('company_id', $user->company_id)
            ->with('salaryAdjustments')
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $this->salaryService->serializeDetails($record),
        ]);
    }
}
