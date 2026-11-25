/* Portal: calendario del mes de cada proyecto y ventanas con el detalle. */
( function () {
	'use strict';
	var MESES = [ 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' ];
	var DIAS = [ 'domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado' ];

	function esc( s ) {
		return String( s ).replace( /[&<>"]/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ c ];
		} );
	}
	function iso( d ) {
		return d.getFullYear() + '-' + ( '0' + ( d.getMonth() + 1 ) ).slice( -2 ) + '-' + ( '0' + d.getDate() ).slice( -2 );
	}
	function larga( f ) {
		var d = new Date( f + 'T12:00:00' );
		return DIAS[ d.getDay() ] + ' ' + d.getDate() + ' de ' + MESES[ d.getMonth() ];
	}
	function corto( n ) {
		return String( n ).replace( /^Publicaci[oó]n\s+(\d+)\s*·\s*/i, 'P$1 · ' );
	}

	function iniciar( box ) {
		if ( box.__cal ) {
			return;
		}
		box.__cal = true;
		var ev = [];
		try {
			ev = JSON.parse( box.querySelector( '[data-cal-json]' ).textContent ) || [];
		} catch ( e ) {
			ev = [];
		}
		var hoy = box.getAttribute( 'data-hoy' );
		var dias = box.querySelector( '[data-cal-dias]' );
		var agenda = box.querySelector( '[data-cal-dia]' );
		var titulo = box.querySelector( '[data-cal-t]' );
		// Abre en el mes de hoy si tiene algo; si no, en el de la cosa más cercana.
		var base = hoy;
		if ( ! ev.some( function ( e ) { return e.f.slice( 0, 7 ) === hoy.slice( 0, 7 ); } ) && ev.length ) {
			var prox = ev.filter( function ( e ) { return e.f >= hoy; } ).sort( function ( a, b ) { return a.f < b.f ? -1 : 1; } )[ 0 ];
			base = prox ? prox.f : ev.slice().sort( function ( a, b ) { return a.f < b.f ? 1 : -1; } )[ 0 ].f;
		}
		var ver = { a: +base.slice( 0, 4 ), m: +base.slice( 5, 7 ) - 1 };
		var elegido = base === hoy ? hoy : null;

		function del( f ) {
			var ORD = { tu: 0, pago: 0, firma: 0, pub: 1, entrega: 2 };
			return ev.filter( function ( e ) { return e.f === f; } ).sort( function ( a, b ) {
				return ( ORD[ a.k ] || 0 ) - ( ORD[ b.k ] || 0 );
			} );
		}
		var conProy = false;
		function chip( e ) {
			var cls = 'esc-mcal__ev is-' + e.k + ( e.ok ? ' is-ok' : '' ) + ( e.tr ? ' is-tarde' : '' );
			var em = { tu: e.tr ? 'Atrasada' : 'Tú', pago: 'Pago', firma: 'Firma', entrega: 'Entrega' }[ e.k ] || e.fmt;
			return '<button type="button" class="' + cls + '" data-cal-ev-id="' + esc( e.id ) + '"' + ( e.p ? ' title="' + esc( e.p ) + '"' : '' ) + '><em>' + esc( em ) + '</em><span>' + esc( corto( e.n ) ) + ( e.p && conProy ? '<small>' + esc( e.p ) + '</small>' : '' ) + '</span></button>';
		}
		function pintar() {
			titulo.textContent = MESES[ ver.m ] + ' ' + ver.a;
			var primero = new Date( ver.a, ver.m, 1 );
			var desde = new Date( primero );
			desde.setDate( 1 - ( ( primero.getDay() + 6 ) % 7 ) );
			var h = '';
			for ( var i = 0; i < 42; i++ ) {
				var d = new Date( desde );
				d.setDate( desde.getDate() + i );
				if ( 35 === i && d.getMonth() !== ver.m ) {
					break;
				}
				var f = iso( d );
				var es = del( f );
				var cls = 'esc-mcal__d' + ( d.getMonth() !== ver.m ? ' is-fuera' : '' ) + ( f === hoy ? ' is-hoy' : '' ) + ( f === elegido ? ' is-elegido' : '' );
				var chips = es.slice( 0, 3 ).map( chip ).join( '' ) + ( es.length > 3 ? '<span class="esc-mcal__mas">+' + ( es.length - 3 ) + ' más</span>' : '' );
				var dots = es.map( function ( e ) {
					return '<i class="' + ( e.ok ? 'is-ok' : ( e.tr ? 'is-tarde' : ( 'pub' === e.k ? 'is-pub' : ( 'entrega' === e.k ? 'is-entrega' : 'is-tu' ) ) ) ) + '"></i>';
				} ).join( '' );
				h += '<div class="' + cls + '" data-cal-d="' + f + '" role="button" tabindex="0" aria-label="' + esc( larga( f ) + ( es.length ? ', ' + es.length + ( 1 === es.length ? ' cosa' : ' cosas' ) : '' ) ) + '"><span class="esc-mcal__n">' + d.getDate() + '</span>' + chips + '<span class="esc-mcal__dots">' + dots + '</span></div>';
			}
			dias.innerHTML = h;
			pintarDia();
		}
		function pintarDia() {
			if ( ! elegido ) {
				agenda.innerHTML = '<p class="esc-mcal__vacio">Toca un día o una publicación para ver el detalle.</p>';
				return;
			}
			var es = del( elegido );
			var t = larga( elegido );
			box.__diaTxt = t.charAt( 0 ).toUpperCase() + t.slice( 1 );
			var detL = box.querySelector( '[data-cal-det-lado]' );
			if ( detL && ! box.__mantener ) {
				detL.hidden = true;
				agenda.hidden = false;
			}
			agenda.innerHTML = '<p class="esc-mcal__dt">' + esc( t.charAt( 0 ).toUpperCase() + t.slice( 1 ) ) + '</p>' + ( es.length
				? '<div class="esc-mcal__ag">' + ( conProy = true, es.map( chip ).join( '' ) ) + '</div>' + ( conProy = false, '' )
				: '<p class="esc-mcal__vacio">Nada para este día.</p>' );
		}

		box.addEventListener( 'click', function ( e ) {
			var v = e.target.closest( '[data-cal-vista]' );
			if ( v ) {
				var cal = 'cal' === v.getAttribute( 'data-cal-vista' );
				box.querySelector( '.esc-mcal__mes' ).hidden = ! cal;
				box.querySelector( '.esc-pd__l' ).hidden = cal;
				box.querySelectorAll( '[data-cal-vista]' ).forEach( function ( b ) {
					b.setAttribute( 'aria-pressed', b === v ? 'true' : 'false' );
				} );
				return;
			}
			var m = e.target.closest( '[data-cal-mover]' );
			if ( m ) {
				var paso = +m.getAttribute( 'data-cal-mover' );
				if ( ! paso ) {
					ver = { a: +hoy.slice( 0, 4 ), m: +hoy.slice( 5, 7 ) - 1 };
					elegido = hoy;
				} else {
					ver.m += paso;
					if ( ver.m < 0 ) { ver.m = 11; ver.a--; }
					if ( ver.m > 11 ) { ver.m = 0; ver.a++; }
				}
				pintar();
				return;
			}
			if ( e.target.closest( '[data-cal-ev-id]' ) ) {
				return; // Lo abre el manejador de las ventanas.
			}
			var d = e.target.closest( '[data-cal-d]' );
			if ( d ) {
				elegido = d.getAttribute( 'data-cal-d' );
				pintar();
			}
		} );
		box.addEventListener( 'keydown', function ( e ) {
			var d = e.target.closest && e.target.closest( '[data-cal-d]' );
			if ( d && ( 'Enter' === e.key || ' ' === e.key ) && ! e.target.closest( '[data-cal-ev-id]' ) ) {
				e.preventDefault();
				d.click();
			}
		} );
		pintar();
		// En pantalla ancha abre con el detalle de la próxima publicación al lado.
		if ( ANCHO.matches ) {
			var sig = ev.filter( function ( e ) { return 'pub' === e.k && ! e.ok && e.f >= hoy; } ).sort( function ( a, b ) { return a.f < b.f ? -1 : 1; } )[ 0 ];
			var ch = sig ? box.querySelector( '.esc-mcal__dias [data-cal-ev-id="' + sig.id + '"]' ) : null;
			if ( ch ) {
				ch.click();
			}
		}
	}

	var ANCHO = window.matchMedia ? window.matchMedia( '(min-width: 1000px)' ) : { matches: false };

	// El detalle: al lado del calendario en pantallas anchas; si no, en una
	// ventana encima de la ficha del proyecto (también desde la lista).
	document.addEventListener( 'click', function ( e ) {
		var ir = e.target.closest( '[data-cal-ir]' );
		if ( ir ) {
			var dl = ir.closest( 'dialog' );
			if ( dl ) {
				dl.close();
			}
			return; // El enlace sigue a su sección.
		}
		var volver = e.target.closest( '[data-cal-volver]' );
		if ( volver ) {
			var lado0 = volver.closest( '.esc-mcal__lado' );
			lado0.querySelector( '[data-cal-det-lado]' ).hidden = true;
			lado0.querySelector( '[data-cal-dia]' ).hidden = false;
			lado0.closest( '[data-cal]' ).querySelectorAll( '.esc-mcal__ev.is-activo' ).forEach( function ( x ) { x.classList.remove( 'is-activo' ); } );
			return;
		}
		var b = e.target.closest( '[data-cal-ev-id], [data-cal-ev]' );
		if ( ! b || 'SCRIPT' === b.tagName ) {
			return;
		}
		var id = b.getAttribute( 'data-cal-ev-id' ) || b.getAttribute( 'data-cal-ev' );
		var raiz = b.closest( '[data-cal]' ) || b.closest( '.esc-pd' ) || document;
		var tpl = raiz.querySelector( 'template[data-cal-det="' + ( window.CSS && CSS.escape ? CSS.escape( id ) : id ) + '"]' );
		var pop = raiz.querySelector( '.esc-mcal__pop' );
		if ( ! tpl || ! pop ) {
			return;
		}
		e.preventDefault();
		e.stopPropagation();
		var mes = b.closest( '.esc-mcal__mes' );
		if ( mes && ANCHO.matches ) {
			var lado = mes.querySelector( '.esc-mcal__lado' );
			var det = lado.querySelector( '[data-cal-det-lado]' );
			var ev0 = mes.closest( '[data-cal]' );
			det.innerHTML = '<button type="button" class="esc-mcal__volver" data-cal-volver>‹ ' + ( ev0.__diaTxt ? esc( ev0.__diaTxt ) : 'Volver' ) + '</button>';
			det.appendChild( tpl.content.cloneNode( true ) );
			det.hidden = false;
			lado.querySelector( '[data-cal-dia]' ).hidden = true;
			mes.querySelectorAll( '.esc-mcal__ev.is-activo' ).forEach( function ( x ) { x.classList.remove( 'is-activo' ); } );
			mes.querySelectorAll( '[data-cal-ev-id="' + id + '"]' ).forEach( function ( x ) { x.classList.add( 'is-activo' ); } );
			return;
		}
		var c = pop.querySelector( '.esc-mcal__popc' );
		c.innerHTML = '';
		c.appendChild( tpl.content.cloneNode( true ) );
		if ( pop.showModal && ! pop.open ) {
			pop.showModal();
		}
		pop.scrollTop = 0;
	}, true );

	// El calendario se arma cuando se abre la ficha del proyecto.
	function todos() {
		document.querySelectorAll( '[data-cal]' ).forEach( iniciar );
	}
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', todos );
	} else {
		todos();
	}
}() );

/* Portal: grabar una respuesta de voz para el estudio. */
( function () {
	'use strict';
	var rec = null;
	var trozos = [];
	var blob = null;
	var t0 = 0;
	var reloj = null;
	function fmt( s ) {
		var m = Math.floor( s / 60 );
		var x = Math.floor( s % 60 );
		return m + ':' + ( x < 10 ? '0' : '' ) + x;
	}
	document.addEventListener( 'click', function ( e ) {
		var box = e.target.closest( '[data-grab]' );
		if ( ! box ) {
			return;
		}
		var est = box.querySelector( '[data-grab-est]' );
		var btn = box.querySelector( '[data-grab-rec]' );
		var fin = box.querySelector( '[data-grab-fin]' );
		if ( e.target.closest( '[data-grab-rec]' ) || e.target.closest( '[data-grab-otra]' ) ) {
			e.preventDefault();
			e.stopPropagation();
			if ( rec && 'recording' === rec.state ) {
				rec.stop();
				return;
			}
			if ( ! navigator.mediaDevices || ! window.MediaRecorder ) {
				est.textContent = 'Tu navegador no permite grabar. Prueba con Chrome o Safari actualizados.';
				return;
			}
			fin.hidden = true;
			btn.hidden = false;
			navigator.mediaDevices.getUserMedia( { audio: true } ).then( function ( st ) {
				trozos = [];
				var op = {};
				[ 'audio/webm;codecs=opus', 'audio/mp4', 'audio/ogg' ].some( function ( m ) {
					if ( MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported( m ) ) {
						op.mimeType = m;
						return true;
					}
					return false;
				} );
				rec = new MediaRecorder( st, op );
				rec.ondataavailable = function ( ev ) {
					if ( ev.data.size ) {
						trozos.push( ev.data );
					}
				};
				rec.onstop = function () {
					st.getTracks().forEach( function ( x ) { x.stop(); } );
					clearInterval( reloj );
					blob = new Blob( trozos, { type: rec.mimeType || 'audio/webm' } );
					box.querySelector( '[data-grab-audio]' ).src = URL.createObjectURL( blob );
					btn.hidden = true;
					fin.hidden = false;
					box.classList.remove( 'is-rec' );
					btn.querySelector( 'span' ).textContent = 'Grabar una respuesta';
					est.textContent = 'Escúchala. Si te gusta, envíala.';
				};
				rec.start();
				t0 = Date.now();
				box.classList.add( 'is-rec' );
				btn.querySelector( 'span' ).textContent = 'Detener';
				est.textContent = 'Grabando · 0:00';
				reloj = setInterval( function () {
					est.textContent = 'Grabando · ' + fmt( ( Date.now() - t0 ) / 1000 );
				}, 500 );
			} ).catch( function () {
				est.textContent = 'No pude usar el micrófono. Revisa el permiso del navegador.';
			} );
			return;
		}
		if ( e.target.closest( '[data-grab-enviar]' ) && blob ) {
			e.preventDefault();
			e.stopPropagation();
			var b = e.target.closest( '[data-grab-enviar]' );
			b.disabled = true;
			est.textContent = 'Enviando…';
			var f = new FormData();
			f.append( 'action', 'malka_crm_portal_voz' );
			f.append( 'para', 'resp' );
			[ 'cliente', 'pid', 'tid', 'nonce' ].forEach( function ( k ) {
				f.append( k, box.getAttribute( 'data-' + k ) );
			} );
			f.append( 'audio', blob, 'respuesta.' + ( /mp4/.test( blob.type ) ? 'm4a' : /ogg/.test( blob.type ) ? 'ogg' : 'webm' ) );
			fetch( box.getAttribute( 'data-ajax' ), { method: 'POST', credentials: 'same-origin', body: f } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( j ) {
					b.disabled = false;
					if ( j && j.success ) {
						est.textContent = '¡Listo! Le llegó tu grabación al estudio.';
						fin.hidden = true;
						btn.hidden = false;
						btn.querySelector( 'span' ).textContent = 'Grabar de nuevo';
					} else {
						est.textContent = ( j && j.data && j.data.message ) || 'No se pudo enviar.';
					}
				} )
				.catch( function () {
					b.disabled = false;
					est.textContent = 'No se pudo enviar.';
				} );
		}
	}, true );
}() );
