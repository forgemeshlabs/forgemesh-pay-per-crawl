<?php
/**
 * Plugin Name:       ForgeMesh Pay-per-Crawl
 * Plugin URI:        https://forgemesh.io/wordpress
 * Description:       Charge AI crawlers and bots per page with x402 USDC micropayments on Base, paid straight to your wallet. Humans and verified search engines read free. Full bot analytics in wp-admin.
 * Version:           0.2.2
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            ForgeMesh Labs
 * Author URI:        https://forgemesh.io
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       forgemesh-pay-per-crawl
 *
 * @package ForgeMesh_Pay_Per_Crawl
 */

defined( 'ABSPATH' ) || exit;

define( 'FMPPC_VERSION', '0.2.2' );
define( 'FMPPC_FILE', __FILE__ );
define( 'FMPPC_DIR', plugin_dir_path( __FILE__ ) );
define( 'FMPPC_URL', plugin_dir_url( __FILE__ ) );

require_once FMPPC_DIR . 'includes/class-fmppc-keccak.php';
require_once FMPPC_DIR . 'includes/class-fmppc-classifier.php';
require_once FMPPC_DIR . 'includes/class-fmppc-x402.php';
require_once FMPPC_DIR . 'includes/class-fmppc-log.php';
require_once FMPPC_DIR . 'includes/class-fmppc-gate.php';
require_once FMPPC_DIR . 'includes/class-fmppc-discovery.php';
require_once FMPPC_DIR . 'includes/class-fmppc-pro.php';

final class FMPPC {

	const OPTION = 'fmppc_settings';

	public static function defaults() {
		return array(
			'mode'               => 'monitor', // monitor | gate
			'pay_to'             => '',
			'price_usd'          => 0.01,
			'facilitator'        => 'payai',   // payai | custom
			'facilitator_url'    => '',
			'facilitator_auth'   => '',
			'class_policy'       => array(
				'search'     => 'free',
				'social'     => 'free',
				'monitor'    => 'free',
				'seo'        => 'paid',
				'ai'         => 'paid',
				'automation' => 'paid',
				'bot'        => 'paid',
			),
			'bot_overrides'      => array(),
			'allow_paths'        => array(),
			'gate_feeds'         => 0,
			'gate_rest'          => 0,
			'log_humans'         => 1,
			'discovery'          => 1,
			'publish_contact'    => 0,
			'trust_proxy_header' => '',
			'retention_days'     => 90,
			'mpp_secret'         => '',
			'pro_key'            => '',
			'pro_listed'         => 1,
		);
	}

	public static function settings() {
		static $s = null;
		if ( null === $s ) {
			$saved = get_option( self::OPTION, array() );
			$s     = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
			$s['class_policy'] = array_merge( self::defaults()['class_policy'], (array) $s['class_policy'] );
			if ( '' === $s['mpp_secret'] ) {
				$s['mpp_secret'] = wp_generate_password( 48, true, true );
				update_option( self::OPTION, $s, false );
			}
			if ( 'payai' === $s['facilitator'] ) {
				$s['facilitator_url'] = FMPPC_X402::FACILITATORS['payai'];
			}
			// Never gate without a wallet: a 402 nobody can pay is just a block.
			if ( 'gate' === $s['mode'] && ! preg_match( '/^0x[0-9a-fA-F]{40}$/', (string) $s['pay_to'] ) ) {
				$s['mode'] = 'monitor';
			}
			$s['price_usd'] = max( 0.005, (float) $s['price_usd'] );
		}
		return $s;
	}

	public static function boot() {
		$s = self::settings();
		if ( FMPPC_Pro::enabled( $s ) ) {
			add_filter( 'fmppc_catalog', array( 'FMPPC_Pro', 'merge_catalog' ) );
		}
		( new FMPPC_Discovery( $s ) )->hooks();
		( new FMPPC_Pro( $s ) )->hooks();
		( new FMPPC_Gate( $s ) )->hooks();
		add_action( 'fmppc_daily', array( __CLASS__, 'daily' ) );
		if ( is_admin() ) {
			require_once FMPPC_DIR . 'admin/class-fmppc-admin.php';
			( new FMPPC_Admin() )->hooks();
		}
	}

	public static function activate() {
		FMPPC_Log::install();
		self::settings();
		if ( ! wp_next_scheduled( 'fmppc_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'fmppc_daily' );
		}
		update_option( 'fmppc_db_version', FMPPC_VERSION, false );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'fmppc_daily' );
		FMPPC_Pro::unschedule();
	}

	public static function daily() {
		FMPPC_Log::prune( self::settings()['retention_days'] );
	}
}

register_activation_hook( __FILE__, array( 'FMPPC', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'FMPPC', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'FMPPC', 'boot' ) );
// Keep the table current after updates without a reactivation.
add_action( 'admin_init', function () {
	if ( get_option( 'fmppc_db_version' ) !== FMPPC_VERSION ) {
		FMPPC::activate();
	}
} );
