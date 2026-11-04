<?php
/**
 * Motor de despliegue, previsualización, detección de proyectos y rollback.
 *
 * @package DespliegaGit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Gestiona la descarga, extracción, staging, backups y publicación final.
 */
class DG_Deployer {

	/**
	 * Devuelve las rutas base de trabajo del plugin dentro de wp-content/uploads/despliegagit/.
	 *
	 * @return array
	 */
	public static function paths() {
		$upload_dir = wp_upload_dir();
		$base       = trailingslashit( $upload_dir['basedir'] ) . 'despliegagit';
		$base_url   = trailingslashit( $upload_dir['baseurl'] ) . 'despliegagit';

		return array(
			'base'      => $base,
			'base_url'  => $base_url,
			'tmp'       => $base . '/tmp',
			'staging'   => $base . '/staging',
			'sites'     => $base . '/sites',
			'sites_url' => $base_url . '/sites',
			'backups'   => $base . '/backups',
		);
	}

	/**
	 * Crea las carpetas de trabajo con protección index.php / .htaccess.
	 */
	public static function ensure_dirs() {
		$paths = self::paths();
		foreach ( array( $paths['base'], $paths['tmp'], $paths['staging'], $paths['sites'], $paths['backups'] ) as $dir ) {
			if ( ! file_exists( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			$index_file = trailingslashit( $dir ) . 'index.php';
			if ( ! file_exists( $index_file ) ) {
				file_put_contents( $index_file, '<?php // Silence is golden.' );
			}
		}

		// Proteger directorio de backups y tmp contra acceso directo.
		foreach ( array( $paths['backups'], $paths['tmp'] ) as $private_dir ) {
			$htaccess = trailingslashit( $private_dir ) . '.htaccess';
			if ( ! file_exists( $htaccess ) ) {
				file_put_contents( $htaccess, "Deny from all\n" );
			}
		}
	}

	/**
	 * Clave del transient para almacenar el progreso de importación.
	 *
	 * @param string $project_id ID del proyecto.
	 * @return string
	 */
	public static function progress_key( $project_id ) {
		return 'dg_prog_' . sanitize_key( $project_id );
	}

	/**
	 * Actualiza el progreso de una importación / preparación.
	 *
	 * @param string $project_id ID del proyecto.
	 * @param int    $percent    Porcentaje (0-100).
	 * @param string $step       Identificador del paso.
	 * @param string $message    Mensaje descriptivo.
	 */
	public static function set_progress( $project_id, $percent, $step, $message ) {
		set_transient(
			self::progress_key( $project_id ),
			array(
				'percent' => max( 0, min( 100, (int) $percent ) ),
				'step'    => sanitize_key( $step ),
				'message' => sanitize_text_field( $message ),
				'time'    => time(),
			),
			120
		);
	}

	/**
	 * Obtiene el estado actual del progreso.
	 *
	 * @param string $project_id ID del proyecto.
	 * @return array
	 */
	public static function get_progress( $project_id ) {
		$data = get_transient( self::progress_key( $project_id ) );
		if ( ! is_array( $data ) ) {
			return array(
				'percent' => 5,
				'step'    => 'init',
				'message' => __( 'Iniciando proceso de importación...', 'despliegagit' ),
			);
		}
		return $data;
	}

	/**
	 * Limpia el progreso guardado.
	 *
	 * @param string $project_id ID del proyecto.
	 */
	public static function clear_progress( $project_id ) {
		delete_transient( self::progress_key( $project_id ) );
	}

	/**
	 * Descarga el zip del repositorio en staging y analiza su estructura a máxima velocidad.
	 *
	 * Optimizaciones aplicadas:
	 * - Descarga directa del zipball sin esperas previas innecesarias.
	 * - Detección del SHA del commit directamente de las cabeceras/raíz del ZIP.
	 * - Extracción y análisis en un único pase (un solo recorrido en memoria).
	 * - Omisión de archivos excluidos (.git, node_modules) antes de escribirlos en disco.
	 * - Sin doble lectura ni movimientos de carpetas en el sistema de archivos.
	 *
	 * @param array  $project Datos del proyecto.
	 * @param string $ref     Rama o commit a descargar.
	 * @return array|WP_Error Resultado de la preparación / staging.
	 */
	public static function stage( array $project, $ref = '' ) {
		self::ensure_dirs();

		if ( empty( $ref ) ) {
			$ref = ! empty( $project['branch'] ) ? $project['branch'] : 'main';
		}

		$paths     = self::paths();
		$stage_dir = $paths['staging'] . '/' . $project['id'];
		$tmp_zip   = $paths['tmp'] . '/' . $project['id'] . '-' . time() . '.zip';

		self::set_progress(
			$project['id'],
			20,
			'connect',
			__( 'Conectando con GitHub y preparando descarga...', 'despliegagit' )
		);

		$token  = DG_Projects::token_for( $project );
		$github = new DG_GitHub( $token );

		self::set_progress(
			$project['id'],
			45,
			'download',
			__( 'Descargando paquete comprimido del repositorio (.zip)...', 'despliegagit' )
		);

		// Descarga directa a alta velocidad mediante streaming cURL.
		$downloaded = $github->download_zip( $project['owner'], $project['repo'], $ref, $tmp_zip );
		if ( is_wp_error( $downloaded ) ) {
			self::clear_progress( $project['id'] );
			return $downloaded;
		}

		self::set_progress(
			$project['id'],
			75,
			'unzip',
			__( 'Extrayendo archivos y analizando componentes en memoria...', 'despliegagit' )
		);

		// Limpiar staging anterior del proyecto.
		self::rmdir_recursive( $stage_dir );
		wp_mkdir_p( $stage_dir );

		// Extracción optimizada de un solo paso: filtra exclusiones, subdirectorios y calcula estadísticas.
		$inspect_result = self::fast_unzip_and_inspect( $tmp_zip, $stage_dir, $project );
		@unlink( $tmp_zip );

		if ( is_wp_error( $inspect_result ) ) {
			self::clear_progress( $project['id'] );
			return $inspect_result;
		}

		self::set_progress(
			$project['id'],
			90,
			'inspect',
			__( 'Obteniendo detalles del commit y preparando previsualización...', 'despliegagit' )
		);

		$detected_sha = ! empty( $inspect_result['sha'] ) ? $inspect_result['sha'] : $ref;

		// Obtener información del commit (o generar una estructura válida rápida si la API no responde).
		$commit_info = array(
			'sha'     => $detected_sha,
			'message' => sprintf( __( 'Despliegue de %s (#%s)', 'despliegagit' ), $ref, substr( $detected_sha, 0, 7 ) ),
			'author'  => '',
			'date'    => current_time( 'mysql' ),
			'url'     => 'https://github.com/' . $project['owner'] . '/' . $project['repo'] . '/commit/' . $detected_sha,
		);

		$commit_fetch = $github->get_commit( $project['owner'], $project['repo'], $detected_sha );
		if ( ! is_wp_error( $commit_fetch ) && ! empty( $commit_fetch['sha'] ) ) {
			$commit_info = $commit_fetch;
		}

		$type = $project['type'];
		if ( 'auto' === $type || empty( $type ) ) {
			$type = $inspect_result['detected_type'];
		}

		$stage_data = array(
			'stage_id'       => $project['id'] . '-' . substr( md5( $detected_sha . time() ), 0, 8 ),
			'timestamp'      => time(),
			'ref'            => $ref,
			'commit'         => $commit_info,
			'detected_type'  => $inspect_result['detected_type'],
			'effective_type' => $type,
			'suggested_slug' => ! empty( $project['slug'] ) ? $project['slug'] : $inspect_result['suggested_slug'],
			'file_count'     => $inspect_result['file_count'],
			'total_bytes'    => $inspect_result['total_bytes'],
			'has_index_html' => $inspect_result['has_index_html'],
			'has_style_css'  => $inspect_result['has_style_css'],
			'has_plugin_php' => $inspect_result['has_plugin_php'],
			'files'          => $inspect_result['files'],
		);

		DG_Projects::update(
			$project['id'],
			array(
				'staged' => $stage_data,
				'status' => 'staged',
			)
		);

		self::set_progress(
			$project['id'],
			100,
			'ready',
			__( '¡Importación completada! Abriendo previsualización...', 'despliegagit' )
		);

		return $stage_data;
	}

	/**
	 * Extrae y analiza un ZIP de GitHub en un solo paso ultrarrápido.
	 *
	 * @param string $zip_path Archivo zip.
	 * @param string $dest_dir Directorio destino para staging.
	 * @param array  $project  Configuración del proyecto.
	 * @return array|WP_Error
	 */
	public static function fast_unzip_and_inspect( $zip_path, $dest_dir, array $project ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return self::unzip( $zip_path, $dest_dir );
		}

		$zip = new ZipArchive();
		$res = $zip->open( $zip_path );
		if ( true !== $res ) {
			return new WP_Error( 'dg_zip_open', __( 'No se pudo abrir el archivo ZIP descargado.', 'despliegagit' ) );
		}

		$num_files = $zip->numFiles;
		if ( 0 === $num_files ) {
			$zip->close();
			return new WP_Error( 'dg_zip_empty', __( 'El archivo ZIP está vacío.', 'despliegagit' ) );
		}

		// 1. Detectar el prefijo raíz (GitHub siempre crea owner-repo-sha/).
		$root_prefix = '';
		for ( $i = 0; $i < min( 20, $num_files ); $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( false !== strpos( $name, '/' ) ) {
				$parts       = explode( '/', $name );
				$root_prefix = $parts[0] . '/';
				break;
			}
		}

		// Extraer el commit SHA del nombre de la carpeta raíz si coincide.
		$detected_sha = '';
		if ( ! empty( $root_prefix ) && preg_match( '/-([0-9a-f]{7,40})\/$/i', $root_prefix, $matches ) ) {
			$detected_sha = $matches[1];
		}

		// 2. Prefijo efectivo según si hay subdirectorio configurado (ej: dist/, build/).
		$effective_prefix = $root_prefix;
		if ( ! empty( $project['subdir'] ) ) {
			$sub = trim( str_replace( '\\', '/', $project['subdir'] ), '/' );
			if ( '' !== $sub ) {
				$effective_prefix .= $sub . '/';
			}
		}

		$exclude_patterns = DG_Projects::patterns( $project['exclude'] );

		// Métricas e inspección simultáneas.
		$file_count     = 0;
		$total_bytes    = 0;
		$has_index_html = false;
		$has_style_css  = false;
		$has_plugin_php = false;
		$is_theme       = false;
		$files_tree     = array();

		for ( $i = 0; $i < $num_files; $i++ ) {
			$entry_name = $zip->getNameIndex( $i );

			// Protección Zip Slip.
			if ( false !== strpos( $entry_name, '../' ) || false !== strpos( $entry_name, '..\\' ) ) {
				$zip->close();
				return new WP_Error( 'dg_zip_slip', __( 'El archivo ZIP contiene rutas relativas inseguras.', 'despliegagit' ) );
			}

			// Solo procesar archivos dentro del prefijo efectivo.
			if ( '' !== $effective_prefix && 0 !== strpos( $entry_name, $effective_prefix ) ) {
				continue;
			}

			$rel_path = substr( $entry_name, strlen( $effective_prefix ) );
			if ( '' === $rel_path || '/' === $rel_path ) {
				continue;
			}

			$is_dir = ( '/' === substr( $rel_path, -1 ) );
			$clean_rel_path = rtrim( $rel_path, '/' );

			// Comprobar exclusiones.
			if ( self::matches_patterns( $clean_rel_path, $exclude_patterns ) ) {
				continue;
			}

			$target_path = $dest_dir . '/' . $clean_rel_path;

			if ( $is_dir ) {
				wp_mkdir_p( $target_path );
				if ( count( $files_tree ) < 100 ) {
					$files_tree[] = array(
						'path' => $clean_rel_path,
						'type' => 'dir',
						'size' => 0,
					);
				}
				continue;
			}

			// Es un archivo:
			$stat = $zip->statIndex( $i );
			$size = isset( $stat['size'] ) ? (int) $stat['size'] : 0;

			$file_count++;
			$total_bytes += $size;

			// Crear directorio contenedor en disco.
			wp_mkdir_p( dirname( $target_path ) );

			// Extraer con stream de alto rendimiento.
			$fp_in  = $zip->getStream( $entry_name );
			$fp_out = @fopen( $target_path, 'wb' );
			if ( $fp_in && $fp_out ) {
				stream_copy_to_stream( $fp_in, $fp_out );
				fclose( $fp_in );
				fclose( $fp_out );
			}

			// Detección de tipo en los archivos clave del nivel superior.
			$basename = basename( $clean_rel_path );
			$dirname  = dirname( $clean_rel_path );

			if ( '.' === $dirname || '' === $dirname ) {
				if ( 'index.html' === $basename || 'index.htm' === $basename ) {
					$has_index_html = true;
				} elseif ( 'style.css' === $basename ) {
					$has_style_css = true;
					$content       = (string) file_get_contents( $target_path, false, null, 0, 4096 );
					if ( preg_match( '/Theme Name\s*:/i', $content ) ) {
						$is_theme = true;
					}
				} elseif ( 'php' === pathinfo( $basename, PATHINFO_EXTENSION ) && ! $is_theme ) {
					$content = (string) file_get_contents( $target_path, false, null, 0, 4096 );
					if ( preg_match( '/Plugin Name\s*:/i', $content ) ) {
						$has_plugin_php = true;
					}
				}
			}

			if ( count( $files_tree ) < 100 ) {
				$files_tree[] = array(
					'path' => $clean_rel_path,
					'type' => 'file',
					'size' => $size,
				);
			}
		}

		$zip->close();

		$detected = 'static';
		if ( $is_theme ) {
			$detected = 'theme';
		} elseif ( $has_plugin_php ) {
			$detected = 'plugin';
		} elseif ( $has_index_html ) {
			$detected = 'static';
		}

		return array(
			'sha'            => $detected_sha,
			'detected_type'  => $detected,
			'suggested_slug' => DG_Projects::sanitize_slug( $project['repo'] ),
			'has_index_html' => $has_index_html,
			'has_style_css'  => $has_style_css,
			'has_plugin_php' => $has_plugin_php,
			'file_count'     => $file_count,
			'total_bytes'    => $total_bytes,
			'files'          => $files_tree,
		);
	}

	/**
	 * Publica definitivamente el staging en su ubicación final.
	 *
	 * @param array       $project     Proyecto.
	 * @param string      $type_override Tipo forzado opcional.
	 * @param string      $slug_override Slug forzado opcional.
	 * @param string|null $source      Origen ('manual', 'webhook', etc.).
	 * @return array|WP_Error
	 */
	public static function deploy( array $project, $type_override = '', $slug_override = '', $source = 'manual' ) {
		$staged = $project['staged'];
		if ( empty( $staged ) || ! is_array( $staged ) ) {
			// Si no está staged, intentamos hacer staging primero.
			$staged = self::stage( $project );
			if ( is_wp_error( $staged ) ) {
				return $staged;
			}
			$project = DG_Projects::get( $project['id'] );
		}

		$paths     = self::paths();
		$stage_dir = $paths['staging'] . '/' . $project['id'];
		if ( ! empty( $project['subdir'] ) ) {
			$sub = trailingslashit( $stage_dir ) . trim( $project['subdir'], '/\\' );
			if ( is_dir( $sub ) ) {
				$stage_dir = $sub;
			}
		}

		if ( ! is_dir( $stage_dir ) ) {
			return new WP_Error( 'dg_stage_missing', __( 'No se encontró la carpeta en preparación. Vuelve a importar el repositorio.', 'despliegagit' ) );
		}

		$type = ! empty( $type_override ) ? $type_override : ( ! empty( $project['type'] ) && 'auto' !== $project['type'] ? $project['type'] : $staged['effective_type'] );
		$slug = ! empty( $slug_override ) ? DG_Projects::sanitize_slug( $slug_override ) : ( ! empty( $project['slug'] ) ? $project['slug'] : $staged['suggested_slug'] );

		if ( empty( $slug ) ) {
			$slug = DG_Projects::sanitize_slug( $project['repo'] );
		}

		// Validar slug si es tipo estático contra rutas reservadas.
		if ( 'static' === $type && in_array( $slug, DG_Projects::reserved_slugs(), true ) ) {
			return new WP_Error( 'dg_slug_reserved', sprintf( __( 'El slug "%s" está reservado por WordPress. Elige otro nombre.', 'despliegagit' ), $slug ) );
		}

		// Determinar la carpeta de destino final.
		$target_dir = self::resolve_target_dir( $type, $slug );
		if ( is_wp_error( $target_dir ) ) {
			return $target_dir;
		}

		// Crear copia de seguridad previa si el destino ya existía.
		$backup_file = '';
		if ( is_dir( $target_dir ) ) {
			$backup_file = self::create_backup( $project['id'], $target_dir );
		}

		// Lista de exclusiones y preservaciones.
		$exclude_patterns  = DG_Projects::patterns( $project['exclude'] );
		$preserve_patterns = DG_Projects::patterns( $project['preserve'] );

		// Realizar el despliegue copiando archivos.
		$deploy_res = self::sync_directories( $stage_dir, $target_dir, $exclude_patterns, $preserve_patterns );
		if ( is_wp_error( $deploy_res ) ) {
			// Si falla, intentamos restaurar el backup si existía.
			if ( ! empty( $backup_file ) && file_exists( $backup_file ) ) {
				self::restore_backup_file( $backup_file, $target_dir );
			}
			return $deploy_res;
		}

		// Limpiar el staging tras el despliegue exitoso.
		self::rmdir_recursive( $paths['staging'] . '/' . $project['id'] );

		// Actualizar metadatos del proyecto.
		$backups = (array) $project['backups'];
		if ( ! empty( $backup_file ) ) {
			array_unshift(
				$backups,
				array(
					'file'      => basename( $backup_file ),
					'timestamp' => time(),
					'commit'    => ! empty( $staged['commit']['sha'] ) ? substr( $staged['commit']['sha'], 0, 7 ) : '',
				)
			);
			// Rotación según keep_backups.
			$keep = (int) DG_Settings::get( 'keep_backups' );
			if ( count( $backups ) > $keep ) {
				$removed = array_splice( $backups, $keep );
				foreach ( $removed as $rem ) {
					@unlink( $paths['backups'] . '/' . $rem['file'] );
				}
			}
		}

		$public_url = self::get_public_url( $type, $slug );

		DG_Projects::update(
			$project['id'],
			array(
				'status'        => 'deployed',
				'staged'        => null,
				'deployed_type' => $type,
				'deployed_slug' => $slug,
				'last_commit'   => $staged['commit'],
				'last_deployed' => time(),
				'backups'       => $backups,
			)
		);

		// Registrar en historial.
		DG_Projects::add_history(
			$project['id'],
			array(
				'time'    => time(),
				'action'  => 'deploy',
				'commit'  => ! empty( $staged['commit']['sha'] ) ? substr( $staged['commit']['sha'], 0, 7 ) : '',
				'message' => ! empty( $staged['commit']['message'] ) ? $staged['commit']['message'] : '',
				'source'  => $source,
			)
		);

		// Refrescar reglas si es un sitio estático nuevo.
		if ( 'static' === $type ) {
			flush_rewrite_rules( false );
		}

		return array(
			'success'    => true,
			'type'       => $type,
			'slug'       => $slug,
			'target_dir' => $target_dir,
			'public_url' => $public_url,
		);
	}

	/**
	 * Restaura el último backup de un proyecto.
	 *
	 * @param array $project Proyecto.
	 * @return true|WP_Error
	 */
	public static function rollback( array $project ) {
		if ( empty( $project['backups'] ) ) {
			return new WP_Error( 'dg_no_backup', __( 'Este proyecto no dispone de copias de seguridad anteriores.', 'despliegagit' ) );
		}

		$paths        = self::paths();
		$latest       = $project['backups'][0];
		$backup_path  = $paths['backups'] . '/' . $latest['file'];

		if ( ! file_exists( $backup_path ) ) {
			return new WP_Error( 'dg_backup_missing', __( 'El archivo de copia de seguridad no se encuentra en el servidor.', 'despliegagit' ) );
		}

		$type = ! empty( $project['deployed_type'] ) ? $project['deployed_type'] : $project['type'];
		$slug = ! empty( $project['deployed_slug'] ) ? $project['deployed_slug'] : $project['slug'];
		$target_dir = self::resolve_target_dir( $type, $slug );

		if ( is_wp_error( $target_dir ) ) {
			return $target_dir;
		}

		// Reemplazar destino con el contenido del backup.
		self::rmdir_recursive( $target_dir );
		wp_mkdir_p( $target_dir );

		$res = self::unzip( $backup_path, $target_dir );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		// Registrar rollback en historial.
		DG_Projects::add_history(
			$project['id'],
			array(
				'time'    => time(),
				'action'  => 'rollback',
				'commit'  => $latest['commit'],
				'message' => sprintf( __( 'Rollback a la versión %s', 'despliegagit' ), $latest['commit'] ),
				'source'  => 'manual',
			)
		);

		return true;
	}

	/**
	 * Calcula la ruta del sistema de archivos según el tipo y el slug.
	 *
	 * @param string $type Tipo.
	 * @param string $slug Slug.
	 * @return string|WP_Error
	 */
	public static function resolve_target_dir( $type, $slug ) {
		$slug = DG_Projects::sanitize_slug( $slug );
		if ( empty( $slug ) ) {
			return new WP_Error( 'dg_empty_slug', __( 'El slug o identificador de destino no puede estar vacío.', 'despliegagit' ) );
		}

		switch ( $type ) {
			case 'theme':
				return get_theme_root() . '/' . $slug;
			case 'plugin':
				return WP_PLUGIN_DIR . '/' . $slug;
			case 'static':
			default:
				$paths = self::paths();
				return $paths['sites'] . '/' . $slug;
		}
	}

	/**
	 * Devuelve la URL pública accesible para el recurso desplegado.
	 *
	 * @param string $type Tipo.
	 * @param string $slug Slug.
	 * @return string
	 */
	public static function get_public_url( $type, $slug ) {
		switch ( $type ) {
			case 'theme':
				return admin_url( 'themes.php' );
			case 'plugin':
				return admin_url( 'plugins.php' );
			case 'static':
			default:
				return home_url( '/' . $slug . '/' );
		}
	}

	/**
	 * Crea un backup ZIP del directorio existente antes de sobreescribir.
	 *
	 * @param string $project_id ID del proyecto.
	 * @param string $dir        Directorio a respaldar.
	 * @return string Ruta del ZIP creado o cadena vacía.
	 */
	private static function create_backup( $project_id, $dir ) {
		$paths       = self::paths();
		$backup_name = 'backup-' . $project_id . '-' . date( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false ) . '.zip';
		$backup_path = $paths['backups'] . '/' . $backup_name;

		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new ZipArchive();
			if ( true === $zip->open( $backup_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
				$files = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
					RecursiveIteratorIterator::LEAVES_ONLY
				);
				foreach ( $files as $file ) {
					if ( ! $file->isDir() ) {
						$file_path     = $file->getRealPath();
						$relative_path = substr( $file_path, strlen( $dir ) + 1 );
						$zip->addFile( $file_path, $relative_path );
					}
				}
				$zip->close();
				return $backup_path;
			}
		}

		return '';
	}

	/**
	 * Restaura un ZIP sobre una carpeta.
	 *
	 * @param string $zip_file Archivo zip.
	 * @param string $dest_dir Destino.
	 * @return true|WP_Error
	 */
	private static function restore_backup_file( $zip_file, $dest_dir ) {
		return self::unzip( $zip_file, $dest_dir );
	}

	/**
	 * Analiza el contenido de una carpeta para deducir el tipo de proyecto.
	 *
	 * @param string $dir Directorio.
	 * @return array
	 */
	public static function inspect_directory( $dir ) {
		$has_index_html = file_exists( $dir . '/index.html' ) || file_exists( $dir . '/index.htm' );
		$has_style_css  = file_exists( $dir . '/style.css' );
		$has_plugin_php = false;
		$suggested_slug = sanitize_title( basename( $dir ) );

		// Comprobar si es un tema WP revisando style.css con cabeceras de tema.
		$is_theme = false;
		if ( $has_style_css ) {
			$content = (string) file_get_contents( $dir . '/style.css', false, null, 0, 4096 );
			if ( preg_match( '/Theme Name\s*:/i', $content ) ) {
				$is_theme = true;
			}
		}

		// Comprobar si es un plugin de WordPress.
		if ( ! $is_theme ) {
			$php_files = glob( $dir . '/*.php' );
			if ( ! empty( $php_files ) ) {
				foreach ( $php_files as $file ) {
					$content = (string) file_get_contents( $file, false, null, 0, 4096 );
					if ( preg_match( '/Plugin Name\s*:/i', $content ) ) {
						$has_plugin_php = true;
						$suggested_slug = sanitize_title( pathinfo( $file, PATHINFO_FILENAME ) );
						break;
					}
				}
			}
		}

		// Contar archivos y tamaño total.
		$file_count  = 0;
		$total_bytes = 0;
		if ( is_dir( $dir ) ) {
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
			);
			foreach ( $it as $item ) {
				if ( $item->isFile() ) {
					$file_count++;
					$total_bytes += $item->getSize();
				}
			}
		}

		$detected = 'static';
		if ( $is_theme ) {
			$detected = 'theme';
		} elseif ( $has_plugin_php ) {
			$detected = 'plugin';
		} elseif ( $has_index_html ) {
			$detected = 'static';
		}

		return array(
			'detected_type'  => $detected,
			'suggested_slug' => $suggested_slug,
			'has_index_html' => $has_index_html,
			'has_style_css'  => $has_style_css,
			'has_plugin_php' => $has_plugin_php,
			'file_count'     => $file_count,
			'total_bytes'    => $total_bytes,
		);
	}

	/**
	 * Copia archivos preservando o excluyendo patrones.
	 *
	 * @param string $source   Origen.
	 * @param string $dest     Destino.
	 * @param array  $exclude  Patrones a excluir.
	 * @param array  $preserve Patrones en destino a no sobreescribir.
	 * @return true|WP_Error
	 */
	public static function sync_directories( $source, $dest, array $exclude = array(), array $preserve = array() ) {
		if ( ! is_dir( $source ) ) {
			return new WP_Error( 'dg_sync_source', __( 'El directorio de origen no existe.', 'despliegagit' ) );
		}

		wp_mkdir_p( $dest );

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$subpath = substr( $item->getPathname(), strlen( $source ) + 1 );
			$subpath_normalized = str_replace( '\\', '/', $subpath );

			// Comprobar si coincide con exclusiones del repo.
			if ( self::matches_patterns( $subpath_normalized, $exclude ) ) {
				continue;
			}

			$target_item = $dest . '/' . $subpath;

			if ( $item->isDir() ) {
				wp_mkdir_p( $target_item );
			} else {
				// Comprobar si el archivo en destino ya existe y debe ser preservado.
				if ( file_exists( $target_item ) && self::matches_patterns( $subpath_normalized, $preserve ) ) {
					continue;
				}
				wp_mkdir_p( dirname( $target_item ) );
				if ( ! @copy( $item->getPathname(), $target_item ) ) {
					/* translators: %s: ruta del archivo. */
					return new WP_Error( 'dg_copy_fail', sprintf( __( 'No se pudo copiar el archivo: %s', 'despliegagit' ), $subpath ) );
				}
			}
		}

		return true;
	}

	/**
	 * Verifica si una ruta coincide con alguna de las reglas glob.
	 *
	 * @param string $path     Ruta relativa normalizada con /.
	 * @param array  $patterns Lista de patrones.
	 * @return bool
	 */
	public static function matches_patterns( $path, array $patterns ) {
		$basename = basename( $path );
		foreach ( $patterns as $pattern ) {
			$pattern = trim( str_replace( '\\', '/', $pattern ) );
			if ( '' === $pattern ) {
				continue;
			}
			// Coincidencia exacta con nombre o fnmatch.
			if ( fnmatch( $pattern, $path ) || fnmatch( $pattern, $basename ) ) {
				return true;
			}
			// Coincidencia con carpetas (ej: node_modules o .git).
			if ( 0 === strpos( $path, trim( $pattern, '/' ) . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Descomprime un archivo ZIP de forma segura.
	 *
	 * @param string $zip_path Archivo zip.
	 * @param string $dest_dir Directorio destino.
	 * @return true|WP_Error
	 */
	private static function unzip( $zip_path, $dest_dir ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
			$res = unzip_file( $zip_path, $dest_dir );
			return is_wp_error( $res ) ? $res : true;
		}

		$zip = new ZipArchive();
		$res = $zip->open( $zip_path );
		if ( true !== $res ) {
			return new WP_Error( 'dg_zip_open', __( 'No se pudo abrir el archivo ZIP descargado.', 'despliegagit' ) );
		}

		// Protección Zip Slip.
		$real_dest = realpath( $dest_dir );
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$filename = $zip->getNameIndex( $i );
			if ( false !== strpos( $filename, '../' ) || false !== strpos( $filename, '..\\' ) ) {
				$zip->close();
				return new WP_Error( 'dg_zip_slip', __( 'El archivo ZIP contiene rutas relativas inseguras.', 'despliegagit' ) );
			}
		}

		$extracted = $zip->extractTo( $dest_dir );
		$zip->close();

		return $extracted ? true : new WP_Error( 'dg_zip_extract', __( 'Error al extraer los archivos del ZIP.', 'despliegagit' ) );
	}

	/**
	 * Si la raíz extraída contiene una única carpeta contenedora (como los zips de GitHub), saca su contenido al nivel superior.
	 *
	 * @param string $dir Directorio de trabajo.
	 */
	private static function flatten_single_root_dir( $dir ) {
		$items = array_values( array_diff( scandir( $dir ), array( '.', '..' ) ) );
		if ( 1 === count( $items ) ) {
			$single = $dir . '/' . $items[0];
			if ( is_dir( $single ) ) {
				$sub_items = scandir( $single );
				foreach ( $sub_items as $sub ) {
					if ( '.' === $sub || '..' === $sub ) {
						continue;
					}
					rename( $single . '/' . $sub, $dir . '/' . $sub );
				}
				@rmdir( $single );
			}
		}
	}

	/**
	 * Genera un árbol/resumen plano de los primeros N archivos para mostrar en la previsualización.
	 *
	 * @param string $dir   Directorio.
	 * @param int    $limit Límite de archivos.
	 * @return array
	 */
	public static function list_files_summary( $dir, $limit = 100 ) {
		$results = array();
		if ( ! is_dir( $dir ) ) {
			return $results;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( count( $results ) >= $limit ) {
				break;
			}
			$subpath = substr( $item->getPathname(), strlen( $dir ) + 1 );
			$results[] = array(
				'path'  => str_replace( '\\', '/', $subpath ),
				'type'  => $item->isDir() ? 'dir' : 'file',
				'size'  => $item->isFile() ? $item->getSize() : 0,
			);
		}

		return $results;
	}

	/**
	 * Elimina recursivamente un directorio.
	 *
	 * @param string $dir Directorio.
	 */
	public static function rmdir_recursive( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $files as $fileinfo ) {
			if ( $fileinfo->isDir() ) {
				@rmdir( $fileinfo->getRealPath() );
			} else {
				@unlink( $fileinfo->getRealPath() );
			}
		}
		@rmdir( $dir );
	}
}

