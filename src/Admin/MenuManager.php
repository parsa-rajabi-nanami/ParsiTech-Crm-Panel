<?php

declare(strict_types=1);

namespace ParsiReach\Admin;

use ParsiReach\Core\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MenuManager {
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'add_menus' ), 9 );
	}

	public static function add_menus(): void {
		add_menu_page(
			'ParsiReach',
			'ParsiReach',
			Capabilities::MANAGE_CAP,
			'parsireach',
			array( DashboardPage::class, 'render_page' ),
			'dashicons-megaphone',
			56
		);

		add_submenu_page(
			'parsireach',
			__( 'داشبورد', 'parsireach' ),
			__( 'داشبورد', 'parsireach' ),
			Capabilities::MANAGE_CAP,
			'parsireach',
			array( DashboardPage::class, 'render_page' )
		);
	}
}
