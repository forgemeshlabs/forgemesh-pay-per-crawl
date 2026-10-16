<?php
/**
 * Discovery files on the site's own domain so paying agents and x402 indexes
 * find the paid copy: /.well-known/x402.json, /llms.txt, /agents.txt, robots.txt lines.
 *
 * @package ForgeMesh_Pay_Per_Crawl
 */

defined( 'ABSPATH' ) || exit;

final class FMPPC_Discovery {

	private $s;

	public function __construct( array $settings ) {
		$this->s = $settings;
	}

	public function hooks() {
		add_action( 'init', array( $this, 'serve' ), 1 );
		add_filter( 'robots_txt', array( $this, 'robots' ), 20, 2 );
	}

	public function serve() {
		if ( empty( $this->s['discovery'] ) ) {
			return;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/'; // phpcs:ignore
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		$path = rtrim( (string) $path, '/' );
		switch ( $path ) {
			case '/.well-known/x402.json':
				$this->send( 'application/json; charset=utf-8', wp_json_encode( $this->manifest(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) );
				break;
			case '/llms.txt':
				$this->send( 'text/plain; charset=utf-8', $this->llms() );
				break;
			case '/agents.txt':
				$this->send( 'text/plain; charset=utf-8', $this->agents() );
				break;
		}
	}

	private function send( $type, $body ) {
		status_header( 200 );
		header( 'Content-Type: ' . $type );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'Access-Control-Allow-Origin: *' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	private function paid() {
		return 'gate' === $this->s['mode'] && ! empty( $this->s['pay_to'] );
	}

	public function manifest() {
		$home  = home_url( '/' );
		$price = (float) $this->s['price_usd'];
		$res   = array(
			'resource' => $home . '{path}',
			'type'     => 'http',
			'metadata' => array(
				'method'      => 'GET',
				'name'        => sprintf( '%s: page fetch', get_bloginfo( 'name' ) ),
				'description' => sprintf( 'Any page or post on %s, served as HTML to automated clients after an x402 payment of $%s USDC on Base. Humans and verified search engines read free.', wp_parse_url( $home, PHP_URL_HOST ), rtrim( rtrim( number_format( $price, 6, '.', '' ), '0' ), '.' ) ),
				'priceUsd'    => $price,
				'mimeType'    => 'text/html',
				'paid'        => $this->paid(),
			),
			'accepts'  => array(
				array(
					'scheme'            => 'exact',
					'network'           => FMPPC_X402::NETWORK_V2,
					'amount'            => FMPPC_X402::usd_to_units( $price ),
					'asset'             => FMPPC_X402::USDC,
					'payTo'             => (string) $this->s['pay_to'],
					'maxTimeoutSeconds' => FMPPC_X402::TIMEOUT_SECS,
					'extra'             => array( 'name' => FMPPC_X402::USDC_NAME, 'version' => FMPPC_X402::USDC_VERSION ),
				),
			),
		);
		return array(
			'x402Version' => 2,
			'provider'    => array(
				'name'        => get_bloginfo( 'name' ),
				'url'         => $home,
				'description' => get_bloginfo( 'description' ),
				'contact'     => get_option( 'admin_email' ) && ! empty( $this->s['publish_contact'] ) ? get_option( 'admin_email' ) : null,
			),
			'resources'   => array( $res ),
			'protocols'   => array( 'x402', 'mpp' ),
			'generator'   => 'forgemesh-pay-per-crawl/' . FMPPC_VERSION,
			'sitemap'     => home_url( '/sitemap.xml' ),
		);
	}

	public function llms() {
		$home = home_url( '/' );
		$l    = array();
		$l[]  = '# ' . get_bloginfo( 'name' );
		$l[]  = '';
		$l[]  = '> ' . get_bloginfo( 'description' );
		$l[]  = '';
		$l[]  = 'Site: ' . $home;
		$l[]  = 'Sitemap: ' . home_url( '/sitemap.xml' );
		$l[]  = '';
		$l[]  = '## Access for automated clients';
		$l[]  = '';
		if ( $this->paid() ) {
			$l[] = sprintf( 'Pages on this site answer HTTP 402 to crawlers and agents. Pay $%s USDC on Base per page with x402 (PAYMENT-SIGNATURE or X-PAYMENT header) or MPP (Authorization: Payment) and retry the same URL.', rtrim( rtrim( number_format( (float) $this->s['price_usd'], 6, '.', '' ), '0' ), '.' ) );
		} else {
			$l[] = 'This site is instrumented for x402 pay-per-crawl; charging is not enabled yet. Terms will appear in the manifest below when it is.';
		}
		$l[] = 'Verified search engines and humans are never charged.';
		$l[] = '';
		$l[] = 'Manifest: ' . home_url( '/.well-known/x402.json' );
		$l[] = 'Agents: ' . home_url( '/agents.txt' );
		$l[] = 'Protocol: https://x402.org';
		$l[] = '';
		$l[] = '## Recent posts';
		$l[] = '';
		$posts = get_posts( array( 'numberposts' => 20, 'post_status' => 'publish' ) );
		foreach ( $posts as $p ) {
			$l[] = '- [' . wp_strip_all_tags( get_the_title( $p ) ) . '](' . get_permalink( $p ) . ')';
		}
		return implode( "\n", $l ) . "\n";
	}

	public function agents() {
		$home = home_url( '/' );
		$host = wp_parse_url( $home, PHP_URL_HOST );
		$l    = array(
			'# agents.txt — ' . $host,
			'# Standard: https://agents-txt.com',
			'',
			'Name: ' . get_bloginfo( 'name' ),
			'Provider: ' . get_bloginfo( 'name' ) . ' (' . $home . ')',
			'Protocols: x402, mpp, http',
			'Payments: ' . ( $this->paid() ? 'required' : 'none' ),
			'',
			'LLMs: ' . home_url( '/llms.txt' ),
			'x402: ' . home_url( '/.well-known/x402.json' ),
			'Sitemap: ' . home_url( '/sitemap.xml' ),
			'',
			'## Payment',
			'Protocol: x402 (HTTP 402 challenge; pay and retry with PAYMENT-SIGNATURE) and MPP (WWW-Authenticate: Payment)',
			'Network: ' . FMPPC_X402::NETWORK_V2,
			'Asset: ' . FMPPC_X402::USDC . ' (USDC)',
			'Price: $' . rtrim( rtrim( number_format( (float) $this->s['price_usd'], 6, '.', '' ), '0' ), '.' ) . ' per page',
			'PayTo: ' . (string) $this->s['pay_to'],
			'',
		);
		return implode( "\n", $l );
	}

	public function robots( $output, $public ) {
		if ( empty( $this->s['discovery'] ) ) {
			return $output;
		}
		$output .= "\n# x402 pay-per-crawl (forgemesh-pay-per-crawl)\n";
		$output .= '# Manifest: ' . home_url( '/.well-known/x402.json' ) . "\n";
		$output .= '# LLMs: ' . home_url( '/llms.txt' ) . "\n";
		$output .= '# Agents: ' . home_url( '/agents.txt' ) . "\n";
		return $output;
	}
}
