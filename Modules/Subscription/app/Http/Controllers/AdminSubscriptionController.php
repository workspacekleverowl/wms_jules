<?php

namespace Modules\Subscription\Http\Controllers;

use App\Http\Controllers\ApiController;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Razorpay\Api\Api as RazorpayApi;

class AdminSubscriptionController extends ApiController
{
    /**
     * Display a listing of the resource.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $query = Subscription::with(['user', 'plan']);

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            $subscriptions = $query->latest()->paginate(15);

            return $this->paginatedResponse($subscriptions, 'Subscriptions retrieved successfully.');
        } catch (\Exception $e) {
            return $this->errorResponse('An error occurred while fetching subscriptions.', 500);
        }
    }

    /**
     * Cancel a user's subscription.
     *
     * @param int $id The local subscription ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancel($id)
    {
        try {
            $subscription = Subscription::find($id);

            if (!$subscription) {
                return $this->errorResponse404('Subscription not found.');
            }
            
            if (!$subscription->razorpay_subscription_id) {
                return $this->errorResponse('This subscription does not have a Razorpay ID and cannot be cancelled externally.', 400);
            }

            $api = new RazorpayApi(env('RAZORPAY_KEY'), env('RAZORPAY_SECRET'));
            $api->subscription->cancel($subscription->razorpay_subscription_id);

            // The local status will be updated by the 'subscription.cancelled' webhook.
            return $this->successResponse(null, 'Subscription cancellation initiated successfully.');

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to cancel subscription: ' . $e->getMessage(), 500);
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
            'user_id' => 'required|exists:users,id',
            'plan_id' => 'required|exists:plans,id',
            'start_date' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->all(), 422);
        }

        try {
            $plan = Plan::find($request->plan_id);
            $startDate = $request->filled('start_date') ? Carbon::parse($request->start_date) : Carbon::now();
            
            if ($plan->billing_interval === 'year') {
                $endDate = $startDate->copy()->addYear();
            } else {
                $endDate = $startDate->copy()->addMonth();
            }

            $subscription = Subscription::create([
                'user_id' => $request->user_id,
                'plan_id' => $request->plan_id,
                'status' => 'active',
                'starts_at' => $startDate,
                'ends_at' => $endDate,
                'provisioned_by' => Auth::id(),
                'notes' => $request->notes,
                'razorpay_subscription_id' => "manual",
            ]);

            return $this->successResponse($subscription, 'Subscription created successfully.', 201);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to create subscription: ' . $e->getMessage(), 500);
        }
    }
}
