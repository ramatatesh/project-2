<?php

namespace App\Http\Controllers;

use App\Http\Requests\HrManagerRequest;
use App\Http\Requests\UpdateHrManagerRequest;
use App\Jobs\SendRegistrationEmailJob;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * @OA\Tag(
 *   name="HR Managers",
 *   description="Endpoints for managing HR manager accounts within a tenant company"
 * )
 */
class HrManagerController extends Controller
{
    /**
     * @OA\Get(
     *   path="/api/companies/{company}/hr-managers",
     *   summary="List HR managers for a company",
     *   tags={"HR Managers"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="company",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="List of HR managers",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *     )
     *   )
     * )
     */
    public function index(Company $company): JsonResponse
    {
        $hrManagers = $company->users()
            ->where('role', 'hr_manager')
            ->with('employee')
            ->get()
            ->map(fn (User $manager) => $this->serializeHrManager($manager));

        return response()->json([
            'success' => true,
            'data' => $hrManagers,
        ]);
    }

    /**
     * @OA\Post(
     *   path="/api/companies/{company}/hr-managers",
     *   summary="Create a new HR manager for a company",
     *   tags={"HR Managers"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="company",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"full_name","email","department_id","job_title","base_salary","hire_date"},
     *       @OA\Property(property="full_name", type="string", example="Sarah Ahmed"),
     *       @OA\Property(property="email", type="string", format="email", example="sarah@company.com"),
     *       @OA\Property(property="phone", type="string", example="+963999888777"),
     *       @OA\Property(property="employee_code", type="string", example="HRM-001"),
     *       @OA\Property(property="education", type="string", example="Bachelor of Business Administration"),
     *       @OA\Property(property="job_title", type="string", example="HR Manager"),
     *       @OA\Property(property="base_salary", type="number", format="float", example=1200.50),
     *       @OA\Property(property="hire_date", type="string", format="date", example="2026-07-01"),
     *       @OA\Property(property="employment_type", type="string", example="full-time"),
     *       @OA\Property(property="is_active", type="boolean", example=true)
     *     )
     *   ),
     *   @OA\Response(
     *     response=201,
     *     description="HR manager created successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="message", type="string", example="HR manager created successfully."),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="hr_manager", type="object")
     *       )
     *     )
     *   )
     * )
     */
    public function store(HrManagerRequest $request, Company $company): JsonResponse
    {
        try {
            $data = $request->validated();
            $tempPassword = Str::random(10);

            $hrDepartment = Department::where('company_id', $company->id)
                ->where('name', 'Human Resources')
                ->first();

           if (!$hrDepartment) {
                return response()->json([
               'success' => false,
               'message' => 'Human Resources department not found.', ], 500);
            }

            $hrManager = DB::transaction(function () use ($company, $data, $tempPassword,$hrDepartment) {
                $user = User::create([
                    'id' => Str::uuid()->toString(),
                    'company_id' => $company->id,
                    'full_name' => $data['full_name'],
                    'email' => $data['email'],
                    'password_hash' => Hash::make($tempPassword),
                    'role' => 'hr_manager',
                    'status' => 'active',
                    'is_first_login' => true,
                    'phone' => $data['phone'] ?? null,
                ]);

                $employee = Employee::create([
                    'id' => Str::uuid()->toString(),
                    'user_id' => $user->id,
                    'company_id' => $company->id,
                    'department_id' => $hrDepartment->id,
                    'employee_code' => $data['employee_code'] ?? null,
                    'education' => $data['education'] ?? null,
                    'job_title' => $data['job_title'],
                    'base_salary' => $data['base_salary'],
                    'hire_date' => $data['hire_date'],
                    'employment_type' => $data['employment_type'] ?? null,
                    'is_active' => $data['is_active'] ?? true,
                ]);
                if (!$hrDepartment->manager_id) {
                    $hrDepartment->update([ 'manager_id' => $employee->id,]);
                }

                $user->setRelation('employee', $employee);

                return $user;
            });

            SendRegistrationEmailJob::dispatch($hrManager->email, $tempPassword);

            return response()->json([
                'success' => true,
                'message' => 'HR manager created successfully.',
                'data' => [
                    'hr_manager' => $this->serializeHrManager($hrManager),
                ],
            ], 201);
        } catch (\Throwable $th) {
            Log::error('HR manager creation failed', ['error' => $th->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to create HR manager.',
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *   path="/api/companies/{company}/hr-managers/{hr_manager}",
     *   summary="Get HR manager details",
     *   tags={"HR Managers"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="company",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Parameter(
     *     name="hr_manager",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="HR manager details",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="object")
     *     )
     *   )
     * )
     */
    public function show(Company $company, User $hrManager): JsonResponse
    {
        $hrManager = $this->ensureHrManagerBelongsToCompany($company, $hrManager);
        $hrManager->load('employee');

        return response()->json([
            'success' => true,
            'data' => $this->serializeHrManager($hrManager),
        ]);
    }

    /**
     * @OA\Put(
     *   path="/api/companies/{company}/hr-managers/{hr_manager}",
     *   summary="Update an existing HR manager",
     *   tags={"HR Managers"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="company",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Parameter(
     *     name="hr_manager",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       @OA\Property(property="full_name", type="string", example="Sarah Ahmed"),
     *       @OA\Property(property="email", type="string", format="email", example="sarah@company.com"),
     *       @OA\Property(property="phone", type="string", example="+963999888777"),
     *       @OA\Property(property="department_id", type="string", format="uuid", example="123e4567-e89b-12d3-a456-426614174000"),
     *       @OA\Property(property="employee_code", type="string", example="HRM-001"),
     *       @OA\Property(property="education", type="string", example="Bachelor of Business Administration"),
     *       @OA\Property(property="job_title", type="string", example="HR Manager"),
     *       @OA\Property(property="base_salary", type="number", format="float", example=1200.50),
     *       @OA\Property(property="hire_date", type="string", format="date", example="2026-07-01"),
     *       @OA\Property(property="employment_type", type="string", example="full-time"),
     *       @OA\Property(property="is_active", type="boolean", example=true)
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="HR manager updated successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="message", type="string", example="HR manager updated successfully."),
     *       @OA\Property(property="data", type="object")
     *     )
     *   )
     * )
     */
    public function update(UpdateHrManagerRequest $request, Company $company, User $hrManager): JsonResponse
    {
        try {
            $hrManager = $this->ensureHrManagerBelongsToCompany($company, $hrManager);
            $data = $request->validated();

            DB::transaction(function () use ($hrManager, $data) {
                $userUpdates = [];
                $employeeUpdates = [];

                if (array_key_exists('full_name', $data)) {
                    $userUpdates['full_name'] = $data['full_name'];
                }
                if (array_key_exists('email', $data)) {
                    $userUpdates['email'] = $data['email'];
                }
                if (array_key_exists('phone', $data)) {
                    $userUpdates['phone'] = $data['phone'];
                }
                if (isset($data['is_active'])) {
                    $userUpdates['status'] = $data['is_active'] ? 'active' : 'inactive';
                }

                if (! empty($userUpdates)) {
                    $hrManager->update($userUpdates);
                }

                $employee = $hrManager->employee ?? Employee::where('user_id', $hrManager->id)->first();

                if (! $employee) {
                    throw new \RuntimeException('Associated employee record not found.');
                }

                foreach (['department_id', 'employee_code', 'education', 'job_title', 'base_salary', 'hire_date', 'employment_type', 'is_active'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $employeeUpdates[$field] = $data[$field];
                    }
                }

                if (! empty($employeeUpdates)) {
                    $employee->update($employeeUpdates);
                }
            });

            $hrManager->refresh();
            $hrManager->load('employee');

            return response()->json([
                'success' => true,
                'message' => 'HR manager updated successfully.',
                'data' => $this->serializeHrManager($hrManager),
            ]);
        } catch (\Throwable $th) {
            Log::error('HR manager update failed', ['error' => $th->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to update HR manager.',
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *   path="/api/companies/{company}/hr-managers/{hr_manager}/activate",
     *   summary="Activate an HR manager account",
     *   tags={"HR Managers"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="company",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Parameter(
     *     name="hr_manager",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="HR manager activated successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="message", type="string", example="HR manager activated successfully.")
     *     )
     *   )
     * )
     */
    public function activate(Company $company, User $hrManager): JsonResponse
    {
        try {
            $hrManager = $this->ensureHrManagerBelongsToCompany($company, $hrManager);
            $hrManager->update(['status' => 'active']);
            $hrManager->employee()->update(['is_active' => true]);

            return response()->json([
                'success' => true,
                'message' => 'HR manager activated successfully.',
            ]);
        } catch (\Throwable $th) {
            Log::error('HR manager activation failed', ['error' => $th->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to activate HR manager.',
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *   path="/api/companies/{company}/hr-managers/{hr_manager}/deactivate",
     *   summary="Deactivate an HR manager account",
     *   tags={"HR Managers"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="company",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Parameter(
     *     name="hr_manager",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="HR manager deactivated successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="message", type="string", example="HR manager deactivated successfully.")
     *     )
     *   )
     * )
     */
    public function deactivate(Company $company, User $hrManager): JsonResponse
    {
        try {
            $hrManager = $this->ensureHrManagerBelongsToCompany($company, $hrManager);
            $hrManager->update(['status' => 'inactive']);
            $hrManager->employee()->update(['is_active' => false]);

            return response()->json([
                'success' => true,
                'message' => 'HR manager deactivated successfully.',
            ]);
        } catch (\Throwable $th) {
            Log::error('HR manager deactivation failed', ['error' => $th->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to deactivate HR manager.',
            ], 500);
        }
    }

    /**
     * @OA\Delete(
     *   path="/api/companies/{company}/hr-managers/{hr_manager}",
     *   summary="Delete an HR manager",
     *   tags={"HR Managers"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(
     *     name="company",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Parameter(
     *     name="hr_manager",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", format="uuid")
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="HR manager deleted successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="message", type="string", example="HR manager deleted successfully.")
     *     )
     *   )
     * )
     */
    public function destroy(Company $company, User $hrManager): JsonResponse
    {
        try {
            $hrManager = $this->ensureHrManagerBelongsToCompany($company, $hrManager);

            DB::transaction(function () use ($hrManager) {
                $employee = $hrManager->employee ?? Employee::where('user_id', $hrManager->id)->first();
                if ($employee) {
                    $employee->delete();
                }
                $hrManager->delete();
            });

            return response()->json([
                'success' => true,
                'message' => 'HR manager deleted successfully.',
            ]);
        } catch (\Throwable $th) {
            Log::error('HR manager deletion failed', ['error' => $th->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to delete HR manager.',
            ], 500);
        }
    }

    protected function ensureHrManagerBelongsToCompany(Company $company, User $hrManager): User
    {
        if ($hrManager->company_id !== $company->id || $hrManager->role !== 'hr_manager') {
            abort(404, 'HR manager not found.');
        }

        return $hrManager;
    }

    protected function serializeHrManager(User $hrManager): array
    {
        $hrManager->loadMissing('employee');

        return [
            'id' => $hrManager->id,
            'company_id' => $hrManager->company_id,
            'full_name' => $hrManager->full_name,
            'email' => $hrManager->email,
            'phone' => $hrManager->phone,
            'status' => $hrManager->status,
            'is_first_login' => $hrManager->is_first_login,
            'role' => $hrManager->role,
            'employee' => $hrManager->employee ? [
                'id' => $hrManager->employee->id,
                'department_id' => $hrManager->employee->department_id,
                'employee_code' => $hrManager->employee->employee_code,
                'education' => $hrManager->employee->education,
                'job_title' => $hrManager->employee->job_title,
                'base_salary' => $hrManager->employee->base_salary,
                'hire_date' => $hrManager->employee->hire_date,
                'employment_type' => $hrManager->employee->employment_type,
                'is_active' => $hrManager->employee->is_active,
            ] : null,
        ];
    }
}
