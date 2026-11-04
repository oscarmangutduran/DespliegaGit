<?php
/**
 * Cifrado simétrico de los tokens de GitHub guardados en la base de datos.
 *
 * @package DespliegaGit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cifra/descifra secretos usando las claves de wp-config.php.
 */
class DG_Crypto {

	/**
	 * Clave derivada de las sales de WordPress.
	 *
	 * @return string
	 */
	private static function key() {
		$material  = defined( 'AUTH_KEY' ) ? AUTH_KEY : '';
		$material .= defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '';
		$material .= 'despliegagit';

		return hash( 'sha256', $material, true );
	}

	/**
	 * Cifra un texto.
	 *
	 * @param string $plain Texto plano.
	 * @return string
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}

		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return 'b64:' . base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plain, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );
		$mac    = hash_hmac( 'sha256', $iv . $cipher, self::key(), true );

		return 'v1:' . base64_encode( $iv . $mac . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Descifra un texto previamente cifrado con encrypt().
	 *
	 * @param string $stored Valor guardado.
	 * @return string
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return '';
		}

		if ( 0 === strpos( $stored, 'b64:' ) ) {
			return (string) base64_decode( substr( $stored, 4 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		}

		if ( 0 !== strpos( $stored, 'v1:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		$raw = base64_decode( substr( $stored, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) < 49 ) {
			return '';
		}

		$iv     = substr( $raw, 0, 16 );
		$mac    = substr( $raw, 16, 32 );
		$cipher = substr( $raw, 48 );

		if ( ! hash_equals( hash_hmac( 'sha256', $iv . $cipher, self::key(), true ), $mac ) ) {
			return '';
		}

		$plain = openssl_decrypt( $cipher, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );

		return false === $plain ? '' : $plain;
	}
}

