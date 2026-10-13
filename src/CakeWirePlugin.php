<?php
declare(strict_types=1);

namespace CakeWire;

use Cake\Core\BasePlugin;
use Cake\Routing\RouteBuilder;

class CakeWirePlugin extends BasePlugin
{
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
