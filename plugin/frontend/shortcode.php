<?php
namespace Sgdg\Frontend\Shortcode;

function register() {
	add_action( 'init', '\\Sgdg\\Frontend\\Shortcode\\add' );
	add_action( 'wp_enqueue_scripts', '\\Sgdg\\Frontend\\Shortcode\\register_scripts_styles' );
}

function add() {
	add_shortcode( 'sgdg', '\\Sgdg\\Frontend\\Shortcode\\render' );
}

function register_scripts_styles() {
	wp_register_script( 'sgdg_gallery_init', plugins_url( '/skaut-google-drive-gallery/frontend/js/gallery_init.js' ), [ 'jquery' ] );
	wp_register_style( 'sgdg_gallery_css', plugins_url( '/skaut-google-drive-gallery/frontend/css/gallery.css' ) );

	wp_register_script( 'sgdg_masonry', plugins_url( '/skaut-google-drive-gallery/bundled/masonry.pkgd.min.js' ), [ 'jquery' ] );
	wp_register_script( 'sgdg_imagesloaded', plugins_url( '/skaut-google-drive-gallery/bundled/imagesloaded.pkgd.min.js' ), [ 'jquery' ] );
	wp_register_script( 'sgdg_imagelightbox_script', plugins_url( '/skaut-google-drive-gallery/bundled/imagelightbox.min.js' ), [ 'jquery' ] );
	wp_register_style( 'sgdg_imagelightbox_style', plugins_url( '/skaut-google-drive-gallery/bundled/imagelightbox.min.css' ) );
}

function render( $atts = [] ) {
	wp_enqueue_script( 'sgdg_masonry' );
	wp_enqueue_script( 'sgdg_imagesloaded' );
	wp_enqueue_script( 'sgdg_imagelightbox_script' );
	wp_enqueue_style( 'sgdg_imagelightbox_style' );

	wp_enqueue_script( 'sgdg_gallery_init' );
	wp_localize_script( 'sgdg_gallery_init', 'sgdg_jquery_localize', [
		'thumbnail_size'      => \Sgdg\Options::$thumbnail_size->get(),
		'thumbnail_spacing'   => \Sgdg\Options::$thumbnail_spacing->get(),
		'preview_speed'       => \Sgdg\Options::$preview_speed->get(),
		'preview_arrows'      => \Sgdg\Options::$preview_arrows->get(),
		'preview_closebutton' => \Sgdg\Options::$preview_close_button->get(),
		'preview_quitOnEnd'   => \Sgdg\Options::$preview_loop->get_inverted(),
		'preview_activity'    => \Sgdg\Options::$preview_activity_indicator->get(),
	]);
	wp_enqueue_style( 'sgdg_gallery_css' );
	wp_add_inline_style( 'sgdg_gallery_css', '.sgdg-grid-item { margin-bottom: ' . intval( \Sgdg\Options::$thumbnail_spacing->get() - 7 ) . 'px; width: ' . \Sgdg\Options::$thumbnail_size->get() . 'px; }' );

	try {
		$client = \Sgdg\Frontend\GoogleAPILib\get_drive_client();
	} catch ( \Exception $e ) {
		return '<div id="sgdg-gallery">' . esc_html__( 'Not authorized.', 'skaut-google-drive-gallery' ) . '</div>';
	}
	$root_path = \Sgdg\Options::$root_path->get();
	$dir       = end( $root_path );

	if ( isset( $atts['path'] ) && '' !== $atts['path'] ) {
		$path = explode( '/', trim( $atts['path'], " /\t\n\r\0\x0B" ) );
		$dir  = find_dir( $client, $dir, $path );
	}
	if ( ! $dir ) {
		return '<div id="sgdg-gallery">' . esc_html__( 'No such gallery found.', 'skaut-google-drive-gallery' ) . '</div>';
	}
	$ret = '<div id="sgdg-gallery">';
	if ( isset( $_GET['sgdg-path'] ) ) {

		$path = explode( '/', $_GET['sgdg-path'] );
		$ret .= '<div id="sgdg-breadcrumbs"><a href="' . remove_query_arg( 'sgdg-path' ) . '">' . esc_html__( 'Gallery', 'skaut-google-drive-gallery' ) . '</a>' . render_breadcrumbs( $client, $path ) . '</div>';
		$dir  = apply_path( $client, $dir, $path );
	}
	$ret .= render_directories( $client, $dir );
	$ret .= render_images( $client, $dir );
	return $ret . '</div>';
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

function render_breadcrumbs( $client, array $path, array $used_path = [] ) {
	$response = $client->files->get( $path[0], [
		'supportsTeamDrives' => true,
		'fields'             => 'name',
	]);
	$ret      = ' > <a href="' . add_query_arg( 'sgdg-path', implode( '/', array_merge( $used_path, [ $path[0] ] ) ) ) . '">' . $response->getName() . '</a>';
	if ( count( $path ) === 1 ) {
		return $ret;
	}
	$used_path[] = array_shift( $path );
	return $ret . render_breadcrumbs( $client, $path, $used_path );
}

function render_directories( $client, $dir ) {
	$ret        = '';
	$dir_counts = \Sgdg\Options::$dir_counts->get() === 'true';
	if ( $dir_counts ) {
		wp_add_inline_style( 'sgdg_gallery_css', '.sgdg-dir-overlay { height: 4.1em; }' );
	} else {
		wp_add_inline_style( 'sgdg_gallery_css', '.sgdg-dir-overlay { height: 3em; }' );
	}
	$page_token = null;
	do {
		$params   = [
			'q'                     => '"' . $dir . '" in parents and mimeType = "application/vnd.google-apps.folder" and trashed = false',
			'supportsTeamDrives'    => true,
			'includeTeamDriveItems' => true,
			'pageToken'             => $page_token,
			'pageSize'              => 1000,
			'fields'                => 'nextPageToken, files(id, name)',
		];
		$response = $client->files->listFiles( $params );
		foreach ( $response->getFiles() as $file ) {
			$href = add_query_arg( 'sgdg-path', ( isset( $_GET['sgdg-path'] ) ? $_GET['sgdg-path'] . '/' : '' ) . $file->getId() );
			$ret .= '<div class="sgdg-grid-item"><a class="sgdg-grid-a" href="' . $href . '">' . random_dir_image( $client, $file->getId() ) . '<div class="sgdg-dir-overlay"><div class="sgdg-dir-name">' . $file->getName() . '</div>';
			if ( $dir_counts ) {
				$ret .= dir_counts( $client, $file->getId() );
			}
			$ret .= '</div></a></div>';
		}
		$page_token = $response->getNextPageToken();
	} while ( null !== $page_token );
	return $ret;
}

function random_dir_image( $client, $dir ) {
	$images     = [];
	$page_token = null;
	do {
		$params     = [
			'q'                     => '"' . $dir . '" in parents and mimeType contains "image/" and trashed = false',
			'supportsTeamDrives'    => true,
			'includeTeamDriveItems' => true,
			'pageToken'             => $page_token,
			'pageSize'              => 1000,
			'fields'                => 'nextPageToken, files(thumbnailLink)',
		];
		$response   = $client->files->listFiles( $params );
		$images     = array_merge( $images, $response->getFiles() );
		$page_token = $response->getNextPageToken();
	} while ( null !== $page_token );
	if ( count( $images ) === 0 ) {
		return '<svg class="sgdg-dir-icon" x="0px" y="0px" focusable="false" viewBox="0 0 24 20" fill="#8f8f8f"><path d="M10 2H4c-1.1 0-1.99.9-1.99 2L2 16c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2h-8l-2-2z"></path></svg>';
	}
	$file = $images[ array_rand( $images ) ];
	return '<img class="sgdg-grid-img" src="' . substr( $file->getThumbnailLink(), 0, -4 ) . 'w' . \Sgdg\Options::$thumbnail_size->get() . '">';
}

function dir_counts( $client, $dir ) {
	$ret        = '<div class="sgdg-dir-counts">';
	$dircount   = dir_count_types( $client, $dir, 'application/vnd.google-apps.folder' );
	$imagecount = dir_count_types( $client, $dir, 'image/' );
	if ( $dircount > 0 ) {
		$ret .= $dircount . ' ' . esc_html( _n( 'folder', 'folders', $dircount, 'skaut-google-drive-gallery' ) );
		if ( $imagecount > 0 ) {
			$ret .= ', ';
		}
	}
	if ( $imagecount > 0 ) {
		$ret .= $imagecount . ' ' . esc_html( _n( 'image', 'images', $imagecount, 'skaut-google-drive-gallery' ) );
	}
	return $ret . '</div>';
}

function dir_count_types( $client, $dir, $type ) {
	$count      = 0;
	$page_token = null;
	do {
		$params     = [
			'q'                     => '"' . $dir . '" in parents and mimeType contains "' . $type . '" and trashed = false',
			'supportsTeamDrives'    => true,
			'includeTeamDriveItems' => true,
			'pageToken'             => $page_token,
			'pageSize'              => 1000,
			'fields'                => 'nextPageToken, files(id)',
		];
		$response   = $client->files->listFiles( $params );
		$count     += count( $response->getFiles() );
		$page_token = $response->getNextPageToken();
	} while ( null !== $page_token );
	return $count;
}

function render_images( $client, $dir ) {
	$ret        = '';
	$page_token = null;
	do {
		$params   = [
			'q'                     => '"' . $dir . '" in parents and mimeType contains "image/" and trashed = false',
			'supportsTeamDrives'    => true,
			'includeTeamDriveItems' => true,
			'pageToken'             => $page_token,
			'pageSize'              => 1000,
			'fields'                => 'nextPageToken, files(thumbnailLink)',
		];
		$response = $client->files->listFiles( $params );
		foreach ( $response->getFiles() as $file ) {
			$ret .= '<div class="sgdg-grid-item"><a class="sgdg-grid-a" data-imagelightbox="a" href="' . substr( $file->getThumbnailLink(), 0, -3 ) . \Sgdg\Options::$preview_size->get() . '"><img class="sgdg-grid-img" src="' . substr( $file->getThumbnailLink(), 0, -4 ) . 'w' . \Sgdg\Options::$thumbnail_size->get() . '"></a></div>';
		}
		$page_token = $response->getNextPageToken();
	} while ( null !== $page_token );
	return $ret;
}