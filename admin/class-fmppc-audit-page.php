<?php
/**
 * wp-admin: the AI Crawler Audit page (free summary, unlockable full report).
 *
 * @package ForgeMesh_Pay_Per_Crawl
 */

defined( 'ABSPATH' ) || exit;

final class FMPPC_Audit_Page {

	const CAP = 'manage_options';

	public function hooks() {
		add_action( 'admin_post_fmppc_audit_token', array( $this, 'save_token' ) );
		add_action( 'admin_post_fmppc_audit_evidence', array( $this, 'evidence' ) );
	}

	public function save_token() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'forbidden' );
		}
		check_admin_referer( 'fmppc_audit_token' );
		$tok = isset( $_POST['token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '';
		if ( '' === $tok ) {
			delete_option( FMPPC_Audit::OPTION );
			wp_safe_redirect( admin_url( 'admin.php?page=fmppc-audit&msg=cleared' ) );
			exit;
		}
		$v = FMPPC_Audit::verify( $tok );
		if ( ! $v['ok'] ) {
			wp_safe_redirect( admin_url( 'admin.php?page=fmppc-audit&msg=bad&why=' . rawurlencode( $v['error'] . ( isset( $v['host'] ) ? ' (' . $v['host'] . ')' : '' ) ) ) );
			exit;
		}
		update_option( FMPPC_Audit::OPTION, $tok, false );
		wp_safe_redirect( admin_url( 'admin.php?page=fmppc-audit&msg=unlocked' ) );
		exit;
	}

	public function evidence() {
		if ( ! current_user_can( self::CAP ) || ! FMPPC_Audit::unlocked() ) {
			wp_die( 'forbidden' );
		}
		check_admin_referer( 'fmppc_audit_evidence' );
		$days = isset( $_GET['days'] ) ? max( 1, min( 365, (int) $_GET['days'] ) ) : 30;
		$e    = FMPPC_Audit::evidence( $days );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="crawler-evidence-' . FMPPC_Audit::host() . '-' . $days . 'd-' . substr( $e['digest'], 0, 8 ) . '.csv"' );
		echo $e['csv']; // phpcs:ignore WordPress.Security.EscapeOutput -- CSV body, cells neutralised in FMPPC_Audit::evidence().
		exit;
	}

	public function page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$days     = isset( $_GET['days'] ) ? max( 1, min( 365, (int) $_GET['days'] ) ) : 30; // phpcs:ignore WordPress.Security.NonceVerification
		$msg      = isset( $_GET['msg'] ) ? sanitize_key( $_GET['msg'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$why      = isset( $_GET['why'] ) ? sanitize_text_field( wp_unslash( $_GET['why'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$unlocked = FMPPC_Audit::unlocked();
		$r        = FMPPC_Audit::build( $days );
		$ev       = $unlocked ? FMPPC_Audit::evidence( $days ) : null;
		if ( $ev ) {
			$r['digest'] = $ev['digest'];
		}
		$host     = FMPPC_Audit::host();
		$buy      = FMPPC_Audit::BUY_URL . '?host=' . rawurlencode( $host );
		$usd      = function ( $u ) { return '$' . number_format( (int) $u / 1e6, 2 ); };
		$mb       = function ( $b ) { return number_format( $b / 1048576, 1 ) . ' MB'; };
		$ratio    = $r['totals']['hits'] ? round( 100 * $r['totals']['bot_hits'] / $r['totals']['hits'] ) : 0;
		?>
		<div class="wrap fmppc fmppc-audit">
			<h1><?php esc_html_e( 'AI Crawler Audit', 'forgemesh-pay-per-crawl' ); ?> <span class="fmppc-audit-host"><?php echo esc_html( $host ); ?></span></h1>
			<?php if ( 'unlocked' === $msg ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'Audit unlocked for this host. The full report is below.', 'forgemesh-pay-per-crawl' ); ?></p></div><?php endif; ?>
			<?php if ( 'bad' === $msg ) : ?><div class="notice notice-error"><p><?php echo esc_html( sprintf( /* translators: reason */ __( 'That token did not verify: %s. Tokens are bound to one host; buy one for this host or paste the token from your receipt email.', 'forgemesh-pay-per-crawl' ), $why ) ); ?></p></div><?php endif; ?>
			<?php if ( 'cleared' === $msg ) : ?><div class="notice notice-info"><p><?php esc_html_e( 'Token removed.', 'forgemesh-pay-per-crawl' ); ?></p></div><?php endif; ?>

			<p class="fmppc-range fmppc-noprint">
				<?php foreach ( array( 7, 30, 90, 365 ) as $d ) : ?>
					<a class="button <?php echo $d === $days ? 'button-primary' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=fmppc-audit&days=' . $d ) ); ?>"><?php echo esc_html( $d . 'd' ); ?></a>
				<?php endforeach; ?>
				<?php if ( $unlocked ) : ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fmppc_audit_evidence&days=' . $days ), 'fmppc_audit_evidence' ) ); ?>"><?php esc_html_e( 'Download evidence CSV', 'forgemesh-pay-per-crawl' ); ?></a>
					<a class="button" href="#" onclick="window.print();return false;"><?php esc_html_e( 'Print / save as PDF', 'forgemesh-pay-per-crawl' ); ?></a>
				<?php endif; ?>
			</p>

			<p class="description"><?php echo esc_html( sprintf( /* translators: 1 from, 2 to */ __( 'Period %1$s to %2$s (UTC). Built from this site\'s own ledger; nothing is uploaded.', 'forgemesh-pay-per-crawl' ), $r['from'], $r['to'] ) ); ?></p>

			<h2><?php esc_html_e( '1. Summary', 'forgemesh-pay-per-crawl' ); ?></h2>
			<div class="fmppc-tiles">
				<div class="fmppc-tile"><span><?php esc_html_e( 'Requests', 'forgemesh-pay-per-crawl' ); ?></span><strong><?php echo esc_html( number_format_i18n( $r['totals']['hits'] ) ); ?></strong><em><?php echo esc_html( sprintf( /* translators: percent */ __( '%d%% from bots', 'forgemesh-pay-per-crawl' ), $ratio ) ); ?></em></div>
				<div class="fmppc-tile"><span><?php esc_html_e( 'Bot operators', 'forgemesh-pay-per-crawl' ); ?></span><strong><?php echo esc_html( number_format_i18n( count( $r['by_operator'] ) ) ); ?></strong><em><?php echo esc_html( sprintf( /* translators: count */ __( '%d impostor search bots', 'forgemesh-pay-per-crawl' ), count( $r['impostors'] ) ) ); ?></em></div>
				<div class="fmppc-tile"><span><?php esc_html_e( 'Estimated bandwidth to bots', 'forgemesh-pay-per-crawl' ); ?></span><strong><?php echo esc_html( $mb( $r['bot_bytes'] ) ); ?></strong><em><?php echo esc_html( sprintf( /* translators: bytes */ __( 'at ~%s KB per page', 'forgemesh-pay-per-crawl' ), number_format( $r['page_bytes'] / 1024 ) ) ); ?></em></div>
				<div class="fmppc-tile fmppc-tile-rev"><span><?php esc_html_e( 'Worth at $0.01 / page', 'forgemesh-pay-per-crawl' ); ?></span><strong><?php echo esc_html( $usd( $r['value']['0.01'] ) ); ?></strong><em><?php echo esc_html( sprintf( /* translators: 1 low, 2 high */ __( '%1$s at $0.005 · %2$s at $0.05', 'forgemesh-pay-per-crawl' ), $usd( $r['value']['0.005'] ), $usd( $r['value']['0.05'] ) ) ); ?></em></div>
			</div>
			<table class="widefat striped fmppc-audit-classes">
				<thead><tr><th><?php esc_html_e( 'Class', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Hits', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Paid', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Revenue', 'forgemesh-pay-per-crawl' ); ?></th></tr></thead>
				<tbody><?php foreach ( $r['by_class'] as $c ) : ?><tr><td><?php echo esc_html( $c['ua_class'] ); ?></td><td><?php echo esc_html( number_format_i18n( $c['hits'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $c['paid'] ) ); ?></td><td><?php echo esc_html( $usd( $c['revenue_units'] ) ); ?></td></tr><?php endforeach; ?></tbody>
			</table>

			<?php if ( ! $unlocked ) : ?>
				<div class="fmppc-audit-lock">
					<h2><?php esc_html_e( 'Unlock the full audit', 'forgemesh-pay-per-crawl' ); ?> <span class="fmppc-audit-price">$19 <?php esc_html_e( 'one-time, this host', 'forgemesh-pay-per-crawl' ); ?></span></h2>
					<p><?php esc_html_e( 'The sections below are built from data already on this site. The unlock token is signed by ForgeMesh and checked here, offline. Nothing is uploaded.', 'forgemesh-pay-per-crawl' ); ?></p>
					<ul>
						<li><strong><?php esc_html_e( 'Operators', 'forgemesh-pay-per-crawl' ); ?></strong> — <?php echo esc_html( sprintf( /* translators: count */ __( '%d companies and script sources seen, each with what it does with your pages, its published docs, its robots.txt token, and whether it was verified or an impostor.', 'forgemesh-pay-per-crawl' ), count( $r['by_operator'] ) ) ); ?></li>
						<li><strong><?php esc_html_e( 'Cost and value', 'forgemesh-pay-per-crawl' ); ?></strong> — <?php esc_html_e( 'bandwidth served to bots and the value of the same fetches at three price points, by operator.', 'forgemesh-pay-per-crawl' ); ?></li>
						<li><strong><?php esc_html_e( 'Evidence log', 'forgemesh-pay-per-crawl' ); ?></strong> — <?php esc_html_e( 'every bot fetch in the period as a hash-chained CSV with a digest you can quote.', 'forgemesh-pay-per-crawl' ); ?></li>
						<li><strong><?php esc_html_e( 'Licensing notices', 'forgemesh-pay-per-crawl' ); ?></strong> — <?php esc_html_e( 'a prefilled notice for each AI operator that fetched the site.', 'forgemesh-pay-per-crawl' ); ?></li>
					</ul>
					<p><a class="button button-primary button-hero" target="_blank" rel="noopener" href="<?php echo esc_url( $buy ); ?>"><?php esc_html_e( 'Buy the audit for this host', 'forgemesh-pay-per-crawl' ); ?></a></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fmppc-audit-tokenform">
						<?php wp_nonce_field( 'fmppc_audit_token' ); ?><input type="hidden" name="action" value="fmppc_audit_token">
						<label for="fmppc_audit_token"><?php esc_html_e( 'Already have a token? Paste it:', 'forgemesh-pay-per-crawl' ); ?></label>
						<input type="text" class="regular-text code" id="fmppc_audit_token" name="token" placeholder="eyJ2IjoxLCJza3UiOiJ3cC1hdWRpdCIs…">
						<?php submit_button( __( 'Unlock', 'forgemesh-pay-per-crawl' ), 'secondary', 'submit', false ); ?>
					</form>
				</div>
				<?php $this->preview_operators( $r ); ?>
			<?php else : ?>
				<?php $this->full_report( $r, $ev, $days ); ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fmppc-noprint" style="margin-top:2em">
					<?php wp_nonce_field( 'fmppc_audit_token' ); ?><input type="hidden" name="action" value="fmppc_audit_token"><input type="hidden" name="token" value="">
					<?php submit_button( __( 'Remove token from this site', 'forgemesh-pay-per-crawl' ), 'link', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Locked view: operator names and hit counts only. */
	private function preview_operators( array $r ) {
		?>
		<h2><?php esc_html_e( '2. Operators (preview)', 'forgemesh-pay-per-crawl' ); ?></h2>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Operator', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Hits', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'What it does with your pages', 'forgemesh-pay-per-crawl' ); ?></th></tr></thead><tbody>
		<?php foreach ( array_slice( $r['by_operator'], 0, 8 ) as $op ) : ?>
			<tr><td><?php echo esc_html( $op['op'] ); ?></td><td><?php echo esc_html( number_format_i18n( $op['hits'] ) ); ?></td><td class="fmppc-blur"><?php esc_html_e( 'Unlock to see purpose, docs, robots token, verification and notices.', 'forgemesh-pay-per-crawl' ); ?></td></tr>
		<?php endforeach; ?>
		<?php if ( ! $r['by_operator'] ) : ?><tr><td colspan="3"><?php esc_html_e( 'No bot traffic in this period yet.', 'forgemesh-pay-per-crawl' ); ?></td></tr><?php endif; ?>
		</tbody></table>
		<?php
	}

	private function full_report( array $r, array $ev, $days ) {
		$usd = function ( $u ) { return '$' . number_format( (int) $u / 1e6, 2 ); };
		$mb  = function ( $b ) { return number_format( $b / 1048576, 1 ) . ' MB'; };
		?>
		<h2><?php esc_html_e( '2. Operators', 'forgemesh-pay-per-crawl' ); ?></h2>
		<?php foreach ( $r['by_operator'] as $op ) : ?>
			<div class="fmppc-audit-op">
				<h3><?php echo esc_html( $op['op'] ); ?> <span class="fmppc-audit-n"><?php echo esc_html( sprintf( /* translators: 1 hits, 2 sources */ __( '%1$s fetches from %2$s source(s)', 'forgemesh-pay-per-crawl' ), number_format_i18n( $op['hits'] ), number_format_i18n( max( 1, $op['sources'] ) ) ) ); ?></span></h3>
				<p><strong><?php esc_html_e( 'Purpose:', 'forgemesh-pay-per-crawl' ); ?></strong> <?php echo esc_html( implode( ', ', array_map( array( 'FMPPC_Audit', 'purpose_label' ), array_keys( $op['purposes'] ) ) ) ?: __( 'Unknown', 'forgemesh-pay-per-crawl' ) ); ?>
				<?php if ( $op['robots'] ) : ?> · <strong>robots.txt:</strong> <code><?php echo esc_html( implode( ', ', array_keys( $op['robots'] ) ) ); ?></code><?php endif; ?>
				<?php if ( '' !== $op['docs'] ) : ?> · <a href="<?php echo esc_url( $op['docs'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'operator docs', 'forgemesh-pay-per-crawl' ); ?></a><?php endif; ?>
				· <strong><?php esc_html_e( 'Bandwidth:', 'forgemesh-pay-per-crawl' ); ?></strong> <?php echo esc_html( $mb( $op['hits'] * $r['page_bytes'] ) ); ?>
				· <strong><?php esc_html_e( 'Worth at $0.01:', 'forgemesh-pay-per-crawl' ); ?></strong> <?php echo esc_html( $usd( $op['hits'] * 10000 ) ); ?>
				<?php if ( $op['paid'] ) : ?> · <strong><?php esc_html_e( 'Paid:', 'forgemesh-pay-per-crawl' ); ?></strong> <?php echo esc_html( number_format_i18n( $op['paid'] ) . ' (' . $usd( $op['revenue_units'] ) . ')' ); ?><?php endif; ?></p>
				<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Crawler', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Class', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Hits', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Verified', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Notes', 'forgemesh-pay-per-crawl' ); ?></th></tr></thead><tbody>
				<?php foreach ( $op['bots'] as $b ) : ?>
					<tr><td><?php echo esc_html( $b['name'] ); ?></td><td><?php echo esc_html( $b['class'] ); ?></td><td><?php echo esc_html( number_format_i18n( $b['hits'] ) ); ?></td>
					<td><?php echo '1' === (string) $b['verified'] ? '✅' : ( '0' === (string) $b['verified'] ? '⚠️ ' . esc_html__( 'impostor', 'forgemesh-pay-per-crawl' ) : '—' ); ?></td>
					<td><?php echo esc_html( $b['note'] ); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			</div>
		<?php endforeach; ?>
		<?php if ( ! $r['by_operator'] ) : ?><p><?php esc_html_e( 'No bot traffic in this period yet.', 'forgemesh-pay-per-crawl' ); ?></p><?php endif; ?>

		<?php if ( $r['impostors'] ) : ?>
			<h2><?php esc_html_e( '3. Impostors', 'forgemesh-pay-per-crawl' ); ?></h2>
			<p><?php esc_html_e( 'These requests claimed a search-engine user agent but their address did not reverse-resolve to that operator. They were treated as scripts. Real search engines pass this check and stay free.', 'forgemesh-pay-per-crawl' ); ?></p>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Claimed', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Treated as', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Hits', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Sources', 'forgemesh-pay-per-crawl' ); ?></th></tr></thead><tbody>
			<?php foreach ( $r['impostors'] as $i ) : ?><tr><td><?php echo esc_html( $i['bot_name'] ); ?></td><td><?php echo esc_html( $i['ua_class'] ); ?></td><td><?php echo esc_html( number_format_i18n( $i['hits'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $i['sources'] ) ); ?></td></tr><?php endforeach; ?>
			</tbody></table>
		<?php endif; ?>

		<h2><?php esc_html_e( '4. Most fetched pages', 'forgemesh-pay-per-crawl' ); ?></h2>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Path', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Bot hits', 'forgemesh-pay-per-crawl' ); ?></th><th><?php esc_html_e( 'Paid', 'forgemesh-pay-per-crawl' ); ?></th></tr></thead><tbody>
		<?php foreach ( $r['paths'] as $p ) : ?><tr><td><code><?php echo esc_html( $p['path'] ); ?></code></td><td><?php echo esc_html( number_format_i18n( $p['hits'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $p['paid'] ) ); ?></td></tr><?php endforeach; ?>
		</tbody></table>

		<h2><?php esc_html_e( '5. Evidence log', 'forgemesh-pay-per-crawl' ); ?></h2>
		<p><?php echo esc_html( sprintf( /* translators: 1 rows, 2 days */ __( '%1$s bot requests over %2$d days, each row carrying a SHA-256 chain over the previous row. Changing or removing any row breaks the chain.', 'forgemesh-pay-per-crawl' ), number_format_i18n( $ev['rows'] ), $days ) ); ?></p>
		<p><strong><?php esc_html_e( 'Digest:', 'forgemesh-pay-per-crawl' ); ?></strong> <code class="fmppc-digest"><?php echo esc_html( $ev['digest'] ); ?></code></p>
		<p class="description"><?php esc_html_e( 'How it was collected: the plugin records every non-human request at template_redirect in WordPress, before any page template runs. Client addresses are never stored; sources are counted by a daily-salted hash. Search engines are verified by reverse DNS on the client address.', 'forgemesh-pay-per-crawl' ); ?></p>

		<h2><?php esc_html_e( '6. Licensing notices', 'forgemesh-pay-per-crawl' ); ?></h2>
		<p><?php esc_html_e( 'One per AI operator seen in the period. Paste into an email or a web form; these use only the operator\'s published documentation, so check for a current contact there. This is a template, not legal advice.', 'forgemesh-pay-per-crawl' ); ?></p>
		<?php
		$any = false;
		foreach ( $r['by_operator'] as $op ) {
			$ai = array_intersect( array_keys( $op['purposes'] ), array( 'training', 'ai-search', 'user-fetch' ) );
			if ( ! $ai ) {
				continue;
			}
			$any = true;
			?>
			<details class="fmppc-audit-notice"><summary><?php echo esc_html( $op['op'] ); ?></summary><textarea readonly rows="14"><?php echo esc_textarea( FMPPC_Audit::notice( $op, $r ) ); ?></textarea></details>
			<?php
		}
		if ( ! $any ) {
			echo '<p>' . esc_html__( 'No AI operators in this period.', 'forgemesh-pay-per-crawl' ) . '</p>';
		}
	}
}
