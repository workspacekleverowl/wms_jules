<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\Employee;
use App\Models\EmployeeDocument;

class EmployeeDocumentController extends ApiController
{
    public function index(Request $request, $employee_id): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        try {
            $employee = Employee::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($employee_id);

            if (!$employee) {
                return $this->errorResponse('Employee not found', 404);
            }

            $documents = $employee->documents()->latest()->get();
            return $this->successResponse($documents, 'Documents retrieved successfully.');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve documents: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request, $employee_id): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        $validator = Validator::make($request->all(), [
            'document_type' => 'required|string|max:100',
            'file' => 'required|file|mimes:pdf,docx,jpg,png|max:5120',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->all(), 422);
        }

        DB::beginTransaction();
        try {
            $employee = Employee::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($employee_id);

            if (!$employee) {
                return $this->errorResponse('Employee not found', 404);
            }

            $file = $request->file('file');
            $path = $file->store("employee_documents/{$tenantId}/{$employee->id}");

            $document = $employee->documents()->create([
                'document_type' => $request->document_type,
                'file_path' => $path,
                'tenant_id' => $tenantId,
                'company_id' => $activeCompanyId,
            ]);

            DB::commit();

            return $this->successResponse($document, 'Document uploaded successfully.', 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Failed to upload document: ' . $e->getMessage(), 500);
        }
    }

    public function destroy(Request $request, $document_id): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        try {
            $document = EmployeeDocument::where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->find($document_id);

            if (!$document) {
                return $this->errorResponse('Document not found', 404);
            }

            $document->delete();

            return $this->successResponse(null, 'Document deleted successfully.');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to delete document: ' . $e->getMessage(), 500);
        }
    }
}
