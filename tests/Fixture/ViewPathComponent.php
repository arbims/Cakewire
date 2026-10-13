<?php
declare(strict_types=1);

namespace CakeWire\Test\Fixture;

use CakeWire\Component\Component;
use CakeWire\View\ComponentTemplate;

use function CakeWire\view;

final class ViewPathComponent extends Component
{
    public string $label = '';

    /**
     * @var list<string>
     */
    protected array $actions = ['ping'];

    public function ping(): void
    {
        $this->label .= '!';
    }

    public function render(): ComponentTemplate
    {
        return view('posts/nested-demo');
    }
}
