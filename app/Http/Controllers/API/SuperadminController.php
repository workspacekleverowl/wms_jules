<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Models\Permission;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\API\TenantController;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use App\Models\company;


class SuperadminController extends Controller
{
  /**
     * Create a Superadmin user.
     */
    public function createSuperadmin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|unique:users,email',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Check if the "Superadmin" role exists, or create it
            $role = Role::firstOrCreate(['name' => 'Superadmin'], ['guard_name' => 'web']);

            $superuser = User::where('role_id',$role->id)->first();

            if ($superuser) {
                return response()->json([
                    'status' => 403,
                    'message' => 'Super Admin already exist',
                ], 403);
            }  

            // Create the Superadmin user
            $user = User::create([
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role_id' => $role->id,
            ]);

            // Assign the "Superadmin" role to the user
            $user->assignRole($role);

            return response()->json([
                'status' => 200,
                'message' => 'Superadmin created successfully',
                'record' => $user->load('roles'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Error creating Superadmin',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Edit a Superadmin profile.
     */
    public function editSuperadmin(Request $request, $superadminId)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'sometimes|required|string|email|unique:users,email,' . $superadminId,
            'password' => 'sometimes|required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {

            $role = Role::where('name', 'Superadmin')->first();

            if (!$role) {
                return response()->json([
                    'status' => 404,
                    'message' => 'No role details found',
                ], 404);
            }  

            $user = User::where('role_id',$role->id)->find($superadminId);

            if (!$user) {
                return response()->json([
                    'status' => 404,
                    'message' => 'No user details found',
                ], 404);
            }  

           
         

            if ($request->has('email')) {
                $user->email = $request->email;
            }

            if ($request->has('password')) {
                $user->password = Hash::make($request->password);
            }

            $user->save();

            return response()->json([
                'status' => 200,
                'message' => 'Superadmin updated successfully',
                'record' => $user,
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 404,
                'message' => 'Superadmin not found',
            ], 404);
        }
    }

    /**
     * Show a Superadmin profile.
     */
    public function showSuperadmin()
    {
        try {
            $role = Role::where('name', 'Superadmin')->first();

            if (!$role) {
                return response()->json([
                    'status' => 404,
                    'message' => 'No role details found',
                ], 404);
            }  

            $user = User::where('role_id',$role->id)->first();

            if (!$user) {
                return response()->json([
                    'status' => 404,
                    'message' => 'No user details found',
                ], 404);
            }  
            return response()->json([
                'status' => 200,
                'message' => 'Superadmin retrieved successfully',
                'record' => $user,
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 404,
                'message' => 'Superadmin not found',
            ], 404);
        }
    }

    /**
     * Delete a Superadmin profile.
     */
    public function deleteSuperadmin()
    {
        try {
            $role = Role::where('name', 'Superadmin')->first();

            if (!$role) {
                return response()->json([
                    'status' => 404,
                    'message' => 'No role details found',
                ], 404);
            }  

            $user = User::where('role_id',$role->id)->first();

            if (!$user) {
                return response()->json([
                    'status' => 404,
                    'message' => 'No user details found',
                ], 404);
            }  

            $user->delete();

            return response()->json([
                'status' => 200,
                'message' => 'Superadmin deleted successfully',
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 404,
                'message' => 'Superadmin not found',
            ], 404);
        }
    }

    /**
     * Show all tenants and the admin users assigned to them.
     */
    public function showTenantsAndAdmins(Request $request)
    {
        try {
            // Get perPage and page parameters with defaults
            $perPage = $request->get('perPage', 10); // Default to 10 items per page
            $page = $request->get('page', 1);

            // Fetch tenants with admins and staff
            $tenants = Tenant::with(['users' => function ($query) {
                $query->whereHas('roles', function ($query) {
                    $query->whereIn('name', ['Admin', 'Staff']); // Check for 'Admin' or 'Staff' role
                })->with('meta', 'roles');
            }])
            ->paginate($perPage, ['*'], 'page', $page);

            // Map tenants with their respective admins and staff
            $tenantData = $tenants->getCollection()->map(function ($tenant) {
                // Separate admins and staff
                $admins = $tenant->users->filter(function ($user) {
                    return $user->roles->contains('name', 'Admin');
                });

                $staff = $tenant->users->filter(function ($user) {
                    return $user->roles->contains('name', 'Staff');
                });

                return [
                    'tenant_id' => $tenant->id,
                    'tenant_name' => $tenant->name,
                    'subscription_status' => $tenant->subscription_status,
                    'admins' => $admins->map(function ($user) {
                        return [
                            'id' => $user->id,
                            'email' => $user->email,
                            'first_name' => $user->meta->first_name ?? null,
                            'last_name' => $user->meta->last_name ?? null,
                            'company_name' => $user->meta->company_name ?? null,
                            'address1' => $user->meta->address1 ?? null,
                            'address2' => $user->meta->address2 ?? null,
                            'city' => $user->meta->city ?? null,
                            'state' => $user->meta->state->title ?? null,
                            'pincode' => $user->meta->pincode ?? null,
                            'gst_number' => $user->meta->gst_number ?? null,
                        ];
                    })->values(),
                    'staff' => $staff->map(function ($user) {
                        return [
                            'id' => $user->id,
                            'email' => $user->email,
                            'first_name' => $user->meta->first_name ?? null,
                            'last_name' => $user->meta->last_name ?? null,
                            'company_name' => $user->meta->company_name ?? null,
                            'address1' => $user->meta->address1 ?? null,
                            'address2' => $user->meta->address2 ?? null,
                            'city' => $user->meta->city ?? null,
                            'state' => $user->meta->state->title ?? null,
                            'pincode' => $user->meta->pincode ?? null,
                            'gst_number' => $user->meta->gst_number ?? null,
                        ];
                    })->values(),
                ];
            });

            // Pagination object
            $pagination = [
                'total_records' => $tenants->total(),
                'per_page' => $tenants->perPage(),
                'current_page' => $tenants->currentPage(),
                'last_page' => $tenants->lastPage(),
            ];

            return response()->json([
                'status' => 200,
                'message' => 'Tenants with their assigned admins and staff retrieved successfully',
                'pagination' => $pagination,
                'record' => $tenantData,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Error retrieving tenants, admins, and staff',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function login(Request $request)
    {
        // Validate request
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'error' => $validator->errors(),
                'record' => null,
            ], 422);
        }

        $credentials = $request->only('email', 'password');

        if (Auth::attempt($credentials)) {
            $user = Auth::user();

            $user->makeHidden(['permissions']);


            // Generate a new token
            $token = $user->createToken('API Token')->plainTextToken;


            return response()->json([
                'status' => 200,
                'message' => 'Login successful',
                'record' => [
                    'user' => $user,
                    'token' =>  $token,
                ],
            ], 200);
        }

        return response()->json([
            'status' => 401,
            'message' => 'Invalid credentials',
            'record' => null,
        ], 401);
    }

 

    public function loginasuser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'error' => $validator->errors(),
                'record' => null,
            ], 422);
        }
    
        $superadmin = $request->user();
        $superadminrole = Role::where('name', 'Superadmin')->first();
    
        if (!$superadminrole || $superadmin->role_id !== $superadminrole->id) {
            return response()->json([
                'status' => 403,
                'message' => 'Unauthorized. User does not have Superadmin privileges.',
                'record' => null,
            ], 403);
        }
    
        $user = User::findOrFail($request->user_id);
    
        // Log the user in using the 'web' guard to avoid the error
        auth('web')->login($user);
    
        // Generate a Sanctum token
        $token = $user->createToken('API Token')->plainTextToken;
    
        $hasMeta = $user->meta()->exists();
        $tenant = $user->tenant;
        $subscriptionActive = $tenant && $tenant->subscription_status === 'active';
    
        $permissionsByCategory = Permission::where('tenant_id', $tenant->id)
            ->get()
            ->groupBy('category')
            ->mapWithKeys(function ($permissions, $category) use ($user) {
                $rolePermissionIds = DB::table('role_has_permissions')
                    ->whereIn('role_id', $user->roles->pluck('id'))
                    ->pluck('permission_id')
                    ->toArray();
    
                return [
                    $category => $permissions->pluck('name', 'id')->mapWithKeys(function ($name, $id) use ($rolePermissionIds) {
                        return [$name => in_array($id, $rolePermissionIds)];
                    }),
                ];
            });
    
        $user->makeHidden(['permissions']);
    
        return response()->json([
            'status' => 200,
            'message' => 'Login successful',
            'record' => [
                'user' => $user->load('meta'),
                'token' => $token,
                'profile' => $hasMeta,
                'subscription' => $subscriptionActive,
                'permissions' => $permissionsByCategory,
            ],
        ]);
    }
    


    
}
