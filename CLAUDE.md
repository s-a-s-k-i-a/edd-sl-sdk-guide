# Instructions for AI Agents (Claude, Gemini, Codex, Hermes, OpenClaw, …)

You have been pointed at this repository because someone wants you to integrate the **Easy Digital Downloads Software Licensing SDK** into a WordPress plugin or theme. Read this file first, then the [README](README.md).

> **Companion file**: [AGENTS.md](AGENTS.md) is identical to this file under a different filename, kept in sync so agents that look for either convention land on the same instructions.

## Your task, in one sentence

Ship a WordPress plugin (or theme) that uses the official [`easy-digital-downloads/edd-sl-sdk`](https://github.com/awesomemotive/edd-sl-sdk) Composer package so that end users can activate a license and receive automatic updates from an EDD store — without you writing custom UI, custom AJAX, or custom API code.

## The mental model

The SDK is **complete**. It already contains:

- The license-key input modal in the Plugins screen.
- The activate/deactivate buttons and their AJAX wiring.
- The license-state storage (its own option keys, namespaced `edd_sl_*`).
- The WordPress `pre_set_site_transient_update_plugins` integration that drives the standard core update banner.
- The translation layer and the customization hooks.

Your job is to **register the plugin with the SDK** (one `add_action` call with 5 fields) and to **make sure the SDK ships in your release ZIP**. That's it.

If you find yourself writing license-key text inputs, AJAX handlers for `wp_ajax_my_plugin_activate_license`, or POST helpers that hit `edd_action=activate_license` — **stop**. The SDK already did it. You are duplicating work and creating two sources of truth that will diverge.

## Implementation order

Follow these steps in order. Do not skip ahead.

### 1. Verify the precondition

Confirm the store has Software Licensing enabled for the product:

- On the EDD store, the product's `_edd_sl_enabled` postmeta must be `1`.
- The product must have a `_edd_sl_version` set.
- The product's "Download Files" must point at a working ZIP URL (e.g. a GitHub Releases asset).

If you don't have access to the store, ask the user to confirm. Don't proceed assuming.

### 2. Add the SDK to `composer.json`

The SDK is **not on Packagist**. You must register it as a VCS repository:

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

Then run `composer install --no-dev --optimize-autoloader`. Commit `composer.lock` so CI is reproducible.

### 3. Bootstrap the SDK from the main plugin file

```php
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// SDK bootstrap is a separate file from the autoloader — easy to forget.
if ( file_exists( __DIR__ . '/vendor/easy-digital-downloads/edd-sl-sdk/edd-sl-sdk.php' ) ) {
    require_once __DIR__ . '/vendor/easy-digital-downloads/edd-sl-sdk/edd-sl-sdk.php';
}
```

### 4. Register the plugin with the SDK

```php
add_action( 'edd_sl_sdk_registry', function ( $init ) {
    $init->register( array(
        'id'      => 'your-plugin-slug',
        'url'     => 'https://store.example',
        'item_id' => 123,
        'version' => '1.0.0',
        'file'    => __FILE__,
    ) );
} );
```

For themes: drop `file`, add `'type' => 'theme'`.

### 5. Make sure the release ZIP includes `vendor/`

Your CI must run `composer install --no-dev` **before** zipping, and `vendor/` must end up inside the ZIP. If your release pipeline excludes `vendor/`, the SDK silently won't load and no user will ever see a license modal or an update banner. This is the most common shipping bug.

### 6. Verify before you declare done

Run these three checks. Do not skip them.

```bash
# (a) SDK class is reachable at runtime
wp eval 'echo class_exists("\\EasyDigitalDownloads\\Updater\\Registry") ? "loaded\n" : "missing\n";'

# (b) Store endpoint answers with the expected shape
curl "https://store.example/?edd_action=get_version&item_id=123&license=KEY&url=https://target-site.example"

# (c) Store accepts license activation
curl -X POST "https://store.example/?edd_action=activate_license&item_id=123&license=KEY&url=https://target-site.example"
```

If all three pass, the SDK is functional. The remaining "Manage License" UI is standard EDD-SL UI and works by virtue of (a) being true.

## Hard rules — do not violate

1. **Never commit license keys, API tokens, or store credentials to any file.** Use placeholders in code examples (`KEY`, `123`, `store.example`) and ask the user to inject real values via environment variables, options, or hand-paste them at runtime.
2. **Never call `activate_license` / `deactivate_license` / `check_license` from your plugin's own code** unless you are explicitly building a *non-SDK* tool. The SDK does this.
3. **Never add a license-key text field to your plugin's settings page.** The SDK injects its own modal in the Plugins screen.
4. **Never pass `'license' => …` in `$registry->register()`.** It is ignored. The SDK stores keys in its own option keys.
5. **Never strip `vendor/` from the release ZIP.** Strip `composer.lock`, `tests/`, `.github/`, but keep `vendor/`.
6. **Never use the old `EDD_SL_Plugin_Updater.php` single-file approach for new work.** EDD officially recommends the SDK in their 2025 docs. Use the SDK.
7. **Never assume the user's store URL or item ID.** Ask. The item ID is a post ID on the store (the EDD download post type); it is not derivable from the slug.

## Distribution from a private GitHub repo

If the source repo of the plugin you are integrating is **private** and the EDD store points its `edd_download_files[].file` at the GitHub release asset URL, customers will see a `"download failed: Not Found"` on every update click — EDD-SL's `package_download` proxy hits GitHub server-side without auth and gets 404. [`PRO_TIPS.md`](PRO_TIPS.md) §1 has a full recipe to fix this with a signed CI webhook + a small mu-plugin on the store side. Implement it whenever the source repo is private. Use the recipe verbatim — including the HMAC-SHA256 signature pattern and the `update_post_meta()` step the EDD Git Updater plugin omits.

## When the SDK isn't enough

The SDK covers the standard licensing + auto-update workflow. If your product has unusual requirements, you may need to fall back to the [Software Licensing API](https://easydigitaldownloads.com/docs/software-licensing-api/) directly:

- License-status checks from a non-WordPress client (e.g., a desktop app).
- License provisioning from a server-side workflow (e.g., issuing keys via a webhook).
- Custom UI flows that don't fit the SDK's plugins-screen modal pattern.

For those cases, the JSON API endpoints (`activate_license`, `deactivate_license`, `check_license`, `get_version`) are stable and documented in the link above. The SDK is built on top of them.

## Reporting back to the user

When you finish, give the user a short verification summary:

- Plugin version + SDK version installed.
- Result of the three CLI verification checks above.
- The exact URL of the "Manage License" link the user should click in wp-admin.
- The license key format they should expect (32-char hex usually, e.g. `xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx`).

Don't claim success until those checks pass. If something is unclear, ask.
