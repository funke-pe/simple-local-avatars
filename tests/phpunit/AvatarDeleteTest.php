<?php

class AvatarDeleteTest extends \WP_Mock\Tools\TestCase {
	private $files = [];

	public function setUp(): void {
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->addToAssertionCount( Mockery::getContainer()->mockery_getExpectationCount() );
		parent::tearDown();
	}

	public function deletion_cases() {
		return [
			'media aliases on deletion' => [ true, true, false ],
			'media aliases on reassignment' => [ true, true, true ],
			'media derivatives on deletion' => [ true, false, false ],
			'media derivatives on reassignment' => [ true, false, true ],
			'URL-only aliases on deletion' => [ false, true, false ],
			'URL-only aliases on reassignment' => [ false, true, true ],
			'URL-only derivatives on deletion' => [ false, false, false ],
			'URL-only derivatives on reassignment' => [ false, false, true ],
		];
	}

	/**
	 * @dataProvider deletion_cases
	 */
	public function test_original_protection_and_derivative_cleanup( $media, $aliases, $reassign ) {
		$original   = tempnam( sys_get_temp_dir(), 'sla-original-' );
		$derivative = tempnam( sys_get_temp_dir(), 'sla-size-' );
		$this->assertNotFalse( $original );
		$this->assertNotFalse( $derivative );
		$this->files = [ $original, $derivative ];
		$baseurl    = 'https://origin.example/uploads';
		$full       = $baseurl . '/' . basename( $original );
		$meta       = [
			'full' => $full,
			'blog_id' => 6,
			256 => $baseurl . '/' . basename( $derivative ),
		];
		if ( $media ) {
			$meta['media_id'] = 101;
		}
		if ( $aliases ) {
			$meta[300] = $full;
			$meta[512] = $full;
		}
		$instance = Mockery::mock( 'Simple_Local_Avatars' )->makePartial();
		foreach ( [ 'user_key' => 'simple_local_avatar', 'rating_key' => 'simple_local_avatar_rating' ] as $key => $value ) {
			$property = new ReflectionProperty( 'Simple_Local_Avatars', $key );
			$property->setAccessible( true );
			$property->setValue( $instance, $value );
		}
		WP_Mock::userFunction( 'get_user_meta' )
			->with( 1, 'simple_local_avatar', true )->andReturn( $meta );
		WP_Mock::userFunction( 'wp_upload_dir' )->andReturn( [
			'baseurl' => $baseurl,
			'basedir' => dirname( $original ),
		] );
		WP_Mock::userFunction( 'wp_delete_file' )->with( $original )->times( $media ? 0 : 1 )
			->andReturnUsing( function ( $file ) {
				unlink( $file );
			} );
		WP_Mock::userFunction( 'wp_delete_file' )->with( $derivative )->once()
			->andReturnUsing( function ( $file ) {
				unlink( $file );
			} );
		WP_Mock::userFunction( 'delete_user_meta' )->with( 1, 'simple_local_avatar' )->once();
		WP_Mock::userFunction( 'delete_user_meta' )->with( 1, 'simple_local_avatar_rating' )->once();
		WP_Mock::expectAction( 'simple_local_avatar_deleted', 1 );
		if ( $reassign ) {
			$new_url = 'https://origin.example/uploads/new-avatar.jpg';
			WP_Mock::userFunction( 'wp_get_attachment_url' )->with( 202 )->andReturn( $new_url );
			WP_Mock::userFunction( 'get_current_blog_id' )->andReturn( 6 );
			WP_Mock::userFunction( 'update_user_meta' )->with( 1, 'simple_local_avatar', [
				'media_id' => 202,
				'full' => $new_url,
				'blog_id' => 6,
			] )->once()->andReturn( true );
			WP_Mock::expectAction( 'simple_local_avatar_updated', 1 );
			$instance->assign_new_user_avatar( 202, 1 );
		} else {
			$instance->avatar_delete( 1 );
		}
		$this->assertSame( $media, file_exists( $original ) );
		$this->assertFileDoesNotExist( $derivative );
	}
}
