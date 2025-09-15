<x-mail::message>
# Subscription Activated

Hello {{ $subscription->user->name }},

Congratulations! Your subscription to the **{{ $subscription->plan->name }}** plan is now active.

Your plan started on {{ $subscription->starts_at->format('F j, Y') }}.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
