<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StoreSetting;
use App\Services\ResellerBrainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class StorefrontController extends Controller
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
     * GET /api/store/info
     * Public store profile & branding
     */
    public function getStoreInfo(): JsonResponse
    {
        $settings = StoreSetting::current();

        return $this->success('Store information retrieved', [
            'store_id' => $settings->id,
            'domain' => $settings->domain,
            'store_name' => $settings->store_name,
            'store_tagline' => $settings->store_tagline,
            'store_phone' => $settings->store_phone,
            'whatsapp_number' => $settings->whatsapp_number ?: $settings->store_phone,
            'store_email' => $settings->store_email,
            'store_address' => $settings->store_address,
            'announcement_text' => $settings->announcement_text,
            'logo_url' => $settings->logo_url,
            'banner_url' => $settings->banner_url,
            'primary_color' => $settings->primary_color,
            'delivery_inside_dhaka' => (float) $settings->delivery_inside_dhaka,
            'delivery_outside_dhaka' => (float) $settings->delivery_outside_dhaka,
            'rb_connected' => !empty($settings->rb_api_key),
        ]);
    }

    /**
     * GET /api/store/categories
     * Active categories for current store
     */
    public function getCategories(): JsonResponse
    {
        $store = StoreSetting::current();

        $categories = Category::where('store_id', $store->id)
            ->where('is_active', true)
            ->withCount(['products' => function ($q) use ($store) {
                $q->where('store_id', $store->id)
                  ->where('is_active', true)
                  ->where('is_in_stock', true);
            }])
            ->orderBy('sort_order')
            ->get();

        return $this->success('Categories fetched', $categories);
    }

    /**
     * GET /api/store/products
     * Public product list with filters scoped to current store
     */
    public function getProducts(Request $request): JsonResponse
    {
        $store = StoreSetting::current();

        $query = Product::where('store_id', $store->id)
            ->where('is_active', true)
            ->with('category:id,name,slug');

        if ($request->boolean('featured_only')) {
            $query->where('is_featured', true);
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('category_slug')) {
            $query->whereHas('category', function ($q) use ($request, $store) {
                $q->where('store_id', $store->id)
                  ->where('slug', $request->category_slug);
            });
        }

        if ($request->filled('search')) {
            $term = '%' . trim($request->search) . '%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                  ->orWhere('sku', 'like', $term);
            });
        }

        // Sorting
        $sort = $request->input('sort', 'newest');
        match ($sort) {
            'price_low' => $query->orderBy('retail_price', 'asc'),
            'price_high' => $query->orderBy('retail_price', 'desc'),
            'popular' => $query->orderBy('stock', 'desc'),
            default => $query->latest('id'),
        };

        $perPage = min((int) $request->input('per_page', 24), 60);
        $products = $query->paginate($perPage);

        return $this->success('Products retrieved', [
            'current_page' => $products->currentPage(),
            'per_page' => $products->perPage(),
            'total' => $products->total(),
            'last_page' => $products->lastPage(),
            'items' => $products->items(),
        ]);
    }

    /**
     * GET /api/store/products/{slug}
     * Single product details with related items scoped to current store
     */
    public function getProductDetails(string $slug): JsonResponse
    {
        $store = StoreSetting::current();

        $product = Product::where('store_id', $store->id)
            ->where('slug', $slug)
            ->where('is_active', true)
            ->with('category')
            ->first();

        if (!$product) {
            return $this->failed('Product not found or unavailable.', null, 404);
        }

        $related = Product::where('store_id', $store->id)
            ->where('is_active', true)
            ->where('id', '!=', $product->id)
            ->when($product->category_id, fn($q) => $q->where('category_id', $product->category_id))
            ->limit(8)
            ->get();

        return $this->success('Product details retrieved', [
            'product' => $product,
            'related' => $related,
        ]);
    }

    /**
     * POST /api/store/checkout
     * 1-Page Customer Checkout scoped to current store
     */
    public function checkout(Request $request): JsonResponse
    {
        $store = StoreSetting::current();

        $validator = Validator::make($request->all(), [
            'customer_name' => 'required|string|max:191',
            'customer_phone' => 'required|string|regex:/^01[3-9]\d{8}$/',
            'shipping_address' => 'required|string|max:500',
            'district' => 'nullable|string|max:100',
            'zone' => 'nullable|string|max:100',
            'area' => 'nullable|string|max:100',
            'shipping_fee' => 'required|numeric|min:0',
            'payment_method' => 'nullable|string|in:cod,bkash',
            'customer_note' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1',
        ], [
            'customer_phone.regex' => 'অনুগ্রহ করে সঠিক ১১ ডিজিটের মোবাইল নম্বর দিন (যেমন: 01711223344)',
        ]);

        if ($validator->fails()) {
            return $this->failed('Validation failed', $validator->errors(), 422);
        }

        $validated = $validator->validated();

        try {
            DB::beginTransaction();

            $productIds = collect($validated['items'])->pluck('product_id')->all();
            $products = Product::where('store_id', $store->id)
                ->whereIn('id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $orderItemsData = [];
            $subtotal = 0.0;
            $totalProfit = 0.0;
            $hasRbItems = false;

            foreach ($validated['items'] as $item) {
                $pId = $item['product_id'];
                $qty = (int) $item['quantity'];
                $product = $products->get($pId);

                if (!$product || !$product->is_active) {
                    DB::rollBack();
                    return $this->failed("Product ID {$pId} is not available in this store.", null, 422);
                }

                if ($product->stock < $qty) {
                    DB::rollBack();
                    return $this->failed("দুঃখিত, '{$product->name}' প্রোডাক্টটির স্টক শেষ বা পর্যাপ্ত নেই। (বর্তমান স্টক: {$product->stock})", null, 422);
                }

                $unitPrice = (float) $product->retail_price;
                $wholesalePrice = (float) $product->wholesale_price;
                $lineTotal = round($unitPrice * $qty, 2);
                $lineProfit = round(($unitPrice - $wholesalePrice) * $qty, 2);

                $subtotal += $lineTotal;
                $totalProfit += $lineProfit;

                if ($product->source === 'resellerbrain' && $product->rb_product_id) {
                    $hasRbItems = true;
                }

                $orderItemsData[] = [
                    'product_id' => $product->id,
                    'source' => $product->source,
                    'rb_product_id' => $product->rb_product_id,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'primary_image' => $product->primary_image,
                    'unit_price' => $unitPrice,
                    'wholesale_price' => $wholesalePrice,
                    'quantity' => $qty,
                    'line_total' => $lineTotal,
                    'line_profit' => $lineProfit,
                ];

                // Decrement local inventory
                $product->decrement('stock', $qty);
                if ($product->stock <= 0) {
                    $product->update(['is_in_stock' => false]);
                }
            }

            $shippingFee = (float) $validated['shipping_fee'];
            $total = round($subtotal + $shippingFee, 2);

            // Generate clean Order Number (e.g. ORD-20261102-3982)
            $orderNumber = 'ORD-' . date('Ymd') . '-' . strtoupper(Str::random(4));

            $order = Order::create([
                'store_id' => $store->id,
                'order_number' => $orderNumber,
                'customer_name' => $validated['customer_name'],
                'customer_phone' => $validated['customer_phone'],
                'shipping_address' => $validated['shipping_address'],
                'district' => $validated['district'] ?? null,
                'zone' => $validated['zone'] ?? null,
                'area' => $validated['area'] ?? null,
                'subtotal' => $subtotal,
                'shipping_fee' => $shippingFee,
                'discount' => 0.0,
                'total' => $total,
                'total_profit' => $totalProfit,
                'payment_method' => $validated['payment_method'] ?? 'cod',
                'payment_status' => 'unpaid',
                'status' => 'pending',
                'customer_note' => $validated['customer_note'] ?? null,
                'has_rb_items' => $hasRbItems,
                'rb_status' => $hasRbItems ? 'pending' : 'not_applicable',
            ]);

            foreach ($orderItemsData as $itemData) {
                $order->items()->create($itemData);
            }

            DB::commit();

            // Auto-forward to ResellerBrain if enabled and contains supplier products
            $rbForwardResult = null;
            $rbService = new ResellerBrainService($store);
            if ($hasRbItems && $store->auto_forward_orders && $rbService->isConfigured()) {
                $rbForwardResult = $rbService->forwardOrderToResellerBrain($order);
            }

            return $this->success('আপনার অর্ডারটি সফলভাবে গৃহীত হয়েছে!', [
                'order_number' => $order->order_number,
                'customer_name' => $order->customer_name,
                'total' => $order->total,
                'payment_method' => $order->payment_method,
                'status' => $order->status,
                'resellerbrain_forwarded' => (bool) ($order->rb_status === 'forwarded'),
                'created_at' => $order->created_at->toIso8601String(),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->failed('অর্ডার প্রক্রিয়াকরণে সমস্যা হয়েছে: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * GET /api/store/orders/track/{orderNumber}
     * Customer order tracking scoped to current store
     */
    public function trackOrder(string $orderNumber): JsonResponse
    {
        $store = StoreSetting::current();

        $order = Order::where('store_id', $store->id)
            ->where(function ($q) use ($orderNumber) {
                $q->where('order_number', $orderNumber)
                  ->orWhere('customer_phone', $orderNumber);
            })
            ->with('items')
            ->latest()
            ->first();

        if (!$order) {
            return $this->failed('এই নম্বরে কোনো অর্ডার পাওয়া যায়নি।', null, 404);
        }

        return $this->success('অর্ডারের বিবরণ পাওয়া গেছে', [
            'order_number' => $order->order_number,
            'status' => $order->status,
            'customer_name' => $order->customer_name,
            'shipping_address' => $order->shipping_address,
            'total' => $order->total,
            'payment_status' => $order->payment_status,
            'courier_tracking_id' => $order->rb_consignment_id,
            'items' => $order->items->map(fn($i) => [
                'name' => $i->product_name,
                'quantity' => $i->quantity,
                'price' => $i->unit_price,
                'image' => $i->primary_image,
            ]),
            'created_at' => $order->created_at->toIso8601String(),
        ]);
    }
}
