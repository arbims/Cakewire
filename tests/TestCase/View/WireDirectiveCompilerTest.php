<?php
declare(strict_types=1);

namespace CakeWire\Test\TestCase\View;

use Cake\TestSuite\TestCase;
use CakeWire\View\WireDirectiveCompiler;

class WireDirectiveCompilerTest extends TestCase
{
    public function testCompilesWireClickToAlpine(): void
    {
        $compiler = new WireDirectiveCompiler();
        $html = $compiler->compile('<button type="button" wire:click="increment">+</button>');

        $this->assertStringContainsString("x-on:click.prevent=\"handleWireClick(\$event, 'increment')\"", $html);
        $this->assertStringNotContainsString('wire:click', $html);
    }

    public function testCompilesWireKeyForMorphdom(): void
    {
        $compiler = new WireDirectiveCompiler();
        $html = $compiler->compile('<tr wire:key="user-1"><td>Ada</td></tr>');

        $this->assertStringContainsString('data-cakewire-key="user-1"', $html);
        $this->assertStringNotContainsString('wire:key', $html);
    }
}
