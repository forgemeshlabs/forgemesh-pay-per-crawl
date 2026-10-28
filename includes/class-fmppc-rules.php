<?php
/**
 * Pricing rules (Pro): price or policy by content group (post type, category,
 * tag, path) and by bot (name, class, purpose). First matching rule wins;
 * otherwise the per-bot override, class policy and site price apply as before.
 *
 * Rules are stored in settings['price_rules'] and only take effect while a
 * verified Pro key is present; without one the site behaves exactly as the
 * free plugin, so a lapsed key degrades to the single site price, never to open.
 *
 * @package ForgeMesh_Pay_Per_Crawl
 */

defined( 'ABSPATH' ) || exit;

final class FMPPC_Rules {

	const TYPES   = array( 'post_type', 'category', 'tag', 'path', 'bot', 'class', 'purpose' );
	const ACTIONS = array( 'paid', 'free', 'block' );
	const MAX     = 40;

	public static function content_types() {
		return array( 'post_type', 'category', 'tag', 'path' );
	}

	/** Rules are live only with a verified Pro key. */
	public static function active( array $s ) {
		if ( empty( $s['price_rules'] ) || ! FMPPC_Pro::enabled( $s ) ) {
			return false;
		}
		$st = FMPPC_Pro::state();
		return ! empty( $st['ok'] );
	}

	public static function sanitize( $raw ) {
		$out = array();
		foreach ( (array) $raw as $r ) {
			$type  = sanitize_key( $r['type'] ?? '' );
			$value = trim( sanitize_text_field( (string) ( $r['value'] ?? '' ) ) );
			$act   = sanitize_key( $r['action'] ?? 'paid' );
			if ( ! in_array( $type, self::TYPES, true ) || '' === $value || ! in_array( $act, self::ACTIONS, true ) ) {
				continue;
			}
			if ( 'path' === $type ) {
				$value = '/' . ltrim( $value, '/' );
			} elseif ( in_array( $type, array( 'category', 'tag', 'post_type' ), true ) ) {
				$value = sanitize_title( $value );
			}
			$price = isset( $r['price_usd'] ) && '' !== $r['price_usd'] ? max( 0.005, min( 100, (float) $r['price_usd'] ) ) : null;
			$out[] = array( 'type' => $type, 'value' => $value, 'action' => $act, 'price_usd' => 'paid' === $act ? $price : null );
			if ( count( $out ) >= self::MAX ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * First rule matching this request and classification, or null.
	 *
	 * @param array  $rules settings['price_rules']
	 * @param array  $c     classifier result {class, name}
	 * @param string $path  request path
	 */
	public static function match( array $rules, array $c, $path ) {
		$post    = ( function_exists( 'is_singular' ) && is_singular() ) ? get_queried_object() : null;
		$purpose = null;
		foreach ( $rules as $r ) {
			$v = $r['value'];
			switch ( $r['type'] ) {
				case 'bot':
					$hit = '' !== $c['name'] && 0 === strcasecmp( $c['name'], $v );
					break;
				case 'class':
					$hit = $c['class'] === $v;
					break;
				case 'purpose':
					if ( null === $purpose ) {
						$ops     = FMPPC_Audit::operators();
						$purpose = $ops[ $c['name'] ]['purpose'] ?? ( 'automation' === $c['class'] ? 'scraping' : '' );
					}
					$hit = $purpose === $v;
					break;
				case 'post_type':
					$hit = $post && $post->post_type === $v;
					break;
				case 'category':
					$hit = $post && has_category( $v, $post );
					break;
				case 'tag':
					$hit = $post && has_tag( $v, $post );
					break;
				case 'path':
					$hit = self::path_match( $path, $v );
					break;
				default:
					$hit = false;
			}
			if ( $hit ) {
				return $r;
			}
		}
		return null;
	}

	/** Anchored: exact path, or prefix when the rule ends with "/" or "*". */
	public static function path_match( $path, $rule ) {
		$path = '/' . ltrim( (string) $path, '/' );
		if ( '*' === substr( $rule, -1 ) ) {
			return 0 === strpos( $path, rtrim( $rule, '*' ) );
		}
		if ( '/' === substr( $rule, -1 ) && '/' !== $rule ) {
			return $path === rtrim( $rule, '/' ) || 0 === strpos( $path, $rule );
		}
		return $path === $rule || $path === $rule . '/';
	}

	/**
	 * Manifest resources: one per distinct content price group, so indexes see
	 * "recipes cost X, guides cost Y" instead of a single site price.
	 * Returns array of {label, description, price_usd, pattern}.
	 */
	public static function manifest_groups( array $rules, $home ) {
		$groups = array();
		foreach ( $rules as $r ) {
			if ( ! in_array( $r['type'], self::content_types(), true ) || 'paid' !== $r['action'] || null === $r['price_usd'] ) {
				continue;
			}
			switch ( $r['type'] ) {
				case 'path':
					$pattern = $home . ltrim( rtrim( $r['value'], '*' ), '/' ) . ( '*' === substr( $r['value'], -1 ) || '/' === substr( $r['value'], -1 ) ? '{path}' : '' );
					$label   = sprintf( 'pages under %s', $r['value'] );
					break;
				case 'post_type':
					$pattern = $home . '{path}';
					$pt      = get_post_type_object( $r['value'] );
					$label   = sprintf( 'post type "%s"', $pt ? $pt->labels->name : $r['value'] );
					break;
				case 'category':
					$term    = get_term_by( 'slug', $r['value'], 'category' );
					$pattern = $term ? get_term_link( $term ) . '{path}' : $home . '{path}';
					$pattern = is_wp_error( $pattern ) ? $home . '{path}' : $pattern;
					$label   = sprintf( 'posts in category "%s"', $term ? $term->name : $r['value'] );
					break;
				default:
					$pattern = $home . '{path}';
					$label   = sprintf( 'posts tagged "%s"', $r['value'] );
			}
			$groups[] = array( 'label' => $label, 'price_usd' => (float) $r['price_usd'], 'pattern' => $pattern );
		}
		return $groups;
	}
}
