<?php
declare(strict_types=1);

namespace CakeWire\View\Helper;

use Cake\Routing\Router;
use Cake\View\Helper;
use CakeWire\Component\ComponentRegistry;
use InvalidArgumentException;

class CakeWireHelper extends Helper
{
    private const RUNTIME_VERSION = '6';
    /**
     * Mounts a registered component in a reactive Alpine.js root element.
     *
     * @param string $component Registered component alias.
     * @param array<string, mixed> $properties Initial public properties.
     * @return string
     */
    public function component(string $component, array $properties = []): string
    {
        $instance = (new ComponentRegistry())->create($component);
        $instance->mount($properties);

        $configuration = [
            'component' => $component,
            'endpoint' => Router::url([
                'plugin' => 'CakeWire',
                'controller' => 'Updates',
                'action' => 'update',
                '_method' => 'POST',
            ]),
            'state' => $instance->dehydrate(),
        ];
        $encodedConfiguration = $this->encode($configuration);

        return sprintf(
            '<div data-cakewire-config="%1$s" data-cakewire-alias="%3$s" x-data="cakewireShell()">%2$s</div>',
            htmlspecialchars($encodedConfiguration, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $instance->renderHtml(),
            htmlspecialchars($component, ENT_QUOTES, 'UTF-8'),
        );
    }

    /**
     * Returns attributes for binding an input to one public property.
     *
     * @param string $property Public property name.
     * @return string
     */
    public function model(string $property): string
    {
        $this->assertPropertyName($property);

        return sprintf('wire:model="%s"', $property);
    }

    /**
     * Returns an Alpine event binding that invokes an allowed component action.
     *
     * @param string $action Component action name.
     * @return string
     */
    public function click(string $action): string
    {
        $this->assertPropertyName($action);

        return sprintf('wire:click="%s"', $action);
    }

    /**
     * Placeholder d'erreur de validation (équivalent Livewire @error).
     *
     * @param string $field Field name.
     * @return string
     */
    public function error(string $field): string
    {
        $this->assertPropertyName($field);

        return sprintf('<span class="cakewire-field-error" wire:error="%s"></span>', $field);
    }

    /**
     * Returns the Alpine.js and CakeWire runtime script tags.
     *
     * @return string
     */
    public function scripts(): string
    {
        return $this->headAssets() . $this->runtimeScripts();
    }

    /**
     * Styles and CSRF meta tag for the HTML head.
     *
     * @return string
     */
    public function headAssets(): string
    {
        return $this->styles()
            . '<meta name="csrfToken" content="'
            . htmlspecialchars((string)$this->getView()->getRequest()->getAttribute('csrfToken'), ENT_QUOTES, 'UTF-8')
            . '">';
    }

    /**
     * CakeWire and Alpine.js script tags (place before &lt;/body&gt;).
     *
     * @return string
     */
    public function runtimeScripts(): string
    {
        $morphdom = Router::url('/cake_wire/js/morphdom.min.js') . '?v=' . self::RUNTIME_VERSION;
        $runtime = Router::url('/cake_wire/js/cake-wire.js') . '?v=' . self::RUNTIME_VERSION;

        return '<script src="'
            . htmlspecialchars($morphdom, ENT_QUOTES, 'UTF-8')
            . '"></script><script src="'
            . htmlspecialchars($runtime, ENT_QUOTES, 'UTF-8')
            . '"></script>'
            . '<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.9/dist/cdn.min.js"></script>';
    }

    /**
     * Styles requis par certaines directives wire:* avant initialisation Alpine.
     *
     * @return string
     */
    public function styles(): string
    {
        return <<<'HTML'
<style>
    [wire\:loading] { display: none; }
    [wire\:cloak] { display: none !important; }
    .cakewire-field-error { display: block; color: #c0392b; font-size: 0.9rem; margin-top: 0.25rem; }
</style>
HTML;
    }

    /**
     * @param array<string, mixed> $value JSON-compatible component configuration.
     * @return string
     */
    private function encode(array $value): string
    {
        return (string)json_encode(
            $value,
            JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param string $name Property or action name.
     * @return void
     */
    private function assertPropertyName(string $name): void
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException(
                'CakeWire property and action names must be valid JavaScript identifiers.',
            );
        }
    }
}
