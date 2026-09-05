<?php
/**
 * Panel de Administración y Control AJAX para WP autocontent.
 *
 * @package WP_Autocontent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Autocontent_Admin_Settings {

	/**
	 * Identificador del hook de la página de administración.
	 *
	 * @var string
	 */
	private string $page_hook = '';

	/**
	 * Constructor. Registra hooks de administración y peticiones AJAX.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// Endpoints AJAX
		add_action( 'wp_ajax_wp_autocontent_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_wp_autocontent_test_api_keys', array( $this, 'ajax_test_api_keys' ) );
		add_action( 'wp_ajax_wp_autocontent_test_single_key', array( $this, 'ajax_test_single_key' ) );
		add_action( 'wp_ajax_wp_autocontent_prepare_pool', array( $this, 'ajax_prepare_pool' ) );
		add_action( 'wp_ajax_wp_autocontent_process_page', array( $this, 'ajax_process_page' ) );
	}

	/**
	 * Añade las páginas y submenús al menú principal de administración de WordPress.
	 */
	public function register_admin_menu(): void {
		$this->page_hook = add_menu_page(
			__( 'WP autocontent', 'wp-autocontent' ),
			__( 'WP autocontent', 'wp-autocontent' ),
			'manage_options',
			'wp-autocontent',
			array( $this, 'render_admin_page' ),
			'dashicons-superhero',
			30
		);

		add_submenu_page(
			'wp-autocontent',
			__( 'Origen y Páginas', 'wp-autocontent' ),
			__( 'Origen y Páginas', 'wp-autocontent' ),
			'manage_options',
			'wp-autocontent',
			array( $this, 'render_admin_page' )
		);

		add_submenu_page(
			'wp-autocontent',
			__( 'Configuración API', 'wp-autocontent' ),
			__( 'Configuración API', 'wp-autocontent' ),
			'manage_options',
			'wp-autocontent-settings',
			array( $this, 'render_api_settings_page' )
		);
	}

	/**
	 * Carga estilos CSS y scripts JS solo en la página del plugin.
	 *
	 * @param string $hook Identificador del hook de la página actual.
	 */
	public function enqueue_admin_assets( string $hook ): void {
		if ( strpos( $hook, 'wp-autocontent' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'wp-autocontent-admin-css',
			WP_AUTOCONTENT_URL . 'assets/css/admin.css',
			array(),
			WP_AUTOCONTENT_VERSION
		);

		wp_enqueue_script(
			'wp-autocontent-admin-runner',
			WP_AUTOCONTENT_URL . 'assets/js/admin-runner.js',
			array( 'jquery' ),
			WP_AUTOCONTENT_VERSION,
			true
		);

		wp_localize_script(
			'wp-autocontent-admin-runner',
			'WPAutocontent',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'wp_autocontent_nonce' ),
				'strings'  => array(
					'select_page'      => __( 'Por favor, selecciona al menos una página para procesar.', 'wp-autocontent' ),
					'invalid_url'      => __( 'Por favor, introduce una URL externa válida para el scraping.', 'wp-autocontent' ),
					'invalid_sector'   => __( 'Por favor, indica el sector/actividad de negocio.', 'wp-autocontent' ),
					'invalid_keyword'  => __( 'Por favor, escribe una palabra clave en inglés para las imágenes.', 'wp-autocontent' ),
					'preparing_pool'   => __( 'Analizando páginas y generando pool de contenidos (Textos, Imágenes e Iconos PNG)...', 'wp-autocontent' ),
					'processing_page'  => __( 'Procesando página %s de %s (ID: %d)...', 'wp-autocontent' ),
					'process_complete' => __( '¡Proceso completado con éxito para todas las páginas seleccionadas!', 'wp-autocontent' ),
					'error_occurred'   => __( 'Ocurrió un error en el proceso:', 'wp-autocontent' ),
				),
			)
		);
	}

	/**
	 * Renderiza la vista principal: Origen y Páginas (con pestañas interactivas).
	 */
	public function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$logo_url = WP_AUTOCONTENT_URL . 'assets/images/logo.jpg';

		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$elementor_pages = array();
		foreach ( $pages as $page ) {
			$edit_mode = get_post_meta( $page->ID, '_elementor_edit_mode', true );
			$raw_data  = get_post_meta( $page->ID, '_elementor_data', true );

			$is_elementor = ( 'builder' === $edit_mode || ! empty( $raw_data ) );

			$elementor_pages[] = array(
				'ID'           => $page->ID,
				'title'        => $page->post_title,
				'status'       => $page->post_status,
				'is_elementor' => $is_elementor,
			);
		}
		?>
		<div class="wrap wpac-wrap">
			<div class="wpac-header">
				<div class="wpac-brand">
					<img src="<?php echo esc_url( $logo_url ); ?>" alt="WP autocontent" class="wpac-logo" />
					<div>
						<h1>WP autocontent <span class="wpac-badge">v<?php echo esc_html( WP_AUTOCONTENT_VERSION ); ?></span></h1>
						<p class="wpac-subtitle">Desarrollado por <a href="https://19webs.com" target="_blank" rel="noopener">19webs</a>. Sustitución automatizada de contenido Lorem Ipsum para kits de Elementor y maquetas WordPress.</p>
					</div>
				</div>
				<div class="wpac-header-actions">
					<button type="button" id="wpac-check-update-btn" class="button wpac-update-btn">
						<span class="dashicons dashicons-update"></span> Comprobar actualizaciones
					</button>
					<div id="wpac-update-status" class="wpac-update-status"></div>
				</div>
			</div>

			<!-- Barra de Pestañas Segmentada (Solo 2 pestañas para Origen y Páginas) -->
			<nav class="wpac-tabs-nav">
				<button type="button" class="wpac-tab-btn active" data-tab="tab-content">
					<span class="dashicons dashicons-welcome-write-blog"></span> Origen del Contenido
				</button>
				<button type="button" class="wpac-tab-btn" data-tab="tab-runner">
					<span class="dashicons dashicons-admin-page"></span> Selector de Páginas y Ejecución
				</button>
			</nav>

			<div class="wpac-tabs-container">

				<!-- PESTAÑA 1: ORIGEN DEL CONTENIDO -->
				<div id="tab-content" class="wpac-tab-pane active">
					<div class="wpac-card">
						<h2><span class="dashicons dashicons-welcome-write-blog"></span> Configuración de Origen del Contenido</h2>
						
						<!-- Modo Textos -->
						<div class="wpac-section-block">
							<h3><span class="dashicons dashicons-editor-paragraph"></span> Generación / Extraer Textos</h3>
							<div class="wpac-radio-group">
								<label>
									<input type="radio" name="text_source" value="gemini" checked />
									<strong>Opción A: Generación con Google Gemini (1.5 / 3.6 Flash - Gratis)</strong>
								</label>
								<div class="wpac-subfields" id="subfields-gemini">
									<div class="wpac-field-row">
										<div>
											<label for="gemini_sector">Sector / Actividad del Negocio:</label>
											<input type="text" id="gemini_sector" name="gemini_sector" placeholder="Ej. Escuela de deportes náuticos, Clínica dental..." />
										</div>
										<div>
											<label for="gemini_tone">Tono de Comunicación:</label>
											<select id="gemini_tone" name="gemini_tone">
												<option value="Profesional y cercano">Profesional y cercano</option>
												<option value="Corporativo y formal">Corporativo y formal</option>
												<option value="Innovador y tecnológico">Innovador y tecnológico</option>
												<option value="Fresco y dinámico">Fresco y dinámico</option>
												<option value="Elegante y exclusivo">Elegante y exclusivo</option>
											</select>
										</div>
									</div>
								</div>

								<label>
									<input type="radio" name="text_source" value="scrape" />
									<strong>Opción B: Extraer (Scraping) desde URL de cliente o competidor</strong>
								</label>
								<div class="wpac-subfields wpac-hidden" id="subfields-scrape">
									<label for="scrape_url">URL de la web externa a escanear:</label>
									<input type="url" id="scrape_url" name="scrape_url" placeholder="https://ejemplo.com" />
								</div>

								<label>
									<input type="radio" name="text_source" value="none" />
									<strong>Opción C: No modificar los textos actuales</strong>
								</label>
							</div>
						</div>

						<!-- Modo Imágenes (Opción D colocada por encima del campo de palabra clave) -->
						<div class="wpac-section-block">
							<h3><span class="dashicons dashicons-format-image"></span> Generación / Extraer Imágenes</h3>
							<div class="wpac-radio-group">
								<label>
									<input type="radio" name="image_source" value="pexels" checked />
									<strong>Opción A: Búsqueda y descarga con Pexels API (Predeterminado)</strong>
								</label>
								<label>
									<input type="radio" name="image_source" value="unsplash" />
									<strong>Opción B: Búsqueda y descarga con Unsplash API</strong>
								</label>
								<label>
									<input type="radio" name="image_source" value="pixabay" />
									<strong>Opción C: Búsqueda y descarga con Pixabay API</strong>
								</label>
								<label>
									<input type="radio" name="image_source" value="scrape" />
									<strong>Opción D: Extraer imágenes de la URL de scraping ingresada arriba</strong>
								</label>

								<!-- Campo de palabra clave colocado debajo de las opciones A, B, C y D -->
								<div class="wpac-subfields" id="subfields-pexels">
									<label for="pexels_keyword">Palabra clave / temática para las imágenes (en español o inglés):</label>
									<input type="text" id="pexels_keyword" name="pexels_keyword" placeholder="Ej. escuela náutica, clínica dental, deportes de aventura..." />
									<p class="description" id="wpac-selected-api-note">💡 Puedes escribir en español. Se traducirá automáticamente al inglés para buscar fotos en <strong>Pexels API</strong> (Predeterminada).</p>
								</div>

								<label>
									<input type="radio" name="image_source" value="none" />
									<strong>Opción E: No modificar las imágenes actuales</strong>
								</label>
							</div>
						</div>

						<!-- Modo Iconos PNG (Por defecto Opción C: No modificar) -->
						<div class="wpac-section-block">
							<h3><span class="dashicons dashicons-category"></span> Iconos PNG Transparentes para Cajas de Servicios</h3>
							<div class="wpac-radio-group">
								<label>
									<input type="radio" name="icon_source" value="iconify" />
									<strong>Opción A: Inyectar Iconos PNG automáticos con Iconify API (100% Gratis - Sin clave)</strong>
								</label>
								<label>
									<input type="radio" name="icon_source" value="iconfinder" />
									<strong>Opción B: Descargar Iconos PNG con Iconfinder API (Requiere plan pago)</strong>
								</label>
								<label>
									<input type="radio" name="icon_source" value="none" checked />
									<strong>Opción C: No modificar los iconos actuales (Predeterminado)</strong>
								</label>
							</div>
						</div>

						<!-- Opciones Avanzadas de Maquetación -->
						<div class="wpac-section-block">
							<h3><span class="dashicons dashicons-layout"></span> Ajustes Avanzados de Maquetación</h3>
							<label class="wpac-checkbox-option">
								<input type="checkbox" id="auto_create_sections" name="auto_create_sections" value="1" />
								<strong>Crear automáticamente nuevas secciones en Elementor (Texto a la izquierda + Foto a la derecha) si sobra texto extraído.</strong>
							</label>
							<p class="description">Desactivado por defecto. Si se activa y la web del cliente tiene más párrafos de los que caben en las ranuras de la plantilla, el plugin maquetará dinámicamente contenedores de 2 columnas al final de la página con el texto sobrante.</p>
						</div>
					</div>
				</div>

				<!-- PESTAÑA 2: SELECTOR DE PÁGINAS Y EJECUCIÓN -->
				<div id="tab-runner" class="wpac-tab-pane">
					<div class="wpac-card wpac-full-width">
						<h2><span class="dashicons dashicons-admin-page"></span> Selector de Páginas y Ejecución</h2>
						<p class="description">Selecciona las páginas que deseas sustituir con el contenido generado. Ninguna opción está seleccionada por defecto.</p>

						<div class="wpac-table-header-controls">
							<div class="wpac-table-actions">
								<label><input type="checkbox" id="wpac-select-all-pages" /> <strong>Seleccionar / Deseleccionar Todas</strong></label>
							</div>
							<div class="wpac-search-box">
								<span class="dashicons dashicons-search"></span>
								<input type="text" id="wpac-search-pages" placeholder="Buscar página por título o ID..." />
							</div>
						</div>

						<div class="wpac-table-container">
							<table class="wp-list-table widefat fixed striped" id="wpac-pages-table">
								<thead>
									<tr>
										<td class="manage-column column-cb check-column"><input type="checkbox" id="cb-select-all" /></td>
										<th>Título de la Página</th>
										<th>Estado WP</th>
										<th>Compatibilidad Elementor</th>
										<th>ID</th>
									</tr>
								</thead>
								<tbody>
									<?php if ( empty( $elementor_pages ) ) : ?>
										<tr><td colspan="5">No se encontraron páginas en el sitio.</td></tr>
									<?php else : ?>
										<?php foreach ( $elementor_pages as $p ) : ?>
											<tr class="wpac-page-row" data-title="<?php echo esc_attr( strtolower( $p['title'] ) ); ?>" data-id="<?php echo esc_attr( $p['ID'] ); ?>">
												<th scope="row" class="check-column">
													<input type="checkbox" name="selected_pages[]" value="<?php echo esc_attr( $p['ID'] ); ?>" class="wpac-page-checkbox" />
												</th>
												<td><strong><?php echo esc_html( $p['title'] ); ?></strong></td>
												<td><span class="wpac-status-pill status-<?php echo esc_attr( $p['status'] ); ?>"><?php echo esc_html( ucfirst( $p['status'] ) ); ?></span></td>
												<td>
													<?php if ( $p['is_elementor'] ) : ?>
														<span class="wpac-badge wpac-badge-success"><span class="dashicons dashicons-yes"></span> Elementor Activo</span>
													<?php else : ?>
														<span class="wpac-badge wpac-badge-warning">Estándar WP / Plantilla</span>
													<?php endif; ?>
												</td>
												<td><code>#<?php echo esc_html( $p['ID'] ); ?></code></td>
											</tr>
										<?php endforeach; ?>
									<?php endif; ?>
								</tbody>
							</table>
						</div>

						<!-- Control de Paginación -->
						<div class="wpac-pagination-container">
							<span id="wpac-pagination-info" class="wpac-pagination-info">Mostrando 0 páginas</span>
							<div class="wpac-pagination-buttons">
								<button type="button" class="button" id="wpac-prev-page" disabled>&laquo; Anterior</button>
								<span id="wpac-page-numbers" class="wpac-page-numbers"></span>
								<button type="button" class="button" id="wpac-next-page">Siguiente &raquo;</button>
							</div>
						</div>

						<!-- Botón de Ejecución y Barra de Progreso -->
						<div class="wpac-runner-section">
							<button type="button" id="wpac-run-process-btn" class="button button-primary button-hero">
								<span class="dashicons dashicons-controls-play"></span> Comenzar Procesamiento de Demo
							</button>

							<div id="wpac-progress-container" class="wpac-hidden">
								<div class="wpac-progress-header">
									<span id="wpac-progress-status-text">Iniciando proceso...</span>
									<span id="wpac-progress-percent">0%</span>
								</div>
								<div class="wpac-progress-bar">
									<div id="wpac-progress-fill" class="wpac-progress-fill" style="width: 0%;"></div>
								</div>
							</div>

							<div id="wpac-log-container" class="wpac-hidden">
								<h3>Consola de Registro en Tiempo Real</h3>
								<div id="wpac-log-console"></div>
							</div>
						</div>
					</div>
				</div>

			</div>

			<div class="wpac-footer">
				<p>WP autocontent v<?php echo esc_html( WP_AUTOCONTENT_VERSION ); ?> &bull; Desarrollado con ❤️ por <a href="https://19webs.com" target="_blank" rel="noopener">19webs</a></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Renderiza la vista independiente: Configuración API (Submenú de WordPress).
	 */
	public function render_api_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$gemini_key     = get_option( 'wp_autocontent_gemini_key', '' );
		$pexels_key     = get_option( 'wp_autocontent_pexels_key', '' );
		$unsplash_key   = get_option( 'wp_autocontent_unsplash_key', '' );
		$pixabay_key    = get_option( 'wp_autocontent_pixabay_key', '' );
		$iconfinder_key = get_option( 'wp_autocontent_iconfinder_key', '' );
		$logo_url       = WP_AUTOCONTENT_URL . 'assets/images/logo.jpg';
		?>
		<div class="wrap wpac-wrap">
			<div class="wpac-header">
				<div class="wpac-brand">
					<img src="<?php echo esc_url( $logo_url ); ?>" alt="WP autocontent" class="wpac-logo" />
					<div>
						<h1>WP autocontent <span class="wpac-badge">v<?php echo esc_html( WP_AUTOCONTENT_VERSION ); ?></span></h1>
						<p class="wpac-subtitle">Desarrollado por <a href="https://19webs.com" target="_blank" rel="noopener">19webs</a>. Configuración global de claves de proveedores API.</p>
					</div>
				</div>
				<div class="wpac-header-actions">
					<button type="button" id="wpac-check-update-btn" class="button wpac-update-btn">
						<span class="dashicons dashicons-update"></span> Comprobar actualizaciones
					</button>
					<div id="wpac-update-status" class="wpac-update-status"></div>
				</div>
			</div>

			<div class="wpac-card">
				<h2><span class="dashicons dashicons-admin-network"></span> Configuración de Claves API</h2>
				<form id="wpac-api-settings-form">
					<div class="wpac-field">
						<label for="gemini_api_key">Google Gemini API Key (IA Textos)</label>
						<div class="wpac-input-btn-row">
							<input type="password" id="gemini_api_key" name="gemini_api_key" value="<?php echo esc_attr( $gemini_key ); ?>" placeholder="AIzaSy..." />
							<button type="button" class="button wpac-test-single-key-btn" data-provider="gemini">
								<span class="dashicons dashicons-yes-alt"></span> Probar
							</button>
						</div>
						<span id="wpac-status-gemini" class="wpac-field-status"></span>
						<p class="description">Obtén tu clave gratuita en <a href="https://aistudio.google.com/" target="_blank" rel="noopener">Google AI Studio Portal</a>.</p>
					</div>

					<div class="wpac-field">
						<label for="pexels_api_key">Pexels API Key (Imágenes HD - Predeterminada)</label>
						<div class="wpac-input-btn-row">
							<input type="password" id="pexels_api_key" name="pexels_api_key" value="<?php echo esc_attr( $pexels_key ); ?>" placeholder="563492ad6f91700001000001..." />
							<button type="button" class="button wpac-test-single-key-btn" data-provider="pexels">
								<span class="dashicons dashicons-yes-alt"></span> Probar
							</button>
						</div>
						<span id="wpac-status-pexels" class="wpac-field-status"></span>
						<p class="description">Obtén tu clave gratuita en <a href="https://www.pexels.com/api/" target="_blank" rel="noopener">Pexels API Portal</a>.</p>
					</div>

					<div class="wpac-field">
						<label for="unsplash_api_key">Unsplash Access Key (Opcional)</label>
						<div class="wpac-input-btn-row">
							<input type="password" id="unsplash_api_key" name="unsplash_api_key" value="<?php echo esc_attr( $unsplash_key ); ?>" placeholder="v_abc123..." />
							<button type="button" class="button wpac-test-single-key-btn" data-provider="unsplash">
								<span class="dashicons dashicons-yes-alt"></span> Probar
							</button>
						</div>
						<span id="wpac-status-unsplash" class="wpac-field-status"></span>
						<p class="description">Obtén tu clave gratuita en <a href="https://unsplash.com/developers" target="_blank" rel="noopener">Unsplash Developers Portal</a>. (💡 <em>Solo la <strong>Access Key</strong></em>).</p>
					</div>

					<div class="wpac-field">
						<label for="pixabay_api_key">Pixabay API Key (Opcional)</label>
						<div class="wpac-input-btn-row">
							<input type="password" id="pixabay_api_key" name="pixabay_api_key" value="<?php echo esc_attr( $pixabay_key ); ?>" placeholder="12345678-abc..." />
							<button type="button" class="button wpac-test-single-key-btn" data-provider="pixabay">
								<span class="dashicons dashicons-yes-alt"></span> Probar
							</button>
						</div>
						<span id="wpac-status-pixabay" class="wpac-field-status"></span>
						<p class="description">Obtén tu clave gratuita en <a href="https://pixabay.com/api/docs/" target="_blank" rel="noopener">Pixabay API Portal</a>.</p>
					</div>

					<div class="wpac-field">
						<label for="iconfinder_api_key">Iconfinder API Key (Opcional - Plan de Pago)</label>
						<div class="wpac-input-btn-row">
							<input type="password" id="iconfinder_api_key" name="iconfinder_api_key" value="<?php echo esc_attr( $iconfinder_key ); ?>" placeholder="secret_xyz..." />
							<button type="button" class="button wpac-test-single-key-btn" data-provider="iconfinder">
								<span class="dashicons dashicons-yes-alt"></span> Probar
							</button>
						</div>
						<span id="wpac-status-iconfinder" class="wpac-field-status"></span>
						<p class="description">⚠️ <em>Iconfinder redirige a planes de pago (Magnific). Para usar iconos <strong>100% gratis sin registro</strong>, usa la <strong>Opción A: Iconify API</strong> en Origen del Contenido.</em></p>
					</div>

					<div class="wpac-btn-group">
						<button type="button" id="wpac-save-keys-btn" class="button button-primary button-large">
							<span class="dashicons dashicons-saved"></span> Guardar Claves API
						</button>
						<span id="wpac-keys-status" class="wpac-inline-status"></span>
					</div>
				</form>
			</div>

			<div class="wpac-footer">
				<p>WP autocontent v<?php echo esc_html( WP_AUTOCONTENT_VERSION ); ?> &bull; Desarrollado con ❤️ por <a href="https://19webs.com" target="_blank" rel="noopener">19webs</a></p>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX Handler: Guardar Claves API.
	 */
	public function ajax_save_settings(): void {
		check_ajax_referer( 'wp_autocontent_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes permisos suficientes.', 'wp-autocontent' ) ) );
		}

		$gemini_key     = isset( $_POST['gemini_key'] ) ? sanitize_text_field( wp_unslash( $_POST['gemini_key'] ) ) : '';
		$pexels_key     = isset( $_POST['pexels_key'] ) ? sanitize_text_field( wp_unslash( $_POST['pexels_key'] ) ) : '';
		$unsplash_key   = isset( $_POST['unsplash_key'] ) ? sanitize_text_field( wp_unslash( $_POST['unsplash_key'] ) ) : '';
		$pixabay_key    = isset( $_POST['pixabay_key'] ) ? sanitize_text_field( wp_unslash( $_POST['pixabay_key'] ) ) : '';
		$iconfinder_key = isset( $_POST['iconfinder_key'] ) ? sanitize_text_field( wp_unslash( $_POST['iconfinder_key'] ) ) : '';

		update_option( 'wp_autocontent_gemini_key', $gemini_key );
		update_option( 'wp_autocontent_pexels_key', $pexels_key );
		update_option( 'wp_autocontent_unsplash_key', $unsplash_key );
		update_option( 'wp_autocontent_pixabay_key', $pixabay_key );
		update_option( 'wp_autocontent_iconfinder_key', $iconfinder_key );

		wp_send_json_success( array( 'message' => __( '¡Claves API guardadas correctamente!', 'wp-autocontent' ) ) );
	}

	/**
	 * AJAX Handler: Fase 1 - Preparar / Extraer Pool de Contenidos.
	 */
	public function ajax_prepare_pool(): void {
		check_ajax_referer( 'wp_autocontent_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes permisos suficientes.', 'wp-autocontent' ) ) );
		}

		$post_gemini_key     = isset( $_POST['gemini_key'] ) ? sanitize_text_field( wp_unslash( $_POST['gemini_key'] ) ) : '';
		$post_pexels_key     = isset( $_POST['pexels_key'] ) ? sanitize_text_field( wp_unslash( $_POST['pexels_key'] ) ) : '';
		$post_unsplash_key   = isset( $_POST['unsplash_key'] ) ? sanitize_text_field( wp_unslash( $_POST['unsplash_key'] ) ) : '';
		$post_pixabay_key    = isset( $_POST['pixabay_key'] ) ? sanitize_text_field( wp_unslash( $_POST['pixabay_key'] ) ) : '';
		$post_iconfinder_key = isset( $_POST['iconfinder_key'] ) ? sanitize_text_field( wp_unslash( $_POST['iconfinder_key'] ) ) : '';

		if ( ! empty( $post_gemini_key ) ) {
			update_option( 'wp_autocontent_gemini_key', $post_gemini_key );
		}
		if ( ! empty( $post_pexels_key ) ) {
			update_option( 'wp_autocontent_pexels_key', $post_pexels_key );
		}
		if ( ! empty( $post_unsplash_key ) ) {
			update_option( 'wp_autocontent_unsplash_key', $post_unsplash_key );
		}
		if ( ! empty( $post_pixabay_key ) ) {
			update_option( 'wp_autocontent_pixabay_key', $post_pixabay_key );
		}
		if ( ! empty( $post_iconfinder_key ) ) {
			update_option( 'wp_autocontent_iconfinder_key', $post_iconfinder_key );
		}

		$text_source   = isset( $_POST['text_source'] ) ? sanitize_text_field( wp_unslash( $_POST['text_source'] ) ) : 'gemini';
		$image_source  = isset( $_POST['image_source'] ) ? sanitize_text_field( wp_unslash( $_POST['image_source'] ) ) : 'pexels';
		$icon_source   = isset( $_POST['icon_source'] ) ? sanitize_text_field( wp_unslash( $_POST['icon_source'] ) ) : 'iconify';
		$scrape_url    = isset( $_POST['scrape_url'] ) ? esc_url_raw( wp_unslash( $_POST['scrape_url'] ) ) : '';
		$gemini_sector = isset( $_POST['gemini_sector'] ) ? sanitize_text_field( wp_unslash( $_POST['gemini_sector'] ) ) : '';
		$gemini_tone   = isset( $_POST['gemini_tone'] ) ? sanitize_text_field( wp_unslash( $_POST['gemini_tone'] ) ) : 'Profesional';
		$pexels_kw     = isset( $_POST['pexels_keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['pexels_keyword'] ) ) : '';
		$page_ids      = isset( $_POST['page_ids'] ) && is_array( $_POST['page_ids'] ) ? array_map( 'intval', $_POST['page_ids'] ) : array();

		// PRE-ESCANEO DE LAS PÁGINAS SELECCIONADAS
		$needed_slots = WP_Autocontent_Elementor_Mutator::count_pages_slots( $page_ids );

		$headings   = array();
		$paragraphs = array();
		$images     = array();
		$icons      = array();

		// Instanciar importador de medios
		$p_key = ! empty( $post_pexels_key ) ? $post_pexels_key : get_option( 'wp_autocontent_pexels_key', '' );
		$u_key = ! empty( $post_unsplash_key ) ? $post_unsplash_key : get_option( 'wp_autocontent_unsplash_key', '' );
		$x_key = ! empty( $post_pixabay_key ) ? $post_pixabay_key : get_option( 'wp_autocontent_pixabay_key', '' );
		$i_key = ! empty( $post_iconfinder_key ) ? $post_iconfinder_key : get_option( 'wp_autocontent_iconfinder_key', '' );
		$g_key = ! empty( $post_gemini_key ) ? $post_gemini_key : get_option( 'wp_autocontent_gemini_key', '' );

		$importer = new WP_Autocontent_Media_Importer( $p_key, $u_key, $x_key, $i_key );

		// 1. Procesar Textos
		if ( 'none' === $text_source ) {
			$headings   = array();
			$paragraphs = array();
		} elseif ( 'scrape' === $text_source ) {
			if ( empty( $scrape_url ) ) {
				wp_send_json_error( array( 'message' => __( 'Debe ingresar una URL válida para scraping.', 'wp-autocontent' ) ) );
			}
			$scraper = new WP_Autocontent_Scraper();
			$scraped = $scraper->scrape_url( $scrape_url );

			if ( is_wp_error( $scraped ) ) {
				wp_send_json_error( array( 'message' => $scraped->get_error_message() ) );
			}

			$raw_headings   = $scraped['headings'];
			$raw_paragraphs = $scraped['paragraphs'];

			// Si Gemini API Key está disponible, pulir y limpiar el texto extraído con IA
			if ( ! empty( $g_key ) && ( ! empty( $raw_headings ) || ! empty( $raw_paragraphs ) ) ) {
				$gemini    = new WP_Autocontent_Gemini_Client( $g_key );
				$req_h     = max( 25, $needed_slots['headings'] + 10 );
				$req_p     = max( 25, $needed_slots['paragraphs'] + 10 );
				$enhanced  = $gemini->enhance_scraped_text( $raw_headings, $raw_paragraphs, $req_h, $req_p );

				if ( ! is_wp_error( $enhanced ) && ! empty( $enhanced['headings'] ) ) {
					$headings   = $enhanced['headings'];
					$paragraphs = $enhanced['paragraphs'];
				} else {
					$headings   = $raw_headings;
					$paragraphs = $raw_paragraphs;
				}
			} else {
				$headings   = $raw_headings;
				$paragraphs = $raw_paragraphs;
			}

			// Si el origen de imágenes es la web del cliente o híbrido
			if ( ( 'scrape' === $image_source ) && ! empty( $scraped['images'] ) ) {
				foreach ( $scraped['images'] as $raw_img_url ) {
					$imported = $importer->import_remote_image( $raw_img_url );
					if ( ! is_wp_error( $imported ) ) {
						$images[] = $imported;
					}
				}
			}

			// Extraer e importar logotipos de clientes / marcas y guardar datos de contacto
			$imported_logos = array();
			if ( ! empty( $scraped['contact']['logos'] ) && is_array( $scraped['contact']['logos'] ) ) {
				foreach ( $scraped['contact']['logos'] as $logo_url ) {
					$imp_logo = $importer->import_remote_image( $logo_url );
					if ( ! is_wp_error( $imp_logo ) && is_array( $imp_logo ) ) {
						$imported_logos[] = $imp_logo;
					}
				}
			}

			$contact_data = array(
				'phones'      => ! empty( $scraped['contact']['phones'] ) ? $scraped['contact']['phones'] : array( '+34 912 345 678' ),
				'emails'      => ! empty( $scraped['contact']['emails'] ) ? $scraped['contact']['emails'] : array( 'info@empresa.com' ),
				'address'     => ! empty( $scraped['contact']['address'] ) ? $scraped['contact']['address'] : 'Calle Velázquez 45, 28001 Madrid, España',
				'logos'       => $imported_logos,
				'form_fields' => ! empty( $scraped['contact']['form_fields'] ) ? $scraped['contact']['form_fields'] : array( 'Nombre completo', 'Correo electrónico', 'Teléfono / Móvil', 'Asunto', 'Mensaje' ),
			);
		} else { // 'gemini'
			if ( empty( $gemini_sector ) ) {
				wp_send_json_error( array( 'message' => __( 'Debe ingresar un sector/actividad para la IA de Gemini.', 'wp-autocontent' ) ) );
			}

			if ( empty( $g_key ) ) {
				wp_send_json_error( array( 'message' => __( 'Por favor, introduce la API Key de Google Gemini.', 'wp-autocontent' ) ) );
			}

			$req_headings   = max( 25, $needed_slots['headings'] + 10 );
			$req_paragraphs = max( 25, $needed_slots['paragraphs'] + 10 );

			$gemini    = new WP_Autocontent_Gemini_Client( $g_key );
			$generated = $gemini->generate_content( $gemini_sector, $gemini_tone, $req_headings, $req_paragraphs );

			if ( is_wp_error( $generated ) ) {
				wp_send_json_error( array( 'message' => $generated->get_error_message() ) );
			}

			$headings   = $generated['headings'];
			$paragraphs = $generated['paragraphs'];

			$contact_data = array(
				'phones'      => array( '+34 912 345 678' ),
				'emails'      => array( 'info@empresa.com' ),
				'address'     => 'Calle Velázquez 45, 28001 Madrid, España',
				'logos'       => array(),
				'form_fields' => array( 'Nombre completo', 'Correo electrónico', 'Teléfono / Móvil', 'Asunto', 'Mensaje' ),
			);
		}

		// 2. Procesar Imágenes (Pexels, Unsplash, Pixabay) o Fallback si faltan ranuras
		$needed_img_count  = $needed_slots['images'];
		$current_img_count = count( $images );

		// Si el usuario no escribió una palabra clave y venimos de scraping, deducir la temática de los titulares escaneados
		if ( empty( $pexels_kw ) && ! empty( $headings ) ) {
			$scraped_context = implode( ' ', array_slice( $headings, 0, 3 ) );
			$pexels_kw       = mb_substr( trim( $scraped_context ), 0, 80, 'UTF-8' );
		}

		$raw_kw = ! empty( $pexels_kw ) ? $pexels_kw : ( ! empty( $gemini_sector ) ? $gemini_sector : 'nautical sports' );
		$kw     = $this->translate_to_english( $raw_kw );

		if ( in_array( $image_source, array( 'pexels', 'unsplash', 'pixabay' ), true ) || ( 'scrape' === $image_source && $current_img_count < $needed_img_count ) ) {
			$stock_provider = in_array( $image_source, array( 'pexels', 'unsplash', 'pixabay' ), true ) ? $image_source : 'pexels';

			$images_to_fetch = ( 'scrape' === $image_source ) ? max( 5, $needed_img_count - $current_img_count + 5 ) : max( 20, min( 80, $needed_img_count + 10 ) );
			$imported_stock  = $importer->fetch_and_import( $stock_provider, $kw, $images_to_fetch );

			if ( ! is_wp_error( $imported_stock ) && is_array( $imported_stock ) ) {
				if ( 'scrape' === $image_source ) {
					// Combinar: imágenes reales del cliente primero, y fotos stock para completar ranuras vacías
					$images = array_merge( $images, $imported_stock );
				} else {
					$images = $imported_stock;
				}
			}
		}

		// 3. Procesar Iconos PNG
		if ( 'none' !== $icon_source ) {
			$fetched_icons = $importer->fetch_png_icons( $icon_source, $kw, 15 );
			if ( ! is_wp_error( $fetched_icons ) ) {
				$icons = $fetched_icons;
			}
		}

		$auto_create_sections = isset( $_POST['auto_create_sections'] ) ? (int) $_POST['auto_create_sections'] : 0;

		$pool_data = array(
			'headings'             => $headings,
			'paragraphs'           => $paragraphs,
			'images'               => $images,
			'icons'                => $icons,
			'contact'              => isset( $contact_data ) ? $contact_data : array(),
			'auto_create_sections' => $auto_create_sections,
		);

		$transient_key = 'wp_autocontent_pool_' . get_current_user_id();
		set_transient( $transient_key, $pool_data, HOUR_IN_SECONDS );

		wp_send_json_success(
			array(
				'message' => sprintf(
					__( 'Páginas analizadas (%d ranuras detectadas). Pool generado con éxito: %d titulares, %d párrafos, %d imágenes y %d iconos PNG listos.', 'wp-autocontent' ),
					($needed_slots['headings'] + $needed_slots['paragraphs'] + $needed_slots['images']),
					count( $headings ),
					count( $paragraphs ),
					count( $images ),
					count( $icons )
				),
				'stats'   => array(
					'headings'   => count( $headings ),
					'paragraphs' => count( $paragraphs ),
					'images'     => count( $images ),
					'icons'      => count( $icons ),
				),
			)
		);
	}

	/**
	 * AJAX Handler: Fase 2 - Procesar y Mutar una Página Individual.
	 */
	public function ajax_process_page(): void {
		check_ajax_referer( 'wp_autocontent_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes permisos suficientes.', 'wp-autocontent' ) ) );
		}

		$page_id = isset( $_POST['page_id'] ) ? (int) $_POST['page_id'] : 0;
		if ( ! $page_id ) {
			wp_send_json_error( array( 'message' => __( 'ID de página no válido.', 'wp-autocontent' ) ) );
		}

		$transient_key = 'wp_autocontent_pool_' . get_current_user_id();
		$pool_data     = get_transient( $transient_key );

		if ( ! is_array( $pool_data ) ) {
			wp_send_json_error( array( 'message' => __( 'El pool de contenidos expiró o no fue preparado.', 'wp-autocontent' ) ) );
		}

		$mutator = new WP_Autocontent_Elementor_Mutator(
			isset( $pool_data['headings'] ) ? $pool_data['headings'] : array(),
			isset( $pool_data['paragraphs'] ) ? $pool_data['paragraphs'] : array(),
			isset( $pool_data['images'] ) ? $pool_data['images'] : array(),
			isset( $pool_data['icons'] ) ? $pool_data['icons'] : array()
		);

		if ( ! empty( $pool_data['contact'] ) && is_array( $pool_data['contact'] ) ) {
			$mutator->set_contact_info( $pool_data['contact'] );
		}

		if ( ! empty( $pool_data['auto_create_sections'] ) ) {
			$mutator->set_auto_create_sections( true );
		}

		$result = $mutator->mutate_page( $page_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX Handler: Probar Conexión en vivo de las Claves API ingresadas.
	 */
	public function ajax_test_api_keys(): void {
		check_ajax_referer( 'wp_autocontent_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes permisos suficientes.', 'wp-autocontent' ) ) );
		}

		$gemini_key     = isset( $_POST['gemini_key'] ) ? sanitize_text_field( wp_unslash( $_POST['gemini_key'] ) ) : get_option( 'wp_autocontent_gemini_key', '' );
		$pexels_key     = isset( $_POST['pexels_key'] ) ? sanitize_text_field( wp_unslash( $_POST['pexels_key'] ) ) : get_option( 'wp_autocontent_pexels_key', '' );
		$unsplash_key   = isset( $_POST['unsplash_key'] ) ? sanitize_text_field( wp_unslash( $_POST['unsplash_key'] ) ) : get_option( 'wp_autocontent_unsplash_key', '' );
		$pixabay_key    = isset( $_POST['pixabay_key'] ) ? sanitize_text_field( wp_unslash( $_POST['pixabay_key'] ) ) : get_option( 'wp_autocontent_pixabay_key', '' );
		$iconfinder_key = isset( $_POST['iconfinder_key'] ) ? sanitize_text_field( wp_unslash( $_POST['iconfinder_key'] ) ) : get_option( 'wp_autocontent_iconfinder_key', '' );

		$results = array();

		// 1. Probar Google Gemini
		if ( ! empty( $gemini_key ) ) {
			$gemini_client = new WP_Autocontent_Gemini_Client( $gemini_key );
			$models        = $gemini_client->discover_active_models();
			if ( ! is_wp_error( $models ) && ! empty( $models ) ) {
				$results[] = array(
					'status'  => 'success',
					'service' => 'Google Gemini (IA)',
					'message' => sprintf( __( 'Conexión exitosa. (%d modelos activos detectados)', 'wp-autocontent' ), count( $models ) ),
				);
			} else {
				$err_msg   = is_wp_error( $models ) ? $models->get_error_message() : __( 'Clave API no válida.', 'wp-autocontent' );
				$results[] = array(
					'status'  => 'error',
					'service' => 'Google Gemini (IA)',
					'message' => sprintf( __( 'Error: %s', 'wp-autocontent' ), $err_msg ),
				);
			}
		} else {
			$results[] = array(
				'status'  => 'warning',
				'service' => 'Google Gemini (IA)',
				'message' => __( 'Sin clave configurada.', 'wp-autocontent' ),
			);
		}

		// 2. Probar Pexels
		if ( ! empty( $pexels_key ) ) {
			$res  = wp_remote_get( 'https://api.pexels.com/v1/search?query=test&per_page=1', array( 'headers' => array( 'Authorization' => $pexels_key ), 'timeout' => 10 ) );
			$code = wp_remote_retrieve_response_code( $res );
			if ( 200 === $code ) {
				$results[] = array(
					'status'  => 'success',
					'service' => 'Pexels API (Imágenes)',
					'message' => __( 'Conexión exitosa. Clave API válida.', 'wp-autocontent' ),
				);
			} else {
				$results[] = array(
					'status'  => 'error',
					'service' => 'Pexels API (Imágenes)',
					'message' => sprintf( __( 'Error HTTP %d. Verifica tu clave API.', 'wp-autocontent' ), $code ),
				);
			}
		} else {
			$results[] = array(
				'status'  => 'warning',
				'service' => 'Pexels API (Imágenes)',
				'message' => __( 'Sin clave configurada (Predeterminada).', 'wp-autocontent' ),
			);
		}

		// 3. Probar Unsplash
		if ( ! empty( $unsplash_key ) ) {
			$res  = wp_remote_get( 'https://api.unsplash.com/search/photos?query=test&per_page=1', array( 'headers' => array( 'Authorization' => 'Client-ID ' . $unsplash_key ), 'timeout' => 10 ) );
			$code = wp_remote_retrieve_response_code( $res );
			if ( 200 === $code ) {
				$results[] = array(
					'status'  => 'success',
					'service' => 'Unsplash API (Imágenes)',
					'message' => __( 'Conexión exitosa. Access Key válida.', 'wp-autocontent' ),
				);
			} else {
				$results[] = array(
					'status'  => 'error',
					'service' => 'Unsplash API (Imágenes)',
					'message' => sprintf( __( 'Error HTTP %d. Verifica tu Access Key.', 'wp-autocontent' ), $code ),
				);
			}
		} else {
			$results[] = array(
				'status'  => 'info',
				'service' => 'Unsplash API (Imágenes)',
				'message' => __( 'Opcional (Sin clave configurada).', 'wp-autocontent' ),
			);
		}

		// 4. Probar Pixabay
		if ( ! empty( $pixabay_key ) ) {
			$res  = wp_remote_get( sprintf( 'https://pixabay.com/api/?key=%s&q=test&per_page=3', urlencode( $pixabay_key ) ), array( 'timeout' => 10 ) );
			$code = wp_remote_retrieve_response_code( $res );
			if ( 200 === $code ) {
				$results[] = array(
					'status'  => 'success',
					'service' => 'Pixabay API (Imágenes)',
					'message' => __( 'Conexión exitosa. API Key válida.', 'wp-autocontent' ),
				);
			} else {
				$results[] = array(
					'status'  => 'error',
					'service' => 'Pixabay API (Imágenes)',
					'message' => sprintf( __( 'Error HTTP %d. Verifica tu API Key.', 'wp-autocontent' ), $code ),
				);
			}
		} else {
			$results[] = array(
				'status'  => 'info',
				'service' => 'Pixabay API (Imágenes)',
				'message' => __( 'Opcional (Sin clave configurada).', 'wp-autocontent' ),
			);
		}

		// 5. Probar Iconfinder (Opcional)
		if ( ! empty( $iconfinder_key ) ) {
			$res  = wp_remote_get( 'https://api.iconfinder.com/v4/icons/search?query=test&count=1', array( 'headers' => array( 'Authorization' => 'Bearer ' . $iconfinder_key ), 'timeout' => 10 ) );
			$code = wp_remote_retrieve_response_code( $res );
			if ( 200 === $code ) {
				$results[] = array(
					'status'  => 'success',
					'service' => 'Iconfinder API (Iconos)',
					'message' => __( 'Conexión exitosa. Plan de pago activo.', 'wp-autocontent' ),
				);
			} else {
				$results[] = array(
					'status'  => 'error',
					'service' => 'Iconfinder API (Iconos)',
					'message' => sprintf( __( 'Error HTTP %d (Requiere suscripción activa en Magnific).', 'wp-autocontent' ), $code ),
				);
			}
		}

		wp_send_json_success( array( 'results' => $results ) );
	}

	/**
	 * AJAX Handler: Probar individualmente la conexión de una clave API específica.
	 */
	public function ajax_test_single_key(): void {
		check_ajax_referer( 'wp_autocontent_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes permisos suficientes.', 'wp-autocontent' ) ) );
		}

		$provider = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : '';
		$api_key  = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

		if ( empty( $provider ) ) {
			wp_send_json_error( array( 'message' => __( 'Proveedor no especificado.', 'wp-autocontent' ) ) );
		}

		if ( empty( $api_key ) ) {
			$option_keys = array(
				'gemini'     => 'wp_autocontent_gemini_key',
				'pexels'     => 'wp_autocontent_pexels_key',
				'unsplash'   => 'wp_autocontent_unsplash_key',
				'pixabay'    => 'wp_autocontent_pixabay_key',
				'iconfinder' => 'wp_autocontent_iconfinder_key',
			);
			if ( isset( $option_keys[ $provider ] ) ) {
				$api_key = get_option( $option_keys[ $provider ], '' );
			}
		}

		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Por favor, ingresa una clave API primero.', 'wp-autocontent' ) ) );
		}

		if ( 'gemini' === $provider ) {
			$gemini_client = new WP_Autocontent_Gemini_Client( $api_key );
			$models        = $gemini_client->discover_active_models();
			if ( ! is_wp_error( $models ) && ! empty( $models ) ) {
				wp_send_json_success( array( 'message' => sprintf( __( 'Conexión exitosa con Google Gemini IA (%d modelos activos).', 'wp-autocontent' ), count( $models ) ) ) );
			} else {
				$err_msg = is_wp_error( $models ) ? $models->get_error_message() : __( 'Clave API no válida.', 'wp-autocontent' );
				wp_send_json_error( array( 'message' => $err_msg ) );
			}
		} elseif ( 'pexels' === $provider ) {
			$res  = wp_remote_get( 'https://api.pexels.com/v1/search?query=office&per_page=1', array( 'headers' => array( 'Authorization' => $api_key ), 'timeout' => 10 ) );
			$code = wp_remote_retrieve_response_code( $res );
			if ( 200 === $code ) {
				wp_send_json_success( array( 'message' => __( 'Conexión exitosa. Clave API de Pexels válida.', 'wp-autocontent' ) ) );
			} else {
				wp_send_json_error( array( 'message' => sprintf( __( 'Error HTTP %d. Revisa tu clave API de Pexels.', 'wp-autocontent' ), $code ) ) );
			}
		} elseif ( 'unsplash' === $provider ) {
			$res  = wp_remote_get( 'https://api.unsplash.com/search/photos?query=office&per_page=1', array( 'headers' => array( 'Authorization' => 'Client-ID ' . $api_key ), 'timeout' => 10 ) );
			$code = wp_remote_retrieve_response_code( $res );
			if ( 200 === $code ) {
				wp_send_json_success( array( 'message' => __( 'Conexión exitosa. Access Key de Unsplash válida.', 'wp-autocontent' ) ) );
			} else {
				wp_send_json_error( array( 'message' => sprintf( __( 'Error HTTP %d. Revisa tu Access Key de Unsplash.', 'wp-autocontent' ), $code ) ) );
			}
		} elseif ( 'pixabay' === $provider ) {
			$res  = wp_remote_get( sprintf( 'https://pixabay.com/api/?key=%s&q=office&per_page=3', urlencode( $api_key ) ), array( 'timeout' => 10 ) );
			$code = wp_remote_retrieve_response_code( $res );
			if ( 200 === $code ) {
				wp_send_json_success( array( 'message' => __( 'Conexión exitosa. API Key de Pixabay válida.', 'wp-autocontent' ) ) );
			} else {
				wp_send_json_error( array( 'message' => sprintf( __( 'Error HTTP %d. Revisa tu API Key de Pixabay.', 'wp-autocontent' ), $code ) ) );
			}
		} elseif ( 'iconfinder' === $provider ) {
			$res  = wp_remote_get( 'https://api.iconfinder.com/v4/icons/search?query=test&count=1', array( 'headers' => array( 'Authorization' => 'Bearer ' . $api_key ), 'timeout' => 10 ) );
			$code = wp_remote_retrieve_response_code( $res );
			if ( 200 === $code ) {
				wp_send_json_success( array( 'message' => __( 'Conexión exitosa con Iconfinder API.', 'wp-autocontent' ) ) );
			} else {
				wp_send_json_error( array( 'message' => sprintf( __( 'Error HTTP %d (Requiere suscripción activa en Magnific).', 'wp-autocontent' ), $code ) ) );
			}
		} else {
			wp_send_json_error( array( 'message' => __( 'Proveedor desconocido.', 'wp-autocontent' ) ) );
		}
	}

	/**
	 * Traduce automáticamente cualquier texto o palabra clave en español al inglés para consultar las APIs de Stock e Iconos.
	 *
	 * @param string $text Texto o palabra clave a traducir.
	 * @return string Texto traducido al inglés.
	 */
	private function translate_to_english( string $text ): string {
		$text = trim( $text );
		if ( empty( $text ) ) {
			return '';
		}

		$endpoint = sprintf(
			'https://translate.googleapis.com/translate_a/single?client=gtx&sl=auto&tl=en&dt=t&q=%s',
			rawurlencode( $text )
		);

		$response = wp_remote_get( $endpoint, array( 'timeout' => 8 ) );
		if ( ! is_wp_error( $response ) ) {
			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );
			if ( 200 === $code && ! empty( $body ) ) {
				$data = json_decode( $body, true );
				if ( isset( $data[0][0][0] ) && ! empty( $data[0][0][0] ) ) {
					return sanitize_text_field( trim( $data[0][0][0] ) );
				}
			}
		}

		return $text;
	}
}
