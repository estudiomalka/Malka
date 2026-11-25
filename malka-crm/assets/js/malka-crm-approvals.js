/**
 * Malka CRM — Aprobaciones del cliente.
 * Envía la decisión a admin-ajax.php y actualiza el bloque sin recargar.
 */
( function () {
	'use strict';

	var config = window.MalkaCRMApprovals || {};
	var i18n = config.i18n || {};


	/* ── Diálogo modal ─────────────────────────────────────── */
	var activeModal = null;

	function closeModal() {
		if ( ! activeModal ) {
			return;
		}

		var data = activeModal;
		activeModal = null;

		if ( data.placeholder && data.placeholder.parentNode ) {
			data.placeholder.parentNode.insertBefore( data.content, data.placeholder );
			data.placeholder.parentNode.removeChild( data.placeholder );
		}

		data.content.hidden = true;

		if ( data.overlay && data.overlay.parentNode ) {
			data.overlay.parentNode.removeChild( data.overlay );
		}

		document.body.classList.remove( 'malka-crm-modal-open' );
	}

	function openModal( content ) {
		if ( ! content ) {
			return;
		}

		closeModal();

		var title = content.getAttribute( 'data-modal-title' ) || '';
		var overlay = document.createElement( 'div' );

		overlay.className = 'malka-crm-modal';
		overlay.innerHTML =
			'<div class="malka-crm-modal__box" role="dialog" aria-modal="true">' +
			'<div class="malka-crm-modal__head">' +
			'<h4>' + title + '</h4>' +
			'<button type="button" class="malka-crm-modal__close" aria-label="Cerrar">&times;</button>' +
			'</div>' +
			'<div class="malka-crm-modal__body"></div>' +
			'</div>';

		var placeholder = document.createComment( 'malka-crm-modal-slot' );
		content.parentNode.insertBefore( placeholder, content );

		overlay.querySelector( '.malka-crm-modal__body' ).appendChild( content );
		content.hidden = false;

		document.body.appendChild( overlay );
		document.body.classList.add( 'malka-crm-modal-open' );

		activeModal = {
			overlay: overlay,
			content: content,
			placeholder: placeholder
		};

		overlay.addEventListener( 'click', function ( event ) {
			if ( event.target === overlay || ( event.target.closest && event.target.closest( '.malka-crm-modal__close' ) ) ) {
				closeModal();
			}
		} );

		var focusable = content.querySelector( 'input, textarea' );
		if ( focusable ) {
			focusable.focus();
		}
	}

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key ) {
			closeModal();
		}
	} );

	function setFeedback( block, message, isError ) {
		var node = block.querySelector( '.malka-crm-approval__feedback' );
		if ( ! node ) {
			return;
		}
		node.textContent = message || '';
		node.classList.toggle( 'is-error', !! isError );
	}

	function toggleBusy( block, busy ) {
		Array.prototype.forEach.call( block.querySelectorAll( 'button' ), function ( btn ) {
			btn.disabled = !! busy;
		} );
	}

	function renderResolved( block, data ) {
		closeModal();

		var badge = block.querySelector( '.malka-crm-chip' );
		var actions = block.querySelector( '.malka-crm-approval__actions' );
		var note = block.querySelector( '.malka-crm-approval__note' );
		var rounds = block.querySelector( '.malka-crm-approval__rounds' );
		var exhausted = block.querySelector( '.malka-crm-approval__exhausted' );

		if ( badge ) {
			badge.textContent = 'approved' === data.status ? 'Aprobada' : 'Corrección enviada';
			badge.className = 'malka-crm-chip malka-crm-chip--' + ( 'approved' === data.status ? 'ok' : 'warn' );
		}

		if ( rounds ) {
			if ( data.roundsTxt ) {
				rounds.textContent = data.roundsTxt;
			} else {
				rounds.remove();
			}
		}

		if ( data.exhausted ) {
			var requestBtn = block.querySelector( '.malka-crm-request' );
			if ( requestBtn ) {
				requestBtn.remove();
			}
			if ( note ) {
				note.remove();
				note = null;
			}
			if ( exhausted ) {
				exhausted.hidden = false;
			}
		}

		if ( 'approved' === data.status ) {
			if ( actions ) {
				actions.remove();
			}
			if ( note ) {
				note.remove();
			}
			var signBox = block.querySelector( '.malka-crm-approval__sign' );
			if ( signBox ) {
				signBox.remove();
			}

			block.classList.add( 'is-approved', 'is-locked' );

			if ( badge && ! block.querySelector( '.malka-crm-locked' ) ) {
				var lock = document.createElement( 'span' );
				lock.className = 'malka-crm-locked';
				lock.textContent = 'Bloqueada';
				badge.parentNode.insertBefore( lock, badge.nextSibling );
			}
		} else if ( 'changes' === data.status ) {
			if ( actions ) {
				actions.remove();
			}
			if ( note ) {
				note.remove();
			}
			block.classList.add( 'is-locked' );
		} else if ( note ) {
			note.hidden = true;
			var textarea = note.querySelector( 'textarea' );
			if ( textarea ) {
				textarea.value = '';
			}
		}

		setFeedback( block, data.message, false );
	}

	function submit( block, decision, note, signer, signature ) {
		var body = new URLSearchParams();
		body.append( 'action', config.action || 'malka_crm_approval' );
		body.append( 'project_id', block.getAttribute( 'data-project' ) );
		body.append( 'task_key', block.getAttribute( 'data-key' ) );
		body.append( 'nonce', block.getAttribute( 'data-nonce' ) );
		body.append( 'decision', decision );
		body.append( 'note', note || '' );
		body.append( 'signer', signer || '' );
		body.append( 'rut', signature || '' );

		toggleBusy( block, true );
		setFeedback( block, i18n.sending || 'Enviando…', false );

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
				toggleBusy( block, false );

				if ( payload && payload.success ) {
					renderResolved( block, payload.data );
				} else {
					var data = ( payload && payload.data ) || {};
					var message = data.message || i18n.error || 'Error';
					setFeedback( block, message, true );

					if ( data.locked ) {
						var lockedActions = block.querySelector( '.malka-crm-approval__actions' );
						if ( lockedActions ) {
							lockedActions.remove();
						}
					}

					if ( data.exhausted ) {
						var requestBtn = block.querySelector( '.malka-crm-request' );
						var noteBox = block.querySelector( '.malka-crm-approval__note' );
						var exhaustedBox = block.querySelector( '.malka-crm-approval__exhausted' );
						if ( requestBtn ) {
							requestBtn.remove();
						}
						if ( noteBox ) {
							noteBox.remove();
						}
						if ( exhaustedBox ) {
							exhaustedBox.hidden = false;
						}
					}
				}
			} )
			.catch( function () {
				toggleBusy( block, false );
				setFeedback( block, i18n.error || 'Error', true );
			} );
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;

		var viewer = target.closest ? target.closest( '.malka-crm-view-request' ) : null;
		if ( viewer ) {
			event.preventDefault();
			var holder = viewer.closest( '.malka-crm-approval' );
			openModal( holder ? holder.querySelector( '.malka-crm-approval__comment' ) : null );
			return;
		}

		var opener = target.closest ? target.closest( '.malka-crm-open-approve' ) : null;
		if ( opener ) {
			event.preventDefault();
			var blockO = opener.closest( '.malka-crm-approval' );
			openModal( blockO ? blockO.querySelector( '.malka-crm-approval__sign' ) : null );
			return;
		}

		var canceller = target.closest ? target.closest( '.malka-crm-cancel-approve' ) : null;
		if ( canceller ) {
			event.preventDefault();
			var blockC = canceller.closest( '.malka-crm-approval' );
			closeModal();
			return;
		}

		var approve = target.closest ? target.closest( '.malka-crm-approve' ) : null;
		if ( approve ) {
			event.preventDefault();

			var blockA = approve.closest( '.malka-crm-approval' );
			if ( ! blockA ) {
				return;
			}

			var signerField = blockA.querySelector( '.malka-crm-signer' );
			var signatureField = blockA.querySelector( '.malka-crm-rut' );
			var signerValue = signerField ? signerField.value.trim() : '';
			var signatureValue = signatureField ? signatureField.value.trim() : '';

			if ( ! signerValue || ! signatureValue ) {
				setFeedback( blockA, 'Escribe tu nombre y tu RUT para aprobar.', true );
				if ( signerField && ! signerValue ) {
					signerField.focus();
				} else if ( signatureField ) {
					signatureField.focus();
				}
				return;
			}

			if ( window.confirm( i18n.confirm || '¿Confirmas la aprobación? Quedará bloqueada.' ) ) {
				submit( blockA, 'approved', '', signerValue, signatureValue );
			}
			return;
		}

		var request = target.closest ? target.closest( '.malka-crm-request' ) : null;
		if ( request ) {
			event.preventDefault();
			var blockR = request.closest( '.malka-crm-approval' );
			openModal( blockR ? blockR.querySelector( '.malka-crm-approval__note' ) : null );
			return;
		}

		var send = target.closest ? target.closest( '.malka-crm-send-note' ) : null;
		if ( send ) {
			event.preventDefault();
			var blockS = send.closest( '.malka-crm-approval' );
			if ( ! blockS ) {
				return;
			}
			var textarea = blockS.querySelector( 'textarea' );
			var value = textarea ? textarea.value.trim() : '';

			if ( ! value ) {
				setFeedback( blockS, i18n.noteHint || '', true );
				if ( textarea ) {
					textarea.focus();
				}
				return;
			}

			submit( blockS, 'changes', value, '', '' );
		}
	} );
} )();

/* Etapas plegables dentro del detalle de tareas. */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var head = event.target.closest ? event.target.closest( '.malka-crm-stage-head' ) : null;

		if ( ! head ) {
			return;
		}

		event.preventDefault();

		var section = head.closest( '.malka-crm-stage' );
		var open = section.classList.toggle( 'is-open' );

		head.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
	} );
} )();
