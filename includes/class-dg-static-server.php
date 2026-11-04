<?php
/**
 * Servidor de sitios estáticos, componentes HTML y previsualización.
 *
 * @package DespliegaGit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sirve archivos estáticos directamente bajo URLs limpias (ej: tudominio.com/mi-app/).
 */
class DG_Static_Server {

	/**
	 * Inicializa los hooks de reescritura y despacho.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_request' ), 1 );
	}

	/**
	 * Registra los query vars personalizados.
	 *
	 * @param array $vars Lista de variables.
	 * @return array
	 */
	public static function register_query_vars( $vars ) {
		$vars[] = 'dg_site';
		$vars[] = 'dg_subpath';
		$vars[] = 'dg_preview';
		return $vars;
	}

	/**
	 * Registra las reglas de reescritura para sitios estáticos desplegados y previsualizaciones.
	 */
	public static function register_rules() {
		add_rewrite_rule(
			'^despliegagit-preview/([a-z0-9_-]+)(?:/(.*))?/?$',
			'index.php?dg_preview=$matches[1]&dg_subpath=$matches[2]',
			'top'
		);

		// Obtener todos los slugs de proyectos de tipo estático.
		$projects = DG_Projects::all();
		foreach ( $projects as $project ) {
			$type = ! empty( $project['deployed_type'] ) ? $project['deployed_type'] : $project['type'];
			$slug = ! empty( $project['deployed_slug'] ) ? $project['deployed_slug'] : $project['slug'];

			if ( 'static' === $type && ! empty( $slug ) ) {
				add_rewrite_rule(
					'^' . preg_quote( $slug, '^' ) . '(?:/(.*))?/?$',
					'index.php?dg_site=' . $slug . '&dg_subpath=$matches[1]',
					'top'
				);
			}
		}
	}

	/**
	 * Intercepta la petición si coincide con un sitio estático o una previsualización.
	 */
	public static function handle_request() {
		$preview_id = get_query_var( 'dg_preview' );
		$site_slug  = get_query_var( 'dg_site' );
		$subpath    = get_query_var( 'dg_subpath' );

		// 1. Manejo de previsualización en staging (requiere privilegios de admin).
		if ( ! empty( $preview_id ) ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Acceso denegado: debes ser administrador para ver la previsualización.', 'despliegagit' ), 403 );
			}

			$paths     = DG_Deployer::paths();
			$stage_dir = $paths['staging'] . '/' . sanitize_key( $preview_id );

			// Si el proyecto tiene subcarpeta configurada, ajustamos la ruta.
			$project = DG_Projects::get( $preview_id );
			if ( $project && ! empty( $project['subdir'] ) ) {
				$candidate = trailingslashit( $stage_dir ) . trim( $project['subdir'], '/\\' );
				if ( is_dir( $candidate ) ) {
					$stage_dir = $candidate;
				}
			}

			self::serve_directory( $stage_dir, $subpath, true );
			exit;
		}

		// 2. Manejo de sitio estático en producción.
		if ( ! empty( $site_slug ) ) {
			$paths    = DG_Deployer::paths();
			$site_dir = $paths['sites'] . '/' . DG_Projects::sanitize_slug( $site_slug );

			// Buscar si el proyecto tiene activado SPA Fallback.
			$spa = false;
			foreach ( DG_Projects::all() as $p ) {
				if ( ( ! empty( $p['deployed_slug'] ) && $p['deployed_slug'] === $site_slug ) || $p['slug'] === $site_slug ) {
					$spa = ! empty( $p['spa_fallback'] );
					break;
				}
			}

			self::serve_directory( $site_dir, $subpath, false, $spa );
			exit;
		}
	}

	/**
	 * Sirve un archivo desde una carpeta de forma segura y con las cabeceras MIME correctas.
	 *
	 * @param string $root_dir     Directorio raíz.
	 * @param string $subpath      Ruta solicitada dentro del directorio.
	 * @param bool   $is_preview   Indica si es modo previsualización.
	 * @param bool   $spa_fallback Si es true y no encuentra el archivo, sirve index.html.
	 */
	public static function serve_directory( $root_dir, $subpath = '', $is_preview = false, $spa_fallback = false ) {
		if ( ! is_dir( $root_dir ) ) {
			status_header( 404 );
			wp_die( esc_html__( 'El proyecto no existe o aún no ha sido publicado.', 'despliegagit' ), 404 );
		}

		$real_root = realpath( $root_dir );
		if ( false === $real_root ) {
			status_header( 404 );
			wp_die( esc_html__( 'Ruta raíz no encontrada.', 'despliegagit' ), 404 );
		}

		// Normalizar y sanear la subruta para evitar Directory Traversal.
		$clean_sub = trim( str_replace( array( '../', '..\\' ), '', (string) $subpath ), '/\\' );

		if ( empty( $clean_sub ) ) {
			$clean_sub = 'index.html';
		}

		$target_file = $real_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $clean_sub );

		// Si es una carpeta, buscar index.html en ella.
		if ( is_dir( $target_file ) ) {
			$target_file = rtrim( $target_file, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'index.html';
		}

		$real_target = realpath( $target_file );

		// Validar que el archivo esté estrictamente dentro de la raíz permitida.
		if ( false === $real_target || 0 !== strpos( $real_target, $real_root ) || ! is_file( $real_target ) ) {
			// Si no existe pero tiene SPA Fallback activo (React Router, Vue, etc.).
			if ( $spa_fallback && file_exists( $real_root . DIRECTORY_SEPARATOR . 'index.html' ) ) {
				$real_target = $real_root . DIRECTORY_SEPARATOR . 'index.html';
			} else {
				status_header( 404 );
				wp_die( esc_html__( 'Archivo no encontrado en el proyecto.', 'despliegagit' ), 404 );
			}
		}

		$mime = self::get_mime_type( $real_target );

		// Cabeceras HTTP.
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . filesize( $real_target ) );
		header( 'X-Content-Type-Options: nosniff' );

		if ( $is_preview ) {
			// Evitar que los motores indexen las previsualizaciones.
			header( 'X-Robots-Tag: noindex, nofollow' );
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		} else {
			// Caché estándar para activos estáticos.
			if ( preg_match( '/\.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot|webp)$/i', $real_target ) ) {
				header( 'Cache-Control: public, max-age=86400' );
			} else {
				header( 'Cache-Control: public, max-age=300, must-revalidate' );
			}
		}

		// Entregar el archivo y salir.
		readfile( $real_target );
		exit;
	}

	/**
	 * Devuelve el MIME type adecuado para el archivo.
	 *
	 * @param string $file Ruta del archivo.
	 * @return string
	 */
	public static function get_mime_type( $file ) {
		$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

		$mimes = array(
			'html'  => 'text/html; charset=utf-8',
			'htm'   => 'text/html; charset=utf-8',
			'css'   => 'text/css; charset=utf-8',
			'js'    => 'application/javascript; charset=utf-8',
			'json'  => 'application/json; charset=utf-8',
			'png'   => 'image/png',
			'jpg'   => 'image/jpeg',
			'jpeg'  => 'image/jpeg',
			'gif'   => 'image/gif',
			'svg'   => 'image/svg+xml',
			'webp'  => 'image/webp',
			'ico'   => 'image/x-icon',
			'woff'  => 'font/woff',
			'woff2' => 'font/woff2',
			'ttf'   => 'font/ttf',
			'eot'   => 'application/vnd.ms-fontobject',
			'otf'   => 'font/otf',
			'txt'   => 'text/plain; charset=utf-8',
			'xml'   => 'application/xml; charset=utf-8',
			'mp4'   => 'video/mp4',
			'webm'  => 'video/webm',
			'mp3'   => 'audio/mpeg',
			'wav'   => 'audio/wav',
			'pdf'   => 'application/pdf',
			'map'   => 'application/json',
		);

		return isset( $mimes[ $ext ] ) ? $mimes[ $ext ] : 'application/octet-stream';
	}
}

