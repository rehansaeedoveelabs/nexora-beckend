<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Location extends Model
{
    public const TYPE_STORE = 'store';
    public const TYPE_WAREHOUSE = 'warehouse';
    public const TYPE_THIRD_PARTY = 'third_party';

    public const TYPES = [
        self::TYPE_STORE,
        self::TYPE_WAREHOUSE,
        self::TYPE_THIRD_PARTY,
    ];

    protected $fillable = [
        'shopify_id',
        'name',
        'address1',
        'city',
        'province',
        'province_code',
        'country_code',
        'zip',
        'formatted_address',
        'is_active',
        'ships',
        'pickup',
        'managed_by_app',
        'type',
        'tags',
        'daily_order_cap',
        'backlog_cap',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'ships' => 'boolean',
            'pickup' => 'boolean',
            'tags' => 'array',
            'daily_order_cap' => 'integer',
            'backlog_cap' => 'integer',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeUntyped(Builder $query): Builder
    {
        return $query->whereNull('type');
    }
}
