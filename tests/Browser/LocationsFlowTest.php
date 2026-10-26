<?php

namespace Tests\Browser;

use App\Models\Location;
use App\Models\Shop;
use Facebook\WebDriver\WebDriverBy;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Assert;
use Tests\DuskTestCase;

/**
 * End-to-end: real frontend (React + Polaris) + real API + real DB, driven through Chrome.
 *
 * Polaris renders headings/labels inside shadow DOM and React sets their props as element
 * properties, so the helpers below read text through shadow roots and find elements by property.
 */
class LocationsFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    private const WAIT = 15;

    /**
     * Visible text of a node: light DOM, open shadow roots, plus the text Polaris renders from
     * component props (heading, label, error, details), which may live in closed shadow roots.
     */
    private const DEEP_TEXT = <<<'JS'
        const PROPS = ['heading', 'label', 'error', 'details'];
        const walk = (node) => {
            if (node.tagName === 'STYLE' || node.tagName === 'SCRIPT') return ' ';
            let text = node.shadowRoot ? walk(node.shadowRoot) : '';
            if (node.tagName?.startsWith('S-')) {
                for (const prop of PROPS) if (typeof node[prop] === 'string' && node[prop]) text += ` ${node[prop]} `;
            }
            for (const child of node.childNodes) {
                // Adjacent text nodes join as-is (React splits `${n} selected` into two nodes); elements get a separator.
                if (child.nodeType === Node.TEXT_NODE) text += child.textContent;
                else if (child.nodeType === Node.ELEMENT_NODE) text += ` ${walk(child)} `;
            }
            return text;
        };
        const deepText = (node) => walk(node).replace(/\s+/g, ' ');
    JS;

    protected function setUp(): void
    {
        parent::setUp();

        // Installed shop: holds a valid token pair, so no call to Shopify is ever made.
        $shop = Shop::create([
            'domain' => self::SHOP,
            'name' => 'oveelab',
            'access_token' => 'shpat_e2e',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token' => 'shprt_e2e',
            'refresh_token_expires_at' => now()->addDays(90),
            'installed_at' => now(),
            'locations_synced_at' => now(),
        ]);

        $rows = [
            ['Lahore warehouse', 'Johar Town, Lahore 54000, Pakistan', true, false, null, null],
            ['My Custom Location', '123 Main St, Toronto ON A1A 1A1, Canada', true, true, null, null],
            ['Shop location', 'Gaage Ln, Hugo MN 55038, United States', true, true, Location::TYPE_STORE, null],
            ['Snow City Warehouse', 'United States', false, false, Location::TYPE_THIRD_PARTY, 'Snow City 3PL'],
        ];

        foreach ($rows as $i => [$name, $address, $ships, $pickup, $type, $app]) {
            $shop->locations()->create([
                'shopify_id' => "gid://shopify/Location/{$i}",
                'name' => $name,
                'formatted_address' => $address,
                'is_active' => true,
                'ships' => $ships,
                'pickup' => $pickup,
                'type' => $type,
                'managed_by_app' => $app,
            ]);
        }
    }

    public function test_home_shows_a_truthful_setup_guide_and_explained_numbers(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/');

            $this->waitForText($browser, 'Get Nexora ready');
            $this->assertSees($browser, [
                '1 of 2 steps done',
                '2 locations still need a type',
                'Coming soon',
                'Need a type',
                'Active locations Nexora can’t route to yet',
                'How Nexora works',
            ]);
        });
    }

    public function test_locations_page_explains_everything_in_words(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/locations');

            $this->waitForText($browser, 'Lahore warehouse');
            $this->assertSees($browser, [
                '2 locations need a type',
                'Ships orders',
                'Local pickup',
                'Doesn’t fulfill online orders',
                'Managed by Snow City 3PL',
                'No limit',
                'What do the types mean?',
            ]);
        });
    }

    public function test_merchant_fixes_missing_types_inline_and_setup_completes(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/locations');
            $this->waitForText($browser, '2 locations need a type');

            $this->choose($browser, 's-select', 'Type for Lahore warehouse', 'warehouse');
            $this->waitForText($browser, '1 location needs a type');
            $this->waitForText($browser, 'Lahore warehouse is now warehouse');

            $this->choose($browser, 's-select', 'Type for My Custom Location', 'store');
            $this->waitUntilMissingText($browser, 'needs a type');

            $browser->visit('/');
            $this->waitForText($browser, 'Your locations are ready');
            $this->assertDoesNotSee($browser, 'Get Nexora ready');
        });

        $this->assertSame('warehouse', Location::where('name', 'Lahore warehouse')->value('type'));
        $this->assertSame('store', Location::where('name', 'My Custom Location')->value('type'));
    }

    public function test_setup_guide_deep_link_opens_the_filtered_fix_view(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/');
            $this->waitForText($browser, 'Set location types');

            $this->clickButton($browser, 'Set location types');

            $this->waitForText($browser, 'My Custom Location');
            $browser->assertPathIs('/locations')->assertQueryStringHas('tab', 'untyped');
            $this->assertSees($browser, ['Lahore warehouse']);
            $this->assertDoesNotSee($browser, 'Shop location');
        });
    }

    public function test_bulk_set_type_for_selected_locations(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/locations?tab=untyped');
            $this->waitForText($browser, 'Lahore warehouse');

            $this->tick($browser, 'Select Lahore warehouse');
            $this->tick($browser, 'Select My Custom Location');
            $this->waitForText($browser, '2 selected');

            $this->clickButton($browser, 'Apply');

            $this->waitForText($browser, '2 locations set to store');
            $this->waitForText($browser, 'Every active location has a type');
        });

        $this->assertSame(0, Location::whereNull('type')->count());
    }

    public function test_search_narrows_the_list_and_updates_the_url(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/locations');
            $this->waitForText($browser, 'Lahore warehouse');

            $browser->script(<<<'JS'
                const field = document.querySelector('s-search-field');
                field.value = 'toronto';
                field.dispatchEvent(new Event('input', { bubbles: true }));
            JS);

            $this->waitUntilMissingText($browser, 'Lahore warehouse');
            $this->assertSees($browser, ['My Custom Location']);
            $browser->assertQueryStringHas('q', 'toronto');
        });
    }

    public function test_merchant_edits_limits_and_tags(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/locations');
            $this->waitForText($browser, 'Shop location');

            $this->openEditModal($browser, 'Shop location');

            $browser->script(<<<'JS'
                const modal = document.getElementById('nx-location-modal');
                const set = (el, value) => { el.value = value; el.dispatchEvent(new Event('input', { bubbles: true })); };
                const [daily, backlog] = modal.querySelectorAll('s-number-field');
                set(daily, '40');
                set(backlog, '120');
                set(modal.querySelector('s-text-field'), 'flagship, north');
            JS);
            $this->clickButton($browser, 'Save');

            $this->waitForText($browser, 'Shop location updated');
            $this->waitForText($browser, '40 orders / day');
            $this->assertSees($browser, ['Backlog max 120']);
        });

        $location = Location::where('name', 'Shop location')->first();
        $this->assertSame(40, $location->daily_order_cap);
        $this->assertSame(120, $location->backlog_cap);
        $this->assertSame(['flagship', 'north'], $location->tags);
    }

    public function test_invalid_limit_shows_the_error_on_the_field(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/locations');
            $this->waitForText($browser, 'Shop location');

            $this->openEditModal($browser, 'Shop location');

            $browser->script(<<<'JS'
                const field = document.querySelector('#nx-location-modal s-number-field');
                field.value = '0';
                field.dispatchEvent(new Event('input', { bubbles: true }));
            JS);
            $this->clickButton($browser, 'Save');

            $this->waitForText($browser, 'must be at least 1');
        });

        $this->assertNull(Location::where('name', 'Shop location')->value('daily_order_cap'));
    }

    public function test_unknown_route_shows_a_helpful_not_found_page(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/does-not-exist');

            $this->waitForText($browser, 'Page not found');
            $this->assertSees($browser, ['Go to Home']);
        });
    }

    // ---------------------------------------------------------------- helpers

    private function pageText(Browser $browser): string
    {
        return (string) $browser->script(self::DEEP_TEXT.' return deepText(document.body);')[0];
    }

    private function waitForText(Browser $browser, string $text): void
    {
        $browser->waitUsing(self::WAIT, 100, fn () => str_contains($this->pageText($browser), $text), "Waited for text: {$text}");
    }

    private function waitUntilMissingText(Browser $browser, string $text): void
    {
        $browser->waitUsing(self::WAIT, 100, fn () => ! str_contains($this->pageText($browser), $text), "Waited for text to disappear: {$text}");
    }

    private function assertSees(Browser $browser, array $texts): void
    {
        $page = $this->pageText($browser);
        foreach ($texts as $text) {
            Assert::assertStringContainsString($text, $page);
        }
    }

    private function assertDoesNotSee(Browser $browser, string $text): void
    {
        Assert::assertStringNotContainsString($text, $this->pageText($browser));
    }

    /** Polaris fields expose `value` + native events; drive them the way a user's input would. */
    private function choose(Browser $browser, string $tag, string $label, string $value): void
    {
        $this->markByProperty($browser, $tag, 'label', $label);
        $browser->script(sprintf(<<<'JS'
            const el = document.querySelector('[data-e2e-target]');
            el.value = %s;
            el.dispatchEvent(new Event('change', { bubbles: true }));
        JS, json_encode($value)));
    }

    private function tick(Browser $browser, string $label): void
    {
        $this->markByProperty($browser, 's-checkbox', 'accessibilityLabel', $label);
        $browser->script(<<<'JS'
            const el = document.querySelector('[data-e2e-target]');
            el.checked = true;
            el.dispatchEvent(new Event('change', { bubbles: true }));
        JS);
    }

    private function clickButton(Browser $browser, string $text): void
    {
        $browser->script(sprintf(<<<'JS'
            [...document.querySelectorAll('s-button')].find((b) => b.textContent.trim() === %s).click();
        JS, json_encode($text)));
    }

    /**
     * Opens the edit modal with a real WebDriver click on the button inside <s-button>'s shadow DOM
     * (the host element has zero size), so Polaris handles commandFor/--show exactly as for a user.
     */
    private function openEditModal(Browser $browser, string $locationName): void
    {
        $this->markByProperty($browser, 's-button', 'accessibilityLabel', "Edit limits and tags for {$locationName}");

        // Polaris documents a `show` event on s-modal; record it to know the modal really opened.
        $browser->script(<<<'JS'
            window.__modalShown = false;
            document.getElementById('nx-location-modal').addEventListener('show', () => { window.__modalShown = true; }, { once: true });
        JS);

        $button = $browser->element('[data-e2e-target]')
            ->getShadowRoot()
            ->findElement(WebDriverBy::cssSelector('button'));

        // Pointer action (move + press): ChromeDriver's element click wrongly reports shadow-DOM
        // buttons as "intercepted" by their own host element.
        $browser->driver->action()->moveToElement($button)->click()->perform();

        $browser->waitUsing(self::WAIT, 100, fn () => (bool) $browser->script('return window.__modalShown === true;')[0], 'Waited for the edit modal to open');
    }

    /** Tag the single element whose JS property matches, so WebDriver can target it. */
    private function markByProperty(Browser $browser, string $tag, string $property, string $value): void
    {
        $browser->waitUsing(self::WAIT, 100, fn () => (bool) $browser->script(sprintf(<<<'JS'
            document.querySelectorAll('[data-e2e-target]').forEach((el) => el.removeAttribute('data-e2e-target'));
            const el = [...document.querySelectorAll(%s)].find((e) => e[%s] === %s);
            if (el) el.setAttribute('data-e2e-target', '');
            return !!el;
        JS, json_encode($tag), json_encode($property), json_encode($value)))[0], "Waited for {$tag} with {$property}={$value}");
    }
}
