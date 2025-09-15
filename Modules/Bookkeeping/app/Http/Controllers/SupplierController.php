<?php

namespace Modules\Bookkeeping\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\bksupplier;
use Carbon\Carbon;
use App\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;


class SupplierController extends ApiController
{
    public function Index(Request $request)
    {
        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Book-Keeping-Supplier-Show');
        if ($permissionResponse) return $permissionResponse;
        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $tenantId = $user->tenant_id;

        try {
            $perPage = $request->input('per_page', 10);
            $page = $request->input('page', 1);
            $search = $request->input('search');
            $paymentStatus = $request->input('payment_status');
            $query = bksupplier::where('tenant_id', $tenantId)->where('company_id', $activeCompanyId)->whereNull('deleted_at');

            // Add payment status filter using subqueries
            if ($paymentStatus !== null && $paymentStatus !== '') {
                switch (strtolower($paymentStatus)) {
                    case 'paid':
                        // Suppliers where total_paid >= total_amount (or no orders)
                        $query->where(function($q) {
                            $q->whereRaw('
                                (SELECT COALESCE(SUM(paid_amount), 0) FROM bk_order 
                                WHERE party_id = bk_supplier.id AND order_type = "purchase") >= 
                                (SELECT COALESCE(SUM(total_amount), 0) FROM bk_order 
                                WHERE party_id = bk_supplier.id AND order_type = "purchase")
                            ')
                            ->whereRaw('
                                (SELECT COALESCE(SUM(total_amount), 0) FROM bk_order 
                                WHERE party_id = bk_supplier.id AND order_type = "purchase") > 0
                            ');
                        });
                        break;
                        
                    case 'unpaid':
                        // Suppliers where paid_amount = 0 and total_amount > 0
                        $query->where(function($q) {
                            $q->whereRaw('
                                (SELECT COALESCE(SUM(paid_amount), 0) FROM bk_order 
                                WHERE party_id = bk_supplier.id AND order_type = "purchase") = 0
                            ')
                            ->whereRaw('
                                (SELECT COALESCE(SUM(total_amount), 0) FROM bk_order 
                                WHERE party_id = bk_supplier.id AND order_type = "purchase") > 0
                            ');
                        });
                        break;
                        
                    case 'partially_paid':
                        // Suppliers where 0 < paid_amount < total_amount
                        $query->where(function($q) {
                            $q->whereRaw('
                                (SELECT COALESCE(SUM(paid_amount), 0) FROM bk_order 
                                WHERE party_id = bk_supplier.id AND order_type = "purchase") > 0
                            ')
                            ->whereRaw('
                                (SELECT COALESCE(SUM(paid_amount), 0) FROM bk_order 
                                WHERE party_id = bk_supplier.id AND order_type = "purchase") < 
                                (SELECT COALESCE(SUM(total_amount), 0) FROM bk_order 
                                WHERE party_id = bk_supplier.id AND order_type = "purchase")
                            ');
                        });
                        break;
                }
            }


            if (!empty($search)) {
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('address1', 'like', "%{$search}%")
                    ->orWhere('address2', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%");
                });
            }

            $query->orderBy('id', 'desc');
            $records = $query->paginate((int)$perPage, ['*'], 'page', (int)$page);

            return $this->paginatedResponse($records,'Supplier retrieved successfully');
        } catch (\Exception $e) {
            return static::errorResponse(['Failed to retrieve Supplier', $e->getMessage()], 500);
        }
    }

    public function Store(Request $request)
    {
        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Book-Keeping-Supplier-Insert');
        if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $tenantId = $user->tenant_id;

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'nullable',
            'address1' => 'required|string|max:255',
            'address2' => 'sometimes|nullable|string|max:255',
            'city' => 'required|string|max:255',
            'state_id' => 'required|integer|exists:states,id',
            'pincode' => 'required|integer',
            'gst_number' => 'nullable|string|max:15',
            'email' => 'nullable',
        ]);

        if ($validator->fails()) {
            return static::errorResponse($validator->errors()->all(), 422);
        }

        if (bksupplier::where('tenant_id', $tenantId)->where('company_id', $activeCompanyId)->where('name', $request->name)->exists()) {
            return static::errorResponse(['Supplier already exists for this User'], 409);
        }


        DB::beginTransaction();
        try {
            $data = [
                'tenant_id' => $tenantId,
                'company_id' => $activeCompanyId,
                'name' => $request->name,
                'phone' => $request->phone,
                'address1' => $request->address1,
                'address2' => $request->address2,
                'city' => $request->city,
                'state_id' => $request->state_id,
                'pincode' => $request->pincode,
                'gst_number' => $request->gst_number,
                'email' => $request->email
            ];

            $record = bksupplier::create($data);
            DB::commit();

            return $this->Show($request, $record->id);
        } catch (\Exception $e) {
            DB::rollBack();
            return static::errorResponse(['Failed to create record', $e->getMessage()], 500);
        }
    }

    public function Show(Request $request, $id)
    {
         $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Book-Keeping-Supplier-Show');
        if ($permissionResponse) return $permissionResponse;
        
        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $tenantId = $user->tenant_id;

        try {
            $record = bksupplier::where('tenant_id', $tenantId)->where('company_id', $activeCompanyId)->whereNull('deleted_at')->find($id);

            if (!$record) {
                return static::errorResponse(['Invalid ID'], 404);
            }

            return static::successResponse(['record' => $record], 'Record fetched');
        } catch (\Exception $e) {
            return static::errorResponse(['Failed to retrieve record', $e->getMessage()], 500);
        }
    }

    public function Update(Request $request, $id)
    {
        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Book-Keeping-Supplier-Update');
        if ($permissionResponse) return $permissionResponse;
        
        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $tenantId = $user->tenant_id;

         $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'nullable',
            'address1' => 'required|string|max:255',
            'address2' => 'sometimes|nullable|string|max:255',
            'city' => 'required|string|max:255',
            'state_id' => 'required|integer|exists:states,id',
            'pincode' => 'required|integer',
            'gst_number' => 'nullable|string|max:15',
            'email' => 'nullable',
        ]);

        if ($validator->fails()) {
            return static::errorResponse($validator->errors()->all(), 422);
        }

        DB::beginTransaction();
        try {
            $record = bksupplier::where('tenant_id', $tenantId)->where('company_id', $activeCompanyId)->whereNull('deleted_at')->find($id);

            if (!$record) {
                return static::errorResponse(['Invalid ID'], 404);
            }

            if ($request->filled('name') || $request->name === null || $request->name === '') {
                $record->name = $request->name;
            }

            if ($request->filled('phone') || $request->phone === null || $request->phone === '') {
                $record->phone = $request->phone;
            }

             if ($request->filled('gst_number') || $request->gst_number === null || $request->gst_number === '') {
                $record->gst_number = $request->gst_number;
            }

            if ($request->filled('email') || $request->email === null || $request->email === '') {
                $record->email = $request->email;
            }
            
            if ($request->filled('address1') || $request->address1 === null || $request->address1 === '') {
                $record->address1 = $request->address1;
            }

            
            if ($request->filled('address2') || $request->address2 === null || $request->address2 === '') {
                $record->address2 = $request->address2;
            }

             if ($request->filled('city') || $request->city === null || $request->city === '') {
                $record->city = $request->city;
            }
            

            if ($request->filled('state_id') || $request->state_id === null || $request->state_id === '') {
                $record->state_id = $request->state_id ;
            }

            if ($request->filled('pincode') || $request->pincode === null || $request->pincode === '') {
                $record->pincode = $request->pincode;
            }

    

            $record->save();

            DB::commit();
            return $this->Show($request, $id);
        } catch (\Exception $e) {
            DB::rollBack();
            return static::errorResponse(['Failed to update record', $e->getMessage()], 500);
        }
    }

    public function Destroy(Request $request, $id)
    {
        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Book-Keeping-Supplier-Delete');
        if ($permissionResponse) return $permissionResponse;
        
        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $tenantId = $user->tenant_id;

        try {
            $record = bksupplier::where('tenant_id', $tenantId)->where('company_id', $activeCompanyId)->whereNull('deleted_at')->find($id);

            if (!$record) {
                return static::errorResponse(['Invalid ID'], 404);
            }

            $record->deleted_at = now();
            $record->save();
        
            return static::successResponse(null, 'Record deleted successfully');
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
            return static::errorResponse(['Failed to delete record', $e->getMessage()], 500);
        }
    }

  
}
