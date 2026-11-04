<?php
/**
 * Cliente mínimo de la API REST de GitHub.
 *
 * @package DespliegaGit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Habla con api.github.com usando la HTTP API de WordPress.
 */
class DG_GitHub {

	const API = 'https://api.github.com';

	/**
	 * Personal Access Token.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Constructor.
	 *
	 * @param string $token Token opcional.
	 */
	public function __construct( $token = '' ) {
		$this->token = trim( (string) $token );
	}

	/**
	 * Interpreta cualquier formato habitual de URL de GitHub.
	 *
	 * Admite https://github.com/u/r, .git, /tree/rama/subcarpeta, git@github.com:u/r.git y u/r.
	 *
	 * @param string $url URL.
	 * @return array|WP_Error
	 */
	public static function parse_repo_url( $url ) {
		$url    = trim( (string) $url );
		$url    = preg_replace( '#[?\#].*$#', '', $url );
		$branch = '';
		$subdir = '';

		if ( preg_match( '#^git@github\.com:([\w.-]+)/([\w.-]+?)(?:\.git)?/?$#i', $url, $m ) ) {
			$owner = $m[1];
			$repo  = $m[2];
		} elseif ( preg_match( '#^(?:https?://)?(?:www\.)?github\.com/([\w.-]+)/([\w.-]+?)(?:\.git)?(?:/(?:tree|blob)/([^/]+)(?:/(.+?))?)?/?$#i', $url, $m ) ) {
			$owner  = $m[1];
			$repo   = $m[2];
			$branch = isset( $m[3] ) ? rawurldecode( $m[3] ) : '';
			$subdir = isset( $m[4] ) ? rawurldecode( $m[4] ) : '';
		} elseif ( preg_match( '#^([\w.-]+)/([\w.-]+?)(?:\.git)?$#', $url, $m ) ) {
			$owner = $m[1];
			$repo  = $m[2];
		} else {
			return new WP_Error( 'dg_bad_url', __( 'La URL no parece un repositorio de GitHub válido. Ejemplo: https://github.com/usuario/repositorio', 'despliegagit' ) );
		}

		return array(
			'owner'  => $owner,
			'repo'   => $repo,
			'branch' => $branch,
			'subdir' => $subdir,
			'url'    => 'https://github.com/' . $owner . '/' . $repo,
		);
	}

	/**
	 * Codifica una referencia (rama/tag/sha) para usarla en una ruta.
	 *
	 * @param string $ref Referencia.
	 * @return string
	 */
	private static function encode_ref( $ref ) {
		return implode( '/', array_map( 'rawurlencode', explode( '/', (string) $ref ) ) );
	}

	/**
	 * Ruta base de un repositorio.
	 *
	 * @param string $owner Propietario.
	 * @param string $repo  Repositorio.
	 * @return string
	 */
	private static function repo_path( $owner, $repo ) {
		return '/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo );
	}

	/**
	 * Cabeceras comunes.
	 *
	 * @return array
	 */
	private function headers() {
		$headers = array(
			'Accept'               => 'application/vnd.github+json',
			'User-Agent'           => 'DespliegaGit/' . DESPLIEGAGIT_VERSION . '; ' . home_url( '/' ),
			'X-GitHub-Api-Version' => '2022-11-28',
		);

		if ( '' !== $this->token ) {
			$headers['Authorization'] = 'Bearer ' . $this->token;
		}

		return $headers;
	}

	/**
	 * Petición GET a la API.
	 *
	 * @param string $path  Ruta.
	 * @param array  $query Parámetros.
	 * @return array|WP_Error
	 */
	private function request( $path, array $query = array() ) {
		$url = self::API . $path;
		if ( $query ) {
			$url = add_query_arg( $query, $url );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => $this->headers(),
			)
		);

		if ( is_wp_error( $response ) ) {
			/* translators: %s: mensaje de error. */
			return new WP_Error( 'dg_http', sprintf( __( 'No se pudo conectar con GitHub: %s', 'despliegagit' ), $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 ) {
			return is_array( $body ) ? $body : array();
		}

		return $this->error_from_response( $code, $response, $body );
	}

	/**
	 * Traduce errores HTTP de GitHub a mensajes comprensibles.
	 *
	 * @param int   $code     Código HTTP.
	 * @param array $response Respuesta.
	 * @param mixed $body     Cuerpo decodificado.
	 * @return WP_Error
	 */
	private function error_from_response( $code, $response, $body ) {
		$api_message = is_array( $body ) && ! empty( $body['message'] ) ? $body['message'] : '';

		switch ( $code ) {
			case 401:
				$message = __( 'El token de acceso no es válido o ha caducado.', 'despliegagit' );
				break;
			case 403:
			case 429:
				if ( '0' === (string) wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' ) ) {
					$reset   = (int) wp_remote_retrieve_header( $response, 'x-ratelimit-reset' );
					$message = sprintf(
						/* translators: %s: hora de reinicio. */
						__( 'Se ha superado el límite de peticiones a GitHub. Vuelve a intentarlo a las %s o añade un token para ampliar el límite.', 'despliegagit' ),
						$reset ? wp_date( get_option( 'time_format' ), $reset ) : '—'
					);
				} else {
					$message = __( 'GitHub ha denegado el acceso. Comprueba que el token tenga permiso de lectura de contenidos (Contents: Read) sobre este repositorio.', 'despliegagit' );
				}
				break;
			case 404:
				$message = '' !== $this->token
					? __( 'No se encontró el repositorio o la rama. Revisa la URL, el nombre de la rama y que el token tenga acceso al repositorio.', 'despliegagit' )
					: __( 'No se encontró el repositorio o la rama. Si el repositorio es privado, añade un Personal Access Token.', 'despliegagit' );
				break;
			case 409:
				$message = __( 'El repositorio está vacío: todavía no tiene commits.', 'despliegagit' );
				break;
			default:
				/* translators: 1: código HTTP, 2: mensaje. */
				$message = sprintf( __( 'GitHub respondió con el error %1$d. %2$s', 'despliegagit' ), $code, $api_message );
		}

		return new WP_Error( 'dg_github_' . $code, $message, array( 'status' => $code ) );
	}

	/**
	 * Información del repositorio.
	 *
	 * @param string $owner Propietario.
	 * @param string $repo  Repositorio.
	 * @return array|WP_Error
	 */
	public function get_repo( $owner, $repo ) {
		return $this->request( self::repo_path( $owner, $repo ) );
	}

	/**
	 * Lista de ramas (hasta 300).
	 *
	 * @param string $owner Propietario.
	 * @param string $repo  Repositorio.
	 * @return array|WP_Error
	 */
	public function get_branches( $owner, $repo ) {
		$names = array();

		for ( $page = 1; $page <= 3; $page++ ) {
			$data = $this->request(
				self::repo_path( $owner, $repo ) . '/branches',
				array(
					'per_page' => 100,
					'page'     => $page,
				)
			);

			if ( is_wp_error( $data ) ) {
				return $page > 1 ? $names : $data;
			}

			foreach ( $data as $branch ) {
				if ( isset( $branch['name'] ) ) {
					$names[] = $branch['name'];
				}
			}

			if ( count( $data ) < 100 ) {
				break;
			}
		}

		return $names;
	}

	/**
	 * Último commit de una referencia.
	 *
	 * @param string $owner Propietario.
	 * @param string $repo  Repositorio.
	 * @param string $ref   Rama, tag o SHA.
	 * @return array|WP_Error
	 */
	public function get_commit( $owner, $repo, $ref ) {
		$data = $this->request( self::repo_path( $owner, $repo ) . '/commits/' . self::encode_ref( $ref ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( empty( $data['sha'] ) ) {
			return new WP_Error( 'dg_no_commit', __( 'No se pudo obtener el último commit de la rama.', 'despliegagit' ) );
		}

		$message = isset( $data['commit']['message'] ) ? strtok( $data['commit']['message'], "\n" ) : '';

		return array(
			'sha'     => $data['sha'],
			'message' => (string) $message,
			'author'  => isset( $data['commit']['author']['name'] ) ? $data['commit']['author']['name'] : '',
			'date'    => isset( $data['commit']['author']['date'] ) ? $data['commit']['author']['date'] : '',
			'url'     => isset( $data['html_url'] ) ? $data['html_url'] : '',
		);
	}

	/**
	 * Descarga el zipball de una referencia a un archivo local.
	 *
	 * @param string $owner Propietario.
	 * @param string $repo  Repositorio.
	 * @param string $ref   Referencia (idealmente un SHA).
	 * @param string $dest  Archivo de destino.
	 * @return true|WP_Error
	 */
	public function download_zip( $owner, $repo, $ref, $dest ) {
		$response = wp_remote_get(
			self::API . self::repo_path( $owner, $repo ) . '/zipball/' . self::encode_ref( $ref ),
			array(
				'timeout'     => 300,
				'stream'      => true,
				'filename'    => $dest,
				'redirection' => 5,
				'headers'     => $this->headers(),
			)
		);

		if ( is_wp_error( $response ) ) {
			/* translators: %s: mensaje de error. */
			return new WP_Error( 'dg_download', sprintf( __( 'Error al descargar el repositorio: %s', 'despliegagit' ), $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$body = file_exists( $dest ) ? json_decode( (string) file_get_contents( $dest ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			return $this->error_from_response( $code, $response, $body );
		}

		if ( ! file_exists( $dest ) || 0 === filesize( $dest ) ) {
			return new WP_Error( 'dg_empty_zip', __( 'GitHub devolvió un archivo vacío.', 'despliegagit' ) );
		}

		return true;
	}
}

