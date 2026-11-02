<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'source',
        'rb_product_id',
        'category_id',
        'name',
        'slug',
        'sku',
        'wholesale_price',
        'retail_price',
        'compare_at_price',
        'stock',
        'is_in_stock',
        'weight',
        'description',
        'primary_image',
        'gallery_images',
        'is_featured',
        'is_active',
        'last_synced_at',
    ];

    protected $casts = [
        'wholesale_price' => 'float',
        'retail_price' => 'float',
        'compare_at_price' => 'float',
        'stock' => 'integer',
        'is_in_stock' => 'boolean',
        'weight' => 'float',
        'gallery_images' => 'array',
        'is_featured' => 'boolean',
        'is_active' => 'boolean',
        'last_synced_at' => 'datetime',
        'rb_product_id' => 'integer',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Compute estimated profit per unit
     */
    public function getUnitProfitAttribute(): float
    {
        return max(0, round($this->retail_price - $this->wholesale_price, 2));
    }
}
