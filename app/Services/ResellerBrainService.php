<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\StoreSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ResellerBrainService
{
    protected StoreSetting $settings;
    protected string $baseUrl;
    protected ?string $apiKey;
    protected ?string $apiSecret;

    public function __construct(?StoreSetting $store = null)
    {
        $this->settings = $store ?: StoreSetting::current();
        $this->baseUrl = rtrim($this->settings->rb_base_url ?: 'http://127.0.0.1:8000/api/partner/v1', '/');
        $this->apiKey = $this->settings->rb_api_key;
        $this->apiSecret = $this->settings->rb_api_secret;
    }

    /**
     * Check if ResellerBrain API credentials are set
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && !empty($this->apiSecret);
    }

    /**
     * Get authentication headers for Partner API
     */
    protected function headers(): array
    {
        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-RB-API-KEY' => $this->apiKey,
            'X-RB-API-SECRET' => $this->apiSecret,
        ];
    }

    /**
     * Test handshake with ResellerBrain
     */
    public function verifyConnection(): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'ResellerBrain API Key and Secret are not configured for store: ' . $this->settings->store_name,
            ];
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders($this->headers())
                ->get("{$this->baseUrl}/auth/verify");

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'message' => 'Connected to ResellerBrain successfully.',
                    'store' => $data['data']['store'] ?? null,
                    'dropshipper' => $data['data']['dropshipper'] ?? null,
                ];
            }

            return [
                'success' => false,
                'message' => $response->json('message') ?: 'Failed to verify ResellerBrain credentials (HTTP ' . $response->status() . ').',
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Sync categories from ResellerBrain
     */
    public function syncCategories(): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'API credentials not configured.'];
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders($this->headers())
                ->get("{$this->baseUrl}/categories");

            if (!$response->successful()) {
                return ['success' => false, 'message' => 'Failed to fetch categories: ' . $response->body()];
            }

            $categories = $response->json('data') ?: [];
            $syncedCount = 0;

            foreach ($categories as $cat) {
                Category::updateOrCreate(
                    [
                        'store_id' => $this->settings->id,
                        'rb_category_id' => $cat['id'],
                    ],
                    [
                        'name' => $cat['name'],
                        'slug' => ($cat['slug'] ?: Str::slug($cat['name'])) . '-' . $this->settings->id,
                        'icon' => $cat['icon'],
                        'banner' => $cat['banner'] ?? null,
                        'is_active' => true,
                    ]
                );
                $syncedCount++;
            }

            return [
                'success' => true,
                'message' => "Successfully synced {$syncedCount} categories for {$this->settings->store_name}.",
                'count' => $syncedCount,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Category sync error: ' . $e->getMessage()];
        }
    }

    /**
     * Sync products catalog from ResellerBrain with profit markup rules
     */
    public function syncProducts(int $perPage = 50, ?string $search = null): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'API credentials not configured.'];
        }

        try {
            $queryParams = ['per_page' => $perPage];
            if ($search) {
                $queryParams['search'] = $search;
            }

            $response = Http::timeout(25)
                ->withHeaders($this->headers())
                ->get("{$this->baseUrl}/products", $queryParams);

            if (!$response->successful()) {
                return ['success' => false, 'message' => 'Failed to fetch products: ' . $response->body()];
            }

            $items = $response->json('data.products') ?: [];
            $categoriesByRbId = Category::where('store_id', $this->settings->id)
                ->whereNotNull('rb_category_id')
                ->pluck('id', 'rb_category_id')
                ->all();

            $created = 0;
            $updated = 0;

            foreach ($items as $item) {
                $rbId = (int) $item['id'];
                $wholesalePrice = (float) $item['admin_price'];
                $suggestedPrice = (float) ($item['suggested_price'] ?: ($wholesalePrice + $this->settings->default_markup_amount));
                $maxResellPrice = (float) ($item['max_resell_price'] ?: ($wholesalePrice * 1.5));

                $existingProduct = Product::where('store_id', $this->settings->id)
                    ->where('rb_product_id', $rbId)
                    ->first();

                // Map category
                $localCategoryId = null;
                if (!empty($item['category']['id']) && isset($categoriesByRbId[$item['category']['id']])) {
                    $localCategoryId = $categoriesByRbId[$item['category']['id']];
                }

                if ($existingProduct) {
                    // Maintain dropshipper's existing retail price unless wholesale increased above it
                    $retailPrice = max($existingProduct->retail_price, $wholesalePrice + 50.0);

                    $existingProduct->update([
                        'wholesale_price' => $wholesalePrice,
                        'retail_price' => $retailPrice,
                        'stock' => (int) $item['current_stock'],
                        'is_in_stock' => (bool) $item['in_stock'],
                        'weight' => (float) ($item['weight'] ?? 0.0),
                        'primary_image' => $item['primary_image_url'] ?: $existingProduct->primary_image,
                        'gallery_images' => $item['gallery_images'] ?: $existingProduct->gallery_images,
                        'description' => $item['description'] ?: $existingProduct->description,
                        'category_id' => $localCategoryId ?: $existingProduct->category_id,
                        'last_synced_at' => now(),
                    ]);
                    $updated++;
                } else {
                    // New product: calculate initial retail price
                    $markup = $this->settings->default_markup_type === 'percentage'
                        ? ($wholesalePrice * ($this->settings->default_markup_amount / 100))
                        : $this->settings->default_markup_amount;

                    $initialRetail = round(max($suggestedPrice, $wholesalePrice + $markup), 2);
                    $compareAt = round(max($maxResellPrice, $initialRetail * 1.2), 2);

                    Product::create([
                        'store_id' => $this->settings->id,
                        'source' => 'resellerbrain',
                        'rb_product_id' => $rbId,
                        'category_id' => $localCategoryId,
                        'name' => $item['name'],
                        'slug' => ($item['slug'] ?: Str::slug($item['name'])) . '-' . $this->settings->id . '-' . $rbId,
                        'sku' => $item['sku'] ?: ('RB-' . $rbId),
                        'wholesale_price' => $wholesalePrice,
                        'retail_price' => $initialRetail,
                        'compare_at_price' => $compareAt,
                        'stock' => (int) $item['current_stock'],
                        'is_in_stock' => (bool) $item['in_stock'],
                        'weight' => (float) ($item['weight'] ?? 0.0),
                        'description' => $item['description'] ?? '',
                        'primary_image' => $item['primary_image_url'] ?? null,
                        'gallery_images' => $item['gallery_images'] ?? [],
                        'is_featured' => false,
                        'is_active' => true,
                        'last_synced_at' => now(),
                    ]);
                    $created++;
                }
            }

            $this->settings->update(['last_synced_at' => now()]);

            return [
                'success' => true,
                'message' => "Sync complete: {$created} new products imported, {$updated} updated.",
                'created' => $created,
                'updated' => $updated,
                'total_synced' => count($items),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Product sync error: ' . $e->getMessage()];
        }
    }

    /**
     * Automatically forward a retail customer order to ResellerBrain for white-label fulfillment
     */
    public function forwardOrderToResellerBrain(Order $order): array
    {
        // Use the order's store settings if available
        $orderStore = $order->store ?: $this->settings;
        $orderService = ($orderStore->id === $this->settings->id) ? $this : new self($orderStore);

        if (!$orderService->isConfigured()) {
            $order->update([
                'rb_status' => 'failed',
                'rb_error' => 'ResellerBrain API keys not configured in store settings for store ' . $orderStore->store_name,
            ]);
            return ['success' => false, 'message' => 'API keys not configured.'];
        }

        // Get only ResellerBrain-sourced items
        $rbItems = $order->items()->where('source', 'resellerbrain')->whereNotNull('rb_product_id')->get();
        if ($rbItems->isEmpty()) {
            return [
                'success' => true,
                'message' => 'No ResellerBrain items in this order. Handled as local fulfillment.',
            ];
        }

        $itemsPayload = $rbItems->map(function ($item) {
            return [
                'product_id' => (int) $item->rb_product_id,
                'quantity' => (int) $item->quantity,
                'selling_price' => (float) $item->unit_price,
            ];
        })->all();

        $payload = [
            'external_order_id' => $order->order_number,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'shipping_address' => $order->shipping_address,
            'district' => $order->district,
            'zone' => $order->zone,
            'area' => $order->area,
            'shipping_fee' => (float) $order->shipping_fee,
            'note' => $order->customer_note,
            'items' => $itemsPayload,
        ];

        try {
            $response = Http::timeout(20)
                ->withHeaders($orderService->headers())
                ->post("{$orderService->baseUrl}/orders/create", $payload);

            if ($response->successful()) {
                $result = $response->json('data');
                $order->update([
                    'has_rb_items' => true,
                    'rb_order_number' => $result['order_number'] ?? null,
                    'rb_status' => 'forwarded',
                    'rb_error' => null,
                ]);

                return [
                    'success' => true,
                    'message' => 'Order successfully forwarded to ResellerBrain for fulfillment.',
                    'rb_order_number' => $result['order_number'] ?? null,
                    'reseller_profit' => $result['reseller_profit'] ?? 0.0,
                ];
            }

            $errorMsg = $response->json('message') ?: 'ResellerBrain error: ' . $response->body();
            $order->update([
                'has_rb_items' => true,
                'rb_status' => 'failed',
                'rb_error' => $errorMsg,
            ]);

            return [
                'success' => false,
                'message' => $errorMsg,
            ];
        } catch (\Throwable $e) {
            $order->update([
                'has_rb_items' => true,
                'rb_status' => 'failed',
                'rb_error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to send order to ResellerBrain: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Check live fulfillment & courier tracking status of an order in ResellerBrain
     */
    public function checkOrderStatus(Order $order): array
    {
        if (!$order->rb_order_number) {
            return ['success' => false, 'message' => 'No ResellerBrain order reference found.'];
        }

        $orderStore = $order->store ?: $this->settings;
        $orderService = ($orderStore->id === $this->settings->id) ? $this : new self($orderStore);

        try {
            $response = Http::timeout(10)
                ->withHeaders($orderService->headers())
                ->get("{$orderService->baseUrl}/orders/{$order->rb_order_number}/status");

            if ($response->successful()) {
                $data = $response->json('data');
                $order->update([
                    'rb_status' => $data['status_name'] ?? $order->rb_status,
                    'rb_consignment_id' => $data['courier']['consignment_id'] ?? $order->rb_consignment_id,
                ]);

                return [
                    'success' => true,
                    'status_name' => $data['status_name'],
                    'courier' => $data['courier'] ?? null,
                ];
            }

            return ['success' => false, 'message' => 'Unable to fetch status'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
