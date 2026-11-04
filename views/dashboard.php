<?php
/**
 * Vista del Dashboard de proyectos de DespliegaGit.
 *
 * @package DespliegaGit
 */

defined( 'ABSPATH' ) || exit;

$has_global_token = '' !== DG_Settings::token();
?>
<div class="wrap dg-wrap">
	<!-- Encabezado Principal -->
	<header class="dg-header">
		<div class="dg-header-title">
			<div class="dg-badge">
				<span class="dashicons dashicons-cloud-upload"></span>
				<strong>DespliegaGit</strong> v<?php echo esc_html( DESPLIEGAGIT_VERSION ); ?>
			</div>
			<h1><?php esc_html_e( 'Repositorios y Despliegues', 'despliegagit' ); ?></h1>
			<p class="dg-tagline">
				<?php esc_html_e( 'De tu repositorio a WordPress en un solo paso: pega el enlace, despliega y publica.', 'despliegagit' ); ?>
			</p>
		</div>
		<div class="dg-header-actions">
			<button type="button" class="button button-primary button-hero js-open-new-project">
				<span class="dashicons dashicons-plus-alt2"></span>
				<?php esc_html_e( 'Importar Repositorio', 'despliegagit' ); ?>
			</button>
		</div>
	</header>

	<?php if ( ! $has_global_token ) : ?>
		<div class="notice notice-info dg-notice-banner inline">
			<p>
				<span class="dashicons dashicons-info"></span>
				<strong><?php esc_html_e( 'Consejo:', 'despliegagit' ); ?></strong>
				<?php esc_html_e( '¿Vas a usar repositorios privados o necesitas un límite de API más alto? Configura tu', 'despliegagit' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=despliegagit-settings' ) ); ?>">
					<?php esc_html_e( 'GitHub Personal Access Token en Ajustes', 'despliegagit' ); ?> &rarr;
				</a>
			</p>
		</div>
	<?php endif; ?>

	<!-- Lista de Proyectos -->
	<main class="dg-content">
		<?php if ( empty( $projects ) ) : ?>
			<div class="dg-empty-state">
				<div class="dg-empty-icon">
					<span class="dashicons dashicons-format-aside"></span>
				</div>
				<h2><?php esc_html_e( 'Aún no has importado ningún repositorio', 'despliegagit' ); ?></h2>
				<p>
					<?php esc_html_e( 'Pega la URL de cualquier repositorio público o privado de GitHub para empezar a desplegar sitios estáticos, componentes o plantillas al instante.', 'despliegagit' ); ?>
				</p>
				<button type="button" class="button button-primary button-hero js-open-new-project">
					<span class="dashicons dashicons-plus-alt2"></span>
					<?php esc_html_e( 'Importar mi primer proyecto', 'despliegagit' ); ?>
				</button>
			</div>
		<?php else : ?>
			<div class="dg-projects-grid">
				<?php foreach ( $projects as $p ) :
					$type_name = isset( $types[ $p['deployed_type'] ? $p['deployed_type'] : $p['type'] ] ) ? $types[ $p['deployed_type'] ? $p['deployed_type'] : $p['type'] ] : ucfirst( $p['type'] );
					$public_url = DG_Deployer::get_public_url( $p['deployed_type'] ? $p['deployed_type'] : $p['type'], $p['deployed_slug'] ? $p['deployed_slug'] : $p['slug'] );
					$is_deployed = 'deployed' === $p['status'];
					$is_staged   = 'staged' === $p['status'] && ! empty( $p['staged'] );
					$webhook_url = DG_Webhook::get_webhook_url( $p['id'] );
				?>
					<div class="dg-card" data-project-id="<?php echo esc_attr( $p['id'] ); ?>">
						<div class="dg-card-header">
							<div class="dg-card-title-group">
								<span class="dg-badge-type dg-type-<?php echo esc_attr( $p['deployed_type'] ? $p['deployed_type'] : $p['type'] ); ?>">
									<?php echo esc_html( $type_name ); ?>
								</span>
								<h3><?php echo esc_html( $p['name'] ); ?></h3>
								<a href="<?php echo esc_url( $p['repo_url'] ); ?>" target="_blank" rel="noopener noreferrer" class="dg-repo-link">
									<span class="dashicons dashicons-external"></span>
									<?php echo esc_html( $p['owner'] . '/' . $p['repo'] ); ?>
								</a>
							</div>
							<div class="dg-status-tag status-<?php echo esc_attr( $p['status'] ); ?>">
								<?php
								if ( $is_deployed ) {
									echo '<span class="dg-dot"></span> ' . esc_html__( 'Publicado', 'despliegagit' );
								} elseif ( $is_staged ) {
									echo '<span class="dg-dot"></span> ' . esc_html__( 'En Previsualización', 'despliegagit' );
								} else {
									echo '<span class="dg-dot"></span> ' . esc_html__( 'Borrador', 'despliegagit' );
								}
								?>
							</div>
						</div>

						<div class="dg-card-body">
							<div class="dg-meta-list">
								<div class="dg-meta-item">
									<strong><?php esc_html_e( 'Rama:', 'despliegagit' ); ?></strong>
									<code><?php echo esc_html( ! empty( $p['branch'] ) ? $p['branch'] : 'main' ); ?></code>
									<?php if ( ! empty( $p['subdir'] ) ) : ?>
										<span class="dg-meta-subdir">/<?php echo esc_html( $p['subdir'] ); ?></span>
									<?php endif; ?>
								</div>

								<div class="dg-meta-item">
									<strong><?php esc_html_e( 'Ruta / Slug:', 'despliegagit' ); ?></strong>
									<code><?php echo esc_html( $p['deployed_slug'] ? $p['deployed_slug'] : $p['slug'] ); ?></code>
								</div>

								<?php if ( ! empty( $p['last_commit'] ) ) : ?>
									<div class="dg-meta-item dg-commit-info">
										<strong><?php esc_html_e( 'Último commit:', 'despliegagit' ); ?></strong>
										<span class="dg-commit-sha">#<?php echo esc_html( substr( $p['last_commit']['sha'], 0, 7 ) ); ?></span>
										<span class="dg-commit-msg" title="<?php echo esc_attr( $p['last_commit']['message'] ); ?>">
											<?php echo esc_html( wp_trim_words( $p['last_commit']['message'], 8 ) ); ?>
										</span>
									</div>
								<?php endif; ?>

								<?php if ( $p['last_deployed'] > 0 ) : ?>
									<div class="dg-meta-item dg-last-deployed">
										<strong><?php esc_html_e( 'Desplegado hace:', 'despliegagit' ); ?></strong>
										<span><?php echo esc_html( human_time_diff( $p['last_deployed'] ) ); ?></span>
									</div>
								<?php endif; ?>
							</div>

							<!-- Badges de características -->
							<div class="dg-pills">
								<?php if ( ! empty( $p['auto_deploy'] ) ) : ?>
									<span class="dg-pill dg-pill-auto" title="<?php esc_attr_e( 'Despliegue automático por Webhook activado', 'despliegagit' ); ?>">
										<span class="dashicons dashicons-update"></span> Auto-deploy
									</span>
								<?php endif; ?>
								<?php if ( ! empty( $p['spa_fallback'] ) ) : ?>
									<span class="dg-pill dg-pill-spa" title="<?php esc_attr_e( 'Fallback SPA habilitado (React/Vue/Astro)', 'despliegagit' ); ?>">
										SPA Router
									</span>
								<?php endif; ?>
								<?php if ( ! empty( $p['backups'] ) ) : ?>
									<span class="dg-pill" title="<?php esc_attr_e( 'Copias de seguridad disponibles', 'despliegagit' ); ?>">
										<?php echo esc_html( count( $p['backups'] ) ); ?> <?php esc_html_e( 'backups', 'despliegagit' ); ?>
									</span>
								<?php endif; ?>
							</div>
						</div>

						<div class="dg-card-footer">
							<div class="dg-card-links">
								<?php if ( $is_deployed ) : ?>
									<a href="<?php echo esc_url( $public_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-secondary">
										<span class="dashicons dashicons-visibility"></span>
										<?php esc_html_e( 'Ver en vivo', 'despliegagit' ); ?>
									</a>
								<?php endif; ?>

								<button type="button" class="button button-primary js-stage-btn" data-id="<?php echo esc_attr( $p['id'] ); ?>">
									<span class="dashicons dashicons-download"></span>
									<?php echo $is_deployed ? esc_html__( 'Sincronizar', 'despliegagit' ) : esc_html__( 'Preparar importación', 'despliegagit' ); ?>
								</button>
							</div>

							<div class="dg-card-menu-wrap">
								<button type="button" class="button button-link js-toggle-menu">
									<span class="dashicons dashicons-ellipsis"></span>
								</button>
								<div class="dg-dropdown-menu">
									<?php if ( ! empty( $p['backups'] ) ) : ?>
										<a href="#" class="js-rollback-btn" data-id="<?php echo esc_attr( $p['id'] ); ?>">
											<span class="dashicons dashicons-undo"></span>
											<?php esc_html_e( 'Restaurar último backup', 'despliegagit' ); ?>
										</a>
									<?php endif; ?>
									<a href="#" class="js-show-webhook-btn" data-webhook="<?php echo esc_url( $webhook_url ); ?>" data-secret="<?php echo esc_attr( $p['webhook_secret'] ); ?>">
										<span class="dashicons dashicons-rest-api"></span>
										<?php esc_html_e( 'Ver datos de Webhook', 'despliegagit' ); ?>
									</a>
									<a href="#" class="js-edit-project-btn" data-project="<?php echo esc_attr( wp_json_encode( $p ) ); ?>">
										<span class="dashicons dashicons-edit"></span>
										<?php esc_html_e( 'Editar configuración', 'despliegagit' ); ?>
									</a>
									<a href="#" class="js-delete-project-btn dg-danger" data-id="<?php echo esc_attr( $p['id'] ); ?>">
										<span class="dashicons dashicons-trash"></span>
										<?php esc_html_e( 'Eliminar proyecto', 'despliegagit' ); ?>
									</a>
								</div>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</main>

	<!-- MODAL: Importar / Editar Proyecto -->
	<div id="dg-project-modal" class="dg-modal" style="display:none;">
		<div class="dg-modal-overlay js-close-modal"></div>
		<div class="dg-modal-dialog">
			<div class="dg-modal-header">
				<h2 id="dg-modal-title"><?php esc_html_e( 'Importar Repositorio de GitHub', 'despliegagit' ); ?></h2>
				<button type="button" class="dg-modal-close js-close-modal">&times;</button>
			</div>
			<div class="dg-modal-body">
				<form id="dg-project-form">
					<input type="hidden" name="id" id="dg-field-id" value="">

					<!-- Paso 1: URL del Repositorio -->
					<div class="dg-form-group">
						<label for="dg-field-repo-url">
							<strong><?php esc_html_e( 'URL del Repositorio de GitHub', 'despliegagit' ); ?></strong>
							<span class="dg-required">*</span>
						</label>
						<div class="dg-input-with-button">
							<input type="text" name="repo_url" id="dg-field-repo-url" class="regular-text" placeholder="https://github.com/usuario/mi-proyecto" required>
							<button type="button" id="dg-btn-inspect" class="button button-secondary">
								<span class="dashicons dashicons-search"></span>
								<?php esc_html_e( 'Conectar y Analizar', 'despliegagit' ); ?>
							</button>
						</div>
						<p class="description">
							<?php esc_html_e( 'Pega la URL de tu repositorio (público o privado). Si contiene /tree/rama o /tree/rama/dist se detectará automáticamente.', 'despliegagit' ); ?>
						</p>
					</div>

					<!-- Token opcional específico para este repo -->
					<div class="dg-form-group">
						<label for="dg-field-token">
							<strong><?php esc_html_e( 'Personal Access Token (Opcional)', 'despliegagit' ); ?></strong>
						</label>
						<input type="password" name="token" id="dg-field-token" class="regular-text" placeholder="ghp_xxxxxxxxxxxxxxxxxxxx">
						<p class="description">
							<?php esc_html_e( 'Solo si es un repositorio privado y no has configurado un token global en Ajustes.', 'despliegagit' ); ?>
						</p>
					</div>

					<!-- Información detectada tras analizar -->
					<div id="dg-inspect-result" class="dg-inspect-panel" style="display:none;">
						<div class="dg-inspect-badge">
							<span class="dashicons dashicons-yes-alt"></span>
							<span id="dg-inspect-summary"></span>
						</div>
					</div>

					<div class="dg-form-row">
						<div class="dg-form-group dg-col-6">
							<label for="dg-field-name"><strong><?php esc_html_e( 'Nombre del Proyecto', 'despliegagit' ); ?></strong></label>
							<input type="text" name="name" id="dg-field-name" class="regular-text" placeholder="Mi Proyecto Web">
						</div>
						<div class="dg-form-group dg-col-6">
							<label for="dg-field-branch"><strong><?php esc_html_e( 'Rama', 'despliegagit' ); ?></strong></label>
							<select name="branch" id="dg-field-branch" class="regular-text">
								<option value="main">main</option>
								<option value="master">master</option>
							</select>
						</div>
					</div>

					<div class="dg-form-row">
						<div class="dg-form-group dg-col-6">
							<label for="dg-field-type"><strong><?php esc_html_e( 'Tipo de Despliegue', 'despliegagit' ); ?></strong></label>
							<select name="type" id="dg-field-type" class="regular-text">
								<?php foreach ( DG_Projects::types() as $val => $lbl ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $lbl ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Por defecto detecta automáticamente si es un sitio estático, tema o plugin.', 'despliegagit' ); ?></p>
						</div>
						<div class="dg-form-group dg-col-6">
							<label for="dg-field-slug"><strong><?php esc_html_e( 'Slug / Ruta de Destino', 'despliegagit' ); ?></strong></label>
							<input type="text" name="slug" id="dg-field-slug" class="regular-text" placeholder="mi-app">
							<p class="description" id="dg-slug-preview-text">
								<?php esc_html_e( 'Se publicará en:', 'despliegagit' ); ?> <code><?php echo esc_html( home_url( '/' ) ); ?><span id="dg-slug-display">mi-app</span>/</code>
							</p>
						</div>
					</div>

					<div class="dg-form-group">
						<label for="dg-field-subdir"><strong><?php esc_html_e( 'Subdirectorio del repositorio (Opcional)', 'despliegagit' ); ?></strong></label>
						<input type="text" name="subdir" id="dg-field-subdir" class="regular-text" placeholder="dist o build">
						<p class="description">
							<?php esc_html_e( 'Si tu código compilado está dentro de una subcarpeta como dist, build o public, especifícala aquí.', 'despliegagit' ); ?>
						</p>
					</div>

					<!-- Opciones avanzadas desplegables -->
					<details class="dg-advanced-options">
						<summary><?php esc_html_e( 'Opciones Avanzadas (Exclusiones, Webhook, SPA)', 'despliegagit' ); ?></summary>
						<div class="dg-advanced-content">
							<div class="dg-form-group">
								<label>
									<input type="checkbox" name="auto_deploy" id="dg-field-auto-deploy" value="1">
									<strong><?php esc_html_e( 'Habilitar Despliegue Automático vía Webhook', 'despliegagit' ); ?></strong>
								</label>
								<p class="description"><?php esc_html_e( 'Cada vez que hagas un push a la rama seleccionada en GitHub, tu web se actualizará automáticamente.', 'despliegagit' ); ?></p>
							</div>

							<div class="dg-form-group">
								<label>
									<input type="checkbox" name="spa_fallback" id="dg-field-spa-fallback" value="1">
									<strong><?php esc_html_e( 'Soporte SPA Router (Single Page Application)', 'despliegagit' ); ?></strong>
								</label>
								<p class="description"><?php esc_html_e( 'Redirige rutas no encontradas a index.html (esencial para React Router, Vue Router, etc.).', 'despliegagit' ); ?></p>
							</div>

							<div class="dg-form-group">
								<label for="dg-field-exclude"><strong><?php esc_html_e( 'Patrones a excluir del repositorio (1 por línea)', 'despliegagit' ); ?></strong></label>
								<textarea name="exclude" id="dg-field-exclude" rows="4" class="large-text code"><?php echo esc_textarea( DG_Settings::get( 'default_exclude' ) ); ?></textarea>
							</div>

							<div class="dg-form-group">
								<label for="dg-field-preserve"><strong><?php esc_html_e( 'Archivos en destino a conservar / no sobreescribir', 'despliegagit' ); ?></strong></label>
								<textarea name="preserve" id="dg-field-preserve" rows="2" class="large-text code" placeholder=".env&#10;uploads/*"></textarea>
							</div>
						</div>
					</details>
				</form>
			</div>
			<div class="dg-modal-footer">
				<button type="button" class="button js-close-modal"><?php esc_html_e( 'Cancelar', 'despliegagit' ); ?></button>
				<button type="button" id="dg-btn-save-project" class="button button-primary">
					<span class="dashicons dashicons-saved"></span>
					<?php esc_html_e( 'Guardar Proyecto', 'despliegagit' ); ?>
				</button>
			</div>
		</div>
	</div>

	<!-- MODAL: Previsualización y Confirmación de Despliegue -->
	<div id="dg-preview-modal" class="dg-modal" style="display:none;">
		<div class="dg-modal-overlay js-close-modal"></div>
		<div class="dg-modal-dialog dg-modal-large">
			<div class="dg-modal-header">
				<h2><?php esc_html_e( 'Previsualización y Análisis de Despliegue', 'despliegagit' ); ?></h2>
				<button type="button" class="dg-modal-close js-close-modal">&times;</button>
			</div>
			<div class="dg-modal-body">
				<div class="dg-preview-columns">
					<div class="dg-preview-meta-panel">
						<div class="dg-preview-meta-card">
							<h4><?php esc_html_e( 'Resumen del Paquete', 'despliegagit' ); ?></h4>
							<ul class="dg-preview-stat-list">
								<li>
									<span><?php esc_html_e( 'Tipo Detectado:', 'despliegagit' ); ?></span>
									<strong id="dg-prev-detected-type">—</strong>
								</li>
								<li>
									<span><?php esc_html_e( 'Archivos Totales:', 'despliegagit' ); ?></span>
									<strong id="dg-prev-file-count">—</strong>
								</li>
								<li>
									<span><?php esc_html_e( 'Tamaño Aprox:', 'despliegagit' ); ?></span>
									<strong id="dg-prev-size">—</strong>
								</li>
								<li>
									<span><?php esc_html_e( 'Commit SHA:', 'despliegagit' ); ?></span>
									<code id="dg-prev-commit-sha">—</code>
								</li>
								<li>
									<span><?php esc_html_e( 'Mensaje Commit:', 'despliegagit' ); ?></span>
									<span id="dg-prev-commit-msg">—</span>
								</li>
							</ul>
						</div>

						<div class="dg-preview-actions-box">
							<a href="#" id="dg-prev-iframe-btn" target="_blank" rel="noopener noreferrer" class="button button-secondary button-large dg-full-width">
								<span class="dashicons dashicons-visibility"></span>
								<?php esc_html_e( 'Abrir Previsualización Web', 'despliegagit' ); ?>
							</a>
							<p class="description">
								<?php esc_html_e( 'Comprueba que los enlaces, hojas de estilo e imágenes cargan perfectamente antes de publicar.', 'despliegagit' ); ?>
							</p>
						</div>
					</div>

					<div class="dg-preview-tree-panel">
						<h4><?php esc_html_e( 'Estructura de Archivos a Importar', 'despliegagit' ); ?></h4>
						<div class="dg-file-tree" id="dg-prev-files-tree">
							<!-- Lista de archivos inyectada por JS -->
						</div>
					</div>
				</div>
			</div>
			<div class="dg-modal-footer">
				<button type="button" class="button js-close-modal"><?php esc_html_e( 'Cerrar', 'despliegagit' ); ?></button>
				<button type="button" id="dg-btn-confirm-deploy" class="button button-primary button-hero">
					<span class="dashicons dashicons-upload"></span>
					<?php esc_html_e( 'Publicar en WordPress Ahora', 'despliegagit' ); ?>
				</button>
			</div>
		</div>
	</div>

	<!-- MODAL: Datos de Webhook -->
	<div id="dg-webhook-modal" class="dg-modal" style="display:none;">
		<div class="dg-modal-overlay js-close-modal"></div>
		<div class="dg-modal-dialog">
			<div class="dg-modal-header">
				<h2><?php esc_html_e( 'Configurar Webhook en GitHub', 'despliegagit' ); ?></h2>
				<button type="button" class="dg-modal-close js-close-modal">&times;</button>
			</div>
			<div class="dg-modal-body">
				<p>
					<?php esc_html_e( 'Para sincronización automática en cada push, ve a tu repositorio en GitHub > Settings > Webhooks > Add webhook y rellena:', 'despliegagit' ); ?>
				</p>
				<div class="dg-form-group">
					<label><strong>Payload URL:</strong></label>
					<input type="text" id="dg-wh-url" class="large-text code" readonly onclick="this.select();">
				</div>
				<div class="dg-form-group">
					<label><strong>Content type:</strong></label>
					<input type="text" value="application/json" class="large-text code" readonly>
				</div>
				<div class="dg-form-group">
					<label><strong>Secret:</strong></label>
					<input type="text" id="dg-wh-secret" class="large-text code" readonly onclick="this.select();">
				</div>
				<div class="dg-form-group">
					<label><strong>Which events would you like to trigger this webhook?</strong></label>
					<p class="description">Just the <code>push</code> event.</p>
				</div>
			</div>
			<div class="dg-modal-footer">
				<button type="button" class="button button-primary js-close-modal"><?php esc_html_e( 'Entendido', 'despliegagit' ); ?></button>
			</div>
		</div>
	</div>

	<!-- Overlay de Carga -->
	<div id="dg-loading-overlay" class="dg-loading-overlay" style="display:none;">
		<div class="dg-spinner-card">
			<span class="spinner is-active"></span>
			<h3 id="dg-loading-message"><?php esc_html_e( 'Procesando...', 'despliegagit' ); ?></h3>
		</div>
	</div>
</div>

