<?php

namespace Modules\Vendor\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\UserMeta;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\API\TenantController;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use App\Models\company;
use App\Models\usersettings;
use Carbon\Carbon;

class VendorController extends Controller
{
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
            $token = $user->createToken('API Token', ['*'], Carbon::now()->addDays(10))->plainTextToken;

               // Check if the user has meta data
            $hasMeta = $user->meta()->exists();

            // Check tenant's subscription status
            $tenant = $user->tenant;
            //dd($tenant);
            $subscriptionActive = $tenant && $tenant->subscription_status === "active";

            $permissionsByCategory = Permission::where('tenant_id', $tenant->id)
            ->get()
            ->groupBy('category')
            ->mapWithKeys(function ($permissions, $category) use ($user) {
                // Get all permission IDs associated with the user's roles
                $rolePermissionIds = \DB::table('role_has_permissions')
                    ->whereIn('role_id', $user->roles->pluck('id'))
                    ->pluck('permission_id')
                    ->toArray();
        
                return [
                    $category => $permissions->pluck('name', 'id')->mapWithKeys(function ($name, $id) use ($rolePermissionIds) {
                        // Check if the permission ID exists in the user's role permissions
                        $hasPermission = in_array($id, $rolePermissionIds);
                        return [$name => $hasPermission];
                    }),
                ];
            });


            return response()->json([
                'status' => 200,
                'message' => 'Login successful',
                'record' => [
                    'user' => $user->load('meta'),
                    'token' =>  $token,
                    'profile' => $hasMeta, // true if meta exists, false otherwise
                    'subscription' => $subscriptionActive, // true if subscription is active, false otherwise
                    'permissions' => $permissionsByCategory,
                ],
            ], 200);
        }

        return response()->json([
            'status' => 401,
            'message' => 'Invalid credentials',
            'record' => null,
        ], 401);
    }

    /**
     * Logout function
     */
    public function logout(Request $request)
    {

       

        if ($request->user()) {
            //$request->user()->tokens()->delete();
            $request->user()->currentAccessToken()->delete();
            return response()->json([
                'status' => 200,
                'message' => 'Logout successful',
                'record' => null,
            ], 200);
        }

        return response()->json([
            'status' => 200,
            'message' => 'User not found',
            'record' => null,
        ], 200);
    }

    /**
     * Register function
     */
    public function register(Request $request)
    {
        // Validate request
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'error' => $validator->errors(),
                'record' => null,
            ], 422);
        }

        $user = \App\Models\User::create([
            'email' => $request->email,
            'password' => bcrypt($request->password),
        ]);

        $token = $user->createToken('auth_token', ['*'], Carbon::now()->addDays(10))->plainTextToken;

        $tenantController = new TenantController();
        $tenantRequest = new Request([
            'name' => $request->name,
            'email' => $request->email,
        ]);

        $tenantRequest->setUserResolver(function () use ($user) {
            return $user;
        });

        $tenantResponse = $tenantController->createTenant($tenantRequest);

        $tenantData = json_decode($tenantResponse->getContent(), true);

        if ($tenantResponse->status() !== 200) {
            return response()->json([
                'status' => 500,
                'message' => 'User registered but tenant creation failed',
                'error' => $tenantData['error'] ?? 'Unknown error',
                'record' => null,
            ], 500);
        }


        return response()->json([
            'status' => 200,
            'message' => 'User registered and tenant created successfully',
            'user' => $user,
            'tenant' => $tenantData['tenant'],
            'company' => $tenantData['company'],
            'item_categories' => $tenantData['item_categories'],
            'permissions'=>$tenantData['permissions'],
            'token' => $token,
            'profile' => false,
            'subscription' => false
        ], 200);
    
    }

    public function updateProfile(Request $request)
    {
        // Define validation rules
        $validator = Validator::make($request->all(), [
            // Users table validations
           'phone' => [
                'sometimes',
                'required',
                'string',
                'max:10',
                'min:10',
                'regex:/^\d{10}$/',
                Rule::unique('users', 'phone')->ignore($request->user()->id),
            ],
            'password' => 'sometimes|nullable|string|min:8|confirmed', // expects password_confirmation field

            // User_meta table validations
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'company_name' => 'required|string|max:255',
            'address1' => 'required|string|max:255',
            'address2' => 'sometimes|nullable|string|max:255',
            'city' => 'required|string|max:255',
            'state_id' => 'required|integer|exists:states,id',
            'pincode' => 'required|string|max:10',
            'gst_number' => 'required|string|max:15',
        ]);

        // Check validation
        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'error' => $validator->errors(),
                'record' => null,
            ], 422);
        }

        try {
            // Begin transaction
            DB::beginTransaction();

            // Get the authenticated user
            $user = $request->user();

            $tId = $request->user()->tenant_id;
            $company = company::where('tenant_id', $tId)->first();

            // Update users table
            $userData = $request->only(['phone']);
            if ($request->filled('password')) {
                $userData['password'] = Hash::make($request->password);
            }

            $user->update($userData);

            // Update or create user_meta
            $metaData = $request->only([
                'first_name',
                'last_name',
                'company_name',
                'address1',
                'address2',
                'city',
                'state_id',
                'pincode',
                'gst_number',
            ]);

            $metaData['active_company_id'] = $company->id;

           // dd($metaData);

            $user->meta()->updateOrCreate(
                ['user_id' => $user->id],
                $metaData
            );

             // Update company_name and gst_number for all users in the same tenant
            $tenantWideUpdates = [];
            
            if ($request->has('company_name')) {
                $tenantWideUpdates['company_name'] = $request->company_name;
            }
            
            if ($request->has('gst_number')) {
                $tenantWideUpdates['gst_number'] = $request->gst_number;
            }

            // If there are tenant-wide updates to perform
            if (!empty($tenantWideUpdates)) {
                // Get all user IDs for the current tenant
                $tenantUserIds = User::where('tenant_id', $tId)->pluck('id');

               
                
                // Update user_meta for all users in the tenant
                UserMeta::whereIn('user_id', $tenantUserIds)
                        ->update($tenantWideUpdates);
            }

            // Commit transaction
            DB::commit();

            // Reload user with meta
            $user->load('meta');

            return response()->json([
                'status' => 200,
                'message' => 'Profile updated successfully',
                'record' => $user,
            ], 200);

        } catch (\Exception $e) {
            // Rollback transaction on error
            DB::rollBack();

            return response()->json([
                'status' => 500,
                'message' => 'Error updating profile',
                'error' => $e->getMessage(),
                'record' => null,
            ], 500);
        }
    }

    /**
     * Change user password
     */
    public function changePassword(Request $request)
    {
        // Validate request
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|different:current_password',
            'confirm_password' => 'required|string|same:new_password',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'error' => $validator->errors(),
                'record' => null,
            ], 422);
        }

        try {
            $user = $request->user();

            // Check if current password matches
            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'status' => 401,
                    'message' => 'Current password is incorrect',
                    'record' => null,
                ], 401);
            }

            // Update password
            $user->update([
                'password' => Hash::make($request->new_password)
            ]);

            // Optionally logout from other devices
            $user->tokens()->where('id', '!=', $request->user()->currentAccessToken()->id)->delete();

            return response()->json([
                'status' => 200,
                'message' => 'Password changed successfully',
                'record' => null,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Error changing password',
                'error' => $e->getMessage(),
                'record' => null,
            ], 500);
        }
    }

    public function changecmpstate(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        $validator = Validator::make($request->all(), [
            'company_id' => [
                'required',
                'integer',
                function ($attribute, $value, $fail) use ($tenantId) {
                    $exists = \DB::table('companies')
                        ->where('id', $value)
                        ->where('tenant_id', $tenantId)
                        ->exists();
 
                    if (!$exists) {
                        $fail('The selected company does not exist or does not belong to you.');
                    }
                },
            ],
        ]);
 
        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Begin transaction
            DB::beginTransaction();

            $company = company::find($request->input('company_id'));
           
            $userMeta = $user->meta();

            if ($userMeta) 
            {
                $userMeta->update(['active_company_id' => $company->id]);
            }

            // Commit transaction
            DB::commit();

            // Reload user with meta
            $user->load('meta');

            return response()->json([
                'status' => 200,
                'message' => 'Active company updated successfully',
                'record' => $user,
            ], 200);

        } catch (\Exception $e) {
            // Rollback transaction on error
            DB::rollBack();

            return response()->json([
                'status' => 500,
                'message' => 'Error updating profile',
                'error' => $e->getMessage(),
                'record' => null,
            ], 500);
        }
    }

    public function getprofile(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();
       

            $user->load('meta');

            return response()->json([
                'status' => 200,
                'message' => 'User Details',
                'record' => $user,
                'activeCompanyId' => $activeCompanyId,
            ], 200);

       
    }

    public function getprofilesettings(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();
       

        $userSettings = $user->usersettings->pluck('val', 'slug');

        return response()->json([
            'status' => 200,
            'usersettings' => $userSettings,
        ]);

       
    }

    public function updateProfileSettings(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $userId = $user->id;

        $inputSettings = $request->input('usersettings', []);
        if (empty($inputSettings)) {
            return response()->json([
                'status' => 422,
                'message' => 'No settings provided for update.'
            ], 200);
        }

        // Check if user is Staff and trying to update restricted settings
        if (!$request->user()->hasRole('Admin')) {
            $restrictedSettings = ['voucher_include_prefix', 'voucher_prefix', 'voucher_include_financial_year'];
            foreach ($restrictedSettings as $restrictedSetting) {
                if (array_key_exists($restrictedSetting, $inputSettings)) {
                    return response()->json([
                        'status' => 403,
                        'message' => "Staff role does not have permission to update '{$restrictedSetting}'."
                    ], 200);
                }
            }
        }

        // Business logic: If voucher_include_rate is "no", set voucher_include_gst to "no"
        if (isset($inputSettings['voucher_include_rate']) && $inputSettings['voucher_include_rate'] === 'no') {
            $inputSettings['voucher_include_gst'] = 'no';
        }

        // Business logic: If voucher_include_prefix is "no", set voucher_prefix to null and voucher_include_financial_year to "no"
        if (isset($inputSettings['voucher_include_prefix']) && $inputSettings['voucher_include_prefix'] === 'no') {
            $inputSettings['voucher_prefix'] = null;
            $inputSettings['voucher_include_financial_year'] = 'no';
        }

        // Get all existing slugs for the user
        $existingSlugs = usersettings::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->pluck('id', 'slug'); // pluck(id, slug) to get setting ID if needed

        foreach ($inputSettings as $slug => $val) {
            if (!isset($existingSlugs[$slug])) {
                return response()->json([
                    'status' => 404,
                    'message' => "Setting key '{$slug}' does not exist."
                ], 200);
            }

            usersettings::where('id', $existingSlugs[$slug])->update(['val' => $val]);
        }

        // If Admin is updating voucher settings, inherit to all Staff users in the tenant
        if ($request->user()->hasRole('Admin')) {
            $voucherSettings = ['voucher_include_prefix', 'voucher_prefix', 'voucher_include_financial_year'];
            $settingsToInherit = array_intersect_key($inputSettings, array_flip($voucherSettings));
            
            if (!empty($settingsToInherit)) {
                // Get all Staff users in the tenant (excluding the current Admin user)
                $staffUsers = User::where('tenant_id', $tenantId)
                    ->where('id', '!=', $userId)
                    ->whereHas('roles', function ($query) {
                        $query->where('name', 'Staff');
                    })
                    ->pluck('id');

                // Update settings for each Staff user
                foreach ($staffUsers as $staffUserId) {
                    foreach ($settingsToInherit as $slug => $val) {
                        usersettings::where('tenant_id', $tenantId)
                            ->where('user_id', $staffUserId)
                            ->where('slug', $slug)
                            ->update(['val' => $val]);
                    }
                }
            }
        }

        // Return updated settings
        $updatedSettings = usersettings::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->pluck('val', 'slug');

        return response()->json([
            'status' => 200,
            'message' => 'Profile settings updated successfully.',
            'usersettings' => $updatedSettings,
        ]);
    }

    public function getprofilecmp(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();
        $company= company::where('id', $activeCompanyId)->first();
       


            return response()->json([
                'status' => 200,
                'message' => 'User active company id',
                'activeCompanyId' => $activeCompanyId,
                'activeCompanyname' => $company->company_name,
            ], 200);

       
    }

}
