<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;

class EmployeeImport implements ToCollection
{
    public ?Collection $rows = null;

    public function collection(Collection $collection): void
    {
        $this->rows = $collection;
    }
}
