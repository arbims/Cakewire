<?php
declare(strict_types=1);

namespace CakeWire\Test\TestCase\Component;

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakeWire\Component\ComponentRegistry;
use CakeWire\Test\Fixture\CounterComponent;
use InvalidArgumentException;

require_once dirname(__DIR__, 2) . '/Fixture/CounterComponent.php';

class ComponentRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('CakeWire.components.counter', CounterComponent::class);
    }

    public function testCreatesOnlyRegisteredComponents(): void
    {
        $component = (new ComponentRegistry())->create('counter');

        $this->assertInstanceOf(CounterComponent::class, $component);
    }

    public function testRejectsUnknownComponents(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ComponentRegistry())->create('unknown');
    }

    public function testHydratesDeclaredPublicPropertiesOnly(): void
    {
        $component = new CounterComponent();
        $component->hydrate(['count' => 3, 'unknown' => 'value']);

        $this->assertSame(['count' => 3], $component->dehydrate());
    }

    public function testAllowsOnlyDeclaredActions(): void
    {
        $component = new CounterComponent();

        $this->assertTrue($component->allowsAction('increment'));
        $this->assertFalse($component->allowsAction('render'));
    }
}
