<?php

namespace App\Http\Controllers;

use App\Models\StoreSetting;
use App\Services\ResellerBrainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProvisionController extends Controller
{
    private function success(string $message, mixed $data = null, int $code = 200): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    private function failed(string $message, mixed $errors = null, int $code = 400): JsonResponse
    {
        return response()->json([
            'status' => 'failed',
            'message' => $message,
            'errors' => $errors,
        ], $code);
    }

    /**
     * Check authorization for provision endpoints (optional security token)
     */
    private function checkAuth(Request $request): ?JsonResponse
    {
        $provisionSecret = env('PROVISION_SECRET');
        if ($provisionSecret) {
            $token = $request->header('X-Provision-Secret') ?: $request->input('provision_secret');
            if ($token !== $provisionSecret) {
                return $this->failed('Unauthorized provisioning request.', null, 401);
            }
        }
        return null;
    }

    /**
     * POST /api/provision/store
     * Provision or update a client store automatically from ResellerBrain
     */
    public function provisionStore(Request $request): JsonResponse
    {
        if ($authError = $this->checkAuth($request)) {
            return $authError;
        }

        $validator = Validator::make($request->all(), [
            'domain' => 'required|string|max:255',
            'store_name' => 'required|string|max:191',
            'rb_api_key' => 'required|string|max:100',
            'rb_api_secret' => 'required|string|max:150',
            'rb_base_url' => 'nullable|url|max:255',
            'store_phone' => 'nullable|string|max:30',
            'whatsapp_number' => 'nullable|string|max:30',
            'store_email' => 'nullable|email|max:100',
            'store_address' => 'nullable|string|max:255',
            'store_tagline' => 'nullable|string|max:255',
            'announcement_text' => 'nullable|string|max:500',
            'logo_url' => 'nullable|string|max:500',
            'banner_url' => 'nullable|string|max:500',
            'primary_color' => 'nullable|string|max:20',
            'delivery_inside_dhaka' => 'nullable|numeric|min:0',
            'delivery_outside_dhaka' => 'nullable|numeric|min:0',
            'default_markup_amount' => 'nullable|numeric|min:0',
            'default_markup_type' => 'nullable|string|in:fixed,percentage',
            'auto_forward_orders' => 'nullable|boolean',
            'sync_now' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->failed('Validation failed', $validator->errors(), 422);
        }

        $domain = preg_replace('/^www\./', '', strtolower(trim($request->input('domain'))));

        $store = StoreSetting::updateOrCreate(
            ['domain' => $domain],
            [
                'store_name' => $request->input('store_name'),
                'store_tagline' => $request->input('store_tagline', 'সেরা মানের পণ্য ও দ্রুত ডেলিভারি'),
                'store_phone' => $request->input('store_phone', '01700000000'),
                'whatsapp_number' => $request->input('whatsapp_number'),
                'store_email' => $request->input('store_email'),
                'store_address' => $request->input('store_address'),
                'announcement_text' => $request->input('announcement_text', 'সারাদেশে হোম ডেলিভারি | ক্যাশ অন ডেলিভারি সুবিধা!'),
                'logo_url' => $request->input('logo_url'),
                'banner_url' => $request->input('banner_url'),
                'primary_color' => $request->input('primary_color', '#2563eb'),
                'delivery_inside_dhaka' => (float) $request->input('delivery_inside_dhaka', 70.00),
                'delivery_outside_dhaka' => (float) $request->input('delivery_outside_dhaka', 130.00),
                'rb_api_key' => $request->input('rb_api_key'),
                'rb_api_secret' => $request->input('rb_api_secret'),
                'rb_base_url' => $request->input('rb_base_url', 'http://127.0.0.1:8000/api/partner/v1'),
                'default_markup_amount' => (float) $request->input('default_markup_amount', 350.00),
                'default_markup_type' => $request->input('default_markup_type', 'fixed'),
                'auto_forward_orders' => (bool) $request->input('auto_forward_orders', true),
                'is_active' => true,
            ]
        );

        $syncResult = null;
        if ($request->boolean('sync_now', true)) {
            $rbService = new ResellerBrainService($store);
            $rbService->syncCategories();
            $syncResult = $rbService->syncProducts();
        }

        return $this->success('Store provisioned successfully!', [
            'store' => $store,
            'sync' => $syncResult,
        ], 201);
    }

    /**
     * POST /api/provision/sync
     * Trigger product & category sync for a given domain
     */
    public function syncStore(Request $request): JsonResponse
    {
        if ($authError = $this->checkAuth($request)) {
            return $authError;
        }

        $domain = preg_replace('/^www\./', '', strtolower(trim($request->input('domain'))));
        $store = StoreSetting::where('domain', $domain)->first();

        if (!$store) {
            return $this->failed('Store not found for domain: ' . $domain, null, 404);
        }

        $rbService = new ResellerBrainService($store);
        $catResult = $rbService->syncCategories();
        $prodResult = $rbService->syncProducts((int) $request->input('per_page', 50));

        return $this->success('Catalog sync finished', [
            'domain' => $domain,
            'categories' => $catResult,
            'products' => $prodResult,
        ]);
    }

    /**
     * GET /api/provision/stores
     * List all provisioned client stores
     */
    public function listStores(Request $request): JsonResponse
    {
        if ($authError = $this->checkAuth($request)) {
            return $authError;
        }

        $stores = StoreSetting::withCount(['products', 'orders'])
            ->orderBy('id', 'desc')
            ->get();

        return $this->success('All client stores retrieved', $stores);
    }
}
