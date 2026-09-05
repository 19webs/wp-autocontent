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

		// Endpoint AJAX para comprobar actualizaciones bajo demanda
		add_action( 'wp_ajax_wp_autocontent_check_updates', array( $this, 'ajax_check_updates' ) );
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
	 * Consulta la API de GitHub para obtener la última versión lanzada.
	 *
	 * @return array|WP_Error Datos de la release o error.
	 */
	private function get_latest_github_release() {
		$cache_key = 'wpac_github_release_cache';
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

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

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'github_api_error', sprintf( __( 'No se encontró una versión en GitHub (HTTP %d).', 'wp-autocontent' ), $code ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			return new WP_Error( 'invalid_response', __( 'Respuesta de GitHub no válida.', 'wp-autocontent' ) );
		}

		set_transient( $cache_key, $data, 6 * HOUR_IN_SECONDS );
		return $data;
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
			$update_url = admin_url( 'update-core.php' );
			wp_send_json_success(
				array(
					'has_update'     => true,
					'latest_version' => $latest_version,
					'message'        => sprintf(
						__( '🚀 ¡Nueva versión v%s disponible en GitHub! Puedes <a href="%s" target="_parent">actualizar ahora desde WordPress</a>.', 'wp-autocontent' ),
						$latest_version,
						esc_url( $update_url )
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
}
