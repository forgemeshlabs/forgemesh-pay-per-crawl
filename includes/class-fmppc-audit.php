<?php
/**
 * AI Crawler Audit: a report built entirely from the site's own ledger.
 *
 * The free summary is always shown. The full report (operator breakdown, cost and
 * value estimates, hash-chained evidence log, licensing notices) is unlocked by a
 * one-time token bought at proxy.forgemesh.io/wp/audit. The token is an Ed25519
 * signature over {sku, host, iat, exp}; it is verified here, offline, with the
 * public key below. Nothing about the site is sent anywhere.
 *
 * @package ForgeMesh_Pay_Per_Crawl
 */

defined( 'ABSPATH' ) || exit;

final class FMPPC_Audit {

	const OPTION   = 'fmppc_audit_token';
	const PUB_HEX  = '31ef45314f4e31405abdb9643d1f7184d5ef99519bdd46228fce69b5c30dbfb9';
	const BUY_URL  = 'https://proxy.forgemesh.io/wp/audit';
	const FALLBACK_PAGE_BYTES = 80000;

	/** Normalise a host the same way the token issuer does. */
	public static function host( $h = null ) {
		$h = null === $h ? (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) : (string) $h;
		return preg_replace( '/^www\./', '', strtolower( trim( $h ) ) );
	}

	/**
	 * @return array {ok, error?, host?, exp?}
	 */
	public static function verify( $token, $host = null ) {
		$token = trim( (string) $token );
		if ( '' === $token || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return array( 'ok' => false, 'error' => '' === $token ? 'empty' : 'libsodium missing' );
		}
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) ) {
			return array( 'ok' => false, 'error' => 'malformed' );
		}
		$payload = self::b64u_decode( $parts[0] );
		$sig     = self::b64u_decode( $parts[1] );
		if ( false === $payload || false === $sig || 64 !== strlen( $sig ) ) {
			return array( 'ok' => false, 'error' => 'malformed' );
		}
		try {
			if ( ! sodium_crypto_sign_verify_detached( $sig, $payload, hex2bin( self::PUB_HEX ) ) ) {
				return array( 'ok' => false, 'error' => 'bad signature' );
			}
		} catch ( Exception $e ) {
			return array( 'ok' => false, 'error' => 'bad signature' );
		}
		$j = json_decode( $payload, true );
		if ( ! is_array( $j ) || ( $j['sku'] ?? '' ) !== 'wp-audit' ) {
			return array( 'ok' => false, 'error' => 'wrong sku' );
		}
		if ( (int) ( $j['exp'] ?? 0 ) < time() ) {
			return array( 'ok' => false, 'error' => 'expired' );
		}
		if ( self::host( $host ) !== (string) ( $j['host'] ?? '' ) ) {
			return array( 'ok' => false, 'error' => 'host mismatch', 'host' => (string) ( $j['host'] ?? '' ) );
		}
		return array( 'ok' => true, 'host' => $j['host'], 'exp' => (int) $j['exp'] );
	}

	public static function unlocked() {
		$t = get_option( self::OPTION, '' );
		return '' !== $t && self::verify( $t )['ok'];
	}

	private static function b64u_decode( $s ) {
		$s = strtr( $s, '-_', '+/' );
		return base64_decode( str_pad( $s, strlen( $s ) + ( 4 - strlen( $s ) % 4 ) % 4, '=' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/**
	 * What each named crawler is, who runs it and what it does with pages.
	 * Purposes: training, ai-search, user-fetch, agent-retrieval, search-index, seo, archive, social, monitor, scraping.
	 * Only published facts; "docs" is the operator's own page. No invented contacts.
	 */
	public static function operators() {
		$g = 'https://developers.google.com/search/docs/crawling-indexing/overview-google-crawlers';
		$o = 'https://platform.openai.com/docs/bots';
		$a = 'https://support.anthropic.com/en/articles/8896518-does-anthropic-crawl-data-from-the-web-and-how-can-site-owners-block-the-crawler';
		$p = 'https://docs.perplexity.ai/guides/bots';
		return array(
			'Googlebot'            => array( 'op' => 'Google', 'purpose' => 'search-index', 'docs' => 'https://developers.google.com/search/docs/crawling-indexing/verifying-googlebot', 'robots' => 'Googlebot', 'note' => 'Indexes pages for Google Search. Blocking it removes you from Google.' ),
			'Google-InspectionTool' => array( 'op' => 'Google', 'purpose' => 'search-index', 'docs' => $g, 'robots' => 'Google-InspectionTool', 'note' => 'Search Console URL inspection and rich-result tests.' ),
			'GoogleOther'          => array( 'op' => 'Google', 'purpose' => 'training', 'docs' => $g, 'robots' => 'GoogleOther', 'note' => 'Generic Google crawler for research and development, including one-off and AI uses. Not Search.' ),
			'Google-Extended'      => array( 'op' => 'Google', 'purpose' => 'training', 'docs' => $g, 'robots' => 'Google-Extended', 'note' => 'A robots.txt token, not a separate crawler: it controls whether Google may use your content for Gemini and AI model training. Search is unaffected.' ),
			'Gemini-Deep-Research' => array( 'op' => 'Google', 'purpose' => 'user-fetch', 'docs' => $g, 'robots' => 'Google-Extended', 'note' => 'Fetches pages on behalf of a Gemini user running a research task.' ),
			'Bingbot'              => array( 'op' => 'Microsoft', 'purpose' => 'search-index', 'docs' => 'https://www.bing.com/webmasters/help/which-crawlers-does-bing-use-8c184ec0', 'robots' => 'Bingbot', 'note' => 'Indexes for Bing, and feeds Copilot answers. Verified by reverse DNS.' ),
			'DuckDuckBot'          => array( 'op' => 'DuckDuckGo', 'purpose' => 'search-index', 'docs' => 'https://duckduckgo.com/duckduckgo-help-pages/results/duckduckbot/', 'robots' => 'DuckDuckBot', 'note' => 'DuckDuckGo results.' ),
			'Applebot'             => array( 'op' => 'Apple', 'purpose' => 'search-index', 'docs' => 'https://support.apple.com/en-us/119829', 'robots' => 'Applebot', 'note' => 'Siri and Spotlight suggestions. Verified by reverse DNS.' ),
			'Applebot-Extended'    => array( 'op' => 'Apple', 'purpose' => 'training', 'docs' => 'https://support.apple.com/en-us/119829', 'robots' => 'Applebot-Extended', 'note' => 'Token controlling whether Applebot-fetched content may train Apple foundation models.' ),
			'YandexBot'            => array( 'op' => 'Yandex', 'purpose' => 'search-index', 'docs' => 'https://yandex.com/support/webmaster/robot-workings/check-yandex-robots.html', 'robots' => 'Yandex', 'note' => 'Yandex search.' ),
			'Baiduspider'          => array( 'op' => 'Baidu', 'purpose' => 'search-index', 'docs' => 'https://help.baidu.com/question?prod_id=99&class=0&id=3001', 'robots' => 'Baiduspider', 'note' => 'Baidu search.' ),
			'GPTBot'               => array( 'op' => 'OpenAI', 'purpose' => 'training', 'docs' => $o, 'robots' => 'GPTBot', 'note' => 'Collects pages that may be used to train OpenAI models. Blocking it does not affect ChatGPT search or browsing.' ),
			'ChatGPT-User'         => array( 'op' => 'OpenAI', 'purpose' => 'user-fetch', 'docs' => $o, 'robots' => 'ChatGPT-User', 'note' => 'Fetches a page because a ChatGPT user asked about it. One request per user action, not bulk crawling.' ),
			'OAI-SearchBot'        => array( 'op' => 'OpenAI', 'purpose' => 'ai-search', 'docs' => $o, 'robots' => 'OAI-SearchBot', 'note' => 'Builds the index behind ChatGPT search results. Blocking it removes you from ChatGPT search, not from training.' ),
			'ClaudeBot'            => array( 'op' => 'Anthropic', 'purpose' => 'training', 'docs' => $a, 'robots' => 'ClaudeBot', 'note' => 'Crawls for Anthropic model training data. Respects robots.txt.' ),
			'Claude-User'          => array( 'op' => 'Anthropic', 'purpose' => 'user-fetch', 'docs' => $a, 'robots' => 'Claude-User', 'note' => 'Fetches a page because a Claude user asked about it.' ),
			'Claude-SearchBot'     => array( 'op' => 'Anthropic', 'purpose' => 'ai-search', 'docs' => $a, 'robots' => 'Claude-SearchBot', 'note' => 'Indexes for Claude search results.' ),
			'PerplexityBot'        => array( 'op' => 'Perplexity', 'purpose' => 'ai-search', 'docs' => $p, 'robots' => 'PerplexityBot', 'note' => 'Indexes pages to cite in Perplexity answers. Not used for training, per Perplexity.' ),
			'Perplexity-User'      => array( 'op' => 'Perplexity', 'purpose' => 'user-fetch', 'docs' => $p, 'robots' => 'Perplexity-User', 'note' => 'Fetches a page on behalf of a user query. Perplexity documents that it generally ignores robots.txt for these user-initiated fetches.' ),
			'Amazonbot'            => array( 'op' => 'Amazon', 'purpose' => 'training', 'docs' => 'https://developer.amazon.com/amazonbot', 'robots' => 'Amazonbot', 'note' => 'Improves Alexa and other Amazon services; may be used for AI.' ),
			'Meta-ExternalAgent'   => array( 'op' => 'Meta', 'purpose' => 'training', 'docs' => 'https://developers.facebook.com/docs/sharing/webmasters/web-crawlers', 'robots' => 'Meta-ExternalAgent', 'note' => 'Crawls for Meta AI model training and product improvement.' ),
			'Bytespider'           => array( 'op' => 'ByteDance', 'purpose' => 'training', 'docs' => '', 'robots' => 'Bytespider', 'note' => 'ByteDance (TikTok) crawler widely reported to feed AI training. No published operator documentation or opt-out page.' ),
			'CCBot'                => array( 'op' => 'Common Crawl', 'purpose' => 'archive', 'docs' => 'https://commoncrawl.org/ccbot', 'robots' => 'CCBot', 'note' => 'Builds the open Common Crawl corpus, which many AI labs have trained on. Non-profit; respects robots.txt.' ),
			'cohere-ai'            => array( 'op' => 'Cohere', 'purpose' => 'training', 'docs' => '', 'robots' => 'cohere-ai', 'note' => 'No published crawler documentation.' ),
			'MistralAI-User'       => array( 'op' => 'Mistral AI', 'purpose' => 'user-fetch', 'docs' => 'https://docs.mistral.ai/robots/', 'robots' => 'MistralAI-User', 'note' => 'Fetches a page on behalf of a Le Chat user.' ),
			'DuckAssistBot'        => array( 'op' => 'DuckDuckGo', 'purpose' => 'ai-search', 'docs' => 'https://duckduckgo.com/duckduckgo-help-pages/results/duckassistbot/', 'robots' => 'DuckAssistBot', 'note' => 'Sources for DuckAssist AI answers.' ),
			'YouBot'               => array( 'op' => 'You.com', 'purpose' => 'ai-search', 'docs' => 'https://about.you.com/youbot/', 'robots' => 'YouBot', 'note' => 'You.com AI search.' ),
			'Diffbot'              => array( 'op' => 'Diffbot', 'purpose' => 'scraping', 'docs' => 'https://www.diffbot.com/', 'robots' => 'Diffbot', 'note' => 'Commercial web-data extraction resold to customers.' ),
			'ImagesiftBot'         => array( 'op' => 'Hive', 'purpose' => 'scraping', 'docs' => 'https://imagesift.com/about', 'robots' => 'ImagesiftBot', 'note' => 'Image indexing for a reverse-image product.' ),
			'Omgili'               => array( 'op' => 'Webz.io', 'purpose' => 'scraping', 'docs' => 'https://webz.io/bot/', 'robots' => 'omgili', 'note' => 'Commercial web-data feeds resold to customers, including AI companies.' ),
			'AI2Bot'               => array( 'op' => 'Allen Institute for AI', 'purpose' => 'training', 'docs' => 'https://allenai.org/crawler', 'robots' => 'AI2Bot', 'note' => 'Open research models (OLMo).' ),
			'PetalBot'             => array( 'op' => 'Huawei', 'purpose' => 'search-index', 'docs' => 'https://aspiegel.com/petalbot', 'robots' => 'PetalBot', 'note' => 'Petal Search and AI features.' ),
			'Timpibot'             => array( 'op' => 'Timpi', 'purpose' => 'search-index', 'docs' => 'https://timpi.io', 'robots' => 'Timpibot', 'note' => 'Decentralised search index.' ),
			'iAskBot'              => array( 'op' => 'iAsk', 'purpose' => 'ai-search', 'docs' => '', 'robots' => 'iAskBot', 'note' => 'AI answer engine.' ),
			'Kagibot'              => array( 'op' => 'Kagi', 'purpose' => 'search-index', 'docs' => 'https://kagi.com/bot', 'robots' => 'Kagibot', 'note' => 'Paid search engine; also feeds its assistant.' ),
			'DeepSeekBot'          => array( 'op' => 'DeepSeek', 'purpose' => 'training', 'docs' => '', 'robots' => 'DeepSeekBot', 'note' => 'No published crawler documentation.' ),
			'Grok'                 => array( 'op' => 'xAI', 'purpose' => 'user-fetch', 'docs' => '', 'robots' => '', 'note' => 'Fetches on behalf of Grok users; no published crawler documentation.' ),
			'Brave Search'         => array( 'op' => 'Brave', 'purpose' => 'search-index', 'docs' => 'https://search.brave.com/help/brave-search-crawler', 'robots' => 'Bravebot', 'note' => 'Independent index; also sold as a search API to AI companies.' ),
			'Firecrawl'            => array( 'op' => 'Firecrawl (agent tooling)', 'purpose' => 'agent-retrieval', 'docs' => 'https://www.firecrawl.dev/', 'robots' => '', 'note' => 'Developer service that fetches pages for AI agents. The agent behind it could be an x402 payer.' ),
			'Jina Reader'          => array( 'op' => 'Jina AI (agent tooling)', 'purpose' => 'agent-retrieval', 'docs' => 'https://jina.ai/reader/', 'robots' => '', 'note' => 'Converts pages to text for LLM agents on request.' ),
			'Exa'                  => array( 'op' => 'Exa (agent tooling)', 'purpose' => 'agent-retrieval', 'docs' => 'https://exa.ai/', 'robots' => '', 'note' => 'Neural search API for agents.' ),
			'Tavily'               => array( 'op' => 'Tavily (agent tooling)', 'purpose' => 'agent-retrieval', 'docs' => 'https://tavily.com/', 'robots' => '', 'note' => 'Search and fetch API for agents.' ),
			'Browserbase'          => array( 'op' => 'Browserbase (agent tooling)', 'purpose' => 'agent-retrieval', 'docs' => 'https://www.browserbase.com/', 'robots' => '', 'note' => 'Hosted browsers driven by agents.' ),
			'AhrefsBot'            => array( 'op' => 'Ahrefs', 'purpose' => 'seo', 'docs' => 'https://ahrefs.com/robot', 'robots' => 'AhrefsBot', 'note' => 'Backlink and SEO index sold to marketers. Blocking it hides your site from Ahrefs users, not from Google.' ),
			'SemrushBot'           => array( 'op' => 'Semrush', 'purpose' => 'seo', 'docs' => 'https://www.semrush.com/bot/', 'robots' => 'SemrushBot', 'note' => 'SEO data product.' ),
			'MJ12bot'              => array( 'op' => 'Majestic', 'purpose' => 'seo', 'docs' => 'https://mj12bot.com/', 'robots' => 'MJ12bot', 'note' => 'Backlink index.' ),
			'DotBot'               => array( 'op' => 'Moz', 'purpose' => 'seo', 'docs' => 'https://moz.com/help/moz-procedures/crawlers/dotbot', 'robots' => 'dotbot', 'note' => 'Moz link index.' ),
			'DataForSeoBot'        => array( 'op' => 'DataForSEO', 'purpose' => 'seo', 'docs' => 'https://dataforseo.com/dataforseo-bot', 'robots' => 'DataForSeoBot', 'note' => 'SEO data API resold to tools.' ),
			'BLEXBot'              => array( 'op' => 'WebMeUp', 'purpose' => 'seo', 'docs' => 'https://webmeup.com/', 'robots' => 'BLEXBot', 'note' => 'Backlink index.' ),
			'Internet Archive'     => array( 'op' => 'Internet Archive', 'purpose' => 'archive', 'docs' => 'https://archive.org/details/archive.org_bot', 'robots' => 'ia_archiver', 'note' => 'Wayback Machine. Non-profit preservation.' ),
			'python-requests'      => array( 'op' => 'Unknown (bare library)', 'purpose' => 'scraping', 'docs' => '', 'robots' => '', 'note' => 'The default user agent of the Python requests library. Someone wrote a script and did not identify it.' ),
			'curl'                 => array( 'op' => 'Unknown (bare tool)', 'purpose' => 'scraping', 'docs' => '', 'robots' => '', 'note' => 'Command-line fetches, often tests or scripts.' ),
			'Scrapy'               => array( 'op' => 'Unknown (framework)', 'purpose' => 'scraping', 'docs' => 'https://scrapy.org/', 'robots' => '', 'note' => 'Purpose-built scraping framework; usually bulk.' ),
			'Go-http-client'       => array( 'op' => 'Unknown (bare library)', 'purpose' => 'scraping', 'docs' => '', 'robots' => '', 'note' => 'Default Go HTTP client user agent.' ),
			'Headless browser'     => array( 'op' => 'Unknown (automation)', 'purpose' => 'scraping', 'docs' => '', 'robots' => '', 'note' => 'Headless Chrome, Playwright or Puppeteer: renders the page like a person would, usually to scrape or to act as an agent.' ),
			'x402 client'          => array( 'op' => 'An x402-capable agent', 'purpose' => 'agent-retrieval', 'docs' => 'https://x402.org', 'robots' => '', 'note' => 'A client that understands HTTP 402 and can pay. These are the only crawlers that pay today.' ),
		);
	}

	public static function purpose_label( $p ) {
		$m = array(
			'training'        => __( 'AI model training', 'forgemesh-pay-per-crawl' ),
			'ai-search'       => __( 'AI search index', 'forgemesh-pay-per-crawl' ),
			'user-fetch'      => __( 'Fetch for an AI user', 'forgemesh-pay-per-crawl' ),
			'agent-retrieval' => __( 'Agent retrieval', 'forgemesh-pay-per-crawl' ),
			'search-index'    => __( 'Search engine index', 'forgemesh-pay-per-crawl' ),
			'seo'             => __( 'SEO data product', 'forgemesh-pay-per-crawl' ),
			'archive'         => __( 'Archive / research corpus', 'forgemesh-pay-per-crawl' ),
			'social'          => __( 'Link preview', 'forgemesh-pay-per-crawl' ),
			'monitor'         => __( 'Monitoring', 'forgemesh-pay-per-crawl' ),
			'scraping'        => __( 'Scraping / unidentified script', 'forgemesh-pay-per-crawl' ),
		);
		return $m[ $p ] ?? __( 'Unknown', 'forgemesh-pay-per-crawl' );
	}

	/**
	 * Average HTML bytes of the pages bots fetch most: rendered post content plus a
	 * fixed allowance for theme markup. No request is made (the plugin would gate
	 * its own fetch); cached a day.
	 */
	const THEME_OVERHEAD_BYTES = 45000;

	public static function avg_page_bytes( array $paths ) {
		$cached = get_transient( 'fmppc_audit_page_bytes' );
		if ( false !== $cached ) {
			return (int) $cached;
		}
		$sizes = array();
		foreach ( array_slice( $paths, 0, 5 ) as $p ) {
			$pid = url_to_postid( home_url( $p['path'] ) );
			if ( $pid ) {
				$post = get_post( $pid );
				if ( $post ) {
					$sizes[] = strlen( (string) apply_filters( 'the_content', $post->post_content ) ) + self::THEME_OVERHEAD_BYTES; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
				}
			}
		}
		$avg = $sizes ? (int) ( array_sum( $sizes ) / count( $sizes ) ) : self::FALLBACK_PAGE_BYTES;
		set_transient( 'fmppc_audit_page_bytes', $avg, DAY_IN_SECONDS );
		return $avg;
	}

	/** Everything the report needs, from the ledger. */
	public static function build( $days ) {
		$s        = FMPPC::settings();
		$t        = FMPPC_Log::totals( $days );
		$bots     = FMPPC_Log::by_bot( $days, 200 );
		$paths    = FMPPC_Log::top_paths( $days, 20 );
		$ops      = self::operators();
		$search   = array();
		foreach ( FMPPC_Classifier::catalog() as $e ) {
			if ( 'search' === $e['class'] ) {
				$search[ $e['name'] ] = true;
			}
		}
		$by_op = array();
		$impostors = array();
		$paid_class_hits = 0;
		foreach ( $bots as $b ) {
			if ( 'human' === $b['ua_class'] ) {
				continue;
			}
			$policy = $s['class_policy'][ $b['ua_class'] ] ?? 'paid';
			if ( 'paid' === $policy ) {
				$paid_class_hits += (int) $b['hits'];
			}
			$impostor = isset( $search[ $b['bot_name'] ] ) && 'search' !== $b['ua_class'];
			if ( $impostor ) {
				$impostors[] = $b;
			}
			$k   = $impostor ? null : ( $ops[ $b['bot_name'] ] ?? null );
			$op  = $impostor ? sprintf( /* translators: claimed bot name */ __( 'Impostor claiming to be %s', 'forgemesh-pay-per-crawl' ), $b['bot_name'] ) : ( $k ? $k['op'] : ( 'automation' === $b['ua_class'] ? __( 'Unidentified scripts', 'forgemesh-pay-per-crawl' ) : $b['bot_name'] ) );
			if ( ! isset( $by_op[ $op ] ) ) {
				$by_op[ $op ] = array( 'op' => $op, 'hits' => 0, 'paid' => 0, 'revenue_units' => 0, 'sources' => 0, 'bots' => array(), 'purposes' => array(), 'docs' => $k['docs'] ?? '', 'robots' => array() );
			}
			$by_op[ $op ]['hits']          += (int) $b['hits'];
			$by_op[ $op ]['paid']          += (int) $b['paid'];
			$by_op[ $op ]['revenue_units'] += (int) $b['revenue_units'];
			$by_op[ $op ]['sources']        = max( $by_op[ $op ]['sources'], (int) $b['sources'] );
			$by_op[ $op ]['bots'][]         = array( 'name' => $b['bot_name'], 'class' => $b['ua_class'], 'hits' => (int) $b['hits'], 'verified' => $impostor ? '0' : $b['verified'], 'purpose' => $k['purpose'] ?? ( $impostor ? 'scraping' : '' ), 'note' => $k['note'] ?? ( $impostor ? __( 'Reverse DNS did not match the claimed operator; treated as a script.', 'forgemesh-pay-per-crawl' ) : '' ) );
			if ( $impostor ) {
				$by_op[ $op ]['purposes']['scraping'] = true;
			}
			if ( $k ) {
				$by_op[ $op ]['purposes'][ $k['purpose'] ] = true;
				if ( '' !== $k['robots'] ) {
					$by_op[ $op ]['robots'][ $k['robots'] ] = true;
				}
			}
		}
		usort( $by_op, function ( $a, $b ) { return $b['hits'] <=> $a['hits']; } );
		$page_bytes = self::avg_page_bytes( $paths );
		return array(
			'days'            => $days,
			'from'            => gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS ),
			'to'              => gmdate( 'Y-m-d' ),
			'totals'          => $t,
			'by_class'        => FMPPC_Log::by_class( $days ),
			'by_operator'     => $by_op,
			'impostors'       => $impostors,
			'paths'           => $paths,
			'paid_class_hits' => $paid_class_hits,
			'page_bytes'      => $page_bytes,
			'bot_bytes'       => (int) $t['bot_hits'] * $page_bytes,
			'value'           => array( '0.005' => $paid_class_hits * 5000, '0.01' => $paid_class_hits * 10000, '0.05' => $paid_class_hits * 50000 ),
			'price_usd'       => (float) $s['price_usd'],
			'mode'            => $s['mode'],
		);
	}

	/**
	 * Evidence log: every bot hit in the period as CSV, each row carrying a running
	 * SHA-256 chain over the previous chain value and the row's fields. The final
	 * value is the digest printed on the report; changing any row breaks the chain.
	 *
	 * @return array {csv, digest, rows}
	 */
	public static function evidence( $days ) {
		$rows  = array_reverse( FMPPC_Log::export_rows( $days ) ); // oldest first so the chain reads forward.
		$chain = hash( 'sha256', 'fmppc-evidence-v1:' . self::host() );
		$safe  = function ( $v ) {
			$v = (string) $v;
			return ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) ? "'" . $v : $v;
		};
		$fh = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $fh, array( 'ts_utc', 'class', 'bot', 'verified', 'method', 'path', 'status', 'rail', 'amount_usd', 'tx_hash', 'payer', 'source_hash', 'user_agent', 'chain_sha256' ) );
		$n = 0;
		foreach ( $rows as $r ) {
			if ( 'human' === $r['ua_class'] ) {
				continue;
			}
			$fields = array( $r['ts'], $r['ua_class'], $r['bot_name'], (string) $r['verified'], $r['method'], $r['path'], $r['status'], $r['rail'], FMPPC_X402::units_to_usd( $r['amount_units'] ), $r['tx_hash'], $r['payer'], $r['ip_hash'], $r['ua'] );
			$chain  = hash( 'sha256', $chain . "\n" . implode( "\x1f", $fields ) );
			fputcsv( $fh, array_merge( array_map( $safe, $fields ), array( $chain ) ) );
			$n++;
		}
		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return array( 'csv' => $csv, 'digest' => $chain, 'rows' => $n );
	}

	/** Prefilled licensing notice for one operator; plain text the owner can paste. */
	public static function notice( array $op, array $report ) {
		$site = self::host();
		$bots = implode( ', ', array_map( function ( $b ) { return $b['name'] . ' (' . $b['hits'] . ')'; }, $op['bots'] ) );
		$lines = array(
			sprintf( 'To: %s', $op['op'] ),
			sprintf( 'Re: automated access to %s, %s to %s', $site, $report['from'], $report['to'] ),
			'',
			sprintf( 'Our server logs show %s requests from crawlers you operate (%s) during this period.', number_format_i18n( $op['hits'] ), $bots ),
			sprintf( 'The pages fetched are original content published on %s. We have not granted a licence for their use in model training, search indexing for AI answers, or any commercial data product.', $site ),
			'',
			sprintf( 'From %s the site answers automated requests of this kind with HTTP 402 Payment Required at $%s per page, payable in USDC on Base via the x402 protocol (see https://%s/.well-known/x402.json).', $report['to'], number_format( $report['price_usd'], 3 ), $site ),
			'Continued access on those terms is welcome. If you prefer a flat licence for the site, reply to this notice.',
			'',
			sprintf( 'A hash-chained log of the requests is retained. Report digest: %s', $report['digest'] ?? '' ),
			'' !== $op['docs'] ? sprintf( 'Your published crawler documentation: %s', $op['docs'] ) : '',
		);
		return implode( "\n", array_filter( $lines, function ( $l ) { return null !== $l; } ) );
	}
}
