# EDD Software Licensing SDK — Integration Guide

A drop-in integration guide for the official [Easy Digital Downloads Software Licensing SDK](https://github.com/awesomemotive/edd-sl-sdk). Built so AI agents (Claude, Gemini, Codex, Hermes, OpenClaw, …) and WordPress plugin developers can ship a working license activation + auto-updater **in minutes**, without inventing custom UI or hand-rolling API calls.

> **Why this guide exists**: the SDK is excellent but the official docs are scattered across three pages, the SDK is not on Packagist (so vanilla `composer require` fails), and the README only shows a happy-path snippet. This guide consolidates everything you need into one focused document — verified against a real production integration.

## What is the EDD-SL SDK?

A WordPress library that adds three things to your plugin or theme **automatically**:

1. A **"Manage License"** action link on the WordPress Plugins screen (or a "Theme License" menu item for themes), which opens a modal for the user to paste their license key.
2. **License activation/deactivation** against your EDD store (`activate_license`, `deactivate_license`, `check_license` endpoints) — no JSON API code on your side.
3. **Auto-update delivery** — when you bump the product version on your store, WordPress shows the update banner; one click installs the new ZIP.

You write ~10 lines of glue. The SDK does the rest.

## 60-Second Integration

### 1. `composer.json`

The SDK is **not on Packagist**. Add it as a VCS repository:

```json
{
    "repositories": {
        "edd-sl-sdk": {
            "type": "vcs",
            "url": "https://github.com/awesomemotive/edd-sl-sdk"
        }
    },
    "require": {
        "easy-digital-downloads/edd-sl-sdk": "^1.0.3"
    }
}
```

Then install:

```bash
composer install --no-dev --optimize-autoloader
```

### 2. Your plugin's main file

```php
<?php
/**
 * Plugin Name: Your Plugin
 * Version:     1.0.0
 */

defined( 'ABSPATH' ) || exit;

// Composer autoloader.
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// EDD-SL SDK bootstrap — separate file from the autoloader, easy to forget.
if ( file_exists( __DIR__ . '/vendor/easy-digital-downloads/edd-sl-sdk/edd-sl-sdk.php' ) ) {
    require_once __DIR__ . '/vendor/easy-digital-downloads/edd-sl-sdk/edd-sl-sdk.php';
}

add_action( 'edd_sl_sdk_registry', function ( $init ) {
    $init->register( array(
        'id'      => 'your-plugin-slug',                 // your plugin slug
        'url'     => 'https://store.example.com',        // your EDD store URL
        'item_id' => 123,                                // EDD download post ID
        'version' => '1.0.0',                            // current plugin version
        'file'    => __FILE__,                           // path to main plugin file
    ) );
} );
```

### 3. Release ZIP must include `vendor/`

Your distribution workflow has to run `composer install --no-dev` before zipping, and the resulting `vendor/` directory must end up inside the ZIP. Most CI setups need an explicit step. Example GitHub Actions snippet:

```yaml
- name: Install production dependencies
  run: composer install --no-dev --optimize-autoloader --no-interaction

- name: Build release ZIP
  run: |
    rsync -a --exclude='.git' --exclude='.github' --exclude='tests' \
              --exclude='node_modules' --exclude='composer.lock' \
              --exclude='*.zip' ./ "release/your-plugin/"
    cd release && zip -r "../your-plugin-${VERSION}.zip" "your-plugin"
```

**Do NOT** add `vendor/` to your `rsync --exclude` list. That ships a broken ZIP with no SDK.

That's the whole integration. There is no admin page to build, no AJAX handler to write, no settings field to register.

## Registry Arguments — Full Reference

```php
$init->register( array(
    // Required
    'id'              => 'your-plugin-slug',        // string — plugin slug
    'url'             => 'https://store.example',   // string — EDD store base URL
    'item_id'         => 123,                       // int — EDD download post ID
    'version'         => '1.0.0',                   // string — current version
    'file'            => __FILE__,                  // string — main plugin file (plugins only)

    // Optional
    'type'            => 'plugin',                  // 'plugin' (default) or 'theme'
    'weekly_check'    => true,                      // bool — background license verify (default true)
    'messenger_class' => MyMessenger::class,        // FQCN extending EasyDigitalDownloads\Updater\Messenger
) );
```

For themes, drop `file` and add `'type' => 'theme'`. The SDK injects a "Theme License" submenu under Appearance instead of a plugin row action.

## What NOT to Build (Common Mistakes)

If you are an AI agent reading this to implement EDD-SL: avoid the following anti-patterns.

| Don't | Why |
|---|---|
| Add a license-key text field to your plugin's own settings page | The SDK injects its own "Manage License" link + modal. A second input causes confusion and never syncs with the SDK's internal state. |
| Pass `'license' => …` in the `register()` array | The SDK manages license storage in its own option keys (`edd_sl_…`). Passing `license` here does nothing useful. |
| Call `edd_action=activate_license` from your plugin yourself | The SDK already does this from its modal. Calling it again can leave state out of sync, or hit `no_activations_left`. |
| Ship without `vendor/` in the release ZIP | The bootstrap file (`require_once`) silently no-ops via the `file_exists()` guard, and your users see no update banners, ever. Hardest bug to spot in this whole integration. |
| Add `vendor/` to your `.gitignore` AND forget the CI install step | Same as above — vendor is missing in the ZIP. Either commit `vendor/`, or make CI install it before zipping. Most teams pick CI install. |
| Use the older single-file `EDD_SL_Plugin_Updater.php` from `easydigitaldownloads/edd-sample-plugin` | The SDK is the current recommendation (per EDD's 2025 docs). The old single-file approach still works but you re-write activate/deactivate UI yourself. |
| Forget to bump the version when releasing | Both the plugin header (`Version:`) and any version constant should match the new tag. The SDK compares this against `new_version` from the store. |

## License Lifecycle (What the SDK Does for You)

Once installed, here is what happens on each user action:

| User action | SDK behaviour |
|---|---|
| Visits **Plugins** screen | Detects whether SDK has a stored license key for this product. Shows "Manage License" link. |
| Clicks "Manage License" | Opens modal with input field. |
| Pastes key + clicks **Activate** | POSTs to `{store_url}/?edd_action=activate_license&item_id=…&license=…&url=…`. On `license=valid`, stores key in `edd_sl_…` option and marks active. On any error, shows the localized error message. |
| Clicks **Deactivate** | POSTs to `deactivate_license`, drops the local active flag, optionally keeps the key for re-activation later. |
| Periodically (weekly by default) | POSTs to `check_license` to confirm the license hasn't expired/been revoked/etc. |
| WordPress polls for updates | SDK intercepts the `pre_set_site_transient_update_plugins` hook, calls `get_version` with the active license, injects `new_version` + `download_link` so the WP core update flow takes over. |

The SDK gracefully handles every license state your store can return: `valid`, `invalid`, `disabled`, `expired`, `key_mismatch`, `item_name_mismatch`, `site_inactive`, plus `no_activations_left`.

## Verify Without wp-admin (curl + wp-cli)

After deploying your plugin to a test site, you can verify each layer of the chain from CLI before opening a browser:

```bash
# 1. SDK class is loaded into WP runtime
wp eval 'echo class_exists("\\EasyDigitalDownloads\\Updater\\Registry") ? "loaded\n" : "missing\n";'

# 2. Store's get_version endpoint answers correctly
curl "https://store.example/?edd_action=get_version&item_id=123&license=YOUR_KEY&url=https://your-site.example" | jq .

# 3. Store's activate_license endpoint accepts your key
curl -X POST "https://store.example/?edd_action=activate_license&item_id=123&license=YOUR_KEY&url=https://your-site.example" | jq .
```

Expected `activate_license` success response:

```json
{
    "success": true,
    "license": "valid",
    "item_id": 123,
    "item_name": "Your Plugin",
    "expires": "lifetime",
    "site_count": 1,
    "activations_left": "unlimited"
}
```

If the API responds correctly but the SDK still doesn't activate from wp-admin, the problem is **almost always** one of: SDK bootstrap not loaded (forgot the `require_once`), `vendor/` missing from ZIP, or product `_edd_sl_enabled` not set to `1` on the store. Check those three first.

## Customizing Translations

The SDK uses its own text domain (`edd-sl-sdk`) by default. To use your own:

### Option A — Custom messenger class

```php
use EasyDigitalDownloads\Updater\Messenger;

class MyPluginMessenger extends Messenger {
    protected $text_domain = 'my-plugin';

    public function get_activate_button_label() {
        return $this->filter_string( __( 'Activate License', $this->text_domain ), 'activate_button' );
    }
}

add_action( 'edd_sl_sdk_registry', function ( $registry ) {
    $registry->register( array(
        'id'              => 'my-plugin',
        'url'             => 'https://store.example',
        'item_id'         => 123,
        'version'         => '1.0.0',
        'file'            => __FILE__,
        'messenger_class' => MyPluginMessenger::class,
    ) );
} );
```

All overrideable methods are listed in [the SDK README](https://github.com/awesomemotive/edd-sl-sdk#available-messenger-methods).

### Option B — Filter hook

```php
add_filter( 'edd_sl_sdk_translate_string', function ( $string, $key, $text_domain ) {
    if ( 'my-text-domain' === $text_domain && 'activate_button' === $key ) {
        return __( 'Enable License', 'my-plugin' );
    }
    return $string;
}, 10, 3 );
```

## Common Pitfalls

| Symptom | Root cause | Fix |
|---|---|---|
| `composer install` says "Could not find a matching version of easy-digital-downloads/edd-sl-sdk" | Forgot the VCS repository block in `composer.json` | Add the `repositories` block shown above. |
| Plugin activates but no "Manage License" link appears | SDK bootstrap file not required (only autoloader is) | Add `require_once __DIR__ . '/vendor/easy-digital-downloads/edd-sl-sdk/edd-sl-sdk.php';` |
| Class `\EasyDigitalDownloads\Updater\Registry` not found at runtime | `vendor/` missing from the released ZIP | Run `composer install --no-dev` in CI **before** zipping, and don't exclude `vendor/` from the rsync. |
| User clicks Activate, sees no error, but status stays "Inactive" | EDD-SL is not enabled for that product on the store | Set postmeta `_edd_sl_enabled = 1` on the download post, OR check the "Enable License Keys" box in the EDD admin. |
| Update banner never appears even after store version bump | License not activated *yet* on this site (SDK doesn't bother checking versions for unlicensed installs in some configurations), OR plugin's installed version >= store's `new_version` | Activate license first, then `wp transient delete update_plugins` and re-check. |
| Activation works once, then "no_activations_left" forever | License limit reached; previous deactivations weren't received | Reset site activations from your EDD admin (License Manager → reset). |

## Pro Tips (when the basics aren't enough)

If your distribution setup is unusual, see [`PRO_TIPS.md`](PRO_TIPS.md):

- **Auto-localizing release ZIPs from a private GitHub repo** — when your source repo is private and EDD-SL can't follow the GitHub release URL without auth (the customer sees "download failed: Not Found"). Full automation via signed CI webhook + mu-plugin so `git push --tags` remains the entire release motion.

## Where This Fits in the EDD Ecosystem

| Repo / Doc | What it is | When you need it |
|---|---|---|
| [awesomemotive/edd-sl-sdk](https://github.com/awesomemotive/edd-sl-sdk) | The SDK source itself | Always — `composer require` it. |
| [EDD docs: Software Licensing API](https://easydigitaldownloads.com/docs/software-licensing-api/) | Wire-level documentation of all `edd_action` endpoints (`activate_license`, etc.) | Debugging, building non-WP clients, or writing your own license-check script outside the SDK. |
| [EDD docs: SL Updater Implementation](https://easydigitaldownloads.com/docs/software-licensing-updater-implementation-for-wordpress-plugins/) | Official SDK integration guide (one page, light on details) | Reference, but this guide is more complete. |
| [easydigitaldownloads/edd-sample-plugin](https://github.com/easydigitaldownloads/edd-sample-plugin) | The OLD single-file `EDD_SL_Plugin_Updater` approach | Legacy projects only — for new work, use the SDK instead. |
| [EDD Software Licensing add-on product page](https://easydigitaldownloads.com/downloads/software-licensing/) | What store owners need to buy to issue license keys at all | Store-side setup, before any plugin can integrate. |

## File Tree of a Working Integration

```
your-plugin/
├── your-plugin.php              # plugin header + bootstrap (see above)
├── composer.json                # require + VCS repo block (see above)
├── composer.lock                # commit this — CI uses it for reproducible builds
├── vendor/                      # gitignored locally, generated in CI, included in release ZIP
│   └── easy-digital-downloads/
│       └── edd-sl-sdk/
│           ├── edd-sl-sdk.php   # the file you require_once
│           └── src/             # autoloaded via Composer PSR-4
└── .github/workflows/release.yml  # `composer install --no-dev` step before zipping
```

## Verifying This Guide

This guide was validated against a real production integration shipped on 2026-05-24 — a private WordPress plugin (FeedbackPilot) using the SDK to deliver updates from a self-hosted EDD store. The exact code patterns above were tested end-to-end:

- `composer install --no-dev` resolves to SDK v1.0.3 (latest at time of writing).
- SDK class `\EasyDigitalDownloads\Updater\Registry` is callable after `require_once`.
- Live `activate_license` curl returned `{license: "valid", success: true}` and flipped the store-side license status from `inactive` to `active`.
- `get_version` with an active license returns a populated `download_link` that the SDK can resolve into a one-click update.

If you find a step that doesn't work for you, please open an issue on this repo with the exact symptom and the curl/wp-cli output you got — the goal is to keep this guide ground-truthed against reality.

## License

GPL-2.0-or-later — same as WordPress core and EDD itself. See [`LICENSE`](LICENSE).
