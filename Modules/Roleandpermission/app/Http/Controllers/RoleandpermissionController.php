<?php

namespace Modules\Roleandpermission\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Support\Facades\Validator;

class RoleandpermissionController extends Controller
{
    public function viewRoles(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $roles = Role::where('tenant_id', $tenantId)->get();
 
        return response()->json([
            'status' => 200,
            'message' => 'Roles retrieved successfully',
            'record' => $roles
        ], 200);
    }
 
    public function viewPermissions(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $permissions = Permission::where('tenant_id', $tenantId)->get();
 
        return response()->json([
            'status' => 200,
            'message' => 'Permissions retrieved successfully',
            'record' => $permissions
        ], 200);
    }
 
   
   
 
    public function assignPermissions(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'permissions' => 'required|array'
        ]);
 
        if ($validator->fails()) {
            return response()->json([
                'status' => 404,
                'message' => 'Validation failed',
                'error' => $validator->errors(),
                'record' => null
            ], 404);
        }
 
        try {
            $tenantId = $request->user()->tenant_id;
            $role = Role::where('name', 'staff')
            ->where('tenant_id', $tenantId)
            ->firstOrFail();
            $permissions = Permission::whereIn('id', $request->permissions)
                ->where('tenant_id', $tenantId)
                ->get();
 
            if ($permissions->isEmpty()) {
                return response()->json([
                    'status' => 404,
                    'message' => 'No valid permissions found',
                    'record' => null
                ], 404);
            }
 
            $role->syncPermissions($permissions);
 
            return response()->json([
                'status' => 200,
                'message' => 'Permissions assigned successfully',
                'record' => $role->load('permissions')
            ], 200);
 
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 404,
                'message' => 'Role not found',
                'record' => null
            ], 404);
        }
    }
 
    public function revokePermissions(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'permissions' => 'required|array'
        ]);
 
        if ($validator->fails()) {
            return response()->json([
                'status' => 404,
                'message' => 'Validation failed',
                'error' => $validator->errors(),
                'record' => null
            ], 404);
        }
 
        try {
            $tenantId = $request->user()->tenant_id;
            $role = Role::where('name', 'staff')
                ->where('tenant_id', $tenantId)
                ->firstOrFail();
            $permissions = Permission::whereIn('id', $request->permissions)
                ->where('tenant_id', $tenantId)
                ->get();
 
            if ($permissions->isEmpty()) {
                return response()->json([
                    'status' => 404,
                    'message' => 'No valid permissions found',
                    'record' => null
                ], 404);
            }
 
            foreach ($permissions as $permission) {
                $role->revokePermissionTo($permission);
            }
 
            return response()->json([
                'status' => 200,
                'message' => 'Permissions revoked successfully',
                'record' => $role->load('permissions')
            ], 200);
 
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 404,
                'message' => 'Role not found',
                'record' => null
            ], 404);
        }
    }

    public function checkRolePermissions(Request $request)
    {
        // Step 1: Get the authenticated user's tenant_id
        $tenantId = $request->user()->tenant_id;

        // Step 2: Retrieve the 'staff' role for the tenant
        $role = Role::where('name', 'staff')
                ->where('tenant_id', $tenantId)
                ->first();

        // Step 3: Check if the role exists
        if (!$role) {
            return response()->json([
                'status' => 404,
                'message' => 'Role "staff" not found for this tenant',
            ], 404);
        }

        // Step 4: Retrieve all permissions for the tenant
        $allPermissions = Permission::where('tenant_id', $tenantId)->get();

        // Step 5: Get assigned permissions for the "staff" role
        $assignedPermissions = $role->permissions->pluck('id')->toArray();

        // Step 6: Prepare response with assigned status
        $permissionsWithStatus = $allPermissions->map(function ($permission) use ($assignedPermissions) {
            return [
                'id' => $permission->id,
                'name' => $permission->name,
                'category' => $permission->category,
                'assigned' => in_array($permission->id, $assignedPermissions), // True if assigned
            ];
        });

        // Step 7: Return the permissions with assigned status
        return response()->json([
            'status' => 200,
            'message' => 'Permissions retrieved successfully',
            'permissions' => $permissionsWithStatus,
        ], 200);
    }
}
