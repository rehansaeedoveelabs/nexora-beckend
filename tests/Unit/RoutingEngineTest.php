<?php

namespace Tests\Unit;

use App\Services\Routing\ReasonFormatter;
use App\Services\Routing\RoutingEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RoutingEngineTest extends TestCase
{
    public static function goldenCases(): array
    {
        $file = json_decode(file_get_contents(__DIR__.'/../Fixtures/routing/cases.json'), true, flags: JSON_THROW_ON_ERROR);

        return array_combine(
            array_column($file['cases'], 'name'),
            array_map(fn ($case) => [$case], $file['cases']),
        );
    }

    #[DataProvider('goldenCases')]
    public function test_golden_case(array $case): void
    {
        $result = (new RoutingEngine)->route($case['config'], $case['scenario']);
        $expected = $case['expected'];

        $this->assertSame($expected['outcome'], $result['outcome']);
        $this->assertSame($expected['shipments'], $result['shipments']);
        $this->assertSame($expected['ranking'], array_column($result['locations'], 'id'));
        $this->assertSame($expected['reason_codes'], array_column($result['reasons'], 'code'));

        foreach ($expected['location_reason_codes'] ?? [] as $locationId => $codes) {
            $location = collect($result['locations'])->firstWhere('id', $locationId);
            $this->assertSame($codes, array_column($location['reasons'], 'code'), "Reasons for {$locationId}");
        }
    }

    /**
     * The shared-with-Rust contract: ranking + per-line rule eligibility. The Rust Shopify Functions
     * assert the SAME fixtures (extensions/routing-core), so if the two engines ever diverge, one suite fails.
     */
    #[DataProvider('goldenCases')]
    public function test_shared_logic_matches_frozen_expectations(array $case): void
    {
        $engine = new RoutingEngine;

        $this->assertSame(
            $case['expected']['ranking'],
            $engine->rankedIds($case['config'], $case['scenario']),
            'ranking',
        );

        $this->assertSame(
            $case['expected']['eligible_by_line'] ?? [],
            $engine->eligibleByLine($case['config'], $case['scenario']),
            'eligible_by_line',
        );
    }

    public function test_every_reason_code_has_a_sentence(): void
    {
        $formatter = new ReasonFormatter(['A' => 'Alpha'], ['1' => 'Mug'], ['r1' => 'products tagged “fragile” only ship from warehouses']);

        foreach (self::goldenCases() as [$case]) {
            $result = $formatter->decorate((new RoutingEngine)->route($case['config'], $case['scenario']));

            foreach ([...$result['reasons'], ...array_merge(...array_column($result['locations'], 'reasons'))] as $reason) {
                $this->assertStringNotContainsString('Routing detail:', $reason['message'], "No sentence for {$reason['code']}");
            }
        }
    }

    public function test_sentences_name_locations_items_and_rules(): void
    {
        $formatter = new ReasonFormatter(['W' => 'Lahore warehouse'], ['1' => 'Blue vase'], ['r1' => 'products tagged “fragile” only ship from warehouses']);

        $this->assertSame(
            'Lahore warehouse has 1 of Blue vase in stock, but the order needs 3.',
            $formatter->format(['code' => 'insufficient_stock', 'location' => 'W', 'line' => '1', 'available' => 1, 'needed' => 3]),
        );
        $this->assertSame(
            'Lahore warehouse can’t ship Blue vase because of your rule: products tagged “fragile” only ship from warehouses.',
            $formatter->format(['code' => 'rule_excludes', 'location' => 'W', 'line' => '1', 'rule_id' => 'r1']),
        );
    }
}
