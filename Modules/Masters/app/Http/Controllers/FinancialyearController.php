<?php

namespace Modules\Masters\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FinancialYear;

class FinancialyearController extends Controller
{
    public function getAllFinancialYears()
    {
        // Fetch all financial years ordered by priority or start_date
        $financialYears = FinancialYear::orderBy('priority', 'asc')->get();

        // Format the financial years for better readability if needed
        $formattedFinancialYears = $financialYears->map(function ($year) {
            return [
                'id' => $year->id,
                'year' => $year->year,
                'slug' => $year->slug,
                'priority' => $year->priority,
                
            ];
        });

        return response()->json($formattedFinancialYears);
    }
}
