<?php
/**
 * Request classifier: who is asking, and is it who it says it is.
 *
 * Classes: human, search, social, monitor, seo, ai, automation, bot.
 *
 * @package ForgeMesh_Pay_Per_Crawl
 */

defined( 'ABSPATH' ) || exit;

final class FMPPC_Classifier {

	/**
	 * Known agents. Order matters: first match wins. Patterns are case-insensitive
	 * substrings of the User-Agent. `verify` lists reverse-DNS suffixes that prove
	 * the operator (search engines publish these); unverifiable claims are demoted.
	 */
	public static function catalog() {
		return array(
			// Search engines (free by default; verified by rDNS).
			array( 'name' => 'Googlebot', 'class' => 'search', 'match' => array( 'googlebot' ), 'verify' => array( '.googlebot.com', '.google.com' ) ),
			array( 'name' => 'Google-InspectionTool', 'class' => 'search', 'match' => array( 'google-inspectiontool' ), 'verify' => array( '.googlebot.com', '.google.com' ) ),
			array( 'name' => 'GoogleOther', 'class' => 'ai', 'match' => array( 'googleother' ), 'verify' => array( '.googlebot.com', '.google.com' ) ),
			array( 'name' => 'Bingbot', 'class' => 'search', 'match' => array( 'bingbot' ), 'verify' => array( '.search.msn.com' ) ),
			array( 'name' => 'DuckDuckBot', 'class' => 'search', 'match' => array( 'duckduckbot' ), 'verify' => array() ),
			array( 'name' => 'Applebot', 'class' => 'search', 'match' => array( 'applebot' ), 'verify' => array( '.applebot.apple.com' ) ),
			array( 'name' => 'YandexBot', 'class' => 'search', 'match' => array( 'yandexbot', 'yandex.com/bots' ), 'verify' => array( '.yandex.ru', '.yandex.net', '.yandex.com' ) ),
			array( 'name' => 'Baiduspider', 'class' => 'search', 'match' => array( 'baiduspider' ), 'verify' => array( '.baidu.com', '.baidu.jp' ) ),
			array( 'name' => 'Yahoo Slurp', 'class' => 'search', 'match' => array( 'yahoo! slurp' ), 'verify' => array( '.crawl.yahoo.net' ) ),
			array( 'name' => 'SeznamBot', 'class' => 'search', 'match' => array( 'seznambot' ), 'verify' => array( '.seznam.cz' ) ),
			array( 'name' => 'Qwantbot', 'class' => 'search', 'match' => array( 'qwantbot', 'qwantify' ), 'verify' => array() ),

			// AI crawlers and assistants (paid by default).
			array( 'name' => 'GPTBot', 'class' => 'ai', 'match' => array( 'gptbot' ) ),
			array( 'name' => 'ChatGPT-User', 'class' => 'ai', 'match' => array( 'chatgpt-user' ) ),
			array( 'name' => 'OAI-SearchBot', 'class' => 'ai', 'match' => array( 'oai-searchbot' ) ),
			array( 'name' => 'ClaudeBot', 'class' => 'ai', 'match' => array( 'claudebot', 'anthropic-ai' ) ),
			array( 'name' => 'Claude-User', 'class' => 'ai', 'match' => array( 'claude-user' ) ),
			array( 'name' => 'Claude-SearchBot', 'class' => 'ai', 'match' => array( 'claude-searchbot' ) ),
			array( 'name' => 'PerplexityBot', 'class' => 'ai', 'match' => array( 'perplexitybot' ) ),
			array( 'name' => 'Perplexity-User', 'class' => 'ai', 'match' => array( 'perplexity-user' ) ),
			array( 'name' => 'Google-Extended', 'class' => 'ai', 'match' => array( 'google-extended' ) ),
			array( 'name' => 'Gemini-Deep-Research', 'class' => 'ai', 'match' => array( 'gemini-deep-research' ) ),
			array( 'name' => 'Amazonbot', 'class' => 'ai', 'match' => array( 'amazonbot' ) ),
			array( 'name' => 'Applebot-Extended', 'class' => 'ai', 'match' => array( 'applebot-extended' ) ),
			array( 'name' => 'Meta-ExternalAgent', 'class' => 'ai', 'match' => array( 'meta-externalagent', 'meta-externalfetcher', 'facebookbot' ) ),
			array( 'name' => 'Bytespider', 'class' => 'ai', 'match' => array( 'bytespider', 'tiktokspider' ) ),
			array( 'name' => 'CCBot', 'class' => 'ai', 'match' => array( 'ccbot' ) ),
			array( 'name' => 'cohere-ai', 'class' => 'ai', 'match' => array( 'cohere-ai', 'cohere-training-data-crawler' ) ),
			array( 'name' => 'MistralAI-User', 'class' => 'ai', 'match' => array( 'mistralai-user' ) ),
			array( 'name' => 'DuckAssistBot', 'class' => 'ai', 'match' => array( 'duckassistbot' ) ),
			array( 'name' => 'YouBot', 'class' => 'ai', 'match' => array( 'youbot' ) ),
			array( 'name' => 'Diffbot', 'class' => 'ai', 'match' => array( 'diffbot' ) ),
			array( 'name' => 'ImagesiftBot', 'class' => 'ai', 'match' => array( 'imagesiftbot' ) ),
			array( 'name' => 'Omgili', 'class' => 'ai', 'match' => array( 'omgili', 'webzio' ) ),
			array( 'name' => 'AI2Bot', 'class' => 'ai', 'match' => array( 'ai2bot' ) ),
			array( 'name' => 'PetalBot', 'class' => 'ai', 'match' => array( 'petalbot' ) ),
			array( 'name' => 'Timpibot', 'class' => 'ai', 'match' => array( 'timpibot' ) ),
			array( 'name' => 'iAskBot', 'class' => 'ai', 'match' => array( 'iaskspider', 'iaskbot' ) ),
			array( 'name' => 'Kagibot', 'class' => 'ai', 'match' => array( 'kagibot' ) ),
			array( 'name' => 'DeepSeekBot', 'class' => 'ai', 'match' => array( 'deepseekbot' ) ),
			array( 'name' => 'Grok', 'class' => 'ai', 'match' => array( 'xai-grok', 'grokbot' ) ),
			array( 'name' => 'Brave Search', 'class' => 'ai', 'match' => array( 'bravebot' ) ),
			array( 'name' => 'Firecrawl', 'class' => 'ai', 'match' => array( 'firecrawl' ) ),
			array( 'name' => 'Jina Reader', 'class' => 'ai', 'match' => array( 'jina', 'readerbot' ) ),
			array( 'name' => 'Exa', 'class' => 'ai', 'match' => array( 'exabot', 'exa.ai' ) ),
			array( 'name' => 'Tavily', 'class' => 'ai', 'match' => array( 'tavily' ) ),
			array( 'name' => 'Browserbase', 'class' => 'ai', 'match' => array( 'browserbase' ) ),

			// SEO crawlers (paid by default: commercial, high volume).
			array( 'name' => 'AhrefsBot', 'class' => 'seo', 'match' => array( 'ahrefsbot' ) ),
			array( 'name' => 'SemrushBot', 'class' => 'seo', 'match' => array( 'semrushbot' ) ),
			array( 'name' => 'MJ12bot', 'class' => 'seo', 'match' => array( 'mj12bot' ) ),
			array( 'name' => 'DotBot', 'class' => 'seo', 'match' => array( 'dotbot' ) ),
			array( 'name' => 'DataForSeoBot', 'class' => 'seo', 'match' => array( 'dataforseobot' ) ),
			array( 'name' => 'BLEXBot', 'class' => 'seo', 'match' => array( 'blexbot' ) ),
			array( 'name' => 'Screaming Frog', 'class' => 'seo', 'match' => array( 'screaming frog' ) ),
			array( 'name' => 'SiteAuditBot', 'class' => 'seo', 'match' => array( 'siteauditbot' ) ),

			// Social link previews (free by default: humans see these).
			array( 'name' => 'Facebook preview', 'class' => 'social', 'match' => array( 'facebookexternalhit' ) ),
			array( 'name' => 'Twitterbot', 'class' => 'social', 'match' => array( 'twitterbot' ) ),
			array( 'name' => 'LinkedInBot', 'class' => 'social', 'match' => array( 'linkedinbot' ) ),
			array( 'name' => 'Slackbot', 'class' => 'social', 'match' => array( 'slackbot', 'slack-imgproxy' ) ),
			array( 'name' => 'Discordbot', 'class' => 'social', 'match' => array( 'discordbot' ) ),
			array( 'name' => 'TelegramBot', 'class' => 'social', 'match' => array( 'telegrambot' ) ),
			array( 'name' => 'WhatsApp', 'class' => 'social', 'match' => array( 'whatsapp' ) ),
			array( 'name' => 'Pinterestbot', 'class' => 'social', 'match' => array( 'pinterestbot', 'pinterest/' ) ),
			array( 'name' => 'Redditbot', 'class' => 'social', 'match' => array( 'redditbot' ) ),
			array( 'name' => 'Mastodon', 'class' => 'social', 'match' => array( 'mastodon' ) ),
			array( 'name' => 'Bluesky', 'class' => 'social', 'match' => array( 'bluesky cardyb' ) ),
			array( 'name' => 'iMessage preview', 'class' => 'social', 'match' => array( 'facebot twitterbot' ) ),

			// Monitors and feed readers (free by default).
			array( 'name' => 'UptimeRobot', 'class' => 'monitor', 'match' => array( 'uptimerobot' ) ),
			array( 'name' => 'Pingdom', 'class' => 'monitor', 'match' => array( 'pingdom' ) ),
			array( 'name' => 'StatusCake', 'class' => 'monitor', 'match' => array( 'statuscake' ) ),
			array( 'name' => 'Better Uptime', 'class' => 'monitor', 'match' => array( 'betteruptime', 'better uptime' ) ),
			array( 'name' => 'Feed reader', 'class' => 'monitor', 'match' => array( 'feedly', 'feedbin', 'inoreader', 'newsblur', 'feedfetcher' ) ),
			array( 'name' => 'Internet Archive', 'class' => 'monitor', 'match' => array( 'archive.org_bot', 'ia_archiver' ) ),
			array( 'name' => 'WordPress', 'class' => 'monitor', 'match' => array( 'wordpress/' ) ),

			// Generic automation (paid by default).
			array( 'name' => 'curl', 'class' => 'automation', 'match' => array( 'curl/' ) ),
			array( 'name' => 'wget', 'class' => 'automation', 'match' => array( 'wget/' ) ),
			array( 'name' => 'python-requests', 'class' => 'automation', 'match' => array( 'python-requests', 'python-urllib', 'aiohttp', 'httpx/' ) ),
			array( 'name' => 'Scrapy', 'class' => 'automation', 'match' => array( 'scrapy' ) ),
			array( 'name' => 'Go-http-client', 'class' => 'automation', 'match' => array( 'go-http-client' ) ),
			array( 'name' => 'Node fetch', 'class' => 'automation', 'match' => array( 'node-fetch', 'undici', 'axios/', 'got (' ) ),
			array( 'name' => 'Java HTTP', 'class' => 'automation', 'match' => array( 'java/', 'okhttp', 'apache-httpclient' ) ),
			array( 'name' => 'Headless browser', 'class' => 'automation', 'match' => array( 'headlesschrome', 'phantomjs', 'puppeteer', 'playwright', 'selenium' ) ),
			array( 'name' => 'HTTPie', 'class' => 'automation', 'match' => array( 'httpie' ) ),
			array( 'name' => 'PostmanRuntime', 'class' => 'automation', 'match' => array( 'postmanruntime' ) ),
			array( 'name' => 'libwww', 'class' => 'automation', 'match' => array( 'libwww', 'lwp-trivial' ) ),
			array( 'name' => 'x402 client', 'class' => 'automation', 'match' => array( 'x402', 'mpp/' ) ),
		);
	}

	public static function classes() {
		return array( 'human', 'search', 'social', 'monitor', 'seo', 'ai', 'automation', 'bot' );
	}

	/**
	 * @param string $ua      User-Agent.
	 * @param array  $headers Lowercased header map (accept, sec-fetch-mode, sec-ch-ua, ...).
	 * @param string $ip      Remote address, for search-engine verification.
	 * @return array {class, name, verified, impersonation}
	 */
	public static function classify( $ua, array $headers = array(), $ip = '' ) {
		$ua_l = strtolower( trim( (string) $ua ) );

		foreach ( self::catalog() as $entry ) {
			foreach ( $entry['match'] as $needle ) {
				if ( false !== strpos( $ua_l, $needle ) ) {
					$class    = $entry['class'];
					$verified = null;
					$imp      = false;
					if ( ! empty( $entry['verify'] ) && $ip ) {
						$verified = self::verify_rdns( $ip, $entry['verify'] );
						if ( false === $verified ) {
							$class = 'automation'; // claimed a search engine, failed rDNS.
							$imp   = true;
						}
					}
					return array( 'class' => $class, 'name' => $entry['name'], 'verified' => $verified, 'impersonation' => $imp );
				}
			}
		}

		if ( '' === $ua_l ) {
			return array( 'class' => 'automation', 'name' => 'Empty User-Agent', 'verified' => null, 'impersonation' => false );
		}

		// Generic bot words in an unknown UA.
		if ( preg_match( '/\b(bot|crawler|spider|scraper|fetcher|crawl|agent|archiver|indexer)\b/i', $ua_l ) ) {
			return array( 'class' => 'bot', 'name' => self::short_name( $ua ), 'verified' => null, 'impersonation' => false );
		}

		// Browser-shaped UA.
		$browser = ( false !== strpos( $ua_l, 'mozilla/' ) ) && preg_match( '/(chrome|safari|firefox|edg|opera|opr|msie|trident|samsungbrowser|ucbrowser)/', $ua_l );
		if ( $browser ) {
			$accept = isset( $headers['accept'] ) ? strtolower( $headers['accept'] ) : '';
			// A real navigation sends text/html; API-ish Accept with a browser UA is a tool.
			if ( '' !== $accept && false === strpos( $accept, 'text/html' ) && false === strpos( $accept, '*/*' ) && false === strpos( $accept, 'image/' ) ) {
				return array( 'class' => 'automation', 'name' => 'Browser-UA tool', 'verified' => null, 'impersonation' => false );
			}
			return array( 'class' => 'human', 'name' => '', 'verified' => null, 'impersonation' => false );
		}

		return array( 'class' => 'bot', 'name' => self::short_name( $ua ), 'verified' => null, 'impersonation' => false );
	}

	/** First token of an unknown UA, for the bot-name column. */
	public static function short_name( $ua ) {
		$ua = trim( (string) $ua );
		if ( preg_match( '/^([A-Za-z0-9_.\-]+)/', $ua, $m ) ) {
			return substr( $m[1], 0, 60 );
		}
		return substr( $ua, 0, 60 );
	}

	/**
	 * Reverse + forward DNS check, cached per IP for a day. Returns true/false,
	 * or null when DNS is unavailable (then the claim is taken at face value).
	 */
	public static function verify_rdns( $ip, array $suffixes ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return null;
		}
		$key = 'fmppc_rdns_' . md5( $ip . '|' . implode( ',', $suffixes ) );
		if ( function_exists( 'get_transient' ) ) {
			$cached = get_transient( $key );
			if ( false !== $cached && '' !== $cached ) {
				return 'yes' === $cached;
			}
		}
		$result = null;
		$host   = @gethostbyaddr( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $host && $host !== $ip ) {
			$host_l = strtolower( rtrim( $host, '.' ) );
			$ok     = false;
			foreach ( $suffixes as $s ) {
				$s = strtolower( $s );
				if ( substr( $host_l, -strlen( $s ) ) === $s ) {
					$ok = true;
					break;
				}
			}
			if ( $ok ) {
				$forward = @gethostbynamel( $host_l ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$ok      = is_array( $forward ) && in_array( $ip, $forward, true );
				if ( ! $ok && false !== strpos( $ip, ':' ) ) {
					$records = @dns_get_record( $host_l, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					if ( is_array( $records ) ) {
						foreach ( $records as $r ) {
							if ( isset( $r['ipv6'] ) && inet_pton( $r['ipv6'] ) === inet_pton( $ip ) ) {
								$ok = true;
								break;
							}
						}
					}
				}
			}
			$result = $ok;
		} elseif ( $host === $ip || false === $host ) {
			$result = false; // no PTR at all: not a search engine.
		}
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $key, null === $result ? '' : ( $result ? 'yes' : 'no' ), DAY_IN_SECONDS );
		}
		return $result;
	}
}
