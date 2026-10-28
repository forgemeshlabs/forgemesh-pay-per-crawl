<?php
/**
 * wp-admin: Bot directory. Every crawler the plugin knows, grouped by what it
 * is for, searchable, with this site's own sightings and the policy that
 * applies. Grows on its own: shipped list + Pro feed + anything seen in the
 * ledger; first-seen dates are remembered so new arrivals are called out.
 *
 * @package ForgeMesh_Pay_Per_Crawl
 */

defined( 'ABSPATH' ) || exit;

final class FMPPC_Bots_Page {

	const CAP        = 'manage_options';
	const FIRST_SEEN = 'fmppc_bot_first_seen';
	const NEW_DAYS   = 14;

	/** Purpose order and plain titles for the groups. */
	public static function groups() {
		return array(
			'search-index'    => array( __( 'Search engines', 'forgemesh-pay-per-crawl' ), __( 'Index pages for search results. Verified by reverse DNS and free by default; blocking them removes you from search.', 'forgemesh-pay-per-crawl' ) ),
			'training'        => array( __( 'AI training crawlers', 'forgemesh-pay-per-crawl' ), __( 'Collect pages to train models. Bulk, periodic, and the ones most owners want to charge or block.', 'forgemesh-pay-per-crawl' ) ),
			'ai-search'       => array( __( 'AI search indexers', 'forgemesh-pay-per-crawl' ), __( 'Build the index behind AI answers and cite you. Blocking them removes you from those answers, not from training.', 'forgemesh-pay-per-crawl' ) ),
			'user-fetch'      => array( __( 'Fetchers for an AI user', 'forgemesh-pay-per-crawl' ), __( 'One request because a person asked an assistant about your page. Not bulk crawling; some ignore robots.txt because a human triggered them.', 'forgemesh-pay-per-crawl' ) ),
			'agent-retrieval' => array( __( 'Agent tooling', 'forgemesh-pay-per-crawl' ), __( 'Services that fetch pages for AI agents on request. The agents behind them can carry wallets: these are the likeliest payers.', 'forgemesh-pay-per-crawl' ) ),
			'seo'             => array( __( 'SEO and backlink crawlers', 'forgemesh-pay-per-crawl' ), __( 'Commercial indexes sold to marketers. Blocking them hides you from their customers, not from Google.', 'forgemesh-pay-per-crawl' ) ),
			'archive'         => array( __( 'Archives and research', 'forgemesh-pay-per-crawl' ), __( 'Preservation and open corpora. Non-profit, but their datasets feed AI training downstream.', 'forgemesh-pay-per-crawl' ) ),
			'social'          => array( __( 'Link previewers', 'forgemesh-pay-per-crawl' ), __( 'Fetch a page once to draw the card when someone shares a link. Free by default so your links look right.', 'forgemesh-pay-per-crawl' ) ),
			'monitor'         => array( __( 'Monitors and readers', 'forgemesh-pay-per-crawl' ), __( 'Uptime checks, feed readers, WordPress itself. Free by default.', 'forgemesh-pay-per-crawl' ) ),
			'scraping'        => array( __( 'Scripts and scrapers', 'forgemesh-pay-per-crawl' ), __( 'Bare HTTP libraries, scraping frameworks, headless browsers. Nobody identified themselves; paid by default.', 'forgemesh-pay-per-crawl' ) ),
			'unknown'         => array( __( 'Seen here, not yet catalogued', 'forgemesh-pay-per-crawl' ), __( 'Names that appeared in your ledger but are not in the catalog yet. They are classified by their generic user agent; the Pro feed names new ones as they are identified.', 'forgemesh-pay-per-crawl' ) ),
		);
	}

	/** Merge catalog, knowledge base and this site's ledger into one list; remember first sightings. */
	public static function all() {
		$ops   = FMPPC_Audit::operators();
		$s     = FMPPC::settings();
		$seen  = array();
		foreach ( FMPPC_Log::by_bot( 365, 500 ) as $b ) {
			if ( 'human' !== $b['ua_class'] && '' !== $b['bot_name'] ) {
				$seen[ $b['bot_name'] ] = $b;
			}
		}
		$first = (array) get_option( self::FIRST_SEEN, array() );
		$now   = time();
		$dirty = false;
		$list  = array();
		$class_purpose = array( 'search' => 'search-index', 'ai' => 'training', 'seo' => 'seo', 'social' => 'social', 'monitor' => 'monitor', 'automation' => 'scraping', 'bot' => 'scraping' );
		foreach ( FMPPC_Classifier::catalog() as $e ) {
			$k = $ops[ $e['name'] ] ?? null;
			$list[ $e['name'] ] = array(
				'name'    => $e['name'],
				'class'   => $e['class'],
				'op'      => $k['op'] ?? '',
				'purpose' => $k['purpose'] ?? ( $class_purpose[ $e['class'] ] ?? 'scraping' ),
				'docs'    => $k['docs'] ?? '',
				'robots'  => $k['robots'] ?? '',
				'note'    => $k['note'] ?? '',
				'verify'  => ! empty( $e['verify'] ) ? implode( ', ', $e['verify'] ) : '',
				'match'   => implode( ', ', (array) $e['match'] ),
				'source'  => ! empty( $e['intel'] ) ? 'feed' : 'shipped',
			);
		}
		foreach ( $seen as $name => $b ) {
			if ( ! isset( $list[ $name ] ) ) {
				$list[ $name ] = array( 'name' => $name, 'class' => $b['ua_class'], 'op' => '', 'purpose' => 'unknown', 'docs' => '', 'robots' => '', 'note' => '', 'verify' => '', 'match' => '', 'source' => 'ledger' );
			}
		}
		foreach ( $list as $name => &$row ) {
			if ( ! isset( $first[ $name ] ) ) {
				$first[ $name ] = $now;
				$dirty          = true;
			}
			$row['first_seen'] = (int) $first[ $name ];
			$row['is_new']     = ( $now - (int) $first[ $name ] ) < self::NEW_DAYS * DAY_IN_SECONDS && count( $first ) > 1;
			$row['hits']       = isset( $seen[ $name ] ) ? (int) $seen[ $name ]['hits'] : 0;
			$row['paid']       = isset( $seen[ $name ] ) ? (int) $seen[ $name ]['paid'] : 0;
			$row['last_seen']  = isset( $seen[ $name ] ) ? $seen[ $name ]['last_seen'] : '';
			// A search-engine name seen under another class means the claim failed verification.
			$row['impostor']   = isset( $seen[ $name ] ) && 'search' === $row['class'] && 'search' !== $seen[ $name ]['ua_class'];
			$row['policy']     = self::policy( $s, $row );
		}
		unset( $row );
		if ( $dirty ) {
			// First render seeds every name at once; that is not "new", so backdate the seed.
			if ( count( array_filter( $first, function ( $t ) use ( $now ) { return $t === $now; } ) ) === count( $first ) ) {
				foreach ( $first as $n => $t ) {
					$first[ $n ] = $now - ( self::NEW_DAYS + 1 ) * DAY_IN_SECONDS;
				}
				foreach ( $list as &$row ) { $row['is_new'] = false; }
				unset( $row );
			}
			update_option( self::FIRST_SEEN, $first, false );
		}
		uasort( $list, function ( $a, $b ) { return strnatcasecmp( $a['name'], $b['name'] ); } );
		return $list;
	}

	/** What this site currently does with the bot: rule, override, class policy. */
	private static function policy( array $s, array $row ) {
		if ( FMPPC_Rules::active( $s ) ) {
			foreach ( (array) $s['price_rules'] as $r ) {
				$hit = ( 'bot' === $r['type'] && 0 === strcasecmp( $r['value'], $row['name'] ) ) || ( 'class' === $r['type'] && $r['value'] === $row['class'] ) || ( 'purpose' === $r['type'] && $r['value'] === $row['purpose'] );
				if ( $hit ) {
					return array( 'action' => $r['action'], 'price' => $r['price_usd'], 'via' => 'rule' );
				}
			}
		}
		$over = (array) $s['bot_overrides'];
		if ( isset( $over[ $row['name'] ] ) && '' !== $over[ $row['name'] ] ) {
			return array( 'action' => $over[ $row['name'] ], 'price' => null, 'via' => 'override' );
		}
		$cls = (array) $s['class_policy'];
		return array( 'action' => $cls[ $row['class'] ] ?? 'paid', 'price' => null, 'via' => 'class' );
	}

	public function page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$s      = FMPPC::settings();
		$all    = self::all();
		$groups = self::groups();
		$by     = array();
		foreach ( $all as $b ) {
			$by[ $b['purpose'] ][] = $b;
		}
		$new   = array_filter( $all, function ( $b ) { return ! empty( $b['is_new'] ); } );
		$seen  = array_filter( $all, function ( $b ) { return $b['hits'] > 0; } );
		$feed  = array_filter( $all, function ( $b ) { return 'feed' === $b['source']; } );
		$pst   = FMPPC_Pro::state();
		$price = (float) $s['price_usd'];
		$pol   = function ( $p ) use ( $price ) {
			$lbl = array( 'paid' => __( 'charged', 'forgemesh-pay-per-crawl' ), 'free' => __( 'free', 'forgemesh-pay-per-crawl' ), 'block' => __( 'blocked', 'forgemesh-pay-per-crawl' ) );
			$t   = $lbl[ $p['action'] ] ?? $p['action'];
			if ( 'paid' === $p['action'] ) {
				$t .= ' $' . rtrim( rtrim( number_format( null !== $p['price'] ? (float) $p['price'] : $price, 3, '.', '' ), '0' ), '.' );
			}
			return $t;
		};
		?>
		<div class="wrap fmppc fmppc-bots">
			<h1><?php esc_html_e( 'Bot directory', 'forgemesh-pay-per-crawl' ); ?> <span class="fmppc-grow"><?php esc_html_e( 'ever-growing', 'forgemesh-pay-per-crawl' ); ?></span></h1>
			<p class="description"><?php esc_html_e( 'Everything that is not a person. This list is built from three sources and grows on its own: the list shipped with the plugin, the Pro crawler feed, and every bot that has already visited this site. A name you have never heard of is normal; new crawlers appear every few weeks.', 'forgemesh-pay-per-crawl' ); ?></p>

			<div class="fmppc-bots-stats">
				<span><strong><?php echo esc_html( number_format_i18n( count( $all ) ) ); ?></strong> <?php esc_html_e( 'known', 'forgemesh-pay-per-crawl' ); ?></span>
				<span><strong><?php echo esc_html( number_format_i18n( count( $seen ) ) ); ?></strong> <?php esc_html_e( 'seen on this site', 'forgemesh-pay-per-crawl' ); ?></span>
				<span><strong><?php echo esc_html( number_format_i18n( count( $new ) ) ); ?></strong> <?php echo esc_html( sprintf( /* translators: days */ __( 'new in the last %d days', 'forgemesh-pay-per-crawl' ), self::NEW_DAYS ) ); ?></span>
				<span><?php echo esc_html( sprintf( /* translators: date */ __( 'shipped list revised %s', 'forgemesh-pay-per-crawl' ), FMPPC_Classifier::SHIPPED_AT ) ); ?><?php if ( ! empty( $pst['intel_at'] ) ) : ?> · <?php echo esc_html( sprintf( /* translators: 1 count, 2 ago */ __( 'feed: %1$d names, refreshed %2$s ago', 'forgemesh-pay-per-crawl' ), count( $feed ), human_time_diff( (int) $pst['intel_at'] ) ) ); ?><?php else : ?> · <?php esc_html_e( 'Pro feed off: new names arrive with plugin updates only', 'forgemesh-pay-per-crawl' ); ?><?php endif; ?></span>
			</div>

			<div class="fmppc-bots-tools">
				<input type="search" id="fmppc-bot-search" placeholder="<?php esc_attr_e( 'Search a bot, company, purpose or robots.txt token…', 'forgemesh-pay-per-crawl' ); ?>" autocomplete="off">
				<div class="fmppc-chips">
					<button type="button" class="fmppc-chip is-on" data-filter=""><?php esc_html_e( 'All', 'forgemesh-pay-per-crawl' ); ?></button>
					<button type="button" class="fmppc-chip" data-filter="seen"><?php esc_html_e( 'Seen here', 'forgemesh-pay-per-crawl' ); ?></button>
					<button type="button" class="fmppc-chip" data-filter="new"><?php esc_html_e( 'New', 'forgemesh-pay-per-crawl' ); ?></button>
					<button type="button" class="fmppc-chip" data-filter="paid"><?php esc_html_e( 'Charged', 'forgemesh-pay-per-crawl' ); ?></button>
					<button type="button" class="fmppc-chip" data-filter="free"><?php esc_html_e( 'Free', 'forgemesh-pay-per-crawl' ); ?></button>
					<button type="button" class="fmppc-chip" data-filter="block"><?php esc_html_e( 'Blocked', 'forgemesh-pay-per-crawl' ); ?></button>
				</div>
				<span class="fmppc-bots-count" id="fmppc-bot-count"></span>
			</div>

			<?php foreach ( $groups as $key => $g ) : if ( empty( $by[ $key ] ) ) { continue; } ?>
				<details class="fmppc-bot-group" data-group="<?php echo esc_attr( $key ); ?>" <?php echo in_array( $key, array( 'training', 'unknown' ), true ) || count( $by[ $key ] ) <= 4 ? 'open' : ''; ?>>
					<summary><span class="fmppc-bot-group-title"><?php echo esc_html( $g[0] ); ?></span> <span class="fmppc-bot-group-n"><?php echo esc_html( number_format_i18n( count( $by[ $key ] ) ) ); ?></span><span class="fmppc-bot-group-desc"><?php echo esc_html( $g[1] ); ?></span></summary>
					<div class="fmppc-bot-cards">
					<?php foreach ( $by[ $key ] as $b ) : $p = $b['policy']; ?>
						<article class="fmppc-bot" id="bot-<?php echo esc_attr( sanitize_title( $b['name'] ) ); ?>"
							data-q="<?php echo esc_attr( strtolower( $b['name'] . ' ' . $b['op'] . ' ' . $b['purpose'] . ' ' . $b['robots'] . ' ' . $b['class'] . ' ' . $b['match'] ) ); ?>"
							data-seen="<?php echo $b['hits'] ? '1' : '0'; ?>" data-new="<?php echo ! empty( $b['is_new'] ) ? '1' : '0'; ?>" data-policy="<?php echo esc_attr( $p['action'] ); ?>">
							<header>
								<h3><?php echo esc_html( $b['name'] ); ?></h3>
								<?php if ( ! empty( $b['is_new'] ) ) : ?><span class="fmppc-tag fmppc-tag-new"><?php esc_html_e( 'new', 'forgemesh-pay-per-crawl' ); ?></span><?php endif; ?>
								<span class="fmppc-tag fmppc-tag-<?php echo esc_attr( $p['action'] ); ?>" title="<?php echo esc_attr( sprintf( /* translators: source */ __( 'set by %s', 'forgemesh-pay-per-crawl' ), $p['via'] ) ); ?>"><?php echo esc_html( $pol( $p ) ); ?></span>
							</header>
							<p class="fmppc-bot-op"><?php echo esc_html( '' !== $b['op'] ? $b['op'] : __( 'Unknown operator', 'forgemesh-pay-per-crawl' ) ); ?> · <?php echo esc_html( $b['class'] ); ?></p>
							<?php if ( '' !== $b['note'] ) : ?><p class="fmppc-bot-note"><?php echo esc_html( $b['note'] ); ?></p><?php endif; ?>
							<dl>
								<?php if ( '' !== $b['robots'] ) : ?><dt>robots.txt</dt><dd><code>User-agent: <?php echo esc_html( $b['robots'] ); ?></code></dd><?php endif; ?>
								<?php if ( '' !== $b['verify'] ) : ?><dt><?php esc_html_e( 'Verified by', 'forgemesh-pay-per-crawl' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: suffixes */ __( 'reverse DNS ending %s', 'forgemesh-pay-per-crawl' ), $b['verify'] ) ); ?></dd><?php endif; ?>
								<dt><?php esc_html_e( 'On this site', 'forgemesh-pay-per-crawl' ); ?></dt><dd><?php echo $b['hits'] ? esc_html( sprintf( /* translators: 1 hits, 2 paid, 3 last seen */ __( '%1$s fetches, %2$s paid, last %3$s', 'forgemesh-pay-per-crawl' ), number_format_i18n( $b['hits'] ), number_format_i18n( $b['paid'] ), $b['last_seen'] ? human_time_diff( strtotime( $b['last_seen'] . ' UTC' ) ) . ' ' . __( 'ago', 'forgemesh-pay-per-crawl' ) : '' ) ) : esc_html__( 'not seen yet', 'forgemesh-pay-per-crawl' ); ?><?php if ( ! empty( $b['impostor'] ) ) : ?> <span class="fmppc-tag fmppc-tag-block"><?php esc_html_e( 'impostor: failed verification, treated as a script', 'forgemesh-pay-per-crawl' ); ?></span><?php endif; ?></dd>
								<?php if ( '' !== $b['docs'] ) : ?><dt><?php esc_html_e( 'Docs', 'forgemesh-pay-per-crawl' ); ?></dt><dd><a href="<?php echo esc_url( $b['docs'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( wp_parse_url( $b['docs'], PHP_URL_HOST ) ); ?></a></dd><?php endif; ?>
							</dl>
						</article>
					<?php endforeach; ?>
					</div>
				</details>
			<?php endforeach; ?>
			<p class="fmppc-bots-empty" id="fmppc-bot-empty" style="display:none"><?php esc_html_e( 'Nothing matches. If a crawler is hitting you under a name that is not here, it will appear under "Seen here, not yet catalogued" after its first visit.', 'forgemesh-pay-per-crawl' ); ?></p>
			<p class="description"><?php esc_html_e( 'Change what happens to a bot in Settings: per-bot overrides (free plugin) or pricing rules by name, purpose or class (Pro).', 'forgemesh-pay-per-crawl' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=fmppc-settings' ) ); ?>"><?php esc_html_e( 'Open settings', 'forgemesh-pay-per-crawl' ); ?></a></p>
		</div>
		<?php
	}
}
