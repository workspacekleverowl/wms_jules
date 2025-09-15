<?php

namespace App\Jobs;

use App\Mail\SubscriptionActivated;
use App\Mail\SubscriptionCancelled;
use App\Mail\SubscriptionCharged;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\WebhookLog;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ProcessRazorpayWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $eventName;
    protected $payload;

    /**
     * Create a new job instance.
     *
     * @param string $eventName
     * @param array $payload
     */
    public function __construct(string $eventName, array $payload)
    {
        $this->eventName = $eventName;
        $this->payload = $payload;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $subscriptionPayload = $this->payload['subscription']['entity'];
        
        // Eager-load relationships for use in emails
        $subscription = Subscription::with(['user', 'plan'])
            ->where('razorpay_subscription_id', $subscriptionPayload['id'])
            ->first();

        if (!$subscription) {
            Log::warning('ProcessRazorpayWebhook: Subscription not found for Razorpay ID: ' . $subscriptionPayload['id']);
            return;
        }
        
        $log = WebhookLog::where('razorpay_subscription_id', $subscriptionPayload['id'])
            ->where('event_type', $this->eventName)
            ->where('status', 'received')
            ->latest()
            ->first();

        switch ($this->eventName) {
            case 'subscription.activated':
                $subscription->status = 'active';
                $subscription->starts_at = Carbon::now();
                $subscription->save();
                Mail::to($subscription->user)->send(new SubscriptionActivated($subscription));
                break;

            case 'subscription.updated':
                $newPlan = Plan::where('razorpay_plan_id', $subscriptionPayload['plan_id'])->first();
                if ($newPlan) {
                    $subscription->plan_id = $newPlan->id;
                    $subscription->save();
                }
                break;

            case 'subscription.charged':
                $newEndDate = $subscription->ends_at ? Carbon::parse($subscription->ends_at) : Carbon::now();
                $subscription->ends_at = ($subscription->plan->billing_interval === 'year') 
                    ? $newEndDate->addYear() 
                    : $newEndDate->addMonth();
                $subscription->save();
                Mail::to($subscription->user)->send(new SubscriptionCharged($subscription));
                break;

            case 'subscription.cancelled':
                $subscription->status = 'cancelled';
                $subscription->cancelled_at = Carbon::createFromTimestamp($subscriptionPayload['end_at']);
                $subscription->save();
                Mail::to($subscription->user)->send(new SubscriptionCancelled($subscription));
                break;

            case 'subscription.halted':
                $subscription->status = 'on_hold';
                $subscription->save();
                break;
            
            default:
                if ($log) $log->update(['status' => 'unhandled_event']);
                return;
        }
        
        if ($log) {
            $log->update(['status' => 'processed', 'processed_at' => Carbon::now()]);
        }
    }
}
