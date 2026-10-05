<?php
/*
Plugin Name: Cloak Affiliate Links for WooCommerce
Plugin URI: https://www.datafeedr.com
Description: Cloak your WooCommerce external & affiliate links.
Author: datafeedr.com
Author URI: http://www.datafeedr.com
License: GPL v3
Requires at least: 4.7.0
Tested up to: 6.7
Version: 1.0.38

WC requires at least: 3.0
WC tested up to: 11.0

Cloak Affiliate Links for WooCommerce plugin
Copyright (C) 2026, Datafeedr - help@datafeedr.com

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

/**
 * Define constants.
 */
define( 'WCCAL_VERSION', '1.0.38' );
define( 'WCCAL_URL', plugin_dir_url( __FILE__ ) );
define( 'WCCAL_PATH', plugin_dir_path( __FILE__ ) );
define( 'WCCAL_BASENAME', plugin_basename( __FILE__ ) );
define( 'WCCAL_DOMAIN', 'wccal' );

/**
 * Declaring WooCommerce HPOS compatibility.
 *
 * @see https://github.com/woocommerce/woocommerce/wiki/High-Performance-Order-Storage-Upgrade-Recipe-Book
 *
 * @since 1.0.33
 */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );


if ( ! class_exists( 'Wccal' ) ) {

	/**
	 * Configuration page.
	 */
	class Wccal {

		public $base;
		public $options;

		public function __construct() {

			register_activation_hook( __FILE__, array( $this, 'activate_plugin' ) );
			register_deactivation_hook( __FILE__, array( $this, 'deactivate_plugin' ) );

			$this->base    = self::get_affiliate_base();
			$this->options = $this->load_options();

			add_filter( 'query_vars', array( $this, 'query_vars' ) );
			add_filter( 'rewrite_rules_array', array( $this, 'rewrite_rules_array' ) );
			add_filter( 'woocommerce_product_class', array( $this, 'woocommerce_product_class' ), 40, 4 );
			add_filter( 'robots_txt', array( $this, 'robots_txt' ), 10, 2 );
			add_filter( 'plugin_action_links_' . WCCAL_BASENAME, array( $this, 'action_links' ) );

			add_action( 'template_redirect', array( $this, 'template_redirect' ) );
			add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ) );
			add_action( 'admin_init', array( $this, 'permalink_settings_init' ) );
			add_action( 'admin_init', array( $this, 'permalink_settings_save' ) );
			add_action( 'admin_menu', array( $this, 'options_page' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
			add_action( 'wccal_clickthrough', array( $this, 'count_clickthrough' ) );
		}

		/**
		 * Flush rerwrite rules on plugin activation.
		 */
		function activate_plugin() {
			flush_rewrite_rules();
		}

		/**
		 * Flush rerwrite rules on plugin deactivation.
		 */
		function deactivate_plugin() {
			flush_rewrite_rules();
		}

		/**
		 * Add "permalink" path to robots.txt file.
		 */
		function robots_txt( $output, $public ) {
			if ( $this->options['robots'] == 'yes' && get_option( 'permalink_structure' ) ) {
				$site_url = parse_url( site_url() );
				$path     = ( ! empty( $site_url['path'] ) ) ? $site_url['path'] : '';
				$text     = "Disallow: $path/" . $this->base . "/\n";
				$text     = apply_filters( 'wccal_robots_txt', $text );
				$output   .= $text;
			}

			return $output;
		}

		/**
		 * Get the base permalink settings.
		 */
		static public function get_affiliate_base() {
			$permalinks = get_option( 'wccal_permalinks' );
			if ( ! $permalinks || ! isset( $permalinks['affiliate_base'] ) || $permalinks['affiliate_base'] == '' ) {
				return 'redirect';
			}

			return $permalinks['affiliate_base'];
		}

		/**
		 * Set default option values.
		 */
		function default_options() {
			return array(
				'status' => '302',
				'robots' => 'yes',
			);
		}

		/**
		 * Load default or configured options.
		 */
		function load_options() {
			$saved   = get_option( 'wccal_options', array() );
			$options = array_merge(
				$this->default_options(),
				is_array( $saved ) ? $saved : array()
			);
			update_option( 'wccal_options', $options );

			return $options;
		}

		/**
		 * Add "Settings" page to "Settings" menu.
		 */
		function options_page() {
			add_options_page(
				__( 'Cloak Affiliate Links for WooCommerce Settings', WCCAL_DOMAIN ),
				__( 'WC Cloak Links', WCCAL_DOMAIN ),
				'manage_options',
				'wccal-options',
				array( $this, 'build_options_page' )
			);
		}

		/**
		 * Add "Settings" link to plugin page.
		 */
		function action_links( $links ) {
			return array_merge(
				array(
					'settings' => '<a href="' . esc_url( admin_url( 'options-general.php?page=wccal-options' ) ) . '">' . esc_html__( 'Settings',
							WCCAL_DOMAIN ) . '</a>',
				),
				$links
			);
		}

		/**
		 * Set up the options page to configure the WCCAL plugin.
		 */
		function build_options_page() {
			echo '<div class="wrap" id="wccal_options">';
			echo '<h2>' . esc_html__( 'Cloak Affiliate Links for WooCommerce Settings', WCCAL_DOMAIN ) . '</h2>';
			echo '<form method="post" action="options.php">';
			settings_fields( 'wccal-options' );
			do_settings_sections( 'wccal-options' );
			submit_button();
			echo '</form>';
			echo '</div>';
		}

		/**
		 * Register settings.
		 */
		function register_settings() {
			register_setting( 'wccal-options', 'wccal_options', array( $this, 'validate' ) );
			add_settings_section( 'general_settings', __( 'General Settings', WCCAL_DOMAIN ),
				array( &$this, 'section_general_settings_desc' ), 'wccal-options' );
			add_settings_field( 'status', __( 'Status Code', WCCAL_DOMAIN ), array( &$this, 'field_status' ),
				'wccal-options', 'general_settings' );
			if ( get_option( 'permalink_structure' ) ) {
				add_settings_field( 'robots', __( 'Add Redirect Path to Robots.txt', WCCAL_DOMAIN ),
					array( &$this, 'field_robots' ), 'wccal-options', 'general_settings' );
			}
		}

		/**
		 * General settings decription.
		 */
		function section_general_settings_desc() {
			// _e( 'General plugin settings.', WCCAL_DOMAIN );
		}

		/**
		 * Field to select Status Code.
		 */
		function field_status() { ?>
            <select id="wwcal_status" name="wccal_options[status]">
                <option value="301" <?php selected( $this->options['status'], '301',
					true ); ?>><?php esc_html_e( '301 (Moved Permanently)', WCCAL_DOMAIN ); ?></option>
                <option value="302" <?php selected( $this->options['status'], '302',
					true ); ?>><?php esc_html_e( '302 (Found/Temporary Redirect)', WCCAL_DOMAIN ); ?></option>
                <option value="307" <?php selected( $this->options['status'], '307',
					true ); ?>><?php esc_html_e( '307 (Temporary Redirect)', WCCAL_DOMAIN ); ?></option>
            </select>
            <p class="description"><?php esc_html_e( 'The status code to use when performing the redirect',
					WCCAL_DOMAIN ); ?></p>
			<?php
		}

		/**
		 * Field to enabled/disable robots.txt.
		 */
		function field_robots() { ?>
            <p><input type="radio" value="yes" name="wccal_options[robots]" <?php checked( $this->options['robots'],
					'yes', true ); ?> /> <?php esc_html_e( 'Yes', WCCAL_DOMAIN ); ?></p>
            <p><input type="radio" value="no" name="wccal_options[robots]" <?php checked( $this->options['robots'],
					'no', true ); ?> /> <?php esc_html_e( 'No', WCCAL_DOMAIN ); ?></p>
            <p class="description"><?php esc_html_e( 'Add the path configured on your Permalinks page to your robots.txt file to prevent any search engines from attempting to view or index that path.',
					WCCAL_DOMAIN ); ?></p>
			<?php
		}

		/**
		 * Register a new var.
		 */
		function query_vars( $vars ) {
			$vars[] = $this->base;

			return $vars;
		}

		/**
		 * Add the new rewrite rule to existings ones.
		 */
		function rewrite_rules_array( $rules ) {
			$new_rules = array( preg_quote( $this->base, '#' ) . '/([^/]+)/?$' => 'index.php?' . $this->base . '=$matches[1]' );
			$rules     = $new_rules + $rules;

			return $rules;
		}

		/**
		 * Redirect the user to external link.
		 */
		function template_redirect() {

			global $wp_query;

			if ( isset( $wp_query->query_vars[ $this->base ] ) ) {

				$post_id = intval( get_query_var( $this->base ) );

				if ( ! $this->is_redirect_allowed( $post_id ) ) {
					$wp_query->set_404();
					status_header( 404 );
					nocache_headers();

					return;
				}

				$external_link = get_post_meta( $post_id, '_product_url', true );
				$external_link = apply_filters( 'wccal_filter_url', $external_link, $post_id );
				$external_link = self::sanitize_redirect_url( $external_link );

				if ( $external_link != '' ) {
					$url = $external_link;
					do_action( 'wccal_clickthrough', $post_id );
				} else {
					$url = get_permalink( $post_id );
					do_action( 'wccal_clickthrough_fail', $post_id );
				}

				wp_redirect( $url, $this->get_redirect_status() );
				exit();
			}
		}

		/**
		 * Whether the cloaked link of $post_id may redirect the current visitor.
		 *
		 * Only published, non-password-protected products redirect, unless the current
		 * user can read the post. This stops visitors from reading the affiliate URLs of
		 * trashed, draft, private or password-protected products.
		 *
		 * @since 1.0.38
		 *
		 * @param int $post_id
		 *
		 * @return bool
		 */
		function is_redirect_allowed( $post_id ) {
			$post    = get_post( $post_id );
			$allowed = $post && 'product' === $post->post_type && (
					( 'publish' === $post->post_status && ! post_password_required( $post ) )
					|| current_user_can( 'read_post', $post->ID )
				);

			/**
			 * Allows other post types or statuses to redirect.
			 *
			 * @since 1.0.38
			 *
			 * @param bool $allowed True if the redirect is allowed.
			 * @param int $post_id The requested post ID.
			 */
			return (bool) apply_filters( 'wccal_is_redirect_allowed', $allowed, $post_id );
		}

		/**
		 * Return $url if it is an http(s) URL. Otherwise, return an empty string.
		 *
		 * @since 1.0.38
		 *
		 * @param mixed $url
		 *
		 * @return string
		 */
		static public function sanitize_redirect_url( $url ) {
			if ( ! is_string( $url ) ) {
				return '';
			}

			$url    = trim( $url );
			$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

			return in_array( $scheme, array( 'http', 'https' ), true ) ? $url : '';
		}

		/**
		 * The configured redirect status, or 302 if it isn't a 3xx code.
		 *
		 * @since 1.0.38
		 *
		 * @return int
		 */
		function get_redirect_status() {
			$status = is_scalar( $this->options['status'] ) ? absint( $this->options['status'] ) : 0;

			return ( $status >= 300 && $status <= 399 ) ? $status : 302;
		}

		/**
		 * Add 1 to clickthrough count.
		 */
		function count_clickthrough( $post_id ) {
			$count = intval( get_post_meta( $post_id, '_wccal_clickthrough_count', true ) );
			update_post_meta( $post_id, '_wccal_clickthrough_count', ( $count + 1 ) );
		}

		/**
		 * Change "WC_Product_External" class to our own class if class is "WC_Product_External".
		 */
		function woocommerce_product_class( $classname, $product_type, $post_type, $product_id ) {

			/**
			 * If we are in the /wp-admin but are not doing an AJAX request.
			 *
			 * This makes it possible to return a modified $classname when
			 * the admin-ajax.php file is requested.
			 */
			if ( is_admin() && ! wp_doing_ajax() ) {
				return $classname;
			}

			/**
			 * Allows the $cloak_urls_when_exporting variable to be modified.
			 *
			 * If $cloak_urls_when_exporting is true, external URLs will be cloaked when exported via
			 * the WooCommerce interface. Default is false.
			 *
			 * @since 1.0.15
			 *
			 * @param boolean True if cloaked URLs should be exported. Otherwise, false.
			 */
			$cloak_urls_when_exporting = apply_filters( 'wccal_cloak_urls_when_exporting', false );

			if ( ! $cloak_urls_when_exporting && isset( $_REQUEST['action'] ) && 'woocommerce_do_ajax_product_export' == $_REQUEST['action'] ) {
				return $classname;
			}

			/**
			 * Allow the $valid_classes array to be modified
			 *
			 * If there's another Product class that should be allowed to be extended, add it here.
			 *
			 * @since 1.0.11
			 *
			 * @param array $valid_classes Array of valid product classes.
			 */
			$valid_classes = apply_filters(
				'wccal_valid_product_classes',
				array( 'WC_Product_External', 'WooZoneWcProductModify_External' )
			);

			if ( ! in_array( $classname, $valid_classes ) ) {
				return $classname;
			}

			$classname = 'Wccal_Product_External';

			return $classname;
		}

		/**
		 * This loads our class only after all plugins have loaded.
		 */
		function plugins_loaded() {
			if ( ! class_exists( 'WC_Product_External' ) ) {
				return;
			}
			require_once( WCCAL_PATH . 'class-wccal-product-external.php' );
		}

		/**
		 * permalink_settings_init function.
		 */
		function permalink_settings_init() {
			add_settings_field(
				'wccal_redirect_slug',
				__( 'Affiliate link base', WCCAL_DOMAIN ),
				array( $this, 'permalink_input' ),
				'permalink',
				'optional'
			);
		}

		/**
		 * permalink_input function.
		 */
		function permalink_input() {
			$permalinks = get_option( 'wccal_permalinks' );
			?>
            <input name="wccal_affiliate_base" type="text" class="regular-text code" value="<?php if ( isset( $permalinks['affiliate_base'] ) ) {
				echo esc_attr( $permalinks['affiliate_base'] );
			} ?>" placeholder="<?php echo esc_attr_x( 'redirect', 'slug', WCCAL_DOMAIN ); ?>"/><code>/%post_id%/</code>
			<?php
		}

		/**
		 * permalink_settings_save function.
		 */
		function permalink_settings_save() {

			if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
				return;
			}

			if ( isset( $_POST['wccal_affiliate_base'] ) ) {

				check_admin_referer( 'update-permalink' );

				$wccal_affiliate_base = self::sanitize_affiliate_base( wp_unslash( $_POST['wccal_affiliate_base'] ) );
				$permalinks           = get_option( 'wccal_permalinks' );

				if ( ! is_array( $permalinks ) ) {
					$permalinks = array();
				}

				// An unusable base keeps the current one, so existing links don't change.
				if ( false === $wccal_affiliate_base ) {
					return;
				}

				$permalinks['affiliate_base'] = $wccal_affiliate_base;

				update_option( 'wccal_permalinks', $permalinks );
			}
		}

		/**
		 * Sanitize the "Affiliate link base" setting.
		 *
		 * The base becomes part of a rewrite rule, a query var and every cloaked URL, so each
		 * "/"-separated segment keeps only letters, numbers, "_" and "-". Case is preserved,
		 * because rewrite rules are case-sensitive.
		 *
		 * @since 1.0.38
		 *
		 * @param mixed $value The submitted value.
		 *
		 * @return string|false The sanitized base, "" to use the default, or false if the value can't be used.
		 */
		static public function sanitize_affiliate_base( $value ) {
			global $wp;

			if ( ! is_string( $value ) ) {
				return false;
			}

			$value = sanitize_text_field( $value );

			if ( '' === $value ) {
				return '';
			}

			$segments = array();
			foreach ( explode( '/', $value ) as $segment ) {
				$segment = (string) preg_replace( '/[^\p{L}\p{N}_-]/u', '', $segment );
				if ( '' !== $segment ) {
					$segments[] = $segment;
				}
			}

			$base     = implode( '/', $segments );
			$reserved = ( $wp instanceof WP ) ? array_merge( $wp->public_query_vars, $wp->private_query_vars ) : array();

			if ( '' === $base || in_array( $base, $reserved, true ) ) {
				return false;
			}

			return $base;
		}

		/**
		 * Validate options submitted.
		 */
		function validate( $input ) {
			$input     = is_array( $input ) ? $input : array();
			$defaults  = $this->default_options();
			$allowed   = array(
				'status' => array( '301', '302', '307' ),
				'robots' => array( 'yes', 'no' ),
			);
			$new_input = array();

			// Keep the current value when a submitted one is missing or not allowed.
			foreach ( $allowed as $key => $values ) {
				foreach ( array( $input, $this->options ) as $source ) {
					if ( isset( $source[ $key ] ) && is_scalar( $source[ $key ] ) && in_array( (string) $source[ $key ], $values, true ) ) {
						$new_input[ $key ] = (string) $source[ $key ];
						break;
					}
				}
				if ( ! isset( $new_input[ $key ] ) ) {
					$new_input[ $key ] = $defaults[ $key ];
				}
			}

			return $new_input;
		}


	} // class Wccal

	new Wccal();

} // class_exists check

