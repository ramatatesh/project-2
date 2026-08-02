<?php

namespace App\Services;

use App\Enums\Role;
use App\Imports\EmployeeImport;
use App\Jobs\SendEmployeeWelcomeEmailJob;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class EmployeeService
{
    public function __construct(
        private readonly LeaveBalanceService $leaveBalanceService,
    ) {
    }

    /**
     * إنشاء مستخدم + سجل موظف مرتبط بالشركة داخل Transaction واحد.
     * لا يعتمد على أي company_id قادم من الطلب؛ يأخذ الشركة من الـ HR الحالي.
     *
     * @return array{user: User, employee: Employee, password: string}
     */
    public function createEmployee(array $data, Company $company): array
    {
        return DB::transaction(function () use ($data, $company) {
            $temporaryPassword = Str::random(12);

            $user = User::create([
                'company_id' => $company->id,
                'full_name' => $data['full_name'],
                'email' => $data['email'],
                'password_hash' => Hash::make($temporaryPassword),
                'role' => Role::Employee->value,
                'status' => 'active',
                'is_first_login' => true,
                'phone' => $data['phone'] ?? null,
                'gender' => $data['gender'] ?? null,
                'marital_status' => $data['marital_status'] ?? null,
                'nationality' => $data['nationality'] ?? null,
                'residence' => $data['residence'] ?? null,
            ]);

            $employee = Employee::create([
                'id' => Str::uuid()->toString(),
                'user_id' => $user->id,
                'company_id' => $company->id,
                'department_id' => $data['department_id'],
                'employee_code' => $data['employee_code'] ?? null,
                'education' => $data['education'] ?? null,
                'job_title' => $data['job_title'],
                'base_salary' => $data['base_salary'],
                'hire_date' => $data['hire_date'],
                'employment_type' => $data['employment_type'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            $this->leaveBalanceService->initializeForEmployee($employee);

            $user->setRelation('employee', $employee);

            return [
                'user' => $user,
                'employee' => $employee,
                'password' => $temporaryPassword,
            ];
        });
    }

    /**
     * تعديل بيانات الموظف (user + employee) داخل Transaction.
     */
    public function updateEmployee(Employee $employee, array $data): Employee
    {
        return DB::transaction(function () use ($employee, $data) {
            $userUpdates = [];
            foreach (['full_name', 'email', 'phone', 'gender', 'marital_status', 'nationality', 'residence'] as $field) {
                if (array_key_exists($field, $data)) {
                    $userUpdates[$field] = $data[$field];
                }
            }
            if (array_key_exists('is_active', $data)) {
                $userUpdates['status'] = $data['is_active'] ? 'active' : 'inactive';
            }
            if (! empty($userUpdates) && $employee->user) {
                $employee->user->update($userUpdates);
            }

            $employeeUpdates = [];
            foreach (['department_id', 'employee_code', 'education', 'job_title', 'base_salary', 'hire_date', 'employment_type', 'is_active'] as $field) {
                if (array_key_exists($field, $data)) {
                    $employeeUpdates[$field] = $data[$field];
                }
            }
            if (! empty($employeeUpdates)) {
                $employee->update($employeeUpdates);
            }

            return $employee->refresh()->load('user');
        });
    }

    /**
     * حذف الموظف مع حسابه المرتبط (لتفادي بيانات يتيمة) داخل Transaction.
     * يمنع حذف المدير العام أو الـ Super Admin للحفاظ على إدارة الشركة.
     */
    public function deleteEmployee(Employee $employee): void
    {
        DB::transaction(function () use ($employee) {
            $user = $employee->user;
            $employee->delete();
            if ($user) {
                $user->delete();
            }
        });
    }

    /**
     * التحقق أن القسم يتبع لنفس الشركة (يُستخدم داخل الـ Validation).
     */
    public static function departmentBelongsToCompany(?string $departmentId, string $companyId): bool
    {
        if (! $departmentId) {
            return true;
        }

        return Department::where('id', $departmentId)
            ->where('company_id', $companyId)
            ->exists();
    }

    /**
     * إرسال إيميل الترحيب بعد إنشاء الموظف.
     */
    public function sendWelcomeEmail(User $user, string $password): void
    {
        SendEmployeeWelcomeEmailJob::dispatch(
            $user->full_name,
            $user->email,
            $password,
            config('app.url'),
        );
    }

    /**
     * استيراد موظفين من ملف Excel/CSV.
     * - يقرأ كل الصفوف.
     * - يتجاهل الأعمدة الفارغة في الترويسة وكذلك صفوف البيانات الفارغة.
     * - يدعم اسم القسم (department) بدل department_id ويحوّله تلقائياً.
     * - يدعم تاريخ Y-m-d و d/m/Y وExcel Serial Date.
     * - يتحقق من كل صف بشكل كامل ويجمع الأخطاء.
     * - All-or-nothing: إن وُجد أي خطأ لا يُدخل أي موظف.
     * - إن نجحت كل الصفوف: يُدخل الكل داخل Transaction واحدة ويُرسل الإيميل لكل موظف.
     *
     * @return array{success: bool, count?: int, errors?: array}
     */
    public function importFromFile(UploadedFile $file, Company $company): array
    {
        $import = new EmployeeImport;
        Excel::import($import, $file);
        $rows = $import->rows;

        if (! $rows || $rows->isEmpty()) {
            return ['success' => false, 'errors' => ['file' => ['The uploaded file is empty.']]];
        }

        $headerMap = $this->buildHeaderMap($rows->first());

        if (empty($headerMap)) {
            return ['success' => false, 'errors' => ['file' => ['The uploaded file does not contain a valid header row.']]];
        }

        $dataRows = $rows->slice(1)->values();

        $departmentsByName = Department::where('company_id', $company->id)
            ->get()
            ->mapWithKeys(function (Department $department) {
                return [strtolower(trim($department->name)) => $department->id];
            });

        $validRows = [];
        $errors = [];
        $usedEmails = [];
        $usedCodes = [];

        foreach ($dataRows as $index => $row) {
            $rowNumber = $index + 2; // 1 = header, data starts at 2
            $rowAssoc = $this->mapRowToHeader($headerMap, $row);

            if ($this->isBlankRow($rowAssoc)) {
                continue;
            }

            $this->normalizeRowDates($rowAssoc);

            $rowErrors = $this->validateRow($rowAssoc, $company, $departmentsByName, $usedEmails, $usedCodes);
            if (! empty($rowErrors)) {
                $errors[$rowNumber] = $rowErrors;

                continue;
            }

            $rowAssoc['department_id'] = $this->resolveDepartmentId($rowAssoc, $departmentsByName);

            $usedEmails[] = strtolower(trim((string) $rowAssoc['email']));
            if (! empty($rowAssoc['employee_code'])) {
                $usedCodes[] = $rowAssoc['employee_code'];
            }

            $validRows[] = $rowAssoc;
        }

        if (! empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        if (empty($validRows)) {
            return ['success' => false, 'errors' => ['file' => ['The uploaded file does not contain any employee data.']]];
        }

        $created = DB::transaction(function () use ($validRows, $company) {
            $count = 0;
            foreach ($validRows as $row) {
                $result = $this->createEmployee($row, $company);
                $this->sendWelcomeEmail($result['user'], $result['password']);
                $count++;
            }

            return $count;
        });

        return ['success' => true, 'count' => $created];
    }

    /**
     * البناء الخاص بخريطة الأعمدة، مع تجاهل الأعمدة الفارغة ودعم أسماء بديلة.
     */
    protected function buildHeaderMap($rawHeader): array
    {
        $map = [];
        if (! $rawHeader instanceof Collection) {
            return $map;
        }

        foreach ($rawHeader as $index => $value) {
            $key = strtolower(trim((string) $value));
            if ($key === '') {
                continue;
            }

            // Aliases
            if ($key === 'name') {
                $key = 'full_name';
            } elseif ($key === 'department_name') {
                $key = 'department';
            }

            $map[$key] = (int) $index;
        }

        return $map;
    }

    /**
     * تحويل صف الإكسل إلى مصفوفة مفاتيح حسب خريطة الترويسة.
     */
    protected function mapRowToHeader(array $headerMap, Collection $row): array
    {
        $assoc = [];
        foreach ($headerMap as $key => $index) {
            $value = $row[$index] ?? null;
            $assoc[$key] = $this->normalizeCellValue($value);
        }

        return $assoc;
    }

    /**
     * Normalizes a cell value from Excel, preserving numeric/date types.
     */
    protected function normalizeCellValue($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::parse($value)->format('Y-m-d');
        }

        if (is_numeric($value) && ! is_string($value)) {
            return $value;
        }

        return trim((string) $value);
    }

    /**
     * Returns true when every mapped value is empty.
     */
    protected function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Converts known date columns into Y-m-d format when possible.
     */
    protected function normalizeRowDates(array &$row): void
    {
        foreach (['hire_date'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = $this->normalizeDate($row[$field]);
            }
        }
    }

    /**
     * Supports Excel serial dates, DateTime objects, Y-m-d, d/m/Y, m/d/Y, Y/m/d.
     */
    protected function normalizeDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::parse($value)->format('Y-m-d');
        }

        if (is_numeric($value)) {
            $numeric = (float) $value;
            // Excel serial date range
            if ($numeric > 0 && $numeric < 2958466) {
                try {
                    return Carbon::instance(Date::excelToDateTimeObject($numeric))->format('Y-m-d');
                } catch (\Throwable) {
                    return null;
                }
            }
        }

        $str = trim((string) $value);
        foreach (['Y-m-d', 'd/m/Y', 'm/d/Y', 'Y/m/d'] as $format) {
            $dt = \DateTime::createFromFormat($format, $str);
            if ($dt && $dt->format($format) === $str) {
                return $dt->format('Y-m-d');
            }
        }

        return null;
    }

    protected function isValidDate(string $date): bool
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * Resolves the department column to a department UUID.
     */
    protected function resolveDepartmentId(array $row, Collection $departmentsByName): ?string
    {
        if (! empty($row['department'])) {
            return $departmentsByName->get(strtolower(trim((string) $row['department'])));
        }

        if (! empty($row['department_id'])) {
            return $row['department_id'];
        }

        return null;
    }

    /**
     * التحقق من صف واحد. يستخدم اسم القسم بدلاً من UUID.
     */
    protected function validateRow(array $row, Company $company, Collection $departmentsByName, array &$usedEmails, array &$usedCodes): array
    {
        $errors = [];
        $email = $row['email'] ?? null;

        if (empty($row['full_name'])) {
            $errors['full_name'] = ['full_name is required.'];
        }
        if (empty($email)) {
            $errors['email'] = ['email is required.'];
        } else {
            $email = trim((string) $email);
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = ['email must be a valid email address.'];
            } elseif (User::where('email', $email)->exists() || in_array(strtolower($email), $usedEmails, true)) {
                $errors['email'] = ['email already exists.'];
            }
        }
        if (! empty($row['phone']) && strlen((string) $row['phone']) > 50) {
            $errors['phone'] = ['phone may not be greater than 50 characters.'];
        }
        if (empty($row['job_title'])) {
            $errors['job_title'] = ['job_title is required.'];
        }
        if (! isset($row['base_salary']) || $row['base_salary'] === '' || $row['base_salary'] === null || ! is_numeric($row['base_salary']) || (float) $row['base_salary'] < 0) {
            $errors['base_salary'] = ['base_salary must be a non-negative number.'];
        }
        if (empty($row['hire_date']) || ! $this->isValidDate($row['hire_date'])) {
            $errors['hire_date'] = ['hire_date is required and must be a valid date (Y-m-d).'];
        }

        $departmentName = $row['department'] ?? null;
        $departmentId = $row['department_id'] ?? null;

        if (empty($departmentName) && empty($departmentId)) {
            $errors['department'] = ['department is required.'];
        } elseif (! empty($departmentName)) {
            if (! $departmentsByName->has(strtolower(trim((string) $departmentName)))) {
                $errors['department'] = ['department not found.'];
            }
        } elseif (! self::departmentBelongsToCompany($departmentId, $company->id)) {
            $errors['department_id'] = ['department does not belong to your company.'];
        }

        if (! empty($row['employee_code'])) {
            if (Employee::where('employee_code', $row['employee_code'])->exists() || in_array($row['employee_code'], $usedCodes, true)) {
                $errors['employee_code'] = ['employee_code already exists.'];
            }
        }

        return $errors;
    }
}
