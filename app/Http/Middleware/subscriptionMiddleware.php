<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class subscriptionMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        // Skip subscription check for superadmin users
        if ($request->user()->hasRole('Superadmin')) {
            return $next($request);
        }

        // Check if the user belongs to a tenant and if the tenant has an active subscription
        if (!$user || !$user->tenant || $user->tenant->subscription_status !== 'active') {
            return response()->json([
                'status' => 402,
                'message' => 'Inactive subscription.',
            ], 402);
        }

        return $next($request);
    }
}
