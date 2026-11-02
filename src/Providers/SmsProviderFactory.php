<?php

declare(strict_types=1);

namespace ParsiReach\Providers;

use ParsiReach\Admin\SettingsPage;
use ParsiReach\Providers\Contracts\SmsProviderInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SmsProviderFactory {

	public static function make( bool $force_live = false ): SmsProviderInterface {
		$settings = SettingsPage::get_settings();

		if ( ! $force_live && ! empty( $settings['sandbox_mode'] ) ) {
			return new SandboxSmsProvider();
		}

		$api_key    = SettingsPage::get_decrypted_api_key();
		$originator = (string) ( $settings['sender_number'] ?? '' );

		$provider = new FarazSmsProvider( $api_key, $originator );

		return apply_filters( 'parsireach_sms_provider', $provider, $settings );
	}
}
