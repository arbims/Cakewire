<?php
declare(strict_types=1);

namespace CakeWire\Test\TestCase\View;

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakeWire\Test\Fixture\ViewPathComponent;
use CakeWire\View\ComponentTemplate;

use function CakeWire\view;

require_once dirname(__DIR__, 2) . '/Fixture/ViewPathComponent.php';

class ComponentTemplateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('CakeWire.templates', ROOT . DS . 'templates' . DS . 'CakeWire' . DS);
    }

    public function testNormalizeName(): void
    {
        $this->assertSame('show-posts', ComponentTemplate::normalizeName('livewire.show-posts'));
        $this->assertSame('dossier1/ficher', ComponentTemplate::normalizeName('dossier1/ficher'));
        $this->assertSame('posts/list', ComponentTemplate::normalizeName('cakewire.posts.list'));
    }

    public function testViewHelperReturnsTemplate(): void
    {
        $template = view('posts/nested-demo');

        $this->assertInstanceOf(ComponentTemplate::class, $template);
        $this->assertSame('posts/nested-demo', $template->name());
    }

    public function testComponentRenderWithViewHelper(): void
    {
        $component = new ViewPathComponent();
        $component->mount(['label' => 'Hello']);

        $html = $component->renderHtml();

        $this->assertStringContainsString('Hello', $html);
        $this->assertStringContainsString("handleWireClick", $html);
    }
}
