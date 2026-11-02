<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'source',
        'rb_product_id',
        'product_name',
        'sku',
        'primary_image',
        'unit_price',
        'wholesale_price',
        'quantity',
        'line_total',
        'line_profit',
    ];

    protected $casts = [
        'unit_price' => 'float',
        'wholesale_price' => 'float',
        'quantity' => 'integer',
        'line_total' => 'float',
        'line_profit' => 'float',
        'rb_product_id' => 'integer',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
