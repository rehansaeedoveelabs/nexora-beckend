<?php

namespace App\Services\Locations;

use App\Models\Location;
use App\Models\Shop;
use App\Services\Shopify\AdminGraphql;

/**
 * Mirrors Shopify locations into MySQL. Only Shopify-owned columns are written;
 * Nexora-owned configuration (type, tags, caps) is never overwritten by a sync.
 */
class LocationSync
{
    private const FIELDS = <<<'GQL'
        id
        name
        isActive
        fulfillsOnlineOrders
        address { address1 city province provinceCode countryCode zip formatted }
        localPickupSettingsV2 { pickupTime }
        fulfillmentService { serviceName }
    GQL;

    public function __construct(private readonly AdminGraphql $graphql) {}

    /**
     * Full import. Locations that no longer exist in Shopify are removed.
     */
    public function syncAll(Shop $shop): int
    {
        $query = 'query Locations($cursor: String) {
            locations(first: 100, after: $cursor, includeInactive: true, includeLegacy: true) {
                pageInfo { hasNextPage endCursor }
                nodes { '.self::FIELDS.' }
            }
        }';

        $seen = [];
        $cursor = null;

        do {
            $data = $this->graphql->query($shop, $query, ['cursor' => $cursor]);
            $page = $data['locations'] ?? ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]];

            foreach ($page['nodes'] as $node) {
                $this->upsert($shop, $node);
                $seen[] = $node['id'];
            }

            $cursor = $page['pageInfo']['endCursor'] ?? null;
        } while (($page['pageInfo']['hasNextPage'] ?? false) && $cursor !== null);

        $shop->locations()->whereNotIn('shopify_id', $seen)->delete();
        $shop->forceFill(['locations_synced_at' => now()])->save();

        return count($seen);
    }

    /**
     * Refresh a single location (webhook path). Removes it if Shopify no longer has it.
     */
    public function syncOne(Shop $shop, string $gid): ?Location
    {
        $query = 'query Location($id: ID!) { location(id: $id) { '.self::FIELDS.' } }';
        $node = $this->graphql->query($shop, $query, ['id' => $gid])['location'] ?? null;

        if ($node === null) {
            $this->remove($shop, $gid);

            return null;
        }

        return $this->upsert($shop, $node);
    }

    public function remove(Shop $shop, string $gid): void
    {
        $shop->locations()->where('shopify_id', $gid)->delete();
    }

    public function upsert(Shop $shop, array $node): Location
    {
        $address = $node['address'] ?? [];
        $managedBy = $node['fulfillmentService']['serviceName'] ?? null;

        $location = $shop->locations()->firstOrNew(['shopify_id' => $node['id']]);
        $location->fill([
            'name' => $node['name'],
            'address1' => $address['address1'] ?? null,
            'city' => $address['city'] ?? null,
            'province' => $address['province'] ?? null,
            'province_code' => $address['provinceCode'] ?? null,
            'country_code' => $address['countryCode'] ?? null,
            'zip' => $address['zip'] ?? null,
            'formatted_address' => mb_substr(implode(', ', $address['formatted'] ?? []), 0, 500) ?: null,
            'is_active' => (bool) ($node['isActive'] ?? true),
            'ships' => (bool) ($node['fulfillsOnlineOrders'] ?? false),
            'pickup' => ! empty($node['localPickupSettingsV2']),
            'managed_by_app' => $managedBy,
        ]);

        // A brand-new app-managed location is almost always a 3PL; pre-classify it,
        // the merchant can still change it.
        if (! $location->exists && $managedBy !== null && $location->type === null) {
            $location->type = Location::TYPE_THIRD_PARTY;
        }

        $location->save();

        return $location;
    }

    public static function gidFromWebhook(array $payload): ?string
    {
        if (! empty($payload['admin_graphql_api_id'])) {
            return $payload['admin_graphql_api_id'];
        }

        return isset($payload['id']) ? 'gid://shopify/Location/'.$payload['id'] : null;
    }
}
