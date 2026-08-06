<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\StoreDepartmentRequest;
use App\Http\Requests\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @OA\Tag(
 *   name="Departments",
 *   description="إدارة أقسام الشركة الحالية (متاح فقط لمدير الموارد البشرية HR Manager)"
 * )
 */
class DepartmentController extends Controller
{
    /**
     * @OA\Get(
     *   path="/api/hr/departments",
     *   summary="عرض أقسام الشركة الحالية فقط",
     *   tags={"Departments"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(name="search", in="query", required=false, @OA\Schema(type="string")),
     *   @OA\Parameter(name="is_active", in="query", required=false, @OA\Schema(type="boolean")),
     *   @OA\Response(
     *     response=200,
     *     description="قائمة الأقسام",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *     )
     *   ),
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=403, description="Forbidden (HR Manager only)")
     * )
     */
    public function index(): JsonResponse
    {
        $companyId = $this->currentUserCompanyId();

        $query = Department::where('company_id', $companyId)
            ->withCount('employees');

        if ($search = request('search')) {
            $query->where('name', 'ilike', "%{$search}%");
        }

        if (request()->has('is_active')) {
            $query->where('is_active', filter_var(request('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $departments = $query->latest('created_at')->get();

        return response()->json([
            'success' => true,
            'data' => DepartmentResource::collection($departments),
        ]);
    }

    /**
     * @OA\Post(
     *   path="/api/hr/departments",
     *   summary="إضافة قسم جديد للشركة الحالية",
     *   tags={"Departments"},
     *   security={{"sanctum":{}}},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"name"},
     *       @OA\Property(property="name", type="string", example="IT"),
     *       @OA\Property(property="is_active", type="boolean", example=true),
     *       @OA\Property(property="manager_id", type="string", format="uuid", nullable=true, description="Must be an employee id belonging to the current company and not already the manager of another department. Setting it promotes that employee's account to Department Manager.")
     *     )
     *   ),
     *   @OA\Response(response=201, description="تم إنشاء القسم",
     *     @OA\JsonContent(@OA\Property(property="success", type="boolean"), @OA\Property(property="data", type="object"))
     *   ),
     *   @OA\Response(response=422, description="Validation failed (including manager_id belonging to another company, or already managing a different department)"),
     *   @OA\Response(response=403, description="Forbidden (HR Manager only), or the company is frozen (status=suspended) - message 'Company is frozen.'")
     * )
     */
    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $data = $request->validated();

        $department = DB::transaction(function () use ($data) {
            $department = Department::create([
                'id' => Str::uuid()->toString(),
                'company_id' => $this->currentUserCompanyId(),
                'name' => $data['name'],
                'is_active' => $data['is_active'] ?? true,
                'manager_id' => $data['manager_id'] ?? null,
            ]);

            if (! empty($data['manager_id'])) {
                $this->promoteToDepartmentManager($data['manager_id']);
            }

            return $department;
        });

        return response()->json([
            'success' => true,
            'message' => 'Department created successfully.',
            'data' => new DepartmentResource($department),
        ], 201);
    }

    /**
     * @OA\Get(
     *   path="/api/hr/departments/{department}",
     *   summary="عرض تفاصيل قسم تابع للشركة الحالية",
     *   tags={"Departments"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(name="department", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *   @OA\Response(response=200, description="تفاصيل القسم"),
     *   @OA\Response(response=404, description="Not found / not in your company")
     * )
     */
    public function show(Department $department): JsonResponse
    {
        $this->ensureBelongsToCurrentCompany($department);

        $department->loadCount('employees');

        return response()->json([
            'success' => true,
            'data' => new DepartmentResource($department),
        ]);
    }

    /**
     * @OA\Put(
     *   path="/api/hr/departments/{department}",
     *   summary="تعديل قسم تابع للشركة الحالية",
     *   tags={"Departments"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(name="department", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       @OA\Property(property="name", type="string", example="IT Department"),
     *       @OA\Property(property="is_active", type="boolean", example=true),
     *       @OA\Property(property="manager_id", type="string", format="uuid", nullable=true, description="Must be an employee who belongs to THIS department and is not already the manager of another department. Setting it promotes that employee's account to Department Manager; the previous manager (if different) is automatically demoted back to Employee (unless they still manage another department).")
     *     )
     *   ),
     *   @OA\Response(response=200, description="تم التعديل"),
     *   @OA\Response(response=404, description="Not found / not in your company"),
     *   @OA\Response(response=422, description="Validation failed (including manager_id not belonging to this department, or already managing a different department)"),
     *   @OA\Response(response=403, description="Company is frozen (status=suspended)")
     * )
     */
    public function update(UpdateDepartmentRequest $request, Department $department): JsonResponse
    {
        $this->ensureBelongsToCurrentCompany($department);

        $validated = $request->validated();

        DB::transaction(function () use ($department, $validated) {
            if (array_key_exists('manager_id', $validated)) {
                $oldManagerId = $department->manager_id;
                $newManagerId = $validated['manager_id'];

                if ($newManagerId !== $oldManagerId) {
                    if ($oldManagerId) {
                        $this->revertEmployeeRole($department, $oldManagerId);
                    }

                    if ($newManagerId) {
                        $this->promoteToDepartmentManager($newManagerId);
                    }
                }
            }

            $department->update($validated);
        });

        return response()->json([
            'success' => true,
            'message' => 'Department updated successfully.',
            'data' => new DepartmentResource($department),
        ]);
    }

    protected function promoteToDepartmentManager(string $employeeId): void
    {
        $employee = Employee::find($employeeId);

        if (! $employee || ! $employee->user) {
            return;
        }

        if ($employee->user->role === Role::Employee->value) {
            $employee->user->update(['role' => Role::DepartmentManager->value]);
        }
    }

    protected function revertEmployeeRole(Department $department, string $employeeId): void
    {
        $employee = Employee::find($employeeId);

        if (! $employee || ! $employee->user) {
            return;
        }

        $stillManagesAnotherDepartment = Department::where('company_id', $department->company_id)
            ->where('manager_id', $employeeId)
            ->where('id', '!=', $department->id)
            ->exists();

        if ($stillManagesAnotherDepartment) {
            return;
        }

        if ($employee->user->role === Role::DepartmentManager->value) {
            $employee->user->update(['role' => Role::Employee->value]);
        }
    }

    /**
     * @OA\Delete(
     *   path="/api/hr/departments/{department}",
     *   summary="حذف قسم (يُمنع الحذف إذا يحتوي موظفين)",
     *   tags={"Departments"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(name="department", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *   @OA\Response(response=200, description="تم الحذف"),
     *   @OA\Response(response=404, description="Not found / not in your company"),
     *   @OA\Response(response=409, description="Cannot delete: department has employees")
     * )
     */
    public function destroy(Department $department): JsonResponse
    {
        $this->ensureBelongsToCurrentCompany($department);

        if ($department->employees()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete this department because it still has employees. Please move or remove the employees first.',
            ], 409);
        }

        $department->delete();

        return response()->json([
            'success' => true,
            'message' => 'Department deleted successfully.',
        ]);
    }

    protected function currentUserCompanyId(): string
    {
        return (string) auth()->user()->company_id;
    }

    protected function ensureBelongsToCurrentCompany(Department $department): void
    {
        if ($department->company_id !== $this->currentUserCompanyId()) {
            abort(404, 'Department not found.');
        }
    }
}
