<?php
/**
 * Vista de Ajustes Globales de DespliegaGit.
 *
 * @package DespliegaGit
 */

defined( 'ABSPATH' ) || exit;

$has_token = '' !== DG_Settings::token();
?>
<div class="wrap dg-wrap">
	<header class="dg-header">
		<div class="dg-header-title">
			<div class="dg-badge">
				<span class="dashicons dashicons-admin-generic"></span>
				<strong>DespliegaGit</strong>
			</div>
			<h1><?php esc_html_e( 'Ajustes Globales', 'despliegagit' ); ?></h1>
			<p class="dg-tagline">
				<?php esc_html_e( 'Configuración de credenciales de GitHub y preferencias de despliegue.', 'despliegagit' ); ?>
			</p>
		</div>
		<div class="dg-header-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=despliegagit' ) ); ?>" class="button button-secondary">
				&larr; <?php esc_html_e( 'Volver a Proyectos', 'despliegagit' ); ?>
			</a>
		</div>
	</header>

	<main class="dg-content">
		<div class="dg-card dg-card-settings">
			<form id="dg-settings-form">
				<!-- GitHub Token -->
				<div class="dg-settings-section">
					<h2>
						<span class="dashicons dashicons-shield"></span>
						<?php esc_html_e( 'Autenticación con GitHub', 'despliegagit' ); ?>
					</h2>
					<p class="description">
						<?php esc_html_e( 'Un Personal Access Token (PAT) permite acceder a repositorios privados y eleva el límite de peticiones de GitHub de 60 a 5.000 por hora.', 'despliegagit' ); ?>
					</p>

					<div class="dg-form-group">
						<label for="dg-settings-token">
							<strong><?php esc_html_e( 'Personal Access Token (PAT)', 'despliegagit' ); ?></strong>
						</label>
						<input type="password" name="token" id="dg-settings-token" class="regular-text" placeholder="<?php echo $has_token ? '••••••••••••••••••••••••••••••••' : 'ghp_xxxxxxxxxxxxxxxxxxxx'; ?>">
						<?php if ( $has_token ) : ?>
							<span class="dg-token-status dg-status-ok">
								<span class="dashicons dashicons-yes-alt"></span>
								<?php esc_html_e( 'Token activo y cifrado en base de datos', 'despliegagit' ); ?>
							</span>
							<label class="dg-clear-token-label">
								<input type="checkbox" name="clear_token" value="1">
								<?php esc_html_e( 'Eliminar token guardado', 'despliegagit' ); ?>
							</label>
						<?php endif; ?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: enlace a GitHub */
								__( 'Puedes generar un token clásico con el ámbito <code>repo</code> o uno fine-grained con permisos de <code>Contents: Read</code> en %s.', 'despliegagit' ),
								'<a href="https://github.com/settings/tokens" target="_blank" rel="noopener noreferrer">GitHub &rarr; Developer settings &rarr; Personal access tokens</a>'
							);
							?>
						</p>
					</div>
				</div>

				<hr class="dg-divider">

				<!-- Copias de seguridad -->
				<div class="dg-settings-section">
					<h2>
						<span class="dashicons dashicons-backup"></span>
						<?php esc_html_e( 'Copias de Seguridad (Rollback)', 'despliegagit' ); ?>
					</h2>
					<p class="description">
						<?php esc_html_e( 'DespliegaGit crea automáticamente un respaldo comprimido de la versión anterior antes de actualizar cualquier sitio.', 'despliegagit' ); ?>
					</p>

					<div class="dg-form-group">
						<label for="dg-settings-keep-backups">
							<strong><?php esc_html_e( 'Número de copias a conservar por proyecto', 'despliegagit' ); ?></strong>
						</label>
						<input type="number" name="keep_backups" id="dg-settings-keep-backups" value="<?php echo esc_attr( $settings['keep_backups'] ); ?>" min="1" max="10" class="small-text">
						<p class="description">
							<?php esc_html_e( 'Las copias más antiguas se eliminan automáticamente cuando se supera este límite.', 'despliegagit' ); ?>
						</p>
					</div>
				</div>

				<hr class="dg-divider">

				<!-- Exclusiones por defecto -->
				<div class="dg-settings-section">
					<h2>
						<span class="dashicons dashicons-hidden"></span>
						<?php esc_html_e( 'Exclusiones por Defecto', 'despliegagit' ); ?>
					</h2>
					<p class="description">
						<?php esc_html_e( 'Archivos y carpetas que nunca se copiarán a la web en los nuevos proyectos (un patrón por línea).', 'despliegagit' ); ?>
					</p>

					<div class="dg-form-group">
						<textarea name="default_exclude" id="dg-settings-exclude" rows="8" class="large-text code"><?php echo esc_textarea( $settings['default_exclude'] ); ?></textarea>
					</div>
				</div>

				<div class="dg-settings-footer">
					<button type="submit" id="dg-btn-save-settings" class="button button-primary button-hero">
						<span class="dashicons dashicons-saved"></span>
						<?php esc_html_e( 'Guardar Ajustes', 'despliegagit' ); ?>
					</button>
				</div>
			</form>
		</div>
	</main>
</div>

