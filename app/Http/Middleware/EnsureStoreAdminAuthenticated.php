<?php

namespace App\Http\Middleware;

use App\Models\StoreSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStoreAdminAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        $store = StoreSetting::current();

        $token = $request->bearerToken() 
            ?: $request->header('X-Admin-Token')
            ?: $request->header('admin-token');

        if (!$token || empty($store->admin_token) || !hash_equals($store->admin_token, $token)) {
            return response()->json([
                'status'  => 'failed',
                'message' => 'অ্যাডমিন সেশনের মেয়াদ শেষ হয়েছে বা আপনি লগইন করেননি। অনুগ্রহ করে পুনরায় লগইন করুন।',
            ], 401);
        }

        return $next($request);
    }
}
