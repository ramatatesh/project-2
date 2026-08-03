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
        ];
    }

    public function array(): array
    {
        return [
            [
                'Ahmad Ali',
                'ahmad@example.com',
                '+963999999999',
                'Human Resources',
                'Bachelor',
                'Software Engineer',
                1500,
                '2026-07-14',
                'full-time',
            ],
            ['', '', '', '', '', '', '', '', ''],
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:I1')->getFont()->setBold(true);
        $sheet->getStyle('A1:I1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDDDDD');

        $sheet->getColumnDimension('A')->setWidth(25);
        $sheet->getColumnDimension('B')->setWidth(32);
        $sheet->getColumnDimension('C')->setWidth(18);
        $sheet->getColumnDimension('D')->setWidth(22);
        $sheet->getColumnDimension('E')->setWidth(20);
        $sheet->getColumnDimension('F')->setWidth(22);
        $sheet->getColumnDimension('G')->setWidth(14);
        $sheet->getColumnDimension('H')->setWidth(14);
        $sheet->getColumnDimension('I')->setWidth(18);

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
                    'C1' => 'Phone number, optional.',
                    'D1' => 'Department name as registered in your company (required).',
                    'E1' => 'Education level, optional.',
                    'F1' => 'Job title (required).',
                    'G1' => 'Base salary as a number, e.g. 1500 (required).',
                    'H1' => 'Hire date as Y-m-d or d/m/Y, e.g. 2026-07-14 (required).',
                    'I1' => 'Employment type: full-time, part-time, contract or internship.',
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
            },
        ];
    }
}
