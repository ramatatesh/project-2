<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class EmployeeTemplateExport implements FromArray, WithEvents, WithHeadings, WithStyles, WithTitle
{
    public function title(): string
    {
        return 'Employees';
    }

    public function headings(): array
    {
        return [
            'full_name',
            'email',
            'phone',
            'department',
            'education',
            'job_title',
            'base_salary',
            'hire_date',
            'employment_type',
            'gender',
            'marital_status',
            'nationality',
            'residence',
            'birth_date',
        ];
    }

    public function array(): array
    {
        return [
            [
                'Ahmad Ali',
                'ahmad@example.com',
                '0999999999',
                'Human Resources',
                'Bachelor',
                'Software Engineer',
                1500,
                '2026-07-14',
                'full-time',
                'male',
                'single',
                'Syrian',
                'Damascus',
                '1995-05-20',
            ],
            ['', '', '', '', '', '', '', '', '', '', '', '', '', ''],
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:N1')->getFont()->setBold(true);
        $sheet->getStyle('A1:N1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDDDDD');

        $sheet->getColumnDimension('A')->setWidth(25);
        $sheet->getColumnDimension('B')->setWidth(32);
        $sheet->getColumnDimension('C')->setWidth(18);
        $sheet->getColumnDimension('D')->setWidth(22);
        $sheet->getColumnDimension('E')->setWidth(20);
        $sheet->getColumnDimension('F')->setWidth(22);
        $sheet->getColumnDimension('G')->setWidth(14);
        $sheet->getColumnDimension('H')->setWidth(14);
        $sheet->getColumnDimension('I')->setWidth(18);
        $sheet->getColumnDimension('J')->setWidth(14);
        $sheet->getColumnDimension('K')->setWidth(18);
        $sheet->getColumnDimension('L')->setWidth(18);
        $sheet->getColumnDimension('M')->setWidth(20);
        $sheet->getColumnDimension('N')->setWidth(16);

        $sheet->freezePane('A2');
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => static function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $comments = [
                    'A1' => 'Full name of the employee (required).',
                    'B1' => 'Unique email address (required).',
                    'C1' => 'Phone number, optional. Must start with 09 and be 10 digits.',
                    'D1' => 'Department name as registered in your company (required).',
                    'E1' => 'Education level, optional.',
                    'F1' => 'Job title (required).',
                    'G1' => 'Base salary as a number, e.g. 1500 (required).',
                    'H1' => 'Hire date as Y-m-d or d/m/Y, e.g. 2026-07-14 (required, cannot be in the future).',
                    'I1' => 'Employment type: full-time, part-time, contract or internship.',
                    'J1' => 'Gender, optional: male or female.',
                    'K1' => 'Marital status, optional: single, married, divorced or widowed.',
                    'L1' => 'Nationality, optional, free text.',
                    'M1' => 'Place of residence, optional, free text.',
                    'N1' => 'Date of birth as Y-m-d or d/m/Y, e.g. 1995-05-20, optional (cannot be in the future).',
                ];

                foreach ($comments as $cell => $text) {
                    $comment = $sheet->getComment($cell);
                    $comment->getText()->createTextRun($text);
                }

                $validation = new DataValidation;
                $validation->setType(DataValidation::TYPE_LIST);
                $validation->setErrorStyle(DataValidation::STYLE_INFORMATION);
                $validation->setAllowBlank(true);
                $validation->setShowInputMessage(true);
                $validation->setShowErrorMessage(true);
                $validation->setShowDropDown(true);
                $validation->setErrorTitle('Invalid employment type');
                $validation->setError('Please select a value from the list.');
                $validation->setPromptTitle('Employment type');
                $validation->setPrompt('Choose an employment type.');
                $validation->setFormula1('"full-time,part-time,contract,internship"');

                $sheet->setDataValidation('I2:I1000', $validation);

                $genderValidation = clone $validation;
                $genderValidation->setErrorTitle('Invalid gender');
                $genderValidation->setPromptTitle('Gender');
                $genderValidation->setPrompt('Choose a gender.');
                $genderValidation->setFormula1('"male,female"');
                $sheet->setDataValidation('J2:J1000', $genderValidation);

                $maritalValidation = clone $validation;
                $maritalValidation->setErrorTitle('Invalid marital status');
                $maritalValidation->setPromptTitle('Marital status');
                $maritalValidation->setPrompt('Choose a marital status.');
                $maritalValidation->setFormula1('"single,married,divorced,widowed"');
                $sheet->setDataValidation('K2:K1000', $maritalValidation);
            },
        ];
    }
}
