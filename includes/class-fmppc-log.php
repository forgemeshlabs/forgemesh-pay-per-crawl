<?php
/**
 * Hit log: one row per classified request, plus rollups for the dashboard.
 *
 * @package ForgeMesh_Pay_Per_Crawl
 */

defined( 'ABSPATH' ) || exit;

final class FMPPC_Log {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'fmppc_hits';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $t (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ts datetime NOT NULL,
			ua_class varchar(16) NOT NULL,
			bot_name varchar(64) NOT NULL DEFAULT '',
			verified tinyint(1) DEFAULT NULL,
			method varchar(8) NOT NULL DEFAULT 'GET',
			path varchar(255) NOT NULL DEFAULT '/',
			status varchar(16) NOT NULL,
			rail varchar(8) NOT NULL DEFAULT '',
			amount_units bigint(20) NOT NULL DEFAULT 0,
			tx_hash varchar(80) NOT NULL DEFAULT '',
			payer varchar(48) NOT NULL DEFAULT '',
			reason varchar(64) NOT NULL DEFAULT '',
			ip_hash char(16) NOT NULL DEFAULT '',
			ua varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY ts (ts),
			KEY bot_name (bot_name),
			KEY status (status),
			KEY ua_class (ua_class)
		) $charset;"
		);
	}

	/** Daily-salted, truncated IP hash: enough to count distinct sources, never a stored IP. */
	public static function ip_hash( $ip ) {
		$salt = get_option( 'fmppc_ip_salt' );
		if ( ! $salt ) {
			$salt = wp_generate_password( 32, false );
			update_option( 'fmppc_ip_salt', $salt, false );
		}
		return substr( hash( 'sha256', $salt . gmdate( 'Y-m-d' ) . $ip ), 0, 16 );
	}

	public static function record( array $row ) {
		global $wpdb;
		$defaults = array(
			'ts'           => current_time( 'mysql', true ),
			'ua_class'     => 'bot',
			'bot_name'     => '',
			'verified'     => null,
			'method'       => 'GET',
			'path'         => '/',
			'status'       => 'passed',
			'rail'         => '',
			'amount_units' => 0,
			'tx_hash'      => '',
			'payer'        => '',
			'reason'       => '',
			'ip_hash'      => '',
			'ua'           => '',
		);
		$row = array_merge( $defaults, $row );
		$row['bot_name'] = substr( (string) $row['bot_name'], 0, 64 );
		$row['path']     = substr( (string) $row['path'], 0, 255 );
		$row['ua']       = substr( (string) $row['ua'], 0, 255 );
		$row['reason']   = substr( (string) $row['reason'], 0, 64 );
		$wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	public static function prune( $days ) {
		global $wpdb;
		$days = max( 1, (int) $days );
		$t    = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE ts < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)", $days ) ); // phpcs:ignore WordPress.DB
	}

	// ── Rollups ─────────────────────────────────────────────────────────────

	private static function since( $days ) {
		return gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	}

	public static function totals( $days ) {
		global $wpdb;
		$t = self::table();
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) hits, SUM(ua_class <> 'human') bot_hits, SUM(status='paid') paid, SUM(status='unpaid') unpaid, SUM(status='rejected') rejected, SUM(status='blocked') blocked, SUM(status='monitor') monitor, SUM(CASE WHEN status='paid' THEN amount_units ELSE 0 END) revenue_units, COUNT(DISTINCT CASE WHEN ua_class <> 'human' THEN ip_hash END) bot_sources FROM $t WHERE ts >= %s", self::since( $days ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( 'intval', (array) $r );
	}

	public static function by_bot( $days, $limit = 50 ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT bot_name, ua_class, MAX(verified) verified, COUNT(*) hits, SUM(status='paid') paid, SUM(status='unpaid') unpaid, SUM(status='rejected') rejected, SUM(status='monitor') monitor, SUM(CASE WHEN status='paid' THEN amount_units ELSE 0 END) revenue_units, COUNT(DISTINCT ip_hash) sources, MAX(ts) last_seen FROM $t WHERE ua_class <> 'human' AND ts >= %s GROUP BY bot_name, ua_class ORDER BY hits DESC LIMIT %d", self::since( $days ), $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function by_class( $days ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT ua_class, COUNT(*) hits, SUM(status='paid') paid, SUM(CASE WHEN status='paid' THEN amount_units ELSE 0 END) revenue_units FROM $t WHERE ts >= %s GROUP BY ua_class ORDER BY hits DESC", self::since( $days ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function top_paths( $days, $limit = 20 ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT path, COUNT(*) hits, SUM(status='paid') paid, SUM(CASE WHEN status='paid' THEN amount_units ELSE 0 END) revenue_units FROM $t WHERE ua_class <> 'human' AND ts >= %s GROUP BY path ORDER BY hits DESC LIMIT %d", self::since( $days ), $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function daily( $days ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT DATE(ts) day, SUM(ua_class <> 'human') bot_hits, SUM(ua_class = 'human') human_hits, SUM(status='paid') paid, SUM(CASE WHEN status='paid' THEN amount_units ELSE 0 END) revenue_units FROM $t WHERE ts >= %s GROUP BY DATE(ts) ORDER BY day ASC", self::since( $days ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function settlements( $limit = 50 ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT ts, bot_name, ua_class, path, rail, amount_units, tx_hash, payer FROM $t WHERE status = 'paid' ORDER BY ts DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function recent( $limit = 100 ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT ts, ua_class, bot_name, verified, method, path, status, rail, amount_units, tx_hash, reason FROM $t WHERE ua_class <> 'human' ORDER BY ts DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function export_rows( $days ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT ts, ua_class, bot_name, verified, method, path, status, rail, amount_units, tx_hash, payer, reason, ip_hash, ua FROM $t WHERE ts >= %s ORDER BY ts DESC LIMIT 50000", self::since( $days ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}
}
