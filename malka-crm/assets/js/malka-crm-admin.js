/**
 * Malka CRM — Repetidores de WP-Admin: tareas del proyecto y abonos de la cotización.
 */
( function ( $ ) {
	'use strict';

	function refreshStageOptions( $body ) {
		var stages = [];

		$body.find( '.malka-crm-task-row' ).each( function () {
			var $row = $( this );
			if ( 'stage' === $row.find( '.malka-crm-type' ).val() ) {
				stages.push( {
					id: $row.find( 'input[name*="[id]"]' ).val(),
					name: $row.find( 'input[name*="[name]"]' ).val()
				} );
			}
		} );

		$body.find( '.malka-crm-stage-select' ).each( function () {
			var $select = $( this );
			var current = $select.val();
			var options = '<option value="">— Sin etapa —</option>';

			stages.forEach( function ( stage ) {
				if ( ! stage.id ) {
					return;
				}
				options += '<option value="' + stage.id + '">' + ( stage.name || stage.id ) + '</option>';
			} );

			$select.html( options ).val( current );
		} );
	}

	function applyRowState( $row ) {
		var isStage = 'stage' === $row.find( '.malka-crm-type' ).val();

		$row.toggleClass( 'is-stage', isStage );
		$row.find( '.malka-crm-stage-select, .malka-crm-owner, input[name*="[due_days]"]' ).prop( 'disabled', isStage );
		$row.find( 'input[type="date"], .malka-crm-progress' ).prop( 'readonly', isStage );
	}

	$( function () {

		var $body = $( '#malka-crm-tasks-body' );

		if ( $body.length ) {

			var taskTemplate = $( '#tmpl-malka-crm-task-row' ).html() || '';
			var stageTemplate = $( '#tmpl-malka-crm-stage-row' ).html() || '';
			var counter = $body.find( '.malka-crm-task-row' ).length;

			function buildFallbackRow( type ) {
				var $last = $body.find( '.malka-crm-task-row' ).last();

				if ( ! $last.length ) {
					window.alert( 'No se pudo cargar la plantilla de filas. Recarga la página (Cmd+Shift+R) e inténtalo de nuevo.' );
					return null;
				}

				var $clone = $last.clone();

				$clone.find( 'input[type="text"], input[type="date"], textarea' ).val( '' );
				$clone.find( 'input[type="number"]' ).val( 0 );
				$clone.find( 'select' ).each( function () {
					this.selectedIndex = 0;
				} );
				$clone.find( '.malka-crm-type' ).val( type );

				// Renumera los name="malka_crm_tasks[N][campo]" de la fila clonada.
				$clone.removeClass( 'is-done is-parent' );
				$clone.find( '[name*="malka_crm_tasks["]' ).each( function () {
					var name = $( this ).attr( 'name' );
					$( this ).attr( 'name', name.replace( /malka_crm_tasks\[[^\]]*\]/, 'malka_crm_tasks[new' + counter + ']' ) );
				} );

				return $clone;
			}

			function addRow( template, prefix ) {
				var $row;

				if ( template && template.replace( /\s/g, '' ).length ) {
					$row = $( $.parseHTML( template.replace( /__INDEX__/g, 'new' + counter ).trim() ) );
				} else {
					$row = buildFallbackRow( 'etapa' === prefix ? 'stage' : 'task' );
				}

				if ( ! $row || ! $row.length ) {
					return;
				}

				$row.find( 'input[name*="[id]"]' ).val( prefix + '-' + ( counter + 1 ) );
				$row.find( 'input[type="date"]' ).val( ( window.MalkaCRMAdmin && window.MalkaCRMAdmin.today ) || '' );

				$body.append( $row );
				counter++;
				refreshHierarchy();
				recalcLive();

				applyRowState( $row );
				refreshStageOptions( $body );
			}

			$( '#malka-crm-add-task, #malka-crm-add-task-2' ).on( 'click', function ( event ) {
				event.preventDefault();
				addRow( taskTemplate, 'tarea' );
			} );

			$( '#malka-crm-add-stage, #malka-crm-add-stage-2' ).on( 'click', function ( event ) {
				event.preventDefault();
				addRow( stageTemplate, 'etapa' );
			} );

			$body.on( 'change', '.malka-crm-type', function () {
				applyRowState( $( this ).closest( '.malka-crm-task-row' ) );
				refreshStageOptions( $body );
			} );

			$body.on( 'blur', 'input[name*="[name]"], input[name*="[id]"]', function () {
				refreshStageOptions( $body );
			} );

			$body.on( 'click', '.malka-crm-remove-task', function ( event ) {
				event.preventDefault();
				var message = ( window.MalkaCRMAdmin && window.MalkaCRMAdmin.confirmDelete ) || '¿Eliminar esta fila?';
				if ( window.confirm( message ) ) {
					$( this ).closest( '.malka-crm-task-row, .malka-crm-payment-row, .malka-crm-template-row' ).remove();
					refreshStageOptions( $body );
				}
			} );

			if ( $.fn.sortable ) {
				$body.sortable( {
					items: '> .malka-crm-task-row',
					handle: '.malka-crm-handle',
					axis: 'y',
					cursor: 'grabbing',
					placeholder: 'malka-crm-drop',
					helper: function ( event, ui ) {
						ui.children().each( function () {
							$( this ).width( $( this ).width() );
						} );
						return ui;
					}
				} );
			}

			$body.find( '.malka-crm-task-row' ).each( function () {
				applyRowState( $( this ) );
			} );
		}

		// Buscador sobre cualquier <select> grande.
		$( '.malka-crm-filter' ).each( function () {
			var $input = $( this );
			var $select = $( $input.data( 'target' ) );

			if ( ! $select.length ) {
				return;
			}

			var all = $select.find( 'option' ).map( function () {
				return { value: this.value, text: this.text };
			} ).get();

			$input.on( 'input', function () {
				var term = $input.val().toLowerCase().trim();
				var current = $select.val();
				var html = '';

				all.forEach( function ( opt ) {
					if ( ! term || opt.text.toLowerCase().indexOf( term ) !== -1 ) {
						html += '<option value="' + opt.value + '">' + $( '<div>' ).text( opt.text ).html() + '</option>';
					}
				} );

				$select.html( html );

				if ( $select.find( 'option[value="' + current + '"]' ).length ) {
					$select.val( current );
				}
			} );
		} );

		// ── Avance en vivo, casillas y colapso de etapas ──────
		// Jerarquía en vivo a partir del número: 0 → 0.4 → 0.4.1
		function numberLevel( value ) {
			value = ( value || '' ).trim();
			if ( ! value ) {
				return 0;
			}
			return value.split( '.' ).filter( function ( part ) {
				return part.length;
			} ).length;
		}

		function numberParent( value ) {
			var parts = ( value || '' ).trim().split( '.' ).filter( function ( part ) {
				return part.length;
			} );
			if ( parts.length < 2 ) {
				return '';
			}
			parts.pop();
			return parts.join( '.' );
		}

		function refreshHierarchy() {
			var $rows = $( '#malka-crm-tasks-body .malka-crm-task-row' );
			var numbers = {};

			$rows.each( function () {
				var num = ( $( this ).find( 'input[name*="[number]"]' ).val() || '' ).trim();
				if ( num ) {
					numbers[ num ] = true;
				}
			} );

			$rows.each( function () {
				var $row = $( this );
				var num = ( $row.find( 'input[name*="[number]"]' ).val() || '' ).trim();
				var level = numberLevel( num );
				var isStage = $row.hasClass( 'is-stage' );

				$row.attr( 'data-level', level );
				$row.toggleClass( 'is-sub', level > 2 );

				// Es padre si alguna otra fila lo declara como su número base.
				var isParent = false;
				if ( num && ! isStage ) {
					$rows.each( function () {
						var other = ( $( this ).find( 'input[name*="[number]"]' ).val() || '' ).trim();
						if ( other && numberParent( other ) === num ) {
							isParent = true;
							return false;
						}
					} );
				}

				$row.toggleClass( 'is-parent', isParent );
				$row.find( '.malka-crm-progress, .malka-crm-done' ).prop( 'disabled', isParent )
					.attr( 'title', isParent ? 'Se calcula desde sus subtareas' : '' );
			} );
		}

		$( document ).on( 'input', 'input[name*="[number]"]', function () {
			refreshHierarchy();
			recalcLive();
		} );

		function recalcLive() {
			var $rows = $( '#malka-crm-tasks-body .malka-crm-task-row' ).not( '.is-stage' ).not( '.is-parent' );
			var total = 0;
			var done = 0;
			var count = 0;

			$rows.each( function () {
				var $row = $( this );
				var isDone = $row.find( '.malka-crm-done' ).is( ':checked' );
				var pct = parseInt( $row.find( '.malka-crm-progress' ).val(), 10 ) || 0;

				count++;
				total += isDone ? 100 : pct;
				if ( isDone || 100 === pct ) {
					done++;
				}
			} );

			var avg = count ? Math.round( total / count ) : 0;

			$( '[data-malka-live]' ).text( avg + '%' ).attr( 'data-malka-live', avg );
			$( '[data-malka-count]' ).text( done + ' / ' + count + ' completadas · ' + ( count - done ) + ' pendientes' );
		}

		$( document ).on( 'change', '.malka-crm-done', function () {
			var $row = $( this ).closest( '.malka-crm-task-row' );
			var checked = $( this ).is( ':checked' );

			$row.toggleClass( 'is-done', checked );
			$row.find( '.malka-crm-progress' ).val( checked ? 100 : 0 );
			recalcLive();
		} );

		$( document ).on( 'input change', '.malka-crm-progress', function () {
			var $row = $( this ).closest( '.malka-crm-task-row' );
			var pct = parseInt( $( this ).val(), 10 ) || 0;

			$row.find( '.malka-crm-done' ).prop( 'checked', 100 === pct );
			$row.toggleClass( 'is-done', 100 === pct );
			recalcLive();
		} );

		$( document ).on( 'click', '.malka-crm-remove-task', recalcLive );

		$( '#malka-crm-collapse-all' ).on( 'click', function ( e ) {
			e.preventDefault();
			$( '#malka-crm-tasks-body .malka-crm-task-row' ).not( '.is-stage' ).hide();
			$( '#malka-crm-tasks-body .is-stage' ).addClass( 'is-collapsed' );
		} );

		$( '#malka-crm-expand-all' ).on( 'click', function ( e ) {
			e.preventDefault();
			$( '#malka-crm-tasks-body .malka-crm-task-row' ).show();
			$( '#malka-crm-tasks-body .is-stage' ).removeClass( 'is-collapsed' );
		} );

		// Clic en el asa de una etapa: pliega o despliega solo esa etapa.
		$( document ).on( 'click', '.is-stage .malka-crm-handle', function ( e ) {
			e.preventDefault();
			var $stage = $( this ).closest( '.malka-crm-task-row' );
			var stageId = $stage.attr( 'data-stage' );
			var collapsed = $stage.toggleClass( 'is-collapsed' ).hasClass( 'is-collapsed' );

			$( '#malka-crm-tasks-body > .malka-crm-task-row' ).not( '.is-stage' ).each( function () {
				if ( String( $( this ).attr( 'data-stage' ) ) === String( stageId ) ) {
					$( this ).toggle( ! collapsed );
				}
			} );
		} );

		refreshHierarchy();
		recalcLive();

		// ── Aplicar plantilla (enlace, nunca un form anidado) ──
		$( '.malka-crm-apply-template' ).on( 'click', function ( event ) {
			event.preventDefault();

			var $box = $( this ).closest( '.malka-crm-template-apply' );
			var confirmText = $box.data( 'confirm' ) || '¿Continuar?';

			if ( ! window.confirm( confirmText ) ) {
				return;
			}

			var params = {
				action: 'malka_crm_apply_template',
				project_id: $box.data( 'project' ),
				template_id: $box.find( '.malka-crm-tpl-select' ).val(),
				start: $box.find( '.malka-crm-tpl-start' ).val(),
				replace: $box.find( '.malka-crm-tpl-replace' ).is( ':checked' ) ? 1 : 0,
				origin: $box.data( 'origin' ) || 'tasks',
				_wpnonce: $box.data( 'nonce' )
			};

			var query = Object.keys( params ).map( function ( key ) {
				return encodeURIComponent( key ) + '=' + encodeURIComponent( params[ key ] );
			} ).join( '&' );

			window.location.href = $box.data( 'url' ) + '?' + query;
		} );

		// ── Editor de plantillas ──────────────────────────────
		var $tpl = $( '#malka-crm-template-body' );

		if ( $tpl.length ) {

			var tplItem = $( '#tmpl-malka-crm-tpl-item' ).html() || '';
			var tplStage = $( '#tmpl-malka-crm-tpl-stage' ).html() || '';
			var tplCounter = $tpl.find( '.malka-crm-template-row' ).length;

			function addTplRow( template ) {
				if ( ! template || ! template.replace( /\s/g, '' ).length ) {
					window.alert( 'No se pudo cargar la plantilla de filas. Recarga la página.' );
					return;
				}
				var $row = $( $.parseHTML( template.replace( /__INDEX__/g, 'new' + tplCounter ).trim() ) );
				$tpl.append( $row );
				tplCounter++;
			}

			$( '#malka-crm-add-tpl-item, #malka-crm-add-tpl-item-2' ).on( 'click', function ( e ) {
				e.preventDefault();
				addTplRow( tplItem );
			} );

			$( '#malka-crm-add-tpl-stage, #malka-crm-add-tpl-stage-2' ).on( 'click', function ( e ) {
				e.preventDefault();
				addTplRow( tplStage );
			} );

			$tpl.on( 'change', '.malka-crm-type', function () {
				var $tr = $( this ).closest( '.malka-crm-template-row' );
				$tr.attr( 'class', 'malka-crm-template-row malka-crm-task-row is-' + $( this ).val() );
			} );

			$tpl.on( 'click', '.malka-crm-remove-task', function ( e ) {
				e.preventDefault();
				var message = ( window.MalkaCRMAdmin && window.MalkaCRMAdmin.confirmDelete ) || '¿Eliminar esta fila?';
				if ( window.confirm( message ) ) {
					$( this ).closest( '.malka-crm-task-row, .malka-crm-payment-row, .malka-crm-template-row' ).remove();
				}
			} );

			if ( $.fn.sortable ) {
				$tpl.sortable( {
					items: '> .malka-crm-template-row',
					handle: '.malka-crm-handle',
					axis: 'y',
					cursor: 'grabbing',
					placeholder: 'malka-crm-drop'
				} );
			}
		}

		var $payments = $( '#malka-crm-payments-body' );

		if ( $payments.length ) {

			var paymentTemplate = $( '#tmpl-malka-crm-payment-row' ).html() || '';
			var payCounter = $payments.find( '.malka-crm-payment-row' ).length;

			$( '#malka-crm-add-payment' ).on( 'click', function ( event ) {
				event.preventDefault();
				$payments.append( $( $.parseHTML( paymentTemplate.replace( /__INDEX__/g, 'new' + payCounter ).trim() ) ) );
				payCounter++;
			} );

			$payments.on( 'click', '.malka-crm-remove-payment', function ( event ) {
				event.preventDefault();
				var message = ( window.MalkaCRMAdmin && window.MalkaCRMAdmin.confirmDelete ) || '¿Eliminar esta fila?';
				if ( window.confirm( message ) ) {
					$( this ).closest( '.malka-crm-task-row, .malka-crm-payment-row, .malka-crm-template-row' ).remove();
				}
			} );
		}
	} );
} )( jQuery );
