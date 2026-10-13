<?php
declare(strict_types=1);

namespace CakeWire\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use CakeWire\Test\Fixture\UpdateCounterComponent;

require_once dirname(__DIR__, 2) . '/Fixture/UpdateCounterComponent.php';

class UpdatesControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('CakeWire.components.counter', UpdateCounterComponent::class);
    }

    public function testUpdatesAnAllowedComponentAction(): void
    {
        $this->configureJsonRequest([
            'component' => 'counter',
            'state' => ['count' => 4],
            'action' => 'increment',
            'params' => [],
        ]);

        $this->post('/cake-wire/update');

        $this->assertResponseOk();
        $this->assertSame(
            ['html' => '<span>5</span>', 'state' => ['count' => 5]],
            json_decode((string)$this->_response->getBody(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testRejectsAnUndeclaredAction(): void
    {
        $this->configureJsonRequest([
            'component' => 'counter',
            'state' => [],
            'action' => 'render',
            'params' => [],
        ]);

        $this->post('/cake-wire/update');

        $this->assertResponseCode(400);
    }

    /**
     * @param array<string, mixed> $payload CakeWire request body.
     * @return void
     */
    private function configureJsonRequest(array $payload): void
    {
        $token = (new CsrfProtectionMiddleware())->createToken();
        $this->configRequest([
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-CSRF-Token' => $token,
            ],
            'cookies' => ['csrfToken' => $token],
            'input' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
    }
}
