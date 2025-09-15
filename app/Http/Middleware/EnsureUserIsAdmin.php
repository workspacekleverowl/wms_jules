<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
       // Check if the user is authenticated and has the Admin role
        if (!$request->user() || !$request->user()->hasRole('Admin')) {
            return response()->json([
                'status' => 403,
                'message' => 'Access denied: Only admins can access this resource.',
            ], 403);
        }

        return $next($request);
    }
}


