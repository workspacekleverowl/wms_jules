<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class MultiSheetExport implements WithMultipleSheets
{
    private $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function sheets(): array
    {
        $sheets = [];
        foreach ($this->data as $sheetName => $sheetData) {
            $sheets[] = new DataSheet($sheetName, $sheetData['data'], $sheetData['headings']);
        }
        return $sheets;
    }
}
