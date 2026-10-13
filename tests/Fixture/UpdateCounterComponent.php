<?php
declare(strict_types=1);

namespace CakeWire\Test\Fixture;

use CakeWire\Component\Component;

class UpdateCounterComponent extends Component
{
    public int $count = 0;

    protected array $actions = ['increment'];

    public function increment(): void
    {
        $this->count++;
    }

    public function render(): string
    {
        return sprintf('<span>%d</span>', $this->count);
    }
}
