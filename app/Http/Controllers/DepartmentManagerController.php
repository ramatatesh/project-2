<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\DepartmentManagerRequest;
use App\Http\Requests\UpdateDepartmentManagerRequest;
use App\Jobs\SendRegistrationEmailJob;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\EmployeeService;
use App\Services\LeaveBalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * @OA\Tag(
 *   name="Department Managers",
 *   description="Endpoints for managing department manager accounts within the current company (HR only)"
 * )
 */
class DepartmentManagerController extends Controller
{
    public function __construct(
        private readonly LeaveBalanceService $leaveBalanceService,
        private readonly EmployeeService $employeeService,
    ) {
    }

    /**
     * @OA\Get(
     *   path="/api/hr/department-managers",
     *   summary="List department managers for the current company",
     *   tags={"Department Managers"},
     *   security={{"sanctum":{}}},
     *   @OA\Response(
     *     response=200,
     *     description="List of department managers",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *     )
     *   )
     * )
     */
    public function index(): JsonResponse
    {
        $companyId = auth()->user()?->company_id;

        $managers = User::where('company_id', $companyId)
            ->where('role', Role::DepartmentManager->value)
            ->with('employee.department')
            ->orderBy('full_name')
            ->get()
            ->map(fn (User $manager) => $this->serializeDepartmentManager($manager));

        return response()->json([
            'success' => true,
            'data' => $managers,
        ]);
    }

    /**
     * @OA\Post(
     *   path="/api/hr/department-managers",
     *   summary="Create a new department manager",
     *   tags={"Department Managers"},
     *   security={{"sanctum":{}}},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"full_name","email","department_id","job_title","base_salary","hire_date"},
     *       @OA\Property(property="full_name", type="string", example="Omar Hassan"),
     *       @OA\Property(property="email", type="string", format="email", example="omar@company.com"),
     *       @OA\Property(property="phone", type="string", pattern="^09[0-9]{8}$", example="0999888777", description="يجب أن يبدأ بـ 09 ويتكون من 10 أرقام"),
     *       @OA\Property(property="department_id", type="string", format="uuid"),
     *       @OA\Property(property="education", type="string", example="Bachelor of Engineering"),
     *       @OA\Property(property="job_title", type="string", example="Engineering Manager"),
     *       @OA\Property(property="base_salary", type="number", format="float", example=1500),
     *       @OA\Property(property="hire_date", type="string", format="date", example="2026-01-15", description="لا يمكن أن يكون تاريخاً مستقبلياً"),
     *       @OA\Property(property="employment_type", type="string", example="full-time"),
     *       @OA\Property(property="is_active", type="boolean", example=true),
     *       @OA\Property(property="gender", type="string", enum={"male","female"}, nullable=true),
     *       @OA\Property(property="marital_status", type="string", enum={"single","married","divorced","widowed"}, nullable=true),
     *       @OA\Property(property="nationality", type="string", nullable=true, example="Syrian"),
     *       @OA\Property(property="residence", type="string", nullable=true, example="Damascus, Syria"),
     *       @OA\Property(property="birth_date", type="string", format="date", nullable=true, example="1990-03-10", description="لا يمكن أن يكون تاريخاً مستقبلياً")
     *     )
     *   ),
     *   @OA\Response(response=201, description="Department manager created successfully"),
     *   @OA\Response(response=422, description="Validation failed")
     * )
     */
    public function store(DepartmentManagerRequest $request): JsonResponse
    {
        try {
            $companyId = auth()->user()?->company_id;
            $data = $request->validated();
            $tempPassword = Str::random(10);

            $department = Department::where('id', $data['department_id'])
                ->where('company_id', $companyId)
                ->firstOrFail();

            $manager = DB::transaction(function () use ($companyId, $data, $tempPassword, $department) {
                $user = User::create([
                    'id' => Str::uuid()->toString(),
                    'company_id' => $companyId,
                    'full_name' => $data['full_name'],
                    'email' => $data['email'],
                    'password_hash' => Hash::make($tempPassword),
                    'role' => Role::DepartmentManager->value,
                    'status' => ($data['is_active'] ?? true) ? 'active' : 'inactive',
                    'is_first_login' => true,
                    'phone' => $data['phone'] ?? null,
                    'gender' => $data['gender'] ?? null,
                    'marital_status' => $data['marital_status'] ?? null,
                    'nationality' => $data['nationality'] ?? null,
                    'residence' => $data['residence'] ?? null,
                    'birth_date' => $data['birth_date'] ?? null,
                ]);

                $employee = Employee::create([
                    'id' => Str::uuid()->toString(),
                    'user_id' => $user->id,
                    'company_id' => $companyId,
                    'department_id' => $department->id,
                    'education' => $data['education'] ?? null,
                    'job_title' => $data['job_title'],
                    'base_salary' => $data['base_salary'],
                    'hire_date' => $data['hire_date'],
                    'employment_type' => $data['employment_type'] ?? null,
                    'is_active' => $data['is_active'] ?? true,
                ]);

                $department->update(['manager_id' => $employee->id]);

                $this->leaveBalanceService->initializeForEmployee($employee);

                $user->setRelation('employee', $employee);

                return $user;
            });

            SendRegistrationEmailJob::dispatch($manager->email, $tempPassword);

            return response()->json([
                'success' => true,
                'message' => 'Department manager created successfully.',
                'data' => [
                    'department_manager' => $this->serializeDepartmentManager($manager->load('employee.department')),
                ],
            ], 201);
        } catch (\Throwable $th) {
            Log::error('Department manager creation failed', ['error' => $th->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to create department manager.',
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *   path="/api/hr/department-managers/{department_manager}",
     *   summary="Get department manager details",
     *   tags={"Department Managers"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="department_manager",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Response(response=200, description="Department manager details")
     * )
     */
    public function show(User $departmentManager): JsonResponse
    {
        $departmentManager = $this->ensureBelongsToCurrentCompany($departmentManager);
        $departmentManager->load('employee.department');

        return response()->json([
            'success' => true,
            'data' => $this->serializeDepartmentManager($departmentManager),
        ]);
    }

    /**
     * @OA\Put(
     *   path="/api/hr/department-managers/{department_manager}",
     *   summary="Update a department manager",
     *   tags={"Department Managers"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="department_manager",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Response(response=200, description="Department manager updated successfully")
     * )
     */
    public function update(UpdateDepartmentManagerRequest $request, User $departmentManager): JsonResponse
    {
        try {
            $departmentManager = $this->ensureBelongsToCurrentCompany($departmentManager);
            $data = $request->validated();
            $companyId = auth()->user()?->company_id;

            DB::transaction(function () use ($departmentManager, $data, $companyId) {
                $userUpdates = [];

                if (array_key_exists('full_name', $data)) {
                    $userUpdates['full_name'] = $data['full_name'];
                }
                if (array_key_exists('email', $data)) {
                    $userUpdates['email'] = $data['email'];
                }
                if (array_key_exists('phone', $data)) {
                    $userUpdates['phone'] = $data['phone'];
                }
                foreach (['gender', 'marital_status', 'nationality', 'residence', 'birth_date'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $userUpdates[$field] = $data[$field];
                    }
                }
                if (isset($data['is_active'])) {
                    $userUpdates['status'] = $data['is_active'] ? 'active' : 'inactive';
                }

                if (! empty($userUpdates)) {
                    $departmentManager->update($userUpdates);
                }

                $employee = $departmentManager->employee
                    ?? Employee::where('user_id', $departmentManager->id)->first();

                if (! $employee) {
                    throw new \RuntimeException('Associated employee record not found.');
                }

                $employeeUpdates = [];
                foreach (['education', 'job_title', 'base_salary', 'hire_date', 'employment_type', 'is_active'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $employeeUpdates[$field] = $data[$field];
                    }
                }

                if (array_key_exists('department_id', $data)) {
                    $newDepartment = Department::where('id', $data['department_id'])
                        ->where('company_id', $companyId)
                        ->firstOrFail();

                    $oldDepartmentId = $employee->department_id;
                    $employeeUpdates['department_id'] = $newDepartment->id;

                    if ($oldDepartmentId && $oldDepartmentId !== $newDepartment->id) {
                        Department::where('id', $oldDepartmentId)
                            ->where('manager_id', $employee->id)
                            ->update(['manager_id' => null]);
                    }

                    $newDepartment->update(['manager_id' => $employee->id]);
                }

                if (! empty($employeeUpdates)) {
                    $employee->update($employeeUpdates);
                }
            });

            $departmentManager->refresh();
            $departmentManager->load('employee.department');

            return response()->json([
                'success' => true,
                'message' => 'Department manager updated successfully.',
                'data' => $this->serializeDepartmentManager($departmentManager),
            ]);
        } catch (\Throwable $th) {
            Log::error('Department manager update failed', ['error' => $th->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to update department manager.',
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *   path="/api/hr/department-managers/{department_manager}/activate",
     *   summary="Activate a department manager account",
     *   tags={"Department Managers"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="department_manager",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Response(response=200, description="Activated successfully")
     * )
     */
    public function activate(User $departmentManager): JsonResponse
    {
        try {
            $departmentManager = $this->ensureBelongsToCurrentCompany($departmentManager);
            $departmentManager->update(['status' => 'active']);
            $departmentManager->employee()?->update(['is_active' => true]);

            return response()->json([
                'success' => true,
                'message' => 'Department manager activated successfully.',
            ]);
        } catch (\Throwable $th) {
            Log::error('Department manager activation failed', ['error' => $th->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to activate department manager.',
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *   path="/api/hr/department-managers/{department_manager}/deactivate",
     *   summary="Deactivate a department manager account",
     *   tags={"Department Managers"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="department_manager",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Response(response=200, description="Deactivated successfully")
     * )
     */
    public function deactivate(User $departmentManager): JsonResponse
    {
        try {
            $departmentManager = $this->ensureBelongsToCurrentCompany($departmentManager);
            $departmentManager->update(['status' => 'inactive']);
            $departmentManager->employee()?->update(['is_active' => false]);

            return response()->json([
                'success' => true,
                'message' => 'Department manager deactivated successfully.',
            ]);
        } catch (\Throwable $th) {
            Log::error('Department manager deactivation failed', ['error' => $th->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to deactivate department manager.',
            ], 500);
        }
    }

    /**
     * @OA\Delete(
     *   path="/api/hr/department-managers/{department_manager}",
     *   summary="Delete a department manager",
     *   tags={"Department Managers"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="department_manager",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Response(response=200, description="Deleted successfully"),
     *   @OA\Response(
     *     response=409,
     *     description="لا يمكن الحذف بسبب وجود سجلات مرتبطة - تم تجميد الحساب بدلاً من ذلك",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=false),
     *       @OA\Property(property="message", type="string", example="لا يمكن حذف الموظف لأنه يمتلك سجلات مرتبطة بالنظام. تم تجميد حسابه بدلاً من ذلك.")
     *     )
     *   )
     * )
     */
    public function destroy(User $departmentManager): JsonResponse
    {
        try {
            $departmentManager = $this->ensureBelongsToCurrentCompany($departmentManager);

            $employee = $departmentManager->employee
                ?? Employee::where('user_id', $departmentManager->id)->first();

            if ($employee && $this->employeeService->hasHistoricalRecords($employee)) {
                $this->employeeService->freezeEmployee($employee);

                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete this employee because they have related records. The account was frozen instead.',
                ], 409);
            }

            DB::transaction(function () use ($departmentManager) {
                $employee = $departmentManager->employee
                    ?? Employee::where('user_id', $departmentManager->id)->first();

                if ($employee) {
                    Department::where('manager_id', $employee->id)->update(['manager_id' => null]);
                    $employee->delete();
                }

                $departmentManager->delete();
            });

            return response()->json([
                'success' => true,
                'message' => 'Department manager deleted successfully.',
            ]);
        } catch (\Throwable $th) {
            Log::error('Department manager deletion failed', ['error' => $th->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to delete department manager.',
            ], 500);
        }
    }

    protected function ensureBelongsToCurrentCompany(User $departmentManager): User
    {
        $companyId = auth()->user()?->company_id;

        if (
            $departmentManager->company_id !== $companyId
            || $departmentManager->role !== Role::DepartmentManager->value
        ) {
            abort(404, 'Department manager not found.');
        }

        return $departmentManager;
    }

    protected function serializeDepartmentManager(User $manager): array
    {
        $manager->loadMissing('employee.department');

        return [
            'id' => $manager->id,
            'company_id' => $manager->company_id,
            'full_name' => $manager->full_name,
            'email' => $manager->email,
            'phone' => $manager->phone,
            'status' => $manager->status,
            'is_first_login' => $manager->is_first_login,
            'profile_completed' => $manager->profile_completed,
            'gender' => $manager->gender,
            'marital_status' => $manager->marital_status,
            'nationality' => $manager->nationality,
            'residence' => $manager->residence,
            'role' => $manager->role,
            'employee' => $manager->employee ? [
                'id' => $manager->employee->id,
                'department_id' => $manager->employee->department_id,
                'department_name' => $manager->employee->department?->name,
                'education' => $manager->employee->education,
                'job_title' => $manager->employee->job_title,
                'base_salary' => $manager->employee->base_salary,
                'hire_date' => $manager->employee->hire_date,
                'employment_type' => $manager->employee->employment_type,
                'is_active' => $manager->employee->is_active,
            ] : null,
        ];
    }
}
