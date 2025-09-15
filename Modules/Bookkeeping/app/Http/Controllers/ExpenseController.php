<?php

namespace Modules\Bookkeeping\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Models\bkexpense;
use App\Models\bkexpensetype;
use App\Models\State;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log; 

class ExpenseController extends ApiController
{
    public function bkexpensetypeindex(Request $request)
    {

        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Book-Keeping-Expense-Type-Show');
        if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $Id = $request->user()->tenant_id;

        try {
            $perPage = $request->input('per_page', 10);
            $page = $request->input('page', 1);
            $query = bkexpensetype::where('tenant_id', $Id)->where('company_id', $activeCompanyId);
            $search = $request->input('search');

             // Get the authenticated user
             $user = auth()->user();

            if ($search !== null && $search !== '')
            {
                $query->where('expensetype_name', 'like', "%{$search}%");
            }

            $query->orderBy('id', 'desc');
            
           

            $expensetype = $query->paginate((int)$perPage, ['*'], 'page', (int)$page);
            return $this->paginatedResponse($expensetype, 'expense type retrieved successfully');
        } catch (\Exception $e) {
            return static::errorResponse(['Failed to retrieve expense type', $e->getMessage()], 500);
        }
    }

   
    public function bkexpensetypestore(Request $request)
    {
        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Book-Keeping-Expense-Type-Insert');
        if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $Id = $request->user()->tenant_id;

        $validator = Validator::make($request->all(), [
            'expensetype_name' => 'required'
            
        ]);

        if ($validator->fails()) {
            return static::errorResponse($validator->errors()->all(), 422);
        }

        DB::beginTransaction();
        try {
           
            $Data = [
                'tenant_id' => $Id,
                'company_id' => $activeCompanyId,
                'expensetype_name' =>  $request->expensetype_name??null
            ];

            $bkexpensetype = bkexpensetype::create($Data);

            DB::commit();
            return $this->bkexpensetypeshow($request,$bkexpensetype->id);
        } catch (\Exception $e) {
            DB::rollBack();
            return static::errorResponse(['Failed to create expense type', $e->getMessage()], 500);
        }
    }


   
    public function bkexpensetypeshow(Request $request, $id)
    {
        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Book-Keeping-Expense-Type-Show');
        if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $Id = $request->user()->tenant_id;

        try {
            $expensetype = bkexpensetype::where('tenant_id', $Id)->where('company_id', $activeCompanyId)->find($id); 

            if(!$expensetype) {
                return static::errorResponse(['Invalid expense type ID'], 404);
            }

           
            return static::successResponse([
                'expensetype' => $expensetype
            ], 'Expense type Fetched');
        } catch (\Exception $e) {
            return static::errorResponse(['Failed to retrieve expense type', $e->getMessage()], 500);
        }
    }

    public function bkexpensetypeupdate(Request $request, $id)
    {
        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Book-Keeping-Expense-Type-Update');
        if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $Id = $request->user()->tenant_id;

       

        $validator = Validator::make($request->all(), [
            'expensetype_name' => 'required'
            
        ]);

        if ($validator->fails()) {
            return static::errorResponse($validator->errors()->all(), 422);
        }
    

        DB::beginTransaction(); // Start transaction
    
        try {
            $expensetype = bkexpensetype::where('tenant_id', $Id)->where('company_id', $activeCompanyId)->find($id); 

            if(!$expensetype) {
                return static::errorResponse(['Invalid expense type ID'], 404);
            }

            $fieldsToUpdate = [];

    
            
            if ($request->filled('expensetype_name') || $request->expensetype_name === null || $request->expensetype_name === '') {
                $fieldsToUpdate['expensetype_name'] = $request->expensetype_name;
            }
    
            // Update order with provided fields
            $expensetype->update($fieldsToUpdate);

            DB::commit(); // Commit transaction if everything is successful

           
            return $this->bkexpensetypeshow($request, $id);
    
        } catch (\Exception $e) {
            DB::rollBack(); // Rollback transaction on failure
            return static::errorResponse(['Failed to update expense type', $e->getMessage()], 500);
        }
    }

    public function bkexpensetypedestroy(Request $request, $id)
    {
        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Book-Keeping-Expense-Type-Delete');
        if ($permissionResponse) return $permissionResponse;
        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $Id = $request->user()->tenant_id;


        try {
            $expensetype = bkexpensetype::where('tenant_id', $Id)->where('company_id', $activeCompanyId)->find($id); 

            if(!$expensetype) {
                return static::errorResponse(['Invalid expense type ID'], 404);
            }

            $expensetype->delete();

        

            return static::successResponse(null, 'expense type deleted successfully');
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
            return static::errorResponse(['Failed to delete expense type', $e->getMessage()], 500);
        }
    }

    public function bkexpensetypefetch(Request $request)
    {

        $authHeader = $request->header('Authorization');
       
        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $Id = $request->user()->tenant_id;

        try {
            $query = bkexpensetype::where('tenant_id', $Id)->where('company_id', $activeCompanyId);
            $search = $request->input('search');

             // Get the authenticated user
             $user = auth()->user();

            if ($search !== null && $search !== '')
            {
                $query->where('expensetype_name', 'like', "%{$search}%");
            }

            $query->orderBy('id', 'desc');
            
           

            $expensetype = $query->select('id', 'expensetype_name')->get();
            return $this->successResponse($expensetype, 'expense type retrieved successfully');
        } catch (\Exception $e) {
            return static::errorResponse(['Failed to retrieve expense type', $e->getMessage()], 500);
        }
    }

    public function bkexpenseindex(Request $request)
    {

        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Book-Keeping-Expense-Transactions-Show');
        if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $Id = $request->user()->tenant_id;

        try {
            $perPage = $request->input('per_page', 10);
            $page = $request->input('page', 1);
            $query = bkexpense::with('expenseType')->where('tenant_id', $Id)->where('company_id', $activeCompanyId);
            $search = $request->input('search');

             // Get the authenticated user
             $user = auth()->user();

             if ($search !== null && $search !== '') {
                // Search by amount OR by joining with expense type table to search by name
                $query->where(function($q) use ($search) {
                    $q->where('amount', 'like', "%{$search}%")
                    ->orWhereHas('expenseType', function($query) use ($search) {
                        $query->where('expensetype_name', 'like', "%{$search}%");
                    });
                });
            }

            $query->orderBy('id', 'desc');
            
           

            $expense = $query->paginate((int)$perPage, ['*'], 'page', (int)$page);
            return $this->paginatedResponse($expense, 'expense retrieved successfully');
        } catch (\Exception $e) {
            return static::errorResponse(['Failed to retrieve expense', $e->getMessage()], 500);
        }
    }

   
    public function bkexpensestore(Request $request)
    {
        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Book-Keeping-Expense-Transactions-Insert');
        if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $Id = $request->user()->tenant_id;

        $validator = Validator::make($request->all(), [
            'expensetype_id' => 'required|integer|exists:bk_expensetype,id',
            'amount' =>'required',
            'date' => 'required|date',
            'expence_title' =>'nullable'
            
        ]);

        if ($validator->fails()) {
            return static::errorResponse($validator->errors()->all(), 422);
        }

        DB::beginTransaction();
        try {
           
            $Data = [
                'tenant_id' => $Id,
                'company_id' => $activeCompanyId,
                'expensetype_id' =>  $request->expensetype_id??null,
                'amount' => $request->amount??null,
                'date' =>  $request->date??null,
                'expence_title' =>$request->expence_title??null,
            ];

            $bkexpense = bkexpense::create($Data);

            DB::commit();
            return $this->bkexpenseshow($request,$bkexpense->id);
        } catch (\Exception $e) {
            DB::rollBack();
            return static::errorResponse(['Failed to create expense', $e->getMessage()], 500);
        }
    }


   
    public function bkexpenseshow(Request $request, $id)
    {
        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Book-Keeping-Expense-Transactions-Show');
        if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $Id = $request->user()->tenant_id;

        try {
            $expense = bkexpense::with('expenseType')->where('tenant_id', $Id)->where('company_id', $activeCompanyId)->find($id); 

            if(!$expense) {
                return static::errorResponse(['Invalid expense ID'], 404);
            }

           
            return static::successResponse([
                'expense' => $expense
            ], 'Expense Fetched');
        } catch (\Exception $e) {
            return static::errorResponse(['Failed to retrieve expense', $e->getMessage()], 500);
        }
    }

    public function bkexpenseupdate(Request $request, $id)
    {
        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Book-Keeping-Expense-Transactions-Update');
        if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $Id = $request->user()->tenant_id;

       

        $validator = Validator::make($request->all(), [
            'expensetype_id' => 'nullable|integer|exists:bk_expensetype,id',
            'amount' =>'nullable',
            'date' => 'nullable|date',
            'expence_title' =>'nullable'
            
        ]);

        if ($validator->fails()) {
            return static::errorResponse($validator->errors()->all(), 422);
        }
    

        DB::beginTransaction(); // Start transaction
    
        try {
            $expense = bkexpense::where('tenant_id', $Id)->where('company_id', $activeCompanyId)->find($id); 

            if(!$expense) {
                return static::errorResponse(['Invalid expense ID'], 404);
            }

            $fieldsToUpdate = [];

    
            
            if ($request->filled('expensetype_id') || $request->expensetype_id === null || $request->expensetype_id === '') {
                $fieldsToUpdate['expensetype_id'] = $request->expensetype_id;
            }

            if ($request->filled('amount') || $request->amount === null || $request->amount === '') {
                $fieldsToUpdate['amount'] = $request->amount;
            }

            if ($request->filled('expence_title') || $request->expence_title === null || $request->expence_title === '') {
                $fieldsToUpdate['expence_title'] = $request->expence_title;
            }

            if ($request->filled('date') || $request->date === null || $request->date === '') {
                $fieldsToUpdate['date'] = $request->date;
            }
    
            // Update order with provided fields
            $expense->update($fieldsToUpdate);

            DB::commit(); // Commit transaction if everything is successful

           
            return $this->bkexpenseshow($request, $id);
    
        } catch (\Exception $e) {
            DB::rollBack(); // Rollback transaction on failure
            return static::errorResponse(['Failed to update expense', $e->getMessage()], 500);
        }
    }

    public function bkexpensedestroy(Request $request, $id)
    {
        $authHeader = $request->header('Authorization');
        $permissionResponse = $this->checkPermission('Book-Keeping-Expense-Transactions-Delete');
        if ($permissionResponse) return $permissionResponse;
        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        $Id = $request->user()->tenant_id;


        try {
            $expense = bkexpense::where('tenant_id', $Id)->where('company_id', $activeCompanyId)->find($id); 

            if(!$expense) {
                return static::errorResponse(['Invalid expense ID'], 404);
            }

            $expense->delete();

        

            return static::successResponse(null, 'expense deleted successfully');
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
            return static::errorResponse(['Failed to delete expense', $e->getMessage()], 500);
        }
    }


    

}
