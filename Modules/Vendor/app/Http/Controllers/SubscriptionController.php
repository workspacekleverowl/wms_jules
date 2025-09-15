<?php

namespace Modules\Vendor\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Models\SubscriptionPackage;
use App\Models\TenantSubscription;
use App\Models\PaymentTransaction;
use App\Models\Tenant;
use Razorpay\Api\Api;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;


class SubscriptionController extends ApiController
{
     // Razorpay API client
    protected $razorpay;

    // Minimal verification amount (1 INR in paise)
    const VERIFICATION_AMOUNT = 200;

    public function __construct()
    {
        $this->razorpay = new Api(env('RAZORPAY_KEY_ID'), env('RAZORPAY_KEY_SECRET'));
    }

    /**
     * Create or get verification plan for free subscriptions
     */
    private function getVerificationPlan()
    {
        $planId = 'plan_verification_1rs_monthly';
        
        try {
            // Try to fetch existing plan
            $plan = $this->razorpay->plan->fetch($planId);
            return $plan;
        } catch (\Exception $e) {
            // Create verification plan if it doesn't exist
            $plan = $this->razorpay->plan->create([
                'period' => 'monthly',
                'interval' => 1,
                'item' => [
                    'name' => 'Verification Plan',
                    'amount' => self::VERIFICATION_AMOUNT,
                    'currency' => 'INR'
                ]
            ]);
            return $plan;
        }
    }

    /**
     * Handle a user's request to create a new recurring subscription.
     */
    public function createSubscription(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'package_id' => 'required|exists:subscription_packages,id',
        ]);

        if ($validator->fails()) {
            return static::errorResponse($validator->errors()->all(), 422);
        }

        $tenant = $request->user()->tenant;
        $package = SubscriptionPackage::find($request->package_id);

        // Check if the tenant already has an active subscription
        $currentSubscription = TenantSubscription::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->first();

        if ($currentSubscription) {
            return static::errorResponse(
                'You have an active subscription. Please cancel your current subscription first before creating a new one.', 
                409
            );
        }
        
        // Check if a free plan has been taken before
        if ($package->is_free && TenantSubscription::where('tenant_id', $tenant->id)->where('subscription_package_id', $package->id)->exists()) {
            return static::errorResponse('You have already taken this free plan once.', 409);
        }


        // Validate package configuration
        if (!$package->is_free && empty($package->razorpay_plan_id)) {
            return static::errorResponse('Selected package is not configured for payments.', 400);
        }

        DB::beginTransaction();
        try {
            if ($package->is_free) {
                // For free plans, create ₹1 verification subscription
                $verificationPlan = $this->getVerificationPlan();
                
                $razorpaySubscription = $this->razorpay->subscription->create([
                    'plan_id' => $verificationPlan->id,
                    'customer_notify' => 1,
                    'total_count' => 1, // Only one payment
                    'notes' => [
                        'tenant_id' => $tenant->id,
                        'package_id' => $package->id,
                        'verification' => 'true',
                        'free_plan' => 'true'
                    ]
                ]);

                // Create pending subscription record
                TenantSubscription::create([
                    'tenant_id' => $tenant->id,
                    'subscription_package_id' => $package->id,
                    'razorpay_subscription_id' => $razorpaySubscription->id,
                    'is_recurring' => false,
                    'assigned_by_superadmin' => false,
                    'status' => 'inactive', // Will be activated by webhook
                ]);

                DB::commit();
                
                return static::successResponse([
                    'razorpay_subscription_id' => $razorpaySubscription->id,
                    'short_url' => $razorpaySubscription->short_url ?? null,
                    'verification_amount' => self::VERIFICATION_AMOUNT,
                    'key' => env('RAZORPAY_KEY_ID')
                ], 'Please complete ₹1 verification payment to activate your free subscription.');

            } else {
                // Create a Razorpay subscription for paid plans
                $razorpaySubscription = $this->razorpay->subscription->create([
                    'plan_id' => $package->razorpay_plan_id,
                    'customer_notify' => 1,
                    'total_count' => 1, // Unlimited recurring payments
                    'notes' => [
                        'tenant_id' => $tenant->id,
                        'package_id' => $package->id
                    ]
                ]);

                // Create a pending subscription record
                TenantSubscription::create([
                    'tenant_id' => $tenant->id,
                    'subscription_package_id' => $package->id,
                    'razorpay_subscription_id' => $razorpaySubscription->id,
                    'status' => 'inactive',
                    'is_recurring' => true,
                    'assigned_by_superadmin' => false,
                ]);

                DB::commit();
                
                return static::successResponse([
                    'razorpay_subscription_id' => $razorpaySubscription->id,
                    'short_url' => $razorpaySubscription->short_url ?? null
                ], 'Subscription created successfully. Please complete payment.');
            }

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Subscription creation failed', [
                'tenant_id' => $tenant->id,
                'package_id' => $request->package_id,
                'error' => $e->getMessage()
            ]);
            return static::errorResponse(['Failed to create subscription', $e->getMessage()], 500);
        }
    }


    /**
     * Cancel subscription without refund
     */
    public function cancelSubscription(Request $request)
    {
        $tenant = $request->user()->tenant;
        
        $currentSubscription = TenantSubscription::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->first();

        if (!$currentSubscription) {
            return static::errorResponse('No active subscription found to cancel.', 404);
        }

        DB::beginTransaction();
        try {
            // Cancel Razorpay subscription if it's recurring and from Razorpay
            if ($currentSubscription->is_recurring && $currentSubscription->razorpay_subscription_id) {
                $this->razorpay->subscription->fetch($currentSubscription->razorpay_subscription_id)->cancel();
            }

            // Update subscription status
            $currentSubscription->update([
                'status' => 'cancelled',
                'cancellation_date' => Carbon::now()
            ]);

            // Update tenant status
            $this->updateTenantSubscriptionStatus($tenant->id, 'inactive');

            DB::commit();
            
            return static::successResponse(null, 'Subscription cancelled successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Subscription cancellation failed', [
                'tenant_id' => $tenant->id,
                'subscription_id' => $currentSubscription->id,
                'error' => $e->getMessage()
            ]);
            return static::errorResponse(['Failed to cancel subscription', $e->getMessage()], 500);
        }
    }

    /**
     * Handle Superadmin assigning a non-recurring plan.
     */
    public function assignSubscription(Request $request, $tenantId)
    {
        $validator = Validator::make($request->all(), [
            'package_id' => 'required|exists:subscription_packages,id',
        ]);

        if ($validator->fails()) {
            return static::errorResponse($validator->errors()->all(), 422);
        }

        DB::beginTransaction();
        try {
            $tenant = Tenant::findOrFail($tenantId);
            $package = SubscriptionPackage::findOrFail($request->package_id);

            // Cancel any existing active subscriptions
            $this->cancelExistingSubscriptions($tenant->id);

            // Create the new non-recurring subscription
            TenantSubscription::create([
                'tenant_id' => $tenant->id,
                'subscription_package_id' => $package->id,
                'is_recurring' => false,
                'assigned_by_superadmin' => true,
                'status' => 'active',
                'start_date' => Carbon::today(),
                'end_date' => Carbon::today()->addDays($package->duration_days),
            ]);

            // Update tenant status
            $this->updateTenantSubscriptionStatus($tenant->id, 'active');

            DB::commit();
            return static::successResponse(null, 'Subscription assigned successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Subscription assignment failed', [
                'tenant_id' => $tenantId,
                'package_id' => $request->package_id,
                'error' => $e->getMessage()
            ]);
            return static::errorResponse(['Failed to assign subscription', $e->getMessage()], 500);
        }
    }

    /**
     * Handle the process of a user changing their plan.
     */
    public function changePlan(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'new_package_id' => 'required|exists:subscription_packages,id',
        ]);

        if ($validator->fails()) {
            return static::errorResponse($validator->errors()->all(), 422);
        }

        $tenant = $request->user()->tenant;
        $newPackage = SubscriptionPackage::find($request->new_package_id);
        
        $currentSubscription = TenantSubscription::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->first();

        if (!$currentSubscription) {
            return static::errorResponse('No active subscription found to change.', 404);
        }
        
        $currentPackage = SubscriptionPackage::find($currentSubscription->subscription_package_id);
        
        if ($currentSubscription->subscription_package_id == $request->new_package_id) {
            return static::errorResponse('You already have an active subscription to this package.', 409);
        }

        if ($newPackage->is_free) {
            return static::errorResponse('Cannot change plan to free trial package.', 400);
        }

        if (empty($newPackage->razorpay_plan_id)) {
            return static::errorResponse('Selected package is not configured for recurring payments.', 400);
        }
        
        // Check if the new plan is of a higher price
        if ($newPackage->price_in_cents <= $currentPackage->price_in_cents) {
            return static::errorResponse('You can only upgrade to a higher-priced plan.', 400);
        }

        DB::beginTransaction();
        try {
            // Cancel current subscription only if payment was completed
            if ($currentSubscription->status === 'active' && $currentSubscription->razorpay_subscription_id) {
                 $this->razorpay->subscription->fetch($currentSubscription->razorpay_subscription_id)->cancel();
            }

            // Cancel current subscription
            $currentSubscription->update([
                'status' => 'cancelled', 
                'cancellation_date' => Carbon::now()
            ]);

            // Create new subscription
            $newRazorpaySubscription = $this->razorpay->subscription->create([
                'plan_id' => $newPackage->razorpay_plan_id,
                'customer_notify' => 1,
                'total_count' => 1, // Unlimited recurring payments
                'notes' => [
                    'tenant_id' => $tenant->id,
                    'package_id' => $newPackage->id,
                    'plan_change' => 'true'
                ]
            ]);

            // Create new subscription record
            TenantSubscription::create([
                'tenant_id' => $tenant->id,
                'subscription_package_id' => $newPackage->id,
                'razorpay_subscription_id' => $newRazorpaySubscription->id,
                'status' => 'inactive', // Will be activated by webhook
                'is_recurring' => true,
                'assigned_by_superadmin' => false,
            ]);

            DB::commit();
            
            return static::successResponse([
                'razorpay_subscription_id' => $newRazorpaySubscription->id,
                'short_url' => $newRazorpaySubscription->short_url,
            ], 'Plan change initiated. Please complete payment for the new plan.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Plan change failed', [
                'tenant_id' => $tenant->id,
                'current_subscription_id' => $currentSubscription->id,
                'new_package_id' => $request->new_package_id,
                'error' => $e->getMessage()
            ]);
            return static::errorResponse(['Failed to change plan', $e->getMessage()], 500);
        }
    }

    /**
     * Handle incoming webhooks from Razorpay.
     */
    public function handleRazorpayWebhook(Request $request)
    {
        $webhookSecret = env('RAZORPAY_WEBHOOK_SECRET');
        $signature = $request->header('X-Razorpay-Signature');

        try {
            $this->razorpay->utility->verifyWebhookSignature($request->getContent(), $signature, $webhookSecret);
        } catch (\Exception $e) {
            Log::error('Invalid webhook signature', ['signature' => $signature]);
            return response()->json(['status' => 'error', 'message' => 'Invalid signature'], 400);
        }

        $payload = json_decode($request->getContent());
        $event = $payload->event;

        DB::beginTransaction();
        try {
            switch ($event) {
                case 'subscription.charged':
                    $this->handleSubscriptionCharged($payload);
                    break;

                case 'subscription.cancelled':
                    $this->handleSubscriptionCancelled($payload);
                    break;
                
                default:
                    Log::info('Unhandled webhook event', ['event' => $event]);
                    break;
            }
            
            DB::commit();
            return response()->json(['status' => 'success'], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Razorpay Webhook Error', [
                'event' => $event,
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);
            return response()->json(['status' => 'error'], 500);
        }
    }

    /**
     * Cancel all existing subscriptions for a tenant
     */
    private function cancelExistingSubscriptions($tenantId)
    {
        $activeSubscriptions = TenantSubscription::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->get();

        foreach ($activeSubscriptions as $subscription) {
            if ($subscription->is_recurring && $subscription->razorpay_subscription_id) {
                try {
                    $this->razorpay->subscription->fetch($subscription->razorpay_subscription_id)->cancel();
                } catch (\Exception $e) {
                    Log::warning('Failed to cancel Razorpay subscription', [
                        'subscription_id' => $subscription->razorpay_subscription_id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            $subscription->update([
                'status' => 'cancelled',
                'cancellation_date' => Carbon::now()
            ]);
        }
    }

    /**
     * Update tenant subscription status
     */
    private function updateTenantSubscriptionStatus($tenantId, $status)
    {
        $currentDataJson = DB::table('tenants')->where('id', $tenantId)->value('data');
        $tenantData = json_decode($currentDataJson, true) ?? [];
        $tenantData['subscription_status'] = $status;

        DB::table('tenants')
            ->where('id', $tenantId)
            ->update([
                'subscription_status' => $status,
                'data' => json_encode($tenantData)
            ]);
    }

    /**
     * Handle subscription charged webhook
     */
    private function handleSubscriptionCharged($payload)
    {
        $subscriptionId = $payload->payload->subscription->entity->id;
        $paymentId = $payload->payload->payment->entity->id;
        $amount = $payload->payload->payment->entity->amount;

        $tenantSubscription = TenantSubscription::where('razorpay_subscription_id', $subscriptionId)->firstOrFail();
        $tenant = $tenantSubscription->tenant;

        // Check if this is a free plan verification subscription
        $isFreeVerification = isset($payload->payload->subscription->entity->notes) && 
                            isset($payload->payload->subscription->entity->notes->free_plan) &&
                            $payload->payload->subscription->entity->notes->free_plan === 'true';

        // Cancel any other active subscriptions
        $this->cancelExistingSubscriptions($tenant->id);
        
        $package = $tenantSubscription->package;
        
        // Calculate end date based on plan period
        if ($package->period == 'monthly') {
            $endDate = Carbon::now()->addMonths($package->duration_months);
        } elseif ($package->period == 'yearly') {
            $endDate = Carbon::now()->addYears($package->duration_years);
        } else {
            $endDate = null;
        }

        // Activate the subscription
        $tenantSubscription->update([
            'status' => 'active',
            'start_date' => Carbon::now(),
            'end_date' => $endDate,
        ]);

        // Update tenant status
        $this->updateTenantSubscriptionStatus($tenant->id, 'active');

        // Log payment transaction
        PaymentTransaction::create([
            'tenant_id' => $tenant->id,
            'tenant_subscription_id' => $tenantSubscription->id,
            'razorpay_payment_id' => $paymentId,
            'amount_in_cents' => $amount,
            'type' => $isFreeVerification ? 'verification' : 'payment',
            'status' => 'success',
            'transaction_date' => Carbon::now(),
        ]);
        
        // If this is a free plan verification, cancel the subscription to prevent future charges.
        // The original code handled the refund here, but per instructions, we remove that part.
        if ($isFreeVerification) {
            try {
                $this->razorpay->subscription->fetch($subscriptionId)->cancel();
            } catch (\Exception $e) {
                Log::error('Free plan subscription cancellation failed', [
                    'subscription_id' => $subscriptionId,
                    'payment_id' => $paymentId,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Handle subscription cancelled webhook
     */
    private function handleSubscriptionCancelled($payload)
    {
        $subscriptionId = $payload->payload->subscription->entity->id;
        $tenantSubscription = TenantSubscription::where('razorpay_subscription_id', $subscriptionId)->firstOrFail();

        $tenantSubscription->update([
            'status' => 'cancelled',
            'cancellation_date' => Carbon::now(),
        ]);

        $this->updateTenantSubscriptionStatus($tenantSubscription->tenant_id, 'inactive');
    }
}
