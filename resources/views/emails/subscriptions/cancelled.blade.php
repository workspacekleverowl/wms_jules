<x-mail::message>
# Subscription Cancelled

Hello {{ $subscription->user->name }},

This email is to confirm that your subscription to the **{{ $subscription->plan->name }}** plan has been cancelled.

Your access will continue until the end of your current billing period, which is {{ $subscription->ends_at->format('F j, Y') }}. You will not be charged again.

We're sorry to see you go.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
