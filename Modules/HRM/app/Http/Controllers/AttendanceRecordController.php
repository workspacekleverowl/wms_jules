<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Models\AttendanceRecord;

class AttendanceRecordController extends ApiController
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        // $permissionResponse = $this->checkPermission('HRM-Attendance-Show');
        // if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        try {
            $perPage = $request->input('per_page', 10);

            $query = AttendanceRecord::with(['employee', 'breaks'])
                ->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId);

            // Apply filters
            if ($request->filled('employee_id')) {
                $query->where('employee_id', $request->employee_id);
            }
            if ($request->filled('start_date')) {
                $query->where('attendance_date', '>=', $request->start_date);
            }
            if ($request->filled('end_date')) {
                $query->where('attendance_date', '<=', $request->end_date);
            }
             if ($request->filled('search')) {
                $search = $request->search;
                $query->whereHas('employee', function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%");
                });
            }

            $records = $query->orderBy('attendance_date', 'desc')->paginate($perPage);

            return $this->paginatedResponse($records, 'Attendance records retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve attendance records: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        // $permissionResponse = $this->checkPermission('HRM-Attendance-Insert');
        // if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        $validator = $this->validateAttendanceRecord($request, 'store', null, $tenantId, $activeCompanyId);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->all(), 422);
        }

        DB::beginTransaction();
        try {
            $validatedData = $validator->validated();
            $validatedData['tenant_id'] = $tenantId;
            $validatedData['company_id'] = $activeCompanyId;
            
            $record = AttendanceRecord::create($validatedData);
            DB::commit();

            return $this->show($request, $record->id);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Failed to create attendance record: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, $id): JsonResponse
    {
        // $permissionResponse = $this->checkPermission('HRM-Attendance-Show');
        // if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        try {
            $record = AttendanceRecord::with(['employee', 'breaks'])
                ->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($id);

            if (!$record) {
                return $this->errorResponse('Attendance record not found', 404);
            }

            return $this->successResponse($record, 'Attendance record retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve attendance record: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id): JsonResponse
    {
        // $permissionResponse = $this->checkPermission('HRM-Attendance-Update');
        // if ($permissionResponse) return $permissionResponse;
        
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        DB::beginTransaction();
        try {
            $record = AttendanceRecord::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($id);

            if (!$record) {
                return $this->errorResponse('Attendance record not found', 404);
            }

            $validator = $this->validateAttendanceRecord($request, 'update', $id, $tenantId, $activeCompanyId, $record);

            if ($validator->fails()) {
                return $this->errorResponse($validator->errors()->all(), 422);
            }

            $record->update($validator->validated());
            DB::commit();

            return $this->show($request, $id);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Failed to update attendance record: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        // $permissionResponse = $this->checkPermission('HRM-Attendance-Delete');
        // if ($permissionResponse) return $permissionResponse;

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        try {
            $record = AttendanceRecord::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($id);

            if (!$record) {
                return $this->errorResponse('Attendance record not found', 404);
            }

            $record->delete();

            return $this->successResponse(null, 'Attendance record deleted successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to delete attendance record: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Private validation helper method.
     */
    private function validateAttendanceRecord(Request $request, string $method, ?int $recordId, string $tenantId, int $companyId, $existingRecord = null)
    {
        $isPost = $method === 'store';
        $required = $isPost ? 'required' : 'sometimes';

        // For updates, use the request's employee_id or fall back to the existing one.
        $employeeId = $request->input('employee_id', $existingRecord->employee_id ?? null);
        $attendanceDate = $request->input('attendance_date', $existingRecord->attendance_date ?? null);
        
        $rules = [
            'employee_id' => [
                $required,
                'integer',
                "exists:employees,id,tenant_id,{$tenantId},company_id,{$companyId}"
            ],
            'attendance_date' => [
                $required,
                'date',
                // Prevent duplicate entries for the same employee on the same day.
                Rule::unique('attendance_records')->where(function ($query) use ($employeeId, $attendanceDate) {
                    return $query->where('employee_id', $employeeId)->where('attendance_date', $attendanceDate);
                })->ignore($recordId),
            ],
            'check_in_time' => 'nullable|date_format:H:i:s',
            'check_out_time' => 'nullable|date_format:H:i:s|after_or_equal:check_in_time',
            'status' => [$required, Rule::in(['Present', 'Absent', 'Leave', 'Holiday'])],
            'notes' => 'nullable|string',
        ];

        return Validator::make($request->all(), $rules);
    }
}