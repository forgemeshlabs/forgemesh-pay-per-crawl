<?php
// Unit tests with WordPress stubs. Run: php tests/unit.php
define( 'ABSPATH', '/' );
define( 'FMPPC_VERSION', 'test' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
$GLOBALS['transients'] = array();
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
class WP_Error { public $m; function __construct( $c, $m ) { $this->m = $m; } function get_error_message() { return $this->m; } }
$GLOBALS['posts'] = array();
function wp_remote_post( $url, $args ) { $GLOBALS['posts'][] = array( $url, json_decode( $args['body'], true ) ); return $GLOBALS['facilitator']( $url, json_decode( $args['body'], true ) ); }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_retrieve_response_code( $r ) { return $r['code'] ?? 200; }

require __DIR__ . '/../includes/class-fmppc-keccak.php';
require __DIR__ . '/../includes/class-fmppc-classifier.php';
require __DIR__ . '/../includes/class-fmppc-x402.php';
define( 'FMPPC_DIR', __DIR__ . '/../' );
require __DIR__ . '/../includes/class-fmppc-pro.php';

$pass = 0; $fail = 0;
function ok( $cond, $name ) { global $pass, $fail; if ( $cond ) { $pass++; } else { $fail++; echo "FAIL: $name\n"; } }

// keccak
ok( FMPPC_Keccak::hex( '' ) === '0xc5d2460186f7233c927e7db2dcc703c0e500b653ca82273b7bfad8045d85a470', 'keccak empty' );
ok( FMPPC_Keccak::hex( 'abc' ) === '0x4e03657aea45a94fc7d47ba826c8d667c0d1e6e33a64a036ec44f58fa12d6c45', 'keccak abc' );
ok( FMPPC_Keccak::hex( str_repeat( 'a', 135 ) ) === '0x' . trim( shell_exec( 'cd /home/ubuntu/dev/x402-proxy && node -e "const {keccak256,toHex}=require(\'viem\');process.stdout.write(keccak256(toHex(\'a\'.repeat(135))).slice(2))"' ) ), 'keccak 135 (pad edge)' );
ok( FMPPC_Keccak::hex( str_repeat( 'xyz', 100 ) ) === '0x' . trim( shell_exec( 'cd /home/ubuntu/dev/x402-proxy && node -e "const {keccak256,toHex}=require(\'viem\');process.stdout.write(keccak256(toHex(\'xyz\'.repeat(100))).slice(2))"' ) ), 'keccak multi-block' );

// classifier
$c = FMPPC_Classifier::classify( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36', array( 'accept' => 'text/html,application/xhtml+xml' ) );
ok( 'human' === $c['class'], 'chrome is human' );
$c = FMPPC_Classifier::classify( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)' );
ok( 'ai' === $c['class'] && 'GPTBot' === $c['name'], 'gptbot' );
$c = FMPPC_Classifier::classify( 'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)' );
ok( 'ai' === $c['class'] && 'ClaudeBot' === $c['name'], 'claudebot' );
$c = FMPPC_Classifier::classify( 'curl/8.5.0' );
ok( 'automation' === $c['class'] && 'curl' === $c['name'], 'curl' );
$c = FMPPC_Classifier::classify( '' );
ok( 'automation' === $c['class'], 'empty UA' );
$c = FMPPC_Classifier::classify( 'facebookexternalhit/1.1' );
ok( 'social' === $c['class'], 'fb preview' );
$c = FMPPC_Classifier::classify( 'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)' );
ok( 'seo' === $c['class'], 'ahrefs' );
$c = FMPPC_Classifier::classify( 'SomethingNewCrawler/2.0 (+https://example.com/bot)' );
ok( 'bot' === $c['class'] && 'SomethingNewCrawler' === $c['name'], 'unknown bot word' );
$c = FMPPC_Classifier::classify( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', array(), '66.249.66.1' );
ok( 'search' === $c['class'] && true === $c['verified'], 'googlebot real IP verifies: ' . json_encode( $c ) );
$c = FMPPC_Classifier::classify( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', array(), '203.0.113.9' );
ok( 'automation' === $c['class'] && $c['impersonation'], 'googlebot fake IP demoted: ' . json_encode( $c ) );
$c = FMPPC_Classifier::classify( 'Mozilla/5.0 (compatible; Googlebot/2.1)', array(), '' );
ok( 'search' === $c['class'] && null === $c['verified'], 'no ip: claim kept, unverified' );
$c = FMPPC_Classifier::classify( 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120 Safari/537.36', array( 'accept' => 'application/json' ) );
ok( 'automation' === $c['class'], 'browser UA with json accept is a tool' );

// x402 envelopes
$cfg = array( 'pay_to' => '0x13C3C28c64cc9d60eEb8a675F3fe27eBC6a4Cd09', 'price_usd' => 0.01, 'facilitator_url' => '', 'facilitator_auth' => '', 'mpp_secret' => 'unit-secret', 'realm' => 'example.test', 'site_name' => 'Example', 'manifest_url' => 'https://example.test/.well-known/x402.json' );
$x = new FMPPC_X402( $cfg );
$env = $x->envelope_v2( 'https://example.test/post/', 'Example: page' );
ok( 2 === $env['x402Version'] && '10000' === $env['accepts'][0]['amount'] && 'eip155:8453' === $env['accepts'][0]['network'], 'v2 envelope' );
$b = $x->body_v1( 'https://example.test/post/', 'Example: page' );
ok( 1 === $b['x402Version'] && 'base' === $b['accepts'][0]['network'] && '10000' === $b['accepts'][0]['maxAmountRequired'] && 'https://example.test/post/' === $b['accepts'][0]['resource'], 'v1 body' );
ok( FMPPC_X402::usd_to_units( 0.005 ) === '5000' && FMPPC_X402::usd_to_units( '0.01' ) === '10000', 'units' );
ok( $x->facilitator_url() === 'https://facilitator.payai.network', 'default facilitator' );

// MPP challenge + credential round trip
$hdr = $x->mpp_challenge( 'Example: page' );
ok( preg_match( '/^Payment id="([^"]+)", realm="example.test", method="evm", intent="charge", expires="([^"]+)", request="([^"]+)"$/', $hdr, $m ) === 1, 'mpp challenge header' );
$req = FMPPC_X402::b64url_json( $m[3] );
ok( '10000' === $req['amount'] && 8453 === $req['methodDetails']['chainId'], 'mpp request' );
$nonce = FMPPC_Keccak::hex( $m[1] . 'example.test' );
$cred = array( 'challenge' => array( 'id' => $m[1], 'realm' => 'example.test', 'method' => 'evm', 'intent' => 'charge', 'expires' => $m[2], 'request' => $m[3] ), 'payload' => array( 'type' => 'authorization', 'from' => '0x' . str_repeat( 'a', 40 ), 'to' => $cfg['pay_to'], 'value' => '10000', 'validAfter' => '0', 'validBefore' => (string) ( time() + 300 ), 'nonce' => $nonce, 'signature' => '0xsig' ) );
$h = array( 'authorization' => 'Payment ' . FMPPC_X402::b64url( json_encode( $cred ) ) );
$p = $x->extract_payment( $h, 'https://example.test/post/' );
ok( 'mpp' === $p['rail'] && empty( $p['error'] ) && 2 === $p['payload']['x402Version'] && $nonce === $p['payload']['payload']['authorization']['nonce'], 'mpp credential accepted: ' . json_encode( $p ) );
ok( null === $x->precheck( $p, 'https://example.test/post/' ), 'mpp precheck' );
$cred2 = $cred; $cred2['payload']['nonce'] = '0x' . str_repeat( '1', 64 );
$p2 = $x->extract_payment( array( 'authorization' => 'Payment ' . FMPPC_X402::b64url( json_encode( $cred2 ) ) ), 'u' );
ok( 'verification-failed' === $p2['error'], 'mpp bad nonce rejected' );
$cred3 = $cred; $cred3['challenge']['id'] = 'tampered';
$p3 = $x->extract_payment( array( 'authorization' => 'Payment ' . FMPPC_X402::b64url( json_encode( $cred3 ) ) ), 'u' );
ok( 'invalid-challenge' === $p3['error'], 'mpp tampered id rejected' );

// v2 + v1 extraction & precheck
$auth = array( 'from' => '0x' . str_repeat( 'b', 40 ), 'to' => $cfg['pay_to'], 'value' => '10000', 'validAfter' => '0', 'validBefore' => (string) ( time() + 100 ), 'nonce' => '0x' . str_repeat( '2', 64 ) );
$v2 = array( 'x402Version' => 2, 'accepted' => $x->requirements_v2( 'u' ), 'payload' => array( 'signature' => '0xs', 'authorization' => $auth ) );
$p = $x->extract_payment( array( 'payment-signature' => base64_encode( json_encode( $v2 ) ) ), 'u' );
ok( 'x402v2' === $p['rail'] && null === $x->precheck( $p, 'u' ), 'v2 ok' );
$v1 = array( 'x402Version' => 1, 'scheme' => 'exact', 'network' => 'base', 'payload' => array( 'signature' => '0xs', 'authorization' => $auth ) );
$p = $x->extract_payment( array( 'x-payment' => base64_encode( json_encode( $v1 ) ) ), 'u' );
ok( 'x402v1' === $p['rail'] && null === $x->precheck( $p, 'u' ), 'v1 ok' );
$bad = $v2; $bad['payload']['authorization']['to'] = '0x' . str_repeat( 'c', 40 );
$p = $x->extract_payment( array( 'payment-signature' => base64_encode( json_encode( $bad ) ) ), 'u' );
ok( 'wrong_pay_to' === $x->precheck( $p, 'u' ), 'wrong payTo' );
$bad = $v2; $bad['payload']['authorization']['value'] = '9999';
$p = $x->extract_payment( array( 'payment-signature' => base64_encode( json_encode( $bad ) ) ), 'u' );
ok( 'wrong_amount' === $x->precheck( $p, 'u' ), 'wrong amount' );
$bad = $v2; $bad['payload']['authorization']['validBefore'] = '1';
$p = $x->extract_payment( array( 'payment-signature' => base64_encode( json_encode( $bad ) ) ), 'u' );
ok( 'authorization_expired' === $x->precheck( $p, 'u' ), 'expired' );
ok( 'malformed_payment_signature' === $x->extract_payment( array( 'payment-signature' => 'zzz' ), 'u' )['error'], 'garbage header' );

// facilitator flow: verify then settle, tx required
$GLOBALS['facilitator'] = function ( $url, $body ) {
	if ( str_ends_with( $url, '/verify' ) ) { return array( 'body' => json_encode( array( 'isValid' => true, 'payer' => '0xpayer' ) ) ); }
	return array( 'body' => json_encode( array( 'success' => true, 'transaction' => '0xtx', 'network' => 'eip155:8453', 'payer' => '0xpayer' ) ) );
};
$p = $x->extract_payment( array( 'payment-signature' => base64_encode( json_encode( $v2 ) ) ), 'https://example.test/x' );
$r = $x->verify_and_settle( $p, 'https://example.test/x' );
ok( $r['ok'] && '0xtx' === $r['tx'], 'settle ok' );
ok( 2 === count( $GLOBALS['posts'] ) && 2 === $GLOBALS['posts'][0][1]['x402Version'] && 'eip155:8453' === $GLOBALS['posts'][0][1]['paymentRequirements']['network'] && str_ends_with( $GLOBALS['posts'][1][0], '/settle' ), 'v2 facilitator body' );
$GLOBALS['posts'] = array();
$p = $x->extract_payment( array( 'x-payment' => base64_encode( json_encode( $v1 ) ) ), 'https://example.test/x' );
$r = $x->verify_and_settle( $p, 'https://example.test/x' );
ok( $r['ok'] && 1 === $GLOBALS['posts'][0][1]['x402Version'] && 'base' === $GLOBALS['posts'][0][1]['paymentRequirements']['network'] && '10000' === $GLOBALS['posts'][0][1]['paymentRequirements']['maxAmountRequired'], 'v1 facilitator body' );
$GLOBALS['facilitator'] = function ( $url ) { return array( 'body' => json_encode( str_ends_with( $url, '/verify' ) ? array( 'isValid' => true ) : array( 'success' => true, 'transaction' => '' ) ) ); };
$r = $x->verify_and_settle( $p, 'u' );
ok( ! $r['ok'] && 'settlement_failed' === $r['reason'], 'empty tx is not a sale' );
$GLOBALS['facilitator'] = function () { return array( 'body' => json_encode( array( 'isValid' => false, 'invalidReason' => 'insufficient_funds' ) ) ); };
$r = $x->verify_and_settle( $p, 'u' );
ok( ! $r['ok'] && 'insufficient_funds' === $r['reason'], 'invalidReason surfaced' );
$GLOBALS['facilitator'] = function () { return new WP_Error( 'x', 'timeout' ); };
$r = $x->verify_and_settle( $p, 'u' );
ok( 'facilitator_unreachable' === $r['reason'], 'unreachable' );

echo "pass=$pass fail=$fail\n";

// ── Pro: intel merge into the catalog ──
$GLOBALS['options'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = false ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); }
function apply_filters( $h, $v ) { return 'fmppc_catalog' === $h ? FMPPC_Pro::merge_catalog( $v ) : $v; }
function wp_clear_scheduled_hook( $h ) {}
$pass0 = $pass; $fail0 = $fail;
$GLOBALS['options']['fmppc_intel'] = array( 'version' => '2026-10-16', 'agents' => array(
	array( 'name' => 'GPTBot', 'class' => 'ai', 'match' => array( 'gptbot', 'gptbot-next' ), 'verify' => array() ),
	array( 'name' => 'Brand New Crawler', 'class' => 'seo', 'match' => array( 'brandnewcrawler/' ), 'verify' => array() ),
) );
$merged = FMPPC_Pro::merge_catalog( FMPPC_Classifier::shipped() );
$names  = array_column( $merged, 'name' );
ok( in_array( 'Brand New Crawler', $names, true ), 'intel appends new agent' );
ok( count( array_keys( $names, 'GPTBot', true ) ) === 1, 'intel does not duplicate known agent' );
$g = $merged[ array_search( 'GPTBot', $names, true ) ];
ok( in_array( 'gptbot-next', $g['match'], true ) && in_array( 'gptbot', $g['match'], true ), 'intel extends match list' );
ok( FMPPC_Pro::enabled( array( 'pro_key' => 'fmppc_' . str_repeat( 'a', 30 ) ) ) && ! FMPPC_Pro::enabled( array( 'pro_key' => 'nope' ) ), 'pro key shape' );
// gate exemptions
require __DIR__ . '/../includes/class-fmppc-gate.php';
$pass1 = $pass; $fail1 = $fail;
$cases = array(
	'/robots.txt' => true, '/robots.txt.html' => false, '/robots.txt.anything' => false,
	'/llms.txt' => true, '/llms.txtx' => false, '/agents.txt' => true, '/favicon.ico' => true,
	'/sitemap.xml' => true, '/wp-sitemap.xml' => true, '/wp-sitemap-posts-post-1.xml' => true, '/sitemap_index.xml' => true, '/post-sitemap.xml' => true,
	'/sitemapfoo' => false, '/sitemap.xml/x' => false, '/a/sitemap.xml' => false,
	'/.well-known/x402.json' => true, '/.well-known/../post' => false, '/.well-known/%2e%2e/post' => false, '/.well-known/%2E%2E/post' => false, '/foo%2fbar' => false,
	'/wp-login.php' => true, '/wp-login.phpx' => false, '/wp-admin/' => true, '/wp-admin/admin-ajax.php' => true, '/wp-adminx' => false, '/xmlrpc.php' => true, '/xmlrpc.php/x' => false, '/wp-cron.php' => true,
	'/pricing' => true, '/pricing/' => true, '/pricing/plans' => true, '/pricing-secret' => false,
	'//robots.txt' => false, '/post' => false, '/' => false,
);
foreach ( $cases as $path => $want ) {
	ok( FMPPC_Gate::path_is_free( $path, array( '/pricing' ) ) === $want, 'exempt ' . $path );
}
ok( FMPPC_Gate::path_is_free( '/docs/a', array( '/docs/' ) ) && ! FMPPC_Gate::path_is_free( '/docs', array( '/docs/' ) ) && ! FMPPC_Gate::path_is_free( '/docsx', array( '/docs/' ) ), 'trailing-slash allow path' );
echo "gate exempt pass=" . ( $pass - $pass1 ) . " fail=" . ( $fail - $fail1 ) . "\n";
echo "pro pass=" . ( $pass - $pass0 ) . " fail=" . ( $fail - $fail0 ) . "\n";
exit( $fail ? 1 : 0 );
