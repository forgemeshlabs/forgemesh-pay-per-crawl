<?php
/**
 * Pro: optional hosted service (license key). Hourly sync of daily rollups and
 * settlements to the hosted dashboard, daily crawler-intel pull, license check.
 * Nothing here runs without a license key; the free plugin never calls out.
 *
 * @package ForgeMesh_Pay_Per_Crawl
 */

defined( 'ABSPATH' ) || exit;

final class FMPPC_Pro {

	const BASE       = 'https://proxy.forgemesh.io';
	const OPT_STATE  = 'fmppc_pro_state';
	const OPT_INTEL  = 'fmppc_intel';

	private $s;

	public function __construct( array $settings ) {
		$this->s = $settings;
	}

	public static function enabled( array $s ) {
		return ! empty( $s['pro_key'] ) && preg_match( '/^fmppc_[A-Za-z0-9_\-]{20,}$/', $s['pro_key'] );
	}

	public function hooks() {
		if ( ! self::enabled( $this->s ) ) {
			return;
		}
		add_action( 'fmppc_pro_sync', array( $this, 'sync' ) );
		if ( ! wp_next_scheduled( 'fmppc_pro_sync' ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', 'fmppc_pro_sync' );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( 'fmppc_pro_sync' );
	}

	public static function state() {
		$st = get_option( self::OPT_STATE, array() );
		return is_array( $st ) ? $st : array();
	}

	private static function save_state( array $patch ) {
		update_option( self::OPT_STATE, array_merge( self::state(), $patch ), false );
	}

	private function request( $method, $path, $body = null ) {
		$args = array(
			'method'     => $method,
			'timeout'    => 30,
			'headers'    => array( 'Authorization' => 'Bearer ' . $this->s['pro_key'], 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
			'user-agent' => 'forgemesh-pay-per-crawl/' . FMPPC_VERSION . ' (' . wp_parse_url( home_url(), PHP_URL_HOST ) . ')',
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$r = wp_remote_request( self::BASE . $path, $args );
		if ( is_wp_error( $r ) ) {
			return array( 'ok' => false, 'error' => $r->get_error_message(), 'code' => 0 );
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		$json = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		if ( ! is_array( $json ) ) {
			return array( 'ok' => false, 'error' => 'HTTP ' . $code, 'code' => $code );
		}
		$json['code'] = $code;
		if ( $code >= 400 ) {
			$json['ok'] = false;
		}
		return $json;
	}

	/** Called on settings save and daily. Stores status for the admin screens. */
	public function verify() {
		$r = $this->request( 'POST', '/api/wp/license/verify' );
		self::save_state(
			array(
				'verified_at' => time(),
				'ok'          => ! empty( $r['ok'] ),
				'status'      => (string) ( $r['status'] ?? ( $r['error'] ?? 'error' ) ),
				'email'       => (string) ( $r['email'] ?? '' ),
				'sites_used'  => (int) ( $r['sites_used'] ?? 0 ),
				'site_limit'  => (int) ( $r['site_limit'] ?? 0 ),
				'dashboard'   => (string) ( $r['dashboard_url'] ?? self::BASE . '/wp/dashboard' ),
			)
		);
		return $r;
	}

	/** Hourly: push rollups for yesterday + today, new settlements, pull intel once a day. */
	public function sync() {
		global $wpdb;
		$st = self::state();
		if ( empty( $st['verified_at'] ) || time() - (int) $st['verified_at'] > DAY_IN_SECONDS ) {
			$this->verify();
			$st = self::state();
		}
		if ( empty( $st['ok'] ) ) {
			return;
		}
		$t     = FMPPC_Log::table();
		$days  = array( gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ), gmdate( 'Y-m-d' ) );
		$daily = array();
		foreach ( $days as $day ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT bot_name, ua_class, COUNT(*) hits, SUM(status='paid') paid, SUM(status='unpaid') unpaid, SUM(status='rejected' OR status='settle_failed') rejected, SUM(status='monitor') monitor, SUM(CASE WHEN status='paid' THEN amount_units ELSE 0 END) revenue_units, SUM(CASE WHEN status='monitor' THEN amount_units ELSE 0 END) would_units, COUNT(DISTINCT ip_hash) sources FROM $t WHERE DATE(ts) = %s GROUP BY bot_name, ua_class ORDER BY hits DESC LIMIT 500", $day ), ARRAY_A ); // phpcs:ignore WordPress.DB
			$daily[] = array( 'day' => $day, 'rows' => array_map( function ( $r ) { return array_map( 'intval', $r ) + array( 'bot_name' => $r['bot_name'], 'ua_class' => $r['ua_class'] ); }, (array) $rows ) );
		}
		$last_id     = (int) ( $st['last_settlement_id'] ?? 0 );
		$settlements = $wpdb->get_results( $wpdb->prepare( "SELECT id, ts, bot_name, ua_class, path, rail, amount_units, tx_hash, payer FROM $t WHERE status='paid' AND id > %d ORDER BY id ASC LIMIT 1000", $last_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$payload     = array(
			'site'        => array(
				'host'           => wp_parse_url( home_url(), PHP_URL_HOST ),
				'url'            => home_url( '/' ),
				'name'           => get_bloginfo( 'name' ),
				'plugin_version' => FMPPC_VERSION,
				'mode'           => $this->s['mode'],
				'price_usd'      => (float) $this->s['price_usd'],
				'pay_to'         => (string) $this->s['pay_to'],
				'listed'         => ! empty( $this->s['pro_listed'] ),
			),
			'daily'       => $daily,
			'settlements' => array_map( function ( $r ) { unset( $r['id'] ); $r['amount_units'] = (int) $r['amount_units']; return $r; }, (array) $settlements ),
		);
		$r = $this->request( 'POST', '/api/wp/ingest', $payload );
		$patch = array( 'synced_at' => time(), 'sync_ok' => ! empty( $r['ok'] ), 'sync_error' => empty( $r['ok'] ) ? (string) ( $r['error'] ?? 'HTTP ' . $r['code'] ) : '' );
		if ( ! empty( $r['ok'] ) && $settlements ) {
			$patch['last_settlement_id'] = (int) end( $settlements )['id'];
		}
		self::save_state( $patch );

		if ( empty( $st['intel_at'] ) || time() - (int) $st['intel_at'] > DAY_IN_SECONDS ) {
			$intel = $this->request( 'GET', '/api/wp/intel' );
			if ( ! empty( $intel['agents'] ) && is_array( $intel['agents'] ) ) {
				$clean = array();
				foreach ( array_slice( $intel['agents'], 0, 500 ) as $a ) {
					if ( empty( $a['name'] ) || empty( $a['match'] ) || ! is_array( $a['match'] ) || ! in_array( $a['class'] ?? '', FMPPC_Classifier::classes(), true ) ) {
						continue;
					}
					$clean[] = array(
						'name'   => substr( sanitize_text_field( $a['name'] ), 0, 64 ),
						'class'  => $a['class'],
						'match'  => array_values( array_filter( array_map( function ( $m ) { return strtolower( substr( (string) $m, 0, 64 ) ); }, array_slice( $a['match'], 0, 8 ) ) ) ),
						'verify' => array_values( array_filter( array_map( 'strval', (array) ( $a['verify'] ?? array() ) ) ) ),
					);
				}
				update_option( self::OPT_INTEL, array( 'version' => (string) ( $intel['version'] ?? '' ), 'agents' => $clean ), false );
				self::save_state( array( 'intel_at' => time(), 'intel_version' => (string) ( $intel['version'] ?? '' ), 'intel_count' => count( $clean ) ) );
			}
		}
	}

	/** Merge the pulled feed into the shipped catalog: new names appended, known names updated. */
	public static function merge_catalog( array $catalog ) {
		$intel = get_option( self::OPT_INTEL, array() );
		if ( empty( $intel['agents'] ) ) {
			return $catalog;
		}
		$by = array();
		foreach ( $catalog as $i => $e ) {
			$by[ $e['name'] ] = $i;
		}
		foreach ( $intel['agents'] as $a ) {
			if ( isset( $by[ $a['name'] ] ) ) {
				$catalog[ $by[ $a['name'] ] ]['match'] = array_values( array_unique( array_merge( $catalog[ $by[ $a['name'] ] ]['match'], $a['match'] ) ) );
				if ( ! empty( $a['verify'] ) ) {
					$catalog[ $by[ $a['name'] ] ]['verify'] = $a['verify'];
				}
			} else {
				$catalog[] = $a;
			}
		}
		return $catalog;
	}
}
