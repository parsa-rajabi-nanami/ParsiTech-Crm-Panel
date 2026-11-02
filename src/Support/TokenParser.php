<?php

declare(strict_types=1);

namespace ParsiReach\Support;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TokenParser {
	/** @param array<string, mixed> $values
	 *  @return array<string, string>
	 */
	public static function parse_array( array $values, \WC_Order $order ): array {
		$tokens = self::get_tokens( $order );
		$parsed = array();
		foreach ( $values as $key => $value ) {
			$key = (string) $key;
			if ( is_scalar( $value ) && 1 === preg_match( '/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $key ) ) {
				$parsed[ $key ] = strtr( (string) $value, $tokens );
			}
		}
		return $parsed;
	}

	/** @return array<string, string> */
	private static function get_tokens( \WC_Order $order ): array {
		$items         = $order->get_items();
		$first_product = '';
		if ( ! empty( $items ) ) {
			$first_item    = reset( $items );
			$first_product = $first_item ? $first_item->get_name() : '';
		}

		$created = $order->get_date_created();
		return array(
			'{customer_name}'      => (string) $order->get_billing_first_name(),
			'{customer_last_name}' => (string) $order->get_billing_last_name(),
			'{order_number}'       => (string) $order->get_order_number(),
			'{order_date}'         => $created ? wc_format_datetime( $created ) : '',
			'{order_total}'        => wp_strip_all_tags( wc_price( (float) $order->get_total(), array( 'currency' => $order->get_currency() ) ) ),
			'{product_name}'       => (string) $first_product,
			'{shop_name}'          => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		);
	}
}
