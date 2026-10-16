<?php
/**
 * wp-admin: dashboard, settings, CSV export.
 *
 * @package ForgeMesh_Pay_Per_Crawl
 */

defined( 'ABSPATH' ) || exit;

final class FMPPC_Admin {

	const CAP = 'manage_options';

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'admin_post_fmppc_export', array( $this, 'export' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FMPPC_FILE ), array( $this, 'links' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
	}

	public function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=fmppc-settings' ) ) . '">' . esc_html__( 'Settings', 'forgemesh-pay-per-crawl' ) . '</a>' );
		return $links;
	}

	public function menu() {
		add_menu_page( __( 'Pay-per-Crawl', 'forgemesh-pay-per-crawl' ), __( 'Pay-per-Crawl', 'forgemesh-pay-per-crawl' ), self::CAP, 'fmppc', array( $this, 'dashboard' ), 'dashicons-money-alt', 81 );
		add_submenu_page( 'fmppc', __( 'Bot analytics', 'forgemesh-pay-per-crawl' ), __( 'Bot analytics', 'forgemesh-pay-per-crawl' ), self::CAP, 'fmppc', array( $this, 'dashboard' ) );
		add_submenu_page( 'fmppc', __( 'Settings', 'forgemesh-pay-per-crawl' ), __( 'Settings', 'forgemesh-pay-per-crawl' ), self::CAP, 'fmppc-settings', array( $this, 'settings_page' ) );
	}

	public function assets( $hook ) {
		if ( false === strpos( $hook, 'fmppc' ) ) {
			return;
		}
		wp_enqueue_style( 'fmppc-admin', FMPPC_URL . 'assets/admin.css', array(), FMPPC_VERSION );
	}

	public function notices() {
		$s = FMPPC::settings();
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, 'fmppc' ) ) {
			return;
		}
		if ( '' === $s['pay_to'] ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Add your payout wallet (an Ethereum-style 0x address on Base) in Settings to start charging. Until then the plugin only observes.', 'forgemesh-pay-per-crawl' ) . '</p></div>';
		} elseif ( 'monitor' === $s['mode'] ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'Monitor mode: bots are logged with the revenue they would have paid, nothing is charged yet. Switch the mode to "Charge" in Settings when the numbers look right.', 'forgemesh-pay-per-crawl' ) . '</p></div>';
		}
	}

	// ── Settings API ────────────────────────────────────────────────────────

	public function register() {
		register_setting( 'fmppc', FMPPC::OPTION, array( 'sanitize_callback' => array( $this, 'sanitize' ), 'default' => FMPPC::defaults() ) );
	}

	public function sanitize( $in ) {
		$d   = FMPPC::defaults();
		$cur = get_option( FMPPC::OPTION, array() );
		$in  = is_array( $in ) ? $in : array();
		$out = array();

		$out['mode']            = ( isset( $in['mode'] ) && 'gate' === $in['mode'] ) ? 'gate' : 'monitor';
		$pay                    = trim( (string) ( $in['pay_to'] ?? '' ) );
		$out['pay_to']          = preg_match( '/^0x[0-9a-fA-F]{40}$/', $pay ) ? $pay : '';
		if ( '' !== $pay && '' === $out['pay_to'] ) {
			add_settings_error( 'fmppc', 'pay_to', __( 'The payout wallet must be a 0x address with 40 hex characters.', 'forgemesh-pay-per-crawl' ) );
		}
		$out['price_usd']       = max( 0.005, min( 100, (float) ( $in['price_usd'] ?? $d['price_usd'] ) ) );
		$out['facilitator']     = ( isset( $in['facilitator'] ) && 'custom' === $in['facilitator'] ) ? 'custom' : 'payai';
		$url                    = esc_url_raw( trim( (string) ( $in['facilitator_url'] ?? '' ) ) );
		$out['facilitator_url'] = ( 'custom' === $out['facilitator'] && 0 === strpos( $url, 'https://' ) ) ? $url : '';
		if ( 'custom' === $out['facilitator'] && '' === $out['facilitator_url'] ) {
			$out['facilitator'] = 'payai';
			add_settings_error( 'fmppc', 'facilitator', __( 'A custom facilitator needs an https:// URL; using PayAI instead.', 'forgemesh-pay-per-crawl' ) );
		}
		$out['facilitator_auth'] = sanitize_text_field( (string) ( $in['facilitator_auth'] ?? '' ) );

		$out['class_policy'] = array();
		foreach ( $d['class_policy'] as $cls => $def ) {
			$v                           = $in['class_policy'][ $cls ] ?? $def;
			$out['class_policy'][ $cls ] = in_array( $v, array( 'free', 'paid', 'block' ), true ) ? $v : $def;
		}
		$out['bot_overrides'] = array();
		foreach ( (array) ( $in['bot_overrides'] ?? array() ) as $name => $v ) {
			$name = sanitize_text_field( (string) $name );
			if ( '' !== $name && in_array( $v, array( 'free', 'paid', 'block' ), true ) ) {
				$out['bot_overrides'][ $name ] = $v;
			}
		}
		$paths = preg_split( '/[\r\n]+/', (string) ( $in['allow_paths'] ?? '' ) );
		$out['allow_paths'] = array();
		foreach ( $paths as $p ) {
			$p = trim( $p );
			if ( '' !== $p && '/' === $p[0] ) {
				$out['allow_paths'][] = sanitize_text_field( $p );
			}
		}
		foreach ( array( 'gate_feeds', 'gate_rest', 'log_humans', 'discovery', 'publish_contact' ) as $flag ) {
			$out[ $flag ] = empty( $in[ $flag ] ) ? 0 : 1;
		}
		$hdr = strtolower( sanitize_text_field( (string) ( $in['trust_proxy_header'] ?? '' ) ) );
		$out['trust_proxy_header'] = in_array( $hdr, array( '', 'cf-connecting-ip', 'x-forwarded-for', 'x-real-ip', 'true-client-ip' ), true ) ? $hdr : '';
		$out['retention_days']     = max( 1, min( 3650, (int) ( $in['retention_days'] ?? $d['retention_days'] ) ) );
		$out['mpp_secret']         = ! empty( $cur['mpp_secret'] ) ? $cur['mpp_secret'] : wp_generate_password( 48, true, true );
		return $out;
	}

	// ── Pages ───────────────────────────────────────────────────────────────

	private static function usd( $units ) {
		return '$' . number_format( FMPPC_X402::units_to_usd( (int) $units ), 4 );
	}

	private static function tx_link( $tx ) {
		if ( '' === $tx ) {
			return '';
		}
		return '<a href="' . esc_url( 'https://basescan.org/tx/' . $tx ) . '" target="_blank" rel="noopener"><code>' . esc_html( substr( $tx, 0, 10 ) . '…' . substr( $tx, -6 ) ) . '</code></a>';
	}

	public function dashboard() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$s      = FMPPC::settings();
		$days   = isset( $_GET['days'] ) ? max( 1, min( 365, (int) $_GET['days'] ) ) : 7; // phpcs:ignore WordPress.Security.NonceVerification
		$t      = FMPPC_Log::totals( $days );
		$t1     = FMPPC_Log::totals( 1 );
		$bots   = FMPPC_Log::by_bot( $days );
		$cls    = FMPPC_Log::by_class( $days );
		$paths  = FMPPC_Log::top_paths( $days );
		$daily  = FMPPC_Log::daily( $days );
		$settle = FMPPC_Log::settlements( 25 );
		$recent = FMPPC_Log::recent( 40 );
		$would  = 0;
		global $wpdb;
		$tbl   = FMPPC_Log::table();
		$would = (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(amount_units) FROM $tbl WHERE status='monitor' AND ts >= %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
		$max   = 1;
		foreach ( $daily as $d ) {
			$max = max( $max, (int) $d['bot_hits'] );
		}
		$export = wp_nonce_url( admin_url( 'admin-post.php?action=fmppc_export&days=' . $days ), 'fmppc_export' );
		?>
		<div class="wrap fmppc">
			<h1><?php esc_html_e( 'Pay-per-Crawl: bot analytics', 'forgemesh-pay-per-crawl' ); ?></h1>
			<p class="fmppc-range">
				<?php foreach ( array( 1, 7, 30, 90 ) as $r ) : ?>
					<a class="button <?php echo $r === $days ? 'button-primary' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=fmppc&days=' . $r ) ); ?>"><?php echo esc_html( 1 === $r ? __( 'Today', 'forgemesh-pay-per-crawl' ) : sprintf( /* translators: days */ __( '%d days', 'forgemesh-pay-per-crawl' ), $r ) ); ?></a>
				<?php endforeach; ?>
				<a class="button" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( 'Export CSV', 'forgemesh-pay-per-crawl' ); ?></a>
				<span class="fmppc-mode fmppc-mode-<?php echo esc_attr( $s['mode'] ); ?>"><?php echo 'gate' === $s['mode'] ? esc_html__( 'Charging', 'forgemesh-pay-per-crawl' ) : esc_html__( 'Monitor only', 'forgemesh-pay-per-crawl' ); ?></span>
			</p>

			<div class="fmppc-tiles">
				<div class="fmppc-tile"><span><?php esc_html_e( 'Bot hits', 'forgemesh-pay-per-crawl' ); ?></span><strong><?php echo esc_html( number_format_i18n( $t['bot_hits'] ) ); ?></strong><em><?php echo esc_html( sprintf( /* translators: count */ __( '%s today', 'forgemesh-pay-per-crawl' ), number_format_i18n( $t1['bot_hits'] ) ) ); ?></em></div>
				<div class="fmppc-tile"><span><?php esc_html_e( 'Distinct bot sources', 'forgemesh-pay-per-crawl' ); ?></span><strong><?php echo esc_html( number_format_i18n( $t['bot_sources'] ) ); ?></strong><em><?php esc_html_e( 'by hashed IP', 'forgemesh-pay-per-crawl' ); ?></em></div>
				<div class="fmppc-tile"><span><?php esc_html_e( 'Paid fetches', 'forgemesh-pay-per-crawl' ); ?></span><strong><?php echo esc_html( number_format_i18n( $t['paid'] ) ); ?></strong><em><?php echo esc_html( sprintf( /* translators: count */ __( '%s challenged, unpaid', 'forgemesh-pay-per-crawl' ), number_format_i18n( $t['unpaid'] ) ) ); ?></em></div>
				<div class="fmppc-tile fmppc-tile-rev"><span><?php esc_html_e( 'Revenue (USDC)', 'forgemesh-pay-per-crawl' ); ?></span><strong><?php echo esc_html( self::usd( $t['revenue_units'] ) ); ?></strong><em><?php echo 'monitor' === $s['mode'] ? esc_html( sprintf( /* translators: amount */ __( 'would have been %s at the current price', 'forgemesh-pay-per-crawl' ), self::usd( $would ) ) ) : esc_html( sprintf( /* translators: count */ __( '%s rejected', 'forgemesh-pay-per-crawl' ), number_format_i18n( $t['rejected'] ) ) ); ?></em></div>
			</div>

			<h2><?php esc_html_e( 'Bot hits per day', 'forgemesh-pay-per-crawl' ); ?></h2>
			<div class="fmppc-chart">
				<?php foreach ( $daily as $d ) : ?>
					<div class="fmppc-bar" title="<?php echo esc_attr( $d['day'] . ': ' . $d['bot_hits'] . ' bot, ' . $d['human_hits'] . ' human, ' . $d['paid'] . ' paid' ); ?>">
						<div class="fmppc-bar-fill" style="height:<?php echo esc_attr( (int) round( 100 * (int) $d['bot_hits'] / $max ) ); ?>%"></div>
						<div class="fmppc-bar-paid" style="height:<?php echo esc_attr( (int) round( 100 * (int) $d['paid'] / $max ) ); ?>%"></div>
						<label><?php echo esc_html( substr( $d['day'], 5 ) ); ?></label>
					</div>
				<?php endforeach; ?>
				<?php if ( ! $daily ) : ?><p><?php esc_html_e( 'No traffic logged yet.', 'forgemesh-pay-per-crawl' ); ?></p><?php endif; ?>
			</div>

			<div class="fmppc-cols">
			<div>
			<h2><?php esc_html_e( 'Who is crawling', 'forgemesh-pay-per-crawl' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Bot', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Class', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Hits', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Sources', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Paid', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Revenue', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Last seen', 'forgemesh-pay-per-crawl' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $bots as $b ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $b['bot_name'] ? $b['bot_name'] : __( '(unnamed)', 'forgemesh-pay-per-crawl' ) ); ?></strong><?php if ( '1' === (string) $b['verified'] ) : ?> <span class="fmppc-badge fmppc-ok" title="<?php esc_attr_e( 'Reverse DNS verified', 'forgemesh-pay-per-crawl' ); ?>">✓</span><?php endif; ?></td>
						<td><span class="fmppc-class fmppc-class-<?php echo esc_attr( $b['ua_class'] ); ?>"><?php echo esc_html( $b['ua_class'] ); ?></span></td>
						<td><?php echo esc_html( number_format_i18n( $b['hits'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $b['sources'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $b['paid'] ) ); ?></td>
						<td><?php echo esc_html( self::usd( $b['revenue_units'] ) ); ?></td>
						<td><?php echo esc_html( human_time_diff( strtotime( $b['last_seen'] . ' UTC' ) ) ); ?> <?php esc_html_e( 'ago', 'forgemesh-pay-per-crawl' ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $bots ) : ?><tr><td colspan="7"><?php esc_html_e( 'No bots seen in this range.', 'forgemesh-pay-per-crawl' ); ?></td></tr><?php endif; ?>
				</tbody>
			</table>
			</div>
			<div>
			<h2><?php esc_html_e( 'By class', 'forgemesh-pay-per-crawl' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Class', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Hits', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Paid', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Revenue', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Policy', 'forgemesh-pay-per-crawl' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $cls as $c ) : ?>
					<tr><td><span class="fmppc-class fmppc-class-<?php echo esc_attr( $c['ua_class'] ); ?>"><?php echo esc_html( $c['ua_class'] ); ?></span></td><td><?php echo esc_html( number_format_i18n( $c['hits'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $c['paid'] ) ); ?></td><td><?php echo esc_html( self::usd( $c['revenue_units'] ) ); ?></td><td><?php echo esc_html( 'human' === $c['ua_class'] ? 'free' : ( $s['class_policy'][ $c['ua_class'] ] ?? 'paid' ) ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<h2><?php esc_html_e( 'Most crawled paths', 'forgemesh-pay-per-crawl' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Path', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Bot hits', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Paid', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Revenue', 'forgemesh-pay-per-crawl' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $paths as $p ) : ?>
					<tr><td><code><?php echo esc_html( $p['path'] ); ?></code></td><td><?php echo esc_html( number_format_i18n( $p['hits'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $p['paid'] ) ); ?></td><td><?php echo esc_html( self::usd( $p['revenue_units'] ) ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			</div>

			<h2><?php esc_html_e( 'Settlements', 'forgemesh-pay-per-crawl' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'When (UTC)', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Bot', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Path', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Rail', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Amount', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Payer', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Transaction', 'forgemesh-pay-per-crawl' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $settle as $r ) : ?>
					<tr><td><?php echo esc_html( $r['ts'] ); ?></td><td><?php echo esc_html( $r['bot_name'] ); ?></td><td><code><?php echo esc_html( $r['path'] ); ?></code></td><td><?php echo esc_html( $r['rail'] ); ?></td><td><?php echo esc_html( self::usd( $r['amount_units'] ) ); ?></td><td><code><?php echo esc_html( $r['payer'] ? substr( $r['payer'], 0, 8 ) . '…' : '' ); ?></code></td><td><?php echo wp_kses_post( self::tx_link( $r['tx_hash'] ) ); ?></td></tr>
				<?php endforeach; ?>
				<?php if ( ! $settle ) : ?><tr><td colspan="7"><?php esc_html_e( 'No settlements yet.', 'forgemesh-pay-per-crawl' ); ?></td></tr><?php endif; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Recent bot requests', 'forgemesh-pay-per-crawl' ); ?></h2>
			<table class="widefat striped fmppc-recent">
				<thead><tr><th><?php esc_html_e( 'When (UTC)', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Bot', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Class', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Request', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Outcome', 'forgemesh-pay-per-crawl' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $recent as $r ) : ?>
					<tr><td><?php echo esc_html( $r['ts'] ); ?></td><td><?php echo esc_html( $r['bot_name'] ); ?></td><td><span class="fmppc-class fmppc-class-<?php echo esc_attr( $r['ua_class'] ); ?>"><?php echo esc_html( $r['ua_class'] ); ?></span></td><td><code><?php echo esc_html( $r['method'] . ' ' . $r['path'] ); ?></code></td><td><span class="fmppc-status fmppc-status-<?php echo esc_attr( $r['status'] ); ?>"><?php echo esc_html( $r['status'] ); ?></span> <?php echo esc_html( $r['reason'] ); ?> <?php echo wp_kses_post( self::tx_link( $r['tx_hash'] ) ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'IPs are never stored: sources are counted by a daily-salted hash. Humans are logged as counts only.', 'forgemesh-pay-per-crawl' ); ?></p>
		</div>
		<?php
	}

	public function settings_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$s = FMPPC::settings();
		$o = get_option( FMPPC::OPTION, array() );
		$o = array_merge( FMPPC::defaults(), is_array( $o ) ? $o : array() );
		$catalog = FMPPC_Classifier::catalog();
		?>
		<div class="wrap fmppc">
			<h1><?php esc_html_e( 'Pay-per-Crawl settings', 'forgemesh-pay-per-crawl' ); ?></h1>
			<?php settings_errors( 'fmppc' ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'fmppc' ); ?>
				<h2><?php esc_html_e( '1. Payout', 'forgemesh-pay-per-crawl' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><label for="fmppc_pay_to"><?php esc_html_e( 'Payout wallet', 'forgemesh-pay-per-crawl' ); ?></label></th>
						<td><input type="text" class="regular-text code" id="fmppc_pay_to" name="<?php echo esc_attr( FMPPC::OPTION ); ?>[pay_to]" value="<?php echo esc_attr( $o['pay_to'] ); ?>" placeholder="0x…" pattern="0x[0-9a-fA-F]{40}">
						<p class="description"><?php esc_html_e( 'An Ethereum-style address on Base. Every payment is sent here directly by the payer; nothing is held by anyone else. Use a wallet you control (Coinbase Wallet, MetaMask, Rabby, a hardware wallet).', 'forgemesh-pay-per-crawl' ); ?></p></td></tr>
					<tr><th><label for="fmppc_price"><?php esc_html_e( 'Price per page (USD)', 'forgemesh-pay-per-crawl' ); ?></label></th>
						<td><input type="number" step="0.001" min="0.005" max="100" id="fmppc_price" name="<?php echo esc_attr( FMPPC::OPTION ); ?>[price_usd]" value="<?php echo esc_attr( $o['price_usd'] ); ?>">
						<p class="description"><?php esc_html_e( 'Charged in USDC per page fetch. $0.005 is the floor; $0.01 is a sensible start.', 'forgemesh-pay-per-crawl' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Mode', 'forgemesh-pay-per-crawl' ); ?></th>
						<td><label><input type="radio" name="<?php echo esc_attr( FMPPC::OPTION ); ?>[mode]" value="monitor" <?php checked( 'monitor', $o['mode'] ); ?>> <?php esc_html_e( 'Monitor: log every bot and what it would have paid, charge nothing', 'forgemesh-pay-per-crawl' ); ?></label><br>
						<label><input type="radio" name="<?php echo esc_attr( FMPPC::OPTION ); ?>[mode]" value="gate" <?php checked( 'gate', $o['mode'] ); ?>> <?php esc_html_e( 'Charge: answer 402 Payment Required to paid classes until they pay', 'forgemesh-pay-per-crawl' ); ?></label>
						<?php if ( 'gate' === $o['mode'] && 'gate' !== $s['mode'] ) : ?><p class="description" style="color:#b32d2e"><?php esc_html_e( 'Charge is selected but no valid wallet is set, so the plugin is running in monitor mode.', 'forgemesh-pay-per-crawl' ); ?></p><?php endif; ?></td></tr>
				</table>

				<h2><?php esc_html_e( '2. Who pays', 'forgemesh-pay-per-crawl' ); ?></h2>
				<p><?php esc_html_e( 'Humans always read free. Verified search engines are checked by reverse DNS; a crawler that claims to be Googlebot and fails the check is treated as automation.', 'forgemesh-pay-per-crawl' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					$labels = array(
						'search'     => __( 'Search engines (Googlebot, Bingbot, DuckDuckBot, Applebot…)', 'forgemesh-pay-per-crawl' ),
						'social'     => __( 'Social link previews (Facebook, X, Slack, Discord, iMessage…)', 'forgemesh-pay-per-crawl' ),
						'monitor'    => __( 'Uptime monitors, feed readers, Internet Archive', 'forgemesh-pay-per-crawl' ),
						'ai'         => __( 'AI crawlers and assistants (GPTBot, ClaudeBot, PerplexityBot, Bytespider, CCBot…)', 'forgemesh-pay-per-crawl' ),
						'seo'        => __( 'SEO crawlers (Ahrefs, Semrush, Majestic…)', 'forgemesh-pay-per-crawl' ),
						'automation' => __( 'Scripts and tools (curl, python, Go, headless browsers)', 'forgemesh-pay-per-crawl' ),
						'bot'        => __( 'Other bots (unknown User-Agents that say bot/crawler/spider)', 'forgemesh-pay-per-crawl' ),
					);
					foreach ( $labels as $cls => $label ) :
						$v = $o['class_policy'][ $cls ] ?? 'paid';
						?>
						<tr><th><?php echo esc_html( $label ); ?></th><td>
							<select name="<?php echo esc_attr( FMPPC::OPTION ); ?>[class_policy][<?php echo esc_attr( $cls ); ?>]">
								<option value="free" <?php selected( 'free', $v ); ?>><?php esc_html_e( 'Free', 'forgemesh-pay-per-crawl' ); ?></option>
								<option value="paid" <?php selected( 'paid', $v ); ?>><?php esc_html_e( 'Paid', 'forgemesh-pay-per-crawl' ); ?></option>
								<option value="block" <?php selected( 'block', $v ); ?>><?php esc_html_e( 'Block (403)', 'forgemesh-pay-per-crawl' ); ?></option>
							</select></td></tr>
					<?php endforeach; ?>
				</table>
				<details><summary><?php esc_html_e( 'Per-bot overrides', 'forgemesh-pay-per-crawl' ); ?></summary>
				<table class="widefat striped fmppc-overrides"><thead><tr><th><?php esc_html_e( 'Bot', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Class', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Policy', 'forgemesh-pay-per-crawl' ); ?></th></tr></thead><tbody>
				<?php foreach ( $catalog as $e ) :
					$v = $o['bot_overrides'][ $e['name'] ] ?? '';
					?>
					<tr><td><?php echo esc_html( $e['name'] ); ?></td><td><?php echo esc_html( $e['class'] ); ?></td><td>
						<select name="<?php echo esc_attr( FMPPC::OPTION ); ?>[bot_overrides][<?php echo esc_attr( $e['name'] ); ?>]">
							<option value="" <?php selected( '', $v ); ?>><?php esc_html_e( 'Class default', 'forgemesh-pay-per-crawl' ); ?></option>
							<option value="free" <?php selected( 'free', $v ); ?>><?php esc_html_e( 'Free', 'forgemesh-pay-per-crawl' ); ?></option>
							<option value="paid" <?php selected( 'paid', $v ); ?>><?php esc_html_e( 'Paid', 'forgemesh-pay-per-crawl' ); ?></option>
							<option value="block" <?php selected( 'block', $v ); ?>><?php esc_html_e( 'Block', 'forgemesh-pay-per-crawl' ); ?></option>
						</select></td></tr>
				<?php endforeach; ?>
				</tbody></table></details>

				<h2><?php esc_html_e( '3. Scope', 'forgemesh-pay-per-crawl' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><label for="fmppc_allow"><?php esc_html_e( 'Always-free paths', 'forgemesh-pay-per-crawl' ); ?></label></th>
						<td><textarea id="fmppc_allow" class="large-text code" rows="3" name="<?php echo esc_attr( FMPPC::OPTION ); ?>[allow_paths]"><?php echo esc_textarea( implode( "\n", (array) $o['allow_paths'] ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One path prefix per line, e.g. /pricing or /api/public. Login, admin, robots.txt, sitemaps and the discovery files are always free.', 'forgemesh-pay-per-crawl' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Also gate', 'forgemesh-pay-per-crawl' ); ?></th><td>
						<label><input type="checkbox" name="<?php echo esc_attr( FMPPC::OPTION ); ?>[gate_feeds]" value="1" <?php checked( 1, (int) $o['gate_feeds'] ); ?>> <?php esc_html_e( 'RSS / Atom feeds', 'forgemesh-pay-per-crawl' ); ?></label><br>
						<label><input type="checkbox" name="<?php echo esc_attr( FMPPC::OPTION ); ?>[gate_rest]" value="1" <?php checked( 1, (int) $o['gate_rest'] ); ?>> <?php esc_html_e( 'REST API (/wp-json) for anonymous requests', 'forgemesh-pay-per-crawl' ); ?></label></td></tr>
					<tr><th><?php esc_html_e( 'Discovery files', 'forgemesh-pay-per-crawl' ); ?></th><td>
						<label><input type="checkbox" name="<?php echo esc_attr( FMPPC::OPTION ); ?>[discovery]" value="1" <?php checked( 1, (int) $o['discovery'] ); ?>> <?php esc_html_e( 'Serve /.well-known/x402.json, /llms.txt and /agents.txt so paying agents and x402 indexes can find this site', 'forgemesh-pay-per-crawl' ); ?></label><br>
						<label><input type="checkbox" name="<?php echo esc_attr( FMPPC::OPTION ); ?>[publish_contact]" value="1" <?php checked( 1, (int) $o['publish_contact'] ); ?>> <?php esc_html_e( 'Include the site admin email as the contact in the manifest', 'forgemesh-pay-per-crawl' ); ?></label></td></tr>
					<tr><th><?php esc_html_e( 'Logging', 'forgemesh-pay-per-crawl' ); ?></th><td>
						<label><input type="checkbox" name="<?php echo esc_attr( FMPPC::OPTION ); ?>[log_humans]" value="1" <?php checked( 1, (int) $o['log_humans'] ); ?>> <?php esc_html_e( 'Count human page views too (for the bot-vs-human ratio)', 'forgemesh-pay-per-crawl' ); ?></label><br>
						<label><?php esc_html_e( 'Keep rows for', 'forgemesh-pay-per-crawl' ); ?> <input type="number" min="1" max="3650" name="<?php echo esc_attr( FMPPC::OPTION ); ?>[retention_days]" value="<?php echo esc_attr( $o['retention_days'] ); ?>" style="width:5em"> <?php esc_html_e( 'days', 'forgemesh-pay-per-crawl' ); ?></label></td></tr>
					<tr><th><label for="fmppc_proxy"><?php esc_html_e( 'Client IP header', 'forgemesh-pay-per-crawl' ); ?></label></th><td>
						<select id="fmppc_proxy" name="<?php echo esc_attr( FMPPC::OPTION ); ?>[trust_proxy_header]">
							<?php foreach ( array( '' => __( 'None (direct connection)', 'forgemesh-pay-per-crawl' ), 'cf-connecting-ip' => 'CF-Connecting-IP (Cloudflare)', 'x-forwarded-for' => 'X-Forwarded-For', 'x-real-ip' => 'X-Real-IP', 'true-client-ip' => 'True-Client-IP' ) as $k => $lbl ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $o['trust_proxy_header'] ); ?>><?php echo esc_html( $lbl ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Only set this if your site sits behind a proxy or CDN that sets the header; it is used for search-engine verification and source counting.', 'forgemesh-pay-per-crawl' ); ?></p></td></tr>
				</table>

				<h2><?php esc_html_e( '4. Facilitator (advanced)', 'forgemesh-pay-per-crawl' ); ?></h2>
				<p><?php esc_html_e( 'The facilitator verifies the payer\'s signed USDC authorization and submits it on-chain. It never holds funds. PayAI is free and needs no account; you can point the plugin at any x402 facilitator instead.', 'forgemesh-pay-per-crawl' ); ?></p>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'Facilitator', 'forgemesh-pay-per-crawl' ); ?></th><td>
						<label><input type="radio" name="<?php echo esc_attr( FMPPC::OPTION ); ?>[facilitator]" value="payai" <?php checked( 'payai', $o['facilitator'] ); ?>> PayAI (<code>facilitator.payai.network</code>)</label><br>
						<label><input type="radio" name="<?php echo esc_attr( FMPPC::OPTION ); ?>[facilitator]" value="custom" <?php checked( 'custom', $o['facilitator'] ); ?>> <?php esc_html_e( 'Custom URL', 'forgemesh-pay-per-crawl' ); ?></label>
						<input type="url" class="regular-text code" name="<?php echo esc_attr( FMPPC::OPTION ); ?>[facilitator_url]" value="<?php echo esc_attr( $o['facilitator_url'] ); ?>" placeholder="https://">
						<br><label><?php esc_html_e( 'Authorization header (optional)', 'forgemesh-pay-per-crawl' ); ?> <input type="text" class="regular-text code" name="<?php echo esc_attr( FMPPC::OPTION ); ?>[facilitator_auth]" value="<?php echo esc_attr( $o['facilitator_auth'] ); ?>" placeholder="Bearer …"></label></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<hr>
			<h2><?php esc_html_e( 'Get listed', 'forgemesh-pay-per-crawl' ); ?></h2>
			<p><?php esc_html_e( 'x402 indexes pick this site up from its manifest once a first payment settles. To be listed sooner, submit the site to the ForgeMesh x402 Verified index (free by pull request, or a $0.05 paid submit).', 'forgemesh-pay-per-crawl' ); ?>
			<a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( 'https://forgemesh.io/verified?submit=' . rawurlencode( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'List on ForgeMesh', 'forgemesh-pay-per-crawl' ); ?></a></p>
			<p class="description"><?php esc_html_e( 'The plugin itself never contacts ForgeMesh. Its only outbound requests are to the facilitator you chose, and only when a payment is presented.', 'forgemesh-pay-per-crawl' ); ?></p>
		</div>
		<?php
	}

	public function export() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'forbidden' );
		}
		check_admin_referer( 'fmppc_export' );
		$days = isset( $_GET['days'] ) ? max( 1, min( 365, (int) $_GET['days'] ) ) : 30;
		$rows = FMPPC_Log::export_rows( $days );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="pay-per-crawl-' . $days . 'd.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, array( 'ts', 'class', 'bot', 'verified', 'method', 'path', 'status', 'rail', 'amount_usd', 'tx_hash', 'payer', 'reason', 'source_hash', 'user_agent' ) );
		foreach ( $rows as $r ) {
			fputcsv( $out, array( $r['ts'], $r['ua_class'], $r['bot_name'], $r['verified'], $r['method'], $r['path'], $r['status'], $r['rail'], FMPPC_X402::units_to_usd( $r['amount_units'] ), $r['tx_hash'], $r['payer'], $r['reason'], $r['ip_hash'], $r['ua'] ) );
		}
		exit;
	}
}
