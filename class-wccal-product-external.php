<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wccal_Product_External extends WC_Product_External {
	public function get_product_url( $context = 'view' ) {

		// Let's fetch an array of all whitelisted domains
		$whitelist = Wccal::get_whitelist_array();

		// The actual URL
		$url = esc_url_raw( $this->get_prop( 'product_url', $context ) );

		//Find matches, return the real URL if something is whitelisted
		$domain = parse_url($url, PHP_URL_SCHEME)."://".parse_url($url, PHP_URL_HOST);
		if ( is_array($whitelist) && in_array($domain, $whitelist) ) return $url; 

		$base = Wccal::get_affiliate_base();
		if ( get_option( 'permalink_structure' ) ) {
			return user_trailingslashit( home_url() . '/' . $base . '/' . $this->id );
		} else {
			return home_url() . '/index.php?' . $base . '=' . $this->id;
		}
	}
}