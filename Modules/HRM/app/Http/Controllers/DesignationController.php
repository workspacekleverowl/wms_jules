<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Models\Designation;

class DesignationController extends ApiController
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        // Assuming you have a permission check helper
        // $permissionResponse = $this->checkPermission('HRM-Designation-Show');
        // if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        try {
            $perPage = $request->input('per_page', 10);
            $search = $request->input('search');

            $query = Designation::with('department')
                ->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId);

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                      ->orWhereHas('department', function ($deptQuery) use ($search) {
                          $deptQuery->where('name', 'like', "%{$search}%");
                      });
                });
            }

            $designations = $query->latest()->paginate($perPage);

            return $this->paginatedResponse($designations, 'Designations retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve designations: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        // $permissionResponse = $this->checkPermission('HRM-Designation-Insert');
        // if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        $validator = Validator::make($request->all(), [
            'title' => [
                'required',
                'string',
                'max:255',
                // Unique rule scoped to the department, tenant, and company
                'unique:designations,title,NULL,id,department_id,' . $request->department_id . ',tenant_id,' . $tenantId . ',company_id,' . $activeCompanyId
            ],
            'department_id' => [
                'required',
                'integer',
                // Ensure the department exists for the current tenant and company
                'exists:departments,id,tenant_id,' . $tenantId . ',company_id,' . $activeCompanyId
            ],
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->all(), 422);
        }

        DB::beginTransaction();
        try {
            $data = [
                'title'         => $request->title,
                'description'   => $request->description,
                'department_id' => $request->department_id,
                'tenant_id'     => $tenantId,
                'company_id'    => $activeCompanyId,
            ];

            $designation = Designation::create($data);
            DB::commit();

            return $this->show($request, $designation->id);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Failed to create designation: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, $id): JsonResponse
    {
        // $permissionResponse = $this->checkPermission('HRM-Designation-Show');
        // if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        try {
            $designation = Designation::with('department')
                ->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($id);

            if (!$designation) {
                return $this->errorResponse('Designation not found', 404);
            }

            return $this->successResponse($designation, 'Designation retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve designation: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id): JsonResponse
    {
        // $permissionResponse = $this->checkPermission('HRM-Designation-Update');
        // if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();
        
        DB::beginTransaction();
        try {
            $designation = Designation::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($id);

            if (!$designation) {
                return $this->errorResponse('Designation not found', 404);
            }
            
            // Use the department from the request if provided, otherwise the existing one
            $departmentId = $request->input('department_id', $designation->department_id);

            $validator = Validator::make($request->all(), [
                 'title' => [
                    'sometimes',
                    'required',
                    'string',
                    'max:255',
                    'unique:designations,title,' . $id . ',id,department_id,' . $departmentId . ',tenant_id,' . $tenantId . ',company_id,' . $activeCompanyId
                ],
                'department_id' => [
                    'sometimes',
                    'required',
                    'integer',
                    'exists:departments,id,tenant_id,' . $tenantId . ',company_id,' . $activeCompanyId
                ],
                'description' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return $this->errorResponse($validator->errors()->all(), 422);
            }
            
            $designation->update($validator->validated());
            DB::commit();

            return $this->show($request, $id);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Failed to update designation: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        // $permissionResponse = $this->checkPermission('HRM-Designation-Delete');
        // if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        try {
            $designation = Designation::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($id);

            if (!$designation) {
                return $this->errorResponse('Designation not found', 404);
            }

            if ($designation->employees()->exists()) {
                return $this->errorResponse(
                   'Cannot delete. This designation has associated employees.',
                   409 // Conflict
                );
            }

            $designation->delete();

            return $this->successResponse(null, 'Designation deleted successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to delete designation: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Fetch a list of designations for dropdowns, optionally filtered by department.
     */
    public function fetch(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        try {
            $query = Designation::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId);

            // Add optional filtering by department_id
            if ($request->has('department_id')) {
                $query->where('department_id', $request->department_id);
            }

            $designations = $query->select('id', 'title')->orderBy('title')->get();
            
            return $this->successResponse($designations, 'Designations list fetched successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to fetch designations: ' . $e->getMessage(), 500);
        }
    }
}