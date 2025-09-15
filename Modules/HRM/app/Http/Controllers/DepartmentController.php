<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\ApiController;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class DepartmentController extends ApiController
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        // Assuming you have a permission check helper
        // $permissionResponse = $this->checkPermission('HRM-Department-Show');
        // if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId(); // Assumes this method exists on User model

        try {
            $perPage = $request->input('per_page', 10);
            $search = $request->input('search');

            $query = Department::where('tenant_id', $tenantId)->where('company_id', $activeCompanyId);

            if ($search) {
                $query->where('name', 'like', "%{$search}%");
            }

            $departments = $query->latest()->paginate($perPage);

            return $this->paginatedResponse($departments, 'Departments retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve departments: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        // $permissionResponse = $this->checkPermission('HRM-Department-Insert');
        // if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                'max:255',
                // Unique rule scoped to the current tenant and company
                'unique:departments,name,NULL,id,tenant_id,' . $tenantId . ',company_id,' . $activeCompanyId
            ],
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->all(), 422);
        }

        DB::beginTransaction();
        try {
            $data = [
                'name' => $request->name,
                'description' => $request->description,
                'tenant_id' => $tenantId,
                'company_id' => $activeCompanyId,
            ];

            $department = Department::create($data);
            DB::commit();

            return $this->show($request, $department->id);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Failed to create department: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, $id): JsonResponse
    {
        // $permissionResponse = $this->checkPermission('HRM-Department-Show');
        // if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        try {
            $department = Department::with('designations') ->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($id);

            if (!$department) {
                return $this->errorResponse('Department not found', 404);
            }

            return $this->successResponse($department, 'Department retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve department: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id): JsonResponse
    {
        // $permissionResponse = $this->checkPermission('HRM-Department-Update');
        // if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        DB::beginTransaction();
        try {
            $department = Department::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($id);

            if (!$department) {
                return $this->errorResponse('Department not found', 404);
            }

            $validator = Validator::make($request->all(), [
                'name' => [
                    'sometimes', // Use 'sometimes' for updates
                    'required',
                    'string',
                    'max:255',
                     // Unique rule ignoring the current record
                    'unique:departments,name,' . $id . ',id,tenant_id,' . $tenantId . ',company_id,' . $activeCompanyId
                ],
                'description' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return $this->errorResponse($validator->errors()->all(), 422);
            }

            $department->update($validator->validated());
            DB::commit();

            return $this->show($request, $id);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Failed to update department: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        // $permissionResponse = $this->checkPermission('HRM-Department-Delete');
        // if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        try {
            $department = Department::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($id);

            if (!$department) {
                return $this->errorResponse('Department not found', 404);
            }

            // Prevent deletion if related records exist
            if ($department->employees()->exists() || $department->designations()->exists()) {
                 return $this->errorResponse(
                    'Cannot delete. This department has associated employees or designations.',
                    409 // Conflict status code
                 );
            }

            $department->delete();

            return $this->successResponse(null, 'Department deleted successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to delete department: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Fetch a list of departments for dropdowns.
     */
    public function fetch(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        try {
            $departments = Department::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->select('id', 'name')
                ->orderBy('name')
                ->get();
            
            return $this->successResponse($departments, 'Departments list fetched successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to fetch departments: ' . $e->getMessage(), 500);
        }
    }
}
