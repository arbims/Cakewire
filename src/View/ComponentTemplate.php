<?php
declare(strict_types=1);

namespace CakeWire\View;

use CakeWire\Component\Component;
use InvalidArgumentException;

/**
 * Référence à un template sous `templates/CakeWire/` (retournée par {@see view()}).
 */
final class ComponentTemplate
{
    private string $normalizedName;

    public function __construct(string $name)
    {
        $this->normalizedName = self::normalizeName($name);
    }

    public function name(): string
    {
        return $this->normalizedName;
    }

    /**
     * Compile et rend le template pour le composant donné.
     */
    public function render(Component $component): string
    {
        $html = (new ComponentViewRenderer())->render($component, $this->normalizedName);

        return (new WireDirectiveCompiler())->compile($html);
    }

    /**
     * `livewire.show-posts`, `dossier1/ficher` → chemin relatif sous CakeWire/.
     */
    public static function normalizeName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = str_replace('.', '/', $name);

        foreach (['livewire/', 'cakewire/'] as $prefix) {
            if (str_starts_with($name, $prefix)) {
                $name = substr($name, strlen($prefix));
                break;
            }
        }

        if ($name === '' || preg_match('/^[a-z][a-z0-9\-\/]*$/', $name) !== 1) {
            throw new InvalidArgumentException('The CakeWire view name is invalid.');
        }

        return $name;
    }
}
