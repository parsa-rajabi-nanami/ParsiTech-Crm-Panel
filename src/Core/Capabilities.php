<?php

declare(strict_types=1);

namespace ParsiShop\CRM\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Capabilities {

	public const MANAGE_CAP = 'parsishop_crm_manage';

	public static function init(): void {
		add_filter( 'user_has_cap', array( self::class, 'grant_cap_to_shop_managers' ), 10, 4 );
	}

	/**
	 * @param array<string, bool> $allcaps
	 * @param array<int, string>  $caps
	 * @param array<int, mixed>   $args
	 * @param \WP_User            $user
	 * @return array<string, bool>
	 */
	public static function grant_cap_to_shop_managers( array $allcaps, array $caps, array $args, \WP_User $user ): array {
		if ( ! empty( $allcaps['manage_woocommerce'] ) || ! empty( $allcaps['manage_options'] ) ) {
			$allcaps[ self::MANAGE_CAP ] = true;
		}
		return $allcaps;
	}

	public static function current_user_can_manage(): bool {
		return current_user_can( self::MANAGE_CAP ) || current_user_can( 'manage_woocommerce' );
	}
}
