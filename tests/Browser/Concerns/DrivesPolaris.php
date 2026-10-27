<?php

namespace Tests\Browser\Concerns;

use Facebook\WebDriver\WebDriverBy;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Assert;

/**
 * Helpers for driving Polaris web components rendered by React.
 *
 * Polaris renders headings/labels inside shadow DOM and React 19 sets their props as element
 * properties (not attributes), so these read text through shadow roots + props and find
 * elements by property.
 */
trait DrivesPolaris
{
    protected const WAIT = 15;

    /**
     * Visible text of a node: light DOM, open shadow roots, plus the text Polaris renders from
     * component props (heading, label, error, details), which may live in closed shadow roots.
     */
    protected const DEEP_TEXT = <<<'JS'
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


    protected function pageText(Browser $browser): string
    {
        return (string) $browser->script(self::DEEP_TEXT.' return deepText(document.body);')[0];
    }

    protected function waitForText(Browser $browser, string $text): void
    {
        $browser->waitUsing(self::WAIT, 100, fn () => str_contains($this->pageText($browser), $text), "Waited for text: {$text}");
    }

    protected function waitUntilMissingText(Browser $browser, string $text): void
    {
        $browser->waitUsing(self::WAIT, 100, fn () => ! str_contains($this->pageText($browser), $text), "Waited for text to disappear: {$text}");
    }

    protected function assertSees(Browser $browser, array $texts): void
    {
        $page = $this->pageText($browser);
        foreach ($texts as $text) {
            Assert::assertStringContainsString($text, $page);
        }
    }

    protected function assertDoesNotSee(Browser $browser, string $text): void
    {
        Assert::assertStringNotContainsString($text, $this->pageText($browser));
    }

    /** Polaris fields expose `value` + native events; drive them the way a user's input would. */
    protected function choose(Browser $browser, string $tag, string $label, string $value): void
    {
        $this->markByProperty($browser, $tag, 'label', $label);
        $browser->script(sprintf(<<<'JS'
            const el = document.querySelector('[data-e2e-target]');
            el.value = %s;
            el.dispatchEvent(new Event('change', { bubbles: true }));
        JS, json_encode($value)));
    }

    /** Type into a Polaris text/number field found by its visible label (nth match, 0-based). */
    protected function typeInto(Browser $browser, string $tag, string $label, string $value, int $nth = 0): void
    {
        $browser->script(sprintf(<<<'JS'
            const el = [...document.querySelectorAll(%s)].filter((e) => e.label === %s)[%d];
            el.value = %s;
            el.dispatchEvent(new Event('input', { bubbles: true }));
        JS, json_encode($tag), json_encode($label), $nth, json_encode($value)));
    }

    /** Set a labelled Polaris switch or checkbox on or off (nth match, 0-based). */
    protected function setToggle(Browser $browser, string $label, bool $on, int $nth = 0): void
    {
        $browser->script(sprintf(<<<'JS'
            const el = [...document.querySelectorAll('s-switch, s-checkbox')].filter((e) => e.label === %s)[%d];
            el.checked = %s;
            el.dispatchEvent(new Event('change', { bubbles: true }));
        JS, json_encode($label), $nth, $on ? 'true' : 'false'));
    }

    /** Click an icon-style s-button identified by its accessibility label. */
    protected function clickButtonLabelled(Browser $browser, string $accessibilityLabel): void
    {
        $browser->script(sprintf(<<<'JS'
            [...document.querySelectorAll('s-button')].find((b) => b.accessibilityLabel === %s).click();
        JS, json_encode($accessibilityLabel)));
    }

    protected function tick(Browser $browser, string $label): void
    {
        $this->markByProperty($browser, 's-checkbox', 'accessibilityLabel', $label);
        $browser->script(<<<'JS'
            const el = document.querySelector('[data-e2e-target]');
            el.checked = true;
            el.dispatchEvent(new Event('change', { bubbles: true }));
        JS);
    }

    /** Click a button by its text. Matches Polaris s-button and the save bar's plain <button>s. */
    protected function clickButton(Browser $browser, string $text): void
    {
        $browser->script(sprintf(<<<'JS'
            [...document.querySelectorAll('s-button, ui-save-bar button')].find((b) => b.textContent.trim() === %s).click();
        JS, json_encode($text)));
    }

    /**
     * Opens the edit modal with a real WebDriver click on the button inside <s-button>'s shadow DOM
     * (the host element has zero size), so Polaris handles commandFor/--show exactly as for a user.
     */
    protected function openEditModal(Browser $browser, string $locationName): void
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
    protected function markByProperty(Browser $browser, string $tag, string $property, string $value): void
    {
        $browser->waitUsing(self::WAIT, 100, fn () => (bool) $browser->script(sprintf(<<<'JS'
            document.querySelectorAll('[data-e2e-target]').forEach((el) => el.removeAttribute('data-e2e-target'));
            const el = [...document.querySelectorAll(%s)].find((e) => e[%s] === %s);
            if (el) el.setAttribute('data-e2e-target', '');
            return !!el;
        JS, json_encode($tag), json_encode($property), json_encode($value)))[0], "Waited for {$tag} with {$property}={$value}");
    }
}
