# CakeWire — documentation complète

**Documentation HTML (navigateur)** : une fois l’application démarrée, ouvrez  
`/cake_wire/doc/index.html` (fichiers statiques dans `plugins/CakeWire/webroot/doc/`).

CakeWire reproduit le modèle **[Livewire 3](https://livewire.laravel.com/docs/3.x)** pour **CakePHP 5** : composants PHP, état synchronisé, actions serveur, directives `wire:*` dans le HTML, runtime **Alpine.js 3**.

---

## Table des matières

1. [Concepts](#concepts)
2. [Installation](#installation)
3. [Runtime Alpine.js et montage](#runtime-alpinejs-et-montage)
4. [Cycle de vie d’un composant](#cycle-de-vie-dun-composant)
5. [API PHP](#api-php)
6. [Directives `wire:*`](#directives-wire)
7. [Objet magique `$wire` (Alpine)](#objet-magique-wire-alpine)
8. [Sécurité](#sécurité)
9. [Parité Livewire ↔ CakeWire](#parité-livewire--cakewire)
10. [Limites connues](#limites-connues)

---

## Concepts

| Idée Livewire | CakeWire |
|---------------|----------|
| Composant PHP | Classe `CakeWire\Component\Component` |
| Propriétés publiques | État sérialisé JSON (`dehydrate` / `hydrate`) |
| Actions | Méthodes publiques listées dans `$actions` |
| `wire:model` | Liaison champ ↔ propriété + requête AJAX |
| `return view('…')` | Templates PHP sous `templates/CakeWire/` (syntaxe proche de Livewire) |
| Endpoint Livewire | `POST /cake-wire/update` (plugin) |

Le navigateur n’exécute jamais la logique métier : il envoie l’état et le nom d’une action ; PHP met à jour l’état, re-rend le HTML interne du composant, renvoie `{ html, state }`.

---

## Installation

### Composer et plugin

Package Packagist : **[cakewire/cakewire](https://packagist.org/packages/cakewire/cakewire)**  
Dépôt : **[github.com/arbims/Cakewire](https://github.com/arbims/Cakewire)**

**Installation stable** (release taguée, ex. `v1.0.0`) :

```bash
composer require cakewire/cakewire
```

**Branche `main` avant première release** (projet en `minimum-stability: stable` par défaut) :

```bash
composer require cakewire/cakewire:dev-main@dev
```

Ou ajoutez temporairement dans le `composer.json` de l’application :

```json
{
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

Puis `composer require cakewire/cakewire:dev-main`.

**Installation depuis GitHub** (sans Packagist) :

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/arbims/Cakewire"
        }
    ],
    "require": {
        "cakewire/cakewire": "^1.0"
    }
}
```

Après `composer update`, le plugin est installé sous `vendor/cakewire/cakewire/`.
L’installer CakePHP l’enregistre automatiquement si le plugin Composer est autorisé :

```json
"config": {
    "allow-plugins": {
        "cakephp/plugin-installer": true
    }
}
```

Sur [Packagist](https://packagist.org/packages/cakewire/cakewire), cliquez **Update** après avoir poussé un tag (`v1.0.0`, …) si la version stable n’apparaît pas encore.

`config/plugins.php` (si besoin de forcer l’activation) :

```php
return [
    'CakeWire\CakeWirePlugin' => [],
];
```

### Helper et layout

`src/View/AppView.php` :

```php
public function initialize(): void
{
    parent::initialize();
    $this->addHelper('CakeWire.CakeWire');
}
```

Layout (une fois par page) — recommandé :

```php
<!-- dans <head> -->
<?= $this->CakeWire->headAssets() ?>

<!-- avant </body> -->
<?= $this->CakeWire->runtimeScripts() ?>
```

Équivalent en une ligne : `<?= $this->CakeWire->scripts() ?>` (= `headAssets()` + `runtimeScripts()`).

| Méthode | Contenu |
|---------|---------|
| `headAssets()` | Meta CSRF (`csrfToken`), CSS `wire:loading` / `wire:cloak` |
| `runtimeScripts()` | `morphdom.min.js`, `cake-wire.js`, puis Alpine.js 3 (CDN, `defer`) |

**Ordre obligatoire** : `morphdom.min.js` puis `cake-wire.js` **sans** `defer`, **avant** le script Alpine (`defer`). Alpine ne démarre qu’après le parse du DOM ; le runtime CakeWire doit déjà être chargé et enregistrer `Alpine.data('cakewireShell', …)` dans l’événement `alpine:init`.

Le script runtime est servi avec un paramètre de version (`cake-wire.js?v=2`) pour éviter un JS mis en cache sans les API récentes. Incrémentez `CakeWireHelper::RUNTIME_VERSION` après une mise à jour du plugin si les utilisateurs ne voient pas le nouveau comportement.

### Enregistrer un composant

`config/bootstrap.php` :

```php
use Cake\Core\Configure;

Configure::write('CakeWire.components.counter', \App\CakeWire\CounterComponent::class);
Configure::write('CakeWire.components.contact-form', \App\CakeWire\ContactFormComponent::class);
```

Alias : `[a-z][a-z0-9-]*` uniquement.

### Générer un composant (CLI)

Depuis la racine de l’application CakePHP :

```bash
bin/cake cakewire component TodoList
bin/cake cakewire component TodoList --register
bin/cake cakewire component Reports --alias=reports --view=reports/index --force
```

Crée par défaut :

- `src/CakeWire/TodoListComponent.php` — classe avec `render()` → `view('todo-list')`
- `templates/CakeWire/todo-list.php` — template minimal

Options utiles : `--register` / `-r` (ajoute `Configure::write` dans `config/bootstrap.php`), `--no-template`, `--force`.

Configuration optionnelle :

```php
Configure::write('CakeWire.generator.namespace', 'App\\CakeWire');
Configure::write('CakeWire.generator.componentPath', ROOT . 'src' . DS . 'CakeWire' . DS);
```

### Monter un composant

```php
<?= $this->CakeWire->component('counter', ['count' => 0]) ?>
```

Produit une racine du type :

```html
<div
  data-cakewire-config="{&quot;component&quot;:&quot;counter&quot;,...}"
  data-cakewire-alias="counter"
  x-data="cakewireShell()">
  <!-- HTML interne (directives wire:* déjà compilées côté serveur) -->
</div>
```

- **`data-cakewire-config`** — JSON (composant, endpoint, état initial).
- **`data-cakewire-alias`** — alias pour `$dispatch->to('…')` et le ciblage d’événements.
- **`x-data="cakewireShell()"`** — factory Alpine enregistrée par `cake-wire.js` (ne pas utiliser `CakeWire.shell()` dans le HTML : préférer le nom Alpine).

---

## Runtime Alpine.js et montage

### Deux couches : PHP compile, JS complète

| Étape | Où | Rôle |
|-------|-----|------|
| **Premier HTML** | `WireDirectiveCompiler` (PHP) | Transforme `wire:click`, `wire:model`, `wire:loading`, `wire:error`, etc. en attributs Alpine (`x-on:click`, `x-model`, `x-show`, …) avant envoi au navigateur. |
| **Après réponse AJAX** | `cake-wire.js` (`bindWire`) | Recompile les directives restantes (`wire:poll`, `wire:init`, `wire:dirty`, …) sur le HTML injecté, puis `Alpine.initTree()`. |

Exemple de compilation serveur :

| `wire:*` (vue PHP) | Alpine généré (extrait) |
|--------------------|-------------------------|
| `wire:click="increment"` | `x-on:click.prevent="handleWireClick($event, 'increment')"` |
| `wire:model.live="email"` | `x-model="state.email"` + `x-on:input.debounce.150ms="handleWireSync('email')"` |
| `wire:loading wire:target="increment"` | `x-show="wireIsLoading('increment')"` |
| `wire:error="email"` | `x-show="wireHasError('email')"` + `x-text="wireErrorMessage('email')"` |

Les handlers `handleWireClick`, `handleWireSync`, `wireIsLoading`, … sont définis dès le **`cakewireShell()`** ; ils délèguent à `runAction`, `sync`, etc. une fois le blueprint runtime fusionné.

### Montage d’un composant

1. Alpine instancie `cakewireShell()` (état vide + délégués).
2. Le hook Alpine **`init()`** du shell lit `data-cakewire-config`, construit le blueprint (`createComponent`) et fusionne état, `runAction`, `sync`, polls, etc.
3. Le **`init()`** du blueprint exécute `bindWire` pour les directives non compilées côté serveur.

API globale utile (console / scripts) :

| API | Description |
|-----|-------------|
| `CakeWire.mount(data, el)` | Fusion manuelle du blueprint (legacy ; le helper n’utilise plus `x-init`) |
| `CakeWire.component(options)` | Blueprint JS à partir d’un objet config |
| `CakeWire.navigate(url)` | Navigation `wire:navigate` |
| `CakeWire.dispatch` / `dispatchTo` | Événements navigateur |

---

## Cycle de vie d’un composant

1. **Montage PHP** — `ComponentRegistry` instancie la classe, `mount($properties)` hydrate les propriétés publiques.
2. **Premier rendu** — la vue PHP est compilée (`WireDirectiveCompiler`) puis envoyée dans le wrapper `component()`.
3. **Init Alpine** — `cakewireShell()` + `init()` runtime ; `bindWire` pour poll/init/dirty/etc.
4. **Interaction** — clic, submit, `wire:model`, poll, etc. → `POST /cake-wire/update` avec JSON :

```json
{
  "component": "counter",
  "state": { "count": 1 },
  "action": "increment",
  "params": []
}
```

5. **Réponse** — PHP `hydrate($state)`, exécute l’action si autorisée, `renderHtml()` (résout `view()` si besoin), renvoie HTML + état.
6. **Patch DOM** — le client **morphe** les enfants du wrapper (`morphdom`, le nœud `x-data="cakewireShell()"` reste), recompile les `wire:*` restants via `bindWire`, puis `Alpine.initTree()` sur les nœuds ajoutés ou mis à jour.

Sans `action` (sync / `$refresh` / `wire:model`), seule la synchronisation + re-rendu ont lieu.

---

## API PHP

### Vues et `render()` (syntaxe Livewire)

Chaque composant choisit son template via **`render()`** et la fonction helper **`view()`** (comme Livewire) :

```php
use CakeWire\Component\Component;
use CakeWire\View\ComponentTemplate;

use function CakeWire\view;

final class ShowPosts extends Component
{
    public function render(): ComponentTemplate
    {
        return view('posts/list');
    }
}
```

Fichier correspondant : **`templates/CakeWire/posts/list.php`**.

| Argument `view()` | Fichier résolu |
|-------------------|----------------|
| `'counter'` | `templates/CakeWire/counter.php` |
| `'posts/list'` | `templates/CakeWire/posts/list.php` |
| `'livewire.show-posts'` | `templates/CakeWire/show-posts.php` (préfixe `livewire.` ou `cakewire.` ignoré) |
| `'cakewire.posts.list'` | `templates/CakeWire/posts/list.php` (`.` → `/`) |

Les noms doivent respecter `[a-z][a-z0-9\-\/]*` (minuscules, tirets, sous-dossiers).

Le helper `CakeWire\view()` est chargé par Composer (`plugins/CakeWire/src/functions.php`). Après mise à jour du plugin, exécutez `composer dump-autoload` si l’IDE ne le voit pas.

#### Syntaxe des templates (proche de Blade)

Pas de moteur Blade : fichiers **`.php`** avec raccourcis familiers :

| Syntaxe | Effet |
|---------|--------|
| `{{ $count }}` | Échappement HTML (`htmlspecialchars`) |
| `{!! $feedback !!}` | HTML brut |
| `@error('email')` | `<span wire:error="email">` |
| `wire:click`, `wire:model`, … | Directives CakeWire (compilées au rendu) |

Le PHP classique reste autorisé (`<?php if (...) : ?>`, boucles, etc.). Les **propriétés publiques** du composant sont disponibles comme variables (`$count`, `$email`, …).

**Composant** (`src/CakeWire/CounterComponent.php`) :

```php
final class CounterComponent extends Component
{
    public int $count = 0;
    protected array $actions = ['increment', 'decrement', 'reset'];

    public function increment(): void { $this->count++; }

    public function render(): ComponentTemplate
    {
        return view('counter');
    }
}
```

**Vue** (`templates/CakeWire/counter.php`) :

```html
<div>
    <h1>Compteur : {{ $count }}</h1>
    <button type="button" wire:click="increment">Incrémenter</button>
</div>
```

#### Rendu sans `view()` (rétrocompatibilité)

Si vous **ne surchargez pas** `render()`, CakeWire infère le template :

- propriété `protected string $view = 'mon-template';`, ou
- nom de classe : `CounterComponent` → `counter`, `ContactFormComponent` → `contact-form`.

Vous pouvez aussi retourner une **`string`** depuis `render()` (HTML en PHP pur, déjà compilé ou à compiler vous-même) — utile pour des tests ou du markup minimal.

Le framework appelle **`renderHtml()`** (méthode `final`) pour obtenir le HTML final : elle invoque `render()`, résout un `ComponentTemplate` retourné par `view()`, puis applique `WireDirectiveCompiler`.

Configuration optionnelle du dossier des vues :

```php
Configure::write('CakeWire.templates', ROOT . 'templates' . DS . 'CakeWire' . DS);
```

| Méthode | Rôle |
|---------|------|
| `mount(array $properties)` | Valeurs initiales au montage |
| `hydrate(array $state)` | Applique l’état venant du client (propriétés publiques non readonly) |
| `dehydrate(): array` | Exporte l’état pour le JSON |
| `allowsAction(string $action): bool` | Vérifie la whitelist `$actions` |
| `render(): string\|ComponentTemplate` | Template (`view()`) ou HTML brut |
| `renderHtml(): string` | HTML final (helper, endpoint AJAX) — ne pas surcharger |

### Helper `CakeWireHelper`

| Méthode | Description |
|---------|-------------|
| `component(string $alias, array $props = [])` | Monte le composant (`data-cakewire-config`, `cakewireShell()`) |
| `scripts()` | `headAssets()` + `runtimeScripts()` |
| `headAssets()` | CSRF + styles |
| `runtimeScripts()` | `cake-wire.js?v=…` + Alpine CDN |
| `styles()` | CSS pour `wire:loading` / `wire:cloak` |
| `model(string $property)` | Attribut `wire:model="..."` |
| `click(string $action)` | Attribut `wire:click="..."` |

---

## Directives `wire:*`

Les directives **courantes** (`model`, `click`, `submit`, `loading`, `error`, `on:*`, …) sont **compilées en PHP** dans `WireDirectiveCompiler` lors du `render()`. **`cake-wire.js`** complète la compilation au **premier montage** et **après chaque mise à jour AJAX** pour le reste (`poll`, `init`, `dirty`, `navigate`, etc.).

### Liaison de données — `wire:model`

Comportement aligné sur **Livewire 3** : **`wire:model` est différé** (sync au `change`), pas en temps réel.

| Directive | Comportement |
|-----------|--------------|
| `wire:model="prop"` | Sync au `change` (select, checkbox, textarea, input) |
| `wire:model.live="prop"` | Sync à chaque saisie (`input`, debounce 150 ms par défaut) + validation live si `$validationRules` |
| `wire:model.live.debounce.300ms="prop"` | Debounce personnalisé |
| `wire:model.live.throttle.150ms="prop"` | Throttle |
| `wire:model.blur="prop"` | Sync au blur |
| `wire:model.number="prop"` | Cast numérique (`x-model.number`) |
| `wire:model.boolean="prop"` | Booléen (`x-model.boolean`) |

Exemple :

```html
<input type="text" wire:model.live.debounce.200ms="title">
<textarea wire:model="content"></textarea>
<input type="checkbox" wire:model.boolean="published">
```

### Actions — événements

Toute directive `wire:<événement>[.<modificateur>]` non réservée devient un listener Alpine.

| Directive | Usage |
|-----------|--------|
| `wire:click="save"` | Clic |
| `wire:click.prevent="save"` | Avec `preventDefault` |
| `wire:submit="save"` | Formulaire (`prevent` par défaut) |
| `wire:keydown.enter="search"` | Touche Entrée |
| `wire:keydown="track"` | Keydown générique |
| `wire:keyup`, `wire:mouseenter`, `wire:mouseleave`, … | Même principe |

Expressions d’action :

```html
<button wire:click="increment">+</button>
<button wire:click="add(2, 'note')">Add</button>
<button wire:click="$refresh">Rafraîchir</button>
```

`$refresh` re-rend le composant côté serveur avec l’état actuel (comme Livewire).

Paramètres supportés : entiers, floats, `true` / `false` / `null`, chaînes `'...'` ou `"..."`.

#### Validation live (Livewire)

Définissez `$validationRules` sur le composant. Chaque sync `wire:model.live` envoie `syncProperty` au serveur, qui exécute `validateOnly()` et le hook `updated{Property}()` (ex. `updatedEmail()`).

```php
protected array $validationRules = [
    'email' => 'required|email',
];
```

```html
<input wire:model.live="email">
<span wire:error="email"></span>
<span wire:loading wire:target="email">Vérification…</span>
```

### Événements — `$dispatch` et `wire:on`

Émission (Alpine / Livewire) :

```html
<button wire:click="$dispatch('item-added', { id: 1 })">Publier</button>
<button wire:click="$dispatch('item-added', { id: 1 })->to('event-listener')">Cibler un composant</button>
```

Réception côté serveur :

```html
<section wire:on:item-added="onItemAdded">...</section>
```

```php
public function onItemAdded(array $payload = []): void
{
    // $payload contient le detail de l'événement
}
```

L'action doit être dans `$actions`. Les alias de composants sont exposés via `data-cakewire-alias` (attribut `component()` du helper).

API JS : `CakeWire.dispatch(name, detail)`, `CakeWire.dispatchTo(name, detail, alias)`.

### Confirmation — `wire:confirm`

```html
<button
    type="button"
    wire:click="delete"
    wire:confirm="Supprimer cet élément ?">
    Supprimer
</button>
```

Affiche `confirm()` natif avant l’appel serveur.

### Chargement — `wire:loading`

| Directive | Effet |
|-----------|--------|
| `wire:loading` | `x-show` pendant une requête |
| `wire:loading.remove` | Visible sauf pendant le chargement |
| `wire:loading.attr` | `:disabled` pendant le chargement |
| `wire:loading.class="opacity-50"` | Classe CSS conditionnelle |
| `wire:loading.delay.200ms` | Affiche le loading après un délai |
| `wire:target="save"` | Filtre sur une action |
| `wire:target="save, remove"` | Plusieurs actions |
| `wire:target="username"` | Pendant sync `wire:model.live` de `username` |

Exemple :

```html
<form wire:submit="save">
    <input wire:model.live="username">
    <button type="submit">Enregistrer</button>
    <span wire:loading wire:target="save">Enregistrement…</span>
    <span wire:loading wire:target="username">Vérification…</span>
</form>
```

CSS global (via `CakeWireHelper::styles()`) : `[wire:loading] { display: none; }` avant compilation Alpine.

### Modifications locales — `wire:dirty`

Compare l’état Alpine `state` à `serverState` (dernière réponse serveur).

| Directive | Effet |
|-----------|--------|
| `wire:dirty` | Affiché si l’état global diffère |
| `wire:dirty="title"` | Cible une propriété |
| `wire:dirty.remove` | Inverse |
| `wire:dirty.class` | Classe `is-dirty` |

Peut être combiné avec `wire:target` sur le même élément.

### Initialisation — `wire:init`

```html
<div wire:init="loadData">...</div>
```

Appelle l’action (ou `$refresh`) **une seule fois** au premier montage Alpine.

### Polling — `wire:poll`

```html
<div wire:poll.5s="refresh">...</div>
<div wire:poll.30s="$refresh"></div>
```

Intervalle : suffixe `.5s`, `.2m`, `.1h`, `.1d` (défaut `2s` si omis).

### DOM — `wire:ignore`, `wire:cloak`, `wire:key`

| Directive | Effet |
|-----------|--------|
| `wire:ignore` | `x-ignore` — Alpine ne touche pas le sous-arbre |
| `wire:cloak` | Masqué jusqu’à init (`x-cloak` + CSS) |
| `wire:key="id"` | `data-cakewire-key` — clé de réconciliation morphdom |

### Validation — `@error` Livewire

Côté PHP, utilisez `$this->validate([...])` et `$this->error('field')` ou `wire:error` dans le HTML.

```php
if (!$this->validate([
    'email' => 'required|email|maxLength:255',
    'name' => 'required|minLength:2',
])) {
    return;
}
```

Règles supportées : `required`, `email`, `maxLength:N`, `minLength:N`.

Les erreurs sont exposées dans `state._errors` (jamais hydratées depuis le client).

```html
<input wire:model.live="email">
<span wire:error="email"></span>
```

Équivalent helper : `<?= $this->CakeWire->error('email') ?>`.

### Upload — `wire:model` sur `<input type="file">`

Déclarez le champ dans `$uploads` du composant :

```php
protected array $uploads = ['avatar'];
public ?string $avatar = null;
```

```html
<input type="file" wire:model="avatar" accept="image/*">
<span wire:error="avatar"></span>
```

Le navigateur envoie un `multipart/form-data` ; PHP stocke le fichier dans `tmp/cakewire_uploads/` et met à jour la propriété.

### Navigation — `wire:navigate`

Navigation style SPA (fetch HTML, remplacement de `[data-cakewire-navigate]`) :

```html
<a href="/contact" wire:navigate>Contact</a>
```

Les liens du **layout** (ex. `Html->link(..., ['wire:navigate' => true])`) n’ont pas besoin d’Alpine sur le `<a>` : `cake-wire.js` intercepte les clics via une **délégation document** sur `a[href][wire:navigate]`.

Le layout doit contenir :

```html
<main data-cakewire-navigate>...</main>
```

API JS : `CakeWire.navigate(url)`.

### Hors ligne — `wire:offline`

```html
<div wire:offline>Vous êtes hors ligne.</div>
<div wire:offline.class="opacity-50">...</div>
```

Affichage basé sur `navigator.onLine`.

---

## Objet magique `$wire` (Alpine)

Comme Livewire, CakeWire expose **`$wire`** dans les expressions Alpine du composant :

```html
<input type="text" wire:model.live="title">
<p x-text="$wire.title.length + ' caractères'"></p>
<button type="button" x-on:click="$wire.title = ''">Effacer</button>
<button type="button" x-on:click="$wire.set('title', '')">Effacer (sync)</button>
```

| API | Description |
|-----|-------------|
| `$wire.prop` | Lecture / écriture locale de `state.prop` |
| `$wire.get('prop')` | Lecture |
| `$wire.set('prop', value)` | Écriture + `sync('prop')` |
| `$wire.runAction('action', ...)` | Action serveur (équivalent `wire:click`) |
| `$wire.refresh()` | Re-rendu serveur |

`$wire` n’est disponible que **à l’intérieur** d’un composant monté (ancêtre `[data-cakewire-config]`).

---

## Sécurité

1. **Whitelist d’actions** — toute méthode appelée doit figurer dans `$actions`.
2. **Enregistrement** — alias → classe dans `Configure::write('CakeWire.components.*')`.
3. **État client non fiable** — toujours re-valider en PHP (auth, ownership, CSRF).
4. **CSRF** — token via meta `csrfToken` + en-tête `X-CSRF-Token`.
5. **Propriétés** — seules les propriétés publiques non statiques sont hydratées ; les `readonly` sont ignorées à l’hydratation.

---

## Parité Livewire ↔ CakeWire

| Fonctionnalité Livewire 3 | CakeWire |
|---------------------------|----------|
| Composants + propriétés publiques | Oui |
| `render()` + `view('…')` | Oui (`CakeWire\view()`, sous-dossiers `templates/CakeWire/`) |
| `wire:model` différé / `.live` / `.blur` | Oui |
| `.debounce` / `.throttle` sur `.live` | Oui |
| `.number` / `.boolean` | Oui |
| `wire:click`, `wire:submit`, événements `wire:*` | Oui |
| `wire:confirm` | Oui |
| `$refresh` | Oui |
| `wire:loading` (+ target, delay, class, attr, remove) | Oui |
| `wire:dirty` | Oui |
| `wire:init` | Oui |
| `wire:poll` | Oui |
| `wire:ignore`, `wire:cloak`, `wire:key` | Oui (`wire:key` → `data-cakewire-key` pour le morph) |
| `wire:offline` | Basique |
| `$wire` Alpine | Oui |
| Validation `@error` / `$this->validate()` | Oui (`wire:error`, `state._errors`) |
| `wire:navigate` (SPA) | Oui (zone `data-cakewire-navigate`) |
| Upload fichiers `wire:model` | Oui (`$uploads`, multipart) |
| Composants imbriqués / `@livewire` | Non (un alias par montage) |
| Events `$dispatch` / `wire:on` | Oui |
| Lazy / defer / `@persist` | Non |
| Eloquent `wire:model` sur modèles | Non |
| Tests Livewire PHP | Utiliser PHPUnit + tests d’intégration `/cake-wire/update` |

---

## Morph DOM

Après chaque réponse AJAX (et lors de `wire:navigate`), CakeWire met à jour le DOM avec **[morphdom](https://github.com/patrick-steele-idem/morphdom)** au lieu d’un `innerHTML` brutal :

- seuls les nœuds **modifiés** sont touchés (meilleure préservation du focus, moins de clignotement) ;
- **`wire:key="id"`** devient `data-cakewire-key` et sert de clé stable pour réconcilier les listes ;
- **`wire:ignore`** (`x-ignore`) : le sous-arbre n’est pas mis à jour ;
- l’élément **focus** (input, textarea, select) n’est pas écrasé pendant la frappe ;
- les champs **file** ne sont pas remplacés (limitation navigateur).

Fallback : si `morphdom` n’est pas chargé, retour à `innerHTML`.

API de debug : `CakeWire.morphComponentDom(root, htmlString)`.

---

## Limites connues

- **Morph sans plugin Alpine dédié** : pas d’équivalent complet de `@alpinejs/morph` ; des nœuds très dynamiques côté Alpine peuvent parfois nécessiter `wire:ignore`.
- **Pas de file d’attente offline** : les requêtes échouent si le réseau est coupé.
- **Alpine requis** : sans JS, seul le HTML initial s’affiche.
- **Une action par requête** : pas de batching Livewire v3 avancé.

---

## Débogage

- Vérifier la console navigateur (erreurs de directive ou HTTP 400/403).
- **403** : token CSRF manquant ou invalide (meta `csrfToken` dans le `<head>`).
- **400** : action non listée dans `$actions` ou alias inconnu.
- **Network** : corps JSON et réponse `{ "html", "state" }`.
- **`cakewireShell is not defined` / `wireIsLoading is not defined`** : Alpine a démarré sans `cake-wire.js`, ou une **vieille version** du JS est en cache — vérifier l’ordre des scripts et recharger `cake-wire.js?v=…` (hard refresh).
- **`CakeWire is not mounted yet`** : clic avant la fin du `init()` du composant (rare) ; vérifier une erreur JS antérieure dans la console.
- **Layout SPA** : zone `[data-cakewire-navigate]` pour `wire:navigate` ; après navigation, Alpine réinitialise les composants présents dans le fragment injecté.

---

## Exemples dans ce dépôt

| URL | Composant | Directives illustrées |
|-----|-----------|------------------------|
| `/counter` | `CounterComponent` | `view('counter')`, `wire:click`, `wire:loading`, `wire:target` |
| `/contact` | `ContactFormComponent` | `view('contact-form')`, `wire:model`, validation, `wire:submit` |
| `/users` | `UsersListComponent` | `view('users-list')`, `wire:model.live.debounce`, filtres |
| `/posts` | `PostsListComponent` | `view('posts-list')`, CRUD, validation |
| `/profile` | `ProfileUploadComponent` | `view('profile-upload')`, upload multipart |
| `/events` | `EventPublisher` + `EventListener` | `view('event-publisher')` / `view('event-listener')`, `$dispatch`, `wire:on` |

---

## Référence rapide des directives supportées

```
wire:model[.live|.blur|.number|.boolean][.debounce.Nms|.throttle.Nms]="prop"
wire:click[.prevent]="action"
wire:submit="action"
wire:keydown[.enter|...]="action"
wire:keyup, wire:mouseenter, wire:mouseleave, … (événements DOM)
wire:confirm="message"
wire:loading[.remove|.attr|.class|.delay.Nms]
wire:target="action" | "a, b" | "propertyName"
wire:dirty[.remove|.class][="property"]
wire:init="action"
wire:poll[.5s|.2m|.1h|.1d]="action|$refresh"
wire:ignore
wire:cloak
wire:key="unique"
wire:offline[.class]
wire:error="field"
wire:navigate (sur `<a href="...">`)
$refresh (expression d’action)
$wire (magic Alpine)
```

Pour toute question d’évolution, comparez avec la [documentation Livewire 3](https://livewire.laravel.com/docs/3.x) et la section [Parité](#parité-livewire--cakewire) ci-dessus.
