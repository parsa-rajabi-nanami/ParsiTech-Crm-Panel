<?php

declare(strict_types=1);

namespace ParsiShop\CRM\Support;

use DateTimeImmutable;
use DateTimeZone;
use ParsiShop\CRM\Admin\SettingsPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DateHelper {
	public static function now_utc(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	public static function adjust_to_allowed_window(
		int $utc_timestamp,
		?string $window_start = null,
		?string $window_end = null,
		?bool $enforce_window = null
	): int {
		$settings      = SettingsPage::get_settings();
		$window_start  = $window_start ?? (string) ( $settings['window_start'] ?? '09:00' );
		$window_end    = $window_end ?? (string) ( $settings['window_end'] ?? '21:00' );
		$enforce_window = $enforce_window ?? ! empty( $settings['enforce_time_window'] );

		if ( ! $enforce_window ) {
			return $utc_timestamp;
		}
		if ( $window_start === $window_end ) {
			$window_start = '09:00';
			$window_end   = '21:00';
		}

		if ( ! self::valid_time( $window_start ) || ! self::valid_time( $window_end ) ) {
			return $utc_timestamp;
		}

		$tz       = wp_timezone();
		$local_dt = ( new DateTimeImmutable( '@' . $utc_timestamp ) )->setTimezone( $tz );
		[ $start_h, $start_m ] = array_map( 'intval', explode( ':', $window_start ) );
		[ $end_h, $end_m ]     = array_map( 'intval', explode( ':', $window_end ) );

		$today_start = $local_dt->setTime( $start_h, $start_m, 0 );
		$today_end   = $local_dt->setTime( $end_h, $end_m, 0 );
		$overnight   = $window_start > $window_end;

		if ( ! $overnight ) {
			if ( $local_dt < $today_start ) {
				return $today_start->getTimestamp();
			}
			if ( $local_dt >= $today_end ) {
				return $today_start->modify( '+1 day' )->getTimestamp();
			}
			return $utc_timestamp;
		}

		// An overnight window, e.g. 21:00–07:00, spans two local dates.
		if ( $local_dt >= $today_start || $local_dt < $today_end ) {
			return $utc_timestamp;
		}
		return $today_start->getTimestamp();
	}

	public static function is_currently_in_allowed_window(): bool {
		$now = time();
		return self::adjust_to_allowed_window( $now ) === $now;
	}

	public static function format_for_display( string $utc_mysql_date, string $format = 'Y/m/d H:i' ): string {
		if ( '' === $utc_mysql_date || '0000-00-00 00:00:00' === $utc_mysql_date ) {
			return '—';
		}

		try {
			$dt = new DateTimeImmutable( $utc_mysql_date, new DateTimeZone( 'UTC' ) );
			return (string) wp_date( $format, $dt->getTimestamp(), wp_timezone() );
		} catch ( \Exception $e ) {
			return $utc_mysql_date;
		}
	}

	private static function valid_time( string $time ): bool {
		return 1 === preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time );
	}
}
