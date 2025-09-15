<?php

namespace Modules\Masters\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\State;


class MastersController extends Controller
{
    public function viewstates(Request $request)
    {
        $query = State::query();

        // Check if a search term is provided
        if ($request->has('search') && !empty($request->search)) {
            $searchTerm = $request->search;
            $query->where('title', 'like', '%' . $searchTerm . '%');
        }

        $states = $query->get();

        return response()->json([
            'status' => 200,
            'message' => 'States retrieved successfully',
            'record' => $states
        ], 200);
    }
}
