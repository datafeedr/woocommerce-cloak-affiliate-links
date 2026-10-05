<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wccal_Product_External extends WC_Product_External {
	public function get_product_url( $context = 'view' ) {

		// WooCommerce saves products with the 'edit' context: return the real URL so the cloaked one is never stored.
		if ( 'edit' === $context ) {
			return parent::get_product_url( $context );
		}

		$base = Wccal::get_affiliate_base();

		if ( get_option( 'permalink_structure' ) ) {
			return apply_filters(
				'wccal_product_url_permalink',
				user_trailingslashit( home_url() . '/' . $base . '/' . $this->get_id() ),
				$this
			);
		}

		return add_query_arg( apply_filters( 'wccal_product_url_qs', [
			$base => $this->get_id(),
		], $this ), home_url() . '/index.php' );
	}
}
