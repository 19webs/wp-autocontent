<?php
/**
 * Clase para el scraping y extracción de contenidos desde URLs externas.
 *
 * @package WP_Autocontent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Autocontent_Scraper {

	/**
	 * Extrae títulos, párrafos e imágenes desde una URL.
	 *
	 * @param string $url URL externa a escanear.
	 * @return array|WP_Error Array con 'headings', 'paragraphs', 'images' o WP_Error en caso de fallo.
	 */
	public function scrape_url( string $url ) {
		$url = esc_url_raw( $url );
		if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return new WP_Error( 'invalid_url', __( 'La URL proporcionada no es válida.', 'wp-autocontent' ) );
		}

		$args = array(
			'timeout'    => 15,
			'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 WPAutocontent/1.0',
			'sslverify'  => false,
		);

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'http_error', sprintf( __( 'Respuesta HTTP no válida: Código %d', 'wp-autocontent' ), $code ) );
		}

		$html = wp_remote_retrieve_body( $response );
		if ( empty( $html ) ) {
			return new WP_Error( 'empty_body', __( 'El cuerpo de la respuesta HTML está vacío.', 'wp-autocontent' ) );
		}

		return $this->parse_html( $html, $url );
	}

	/**
	 * Parsea el HTML usando DOMDocument y DOMXPath de PHP.
	 *
	 * @param string $html HTML descargado.
	 * @param string $base_url URL base para resolver URLs relativas de imágenes.
	 * @return array
	 */
	private function parse_html( string $html, string $base_url ): array {
		$libxml_previous_state = libxml_use_internal_errors( true );

		$dom = new DOMDocument();
		$encoded_html = '<?xml encoding="UTF-8">' . $html;
		@$dom->loadHTML( mb_convert_encoding( $encoded_html, 'HTML-ENTITIES', 'UTF-8' ) );

		$xpath = new DOMXPath( $dom );

		$headings   = array();
		$paragraphs = array();
		$images     = array();

		// 1. Extraer Encabezados (h1, h2, h3)
		$heading_nodes = $xpath->query( '//h1 | //h2 | //h3' );
		if ( $heading_nodes ) {
			foreach ( $heading_nodes as $node ) {
				$text = sanitize_text_field( trim( $node->textContent ) );
				if ( strlen( $text ) >= 5 && ! in_array( $text, $headings, true ) ) {
					$headings[] = $text;
				}
			}
		}

		// 2. Extraer Párrafos (p)
		$paragraph_nodes = $xpath->query( '//p' );
		if ( $paragraph_nodes ) {
			foreach ( $paragraph_nodes as $node ) {
				$text = sanitize_text_field( trim( $node->textContent ) );
				if ( mb_strlen( $text, 'UTF-8' ) >= 25 && ! in_array( $text, $paragraphs, true ) ) {
					$paragraphs[] = $text;
				}
			}
		}

		// 3. Extraer Imágenes (img src, data-src, data-lazy-src)
		$img_nodes = $xpath->query( '//img' );
		if ( $img_nodes ) {
			foreach ( $img_nodes as $node ) {
				/** @var DOMElement $node */
				$src = $node->getAttribute( 'src' );
				if ( empty( $src ) || strpos( $src, 'data:image' ) === 0 ) {
					$src = $node->getAttribute( 'data-src' );
				}
				if ( empty( $src ) || strpos( $src, 'data:image' ) === 0 ) {
					$src = $node->getAttribute( 'data-lazy-src' );
				}

				if ( empty( $src ) ) {
					continue;
				}

				$full_img_url = $this->resolve_relative_url( $src, $base_url );

				if ( $this->is_valid_image_url( $full_img_url ) && ! in_array( $full_img_url, $images, true ) ) {
					$images[] = $full_img_url;
				}
			}
		}

		libxml_clear_errors();
		libxml_use_internal_errors( $libxml_previous_state );

		return array(
			'headings'   => array_values( $headings ),
			'paragraphs' => array_values( $paragraphs ),
			'images'     => array_values( $images ),
		);
	}

	/**
	 * Verifica si la URL de imagen es válida y tiene extensiones permitidas.
	 *
	 * @param string $url URL de la imagen.
	 * @return bool
	 */
	private function is_valid_image_url( string $url ): bool {
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		if ( preg_match( '/\.(svg|ico)$/i', $url ) || preg_match( '/(avatar|logo|icon|sprite|favicon|badge)/i', $url ) ) {
			return false;
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! $path ) {
			return false;
		}

		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp' ), true );
	}

	/**
	 * Resuelve URLs relativas contra la URL base.
	 *
	 * @param string $relative URL encontrada en el HTML.
	 * @param string $base URL base de la página escaneda.
	 * @return string URL absoluta resultante.
	 */
	private function resolve_relative_url( string $relative, string $base ): string {
		if ( parse_url( $relative, PHP_URL_SCHEME ) != '' ) {
			return $relative;
		}

		if ( strpos( $relative, '//' ) === 0 ) {
			$scheme = parse_url( $base, PHP_URL_SCHEME );
			return ( $scheme ? $scheme : 'https' ) . ':' . $relative;
		}

		$base_parts = parse_url( $base );
		$scheme     = isset( $base_parts['scheme'] ) ? $base_parts['scheme'] : 'https';
		$host       = isset( $base_parts['host'] ) ? $base_parts['host'] : '';

		if ( strpos( $relative, '/' ) === 0 ) {
			return $scheme . '://' . $host . $relative;
		}

		$path = isset( $base_parts['path'] ) ? $base_parts['path'] : '/';
		$path = preg_replace( '#/[^/]*$#', '/', $path );

		return $scheme . '://' . $host . $path . $relative;
	}
}
