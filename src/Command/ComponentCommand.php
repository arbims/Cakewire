<?php
declare(strict_types=1);

namespace CakeWire\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Cake\Utility\Inflector;

/**
 * Generates a CakeWire component class and matching template.
 */
class ComponentCommand extends Command
{
    /**
     * @return string
     */
    public static function defaultName(): string
    {
        return 'cakewire component';
    }

    /**
     * @param \Cake\Console\ConsoleOptionParser $parser Option parser.
     * @return \Cake\Console\ConsoleOptionParser
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->setDescription('Generate a CakeWire component (PHP class + template).');
        $parser->addArgument('name', [
            'help' => 'Name (TodoList, todo-list, or TodoListComponent)',
            'required' => true,
        ]);
        $parser->addOption('alias', [
            'short' => 'a',
            'help' => 'Registration alias for CakeWire.components.* (default: derived from name)',
        ]);
        $parser->addOption('view', [
            'help' => 'Template name under templates/CakeWire/ (default: same as alias)',
        ]);
        $parser->addOption('force', [
            'short' => 'f',
            'help' => 'Overwrite existing files',
            'boolean' => true,
            'default' => false,
        ]);
        $parser->addOption('register', [
            'short' => 'r',
            'help' => 'Append Configure::write(...) to config/bootstrap.php',
            'boolean' => true,
            'default' => false,
        ]);
        $parser->addOption('no-template', [
            'help' => 'Skip template generation',
            'boolean' => true,
            'default' => false,
        ]);

        return $parser;
    }

    /**
     * @param \Cake\Console\Arguments $args Arguments.
     * @param \Cake\Console\ConsoleIo $io Console IO.
     * @return int
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        if (!defined('ROOT')) {
            $io->error('ROOT is not defined. Run this command from a CakePHP application.');

            return static::CODE_ERROR;
        }

        $baseName = $this->normalizeBaseName((string)$args->getArgument('name'));
        if ($baseName === '') {
            $io->error('The component name is invalid.');

            return static::CODE_ERROR;
        }

        $className = $baseName . 'Component';
        $alias = $args->getOption('alias') !== null
            ? $this->normalizeAlias((string)$args->getOption('alias'))
            : Inflector::dasherize($baseName);
        if (!$this->isValidAlias($alias)) {
            $io->error('The alias must match [a-z][a-z0-9-]*.');

            return static::CODE_ERROR;
        }

        $viewName = $this->normalizeViewName((string)($args->getOption('view') ?: $alias));
        if (!$this->isValidViewName($viewName)) {
            $io->error('The view name is invalid.');

            return static::CODE_ERROR;
        }

        $namespace = (string)Configure::read('CakeWire.generator.namespace', 'App\\CakeWire');
        $componentDir = (string)Configure::read(
            'CakeWire.generator.componentPath',
            ROOT . DS . 'src' . DS . 'CakeWire' . DS,
        );
        $templateRoot = (string)Configure::read(
            'CakeWire.templates',
            ROOT . DS . 'templates' . DS . 'CakeWire' . DS,
        );

        if (!str_ends_with($componentDir, DS)) {
            $componentDir .= DS;
        }
        if (!str_ends_with($templateRoot, DS)) {
            $templateRoot .= DS;
        }

        $classPath = $componentDir . $className . '.php';
        $templatePath = $templateRoot . str_replace('/', DS, $viewName) . '.php';
        $force = (bool)$args->getOption('force');

        if (!$force && (is_file($classPath) || (!$args->getOption('no-template') && is_file($templatePath)))) {
            $io->error('Files already exist. Use --force to overwrite.');
            $io->out('  ' . $classPath);
            if (!$args->getOption('no-template')) {
                $io->out('  ' . $templatePath);
            }

            return static::CODE_ERROR;
        }

        if (!$this->ensureDirectory(dirname($classPath), $io)) {
            return static::CODE_ERROR;
        }

        $classContents = $this->buildClassContents($namespace, $className, $viewName);
        if (file_put_contents($classPath, $classContents) === false) {
            $io->error('Unable to write the component class.');

            return static::CODE_ERROR;
        }
        $io->success('Created ' . $classPath);

        if (!$args->getOption('no-template')) {
            $templateDir = dirname($templatePath);
            if (!$this->ensureDirectory($templateDir, $io)) {
                return static::CODE_ERROR;
            }

            if (file_put_contents($templatePath, $this->buildTemplateContents($className, $alias)) === false) {
                $io->error('Unable to write the template.');

                return static::CODE_ERROR;
            }
            $io->success('Created ' . $templatePath);
        }

        $fqcn = $namespace . '\\' . $className;
        $registerLine = sprintf(
            "Configure::write('CakeWire.components.%s', \\%s::class);",
            $alias,
            $fqcn,
        );

        if ($args->getOption('register')) {
            if ($this->registerInBootstrap($registerLine, $io)) {
                $io->success('Registered in config/bootstrap.php');
            } else {
                $io->warning('Could not update config/bootstrap.php automatically.');
                $io->out($registerLine);
            }
        } else {
            $io->out('');
            $io->out('Add to config/bootstrap.php:');
            $io->out('  ' . $registerLine);
            $io->out('');
            $io->out('Mount in a view:');
            $io->out(sprintf('  <?= $this->CakeWire->component(\'%s\') ?>', $alias));
        }

        return static::CODE_SUCCESS;
    }

    /**
     * @param string $raw User input.
     * @return string PascalCase base name without "Component" suffix.
     */
    private function normalizeBaseName(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (str_ends_with($raw, 'Component')) {
            $raw = substr($raw, 0, -strlen('Component'));
        }

        $raw = str_replace(['/', '\\', '_'], '-', $raw);
        $camel = Inflector::camelize(str_replace('-', '_', $raw));

        return $camel !== '' ? $camel : '';
    }

    /**
     * @param string $raw Alias input.
     * @return string
     */
    private function normalizeAlias(string $raw): string
    {
        $raw = strtolower(trim($raw));
        $raw = str_replace(['_', ' '], '-', $raw);

        return Inflector::dasherize(Inflector::camelize(str_replace('-', '_', $raw)));
    }

    /**
     * @param string $raw View name.
     * @return string
     */
    private function normalizeViewName(string $raw): string
    {
        $raw = strtolower(trim($raw));
        $raw = str_replace('\\', '/', $raw);
        $raw = str_replace('_', '-', $raw);
        $raw = preg_replace('#/+#', '/', $raw) ?? $raw;

        return trim($raw, '/');
    }

    /**
     * @param string $alias Component alias.
     * @return bool
     */
    private function isValidAlias(string $alias): bool
    {
        return preg_match('/^[a-z][a-z0-9-]*$/', $alias) === 1;
    }

    /**
     * @param string $view View path.
     * @return bool
     */
    private function isValidViewName(string $view): bool
    {
        return preg_match('/^[a-z][a-z0-9\-\/]*$/', $view) === 1;
    }

    /**
     * @param string $directory Target directory.
     * @param \Cake\Console\ConsoleIo $io Console IO.
     * @return bool
     */
    private function ensureDirectory(string $directory, ConsoleIo $io): bool
    {
        if (is_dir($directory)) {
            return true;
        }

        if (mkdir($directory, 0775, true) || is_dir($directory)) {
            return true;
        }

        $io->error('Unable to create directory: ' . $directory);

        return false;
    }

    /**
     * @param string $namespace PHP namespace.
     * @param string $className Class short name.
     * @param string $viewName Template name for view().
     * @return string
     */
    private function buildClassContents(string $namespace, string $className, string $viewName): string
    {
        $viewLiteral = addslashes($viewName);

        return <<<PHP
<?php
declare(strict_types=1);

namespace {$namespace};

use CakeWire\Component\Component;
use CakeWire\View\ComponentTemplate;

use function CakeWire\view;

final class {$className} extends Component
{
    /**
     * @var list<string>
     */
    protected array \$actions = [];

    public function render(): ComponentTemplate
    {
        return view('{$viewLiteral}');
    }
}

PHP;
    }

    /**
     * @param string $className Component class short name.
     * @param string $alias Mount alias.
     * @return string
     */
    private function buildTemplateContents(string $className, string $alias): string
    {
        return <<<HTML
<div>
    <h1>{$className}</h1>
    <p>Template CakeWire (<code>{$alias}</code>).</p>
</div>

HTML;
    }

    /**
     * @param string $registerLine Line to append.
     * @param \Cake\Console\ConsoleIo $io Console IO.
     * @return bool
     */
    private function registerInBootstrap(string $registerLine, ConsoleIo $io): bool
    {
        $bootstrap = ROOT . DS . 'config' . DS . 'bootstrap.php';
        if (!is_file($bootstrap)) {
            return false;
        }

        $contents = file_get_contents($bootstrap);
        if ($contents === false) {
            return false;
        }

        if (str_contains($contents, $registerLine)) {
            $io->verbose('Registration already present in bootstrap.php.');

            return true;
        }

        $marker = '// CakeWire components (generated)';
        if (!str_contains($contents, $marker)) {
            $contents = rtrim($contents) . PHP_EOL . PHP_EOL . $marker . PHP_EOL;
        }

        $contents .= $registerLine . PHP_EOL;

        return file_put_contents($bootstrap, $contents) !== false;
    }
}
