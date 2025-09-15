<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Tenant;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Models\company;
use App\Models\usersettings;
use App\Models\Itemcategory;

class TenantController extends Controller
{
    public function createTenant(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                function ($attribute, $value, $fail) {
                    // Check if the email exists in the data JSON column of the tenants table
                    $exists = DB::table('tenants')
                        ->whereJsonContains('data->email', $value)
                        ->exists();
    
                    if ($exists) {
                        $fail('The email has already been taken.');
                    }
                },
            ],
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'error' => $validator->errors()
            ], 400);
        }
    
        DB::beginTransaction();

        try 
        {

            // Step 2: Create Tenant
            $tenant = Tenant::create([
                'name' => $request->name,
                'email' => $request->email,
                'subscription_status' => 'inactive', // Set the subscription status to active by default
            ]);

            // Step 3: Create Permissions for the Tenant
            $tenantId = $tenant->id; // Use tenant_id for scoping permissions

            $menuPermissions = [
                'Dashboard-Menu',
                'Masters-Menu',
                'Masters-Category-Menu',
                'Masters-Items-Menu',
                'Masters-Party-Menu',
                'Masters-Company-Menu',
                'Masters-Transporter-Menu',
                'Masters-Staff-Menu',
                'Access-Control-Menu',
                'Reports-Menu',
                'Stock-Reports-Menu',
                'Sales-Job-Work-Reports-Menu',
                'Purchase-Job-Work-Reports-Menu',
                'Scrap-Return-Reports-Menu',
                'Scrap-Receivable-Reports-Menu',
                'Item-Reports-Menu',
                'Attendance-Reports-Menu',
                'In-House-Vouchers-Menu',
                'Sub-Contract-Vouchers-Menu',
                'Scrap-Return-Menu',
                'Employee-Menu',
                'Tools-Menu',
                'Subscriptions-Menu',
                'View-Plans-Menu',
                'Manage-Plans-Menu',
                'Payment-Transactions-Menu',
                'Settings-Menu',
                'Book-Keeping-Menu',
                'Book-Keeping-Dashboard-Menu',
                'Book-Keeping-Purchase-Menu',
                'Book-Keeping-Purchase-Transactions-Menu',
                'Book-Keeping-Purchase-Order-Menu',
                'Book-Keeping-Purchase-Return-Menu',
                'Book-Keeping-Sales-Menu',
                'Book-Keeping-Sales-Transactions-Menu',
                'Book-Keeping-Sales-Return-Menu',
                'Book-Keeping-Expense-Menu',
                'Book-Keeping-Expense-Type-Menu',
                'Book-Keeping-Expense-Transactions-Menu',
                'Book-Keeping-Payment-Reports-Menu',
                'Book-Keeping-Supplier-Payment-Menu',
                'Book-Keeping-Customer-Payment-Menu',
                'Book-Keeping-Customer-Menu',
                'Book-Keeping-Supplier-Menu'  
            ];
        
            $otherPermissions = [
                'Dashboard-Dashboard','Dashboard-Stocks',
                'Category-Insert', 'Category-Update', 'Category-Delete', 'Category-Show',
                'Item-Insert', 'Item-Update', 'Item-Delete','Item-Restore', 'Item-Show',
                'Party-Insert', 'Party-Update', 'Party-Delete','Party-Restore', 'Party-Show',
                'Company-Insert', 'Company-Update', 'Company-Delete', 'Company-Show',
                'Transporter-Insert', 'Transporter-Update', 'Transporter-Delete', 'Transporter-Show',
                'Staff-Insert', 'Staff-Update', 'Staff-Delete', 'Staff-Show',
                'Stock-Report-Download','Sales-Job-Work-Reports-Download','Purchase-Job-Work-Reports-Download',
                'Scrap-Return-Reports-Download','Scrap-Receivable-Reports-Download','Item-Reports-Download',
                'Voucher-Insert', 'Voucher-Update', 'Voucher-Delete', 'Voucher-Show',
                'Employee-Insert', 'Employee-Update', 'Employee-Delete', 'Employee-Show',
                'Scrap-Transaction-Insert', 'Scrap-Transaction-Update', 'Scrap-Transaction-Delete', 'Scrap-Transaction-Show',
                'Invoice-Generator-Insert','Invoice-Generator-Update','Invoice-Generator-Delete','Invoice-Generator-Show',
                'Quotation-Generator-Insert','Quotation-Generator-Update','Quotation-Generator-Delete','Quotation-Generator-Show',
                'Predispatch-Inspection-Insert','Predispatch-Inspection-Update','Predispatch-Inspection-Delete','Predispatch-Inspection-Show',
                'Item-Insert', 'Item-Update', 'Item-Delete','Item-Restore', 'Item-Show',
                'Book-Keeping-Purchase-Transactions-Insert','Book-Keeping-Purchase-Transactions-Update','Book-Keeping-Purchase-Transactions-Show','Book-Keeping-Purchase-Transactions-Delete','Book-Keeping-Purchase-Transactions-Return','Book-Keeping-Purchase-Transactions-Make-Payment',
                'Book-Keeping-Purchase-Return-Update','Book-Keeping-Purchase-Return-Show','Book-Keeping-Purchase-Return-Delete',
                'Book-Keeping-Purchase-Order-Insert','Book-Keeping-Purchase-Order-Update','Book-Keeping-Purchase-Order-Show','Book-Keeping-Purchase-Order-Delete',
                'Book-Keeping-Sales-Transactions-Insert','Book-Keeping-Sales-Transactions-Update','Book-Keeping-Sales-Transactions-Show','Book-Keeping-Sales-Transactions-Delete','Book-Keeping-Sales-Transactions-Return','Book-Keeping-Sales-Transactions-Make-Payment',
                'Book-Keeping-Sales-Return-Update','Book-Keeping-Sales-Return-Show','Book-Keeping-Sales-Return-Delete',
                'Book-Keeping-Expense-Type-Insert', 'Book-Keeping-Expense-Type-Update', 'Book-Keeping-Expense-Type-Delete', 'Book-Keeping-Expense-Type-Show',
                'Book-Keeping-Expense-Transactions-Insert', 'Book-Keeping-Expense-Transactions-Update', 'Book-Keeping-Expense-Transactions-Delete', 'Book-Keeping-Expense-Transactions-Show',
                'Book-Keeping-Supplier-Payment-Update', 'Book-Keeping-Supplier-Payment-Delete', 'Book-Keeping-Supplier-Payment-Show',
                'Book-Keeping-Customer-Payment-Update', 'Book-Keeping-Customer-Payment-Delete', 'Book-Keeping-Customer-Payment-Show',
                'Book-Keeping-Customer-Insert', 'Book-Keeping-Customer-Update', 'Book-Keeping-Customer-Delete', 'Book-Keeping-Customer-Show',
                'Book-Keeping-Supplier-Insert', 'Book-Keeping-Supplier-Update', 'Book-Keeping-Supplier-Delete', 'Book-Keeping-Supplier-Show',
                ];
        
            foreach ($menuPermissions as $permission) {
                Permission::firstOrCreate([
                    'name' => $permission,
                    'tenant_id' => $tenantId,
                    'category' => 'Menu Permissions',
                ]);
            }
        
            foreach ($otherPermissions as $permission) {
                Permission::firstOrCreate([
                    'name' => $permission,
                    'tenant_id' => $tenantId,
                    'category' => 'Other Permissions',
                ]);
            }

            // Step 4: Create Roles for the Tenant
            $adminRole = Role::firstOrCreate([
                'name' => 'Admin',
                'tenant_id' => $tenantId,
            ]);

            $adminroleId= $adminRole->id;
        
            $staffRole = Role::firstOrCreate([
                'name' => 'Staff',
                'tenant_id' => $tenantId,
            ]);

            // Step 5: Assign Permissions to Roles
            $adminRole->syncPermissions(Permission::where('tenant_id', $tenantId)->get());

            // $staffRole->syncPermissions([
            //     Permission::where('name', 'product management')->where('tenant_id', $tenantId)->first(),
            //     Permission::where('name', 'transaction management')->where('tenant_id', $tenantId)->first(),
            // ]);

       
            $user = $request->user();
            $user->update([
                'role_id' => $adminroleId,
                'tenant_id' => $tenantId,
            ]);

            $user->assignRole($adminRole);

            $company = company::create([
                'tenant_id' => $tenantId,
                'company_name' =>  'Untitled Company',
                'address1' =>  'Default Lane1',
                'address2' =>  'Default Lane2',
                'city' =>  'Default city',
                'state_id' => 14,
                'pincode' => '500081',
                'gst_number' => '36ABCDE1234F1Z5',
            ]);
    
            $names = ['Casting', 'Tools and gauges', 'Fixtures'];
            $createdCategories = [];
            
            foreach ($names as $name) {
                $createdCategories[] = Itemcategory::create([
                    'tenant_id' => $tenantId,
                    'company_id' => $company->id,
                    'name' => $name,
                ]);
            }

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
                'transaction_time'=> 'yes',
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

             // Aggregate permissions by category
           
            $permissionsByCategory = Permission::where('tenant_id', $tenantId)
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

            DB::commit();


            return response()->json([
                'status' => 200,
                'message' => "Tenant {$tenant->name} created successfully!",
                'tenant' => $tenant,
                'record' => $user,
                'company' => $company,
                'item_categories' => $createdCategories,
                'permissions' => $permissionsByCategory,
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 500,
                'message' => 'Error updating profile',
                'error' => $e->getMessage(),
                'record' => null,
            ], 500);
        }

    }
}
