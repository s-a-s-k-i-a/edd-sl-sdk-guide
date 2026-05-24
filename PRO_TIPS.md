# Pro Tips — Beyond the Default Setup

This file covers patterns the [main README](README.md) intentionally keeps out so the 60-second integration stays focused. Each tip is a self-contained recipe, written so an AI agent can implement it cold.

## Table of contents

1. [Auto-localizing release ZIPs from a private GitHub repo](#1-auto-localizing-release-zips-from-a-private-github-repo)

---

## 1. Auto-localizing release ZIPs from a private GitHub repo

### When you need this

You are selling a WordPress plugin via Easy Digital Downloads on `your-store.example`. The plugin's source lives in a **private** GitHub repo (`vendor/plugin`). Your release pipeline builds a ZIP, attaches it to a GitHub Release, and you point the EDD download file URL at that GitHub release asset.

**The trap**: when a customer's WordPress install clicks "Update" on the plugin, EDD-SL's `package_download` endpoint **server-side fetches** the configured file URL without GitHub authentication. GitHub returns `HTTP 404` for unauthenticated requests to private-repo release assets (security feature — does not even leak that the repo exists).

Symptom in the customer's wp-admin:

> `Beim Aktualisieren von Your Plugin ist ein Fehler aufgetreten: Der Download ist fehlgeschlagen. Not Found`

### Three solutions, ranked

| Option | Effort | Trade-off |
|---|---|---|
| **A. Make the repo public** | 1 CLI command | Source becomes world-readable. Plugin is GPL anyway, but the move is one-way (caches, indexers). |
| **B. Localize the ZIP onto your store** | Webhook + tiny mu-plugin | Repo stays private. Full automation. **This recipe.** |
| **C. Proxy GitHub auth in EDD-SL** | Custom filter on store | Repo stays private. More custom code on the store side. |

Option B is what most people actually want — keep the repo private, but let customers get updates with zero manual intervention per release.

### The B architecture

```
                    git push --tags vX.Y.Z
                                ↓
                  GitHub Actions release workflow
                    ├─ build ZIP (include vendor/)
                    ├─ create GitHub Release + attach ZIP
                    └─ POST signed webhook to your-store.example
                                ↓
                  Mu-plugin on your-store.example
                    ├─ verify HMAC-SHA256 signature
                    ├─ resolve repo → EDD product ID
                    ├─ ask EDD Git Download Updater plugin to
                    │  download the asset (via its stored OAuth
                    │  token, which CAN read the private repo)
                    ├─ store ZIP under wp-content/uploads/edd/
                    └─ rewrite edd_download_files[].file +
                       _edd_sl_version postmeta
                                ↓
                  Customer's wp-admin Update click
                    ├─ EDD-SL get_version → new download_link
                    ├─ EDD-SL package_download → 302 → local ZIP
                    └─ WP installs the update normally
```

After this is set up, **`git push --tags` is the entire release motion**. No login, no admin click.

### What you need in place first

- The **EDD Software Licensing add-on** active on your store (this is the paid EDD extension).
- The **EDD Git Download Updater plugin** active on the same store, with its GitHub OAuth flow already authorized (Downloads → Settings → Extensions → Git Updater → Authorize with GitHub).
- The product post on the store has `_edd_download_use_git = 1`, an entry in `edd_download_files`, and `_edd_sl_enabled = 1`.

### Step 1 — Generate a shared secret (do NOT commit it anywhere)

On your local machine:

```bash
# Generate 32 bytes of hex into a strict-permission tmpfile.
SECRET_FILE=$(mktemp) && chmod 600 "$SECRET_FILE"
openssl rand -hex 32 > "$SECRET_FILE"

# Store it on the store (via wp-cli stdin, never argv):
ssh you@your-store.example \
  "wp option update fbp_webhook_secret -" < "$SECRET_FILE"

# Store it as a GitHub repo secret (via gh stdin):
gh secret set WEBHOOK_SECRET --repo vendor/plugin < "$SECRET_FILE"

# Wipe the local copy.
shred -u "$SECRET_FILE" 2>/dev/null || rm -P "$SECRET_FILE"
```

Now both ends hold the same secret. The plaintext exists nowhere on disk.

### Step 2 — Drop the mu-plugin on the store

Save the following as `wp-content/mu-plugins/your-release-webhook.php` on the store. Mu-plugins load automatically and can't be deactivated by accident.

```php
<?php
/**
 * Plugin Name: Release Webhook
 * Description: Receives GitHub Actions release notifications and triggers the
 *              EDD Git Download Updater to localize the new release ZIP.
 * License:     GPL-2.0-or-later
 */
defined( 'ABSPATH' ) || exit;

// Map each repo to its EDD download post ID. Extend as you add products.
if ( ! defined( 'YR_WEBHOOK_REPO_MAP' ) ) {
    define( 'YR_WEBHOOK_REPO_MAP', wp_json_encode( array(
        'vendor/plugin' => 123,
    ) ) );
}

add_action( 'rest_api_init', static function () {
    register_rest_route( 'your-webhooks/v1', '/release', array(
        'methods'             => 'POST',
        'callback'            => 'yr_webhook_handle',
        'permission_callback' => 'yr_webhook_verify_signature',
    ) );

    register_rest_route( 'your-webhooks/v1', '/ping', array(
        'methods'             => 'GET',
        'callback'            => static function () {
            return rest_ensure_response( array(
                'ok'                => true,
                'updater_active'    => class_exists( 'EDD_GIT_Download_Updater' ),
                'secret_configured' => '' !== (string) get_option( 'fbp_webhook_secret', '' ),
            ) );
        },
        'permission_callback' => '__return_true',
    ) );
} );

function yr_webhook_verify_signature( WP_REST_Request $req ) {
    $secret = (string) get_option( 'fbp_webhook_secret', '' );
    if ( '' === $secret ) {
        return new WP_Error( 'no_secret', 'Webhook secret not configured.', array( 'status' => 503 ) );
    }
    $sig = (string) $req->get_header( 'X-Yr-Signature' );
    if ( 0 !== strpos( $sig, 'sha256=' ) ) {
        return new WP_Error( 'bad_sig', 'Missing or malformed signature header.', array( 'status' => 401 ) );
    }
    $expected = 'sha256=' . hash_hmac( 'sha256', (string) $req->get_body(), $secret );
    if ( ! hash_equals( $expected, $sig ) ) {
        return new WP_Error( 'bad_sig', 'Signature mismatch.', array( 'status' => 401 ) );
    }
    return true;
}

function yr_webhook_handle( WP_REST_Request $req ) {
    $body = json_decode( $req->get_body(), true );
    if ( ! is_array( $body ) ) {
        return new WP_Error( 'bad_payload', 'Body is not JSON.', array( 'status' => 400 ) );
    }
    $repo = isset( $body['repo'] ) ? (string) $body['repo'] : '';
    $tag  = isset( $body['tag'] ) ? (string) $body['tag'] : '';
    if ( '' === $repo || '' === $tag ) {
        return new WP_Error( 'missing', 'repo and tag are required.', array( 'status' => 400 ) );
    }

    $map = (array) json_decode( YR_WEBHOOK_REPO_MAP, true );
    if ( ! isset( $map[ $repo ] ) ) {
        return new WP_Error( 'unknown_repo', sprintf( 'Repo %s not mapped.', $repo ), array( 'status' => 404 ) );
    }
    if ( ! class_exists( 'EDD_GIT_Download_Updater' ) ) {
        return new WP_Error( 'no_updater', 'EDD Git Download Updater is not active.', array( 'status' => 500 ) );
    }

    list( $owner, $name ) = explode( '/', $repo, 2 );
    $post_id  = (int) $map[ $repo ];
    $repo_url = 'https://github.com/' . $repo;
    $updater  = EDD_GIT_Download_Updater::instance();

    try {
        $provider = edd_git_download_updater()->providerRegistry->getProvider( 'github' );

        // Locate the .zip asset attached to this release.
        $assets = $provider->getAssetsFromRepoTag( $repo, $tag );
        $asset  = null;
        foreach ( (array) $assets as $a ) {
            if ( '.zip' === strtolower( substr( (string) ( $a['name'] ?? '' ), -4 ) ) ) {
                $asset = $a;
                break;
            }
        }
        if ( null === $asset ) {
            return new WP_Error( 'no_zip', 'No .zip asset on this release.', array( 'status' => 404 ) );
        }

        // Drive the exact same code path as the "Fetch Now" admin button.
        $updater->process_file->url = $asset['url'];
        $new_zip = $updater->process_file->process(
            $post_id, $tag, $repo_url, 0, '', $asset['name'], $owner, $name, $provider
        );

        // process() builds the new file array but does NOT persist it — that
        // step is normally done by the admin-side JavaScript. Do it here.
        $sl_version = ltrim( $tag, 'v' );
        $files      = (array) get_post_meta( $post_id, 'edd_download_files', true );
        $base       = isset( $files[0] ) && is_array( $files[0] ) ? $files[0] : array();
        $files[0]   = array_merge( $base, array(
            'index'           => 0,
            'attachment_id'   => 0,
            'thumbnail_size'  => '',
            'name'            => isset( $base['name'] ) ? $base['name'] : ( $name . ' v' . $sl_version ),
            'file'            => $new_zip['url'],
            'condition'       => 'all',
            'git_version'     => $tag,
            'git_url'         => $repo_url,
            'git_file_asset'  => $asset['url'],
            'git_folder_name' => isset( $base['git_folder_name'] ) ? $base['git_folder_name'] : '',
        ) );
        update_post_meta( $post_id, 'edd_download_files', $files );
        update_post_meta( $post_id, '_edd_sl_version', $sl_version );

        return rest_ensure_response( array(
            'ok'         => true,
            'product_id' => $post_id,
            'sl_version' => $sl_version,
            'file'       => $new_zip['url'] ?? null,
        ) );
    } catch ( \Throwable $e ) {
        return new WP_Error( 'fetch_failed', $e->getMessage(), array( 'status' => 500 ) );
    }
}
```

Health-check it from CLI:

```bash
curl -s https://your-store.example/wp-json/your-webhooks/v1/ping
# expect: {"ok":true,"updater_active":true,"secret_configured":true}
```

### Step 3 — Add the CI step

In your plugin repo's `.github/workflows/release.yml`, after the step that creates the GitHub Release:

```yaml
- name: Notify store to localize the new release ZIP
  env:
    WEBHOOK_SECRET: ${{ secrets.WEBHOOK_SECRET }}
  run: |
    PAYLOAD=$(printf '{"repo":"%s","tag":"%s"}' "$GITHUB_REPOSITORY" "$GITHUB_REF_NAME")
    SIGNATURE="sha256=$(printf '%s' "$PAYLOAD" | openssl dgst -sha256 -hmac "$WEBHOOK_SECRET" -hex | awk '{print $NF}')"
    HTTP_CODE=$(curl -sS -o /tmp/r.json -w '%{http_code}' \
      -X POST \
      -H 'Content-Type: application/json' \
      -H "X-Yr-Signature: $SIGNATURE" \
      -d "$PAYLOAD" \
      'https://your-store.example/wp-json/your-webhooks/v1/release')
    echo "Webhook HTTP $HTTP_CODE"
    cat /tmp/r.json
    test "$HTTP_CODE" -ge 200 && test "$HTTP_CODE" -lt 300
```

The trailing `test` makes the workflow fail (visible red CI run) if the webhook returns non-2xx. Silent breakage is the worst failure mode — a hard fail is better.

### Step 4 — Verify end-to-end

Push a real tag (or a `vX.Y.Z-test` prerelease tag) and watch:

```bash
# In the plugin repo
git tag v1.0.0 && git push --tags

# Watch the run
gh run watch --repo vendor/plugin

# Confirm the store picked it up
curl -s "https://your-store.example/?edd_action=get_version&item_id=123&license=KEY&url=https://target-site.example" | jq .
# expect new_version: "1.0.0", download_link: "https://your-store.example/edd-sl/package_download/..."

# Confirm the wrapped link actually serves the localized ZIP
curl -sL -o /tmp/test.zip "<download_link>"
unzip -l /tmp/test.zip | head
```

### Why HMAC-SHA256 and not a simple bearer token?

A bearer token works, but HMAC-SHA256 over the body has two advantages:

1. **Replay-of-truncated-bodies is harder** — a stolen header + new body fails signature verification.
2. **The pattern is identical to what GitHub's own webhooks use** (`X-Hub-Signature-256`). Any reader of this code recognizes it immediately, including AI agents trained on GitHub webhook patterns.

The cost is one `openssl dgst` invocation per release. Worth it.

### Hard rules — do not violate

1. **Never commit the secret to either repo.** Generate it locally, pipe it via stdin to `wp option update` and `gh secret set`, then wipe the local copy.
2. **Never log the secret.** Don't `echo "$SECRET"` for debugging. The signed payload + HTTP code are enough to verify.
3. **Always require a 2xx response** in the CI step (with the `test` trailer shown above). A 500 means the localization failed; you want the CI run to go red so you notice.
4. **Don't reuse the secret across products / projects.** One secret per webhook receiver. If a customer ever needs their own copy of this pattern for their own private repo, they generate their own secret pair.
5. **The mu-plugin must not echo error messages that contain raw OAuth tokens, file paths outside `wp-content/`, or environment data.** Stick to `WP_Error` with sanitized messages.

### Troubleshooting

| Symptom | Diagnosis |
|---|---|
| Webhook returns `401 signature_invalid` | Secrets don't match. Re-derive both via the Step 1 procedure. |
| Webhook returns `404 unknown_repo` | The repo isn't in `YR_WEBHOOK_REPO_MAP`. Add it and re-deploy the mu-plugin. |
| Webhook returns `404 no_zip` | The GitHub Release for this tag has no `.zip` asset. Check your CI's zipping/attach step. |
| Webhook returns `500 fetch_failed` | Either the EDD Git Updater OAuth token expired, or the repo isn't accessible to the authorized GitHub account. Re-authorize via Downloads → Settings → Extensions → Git Updater → Reconnect. |
| Webhook returns `200` but customers still get 404 on update | The `edd_download_files[0].file` didn't actually get updated. Inspect with `wp post meta get <id> edd_download_files`. If the file URL still points at GitHub, the mu-plugin's persistence block didn't run — check the mu-plugin file actually has the `update_post_meta` calls (the EDD plugin's own `process_file::update_files()` builds the array but does NOT persist; that's why our mu-plugin has to do it). |

### Verifying this recipe

This pattern was deployed in production on 2026-05-24 for a private WordPress plugin distributed via a self-hosted EDD store. The full chain — `git push --tags` → GitHub Release → webhook → mu-plugin → `process()` → `update_post_meta()` → EDD-SL `get_version` → wrapped `package_download` → customer's WP installer — completes in ~20 seconds and serves a working ZIP to end users on the next update-check cycle.

If you implement this recipe and it doesn't work end-to-end, please open an issue with the exact wp-cli / curl output you got at the failing step — the goal is to keep this recipe ground-truthed against reality.
