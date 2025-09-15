<x-mail::message>
# Payment Successful

Hello {{ $subscription->user->name }},

This is a receipt for your recent payment for the **{{ $subscription->plan->name }}** plan.

Amount: ${{ number_format($subscription->plan->price / 100, 2) }}

Your subscription is now valid until {{ $subscription->ends_at->format('F j, Y') }}.

Thank you for your business.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
