<?php

namespace Modules\Masters\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\party;
use App\Models\Tenant;
use App\Models\User; 
use App\Models\UserMeta; 
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\company;
use App\Models\scraptransactions;


class ScrapController extends Controller
{
    /**
     * Display a listing of the resource.
     */
     // Retrieve all scrap transactions for the authenticated tenant
     public function index(Request $request)
     {
        $response = $this->checkPermission('Scrap-Transaction-Insert');
    
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

         $Id = $request->user()->tenant_id;
         $user = $request->user();
         $activeCompanyId = $user->getActiveCompanyId();
        $transactionDateFrom = $request->input('transaction_date_from');
        $transactionDateTo = $request->input('transaction_date_to');
        $transaction_type= $request->input('transaction_type');
        $partyID=$request->input('party_id');
 
         // Validate request inputs for pagination, search, and filters
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
             // Query companies for the authenticated user's tenant
             $query = scraptransactions::where('tenant_id', $Id)->where('company_id', $activeCompanyId)->orderBy('date', 'desc');

              if ($transactionDateFrom && $transactionDateTo) {
                    $query->whereBetween('date', [$transactionDateFrom, $transactionDateTo]);
                } elseif ($transactionDateFrom) {
                    $query->where('date', '>=', $transactionDateFrom);
                } elseif ($transactionDateTo) {
                    $query->where('date', '<=', $transactionDateTo);
                }


                if ($request->has('transaction_type') && !empty($request->transaction_type)) {
                     $query->where('scrap_type',$request->transaction_type);
                }

                if ($request->has('party_id') && !empty($request->party_id)) {
                     $query->where('party_id',$request->party_id);
                }
    
    
             // Apply search filter
             if ($request->has('search') && !empty($request->search)) {
                 $searchTerm = $request->search;
                 $query->where(function ($q) use ($searchTerm) {
                     $q->where('scrap_type', 'like', '%' . $searchTerm . '%')
                       ->orWhere('weighbridge_voucher_number', 'like', '%' . $searchTerm . '%')
                       ->orWhere('weighbridge_name', 'like', '%' . $searchTerm . '%')
                       ->orWhere('vehical_number', 'like', '%' . $searchTerm . '%')
                       ->orWhere('scrap_weight', 'like', '%' . $searchTerm . '%')
                       ->orWhere('description', 'like', '%' . $searchTerm . '%')
                       ->orWhere('voucher_number', 'like', '%' . $searchTerm . '%');
                 });
             }

              // Get total records count
            $totalRecords = $query->count();
     
             // Apply pagination
             $scraptransactions = $query->skip(($page - 1) * $perPage)
             ->take($perPage)
             ->get();
 
            if ($scraptransactions->isEmpty()) {
                return response()->json([
                    'status' => 200,
                    'message' => 'No scrap transactions found',
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
                'message' => 'scrap transactions retrieved successfully',
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total_records' => $totalRecords,
                ],
                'record' => $scraptransactions,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Error retrieving scrap transactions',
                'error' => $e->getMessage(),
            ], 500);
        }
     }

    // Create a new scrap transactions
    public function store(Request $request)
    {
        $response = $this->checkPermission('Scrap-Transaction-Insert');
    
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $tenantId = $user->tenant_id;
        
        $validator = Validator::make($request->all(), [
            'scrap_type' => 'required|in:inward,outward,adjustment',
            'party_id' => [
                    'integer',
                    Rule::exists('party', 'id')->where(function ($query) use ($tenantId, $activeCompanyId) {
                        $query->where('tenant_id', $tenantId)
                            ->where('company_id', $activeCompanyId);
                    }),
                ],
            'date' => 'required|date',
            'scrap_weight' => 'required',
           
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $Id = $request->user()->tenant_id;
        

       

        $scraptransaction = scraptransactions::create([
            'tenant_id' => $Id,
            'company_id' => $activeCompanyId,
            'scrap_type' => $request->scrap_type,
            'date' => $request->date,
            'party_id' => $request->party_id,
            'weighbridge_voucher_number' => $request->weighbridge_voucher_number,
            'weighbridge_name' => $request->weighbridge_name,
            'vehical_number' => $request->vehical_number,
            'scrap_weight' => $request->scrap_weight,
            'voucher_number' => $request->voucher_number,
            'description' => $request->description,
        ]);

        


        return response()->json([
            'status' => 200,
            'message' => 'scrap transactions created successfully',
            'record' => $scraptransaction,
        ], 200);
    }

    /**
     * Update the specified scrap transaction in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $response = $this->checkPermission('Scrap-Transaction-Update');

        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $tenantId = $user->tenant_id;
        
        // First, check if the scrap transaction exists and belongs to the user's tenant and company
        $scraptransaction = scraptransactions::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)
            ->first();
        
        if (!$scraptransaction) {
            return response()->json([
                'status' => 404,
                'message' => 'Scrap transaction not found',
            ], 404);
        }
        
        $validator = Validator::make($request->all(), [
            'scrap_type' => 'sometimes|required|in:inward,outward,adjustment',
            'party_id' => [
                'sometimes',
                'integer',
                Rule::exists('party', 'id')->where(function ($query) use ($tenantId, $activeCompanyId) {
                    $query->where('tenant_id', $tenantId)
                        ->where('company_id', $activeCompanyId);
                }),
            ],
            'date' => 'sometimes|required|date',
            'scrap_weight' => 'sometimes|required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Update only the fields that are provided in the request
        $fieldsToUpdate = [];
        
        // Check each field and update only if it's provided in the request
        if ($request->has('scrap_type')) {
            $fieldsToUpdate['scrap_type'] = $request->scrap_type;
        }
        
        if ($request->has('date')) {
            $fieldsToUpdate['date'] = $request->date;
        }
        
        if ($request->has('party_id')) {
            $fieldsToUpdate['party_id'] = $request->party_id;
        }
        
        if ($request->has('weighbridge_voucher_number')) {
            $fieldsToUpdate['weighbridge_voucher_number'] = $request->weighbridge_voucher_number;
        }
        
        if ($request->has('weighbridge_name')) {
            $fieldsToUpdate['weighbridge_name'] = $request->weighbridge_name;
        }
        
        if ($request->has('vehical_number')) {
            $fieldsToUpdate['vehical_number'] = $request->vehical_number;
        }
        
        if ($request->has('scrap_weight')) {
            $fieldsToUpdate['scrap_weight'] = $request->scrap_weight;
        }
        
        if ($request->has('voucher_number')) {
            $fieldsToUpdate['voucher_number'] = $request->voucher_number;
        }
        
        if ($request->has('description')) {
            $fieldsToUpdate['description'] = $request->description;
        }
        
        // Update the scrap transaction
        $scraptransaction->update($fieldsToUpdate);

        return response()->json([
            'status' => 200,
            'message' => 'Scrap transaction updated successfully',
            'record' => $scraptransaction,
        ], 200);
    }

    /**
     * Remove the specified scrap transaction from storage.
     *
     * @param  int  $id
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function destroy($id, Request $request)
    {
        $response = $this->checkPermission('Scrap-Transaction-Delete');

        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $tenantId = $user->tenant_id;
        
        // Find the scrap transaction by ID, tenant_id, and company_id
        $scraptransaction = scraptransactions::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)
            ->first();
        
        if (!$scraptransaction) {
            return response()->json([
                'status' => 404,
                'message' => 'Scrap transaction not found',
            ], 404);
        }
        
        // Delete the scrap transaction
        $scraptransaction->delete();
        
        return response()->json([
            'status' => 200,
            'message' => 'Scrap transaction deleted successfully',
        ], 200);
    }

    /**
     * Display the specified scrap transaction.
     *
     * @param  int  $id
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function show($id, Request $request)
    {
        $response = $this->checkPermission('Scrap-Transaction-Show');

        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $tenantId = $user->tenant_id;
        
        // Find the scrap transaction by ID, tenant_id, and company_id
        $scraptransaction = scraptransactions::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)
            ->first();
        
        if (!$scraptransaction) {
            return response()->json([
                'status' => 404,
                'message' => 'Scrap transaction not found',
            ], 404);
        }
        
        // Get party information if party_id exists
        $partyInfo = null;
        if ($scraptransaction->party_id) {
            $partyInfo = party::find($scraptransaction->party_id);
        }
        
        return response()->json([
            'status' => 200,
            'message' => 'Scrap transaction retrieved successfully',
            'record' => $scraptransaction,
            'party' => $partyInfo,
        ], 200);
    }
    
    public function checkscrapVoucherNumber(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $companyId = $user->getActiveCompanyId();

        $data = $request->validate([
            'voucher_no' => 'required',
            'party_id' => 'required|exists:party,id',
        ]);


        try {
            // Check for existing voucher
            $existingVoucher = scraptransactions::where('party_id', $data['party_id'])
                ->where('weighbridge_voucher_number', $data['voucher_no'])
                ->first();

            if ($existingVoucher) {
                $existingCompanyName = company::where('id', $existingVoucher->company_id)->value('company_name');
                if ($existingVoucher->company_id === $companyId) {
                    return response()->json([
                        'status' => 200,
                        'message' => "Voucher number already exists for this company: {$existingCompanyName}.",
                        'voucher' => 1,
                    ]);
                } else {
                    return response()->json([
                        'status' => 200,
                        'message' => "Voucher number already exists for another company: {$existingCompanyName}.",
                        'voucher' => 1,
                    ]);
                }
            }

            // Voucher number does not exist
            return response()->json([
                'status' => 200,
                'message' => 'Scrap Voucher number is available.',
                'voucher' => 0,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to check voucher number: ' . $e->getMessage(),
            ], 500);
        }
    }
}
