<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\StoreSetting;
use App\Services\ResellerBrainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
     * Sales & Profit Dashboard Summary scoped to current store
     */
    public function getDashboard(): JsonResponse
    {
        $store = StoreSetting::current();

        $totalOrders = Order::where('store_id', $store->id)->count();
        $totalSales = Order::where('store_id', $store->id)->where('status', '!=', 'cancelled')->sum('total');
        $totalProfit = Order::where('store_id', $store->id)->where('status', '!=', 'cancelled')->sum('total_profit');
        $pendingOrders = Order::where('store_id', $store->id)->where('status', 'pending')->count();
        $deliveredOrders = Order::where('store_id', $store->id)->where('status', 'delivered')->count();

        $recentOrders = Order::where('store_id', $store->id)
            ->with('items')
            ->latest('id')
            ->limit(10)
            ->get();

        return $this->success('Dashboard metrics retrieved', [
            'store_id' => $store->id,
            'store_name' => $store->store_name,
            'domain' => $store->domain,
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
            'whatsapp_number' => 'nullable|string|max:30',
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
    public function testRbConnection(): JsonResponse
    {
        $store = StoreSetting::current();
        $rbService = new ResellerBrainService($store);

        $result = $rbService->verifyConnection();
        if ($result['success']) {
            return $this->success($result['message'], $result);
        }
        return $this->failed($result['message'], $result, 400);
    }

    /**
     * POST /api/admin/resellerbrain/sync
     */
    public function triggerRbSync(Request $request): JsonResponse
    {
        $store = StoreSetting::current();
        $rbService = new ResellerBrainService($store);

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
        $store = StoreSetting::current();

        $query = Product::where('store_id', $store->id)->with('category');

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
        $store = StoreSetting::current();

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'category_id' => 'nullable|integer',
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
        $slug = Str::slug($validated['name']) . '-' . $store->id . '-' . time();
        $sku = 'CUST-' . strtoupper(Str::random(6));

        $product = Product::create([
            'store_id' => $store->id,
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
        $store = StoreSetting::current();

        $product = Product::where('store_id', $store->id)->find($id);
        if (!$product) {
            return $this->failed('Product not found in this store', null, 404);
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
        $store = StoreSetting::current();

        $query = Order::where('store_id', $store->id)->with('items');

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
    public function retryForwardRbOrder(int $id): JsonResponse
    {
        $store = StoreSetting::current();

        $order = Order::where('store_id', $store->id)->with('items')->find($id);
        if (!$order) {
            return $this->failed('Order not found', null, 404);
        }

        $rbService = new ResellerBrainService($store);
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
        $store = StoreSetting::current();

        $order = Order::where('store_id', $store->id)->find($id);
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

    /**
     * POST /api/admin/login
     */
    public function login(Request $request): JsonResponse
    {
        $store = StoreSetting::current();

        $validator = Validator::make($request->all(), [
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->failed('অনুগ্রহ করে অ্যাডমিন পাসওয়ার্ড দিন।', $validator->errors(), 422);
        }

        $password = $request->input('password');

        // Check against hashed password, fallback to default 'admin123' if not yet initialized
        $isMatch = false;
        if (!empty($store->admin_password)) {
            $isMatch = Hash::check($password, $store->admin_password) || $password === $store->admin_password;
        } else {
            $isMatch = ($password === 'admin123');
            if ($isMatch) {
                $store->admin_password = Hash::make('admin123');
            }
        }

        if (!$isMatch) {
            return $this->failed('ভুল পাসওয়ার্ড! অনুগ্রহ করে সঠিক অ্যাডমিন পাসওয়ার্ড দিন।', null, 401);
        }

        $token = Str::random(64);
        $store->admin_token = $token;
        $store->save();

        return $this->success('লগইন সফল হয়েছে!', [
            'token' => $token,
            'store' => [
                'id' => $store->id,
                'name' => $store->store_name,
                'domain' => $store->domain,
            ],
        ]);
    }

    /**
     * GET /api/admin/check-auth
     */
    public function checkAuth(): JsonResponse
    {
        $store = StoreSetting::current();
        return $this->success('Authenticated', [
            'store' => [
                'id' => $store->id,
                'name' => $store->store_name,
                'domain' => $store->domain,
                'phone' => $store->store_phone,
            ],
        ]);
    }

    /**
     * POST /api/admin/logout
     */
    public function logout(): JsonResponse
    {
        $store = StoreSetting::current();
        $store->update(['admin_token' => null]);
        return $this->success('সফলভাবে লগআউট হয়েছে।');
    }

    /**
     * POST /api/admin/change-password
     */
    public function changePassword(Request $request): JsonResponse
    {
        $store = StoreSetting::current();

        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return $this->failed('পাসওয়ার্ড ভ্যালিডেশন ব্যর্থ হয়েছে।', $validator->errors(), 422);
        }

        if (!empty($store->admin_password) && !Hash::check($request->current_password, $store->admin_password)) {
            return $this->failed('বর্তমান পাসওয়ার্ডটি সঠিক নয়।', null, 400);
        }

        $store->update([
            'admin_password' => Hash::make($request->new_password),
        ]);

        return $this->success('পাসওয়ার্ড সফলভাবে আপডেট হয়েছে!');
    }
}
