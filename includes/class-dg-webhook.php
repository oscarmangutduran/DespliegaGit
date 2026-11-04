<?php
/**
 * Receptor de Webhooks de GitHub para sincronización y despliegue automático.
 *
 * @package DespliegaGit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Procesa notificaciones push de GitHub y desencadena el despliegue automático.
 */
class DG_Webhook {

	const REST_NAMESPACE = 'despliegagit/v1';
	const CRON_HOOK      = 'despliegagit_async_deploy';

	/**
	 * Registra rutas REST y eventos programados.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'execute_async_deploy' ), 10, 2 );
	}

	/**
	 * Registra el endpoint REST para webhooks.
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/webhook/(?P<id>[a-z0-9_-]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_webhook' ),
				'permission_callback' => '__return_true', // La validación se hace con HMAC de GitHub.
			)
		);
	}

	/**
	 * Devuelve la URL del webhook para un proyecto determinado.
	 *
	 * @param string $project_id ID del proyecto.
	 * @return string
	 */
	public static function get_webhook_url( $project_id ) {
		return rest_url( self::REST_NAMESPACE . '/webhook/' . $project_id );
	}

	/**
	 * Manejador principal de la petición de webhook entrante de GitHub.
	 *
	 * @param WP_REST_Request $request Petición REST.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_webhook( WP_REST_Request $request ) {
		$project_id = $request->get_param( 'id' );
		$project    = DG_Projects::get( $project_id );

		if ( ! $project ) {
			return new WP_Error( 'dg_project_not_found', __( 'Proyecto no encontrado.', 'despliegagit' ), array( 'status' => 404 ) );
		}

		if ( empty( $project['auto_deploy'] ) ) {
			return new WP_REST_Response( array( 'message' => 'El despliegue automático está desactivado para este proyecto.' ), 200 );
		}

		// Obtener cabeceras de GitHub.
		$event     = $request->get_header( 'x-github-event' );
		$signature = $request->get_header( 'x-hub-signature-256' );
		$payload   = $request->get_body();

		// Si no hay payload o evento.
		if ( empty( $event ) || empty( $payload ) ) {
			return new WP_Error( 'dg_invalid_webhook', __( 'Petición de webhook inválida.', 'despliegagit' ), array( 'status' => 400 ) );
		}

		// Validar firma HMAC si hay un secret configurado.
		if ( ! empty( $project['webhook_secret'] ) ) {
			if ( empty( $signature ) ) {
				return new WP_Error( 'dg_no_signature', __( 'Falta la firma del webhook (X-Hub-Signature-256).', 'despliegagit' ), array( 'status' => 401 ) );
			}

			$expected_signature = 'sha256=' . hash_hmac( 'sha256', $payload, $project['webhook_secret'] );
			if ( ! hash_equals( $expected_signature, $signature ) ) {
				return new WP_Error( 'dg_invalid_signature', __( 'Firma del webhook inválida.', 'despliegagit' ), array( 'status' => 403 ) );
			}
		}

		// Responder al evento de comprobación ("ping") de GitHub.
		if ( 'ping' === $event ) {
			return new WP_REST_Response(
				array(
					'message' => 'Pong! Webhook configurado correctamente en DespliegaGit.',
					'project' => $project['name'],
				),
				200
			);
		}

		// Solo procesamos eventos "push".
		if ( 'push' !== $event ) {
			return new WP_REST_Response( array( 'message' => 'Evento ignorado (solo se atienden eventos push).' ), 200 );
		}

		$data = json_decode( $payload, true );
		if ( ! is_array( $data ) || empty( $data['ref'] ) ) {
			return new WP_Error( 'dg_bad_payload', __( 'Formato de payload no válido.', 'despliegagit' ), array( 'status' => 400 ) );
		}

		// Comprobar que el push corresponda a la rama configurada.
		$pushed_branch     = str_replace( 'refs/heads/', '', $data['ref'] );
		$configured_branch = ! empty( $project['branch'] ) ? $project['branch'] : 'main';

		if ( $pushed_branch !== $configured_branch ) {
			return new WP_REST_Response(
				array(
					'message'           => sprintf( 'Push recibido para rama %s; el proyecto escucha en %s. Ignorado.', $pushed_branch, $configured_branch ),
					'pushed_branch'     => $pushed_branch,
					'configured_branch' => $configured_branch,
				),
				200
			);
		}

		$commit_sha = ! empty( $data['after'] ) ? $data['after'] : $configured_branch;

		// Programar ejecución en segundo plano para no demorar la respuesta a GitHub (timeout de 10s).
		wp_schedule_single_event( time(), self::CRON_HOOK, array( $project_id, $commit_sha ) );

		// Si cron alternativo está habilitado o se prefiere ejecución inmediata si es posible:
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		return new WP_REST_Response(
			array(
				'message' => 'Despliegue programado correctamente con éxito.',
				'project' => $project['name'],
				'commit'  => substr( $commit_sha, 0, 7 ),
			),
			202
		);
	}

	/**
	 * Ejecuta el despliegue en segundo plano vía WP-Cron.
	 *
	 * @param string $project_id ID del proyecto.
	 * @param string $ref        Commit SHA o rama.
	 */
	public static function execute_async_deploy( $project_id, $ref ) {
		$project = DG_Projects::get( $project_id );
		if ( ! $project ) {
			return;
		}

		// 1. Preparar staging.
		$staged = DG_Deployer::stage( $project, $ref );
		if ( is_wp_error( $staged ) ) {
			DG_Projects::add_history(
				$project_id,
				array(
					'time'    => time(),
					'action'  => 'error',
					'commit'  => substr( $ref, 0, 7 ),
					'message' => $staged->get_error_message(),
					'source'  => 'webhook',
				)
			);
			return;
		}

		// 2. Desplegar inmediatamente.
		$project = DG_Projects::get( $project_id );
		$deploy  = DG_Deployer::deploy( $project, '', '', 'webhook' );

		if ( is_wp_error( $deploy ) ) {
			DG_Projects::add_history(
				$project_id,
				array(
					'time'    => time(),
					'action'  => 'error',
					'commit'  => substr( $ref, 0, 7 ),
					'message' => $deploy->get_error_message(),
					'source'  => 'webhook',
				)
			);
		}
	}
}

