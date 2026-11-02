<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\StoreSetting;
use App\Services\ResellerBrainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AdminController extends Controller
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
     * GET /api/admin/dashboard
     * Sales & Profit Dashboard Summary
     */
    public function getDashboard(): JsonResponse
    {
        $totalOrders = Order::count();
        $totalSales = Order::where('status', '!=', 'cancelled')->sum('total');
        $totalProfit = Order::where('status', '!=', 'cancelled')->sum('total_profit');
        $pendingOrders = Order::where('status', 'pending')->count();
        $deliveredOrders = Order::where('status', 'delivered')->count();

        $recentOrders = Order::with('items')
            ->latest('id')
            ->limit(10)
            ->get();

        return $this->success('Dashboard metrics retrieved', [
            'metrics' => [
                'total_orders' => $totalOrders,
                'total_sales' => round((float) $totalSales, 2),
                'total_profit' => round((float) $totalProfit, 2),
                'pending_orders' => $pendingOrders,
                'delivered_orders' => $deliveredOrders,
            ],
            'recent_orders' => $recentOrders,
        ]);
    }

    /**
     * GET /api/admin/settings
     */
    public function getSettings(): JsonResponse
    {
        $settings = StoreSetting::current();
        return $this->success('Settings retrieved', $settings);
    }

    /**
     * POST /api/admin/settings
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $settings = StoreSetting::current();

        $validator = Validator::make($request->all(), [
            'store_name' => 'nullable|string|max:191',
            'store_tagline' => 'nullable|string|max:255',
            'store_phone' => 'nullable|string|max:30',
            'store_email' => 'nullable|email|max:100',
            'store_address' => 'nullable|string|max:255',
            'announcement_text' => 'nullable|string|max:500',
            'logo_url' => 'nullable|url|max:500',
            'banner_url' => 'nullable|url|max:500',
            'primary_color' => 'nullable|string|max:20',
            'delivery_inside_dhaka' => 'nullable|numeric|min:0',
            'delivery_outside_dhaka' => 'nullable|numeric|min:0',
            'rb_api_key' => 'nullable|string|max:100',
            'rb_api_secret' => 'nullable|string|max:150',
            'rb_base_url' => 'nullable|url|max:255',
            'default_markup_amount' => 'nullable|numeric|min:0',
            'default_markup_type' => 'nullable|string|in:fixed,percentage',
            'auto_forward_orders' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->failed('Validation error', $validator->errors(), 422);
        }

        $settings->update($validator->validated());

        return $this->success('সেটিংস সফলভাবে সংরক্ষিত হয়েছে', $settings);
    }

    /**
     * POST /api/admin/resellerbrain/test-connection
     */
    public function testRbConnection(ResellerBrainService $rbService): JsonResponse
    {
        $result = $rbService->verifyConnection();
        if ($result['success']) {
            return $this->success($result['message'], $result);
        }
        return $this->failed($result['message'], $result, 400);
    }

    /**
     * POST /api/admin/resellerbrain/sync
     */
    public function triggerRbSync(Request $request, ResellerBrainService $rbService): JsonResponse
    {
        // 1. Sync categories first
        $catResult = $rbService->syncCategories();

        // 2. Sync products
        $prodResult = $rbService->syncProducts((int) $request->input('per_page', 50));

        if ($prodResult['success']) {
            return $this->success($prodResult['message'], [
                'categories' => $catResult,
                'products' => $prodResult,
            ]);
        }

        return $this->failed($prodResult['message'], null, 500);
    }

    /**
     * GET /api/admin/products
     */
    public function getProducts(Request $request): JsonResponse
    {
        $query = Product::with('category');

        if ($request->filled('source')) {
            $query->where('source', $request->source);
        }

        if ($request->filled('search')) {
            $term = '%' . trim($request->search) . '%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)->orWhere('sku', 'like', $term);
            });
        }

        $products = $query->latest('id')->paginate((int) $request->input('per_page', 30));

        return $this->success('Products retrieved', $products);
    }

    /**
     * POST /api/admin/products (Add dropshipper's custom product)
     */
    public function createCustomProduct(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'category_id' => 'nullable|integer|exists:categories,id',
            'wholesale_price' => 'nullable|numeric|min:0',
            'retail_price' => 'required|numeric|min:0',
            'compare_at_price' => 'nullable|numeric|min:0',
            'stock' => 'required|integer|min:0',
            'description' => 'nullable|string',
            'primary_image' => 'nullable|url|max:500',
            'gallery_images' => 'nullable|array',
            'is_featured' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->failed('Validation failed', $validator->errors(), 422);
        }

        $validated = $validator->validated();
        $slug = Str::slug($validated['name']) . '-' . time();
        $sku = 'CUST-' . strtoupper(Str::random(6));

        $product = Product::create([
            'source' => 'custom',
            'category_id' => $validated['category_id'] ?? null,
            'name' => $validated['name'],
            'slug' => $slug,
            'sku' => $sku,
            'wholesale_price' => $validated['wholesale_price'] ?? 0.00,
            'retail_price' => $validated['retail_price'],
            'compare_at_price' => $validated['compare_at_price'] ?? null,
            'stock' => $validated['stock'],
            'is_in_stock' => $validated['stock'] > 0,
            'description' => $validated['description'] ?? '',
            'primary_image' => $validated['primary_image'] ?? null,
            'gallery_images' => $validated['gallery_images'] ?? [],
            'is_featured' => $request->boolean('is_featured', false),
            'is_active' => true,
        ]);

        return $this->success('প্রোডাক্ট সফলভাবে যুক্ত হয়েছে!', $product, 201);
    }

    /**
     * PUT /api/admin/products/{id}
     */
    public function updateProduct(Request $request, int $id): JsonResponse
    {
        $product = Product::find($id);
        if (!$product) {
            return $this->failed('Product not found', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'retail_price' => 'nullable|numeric|min:0',
            'compare_at_price' => 'nullable|numeric|min:0',
            'stock' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->failed('Validation failed', $validator->errors(), 422);
        }

        $product->update($validator->validated());
        if ($request->has('stock')) {
            $product->update(['is_in_stock' => $product->stock > 0]);
        }

        return $this->success('প্রোডাক্ট আপডেট হয়েছে!', $product);
    }

    /**
     * GET /api/admin/orders
     */
    public function getOrders(Request $request): JsonResponse
    {
        $query = Order::with('items');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $term = '%' . trim($request->search) . '%';
            $query->where(function ($q) use ($term) {
                $q->where('order_number', 'like', $term)
                  ->orWhere('customer_name', 'like', $term)
                  ->orWhere('customer_phone', 'like', $term);
            });
        }

        $orders = $query->latest('id')->paginate((int) $request->input('per_page', 25));

        return $this->success('Orders retrieved', $orders);
    }

    /**
     * POST /api/admin/orders/{id}/forward-to-rb
     * Retry forwarding order to ResellerBrain
     */
    public function retryForwardRbOrder(int $id, ResellerBrainService $rbService): JsonResponse
    {
        $order = Order::with('items')->find($id);
        if (!$order) {
            return $this->failed('Order not found', null, 404);
        }

        $result = $rbService->forwardOrderToResellerBrain($order);
        if ($result['success']) {
            return $this->success($result['message'], $order->fresh());
        }

        return $this->failed($result['message'], null, 400);
    }

    /**
     * POST /api/admin/orders/{id}/status
     */
    public function updateOrderStatus(Request $request, int $id): JsonResponse
    {
        $order = Order::find($id);
        if (!$order) {
            return $this->failed('Order not found', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:pending,processing,shipped,delivered,cancelled',
        ]);

        if ($validator->fails()) {
            return $this->failed('Invalid status', $validator->errors(), 422);
        }

        $order->update(['status' => $request->status]);

        return $this->success('অর্ডার স্ট্যাটাস আপডেট হয়েছে', $order);
    }
}
