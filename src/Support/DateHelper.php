<?php

declare(strict_types=1);

namespace ParsiReach\Support;

use DateTimeImmutable;
use DateTimeZone;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DateHelper {
	public static function now_utc(): string {
		return gmdate( 'Y-m-d H:i:s' );
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

}
