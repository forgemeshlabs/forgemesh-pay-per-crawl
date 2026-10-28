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
		add_submenu_page( 'fmppc', __( 'Bot directory', 'forgemesh-pay-per-crawl' ), __( 'Bots', 'forgemesh-pay-per-crawl' ), self::CAP, 'fmppc-bots', array( new FMPPC_Bots_Page(), 'page' ) );
		add_submenu_page( 'fmppc', __( 'AI Crawler Audit', 'forgemesh-pay-per-crawl' ), __( 'Audit', 'forgemesh-pay-per-crawl' ), self::CAP, 'fmppc-audit', array( new FMPPC_Audit_Page(), 'page' ) );
	}

	public function assets( $hook ) {
		if ( false === strpos( $hook, 'fmppc' ) ) {
			return;
		}
		// Version by file time so CDN and browser caches (Cloudflare keeps CSS 4h) refresh on every change.
		$ver = function ( $f ) { $t = @filemtime( FMPPC_DIR . $f ); return FMPPC_VERSION . ( $t ? '.' . $t : '' ); };
		wp_enqueue_style( 'fmppc-admin', FMPPC_URL . 'assets/admin.css', array(), $ver( 'assets/admin.css' ) );
		if ( false !== strpos( $hook, 'fmppc-settings' ) || false !== strpos( $hook, 'fmppc-bots' ) ) {
			wp_enqueue_script( 'fmppc-admin', FMPPC_URL . 'assets/admin.js', array(), $ver( 'assets/admin.js' ), true );
			if ( false !== strpos( $hook, 'fmppc-settings' ) ) {
				wp_localize_script( 'fmppc-admin', 'fmppcRules', self::rule_options() );
			}
		}
	}

	/**
	 * Option sets for the pricing-rules editor. Bots = shipped catalog + Pro
	 * crawler feed (merged by FMPPC_Classifier::catalog) + every name already
	 * seen in this site's ledger, so the list grows as new crawlers appear.
	 */
	public static function rule_options() {
		$bots = array();
		foreach ( FMPPC_Classifier::catalog() as $e ) {
			$bots[ $e['name'] ] = $e['name'] . ' (' . $e['class'] . ')';
		}
		foreach ( FMPPC_Log::by_bot( 365, 300 ) as $b ) {
			if ( '' !== $b['bot_name'] && 'human' !== $b['ua_class'] && ! isset( $bots[ $b['bot_name'] ] ) ) {
				$bots[ $b['bot_name'] ] = $b['bot_name'] . ' (' . $b['ua_class'] . ', ' . sprintf( /* translators: hits */ __( 'seen %s times', 'forgemesh-pay-per-crawl' ), number_format_i18n( $b['hits'] ) ) . ')';
			}
		}
		ksort( $bots, SORT_NATURAL | SORT_FLAG_CASE );
		$opt = function ( $pairs ) { $o = array(); foreach ( $pairs as $v => $l ) { $o[] = array( 'value' => (string) $v, 'label' => (string) $l ); } return $o; };
		$pts = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $p ) {
			if ( 'attachment' !== $p->name ) {
				$pts[ $p->name ] = $p->labels->name . ' (' . $p->name . ')';
			}
		}
		$terms = function ( $tax ) {
			$t = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'number' => 500, 'orderby' => 'count', 'order' => 'DESC' ) );
			$o = array();
			foreach ( ( is_array( $t ) ? $t : array() ) as $x ) { $o[ $x->slug ] = $x->name . ' (' . $x->count . ')'; }
			return $o;
		};
		$purposes = array();
		foreach ( array( 'training', 'ai-search', 'user-fetch', 'agent-retrieval', 'search-index', 'seo', 'archive', 'scraping' ) as $p ) {
			$purposes[ $p ] = FMPPC_Audit::purpose_label( $p ) . ' (' . $p . ')';
		}
		$classes = array();
		foreach ( FMPPC_Classifier::classes() as $c ) { $classes[ $c ] = $c; }
		return array(
			'options'      => array( 'post_type' => $opt( $pts ), 'category' => $opt( $terms( 'category' ) ), 'tag' => $opt( $terms( 'post_tag' ) ), 'bot' => $opt( $bots ), 'purpose' => $opt( $purposes ), 'class' => $opt( $classes ) ),
			'placeholders' => array( 'path' => '/guides/  (prefix)   or   /about  (exact)', 'bot' => __( 'exact user-agent name', 'forgemesh-pay-per-crawl' ) ),
			'i18n'         => array( 'other' => __( 'Other (type a name)…', 'forgemesh-pay-per-crawl' ), 'missing' => __( '(not found on this site)', 'forgemesh-pay-per-crawl' ) ),
		);
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
		$out['price_rules'] = FMPPC_Rules::sanitize( $in['price_rules'] ?? array() );
		foreach ( array( 'gate_feeds', 'gate_rest', 'log_humans', 'discovery', 'publish_contact' ) as $flag ) {
			$out[ $flag ] = empty( $in[ $flag ] ) ? 0 : 1;
		}
		$hdr = strtolower( sanitize_text_field( (string) ( $in['trust_proxy_header'] ?? '' ) ) );
		$out['trust_proxy_header'] = in_array( $hdr, array( '', 'cf-connecting-ip', 'x-forwarded-for', 'x-real-ip', 'true-client-ip' ), true ) ? $hdr : '';
		$out['retention_days']     = max( 1, min( 3650, (int) ( $in['retention_days'] ?? $d['retention_days'] ) ) );
		$out['mpp_secret']         = ! empty( $cur['mpp_secret'] ) ? $cur['mpp_secret'] : wp_generate_password( 48, true, true );
		$key                       = trim( (string) ( $in['pro_key'] ?? '' ) );
		$out['pro_key']            = preg_match( '/^fmppc_[A-Za-z0-9_\-]{20,}$/', $key ) ? $key : '';
		if ( '' !== $key && '' === $out['pro_key'] ) {
			add_settings_error( 'fmppc', 'pro_key', __( 'That does not look like a Pro license key (they start with fmppc_).', 'forgemesh-pay-per-crawl' ) );
		}
		$out['pro_listed'] = empty( $in['pro_listed'] ) ? 0 : 1;
		if ( '' !== $out['pro_key'] ) {
			$probe = new FMPPC_Pro( array_merge( $out, array( 'pro_key' => $out['pro_key'] ) ) );
			$r     = $probe->verify();
			if ( empty( $r['ok'] ) ) {
				add_settings_error( 'fmppc', 'pro_verify', sprintf( /* translators: reason */ __( 'Pro key saved but not verified: %s. The plugin will retry hourly.', 'forgemesh-pay-per-crawl' ), (string) ( $r['status'] ?? $r['error'] ?? 'unknown' ) ), 'warning' );
			} else {
				add_settings_error( 'fmppc', 'pro_ok', __( 'Pro license verified. First sync runs within the hour.', 'forgemesh-pay-per-crawl' ), 'success' );
			}
		} else {
			delete_option( FMPPC_Pro::OPT_STATE );
			delete_option( FMPPC_Pro::OPT_INTEL );
			FMPPC_Pro::unschedule();
		}
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
		$would = (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(amount_units) FROM {$wpdb->prefix}fmppc_hits WHERE status='monitor' AND ts >= %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
		// Pad the range so every day in view gets a bar (a lone day is otherwise one full-width block).
		$by_day = array();
		foreach ( $daily as $d ) {
			$by_day[ $d['day'] ] = $d;
		}
		$daily = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$day     = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			$daily[] = isset( $by_day[ $day ] ) ? $by_day[ $day ] : array( 'day' => $day, 'bot_hits' => 0, 'human_hits' => 0, 'paid' => 0, 'revenue_units' => 0 );
		}
		$max     = 1;
		$any     = false;
		foreach ( $daily as $d ) {
			$max = max( $max, (int) $d['bot_hits'] );
			$any = $any || (int) $d['bot_hits'] > 0 || (int) $d['human_hits'] > 0;
		}
		$label_every = $days > 31 ? (int) ceil( $days / 15 ) : 1;
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

			<?php $pst = FMPPC_Pro::state(); if ( FMPPC_Pro::enabled( $s ) ) : ?>
				<p class="fmppc-pro"><?php echo ! empty( $pst['ok'] ) ? '<strong>Pro</strong> ' . esc_html__( 'active', 'forgemesh-pay-per-crawl' ) : '<strong>Pro</strong> ' . esc_html( (string) ( $pst['status'] ?? 'pending' ) ); ?> · <a href="<?php echo esc_url( ! empty( $pst['dashboard'] ) ? $pst['dashboard'] : FMPPC_Pro::BASE . '/wp/dashboard' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open hosted dashboard', 'forgemesh-pay-per-crawl' ); ?></a><?php if ( ! empty( $pst['synced_at'] ) ) : ?> · <?php echo esc_html( sprintf( /* translators: time ago */ __( 'synced %s ago', 'forgemesh-pay-per-crawl' ), human_time_diff( (int) $pst['synced_at'] ) ) ); ?><?php endif; ?></p>
			<?php else : ?>
				<p class="fmppc-catalog description"><?php echo esc_html( sprintf( /* translators: 1 count, 2 date */ __( 'Bot list: %1$d known agents, built in on %2$s. New AI crawlers appear every few weeks; until this plugin is updated they are classified by their generic user agent instead of by name. Pro refreshes the list daily.', 'forgemesh-pay-per-crawl' ), count( FMPPC_Classifier::shipped() ), FMPPC_Classifier::SHIPPED_AT ) ); ?></p>
				<p class="fmppc-pro fmppc-pro-upsell"><?php esc_html_e( 'Want all your sites in one place, a Monday digest, paywall alerts and a bot list that updates itself?', 'forgemesh-pay-per-crawl' ); ?> <a href="<?php echo esc_url( FMPPC_Pro::BASE . '/wp?utm_source=plugin' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Pro is $4.99/month', 'forgemesh-pay-per-crawl' ); ?></a></p>
			<?php endif; ?>
			<div class="fmppc-tiles">
				<div class="fmppc-tile"><span><?php esc_html_e( 'Bot hits', 'forgemesh-pay-per-crawl' ); ?></span><strong><?php echo esc_html( number_format_i18n( $t['bot_hits'] ) ); ?></strong><em><?php echo esc_html( sprintf( /* translators: count */ __( '%s today', 'forgemesh-pay-per-crawl' ), number_format_i18n( $t1['bot_hits'] ) ) ); ?></em></div>
				<div class="fmppc-tile"><span><?php esc_html_e( 'Distinct bot sources', 'forgemesh-pay-per-crawl' ); ?></span><strong><?php echo esc_html( number_format_i18n( $t['bot_sources'] ) ); ?></strong><em><?php esc_html_e( 'by hashed IP', 'forgemesh-pay-per-crawl' ); ?></em></div>
				<div class="fmppc-tile"><span><?php esc_html_e( 'Paid fetches', 'forgemesh-pay-per-crawl' ); ?></span><strong><?php echo esc_html( number_format_i18n( $t['paid'] ) ); ?></strong><em><?php echo esc_html( sprintf( /* translators: count */ __( '%s challenged, unpaid', 'forgemesh-pay-per-crawl' ), number_format_i18n( $t['unpaid'] ) ) ); ?></em></div>
				<div class="fmppc-tile fmppc-tile-rev"><span><?php esc_html_e( 'Revenue (USDC)', 'forgemesh-pay-per-crawl' ); ?></span><strong><?php echo esc_html( self::usd( $t['revenue_units'] ) ); ?></strong><em><?php echo 'monitor' === $s['mode'] ? esc_html( sprintf( /* translators: amount */ __( 'would have been %s at the current price', 'forgemesh-pay-per-crawl' ), self::usd( $would ) ) ) : esc_html( sprintf( /* translators: count */ __( '%s rejected', 'forgemesh-pay-per-crawl' ), number_format_i18n( $t['rejected'] ) ) ); ?></em></div>
			</div>

			<?php $dir_n = count( FMPPC_Classifier::catalog() ); $dir_seen = count( array_filter( $bots, function ( $b ) { return 'human' !== $b['ua_class']; } ) ); ?>
			<a class="fmppc-feature" href="<?php echo esc_url( admin_url( 'admin.php?page=fmppc-bots' ) ); ?>" target="_blank" rel="noopener">
				<span class="fmppc-feature-icon">&#9881;</span>
				<span class="fmppc-feature-text"><strong><?php esc_html_e( 'Bot directory', 'forgemesh-pay-per-crawl' ); ?></strong> <?php echo esc_html( sprintf( /* translators: 1 known, 2 seen */ __( '%1$s crawlers explained, %2$s of them seen on this site. Who runs each one, what it does with your pages, how to verify it, and what you currently do with it. Searchable, grouped, and it grows as new bots appear.', 'forgemesh-pay-per-crawl' ), number_format_i18n( $dir_n ), number_format_i18n( $dir_seen ) ) ); ?></span>
				<span class="fmppc-feature-cta"><?php esc_html_e( 'Open in new tab', 'forgemesh-pay-per-crawl' ); ?> &#8599;</span>
			</a>

			<h2><?php esc_html_e( 'Bot hits per day', 'forgemesh-pay-per-crawl' ); ?> <span class="fmppc-legend"><i class="fmppc-sw fmppc-sw-bot"></i> <?php esc_html_e( 'bot hits', 'forgemesh-pay-per-crawl' ); ?> <i class="fmppc-sw fmppc-sw-paid"></i> <?php esc_html_e( 'paid', 'forgemesh-pay-per-crawl' ); ?> · <?php echo esc_html( sprintf( /* translators: peak count */ __( 'peak %s/day', 'forgemesh-pay-per-crawl' ), number_format_i18n( $max ) ) ); ?></span></h2>
			<div class="fmppc-chart">
				<?php foreach ( $daily as $i => $d ) : ?>
					<div class="fmppc-bar<?php echo (int) $d['bot_hits'] ? '' : ' fmppc-bar-empty'; ?>" title="<?php echo esc_attr( $d['day'] . ': ' . $d['bot_hits'] . ' bot, ' . $d['human_hits'] . ' human, ' . $d['paid'] . ' paid' ); ?>">
						<?php if ( (int) $d['bot_hits'] ) : ?><span class="fmppc-bar-n"><?php echo esc_html( number_format_i18n( (int) $d['bot_hits'] ) ); ?><?php if ( (int) $d['paid'] ) : ?><em><?php echo esc_html( number_format_i18n( (int) $d['paid'] ) ); ?></em><?php endif; ?></span><?php endif; ?>
						<div class="fmppc-bar-fill" style="height:<?php echo esc_attr( (int) round( 100 * (int) $d['bot_hits'] / $max ) ); ?>%"></div>
						<div class="fmppc-bar-paid" style="height:<?php echo esc_attr( (int) round( 100 * (int) $d['paid'] / $max ) ); ?>%"></div>
						<?php if ( 0 === $i % $label_every || $i === $days - 1 ) : ?><label><?php echo esc_html( 1 === $days ? __( 'today', 'forgemesh-pay-per-crawl' ) : substr( $d['day'], 5 ) ); ?></label><?php endif; ?>
					</div>
				<?php endforeach; ?>
				<?php if ( ! $any ) : ?><p class="fmppc-chart-empty"><?php esc_html_e( 'No traffic logged yet.', 'forgemesh-pay-per-crawl' ); ?></p><?php endif; ?>
			</div>

			<div class="fmppc-cols">
			<div>
			<h2><?php esc_html_e( 'Who is crawling', 'forgemesh-pay-per-crawl' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Bot', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Class', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Hits', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Sources', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Paid', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Revenue', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Last seen', 'forgemesh-pay-per-crawl' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $bots as $b ) : ?>
					<tr>
						<td><strong><?php if ( $b['bot_name'] ) : ?><a class="fmppc-botlink" target="_blank" rel="noopener" title="<?php esc_attr_e( 'Open in the bot directory (new tab)', 'forgemesh-pay-per-crawl' ); ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=fmppc-bots#bot-' . sanitize_title( $b['bot_name'] ) ) ); ?>"><?php echo esc_html( $b['bot_name'] ); ?></a><?php else : ?><?php esc_html_e( '(unnamed)', 'forgemesh-pay-per-crawl' ); ?><?php endif; ?></strong><?php if ( '1' === (string) $b['verified'] ) : ?> <span class="fmppc-badge fmppc-ok" title="<?php esc_attr_e( 'Reverse DNS verified', 'forgemesh-pay-per-crawl' ); ?>">✓</span><?php endif; ?></td>
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

				<h2><?php esc_html_e( '2b. Pricing rules', 'forgemesh-pay-per-crawl' ); ?> <span class="fmppc-pro-badge">Pro</span></h2>
				<?php
				$rules_on = FMPPC_Rules::active( $o );
				$pro_on   = FMPPC_Pro::enabled( $o ) && ! empty( FMPPC_Pro::state()['ok'] );
				$rules    = array_values( (array) $o['price_rules'] );
				$slots    = max( 6, count( $rules ) + 3 );
				$pts      = array_values( array_filter( get_post_types( array( 'public' => true ), 'objects' ), function ( $p ) { return 'attachment' !== $p->name; } ) );
				$cats     = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => true, 'number' => 25, 'orderby' => 'count', 'order' => 'DESC' ) );
				$purposes = array( 'training', 'ai-search', 'user-fetch', 'agent-retrieval', 'seo', 'archive', 'scraping' );
				?>
				<p><?php esc_html_e( 'Price or policy by what is being fetched and by who is fetching it. Rules are checked top to bottom; the first match wins. Anything unmatched falls back to the per-bot overrides, the class policy and the site price above. Content rules are advertised as separate entries in the x402 manifest, so indexes see each price group.', 'forgemesh-pay-per-crawl' ); ?></p>
				<?php if ( ! $pro_on ) : ?><p class="description" style="color:#b32d2e"><?php esc_html_e( 'Rules are saved but only enforced while a verified Pro key is present (section 5). Without one the site uses the single price above.', 'forgemesh-pay-per-crawl' ); ?></p><?php endif; ?>
				<p class="description"><a href="<?php echo esc_url( admin_url( 'admin.php?page=fmppc-bots' ) ); ?>" target="_blank" rel="noopener" class="fmppc-popout"><?php esc_html_e( 'What are all these bots? Open the bot directory in a new tab', 'forgemesh-pay-per-crawl' ); ?> &#8599;</a> <?php esc_html_e( 'Pick a type and the next box lists what exists on this site (your post types, categories and tags) or every crawler the plugin knows: the built-in list, the Pro crawler feed, and any bot already seen in your ledger. New crawlers show up here as they appear. Paths are typed by hand.', 'forgemesh-pay-per-crawl' ); ?></p>
				<table class="widefat striped fmppc-rules"><thead><tr><th>#</th><th><?php esc_html_e( 'When', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'is', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'then', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'price (USD)', 'forgemesh-pay-per-crawl' ); ?></th></tr></thead><tbody>
				<?php for ( $i = 0; $i < $slots; $i++ ) :
					$r = $rules[ $i ] ?? array( 'type' => '', 'value' => '', 'action' => 'paid', 'price_usd' => '' );
					$n = esc_attr( FMPPC::OPTION ) . '[price_rules][' . $i . ']';
					?>
					<tr><td><?php echo (int) ( $i + 1 ); ?></td>
					<td><select class="fmppc-rule-type" name="<?php echo $n; // phpcs:ignore ?>[type]"><option value=""><?php esc_html_e( '— unused —', 'forgemesh-pay-per-crawl' ); ?></option>
						<?php foreach ( array( 'post_type' => __( 'post type', 'forgemesh-pay-per-crawl' ), 'category' => __( 'category', 'forgemesh-pay-per-crawl' ), 'tag' => __( 'tag', 'forgemesh-pay-per-crawl' ), 'path' => __( 'path', 'forgemesh-pay-per-crawl' ), 'bot' => __( 'bot name', 'forgemesh-pay-per-crawl' ), 'purpose' => __( 'bot purpose', 'forgemesh-pay-per-crawl' ), 'class' => __( 'bot class', 'forgemesh-pay-per-crawl' ) ) as $k => $lbl ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $r['type'] ); ?>><?php echo esc_html( $lbl ); ?></option>
						<?php endforeach; ?></select></td>
					<td><select class="fmppc-rule-select" style="display:none"></select><input type="text" class="code fmppc-rule-value" name="<?php echo $n; // phpcs:ignore ?>[value]" value="<?php echo esc_attr( $r['value'] ); ?>"></td>
					<td><select class="fmppc-rule-action" name="<?php echo $n; // phpcs:ignore ?>[action]">
						<option value="paid" <?php selected( 'paid', $r['action'] ); ?>><?php esc_html_e( 'charge', 'forgemesh-pay-per-crawl' ); ?></option>
						<option value="free" <?php selected( 'free', $r['action'] ); ?>><?php esc_html_e( 'free', 'forgemesh-pay-per-crawl' ); ?></option>
						<option value="block" <?php selected( 'block', $r['action'] ); ?>><?php esc_html_e( 'block', 'forgemesh-pay-per-crawl' ); ?></option></select></td>
					<td><input type="number" step="0.001" min="0.005" max="100" class="fmppc-rule-price" name="<?php echo $n; // phpcs:ignore ?>[price_usd]" value="<?php echo esc_attr( null === $r['price_usd'] ? '' : $r['price_usd'] ); ?>" placeholder="<?php echo esc_attr( $o['price_usd'] ); ?>" style="width:7em"></td></tr>
				<?php endfor; ?>
				</tbody></table>
				<p class="description"><?php esc_html_e( 'Examples: category recipes → charge 0.05 · post type page → free · bot purpose training → charge 0.10 · bot name Bytespider → block · path /archive/ → charge 0.005. Leave price empty to use the site price. Save with the type set to "unused" to delete a row.', 'forgemesh-pay-per-crawl' ); ?><?php if ( $rules_on ) : ?> <strong><?php echo esc_html( sprintf( /* translators: count */ _n( '%d rule active.', '%d rules active.', count( $rules ), 'forgemesh-pay-per-crawl' ), count( $rules ) ) ); ?></strong><?php endif; ?></p>

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
						<?php
						$seen_hdrs = array();
						foreach ( array( 'cf-connecting-ip' => 'HTTP_CF_CONNECTING_IP', 'true-client-ip' => 'HTTP_TRUE_CLIENT_IP', 'x-real-ip' => 'HTTP_X_REAL_IP', 'x-forwarded-for' => 'HTTP_X_FORWARDED_FOR' ) as $hk => $sv ) {
							if ( ! empty( $_SERVER[ $sv ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- presence check only, value unused.
								$seen_hdrs[] = $hk;
							}
						}
						if ( '' === $o['trust_proxy_header'] && $seen_hdrs ) :
							?>
							<p class="description" style="color:#b32d2e"><?php echo esc_html( sprintf( /* translators: header name */ __( 'This request arrived with a %s header, so your site is behind a proxy or CDN. With "None" selected every visitor looks like it comes from the proxy: real Googlebot fails verification and gets charged, and source counts collapse to one.', 'forgemesh-pay-per-crawl' ), strtoupper( $seen_hdrs[0] ) ) ); ?></p>
						<?php elseif ( '' !== $o['trust_proxy_header'] && ! in_array( $o['trust_proxy_header'], $seen_hdrs, true ) ) : ?>
							<p class="description" style="color:#b32d2e"><?php esc_html_e( 'The selected header was not present on this request. If your proxy does not set it, a crawler can send the header itself and claim any address.', 'forgemesh-pay-per-crawl' ); ?></p>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Why it matters: the plugin verifies search engines by reverse DNS on the client address and counts distinct bot sources by it. Behind Cloudflare or another proxy the connection address is the proxy, not the crawler, so the real address has to be read from a header the proxy adds. Only choose a header your proxy sets itself; otherwise anyone can forge it.', 'forgemesh-pay-per-crawl' ); ?></p>
						<ul class="description" style="margin:0.3em 0 0 1.2em;list-style:disc">
							<li><strong>None</strong> — <?php esc_html_e( 'WordPress is reached directly (no CDN, no reverse proxy). Default.', 'forgemesh-pay-per-crawl' ); ?></li>
							<li><strong>CF-Connecting-IP</strong> — <?php esc_html_e( 'set by Cloudflare on every proxied request. Pick this if your DNS is on Cloudflare with the orange cloud on, or you use a Cloudflare Tunnel.', 'forgemesh-pay-per-crawl' ); ?></li>
							<li><strong>True-Client-IP</strong> — <?php esc_html_e( 'set by Akamai and by Cloudflare Enterprise. Same meaning as CF-Connecting-IP.', 'forgemesh-pay-per-crawl' ); ?></li>
							<li><strong>X-Real-IP</strong> — <?php esc_html_e( 'set by nginx or a load balancer you run yourself (proxy_set_header X-Real-IP $remote_addr). Not set by Cloudflare.', 'forgemesh-pay-per-crawl' ); ?></li>
							<li><strong>X-Forwarded-For</strong> — <?php esc_html_e( 'the generic chain header most hosts and load balancers append to. The plugin uses the right-most address, the one your own proxy added, because a crawler can put anything in the left-most slot.', 'forgemesh-pay-per-crawl' ); ?></li>
						</ul></td></tr>
				</table>

				<h2><?php esc_html_e( '4. Facilitator (advanced)', 'forgemesh-pay-per-crawl' ); ?></h2>
				<p><?php esc_html_e( 'The facilitator verifies the payer\'s signed USDC authorization and submits it on-chain. It never holds funds. PayAI is free and needs no account; you can point the plugin at any x402 facilitator instead.', 'forgemesh-pay-per-crawl' ); ?></p>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'Facilitator', 'forgemesh-pay-per-crawl' ); ?></th><td>
						<label><input type="radio" name="<?php echo esc_attr( FMPPC::OPTION ); ?>[facilitator]" value="payai" <?php checked( 'payai', $o['facilitator'] ); ?>> PayAI (<code>facilitator.payai.network</code>)</label><br>
						<label><input type="radio" name="<?php echo esc_attr( FMPPC::OPTION ); ?>[facilitator]" value="custom" <?php checked( 'custom', $o['facilitator'] ); ?>> <?php esc_html_e( 'Custom URL', 'forgemesh-pay-per-crawl' ); ?></label>
						<input type="url" class="regular-text code" name="<?php echo esc_attr( FMPPC::OPTION ); ?>[facilitator_url]" value="<?php echo esc_attr( $o['facilitator_url'] ); ?>" placeholder="https://">
						<br><label><?php esc_html_e( 'Authorization header (optional)', 'forgemesh-pay-per-crawl' ); ?> <input type="text" class="regular-text code" name="<?php echo esc_attr( FMPPC::OPTION ); ?>[facilitator_auth]" value="<?php echo esc_attr( $o['facilitator_auth'] ); ?>" placeholder="Bearer …"></label>
						<p class="description"><?php esc_html_e( 'Sent as the Authorization header on every verify and settle call to a custom facilitator. Leave it empty for PayAI, which needs no credentials. Use it when the facilitator you run or rent requires a key, for example Coinbase CDP (a Bearer token built from your CDP API key). It is never sent anywhere except the facilitator URL above.', 'forgemesh-pay-per-crawl' ); ?></p></td></tr>
				</table>
				<h2><?php esc_html_e( '5. Pro (optional)', 'forgemesh-pay-per-crawl' ); ?></h2>
				<p><?php esc_html_e( 'The free plugin does all the gating. Pro is for people who want control and visibility on top of it, $4.99/month for up to 5 sites:', 'forgemesh-pay-per-crawl' ); ?></p>
				<ul class="fmppc-pro-list">
					<li><strong><?php esc_html_e( 'Pricing rules', 'forgemesh-pay-per-crawl' ); ?></strong> — <?php esc_html_e( 'different prices for different parts of your site and different bots (section 2b). Charge AI training crawlers more, let one bot in free, block another.', 'forgemesh-pay-per-crawl' ); ?></li>
					<li><strong><?php esc_html_e( 'Being found, like SEO but for bots', 'forgemesh-pay-per-crawl' ); ?></strong> — <?php esc_html_e( 'Search engines find pages through sitemaps. Paying agents find sites through directories that list which pages are for sale and at what price. Pro lists this site in the ForgeMesh directory and keeps the listing current, so agents that can pay know you exist. Without a listing, a paying agent only finds you by accident.', 'forgemesh-pay-per-crawl' ); ?></li>
					<li><strong><?php esc_html_e( 'Up-to-date bot list', 'forgemesh-pay-per-crawl' ); ?></strong> — <?php esc_html_e( 'new crawlers appear every few weeks. The feed names them so they show up in your analytics and rules by name instead of as "unknown script".', 'forgemesh-pay-per-crawl' ); ?></li>
					<li><strong><?php esc_html_e( 'One dashboard, all sites', 'forgemesh-pay-per-crawl' ); ?></strong> — <?php esc_html_e( 'bots, payments and a revenue report with a CSV for your bookkeeper, across every site on the key, plus a Monday email digest.', 'forgemesh-pay-per-crawl' ); ?></li>
					<li><strong><?php esc_html_e( 'A watchdog', 'forgemesh-pay-per-crawl' ); ?></strong> — <?php esc_html_e( 'a daily check that your site still answers bots with the right price and wallet, with an email if it stops.', 'forgemesh-pay-per-crawl' ); ?></li>
				</ul>
				<p><?php esc_html_e( 'Payments from bots never pass through ForgeMesh in any tier; they go straight to your wallet.', 'forgemesh-pay-per-crawl' ); ?>
				<a href="<?php echo esc_url( FMPPC_Pro::BASE . '/wp?utm_source=plugin' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Get a license key', 'forgemesh-pay-per-crawl' ); ?></a></p>
				<?php $pst = FMPPC_Pro::state(); ?>
				<table class="form-table" role="presentation">
					<tr><th><label for="fmppc_pro_key"><?php esc_html_e( 'License key', 'forgemesh-pay-per-crawl' ); ?></label></th>
						<td><input type="text" class="regular-text code" id="fmppc_pro_key" name="<?php echo esc_attr( FMPPC::OPTION ); ?>[pro_key]" value="<?php echo esc_attr( $o['pro_key'] ); ?>" placeholder="fmppc_…" autocomplete="off">
						<?php if ( '' !== $o['pro_key'] ) : ?>
							<p class="description"><?php echo ! empty( $pst['ok'] ) ? '<span style="color:#0b6b2e">&#10003; ' . esc_html( sprintf( /* translators: 1 status, 2 used, 3 limit */ __( 'verified (%1$s), %2$d of %3$d sites', 'forgemesh-pay-per-crawl' ), $pst['status'], $pst['sites_used'], $pst['site_limit'] ) ) . '</span>' : '<span style="color:#b32d2e">' . esc_html( sprintf( /* translators: status */ __( 'not verified: %s', 'forgemesh-pay-per-crawl' ), (string) ( $pst['status'] ?? 'pending' ) ) ) . '</span>'; ?>
							<?php if ( ! empty( $pst['synced_at'] ) ) : ?> · <?php echo esc_html( sprintf( /* translators: time ago */ __( 'last sync %s ago', 'forgemesh-pay-per-crawl' ), human_time_diff( (int) $pst['synced_at'] ) ) ); ?><?php echo ! empty( $pst['sync_error'] ) ? ' (' . esc_html( $pst['sync_error'] ) . ')' : ''; ?><?php endif; ?>
							<?php if ( ! empty( $pst['intel_count'] ) ) : ?> · <?php echo esc_html( sprintf( /* translators: 1 count, 2 version, 3 time ago */ __( 'crawler feed: %1$d agents (%2$s), refreshed %3$s ago', 'forgemesh-pay-per-crawl' ), $pst['intel_count'], $pst['intel_version'], human_time_diff( (int) $pst['intel_at'] ) ) ); ?><?php endif; ?>
							· <a href="<?php echo esc_url( ! empty( $pst['dashboard'] ) ? $pst['dashboard'] : FMPPC_Pro::BASE . '/wp/dashboard' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'hosted dashboard', 'forgemesh-pay-per-crawl' ); ?></a></p>
						<?php endif; ?></td></tr>
					<tr><th><?php esc_html_e( 'Directory', 'forgemesh-pay-per-crawl' ); ?></th><td>
						<label><input type="checkbox" name="<?php echo esc_attr( FMPPC::OPTION ); ?>[pro_listed]" value="1" <?php checked( 1, (int) $o['pro_listed'] ); ?>> <?php esc_html_e( 'List this site in the ForgeMesh directory while it is in Charge mode (Pro)', 'forgemesh-pay-per-crawl' ); ?></label>
						<p class="description"><?php esc_html_e( 'The directory is how agents that can pay discover sites to buy from: the same job a sitemap does for Google, but for bots with wallets. The listing shows your site name, what is for sale and the price. It never shows your visitors or your content.', 'forgemesh-pay-per-crawl' ); ?></p></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<hr>
			<h2><?php esc_html_e( 'Get found by paying agents', 'forgemesh-pay-per-crawl' ); ?></h2>
			<p><?php esc_html_e( 'Think of this as SEO for bots. Your site already publishes a price list for machines (the discovery files in section 3). Directories read those files and tell paying agents where to shop. Some pick you up on their own after a first payment; the ForgeMesh x402 Verified index lists you right away. Submitting is free by pull request, or $0.05 for an automatic submit.', 'forgemesh-pay-per-crawl' ); ?>
			<a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( 'https://forgemesh.io/verified/claim?src=wordpress&host=' . rawurlencode( wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) ); ?>"><?php esc_html_e( 'List on ForgeMesh', 'forgemesh-pay-per-crawl' ); ?></a></p>
			<p class="description"><?php esc_html_e( 'Without a Pro key the plugin never contacts ForgeMesh. Its only outbound requests are to the facilitator you chose, and only when a payment is presented. With a Pro key it also syncs bot counts and settlements to the hosted dashboard once an hour.', 'forgemesh-pay-per-crawl' ); ?></p>
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
		// Bot names, paths and user agents are chosen by the requester: neutralise formula-leading
		// cells so a UA like "=HYPERLINK(...)" cannot execute when the CSV opens in a spreadsheet.
		$safe = function ( $v ) {
			$v = (string) $v;
			return ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) ? "'" . $v : $v;
		};
		fputcsv( $out, array( 'ts', 'class', 'bot', 'verified', 'method', 'path', 'status', 'rail', 'amount_usd', 'tx_hash', 'payer', 'reason', 'source_hash', 'user_agent' ) );
		foreach ( $rows as $r ) {
			fputcsv( $out, array_map( $safe, array( $r['ts'], $r['ua_class'], $r['bot_name'], $r['verified'], $r['method'], $r['path'], $r['status'], $r['rail'], FMPPC_X402::units_to_usd( $r['amount_units'] ), $r['tx_hash'], $r['payer'], $r['reason'], $r['ip_hash'], $r['ua'] ) ) );
		}
		exit;
	}
}
