<?php
/**
 * Security regression: cloaked-link redirect, product URL context, settings and affiliate base (audit WCCAL-01 to WCCAL-08).
 *
 * Run from the site root:
 *   wp eval-file wp-content/plugins/woocommerce-cloak-affiliate-links/tests/security/redirect-and-settings.php 2>/dev/null | grep RESULT
 *
 * Expect every line to end in "ok". The HTTP checks request this site's own cloaked links (in the Claude sandbox,
 * allow the site's domain). They need a published and a trashed external product with a product URL; the
 * published product's click count is restored afterwards. Options changed by the test are restored too.
 */

defined( 'ABSPATH' ) || exit;

function wccal_sec_result( $label, $pass ) { echo 'RESULT ' . $label . ' ' . ( $pass ? 'ok' : 'FAIL' ) . "\n"; }

global $wpdb;

$wccal = new Wccal();

$published_id = (int) $wpdb->get_var( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_product_url' AND m.meta_value LIKE 'http%' WHERE p.post_type = 'product' AND p.post_status = 'publish' AND p.post_password = '' LIMIT 1" );
$trashed_id   = (int) $wpdb->get_var( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_product_url' AND m.meta_value <> '' WHERE p.post_type = 'product' AND p.post_status = 'trash' LIMIT 1" );
$page_id      = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' LIMIT 1" );
wccal_sec_result( "fixtures found (published $published_id, trashed $trashed_id, page $page_id)", $published_id && $trashed_id && $page_id );

// WCCAL-01: only published, non-password-protected products redirect for visitors.
wp_set_current_user( 0 );
$fake = function ( $status, $password = '' ) {
	return new WP_Post( (object) array( 'ID' => 999999999, 'post_type' => 'product', 'post_status' => $status, 'post_password' => $password, 'filter' => 'raw' ) );
};
wccal_sec_result( 'published product redirects', $wccal->is_redirect_allowed( $published_id ) );
wccal_sec_result( 'trashed product does not redirect', ! $wccal->is_redirect_allowed( $trashed_id ) );
wccal_sec_result( 'missing post does not redirect', ! $wccal->is_redirect_allowed( 999999999 ) );
wccal_sec_result( 'page does not redirect', ! $wccal->is_redirect_allowed( $page_id ) );
foreach ( array( 'draft', 'pending', 'future', 'private' ) as $status ) {
	wccal_sec_result( "$status product does not redirect", ! $wccal->is_redirect_allowed( $fake( $status ) ) );
}
wccal_sec_result( 'password-protected product does not redirect', ! $wccal->is_redirect_allowed( $fake( 'publish', 'secret' ) ) );
add_filter( 'wccal_is_redirect_allowed', '__return_true' );
wccal_sec_result( 'wccal_is_redirect_allowed filter can allow', $wccal->is_redirect_allowed( $trashed_id ) );
remove_filter( 'wccal_is_redirect_allowed', '__return_true' );
wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID );
wccal_sec_result( 'admin can follow a trashed product link', $wccal->is_redirect_allowed( $trashed_id ) );
wp_set_current_user( 0 );

// WCCAL-01 end to end, logged out.
$count = get_post_meta( $published_id, '_wccal_clickthrough_count', true );
$get   = function ( $id ) {
	return wp_remote_get( home_url( '/' . Wccal::get_affiliate_base() . '/' . $id . '/' ), array( 'redirection' => 0, 'timeout' => 20 ) );
};
$r = $get( $trashed_id );
wccal_sec_result( 'HTTP: trashed product link is a 404 (' . wp_remote_retrieve_response_code( $r ) . ')', 404 === wp_remote_retrieve_response_code( $r ) && '' === wp_remote_retrieve_header( $r, 'location' ) );
$r = $get( $published_id );
wccal_sec_result( 'HTTP: published product link redirects off-site (' . wp_remote_retrieve_response_code( $r ) . ')', in_array( wp_remote_retrieve_response_code( $r ), array( 301, 302, 307 ), true ) && 0 === strpos( wp_remote_retrieve_header( $r, 'location' ), 'http' ) && false === strpos( wp_remote_retrieve_header( $r, 'location' ), home_url() ) );
wp_cache_delete( $published_id, 'post_meta' );
wccal_sec_result( 'HTTP: published product click was counted', (int) get_post_meta( $published_id, '_wccal_clickthrough_count', true ) === (int) $count + 1 );
'' === $count ? delete_post_meta( $published_id, '_wccal_clickthrough_count' ) : update_post_meta( $published_id, '_wccal_clickthrough_count', $count );

// WCCAL-06: only http(s) redirect targets.
wccal_sec_result( 'redirect URL: https kept', 'https://m.example/x?a=1&b=2' === Wccal::sanitize_redirect_url( ' https://m.example/x?a=1&b=2 ' ) );
wccal_sec_result( 'redirect URL: HTTP kept', 'HTTP://m.example/' === Wccal::sanitize_redirect_url( 'HTTP://m.example/' ) );
foreach ( array( 'javascript:alert(1)', 'data:text/html,x', '//m.example/x', 'www.m.example', '' ) as $bad ) {
	wccal_sec_result( "redirect URL: '$bad' dropped", '' === Wccal::sanitize_redirect_url( $bad ) );
}
wccal_sec_result( 'redirect URL: array dropped', '' === Wccal::sanitize_redirect_url( array( 'https://m.example/' ) ) );

// WCCAL-03: the real URL in 'edit' context, so saving never stores the cloaked URL.
$product = wc_get_product( $published_id );
wccal_sec_result( 'product class is Wccal_Product_External (' . get_class( $product ) . ')', $product instanceof Wccal_Product_External );
wccal_sec_result( "'view' context is cloaked", false !== strpos( $product->get_product_url(), '/' . Wccal::get_affiliate_base() . '/' . $published_id ) );
wccal_sec_result( "'edit' context is the stored URL", get_post_meta( $published_id, '_product_url', true ) === $product->get_product_url( 'edit' ) );
$new = wc_get_product_object( 'external' );
$new->set_product_url( 'https://merchant.example/x?a=1&b=2' );
wccal_sec_result( "unsaved product: 'edit' context is the real URL", 'https://merchant.example/x?a=1&b=2' === $new->get_product_url( 'edit' ) );

// WCCAL-04: settings are allow-listed and a bad stored value doesn't fatal.
$wccal->options = array( 'status' => '307', 'robots' => 'no' );
wccal_sec_result( 'validate: valid values saved', array( 'status' => '301', 'robots' => 'yes' ) === $wccal->validate( array( 'status' => '301', 'robots' => 'yes', 'x' => 'y' ) ) );
wccal_sec_result( 'validate: bad values keep current', array( 'status' => '307', 'robots' => 'no' ) === $wccal->validate( array( 'status' => '999', 'robots' => array( 'yes' ) ) ) );
wccal_sec_result( 'validate: missing values keep current', array( 'status' => '307', 'robots' => 'no' ) === $wccal->validate( array() ) );
wccal_sec_result( 'validate: string input keeps current', array( 'status' => '307', 'robots' => 'no' ) === $wccal->validate( 'garbage' ) );
$wccal->options = array( 'status' => 'bad', 'robots' => 'bad' );
wccal_sec_result( 'validate: bad current falls back to defaults', array( 'status' => '302', 'robots' => 'yes' ) === $wccal->validate( null ) );
foreach ( array( '301' => 301, '307' => 307, 307 => 307, '999' => 302, '200' => 302, 'abc' => 302 ) as $in => $out ) {
	$wccal->options['status'] = $in;
	wccal_sec_result( "redirect status '$in' -> $out", $out === $wccal->get_redirect_status() );
}
$wccal->options['status'] = array( 301 );
wccal_sec_result( 'redirect status array -> 302', 302 === $wccal->get_redirect_status() );

$options_backup = get_option( 'wccal_options' );
update_option( 'wccal_options', 'garbage' ); // Not through validate(): register_setting() only runs in wp-admin.
try {
	$options = $wccal->load_options();
	wccal_sec_result( 'string option loads defaults without a fatal', array( 'status' => '302', 'robots' => 'yes' ) === $options );
} catch ( TypeError $e ) {
	wccal_sec_result( 'string option loads defaults without a fatal (' . $e->getMessage() . ')', false );
}
update_option( 'wccal_options', $options_backup );

// WCCAL-05: affiliate base.
$bases = array(
	'redirect' => 'redirect',
	'go/out/'  => 'go/out',
	'/Go'      => 'Go',
	' go-to '  => 'go-to',
	'go"x'     => 'gox',
	'go.*'     => 'go',
	'перейти'  => 'перейти',
	''         => '',
	'.*'       => false,
	'p'        => false,
	's'        => false,
	'page_id'  => false,
);
foreach ( $bases as $in => $out ) {
	wccal_sec_result( "base '$in' -> " . var_export( $out, true ), $out === Wccal::sanitize_affiliate_base( (string) $in ) );
}
wccal_sec_result( 'base array -> false', false === Wccal::sanitize_affiliate_base( array( 'go' ) ) );
$base_backup  = $wccal->base;
$wccal->base  = 'go-out';
$rules        = $wccal->rewrite_rules_array( array() );
$wccal->base  = $base_backup;
wccal_sec_result( 'rewrite rule quotes the base', array( 'go\-out/([^/]+)/?$' ) === array_keys( $rules ) && 1 === preg_match( '#^' . array_keys( $rules )[0] . '#', 'go-out/123/' ) );

// WCCAL-05 / 08: the Permalinks save, as an admin in wp-admin.
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
$GLOBALS['current_screen'] = WP_Screen::get( 'options-permalink' );
wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID );
$permalinks_backup         = get_option( 'wccal_permalinks' );
$_POST['_wpnonce']         = wp_create_nonce( 'update-permalink' );
$_REQUEST['_wpnonce']      = $_POST['_wpnonce'];
$save                      = function ( $value ) use ( $wccal ) {
	$_POST['wccal_affiliate_base'] = $value;
	$wccal->permalink_settings_save();
	$saved = get_option( 'wccal_permalinks' );

	return is_array( $saved ) && isset( $saved['affiliate_base'] ) ? $saved['affiliate_base'] : null;
};
wccal_sec_result( 'save: slashed quote is unslashed and removed', 'gox' === $save( wp_slash( 'go"x' ) ) );
wccal_sec_result( 'save: unusable base keeps the current one', 'gox' === $save( '.*' ) );
wccal_sec_result( 'save: reserved query var keeps the current one', 'gox' === $save( 'p' ) );
wccal_sec_result( 'save: empty resets to the default', '' === $save( '' ) );
unset( $_POST['wccal_affiliate_base'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
false === $permalinks_backup ? delete_option( 'wccal_permalinks' ) : update_option( 'wccal_permalinks', $permalinks_backup );
wccal_sec_result( 'permalinks option restored', get_option( 'wccal_permalinks' ) === $permalinks_backup );

// WCCAL-07: one nonce field on the settings page.
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
ob_start();
$wccal->build_options_page();
$html = ob_get_clean();
wccal_sec_result( 'settings page has one _wpnonce field', 1 === substr_count( $html, 'name="_wpnonce"' ) );

// Release: the version is the same everywhere.
$header = get_file_data( WCCAL_PATH . 'woocommerce-cloak-affiliate-links.php', array( 'v' => 'Version' ) )['v'];
$stable = get_file_data( WCCAL_PATH . 'readme.txt', array( 'v' => 'Stable tag' ) )['v'];
wccal_sec_result( "version $header / " . WCCAL_VERSION . " / $stable consistent", $header === WCCAL_VERSION && $stable === WCCAL_VERSION && false !== strpos( file_get_contents( WCCAL_PATH . 'readme.txt' ), '= ' . WCCAL_VERSION . ' - ' ) );
