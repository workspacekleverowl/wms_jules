<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SuperadminMiddleware
{
    
    public function handle(Request $request, Closure $next)
    {
        // Check if the user is authenticated and has the Superadmin role
        if (!$request->user() || !$request->user()->hasRole('Superadmin')) {
            return response()->json([
                'status' => 403,
                'message' => 'Access denied: Only superadmins can access this resource.',
            ], 403);
        }

        return $next($request);
    }
}
