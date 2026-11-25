/**
 * Malka CRM — Inicialización de la Carta Gantt (Frappe Gantt).
 * Modo lectura para el cliente: se muestran fechas, avance y dependencias,
 * pero se ignoran los cambios por arrastre.
 */
( function () {
	'use strict';

	var settings = window.MalkaCRMGantt || {};
	var i18n = settings.i18n || {};

	function formatDate( value ) {
		try {
			var d = new Date( value + 'T00:00:00' );
			return d.toLocaleDateString( document.documentElement.lang || 'es-CL', {
				day: '2-digit',
				month: '2-digit',
				year: 'numeric'
			} );
		} catch ( e ) {
			return value;
		}
	}

	function buildPopup( task ) {
		var notes = task.notes ? '<p class="malka-crm-popup__notes">' + escapeHtml( task.notes ) + '</p>' : '';
		return (
			'<div class="malka-crm-popup">' +
			'<h5>' + escapeHtml( task.name ) + '</h5>' +
			'<p>' + escapeHtml( i18n.start || 'Inicio' ) + ': ' + formatDate( task._start_raw || task.start ) + '</p>' +
			'<p>' + escapeHtml( i18n.end || 'Término' ) + ': ' + formatDate( task._end_raw || task.end ) + '</p>' +
			'<p>' + escapeHtml( i18n.progress || 'Avance' ) + ': ' + ( task.progress || 0 ) + '%</p>' +
			( task.ownerLabel ? '<p>' + escapeHtml( i18n.owner || 'Responsable' ) + ': ' + escapeHtml( task.ownerLabel ) + '</p>' : '' ) +
			notes +
			'</div>'
		);
	}

	function escapeHtml( str ) {
		return String( str ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	/**
	 * Frappe Gantt permite arrastrar barras y handles. En el portal del cliente
	 * el calendario es solo lectura: interceptamos el evento en fase de captura
	 * para que nunca llegue al manejador de la librería, conservando el click
	 * que abre el popup informativo.
	 */
	function lockDragging( element ) {
		[ 'mousedown', 'touchstart', 'pointerdown' ].forEach( function ( type ) {
			element.addEventListener(
				type,
				function ( event ) {
					var bar = event.target.closest ? event.target.closest( '.bar-wrapper, .handle, .handle-group' ) : null;
					if ( bar ) {
						event.stopPropagation();
					}
				},
				true
			);
		} );
	}

	function initChart( element ) {
		var raw = element.getAttribute( 'data-malka-gantt' );
		var tasks;

		try {
			tasks = JSON.parse( raw || '[]' );
		} catch ( e ) {
			tasks = [];
		}

		if ( ! tasks.length ) {
			var empty = document.createElement( 'p' );
			empty.className = 'malka-crm-empty';
			empty.textContent = i18n.empty || 'Sin tareas.';
			element.parentNode.replaceChild( empty, element );
			return null;
		}

		// Guardamos las fechas originales para el popup.
		tasks.forEach( function ( t ) {
			t._start_raw = t.start;
			t._end_raw = t.end;
		} );

		if ( typeof window.Gantt !== 'function' ) {
			return null;
		}

		var chart = new window.Gantt( element, tasks, {
			readonly: true,
			header_height: 50,
			bar_height: 22,
			bar_corner_radius: 4,
			padding: 18,
			view_mode: settings.defaultViewMode || 'Week',
			date_format: 'YYYY-MM-DD',
			language: 'es',
			custom_popup_html: buildPopup,
			on_date_change: function () {
				// Solo lectura en el frontend del cliente.
				return false;
			},
			on_progress_change: function () {
				return false;
			}
		} );

		return chart;
	}

	function bindToolbar( wrapper, chart ) {
		var toolbar = wrapper.parentNode.querySelector( '.malka-crm-gantt-toolbar' );
		if ( ! toolbar || ! chart ) {
			return;
		}

		toolbar.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-malka-view]' );
			if ( ! button ) {
				return;
			}
			event.preventDefault();
			chart.change_view_mode( button.getAttribute( 'data-malka-view' ) );

			Array.prototype.forEach.call( toolbar.querySelectorAll( '[data-malka-view]' ), function ( el ) {
				el.classList.remove( 'is-active' );
			} );
			button.classList.add( 'is-active' );
		} );
	}

	function boot() {
		var charts = document.querySelectorAll( '[data-malka-gantt]' );
		Array.prototype.forEach.call( charts, function ( element ) {
			var wrapper = element.parentNode;
			var chart = initChart( element );

			if ( chart ) {
				lockDragging( element );
			}

			bindToolbar( wrapper, chart );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
