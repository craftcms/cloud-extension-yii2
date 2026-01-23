# Craft Cloud Monorepo

This monorepo contains packages for running Craft CMS on [Craft Cloud](https://craftcms.com/cloud).

## Packages

### [`craftcms/cloud`](packages/cloud)

Craft CMS plugin providing user-facing Cloud features: filesystem types, template helpers, image transforms, and static caching.

```bash
composer require craftcms/cloud
```

### [`craftcms/cloud-ops`](packages/cloud-ops)

Standalone Yii2 extension + Composer plugin for Cloud infrastructure. Configures cache, queue, session, and other runtime components. Automatically installed as a dependency of `craftcms/cloud`.

## Composer Plugin

`cloud-ops` includes a Composer plugin that reorders `vendor/composer/autoload_files.php` after install/update. This ensures the `cloud-ops` autoload file (which defines `craft_modify_app_config()`) is loaded before any other package that might define the same function—including older versions of `craftcms/cloud`.

This allows projects to safely migrate from the legacy single-package setup to the new split architecture without bootstrap conflicts.

## Release Plan

1. **`craftcms/cloud-ops@1`** — Release first to override bootstrap from legacy `craftcms/cloud` installations.
2. **`craftcms/cloud@3`** — Craft plugin requiring `cloud-ops`. Compatible with Craft 4 and 5.
3. **`craftcms/cloud@4`** — Future release for Craft 6 (Laravel-based).
