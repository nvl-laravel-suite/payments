# Install and publish: nvl/payments

Targets PHP 8.4+ and Laravel 13. Install the selected NVL dependency closure on compatible 7.x versions.

```sh
composer require nvl/payments:^7.0
```

## Provider discovery

Composer package discovery registers these package-owned providers:

- `Nvl\Payments\Providers\PaymentsServiceProvider`

Do not publish or copy vendor providers into the application. If discovery is deliberately disabled, register these exact classes in the application’s `bootstrap/providers.php`. Host policy and extension bindings belong in an application provider; see [usage](usage.md) for which bindings are required.

## Skills

Laravel Boost discovers this folder at `resources/boost/skills/` in the installed Composer package. In the consumer application, run:

```sh
php artisan boost:install --skills --no-interaction
```

Select skills through the installed Boost configuration when setting it up interactively. `php artisan boost:update --no-interaction` refreshes selected resources. Boost is a consumer development tool, not a required runtime dependency of this package. Agent Skills folders can also be copied to the skill directory supported by another agent; copy the entire folder so references and any scripts remain available.

The package also exposes its native Laravel publish group:

```sh
php artisan vendor:publish --tag=nvl-payments-skills --no-interaction
```

That group copies the full skill tree to `.agents/skills`. Avoid force-overwriting application-authored changes; keep local conventions separate from vendor guidance.

## Publishable groups

- `nvl-payments-config`
- `nvl-payments-migrations`
- `nvl-payments-skills`
- `nvl-payments-translations`

## Configuration

```sh
php artisan vendor:publish --tag=nvl-payments-config --no-interaction
```

The consumer owns `config/nvl-payments.php`. Inspect the actual files registered by this package's config publish group; published sections differ between packages. Set deployment configuration before provider boot when it selects a model, registry or integration. Never store closures in cacheable configuration; bind behavior in an application provider. Rebuild configuration caches and restart long-lived workers after reviewed changes.

## Migrations

Choose exactly one migration owner before the first migration:

- **Vendor-loaded:** enable the package migration loader and run ordinary `php artisan migrate --no-interaction` after reviewing storage and bindings.
- **Published:** publish the tagged files, disable `nvl-payments.migrations.enabled` before migrating, then review and edit the application-owned copies before their first execution.

```sh
php artisan vendor:publish --tag=nvl-payments-migrations --no-interaction
```

Publish migrations once during initial setup. Laravel retimestamps published migrations; publishing again can create duplicate dated copies even without `--force`. Update config or skills with their separate tags, and avoid a blanket `--provider` publication after migration setup. Do not run both vendor and host migration copies. Keep applied history intact; evolve an installed schema with a new reviewed host migration. Preserve required keys, unique constraints, indexes, ownership declarations and transaction connection.

Configure `nvl-payments.connection` and supported table mappings before migrations, together with any host model declarations. Publication does not create tables, establish actor policy, admit an owner or prove native Ownership adoption.

Migration loading is an explicit package capability. Enable `nvl-payments.migrations.enabled` for the vendor-owned choice; leave it false for host-published migrations. Review [usage](usage.md) before enabling provider-backed financial operations.

## Artisan operations

These commands are registered by Composer-discovered package providers. Feature-gated commands require the corresponding enabled adapter/runtime. Read each installed command’s `--help` before supplying operation options:

- `php artisan nvl:payments:doctor --help`
- `php artisan nvl:payments:reconcile --help`

## Optional packages

Installing an optional library does not grant a policy decision or automatically enable an integration. The manifest suggests:

- `nvl/ownership`: Adds optional model-declared ownership, isolation, adoption and captured worker contexts.

## Verify the consumer

Use the package-specific checks in [usage](usage.md), verify required host bindings and run the affected consumer tests. Publishing files is not application or production certification. Preserve the documented explicit actor, revision, owner, locale and outer-commit requirements when adapting HTTP, CLI or jobs.

Official framework guidance: [Laravel package development](https://laravel.com/docs/13.x/packages) and [Boost third-party package skills](https://laravel.com/docs/13.x/boost#third-party-package-skills).
