<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoutingConfig extends Model
{
    protected $fillable = ['settings', 'compiled', 'schema_version'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'compiled' => 'array',
            'schema_version' => 'integer',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
