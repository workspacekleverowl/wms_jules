<?php

namespace Modules\Masters\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use App\Models\Itemcategory;
use App\Models\Item;
use App\Models\party;
use App\Models\Tenant;
use App\Models\User; 
use App\Models\UserMeta; 
use App\Models\transporter; 
use App\Models\Voucher; 
use App\Models\Vouchermeta;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\MultiSheetExport;
use Illuminate\Support\Str;

class CompanyController extends Controller
{
     // Create a new transporter
     public function store(Request $request)
     {
        $response = $this->checkPermission('Company-Insert');
    
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }
        
         $validator = Validator::make($request->all(), [
             'company_name' => 'required|string|max:255',
             'address1' => 'required|string|max:255',
             'address2' => 'sometimes|nullable|string|max:255',
             'city' => 'required|string|max:255',
             'state_id' => 'required|integer|exists:states,id',
             'pincode' => 'required|integer',
             'gst_number' => 'required|string|max:15',
         ]);
 
         if ($validator->fails()) {
             return response()->json([
                 'status' => 422,
                 'message' => 'Validation failed',
                 'errors' => $validator->errors(),
             ], 422);
         }
 
         $Id = $request->user()->tenant_id;
 
         // Ensure the transporter name is unique within the tenant
         if (company::where('tenant_id', $Id)->where('gst_number', $request->gst_number)->exists()) {
             return response()->json([
                 'status' => 409,
                 'message' => 'company already exists for this User',
             ], 409);
         }
 
         $company = company::create([
             'tenant_id' => $Id,
             'company_name' => $request->company_name,
             'address1' => $request->address1,
             'address2' => $request->address2,
             'city' => $request->city,
             'state_id' => $request->state_id,
             'pincode' => $request->pincode,
             'gst_number' => $request->gst_number,
         ]);
 
         $names = ['Casting', 'Tools and gauges', 'Fixtures'];
         $createdCategories = [];
         
         foreach ($names as $name) {
             $createdCategories[] = Itemcategory::create([
                 'tenant_id' => $Id,
                 'company_id' => $company->id,
                 'name' => $name,
             ]);
         }
 
 
         return response()->json([
             'status' => 200,
             'message' => 'company created successfully',
             'record' => $company,
             'item_categories' => $createdCategories,
         ], 200);
     }
 
     // Retrieve all transporters for the authenticated tenant
     public function index(Request $request)
     {
        $response = $this->checkPermission('Company-Show');
    
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

         $Id = $request->user()->tenant_id;
 
         // Validate request inputs for pagination, search, and filters
         $validator = Validator::make($request->all(), [
             'page' => 'sometimes|integer|min:1',
             'per_page' => 'sometimes|integer|min:1|max:100',
             'search' => 'sometimes|string|max:255',
             'state_id' => 'sometimes|integer',
             'status' => 'sometimes|string|in:active,inactive',
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
             // Query companies for the authenticated user's tenant
             $query = company::where('tenant_id', $Id);
     
             // Apply search filter
             if ($request->has('search') && !empty($request->search)) {
                 $searchTerm = $request->search;
                 $query->where(function ($q) use ($searchTerm) {
                     $q->where('company_name', 'like', '%' . $searchTerm . '%')
                       ->orWhere('city', 'like', '%' . $searchTerm . '%')
                       ->orWhere('address1', 'like', '%' . $searchTerm . '%')
                       ->orWhere('address2', 'like', '%' . $searchTerm . '%')
                       ->orWhere('pincode', 'like', '%' . $searchTerm . '%')
                       ->orWhere('gst_number', 'like', '%' . $searchTerm . '%');
                 });
             }
     
             // Apply state_id filter
             if ($request->has('state_id')) {
                 $query->where('state_id', $request->state_id);
             }
     
             // Apply status filter
             if ($request->has('status')) {
                 $query->where('status', $request->status);
             }
     
             // Get total records count
             $totalRecords = $query->count();
     
             // Apply pagination
             $companies = $query->skip(($page - 1) * $perPage)
                 ->take($perPage)
                 ->get();
     
             if ($companies->isEmpty()) {
                 return response()->json([
                     'status' => 200,
                     'message' => 'No companies found',
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
                 'message' => 'Companies retrieved successfully',
                 'pagination' => [
                     'page' => $page,
                     'per_page' => $perPage,
                     'total_records' => $totalRecords,
                 ],
                 'record' => $companies,
             ], 200);
         } catch (\Exception $e) {
             return response()->json([
                 'status' => 500,
                 'message' => 'Error retrieving companies',
                 'error' => $e->getMessage(),
             ], 500);
         }
     }
 
     // Retrieve a single transporter
     public function show($id, Request $request)
     {
        $response = $this->checkPermission('Company-Show');
    
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

         $Id = $request->user()->tenant_id;
         $company = company::where('id', $id)->where('tenant_id', $Id)->first();
 
         if (!$company) {
             return response()->json([
                 'status' => 200,
                 'message' => 'company not found',
             ], 200);
         }
 
         return response()->json([
             'status' => 200,
             'message' => 'company retrieved successfully',
             'record' => $company,
         ]);
     }
 
     // Update a transporter
     public function update(Request $request, $id)
     {
        $response = $this->checkPermission('Company-Update');
    
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

         $validator = Validator::make($request->all(), [
             'company_name' => 'required|string|max:255',
             'address1' => 'required|string|max:255',
             'address2' => 'sometimes|nullable|string|max:255',
             'city' => 'required|string|max:255',
             'state_id' => 'required|integer|exists:states,id',
             'pincode' => 'required|integer',
             'gst_number' => 'required|string|max:15',
         ]);
 
         if ($validator->fails()) {
             return response()->json([
                 'status' => 422,
                 'message' => 'Validation failed',
                 'errors' => $validator->errors(),
             ], 422);
         }
 
         $Id = $request->user()->tenant_id;
         $company = company::where('id', $id)->where('tenant_id', $Id)->first();
 
         if (!$company) {
             return response()->json([
                 'status' => 200,
                 'message' => 'company not found',
             ], 200);
         }
 
         // Ensure the transporter name is unique within the tenant
         if ($request->has('gst_number') && company::where('tenant_id', $Id)->where('gst_number', $request->gst_number)->where('id', '!=', $id)->exists()) {
             return response()->json([
                 'status' => 409,
                 'message' => 'company already exists for this user',
             ], 409);
         }
 
         $company->update($request->all());
 
         return response()->json([
             'status' => 200,
             'message' => 'company updated successfully',
             'record' => $company,
         ]);
     }
 
     // Delete a transporter
    public function destroy($id, Request $request)
    {
         $response = $this->checkPermission('Company-Delete');
     
         // If checkPermission returns a response (i.e., permission denied), return it.
         if ($response) {
             return $response;
         }
     
         $user = $request->user();
         $tenantId = $user->tenant_id;
         $activeCompanyId = $user->getActiveCompanyId();
         
         // Start transaction
         DB::beginTransaction();
     
         try {
             $company = company::where('id', $id)->where('tenant_id', $tenantId)->first();
     
             if (!$company) {
                 return response()->json([
                     'status' => 200,
                     'message' => 'Company not found',
                 ], 200);
             }
     
             // Check if only one company exists for this tenant
             $remainingCompaniesCount = company::where('tenant_id', $tenantId)->count();
     
             if ($remainingCompaniesCount <= 1) {
                 return response()->json([
                     'status' => 409,
                     'message' => 'The last remaining company cannot be deleted',
                 ], 409);
             }
     
             // Perform all the delete operations
             Itemcategory::where('company_id', $company->id)->where('tenant_id', $tenantId)->delete();
             Item::where('company_id', $company->id)->where('tenant_id', $tenantId)->delete();
             party::where('company_id', $company->id)->where('tenant_id', $tenantId)->delete();
            //  productstock::where('company_id', $company->id)->where('tenant_id', $tenantId)->delete();
     
             // Deleting vouchers and related voucher meta
             $vouchers = Voucher::where('tenant_id', $tenantId)
                 ->where('company_id',$company->id)
                 ->get();
     
             foreach ($vouchers as $voucher) {
                 $voucher->Vouchermeta()->delete();  // Delete related voucher meta
                 $voucher->delete();  // Delete the voucher itself
             }
     
             // Soft delete the company
             $company->delete();
     
             // Update the user's active company if needed
             $company1 = company::where('tenant_id', $tenantId)->first();
     
             $userMeta = $user->meta();
             if ($userMeta) {
                 $userMeta->update(['active_company_id' => $company1 ? $company1->id : null]);
             }
     
             // Commit the transaction
             DB::commit();
     
             return response()->json([
                 'status' => 200,
                 'message' => 'Company deleted successfully',
             ]);
         } catch (\Exception $e) {
             // Rollback the transaction if any error occurs
             DB::rollBack();
     
             // Log the error for debugging purposes
             \Log::error('Company deletion failed: ' . $e->getMessage());

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
                 'message' => 'Failed to delete company',
             ], 500);
         }
    }
 
     public function changeStatus(Request $request, $id)
     {
        $response = $this->checkPermission('Company-Update');
    
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }
         // Validate the input
         $validator = Validator::make($request->all(), [
             'status' => 'required|in:active,inactive',
         ]);
 
         if ($validator->fails()) {
             return response()->json([
                 'status' => 422,
                 'message' => 'Validation failed',
                 'error' => $validator->errors(),
             ], 422);
         }
 
         try {
             // Get the authenticated user's tenant ID
             $tenantId = $request->user()->tenant_id;
 
             // Find the company for the tenant
             $company = Company::where('tenant_id', $tenantId)->find($id);
 
             if (!$company) {
                 return response()->json([
                     'status' => 200,
                     'message' => 'Company not found',
                 ], 200);
             }
 
             // Update the status
             $company->status = $request->status;
             $company->save();

            
 
             return response()->json([
                 'status' => 200,
                 'message' => 'Company status updated successfully',
                 'record' => $company,
             ], 200);
         } catch (\Exception $e) {
             return response()->json([
                 'status' => 500,
                 'message' => 'Error updating company status',
                 'error' => $e->getMessage(),
             ], 500);
         }
     }

     public function getCompanyData(Request $request)
     {
        $response = $this->checkPermission('Company-Show');
    
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }
         $user = $request->user();
         $tenantId = $user->tenant_id;
         $activeCompanyId = $user->getActiveCompanyId();
     
         // Fetch company details
         $company = Company::where('tenant_id', $tenantId)
             ->where('id', $activeCompanyId)
             ->where('status','active')
             ->select('id', 'company_name')
             ->first();
     
         // If company not found, return error
         if (!$company) {
             return response()->json([
                 'status' => 200,
                 'message' => 'Active company not found for the tenant',
             ], 200);
         }
     
         // Fetch categories (id and name only)
         $categories = Itemcategory::where('tenant_id', $tenantId)
             ->where('company_id', $activeCompanyId)
             ->select('id', 'name')
             ->get();
     
        
         // Fetch party (id and name only)
         $party = Party::where('tenant_id', $tenantId)
             ->where('company_id', $activeCompanyId)
             ->where('status','active')
             ->whereNull('deleted_at')
             ->select('id', 'name')
             ->get();

         // Fetch products (id and name only)
        //  $products = Product::where('tenant_id', $tenantId)
        //      ->where('company_id', $activeCompanyId)
        //      ->select('id', 'name')
        //      ->get();
     
        $categoryId = $request->input('category_id');
        $partyId = $request->input('party_id');  
        
        $query = Item::where('tenant_id', $tenantId)
            ->where('status','active')
            ->whereNull('deleted_at')
            ->where('company_id', $activeCompanyId);
        
         // Apply category filter
         if ($categoryId) {
            $query->where('category_id', $categoryId);
        }
    
        // Apply party filter
        if ($partyId) {
            $query->whereRaw('JSON_CONTAINS(party_id, ?)', [$partyId]);
        }
    
        
        $items = $query->select('id', 'name','category_id','parent_id')->get(); 
        
         
        $query1 = Item::where('tenant_id', $tenantId)
            ->where('status','active')
            ->whereNull('deleted_at')
            ->whereNull('parent_id')
            ->where('company_id', $activeCompanyId);
        
         // Apply category filter
         if ($categoryId) {
            $query1->where('category_id', $categoryId);
        }
    
        // Apply party filter
        if ($partyId) {
            $query1->whereRaw('JSON_CONTAINS(party_id, ?)', [$partyId]);
        }
    
        
        $parentitems = $query1->select('id', 'name','category_id','parent_id')->get();    

        $itemsQuery = Item::where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->where('company_id', $activeCompanyId);

            // Apply category filter
            if ($categoryId) {
                $itemsQuery->where('category_id', $categoryId);
            }

            // Apply party filter
            if ($partyId) {
                $itemsQuery->whereRaw('JSON_CONTAINS(party_id, ?)', [$partyId]);
            }

            // Fetch all relevant items with item_type
            $items1 = $itemsQuery->select('id', 'name', 'item_type', 'parent_id','category_id')->get();

            // Filter based on item_type logic
            $filteredItems = $items1->filter(function ($item) {
                // Return parent if item_type is 'single'
                if ($item->item_type === 'single' && $item->parent_id === null) {
                    return true;
                }

                // Return child if item_type is 'grouped'
                if ($item->item_type === 'grouped' && $item->parent_id !== null) {
                    return true;
                }

                return false;
            })->values(); // Reindex the collection

         
     
     
         return response()->json([
             'status' => 200,
             'message' => 'Data retrieved successfully',
             'company' => $company,
             'party' => $party,
             'categories' => $categories,
             'items' => $items,
             'parentitems' => $parentitems,
             'singleparentgroupedchilditems' =>$filteredItems
         ]);
     }


     //export company dump 
     public function exportcompanyData(Request $request)
     {
         $user = $request->user();
         $tenantId = $user->tenant_id;
         $companyId = $request->input('company_id');
 
         // Check if company belongs to the tenant
         $company = Company::where('id', $companyId)->where('tenant_id', $tenantId)->first();
         if (!$company) {
            return response()->json([
                'status' => 200,
                'message' => 'Company Not Found...!',
            ]);
         }
 
         $exportData = [
            'Users' => [
                'data' => $this->getUsersData($tenantId),
                'headings' => ['Email', 'Phone','First Name', 'Last Name',  'Company Name','Address line 1','Address line 2', 'City', 'Pincode','GST Number']
            ],
            'Transporters' => [
                'data' => $this->getTransportersData($tenantId),
                'headings' => ['Name', 'Phone', 'Vehicle Number']
            ],
            'Party' => [
                'data' => $this->getPartiesData($tenantId, $companyId),
                'headings' => ['Name', 'Address line 1', 'Address line 2', 'City', 'Pincode', 'GST Number','Company Name']
            ],
            'Item Categories' =>[
                'data' => $this->getItemCategoriesData($tenantId, $companyId),
                'headings' => ['Name']
            ],
            'Items' => [
                'data' =>  $this->getItemsData($tenantId, $companyId),
                'headings' => ['Name', 'Category', 'Party', 'Material Price', 'Job Work Rate', 'Raw Weight', 'Finished Weight','Scrap Weight','Gst Percent Rate','HSN','Item Code','Description']
            ],
            'Vouchers' =>[
                'data' => $this->getVouchersData($tenantId, $companyId),
                'headings' => ['Transaction Type', 'Transaction Date', 'Voucher Number', 'vehicle Number', 'Description', 'Category', 'Item', 'Item Quantity', 'Material Price', 'Job Work Rate', 'Gst Percent Rate', 'Remark']
                
            ],

         ];
 
         $slug = Str::slug($company->company_name);

         // Create the file name using the slug
         $fileName = $slug . '_data_' . time() . '.xlsx';

        Excel::store(new MultiSheetExport($exportData), $fileName, 'excel');

        // Return the file download URL
        return response()->json([
            'status' => 200,
            'message' => 'Export successful',
            'download_url' => url( 'uploads/' . $fileName),
        ]);
     }
 

     //export company dump helper
     private function getUsersData($tenantId)
     {
         return User::where('tenant_id', $tenantId)
             ->leftJoin('user_meta', 'users.id', '=', 'user_meta.user_id')
             ->get(['users.email', 'users.phone', 'user_meta.first_name', 'user_meta.last_name', 'user_meta.company_name', 'user_meta.address1', 'user_meta.address2', 'user_meta.city', 'user_meta.pincode', 'user_meta.gst_number'])
             ->toArray();
     }
 
      //export company dump helper
     private function getTransportersData($tenantId)
     {
         return Transporter::where('tenant_id', $tenantId)
             ->get(['name', 'phone', 'vehicle_number'])
             ->toArray();
     }
 
      //export company dump helper
     private function getPartiesData($tenantId, $companyId)
     {
         return Party::where('party.tenant_id', $tenantId)->where('party.company_id', $companyId)
             ->leftJoin('companies', 'party.company_id', '=', 'companies.id')
             ->get(['party.name', 'party.address1', 'party.address2', 'party.city', 'party.pincode', 'party.gst_number', 'companies.company_name'])
             ->toArray();
     }
 
      //export company dump helper
     private function getItemCategoriesData($tenantId, $companyId)
     {
         return Itemcategory::where('tenant_id', $tenantId)->where('company_id', $companyId)
             ->get(['name'])
             ->toArray();
     }
 
      //export company dump helper
     private function getItemsData($tenantId, $companyId)
     {
         return Item::where('item.tenant_id', $tenantId)->where('item.company_id', $companyId)
             ->leftJoin('item_category', 'item.category_id', '=', 'item_category.id')
             ->leftJoin('party', 'item.party_id', '=', 'party.id')
             ->get(['item.name', 'item_category.name as category_name', 'party.name as party_name', 'item.material_price', 'item.job_work_rate', 'item.raw_weight', 'item.finished_weight', 'item.scrap_weight', 'item.gst_percent_rate', 'item.hsn', 'item.item_code', 'item.description'])
             ->toArray();
     }
 
      //export company dump helper
     private function getVouchersData($tenantId, $companyId)
     {
         return Voucher::where('voucher.tenant_id', $tenantId)->where('voucher.company_id', $companyId)
             ->leftJoin('voucher_meta', 'voucher.id', '=', 'voucher_meta.voucher_id')
             ->leftJoin('item_category', 'voucher_meta.category_id', '=', 'item_category.id')
             ->leftJoin('item', 'voucher_meta.item_id', '=', 'item.id')
             ->get(['voucher.transaction_type', 'voucher.transaction_date', 'voucher.voucher_no', 'voucher.vehicle_number', 'voucher.description', 'item_category.name as category_name', 'item.name as item_name', 'voucher_meta.item_quantity', 'voucher_meta.material_price', 'voucher_meta.job_work_rate', 'voucher_meta.gst_percent_rate', 'voucher_meta.remark'])
             ->toArray();
     }


     
}
