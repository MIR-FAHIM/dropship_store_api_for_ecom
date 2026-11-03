<?php

namespace App\Http\Middleware;

use App\Models\StoreSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCurrentStore
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();

        // Strip www. prefix
        $domain = preg_replace('/^www\./', '', strtolower(trim($host)));

        $store = StoreSetting::where('domain', $domain)->first();

        if (!$store) {
            return response()->json([
                'status'  => 'failed',
                'message' => 'No store found for domain: ' . $domain . '. Please provision the store first.',
            ], 404);
        }

        if (!$store->is_active ?? true) {
            return response()->json([
                'status'  => 'failed',
                'message' => 'This store is currently inactive.',
            ], 403);
        }

        // Bind the resolved store into the service container for this request
        app()->instance('current.store', $store);

        return $next($request);
    }
}
