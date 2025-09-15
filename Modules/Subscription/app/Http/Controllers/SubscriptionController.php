<?php

namespace Modules\Subscription\Http\Controllers;

use App\Http\Controllers\ApiController;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Razorpay\Api\Api as RazorpayApi;

class SubscriptionController extends ApiController
{
    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'plan_id' => [
                'required',
                Rule::exists('plans', 'id')->where(function ($query) {
                    $query->where('is_active', true);
                }),
            ],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->all(), 422);
        }

        try {
            $user = Auth::user();

            // Check for existing active subscription
            $existingSubscription = Subscription::where('user_id', $user->id)
                ->where('status', 'active')
                ->first();

            if ($existingSubscription) {
                return $this->errorResponse('You already have an active subscription.', 400);
            }
            
            $plan = Plan::find($request->plan_id);

            // Razorpay integration
            $api = new RazorpayApi(env('RAZORPAY_KEY'), env('RAZORPAY_SECRET'));

            $razorpaySubscription = $api->subscription->create([
                'plan_id' => $plan->razorpay_plan_id,
                'total_count' => $plan->billing_interval === 'year' ? 12 : 12, // Assuming 12 for both now, can be adjusted
                'quantity' => 1
            ]);

            // Create local subscription record
            $subscription = Subscription::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'razorpay_subscription_id' => $razorpaySubscription->id,
                'status' => 'pending', // Status is pending until payment is successful
            ]);

            return $this->successResponse(
                [
                    'razorpay_subscription_id' => $razorpaySubscription->id,
                    'subscription' => $subscription,
                ],
                'Subscription created successfully. Proceed to payment.'
            );

        } catch (\Exception $e) {
            // It's good practice to log the actual error
            // Log::error('Subscription creation failed: ' . $e->getMessage());
            return $this->errorResponse('Failed to create subscription. ' . $e->getMessage(), 500);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request)
    {
        $user = Auth::user();
        try {
            $subscription = Subscription::where('user_id', $user->id)
                ->whereIn('status', ['active', 'on_hold'])
                ->with('plan')
                ->first();

            if (!$subscription) {
                return $this->errorResponse404('No active subscription found.');
            }

            return $this->successResponse($subscription, 'Active subscription retrieved successfully.');
        } catch (\Exception $e) {
            return $this->errorResponse('An unexpected error occurred.', 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  string  $id The Razorpay Subscription ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
       
        try {
            $subscription = Subscription::where('razorpay_subscription_id', $id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$subscription) {
                return $this->errorResponse404('Subscription not found or you do not have permission to cancel it.');
            }

            $api = new RazorpayApi(env('RAZORPAY_KEY'), env('RAZORPAY_SECRET'));
            $api->subscription->fetch($id)->cancel();


            // The local status will be updated by the 'subscription.cancelled' webhook.
            return $this->successResponse(null, 'Subscription cancellation initiated successfully.');

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to cancel subscription: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request)
    {
        $user = Auth::user();
        $validator = Validator::make($request->all(), [
            'plan_id' => [
                'required',
                Rule::exists('plans', 'id')->where(function ($query) {
                    $query->where('is_active', true);
                }),
            ],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->all(), 422);
        }

        try {
            $oldSubscription = Subscription::where('user_id', $user->id)
                ->where('status', 'active')
                ->with('plan')
                ->first();

            if (!$oldSubscription) {
                return $this->errorResponse404('No active subscription found to update.');
            }

            if ($oldSubscription->plan_id == $request->plan_id) {
                return $this->errorResponse('You are already subscribed to this plan.', 400);
            }

            $newPlan = Plan::find($request->plan_id);
            $api = new RazorpayApi(env('RAZORPAY_KEY'), env('RAZORPAY_SECRET'));

            $notes = [
                'flow' => 'plan_change',
                'old_subscription_id' => $oldSubscription->razorpay_subscription_id,
                'old_plan_id' => $oldSubscription->plan_id,
                'new_plan_id' => $newPlan->id,
            ];

            $subscriptionData = [
                'plan_id' => $newPlan->razorpay_plan_id,
                'total_count' => $newPlan->billing_interval === 'year' ? 12 : 12,
                'quantity' => 1,
                'notes' => $notes,
            ];

            // Upgrade: new plan starts immediately
            if ($newPlan->priority > $oldSubscription->plan->priority) {
                // No start_at, starts immediately after payment
            }
            // Downgrade: new plan starts at the end of the current cycle
            else {
                $razorpayOldSubscription = $api->subscription->fetch($oldSubscription->razorpay_subscription_id);
                $subscriptionData['start_at'] = $razorpayOldSubscription->current_end;
            }

            $razorpaySubscription = $api->subscription->create($subscriptionData);

            // Create a new local subscription record, status is pending until payment
            $newLocalSubscription = Subscription::create([
                'user_id' => $user->id,
                'plan_id' => $newPlan->id,
                'razorpay_subscription_id' => $razorpaySubscription->id,
                'status' => 'pending',
                'notes' => 'Upgrade/Downgrade from ' . $oldSubscription->plan->name,
            ]);

            return $this->successResponse(
                [
                    'razorpay_subscription_id' => $razorpaySubscription->id,
                    'subscription' => $newLocalSubscription,
                    'action' => ($newPlan->priority > $oldSubscription->plan->priority) ? 'upgrade' : 'downgrade',
                ],
                'Subscription change initiated. Please complete the payment.'
            );

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to change subscription: ' . $e->getMessage(), 500);
        }
    }
}
