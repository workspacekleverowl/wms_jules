<?php

namespace Modules\Subscription\Http\Controllers;

use App\Http\Controllers\ApiController;
use App\Jobs\ProcessRazorpayWebhook;
use App\Models\WebhookLog;
use Illuminate\Http\Request;
use Razorpay\Api\Api as RazorpayApi;
use Razorpay\Api\Errors\SignatureVerificationError;

class WebhookController extends ApiController
{
    /**
     * Handle incoming Razorpay webhooks.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function handleRazorpay(Request $request)
    {
        try {
            $api = new RazorpayApi(env('RAZORPAY_KEY'), env('RAZORPAY_SECRET'));
            $api->utility->verifyWebhookSignature(
                $request->getContent(),
                $request->header('X-Razorpay-Signature'),
                env('RAZORPAY_WEBHOOK_SECRET')
            );
        } catch (SignatureVerificationError $e) {
            // Log the failed attempt but don't create a job
            WebhookLog::create([
                'event_type' => $request->input('event', 'unknown'),
                'payload' => $request->all(),
                'status' => 'failed_verification',
            ]);
            return $this->errorResponse('Webhook signature validation failed', 400);
        }

        \Log::info('Razorpay Webhook Payload:', $request->all());

        // Extract subscription id safely
        $subscriptionId = data_get($request->all(), 'payload.subscription.entity.id');
        // Log that the webhook was received and verified
        WebhookLog::create([
            'razorpay_subscription_id' => $subscriptionId,
            'event_type' => $request->input('event'),
            'payload' => $request->all(),
            'status' => 'received',
        ]);

        // Dispatch the job to the queue for processing
        ProcessRazorpayWebhook::dispatch(
            $request->input('event'), 
            $request->input('payload')
        );

        return $this->successResponse(['status' => 'ok', 'message' => 'Webhook received and queued for processing.']);
    }
}
