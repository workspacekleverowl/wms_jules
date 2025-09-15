<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Models\Session;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ApiController extends Controller
{
    //this are the standard functions for response
   
   /**
     * Return a success response.
     *
     * @param mixed $data
     * @param string|array $message
     * @param int $statusCode
     * @param int|null $internalCode
     * @return JsonResponse
     */
    public static function successResponse($data = null, $message = 'Success', int $statusCode = 200, ?int $internalCode = null): JsonResponse
    {
        // Convert single string message to array for consistency
        if (!is_array($message)) {
            $message = [$message];
        }

        // Ensure data is always an object, never null
        if ($data === null) {
            $data = (object) [];
        }
        
        $response = [
            'status' => $internalCode ?? $statusCode,
            'message' => $message,
            'data' => $data,
        ];

        return response()->json($response, $statusCode);
    }

    /**
     * Return an error response with HTTP 200 but internal error code.
     *
     * @param string|array $message
     * @param int $internalCode
     * @param mixed $data
     * @return JsonResponse
     */
    public static function errorResponse($message = 'Error', int $internalCode = 400, $data = null): JsonResponse
    {
        // Convert single string message to array for consistency
        if (!is_array($message)) {
            $message = [$message];
        }

        if ($data === null) {
            $data = (object) [];
        }
        
        
        $response = [
            'status' => $internalCode,
            'message' => $message,
            'data' => $data,
        ];

        // Always return HTTP 200 but with internal status code
        return response()->json($response, $internalCode);
    }

    /**
     * Handle paginated response.
     *
     * @param LengthAwarePaginator $paginator
     * @param string $message
     * @param int $statusCode
     * @return JsonResponse
     */
    protected function paginatedResponse(LengthAwarePaginator $paginator, string $message = 'Success', int $statusCode = 200): JsonResponse
    {
        $responseData = [
            'records' => $paginator->items()
        ];

        // Add pagination info to headers
        return $this->successResponse($responseData, $message, $statusCode)
            ->withHeaders([
                'X-Page' => $paginator->currentPage(),
                'X-Per-Page' => $paginator->perPage(),
                'X-Total-Count' => $paginator->total(),
                'X-Total-Pages' => $paginator->lastPage(),
                'Access-Control-Expose-Headers' => 'X-Page, X-Per-Page, X-Total-Count, X-Total-Pages'
            ]);
    }

    /**
     * Return an error response with HTTP 200 but internal error code.
     *
     * @param string|array $message
     * @param int $internalCode
     * @param mixed $data
     * @return JsonResponse
     */
    public static function errorResponse404($message = 'Error', int $internalCode = 400, $data = null): JsonResponse
    {
        // Convert single string message to array for consistency
        if (!is_array($message)) {
            $message = [$message];
        }

        if ($data === null) {
            $data = (object) [];
        }
        
        
        $response = [
            'status' => $internalCode,
            'message' => $message,
            'data' => $data,
        ];

       
        return response()->json($response, 404);
    }

    /**
     * Return an error response with HTTP 200 but internal error code.
     *
     * @param string|array $message
     * @param int $internalCode
     * @param mixed $data
     * @return JsonResponse
     */
    public static function errorResponse401($message = 'Error', int $internalCode = 400, $data = null): JsonResponse
    {
        // Convert single string message to array for consistency
        if (!is_array($message)) {
            $message = [$message];
        }

        if ($data === null) {
            $data = (object) [];
        }
        
        
        $response = [
            'status' => $internalCode,
            'message' => $message,
            'data' => $data,
        ];

      
        return response()->json($response, 401);
    }

    /**
     * Return an error response with HTTP 200 but internal error code.
     *
     * @param string|array $message
     * @param int $internalCode
     * @param mixed $data
     * @return JsonResponse
     */
    public static function errorResponse403($message = 'Error', int $internalCode = 400, $data = null): JsonResponse
    {
        // Convert single string message to array for consistency
        if (!is_array($message)) {
            $message = [$message];
        }

        if ($data === null) {
            $data = (object) [];
        }
        
        
        $response = [
            'status' => $internalCode,
            'message' => $message,
            'data' => $data,
        ];

      
        return response()->json($response, 403);
    }

   
}
