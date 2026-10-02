<?php
/** Shared formatting and privacy helpers. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function erm_pro_format_bytes( $bytes ) {
	$bytes = max( 0, (float) $bytes );
	$units = array( 'B', 'KB', 'MB', 'GB' );
	$power = min( 3, max( 0, (int) floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) ) ) );
	return round( $bytes / pow( 1024, $power ), 2 ) . ' ' . $units[ $power ];
}

/** Remove URL credentials, fragments, and common secret query parameters. */
function erm_pro_redact_url( $url ) {
	$url   = preg_replace( '/[\r\n]/', '', (string) $url );
	$url   = preg_replace( '~^(https?://)[^/@]+@~i', '$1', $url );
	$url   = explode( '#', $url, 2 )[0];
	$parts = explode( '?', $url, 2 );
	if ( isset( $parts[1] ) ) {
		$query = explode( '&', $parts[1] );
		foreach ( $query as &$parameter ) {
			$pair = explode( '=', $parameter, 2 );
			$key  = urldecode( $pair[0] );
			if ( preg_match( '/token|secret|password|passwd|authorization|api[_-]?key|access[_-]?key|signature|credential|nonce|cookie/i', $key ) ) {
				$parameter = $pair[0] . '=[REDACTED]';
			}
		}
		unset( $parameter );
		$url = $parts[0] . '?' . implode( '&', $query );
	}
	return $url;
}

/** Format legacy local database timestamps using the configured site timezone. */
function erm_pro_time_ago( $mysql ) {
	if ( function_exists( 'wp_timezone' ) ) {
		$zone = wp_timezone();
	} else {
		$name   = get_option( 'timezone_string' );
		$offset = (int) round( (float) get_option( 'gmt_offset', 0 ) * 60 );
		$name   = $name ? $name : sprintf( '%s%02d:%02d', $offset < 0 ? '-' : '+', floor( abs( $offset ) / 60 ), abs( $offset ) % 60 );
		$zone   = new DateTimeZone( $name );
	}
	try {
		$time = new DateTimeImmutable( $mysql, $zone );
	} catch ( Exception $error ) {
		return '-';
	}
	/* translators: %s: Human-readable elapsed time. */
	return sprintf( __( '%s ago', 'erm-pro' ), human_time_diff( $time->getTimestamp(), time() ) );
}
