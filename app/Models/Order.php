<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number',
        'customer_name',
        'customer_phone',
        'shipping_address',
        'district',
        'zone',
        'area',
        'subtotal',
        'shipping_fee',
        'discount',
        'total',
        'total_profit',
        'payment_method',
        'payment_status',
        'status',
        'customer_note',
        'has_rb_items',
        'rb_order_number',
        'rb_status',
        'rb_consignment_id',
        'rb_error',
    ];

    protected $casts = [
        'subtotal' => 'float',
        'shipping_fee' => 'float',
        'discount' => 'float',
        'total' => 'float',
        'total_profit' => 'float',
        'has_rb_items' => 'boolean',
    ];

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }
}
