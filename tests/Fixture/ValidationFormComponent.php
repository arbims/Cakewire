<?php
declare(strict_types=1);

namespace CakeWire\Test\Fixture;

use CakeWire\Component\Component;
use CakeWire\View\WireDirectiveCompiler;

final class ValidationFormComponent extends Component
{
    public string $email = '';

    /**
     * @var list<string>
     */
    protected array $actions = ['submit'];

    /**
     * @var array<string, string>
     */
    protected array $validationRules = [
        'email' => 'required|email',
    ];

    /**
     * @return void
     */
    public function submit(): void
    {
        $this->validate();
    }

    /**
     * @return string
     */
    public function render(): string
    {
        $html = '<form wire:submit="submit">'
            . '<input wire:model.live="email">'
            . '<span wire:error="email"></span>'
            . '</form>';

        return (new WireDirectiveCompiler())->compile($html);
    }
}
