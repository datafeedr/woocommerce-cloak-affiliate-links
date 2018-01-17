<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wccal_Product_External extends WC_Product_External {
	public function get_product_url( $context = 'view' ) {
		$base = Wccal::get_affiliate_base();
		if ( get_option( 'permalink_structure' ) ) {
			return user_trailingslashit( home_url() . '/' . $base . '/' . $this->id );
		} else {
			return home_url() . '/index.php?' . $base . '=' . $this->id;
		}
	}
}