<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Http\Response;
use Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class Controller extends BaseController
{
     /**
     * Check if the authenticated user has the required permission.
     *
     * @param string $permissionName
     * @return void
     */
    protected function checkPermission($permissionName)
    {
        $user = auth()->user();

         // Retrieve the role of the user (assuming the user has one role).
        $role = $user->role;  // assuming the user has a relationship called 'role'

       
        // If no role is assigned, deny access
        if (!$role) {
            return response()->json([
                'status' => 403,
                'message' => 'Permission Denied - No Role Assigned',
            ], 403);
        }

        if ($role->name === 'Superadmin' || $role === 'Superadmin') {
            return; // Allow access
        }

        // Retrieve the permissions for the role
        $permissions = $role->permissions;  // assuming the role has a 'permissions' relationship

        
        // Check if the permission is in the role's permissions
        $permissionExists = $permissions->contains('name', $permissionName);
        //dd($permissionExists);
        if (!$permissionExists) {
            return response()->json([
                'status' => 403,
                'message' => 'Permission Denied',
            ], 403);
        }
    }
}
