<?php
/**
 * Plugin Name: My Licensed Plugin (example)
 * Plugin URI:  https://store.example/downloads/my-plugin/
 * Description: Minimal demonstration of integrating the EDD Software Licensing SDK.
 * Version:     1.0.0
 * Requires PHP: 7.4
 * Requires at least: 6.5
 * Author:      You
 * License:     GPL-2.0-or-later
 * Text Domain: my-plugin
 *
 * @package MyPlugin
 */

defined( 'ABSPATH' ) || exit;

/*
 * Replace these three constants with your store's real values.
 * - MY_PLUGIN_STORE_URL: the EDD-powered store the plugin is sold from.
 * - MY_PLUGIN_ITEM_ID:   the EDD download post ID of this product.
 * - MY_PLUGIN_VERSION:   keep in sync with the `Version:` header above.
 */
define( 'MY_PLUGIN_STORE_URL', 'https://store.example' );
define( 'MY_PLUGIN_ITEM_ID', 123 );
define( 'MY_PLUGIN_VERSION', '1.0.0' );

// 1. Composer autoloader.
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// 2. EDD-SL SDK bootstrap. Separate file from the autoloader, easy to forget.
if ( file_exists( __DIR__ . '/vendor/easy-digital-downloads/edd-sl-sdk/edd-sl-sdk.php' ) ) {
    require_once __DIR__ . '/vendor/easy-digital-downloads/edd-sl-sdk/edd-sl-sdk.php';
}

// 3. Register with the SDK. That's it — no admin page, no AJAX, no API helper.
add_action( 'edd_sl_sdk_registry', function ( $init ) {
    $init->register( array(
        'id'      => 'my-plugin',
        'url'     => MY_PLUGIN_STORE_URL,
        'item_id' => MY_PLUGIN_ITEM_ID,
        'version' => MY_PLUGIN_VERSION,
        'file'    => __FILE__,
    ) );
} );

// Your plugin's actual functionality goes here, untouched by licensing concerns.
