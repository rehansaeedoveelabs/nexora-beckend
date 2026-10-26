<?php

namespace App\Services\Setup;

use App\Models\Shop;

/**
 * Setup guide whose progress is DERIVED from real data, never ticked by hand.
 * (Charlie marks "configure locations" done while every location is missing its type.)
 */
class SetupProgress
{
    public const COMPLETE = 'complete';
    public const INCOMPLETE = 'incomplete';
    public const UPCOMING = 'upcoming';

    public function for(Shop $shop): array
    {
        $active = $shop->locations()->active();
        $activeCount = (clone $active)->count();
        $untyped = (clone $active)->untyped()->count();

        $steps = [
            [
                'key' => 'sync_locations',
                'title' => 'Import your Shopify locations',
                'description' => 'Nexora reads your stores, warehouses and fulfillment apps from Shopify and keeps them in sync automatically.',
                'status' => $shop->locations_synced_at && $activeCount > 0 ? self::COMPLETE : self::INCOMPLETE,
                'detail' => $shop->locations_synced_at
                    ? trans_choice('{0} No active locations found in Shopify|{1} 1 active location imported|[2,*] :count active locations imported', $activeCount)
                    : 'Importing…',
                'action' => ['label' => 'View locations', 'url' => '/locations'],
            ],
            [
                'key' => 'classify_locations',
                'title' => 'Tell Nexora what each location is',
                'description' => 'Mark each location as a store, warehouse, or third-party fulfillment. Routing rules use this to decide where orders ship from.',
                'status' => $activeCount > 0 && $untyped === 0 ? self::COMPLETE : self::INCOMPLETE,
                'detail' => $activeCount === 0
                    ? 'Import locations first'
                    : ($untyped === 0
                        ? 'All active locations have a type'
                        : trans_choice('{1} 1 location still needs a type|[2,*] :count locations still need a type', $untyped)),
                'action' => ['label' => 'Set location types', 'url' => '/locations?tab=untyped'],
            ],
            [
                'key' => 'safety_stock',
                'title' => 'Protect in-store stock with safety stock',
                'description' => 'Hold back units at each store so online orders never take the last item off the shelf.',
                'status' => self::UPCOMING,
                'detail' => 'Available in the next release',
                'action' => null,
            ],
            [
                'key' => 'routing_rules',
                'title' => 'Decide where orders ship from',
                'description' => 'Rank your locations and add rules like "ship fragile items only from warehouses". Test them with the order simulator before going live.',
                'status' => self::UPCOMING,
                'detail' => 'Available in the next release',
                'action' => null,
            ],
        ];

        $actionable = array_filter($steps, fn ($s) => $s['status'] !== self::UPCOMING);
        $completed = count(array_filter($actionable, fn ($s) => $s['status'] === self::COMPLETE));

        return [
            'steps' => $steps,
            'completed' => $completed,
            'total' => count($actionable),
            'is_complete' => $completed === count($actionable),
        ];
    }
}
