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
        // 1. Check explicit header from frontend SPA
        $domainCandidate = $request->header('X-Store-Domain')
            ?: $request->header('X-Tenant-Domain')
            ?: $request->query('domain');

        // 2. Check Origin header (CORS requests from client frontend)
        if (!$domainCandidate && $origin = $request->header('Origin')) {
            $parsed = parse_url($origin, PHP_URL_HOST);
            if ($parsed) {
                $domainCandidate = $parsed;
            }
        }

        // 3. Check Referer header
        if (!$domainCandidate && $referer = $request->header('Referer')) {
            $parsed = parse_url($referer, PHP_URL_HOST);
            if ($parsed) {
                $domainCandidate = $parsed;
            }
        }

        // 4. Fallback to Host header
        if (!$domainCandidate) {
            $domainCandidate = $request->getHost();
        }

        // Clean domain (strip port & www.)
        $domainCandidate = preg_replace('/:\d+$/', '', $domainCandidate);
        $domain = preg_replace('/^www\./', '', strtolower(trim($domainCandidate)));

        // Try to match the store by domain
        $store = StoreSetting::where('domain', $domain)->first();

        // If not found and host is the API subdomain itself (store-api.resellerbrain.com) or localhost, fallback to default store id=1
        if (!$store && (str_contains($domain, 'store-api.') || in_array($domain, ['localhost', '127.0.0.1']))) {
            $store = StoreSetting::find(1);
        }

        if (!$store) {
            return response()->json([
                'status'  => 'failed',
                'message' => 'No store found for domain: ' . $domain . '. Please provision the store first.',
            ], 404);
        }

        if (!($store->is_active ?? true)) {
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
