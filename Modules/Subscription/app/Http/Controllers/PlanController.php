<?php

namespace Modules\Subscription\Http\Controllers;

use App\Http\Controllers\ApiController;
use App\Models\Plan;
use App\Models\plan_details;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Razorpay\Api\Api;
use Exception;

class PlanController extends ApiController
{

    /**
     * Razorpay API client instance.
     *
     * @var \Razorpay\Api\Api
     */
    protected $razorpay;

    public function __construct()
    {
        // Initialize the Razorpay API with your key ID and secret from the .env file.
        $this->razorpay = new Api(env('RAZORPAY_KEY_ID'), env('RAZORPAY_KEY_SECRET'));
    }
    /**
     * Display a listing of the resource.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $plans = Plan::with('details')->where('is_active', true)
                ->paginate($request->get('per_page', 15));

            return $this->paginatedResponse($plans, 'Active plans retrieved successfully.');
        } catch (\Exception $e) {
            return $this->errorResponse('An unexpected error occurred. Please try again later.', 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:plans,name',
            'description' => 'nullable|string|max:1000',
            'price' => 'required|integer|min:1',
            'billing_interval' => 'required|in:monthly,yearly',
            'is_active' => 'boolean',
            'is_free' => 'boolean',
            'ai_features' => 'boolean',
            'priority' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->all(), 422);
        }
        DB::beginTransaction();
        try {
           $razorpay_plan_id = null;
            $duration_days = 0;
            $razorpay_period = '';

            // Map duration string to integer days and Razorpay period
            if ($request->input('billing_interval') === 'monthly') {
                $duration_days = 30;
                $razorpay_period = 'monthly';
            } else {
                $duration_days = 365;
                $razorpay_period = 'yearly';
            }

            // Only create a Razorpay plan if the package is not free.
            if (!$request->boolean('is_free')) {
                // Prepare plan data for Razorpay API.
                $planData = [
                    'period' => $razorpay_period,
                    'interval' => 1,
                    'item' => [
                        'name' => $request->input('name'),
                        'amount' => $request->input('price') * 100,
                        'currency' => 'INR', // Set your currency
                        'description' => 'Subscription to ' . $request->input('name') . ' plan',
                    ]
                ];

                // Create the plan in Razorpay's system.
                $razorpayPlan = $this->razorpay->plan->create($planData);
                $razorpay_plan_id = $razorpayPlan->id;
            }

            // Create the subscription package record with both duration fields
            $plan = Plan::create([
                'name' => $request->input('name'),
                'description' => $request->input('description'),
                'price' => $request->input('price'),
                'billing_interval' => $razorpay_period,
                'is_active' => $request->boolean('is_active', true),
                'is_free' => $request->boolean('is_free', false),
                'razorpay_plan_id' => $razorpay_plan_id,
                'priority' => $request->input('priority', 0),
            ]);
            
            // Create the associated details record.
            plan_details::create([
                'plan_id' => $plan->id,
                'ai_features' => $request->boolean('ai_features', false),
            ]);
            DB::commit();
            return $this->successResponse($plan, 'Plan created successfully.', 201);
        } catch (\Exception $e) {
             DB::rollBack();
              \Log::error('Plan creation failed: '.$e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ]);
            return $this->errorResponse('Failed to create plan. Please try again later.',  500);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $plan = Plan::with('details')->find($id);

            if (!$plan) {
               return static::errorResponse(['Subscription Plan not found.'], 404);
            }

            return $this->successResponse($plan, 'Plan retrieved successfully.');
        } catch (\Exception $e) {
            return $this->errorResponse('An unexpected error occurred. Please try again later.', 500);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'is_active' => 'boolean',
            'ai_features' => 'required|boolean',
            'priority' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return static::errorResponse($validator->errors()->all(), 422);
        }

        DB::beginTransaction();
        try {
            $package = plan::find($id);

            if (!$package) {
                return static::errorResponse(['Subscription Plan not found.'], 404);
            }

            // Update is_active in package (plan table)
            if ($request->has('is_active')) {
                $package->is_active = $request->boolean('is_active');
                $package->save();
            }

            if ($request->has('priority')) {
                $package->priority = $request->input('priority');
                $package->save();
            }



            $package->details->update([
                'ai_features' => $request->boolean('ai_features'),
            ]);

            DB::commit();
            return static::successResponse($package->load('details'), 'Subscription Plan updated successfully.');
        } catch (Exception $e) {
            DB::rollBack();
            return static::errorResponse(['Failed to update subscription Plan', $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $package = Plan::find($id);

            if (!$package) {
                return static::errorResponse(['Subscription package not found.'], 404);
            }
            $package->details->delete();
            $package->delete();
            DB::commit();
            return static::successResponse(null, 'Subscription package deleted successfully.');
        } catch (Exception $e) {
            DB::rollBack();
             if ($e instanceof \Illuminate\Database\QueryException) {
                // Check for foreign key constraint error codes
                if ($e->getCode() == 23000 || strpos($e->getMessage(), 'Integrity constraint violation') !== false || strpos($e->getMessage(), 'foreign key constraint fails') !== false) {
                    return response()->json([
                        'status' => 409,
                        'message' => 'Cannot delete this record because there are linked records associated with it. Please remove all related data first.',
                    ], 200);
                }
            }
            return static::errorResponse(['Failed to delete subscription package', $e->getMessage()], 500);
        }
    }
}
