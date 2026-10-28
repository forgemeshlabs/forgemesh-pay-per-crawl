<?php
/**
 * Crawler Atlas sharing (opt-in). Once a day, send yesterday's per-bot counts to
 * the Atlas so every site benefits from what every other site sees. What is
 * sent: a random reporter id, the day, and per bot: name, class, hits, how many
 * passed verification, how many were impostors, how many paid, what this site
 * does with the bot and at what price. Never sent: the site host, visitor
 * addresses, paths, user agents, content.
 *
 * @package ForgeMesh_Pay_Per_Crawl
 */

defined( 'ABSPATH' ) || exit;

final class FMPPC_Atlas {

	const ENDPOINT   = 'https://proxy.forgemesh.io/api/atlas/sightings';
	const OPT_ID     = 'fmppc_atlas_reporter';
	const OPT_STATE  = 'fmppc_atlas_state';

	public static function enabled( array $s ) {
		return ! empty( $s['atlas_share'] );
	}

	public static function reporter_id() {
		$id = get_option( self::OPT_ID, '' );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', (string) $id ) ) {
			$id = bin2hex( random_bytes( 16 ) );
			update_option( self::OPT_ID, $id, false );
		}
		return $id;
	}

	public static function state() {
		return (array) get_option( self::OPT_STATE, array() );
	}

	/** Rows for one UTC day, shaped for the Atlas. */
	public static function rows_for( $day ) {
		global $wpdb;
		$s    = FMPPC::settings();
		$from = $day . ' 00:00:00';
		$to   = $day . ' 23:59:59';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT bot_name, ua_class, COUNT(*) hits, SUM(verified = 1) verified, SUM(verified = 0) impostor, SUM(status = 'paid') paid FROM {$wpdb->prefix}fmppc_hits WHERE ua_class <> 'human' AND bot_name <> '' AND ts BETWEEN %s AND %s GROUP BY bot_name, ua_class", $from, $to ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$c      = array( 'name' => $r['bot_name'], 'class' => $r['ua_class'] );
			$rule   = FMPPC_Rules::active( $s ) ? FMPPC_Rules::match( (array) $s['price_rules'], $c, '/' ) : null;
			$action = $rule ? $rule['action'] : self::policy( $s, $c );
			$price  = ( $rule && null !== $rule['price_usd'] ) ? (float) $rule['price_usd'] : (float) $s['price_usd'];
			if ( 'monitor' === $s['mode'] && 'paid' === $action ) {
				$action = 'free'; // monitor mode charges nobody; report what actually happened.
			}
			$out[] = array(
				'bot_name'  => $r['bot_name'],
				'ua_class'  => $r['ua_class'],
				'hits'      => (int) $r['hits'],
				'verified'  => (int) $r['verified'],
				'impostor'  => (int) $r['impostor'],
				'paid'      => (int) $r['paid'],
				'action'    => $action,
				'price_usd' => 'paid' === $action ? $price : 0,
			);
		}
		return $out;
	}

	private static function policy( array $s, array $c ) {
		$over = (array) $s['bot_overrides'];
		if ( isset( $over[ $c['name'] ] ) && '' !== $over[ $c['name'] ] ) {
			return $over[ $c['name'] ];
		}
		return ( (array) $s['class_policy'] )[ $c['class'] ] ?? 'paid';
	}

	/** Daily hook: send yesterday (and today-so-far is skipped; days are final at UTC midnight). */
	public static function daily() {
		$s = FMPPC::settings();
		if ( ! self::enabled( $s ) ) {
			return;
		}
		$day = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
		$st  = self::state();
		if ( ( $st['last_day'] ?? '' ) === $day && ! empty( $st['ok'] ) ) {
			return;
		}
		self::send( $day );
	}

	public static function send( $day ) {
		$rows = self::rows_for( $day );
		$body = array( 'reporter' => self::reporter_id(), 'source' => 'plugin', 'day' => $day, 'rows' => $rows );
		$r    = wp_remote_post( self::ENDPOINT, array( 'timeout' => 15, 'headers' => array( 'content-type' => 'application/json', 'user-agent' => 'forgemesh-pay-per-crawl/' . FMPPC_VERSION . ' atlas' ), 'body' => wp_json_encode( $body ) ) );
		$ok   = ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r );
		update_option( self::OPT_STATE, array( 'last_day' => $day, 'sent_at' => time(), 'ok' => $ok, 'rows' => count( $rows ), 'error' => $ok ? '' : ( is_wp_error( $r ) ? $r->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $r ) ) ), false );
		return $ok;
	}
}
