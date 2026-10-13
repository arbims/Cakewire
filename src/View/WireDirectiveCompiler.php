<?php
declare(strict_types=1);

namespace CakeWire\View;

use DOMDocument;
use DOMElement;
use DOMNode;
use InvalidArgumentException;

/**
 * Compiles wire:* attributes into Alpine.js directives (server-side, before HTML is sent).
 */
class WireDirectiveCompiler
{
    /**
     * @param string $html Component markup.
     * @return string
     */
    public function compile(string $html): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="wire-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('wire-root');
        if (!$root instanceof DOMElement) {
            return $html;
        }

        $this->compileElement($root);

        $compiled = '';
        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMNode) {
                $compiled .= $document->saveHTML($child);
            }
        }

        return $compiled;
    }

    /**
     * @param \DOMElement $element Element to transform.
     * @return void
     */
    private function compileElement(DOMElement $element): void
    {
        foreach ($this->childElements($element) as $child) {
            $this->compileElement($child);
        }

        $wireAttributes = [];
        foreach ($element->attributes as $attribute) {
            if (str_starts_with($attribute->name, 'wire:')) {
                $wireAttributes[$attribute->name] = $attribute->value;
            }
        }

        if ($wireAttributes === []) {
            return;
        }

        $alpine = [];
        $target = $wireAttributes['wire:target'] ?? null;
        $confirmMessage = $wireAttributes['wire:confirm'] ?? null;

        foreach ($wireAttributes as $name => $value) {
            if (in_array($name, ['wire:target', 'wire:confirm'], true)) {
                continue;
            }

            $directive = substr($name, strlen('wire:'));
            if (str_starts_with($directive, 'on:')) {
                $eventName = substr($directive, strlen('on:'));
                $alpine['x-on:' . $eventName . '.window'] = sprintf(
                    "handleWireEvent('%s', '%s', \$event.detail)",
                    addslashes($eventName),
                    addslashes(trim($value)),
                );

                continue;
            }

            if (str_starts_with($directive, 'model')) {
                $alpine = array_merge($alpine, $this->compileModel($name, $value));
            } elseif ($directive === 'click' || str_starts_with($directive, 'click.')) {
                $alpine['x-on:click.prevent'] = $this->compileActionHandler($value, $confirmMessage);
            } elseif ($directive === 'submit') {
                $alpine['x-on:submit.prevent'] = $this->compileSubmitHandler($value, $confirmMessage);
            } elseif (str_starts_with($directive, 'loading')) {
                $alpine = array_merge($alpine, $this->compileLoading($directive, $target, $value));
            } elseif ($directive === 'error') {
                $element->setAttribute('role', 'alert');
                $class = trim($element->getAttribute('class') . ' cakewire-field-error');
                $element->setAttribute('class', $class);
                $alpine['x-show'] = sprintf("wireHasError('%s')", addslashes($value));
                $alpine['x-text'] = sprintf("wireErrorMessage('%s')", addslashes($value));
            } elseif ($directive === 'navigate' || str_starts_with($directive, 'navigate.')) {
                $href = $element->getAttribute('href');
                if ($href !== '') {
                    $alpine['x-on:click.prevent'] = sprintf(
                        'CakeWire.navigate(%s)',
                        json_encode($href, JSON_THROW_ON_ERROR),
                    );
                }
            } elseif ($directive === 'key') {
                $element->setAttribute('data-cakewire-key', $value);
            } elseif ($this->isDomEventDirective($directive)) {
                $alpine['x-on:' . $this->toAlpineEventName($directive)] = $this->compileActionHandler($value, $confirmMessage);
            }
        }

        foreach (array_keys($wireAttributes) as $name) {
            $element->removeAttribute($name);
        }

        foreach ($alpine as $attributeName => $expression) {
            $this->appendAlpineAttribute($element, $attributeName, $expression);
        }
    }

    /**
     * @param \DOMElement $element Parent element.
     * @return list<\DOMElement>
     */
    private function childElements(DOMElement $element): array
    {
        $children = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child;
            }
        }

        return $children;
    }

    /**
     * @param string $directive wire:model attribute name.
     * @param string $property Property name.
     * @return array<string, string>
     */
    private function compileModel(string $directive, string $property): array
    {
        $suffix = substr($directive, strlen('wire:model'));
        $mode = 'defer';
        if (str_starts_with($suffix, '.live')) {
            $mode = 'live';
        } elseif (str_starts_with($suffix, '.blur')) {
            $mode = 'blur';
        }

        $debounceMs = 150;
        if (preg_match('/\.debounce\.(\d+)ms/', $suffix, $debounceMatches) === 1) {
            $debounceMs = max(0, (int)$debounceMatches[1]);
        }

        $attributes = ['x-model' => 'state.' . $property];
        $sync = sprintf("handleWireSync('%s')", addslashes($property));

        return match ($mode) {
            'live' => $attributes + [sprintf('x-on:input.debounce.%dms', $debounceMs) => $sync],
            'blur' => $attributes + ['x-on:blur' => $sync],
            default => $attributes + ['x-on:change' => $sync],
        };
    }

    /**
     * @param string $directive Loading directive.
     * @param string|null $target wire:target value.
     * @param string $value Attribute value.
     * @return array<string, string>
     */
    private function compileLoading(string $directive, ?string $target, string $value): array
    {
        $expression = $this->loadingExpression($target);

        if (str_ends_with($directive, '.remove')) {
            return ['x-show' => '!(' . $expression . ')'];
        }

        if (str_ends_with($directive, '.attr')) {
            return ['x-bind:disabled' => $expression];
        }

        if (str_ends_with($directive, '.class')) {
            return ['x-bind:class' => $expression . " ? '" . addslashes($value) . "' : ''"];
        }

        return ['x-show' => $expression];
    }

    /**
     * @param string|null $target Target name(s).
     * @return string
     */
    private function loadingExpression(?string $target): string
    {
        if ($target === null || $target === '') {
            return 'wireIsLoading()';
        }

        $targets = array_map('trim', explode(',', $target));
        if (count($targets) === 1) {
            return sprintf("wireIsLoading('%s')", addslashes($targets[0]));
        }

        $encoded = json_encode($targets, JSON_THROW_ON_ERROR);

        return 'wireIsLoadingAny(' . $encoded . ')';
    }

    /**
     * @param string $expression Action expression.
     * @param string|null $confirmMessage Optional confirm dialog message.
     * @return string
     */
    private function compileActionHandler(string $expression, ?string $confirmMessage): string
    {
        $expression = trim($expression);
        if (str_starts_with($expression, '$dispatch(')) {
            return $this->compileDispatchExpression($expression);
        }

        if ($expression === '$refresh') {
            return 'handleWireRefresh($event)';
        }

        $handler = $this->compileActionCall($expression);
        if ($confirmMessage !== null && $confirmMessage !== '') {
            $handler = sprintf(
                "if(!confirm('%s')) return; %s",
                addslashes($confirmMessage),
                $handler,
            );
        }

        return $handler;
    }

    /**
     * @param string $expression Submit action.
     * @param string|null $confirmMessage Optional confirm dialog message.
     * @return string
     */
    private function compileSubmitHandler(string $expression, ?string $confirmMessage): string
    {
        $handler = str_replace(
            'handleWireClick',
            'handleWireSubmit',
            $this->compileActionCall($expression),
        );
        if ($confirmMessage !== null && $confirmMessage !== '') {
            $handler = sprintf(
                "if(!confirm('%s')) return; %s",
                addslashes($confirmMessage),
                $handler,
            );
        }

        return $handler;
    }

    /**
     * @param string $expression Dispatch expression.
     * @return string
     */
    private function compileDispatchExpression(string $expression): string
    {
        if (preg_match('/->to\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)\s*$/', $expression, $matches) === 1) {
            $dispatch = trim(substr($expression, 0, (int)strpos($expression, '->to(')));
            if (preg_match('/^\$dispatch\s*\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*(.+))?\s*\)$/', $dispatch, $dispatchMatches) === 1) {
                $detail = $dispatchMatches[2] ?? '{}';

                return sprintf(
                    "CakeWire.dispatchTo('%s', %s, '%s')",
                    addslashes($dispatchMatches[1]),
                    $detail,
                    addslashes($matches[1]),
                );
            }
        }

        return $expression;
    }

    /**
     * @param string $expression Action call.
     * @return string
     */
    private function compileActionCall(string $expression): string
    {
        if (!preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)(?:\((.*)\))?$/s', $expression, $matches)) {
            throw new InvalidArgumentException(sprintf('Invalid CakeWire action "%s".', $expression));
        }

        $action = $matches[1];
        if (!isset($matches[2]) || trim($matches[2]) === '') {
            return sprintf("handleWireClick(\$event, '%s')", $action);
        }

        return sprintf("handleWireClick(\$event, '%s', %s)", $action, trim($matches[2]));
    }

    /**
     * @param string $directive wire: directive without prefix.
     * @return bool
     */
    private function isDomEventDirective(string $directive): bool
    {
        $root = explode('.', $directive)[0];

        return in_array($root, ['keydown', 'keyup', 'keypress', 'mouseenter', 'mouseleave', 'dblclick'], true);
    }

    /**
     * @param string $directive wire: directive without prefix.
     * @return string
     */
    private function toAlpineEventName(string $directive): string
    {
        if (!str_contains($directive, '.')) {
            return $directive;
        }

        $segments = explode('.', $directive);

        return $segments[0] . '.' . implode('.', array_slice($segments, 1));
    }

    /**
     * @param \DOMElement $element Target element.
     * @param string $name Attribute name.
     * @param string $expression Alpine expression.
     * @return void
     */
    private function appendAlpineAttribute(DOMElement $element, string $name, string $expression): void
    {
        $existing = $element->getAttribute($name);
        $element->setAttribute($name, $existing !== '' ? $existing . '; ' . $expression : $expression);
    }
}
