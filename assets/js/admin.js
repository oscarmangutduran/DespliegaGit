/**
 * Javascript para DespliegaGit.
 */

(function ($) {
	'use strict';

	var currentStagedProjectId = '';

	$(document).ready(function () {
		initModals();
		initProjectForm();
		initInspection();
		initDeployActions();
		initSettingsForm();
	});

	function showLoading(message) {
		$('#dg-loading-message').text(message || 'Procesando...');
		$('#dg-loading-overlay').fadeIn(150);
	}

	function hideLoading() {
		$('#dg-loading-overlay').fadeOut(150);
	}

	function formatBytes(bytes) {
		if (bytes === 0) return '0 B';
		var k = 1024;
		var sizes = ['B', 'KB', 'MB', 'GB'];
		var i = Math.floor(Math.log(bytes) / Math.log(k));
		return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
	}

	function initModals() {
		// Abrir modal nuevo proyecto
		$('.js-open-new-project').on('click', function (e) {
			e.preventDefault();
			resetProjectForm();
			$('#dg-modal-title').text('Importar Repositorio de GitHub');
			$('#dg-project-modal').fadeIn(200);
		});

		// Cerrar modales
		$('.js-close-modal').on('click', function (e) {
			e.preventDefault();
			$('.dg-modal').fadeOut(150);
		});

		// Menú contextual de cada tarjeta
		$(document).on('click', '.js-toggle-menu', function (e) {
			e.stopPropagation();
			var $menu = $(this).siblings('.dg-dropdown-menu');
			$('.dg-dropdown-menu').not($menu).removeClass('is-active');
			$menu.toggleClass('is-active');
		});

		$(document).on('click', function () {
			$('.dg-dropdown-menu').removeClass('is-active');
		});

		// Mostrar modal de datos de webhook
		$(document).on('click', '.js-show-webhook-btn', function (e) {
			e.preventDefault();
			var url = $(this).data('webhook');
			var secret = $(this).data('secret');
			$('#dg-wh-url').val(url);
			$('#dg-wh-secret').val(secret);
			$('#dg-webhook-modal').fadeIn(200);
		});
	}

	function resetProjectForm() {
		$('#dg-project-form')[0].reset();
		$('#dg-field-id').val('');
		$('#dg-inspect-result').hide();
		$('#dg-slug-display').text('mi-app');
		$('#dg-field-branch').html('<option value="main">main</option><option value="master">master</option>');
	}

	function initInspection() {
		$('#dg-btn-inspect').on('click', function (e) {
			e.preventDefault();
			var repoUrl = $.trim($('#dg-field-repo-url').val());
			var token = $.trim($('#dg-field-token').val());

			if (!repoUrl) {
				alert('Por favor introduce la URL del repositorio.');
				$('#dg-field-repo-url').focus();
				return;
			}

			showLoading(despliegaGit.strings.inspecting);

			$.post(despliegaGit.ajaxUrl, {
				action: 'dg_inspect_repo',
				nonce: despliegaGit.nonce,
				url: repoUrl,
				token: token
			})
				.done(function (res) {
					hideLoading();
					if (res.success) {
						var d = res.data;
						$('#dg-inspect-summary').text(
							(d.is_private ? '🔒 Privado' : '🌍 Público') + ' • ' + d.owner + '/' + d.repo +
							(d.description ? ' — ' + d.description : '')
						);
						$('#dg-inspect-result').slideDown(200);

						// Autocompletar nombre si está vacío
						if (!$('#dg-field-name').val()) {
							$('#dg-field-name').val(d.repo);
						}

						// Autocompletar slug si está vacío
						if (!$('#dg-field-slug').val()) {
							$('#dg-field-slug').val(d.suggested_slug);
							$('#dg-slug-display').text(d.suggested_slug);
						}

						// Si la URL traía subdirectorio (ej: /tree/main/dist)
						if (d.subdir && !$('#dg-field-subdir').val()) {
							$('#dg-field-subdir').val(d.subdir);
						}

						// Poblar ramas
						var $branchSelect = $('#dg-field-branch');
						$branchSelect.empty();
						$.each(d.branches, function (i, b) {
							var sel = (b === d.default_branch) ? ' selected' : '';
							$branchSelect.append('<option value="' + b + '"' + sel + '>' + b + '</option>');
						});
					} else {
						alert(res.data.message || 'Error al conectar con el repositorio.');
					}
				})
				.fail(function () {
					hideLoading();
					alert('Error de conexión con el servidor de WordPress.');
				});
		});

		// Actualizar slug display en vivo
		$('#dg-field-slug').on('input', function () {
			var val = $(this).val().toLowerCase().replace(/[^a-z0-9_-]/g, '');
			$('#dg-slug-display').text(val || 'mi-app');
		});
	}

	function initProjectForm() {
		// Guardar proyecto
		$('#dg-btn-save-project').on('click', function (e) {
			e.preventDefault();
			var repoUrl = $.trim($('#dg-field-repo-url').val());
			if (!repoUrl) {
				alert('La URL del repositorio es obligatoria.');
				return;
			}

			var formData = $('#dg-project-form').serializeArray();
			formData.push({ name: 'action', value: 'dg_save_project' });
			formData.push({ name: 'nonce', value: despliegaGit.nonce });

			showLoading('Guardando proyecto...');

			$.post(despliegaGit.ajaxUrl, formData)
				.done(function (res) {
					hideLoading();
					if (res.success) {
						$('#dg-project-modal').fadeOut(150);
						window.location.reload();
					} else {
						alert(res.data.message || 'Error al guardar el proyecto.');
					}
				})
				.fail(function () {
					hideLoading();
					alert('Error en la comunicación AJAX.');
				});
		});

		// Editar proyecto
		$(document).on('click', '.js-edit-project-btn', function (e) {
			e.preventDefault();
			var p = $(this).data('project');
			resetProjectForm();

			$('#dg-modal-title').text('Editar Proyecto: ' + p.name);
			$('#dg-field-id').val(p.id);
			$('#dg-field-repo-url').val(p.repo_url);
			$('#dg-field-name').val(p.name);
			$('#dg-field-slug').val(p.slug);
			$('#dg-slug-display').text(p.slug);
			$('#dg-field-subdir').val(p.subdir);
			$('#dg-field-type').val(p.type);
			$('#dg-field-exclude').val(p.exclude);
			$('#dg-field-preserve').val(p.preserve);

			if (p.auto_deploy) {
				$('#dg-field-auto-deploy').prop('checked', true);
			}
			if (p.spa_fallback) {
				$('#dg-field-spa-fallback').prop('checked', true);
			}

			// Ramas
			var $branchSelect = $('#dg-field-branch');
			$branchSelect.html('<option value="' + (p.branch || 'main') + '" selected>' + (p.branch || 'main') + '</option>');

			$('#dg-project-modal').fadeIn(200);
		});

		// Eliminar proyecto
		$(document).on('click', '.js-delete-project-btn', function (e) {
			e.preventDefault();
			if (!confirm(despliegaGit.strings.confirmDelete)) {
				return;
			}

			var id = $(this).data('id');
			showLoading('Eliminando proyecto...');

			$.post(despliegaGit.ajaxUrl, {
				action: 'dg_delete_project',
				nonce: despliegaGit.nonce,
				id: id
			})
				.done(function (res) {
					hideLoading();
					if (res.success) {
						window.location.reload();
					} else {
						alert(res.data.message || 'Error al eliminar.');
					}
				})
				.fail(function () {
					hideLoading();
					alert('Error de conexión.');
				});
		});
	}

	function initDeployActions() {
		// Preparar staging e inspeccionar
		$(document).on('click', '.js-stage-btn', function (e) {
			e.preventDefault();
			var id = $(this).data('id');
			currentStagedProjectId = id;

			showLoading(despliegaGit.strings.staging);

			$.post(despliegaGit.ajaxUrl, {
				action: 'dg_stage_project',
				nonce: despliegaGit.nonce,
				id: id
			})
				.done(function (res) {
					hideLoading();
					if (res.success) {
						var st = res.data.staged;
						$('#dg-prev-detected-type').text(st.effective_type.toUpperCase());
						$('#dg-prev-file-count').text(st.file_count + ' archivos');
						$('#dg-prev-size').text(formatBytes(st.total_bytes));
						$('#dg-prev-commit-sha').text(st.commit.sha ? '#' + st.commit.sha.substring(0, 7) : '—');
						$('#dg-prev-commit-msg').text(st.commit.message || '—');

						// URL previsualización
						$('#dg-prev-iframe-btn').attr('href', res.data.preview_url);

						// Renderizar árbol de archivos
						var $tree = $('#dg-prev-files-tree');
						$tree.empty();
						if (st.files && st.files.length > 0) {
							$.each(st.files, function (i, item) {
								var isDir = item.type === 'dir';
								var icon = isDir ? '📁 ' : '📄 ';
								var sizeText = isDir ? '' : '<span class="dg-tree-size">' + formatBytes(item.size) + '</span>';
								$tree.append(
									'<div class="dg-tree-item ' + (isDir ? 'is-dir' : 'is-file') + '">' +
									'<span>' + icon + item.path + '</span>' +
									sizeText +
									'</div>'
								);
							});
						} else {
							$tree.html('<div style="color:#94a3b8;padding:20px;text-align:center;">No se encontraron archivos en la carpeta especificada.</div>');
						}

						$('#dg-preview-modal').fadeIn(200);
					} else {
						alert(res.data.message || 'Error durante la preparación.');
					}
				})
				.fail(function () {
					hideLoading();
					alert('Error al conectar con WordPress.');
				});
		});

		// Confirmar despliegue a producción
		$('#dg-btn-confirm-deploy').on('click', function (e) {
			e.preventDefault();
			if (!currentStagedProjectId) return;

			if (!confirm(despliegaGit.strings.confirmDeploy)) {
				return;
			}

			showLoading(despliegaGit.strings.deploying);

			$.post(despliegaGit.ajaxUrl, {
				action: 'dg_deploy_project',
				nonce: despliegaGit.nonce,
				id: currentStagedProjectId
			})
				.done(function (res) {
					hideLoading();
					if (res.success) {
						$('#dg-preview-modal').fadeOut(150);
						alert(res.data.message);
						if (res.data.public_url) {
							window.location.href = res.data.public_url;
						} else {
							window.location.reload();
						}
					} else {
						alert(res.data.message || 'Error al publicar.');
					}
				})
				.fail(function () {
					hideLoading();
					alert('Error al publicar el proyecto.');
				});
		});

		// Rollback a copia de seguridad anterior
		$(document).on('click', '.js-rollback-btn', function (e) {
			e.preventDefault();
			if (!confirm(despliegaGit.strings.confirmRollback)) {
				return;
			}

			var id = $(this).data('id');
			showLoading(despliegaGit.strings.rollingBack);

			$.post(despliegaGit.ajaxUrl, {
				action: 'dg_rollback_project',
				nonce: despliegaGit.nonce,
				id: id
			})
				.done(function (res) {
					hideLoading();
					if (res.success) {
						alert(res.data.message);
						window.location.reload();
					} else {
						alert(res.data.message || 'Error al restaurar.');
					}
				})
				.fail(function () {
					hideLoading();
					alert('Error durante el rollback.');
				});
		});
	}

	function initSettingsForm() {
		$('#dg-settings-form').on('submit', function (e) {
			e.preventDefault();
			var formData = $(this).serializeArray();
			formData.push({ name: 'action', value: 'dg_save_settings' });
			formData.push({ name: 'nonce', value: despliegaGit.nonce });

			showLoading('Guardando ajustes...');

			$.post(despliegaGit.ajaxUrl, formData)
				.done(function (res) {
					hideLoading();
					if (res.success) {
						alert(res.data.message);
						window.location.reload();
					} else {
						alert(res.data.message || 'Error al guardar los ajustes.');
					}
				})
				.fail(function () {
					hideLoading();
					alert('Error de conexión.');
				});
		});
	}

})(jQuery);

