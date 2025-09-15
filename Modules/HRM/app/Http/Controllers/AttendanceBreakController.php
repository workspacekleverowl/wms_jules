<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Models\AttendanceBreak;
use App\Models\AttendanceRecord;
use Illuminate\Validation\Rule;

class AttendanceBreakController extends ApiController
{
    /**
     * Display a listing of breaks for a specific attendance record.
     */
    public function index(Request $request, $recordId): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        try {
            // First, verify the parent attendance record exists and belongs to the user's scope
            $recordExists = AttendanceRecord::where('id', $recordId)
                ->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->exists();

            if (!$recordExists) {
                return $this->errorResponse('Attendance record not found', 404);
            }

            // Fetch the breaks for that record
            $breaks = AttendanceBreak::where('attendance_record_id', $recordId)
                ->orderBy('break_start_time')
                ->get();

            return $this->successResponse($breaks, 'Attendance breaks retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve attendance breaks: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Store and synchronize breaks for an attendance record.
     * This method will delete all existing breaks for the given attendance_record_id
     * and create new ones from the provided 'breaks' array.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        // Step 1: Validate that the attendance_record_id exists and is valid
        $preValidator = Validator::make($request->all(), [
            'attendance_record_id' => [
                'required',
                'integer',
                Rule::exists('attendance_records', 'id')->where(function ($query) use ($tenantId, $activeCompanyId) {
                    $query->where('tenant_id', $tenantId)->where('company_id', $activeCompanyId);
                }),
            ],
            'breaks' => 'required|array|min:1',
        ]);

        if ($preValidator->fails()) {
            return $this->errorResponse($preValidator->errors()->all(), 422);
        }

        // Step 2: Fetch the parent record to get its time boundaries
        $record = AttendanceRecord::find($request->attendance_record_id);

        if (empty($record->check_in_time) || empty($record->check_out_time)) {
            return $this->errorResponse('Attendance record must have both check-in and check-out times to add breaks.', 400);
        }

        // Step 3: Validate the array of breaks against the record's time boundaries
        $validator = Validator::make($request->all(), [
            'breaks.*.break_start_time' => [
                'required',
                'date_format:H:i:s',
                'distinct', // Ensures start times are unique within the submitted array
                "after_or_equal:{$record->check_in_time}",
                "before:{$record->check_out_time}",
            ],
            'breaks.*.break_end_time' => [
                'required',
                'date_format:H:i:s',
                'after:breaks.*.break_start_time',
                "before_or_equal:{$record->check_out_time}",
            ],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->all(), 422);
        }

        // Step 4: Manually check for overlapping time periods within the submitted breaks
        $breaks = collect($request->breaks)->sortBy('break_start_time')->values();
        for ($i = 1; $i < $breaks->count(); $i++) {
            if ($breaks[$i]['break_start_time'] < $breaks[$i - 1]['break_end_time']) {
                return $this->errorResponse("Break times cannot overlap. The break starting at {$breaks[$i]['break_start_time']} overlaps with the previous one.", 422);
            }
        }
        
        DB::beginTransaction();
        try {
            $attendanceRecordId = $request->attendance_record_id;

            // Delete all existing breaks for this attendance record
            AttendanceBreak::where('attendance_record_id', $attendanceRecordId)->delete();

            $createdBreaks = [];
            // Create the new breaks from the validated data
            foreach ($breaks as $breakData) {
                $createdBreaks[] = AttendanceBreak::create([
                    'attendance_record_id' => $attendanceRecordId,
                    'break_start_time'     => $breakData['break_start_time'],
                    'break_end_time'       => $breakData['break_end_time'],
                    'tenant_id'            => $tenantId,
                    'company_id'           => $activeCompanyId,
                ]);
            }

            DB::commit();
            
            return $this->successResponse($createdBreaks, 'Attendance breaks synchronized successfully', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Failed to synchronize attendance breaks: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Display the specified break.
     */
    public function show(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        try {
            $break = AttendanceBreak::where('id', $id)
                ->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->first();

            if (!$break) {
                return $this->errorResponse('Attendance break not found', 404);
            }

            return $this->successResponse($break, 'Attendance break retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve attendance break: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update the specified break.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        DB::beginTransaction();
        try {
            $break = AttendanceBreak::where('id', $id)
                ->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->first();

            if (!$break) {
                DB::rollBack();
                return $this->errorResponse('Attendance break not found', 404);
            }

            // Fetch the parent attendance record to validate against its time boundaries
            $record = $break->attendanceRecord;
            if (empty($record->check_in_time) || empty($record->check_out_time)) {
                DB::rollBack();
                return $this->errorResponse('Parent attendance record must have check-in and check-out times.', 400);
            }

            $validator = Validator::make($request->all(), [
                'break_start_time' => [
                    'sometimes',
                    'required',
                    'date_format:H:i:s',
                    "after_or_equal:{$record->check_in_time}",
                    "before:{$record->check_out_time}",
                    Rule::unique('attendance_breaks')
                        ->where('attendance_record_id', $break->attendance_record_id)
                        ->ignore($id)
                ],
                'break_end_time' => [
                    'sometimes',
                    'required',
                    'date_format:H:i:s',
                    "before_or_equal:{$record->check_out_time}"
                ],
            ]);

            if ($validator->fails()) {
                DB::rollBack();
                return $this->errorResponse($validator->errors()->all(), 422);
            }
            
            $validatedData = $validator->validated();

            // Manually check that break_end_time is after break_start_time
            $newStartTime = $validatedData['break_start_time'] ?? $break->break_start_time;
            $newEndTime = $validatedData['break_end_time'] ?? $break->break_end_time;

            if (strtotime($newEndTime) <= strtotime($newStartTime)) {
                DB::rollBack();
                return $this->errorResponse('Break end time must be after the break start time.', 422);
            }

            // Manually check for overlaps with other existing breaks
            $otherBreaks = AttendanceBreak::where('attendance_record_id', $break->attendance_record_id)
                ->where('id', '!=', $id)
                ->get();
            
            foreach ($otherBreaks as $otherBreak) {
                if ($newStartTime < $otherBreak->break_end_time && $newEndTime > $otherBreak->break_start_time) {
                    DB::rollBack();
                    return $this->errorResponse("Time overlaps with an existing break ({$otherBreak->break_start_time} - {$otherBreak->break_end_time}).", 422);
                }
            }

            $break->update($validatedData);
            DB::commit();

            return $this->successResponse($break, 'Attendance break updated successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Failed to update attendance break: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Remove the specified break.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        try {
            $break = AttendanceBreak::where('id', $id)
                ->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->first();

            if (!$break) {
                return $this->errorResponse('Attendance break not found', 404);
            }

            $break->delete();

            return $this->successResponse(null, 'Attendance break deleted successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to delete attendance break: ' . $e->getMessage(), 500);
        }
    }
}
