<?php
declare(strict_types=1);

namespace CakeWire\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;

class ComponentCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    private string $generatedClass;

    private string $generatedTemplate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setAppNamespace();
        $this->configApplication(
            \App\Application::class,
            [CONFIG],
        );

        $base = 'CliGenSample';
        $this->generatedClass = ROOT . DS . 'src' . DS . 'CakeWire' . DS . $base . 'Component.php';
        $this->generatedTemplate = ROOT . DS . 'templates' . DS . 'CakeWire' . DS . 'cli-gen-sample.php';
        $this->removeGeneratedFiles();
    }

    protected function tearDown(): void
    {
        $this->removeGeneratedFiles();
        parent::tearDown();
    }

    public function testGeneratesComponentAndTemplate(): void
    {
        $this->exec('cakewire component CliGenSample');

        $this->assertExitSuccess();
        $this->assertFileExists($this->generatedClass);
        $this->assertFileExists($this->generatedTemplate);

        $class = file_get_contents($this->generatedClass);
        $this->assertIsString($class);
        $this->assertStringContainsString('final class CliGenSampleComponent', $class);
        $this->assertStringContainsString("return view('cli-gen-sample');", $class);

        $template = file_get_contents($this->generatedTemplate);
        $this->assertIsString($template);
        $this->assertStringContainsString('CliGenSampleComponent', $template);
    }

    public function testCustomAliasAndView(): void
    {
        $this->exec('cakewire component TodoList --alias=todos --view=tasks/list --force');

        $classPath = ROOT . DS . 'src' . DS . 'CakeWire' . DS . 'TodoListComponent.php';
        $templatePath = ROOT . DS . 'templates' . DS . 'CakeWire' . DS . 'tasks' . DS . 'list.php';

        $this->assertExitSuccess();
        $this->assertFileExists($classPath);
        $this->assertFileExists($templatePath);
        $this->assertStringContainsString("return view('tasks/list');", (string)file_get_contents($classPath));

        @unlink($classPath);
        @unlink($templatePath);
        @rmdir(dirname($templatePath));
    }

    private function removeGeneratedFiles(): void
    {
        if (is_file($this->generatedClass)) {
            unlink($this->generatedClass);
        }
        if (is_file($this->generatedTemplate)) {
            unlink($this->generatedTemplate);
        }
    }
}
