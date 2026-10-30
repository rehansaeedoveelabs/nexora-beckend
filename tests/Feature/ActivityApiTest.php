<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithShopify;
use Tests\TestCase;

class ActivityApiTest extends TestCase
{
    use InteractsWithShopify, RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = $this->installedShop();
    }

    public function test_activity_is_paginated_and_scoped_to_the_verified_shop(): void
    {
        $otherShop = $this->installedShop('other.myshopify.com');
        foreach (range(1, 3) as $index) {
            $this->shop->activityEvents()->create([
                'type' => $index === 2 ? 'location_settings_updated' : 'routing_config_updated',
                'subject' => "Subject {$index}",
                'summary' => "Change {$index}",
                'occurred_at' => now()->subMinutes($index),
            ]);
        }
        $otherShop->activityEvents()->create([
            'type' => 'routing_config_updated',
            'subject' => 'Other shop',
            'summary' => 'Must not be visible',
            'occurred_at' => now(),
        ]);

        $this->apiAs($this->shop)->getJson('/api/activity?first=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.subject', 'Subject 1')
            ->assertJsonPath('data.0.typeLabel', 'Routing configuration updated')
            ->assertJsonPath('meta.page_info.has_next_page', true)
            ->assertJsonMissing(['subject' => 'Other shop']);
    }

    public function test_activity_supports_type_filter_and_cursor_pagination(): void
    {
        foreach (range(1, 3) as $index) {
            $this->shop->activityEvents()->create([
                'type' => 'location_settings_updated',
                'subject' => "Location {$index}",
                'summary' => "Change {$index}",
                'occurred_at' => now()->subMinutes($index),
            ]);
        }
        $this->shop->activityEvents()->create([
            'type' => 'routing_config_updated',
            'subject' => 'Routing',
            'summary' => 'Routing changed',
            'occurred_at' => now(),
        ]);

        $firstPage = $this->apiAs($this->shop)->getJson('/api/activity?first=2&type=location_settings_updated')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.page_info.has_next_page', true);
        $cursor = $firstPage->json('meta.page_info.end_cursor');

        $this->apiAs($this->shop)->getJson('/api/activity?first=2&type=location_settings_updated&after='.urlencode($cursor))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject', 'Location 3')
            ->assertJsonPath('meta.page_info.has_next_page', false);
    }

    public function test_activity_rejects_invalid_page_sizes(): void
    {
        $this->apiAs($this->shop)->getJson('/api/activity?first=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['first']);
    }
}
