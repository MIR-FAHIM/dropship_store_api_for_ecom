<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'name',
        'slug',
        'icon',
        'banner',
        'rb_category_id',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'store_id' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'rb_category_id' => 'integer',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(StoreSetting::class, 'store_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
