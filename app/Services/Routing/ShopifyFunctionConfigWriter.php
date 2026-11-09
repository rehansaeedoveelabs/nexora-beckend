<?php

namespace App\Services\Routing;

use App\Exceptions\ShopifyApiException;
use App\Models\Shop;
use App\Services\Shopify\AdminGraphql;
use JsonException;

class ShopifyFunctionConfigWriter
{
    private const MAX_CONFIG_BYTES = 60_000;

    private const METAFIELD_NAMESPACE = '$app:fulfillment-constraints';

    private const METAFIELD_KEY = 'function-configuration';

    public function __construct(private readonly AdminGraphql $graphql) {}

    /**
     * Create the app-owned constraint rule or replace its configuration metafield.
     *
     * @throws JsonException
     */
    public function sync(Shop $shop, array $config): void
    {
        $value = json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($value) > self::MAX_CONFIG_BYTES) {
            throw new ShopifyApiException('Checkout configuration is too large to sync safely; narrow the product rules.');
        }

        $metafield = [
            'namespace' => self::METAFIELD_NAMESPACE,
            'key' => self::METAFIELD_KEY,
            'type' => 'json',
            'value' => $value,
        ];

        if ($shop->fulfillment_constraint_rule_id !== null) {
            $this->updateMetafield($shop, $metafield);

            return;
        }

        $payload = $this->graphql->mutate(
            $shop,
            <<<'GRAPHQL'
            mutation CreateNexoraFulfillmentConstraintRule(
                $functionHandle: String!
                $deliveryMethodTypes: [DeliveryMethodType!]!
                $metafields: [MetafieldInput!]
            ) {
                fulfillmentConstraintRuleCreate(
                    functionHandle: $functionHandle
                    deliveryMethodTypes: $deliveryMethodTypes
                    metafields: $metafields
                ) {
                    fulfillmentConstraintRule {
                        id
                        function { id }
                    }
                    userErrors { field message }
                }
            }
            GRAPHQL,
            [
                'functionHandle' => config('shopify.fulfillment_constraint_function_handle'),
                'deliveryMethodTypes' => ['SHIPPING'],
                'metafields' => [$metafield],
            ],
            'fulfillmentConstraintRuleCreate',
        );

        $rule = $payload['fulfillmentConstraintRule'] ?? null;
        if (! is_array($rule) || ! is_string($rule['id'] ?? null) || ! is_string($rule['function']['id'] ?? null)) {
            throw new ShopifyApiException('Shopify did not return the created fulfillment constraint rule.');
        }

        $shop->forceFill([
            'fulfillment_constraint_rule_id' => $rule['id'],
            'fulfillment_function_id' => $rule['function']['id'],
        ])->save();
    }

    private function updateMetafield(Shop $shop, array $metafield): void
    {
        $this->graphql->mutate(
            $shop,
            <<<'GRAPHQL'
            mutation SetNexoraFulfillmentConstraintConfig($metafields: [MetafieldsSetInput!]!) {
                metafieldsSet(metafields: $metafields) {
                    metafields { id }
                    userErrors { field message }
                }
            }
            GRAPHQL,
            [
                'metafields' => [[
                    ...$metafield,
                    'ownerId' => $shop->fulfillment_constraint_rule_id,
                ]],
            ],
            'metafieldsSet',
        );
    }
}
