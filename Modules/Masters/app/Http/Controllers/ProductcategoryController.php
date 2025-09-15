<?php

namespace Modules\Masters\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Itemcategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductcategoryController extends Controller
{
    // Create a new Item category
    public function store(Request $request)
    {
        $response = $this->checkPermission('Category-Insert');
    
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

       $user = $request->user();
       $activeCompanyId = $user->getActiveCompanyId();
       $tenantId = $user->tenant_id;

       $validator = Validator::make($request->all(), [
           'name' => 'required|string|max:255',
           
       ]);

       if ($validator->fails()) {
           return response()->json([
               'status' => 422,
               'message' => 'Validation failed',
               'errors' => $validator->errors(),
           ], 422);
       }


        // Ensure the Item category name is unique within the tenant
        if (Itemcategory::where('tenant_id', $tenantId)->where('name', $request->name)->where('company_id', $activeCompanyId)->exists()) {
            return response()->json([
                'status' => 409,
                'message' => 'Item category already exists for this tenant',
            ], 409);
        }

        $Itemcategory = Itemcategory::create([
            'tenant_id' => $tenantId,
            'company_id' => $activeCompanyId,
            'name' => $request->name,
        ]);

        return response()->json([
            'status' => 200,
            'message' => 'Item category created successfully',
            'record' => $Itemcategory,
        ], 200);
    }

    // Retrieve all Item categories for the authenticated tenant
    public function index(Request $request)
    {
        $response = $this->checkPermission('Category-Show');
    
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $tenantId = $request->user()->tenant_id;
        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        // Validate request inputs for pagination and search
        $validator = Validator::make($request->all(), [
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'search' => 'sometimes|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Get pagination parameters or default values
        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 10);

        try {
            $query = Itemcategory::where('tenant_id', $tenantId)->where('company_id', $activeCompanyId);

            // Apply search filter
            if ($request->has('search') && !empty($request->search)) {
                $query->where('name', 'like', '%' . $request->search . '%');
            }

            // Get total records count
            $totalRecords = $query->count();

            // Apply pagination
            $ItemCategories = $query->skip(($page - 1) * $perPage)
                ->take($perPage)
                ->get();

            if ($ItemCategories->isEmpty()) {
                return response()->json([
                    'status' => 200,
                    'message' => 'No Item categories found',
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
                'message' => 'Item categories retrieved successfully',
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total_records' => $totalRecords,
                ],
                'record' => $ItemCategories,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Error retrieving Item categories',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Retrieve a single Item category
    public function show($id, Request $request)
    {
        $response = $this->checkPermission('Category-Show');
    
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $tenantId = $request->user()->tenant_id;
        $Itemcategory = Itemcategory::where('id', $id)
            ->where('tenant_id', $tenantId)->where('company_id', $activeCompanyId)
            ->first();

        if (!$Itemcategory) {
            return response()->json([
                'status' => 200,
                'message' => 'Item category not found',
            ], 200);
        }

        return response()->json([
            'status' => 200,
            'message' => 'Item category retrieved successfully',
            'record' => $Itemcategory,
        ]);
    }

    // Update a Item category
    public function update(Request $request, $id)
    {
        $response = $this->checkPermission('Category-Update');
    
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

       $user = $request->user();
       $activeCompanyId = $user->getActiveCompanyId();
       $tenantId = $user->tenant_id;

           $validator = Validator::make($request->all(), [
               'name' => 'required|string|max:255',
               
           ]);

           if ($validator->fails()) {
               return response()->json([
                   'status' => 422,
                   'message' => 'Validation failed',
                   'errors' => $validator->errors(),
               ], 422);
           }

        $Itemcategory = Itemcategory::where('id', $id)
            ->where('tenant_id', $tenantId)->where('company_id', $activeCompanyId)
            ->first();

        if (!$Itemcategory) {
            return response()->json([
                'status' => 200,
                'message' => 'Item category not found',
            ], 200);
        }

        // Ensure the Item category name is unique within the tenant
        if (Itemcategory::where('tenant_id', $tenantId)
            ->where('name', $request->name)
            ->where('id', '!=', $id)
            ->where('company_id', $activeCompanyId)
            ->exists()) {
            return response()->json([
                'status' => 409,
                'message' => 'Item category already exists for this tenant',
            ], 409);
        }

        $Itemcategory->update(['name' => $request->name]);

        return response()->json([
            'status' => 200,
            'message' => 'Item category updated successfully',
            'record' => $Itemcategory,
        ]);
    }

    // Delete a Item category
    public function destroy($id, Request $request)
    {
        $response = $this->checkPermission('Category-Delete');
        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $tenantId = $request->user()->tenant_id;
        $Itemcategory = Itemcategory::where('id', $id)
            ->where('tenant_id', $tenantId)->where('company_id', $activeCompanyId)
            ->first();

        if (!$Itemcategory) {
            return response()->json([
                'status' => 200,
                'message' => 'Item category not found',
            ], 200);
        }

        try {

        $Itemcategory->delete();

        

        return response()->json([
            'status' => 200,
            'message' => 'Item category deleted successfully',
        ]);

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
         }    
    }
}
