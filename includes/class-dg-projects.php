<?php
/**
 * Almacenamiento de proyectos (repositorio + destino).
 *
 * @package DespliegaGit
 */

defined( 'ABSPATH' ) || exit;

/**
 * CRUD de proyectos guardados en la opción despliegagit_projects.
 */
class DG_Projects {

	const OPTION      = 'despliegagit_projects';
	const MAX_HISTORY = 25;

	/**
	 * Tipos de despliegue admitidos.
	 *
	 * @return array
	 */
	public static function types() {
		return array(
			'auto'   => __( 'Detectar automáticamente', 'despliegagit' ),
			'theme'  => __( 'Tema de WordPress', 'despliegagit' ),
			'plugin' => __( 'Plugin de WordPress', 'despliegagit' ),
			'static' => __( 'Sitio estático / componente (HTML, CSS, JS)', 'despliegagit' ),
		);
	}

	/**
	 * Slugs que no pueden usarse para sitios estáticos.
	 *
	 * @return array
	 */
	public static function reserved_slugs() {
		return array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'wp-login', 'wp-login-php', 'xmlrpc', 'feed', 'comments', 'page', 'category', 'tag', 'author', 'search', 'embed', 'trackback', 'despliegagit-preview' );
	}

	/**
	 * Estructura por defecto de un proyecto.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'id'             => '',
			'name'           => '',
			'repo_url'       => '',
			'owner'          => '',
			'repo'           => '',
			'branch'         => '',
			'token'          => '',
			'type'           => 'auto',
			'slug'           => '',
			'subdir'         => '',
			'exclude'        => DG_Settings::get( 'default_exclude' ),
			'preserve'       => '',
			'auto_deploy'    => false,
			'webhook_secret' => '',
			'spa_fallback'   => false,
			'status'         => 'draft',
			'staged'         => null,
			'deployed_type'  => '',
			'deployed_slug'  => '',
			'last_commit'    => null,
			'last_deployed'  => 0,
			'backups'        => array(),
			'history'        => array(),
			'created'        => 0,
		);
	}

	/**
	 * Todos los proyectos.
	 *
	 * @return array
	 */
	public static function all() {
		$projects = get_option( self::OPTION, array() );
		if ( ! is_array( $projects ) ) {
			return array();
		}

		foreach ( $projects as $id => $project ) {
			$projects[ $id ] = wp_parse_args( $project, self::defaults() );
		}

		return $projects;
	}

	/**
	 * Un proyecto por ID.
	 *
	 * @param string $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		$all = self::all();

		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Crea un proyecto.
	 *
	 * @param array $data Datos.
	 * @return string ID.
	 */
	public static function insert( array $data ) {
		$all = self::all();

		do {
			$id = strtolower( wp_generate_password( 10, false, false ) );
		} while ( isset( $all[ $id ] ) );

		$project                   = wp_parse_args( $data, self::defaults() );
		$project['id']             = $id;
		$project['created']        = time();
		$project['webhook_secret'] = wp_generate_password( 40, false, false );

		$all[ $id ] = $project;
		update_option( self::OPTION, $all, false );

		return $id;
	}

	/**
	 * Actualiza campos de un proyecto.
	 *
	 * @param string $id      ID.
	 * @param array  $changes Cambios.
	 * @return bool
	 */
	public static function update( $id, array $changes ) {
		$all = self::all();
		if ( ! isset( $all[ $id ] ) ) {
			return false;
		}

		$all[ $id ] = array_merge( $all[ $id ], $changes );
		update_option( self::OPTION, $all, false );

		return true;
	}

	/**
	 * Elimina un proyecto.
	 *
	 * @param string $id ID.
	 */
	public static function delete( $id ) {
		$all = self::all();
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Añade una entrada al historial.
	 *
	 * @param string $id    ID.
	 * @param array  $entry Entrada.
	 */
	public static function add_history( $id, array $entry ) {
		$project = self::get( $id );
		if ( ! $project ) {
			return;
		}

		$entry = wp_parse_args(
			$entry,
			array(
				'time'    => time(),
				'action'  => 'deploy',
				'commit'  => '',
				'message' => '',
				'source'  => 'manual',
				'user'    => get_current_user_id(),
			)
		);

		$history = (array) $project['history'];
		array_unshift( $history, $entry );

		self::update( $id, array( 'history' => array_slice( $history, 0, self::MAX_HISTORY ) ) );
	}

	/**
	 * Token efectivo de un proyecto (propio o global).
	 *
	 * @param array $project Proyecto.
	 * @return string
	 */
	public static function token_for( array $project ) {
		$token = DG_Crypto::decrypt( $project['token'] );

		return '' !== $token ? $token : DG_Settings::token();
	}

	/**
	 * Convierte un textarea de patrones en un array.
	 *
	 * @param string $text Texto.
	 * @return array
	 */
	public static function patterns( $text ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $text );
		$out   = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			$out[] = $line;
		}

		return $out;
	}

	/**
	 * Normaliza un slug de carpeta destino.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	public static function sanitize_slug( $slug ) {
		$slug = sanitize_title( (string) $slug );

		return (string) preg_replace( '/[^a-z0-9_-]/', '', $slug );
	}

	/**
	 * Busca otro proyecto que use el mismo destino.
	 *
	 * @param string $type       Tipo.
	 * @param string $slug       Slug.
	 * @param string $exclude_id ID a ignorar.
	 * @return array|null
	 */
	public static function find_conflict( $type, $slug, $exclude_id = '' ) {
		foreach ( self::all() as $project ) {
			if ( $project['id'] === $exclude_id || $project['slug'] !== $slug ) {
				continue;
			}
			$other_type = $project['deployed_type'] ? $project['deployed_type'] : $project['type'];
			if ( $other_type === $type || 'auto' === $type || 'auto' === $other_type ) {
				return $project;
			}
		}

		return null;
	}
}

