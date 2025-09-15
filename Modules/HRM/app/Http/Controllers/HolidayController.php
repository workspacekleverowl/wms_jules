<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Models\Holiday;

class HolidayController extends ApiController
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        // $permissionResponse = $this->checkPermission('HRM-Holiday-Show');
        // if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        try {
            $perPage = $request->input('per_page', 10);
            $search = $request->input('search');
            $year = $request->input('year', date('Y')); // Default to the current year

            $query = Holiday::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId);

            // Filter by year
            if ($year) {
                $query->whereYear('date', $year);
            }
            
            // Filter by search term
            if ($search) {
                $query->where('name', 'like', "%{$search}%");
            }

            $holidays = $query->orderBy('date', 'asc')->paginate($perPage);

            return $this->paginatedResponse($holidays, 'Holidays retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve holidays: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        // $permissionResponse = $this->checkPermission('HRM-Holiday-Insert');
        // if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'date' => [
                'required',
                'date',
                // Date must be unique for the given tenant and company
                'unique:holidays,date,NULL,id,tenant_id,' . $tenantId . ',company_id,' . $activeCompanyId
            ],
            'is_paid' => 'required|boolean',
            'payable_hours' => 'nullable|numeric|min:0|max:24',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->all(), 422);
        }

        DB::beginTransaction();
        try {
            $validatedData = $validator->validated();
            $validatedData['tenant_id'] = $tenantId;
            $validatedData['company_id'] = $activeCompanyId;
            
            $holiday = Holiday::create($validatedData);
            DB::commit();

            return $this->show($request, $holiday->id);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Failed to create holiday: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, $id): JsonResponse
    {
        // $permissionResponse = $this->checkPermission('HRM-Holiday-Show');
        // if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        try {
            $holiday = Holiday::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($id);

            if (!$holiday) {
                return $this->errorResponse('Holiday not found', 404);
            }

            return $this->successResponse($holiday, 'Holiday retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve holiday: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id): JsonResponse
    {
        // $permissionResponse = $this->checkPermission('HRM-Holiday-Update');
        // if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        DB::beginTransaction();
        try {
            $holiday = Holiday::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($id);

            if (!$holiday) {
                return $this->errorResponse('Holiday not found', 404);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:255',
                'date' => [
                    'sometimes',
                    'required',
                    'date',
                    // Unique rule ignoring the current record ID
                    'unique:holidays,date,' . $id . ',id,tenant_id,' . $tenantId . ',company_id,' . $activeCompanyId
                ],
                'is_paid' => 'sometimes|boolean',
                'payable_hours' => 'nullable|numeric|min:0|max:24',
            ]);
            
            if ($validator->fails()) {
                return $this->errorResponse($validator->errors()->all(), 422);
            }

            $holiday->update($validator->validated());
            DB::commit();

            return $this->show($request, $id);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Failed to update holiday: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        // $permissionResponse = $this->checkPermission('HRM-Holiday-Delete');
        // if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        try {
            $holiday = Holiday::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($id);

            if (!$holiday) {
                return $this->errorResponse('Holiday not found', 404);
            }

            $holiday->delete();

            return $this->successResponse(null, 'Holiday deleted successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to delete holiday: ' . $e->getMessage(), 500);
        }
    }
}