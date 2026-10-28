<?php
/**
 * The gate: classify the request, apply policy, answer 402 or let it through.
 *
 * @package ForgeMesh_Pay_Per_Crawl
 */

defined( 'ABSPATH' ) || exit;

final class FMPPC_Gate {

	/** @var array */
	private $s;

	public function __construct( array $settings ) {
		$this->s = $settings;
	}

	public function hooks() {
		add_action( 'template_redirect', array( $this, 'front' ), 0 );
		add_filter( 'rest_pre_dispatch', array( $this, 'rest' ), 0, 3 );
		add_filter( 'wp_headers', array( $this, 'link_header' ) );
	}

	public function link_header( $headers ) {
		if ( ! empty( $this->s['discovery'] ) ) {
			$headers['Link'] = '<' . home_url( '/.well-known/x402.json' ) . '>; rel="describedby"; type="application/json", <' . home_url( '/llms.txt' ) . '>; rel="llms-txt"; type="text/plain"';
		}
		return $headers;
	}

	// ── Entry points ─────────────────────────────────────────────────────────

	public function front() {
		if ( $this->exempt() ) {
			return;
		}
		if ( is_feed() && empty( $this->s['gate_feeds'] ) ) {
			return;
		}
		$this->handle( $this->current_url() );
	}

	public function rest( $result, $server, $request ) {
		if ( empty( $this->s['gate_rest'] ) || $this->exempt() || is_user_logged_in() ) {
			return $result;
		}
		$this->handle( $this->current_url() );
		return $result;
	}

	private function exempt() {
		if ( is_admin() || is_user_logged_in() || wp_doing_cron() || wp_doing_ajax() ) {
			return true;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST && empty( $this->s['gate_rest'] ) ) {
			return true;
		}
		return self::path_is_free( $this->path(), (array) $this->s['allow_paths'] );
	}

	/**
	 * Anchored exemption check. Exact files, root-level sitemap .xml files, /.well-known/ and /wp-admin
	 * directories and allow paths (path-segment boundary, so /pricing never matches /pricing-secret).
	 * Dot segments, doubled slashes and %2e / %2f in the raw path are never exempt.
	 *
	 * @param string $raw   Raw request path.
	 * @param array  $allow Admin-configured allow paths.
	 * @return bool
	 */
	public static function path_is_free( $raw, array $allow = array() ) {
		$raw = (string) $raw;
		if ( '' === $raw || '/' !== $raw[0] || preg_match( '/%2[ef]/i', $raw ) ) {
			return false;
		}
		$out = array();
		foreach ( array_slice( explode( '/', $raw ), 1 ) as $seg ) {
			if ( '..' === $seg ) {
				array_pop( $out );
			} elseif ( '.' !== $seg ) {
				$out[] = $seg;
			}
		}
		$norm = '/' . implode( '/', $out );
		if ( rtrim( $norm, '/' ) !== rtrim( $raw, '/' ) ) {
			return false;
		}
		if ( in_array( $norm, array( '/robots.txt', '/llms.txt', '/agents.txt', '/favicon.ico', '/wp-login.php', '/xmlrpc.php', '/wp-cron.php', '/.well-known', '/wp-admin' ), true )
			|| preg_match( '#^/[a-z0-9_-]*sitemap[a-z0-9_-]*\.xml$#', $norm )
			|| 0 === strpos( $norm, '/.well-known/' ) || 0 === strpos( $norm, '/wp-admin/' ) ) {
			return true;
		}
		foreach ( $allow as $p ) {
			$p = trim( (string) $p );
			if ( '' === $p ) {
				continue;
			}
			if ( '/' === substr( $p, -1 ) ? 0 === strpos( $norm, $p ) : ( $norm === $p || 0 === strpos( $norm, $p . '/' ) ) ) {
				return true;
			}
		}
		return false;
	}

	// ── Core ────────────────────────────────────────────────────────────────

	private function handle( $url ) {
		$h   = $this->headers();
		$ua  = $h['user-agent'] ?? '';
		$ip  = $this->ip();
		$c   = FMPPC_Classifier::classify( $ua, $h, $ip );
		$row = array(
			'ua_class' => $c['class'],
			'bot_name' => $c['name'],
			'verified' => $c['verified'],
			'method'   => isset( $_SERVER['REQUEST_METHOD'] ) ? substr( sanitize_key( $_SERVER['REQUEST_METHOD'] ), 0, 8 ) : 'GET', // phpcs:ignore
			'path'     => $this->path(),
			'ip_hash'  => FMPPC_Log::ip_hash( $ip ),
			'ua'       => $ua,
		);

		$rule    = FMPPC_Rules::active( $this->s ) ? FMPPC_Rules::match( (array) $this->s['price_rules'], $c, $this->path() ) : null;
		$price   = ( $rule && 'paid' === $rule['action'] && null !== $rule['price_usd'] ) ? (float) $rule['price_usd'] : (float) $this->s['price_usd'];
		$x402    = $this->x402( $url, $price );
		$payment = $x402->extract_payment( $h, $url );

		if ( 'human' === $c['class'] && ! $payment ) {
			if ( ! empty( $this->s['log_humans'] ) ) {
				FMPPC_Log::record( $row + array( 'status' => 'passed' ) );
			}
			return;
		}

		$policy = $rule ? $rule['action'] : $this->policy_for( $c );

		// A payment on a free class is still honoured (and logged as revenue).
		if ( 'free' === $policy && ! $payment ) {
			FMPPC_Log::record( $row + array( 'status' => 'passed' ) );
			return;
		}
		if ( 'block' === $policy ) {
			FMPPC_Log::record( $row + array( 'status' => 'blocked' ) );
			$this->respond_blocked();
		}
		if ( ! $payment ) {
			if ( 'monitor' === $this->s['mode'] || 'free' === $policy ) {
				FMPPC_Log::record( $row + array( 'status' => 'monitor', 'amount_units' => (int) FMPPC_X402::usd_to_units( $price ) ) );
				return;
			}
			FMPPC_Log::record( $row + array( 'status' => 'unpaid' ) );
			$this->respond_402( $x402, $url, null );
		}

		// Payment presented.
		$row['rail'] = $payment['rail'];
		if ( ! empty( $payment['error'] ) ) {
			FMPPC_Log::record( $row + array( 'status' => 'rejected', 'reason' => $payment['error'] ) );
			$this->respond_402( $x402, $url, $payment['error'], $payment['detail'] ?? null, $payment['rail'] );
		}
		$pre = $x402->precheck( $payment, $url );
		if ( $pre ) {
			FMPPC_Log::record( $row + array( 'status' => 'rejected', 'reason' => $pre ) );
			$this->respond_402( $x402, $url, $pre, null, $payment['rail'] );
		}
		$auth  = FMPPC_X402::authorization_of( $payment );
		$nonce = strtolower( (string) $auth['nonce'] );
		$lock  = 'fmppc_nonce_' . md5( $nonce );
		if ( get_transient( $lock ) ) {
			FMPPC_Log::record( $row + array( 'status' => 'rejected', 'reason' => 'payment_in_flight' ) );
			$this->respond_402( $x402, $url, 'payment_in_flight', 'This authorization was already presented.', $payment['rail'] );
		}
		set_transient( $lock, 1, 15 * MINUTE_IN_SECONDS );

		$r = $x402->verify_and_settle( $payment, $url );
		if ( empty( $r['ok'] ) ) {
			if ( 'facilitator_unreachable' === $r['reason'] ) {
				delete_transient( $lock ); // let the client retry the same authorization.
			}
			FMPPC_Log::record( $row + array( 'status' => 'settle_failed', 'reason' => $r['reason'], 'payer' => (string) ( $r['payer'] ?? $auth['from'] ) ) );
			$this->respond_402( $x402, $url, $r['reason'], $r['detail'] ?? null, $payment['rail'] );
		}

		FMPPC_Log::record(
			$row + array(
				'status'       => 'paid',
				'amount_units' => (int) $auth['value'],
				'tx_hash'      => $r['tx'],
				'payer'        => (string) ( $r['payer'] ?? $auth['from'] ),
			)
		);
		$settle_b64 = base64_encode( wp_json_encode( $r['settle'] ) );
		header( 'PAYMENT-RESPONSE: ' . $settle_b64 );
		header( 'X-PAYMENT-RESPONSE: ' . $settle_b64 );
		if ( 'mpp' === $payment['rail'] ) {
			header( 'Payment-Receipt: ' . $x402->mpp_receipt( $r['tx'] ) );
		}
		header( 'Cache-Control: private, no-store' );
		nocache_headers();
		do_action( 'fmppc_paid', $r['tx'], $row, $payment );
		// Fall through: WordPress renders the page.
	}

	private function policy_for( array $c ) {
		$over = (array) $this->s['bot_overrides'];
		if ( '' !== $c['name'] && isset( $over[ $c['name'] ] ) && in_array( $over[ $c['name'] ], array( 'free', 'paid', 'block' ), true ) ) {
			return $over[ $c['name'] ];
		}
		$cls = (array) $this->s['class_policy'];
		$p   = $cls[ $c['class'] ] ?? 'paid';
		return in_array( $p, array( 'free', 'paid', 'block' ), true ) ? $p : 'paid';
	}

	public function x402( $url, $price_usd = null ) {
		return new FMPPC_X402(
			array(
				'pay_to'           => $this->s['pay_to'],
				'price_usd'        => null === $price_usd ? $this->s['price_usd'] : $price_usd,
				'facilitator_url'  => $this->s['facilitator_url'],
				'facilitator_auth' => $this->s['facilitator_auth'],
				'mpp_secret'       => $this->s['mpp_secret'],
				'realm'            => wp_parse_url( home_url(), PHP_URL_HOST ),
				'site_name'        => get_bloginfo( 'name' ),
				'manifest_url'     => home_url( '/.well-known/x402.json' ),
			)
		);
	}

	// ── Responses ───────────────────────────────────────────────────────────

	private function respond_402( FMPPC_X402 $x402, $url, $reason, $detail = null, $rail = null ) {
		$desc  = sprintf( '%s: one page fetch (HTML)', get_bloginfo( 'name' ) );
		$error = $reason ? $reason : 'Payment required';
		nocache_headers();
		header( 'Cache-Control: no-store' );
		header( 'PAYMENT-REQUIRED: ' . base64_encode( wp_json_encode( $x402->envelope_v2( $url, $desc, $error ) ) ) );
		// PHP's SAPI flips the status to 401 when it sees WWW-Authenticate, so the 402 goes last.
		header( 'WWW-Authenticate: ' . $x402->mpp_challenge( $desc ) );
		status_header( 402 );
		http_response_code( 402 );
		header( 'Link: <' . home_url( '/.well-known/x402.json' ) . '>; rel="describedby"; type="application/json"' );
		header( 'X-Robots-Tag: noindex' );
		if ( 'mpp' === $rail && $reason ) {
			header( 'Content-Type: application/problem+json; charset=utf-8' );
			echo wp_json_encode( array( 'type' => FMPPC_X402::MPP_PROBLEMS . $reason, 'title' => 'Payment Required', 'status' => 402, 'detail' => $detail ? $detail : $reason ) );
			exit;
		}
		header( 'Content-Type: application/json; charset=utf-8' );
		if ( 'HEAD' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) { // phpcs:ignore
			echo wp_json_encode( $x402->body_v1( $url, $desc, $reason ? 'Payment rejected' : 'Payment required', $reason ) );
		}
		exit;
	}

	private function respond_blocked() {
		status_header( 403 );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( array( 'error' => 'forbidden', 'detail' => 'This crawler is not permitted on this site.' ) );
		exit;
	}

	// ── Request helpers ─────────────────────────────────────────────────────

	public function headers() {
		$out = array();
		foreach ( $_SERVER as $k => $v ) {
			if ( 0 === strpos( $k, 'HTTP_' ) ) {
				$out[ strtolower( str_replace( '_', '-', substr( $k, 5 ) ) ) ] = (string) $v;
			}
		}
		if ( isset( $_SERVER['CONTENT_TYPE'] ) ) {
			$out['content-type'] = sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) );
		}
		if ( empty( $out['authorization'] ) && function_exists( 'apache_request_headers' ) ) {
			$a = apache_request_headers();
			foreach ( $a as $k => $v ) {
				$k = strtolower( $k );
				if ( ! isset( $out[ $k ] ) ) {
					$out[ $k ] = (string) $v;
				}
			}
		}
		return $out;
	}

	public function ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore
		if ( ! empty( $this->s['trust_proxy_header'] ) ) {
			$hdr = 'HTTP_' . strtoupper( str_replace( '-', '_', $this->s['trust_proxy_header'] ) );
			if ( ! empty( $_SERVER[ $hdr ] ) ) {
				// Multi-value forwarding headers grow on the LEFT with client-supplied values;
				// the right-most entry is the one appended by the proxy in front of us.
				$parts = array_values( array_filter( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $hdr ] ) ) ) ) ) );
				$last  = $parts ? end( $parts ) : '';
				if ( filter_var( $last, FILTER_VALIDATE_IP ) ) {
					$ip = $last;
				}
			}
		}
		return $ip;
	}

	public function path() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/'; // phpcs:ignore
		$p   = wp_parse_url( $uri, PHP_URL_PATH );
		return $p ? $p : '/';
	}

	public function current_url() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/'; // phpcs:ignore
		return home_url( $uri );
	}
}
