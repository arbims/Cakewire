# Publication Packagist

## Package

- **Packagist** : https://packagist.org/packages/cakewire/cakewire  
- **GitHub** : https://github.com/arbims/Cakewire  

## Première release stable

Composer en mode `minimum-stability: stable` (défaut) **ne voit que les versions taguées**.  
Sans tag `v*`, seule `dev-main` existe → erreur « Could not find a version matching minimum-stability (stable) ».

```bash
git tag -a v1.0.0 -m "First stable release"
git push origin v1.0.0
```

Sur Packagist, déclenchez **Update** (ou attendez le webhook GitHub) pour indexer `1.0.0`.

Les utilisateurs pourront alors :

```bash
composer require cakewire/cakewire
```

## Webhook GitHub → Packagist

1. Packagist → package → **Settings** → **GitHub Service Hook**  
2. Sur GitHub → repo **Cakewire** → **Settings** → **Webhooks** : hook Packagist actif  

Chaque push de tag met à jour les versions automatiquement.

## Version de développement

```bash
composer require cakewire/cakewire:dev-main@dev
```

## Mainteneur : semver

- Correctifs : `v1.0.1`  
- Features : `v1.1.0`  
- Breaking : `v2.0.0`  

Incrémentez `CakeWireHelper::RUNTIME_VERSION` quand `cake-wire.js` change de façon incompatible avec le cache navigateur.
