<?php
declare(strict_types=1);

namespace CakeWire\View;

use Cake\Core\Configure;
use Cake\Utility\Inflector;
use CakeWire\Component\Component;
use InvalidArgumentException;
use RuntimeException;

/**
 * Renders component templates with Livewire-like {{ $var }} syntax (plain PHP files).
 */
class ComponentViewRenderer
{
    /**
     * @param \CakeWire\Component\Component $component Component instance.
     * @param string|null $view View name (without extension).
     * @return string
     */
    public function render(Component $component, ?string $view = null): string
    {
        $view ??= $this->resolveViewName($component);
        $path = $this->resolveTemplatePath($view);

        if (!is_file($path)) {
            throw new InvalidArgumentException(sprintf('The CakeWire view "%s" was not found.', $view));
        }

        $source = file_get_contents($path);
        if ($source === false) {
            throw new RuntimeException(sprintf('Unable to read the CakeWire view "%s".', $view));
        }

        $compiled = $this->compile($source);

        /** @var array<string, mixed> $variables */
        $variables = $component->dehydrate();

        return $this->evaluate($compiled, $variables);
    }

    /**
     * @param \CakeWire\Component\Component $component Component instance.
     * @return string
     */
    public function resolveViewName(Component $component): string
    {
        $viewProperty = (new \ReflectionClass($component))->getProperty('view');
        $viewProperty->setAccessible(true);
        $view = $viewProperty->getValue($component);
        if (is_string($view) && $view !== '') {
            return $view;
        }

        $baseName = (new \ReflectionClass($component))->getShortName();
        if (str_ends_with($baseName, 'Component')) {
            $baseName = substr($baseName, 0, -strlen('Component'));
        }

        return Inflector::dasherize($baseName);
    }

    /**
     * Compiles {{ $var }}, {!! $var !!} and @error('field') directives.
     *
     * @param string $source Template source.
     * @return string
     */
    public function compile(string $source): string
    {
        $source = preg_replace_callback(
            '/@error\s*\(\s*[\'"](\w+)[\'"]\s*\)/',
            static fn (array $matches): string => sprintf(
                '<span class="cakewire-field-error" wire:error="%s"></span>',
                $matches[1],
            ),
            $source,
        ) ?? $source;

        $source = preg_replace_callback(
            '/\{\!\!\s*\$(\w+)\s*\!\!\}/',
            static fn (array $matches): string => sprintf('<?= (string)($%s ?? \'\') ?>', $matches[1]),
            $source,
        ) ?? $source;

        $source = preg_replace_callback(
            '/\{\{\s*\$(\w+)\s*\}\}/',
            static fn (array $matches): string => sprintf(
                '<?= htmlspecialchars((string)($%s ?? \'\'), ENT_QUOTES | ENT_SUBSTITUTE, \'UTF-8\') ?>',
                $matches[1],
            ),
            $source,
        ) ?? $source;

        return $source;
    }

    /**
     * @param string $view View name.
     * @return string
     */
    private function resolveTemplatePath(string $view): string
    {
        if (preg_match('/^[a-z][a-z0-9\-\/]*$/', $view) !== 1) {
            throw new InvalidArgumentException('The CakeWire view name is invalid.');
        }

        $directory = Configure::read('CakeWire.templates');
        if (!is_string($directory) || $directory === '') {
            $directory = $this->defaultTemplateDirectory();
        }

        if (!str_ends_with($directory, DIRECTORY_SEPARATOR)) {
            $directory .= DIRECTORY_SEPARATOR;
        }

        return $directory . str_replace('/', DIRECTORY_SEPARATOR, $view) . '.php';
    }

    /**
     * @return string
     */
    private function defaultTemplateDirectory(): string
    {
        if (defined('ROOT')) {
            return ROOT . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'CakeWire' . DIRECTORY_SEPARATOR;
        }

        return dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'CakeWire' . DIRECTORY_SEPARATOR;
    }

    /**
     * @param string $compiled Compiled PHP template.
     * @param array<string, mixed> $variables Public component properties.
     * @return string
     */
    private function evaluate(string $compiled, array $variables): string
    {
        $render = static function (string $template, array $data): string {
            extract($data, EXTR_SKIP);
            ob_start();
            try {
                eval('?>' . $template);
            } catch (\Throwable $exception) {
                ob_end_clean();
                throw new RuntimeException('The CakeWire view failed to render.', 0, $exception);
            }

            return (string)ob_get_clean();
        };

        return $render($compiled, $variables);
    }
}
