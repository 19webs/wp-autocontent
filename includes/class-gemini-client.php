<?php
/**
 * Cliente REST API para la generación asistida de textos con Google Gemini.
 *
 * @package WP_Autocontent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Autocontent_Gemini_Client {

	/**
	 * Clave API de Google Gemini.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Constructor.
	 *
	 * @param string $api_key Clave API de Google AI Studio.
	 */
	public function __construct( string $api_key ) {
		$this->api_key = trim( $api_key );
	}

	/**
	 * Descubre dinámicamente los modelos de Gemini soportados activamente por la API Key.
	 *
	 * @return array
	 */
	private function discover_active_models(): array {
		$url      = add_query_arg( 'key', $this->api_key, 'https://generativelanguage.googleapis.com/v1beta/models' );
		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );

		$valid_models = array();

		if ( ! is_wp_error( $response ) ) {
			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( 200 === $code && ! empty( $data['models'] ) && is_array( $data['models'] ) ) {
				foreach ( $data['models'] as $m ) {
					$name    = isset( $m['name'] ) ? $m['name'] : '';
					$methods = isset( $m['supportedGenerationMethods'] ) ? (array) $m['supportedGenerationMethods'] : array();

					// Excluir modelos descontinuados explícitamente (2.0 y 2.5)
					if ( in_array( 'generateContent', $methods, true ) && strpos( $name, 'gemini-2.0' ) === false && strpos( $name, 'gemini-2.5' ) === false ) {
						$valid_models[] = $name;
					}
				}
			}
		}

		// Añadir modelos de producción estándar como prioridad
		return array_unique(
			array_merge(
				$valid_models,
				array(
					'models/gemini-1.5-flash',
					'models/gemini-3.6-flash',
					'models/gemini-1.5-flash-latest',
					'models/gemini-1.5-pro',
				)
			)
		);
	}

	/**
	 * Genera un conjunto de títulos y párrafos orientados a un sector y tono específicos.
	 *
	 * @param string $sector Sector o actividad del negocio (ej. "Clínica dental").
	 * @param string $tone Tono de comunicación (ej. "Profesional y cercano").
	 * @param int $num_headings Número aproximado de títulos deseados (por defecto 25).
	 * @param int $num_paragraphs Número aproximado de párrafos deseados (por defecto 25).
	 * @return array|WP_Error Array con 'headings' y 'paragraphs' o WP_Error en caso de fallo.
	 */
	public function generate_content( string $sector, string $tone = 'Profesional', int $num_headings = 25, int $num_paragraphs = 25 ) {
		if ( empty( $this->api_key ) ) {
			return new WP_Error( 'missing_api_key', __( 'No se ha configurado la API Key de Google Gemini.', 'wp-autocontent' ) );
		}

		$models_to_try = $this->discover_active_models();

		$prompt = sprintf(
			'Actúa como un copywriter profesional de sitios web. Genera contenido ÚNICO y DIVERSO en ESPAÑOL para una web del sector "%s" con un tono de comunicación "%s". ' .
			'Debes devolver EXACTAMENTE %d titulares variados (incluyendo frases muy cortas de 15 a 35 caracteres para listas y botones, y titulares medios de 35 a 65 caracteres para secciones y tarjetas) y %d párrafos detallados (de longitudes variadas entre 80 y 250 caracteres). ' .
			'NO repitas frases ni conceptos entre los elementos. ' .
			'Responde únicamente con un objeto JSON estricto con el siguiente esquema exacto: {"headings": ["Título 1", ...], "paragraphs": ["Párrafo 1", ...]}',
			sanitize_text_field( $sector ),
			sanitize_text_field( $tone ),
			max( 20, $num_headings ),
			max( 20, $num_paragraphs )
		);

		$last_error = null;

		foreach ( $models_to_try as $model_path ) {
			$base_url = 'https://generativelanguage.googleapis.com/v1beta/' . ltrim( $model_path, '/' ) . ':generateContent';
			$endpoint = add_query_arg( 'key', $this->api_key, $base_url );

			$payload = array(
				'contents'         => array(
					array(
						'parts' => array(
							array( 'text' => $prompt ),
						),
					),
				),
				'generationConfig' => array(
					'responseMimeType' => 'application/json',
					'temperature'      => 0.7,
				),
			);

			$args = array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 35,
			);

			$response = wp_remote_post( $endpoint, $args );

			if ( is_wp_error( $response ) ) {
				$last_error = $response;
				continue; // Probar siguiente modelo en la lista
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			if ( 200 !== $code ) {
				$json_err   = json_decode( $body, true );
				$err_msg    = isset( $json_err['error']['message'] ) ? $json_err['error']['message'] : sprintf( __( 'Error HTTP de Gemini (%d)', 'wp-autocontent' ), $code );
				$last_error = new WP_Error( 'gemini_api_error', $err_msg );
				continue; // NO RETORNAR AHORA; probar el siguiente modelo candidato del loop
			}

			$data = json_decode( $body, true );
			if ( empty( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
				$last_error = new WP_Error( 'gemini_empty_response', __( 'Gemini no devolvió un resultado válido.', 'wp-autocontent' ) );
				continue;
			}

			$raw_text = trim( $data['candidates'][0]['content']['parts'][0]['text'] );

			if ( preg_match( '/```(?:json)?\s*(\{.*?\})\s*```/s', $raw_text, $matches ) ) {
				$raw_text = $matches[1];
			} elseif ( preg_match( '/\{.*\}/s', $raw_text, $matches ) ) {
				$raw_text = $matches[0];
			}

			$content = json_decode( $raw_text, true );

			if ( ! is_array( $content ) || ! isset( $content['headings'] ) || ! isset( $content['paragraphs'] ) ) {
				$last_error = new WP_Error( 'gemini_invalid_json', __( 'El formato JSON devuelto por Gemini no coincide con el esquema requerido.', 'wp-autocontent' ) );
				continue;
			}

			$clean_headings = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) $content['headings'] ) ) ) );
			$clean_paras    = array_values( array_unique( array_filter( array_map( 'sanitize_textarea_field', (array) $content['paragraphs'] ) ) ) );

			return array(
				'headings'   => $clean_headings,
				'paragraphs' => $clean_paras,
			);
		}

		return is_wp_error( $last_error ) ? $last_error : new WP_Error( 'gemini_failed', __( 'No se pudo conectar con la API de Gemini tras probar todos los modelos disponibles.', 'wp-autocontent' ) );
	}

	/**
	 * Pulir, limpiar y reestructurar contenido extraído mediante scraping usando Gemini IA.
	 *
	 * @param array $scraped_headings Titulares extraídos.
	 * @param array $scraped_paragraphs Párrafos extraídos.
	 * @param int $num_headings Número aproximado de títulos deseados.
	 * @param int $num_paragraphs Número aproximado de párrafos deseados.
	 * @return array|WP_Error Array con 'headings' y 'paragraphs' o WP_Error.
	 */
	public function enhance_scraped_text( array $scraped_headings, array $scraped_paragraphs, int $num_headings = 25, int $num_paragraphs = 25 ) {
		if ( empty( $this->api_key ) ) {
			return new WP_Error( 'missing_api_key', __( 'No se ha configurado la API Key de Google Gemini.', 'wp-autocontent' ) );
		}

		$models_to_try = $this->discover_active_models();

		$raw_content = "TITULARES EXTRAÍDOS:\n" . implode( "\n", array_slice( $scraped_headings, 0, 30 ) ) . "\n\nPÁRRAFOS EXTRAÍDOS:\n" . implode( "\n", array_slice( $scraped_paragraphs, 0, 30 ) );

		$prompt = sprintf(
			"Actúa como un copywriter web profesional. A continuación se te presentan fragmentos de texto extraídos de la web oficial de un cliente:\n\n%s\n\n" .
			"Tu tarea es:\n" .
			"1. Filtrar y eliminar cualquier texto basura, avisos de cookies, menús de navegación, avisos legales o textos duplicados.\n" .
			"2. Reorganizar y reescribir la información relevante en un español profesional, fluido y de alta calidad.\n" .
			"3. Devolver EXACTAMENTE %d titulares variados (incluyendo frases cortas de 15 a 35 caracteres para botones/tarjetas y títulos de 35 a 65 caracteres) y %d párrafos detallados (de 80 a 250 caracteres).\n" .
			"Responde únicamente con un objeto JSON estricto con el siguiente esquema: {\"headings\": [\"Título 1\", ...], \"paragraphs\": [\"Párrafo 1\", ...]}",
			$raw_content,
			max( 20, $num_headings ),
			max( 20, $num_paragraphs )
		);

		$last_error = null;

		foreach ( $models_to_try as $model_path ) {
			$base_url = 'https://generativelanguage.googleapis.com/v1beta/' . ltrim( $model_path, '/' ) . ':generateContent';
			$endpoint = add_query_arg( 'key', $this->api_key, $base_url );

			$payload = array(
				'contents'         => array(
					array(
						'parts' => array(
							array( 'text' => $prompt ),
						),
					),
				),
				'generationConfig' => array(
					'responseMimeType' => 'application/json',
					'temperature'      => 0.7,
				),
			);

			$args = array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 35,
			);

			$response = wp_remote_post( $endpoint, $args );

			if ( is_wp_error( $response ) ) {
				$last_error = $response;
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			if ( 200 !== $code ) {
				$json_err   = json_decode( $body, true );
				$err_msg    = isset( $json_err['error']['message'] ) ? $json_err['error']['message'] : sprintf( __( 'Error HTTP de Gemini (%d)', 'wp-autocontent' ), $code );
				$last_error = new WP_Error( 'gemini_api_error', $err_msg );
				continue;
			}

			$data = json_decode( $body, true );
			if ( empty( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
				$last_error = new WP_Error( 'gemini_empty_response', __( 'Gemini no devolvió un resultado válido.', 'wp-autocontent' ) );
				continue;
			}

			$raw_text = trim( $data['candidates'][0]['content']['parts'][0]['text'] );

			if ( preg_match( '/```(?:json)?\s*(\{.*?\})\s*```/s', $raw_text, $matches ) ) {
				$raw_text = $matches[1];
			} elseif ( preg_match( '/\{.*\}/s', $raw_text, $matches ) ) {
				$raw_text = $matches[0];
			}

			$content = json_decode( $raw_text, true );

			if ( ! is_array( $content ) || ! isset( $content['headings'] ) || ! isset( $content['paragraphs'] ) ) {
				$last_error = new WP_Error( 'gemini_invalid_json', __( 'El formato JSON devuelto por Gemini no coincide con el esquema requerido.', 'wp-autocontent' ) );
				continue;
			}

			$clean_headings = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) $content['headings'] ) ) ) );
			$clean_paras    = array_values( array_unique( array_filter( array_map( 'sanitize_textarea_field', (array) $content['paragraphs'] ) ) ) );

			return array(
				'headings'   => $clean_headings,
				'paragraphs' => $clean_paras,
			);
		}

		return is_wp_error( $last_error ) ? $last_error : new WP_Error( 'gemini_failed', __( 'No se pudo conectar con la API de Gemini.', 'wp-autocontent' ) );
	}

	/**
	 * Genera un artículo de blog completo optimizado para SEO basado en una temática.
	 *
	 * @param string $topic Temática o prompt del artículo.
	 * @param string $tone Tono de comunicación.
	 * @return array|WP_Error Array con 'title', 'excerpt', 'keyword_for_images', y 'sections' o WP_Error.
	 */
	public function generate_blog_article( string $topic, string $tone = 'Profesional y cercano' ) {
		if ( empty( $this->api_key ) ) {
			return new WP_Error( 'missing_api_key', __( 'No se ha configurado la API Key de Google Gemini.', 'wp-autocontent' ) );
		}

		$models_to_try = $this->discover_active_models();

		$prompt = sprintf(
			'Actúa como un redactor SEO y copywriter profesional en ESPAÑOL. Escribe un artículo de blog exhaustivo, interesante y optimizado sobre el tema: "%s" con un tono "%s". ' .
			'El artículo debe constar de 3 a 5 secciones bien desarrolladas. ' .
			'Debes devolver ÚNICAMENTE un objeto JSON estricto con el siguiente esquema exacto: ' .
			'{"title": "Título SEO atrayente", "excerpt": "Extracto/Resumen corto del post de 140-160 caracteres", "keyword_for_images": "palabra clave en ingles para buscar foto principal", "sections": [{"heading": "Subtítulo H2 de la sección", "content": "Texto amplio y detallado de la sección...", "image_keyword": "palabra clave en ingles para foto de esta seccion"}]}',
			sanitize_text_field( $topic ),
			sanitize_text_field( $tone )
		);

		$last_error = null;

		foreach ( $models_to_try as $model_path ) {
			$base_url = 'https://generativelanguage.googleapis.com/v1beta/' . ltrim( $model_path, '/' ) . ':generateContent';
			$endpoint = add_query_arg( 'key', $this->api_key, $base_url );

			$payload = array(
				'contents'         => array(
					array(
						'parts' => array(
							array( 'text' => $prompt ),
						),
					),
				),
				'generationConfig' => array(
					'responseMimeType' => 'application/json',
					'temperature'      => 0.7,
				),
			);

			$args = array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 35,
			);

			$response = wp_remote_post( $endpoint, $args );

			if ( is_wp_error( $response ) ) {
				$last_error = $response;
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			if ( 200 !== $code ) {
				$json_err   = json_decode( $body, true );
				$err_msg    = isset( $json_err['error']['message'] ) ? $json_err['error']['message'] : sprintf( __( 'Error HTTP de Gemini (%d)', 'wp-autocontent' ), $code );
				$last_error = new WP_Error( 'gemini_api_error', $err_msg );
				continue;
			}

			$data = json_decode( $body, true );
			if ( empty( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
				$last_error = new WP_Error( 'gemini_empty_response', __( 'Gemini no devolvió un resultado válido.', 'wp-autocontent' ) );
				continue;
			}

			$raw_text = trim( $data['candidates'][0]['content']['parts'][0]['text'] );

			if ( preg_match( '/```(?:json)?\s*(\{.*?\})\s*```/s', $raw_text, $matches ) ) {
				$raw_text = $matches[1];
			} elseif ( preg_match( '/\{.*\}/s', $raw_text, $matches ) ) {
				$raw_text = $matches[0];
			}

			$article = json_decode( $raw_text, true );

			if ( ! is_array( $article ) || empty( $article['title'] ) || empty( $article['sections'] ) ) {
				$last_error = new WP_Error( 'gemini_invalid_json', __( 'El formato JSON devuelto por Gemini no coincide con el esquema del artículo de blog.', 'wp-autocontent' ) );
				continue;
			}

			return array(
				'title'              => sanitize_text_field( $article['title'] ),
				'excerpt'            => isset( $article['excerpt'] ) ? sanitize_text_field( $article['excerpt'] ) : '',
				'keyword_for_images' => isset( $article['keyword_for_images'] ) ? sanitize_text_field( $article['keyword_for_images'] ) : 'blog',
				'sections'           => (array) $article['sections'],
			);
		}

		return is_wp_error( $last_error ) ? $last_error : new WP_Error( 'gemini_failed', __( 'No se pudo generar el artículo de blog con Gemini.', 'wp-autocontent' ) );
	}
}
