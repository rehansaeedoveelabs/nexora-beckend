<?php

namespace App\Http\Resources;

use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Location
 */
class LocationResource extends JsonResource
{
    public const TYPE_LABELS = [
        Location::TYPE_STORE => 'Store',
        Location::TYPE_WAREHOUSE => 'Warehouse',
        Location::TYPE_THIRD_PARTY => 'Third-party fulfillment',
    ];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'address' => $this->formatted_address,
            'city' => $this->city,
            'country_code' => $this->country_code,
            'is_active' => $this->is_active,
            'ships' => $this->ships,
            'pickup' => $this->pickup,
            'managed_by_app' => $this->managed_by_app,
            'type' => $this->type,
            'type_label' => self::TYPE_LABELS[$this->type] ?? null,
            'tags' => $this->tags ?? [],
            'daily_order_cap' => $this->daily_order_cap,
            'backlog_cap' => $this->backlog_cap,
            'needs_attention' => $this->is_active && $this->type === null,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
