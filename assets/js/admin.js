/**
 * Javascript para DespliegaGit.
 */

(function ($) {
	'use strict';

	var currentStagedProjectId = '';
	var progressPollInterval = null;
	var currentPercent = 0;

	var STEPS_ORDER = ['connect', 'commit', 'download', 'unzip', 'inspect', 'ready'];

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
			$('.dg-modal').not('#dg-progress-modal').fadeOut(150);
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

						if (!$('#dg-field-name').val()) {
							$('#dg-field-name').val(d.repo);
						}

						if (!$('#dg-field-slug').val()) {
							$('#dg-field-slug').val(d.suggested_slug);
							$('#dg-slug-display').text(d.suggested_slug);
						}

						if (d.subdir && !$('#dg-field-subdir').val()) {
							$('#dg-field-subdir').val(d.subdir);
						}

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

		$('#dg-field-slug').on('input', function () {
			var val = $(this).val().toLowerCase().replace(/[^a-z0-9_-]/g, '');
			$('#dg-slug-display').text(val || 'mi-app');
		});
	}

	function initProjectForm() {
		// Guardar proyecto (solo guardar)
		$('#dg-btn-save-project').on('click', function (e) {
			e.preventDefault();
			submitProjectForm(false);
		});

		// Guardar e Iniciar Importación con barra de progreso
		$('#dg-btn-save-and-import').on('click', function (e) {
			e.preventDefault();
			submitProjectForm(true);
		});

		function submitProjectForm(startImportImmediately) {
			var repoUrl = $.trim($('#dg-field-repo-url').val());
			if (!repoUrl) {
				alert('La URL del repositorio es obligatoria.');
				return;
			}

			var projName = $.trim($('#dg-field-name').val()) || 'Repositorio';
			var branchName = $('#dg-field-branch').val() || 'main';

			var formData = $('#dg-project-form').serializeArray();
			formData.push({ name: 'action', value: 'dg_save_project' });
			formData.push({ name: 'nonce', value: despliegaGit.nonce });

			showLoading('Guardando proyecto...');

			$.post(despliegaGit.ajaxUrl, formData)
				.done(function (res) {
					hideLoading();
					if (res.success) {
						var projectId = res.data.id;
						$('#dg-project-modal').fadeOut(150);

						if (startImportImmediately) {
							// Iniciar de inmediato la importación mostrando la barra de progreso
							startImportWithProgress(projectId, projName, branchName);
						} else {
							window.location.reload();
						}
					} else {
						alert(res.data.message || 'Error al guardar el proyecto.');
					}
				})
				.fail(function () {
					hideLoading();
					alert('Error en la comunicación AJAX.');
				});
		}

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

	/**
	 * Actualiza la UI de la barra de progreso y de cada paso.
	 */
	function updateProgressBarUI(percent, statusMessage, currentStep) {
		currentPercent = Math.max(currentPercent, percent);
		$('#dg-progress-fill').css('width', currentPercent + '%');
		$('#dg-progress-percent').text(currentPercent + '%');

		if (statusMessage) {
			$('#dg-progress-status-text').text(statusMessage);
		}

		if (currentStep) {
			var currentIndex = STEPS_ORDER.indexOf(currentStep);

			$('.dg-step-item').each(function () {
				var stepKey = $(this).data('step');
				var stepIndex = STEPS_ORDER.indexOf(stepKey);
				var $marker = $(this).find('.dg-step-marker');

				if (stepIndex < currentIndex) {
					// Paso completado
					$(this).removeClass('is-active').addClass('is-completed');
					$marker.html('<span class="dashicons dashicons-yes"></span>');
				} else if (stepIndex === currentIndex) {
					// Paso actualmente en ejecución
					$(this).addClass('is-active').removeClass('is-completed');
					$marker.html('<span class="dashicons dashicons-update dg-spin-pulse"></span>');
				} else {
					// Paso pendiente
					$(this).removeClass('is-active is-completed');
					$marker.html('<span class="dashicons dashicons-minus"></span>');
				}
			});
		}
	}

	/**
	 * Inicia la importación con visualización de barra de progreso y sondeo en tiempo real.
	 */
	function startImportWithProgress(projectId, repoName, branchName) {
		currentStagedProjectId = projectId;
		currentPercent = 5;

		if (progressPollInterval) {
			clearInterval(progressPollInterval);
			progressPollInterval = null;
		}

		// Preparar modal de progreso
		$('#dg-progress-repo-name').text(repoName + ' • rama: ' + (branchName || 'main'));
		updateProgressBarUI(5, 'Iniciando conexión con GitHub...', 'connect');
		$('#dg-progress-modal').fadeIn(200);

		// Polling reactivo cada 200ms para leer el progreso
		progressPollInterval = setInterval(function () {
			$.get(despliegaGit.ajaxUrl, {
				action: 'dg_get_progress',
				nonce: despliegaGit.nonce,
				id: projectId
			}).done(function (res) {
				if (res.success && res.data) {
					var d = res.data;
					updateProgressBarUI(d.percent, d.message, d.step);
				}
			});
		}, 200);

		// Ejecución principal del staging
		$.post(despliegaGit.ajaxUrl, {
			action: 'dg_stage_project',
			nonce: despliegaGit.nonce,
			id: projectId
		})
			.done(function (res) {
				clearInterval(progressPollInterval);
				progressPollInterval = null;

				if (res.success) {
					// Llevar la barra al 100% y marcar todos los pasos
					updateProgressBarUI(100, '¡Importación completada! Abriendo previsualización...', 'ready');
					$('.dg-step-item').removeClass('is-active').addClass('is-completed')
						.find('.dg-step-marker').html('<span class="dashicons dashicons-yes"></span>');

					setTimeout(function () {
						$('#dg-progress-modal').fadeOut(150, function () {
							// Abrir la previsualización
							var st = res.data.staged;
							$('#dg-prev-detected-type').text(st.effective_type.toUpperCase());
							$('#dg-prev-file-count').text(st.file_count + ' archivos');
							$('#dg-prev-size').text(formatBytes(st.total_bytes));
							$('#dg-prev-commit-sha').text(st.commit.sha ? '#' + st.commit.sha.substring(0, 7) : '—');
							$('#dg-prev-commit-msg').text(st.commit.message || '—');

							$('#dg-prev-iframe-btn').attr('href', res.data.preview_url);

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
						});
					}, 180);
				} else {
					$('#dg-progress-modal').fadeOut(150);
					alert(res.data.message || 'Error durante la preparación.');
				}
			})
			.fail(function () {
				if (progressPollInterval) {
					clearInterval(progressPollInterval);
					progressPollInterval = null;
				}
				$('#dg-progress-modal').fadeOut(150);
				alert('Error al conectar con WordPress.');
			});
	}

	function initDeployActions() {
		// Preparar staging e inspeccionar al hacer clic en "Preparar importación" o "Sincronizar"
		$(document).on('click', '.js-stage-btn', function (e) {
			e.preventDefault();
			var id = $(this).data('id');
			var $card = $(this).closest('.dg-card');
			var projName = $card.find('.dg-card-title-group h3').text() || 'Proyecto';
			var branchName = $card.find('.dg-meta-item code').first().text() || 'main';

			startImportWithProgress(id, projName, branchName);
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
