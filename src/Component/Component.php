<?php
declare(strict_types=1);

namespace CakeWire\Component;

use Cake\Http\Exception\BadRequestException;
use Cake\Utility\Inflector;
use Cake\Validation\Validator;
use CakeWire\View\ComponentTemplate;
use CakeWire\View\ComponentViewRenderer;
use CakeWire\View\WireDirectiveCompiler;
use InvalidArgumentException;
use Psr\Http\Message\UploadedFileInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use TypeError;

abstract class Component
{
    /**
     * Actions that the browser may invoke.
     *
     * @var list<string>
     */
    protected array $actions = [];

    /**
     * Public properties that accept file uploads from the browser.
     *
     * @var list<string>
     */
    protected array $uploads = [];

    /**
     * Validation rules applied on submit and on `wire:model.live` updates.
     *
     * @var array<string, string>
     */
    protected array $validationRules = [];

    /**
     * Template name under `templates/CakeWire/` (without `.php`).
     * When empty, inferred from the class name (`CounterComponent` → `counter`).
     *
     * @var string
     */
    protected string $view = '';

    /**
     * @var array<string, string>
     */
    private array $errors = [];

    /**
     * Initializes the component with values supplied when it is mounted.
     *
     * @param array<string, mixed> $properties Initial component properties.
     * @return void
     */
    public function mount(array $properties = []): void
    {
        $this->hydrate($properties);
    }

    /**
     * Applies state received from the browser to declared public properties.
     *
     * @param array<string, mixed> $state Component state.
     * @return void
     */
    public function hydrate(array $state): void
    {
        unset($state['_errors']);

        $properties = $this->publicProperties();

        foreach ($state as $name => $value) {
            if (!isset($properties[$name])) {
                continue;
            }

            $property = $properties[$name];
            if ($property->isReadOnly()) {
                continue;
            }

            try {
                $property->setValue($this, $value);
            } catch (TypeError $exception) {
                throw new InvalidArgumentException(
                    sprintf('Invalid value for component property "%s".', $name),
                    0,
                    $exception,
                );
            }
        }
    }

    /**
     * Returns the public properties that are safe to return to the browser.
     *
     * @return array<string, mixed>
     */
    public function dehydrate(): array
    {
        $state = [];
        foreach ($this->publicProperties() as $name => $property) {
            if ($property->isInitialized($this)) {
                $state[$name] = $property->getValue($this);
            }
        }

        if ($this->errors !== []) {
            $state['_errors'] = $this->errors;
        }

        return $state;
    }

    /**
     * Returns whether an action is available to the browser.
     *
     * @param string $action Action name.
     * @return bool
     */
    final public function allowsAction(string $action): bool
    {
        return in_array($action, $this->actions, true);
    }

    /**
     * @return list<string>
     */
    final public function uploadFields(): array
    {
        return $this->uploads;
    }

    /**
     * Stores one uploaded file into a declared upload property.
     *
     * @param string $field Upload field name.
     * @param \Psr\Http\Message\UploadedFileInterface $file Uploaded file.
     * @return void
     */
    final public function storeUpload(string $field, UploadedFileInterface $file): void
    {
        if (!in_array($field, $this->uploads, true)) {
            throw new BadRequestException(sprintf('The upload field "%s" is not allowed.', $field));
        }

        if (!isset($this->publicProperties()[$field])) {
            throw new BadRequestException(sprintf('The upload property "%s" is missing.', $field));
        }

        $clientFilename = $file->getClientFilename() ?? 'upload.bin';
        $extension = pathinfo($clientFilename, PATHINFO_EXTENSION);
        $safeName = bin2hex(random_bytes(8)) . ($extension !== '' ? '.' . $extension : '');

        $directory = TMP . 'cakewire_uploads' . DIRECTORY_SEPARATOR;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new InvalidArgumentException('Unable to create the CakeWire upload directory.');
        }

        $target = $directory . $safeName;
        $file->moveTo($target);

        $this->publicProperties()[$field]->setValue($this, $clientFilename . ' → ' . $safeName);
    }

    /**
     * @param string $field Field name.
     * @param string $message Error message.
     * @return void
     */
    protected function addError(string $field, string $message): void
    {
        $this->errors[$field] = $message;
    }

    /**
     * @param string|null $field Optional field name.
     * @return void
     */
    protected function resetErrors(?string $field = null): void
    {
        if ($field === null) {
            $this->errors = [];

            return;
        }

        unset($this->errors[$field]);
    }

    /**
     * @param string $field Field name.
     * @return bool
     */
    protected function hasError(string $field): bool
    {
        return isset($this->errors[$field]);
    }

    /**
     * @param string $field Field name.
     * @return string|null
     */
    protected function getError(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }

    /**
     * Validates public properties using CakePHP validation rules.
     *
     * Rule format: `'field' => 'required|email|maxLength:255'`
     *
     * @param array<string, string> $rules Validation rules.
     * @return bool
     */
    /**
     * Runs validation and hooks after a `wire:model.live` sync for one property.
     *
     * @param string $property Updated public property name.
     * @return void
     */
    final public function runLiveSync(string $property): void
    {
        $this->resetErrors($property);
        $this->validateOnly($property);
        $this->callUpdatedHook($property);
    }

    /**
     * @return array<string, string>
     */
    protected function validationRules(): array
    {
        return $this->validationRules;
    }

    /**
     * @param array<string, string>|null $rules Optional rules; defaults to {@see validationRules()}.
     * @return bool
     */
    protected function validate(?array $rules = null): bool
    {
        $this->resetErrors();
        $rules ??= $this->validationRules();

        if ($rules === []) {
            return true;
        }

        $validator = $this->buildValidator($rules);
        $errors = $validator->validate($this->validationData());
        $this->collectValidationErrors($errors);

        return $this->errors === [];
    }

    /**
     * Validates a single property (Livewire-style live validation).
     *
     * @param string $field Property name.
     * @return bool
     */
    protected function validateOnly(string $field): bool
    {
        $rules = $this->validationRules();
        if (!isset($rules[$field])) {
            return true;
        }

        $validator = $this->buildValidator([$field => $rules[$field]]);
        $errors = $validator->validate([
            $field => $this->validationData()[$field] ?? null,
        ]);
        $this->collectValidationErrors($errors);

        return !$this->hasError($field);
    }

    /**
     * @param string $property Public property that was updated.
     * @return void
     */
    protected function callUpdatedHook(string $property): void
    {
        $method = 'updated' . Inflector::camelize($property);
        if (!method_exists($this, $method)) {
            return;
        }

        $reflection = new ReflectionMethod($this, $method);
        if ($reflection->isStatic() || $reflection->isPrivate()) {
            return;
        }

        $reflection->invoke($this);
    }

    /**
     * @param array<string, string> $rules Field rules.
     * @return \Cake\Validation\Validator
     */
    private function buildValidator(array $rules): Validator
    {
        $validator = new Validator();
        foreach ($rules as $field => $ruleList) {
            foreach (explode('|', $ruleList) as $rule) {
                $this->applyValidationRule($validator, $field, trim($rule));
            }
        }

        return $validator;
    }

    /**
     * @param array<string, array<string, string>> $errors Validator errors.
     * @return void
     */
    private function collectValidationErrors(array $errors): void
    {
        foreach ($errors as $field => $messages) {
            $first = reset($messages);
            if (is_string($first)) {
                $this->addError($field, $first);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function validationData(): array
    {
        $data = [];
        foreach ($this->publicProperties() as $name => $property) {
            if ($property->isInitialized($this)) {
                $data[$name] = $property->getValue($this);
            }
        }

        return $data;
    }

    /**
     * Renders a validation message placeholder (Livewire `@error` equivalent).
     *
     * @param string $field Field name.
     * @return string
     */
    protected function error(string $field): string
    {
        return sprintf('<span class="cakewire-field-error" wire:error="%s"></span>', htmlspecialchars($field, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * Renders the component view or a custom implementation when overridden.
     *
     * Surchargez et retournez {@see view()} pour choisir explicitement le template :
     *
     * ```php
     * use function CakeWire\view;
     *
     * public function render(): ComponentTemplate
     * {
     *     return view('dossier1/ficher');
     * }
     * ```
     *
     * @return string|\CakeWire\View\ComponentTemplate
     */
    public function render(): string|ComponentTemplate
    {
        $html = (new ComponentViewRenderer())->render($this, $this->view !== '' ? $this->view : null);

        return (new WireDirectiveCompiler())->compile($html);
    }

    /**
     * HTML final du composant (résout {@see view()} si besoin).
     */
    final public function renderHtml(): string
    {
        return self::resolveRenderOutput($this->render(), $this);
    }

    /**
     * @param string|\CakeWire\View\ComponentTemplate $output
     */
    public static function resolveRenderOutput(string|ComponentTemplate $output, Component $component): string
    {
        if ($output instanceof ComponentTemplate) {
            return $output->render($component);
        }

        return $output;
    }

    /**
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @param string $field Field name.
     * @param string $rule Rule definition.
     * @return void
     */
    private function applyValidationRule(Validator $validator, string $field, string $rule): void
    {
        if ($rule === 'required') {
            $validator->requirePresence($field, true, 'Ce champ est obligatoire.');
            $validator->notEmptyString($field, 'Ce champ est obligatoire.');

            return;
        }

        if ($rule === 'email') {
            $validator->email($field, false, 'Adresse e-mail invalide.');

            return;
        }

        if (str_starts_with($rule, 'maxLength:')) {
            $length = (int)substr($rule, strlen('maxLength:'));
            $validator->maxLength($field, $length, sprintf('Maximum %d caractères.', $length));

            return;
        }

        if (str_starts_with($rule, 'minLength:')) {
            $length = (int)substr($rule, strlen('minLength:'));
            $validator->minLength($field, $length, sprintf('Minimum %d caractères.', $length));

            return;
        }

        throw new InvalidArgumentException(sprintf('Unsupported CakeWire validation rule "%s".', $rule));
    }

    /**
     * @return array<string, \ReflectionProperty>
     */
    private function publicProperties(): array
    {
        $properties = [];
        foreach ((new ReflectionClass($this))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if (!$property->isStatic()) {
                $properties[$property->getName()] = $property;
            }
        }

        return $properties;
    }
}
