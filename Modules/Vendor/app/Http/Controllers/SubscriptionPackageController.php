<?php

namespace Modules\Vendor\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Validator;
use App\Models\SubscriptionPackage;
use App\Models\SubscriptionPackageDetail;
use Illuminate\Support\Facades\DB;
use Razorpay\Api\Api;
use Exception;

class SubscriptionPackageController extends ApiController
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
     * Display a listing of the subscription packages.
     */
    public function index()
    {
        try {
            $packages = SubscriptionPackage::with('details')->get();
            return static::successResponse($packages, 'Subscription packages retrieved successfully.');
        } catch (Exception $e) {
            return static::errorResponse(['Failed to retrieve subscription packages', $e->getMessage()], 500);
        }
    }

    /**
     * Store a newly created subscription package in storage.
     * This method now creates the Razorpay plan automatically.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:subscription_packages,name',
            'price_in_cents' => 'required|integer|min:0',
            'duration' => 'required|string|in:monthly,yearly',
            'is_free' => 'boolean',
            'ai_features' => 'boolean',
        ]);

        if ($validator->fails()) {
            return static::errorResponse($validator->errors()->all(), 422);
        }

        DB::beginTransaction();
        try {
            $razorpay_plan_id = null;
            $duration_days = 0;
            $razorpay_period = '';

            // Map duration string to integer days and Razorpay period
            if ($request->input('duration') === 'monthly') {
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
                        'amount' => $request->input('price_in_cents') * 100,
                        'currency' => 'INR', // Set your currency
                        'description' => 'Subscription to ' . $request->input('name') . ' plan',
                    ]
                ];

                // Create the plan in Razorpay's system.
                $razorpayPlan = $this->razorpay->plan->create($planData);
                $razorpay_plan_id = $razorpayPlan->id;
            }

            // Create the subscription package record with both duration fields
            $package = SubscriptionPackage::create([
                'name' => $request->input('name'),
                'price_in_cents' => $request->input('price_in_cents'),
                'duration' => $request->input('duration'),
                'duration_days' => $duration_days, // Save the calculated days
                'is_free' => $request->boolean('is_free', false),
                'razorpay_plan_id' => $razorpay_plan_id,
            ]);
            
            // Create the associated details record.
            SubscriptionPackageDetail::create([
                'subscription_package_id' => $package->id,
                'ai_features' => $request->boolean('ai_features', false),
            ]);

            DB::commit();
            return static::successResponse($package->load('details'), 'Subscription package created successfully.', 201);
        } catch (Exception $e) {
            DB::rollBack();
            return static::errorResponse(['Failed to create subscription package', $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified subscription package.
     */
    public function show(string $id)
    {
        try {
            $package = SubscriptionPackage::with('details')->find($id);

            if (!$package) {
                return static::errorResponse(['Subscription package not found.'], 404);
            }

            return static::successResponse($package, 'Subscription package retrieved successfully.');
        } catch (Exception $e) {
            return static::errorResponse(['Failed to retrieve subscription package', $e->getMessage()], 500);
        }
    }

    /**
     * Update the specified subscription package.
     */
    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'ai_features' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return static::errorResponse($validator->errors()->all(), 422);
        }

        DB::beginTransaction();
        try {
            $package = SubscriptionPackage::find($id);

            if (!$package) {
                return static::errorResponse(['Subscription package not found.'], 404);
            }

            $package->details->update([
                'ai_features' => $request->boolean('ai_features'),
            ]);

            DB::commit();
            return static::successResponse($package->load('details'), 'Subscription package updated successfully.');
        } catch (Exception $e) {
            DB::rollBack();
            return static::errorResponse(['Failed to update subscription package', $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified subscription package from storage.
     */
    public function destroy(string $id)
    {
        DB::beginTransaction();
        try {
            $package = SubscriptionPackage::find($id);

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
