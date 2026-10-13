<?php
declare(strict_types=1);

namespace CakeWire;

use Cake\Console\CommandCollection;
use Cake\Core\BasePlugin;
use Cake\Routing\RouteBuilder;

class CakeWirePlugin extends BasePlugin
{
    /**
     * @param \Cake\Console\CommandCollection $commands Command collection.
     * @return \Cake\Console\CommandCollection
     */
    public function console(CommandCollection $commands): CommandCollection
    {
        return parent::console($commands);
    }

    /**
     * Registers the private endpoint used by the Alpine runtime.
     *
     * @param \Cake\Routing\RouteBuilder $routes The application's route collection.
     * @return void
     */
    public function routes(RouteBuilder $routes): void
    {
        $routes->plugin(
            'CakeWire',
            ['path' => '/cake-wire'],
            function (RouteBuilder $builder): void {
                $builder->post('/update', ['controller' => 'Updates', 'action' => 'update']);
            },
        );

        parent::routes($routes);
    }
}
