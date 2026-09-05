/**
 * Runner JS para la ejecución asíncrona por lotes (AJAX) en WP autocontent.
 *
 * @package WP_Autocontent
 */

jQuery(document).ready(function ($) {
	'use strict';

	// 0. NAVEGACIÓN POR PESTAÑAS (TABS)
	function switchTab(tabId) {
		if (!tabId || !$('#' + tabId).length) {
			tabId = 'tab-content';
		}
		$('.wpac-tab-btn').removeClass('active');
		$('.wpac-tab-btn[data-tab="' + tabId + '"]').addClass('active');

		$('.wpac-tab-pane').removeClass('active').hide();
		$('#' + tabId).addClass('active').show();
	}

	$(document).on('click', '.wpac-tab-btn', function (e) {
		e.preventDefault();
		var tabId = $(this).attr('data-tab');
		switchTab(tabId);
		if (history.pushState) {
			history.pushState(null, null, '#' + tabId);
		}
	});

	if (location.hash) {
		var cleanHash = location.hash.replace('#', '');
		if ($('#' + cleanHash).length) {
			switchTab(cleanHash);
		} else {
			switchTab('tab-content');
		}
	} else {
		switchTab('tab-content');
	}

	// 0b. PAGINACIÓN Y BÚSQUEDA DE PÁGINAS EN TABLA
	var currentPage = 1;
	var itemsPerPage = 10;

	function renderTablePagination() {
		var searchQuery = $('#wpac-search-pages').val() ? $('#wpac-search-pages').val().trim().toLowerCase() : '';
		var $allRows = $('#wpac-pages-table tbody tr.wpac-page-row');

		if ($allRows.length === 0) {
			$('#wpac-pagination-info').text('No hay páginas para mostrar.');
			$('#wpac-page-numbers').empty();
			$('#wpac-prev-page, #wpac-next-page').prop('disabled', true);
			return;
		}

		var $matchingRows = $allRows.filter(function () {
			var title = $(this).data('title') || '';
			var id = $(this).data('id') ? $(this).data('id').toString() : '';
			if (!searchQuery) return true;
			return title.indexOf(searchQuery) !== -1 || id.indexOf(searchQuery) !== -1;
		});

		var totalMatches = $matchingRows.length;
		var totalPages = Math.ceil(totalMatches / itemsPerPage) || 1;

		if (currentPage > totalPages) {
			currentPage = totalPages;
		}
		if (currentPage < 1) {
			currentPage = 1;
		}

		$allRows.hide();

		var startIndex = (currentPage - 1) * itemsPerPage;
		var endIndex = startIndex + itemsPerPage;
		$matchingRows.slice(startIndex, endIndex).show();

		// Info text
		if (totalMatches === 0) {
			$('#wpac-pagination-info').text('No se encontraron páginas con "' + searchQuery + '"');
		} else {
			var displayStart = startIndex + 1;
			var displayEnd = Math.min(endIndex, totalMatches);
			$('#wpac-pagination-info').text('Mostrando ' + displayStart + '-' + displayEnd + ' de ' + totalMatches + ' páginas');
		}

		// Botones Anterior / Siguiente
		$('#wpac-prev-page').prop('disabled', currentPage <= 1);
		$('#wpac-next-page').prop('disabled', currentPage >= totalPages);

		var $numContainer = $('#wpac-page-numbers');
		$numContainer.empty();

		for (var p = 1; p <= totalPages; p++) {
			var $pageBtn = $('<button type="button" class="button wpac-page-num-btn"></button>')
				.text(p)
				.toggleClass('active', p === currentPage)
				.attr('data-page', p);
			$numContainer.append($pageBtn);
		}
	}

	$(document).on('click', '.wpac-page-num-btn', function (e) {
		e.preventDefault();
		currentPage = parseInt($(this).attr('data-page'), 10);
		renderTablePagination();
	});

	$('#wpac-prev-page').on('click', function (e) {
		e.preventDefault();
		if (currentPage > 1) {
			currentPage--;
			renderTablePagination();
		}
	});

	$('#wpac-next-page').on('click', function (e) {
		e.preventDefault();
		currentPage++;
		renderTablePagination();
	});

	$('#wpac-search-pages').on('input', function () {
		currentPage = 1;
		renderTablePagination();
	});

	// Inicializar paginación al cargar
	renderTablePagination();

	// 1. Alternar Subcampos según selección de Radio Buttons (Textos)
	$('input[name="text_source"]').on('change', function () {
		var val = $(this).val();
		if ('gemini' === val) {
			$('#subfields-gemini').removeClass('wpac-hidden');
			$('#subfields-scrape').addClass('wpac-hidden');
		} else if ('scrape' === val) {
			$('#subfields-gemini').addClass('wpac-hidden');
			$('#subfields-scrape').removeClass('wpac-hidden');
		} else {
			// 'none' - Ocultar ambos subcampos de texto
			$('#subfields-gemini').addClass('wpac-hidden');
			$('#subfields-scrape').addClass('wpac-hidden');
		}
	});

	// Alternar Subcampos (Imágenes) y actualizar nota explicativa de API
	$('input[name="image_source"]').on('change', function () {
		var val = $(this).val();
		var $note = $('#wpac-selected-api-note');
		if ('pexels' === val || 'unsplash' === val || 'pixabay' === val || 'scrape' === val) {
			$('#subfields-pexels').removeClass('wpac-hidden');
			if ('pexels' === val) {
				$note.html('Se utilizarán fotografías en alta resolución desde <strong>Pexels API</strong> (Predeterminada).');
			} else if ('unsplash' === val) {
				$note.html('Se utilizarán fotografías profesionales desde <strong>Unsplash API</strong> (vía Access Key).');
			} else if ('pixabay' === val) {
				$note.html('Se utilizarán imágenes libres desde <strong>Pixabay API</strong>.');
			} else if ('scrape' === val) {
				$note.html('Se extraerán las imágenes de la URL de scraping ingresada arriba. Puedes indicar una palabra clave opcional como respaldo.');
			}
		} else {
			$('#subfields-pexels').addClass('wpac-hidden');
		}
	});

	// 2. Control de Checkboxes "Seleccionar Todas" (Filtra solo las visibles)
	$('#wpac-select-all-pages, #cb-select-all').on('change', function () {
		var checked = $(this).is(':checked');
		var searchQuery = $('#wpac-search-pages').val() ? $('#wpac-search-pages').val().trim().toLowerCase() : '';
		var $allRows = $('#wpac-pages-table tbody tr.wpac-page-row');

		$allRows.filter(function () {
			var title = $(this).data('title') || '';
			var id = $(this).data('id') ? $(this).data('id').toString() : '';
			if (!searchQuery) return true;
			return title.indexOf(searchQuery) !== -1 || id.indexOf(searchQuery) !== -1;
		}).find('.wpac-page-checkbox').prop('checked', checked);

		$('#wpac-select-all-pages, #cb-select-all').prop('checked', checked);
	});

	// 3. Guardar Claves API mediante AJAX
	$('#wpac-save-keys-btn').on('click', function (e) {
		e.preventDefault();
		var $btn = $(this);
		var $status = $('#wpac-keys-status');

		var geminiKey = ($('#gemini_api_key').val() || '').trim();
		var pexelsKey = ($('#pexels_api_key').val() || '').trim();
		var unsplashKey = ($('#unsplash_api_key').val() || '').trim();
		var pixabayKey = ($('#pixabay_api_key').val() || '').trim();
		var iconfinderKey = ($('#iconfinder_api_key').val() || '').trim();

		$btn.prop('disabled', true);
		$status.html('<span class="wpac-spinner wpac-spinner-dark"></span> Guardando...').css('color', '#64748b');

		$.ajax({
			url: WPAutocontent.ajax_url,
			type: 'POST',
			data: {
				action: 'wp_autocontent_save_settings',
				nonce: WPAutocontent.nonce,
				gemini_key: geminiKey,
				pexels_key: pexelsKey,
				unsplash_key: unsplashKey,
				pixabay_key: pixabayKey,
				iconfinder_key: iconfinderKey
			},
			success: function (response) {
				$btn.prop('disabled', false);
				if (response.success) {
					$status.text(response.data.message).css('color', '#10b981');
				} else {
					$status.text(response.data.message || 'Error al guardar').css('color', '#ef4444');
				}
			},
			error: function () {
				$btn.prop('disabled', false);
				$status.text('Error de conexión en el servidor.').css('color', '#ef4444');
			}
		});
	});

	// 3b. Probar Conexión en vivo por cada Clave API Individual
	$(document).on('click', '.wpac-test-single-key-btn', function (e) {
		e.preventDefault();
		e.stopPropagation();
		var $btn = $(this);
		var provider = $btn.data('provider');
		var $input = $('#' + provider + '_api_key');
		var apiKey = ($input.val() || '').trim();
		var $status = $('#wpac-status-' + provider);

		if (!apiKey) {
			$status.removeClass('status-success status-error status-testing').addClass('status-error').html('⚠️ Ingresa una clave en el campo primero.');
			return;
		}

		$btn.prop('disabled', true).html('<span class="wpac-spinner wpac-spinner-dark"></span>');
		$status.removeClass('status-success status-error').addClass('status-testing').html('<span class="wpac-spinner wpac-spinner-dark"></span> Verificando conexión en tiempo real...');

		$.ajax({
			url: WPAutocontent.ajax_url,
			type: 'POST',
			data: {
				action: 'wp_autocontent_test_single_key',
				nonce: WPAutocontent.nonce,
				provider: provider,
				api_key: apiKey
			},
			success: function (response) {
				$btn.prop('disabled', false).html('<span class="dashicons dashicons-yes-alt"></span> Probar');
				if (response.success) {
					$status.removeClass('status-testing status-error').addClass('status-success').html('🟢 ' + response.data.message);
				} else {
					$status.removeClass('status-testing status-success').addClass('status-error').html('🔴 ' + (response.data.message || 'Error al autenticar clave.'));
				}
			},
			error: function (xhr, status, error) {
				$btn.prop('disabled', false).html('<span class="dashicons dashicons-yes-alt"></span> Probar');
				$status.removeClass('status-testing status-success').addClass('status-error').html('🔴 Error de red/servidor: ' + error);
			}
		});
	});

	// 4. Ejecución del Procesamiento por Lotes
	$('#wpac-run-process-btn').on('click', function (e) {
		e.preventDefault();
		var $btn = $(this);

		var selectedPages = [];
		$('.wpac-page-checkbox:checked').each(function () {
			selectedPages.push($(this).val());
		});

		if (selectedPages.length === 0) {
			alert(WPAutocontent.strings.select_page);
			return;
		}

		var textSource = $('input[name="text_source"]:checked').val() || 'gemini';
		var imageSource = $('input[name="image_source"]:checked').val() || 'pexels';
		var iconSource = $('input[name="icon_source"]:checked').val() || 'none';
		var scrapeUrl = ($('#scrape_url').val() || '').trim();
		var geminiSector = ($('#gemini_sector').val() || '').trim();
		var geminiTone = $('#gemini_tone').val() || 'Profesional y cercano';
		var pexelsKw = ($('#pexels_keyword').val() || '').trim();
		var geminiKey = ($('#gemini_api_key').val() || '').trim();
		var pexelsKey = ($('#pexels_api_key').val() || '').trim();
		var unsplashKey = ($('#unsplash_api_key').val() || '').trim();
		var pixabayKey = ($('#pixabay_api_key').val() || '').trim();
		var iconfinderKey = ($('#iconfinder_api_key').val() || '').trim();

		if ('scrape' === textSource && !scrapeUrl) {
			alert(WPAutocontent.strings.invalid_url);
			return;
		}
		if ('gemini' === textSource && !geminiSector) {
			alert(WPAutocontent.strings.invalid_sector);
			return;
		}
		if (('pexels' === imageSource || 'unsplash' === imageSource || 'pixabay' === imageSource) && !pexelsKw) {
			alert(WPAutocontent.strings.invalid_keyword);
			return;
		}

		$btn.prop('disabled', true).html('<span class="wpac-spinner"></span> Procesando...');
		$('#wpac-progress-container, #wpac-log-container').removeClass('wpac-hidden');
		$('#wpac-log-console').empty();
		$('#wpac-progress-fill').addClass('is-preparing').css('width', '15%');

		var prepText = '<span class="wpac-spinner wpac-spinner-dark"></span> Analizando ' + selectedPages.length + ' páginas y generando pool de contenidos...';
		updateProgressBar(15, prepText);

		logToConsole('🔍 Analizando la estructura de las ' + selectedPages.length + ' páginas seleccionadas...', 'info');
		if ('gemini' === textSource) {
			logToConsole('🤖 Generando copy contextual con la IA de Google Gemini para el sector "' + geminiSector + '"...', 'info');
		} else if ('scrape' === textSource) {
			logToConsole('🌐 Escaneando la URL externa "' + scrapeUrl + '"...', 'info');
		}

		if ('pexels' === imageSource || 'unsplash' === imageSource || 'pixabay' === imageSource) {
			logToConsole('🖼️ Descargando e importando imágenes en alta resolución desde ' + imageSource.toUpperCase() + ' para la temática "' + pexelsKw + '"...', 'info');
		}

		if ('none' !== iconSource) {
			logToConsole('🎨 Obteniendo e importando iconos PNG transparentes desde ' + iconSource.toUpperCase() + '...', 'info');
		}

		var autoCreateSections = $('#auto_create_sections').is(':checked') ? 1 : 0;

		// Paso 1: Pre-analizar páginas y preparar el Pool de Contenidos
		$.ajax({
			url: WPAutocontent.ajax_url,
			type: 'POST',
			data: {
				action: 'wp_autocontent_prepare_pool',
				nonce: WPAutocontent.nonce,
				gemini_key: geminiKey,
				pexels_key: pexelsKey,
				unsplash_key: unsplashKey,
				pixabay_key: pixabayKey,
				iconfinder_key: iconfinderKey,
				text_source: textSource,
				image_source: imageSource,
				icon_source: iconSource,
				scrape_url: scrapeUrl,
				gemini_sector: geminiSector,
				gemini_tone: geminiTone,
				pexels_keyword: pexelsKw,
				auto_create_sections: autoCreateSections,
				page_ids: selectedPages
			},
			success: function (response) {
				$('#wpac-progress-fill').removeClass('is-preparing');

				if (!response.success) {
					logToConsole(WPAutocontent.strings.error_occurred + ' ' + response.data.message, 'error');
					updateProgressBar(0, 'Error en la preparación');
					resetButton($btn);
					return;
				}

				logToConsole('✔ ' + response.data.message, 'success');
				processPagesQueue(selectedPages, 0, $btn);
			},
			error: function (xhr, status, error) {
				$('#wpac-progress-fill').removeClass('is-preparing');
				logToConsole(WPAutocontent.strings.error_occurred + ' ' + error, 'error');
				updateProgressBar(0, 'Error de servidor');
				resetButton($btn);
			}
		});
	});

	/**
	 * Procesa secuencialmente las páginas seleccionadas enviando peticiones AJAX una a una.
	 */
	function processPagesQueue(pages, index, $btn) {
		var total = pages.length;

		if (index >= total) {
			updateProgressBar(100, DevXpertDP_strings_complete());
			logToConsole(DevXpertDP_strings_complete(), 'success');
			resetButton($btn);
			return;
		}

		var pageId = pages[index];
		var pageTitle = $('input[value="' + pageId + '"]').closest('tr').find('td strong').text() || ('#' + pageId);
		var percent = Math.round(((index) / total) * 100);

		var statusText = '<span class="wpac-spinner wpac-spinner-dark"></span> Procesando página ' + (index + 1) + ' de ' + total + ': <strong>' + pageTitle + '</strong>...';

		updateProgressBar(percent, statusText);
		logToConsole('⚡ Sustituyendo elementos en "' + pageTitle + '" (ID: ' + pageId + ')...', 'info');

		$.ajax({
			url: WPAutocontent.ajax_url,
			type: 'POST',
			data: {
				action: 'wp_autocontent_process_page',
				nonce: WPAutocontent.nonce,
				page_id: pageId
			},
			success: function (response) {
				if (response.success) {
					var stats = response.data.stats;
					var summary = 'Página "' + pageTitle + '" (# ' + pageId + ') completada -> Titulares: ' + stats.headings_replaced + ' | Párrafos: ' + stats.paragraphs_replaced + ' | Imágenes/Fondos: ' + stats.images_replaced + (stats.icons_replaced ? ' | Iconos PNG: ' + stats.icons_replaced : '') + (stats.sections_created ? ' | Nuevas Secciones Elementor: ' + stats.sections_created : '');
					logToConsole('✔ ' + summary, 'success');
				} else {
					logToConsole('✖ Error en página "' + pageTitle + '" (ID: ' + pageId + '): ' + response.data.message, 'error');
				}

				processPagesQueue(pages, index + 1, $btn);
			},
			error: function (xhr, status, error) {
				logToConsole('✖ Error de servidor al procesar página ID ' + pageId + ': ' + error, 'error');
				processPagesQueue(pages, index + 1, $btn);
			}
		});
	}

	function DevXpertDP_strings_complete() {
		return '¡Proceso completado con éxito para todas las páginas!';
	}

	function resetButton($btn) {
		$btn.prop('disabled', false).html('<span class="dashicons dashicons-controls-play"></span> Comenzar Procesamiento de Demo');
	}

	function updateProgressBar(percent, htmlText) {
		$('#wpac-progress-fill').css('width', percent + '%');
		$('#wpac-progress-percent').text(percent + '%');
		$('#wpac-progress-status-text').html(htmlText);
	}

	function logToConsole(message, type) {
		var logConsole = $('#wpac-log-console');
		var timestamp = new Date().toLocaleTimeString();
		var typeClass = type ? 'log-' + type : 'log-info';

		var line = $('<div class="wpac-log-line ' + typeClass + '"></div>')
			.html('[' + timestamp + '] ' + message);

		logConsole.append(line);
		logConsole.scrollTop(logConsole[0].scrollHeight);
	}

	// 5. Comprobar Actualizaciones desde GitHub
	$(document).on('click', '#wpac-check-update-btn', function (e) {
		e.preventDefault();
		var $btn = $(this);
		var $status = $('#wpac-update-status');

		$btn.prop('disabled', true).find('.dashicons').addClass('wpac-spin');
		$status.html('<span class="wpac-spinner wpac-spinner-dark"></span> Consultando GitHub...').css('color', '#c7d2fe');

		$.ajax({
			url: WPAutocontent.ajax_url,
			type: 'POST',
			data: {
				action: 'wp_autocontent_check_updates',
				nonce: WPAutocontent.nonce
			},
			success: function (response) {
				$btn.prop('disabled', false).find('.dashicons').removeClass('wpac-spin');
				if (response.success) {
					$status.html(response.data.message).css('color', response.data.has_update ? '#4ade80' : '#e0f2fe');
				} else {
					$status.html('⚠️ ' + (response.data.message || 'Error al comprobar')).css('color', '#f87171');
				}
			},
			error: function (xhr, status, error) {
				$btn.prop('disabled', false).find('.dashicons').removeClass('wpac-spin');
				$status.html('🔴 Error de conexión con el servidor: ' + error).css('color', '#f87171');
			}
		});
	});

	// 5b. Ejecutar Actualización In-Situ desde el mismo plugin sin parpadeos
	$(document).on('click', '#wpac-install-update-btn', function (e) {
		e.preventDefault();
		var $btn = $(this);
		var $status = $('#wpac-update-status');

		$btn.prop('disabled', true).html('<span class="wpac-spinner wpac-spinner-dark"></span> Instalando...');
		$status.html('<span class="wpac-spinner wpac-spinner-dark"></span> Descargando e instalando actualización en segundo plano...').css('color', '#c7d2fe');

		$.ajax({
			url: WPAutocontent.ajax_url,
			type: 'POST',
			data: {
				action: 'wp_autocontent_install_update',
				nonce: WPAutocontent.nonce
			},
			success: function (response) {
				if (response.success) {
					$status.html('<span style="color:#4ade80;font-weight:700;">' + response.data.message + '</span>');
					setTimeout(function () {
						window.location.reload();
					}, 1000);
				} else {
					$btn.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> Reintentar actualización');
					$status.html('🔴 ' + (response.data.message || 'Error al instalar la actualización.')).css('color', '#f87171');
				}
			},
			error: function (xhr, status, error) {
				$btn.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> Reintentar actualización');
				$status.html('🔴 Error de servidor al instalar: ' + error).css('color', '#f87171');
			}
		});
	});

	// ==========================================
	// 6. PESTAÑA 3: REEMPLAZO QUIRÚRGICO DE IMÁGENES
	// ==========================================
	var swapperPage = 1;
	var activePostId = 0;
	var activeImageKey = '';

	function loadSwapperItems() {
		var postType = $('#wpac-swapper-post-type').val() || 'all';
		var search = ($('#wpac-swapper-search').val() || '').trim();
		var $tbody = $('#wpac-swapper-tbody');

		$tbody.html('<tr><td colspan="5"><span class="wpac-spinner wpac-spinner-dark"></span> Cargando contenidos...</td></tr>');

		$.ajax({
			url: WPAutocontent.ajax_url,
			type: 'POST',
			data: {
				action: 'wp_autocontent_get_content_items',
				nonce: WPAutocontent.nonce,
				post_type: postType,
				search: search,
				page: swapperPage
			},
			success: function (response) {
				if (!response.success) {
					$tbody.html('<tr><td colspan="5">Error al cargar contenidos: ' + response.data.message + '</td></tr>');
					return;
				}

				var items = response.data.items || [];
				if (items.length === 0) {
					$tbody.html('<tr><td colspan="5">No se encontraron páginas o entradas.</td></tr>');
					$('#wpac-swapper-pagination-info').text('0 elementos');
					$('#wpac-swapper-prev-page, #wpac-swapper-next-page').prop('disabled', true);
					return;
				}

				var html = '';
				$.each(items, function (idx, item) {
					html += '<tr>';
					html += '<td><strong>' + item.title + '</strong></td>';
					html += '<td><span class="wpac-badge wpac-badge-info">' + item.post_type + '</span></td>';
					html += '<td><span class="wpac-status-pill status-' + item.status + '">' + item.status + '</span></td>';
					html += '<td><code>#' + item.ID + '</code></td>';
					html += '<td style="text-align: right;"><button type="button" class="button button-small wpac-inspect-btn" data-id="' + item.ID + '"><span class="dashicons dashicons-search"></span> Inspeccionar fotos</button></td>';
					html += '</tr>';
				});

				$tbody.html(html);
				$('#wpac-swapper-pagination-info').text('Página ' + response.data.current_page + ' de ' + response.data.total_pages + ' (' + response.data.total_items + ' total)');
				$('#wpac-swapper-prev-page').prop('disabled', response.data.current_page <= 1);
				$('#wpac-swapper-next-page').prop('disabled', response.data.current_page >= response.data.total_pages);
			},
			error: function () {
				$tbody.html('<tr><td colspan="5">Error de servidor al cargar lista.</td></tr>');
			}
		});
	}

	// Al hacer clic en la pestaña de reemplazo de imágenes, cargar la tabla
	$(document).on('click', '.wpac-tab-btn[data-tab="tab-image-swapper"]', function () {
		swapperPage = 1;
		loadSwapperItems();
	});

	$('#wpac-swapper-post-type').on('change', function () {
		swapperPage = 1;
		loadSwapperItems();
	});

	$('#wpac-swapper-search').on('input', function () {
		swapperPage = 1;
		loadSwapperItems();
	});

	$('#wpac-swapper-prev-page').on('click', function (e) {
		e.preventDefault();
		if (swapperPage > 1) {
			swapperPage--;
			loadSwapperItems();
		}
	});

	$('#wpac-swapper-next-page').on('click', function (e) {
		e.preventDefault();
		swapperPage++;
		loadSwapperItems();
	});

	// Inspeccionar Imágenes de un Post Seleccionado
	$(document).on('click', '.wpac-inspect-btn', function (e) {
		e.preventDefault();
		var postId = $(this).data('id');
		activePostId = postId;
		var $inspector = $('#wpac-inspector-container');
		var $grid = $('#wpac-inspector-grid');

		$inspector.removeClass('wpac-hidden');
		$('#wpac-inspector-title').html('<span class="wpac-spinner wpac-spinner-dark"></span> Inspeccionando imágenes del ID #' + postId + '...');
		$grid.html('<p><span class="wpac-spinner wpac-spinner-dark"></span> Analizando imágenes del post...</p>');

		$('html, body').animate({ scrollTop: $inspector.offset().top - 40 }, 400);

		$.ajax({
			url: WPAutocontent.ajax_url,
			type: 'POST',
			data: {
				action: 'wp_autocontent_scan_post_images',
				nonce: WPAutocontent.nonce,
				post_id: postId
			},
			success: function (response) {
				if (!response.success) {
					$grid.html('<p class="wpac-error">Error: ' + response.data.message + '</p>');
					return;
				}

				$('#wpac-inspector-title').text('Imágenes en "' + response.data.post_title + '" (ID #' + postId + ')');
				var images = response.data.images || [];

				if (images.length === 0) {
					$grid.html('<p>No se detectaron imágenes o widgets de imagen en esta página/entrada.</p>');
					return;
				}

				var html = '';
				$.each(images, function (idx, img) {
					html += '<div class="wpac-image-card">';
					html += '<div class="wpac-card-badge">' + img.type + '</div>';
					html += '<img src="' + img.url + '" alt="Imagen" class="wpac-card-thumb" />';
					html += '<div class="wpac-card-info">';
					html += '<strong>' + img.context + '</strong>';
					html += '</div>';
					html += '<button type="button" class="button button-primary button-small wpac-trigger-swap-btn" data-key="' + img.key + '" data-url="' + img.url + '"><span class="dashicons dashicons-update"></span> Reemplazar foto</button>';
					html += '</div>';
				});

				$grid.html(html);
			},
			error: function () {
				$grid.html('<p class="wpac-error">Error de servidor al escanear imágenes.</p>');
			}
		});
	});

	$('#wpac-close-inspector-btn').on('click', function (e) {
		e.preventDefault();
		$('#wpac-inspector-container').addClass('wpac-hidden');
	});

	// Abrir Modal de Búsqueda de Foto de Reemplazo
	$(document).on('click', '.wpac-trigger-swap-btn', function (e) {
		e.preventDefault();
		activeImageKey = $(this).data('key');
		$('#wpac-replacement-modal').removeClass('wpac-hidden');
		$('#wpac-modal-search-kw').val('').focus();
		$('#wpac-modal-candidates-grid').html('<p class="description">Haz clic en Buscar Fotos para ver opciones en alta resolución.</p>');
	});

	$('#wpac-close-modal-btn').on('click', function (e) {
		e.preventDefault();
		$('#wpac-replacement-modal').addClass('wpac-hidden');
	});

	$('#wpac-modal-search-btn').on('click', function (e) {
		e.preventDefault();
		var kw = ($('#wpac-modal-search-kw').val() || '').trim();
		if (!kw) {
			alert('Ingresa un término de búsqueda.');
			return;
		}

		var $grid = $('#wpac-modal-candidates-grid');
		$grid.html('<p><span class="wpac-spinner wpac-spinner-dark"></span> Buscando imágenes en alta resolución...</p>');

		$.ajax({
			url: WPAutocontent.ajax_url,
			type: 'POST',
			data: {
				action: 'wp_autocontent_search_replacement_images',
				nonce: WPAutocontent.nonce,
				keyword: kw,
				provider: 'pexels'
			},
			success: function (response) {
				if (!response.success) {
					$grid.html('<p class="wpac-error">' + response.data.message + '</p>');
					return;
				}

				var photos = response.data.images || [];
				if (photos.length === 0) {
					$grid.html('<p>No se encontraron resultados.</p>');
					return;
				}

				var html = '';
				$.each(photos, function (idx, item) {
					html += '<div class="wpac-candidate-card" data-id="' + item.id + '" data-url="' + item.url + '">';
					html += '<img src="' + item.url + '" alt="Candidata" />';
					html += '<button type="button" class="button button-small button-primary wpac-select-candidate-btn">Seleccionar</button>';
					html += '</div>';
				});

				$grid.html(html);
			},
			error: function () {
				$grid.html('<p class="wpac-error">Error de red al buscar imágenes.</p>');
			}
		});
	});

	// Aplicar la foto elegida del modal
	$(document).on('click', '.wpac-select-candidate-btn', function (e) {
		e.preventDefault();
		var $card = $(this).closest('.wpac-candidate-card');
		var newId = $card.data('id');
		var newUrl = $card.data('url');

		if (!activePostId || !activeImageKey || !newUrl) {
			alert('Error en los parámetros de reemplazo.');
			return;
		}

		$(this).prop('disabled', true).text('Aplicando...');

		$.ajax({
			url: WPAutocontent.ajax_url,
			type: 'POST',
			data: {
				action: 'wp_autocontent_apply_image_replacement',
				nonce: WPAutocontent.nonce,
				post_id: activePostId,
				image_key: activeImageKey,
				new_id: newId,
				new_url: newUrl
			},
			success: function (response) {
				$('#wpac-replacement-modal').addClass('wpac-hidden');
				if (response.success) {
					alert('✅ ' + response.data.message);
					// Re-inspeccionar la página para reflejar el cambio
					$('.wpac-inspect-btn[data-id="' + activePostId + '"]').trigger('click');
				} else {
					alert('🔴 Error: ' + response.data.message);
				}
			},
			error: function (xhr, status, error) {
				$('#wpac-replacement-modal').addClass('wpac-hidden');
				alert('🔴 Error de servidor: ' + error);
			}
		});
	});

	// ==========================================
	// 7. PESTAÑA 4: GENERADOR DE BLOG CON IA
	// ==========================================
	$('#wpac-generate-blog-btn').on('click', function (e) {
		e.preventDefault();
		var $btn = $(this);
		var topic = ($('#blog_topic').val() || '').trim();
		var tone = $('#blog_tone').val() || 'Profesional y cercano';
		var status = $('#blog_status').val() || 'draft';
		var includeFeatured = $('#blog_include_featured').is(':checked') ? 1 : 0;
		var includeBody = $('#blog_include_body_images').is(':checked') ? 1 : 0;

		if (!topic) {
			alert('Por favor, escribe una temática o prompt para el artículo de blog.');
			return;
		}

		$btn.prop('disabled', true).html('<span class="wpac-spinner"></span> Generando artículo con Gemini IA...');
		$('#wpac-blog-status-container').removeClass('wpac-hidden');
		$('#wpac-blog-status-text').removeClass('status-success status-error').addClass('status-testing').html('<span class="wpac-spinner wpac-spinner-dark"></span> Redactando post e inyectando fotos de stock...');

		$.ajax({
			url: WPAutocontent.ajax_url,
			type: 'POST',
			data: {
				action: 'wp_autocontent_generate_blog_post',
				nonce: WPAutocontent.nonce,
				topic: topic,
				tone: tone,
				status: status,
				include_featured: includeFeatured,
				include_body_images: includeBody
			},
			success: function (response) {
				$btn.prop('disabled', false).html('<span class="dashicons dashicons-admin-post"></span> Generar y Publicar Artículo de Blog con IA');
				if (response.success) {
					$('#wpac-blog-status-text').removeClass('status-testing status-error').addClass('status-success').html(response.data.message);
					$('#blog_topic').val('');
				} else {
					$('#wpac-blog-status-text').removeClass('status-testing status-success').addClass('status-error').html('🔴 Error: ' + response.data.message);
				}
			},
			error: function (xhr, status, error) {
				$btn.prop('disabled', false).html('<span class="dashicons dashicons-admin-post"></span> Generar y Publicar Artículo de Blog con IA');
				$('#wpac-blog-status-text').removeClass('status-testing status-success').addClass('status-error').html('🔴 Error de servidor: ' + error);
			}
		});
	});
});
