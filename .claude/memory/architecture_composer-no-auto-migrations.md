---
name: architecture_composer-no-auto-migrations
description: composer update intentionally does NOT run DB migrations — composer is build-time/file-level only; migrations are an explicit deploy-time step
type: reference
---

# Why `composer update` does not run DB migrations

By design (inherited from OXID eShop 6):

- `post-update-cmd` in both the component `composer.json` (shop-ce) and the
  project `composer.json` (o3-shop distribution) only runs
  `ShopVersionGenerator::generate`, the Incenteev parameter handler, and the
  IDE helper. No migration entry exists.
- `o3-shop/shop-composer-plugin` (`Plugin::updatePackages`) only copies
  package files (shop source, modules, themes) into `source/`. It never
  touches the database. It even guards shop bootstrap with
  `isShopLaunched()` because composer commonly runs when no configured DB
  exists yet (`composer create-project`, CI artifact builds).
- Migrations are an explicit separate step:
  `vendor/bin/oe-eshop-db_migrate migrations:migrate` (from
  `o3-shop/shop-doctrine-migration-wrapper`). The Setup wizard calls it via
  `Setup\Utilities::executeExternalDatabaseMigrationCommand()`.

Rationale: composer = build time (may have no DB access, build-once /
deploy-many artifacts), migrations = deploy time per environment; and
implicit schema changes on a production DB during `composer update` would be
unsafe. Documented update procedure is: `composer update` →
`vendor/bin/oe-eshop-db_migrate migrations:migrate` →
`vendor/bin/oe-eshop-db_views_regenerate`.
