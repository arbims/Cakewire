<?php
declare(strict_types=1);

namespace CakeWire\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use CakeWire\Test\Fixture\ValidationFormComponent;

require_once dirname(__DIR__, 2) . '/Fixture/ValidationFormComponent.php';

class ValidationUpdatesControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('CakeWire.components.validation-form', ValidationFormComponent::class);
    }

    public function testReturnsFieldErrorsInState(): void
    {
        $this->configureJsonRequest([
            'component' => 'validation-form',
            'state' => ['email' => 'invalid'],
            'action' => 'submit',
            'params' => [],
        ]);

        $this->post('/cake-wire/update');

        $this->assertResponseOk();
        $payload = json_decode((string)$this->_response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('_errors', $payload['state']);
        $this->assertArrayHasKey('email', $payload['state']['_errors']);
        $this->assertStringContainsString("wireHasError('email')", $payload['html']);
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
