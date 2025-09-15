<?php

namespace Modules\Vendor\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use App\Models\usersettings;
use App\Models\company;

class StaffController extends Controller
{
   /**
     * Create a new staff user for the authenticated tenant.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createStaff(Request $request)
    {
        $response = $this->checkPermission('Staff-Insert');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }
        // Validate the request data
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|unique:users,email',
            'password' => 'required|string|min:8',
            'phone' => [
                'sometimes',
                'nullable',
                'string',
                'max:10',
                'min:10',
                'regex:/^\d{10}$/'
            ],
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'address1' => 'nullable|string|max:255',
            'address2' => 'sometimes|nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state_id' => 'nullable|integer|exists:states,id',
            'pincode' => 'nullable|string|max:10'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 200,
                'message' => 'Validation failed',
                'error' => $validator->errors(),
                'record' => null
            ], 200);
        }

        // Get the authenticated user's tenant_id
        $tenantId = $request->user()->tenant_id;
        $adminuser = $request->user();

        try {
            // Find the 'staff' role for the tenant
            $role = Role::where('name', 'Staff')
                        ->where('tenant_id', $tenantId)
                        ->firstOrFail();

                       
            

            // Create the new staff user
            $user = User::create([
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'phone' => $request->phone??null,
                'tenant_id' => $tenantId, // Ensure the user is scoped to the correct tenant
                'role_id' => $role->id,
            ]);

            // Assign the 'staff' role to the new user
            $user->assignRole($role);

            $settingsArray = [
                'scrap_inhouse_cr' => 'no',
                'scrap_inhouse_mr' => 'no',
                'scrap_inhouse_bh' => 'no',
                'scrap_outsourcing_cr' => 'no',
                'scrap_outsourcing_mr' => 'no',
                'scrap_outsourcing_bh' => 'no',
                'include_values_in_scrap_return'=> 'no',
                'jobwork_inhouse_cr' => 'no',
                'jobwork_inhouse_mr' => 'no',
                'jobwork_inhouse_bh' => 'no',
                'jobwork_outsourcing_cr' => 'no',
                'jobwork_outsourcing_mr' => 'no',
                'jobwork_outsourcing_bh' => 'no',
                'jobwork_show_gst' => 'no',
                'include_jobwork_rate_in_report' => 'no',
                'transaction_time'    => 'yes',
                'voucher_include_rate'=> 'yes',
                'voucher_include_gst'=> 'yes',
                'voucher_include_hsn_sac'=> 'yes',
                'voucher_auto_voucher_number'=> 'yes',
                'voucher_include_item_wt'=> 'yes',
                'voucher_include_item_status'=> 'yes',
                'voucher_include_prefix'=> 'yes',
                'voucher_include_financial_year'=> 'yes',
                'voucher_prefix' => null
            ];
            
            $settings = [];
            
            foreach ($settingsArray as $slug => $val) {
                $settings[] = [
                    'user_id'   => $user->id,
                    'tenant_id' => $tenantId,
                    'slug'      => $slug,
                    'val'       => $val,
                ];
            }
            
            usersettings::insert($settings);

            $company = company::where('tenant_id', $tenantId)->first();

            // Update or create user_meta
            $metaData = $request->only([
                'first_name',
                'last_name',
                'address1',
                'address2',
                'city',
                'state_id',
                'pincode'
            ]);

            $metaData['active_company_id'] = $company->id;
            $metaData['company_name'] = $adminuser->meta->company_name;
            $metaData['gst_number'] =$adminuser->meta->gst_number;

            $user->meta()->updateOrCreate(
                ['user_id' => $user->id],
                $metaData
            );


            return response()->json([
                'status' => 200,
                'message' => 'Staff user created successfully',
                'record' => $user
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Error creating staff user',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all staff users for the authenticated tenant.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getStaff(Request $request)
    {
        $response = $this->checkPermission('Staff-Show');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }
        // Get the authenticated user's tenant_id
        $tenantId = $request->user()->tenant_id;
    
        // Validate request inputs for pagination
        $validator = Validator::make($request->all(), [
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'search' => 'sometimes|string|max:255',
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'error' => $validator->errors(),
            ], 422);
        }
    
        // Get pagination parameters or default values
        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 10);
    
        try {
            // Query staff users for the tenant with the 'staff' role
            $query = User::with('meta')->where('tenant_id', $tenantId)
                ->whereHas('roles', function ($query) {
                    $query->where('name', 'Staff');
                });

            // Apply search filter
             if ($request->has('search') && !empty($request->search)) {
                $searchTerm = $request->search;
                $query->where(function ($q) use ($searchTerm) {
                    // Search in users table
                    $q->where('phone', 'like', '%' . $searchTerm . '%')
                      ->orWhere('email', 'like', '%' . $searchTerm . '%')
                    // Search in meta relationship
                    ->orWhereHas('meta', function ($metaQuery) use ($searchTerm) {
                        $metaQuery->where('first_name', 'like', '%' . $searchTerm . '%')
                                ->orWhere('last_name', 'like', '%' . $searchTerm . '%')
                                ->orWhere('company_name', 'like', '%' . $searchTerm . '%')
                                ->orWhere('address1', 'like', '%' . $searchTerm . '%')
                                ->orWhere('address2', 'like', '%' . $searchTerm . '%')
                                ->orWhere('city', 'like', '%' . $searchTerm . '%')
                                ->orWhere('pincode', 'like', '%' . $searchTerm . '%')
                                ->orWhere('gst_number', 'like', '%' . $searchTerm . '%');
                    });
                });
            }
        
    
            // Get total records count
            $totalRecords = $query->count();
    
            // Apply pagination
            $staff = $query->skip(($page - 1) * $perPage)
                ->take($perPage)
                ->get();
    
            if ($staff->isEmpty()) {
                return response()->json([
                    'status' => 200,
                    'message' => 'No staff details found',
                    'pagination' => [
                        'page' => $page,
                        'per_page' => $perPage,
                        'total_records' => $totalRecords,
                    ],
                    'record' => [],
                ], 200);
            }
    
            return response()->json([
                'status' => 200,
                'message' => 'Staff users retrieved successfully',
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total_records' => $totalRecords,
                ],
                'record' => $staff,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Error retrieving staff users',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    /**
     * Update a staff user.
     *
     * @param  Request  $request
     * @param  int  $staffId
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateStaff(Request $request, $staffId)
    {
        $response = $this->checkPermission('Staff-Update');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }
        // Validate the request data
        $validator = Validator::make($request->all(), [
            'email' => 'sometimes|required|string|email|unique:users,email,' . $staffId,
            'password' => 'sometimes|nullable|string|min:8',
            'phone' => [
                'sometimes',
                'nullable',
                'string',
                'max:10',
                'min:10',
                'regex:/^\d{10}$/'
            ],
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'address1' => 'nullable|string|max:255',
            'address2' => 'sometimes|nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state_id' => 'nullable|integer|exists:states,id',
            'pincode' => 'nullable|string|max:10'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 200,
                'message' => 'Validation failed',
                'error' => $validator->errors(),
                'record' => null
            ], 200);
        }

        // Get the authenticated user's tenant_id
        $tenantId = $request->user()->tenant_id;

        try {
            // Find the staff user by ID and check tenant scope
            $user = User::where('id', $staffId)
                        ->where('tenant_id', $tenantId)
                        ->first();

            if (!$user) {
                return response()->json([
                    'status' => 200,
                    'message' => 'No staff details found',
                ], 200);
            }                

           

            if ($request->has('email')) {
                $user->email = $request->email;
            }

            if ($request->has('password')) {
                $user->password = Hash::make($request->password);
            }

            if ($request->has('phone')) {
                $user->phone = $request->phone;
            }

            $user->save();

             // Update or create user_meta
            $metaData = $request->only([
                'first_name',
                'last_name',
                'address1',
                'address2',
                'city',
                'state_id',
                'pincode'
            ]);

            // Get the company for this tenant (similar to createStaff)
            $company = company::where('tenant_id', $tenantId)->first();
            
            if ($company) {
                $metaData['active_company_id'] = $company->id;
            }

            // Update or create the user meta data
            $user->meta()->updateOrCreate(
                ['user_id' => $user->id],
                $metaData
            );

             $user->load('meta');

            return response()->json([
                'status' => 200,
                'message' => 'Staff user updated successfully',
                'record' => $user
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Error updating staff user',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a staff user.
     *
     * @param  Request  $request
     * @param  int  $staffId
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteStaff(Request $request, $staffId)
    {
        $response = $this->checkPermission('Staff-Delete');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }
        // Get the authenticated user's tenant_id
        $tenantId = $request->user()->tenant_id;

        try {
            // Find the staff user by ID and check tenant scope
            $user = User::where('id', $staffId)
                        ->where('tenant_id', $tenantId)
                        ->first();

            if (!$user) {
                return response()->json([
                    'status' => 200,
                    'message' => 'No staff details found',
                ], 200);
            }             
            // Delete the staff user
            $user->delete();

            return response()->json([
                'status' => 200,
                'message' => 'Staff user deleted successfully',
            ], 200);
        } catch (\Exception $e) {

            if ($e instanceof \Illuminate\Database\QueryException) {
                // Check for foreign key constraint error codes
                if ($e->getCode() == 23000 || strpos($e->getMessage(), 'Integrity constraint violation') !== false || strpos($e->getMessage(), 'foreign key constraint fails') !== false) {
                    return response()->json([
                        'status' => 409,
                        'message' => 'Cannot delete this record because there are linked records associated with it. Please remove all related data first.',
                    ], 200);
                }
            }
            return response()->json([
                'status' => 500,
                'message' => 'Error deleting staff user',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

     /**
     * Update a staff user.
     *
     * @param  Request  $request
     * @param  int  $staffId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getsingleStaff(Request $request, $staffId)
    {
        $response = $this->checkPermission('Staff-Show');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }
    
        // Get the authenticated user's tenant_id
        $tenantId = $request->user()->tenant_id;

        try {
            // Find the staff user by ID and check tenant scope
            $user = User::with('meta')->where('id', $staffId)
                        ->where('tenant_id', $tenantId)
                        ->first();

            if (!$user) {
                return response()->json([
                    'status' => 200,
                    'message' => 'No staff details found',
                ], 200);
            }                

            

            return response()->json([
                'status' => 200,
                'message' => 'Staff user retrieved successfully',
                'record' => $user
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Staff user retrieved successfully',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
