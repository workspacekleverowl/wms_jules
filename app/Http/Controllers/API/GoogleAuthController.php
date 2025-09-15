<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Throwable;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\API\TenantController;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use App\Models\company;
use Google\Client as GoogleClient;

class GoogleAuthController extends Controller
{
    public function verifyGoogleToken(Request $request)
    {
        try {
            $client = new GoogleClient();
            $client->setClientId(env('GOOGLE_CLIENT_ID'));
            
            $payload = $client->verifyIdToken($request->token);

            if (!$payload) {
                return response()->json([
                    'status' => 401,
                    'message' => 'Invalid token',
                ], 401);
            }

            // Get user email from payload
            $email = $payload['email'];

            // Check if user exists
            $user = User::where('email', $email)->first();

            if (!$user) {
                return response()->json([
                    'status' => 401,
                    'message' => 'Invalid credentials',
                    'record' => null,
                ], 401);
            }

            // User exists, proceed with login
            $user->makeHidden(['permissions']);

            // Generate token
            $token = $user->createToken('API Token')->plainTextToken;

            // Check if the user has meta data
            $hasMeta = $user->meta()->exists();

            // Check tenant's subscription status
            $tenant = $user->tenant;
            $subscriptionActive = $tenant && $tenant->subscription_status === "active";

            $permissionsByCategory = Permission::where('tenant_id', $tenant->id)
                ->get()
                ->groupBy('category')
                ->mapWithKeys(function ($permissions, $category) use ($user) {
                    $rolePermissionIds = \DB::table('role_has_permissions')
                        ->whereIn('role_id', $user->roles->pluck('id'))
                        ->pluck('permission_id')
                        ->toArray();
            
                    return [
                        $category => $permissions->pluck('name', 'id')->mapWithKeys(function ($name, $id) use ($rolePermissionIds) {
                            $hasPermission = in_array($id, $rolePermissionIds);
                            return [$name => $hasPermission];
                        }),
                    ];
                });

            return response()->json([
                'status' => 200,
                'message' => 'Login successful',
                'record' => [
                    'user' => $user->load('meta'),
                    'token' => $token,
                    'profile' => $hasMeta,
                    'subscription' => $subscriptionActive,
                    'permissions' => $permissionsByCategory,
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 401,
                'message' => 'Authentication failed',
                'error' => $e->getMessage()
            ], 401);
        }
    }
}
