<?php
declare(strict_types=1);

namespace CakeWire\Test\TestCase\View;

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakeWire\Component\Component;
use CakeWire\Test\Fixture\CounterComponent;
use CakeWire\View\ComponentViewRenderer;

require_once dirname(__DIR__, 2) . '/Fixture/CounterComponent.php';

class ComponentViewRendererTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('CakeWire.templates', ROOT . DS . 'templates' . DS . 'CakeWire' . DS);
    }

    public function testCompilesEchoSyntax(): void
    {
        $renderer = new ComponentViewRenderer();
        $compiled = $renderer->compile('<h1>{{ $count }}</h1>');

        $this->assertStringContainsString('htmlspecialchars', $compiled);
        $this->assertStringContainsString('$count', $compiled);
    }

    public function testRendersCounterView(): void
    {
        $component = new CounterComponent();
        $component->mount(['count' => 3]);

        $html = (new ComponentViewRenderer())->render($component, 'counter');

        $this->assertStringContainsString('<h1>Compteur : 3</h1>', $html);
        $this->assertStringContainsString('wire:click="increment"', $html);
    }
}
