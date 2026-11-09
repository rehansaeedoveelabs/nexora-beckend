<?php

namespace Tests\Unit;

use App\Exceptions\ShopifyApiException;
use App\Models\Shop;
use App\Services\Flow\FlowTriggerEmitter;
use App\Services\Shopify\AdminGraphql;
use Tests\TestCase;

class FlowTriggerEmitterTest extends TestCase
{
    private function shop(): Shop
    {
        $shop = new Shop;
        $shop->id = 1;
        $shop->domain = 'example.myshopify.com';

        return $shop;
    }

    public function test_emits_configured_trigger_with_handle_and_payload(): void
    {
        config(['shopify.flow_triggers.order_rerouted' => 'nexora-order-rerouted']);

        $graphql = $this->createMock(AdminGraphql::class);
        $graphql->expects($this->once())
            ->method('mutate')
            ->with(
                $this->isInstanceOf(Shop::class),
                $this->stringContains('flowTriggerReceive'),
                $this->callback(function (array $vars): bool {
                    return $vars['handle'] === 'nexora-order-rerouted'
                        && is_object($vars['payload'])
                        && $vars['payload']->order_id === 42;
                }),
                'flowTriggerReceive',
            )
            ->willReturn([]);

        $emitter = new FlowTriggerEmitter($graphql);

        $this->assertTrue($emitter->emit($this->shop(), 'order_rerouted', [
            'order_id' => 42,
            'destination' => 'Warehouse A',
        ]));
    }

    public function test_skips_silently_when_trigger_is_unconfigured(): void
    {
        config(['shopify.flow_triggers.order_rerouted' => null]);

        $graphql = $this->createMock(AdminGraphql::class);
        $graphql->expects($this->never())->method('mutate');

        $emitter = new FlowTriggerEmitter($graphql);

        $this->assertFalse($emitter->emit($this->shop(), 'order_rerouted', ['order_id' => 'gid://shopify/Order/42']));
    }

    public function test_swallows_shopify_errors_without_throwing(): void
    {
        config(['shopify.flow_triggers.order_rerouted' => 'nexora-order-rerouted']);

        $graphql = $this->createMock(AdminGraphql::class);
        $graphql->method('mutate')
            ->willThrowException(new ShopifyApiException('No Flow trigger found for handle.', [], 422));

        $emitter = new FlowTriggerEmitter($graphql);

        // Must not propagate: a missing/undeployed trigger cannot break routing.
        $this->assertFalse($emitter->emit($this->shop(), 'order_rerouted', ['order_id' => 'gid://shopify/Order/42']));
    }
}
