<?php
/**
 * Panel de administración e integración AJAX de DespliegaGit.
 *
 * @package DespliegaGit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Gestiona menús, pantallas y peticiones AJAX del backend.
 */
class DG_Admin {

	/**
	 * Registra menús y acciones AJAX.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

		// AJAX endpoints.
		add_action( 'wp_ajax_dg_inspect_repo', array( __CLASS__, 'ajax_inspect_repo' ) );
		add_action( 'wp_ajax_dg_save_project', array( __CLASS__, 'ajax_save_project' ) );
		add_action( 'wp_ajax_dg_delete_project', array( __CLASS__, 'ajax_delete_project' ) );
		add_action( 'wp_ajax_dg_stage_project', array( __CLASS__, 'ajax_stage_project' ) );
		add_action( 'wp_ajax_dg_get_progress', array( __CLASS__, 'ajax_get_progress' ) );
		add_action( 'wp_ajax_dg_deploy_project', array( __CLASS__, 'ajax_deploy_project' ) );
		add_action( 'wp_ajax_dg_rollback_project', array( __CLASS__, 'ajax_rollback_project' ) );
		add_action( 'wp_ajax_dg_save_settings', array( __CLASS__, 'ajax_save_settings' ) );
	}

	/**
	 * Registra las páginas en el menú de WordPress.
	 */
	public static function register_menu() {
		$hook = add_menu_page(
			__( 'DespliegaGit', 'despliegagit' ),
			__( 'DespliegaGit', 'despliegagit' ),
			'manage_options',
			'despliegagit',
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-cloud-upload',
			30
		);

		add_submenu_page(
			'despliegagit',
			__( 'Proyectos y Despliegues', 'despliegagit' ),
			__( 'Proyectos', 'despliegagit' ),
			'manage_options',
			'despliegagit',
			array( __CLASS__, 'render_dashboard' )
		);

		add_submenu_page(
			'despliegagit',
			__( 'Ajustes de DespliegaGit', 'despliegagit' ),
			__( 'Ajustes', 'despliegagit' ),
			'manage_options',
			'despliegagit-settings',
			array( __CLASS__, 'render_settings' )
		);
	}

	/**
	 * Carga CSS y JS sólo en las páginas del plugin.
	 *
	 * @param string $hook_suffix Hook actual.
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( $hook_suffix, 'despliegagit' ) ) {
			return;
		}

		wp_enqueue_style(
			'despliegagit-admin',
			DESPLIEGAGIT_URL . 'assets/css/admin.css',
			array(),
			DESPLIEGAGIT_VERSION
		);

		wp_enqueue_script(
			'despliegagit-admin',
			DESPLIEGAGIT_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			DESPLIEGAGIT_VERSION,
			true
		);

		wp_localize_script(
			'despliegagit-admin',
			'despliegaGit',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'despliegagit_admin_nonce' ),
				'homeUrl'   => home_url(),
				'adminUrl'  => admin_url(),
				'strings'   => array(
					'inspecting'     => __( 'Conectando con GitHub y analizando repositorio...', 'despliegagit' ),
					'staging'        => __( 'Descargando y preparando archivos para previsualización...', 'despliegagit' ),
					'deploying'      => __( 'Publicando proyecto en el sitio web...', 'despliegagit' ),
					'rollingBack'    => __( 'Restaurando copia de seguridad...', 'despliegagit' ),
					'confirmDelete'  => __( '¿Estás seguro de que deseas eliminar este proyecto de DespliegaGit? (Los archivos ya publicados no se borrarán del servidor).', 'despliegagit' ),
					'confirmRollback'=> __( '¿Deseas restaurar la última copia de seguridad guardada para este proyecto?', 'despliegagit' ),
					'confirmDeploy'  => __( '¿Publicar ahora? Esta acción actualizará los archivos en vivo.', 'despliegagit' ),
				),
			)
		);
	}

	/**
	 * AJAX: Analiza un repositorio de GitHub dado por URL y devuelve ramas e info.
	 */
	public static function ajax_inspect_repo() {
		check_ajax_referer( 'despliegagit_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'despliegagit' ) ) );
		}

		$url   = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

		if ( empty( $url ) ) {
			wp_send_json_error( array( 'message' => __( 'Introduce la URL del repositorio.', 'despliegagit' ) ) );
		}

		$parsed = DG_GitHub::parse_repo_url( $url );
		if ( is_wp_error( $parsed ) ) {
			wp_send_json_error( array( 'message' => $parsed->get_error_message() ) );
		}

		$effective_token = ! empty( $token ) ? $token : DG_Settings::token();
		$github          = new DG_GitHub( $effective_token );

		$repo_info = $github->get_repo( $parsed['owner'], $parsed['repo'] );
		if ( is_wp_error( $repo_info ) ) {
			wp_send_json_error( array( 'message' => $repo_info->get_error_message() ) );
		}

		$branches = $github->get_branches( $parsed['owner'], $parsed['repo'] );
		if ( is_wp_error( $branches ) ) {
			$branches = array( ! empty( $repo_info['default_branch'] ) ? $repo_info['default_branch'] : 'main' );
		}

		$default_branch = ! empty( $parsed['branch'] ) ? $parsed['branch'] : ( ! empty( $repo_info['default_branch'] ) ? $repo_info['default_branch'] : 'main' );

		wp_send_json_success(
			array(
				'owner'          => $parsed['owner'],
				'repo'           => $parsed['repo'],
				'description'    => ! empty( $repo_info['description'] ) ? $repo_info['description'] : '',
				'is_private'     => ! empty( $repo_info['private'] ),
				'default_branch' => $default_branch,
				'branches'       => $branches,
				'suggested_slug' => DG_Projects::sanitize_slug( $parsed['repo'] ),
				'subdir'         => $parsed['subdir'],
			)
		);
	}

	/**
	 * AJAX: Guarda un proyecto (crear o editar).
	 */
	public static function ajax_save_project() {
		check_ajax_referer( 'despliegagit_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'despliegagit' ) ) );
		}

		$id       = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$repo_url = isset( $_POST['repo_url'] ) ? sanitize_text_field( wp_unslash( $_POST['repo_url'] ) ) : '';
		$branch   = isset( $_POST['branch'] ) ? sanitize_text_field( wp_unslash( $_POST['branch'] ) ) : 'main';
		$type     = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'auto';
		$slug     = isset( $_POST['slug'] ) ? DG_Projects::sanitize_slug( wp_unslash( $_POST['slug'] ) ) : '';
		$subdir   = isset( $_POST['subdir'] ) ? sanitize_text_field( wp_unslash( $_POST['subdir'] ) ) : '';
		$token    = isset( $_POST['token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '';
		$exclude  = isset( $_POST['exclude'] ) ? sanitize_textarea_field( wp_unslash( $_POST['exclude'] ) ) : '';
		$preserve = isset( $_POST['preserve'] ) ? sanitize_textarea_field( wp_unslash( $_POST['preserve'] ) ) : '';
		$auto_dep = ! empty( $_POST['auto_deploy'] );
		$spa_fall = ! empty( $_POST['spa_fallback'] );

		$parsed = DG_GitHub::parse_repo_url( $repo_url );
		if ( is_wp_error( $parsed ) ) {
			wp_send_json_error( array( 'message' => $parsed->get_error_message() ) );
		}

		if ( empty( $name ) ) {
			$name = $parsed['repo'];
		}

		if ( empty( $slug ) ) {
			$slug = DG_Projects::sanitize_slug( $parsed['repo'] );
		}

		// Comprobar colisión de slug.
		$conflict = DG_Projects::find_conflict( $type, $slug, $id );
		if ( $conflict ) {
			wp_send_json_error( array( 'message' => sprintf( __( 'El slug o ruta "%s" ya está siendo utilizado por el proyecto "%s".', 'despliegagit' ), $slug, $conflict['name'] ) ) );
		}

		$data = array(
			'name'         => $name,
			'repo_url'     => $parsed['url'],
			'owner'        => $parsed['owner'],
			'repo'         => $parsed['repo'],
			'branch'       => $branch,
			'type'         => $type,
			'slug'         => $slug,
			'subdir'       => $subdir,
			'exclude'      => $exclude,
			'preserve'     => $preserve,
			'auto_deploy'  => $auto_dep,
			'spa_fallback' => $spa_fall,
		);

		if ( ! empty( $token ) ) {
			$data['token'] = DG_Crypto::encrypt( $token );
		}

		if ( ! empty( $id ) && DG_Projects::get( $id ) ) {
			DG_Projects::update( $id, $data );
			$project_id = $id;
		} else {
			$project_id = DG_Projects::insert( $data );
		}

		// Refrescar reglas por si cambió de slug estático.
		flush_rewrite_rules( false );

		wp_send_json_success(
			array(
				'id'      => $project_id,
				'message' => __( 'Proyecto guardado correctamente.', 'despliegagit' ),
			)
		);
	}

	/**
	 * AJAX: Elimina un proyecto.
	 */
	public static function ajax_delete_project() {
		check_ajax_referer( 'despliegagit_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'despliegagit' ) ) );
		}

		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		DG_Projects::delete( $id );
		flush_rewrite_rules( false );

		wp_send_json_success( array( 'message' => __( 'Proyecto eliminado.', 'despliegagit' ) ) );
	}

	/**
	 * AJAX: Descarga en Staging y prepara la previsualización.
	 */
	public static function ajax_stage_project() {
		check_ajax_referer( 'despliegagit_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'despliegagit' ) ) );
		}

		$id      = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$project = DG_Projects::get( $id );

		if ( ! $project ) {
			wp_send_json_error( array( 'message' => __( 'Proyecto no encontrado.', 'despliegagit' ) ) );
		}

		$staged = DG_Deployer::stage( $project );
		if ( is_wp_error( $staged ) ) {
			wp_send_json_error( array( 'message' => $staged->get_error_message() ) );
		}

		$preview_url = home_url( '/despliegagit-preview/' . $id . '/' );

		wp_send_json_success(
			array(
				'message'     => __( 'Proyecto descargado e inspeccionado con éxito.', 'despliegagit' ),
				'staged'      => $staged,
				'preview_url' => $preview_url,
			)
		);
	}

	/**
	 * AJAX: Consulta el progreso en tiempo real de una importación.
	 */
	public static function ajax_get_progress() {
		check_ajax_referer( 'despliegagit_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'despliegagit' ) ) );
		}

		$id = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';
		if ( empty( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'ID de proyecto no especificado.', 'despliegagit' ) ) );
		}

		$progress = DG_Deployer::get_progress( $id );
		wp_send_json_success( $progress );
	}

	/**
	 * AJAX: Despliega definitivamente a producción.
	 */
	public static function ajax_deploy_project() {
		check_ajax_referer( 'despliegagit_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'despliegagit' ) ) );
		}

		$id      = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$project = DG_Projects::get( $id );

		if ( ! $project ) {
			wp_send_json_error( array( 'message' => __( 'Proyecto no encontrado.', 'despliegagit' ) ) );
		}

		$type_override = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		$slug_override = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';

		$result = DG_Deployer::deploy( $project, $type_override, $slug_override, 'manual' );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message'    => __( '¡Despliegue completado con éxito!', 'despliegagit' ),
				'result'     => $result,
				'public_url' => $result['public_url'],
			)
		);
	}

	/**
	 * AJAX: Restaura el último backup de un proyecto.
	 */
	public static function ajax_rollback_project() {
		check_ajax_referer( 'despliegagit_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'despliegagit' ) ) );
		}

		$id      = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$project = DG_Projects::get( $id );

		if ( ! $project ) {
			wp_send_json_error( array( 'message' => __( 'Proyecto no encontrado.', 'despliegagit' ) ) );
		}

		$result = DG_Deployer::rollback( $project );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Copia de seguridad restaurada correctamente.', 'despliegagit' ) ) );
	}

	/**
	 * AJAX: Guarda los ajustes globales.
	 */
	public static function ajax_save_settings() {
		check_ajax_referer( 'despliegagit_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'despliegagit' ) ) );
		}

		$token   = isset( $_POST['token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '';
		$keep    = isset( $_POST['keep_backups'] ) ? absint( $_POST['keep_backups'] ) : 3;
		$exclude = isset( $_POST['default_exclude'] ) ? sanitize_textarea_field( wp_unslash( $_POST['default_exclude'] ) ) : '';

		$data = array(
			'keep_backups'    => max( 1, min( 10, $keep ) ),
			'default_exclude' => $exclude,
		);

		if ( ! empty( $token ) ) {
			$data['token'] = DG_Crypto::encrypt( $token );
		} elseif ( isset( $_POST['clear_token'] ) && '1' === $_POST['clear_token'] ) {
			$data['token'] = '';
		}

		DG_Settings::save( $data );

		wp_send_json_success( array( 'message' => __( 'Ajustes guardados correctamente.', 'despliegagit' ) ) );
	}

	/**
	 * Renderiza la pantalla principal / Dashboard de proyectos.
	 */
	public static function render_dashboard() {
		$projects = DG_Projects::all();
		$types    = DG_Projects::types();
		include DESPLIEGAGIT_DIR . 'views/dashboard.php';
	}

	/**
	 * Renderiza la pantalla de ajustes globales.
	 */
	public static function render_settings() {
		$settings = DG_Settings::all();
		include DESPLIEGAGIT_DIR . 'views/settings.php';
	}
}

