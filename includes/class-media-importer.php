<?php
/**
 * Gestor de medios e importador de imágenes e iconos PNG desde Pexels, Unsplash, Pixabay, Iconify e Iconfinder a wp_media.
 *
 * @package WP_Autocontent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Autocontent_Media_Importer {

	/**
	 * Claves API configuradas.
	 */
	private string $pexels_api_key;
	private string $unsplash_api_key;
	private string $pixabay_api_key;
	private string $iconfinder_api_key;

	/**
	 * Cache interno de URLs importadas.
	 */
	private static array $imported_cache = array();

	/**
	 * Constructor.
	 */
	public function __construct( string $pexels_key = '', string $unsplash_key = '', string $pixabay_key = '', string $iconfinder_key = '' ) {
		$this->pexels_api_key     = trim( $pexels_key );
		$this->unsplash_api_key   = trim( $unsplash_key );
		$this->pixabay_api_key    = trim( $pixabay_key );
		$this->iconfinder_api_key = trim( $iconfinder_key );
	}

	/**
	 * Busca e importa imágenes desde la API seleccionada con fallback automático.
	 */
	public function fetch_and_import( string $provider, string $keyword, int $count = 20 ) {
		$keyword = sanitize_text_field( $keyword );
		if ( empty( $keyword ) ) {
			return new WP_Error( 'empty_keyword', __( 'Debe proporcionar una palabra clave en inglés.', 'wp-autocontent' ) );
		}

		if ( 'unsplash' === $provider && ! empty( $this->unsplash_api_key ) ) {
			$res = $this->fetch_unsplash( $keyword, $count );
			if ( ! is_wp_error( $res ) ) {
				return $res;
			}
		}

		if ( 'pixabay' === $provider && ! empty( $this->pixabay_api_key ) ) {
			$res = $this->fetch_pixabay( $keyword, $count );
			if ( ! is_wp_error( $res ) ) {
				return $res;
			}
		}

		return $this->fetch_pexels( $keyword, $count );
	}

	/**
	 * Busca e importa iconos PNG transparentes desde Iconify (Gratis - Sin clave) o Iconfinder API.
	 */
	public function fetch_png_icons( string $provider, string $keyword, int $count = 15, string $hex_color = '4f46e5' ) {
		$keyword = sanitize_text_field( $keyword );
		if ( empty( $keyword ) ) {
			$keyword = 'business';
		}

		if ( 'iconfinder' === $provider && ! empty( $this->iconfinder_api_key ) ) {
			$res = $this->fetch_iconfinder_png( $keyword, $count );
			if ( ! is_wp_error( $res ) ) {
				return $res;
			}
		}

		// Predeterminado: Iconify API (100% Gratis sin necesidad de clave)
		return $this->fetch_iconify_png( $keyword, $count, $hex_color );
	}

	/**
	 * Iconify API (100% Gratuito sin API Key) - Descarga PNGs vectoriales transparentes
	 */
	public function fetch_iconify_png( string $keyword, int $count = 15, string $hex_color = '4f46e5' ) {
		$endpoint = sprintf(
			'https://api.iconify.design/search?query=%s&limit=%d',
			urlencode( $keyword ),
			min( 50, max( 5, $count ) )
		);

		$response = wp_remote_get( $endpoint, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			return new WP_Error( 'iconify_error', sprintf( __( 'Error en Iconify API (%d)', 'wp-autocontent' ), $code ) );
		}

		$data = json_decode( $body, true );
		if ( empty( $data['icons'] ) || ! is_array( $data['icons'] ) ) {
			// Probar palabra clave genérica de respaldo si no hay resultados específicos
			$endpoint = sprintf( 'https://api.iconify.design/search?query=check&limit=%d', $count );
			$response = wp_remote_get( $endpoint, array( 'timeout' => 15 ) );
			$data     = json_decode( wp_remote_retrieve_body( $response ), true );
		}

		if ( empty( $data['icons'] ) ) {
			return new WP_Error( 'iconify_no_results', __( 'No se encontraron iconos en Iconify.', 'wp-autocontent' ) );
		}

		$clean_color = ltrim( sanitize_hex_color_no_hash( $hex_color ), '#' );
		if ( empty( $clean_color ) ) {
			$clean_color = '4f46e5';
		}

		$imported_icons = array();
		foreach ( $data['icons'] as $icon_fullname ) {
			// Formato: "prefix:name" -> e.g. "heroicons:academic-cap"
			$parts = explode( ':', $icon_fullname );
			if ( count( $parts ) === 2 ) {
				$prefix = $parts[0];
				$name   = $parts[1];

				$png_url = sprintf( 'https://api.iconify.design/%s/%s.png?height=256&color=%%23%s', $prefix, $name, $clean_color );
				$title   = 'Icono PNG - ' . $name;

				$imported = $this->import_remote_image( $png_url, $title );
				if ( ! is_wp_error( $imported ) ) {
					$imported_icons[] = $imported;
				}
			}
		}

		return ! empty( $imported_icons ) ? $imported_icons : new WP_Error( 'iconify_import_failed', __( 'No se pudieron descargar los iconos PNG de Iconify.', 'wp-autocontent' ) );
	}

	/**
	 * Iconfinder API - Descarga de iconos PNG transparentes
	 */
	public function fetch_iconfinder_png( string $keyword, int $count = 15 ) {
		if ( empty( $this->iconfinder_api_key ) ) {
			return new WP_Error( 'missing_iconfinder_key', __( 'No se ha configurado la API Key de Iconfinder.', 'wp-autocontent' ) );
		}

		$endpoint = sprintf(
			'https://api.iconfinder.com/v4/icons/search?query=%s&count=%d',
			urlencode( $keyword ),
			min( 50, max( 5, $count ) )
		);

		$args = array(
			'headers' => array( 'Authorization' => 'Bearer ' . $this->iconfinder_api_key ),
			'timeout' => 15,
		);

		$response = wp_remote_get( $endpoint, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			return new WP_Error( 'iconfinder_error', sprintf( __( 'Error en Iconfinder API (%d)', 'wp-autocontent' ), $code ) );
		}

		$data = json_decode( $body, true );
		if ( empty( $data['icons'] ) || ! is_array( $data['icons'] ) ) {
			return new WP_Error( 'iconfinder_no_results', __( 'No se encontraron iconos en Iconfinder.', 'wp-autocontent' ) );
		}

		$imported_icons = array();
		foreach ( $data['icons'] as $icon_item ) {
			if ( ! empty( $icon_item['raster_sizes'] ) && is_array( $icon_item['raster_sizes'] ) ) {
				// Buscar tamaño PNG grande (256px o 128px)
				$png_url = '';
				foreach ( $icon_item['raster_sizes'] as $size ) {
					if ( isset( $size['size'] ) && ( 256 === $size['size'] || 128 === $size['size'] ) && ! empty( $size['formats'][0]['preview_url'] ) ) {
						$png_url = $size['formats'][0]['preview_url'];
						break;
					}
				}

				if ( empty( $png_url ) && ! empty( $icon_item['raster_sizes'][0]['formats'][0]['preview_url'] ) ) {
					$png_url = $icon_item['raster_sizes'][0]['formats'][0]['preview_url'];
				}

				if ( ! empty( $png_url ) ) {
					$title    = 'Iconfinder PNG - ' . $keyword;
					$imported = $this->import_remote_image( $png_url, $title );
					if ( ! is_wp_error( $imported ) ) {
						$imported_icons[] = $imported;
					}
				}
			}
		}

		return ! empty( $imported_icons ) ? $imported_icons : new WP_Error( 'iconfinder_import_failed', __( 'No se pudieron descargar los iconos de Iconfinder.', 'wp-autocontent' ) );
	}

	/**
	 * Pexels API
	 */
	public function fetch_pexels( string $keyword, int $count = 20 ) {
		if ( empty( $this->pexels_api_key ) ) {
			return new WP_Error( 'missing_pexels_key', __( 'No se ha configurado la API Key de Pexels.', 'wp-autocontent' ) );
		}

		$endpoint = sprintf(
			'https://api.pexels.com/v1/search?query=%s&per_page=%d&orientation=landscape',
			urlencode( $keyword ),
			min( 80, max( 1, $count ) )
		);

		$args = array(
			'headers' => array( 'Authorization' => $this->pexels_api_key ),
			'timeout' => 20,
		);

		$response = wp_remote_get( $endpoint, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			return new WP_Error( 'pexels_api_error', sprintf( __( 'Error en la API de Pexels (Código %d)', 'wp-autocontent' ), $code ) );
		}

		$data = json_decode( $body, true );
		if ( empty( $data['photos'] ) || ! is_array( $data['photos'] ) ) {
			return new WP_Error( 'pexels_no_results', sprintf( __( 'No se encontraron resultados en Pexels para "%s".', 'wp-autocontent' ), $keyword ) );
		}

		$imported_media = array();
		foreach ( $data['photos'] as $photo ) {
			$img_url  = ! empty( $photo['src']['large2x'] ) ? $photo['src']['large2x'] : $photo['src']['large'];
			$title    = ! empty( $photo['alt'] ) ? $photo['alt'] : 'Pexels - ' . $keyword;
			$imported = $this->import_remote_image( $img_url, $title );
			if ( ! is_wp_error( $imported ) ) {
				$imported_media[] = $imported;
			}
		}

		return ! empty( $imported_media ) ? $imported_media : new WP_Error( 'import_failed', __( 'No se pudo importar imágenes desde Pexels.', 'wp-autocontent' ) );
	}

	/**
	 * Unsplash API
	 */
	public function fetch_unsplash( string $keyword, int $count = 20 ) {
		if ( empty( $this->unsplash_api_key ) ) {
			return new WP_Error( 'missing_unsplash_key', __( 'No se ha configurado la API Key de Unsplash.', 'wp-autocontent' ) );
		}

		$endpoint = sprintf(
			'https://api.unsplash.com/search/photos?query=%s&per_page=%d&orientation=landscape',
			urlencode( $keyword ),
			min( 30, max( 1, $count ) )
		);

		$args = array(
			'headers' => array( 'Authorization' => 'Client-ID ' . $this->unsplash_api_key ),
			'timeout' => 20,
		);

		$response = wp_remote_get( $endpoint, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			return new WP_Error( 'unsplash_api_error', sprintf( __( 'Error en la API de Unsplash (Código %d)', 'wp-autocontent' ), $code ) );
		}

		$data = json_decode( $body, true );
		if ( empty( $data['results'] ) || ! is_array( $data['results'] ) ) {
			return new WP_Error( 'unsplash_no_results', sprintf( __( 'No se encontraron resultados en Unsplash para "%s".', 'wp-autocontent' ), $keyword ) );
		}

		$imported_media = array();
		foreach ( $data['results'] as $photo ) {
			$img_url  = ! empty( $photo['urls']['regular'] ) ? $photo['urls']['regular'] : $photo['urls']['full'];
			$title    = ! empty( $photo['alt_description'] ) ? $photo['alt_description'] : 'Unsplash - ' . $keyword;
			$imported = $this->import_remote_image( $img_url, $title );
			if ( ! is_wp_error( $imported ) ) {
				$imported_media[] = $imported;
			}
		}

		return ! empty( $imported_media ) ? $imported_media : new WP_Error( 'import_failed', __( 'No se pudo importar imágenes desde Unsplash.', 'wp-autocontent' ) );
	}

	/**
	 * Pixabay API
	 */
	public function fetch_pixabay( string $keyword, int $count = 20 ) {
		if ( empty( $this->pixabay_api_key ) ) {
			return new WP_Error( 'missing_pixabay_key', __( 'No se ha configurado la API Key de Pixabay.', 'wp-autocontent' ) );
		}

		$endpoint = sprintf(
			'https://pixabay.com/api/?key=%s&q=%s&image_type=photo&orientation=horizontal&per_page=%d',
			urlencode( $this->pixabay_api_key ),
			urlencode( $keyword ),
			min( 50, max( 3, $count ) )
		);

		$args     = array( 'timeout' => 20 );
		$response = wp_remote_get( $endpoint, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			return new WP_Error( 'pixabay_api_error', sprintf( __( 'Error en la API de Pixabay (Código %d)', 'wp-autocontent' ), $code ) );
		}

		$data = json_decode( $body, true );
		if ( empty( $data['hits'] ) || ! is_array( $data['hits'] ) ) {
			return new WP_Error( 'pixabay_no_results', sprintf( __( 'No se encontraron resultados en Pixabay para "%s".', 'wp-autocontent' ), $keyword ) );
		}

		$imported_media = array();
		foreach ( $data['hits'] as $hit ) {
			$img_url  = ! empty( $hit['largeImageURL'] ) ? $hit['largeImageURL'] : $hit['webformatURL'];
			$title    = ! empty( $hit['tags'] ) ? $hit['tags'] : 'Pixabay - ' . $keyword;
			$imported = $this->import_remote_image( $img_url, $title );
			if ( ! is_wp_error( $imported ) ) {
				$imported_media[] = $imported;
			}
		}

		return ! empty( $imported_media ) ? $imported_media : new WP_Error( 'import_failed', __( 'No se pudo importar imágenes desde Pixabay.', 'wp-autocontent' ) );
	}

	/**
	 * Descarga e importa físicamente una imagen o icono remotos a wp_media usando media_handle_sideload().
	 */
	public function import_remote_image( string $url, string $title = '', int $post_id = 0 ) {
		$url = esc_url_raw( $url );
		if ( empty( $url ) ) {
			return new WP_Error( 'invalid_image_url', __( 'La URL de la imagen no es válida.', 'wp-autocontent' ) );
		}

		if ( isset( self::$imported_cache[ $url ] ) ) {
			return self::$imported_cache[ $url ];
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		if ( ! function_exists( 'wp_read_image_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$tmp_file = download_url( $url, 25 );

		if ( is_wp_error( $tmp_file ) ) {
			return $tmp_file;
		}

		$path_parts = pathinfo( wp_parse_url( $url, PHP_URL_PATH ) );
		$filename   = ! empty( $path_parts['basename'] ) ? sanitize_file_name( $path_parts['basename'] ) : 'demo-img-' . time() . '.png';

		if ( ! preg_match( '/\.(jpg|jpeg|png|webp|svg)$/i', $filename ) ) {
			$filename .= '.png';
		}

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp_file,
		);

		$desc = ! empty( $title ) ? sanitize_text_field( $title ) : __( 'Elemento importado por WP autocontent', 'wp-autocontent' );

		$attachment_id = media_handle_sideload( $file_array, $post_id, $desc );

		if ( file_exists( $tmp_file ) ) {
			@unlink( $tmp_file );
		}

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$attachment_url = wp_get_attachment_url( $attachment_id );

		$result = array(
			'id'  => (int) $attachment_id,
			'url' => (string) $attachment_url,
		);

		self::$imported_cache[ $url ] = $result;

		return $result;
	}
}
