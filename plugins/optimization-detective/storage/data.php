<?php
/**
 * Metrics storage data.
 *
 * @package optimization-detective
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Gets the freshness age (TTL) for a given URL Metric.
 *
 * When a URL Metric expires it is eligible to be replaced by a newer one if its viewport lies within the same breakpoint.
 *
 * @since 0.1.0
 * @access private
 *
 * @return int Expiration TTL in seconds.
 */
function od_get_url_metric_freshness_ttl(): int {
	/**
	 * Filters the freshness age (TTL) for a given URL Metric.
	 *
	 * The freshness TTL must be at least zero, in which it considers URL Metrics to always be stale.
	 * In practice, the value should be at least an hour.
	 *
	 * @since 0.1.0
	 *
	 * @param int $ttl Expiration TTL in seconds. Defaults to 1 day.
	 */
	$freshness_ttl = (int) apply_filters( 'od_url_metric_freshness_ttl', DAY_IN_SECONDS );

	if ( $freshness_ttl < 0 ) {
		_doing_it_wrong(
			__FUNCTION__,
			esc_html(
				sprintf(
					/* translators: %s is the TTL freshness */
					__( 'Freshness TTL must be at least zero, but saw "%s".', 'optimization-detective' ),
					$freshness_ttl
				)
			),
			''
		);
		$freshness_ttl = 0;
	}

	return $freshness_ttl;
}

/**
 * Gets the normalized query vars for the current request.
 *
 * This is used as a cache key for stored URL Metrics.
 *
 * TODO: For non-singular requests, consider adding the post IDs from The Loop to ensure publishing a new post will invalidate the cache.
 *
 * @since 0.1.0
 * @access private
 *
 * @return array<string, mixed> Normalized query vars.
 */
function od_get_normalized_query_vars(): array {
	global $wp;

	// Note that the order of this array is naturally normalized since it is
	// assembled by iterating over public_query_vars.
	$normalized_query_vars = $wp->query_vars;

	// Normalize unbounded query vars.
	if ( is_404() ) {
		$normalized_query_vars = array(
			'error' => 404,
		);
	}

	// Vary URL Metrics by whether the user is logged in since additional elements may be present.
	if ( is_user_logged_in() ) {
		$normalized_query_vars['user_logged_in'] = true;
	}

	return $normalized_query_vars;
}

/**
 * Get the URL for the current request.
 *
 * This is essentially the REQUEST_URI prefixed by the scheme and host for the home URL.
 * This is needed in particular due to subdirectory installs.
 *
 * @since 0.1.1
 * @access private
 *
 * @return string Current URL.
 */
function od_get_current_url(): string {
	$parsed_url = wp_parse_url( home_url() );
	if ( ! is_array( $parsed_url ) ) {
		$parsed_url = array();
	}

	if ( ! isset( $parsed_url['scheme'] ) ) {
		$parsed_url['scheme'] = is_ssl() ? 'https' : 'http';
	}
	if ( ! isset( $parsed_url['host'] ) ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$parsed_url['host'] = isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : 'localhost';
	}

	$current_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];
	if ( isset( $parsed_url['port'] ) ) {
		$current_url .= ':' . $parsed_url['port'];
	}
	$current_url .= '/';

	if ( isset( $_SERVER['REQUEST_URI'] ) ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$current_url .= ltrim( wp_unslash( $_SERVER['REQUEST_URI'] ), '/' );
	}
	return esc_url_raw( $current_url );
}

/**
 * Gets slug for URL Metrics.
 *
 * A slug is the hash of the normalized query vars.
 *
 * @since 0.1.0
 * @access private
 *
 * @see od_get_normalized_query_vars()
 *
 * @param array<string, mixed> $query_vars Normalized query vars.
 * @return string Slug.
 */
function od_get_url_metrics_slug( array $query_vars ): string {
	return md5( (string) wp_json_encode( $query_vars ) );
}

/**
 * Computes HMAC for storing URL Metrics for a specific slug.
 *
 * This is used in the REST API to authenticate the storage of new URL Metrics from a given URL.
 *
 * @since 0.8.0
 * @access private
 *
 * @see od_verify_url_metrics_storage_hmac()
 * @see od_get_url_metrics_slug()
 * @todo This should also include an ETag as a parameter. See <https://github.com/WordPress/performance/issues/1466>.
 *
 * @param string   $slug                Slug (hash of normalized query vars).
 * @param string   $url                 URL.
 * @param int|null $cache_purge_post_id Cache purge post ID.
 * @return string HMAC.
 */
function od_get_url_metrics_storage_hmac( string $slug, string $url, ?int $cache_purge_post_id = null ): string {
	$action = "store_url_metric:$slug:$url:$cache_purge_post_id";
	return wp_hash( $action, 'nonce' );
}

/**
 * Verifies HMAC for storing URL Metrics for a specific slug.
 *
 * @since 0.8.0
 * @access private
 *
 * @see od_get_url_metrics_storage_hmac()
 * @see od_get_url_metrics_slug()
 *
 * @param string   $hmac                HMAC.
 * @param string   $slug                Slug (hash of normalized query vars).
 * @param String   $url                 URL.
 * @param int|null $cache_purge_post_id Cache purge post ID.
 * @return bool Whether the HMAC is valid.
 */
function od_verify_url_metrics_storage_hmac( string $hmac, string $slug, string $url, ?int $cache_purge_post_id = null ): bool {
	return hash_equals( od_get_url_metrics_storage_hmac( $slug, $url, $cache_purge_post_id ), $hmac );
}

/**
 * Gets the minimum allowed viewport aspect ratio for URL Metrics.
 *
 * @since 0.6.0
 * @access private
 *
 * @return float Minimum viewport aspect ratio for URL Metrics.
 */
function od_get_minimum_viewport_aspect_ratio(): float {
	/**
	 * Filters the minimum allowed viewport aspect ratio for URL Metrics.
	 *
	 * The 0.4 default value is intended to accommodate the phone with the greatest known aspect
	 * ratio at 21:9 when rotated 90 degrees to 9:21 (0.429).
	 *
	 * @since 0.6.0
	 *
	 * @param float $minimum_viewport_aspect_ratio Minimum viewport aspect ratio.
	 */
	return (float) apply_filters( 'od_minimum_viewport_aspect_ratio', 0.4 );
}

/**
 * Gets the maximum allowed viewport aspect ratio for URL Metrics.
 *
 * @since 0.6.0
 * @access private
 *
 * @return float Maximum viewport aspect ratio for URL Metrics.
 */
function od_get_maximum_viewport_aspect_ratio(): float {
	/**
	 * Filters the maximum allowed viewport aspect ratio for URL Metrics.
	 *
	 * The 2.5 default value is intended to accommodate the phone with the greatest known aspect
	 * ratio at 21:9 (2.333).
	 *
	 * @since 0.6.0
	 *
	 * @param float $maximum_viewport_aspect_ratio Maximum viewport aspect ratio.
	 */
	return (float) apply_filters( 'od_maximum_viewport_aspect_ratio', 2.5 );
}

/**
 * Gets the breakpoint max widths to group URL Metrics for various viewports.
 *
 * Each number represents the maximum width (inclusive) for a given breakpoint. So if there is one number, 480, then
 * this means there will be two viewport groupings, one for 0<=480, and another >480. If instead there were three
 * provided breakpoints (320, 480, 576) then this means there will be four groups:
 *
 *  1. 0-320 (small smartphone)
 *  2. 321-480 (normal smartphone)
 *  3. 481-576 (phablets)
 *  4. >576 (desktop)
 *
 * The default breakpoints are reused from Gutenberg where the _breakpoints.scss file includes these variables:
 *
 *     $break-medium: 782px; // adminbar goes big
 *     $break-small: 600px;
 *     $break-mobile: 480px;
 *
 * These breakpoints appear to be used the most in media queries that affect frontend styles.
 *
 * This array may be empty in which case there are no responsive breakpoints and all URL Metrics are collected in a
 * single group.
 *
 * @since 0.1.0
 * @access private
 * @link https://github.com/WordPress/gutenberg/blob/093d52cbfd3e2c140843d3fb91ad3d03330320a5/packages/base-styles/_breakpoints.scss#L11-L13
 *
 * @return int[] Breakpoint max widths, sorted in ascending order.
 */
function od_get_breakpoint_max_widths(): array {
	$function_name = __FUNCTION__;

	$breakpoint_max_widths = array_map(
		static function ( $original_breakpoint ) use ( $function_name ): int {
			$breakpoint = $original_breakpoint;
			if ( PHP_INT_MAX === $breakpoint ) {
				$breakpoint = PHP_INT_MAX - 1;
				_doing_it_wrong(
					esc_html( $function_name ),
					esc_html(
						sprintf(
							/* translators: %s is the actual breakpoint max width */
							__( 'Breakpoint must be less than PHP_INT_MAX, but saw "%s".', 'optimization-detective' ),
							$original_breakpoint
						)
					),
					''
				);
			} elseif ( $breakpoint <= 0 ) {
				$breakpoint = 1;
				_doing_it_wrong(
					esc_html( $function_name ),
					esc_html(
						sprintf(
							/* translators: %s is the actual breakpoint max width */
							__( 'Breakpoint must be greater zero, but saw "%s".', 'optimization-detective' ),
							$original_breakpoint
						)
					),
					''
				);
			}
			return $breakpoint;
		},
		/**
		 * Filters the breakpoint max widths to group URL Metrics for various viewports.
		 *
		 * A breakpoint must be greater than zero and less than PHP_INT_MAX. This array may be empty in which case there
		 * are no responsive breakpoints and all URL Metrics are collected in a single group.
		 *
		 * @since 0.1.0
		 *
		 * @param int[] $breakpoint_max_widths Max widths for viewport breakpoints. Defaults to [480, 600, 782].
		 */
		array_map( 'intval', (array) apply_filters( 'od_breakpoint_max_widths', array( 480, 600, 782 ) ) )
	);

	$breakpoint_max_widths = array_unique( $breakpoint_max_widths, SORT_NUMERIC );
	sort( $breakpoint_max_widths );
	return $breakpoint_max_widths;
}

/**
 * Gets the sample size for a breakpoint's URL Metrics on a given URL.
 *
 * A breakpoint divides URL Metrics for viewports which are smaller and those which are larger. Given the default
 * sample size of 3 and there being just a single breakpoint (480) by default, for a given URL, there would be a maximum
 * total of 6 URL Metrics stored for a given URL: 3 for mobile and 3 for desktop.
 *
 * @since 0.1.0
 * @access private
 *
 * @return int Sample size.
 */
function od_get_url_metrics_breakpoint_sample_size(): int {
	/**
	 * Filters the sample size for a breakpoint's URL Metrics on a given URL.
	 *
	 * The sample size must be greater than zero.
	 *
	 * @since 0.1.0
	 *
	 * @param int $sample_size Sample size. Defaults to 3.
	 */
	$sample_size = (int) apply_filters( 'od_url_metrics_breakpoint_sample_size', 3 );

	if ( $sample_size <= 0 ) {
		_doing_it_wrong(
			__FUNCTION__,
			esc_html(
				sprintf(
					/* translators: %s is the sample size */
					__( 'Sample size must greater than zero, but saw "%s".', 'optimization-detective' ),
					$sample_size
				)
			),
			''
		);
		$sample_size = 1;
	}

	return $sample_size;
}
