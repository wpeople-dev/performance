=== Optimization Detective ===

Contributors: wordpressdotorg
Tested up to: 6.7
Stable tag:   0.8.0
License:      GPLv2 or later
License URI:  https://www.gnu.org/licenses/gpl-2.0.html
Tags:         performance, optimization, rum

Provides an API for leveraging real user metrics to detect optimizations to apply on the frontend to improve page performance.

== Description ==

This plugin captures real user metrics about what elements are displayed on the page across a variety of device form factors (e.g. desktop, tablet, and phone) in order to apply loading optimizations which are not possible with WordPress’s current server-side heuristics.

This plugin is a dependency which does not provide end-user functionality on its own. For that, please install the dependent plugin [Image Prioritizer](https://wordpress.org/plugins/image-prioritizer/) or [Embed Optimizer](https://wordpress.org/plugins/embed-optimizer/) (among [others](https://github.com/WordPress/performance/labels/%5BPlugin%5D%20Optimization%20Detective) to come from the WordPress Core Performance team).

= Background =

WordPress uses [server-side heuristics](https://make.wordpress.org/core/2023/07/13/image-performance-enhancements-in-wordpress-6-3/) to make educated guesses about which images are likely to be in the initial viewport. Likewise, it uses server-side heuristics to identify a hero image which is likely to be the Largest Contentful Paint (LCP) element. To optimize page loading, it avoids lazy-loading any of these images while also adding `fetchpriority=high` to the hero image. When these heuristics are applied successfully, the LCP metric for page loading can be improved 5-10%. Unfortunately, however, there are limitations to the heuristics that make the correct identification of which image is the LCP element only about 50% effective. See [Analyzing the Core Web Vitals performance impact of WordPress 6.3 in the field](https://make.wordpress.org/core/2023/09/19/analyzing-the-core-web-vitals-performance-impact-of-wordpress-6-3-in-the-field/). For example, it is [common](https://github.com/GoogleChromeLabs/wpp-research/pull/73) for the LCP element to vary between different viewport widths, such as desktop versus mobile. Since WordPress's heuristics are completely server-side it has no knowledge of how the page is actually laid out, and it cannot prioritize loading of images according to the client's viewport width.

In order to increase the accuracy of identifying the LCP element, including across various client viewport widths, this plugin gathers metrics from real users (RUM) to detect the actual LCP element and then use this information to optimize the page for future visitors so that the loading of the LCP element is properly prioritized. This is the purpose of Optimization Detective. The approach is heavily inspired by Philip Walton’s [Dynamic LCP Priority: Learning from Past Visits](https://philipwalton.com/articles/dynamic-lcp-priority/). See also the initial exploration document that laid out this project: [Image Loading Optimization via Client-side Detection](https://docs.google.com/document/u/1/d/16qAJ7I_ljhEdx2Cn2VlK7IkiixobY9zNn8FXxN9T9Ls/view).

= Technical Foundation =

At the core of Optimization Detective is the “URL Metric”, information about a page according to how it was loaded by a client with a specific viewport width. This includes which elements were visible in the initial viewport and which one was the LCP element. The URL Metric data is also extensible. Each URL on a site can have an associated set of these URL Metrics (stored in a custom post type) which are gathered from the visits of real users. It gathers samples of URL Metrics which are grouped according to WordPress's default responsive breakpoints:

1. Mobile: 0-480px
2. Phablet: 481-600px
3. Tablet: 601-782px
4. Desktop: \>782px

When no more URL Metrics are needed for a URL due to the sample size being obtained for the viewport group, it discontinues serving the JavaScript to gather the metrics (leveraging the [web-vitals.js](https://github.com/GoogleChrome/web-vitals) library). With the URL Metrics in hand, the output-buffered page is sent through the HTML Tag Processor and--when the [Image Prioritizer](https://wordpress.org/plugins/image-prioritizer/) dependent plugin is installed--the images which were the LCP element for various breakpoints will get prioritized with high-priority preload links (along with `fetchpriority=high` on the actual `img` tag when it is the common LCP element across all breakpoints). LCP elements with background images added via inline `background-image` styles are also prioritized with preload links.

URL Metrics have a “freshness TTL” after which they will be stale and the JavaScript will be served again to start gathering metrics again to ensure that the right elements continue to get their loading prioritized. When a URL Metrics custom post type hasn't been touched in a while, it is automatically garbage-collected.

👉 **Note:** This plugin optimizes pages for actual visitors, and it depends on visitors to optimize pages (since URL Metrics need to be collected). As such, you won't see optimizations applied immediately after activating the plugin (and dependent plugin(s)). And since administrator users are not normal visitors typically, optimizations are not applied for admins by default (but this can be overridden with the `od_can_optimize_response` filter below). URL Metrics are not collected for administrators because it is likely that additional elements will be present on the page which are not also shown to non-administrators, meaning the URL Metrics could not reliably be reused between them.

There are currently **no settings** and no user interface for this plugin since it is designed to work without any configuration.

When the `WP_DEBUG` constant is enabled, additional logging for Optimization Detective is added to the browser console.

= Hooks =

**Action:** `od_init` (argument: plugin version)

Fires when the Optimization Detective is initializing. This action is useful for loading extension code that depends on Optimization Detective to be running. The version of the plugin is passed as the sole argument so that if the required version is not present, the callback can short circuit.

**Action:** `od_register_tag_visitors` (argument: `OD_Tag_Visitor_Registry`)

Fires to register tag visitors before walking over the document to perform optimizations.

For example, to register a new tag visitor that targets `H1` elements:

`
<?php
add_action(
	'od_register_tag_visitors',
	static function ( OD_Tag_Visitor_Registry $registry ) {
		$registry->register(
			'my-plugin/h1',
			static function ( OD_Tag_Visitor_Context $context ): bool {
				if ( $context->processor->get_tag() !== 'H1' ) {
					return false;
				}
				// Now optimize based on stored URL Metrics in $context->url_metric_group_collection.
				// ...

				// Returning true causes the tag to be tracked in URL Metrics. If there is no need
				// for this, as in there is no reference to $context->url_metric_group_collection
				// in a tag visitor, then this can instead return false.
				return true;
			}
		);
	}
);
`

Refer to [Image Prioritizer](https://github.com/WordPress/performance/tree/trunk/plugins/image-prioritizer) and [Embed Optimizer](https://github.com/WordPress/performance/tree/trunk/plugins/embed-optimizer) for real world examples of how tag visitors are used. Registered tag visitors need only be callables, so in addition to providing a closure you may provide a `callable-string` or even a class which has an `__invoke()` method.

**Filter:** `od_breakpoint_max_widths` (default: `array(480, 600, 782)`)

Filters the breakpoint max widths to group URL Metrics for various viewports. Each number represents the maximum width (inclusive) for a given breakpoint. So if there is one number, 480, then this means there will be two viewport groupings, one for 0\<=480, and another \>480. If instead there are the two breakpoints defined, 480 and 782, then this means there will be three viewport groups of URL Metrics, one for 0\<=480 (i.e. mobile), another 481\<=782 (i.e. phablet/tablet), and another \>782 (i.e. desktop).

These default breakpoints are reused from Gutenberg which appear to be used the most in media queries that affect frontend styles.

**Filter:** `od_can_optimize_response` (default: boolean condition, see below)

Filters whether the current response can be optimized. By default, detection and optimization are only performed when:

1. It’s not a search template (`is_search()`).
2. It’s not a post embed template (`is_embed()`).
3. It’s not the Customizer preview (`is_customize_preview()`)
4. It’s not the response to a `POST` request.
5. The user is not an administrator (`current_user_can( 'customize' )`).

During development, you may want to force this to always be enabled:

`
<?php
add_filter( 'od_can_optimize_response', '__return_true' );
`

**Filter:** `od_url_metrics_breakpoint_sample_size` (default: 3)

Filters the sample size for a breakpoint's URL Metrics on a given URL. The sample size must be greater than zero. During development, it may be helpful to reduce the sample size to 1:

`
<?php
add_filter( 'od_url_metrics_breakpoint_sample_size', function (): int {
	return 1;
} );
`

**Filter:** `od_url_metric_storage_lock_ttl` (default: 1 minute in seconds)

Filters how long a given IP is locked from submitting another metric-storage REST API request. Filtering the TTL to zero will disable any metric storage locking. This is useful, for example, to disable locking when a user is logged-in with code like the following:

`
<?php
add_filter( 'od_metrics_storage_lock_ttl', function ( int $ttl ): int {
    return is_user_logged_in() ? 0 : $ttl;
} );
`

**Filter:** `od_url_metric_freshness_ttl` (default: 1 day in seconds)

Filters the freshness age (TTL) for a given URL Metric. The freshness TTL must be at least zero, in which it considers URL Metrics to always be stale. In practice, the value should be at least an hour. During development, this can be useful to set to zero:

`
<?php
add_filter( 'od_url_metric_freshness_ttl', '__return_zero' );
`

**Filter:** `od_minimum_viewport_aspect_ratio` (default: 0.4)

Filters the minimum allowed viewport aspect ratio for URL Metrics.

The 0.4 value is intended to accommodate the phone with the greatest known aspect ratio at 21:9 when rotated 90 degrees to 9:21 (0.429).

**Filter:** `od_maximum_viewport_aspect_ratio` (default: 2.5)

Filters the maximum allowed viewport aspect ratio for URL Metrics.

The 2.5 value is intended to accommodate the phone with the greatest known aspect ratio at 21:9 (2.333).

During development when you have the DevTools console open, for example, the viewport aspect ratio will be wider than normal. In this case, you may want to increase the maximum aspect ratio:

`
<?php
add_filter( 'od_maximum_viewport_aspect_ratio', function () {
	return 5;
} );
`

**Filter:** `od_template_output_buffer` (default: the HTML response)

Filters the template output buffer prior to sending to the client. This filter is added to implement [\#43258](https://core.trac.wordpress.org/ticket/43258) in WordPress core.

**Filter:** `od_url_metric_schema_element_item_additional_properties` (default: empty array)

Filters additional schema properties which should be allowed for an element's item in a URL Metric.

For example to add a `resizedBoundingClientRect` property:

`
<?php
add_filter(
	'od_url_metric_schema_element_item_additional_properties',
	static function ( array $additional_properties ): array {
		$additional_properties['resizedBoundingClientRect'] = array(
			'type'       => 'object',
			'properties' => array_fill_keys(
				array(
					'width',
					'height',
					'x',
					'y',
					'top',
					'right',
					'bottom',
					'left',
				),
				array(
					'type'     => 'number',
					'required' => true,
				)
			),
		);
		return $additional_properties;
	}
);
`

See also [example usage](https://github.com/WordPress/performance/blob/6bb8405c5c446e3b66c2bfa3ae03ba61b188bca2/plugins/embed-optimizer/hooks.php#L81-L110) in Embed Optimizer.

**Filter:** `od_url_metric_schema_root_additional_properties` (default: empty array)

Filters additional schema properties which should be allowed at the root of a URL Metric.

The usage here is the same as the previous filter, except it allows new properties to be added to the root of the URL Metric and not just to one of the object items in the `elements` property.

**Filter:** `od_extension_module_urls` (default: empty array of strings)

Filters the list of extension script module URLs to import when performing detection.

For example:

`
<?php
add_filter(
	'od_extension_module_urls',
	static function ( array $extension_module_urls ): array {
		$extension_module_urls[] = add_query_arg( 'ver', '1.0', plugin_dir_url( __FILE__ ) . 'detect.js' );
		return $extension_module_urls;
	}
);
`

See also [example usage](https://github.com/WordPress/performance/blob/6bb8405c5c446e3b66c2bfa3ae03ba61b188bca2/plugins/embed-optimizer/hooks.php#L128-L144) in Embed Optimizer. Note in particular the structure of the plugin’s [detect.js](https://github.com/WordPress/performance/blob/trunk/plugins/embed-optimizer/detect.js) script module, how it exports `initialize` and `finalize` functions which Optimization Detective then calls when the page loads and when the page unloads, at which time the URL Metric is constructed and sent to the server for storage. Refer also to the [TypeScript type definitions](https://github.com/WordPress/performance/blob/trunk/plugins/optimization-detective/types.ts).

**Action:** `od_url_metric_stored` (argument: `OD_URL_Metric_Store_Request_Context`)

Fires whenever a URL Metric was successfully stored.

The supplied context object includes these properties:

* `$request`: The `WP_REST_Request` for storing the URL Metric.
* `$post_id`: The post ID for the `od_url_metric` post.
* `$url_metric`: The newly-stored URL Metric.
* `$url_metric_group`: The viewport group that the URL Metric was added to.
* `$url_metric_group_collection`: The `OD_URL_Metric_Group_Collection` instance to which the URL Metric was added.

== Installation ==

= Installation from within WordPress =

1. Visit **Plugins > Add New**.
2. Search for **Optimization Detective**.
3. Install and activate the **Optimization Detective** plugin.

= Manual installation =

1. Upload the entire `optimization-detective` folder to the `/wp-content/plugins/` directory.
2. Visit **Plugins**.
3. Activate the **Optimization Detective** plugin.

== Frequently Asked Questions ==

= Where can I submit my plugin feedback? =

Feedback is encouraged and much appreciated, especially since this plugin may contain future WordPress core features. If you have suggestions or requests for new features, you can [submit them as an issue in the WordPress Performance Team's GitHub repository](https://github.com/WordPress/performance/issues/new/choose). If you need help with troubleshooting or have a question about the plugin, please [create a new topic on our support forum](https://wordpress.org/support/plugin/optimization-detective/#new-topic-0).

= Where can I report security bugs? =

The Performance team and WordPress community take security bugs seriously. We appreciate your efforts to responsibly disclose your findings, and will make every effort to acknowledge your contributions.

To report a security issue, please visit the [WordPress HackerOne](https://hackerone.com/wordpress) program.

= How can I contribute to the plugin? =

Contributions are always welcome! Learn more about how to get involved in the [Core Performance Team Handbook](https://make.wordpress.org/performance/handbook/get-involved/).

The [plugin source code](https://github.com/WordPress/performance/tree/trunk/plugins/optimization-detective) is located in the [WordPress/performance](https://github.com/WordPress/performance) repo on GitHub.

== Changelog ==

= 0.8.0 =

**Enhancements**

* Serve unminified scripts when `SCRIPT_DEBUG` is enabled. ([1643](https://github.com/WordPress/performance/pull/1643))
* Bump web-vitals from 4.2.3 to 4.2.4. ([1628](https://github.com/WordPress/performance/pull/1628))

**Bug Fixes**

* Eliminate the detection time window which prevented URL Metrics from being gathered when page caching is present. ([1640](https://github.com/WordPress/performance/pull/1640))
* Revise the use of nonces in requests to store a URL Metric and block cross-origin requests. ([1637](https://github.com/WordPress/performance/pull/1637))
* Send post ID of queried object or first post in loop in URL Metric storage request to schedule page cache validation. ([1641](https://github.com/WordPress/performance/pull/1641))
* Fix phpstan errors. ([1627](https://github.com/WordPress/performance/pull/1627))

= 0.7.0 =

**Enhancements**

* Send gathered URL Metric data when the page is hidden/unloaded as opposed to once the page has loaded; this enables the ability to track layout shifts and INP scores over the life of the page. ([1373](https://github.com/WordPress/performance/pull/1373))
* Introduce client-side extensions in the form of script modules which are loaded when the detection logic runs. ([1373](https://github.com/WordPress/performance/pull/1373))
* Add an `od_init` action for extensions to load their code. ([1373](https://github.com/WordPress/performance/pull/1373))
* Introduce `OD_Element` class and improve PHP API. ([1585](https://github.com/WordPress/performance/pull/1585))
* Add group collection helper methods to get the first/last groups. ([1602](https://github.com/WordPress/performance/pull/1602))

**Bug Fixes**

* Fix Optimization Detective compatibility with WooCommerce when Coming Soon page is served. ([1565](https://github.com/WordPress/performance/pull/1565))
* Fix storage of URL Metric when plain non-pretty permalinks are enabled. ([1574](https://github.com/WordPress/performance/pull/1574))

= 0.6.0 =

**Enhancements**

* Allow URL Metric schema to be extended. ([1492](https://github.com/WordPress/performance/pull/1492))
* Clarify docs around a tag visitor's boolean return value. ([1479](https://github.com/WordPress/performance/pull/1479))
* Include UUID with each URL Metric. ([1489](https://github.com/WordPress/performance/pull/1489))
* Introduce get_cursor_move_count() to use instead of get_seek_count() and get_next_token_count(). ([1478](https://github.com/WordPress/performance/pull/1478))

**Bug Fixes**

* Add missing global documentation for `delete_all_posts()`. ([1522](https://github.com/WordPress/performance/pull/1522))
* Introduce viewport aspect ratio validation for URL Metrics. ([1494](https://github.com/WordPress/performance/pull/1494))

= 0.5.0 =

**Enhancements**

* Bump web-vitals from 4.2.1 to 4.2.2. ([1386](https://github.com/WordPress/performance/pull/1386))

**Bug Fixes**

* Disable Optimization Detective by default on the embed template. ([1472](https://github.com/WordPress/performance/pull/1472))
* Ensure only HTML documents are processed by Optimization Detective. ([1442](https://github.com/WordPress/performance/pull/1442))
* Ensure the entire template is passed to the output buffer callback for Optimization Detective to process. ([1317](https://github.com/WordPress/performance/pull/1317))
* Implement full support for intersectionRect/boundingClientRect, fix viewportRect typing, and harden JSON schema. ([1411](https://github.com/WordPress/performance/pull/1411))

= 0.4.1 =

**Enhancements**

* Upgrade web-vitals.js from [v3.5.0](https://github.com/GoogleChrome/web-vitals/blob/main/CHANGELOG.md#v350-2023-09-28) to [v4.2.1](https://github.com/GoogleChrome/web-vitals/blob/main/CHANGELOG.md#v422-2024-07-17).

**Bug Fixes**

* Fix logic for seeking during optimization loop to prevent emitting seek() notices. ([1376](https://github.com/WordPress/performance/pull/1376))

= 0.4.0 =

**Enhancements**

* Avoid passing positional parameters in Optimization Detective. ([1338](https://github.com/WordPress/performance/pull/1338))
* Send preload links via HTTP Link headers in addition to LINK tags. ([1323](https://github.com/WordPress/performance/pull/1323))

= 0.3.1 =

**Enhancements**

* Log URL Metrics group collection to console when debugging is enabled (`WP_DEBUG` is true). ([1295](https://github.com/WordPress/performance/pull/1295))

**Bug Fixes**

* Include non-intersecting elements in URL Metrics to fix lazy-load optimization. ([1293](https://github.com/WordPress/performance/pull/1293))

= 0.3.0 =

* The image optimization features have been split out into a new dependent plugin called [Image Prioritizer](https://wordpress.org/plugins/image-prioritizer/), which also now optimizes image lazy-loading. ([1088](https://github.com/WordPress/performance/issues/1088))

= 0.2.0 =

**Enhancements**

* Add optimization_detective_disabled query var to disable behavior. ([1193](https://github.com/WordPress/performance/pull/1193))
* Facilitate embedding Optimization Detective in other plugins/themes. ([1185](https://github.com/WordPress/performance/pull/1185))
* Use PHP 7.2 features in Optimization Detective. ([1162](https://github.com/WordPress/performance/pull/1162))
* Improve overall code quality with stricter static analysis checks. ([775](https://github.com/WordPress/performance/issues/775))
* Bump minimum PHP requirement to 7.2. ([1130](https://github.com/WordPress/performance/pull/1130))

**Bug Fixes**

* Avoid _doing_it_wrong() for Server-Timing in Optimization Detective when output buffering is not enabled. ([1194](https://github.com/WordPress/performance/pull/1194))
* Ensure only HTML responses are optimized. ([1189](https://github.com/WordPress/performance/pull/1189))
* Fix XPath indices to be 1-based instead of 0-based. ([1191](https://github.com/WordPress/performance/pull/1191))

= 0.1.1 =

* Use plugin slug for generator tag. ([1103](https://github.com/WordPress/performance/pull/1103))
* Prevent detection script injection from breaking import maps in classic themes. ([1084](https://github.com/WordPress/performance/pull/1084))

= 0.1.0 =

* Initial release.

== Upgrade Notice ==

= 0.3.0 =

Image loading optimizations have been moved to a new dependent plugin called Image Prioritizer. The Optimization Detective plugin now serves as a dependency.
