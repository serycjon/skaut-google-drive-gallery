<?php
namespace Sgdg\Frontend\Shortcode;

function register() {
	add_action( 'init', '\\Sgdg\\Frontend\\Shortcode\\add' );
	add_action( 'wp_enqueue_scripts', '\\Sgdg\\Frontend\\Shortcode\\register_scripts_styles' );
	add_action( 'wp_ajax_list_dir', '\\Sgdg\\Frontend\\Shortcode\\handle_ajax' );
}

function add() {
	add_shortcode( 'sgdg', '\\Sgdg\\Frontend\\Shortcode\\render' );
}

function register_scripts_styles() {
	wp_register_script( 'sgdg_gallery_init', plugins_url( '/skaut-google-drive-gallery/frontend/js/shortcode.js' ), [ 'jquery' ] );
	wp_register_style( 'sgdg_gallery_css', plugins_url( '/skaut-google-drive-gallery/frontend/css/shortcode.css' ) );

	wp_register_script( 'sgdg_imagelightbox_script', plugins_url( '/skaut-google-drive-gallery/bundled/imagelightbox.min.js' ), [ 'jquery' ] );
	wp_register_style( 'sgdg_imagelightbox_style', plugins_url( '/skaut-google-drive-gallery/bundled/imagelightbox.min.css' ) );
	wp_register_script( 'sgdg_imagesloaded', plugins_url( '/skaut-google-drive-gallery/bundled/imagesloaded.pkgd.min.js' ), [ 'jquery' ] );
	wp_register_script( 'sgdg_justified-layout', plugins_url( '/skaut-google-drive-gallery/bundled/justified-layout.min.js' ) );
}

function render( $atts = [] ) {
	define( 'DONOTCACHEPAGE', true );
	wp_enqueue_script( 'sgdg_imagelightbox_script' );
	wp_enqueue_style( 'sgdg_imagelightbox_style' );
	wp_enqueue_script( 'sgdg_imagesloaded' );
	wp_enqueue_script( 'sgdg_justified-layout' );

	wp_enqueue_script( 'sgdg_gallery_init' );
	wp_localize_script( 'sgdg_gallery_init', 'sgdgShortcodeLocalize', [
		'ajax_url'            => admin_url( 'admin-ajax.php' ),
		'grid_height'         => \Sgdg\Options::$grid_height->get(),
		'grid_spacing'        => \Sgdg\Options::$grid_spacing->get(),
		'preview_speed'       => \Sgdg\Options::$preview_speed->get(),
		'preview_arrows'      => \Sgdg\Options::$preview_arrows->get(),
		'preview_closebutton' => \Sgdg\Options::$preview_close_button->get(),
		'preview_quitOnEnd'   => \Sgdg\Options::$preview_loop->get_inverted(),
		'preview_activity'    => \Sgdg\Options::$preview_activity_indicator->get(),
	]);
	wp_enqueue_style( 'sgdg_gallery_css' );
	wp_add_inline_style( 'sgdg_gallery_css', '.sgdg-dir-name {font-size: ' . \Sgdg\Options::$dir_title_size->get() . ';}' );

	$keyspace = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
	$nonce    = '';
	for ( $i = 0; $i < 128; $i++ ) {
		$nonce .= $keyspace[ wp_rand( 0, strlen( $keyspace ) - 1 ) ];
	}
	$value = isset( $atts['path'] ) ? $atts['path'] : [];

	set_transient( 'sgdg_nonce_' . $nonce, $value, 2 * HOUR_IN_SECONDS );
	return '<div id="sgdg-gallery", data-sgdg-nonce="' . $nonce . '"></div>';
}

function handle_ajax() {
	try {
		$client = \Sgdg\Frontend\GoogleAPILib\get_drive_client();
	} catch ( \Exception $e ) {
		wp_send_json( [ 'error' => esc_html__( 'Not authorized.', 'skaut-google-drive-gallery' ) ] );
	}
	$root_path = \Sgdg\Options::$root_path->get();
	$dir       = end( $root_path );

	// phpcs:ignore WordPress.CSRF.NonceVerification.NoNonceVerification
	$config_path = get_transient( 'sgdg_nonce_' . $_GET['nonce'] );

	if ( false === $config_path ) {
		wp_send_json( [ 'error' => esc_html__( 'The gallery has expired.', 'skaut-google-drive-gallery' ) ] );
	}

	if ( isset( $config_path ) && '' !== $config_path ) {
		$path = explode( '/', trim( $config_path, " /\t\n\r\0\x0B" ) );
		$dir  = find_dir( $client, $dir, $path );
	}

	if ( ! $dir ) {
		wp_send_json( [ 'error' => esc_html__( 'No such gallery found.', 'skaut-google-drive-gallery' ) ] );
	}
	$ret = [];
	// phpcs:ignore WordPress.CSRF.NonceVerification.NoNonceVerification
	if ( isset( $_GET['sgdg-path'] ) ) {

		// phpcs:ignore WordPress.CSRF.NonceVerification.NoNonceVerification
		$path        = explode( '/', $_GET['sgdg-path'] );
		$ret['path'] = path_names( $client, $path ) . '</div>';
		$dir         = apply_path( $client, $dir, $path );
	}
	$ret['directories'] = directories( $client, $dir );
	$ret['images']      = images( $client, $dir );
	wp_send_json( $ret );
}

function find_dir( $client, $root, array $path ) {
	$page_token = null;
	do {
		$params   = [
			'q'                     => '"' . $root . '" in parents and mimeType = "application/vnd.google-apps.folder" and trashed = false',
			'supportsTeamDrives'    => true,
			'includeTeamDriveItems' => true,
			'pageToken'             => $page_token,
			'pageSize'              => 1000,
			'fields'                => 'nextPageToken, files(id, name)',
		];
		$response = $client->files->listFiles( $params );
		foreach ( $response->getFiles() as $file ) {
			if ( $file->getName() === $path[0] ) {
				if ( count( $path ) === 1 ) {
					return $file->getId();
				}
				array_shift( $path );
				return find_dir( $client, $file->getId(), $path );
			}
		}
		$page_token = $response->getNextPageToken();
	} while ( null !== $page_token );
	return null;
}

function apply_path( $client, $root, array $path ) {
	$page_token = null;
	do {
		$params   = [
			'q'                     => '"' . $root . '" in parents and mimeType = "application/vnd.google-apps.folder" and trashed = false',
			'supportsTeamDrives'    => true,
			'includeTeamDriveItems' => true,
			'pageToken'             => $page_token,
			'pageSize'              => 1000,
			'fields'                => 'nextPageToken, files(id)',
		];
		$response = $client->files->listFiles( $params );
		foreach ( $response->getFiles() as $file ) {
			if ( $file->getId() === $path[0] ) {
				if ( count( $path ) === 1 ) {
					return $file->getId();
				}
				array_shift( $path );
				return apply_path( $client, $file->getId(), $path );
			}
		}
		$page_token = $response->getNextPageToken();
	} while ( null !== $page_token );
	return null;
}

function path_names( $client, array $path, array $used_path = [] ) {
	$ret = [];
	foreach ( $path as $segment ) {
		$response = $client->files->get( $segment, [
			'supportsTeamDrives' => true,
			'fields'             => 'name',
		]);
		$ret[]    = [
			'id'   => $segment,
			'name' => $response->getName(),
		];
	}
	return $ret;
}

function directories( $client, $dir ) {
	$dir_counts_allowed = \Sgdg\Options::$dir_counts->get() === 'true';
	$ids                = [];
	$names              = [];

	$page_token = null;
	do {
		$params   = [
			'q'                     => '"' . $dir . '" in parents and mimeType = "application/vnd.google-apps.folder" and trashed = false',
			'supportsTeamDrives'    => true,
			'includeTeamDriveItems' => true,
			'orderBy'               => \Sgdg\Options::$dir_ordering->get(),
			'pageToken'             => $page_token,
			'pageSize'              => 1000,
			'fields'                => 'nextPageToken, files(id, name)',
		];
		$response = $client->files->listFiles( $params );
		foreach ( $response->getFiles() as $file ) {
			$ids[]   = $file->getId();
			$names[] = $file->getName();
		}
		$page_token = $response->getNextPageToken();
	} while ( null !== $page_token );

	$client->getClient()->setUseBatch( true );
	$batch = $client->createBatch();
	dir_images_requests( $client, $batch, $ids );
	if ( $dir_counts_allowed ) {
		dir_counts_requests( $client, $batch, $ids );
	}
	$responses = $batch->execute();
	$client->getClient()->setUseBatch( false );

	try {
		$dir_images = dir_images_responses( $responses, $ids );
		if ( $dir_counts_allowed ) {
			$dir_counts = dir_counts_responses( $responses, $ids );
		}
	} catch ( \Sgdg\Vendor\Google_Service_Exception $e ) {
		if ( 'userRateLimitExceeded' === $e->getErrors()[0]['reason'] ) {
			return esc_html__( 'The maximum number of requests has been exceeded. Please try again in a minute.', 'skaut-google-drive-gallery' );
		} else {
			return $e->getErrors()[0]['message'];
		}
	}

	$ret   = [];
	$count = count( $ids );
	for ( $i = 0; $i < $count; $i++ ) {
		$val = [
			'id'        => $ids[ $i ],
			'name'      => $name[ $i ],
			'thumbnail' => $images[ $i ],
		];
		if ( $dir_counts_allowed ) {
			$val = array_merge( $val, $dir_counts[ $i ] );
		}
		$ret[] = $val;
	}
	return $ret;
}

function dir_images_requests( $client, $batch, $dirs ) {
	$params = [
		'supportsTeamDrives'    => true,
		'includeTeamDriveItems' => true,
		'orderBy'               => \Sgdg\Options::$image_ordering->get(),
		'pageSize'              => 1,
		'fields'                => 'files(imageMediaMetadata(width, height), thumbnailLink)',
	];

	foreach ( $dirs as $dir ) {
		$params['q'] = '"' . $dir . '" in parents and mimeType contains "image/" and trashed = false';
		$request     = $client->files->listFiles( $params );
		$batch->add( $request, 'img-' . $dir );
	}
}

function dir_counts_requests( $client, $batch, $dirs ) {
	$params = [
		'supportsTeamDrives'    => true,
		'includeTeamDriveItems' => true,
		'pageSize'              => 1000,
		'fields'                => 'files(id)',
	];

	foreach ( $dirs as $dir ) {
		$params['q'] = '"' . $dir . '" in parents and mimeType contains "application/vnd.google-apps.folder" and trashed = false';
		$request     = $client->files->listFiles( $params );
		$batch->add( $request, 'dircount-' . $dir );
		$params['q'] = '"' . $dir . '" in parents and mimeType contains "image/" and trashed = false';
		$request     = $client->files->listFiles( $params );
		$batch->add( $request, 'imgcount-' . $dir );
	}
}

function dir_images_responses( $responses, $dirs ) {
	$ret = [];
	foreach ( $dirs as $dir ) {
		$response = $responses[ 'response-img-' . $dir ];
		if ( $response instanceof \Sgdg\Vendor\Google_Service_Exception ) {
			throw $response;
		}
		$images = $response->getFiles();
		if ( count( $images ) === 0 ) {
			$ret[] = false;
		} else {
			$ret[] = substr( $images[0]->getThumbnailLink(), 0, -4 ) . ( $images[0]->getImageMediaMetadata()->getWidth() > $images[0]->getImageMediaMetadata()->getHeight() ? 'h' : 'w' ) . floor( 1.25 * \Sgdg\Options::$grid_height->get() );

		}
	}
	return $ret;
}

function dir_counts_responses( $responses, $dirs ) {
	$ret = [];
	foreach ( $dirs as $dir ) {
		$dir_response = $responses[ 'response-dircount-' . $dir ];
		$img_response = $responses[ 'response-imgcount-' . $dir ];
		if ( $dir_response instanceof \Sgdg\Vendor\Google_Service_Exception ) {
			throw $dir_response;
		}
		if ( $img_response instanceof \Sgdg\Vendor\Google_Service_Exception ) {
			throw $img_response;
		}
		$dircount   = count( $dir_response->getFiles() );
		$imagecount = count( $img_response->getFiles() );

		$val = [];
		if ( $dircount > 0 ) {
			$val['dircount'] = $dircount;
		}
		if ( $imagecount > 0 ) {
			$val['imagecount'] = $imagecount;
		}
		$ret[] = $val;
	}
	return $ret;
}

function images( $client, $dir ) {
	$ret        = [];
	$page_token = null;
	do {
		$params   = [
			'q'                     => '"' . $dir . '" in parents and mimeType contains "image/" and trashed = false',
			'supportsTeamDrives'    => true,
			'includeTeamDriveItems' => true,
			'orderBy'               => \Sgdg\Options::$image_ordering->get(),
			'pageToken'             => $page_token,
			'pageSize'              => 1000,
			'fields'                => 'nextPageToken, files(id, thumbnailLink)',
		];
		$response = $client->files->listFiles( $params );
		foreach ( $response->getFiles() as $file ) {
			$ret[] = [
				'id'        => $file->getId(),
				'image'     => substr( $file->getThumbnailLink(), 0, -3 ) . \Sgdg\Options::$preview_size->get(),
				'thumbnail' => substr( $file->getThumbnailLink(), 0, -4 ) . 'h' . floor( 1.25 * \Sgdg\Options::$grid_height->get() ),
			];
		}
		$page_token = $response->getNextPageToken();
	} while ( null !== $page_token );
	return $ret;
}
