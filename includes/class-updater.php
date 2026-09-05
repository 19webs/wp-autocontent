<?php
/**
 * Actualizador automático desde GitHub para WP autocontent.
 *
 * @package WP_Autocontent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Autocontent_Updater {

	/**
	 * Slugs y URLs del repositorio en GitHub.
	 *
	 * @var string
	 */
	private string $file;
	private string $slug;
	private string $version;
	private string $github_repo = '19webs/wp-autocontent';

	/**
	 * Constructor. Registra hooks para la actualización automática desde GitHub.
	 *
	 * @param string $file Ruta del archivo principal del plugin.
	 * @param string $version Versión actual del plugin.
	 */
	public function __construct( string $file, string $version ) {
		$this->file    = $file;
		$this->slug    = plugin_basename( $file );
		$this->version = $version;

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_post_install', array( $this, 'post_install' ), 10, 3 );

		// Endpoints AJAX
		add_action( 'wp_ajax_wp_autocontent_check_updates', array( $this, 'ajax_check_updates' ) );
		add_action( 'wp_ajax_wp_autocontent_install_update', array( $this, 'ajax_install_update' ) );
	}

	/**
	 * Comprueba si hay una nueva versión en GitHub y actualiza el transient de WordPress.
	 *
	 * @param object $transient Transient de actualización de plugins.
	 * @return object Transient modificado.
	 */
	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_latest_github_release();
		if ( is_wp_error( $release ) || empty( $release['tag_name'] ) ) {
			return $transient;
		}

		$latest_version = ltrim( $release['tag_name'], 'v' );

		if ( version_compare( $this->version, $latest_version, '<' ) ) {
			$download_url = isset( $release['zipball_url'] ) ? $release['zipball_url'] : sprintf( 'https://github.com/%s/archive/refs/tags/%s.zip', $this->github_repo, $release['tag_name'] );

			$obj              = new stdClass();
			$obj->slug        = 'wp-autocontent';
			$obj->plugin      = $this->slug;
			$obj->new_version = $latest_version;
			$obj->url         = sprintf( 'https://github.com/%s', $this->github_repo );
			$obj->package     = $download_url;
			$obj->icons       = array( 'default' => WP_AUTOCONTENT_URL . 'assets/images/logo.jpg' );

			$transient->response[ $this->slug ] = $obj;
		}

		return $transient;
	}

	/**
	 * Proporciona los detalles del plugin en la modal de WordPress Admin.
	 *
	 * @param mixed  $res Objeto de resultado.
	 * @param string $action Acción solicitada.
	 * @param object $args Argumentos.
	 * @return mixed Objeto con los detalles del plugin.
	 */
	public function plugin_info( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'wp-autocontent' !== $args->slug ) {
			return $res;
		}

		$release = $this->get_latest_github_release();
		if ( is_wp_error( $release ) || empty( $release['tag_name'] ) ) {
			return $res;
		}

		$latest_version = ltrim( $release['tag_name'], 'v' );

		$res                = new stdClass();
		$res->name          = 'WP autocontent';
		$res->slug          = 'wp-autocontent';
		$res->version       = $latest_version;
		$res->author        = '<a href="https://19webs.com" target="_blank" rel="noopener">19webs</a>';
		$res->homepage      = sprintf( 'https://github.com/%s', $this->github_repo );
		$res->download_link = sprintf( 'https://github.com/%s/archive/refs/tags/%s.zip', $this->github_repo, $release['tag_name'] );
		$res->sections      = array(
			'description' => 'Sustitución automatizada de contenido Lorem Ipsum para kits de Elementor y maquetas WordPress.',
			'changelog'   => isset( $release['body'] ) ? nl2br( esc_html( $release['body'] ) ) : 'Nueva versión disponible en GitHub.',
		);

		return $res;
	}

	/**
	 * Corrige la carpeta del plugin tras descomprimir la actualización desde GitHub.
	 *
	 * @param bool  $response Respuesta de la instalación.
	 * @param array $hook_extra Datos adicionales.
	 * @param array $result Resultado de la descompresión.
	 * @return array Resultado ajustado.
	 */
	public function post_install( $response, $hook_extra, $result ) {
		global $wp_filesystem;

		$plugin_folder = WP_PLUGIN_DIR . '/wp-autocontent';
		if ( isset( $result['destination'] ) && $result['destination'] !== $plugin_folder ) {
			$wp_filesystem->move( $result['destination'], $plugin_folder );
			$result['destination'] = $plugin_folder;
		}

		return $result;
	}

	/**
	 * Consulta la API de GitHub para obtener la última versión lanzada (vía releases o tags).
	 *
	 * @return array|WP_Error Datos de la release o error.
	 */
	private function get_latest_github_release() {
		$cache_key = 'wpac_github_release_cache';
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		// 1. Intentar consultar releases/latest
		$url      = sprintf( 'https://api.github.com/repos/%s/releases/latest', $this->github_repo );
		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'User-Agent' => 'WordPress/WP-Autocontent-Updater',
					'Accept'     => 'application/vnd.github.v3+json',
				),
				'timeout' => 10,
			)
		);

		if ( ! is_wp_error( $response ) ) {
			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			if ( 200 === $code && ! empty( $body ) ) {
				$data = json_decode( $body, true );
				if ( is_array( $data ) && ! empty( $data['tag_name'] ) ) {
					set_transient( $cache_key, $data, 300 );
					return $data;
				}
			}
		}

		// 2. Fallback: Si no hay release formal creada en GitHub UI, consultar la lista de etiquetas Git (tags)
		$tags_url = sprintf( 'https://api.github.com/repos/%s/tags', $this->github_repo );
		$tags_res = wp_remote_get(
			$tags_url,
			array(
				'headers' => array(
					'User-Agent' => 'WordPress/WP-Autocontent-Updater',
					'Accept'     => 'application/vnd.github.v3+json',
				),
				'timeout' => 10,
			)
		);

		if ( ! is_wp_error( $tags_res ) ) {
			$code = wp_remote_retrieve_response_code( $tags_res );
			$body = wp_remote_retrieve_body( $tags_res );

			if ( 200 === $code && ! empty( $body ) ) {
				$tags = json_decode( $body, true );
				if ( is_array( $tags ) && ! empty( $tags[0]['name'] ) ) {
					$latest_tag = $tags[0]['name'];
					$data       = array(
						'tag_name'    => $latest_tag,
						'zipball_url' => sprintf( 'https://github.com/%s/archive/refs/tags/%s.zip', $this->github_repo, $latest_tag ),
						'body'        => 'Actualización v' . ltrim( $latest_tag, 'v' ) . ' publicada en GitHub.',
					);
					set_transient( $cache_key, $data, 300 );
					return $data;
				}
			}
		}

		return new WP_Error( 'no_releases_found', __( 'Aún no se ha publicado ninguna versión en GitHub.', 'wp-autocontent' ) );
	}

	/**
	 * AJAX Handler: Forzar comprobación manual de actualizaciones desde el panel de control.
	 */
	public function ajax_check_updates(): void {
		check_ajax_referer( 'wp_autocontent_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes permisos suficientes.', 'wp-autocontent' ) ) );
		}

		delete_transient( 'wpac_github_release_cache' );
		delete_site_transient( 'update_plugins' );

		$release = $this->get_latest_github_release();

		if ( is_wp_error( $release ) ) {
			wp_send_json_error( array( 'message' => $release->get_error_message() ) );
		}

		$latest_version = ltrim( $release['tag_name'], 'v' );

		if ( version_compare( $this->version, $latest_version, '<' ) ) {
			wp_send_json_success(
				array(
					'has_update'     => true,
					'latest_version' => $latest_version,
					'message'        => sprintf(
						__( '🚀 ¡Nueva versión v%s disponible! <button type="button" id="wpac-install-update-btn" class="button button-primary wpac-install-btn" data-version="%s"><span class="dashicons dashicons-download"></span> Actualizar a v%s ahora</button>', 'wp-autocontent' ),
						$latest_version,
						$latest_version,
						$latest_version
					),
				)
			);
		} else {
			wp_send_json_success(
				array(
					'has_update'     => false,
					'latest_version' => $latest_version,
					'message'        => sprintf( __( '✅ Tienes la versión más reciente (v%s) instalada.', 'wp-autocontent' ), $this->version ),
				)
			);
		}
	}

	/**
	 * AJAX Handler: Ejecutar la actualización silenciosa in-situ desde la pantalla del plugin.
	 */
	public function ajax_install_update(): void {
		check_ajax_referer( 'wp_autocontent_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes permisos suficientes.', 'wp-autocontent' ) ) );
		}

		delete_transient( 'wpac_github_release_cache' );

		$transient = get_site_transient( 'update_plugins' );
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}
		$transient = $this->check_for_update( $transient );
		set_site_transient( 'update_plugins', $transient );

		include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		include_once ABSPATH . 'wp-admin/includes/file.php';
		include_once ABSPATH . 'wp-admin/includes/misc.php';
		include_once ABSPATH . 'wp-admin/includes/plugin.php';

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $this->slug );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		if ( is_wp_error( $skin->get_errors() ) && $skin->get_errors()->has_errors() ) {
			wp_send_json_error( array( 'message' => $skin->get_errors()->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => __( '✅ ¡Plugin actualizado con éxito! Recargando...', 'wp-autocontent' ),
				'reload'  => true,
			)
		);
	}
}
