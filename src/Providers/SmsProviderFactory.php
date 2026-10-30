<?php

declare(strict_types=1);

namespace ParsiShop\CRM\Providers;

use ParsiShop\CRM\Admin\SettingsPage;
use ParsiShop\CRM\Providers\Contracts\SmsProviderInterface;

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

		return apply_filters( 'parsishop_crm_sms_provider', $provider, $settings );
	}
}
