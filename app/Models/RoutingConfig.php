<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoutingConfig extends Model
{
    protected $fillable = [
        'settings',
        'compiled',
        'schema_version',
        'checkout_sync_status',
        'checkout_sync_error',
        'checkout_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'compiled' => 'array',
            'schema_version' => 'integer',
            'checkout_synced_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
