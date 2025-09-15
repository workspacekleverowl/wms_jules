<?php

namespace Modules\Masters\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\transporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class TransporterController extends Controller
{
  // Create a new transporter
  public function store(Request $request)
  {
        $response = $this->checkPermission('Transporter-Insert');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }
      $validator = Validator::make($request->all(), [
          'name' => 'nullable|string|max:255',
          'phone' => [
                  'sometimes',
                  'nullable',
                  'string',
                  'max:10',
                  'min:10',
                  'regex:/^\d{10}$/',
                  Rule::unique('users', 'phone')->ignore($request->user()->id),
              ],
          'vehicle_number' => 'required|string|max:255',
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
      if (transporter::where('tenant_id', $Id)->where('vehicle_number', $request->vehicle_number)->exists()) {
          return response()->json([
              'status' => 409,
              'message' => 'Vehicle Number already exists for this User',
          ], 409);
      }

      $transporter = transporter::create([
          'tenant_id' => $Id,
          'name' => $request->name,
          'phone' => $request->phone,
          'vehicle_number' => $request->vehicle_number,
      ]);

      return response()->json([
          'status' => 200,
          'message' => 'Transporter created successfully',
          'record' => $transporter,
      ], 200);
  }

  // Retrieve all transporters for the authenticated tenant
  public function index(Request $request)
  {
        $response = $this->checkPermission('Transporter-Show');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }
      $Id = $request->user()->tenant_id;

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
              'error' => $validator->errors(),
          ], 422);
      }

      // Get pagination parameters or default values
      $page = $request->input('page', 1);
      $perPage = $request->input('per_page', 10);

      try {
          // Query transporters for the authenticated user
          $query = transporter::where('tenant_id', $Id);

          // Apply search filter
          if ($request->has('search') && !empty($request->search)) {
              $searchTerm = $request->search;
              $query->where(function ($q) use ($searchTerm) {
                  $q->where('name', 'like', '%' . $searchTerm . '%')
                  ->orWhere('phone', 'like', '%' . $searchTerm . '%')
                  ->orWhere('vehicle_number', 'like', '%' . $searchTerm . '%');
              });
          }

          // Get total records count
          $totalRecords = $query->count();

          // Apply pagination
          $transporters = $query->skip(($page - 1) * $perPage)
              ->take($perPage)
              ->get();

          if ($transporters->isEmpty()) {
              return response()->json([
                  'status' => 200,
                  'message' => 'No transporters found',
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
              'message' => 'Transporters retrieved successfully',
              'pagination' => [
                  'page' => $page,
                  'per_page' => $perPage,
                  'total_records' => $totalRecords,
              ],
              'record' => $transporters,
          ], 200);
      } catch (\Exception $e) {
          return response()->json([
              'status' => 500,
              'message' => 'Error retrieving transporters',
              'error' => $e->getMessage(),
          ], 500);
      }
  }

  // Retrieve a single transporter
  public function show($id, Request $request)
  {
    $response = $this->checkPermission('Transporter-Show');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

      $Id = $request->user()->tenant_id;
      $transporter = transporter::where('id', $id)->where('tenant_id', $Id)->first();

      if (!$transporter) {
          return response()->json([
              'status' => 200,
              'message' => 'transporter not found',
          ], 200);
      }

      return response()->json([
          'status' => 200,
          'message' => 'transporter retrieved successfully',
          'record' => $transporter,
      ]);
  }

  // Update a transporter
  public function update(Request $request, $id)
  {
    $response = $this->checkPermission('Transporter-Update');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }
      $validator = Validator::make($request->all(), [
          'name' => 'nullable|string|max:255',
          'phone' => [
                  'sometimes',
                  'nullable',
                  'string',
                  'max:10',
                  'min:10',
                  'regex:/^\d{10}$/',
                  Rule::unique('users', 'phone')->ignore($request->user()->id),
              ]
      ]);

      if ($validator->fails()) {
          return response()->json([
              'status' => 422,
              'message' => 'Validation failed',
              'errors' => $validator->errors(),
          ], 422);
      }

      $Id = $request->user()->tenant_id;
      $transporter = transporter::where('id', $id)->where('tenant_id', $Id)->first();

      if (!$transporter) {
          return response()->json([
              'status' => 200,
              'message' => 'transporter not found',
          ], 200);
      }

    //   // Ensure the transporter name is unique within the tenant
    //   if ($request->has('vehicle_number') && transporter::where('tenant_id', $Id)->where('vehicle_number', $request->vehicle_number)->where('id', '!=', $id)->exists()) {
    //       return response()->json([
    //           'status' => 409,
    //           'message' => 'Vehicle number already exists for this user',
    //       ], 409);
    //   }

      $transporter->update($request->all());

      return response()->json([
          'status' => 200,
          'message' => 'transporter updated successfully',
          'record' => $transporter,
      ]);
  }

  // Delete a transporter
  public function destroy($id, Request $request)
  {
    $response = $this->checkPermission('Transporter-Delete');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }
      $Id = $request->user()->tenant_id;
      $transporter = transporter::where('id', $id)->where('tenant_id', $Id)->first();

      if (!$transporter) {
          return response()->json([
              'status' => 200,
              'message' => 'transporter not found',
          ], 200);
      }

    try {
      $transporter->delete();

      return response()->json([
          'status' => 200,
          'message' => 'transporter deleted successfully',
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

    public function changeStatus(Request $request, $id)
    {
        $response = $this->checkPermission('Transporter-Update');
    
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
            $transporter = transporter::where('id', $id)->where('tenant_id',  $tenantId)->first();

            if (!$transporter) {
                return response()->json([
                    'status' => 200,
                    'message' => 'transporter not found',
                ], 200);
            }
            // Update the status
            $transporter->status = $request->status;
            $transporter->save();

           

            return response()->json([
                'status' => 200,
                'message' => 'transporter status updated successfully',
                'record' => $transporter,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Error updating transporter status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
