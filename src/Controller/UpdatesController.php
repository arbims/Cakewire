<?php
declare(strict_types=1);

namespace CakeWire\Controller;

use ArgumentCountError;
use Cake\Controller\Controller;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Response;
use CakeWire\Component\Component;
use CakeWire\Component\ComponentRegistry;
use InvalidArgumentException;
use JsonException;
use ReflectionMethod;
use TypeError;

class UpdatesController extends Controller
{
    /**
     * Hydrates, invokes, and re-renders one registered component.
     *
     * @return \Cake\Http\Response
     */
    public function update(): Response
    {
        try {
            $payload = $this->readPayload();
        } catch (JsonException $exception) {
            throw new BadRequestException('The CakeWire payload is invalid JSON.', null, $exception);
        }

        if (!is_array($payload)) {
            throw new BadRequestException('The CakeWire payload must be an object.');
        }

        $alias = $payload['component'] ?? null;
        $state = $payload['state'] ?? [];
        if (!is_string($alias) || !is_array($state)) {
            throw new BadRequestException('The CakeWire component and state are required.');
        }

        try {
            $component = (new ComponentRegistry())->create($alias);
            $component->hydrate($state);
            $this->applyUploads($component);
            $this->executeComponentAction($component, $payload);
            $this->runLiveSync($component, $payload);
        } catch (InvalidArgumentException $exception) {
            throw new BadRequestException($exception->getMessage(), null, $exception);
        }

        return $this->getResponse()
            ->withType('application/json')
            ->withStringBody((string)json_encode([
                'html' => $component->renderHtml(),
                'state' => $component->dehydrate(),
            ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(): array
    {
        $request = $this->getRequest();
        $contentType = $request->getHeaderLine('Content-Type');

        if (str_contains($contentType, 'multipart/form-data')) {
            $encoded = $request->getData('payload');
            if (!is_string($encoded) || $encoded === '') {
                throw new BadRequestException('The CakeWire multipart payload is missing.');
            }

            /** @var array<string, mixed> $payload */
            $payload = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

            return $payload;
        }

        $payload = $request->getData();

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param \CakeWire\Component\Component $component Component receiving uploads.
     * @return void
     */
    private function applyUploads(Component $component): void
    {
        foreach ($component->uploadFields() as $field) {
            $file = $this->getRequest()->getUploadedFile($field);
            if ($file !== null && $file->getError() === UPLOAD_ERR_OK) {
                $component->storeUpload($field, $file);
            }
        }
    }

    /**
     * @param \CakeWire\Component\Component $component Component to invoke.
     * @param array<string, mixed> $payload Browser request payload.
     * @return void
     */
    /**
     * @param \CakeWire\Component\Component $component Component instance.
     * @param array<string, mixed> $payload Request payload.
     * @return void
     */
    private function runLiveSync(Component $component, array $payload): void
    {
        if (($payload['action'] ?? null) !== null) {
            return;
        }

        $property = $payload['syncProperty'] ?? null;
        if (!is_string($property) || $property === '') {
            return;
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $property)) {
            throw new InvalidArgumentException('The CakeWire sync property is invalid.');
        }

        $component->runLiveSync($property);
    }

    /**
     * @param \CakeWire\Component\Component $component Component to invoke.
     * @param array<string, mixed> $payload Browser request payload.
     * @return void
     */
    private function executeComponentAction(Component $component, array $payload): void
    {
        $action = $payload['action'] ?? null;
        if ($action === null) {
            return;
        }

        $parameters = $payload['params'] ?? [];
        if (
            !is_string($action)
            || preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $action) !== 1
            || !is_array($parameters)
            || !array_is_list($parameters)
            || !$component->allowsAction($action)
        ) {
            throw new InvalidArgumentException('The CakeWire action is not allowed.');
        }

        $method = new ReflectionMethod($component, $action);
        if (!$method->isPublic() || $method->isStatic()) {
            throw new InvalidArgumentException('The CakeWire action is not callable.');
        }

        try {
            $method->invokeArgs($component, $parameters);
        } catch (ArgumentCountError | TypeError $exception) {
            throw new InvalidArgumentException('The CakeWire action parameters are invalid.', 0, $exception);
        }
    }
}
