<?php
/**
 * Ajustes globales del plugin.
 *
 * @package DespliegaGit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Acceso a la opción despliegagit_settings.
 */
class DG_Settings {

	const OPTION = 'despliegagit_settings';

	/**
	 * Valores por defecto.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'token'           => '',
			'keep_backups'    => 3,
			'default_exclude' => implode(
				"\n",
				array(
					'.git',
					'.github',
					'.gitignore',
					'.gitattributes',
					'.editorconfig',
					'node_modules',
					'.env',
					'.env.*',
					'wp-config.php',
					'.htaccess',
					'.DS_Store',
					'Thumbs.db',
				)
			),
		);
	}

	/**
	 * Devuelve todos los ajustes.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );

		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	/**
	 * Devuelve un ajuste.
	 *
	 * @param string $key Clave.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();

		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Guarda los ajustes.
	 *
	 * @param array $values Valores.
	 */
	public static function save( array $values ) {
		update_option( self::OPTION, wp_parse_args( $values, self::all() ), false );
	}

	/**
	 * Token global descifrado.
	 *
	 * @return string
	 */
	public static function token() {
		return DG_Crypto::decrypt( self::get( 'token' ) );
	}
}

