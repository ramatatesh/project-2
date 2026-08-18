// داخل App\Services\SalaryService

public function serializeSummary(SalaryRecord $record): array
{
    $currency = Company::find($record->company_id)?->payroll_currency;

    return [
        'id' => $record->id,
        'year' => (int) $record->year,
        'month' => (int) $record->month,
        'base_salary' => (float) $record->base_salary,
        'net_salary' => (float) $record->net_salary,
        'currency' => $currency, // <--- إضافة العملة هنا
        'status' => $record->status,
        // ... باقي الحقول
    ];
}

public function serializeDetails(SalaryRecord $record): array
{
    $currency = Company::find($record->company_id)?->payroll_currency;

    return [
        'id' => $record->id,
        'year' => (int) $record->year,
        'month' => (int) $record->month,
        'base_salary' => (float) $record->base_salary,
        'net_salary' => (float) $record->net_salary,
        'currency' => $currency, // <--- إضافة العملة هنا
        'status' => $record->status,
        // ... باقي الحقول و التفاصيل
    ];
}
