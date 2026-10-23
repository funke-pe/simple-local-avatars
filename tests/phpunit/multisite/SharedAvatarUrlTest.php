<?php

class SharedAvatarUrlTest extends \WP_Mock\Tools\TestCase {
	private $blog_id;

	public function setUp(): void {
		WP_Mock::setUp();
		$this->blog_id = 2;
	}

	public function tearDown(): void {
		$this->addToAssertionCount( Mockery::getContainer()->mockery_getExpectationCount() );
		parent::tearDown();
	}

	public function avatar_cases() {
		return [
			'new 256px size'             => [ [] ],
			'new 512px size'             => [ [ 'size' => 512 ] ],
			'empty cached size'          => [ [ 'cached' => '' ] ],
			'null cached size'           => [ [ 'cached' => null ] ],
			'legacy main site origin'    => [ [ 'legacy' => true ] ],
			'legacy URL-only avatar'     => [ [ 'media' => false, 'legacy' => true ] ],
			'URL-only avatar'            => [ [ 'media' => false ] ],
			'cached absolute URL'       => [ [ 'cached' => 'https://origin.example/cached.png' ] ],
			'cached relative URL'       => [ [ 'cached' => '/cached.png' ] ],
			'resize disabled'           => [ [ 'resize' => false, 'cached' => '' ] ],
			'unmappable destination'    => [ [ 'unmappable' => true ] ],
			'editor error'              => [ [ 'failure' => 'editor' ] ],
			'resize error'              => [ [ 'failure' => 'resize' ] ],
			'save error'                => [ [ 'failure' => 'save' ] ],
			'already correct size'      => [ [ 'exact' => true ] ],
			'deleted attachment'        => [ [ 'deleted' => true ] ],
			'attachment exception'      => [ [ 'exception' => 'attachment' ] ],
			'editor exception'          => [ [ 'exception' => 'editor' ] ],
			'filter override'           => [ [ 'override' => 'https://cdn.example/avatar.png' ] ],
			'empty filter override'     => [ [ 'override' => '' ] ],
			'rating rejected'           => [ [ 'rating' => 'X' ] ],
			'sharing disabled'          => [ [ 'shared' => false ] ],
			'single site'               => [ [ 'multisite' => false ] ],
		];
	}

	/**
	 * @dataProvider avatar_cases
	 */
	public function test_avatar_url_uses_origin_context_and_restores_caller( $case ) {
		$config = array_merge( [
			'size' => 256, 'legacy' => false, 'media' => true,
			'resize' => true, 'unmappable' => false, 'failure' => '',
			'exception' => '', 'exact' => false, 'deleted' => false,
			'rating' => 'G', 'shared' => true, 'multisite' => true,
		], $case );
		$size   = $config['size'];
		$origin = $config['legacy'] ? 1 : 5;
		$shared = $config['shared'] && $config['multisite'];
		$active = $shared ? $origin : 2;
		$full   = 'https://origin.example/uploads/avatar.png';
		$meta   = [ 'full' => $full ];
		if ( $config['media'] ) {
			$meta['media_id'] = 101;
		}
		if ( ! $config['legacy'] ) {
			$meta['blog_id'] = $origin;
		}
		if ( array_key_exists( 'cached', $case ) ) {
			$meta[ $size ] = $case['cached'];
		}
		$instance = Mockery::mock( 'Simple_Local_Avatars' )->makePartial();
		foreach ( [
			'user_key' => 'simple_local_avatar',
			'rating_key' => 'simple_local_avatar_rating',
			'options' => [ 'shared' => $config['shared'] ? 1 : 0 ],
			'avatar_ratings' => [ 'G' => 'General', 'X' => 'Adult' ],
		] as $name => $value ) {
			$property = new ReflectionProperty( 'Simple_Local_Avatars', $name );
			$property->setAccessible( true );
			$property->setValue( $instance, $value );
		}
		WP_Mock::userFunction( 'get_user_meta' )
			->with( 1, 'simple_local_avatar', true )->andReturn( $meta );
		WP_Mock::userFunction( 'get_user_meta' )
			->with( 1, 'simple_local_avatar_rating', true )->andReturn( $config['rating'] );
		WP_Mock::userFunction( 'get_option' )->with( 'avatar_rating' )->andReturn( 'G' );
		$short_circuit = array_key_exists( 'override', $case ) || 'G' !== $config['rating'];
		if ( array_key_exists( 'override', $case ) ) {
			WP_Mock::onFilter( 'pre_simple_local_avatar_url' )
				->with( null, 1, $size, $meta )->reply( $case['override'] );
		}
		WP_Mock::onFilter( 'simple_local_avatars_dynamic_resize' )
			->with( true )->reply( $config['resize'] );
		WP_Mock::userFunction( 'is_multisite', [ 'return' => $config['multisite'] ] );
		WP_Mock::userFunction( 'get_main_site_id', [ 'return' => 1 ] );
		WP_Mock::userFunction( 'switch_to_blog' )->with( $origin )
			->times( $shared && ! $short_circuit ? 1 : 0 )
			->andReturnUsing( function () use ( $origin ) {
				$this->assertSame( 2, $this->blog_id );
				$this->blog_id = $origin;
				return true;
			} );
		WP_Mock::userFunction( 'restore_current_blog' )
			->times( $shared && ! $short_circuit ? 1 : 0 )
			->andReturnUsing( function () use ( $origin ) {
				$this->assertSame( $origin, $this->blog_id );
				$this->blog_id = 2;
				return true;
			} );
		WP_Mock::userFunction( 'get_attached_file' )->with( 101 )
			->andReturnUsing( function () use ( $config, $active ) {
				$this->assertSame( $active, $this->blog_id );
				if ( 'attachment' === $config['exception'] ) {
					throw new RuntimeException( 'Attachment failure' );
				}
				return $config['deleted'] ? false : '/external/origin/uploads/avatar.png';
			} );
		WP_Mock::userFunction( 'wp_upload_dir' )->andReturnUsing( function () use ( $active ) {
			$this->assertSame( $active, $this->blog_id );
			return [
				'basedir' => '/external/origin/uploads',
				'baseurl' => 'https://origin.example/uploads',
			];
		} );
		$error  = new stdClass();
		$editor = Mockery::mock();
		$editor->shouldReceive( 'get_size' )->andReturn( [
			'width' => $config['exact'] ? $size : 1000,
			'height' => $config['exact'] ? $size : 1000,
		] );
		$editor->shouldReceive( 'resize' )->with( $size, $size, true )
			->andReturn( 'resize' === $config['failure'] ? $error : true );
		$dest = $config['unmappable'] ? '/unmapped/avatar.png' : "/external/origin/uploads/avatar-{$size}x{$size}.png";
		$editor->shouldReceive( 'generate_filename' )->andReturn( $dest );
		$editor->shouldReceive( 'save' )->with( $dest )
			->andReturn( 'save' === $config['failure'] ? $error : [ 'path' => $dest ] );
		WP_Mock::userFunction( 'wp_get_image_editor' )
			->with( '/external/origin/uploads/avatar.png' )
			->andReturnUsing( function () use ( $config, $active, $editor, $error ) {
				$this->assertSame( $active, $this->blog_id );
				if ( 'editor' === $config['exception'] ) {
					throw new RuntimeException( 'Editor failure' );
				}
				return 'editor' === $config['failure'] ? $error : $editor;
			} );
		WP_Mock::userFunction( 'is_wp_error' )->andReturnUsing( function ( $value ) use ( $error ) {
			return $value === $error;
		} );
		$expected = "https://origin.example/uploads/avatar-{$size}x{$size}.png";
		if ( ! empty( $meta[ $size ] ) ) {
			$expected = '/' === $meta[ $size ][0] ? 'https://origin.example/cached.png' : $meta[ $size ];
		} elseif ( ! $config['resize'] || $config['unmappable'] || $config['failure'] || $config['exact'] ) {
			$expected = $full;
		}
		$writes = ! $short_circuit && empty( $meta[ $size ] ) && $config['resize'] && ! $config['deleted'] && ! $config['exception'];
		WP_Mock::userFunction( 'update_user_meta' )
			->with( 1, 'simple_local_avatar', Mockery::type( 'array' ) )
			->times( $writes ? 1 : 0 )
			->andReturnUsing( function ( $user_id, $key, $value ) use ( $expected, $size, $active ) {
				$this->assertSame( $active, $this->blog_id );
				$this->assertSame( $expected, $value[ $size ] );
				return true;
			} );
		WP_Mock::userFunction( 'home_url' )->with( '/cached.png' )
			->andReturnUsing( function () use ( $active ) {
				$this->assertSame( $active, $this->blog_id );
				return 'https://origin.example/cached.png';
			} );
		if ( $short_circuit ) {
			$expected = isset( $case['override'] ) ? $case['override'] : '';
		} elseif ( $config['deleted'] ) {
			$expected = '';
		}
		try {
			$url = $instance->get_simple_local_avatar_url( 1, $size );
			$this->assertSame( '', $config['exception'], 'Expected exception must propagate.' );
			$this->assertSame( $expected, $url );
		} catch ( RuntimeException $exception ) {
			$this->assertNotSame( '', $config['exception'] );
			$this->assertStringContainsString( 'failure', $exception->getMessage() );
		}
		$this->assertSame( 2, $this->blog_id );
	}
}
