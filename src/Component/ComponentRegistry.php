<?php
declare(strict_types=1);

namespace CakeWire\Component;

use Cake\Core\Configure;
use InvalidArgumentException;

class ComponentRegistry
{
    /**
     * Creates a component from an alias registered in CakeWire.components.
     *
     * @param string $alias Registered component alias.
     * @return \CakeWire\Component\Component
     */
    public function create(string $alias): Component
    {
        if (preg_match('/^[a-z][a-z0-9-]*$/', $alias) !== 1) {
            throw new InvalidArgumentException('The component alias is invalid.');
        }

        $class = Configure::read(sprintf('CakeWire.components.%s', $alias));
        if (!is_string($class) || !class_exists($class) || !is_a($class, Component::class, true)) {
            throw new InvalidArgumentException(sprintf('The CakeWire component "%s" is not registered.', $alias));
        }

        return new $class();
    }
}
