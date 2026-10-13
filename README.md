# CakeWire

Composants **CakePHP** full-stack, syntaxe **`wire:*`** et modèle mental **[Livewire 3](https://livewire.laravel.com/)**, runtime **[Alpine.js 3](https://alpinejs.dev/)**.

## Documentation

| Format | Lien |
|--------|------|
| **HTML (web)** | `/cake_wire/doc/index.html` — dossier `webroot/doc/` |
| **Markdown** | [DOCUMENTATION.md](./DOCUMENTATION.md) — source complète du plugin |

## Démarrage rapide

```php
// config/bootstrap.php
Configure::write('CakeWire.components.counter', \App\CakeWire\CounterComponent::class);

// layout
<?= $this->CakeWire->scripts() ?>

// vue
<?= $this->CakeWire->component('counter') ?>
```

```php
use CakeWire\Component\Component;
use CakeWire\View\ComponentTemplate;

use function CakeWire\view;

final class CounterComponent extends Component
{
    public int $count = 0;
    protected array $actions = ['increment'];

    public function increment(): void { $this->count++; }

    public function render(): ComponentTemplate
    {
        return view('counter');
    }
}
```

Template : `templates/CakeWire/counter.php` (syntaxe `{{ $count }}`, `wire:click`, etc.).

## Licence

MIT
