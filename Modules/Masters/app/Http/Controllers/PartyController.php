<?php

namespace Modules\Masters\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\party;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;



class PartyController extends Controller
{
    // Create a new party
    public function store(Request $request)
    {
        $response = $this->checkPermission('Party-Insert');
    
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'nullable',
            'address1' => 'required|string|max:255',
            'address2' => 'sometimes|nullable|string|max:255',
            'city' => 'required|string|max:255',
            'state_id' => 'required|integer|exists:states,id',
            'pincode' => 'required|integer',
            'gst_number' => 'nullable|string|max:15',
            'is_billable' =>'required|in:true,false',
            'email' => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $Id = $request->user()->tenant_id;
        

        // Ensure the party name is unique within the tenant
        if (party::where('tenant_id', $Id)->where('company_id', $activeCompanyId)->where('gst_number', $request->gst_number)->exists()) {
            return response()->json([
                'status' => 409,
                'message' => 'party already exists for this User',
            ], 409);
        }

        $party = party::create([
            'tenant_id' => $Id,
            'company_id' => $activeCompanyId,
            'name' => $request->name,
            'phone' =>$request->phone,
            'address1' => $request->address1,
            'address2' => $request->address2,
            'city' => $request->city,
            'state_id' => $request->state_id,
            'pincode' => $request->pincode,
            'gst_number' => $request->gst_number,
            'email' => $request->email,
            'is_billable' => $request->is_billable,
        ]);
  

        return response()->json([
            'status' => 200,
            'message' => 'party created successfully',
            'record' => $party,
        ], 200);
    }

    // Retrieve all partys for the authenticated tenant
    public function index(Request $request)
    {
        $response = $this->checkPermission('Party-Show');
    
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $Id = $request->user()->tenant_id;
        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
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
            $query = party::where('tenant_id', $Id)->where('company_id', $activeCompanyId)->where('bk_customer','false')->whereNull('deleted_at');
    
            // Apply search filter
            if ($request->has('search') && !empty($request->search)) {
                $searchTerm = $request->search;
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('name', 'like', '%' . $searchTerm . '%')
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
            $party = $query->skip(($page - 1) * $perPage)
                ->take($perPage)
                ->get();
    
            if ($party->isEmpty()) {
                return response()->json([
                    'status' => 200,
                    'message' => 'No party found',
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
                'record' => $party,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Error retrieving party',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

     // Retrieve all partys for the authenticated tenant
     public function trashindex(Request $request)
     {
         $response = $this->checkPermission('Party-Show');
     
         // If checkPermission returns a response (i.e., permission denied), return it.
         if ($response) {
             return $response;
         }
 
         $Id = $request->user()->tenant_id;
         $user = $request->user();
         $activeCompanyId = $user->getActiveCompanyId();
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
             $query = party::where('tenant_id', $Id)->where('company_id', $activeCompanyId)->where('bk_customer','false')->whereNotNull('deleted_at');
     
             // Apply search filter
             if ($request->has('search') && !empty($request->search)) {
                 $searchTerm = $request->search;
                 $query->where(function ($q) use ($searchTerm) {
                     $q->where('name', 'like', '%' . $searchTerm . '%')
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
             $party = $query->skip(($page - 1) * $perPage)
                 ->take($perPage)
                 ->get();
     
             if ($party->isEmpty()) {
                 return response()->json([
                     'status' => 200,
                     'message' => 'No party found',
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
                 'record' => $party,
             ], 200);
         } catch (\Exception $e) {
             return response()->json([
                 'status' => 500,
                 'message' => 'Error retrieving party',
                 'error' => $e->getMessage(),
             ], 500);
         }
     }

    // Retrieve a single party
    public function show($id, Request $request)
    {
        $response = $this->checkPermission('Party-Show');
    
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $Id = $request->user()->tenant_id;
        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $party = party::where('id', $id)->where('tenant_id', $Id)->where('company_id', $activeCompanyId)->where('bk_customer','false')->first();
        if (!$party) {
            return response()->json([
                'status' => 200,
                'message' => 'party not found',
            ], 200);
        }

        return response()->json([
            'status' => 200,
            'message' => 'party retrieved successfully',
            'record' => $party,
        ]);
    }

    // Update a party
    public function update(Request $request, $id)
    {
        $response = $this->checkPermission('Party-Update');
    
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'nullable',
            'address1' => 'required|string|max:255',
            'address2' => 'sometimes|nullable|string|max:255',
            'city' => 'required|string|max:255',
            'state_id' => 'required|integer|exists:states,id',
            'pincode' => 'required|integer',
            'gst_number' => 'required|string|max:15',
            'is_billable' =>'required|in:true,false',
            'email' => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $Id = $request->user()->tenant_id;
        $party = party::where('id', $id)->where('tenant_id', $Id)->where('company_id', $activeCompanyId)->where('bk_customer','false')->first();

        if (!$party) {
            return response()->json([
                'status' => 200,
                'message' => 'party not found',
            ], 200);
        }

        // Ensure the party name is unique within the tenant
        if ($request->has('gst_number') && party::where('tenant_id', $Id)->where('gst_number', $request->gst_number)->where('id', '!=', $id)->exists()) {
            return response()->json([
                'status' => 409,
                'message' => 'party already exists for this user',
            ], 409);
        }

        $data = $validator->validated();
        $party->update($data);

        return response()->json([
            'status' => 200,
            'message' => 'party updated successfully',
            'record' => $party,
        ]);
    }

    // Delete a party
    // public function destroy($id, Request $request)
    // {
    //     $response = $this->checkPermission('Party-Delete');
    
    //     // If checkPermission returns a response (i.e., permission denied), return it.
    //     if ($response) {
    //         return $response;
    //     }

    //     $user = $request->user();
    //     $activeCompanyId = $user->getActiveCompanyId();
    //     $Id = $request->user()->tenant_id;
    //     $party = party::where('id', $id)->where('tenant_id', $Id)->where('company_id', $activeCompanyId)->first();

    //     if (!$party) {
    //         return response()->json([
    //             'status' => 200,
    //             'message' => 'party not found',
    //         ], 200);
    //     }

       


    //     $party->delete();

       

    //     return response()->json([
    //         'status' => 200,
    //         'message' => 'party deleted successfully',
    //     ]);
    // }

    //softdelete party
    public function destroy($id, Request $request)
    {
        $response = $this->checkPermission('Party-Delete');
    
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $Id = $request->user()->tenant_id;
        $party = party::where('id', $id)->where('tenant_id', $Id)->where('company_id', $activeCompanyId)->first();

        if (!$party) {
            return response()->json([
                'status' => 200,
                'message' => 'party not found',
            ], 200);
        }

       


        $party->update(['deleted_at' => now()]);

       

        return response()->json([
            'status' => 200,
            'message' => 'party deleted successfully',
        ]);
    }

    //restore party
    public function restore($id, Request $request)
    {
        // $response = $this->checkPermission('Party-Restore');
    
        // // If checkPermission returns a response (i.e., permission denied), return it.
        // if ($response) {
        //     return $response;
        // }

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $Id = $request->user()->tenant_id;
        $party = party::where('id', $id)->where('tenant_id', $Id)->where('company_id', $activeCompanyId)->first();

        if (!$party) {
            return response()->json([
                'status' => 200,
                'message' => 'party not found',
            ], 200);
        }

       


        $party->update(['deleted_at' => null]);

       

        return response()->json([
            'status' => 200,
            'message' => 'party Restored successfully',
        ]);
    }


    public function changeStatus(Request $request, $id)
    {
        $response = $this->checkPermission('Party-Update');
    
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }
        
        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
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
            $party = party::where('tenant_id', $tenantId)->where('company_id', $activeCompanyId)->find($id);

            if (!$party) {
                return response()->json([
                    'status' => 200,
                    'message' => 'party not found',
                ], 200);
            }

            // Update the status
            $party->status = $request->status;
            $party->save();

           

            return response()->json([
                'status' => 200,
                'message' => 'party status updated successfully',
                'record' => $party,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Error updating party status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
