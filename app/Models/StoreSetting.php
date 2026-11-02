<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreSetting extends Model
{
    use HasFactory;

    protected $table = 'store_settings';

    protected $fillable = [
        'store_name',
        'store_tagline',
        'store_phone',
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
        'last_synced_at',
    ];

    protected $casts = [
        'delivery_inside_dhaka' => 'float',
        'delivery_outside_dhaka' => 'float',
        'default_markup_amount' => 'float',
        'auto_forward_orders' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    /**
     * Singleton helper to get or initialize default store settings
     */
    public static function current(): self
    {
        return self::firstOrCreate(['id' => 1], [
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
