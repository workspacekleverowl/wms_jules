<?php

namespace Modules\Masters\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\PredispatchInspection;
use Carbon\Carbon;
use App\Models\company;
use App\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

class PredispatchInspectionController extends ApiController
{
    public function predispatchIndex(Request $request)
    {
        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Predispatch-Inspection-Show');
        if ($permissionResponse) return $permissionResponse;
        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $tenantId = $user->tenant_id;

        try {
            $perPage = $request->input('per_page', 10);
            $page = $request->input('page', 1);
            $search = $request->input('search');
            $partyId = $request->input('party_id');
            $itemId = $request->input('item_id');

            $query = PredispatchInspection::with('party','Item')->where('tenant_id', $tenantId)->where('company_id', $activeCompanyId);

            if ($request->filled('date_from') && $request->filled('date_to')) {
                $query->whereBetween('date', [
                    $request->input('date_from'),
                    $request->input('date_to')
                ]);
            }

           // Party filter
            if (!empty($partyId)) {
                $query->where('party_id', $partyId);
            }

            // Item filter
            if (!empty($itemId)) {
                $query->where('item_id', $itemId);
            }

            // Enhanced search functionality
            if (!empty($search)) {
                $query->where(function($q) use ($search) {
                    $q->where('pdir_no', 'like', "%{$search}%")
                    ->orWhere('challan_no', 'like', "%{$search}%")
                    ->orWhere('checked_by', 'like', "%{$search}%")
                    ->orWhere('verified_by', 'like', "%{$search}%")
                    ->orWhereHas('party', function($partyQuery) use ($search) {
                        $partyQuery->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('Item', function($itemQuery) use ($search) {
                        $itemQuery->where('name', 'like', "%{$search}%");
                    });
                });
            }

            $query->orderBy('id', 'desc');
            $records = $query->paginate((int)$perPage, ['*'], 'page', (int)$page);

            return $this->paginatedResponse($records, 'Predispatch inspections retrieved successfully');
        } catch (\Exception $e) {
            return static::errorResponse(['Failed to retrieve inspections', $e->getMessage()], 500);
        }
    }

    public function predispatchStore(Request $request)
    {
        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Predispatch-Inspection-Insert');
        if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $tenantId = $user->tenant_id;

        $validator = Validator::make($request->all(), [
            'party_id' => [
                    'required',
                    Rule::exists('party', 'id')->where(function ($query) use ($tenantId, $activeCompanyId) {
                        $query->where('tenant_id', $tenantId)
                            ->where('company_id', $activeCompanyId);
                    }),
                ],
            'item_id' =>  'required|integer|exists:item,id',
            'date' => 'required|date',
            'challan_no' => 'required|string',
            'inspection_data' => 'nullable|array',
            'inspection_data.*.parameter' => 'nullable|string',
            'inspection_data.*.specification' => 'nullable|string',
            'inspection_data.*.chs' => 'nullable|string',
            'inspection_data.*.inspection_method' => 'nullable|string',
            'inspection_data.*.supplier_obs_1' => 'nullable|string',
            'inspection_data.*.supplier_obs_2' => 'nullable|string',
            'inspection_data.*.supplier_obs_3' => 'nullable|string',
            'inspection_data.*.supplier_obs_4' => 'nullable|string',
            'inspection_data.*.supplier_obs_5' => 'nullable|string',
            'inspection_data.*.sgi_1' => 'nullable|string',
            'inspection_data.*.sgi_2' => 'nullable|string',
            'inspection_data.*.remark' => 'nullable|string',
            'checked_by' => 'nullable|string',
            'verified_by' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return static::errorResponse($validator->errors()->all(), 422);
        }

        $pdirNo=$this->getNextPdirNo( $activeCompanyId);

        DB::beginTransaction();
        try {
            $data = [
                'tenant_id' => $tenantId,
                'company_id' => $activeCompanyId,
                'party_id' =>  $request->party_id??null,
                'item_id' => $request->item_id??null,
                'date' =>  $request->date??null,
                'pdir_no' =>  $pdirNo,
                'challan_no' => $request->challan_no??null,
                'quantity' => $request->quantity??null,
                'inspection_data' => $request->inspection_data ? json_encode($request->inspection_data) : null,
                'checked_by' => $request->checked_by??null,
                'verified_by' =>  $request->verified_by??null,
            ];

            $record = PredispatchInspection::create($data);
            DB::commit();

            return $this->predispatchShow($request, $record->id);
        } catch (\Exception $e) {
            DB::rollBack();
            return static::errorResponse(['Failed to create record', $e->getMessage()], 500);
        }
    }

    public function predispatchShow(Request $request, $id)
    {
        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Predispatch-Inspection-Show');
        if ($permissionResponse) return $permissionResponse;
        
        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $tenantId = $user->tenant_id;

        try {
            $record = PredispatchInspection::with('party','Item')->where('tenant_id', $tenantId)->where('company_id', $activeCompanyId)->find($id);

            if (!$record) {
                return static::errorResponse(['Invalid ID'], 404);
            }

            // Decode JSON to array for API response
            if ($record->inspection_data) {
                $record->inspection_data = json_decode($record->inspection_data, true);
            }

            $company = company::with('state')->where('id', $activeCompanyId)
                            ->where('tenant_id',  $tenantId)
                            ->first();
            
            if (!$company) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Company not found',
                ], 422);
            }

            return static::successResponse([
                'predispatch_inspection' => $record,
                'company' => [
                    'id' => $company->id,
                    'name' => $company->company_name,
                    'address_line_1' => $company->address1,
                    'address_line_2' => $company->address2,
                    'city' => $company->city,
                    'state' => $company->state->title,
                    'pincode' => $company->pincode,
                    'gst_number' => $company->gst_number,
                ]
            ], 'Record fetched');
        } catch (\Exception $e) {
            return static::errorResponse(['Failed to retrieve record', $e->getMessage()], 500);
        }
    }

    public function predispatchUpdate(Request $request, $id)
    {
         $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Predispatch-Inspection-Update');
        if ($permissionResponse) return $permissionResponse;
        
        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $tenantId = $user->tenant_id;

        $validator = Validator::make($request->all(), [
            'party_id' => [
                    'required',
                    Rule::exists('party', 'id')->where(function ($query) use ($tenantId, $activeCompanyId) {
                        $query->where('tenant_id', $tenantId)
                            ->where('company_id', $activeCompanyId);
                    }),
                ],
            'item_id' =>  'required|integer|exists:item,id',
            'date' => 'required|date',
            'challan_no' => 'required|string',
            'inspection_data' => 'nullable|array',
            'inspection_data.*.parameter' => 'nullable|string',
            'inspection_data.*.specification' => 'nullable|string',
            'inspection_data.*.chs' => 'nullable|string',
            'inspection_data.*.inspection_method' => 'nullable|string',
            'inspection_data.*.supplier_obs_1' => 'nullable|string',
            'inspection_data.*.supplier_obs_2' => 'nullable|string',
            'inspection_data.*.supplier_obs_3' => 'nullable|string',
            'inspection_data.*.supplier_obs_4' => 'nullable|string',
            'inspection_data.*.supplier_obs_5' => 'nullable|string',
            'inspection_data.*.sgi_1' => 'nullable|string',
            'inspection_data.*.sgi_2' => 'nullable|string',
            'inspection_data.*.remark' => 'nullable|string',
            'checked_by' => 'nullable|string',
            'verified_by' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return static::errorResponse($validator->errors()->all(), 422);
        }

        DB::beginTransaction();
        try {
            $record = PredispatchInspection::where('tenant_id', $tenantId)->where('company_id', $activeCompanyId)->find($id);

            if (!$record) {
                return static::errorResponse(['Invalid ID'], 404);
            }

            if ($request->filled('party_id') || $request->party_id === null || $request->party_id === '') {
                $record->party_id = $request->party_id;
            }

            if ($request->filled('item_id') || $request->item_id === null || $request->item_id === '') {
                $record->item_id = $request->item_id;
            }

            if ($request->filled('date') || $request->date === null || $request->date === '') {
                $record->date = $request->date;
            }

            // Skipping pdir_no (auto-incremented and fixed on create only)

            if ($request->filled('challan_no') || $request->challan_no === null || $request->challan_no === '') {
                $record->challan_no = $request->challan_no;
            }

             if ($request->filled('quantity') || $request->quantity === null || $request->quantity === '') {
                $record->quantity = $request->quantity;
            }
            

            if ($request->filled('inspection_data') || $request->inspection_data === null || $request->inspection_data === '') {
                $record->inspection_data = $request->inspection_data ? json_encode($request->inspection_data) : null;
            }

            if ($request->filled('checked_by') || $request->checked_by === null || $request->checked_by === '') {
                $record->checked_by = $request->checked_by;
            }

            if ($request->filled('verified_by') || $request->verified_by === null || $request->verified_by === '') {
                $record->verified_by = $request->verified_by;
            }

            $record->save();

            DB::commit();
            return $this->predispatchShow($request, $id);
        } catch (\Exception $e) {
            DB::rollBack();
            return static::errorResponse(['Failed to update record', $e->getMessage()], 500);
        }
    }

    public function predispatchDestroy(Request $request, $id)
    {
        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Predispatch-Inspection-Delete');
        if ($permissionResponse) return $permissionResponse;
        
        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $tenantId = $user->tenant_id;

        try {
            $record = PredispatchInspection::where('tenant_id', $tenantId)->where('company_id', $activeCompanyId)->find($id);

            if (!$record) {
                return static::errorResponse(['Invalid ID'], 404);
            }

            $record->delete();
            return static::successResponse(null, 'Record deleted successfully');
        } catch (\Exception $e) {
            return static::errorResponse(['Failed to delete record', $e->getMessage()], 500);
        }
    }

    public function getNextPdirNo($companyId)
    {
        $max = PredispatchInspection::where('company_id', $companyId)->max('pdir_no');
        return ($max ?? 0) + 1;
    }
}
