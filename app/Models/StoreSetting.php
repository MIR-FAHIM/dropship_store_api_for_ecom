<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreSetting extends Model
{
    use HasFactory;

    protected $table = 'store_settings';

    protected $fillable = [
        'domain',
        'store_name',
        'store_tagline',
        'store_phone',
        'whatsapp_number',
        'store_email',
        'store_address',
        'announcement_text',
        'logo_url',
        'banner_url',
        'primary_color',
        'delivery_inside_dhaka',
        'delivery_outside_dhaka',
        'rb_api_key',
        'rb_api_secret',
        'rb_base_url',
        'default_markup_amount',
        'default_markup_type',
        'auto_forward_orders',
        'is_active',
        'last_synced_at',
    ];

    protected $casts = [
        'delivery_inside_dhaka' => 'float',
        'delivery_outside_dhaka' => 'float',
        'default_markup_amount' => 'float',
        'auto_forward_orders' => 'boolean',
        'is_active' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class, 'store_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'store_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'store_id');
    }

    /**
     * Resolve store for a specific domain name (stripping www.)
     */
    public static function forDomain(string $domain): ?self
    {
        $clean = preg_replace('/^www\./', '', strtolower(trim($domain)));
        return self::where('domain', $clean)->first();
    }

    /**
     * Resolve the active store:
     * 1. If 'current.store' is bound in container by ResolveCurrentStore middleware, use it.
     * 2. Else fallback to store for request host if found.
     * 3. Else fallback to default store id=1 (or create default).
     */
    public static function current(): self
    {
        if (app()->has('current.store')) {
            return app('current.store');
        }

        if (app()->runningInConsole()) {
            return self::firstOrCreate(['id' => 1], [
                'domain' => 'shops.resellerbrain.com',
                'store_name' => 'My Dropship Store',
                'store_tagline' => 'সেরা মানের পণ্য ও দ্রুত ডেলিভারি',
                'store_phone' => '01700000000',
                'announcement_text' => 'সারাদেশে হোম ডেলিভারি | ক্যাশ অন ডেলিভারি সুবিধা!',
                'primary_color' => '#2563eb',
                'delivery_inside_dhaka' => 70.00,
                'delivery_outside_dhaka' => 130.00,
                'rb_base_url' => 'http://127.0.0.1:8000/api/partner/v1',
                'default_markup_amount' => 250.00,
                'default_markup_type' => 'fixed',
                'auto_forward_orders' => true,
            ]);
        }

        $host = request()->getHost();
        $store = self::forDomain($host);

        if ($store) {
            app()->instance('current.store', $store);
            return $store;
        }

        return self::firstOrCreate(['id' => 1], [
            'domain' => 'shops.resellerbrain.com',
            'store_name' => 'My Dropship Store',
            'store_tagline' => 'সেরা মানের পণ্য ও দ্রুত ডেলিভারি',
            'store_phone' => '01700000000',
            'announcement_text' => 'সারাদেশে হোম ডেলিভারি | ক্যাশ অন ডেলিভারি সুবিধা!',
            'primary_color' => '#2563eb',
            'delivery_inside_dhaka' => 70.00,
            'delivery_outside_dhaka' => 130.00,
            'rb_base_url' => 'http://127.0.0.1:8000/api/partner/v1',
            'default_markup_amount' => 250.00,
            'default_markup_type' => 'fixed',
            'auto_forward_orders' => true,
        ]);
    }
}
