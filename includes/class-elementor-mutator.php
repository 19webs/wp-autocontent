<?php
/**
 * Parser y manipulador de la estructura de datos serializada _elementor_data.
 * Con ajuste inteligente de longitud de caracteres (Length-Aware Content Fitting).
 *
 * @package WP_Autocontent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Autocontent_Elementor_Mutator {

	/**
	 * Pool de titulares.
	 *
	 * @var array
	 */
	private array $headings_pool = array();

	/**
	 * Pool de párrafos.
	 *
	 * @var array
	 */
	private array $paragraphs_pool = array();

	/**
	 * Pool de imágenes.
	 *
	 * @var array
	 */
	private array $images_pool = array();

	/**
	 * Pool de iconos PNG.
	 *
	 * @var array
	 */
	private array $icons_pool = array();

	/**
	 * Datos de contacto (teléfonos, emails, dirección y logotipos de clientes).
	 *
	 * @var array
	 */
	private array $contact_info = array(
		'phones'  => array(),
		'emails'  => array(),
		'address' => '',
		'logos'   => array(),
	);

	/**
	 * Bandera para crear automáticamente nuevas secciones en Elementor si sobra texto extraído.
	 *
	 * @var bool
	 */
	private bool $auto_create_sections = false;

	/**
	 * Registro de elementos usados para evitar repeticiones en la misma página.
	 *
	 * @var array
	 */
	private array $used_headings = array();

	/**
	 * Índices de rotación de pools.
	 *
	 * @var int
	 */
	private int $heading_idx   = 0;
	private int $paragraph_idx = 0;
	private int $image_idx     = 0;
	private int $icon_idx      = 0;

	/**
	 * Contador de estadísticas.
	 *
	 * @var array
	 */
	private array $stats = array(
		'headings_replaced'   => 0,
		'paragraphs_replaced' => 0,
		'images_replaced'     => 0,
		'icons_replaced'      => 0,
		'sections_created'    => 0,
	);

	/**
	 * Establece si se deben crear secciones dinámicas si sobra contenido extraído.
	 *
	 * @param bool $enable
	 */
	public function set_auto_create_sections( bool $enable ): void {
		$this->auto_create_sections = $enable;
	}

	/**
	 * Establece los datos de contacto y logotipos de clientes extraídos.
	 *
	 * @param array $contact Datos de contacto y marcas.
	 */
	public function set_contact_info( array $contact ): void {
		if ( isset( $contact['phones'] ) && is_array( $contact['phones'] ) ) {
			$this->contact_info['phones'] = $contact['phones'];
		}
		if ( isset( $contact['emails'] ) && is_array( $contact['emails'] ) ) {
			$this->contact_info['emails'] = $contact['emails'];
		}
		if ( isset( $contact['address'] ) && is_string( $contact['address'] ) ) {
			$this->contact_info['address'] = $contact['address'];
		}
		if ( isset( $contact['logos'] ) && is_array( $contact['logos'] ) ) {
			$this->contact_info['logos'] = $contact['logos'];
		}
	}

	/**
	 * Constructor.
	 *
	 * @param array $headings Pool de titulares.
	 * @param array $paragraphs Pool de párrafos.
	 * @param array $images Pool de imágenes.
	 * @param array $icons Pool de iconos PNG.
	 */
	public function __construct( array $headings = array(), array $paragraphs = array(), array $images = array(), array $icons = array() ) {
		$this->headings_pool   = array_values( array_unique( array_filter( $headings ) ) );
		$this->paragraphs_pool = array_values( array_unique( array_filter( $paragraphs ) ) );
		$this->images_pool     = array_values( $images );
		$this->icons_pool      = array_values( $icons );
	}

	/**
	 * Pre-escanea una lista de IDs de página para contar el número exacto de ranuras de texto e imágenes requeridas.
	 *
	 * @param array $page_ids Lista de IDs de páginas seleccionadas.
	 * @return array Array con ['headings' => int, 'paragraphs' => int, 'images' => int].
	 */
	public static function count_pages_slots( array $page_ids ): array {
		$counts = array(
			'headings'   => 0,
			'paragraphs' => 0,
			'images'     => 0,
		);

		foreach ( $page_ids as $page_id ) {
			$raw_data = get_post_meta( (int) $page_id, '_elementor_data', true );
			if ( empty( $raw_data ) ) {
				continue;
			}

			$elements = is_array( $raw_data ) ? $raw_data : json_decode( $raw_data, true );
			if ( ! is_array( $elements ) ) {
				continue;
			}

			self::recursive_count_slots( $elements, $counts );
		}

		return $counts;
	}

	/**
	 * Recorrido recursivo para contar ranuras en la estructura de Elementor.
	 *
	 * @param array $elements Array de elementos.
	 * @param array $counts Referencia del contador acumulado.
	 */
	private static function recursive_count_slots( array $elements, array &$counts ): void {
		$ignored_widgets = array( 'posts', 'portfolio', 'archive-posts', 'loop-grid', 'taxonomy-grid', 'post-title', 'post-excerpt', 'post-content', 'author-box' );

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$el_type     = isset( $element['elType'] ) ? $element['elType'] : '';
			$widget_type = isset( $element['widgetType'] ) ? $element['widgetType'] : '';
			$settings    = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();

			if ( in_array( $widget_type, $ignored_widgets, true ) ) {
				continue;
			}

			if ( 'widget' === $el_type ) {
				if ( 'heading' === $widget_type || 'button' === $widget_type || 'counter' === $widget_type ) {
					$counts['headings']++;
				} elseif ( 'text-editor' === $widget_type ) {
					$counts['paragraphs']++;
				} elseif ( 'image-box' === $widget_type || 'icon-box' === $widget_type ) {
					$counts['headings']++;
					$counts['paragraphs']++;
					if ( 'image-box' === $widget_type ) {
						$counts['images']++;
					}
				} elseif ( 'image' === $widget_type ) {
					$counts['images']++;
				} elseif ( 'call-to-action' === $widget_type ) {
					$counts['headings']++;
					$counts['paragraphs']++;
					$counts['images']++;
				} elseif ( 'icon-list' === $widget_type && ! empty( $settings['icon_list'] ) && is_array( $settings['icon_list'] ) ) {
					$counts['headings'] += count( $settings['icon_list'] );
				} elseif ( ( 'accordion' === $widget_type || 'toggle' === $widget_type || 'tabs' === $widget_type ) && ! empty( $settings['tabs'] ) && is_array( $settings['tabs'] ) ) {
					$counts['headings']   += count( $settings['tabs'] );
					$counts['paragraphs'] += count( $settings['tabs'] );
				} elseif ( 'testimonial' === $widget_type ) {
					$counts['headings']++;
					$counts['paragraphs']++;
					$counts['images']++;
				}
			}

			if ( ! empty( $settings['background_image']['url'] ) || ! empty( $settings['background_image']['id'] ) ) {
				$counts['images']++;
			}
			if ( ! empty( $settings['background_overlay_image']['url'] ) || ! empty( $settings['background_overlay_image']['id'] ) ) {
				$counts['images']++;
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::recursive_count_slots( $element['elements'], $counts );
			}
		}
	}

	/**
	 * Procesa y muta el metadato _elementor_data de una página dada.
	 *
	 * @param int $page_id ID de la página en WordPress.
	 * @return array|WP_Error Array con estadísticas de cambios o WP_Error si falla.
	 */
	public function mutate_page( int $page_id ) {
		$page_id = (int) $page_id;
		if ( ! $page_id || 'page' !== get_post_type( $page_id ) ) {
			return new WP_Error( 'invalid_page_id', sprintf( __( 'El ID %d no corresponde a una página válida.', 'wp-autocontent' ), $page_id ) );
		}

		$raw_data = get_post_meta( $page_id, '_elementor_data', true );
		if ( empty( $raw_data ) ) {
			return new WP_Error( 'no_elementor_data', sprintf( __( 'La página ID %d no contiene metadatos _elementor_data.', 'wp-autocontent' ), $page_id ) );
		}

		$elements = is_array( $raw_data ) ? $raw_data : json_decode( $raw_data, true );

		if ( ! is_array( $elements ) ) {
			return new WP_Error( 'json_parse_error', sprintf( __( 'No se pudieron decodificar los datos de Elementor para la página ID %d.', 'wp-autocontent' ), $page_id ) );
		}

		$this->stats = array(
			'headings_replaced'   => 0,
			'paragraphs_replaced' => 0,
			'images_replaced'     => 0,
			'icons_replaced'      => 0,
			'sections_created'    => 0,
		);

		$this->used_headings = array();

		$mutated_elements = $this->traverse_elements( $elements );

		// Si el usuario activó la casilla opcional y aún queda texto sobrante en el pool de párrafos
		if ( $this->auto_create_sections && ! empty( $this->paragraphs_pool ) && $this->paragraph_idx < count( $this->paragraphs_pool ) ) {
			$total_paras = count( $this->paragraphs_pool );

			// Agrupar TODOS los párrafos sobrantes en un único bloque de contenido fluido para evitar duplicar secciones
			$combined_paras_html = '';
			while ( $this->paragraph_idx < $total_paras ) {
				$combined_paras_html .= '<p>' . esc_html( $this->paragraphs_pool[ $this->paragraph_idx ] ) . '</p>';
				$this->paragraph_idx++;
			}

			$heading_text = ! empty( $this->headings_pool ) ? $this->get_fitting_heading( 'Sobre Nosotros' ) : 'Más Información';
			$img          = $this->get_next_image();

			$new_section = array(
				'id'       => 'wpac_sec_' . wp_generate_password( 8, false ),
				'elType'   => 'container',
				'isInner'  => false,
				'settings' => array(
					'content_width'  => 'boxed',
					'flex_direction' => 'row',
					'padding'        => array(
						'unit'     => 'px',
						'top'      => '60',
						'right'    => '30',
						'bottom'   => '60',
						'left'     => '30',
						'isLinked' => false,
					),
				),
				'elements' => array(
					// Columna Izquierda (Todo el texto unificado)
					array(
						'id'       => 'wpac_col_l_' . wp_generate_password( 8, false ),
						'elType'   => 'container',
						'isInner'  => true,
						'settings' => array(
							'width' => array(
								'unit' => '%',
								'size' => '50',
							),
						),
						'elements' => array(
							array(
								'id'         => 'wpac_w_h_' . wp_generate_password( 8, false ),
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => array( 'title' => esc_html( $heading_text ) ),
								'elements'   => array(),
							),
							array(
								'id'         => 'wpac_w_t_' . wp_generate_password( 8, false ),
								'elType'     => 'widget',
								'widgetType' => 'text-editor',
								'settings'   => array( 'editor' => $combined_paras_html ),
								'elements'   => array(),
							),
						),
					),
					// Columna Derecha (Imagen Destacada)
					array(
						'id'       => 'wpac_col_r_' . wp_generate_password( 8, false ),
						'elType'   => 'container',
						'isInner'  => true,
						'settings' => array(
							'width' => array(
								'unit' => '%',
								'size' => '50',
							),
						),
						'elements' => array(
							array(
								'id'         => 'wpac_w_i_' . wp_generate_password( 8, false ),
								'elType'     => 'widget',
								'widgetType' => 'image',
								'settings'   => array(
									'image' => array(
										'id'  => (int) $img['id'],
										'url' => esc_url_raw( $img['url'] ),
									),
								),
								'elements'   => array(),
							),
						),
					),
				),
			);

			$mutated_elements[] = $new_section;
			$this->stats['sections_created']++;
		}

		update_post_meta( $page_id, '_elementor_data', wp_slash( wp_json_encode( $mutated_elements ) ) );

		$this->clear_elementor_cache( $page_id );

		return array(
			'success' => true,
			'page_id' => $page_id,
			'stats'   => $this->stats,
		);
	}

	/**
	 * Recorre el árbol de elementos recursivamente.
	 *
	 * @param array $elements Array de elementos de Elementor.
	 * @return array Árbol de elementos mutado.
	 */
	private function traverse_elements( array $elements ): array {
		$ignored_widgets = array( 'posts', 'portfolio', 'archive-posts', 'loop-grid', 'taxonomy-grid', 'post-title', 'post-excerpt', 'post-content', 'author-box' );

		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$el_type     = isset( $element['elType'] ) ? $element['elType'] : '';
			$widget_type = isset( $element['widgetType'] ) ? $element['widgetType'] : '';

			if ( in_array( $widget_type, $ignored_widgets, true ) ) {
				continue;
			}

			if ( ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) ) {
				$element['settings'] = array();
			}

			// 1. Imágenes de fondo de Secciones, Contenedores Flexbox o Columnas (Heros, Banners)
			if ( ! empty( $this->images_pool ) ) {
				if ( isset( $element['settings']['background_image'] ) && is_array( $element['settings']['background_image'] ) && ! empty( $element['settings']['background_image']['url'] ) ) {
					$next_img                                 = $this->get_next_image();
					$element['settings']['background_image'] = array(
						'id'  => (int) $next_img['id'],
						'url' => esc_url_raw( $next_img['url'] ),
					);
					$this->stats['images_replaced']++;
				}

				if ( isset( $element['settings']['background_overlay_image'] ) && is_array( $element['settings']['background_overlay_image'] ) && ! empty( $element['settings']['background_overlay_image']['url'] ) ) {
					$next_img                                         = $this->get_next_image();
					$element['settings']['background_overlay_image'] = array(
						'id'  => (int) $next_img['id'],
						'url' => esc_url_raw( $next_img['url'] ),
					);
					$this->stats['images_replaced']++;
				}
			}

			// 2. Reemplazar Widgets
			if ( 'widget' === $el_type && ! empty( $widget_type ) ) {
				$element = $this->mutate_widget( $element, $widget_type );
			}

			// 3. Recorrer hijos en 'elements'
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = $this->traverse_elements( $element['elements'] );
			}
		}

		return $elements;
	}

	/**
	 * Aplica la sustitución con adaptación inteligente a la longitud de caracteres original (Length-Aware Content Fitting).
	 *
	 * @param array $element Estructura del widget.
	 * @param string $widget_type Tipo de widget.
	 * @return array Widget mutado.
	 */
	private function mutate_widget( array $element, string $widget_type ): array {
		// 1. Widget de Título ('heading')
		if ( 'heading' === $widget_type && ! empty( $this->headings_pool ) ) {
			$orig                         = isset( $element['settings']['title'] ) ? $element['settings']['title'] : '';
			$element['settings']['title'] = $this->get_fitting_heading( $orig );
			$this->stats['headings_replaced']++;
		}

		// 2. Widget de Editor de Texto ('text-editor')
		if ( 'text-editor' === $widget_type && ! empty( $this->paragraphs_pool ) ) {
			$orig                          = isset( $element['settings']['editor'] ) ? $element['settings']['editor'] : '';
			$element['settings']['editor'] = '<p>' . esc_html( $this->get_fitting_paragraph( $orig ) ) . '</p>';
			$this->stats['paragraphs_replaced']++;
		}

		// 3. Widget de Imagen ('image')
		if ( 'image' === $widget_type && ! empty( $this->images_pool ) ) {
			$next_img                     = $this->get_next_image();
			$element['settings']['image'] = array(
				'id'  => (int) $next_img['id'],
				'url' => esc_url_raw( $next_img['url'] ),
			);
			$this->stats['images_replaced']++;
		}

		// 4. Widget de Caja de Imagen ('image-box')
		if ( 'image-box' === $widget_type ) {
			if ( ! empty( $this->headings_pool ) ) {
				$orig                              = isset( $element['settings']['title_text'] ) ? $element['settings']['title_text'] : '';
				$element['settings']['title_text'] = $this->get_fitting_heading( $orig );
				$this->stats['headings_replaced']++;
			}
			if ( ! empty( $this->paragraphs_pool ) ) {
				$orig                                    = isset( $element['settings']['description_text'] ) ? $element['settings']['description_text'] : '';
				$element['settings']['description_text'] = $this->get_fitting_paragraph( $orig );
				$this->stats['paragraphs_replaced']++;
			}
			if ( ! empty( $this->icons_pool ) ) {
				$next_icon                    = $this->get_next_icon();
				$element['settings']['image'] = array(
					'id'  => (int) $next_icon['id'],
					'url' => esc_url_raw( $next_icon['url'] ),
				);
				$this->stats['icons_replaced']++;
			} elseif ( ! empty( $this->images_pool ) ) {
				$next_img                     = $this->get_next_image();
				$element['settings']['image'] = array(
					'id'  => (int) $next_img['id'],
					'url' => esc_url_raw( $next_img['url'] ),
				);
				$this->stats['images_replaced']++;
			}
		}

		// 5. Widget de Caja de Icono ('icon-box')
		if ( 'icon-box' === $widget_type ) {
			if ( ! empty( $this->headings_pool ) ) {
				$orig                              = isset( $element['settings']['title_text'] ) ? $element['settings']['title_text'] : '';
				$element['settings']['title_text'] = $this->get_fitting_heading( $orig );
				$this->stats['headings_replaced']++;
			}
			if ( ! empty( $this->paragraphs_pool ) ) {
				$orig                                    = isset( $element['settings']['description_text'] ) ? $element['settings']['description_text'] : '';
				$element['settings']['description_text'] = $this->get_fitting_paragraph( $orig );
				$this->stats['paragraphs_replaced']++;
			}
			if ( ! empty( $this->icons_pool ) ) {
				$next_icon                    = $this->get_next_icon();
				$element['settings']['image'] = array(
					'id'  => (int) $next_icon['id'],
					'url' => esc_url_raw( $next_icon['url'] ),
				);
				$element['settings']['selected_icon'] = array();
				$this->stats['icons_replaced']++;
			}
		}

		// 6. Widget de Lista de Iconos ('icon-list')
		if ( 'icon-list' === $widget_type && ! empty( $element['settings']['icon_list'] ) && is_array( $element['settings']['icon_list'] ) ) {
			if ( ! empty( $this->headings_pool ) ) {
				foreach ( $element['settings']['icon_list'] as &$item ) {
					if ( is_array( $item ) && isset( $item['text'] ) ) {
						$item['text'] = $this->get_fitting_heading( $item['text'] );
						$this->stats['headings_replaced']++;
					}
				}
			}
		}

		// 7. Widget de Contador / Estadística ('counter')
		if ( 'counter' === $widget_type ) {
			if ( ! empty( $this->headings_pool ) && isset( $element['settings']['title'] ) ) {
				$element['settings']['title'] = $this->get_fitting_heading( $element['settings']['title'] );
				$this->stats['headings_replaced']++;
			}
		}

		// 8. Widget de Botón ('button')
		if ( 'button' === $widget_type && ! empty( $this->headings_pool ) ) {
			$button_cta                  = array( 'Saber más', 'Ver detalles', 'Solicitar información', 'Contactar ahora', 'Ver servicios', 'Conocer más', 'Empezar ahora' );
			$element['settings']['text'] = $button_cta[ array_rand( $button_cta ) ];
			$this->stats['headings_replaced']++;
		}

		// 9. Widget de Llamada a la Acción ('call-to-action')
		if ( 'call-to-action' === $widget_type ) {
			if ( ! empty( $this->headings_pool ) ) {
				$orig                         = isset( $element['settings']['title'] ) ? $element['settings']['title'] : '';
				$element['settings']['title'] = $this->get_fitting_heading( $orig );
				$this->stats['headings_replaced']++;
			}
			if ( ! empty( $this->paragraphs_pool ) ) {
				$orig                               = isset( $element['settings']['description'] ) ? $element['settings']['description'] : '';
				$element['settings']['description'] = $this->get_fitting_paragraph( $orig );
				$this->stats['paragraphs_replaced']++;
			}
			if ( ! empty( $this->images_pool ) ) {
				$next_img                        = $this->get_next_image();
				$element['settings']['bg_image'] = array(
					'id'  => (int) $next_img['id'],
					'url' => esc_url_raw( $next_img['url'] ),
				);
				$this->stats['images_replaced']++;
			}
		}

		// 10. Acordeones, Toggles y Pestañas ('accordion', 'toggle', 'tabs')
		if ( ( 'accordion' === $widget_type || 'toggle' === $widget_type || 'tabs' === $widget_type ) && ! empty( $element['settings']['tabs'] ) && is_array( $element['settings']['tabs'] ) ) {
			foreach ( $element['settings']['tabs'] as &$tab ) {
				if ( is_array( $tab ) ) {
					if ( ! empty( $this->headings_pool ) && isset( $tab['tab_title'] ) ) {
						$tab['tab_title'] = $this->get_fitting_heading( $tab['tab_title'] );
						$this->stats['headings_replaced']++;
					}
					if ( ! empty( $this->paragraphs_pool ) && isset( $tab['tab_content'] ) ) {
						$tab['tab_content'] = $this->get_fitting_paragraph( $tab['tab_content'] );
						$this->stats['paragraphs_replaced']++;
					}
				}
			}
		}

		// 11. Testimonios ('testimonial')
		if ( 'testimonial' === $widget_type ) {
			if ( ! empty( $this->paragraphs_pool ) && isset( $element['settings']['testimonial_content'] ) ) {
				$element['settings']['testimonial_content'] = $this->get_fitting_paragraph( $element['settings']['testimonial_content'] );
				$this->stats['paragraphs_replaced']++;
			}
			if ( ! empty( $this->headings_pool ) && isset( $element['settings']['testimonial_name'] ) ) {
				$element['settings']['testimonial_name'] = $this->get_fitting_heading( $element['settings']['testimonial_name'] );
				$this->stats['headings_replaced']++;
			}
			if ( ! empty( $this->images_pool ) ) {
				$next_img                                 = $this->get_next_image();
				$element['settings']['testimonial_image'] = array(
					'id'  => (int) $next_img['id'],
					'url' => esc_url_raw( $next_img['url'] ),
				);
				$this->stats['images_replaced']++;
			}
		}

		// 12. Widget de Mapas de Google ('google_maps', 'ea-google-map', 'map', 'google-map')
		if ( in_array( $widget_type, array( 'google_maps', 'ea-google-map', 'google-map', 'map' ), true ) && ! empty( $this->contact_info['address'] ) ) {
			$element['settings']['address'] = esc_html( $this->contact_info['address'] );
			$this->stats['headings_replaced']++;
		}

		// 13. Widget de Carrusel / Galería de Logotipos de Clientes ('image-carousel', 'media-carousel', 'logo-grid', 'gallery', 'ea-logo-carousel')
		if ( in_array( $widget_type, array( 'image-carousel', 'media-carousel', 'logo-grid', 'gallery', 'ea-logo-carousel' ), true ) && ! empty( $this->contact_info['logos'] ) ) {
			$logo_gallery = array();
			foreach ( $this->contact_info['logos'] as $logo_img ) {
				if ( is_array( $logo_img ) && isset( $logo_img['id'] ) ) {
					$logo_gallery[] = array(
						'id'  => (int) $logo_img['id'],
						'url' => esc_url_raw( $logo_img['url'] ),
					);
				}
			}
			if ( ! empty( $logo_gallery ) ) {
				$element['settings']['wp_gallery'] = $logo_gallery;
				$element['settings']['carousel']   = $logo_gallery;
				$this->stats['images_replaced'] += count( $logo_gallery );
			}
		}

		// 14. Adaptación Inteligente de Datos de Contacto en Lista de Iconos ('icon-list')
		if ( 'icon-list' === $widget_type && ! empty( $element['settings']['icon_list'] ) && is_array( $element['settings']['icon_list'] ) ) {
			foreach ( $element['settings']['icon_list'] as &$item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$icon_val = isset( $item['selected_icon']['value'] ) ? strtolower( $item['selected_icon']['value'] ) : '';
				$orig_url = isset( $item['link']['url'] ) ? strtolower( $item['link']['url'] ) : '';

				// Teléfono / Móvil
				if ( ( strpos( $icon_val, 'phone' ) !== false || strpos( $icon_val, 'mobile' ) !== false || strpos( $icon_val, 'whatsapp' ) !== false || strpos( $orig_url, 'tel:' ) !== false ) && ! empty( $this->contact_info['phones'] ) ) {
					$phone_val           = $this->contact_info['phones'][0];
					$item['text']        = esc_html( $phone_val );
					$item['link']['url'] = 'tel:' . preg_replace( '/[^0-9\+]/', '', $phone_val );
					$this->stats['headings_replaced']++;
				}
				// Email / Correo
				elseif ( ( strpos( $icon_val, 'envelope' ) !== false || strpos( $icon_val, 'at' ) !== false || strpos( $icon_val, 'mail' ) !== false || strpos( $orig_url, 'mailto:' ) !== false ) && ! empty( $this->contact_info['emails'] ) ) {
					$email_val           = $this->contact_info['emails'][0];
					$item['text']        = esc_html( $email_val );
					$item['link']['url'] = 'mailto:' . esc_attr( $email_val );
					$this->stats['headings_replaced']++;
				}
				// Dirección / Ubicación
				elseif ( ( strpos( $icon_val, 'map' ) !== false || strpos( $icon_val, 'location' ) !== false || strpos( $icon_val, 'marker' ) !== false || strpos( $icon_val, 'pin' ) !== false ) && ! empty( $this->contact_info['address'] ) ) {
					$item['text'] = esc_html( $this->contact_info['address'] );
					$this->stats['headings_replaced']++;
				}
			}
		}

		return $element;
	}

	/**
	 * Busca en el pool el titular cuya longitud en caracteres mejor se adapte al texto original (Length-Aware Content Fitting).
	 *
	 * @param string $original_text Texto original en el maquetador.
	 * @return string Titular seleccionado.
	 */
	private function get_fitting_heading( string $original_text ): string {
		if ( empty( $this->headings_pool ) ) {
			return '';
		}

		$clean_orig  = trim( strip_tags( $original_text ) );
		$target_len = mb_strlen( $clean_orig, 'UTF-8' );

		if ( 0 === $target_len ) {
			$target_len = 35;
		}

		$best_candidate = '';
		$best_diff      = 9999;
		$best_index     = 0;

		// 1. Buscar entre los titulares del pool que NO hayan sido usados en esta página
		foreach ( $this->headings_pool as $idx => $candidate ) {
			if ( in_array( $candidate, $this->used_headings, true ) ) {
				continue;
			}

			$cand_len = mb_strlen( $candidate, 'UTF-8' );
			$diff     = abs( $cand_len - $target_len );

			if ( $diff < $best_diff ) {
				$best_diff      = $diff;
				$best_candidate = $candidate;
				$best_index     = $idx;
			}
		}

		// 2. Si todos fueron usados en esta página, buscar en el pool general
		if ( empty( $best_candidate ) ) {
			foreach ( $this->headings_pool as $idx => $candidate ) {
				$cand_len = mb_strlen( $candidate, 'UTF-8' );
				$diff     = abs( $cand_len - $target_len );

				if ( $diff < $best_diff ) {
					$best_diff      = $diff;
					$best_candidate = $candidate;
					$best_index     = $idx;
				}
			}
		}

		if ( empty( $best_candidate ) ) {
			$best_candidate = $this->headings_pool[ $this->heading_idx % count( $this->headings_pool ) ];
		}

		$this->used_headings[] = $best_candidate;
		$this->heading_idx++;

		return $best_candidate;
	}

	/**
	 * Ajusta inteligentemente un párrafo del pool para que la cantidad de caracteres esté cercana a la original.
	 *
	 * @param string $original_text Texto original en el maquetador.
	 * @return string Párrafo ajustado.
	 */
	private function get_fitting_paragraph( string $original_text ): string {
		if ( empty( $this->paragraphs_pool ) ) {
			return '';
		}

		$clean_orig = trim( strip_tags( $original_text ) );
		$target_len = mb_strlen( $clean_orig, 'UTF-8' );

		if ( $target_len < 30 ) {
			$target_len = 120;
		}

		// Seleccionar párrafo base
		$base_para = $this->paragraphs_pool[ $this->paragraph_idx % count( $this->paragraphs_pool ) ];
		$this->paragraph_idx++;

		$para_len = mb_strlen( $base_para, 'UTF-8' );

		// Si el párrafo base es sustancialmente más largo (+35%) que el original, lo recortamos limpiamente por frase o palabra
		if ( $para_len > ( $target_len * 1.35 ) && $para_len > 60 ) {
			$max_len = (int) ( $target_len * 1.25 );
			
			// Intentar recortar en el punto final de frase más cercano
			if ( preg_match( '/^(.{30,' . $max_len . '}\.)\s/us', $base_para, $matches ) ) {
				return $matches[1];
			}

			// O recortar limpiamente en un espacio entre palabras
			$trimmed = mb_substr( $base_para, 0, $max_len, 'UTF-8' );
			$last_space = mb_strrpos( $trimmed, ' ', 0, 'UTF-8' );
			if ( $last_space !== false && $last_space > 30 ) {
				$trimmed = mb_substr( $trimmed, 0, $last_space, 'UTF-8' );
			}
			return rtrim( $trimmed, ',;:' ) . '.';
		}

		return $base_para;
	}

	/**
	 * Obtiene la siguiente imagen del pool (con ciclo cíclico).
	 *
	 * @return array Array con ['id' => int, 'url' => string].
	 */
	private function get_next_image(): array {
		$total = count( $this->images_pool );
		if ( 0 === $total ) {
			return array( 'id' => 0, 'url' => '' );
		}
		$img             = $this->images_pool[ $this->image_idx % $total ];
		$this->image_idx++;
		return $img;
	}

	/**
	 * Obtiene el siguiente icono PNG del pool (con ciclo cíclico).
	 *
	 * @return array Array con ['id' => int, 'url' => string].
	 */
	private function get_next_icon(): array {
		$total = count( $this->icons_pool );
		if ( 0 === $total ) {
			return array( 'id' => 0, 'url' => '' );
		}
		$icon            = $this->icons_pool[ $this->icon_idx % $total ];
		$this->icon_idx++;
		return $icon;
	}

	/**
	 * Vacía la caché CSS y fuerza la regeneración de archivos en Elementor.
	 *
	 * @param int $page_id ID de la página.
	 */
	private function clear_elementor_cache( int $page_id ): void {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return;
		}

		try {
			if ( isset( \Elementor\Plugin::$instance->files_manager ) ) {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			}

			if ( isset( \Elementor\Plugin::$instance->posts_css_manager ) ) {
				$post_css = \Elementor\Plugin::$instance->posts_css_manager->get( $page_id );
				if ( $post_css ) {
					$post_css->update();
				}
			}
		} catch ( Exception $e ) {
			// Silenciar excepciones en limpieza de caché
		}
	}
}
