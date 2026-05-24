# Minimal EDD-SL SDK Plugin Example

The smallest possible WordPress plugin that integrates the EDD Software Licensing SDK.

## Files

- [`my-plugin.php`](my-plugin.php) — main plugin file with header, SDK bootstrap, and `register()` call.
- [`composer.json`](composer.json) — declares the SDK as a VCS dependency.

## Build

From inside this directory:

```bash
composer install --no-dev --optimize-autoloader
```

That populates `vendor/` with the SDK. Drop the whole folder into `wp-content/plugins/` on a WordPress site, activate from wp-admin, and the **Manage License** link will appear in the Plugins screen.

## Customize

Edit the three constants at the top of [`my-plugin.php`](my-plugin.php):

```php
define( 'MY_PLUGIN_STORE_URL', 'https://store.example' );  // your EDD store
define( 'MY_PLUGIN_ITEM_ID', 123 );                         // your download post ID
define( 'MY_PLUGIN_VERSION', '1.0.0' );                     // keep in sync with header
```

That's it. There is no settings page to write, no license-key field to render, no AJAX handler to register. The SDK handles all of it.
