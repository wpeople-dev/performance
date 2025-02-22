<?php
/**
 * Tests for optimization-detective plugin storage/data.php.
 *
 * @package optimization-detective
 *
 * @noinspection PhpUnhandledExceptionInspection
 */

class Test_OD_Storage_Data extends WP_UnitTestCase {

	/**
	 * @var string
	 */
	private $original_request_uri;

	public function set_up(): void {
		$this->original_request_uri = $_SERVER['REQUEST_URI'];
		parent::set_up();
	}

	public function tear_down(): void {
		$_SERVER['REQUEST_URI'] = $this->original_request_uri;
		unset( $GLOBALS['wp_customize'] );
		parent::tear_down();
	}

	/**
	 * Test od_get_url_metric_freshness_ttl().
	 *
	 * @covers ::od_get_url_metric_freshness_ttl
	 */
	public function test_od_get_url_metric_freshness_ttl(): void {
		$this->assertSame( DAY_IN_SECONDS, od_get_url_metric_freshness_ttl() );

		add_filter(
			'od_url_metric_freshness_ttl',
			static function (): int {
				return HOUR_IN_SECONDS;
			}
		);

		$this->assertSame( HOUR_IN_SECONDS, od_get_url_metric_freshness_ttl() );
	}

	/**
	 * Test bad od_get_url_metric_freshness_ttl().
	 *
	 * @expectedIncorrectUsage od_get_url_metric_freshness_ttl
	 * @covers ::od_get_url_metric_freshness_ttl
	 */
	public function test_bad_od_get_url_metric_freshness_ttl(): void {
		add_filter(
			'od_url_metric_freshness_ttl',
			static function (): int {
				return -1;
			}
		);

		$this->assertSame( 0, od_get_url_metric_freshness_ttl() );
	}

	/**
	 * Data provider.
	 *
	 * @return array<string, mixed> Data.
	 */
	public function data_provider_test_od_get_normalized_query_vars(): array {
		return array(
			'homepage'     => array(
				'set_up' => function (): array {
					$this->go_to( home_url( '/' ) );
					return array();
				},
			),
			'post'         => array(
				'set_up' => function (): array {
					$post_id = self::factory()->post->create();
					$this->go_to( get_permalink( $post_id ) );
					return array( 'p' => (string) $post_id );
				},
			),
			'date-archive' => array(
				'set_up' => function (): array {
					$post_id = self::factory()->post->create();
					$date = get_post_datetime( $post_id );

					$this->go_to(
						add_query_arg(
							array(
								'day'      => $date->format( 'j' ),
								'year'     => $date->format( 'Y' ),
								'monthnum' => $date->format( 'm' ),
								'bogus'    => 'ignore me',
							),
							home_url()
						)
					);
					return array(
						'year'     => $date->format( 'Y' ),
						'monthnum' => $date->format( 'm' ),
						'day'      => $date->format( 'j' ),
					);
				},
			),
			'not-found'    => array(
				'set_up' => function (): array {
					$this->go_to( home_url( '/?p=1000000' ) );
					return array( 'error' => 404 );
				},
			),
			'logged-in'    => array(
				'set_up' => function (): array {
					wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
					$this->go_to( home_url( '/' ) );
					return array( 'user_logged_in' => true );
				},
			),
		);
	}

	/**
	 * Test od_get_normalized_query_vars().
	 *
	 * @covers ::od_get_normalized_query_vars
	 *
	 * @dataProvider data_provider_test_od_get_normalized_query_vars
	 */
	public function test_od_get_normalized_query_vars( Closure $set_up ): void {
		$expected = $set_up();
		$this->assertSame( $expected, od_get_normalized_query_vars() );
	}

	/**
	 * Data provider.
	 *
	 * @return array<string, mixed>
	 */
	public function data_provider_test_get_current_url(): array {
		$assertions = array(
			'path'                        => function (): void {
				$_SERVER['REQUEST_URI'] = wp_slash( '/foo/' );
				$this->assertEquals(
					home_url( '/foo/' ),
					od_get_current_url()
				);
			},

			'query'                       => function (): void {
				$_SERVER['REQUEST_URI'] = wp_slash( '/bar/?baz=1' );
				$this->assertEquals(
					home_url( '/bar/?baz=1' ),
					od_get_current_url()
				);
			},

			'idn_domain'                  => function (): void {
				$this->set_home_url_with_filter( 'https://⚡️.example.com' );
				$this->go_to( '/?s=lightning' );
				$this->assertEquals( 'https://⚡️.example.com/?s=lightning', od_get_current_url() );
			},

			'punycode_domain'             => function (): void {
				$this->set_home_url_with_filter( 'https://xn--57h.example.com' );
				$this->go_to( '/?s=thunder' );
				$this->assertEquals( 'https://xn--57h.example.com/?s=thunder', od_get_current_url() );
			},

			'ip_host'                     => function (): void {
				$this->set_home_url_with_filter( 'http://127.0.0.1:1234' );
				$this->go_to( '/' );
				$this->assertEquals( 'http://127.0.0.1:1234/', od_get_current_url() );
			},

			'permalink'                   => function (): void {
				global $wp_rewrite;
				update_option( 'permalink_structure', '/%year%/%monthnum%/%day%/%postname%/' );
				$wp_rewrite->use_trailing_slashes = true;
				$wp_rewrite->init();
				$wp_rewrite->flush_rules();

				$permalink = get_permalink( self::factory()->post->create() );

				$this->go_to( $permalink );
				$this->assertEquals( $permalink, od_get_current_url() );
			},

			'unset_request_uri'           => function (): void {
				unset( $_SERVER['REQUEST_URI'] );
				$this->assertEquals( home_url( '/' ), od_get_current_url() );
			},

			'empty_request_uri'           => function (): void {
				$_SERVER['REQUEST_URI'] = '';
				$this->assertEquals( home_url( '/' ), od_get_current_url() );
			},

			'no_slash_prefix_request_uri' => function (): void {
				$_SERVER['REQUEST_URI'] = 'foo/';
				$this->assertEquals( home_url( '/foo/' ), od_get_current_url() );
			},

			'reconstructed_home_url'      => function (): void {
				$_SERVER['HTTPS']       = 'on';
				$_SERVER['REQUEST_URI'] = '/about/';
				$_SERVER['HTTP_HOST']   = 'foo.example.org';
				$this->set_home_url_with_filter( '/' );
				$this->assertEquals(
					'https://foo.example.org/about/',
					od_get_current_url()
				);
			},

			'home_url_with_trimmings'     => function (): void {
				$this->set_home_url_with_filter( 'https://example.museum:8080' );
				$_SERVER['REQUEST_URI'] = '/about/';
				$this->assertEquals(
					'https://example.museum:8080/about/',
					od_get_current_url()
				);
			},

			'complete_parse_fail'         => function (): void {
				$_SERVER['HTTP_HOST'] = 'env.example.org';
				unset( $_SERVER['REQUEST_URI'] );
				$this->set_home_url_with_filter( ':' );
				$this->assertEquals(
					( is_ssl() ? 'https:' : 'http:' ) . '//env.example.org/',
					od_get_current_url()
				);
			},

			'default_to_localhost'        => function (): void {
				unset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
				$this->set_home_url_with_filter( ':' );
				$this->assertEquals(
					( is_ssl() ? 'https:' : 'http:' ) . '//localhost/',
					od_get_current_url()
				);
			},
		);
		return array_map(
			static function ( Closure $assertion ): array {
				return array( $assertion );
			},
			$assertions
		);
	}

	/**
	 * Set home_url with filter.
	 *
	 * @param string $home_url Home URL.
	 */
	private function set_home_url_with_filter( string $home_url ): void {
		add_filter(
			'home_url',
			static function () use ( $home_url ): string {
				return $home_url;
			}
		);
	}

	/**
	 * Test od_get_current_url().
	 *
	 * @covers ::od_get_current_url
	 *
	 * @dataProvider data_provider_test_get_current_url
	 */
	public function test_od_get_current_url( Closure $assert ): void {
		call_user_func( $assert );
	}

	/**
	 * Test od_get_url_metrics_slug().
	 *
	 * @covers ::od_get_url_metrics_slug
	 */
	public function test_od_get_url_metrics_slug(): void {
		$first  = od_get_url_metrics_slug( array() );
		$second = od_get_url_metrics_slug( array( 'p' => 1 ) );
		$this->assertNotEquals( $second, $first );
		foreach ( array( $first, $second ) as $slug ) {
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $slug );
		}
	}

	/**
	 * Data provider.
	 *
	 * @return array<string, mixed> Data.
	 */
	public function data_provider_to_test_hmac(): array {
		return array(
			'is_home'   => array(
				'set_up' => static function (): array {
					$post_id = self::factory()->post->create();
					return array(
						home_url(),
						od_get_url_metrics_slug( array() ),
						$post_id,
					);
				},
			),
			'is_single' => array(
				'set_up' => static function (): array {
					$post_id = self::factory()->post->create();
					return array(
						get_permalink( $post_id ),
						od_get_url_metrics_slug( array( 'p' => $post_id ) ),
						$post_id,
					);
				},
			),
		);
	}

	/**
	 * Test od_get_url_metrics_storage_hmac() and od_verify_url_metrics_storage_hmac().
	 *
	 * @dataProvider data_provider_to_test_hmac
	 *
	 * @covers ::od_get_url_metrics_storage_hmac
	 * @covers ::od_verify_url_metrics_storage_hmac
	 */
	public function test_od_get_url_metrics_storage_hmac_and_od_verify_url_metrics_storage_hmac( Closure $set_up ): void {
		list( $url, $slug, $cache_purge_post_id ) = $set_up();
		$this->go_to( $url );
		$hmac = od_get_url_metrics_storage_hmac( $slug, $url, $cache_purge_post_id );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]+$/', $hmac );
		$this->assertTrue( od_verify_url_metrics_storage_hmac( $hmac, $slug, $url, $cache_purge_post_id ) );
	}

	/**
	 * Test od_get_minimum_viewport_aspect_ratio().
	 *
	 * @covers ::od_get_minimum_viewport_aspect_ratio
	 */
	public function test_od_get_minimum_viewport_aspect_ratio(): void {
		$this->assertSame( 0.4, od_get_minimum_viewport_aspect_ratio() );

		add_filter(
			'od_minimum_viewport_aspect_ratio',
			static function () {
				return '0.6';
			}
		);

		$this->assertSame( 0.6, od_get_minimum_viewport_aspect_ratio() );
	}

	/**
	 * Test od_get_maximum_viewport_aspect_ratio().
	 *
	 * @covers ::od_get_maximum_viewport_aspect_ratio
	 */
	public function test_od_get_maximum_viewport_aspect_ratio(): void {
		$this->assertSame( 2.5, od_get_maximum_viewport_aspect_ratio() );

		add_filter(
			'od_maximum_viewport_aspect_ratio',
			static function () {
				return 3;
			}
		);

		$this->assertSame( 3.0, od_get_maximum_viewport_aspect_ratio() );
	}

	/**
	 * Test od_get_breakpoint_max_widths().
	 *
	 * @covers ::od_get_breakpoint_max_widths
	 */
	public function test_od_get_breakpoint_max_widths(): void {
		$this->assertSame(
			array( 480, 600, 782 ),
			od_get_breakpoint_max_widths()
		);

		$filtered_breakpoints = array( 2000, 500, '1000', 3000 );

		add_filter(
			'od_breakpoint_max_widths',
			static function () use ( $filtered_breakpoints ): array {
				return $filtered_breakpoints;
			}
		);

		$filtered_breakpoints = array_map( 'intval', $filtered_breakpoints );
		sort( $filtered_breakpoints );
		$this->assertSame( $filtered_breakpoints, od_get_breakpoint_max_widths() );
	}

	/**
	 * Data provider.
	 *
	 * @return array<string, mixed> Data.
	 */
	public function data_provider_test_bad_od_get_breakpoint_max_widths(): array {
		return array(
			'negative' => array(
				'breakpoints' => array( -1 ),
				'expected'    => array( 1 ),
			),
			'zero'     => array(
				'breakpoints' => array( 0 ),
				'expected'    => array( 1 ),
			),
			'max'      => array(
				'breakpoints' => array( PHP_INT_MAX ),
				'expected'    => array( PHP_INT_MAX - 1 ),
			),
			'multiple' => array(
				'breakpoints' => array( -1, 0, 10, PHP_INT_MAX ),
				'expected'    => array( 1, 10, PHP_INT_MAX - 1 ),
			),
		);
	}

	/**
	 * Test bad od_get_breakpoint_max_widths().
	 *
	 * @covers ::od_get_breakpoint_max_widths
	 *
	 * @expectedIncorrectUsage od_get_breakpoint_max_widths
	 * @dataProvider data_provider_test_bad_od_get_breakpoint_max_widths
	 *
	 * @param int[] $breakpoints Breakpoints.
	 * @param int[] $expected Expected breakpoints.
	 */
	public function test_bad_od_get_breakpoint_max_widths( array $breakpoints, array $expected ): void {
		add_filter(
			'od_breakpoint_max_widths',
			static function () use ( $breakpoints ): array {
				return $breakpoints;
			}
		);

		$this->assertSame( $expected, od_get_breakpoint_max_widths() );
	}

	/**
	 * Test od_get_url_metrics_breakpoint_sample_size().
	 *
	 * @covers ::od_get_url_metrics_breakpoint_sample_size
	 */
	public function test_od_get_url_metrics_breakpoint_sample_size(): void {
		$this->assertSame( 3, od_get_url_metrics_breakpoint_sample_size() );

		add_filter(
			'od_url_metrics_breakpoint_sample_size',
			static function (): string {
				return '1';
			}
		);

		$this->assertSame( 1, od_get_url_metrics_breakpoint_sample_size() );
	}

	/**
	 * Test bad od_get_url_metrics_breakpoint_sample_size().
	 *
	 * @expectedIncorrectUsage od_get_url_metrics_breakpoint_sample_size
	 * @covers ::od_get_url_metrics_breakpoint_sample_size
	 */
	public function test_bad_od_get_url_metrics_breakpoint_sample_size(): void {
		add_filter(
			'od_url_metrics_breakpoint_sample_size',
			static function (): int {
				return 0;
			}
		);

		$this->assertSame( 1, od_get_url_metrics_breakpoint_sample_size() );
	}
}
