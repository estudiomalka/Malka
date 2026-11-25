/**
 * Malka CRM — El cliente marca sus tareas como completadas.
 */
( function () {
	'use strict';

	var config = window.MalkaCRMTasks || {};
	var i18n = config.i18n || {};

	function feedback( block, message, isError ) {
		var node = block.querySelector( '.malka-crm-approval__feedback' );
		if ( ! node ) {
			return;
		}
		node.textContent = message || '';
		node.classList.toggle( 'is-error', !! isError );
	}

	function submit( block, button ) {
		var body = new URLSearchParams();
		body.append( 'action', config.action || 'malka_crm_client_task' );
		body.append( 'project_id', block.getAttribute( 'data-project' ) );
		body.append( 'task_id', block.getAttribute( 'data-task' ) );
		body.append( 'nonce', block.getAttribute( 'data-nonce' ) );

		button.disabled = true;
		feedback( block, i18n.sending || '', false );

		fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				if ( payload && payload.success ) {
					var badge = block.querySelector( '.malka-crm-deadline' );
					if ( badge ) {
						badge.textContent = i18n.done || 'Completada';
						badge.className = 'malka-crm-deadline malka-crm-deadline--done';
					}
					button.remove();
					feedback( block, payload.data.message, false );
				} else {
					button.disabled = false;
					var message = payload && payload.data && payload.data.message
						? payload.data.message
						: ( i18n.error || 'Error' );
					feedback( block, message, true );
				}
			} )
			.catch( function () {
				button.disabled = false;
				feedback( block, i18n.error || 'Error', true );
			} );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '.malka-crm-task-done' ) : null;

		if ( ! button ) {
			return;
		}

		event.preventDefault();

		var block = button.closest( '.malka-crm-clienttask' );

		if ( ! block ) {
			return;
		}

		if ( window.confirm( i18n.confirm || '¿Confirmas?' ) ) {
			submit( block, button );
		}
	} );
} )();
