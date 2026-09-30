<?php
/**
 * Finds registered routes that look like a route path a client asked for but
 * which doesn't exist, so an unknown-route error can name the close matches.
 *
 * @package HM\RestAbility
 */

namespace HM\RouteSuggestions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns up to `$limit` registered routes that resemble the requested path.
 *
 * Each result is in readable form, with every named regex group replaced by a
 * `{name}` placeholder. A route the requested path is a prefix of ranks
 * highest, then routes sharing whole segments or words with the part of the
 * path after its namespace, with a little tolerance for typos and plurals.
 *
 * @param string   $route    Requested REST route path.
 * @param string[] $patterns Registered route patterns, as returned by
 *                           array_keys( WP_REST_Server::get_routes() ).
 * @param int      $limit    Maximum number of suggestions.
 * @return string[] Readable route paths, best match first.
 */
function suggest_routes( string $route, array $patterns, int $limit = 5 ): array {
	$wanted = normalise_route( $route );

	if ( '/' === $wanted ) {
		return [];
	}

	$known_prefix  = longest_literal_prefix( $wanted, $patterns );
	$tail          = substr( $wanted, strlen( $known_prefix ) );
	$tail_words    = route_words( $tail );
	$tail_segments = route_segments( $tail );

	$scored = [];

	foreach ( $patterns as $pattern ) {
		$readable = readable_route( $pattern );
		$lower    = strtolower( $readable );

		if ( $lower === $wanted || '/' === $lower ) {
			continue;
		}

		$score = 0;

		if ( 0 === strpos( $lower, $wanted . '/' ) ) {
			$score += 5;
		}

		$candidate_words    = route_words( $lower );
		$candidate_segments = route_segments( $lower );

		foreach ( $tail_words as $word ) {
			$score += best_word_score( $word, $candidate_words );
		}

		$score += 2 * count( array_intersect( $tail_segments, $candidate_segments ) );

		if ( $score < 2 ) {
			continue;
		}

		$scored[ $readable ] = [
			'score'    => $score,
			'segments' => count( $candidate_segments ),
			'path'     => $lower,
		];
	}

	uasort( $scored, static function ( array $a, array $b ): int {
		return [ $b['score'], $a['segments'], $a['path'] ] <=> [ $a['score'], $b['segments'], $b['path'] ];
	} );

	return array_slice( array_keys( $scored ), 0, $limit );
}

/**
 * Builds the message for an unknown route, naming any close matches.
 *
 * @param string   $route       Requested REST route path.
 * @param string[] $suggestions Readable routes from suggest_routes().
 * @return string
 */
function no_route_message( string $route, array $suggestions ): string {
	$message = sprintf( 'No route matches %s.', $route );

	if ( $suggestions ) {
		$message .= ' Did you mean: ' . implode( ', ', $suggestions ) . '?';

		if ( false !== strpos( implode( '', $suggestions ), '{' ) ) {
			$message .= ' Replace each {name} placeholder with a value; OPTIONS on a route describes its parameters.';
		}
	}

	return $message . ' GET / lists every route, and GET /<namespace> (for example GET /wp/v2) lists one namespace.';
}

/**
 * Rewrites a route pattern's regex groups as `{name}` placeholders.
 *
 * Core writes URL parameters as named groups such as `(?P<id>[\d]+)`. The
 * character class inside one can hold brackets and parentheses, and a group
 * can nest another, so this walks the pattern rather than using a regex.
 *
 * @param string $pattern Registered route pattern.
 * @return string
 */
function readable_route( string $pattern ): string {
	$out    = '';
	$length = strlen( $pattern );
	$i      = 0;

	while ( $i < $length ) {
		if ( '(' !== $pattern[ $i ] ) {
			$out .= $pattern[ $i ];
			++$i;
			continue;
		}

		$name = 'param';
		if ( preg_match( '/^\(\?P?<(\w+)>/', substr( $pattern, $i, 64 ), $named ) ) {
			$name = $named[1];
		}

		$out .= '{' . $name . '}';
		$i    = group_end( $pattern, $i ) + 1;
	}

	return $out;
}

/**
 * Returns the offset of the parenthesis closing the group opening at `$start`.
 *
 * Escaped characters and anything inside a character class are skipped, so a
 * `)` there doesn't end the group early. An unterminated group ends at the
 * end of the string.
 *
 * @param string $pattern Route pattern.
 * @param int    $start   Offset of the opening parenthesis.
 * @return int
 */
function group_end( string $pattern, int $start ): int {
	$length   = strlen( $pattern );
	$depth    = 0;
	$in_class = false;

	for ( $i = $start; $i < $length; $i++ ) {
		$char = $pattern[ $i ];

		if ( '\\' === $char ) {
			++$i;
			continue;
		}

		if ( $in_class ) {
			$in_class = ']' !== $char;
			continue;
		}

		if ( '[' === $char ) {
			$in_class = true;
		} elseif ( '(' === $char ) {
			++$depth;
		} elseif ( ')' === $char ) {
			--$depth;
			if ( 0 === $depth ) {
				return $i;
			}
		}
	}

	return $length - 1;
}

/**
 * Lowercases a route path and trims trailing slashes, keeping the leading one.
 *
 * @param string $route Route path.
 * @return string
 */
function normalise_route( string $route ): string {
	return '/' . strtolower( trim( $route, '/' ) );
}

/**
 * Returns the longest registered literal route that the path sits under.
 *
 * A literal route has no regex groups, so this picks out the namespace index
 * (`/wp/v2`) or a collection (`/wp/v2/posts`) the path extends. Words from
 * that prefix are then ignored when scoring, since every route under it
 * shares them.
 *
 * @param string   $wanted   Normalised route path.
 * @param string[] $patterns Registered route patterns.
 * @return string The prefix, or '' when none applies.
 */
function longest_literal_prefix( string $wanted, array $patterns ): string {
	$longest = '';

	foreach ( $patterns as $pattern ) {
		if ( false !== strpos( $pattern, '(' ) ) {
			continue;
		}

		$literal = normalise_route( $pattern );

		if ( '/' === $literal || strlen( $literal ) <= strlen( $longest ) ) {
			continue;
		}

		if ( 0 === strpos( $wanted, $literal . '/' ) ) {
			$longest = $literal;
		}
	}

	return $longest;
}

/**
 * Splits a route path into its literal segments, singular and lowercase.
 *
 * Placeholders are left out, so `/wp/v2/global-styles/{id}` gives
 * `wp`, `v2` and `global-style`.
 *
 * @param string $path Route path, or part of one.
 * @return string[]
 */
function route_segments( string $path ): array {
	$segments = [];

	foreach ( explode( '/', strtolower( trim( $path, '/' ) ) ) as $segment ) {
		if ( '' === $segment || '{' === $segment[0] ) {
			continue;
		}

		$segments[] = rtrim( $segment, 's' );
	}

	return $segments;
}

/**
 * Splits a route path into comparable words.
 *
 * Segments are split on hyphens, underscores and dots; placeholders, short
 * fragments (which covers namespace versions like `v2`) and a trailing `s`
 * are dropped, so `block-patterns` and `wp_pattern_category` both yield
 * `pattern`.
 *
 * @param string $path Route path, or part of one.
 * @return string[] Unique words.
 */
function route_words( string $path ): array {
	$path  = preg_replace( '/\{\w+\}/', ' ', strtolower( $path ) );
	$words = [];

	foreach ( preg_split( '/[\/\-_.\s]+/', $path ) as $word ) {
		$word = rtrim( $word, 's' );

		if ( strlen( $word ) >= 3 ) {
			$words[ $word ] = true;
		}
	}

	return array_keys( $words );
}

/**
 * Scores how well one requested word matches any of a route's words.
 *
 * @param string   $word            Word from the requested path.
 * @param string[] $candidate_words Words from a registered route.
 * @return int 4 for an exact match, 2 for a containing or near match, else 0.
 */
function best_word_score( string $word, array $candidate_words ): int {
	$best = 0;

	foreach ( $candidate_words as $candidate ) {
		if ( $word === $candidate ) {
			return 4;
		}

		if ( strlen( $word ) >= 4 && strlen( $candidate ) >= 4 ) {
			if ( false !== strpos( $candidate, $word ) || false !== strpos( $word, $candidate ) ) {
				$best = 2;
				continue;
			}
		}

		if ( strlen( $word ) >= 5 && levenshtein( $word, $candidate ) <= 2 ) {
			$best = 2;
		}
	}

	return $best;
}
