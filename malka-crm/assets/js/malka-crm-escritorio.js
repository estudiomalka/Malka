/*
 * El escritorio dentro de una ventana emergente va siempre en modo «sólo el
 * contenido» (?embed=1): sin menú de abajo, encabezado ni reloj. Si se llegó
 * sin ese parámetro (un enlace o una redirección de adentro), se recarga con él.
 */
( function () {
	try {
		if ( window.self === window.top || ! document.querySelector( '.malka-esc' ) || document.querySelector( '.malka-esc.is-embed' ) ) {
			return;
		}
		var u = new URL( window.location.href );
		u.searchParams.set( 'embed', '1' );
		window.location.replace( u.toString() );
	} catch ( e ) {}
}() );

/* Adentro de la ventana, los enlaces del escritorio siguen en modo ventana. */
document.addEventListener( 'click', function ( e ) {
	if ( ! document.querySelector( '.malka-esc.is-embed' ) ) {
		return;
	}
	var a = e.target.closest ? e.target.closest( 'a[href]' ) : null;
	if ( ! a || a.target || a.hasAttribute( 'download' ) || a.hasAttribute( 'data-pop' ) ) {
		return;
	}
	try {
		var u = new URL( a.href, window.location.href );
		if ( u.origin !== window.location.origin || /\/wp-admin\/|admin-post\.php|admin-ajax\.php/.test( u.pathname ) || u.searchParams.get( 'embed' ) ) {
			return;
		}
		if ( u.pathname.indexOf( window.location.pathname.split( '/' ).slice( 0, 2 ).join( '/' ) ) !== 0 ) {
			return;
		}
		u.searchParams.set( 'embed', '1' );
		a.href = u.toString();
	} catch ( err ) {}
}, true );

/*
 * El árbol de tareas de un negocio con muchas publicaciones pasa fácil los
 * 1.000 campos que PHP acepta por envío (max_input_vars): lo que sobra se
 * pierde sin aviso y esas tareas se borrarían al guardar. Por eso todas las
 * «tarea[…]» viajan juntas en un solo campo JSON.
 */
function empacarTareas( datos ) {
	var arbol = null;
	var quitar = [];
	datos.forEach( function ( v, k ) {
		if ( 0 !== k.indexOf( 'tarea[' ) || ( typeof File !== 'undefined' && v instanceof File ) ) {
			return;
		}
		quitar.push( k );
		var partes = k.slice( 5 ).match( /\[[^\]]*\]/g ) || [];
		arbol = arbol || {};
		var nodo = arbol;
		partes.forEach( function ( p, i ) {
			var c = p.slice( 1, -1 );
			var ultimo = i === partes.length - 1;
			if ( '' === c ) {
				// «[]»: se agrega a la lista.
				if ( ! Array.isArray( nodo.__l ) ) {
					nodo.__l = [];
				}
				if ( ultimo ) {
					nodo.__l.push( v );
				}
				return;
			}
			if ( ultimo ) {
				nodo[ c ] = v;
			} else {
				if ( typeof nodo[ c ] !== 'object' || null === nodo[ c ] ) {
					nodo[ c ] = {};
				}
				nodo = nodo[ c ];
			}
		} );
	} );
	if ( ! arbol ) {
		return datos;
	}
	quitar.forEach( function ( k ) {
		datos.delete( k );
	} );
	datos.append( '_tarea_json', JSON.stringify( arbol, function ( k, v ) {
		return ( v && typeof v === 'object' && Array.isArray( v.__l ) && 1 === Object.keys( v ).length ) ? v.__l : v;
	} ) );
	return datos;
}

/* El guardado a mano (sin autoguardado) también empaca las tareas. */
document.addEventListener( 'submit', function ( e ) {
	var form = e.target;
	if ( ! form || ! form.querySelector || ! form.querySelector( '[name^="tarea["]' ) || e.defaultPrevented ) {
		return;
	}
	var datos = empacarTareas( new FormData( form ) );
	var json  = datos.get( '_tarea_json' );
	if ( ! json ) {
		return;
	}
	var h = form.querySelector( 'input[name="_tarea_json"]' );
	if ( ! h ) {
		h = document.createElement( 'input' );
		h.type = 'hidden';
		h.name = '_tarea_json';
		form.appendChild( h );
	}
	h.value = json;
	form.querySelectorAll( '[name^="tarea["]' ).forEach( function ( c ) {
		c.setAttribute( 'data-nombre', c.name );
		c.removeAttribute( 'name' );
	} );
	window.setTimeout( function () {
		// Si el envío no salió de la página, los campos recuperan su nombre.
		form.querySelectorAll( '[data-nombre]' ).forEach( function ( c ) {
			c.name = c.getAttribute( 'data-nombre' );
			c.removeAttribute( 'data-nombre' );
		} );
	}, 3000 );
} );

/**
 * Escritorio de Malka CRM.
 *
 * Pomodoro con modo enfoque, saldo editable, registro de movimientos y los dos
 * menús de la cabecera. Sin dependencias: el escritorio no carga jQuery.
 */
/* ── Montos con puntos de miles ──────────────────────────────────────
   escMiles(1390000) → «1.390.000»; escLeer('1.390.000') → 1390000. Todo
   campo con data-miles se reescribe así mientras se escribe, con el cursor
   detrás del mismo dígito. */
window.escMiles = function ( n ) {
	var d = String( Math.round( Math.abs( parseFloat( n ) || 0 ) ) );
	return ( n < 0 ? '-' : '' ) + d.replace( /\B(?=(\d{3})+(?!\d))/g, '.' );
};
window.escLeer = function ( v ) {
	var t = String( v || '' ).trim();
	// «1.390.000» (puntos de miles) o un número limpio («992460»).
	if ( /^-?\$?\s*\d{1,3}(\.\d{3})+$/.test( t ) ) {
		return parseFloat( t.replace( /[^\d-]/g, '' ) ) || 0;
	}
	return parseFloat( t.replace( /[^\d.,-]/g, '' ).replace( ',', '.' ) ) || 0;
};
document.addEventListener( 'input', function ( e ) {
	var el = e.target;
	if ( ! el || ! el.hasAttribute || ! el.hasAttribute( 'data-miles' ) ) {
		return;
	}
	var antes   = el.value;
	var cursor  = el.selectionStart || antes.length;
	var digitos = antes.slice( 0, cursor ).replace( /\D/g, '' ).length;
	var limpio  = antes.replace( /\D/g, '' ).replace( /^0+(?=\d)/, '' );
	var nuevo   = limpio.replace( /\B(?=(\d{3})+(?!\d))/g, '.' );
	if ( nuevo === antes ) {
		return;
	}
	el.value = nuevo;
	var pos = 0;
	for ( var vistos = 0; pos < nuevo.length && vistos < digitos; pos++ ) {
		if ( /\d/.test( nuevo[ pos ] ) ) {
			vistos++;
		}
	}
	try {
		el.setSelectionRange( pos, pos );
	} catch ( err ) {}
}, true );

( function () {
	'use strict';

	var CFG  = window.malkaEscritorio || {};
	var I18N = CFG.i18n || {};
	var app  = document.querySelector( '.malka-esc' );

	if ( ! app ) {
		return;
	}

	/* ──────────────────────────  Utilidades  ────────────────────────── */

	function id( x ) {
		return document.getElementById( x );
	}

	/**
	 * Separa los miles con punto, como se escribe el dinero en Chile.
	 */
	function miles( n ) {
		return String( Math.round( Math.abs( n ) ) ).replace( /\B(?=(\d{3})+(?!\d))/g, '.' );
	}

	function soloNumeros( txt ) {
		return parseInt( String( txt ).replace( /[^\d]/g, '' ), 10 ) || 0;
	}

	var toastT = null;

	function aviso( texto ) {
		var el = id( 'esc-toast' );
		if ( ! el ) {
			return;
		}
		el.textContent = texto;
		el.classList.add( 'show' );
		window.clearTimeout( toastT );
		toastT = window.setTimeout( function () {
			el.classList.remove( 'show' );
		}, 2600 );
	}

	/**
	 * POST a admin-ajax.php. Devuelve una promesa con el payload de WordPress.
	 */
	function post( datos ) {
		var cuerpo = new URLSearchParams();
		Object.keys( datos ).forEach( function ( k ) {
			cuerpo.append( k, datos[ k ] );
		} );

		return window.fetch( CFG.ajax, {
			method:      'POST',
			credentials: 'same-origin',
			headers:     { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body:        cuerpo.toString()
		} ).then( function ( r ) {
			return r.json();
		} ).then( function ( j ) {
			if ( ! j || ! j.success ) {
				var msg = ( j && j.data && j.data.message ) ? j.data.message : ( I18N.error || 'Error' );
				throw new Error( msg );
			}
			return j.data || {};
		} );
	}

	/* ──────────────────────────  Pomodoro  ──────────────────────────── */

	var POM = {
		largo:   Math.max( 5, parseInt( CFG.pomodoroMin, 10 ) || 25 ) * 60,
		resta:   0,
		fin:     0,
		corre:   false,
		desde:   0,
		tope:    0,
		acum:    0,
		reloj:   null
	};

	POM.resta = POM.largo;

	var aros = [];

	function registrarAro( elId ) {
		var el = id( elId );
		if ( ! el ) {
			return;
		}
		var c = parseFloat( el.getAttribute( 'stroke-dasharray' ) ) || 0;
		aros.push( { el: el, c: c } );
	}

	registrarAro( 'esc-pom-arc' );
	registrarAro( 'esc-f-arc' );

	function pintar() {
		var mm  = Math.floor( POM.resta / 60 );
		var ss  = Math.floor( POM.resta % 60 );
		var txt = ( mm < 10 ? '0' : '' ) + mm + ':' + ( ss < 10 ? '0' : '' ) + ss;

		[ 'esc-pom-time', 'esc-f-time', 'esc-pom-mini' ].forEach( function ( x ) {
			var el = id( x );
			if ( el ) {
				el.textContent = txt;
			}
		} );

		var ido = POM.largo > 0 ? ( POM.largo - POM.resta ) / POM.largo : 0;
		aros.forEach( function ( a ) {
			a.el.setAttribute( 'stroke-dashoffset', String( a.c * ido ) );
		} );

		var etiqueta = POM.corre
			? ( I18N.pausar || 'Pausar' )
			: ( POM.resta < POM.largo ? ( I18N.continuar || 'Continuar' ) : ( I18N.iniciar || 'Iniciar' ) );

		var gop = id( 'esc-pom-go' );
		if ( gop ) {
			gop.textContent = etiqueta;
		}

		// En el enfoque, iniciar y pausar son botones aparte.
		var gof = id( 'esc-f-go' );
		if ( gof ) {
			gof.textContent = POM.corre ? ( I18N.enCurso || 'En curso' ) : ( POM.resta < POM.largo ? ( I18N.continuar || 'Continuar' ) : ( I18N.iniciar || 'Iniciar' ) );
			gof.disabled = !! POM.corre;
		}
		var paf = id( 'esc-f-pause' );
		if ( paf ) {
			paf.disabled = ! POM.corre;
		}

		// El reloj del encabezado avisa que hay un pomodoro andando o en pausa.
		var chip = id( 'esc-timer' );
		if ( chip ) {
			chip.classList.toggle( 'is-on', !! POM.corre );
			chip.classList.toggle( 'is-pausa', ! POM.corre && POM.resta < POM.largo );
			if ( chip.parentNode ) {
				chip.parentNode.classList.toggle( 'is-on', !! POM.corre );
			}
		}
		var go = id( 'esc-timer-go' );
		if ( go ) {
			go.setAttribute( 'aria-label', etiqueta );
			go.setAttribute( 'title', etiqueta );
		}
	}

	function proyectoActual() {
		var sel = id( 'esc-pom-proj' );
		return sel ? ( parseInt( sel.value, 10 ) || 0 ) : 0;
	}

	/**
	 * Guarda los minutos completos acumulados y deja el resto para la próxima.
	 *
	 * @param {boolean} beacon Usar sendBeacon (al cerrar la pestaña no hay fetch).
	 */
	function guardar( beacon ) {
		var min = Math.floor( POM.acum / 60 );
		if ( min < 1 ) {
			return;
		}
		POM.acum -= min * 60;

		var datos = {
			action:     'malka_crm_time',
			nonce:      CFG.nonceTime,
			minutos:    min,
			project_id: proyectoActual()
		};

		if ( beacon && navigator.sendBeacon ) {
			var fd = new FormData();
			Object.keys( datos ).forEach( function ( k ) {
				fd.append( k, datos[ k ] );
			} );
			navigator.sendBeacon( CFG.ajax, fd );
			return;
		}

		post( datos ).then( function ( d ) {
			var el = id( 'esc-pom-hoy' );
			if ( el && d.texto ) {
				el.textContent = d.texto;
			}
			aviso( I18N.sesion || 'Sesión registrada' );
		} ).catch( function ( e ) {
			POM.acum += min * 60;
			aviso( e.message );
		} );
	}

	function tic() {
		POM.resta = Math.max( 0, Math.round( ( POM.fin - Date.now() ) / 1000 ) );
		pintar();

		if ( POM.resta <= 0 ) {
			detener();
			POM.resta = POM.largo;
			pintar();
			guardar( false );
			aviso( I18N.sesion || 'Sesión registrada' );
		}
	}

	function arrancar() {
		if ( POM.corre ) {
			return;
		}
		POM.corre = true;
		POM.desde = Date.now();
		POM.tope  = POM.resta;
		POM.fin   = Date.now() + ( POM.resta * 1000 );
		POM.reloj = window.setInterval( tic, 500 );
		pintar();
	}

	/**
	 * Para el reloj y suma al acumulado lo que se trabajó en este tramo.
	 *
	 * El tope es lo que quedaba al arrancar: si la pestaña queda en segundo
	 * plano el navegador frena el intervalo y el aviso de término llega tarde,
	 * y una sesión de 25 minutos no puede valer más de 25 minutos.
	 */
	function detener() {
		if ( ! POM.corre ) {
			return;
		}
		window.clearInterval( POM.reloj );
		POM.reloj = null;
		POM.corre = false;
		POM.acum += Math.min( ( Date.now() - POM.desde ) / 1000, POM.tope );
		POM.resta = Math.max( 0, Math.round( ( POM.fin - Date.now() ) / 1000 ) );
	}

	function alternar() {
		if ( POM.corre ) {
			detener();
			pintar();
			guardar( false );
		} else {
			arrancar();
		}
	}

	function reiniciar() {
		detener();
		guardar( false );
		POM.resta = POM.largo;
		pintar();
	}

	[ 'esc-pom-go', 'esc-f-go' ].forEach( function ( x ) {
		var el = id( x );
		if ( el ) {
			el.addEventListener( 'click', alternar );
		}
	} );

	var pausaF = id( 'esc-f-pause' );
	if ( pausaF ) {
		pausaF.addEventListener( 'click', function () {
			if ( POM.corre ) {
				alternar();
			}
		} );
	}

	[ 'esc-pom-reset', 'esc-f-reset' ].forEach( function ( x ) {
		var el = id( x );
		if ( el ) {
			el.addEventListener( 'click', reiniciar );
		}
	} );

	window.addEventListener( 'beforeunload', function () {
		if ( POM.corre ) {
			detener();
		}
		guardar( true );
	} );

	pintar();

	/* ──────────────────────────  Modo enfoque  ──────────────────────── */

	var focus = id( 'esc-focus' );

	function nombreProyecto() {
		var sel = id( 'esc-pom-proj' );
		if ( ! sel || ! sel.options.length ) {
			return '';
		}
		return sel.options[ sel.selectedIndex ] ? sel.options[ sel.selectedIndex ].text : '';
	}

	function abrirFoco() {
		if ( ! focus ) {
			return;
		}
		var p = id( 'esc-f-proj' );
		if ( p ) {
			p.textContent = nombreProyecto();
		}
		focus.classList.add( 'open' );
		pintar();
		var go = id( 'esc-f-go' );
		if ( go ) {
			go.focus();
		}
	}

	function cerrarFoco() {
		if ( focus ) {
			focus.classList.remove( 'open' );
		}
	}

	var btnFoco = id( 'esc-pom-focus' );
	if ( btnFoco ) {
		btnFoco.addEventListener( 'click', abrirFoco );
	}

	var salirFoco = id( 'esc-f-exit' );
	if ( salirFoco ) {
		salirFoco.addEventListener( 'click', cerrarFoco );
	}

	/* ──────────────────────────  Menús  ─────────────────────────────── */

	function cerrarMenus() {
		[ [ 'esc-menu', 'esc-add' ], [ 'esc-notis', 'esc-bell' ], [ 'esc-timer-pop', 'esc-timer' ], [ 'esc-vac-pop', 'esc-vac' ] ].forEach( function ( par ) {
			var caja = id( par[ 0 ] );
			var btn  = id( par[ 1 ] );
			if ( caja ) {
				caja.classList.remove( 'open' );
			}
			if ( btn ) {
				btn.setAttribute( 'aria-expanded', 'false' );
			}
		} );
	}

	function colgar( btnId, cajaId ) {
		var btn  = id( btnId );
		var caja = id( cajaId );
		if ( ! btn || ! caja ) {
			return;
		}
		btn.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			var abierto = caja.classList.contains( 'open' );
			cerrarMenus();
			if ( ! abierto ) {
				caja.classList.add( 'open' );
				btn.setAttribute( 'aria-expanded', 'true' );
			}
		} );
		caja.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
		} );
	}

	colgar( 'esc-add', 'esc-menu' );
	colgar( 'esc-bell', 'esc-notis' );
	colgar( 'esc-timer', 'esc-timer-pop' );
	colgar( 'esc-vac', 'esc-vac-pop' );

	document.addEventListener( 'click', cerrarMenus );

	/* ──────────────────────────  Saldo  ────────────────────────────── */

	var saldo = id( 'esc-saldo' );

	if ( saldo ) {
		var previo = saldo.value;

		saldo.addEventListener( 'focus', function () {
			previo = saldo.value;
			saldo.select();
		} );

		saldo.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key ) {
				e.preventDefault();
				saldo.blur();
			}
			if ( 'Escape' === e.key ) {
				saldo.value = previo;
				saldo.blur();
			}
		} );

		saldo.addEventListener( 'blur', function () {
			var monto = soloNumeros( saldo.value );
			if ( miles( monto ) === previo.replace( /[^\d.]/g, '' ) ) {
				saldo.value = previo;
				return;
			}

			post( {
				action: 'malka_crm_movement',
				nonce:  CFG.nonceFin,
				op:     'saldo',
				monto:  monto
			} ).then( function ( d ) {
				saldo.value = miles( d.saldo );
				previo      = saldo.value;

				var cab = document.querySelector( '.a-mov .esc-ph__r em' );
				if ( cab && d.texto ) {
					cab.textContent = d.texto;
				}

				var msg = id( 'esc-saldo-msg' );
				if ( msg && d.fecha ) {
					msg.textContent = ( I18N.guardado || 'Guardado' ) + ' · ' + d.fecha;
				}

				aviso( I18N.guardado || 'Guardado' );
			} ).catch( function ( e ) {
				saldo.value = previo;
				aviso( e.message );
			} );
		} );
	}

	/* ──────────────────────  Registrar movimiento  ─────────────────── */

	var modal = id( 'esc-modal' );

	// Abre el registro vacío, o con un movimiento para corregirlo.
	function abrirModal( datos ) {
		if ( ! modal ) {
			return;
		}
		cerrarMenus();
		var m = ( datos && datos.id ) ? datos : null;
		var hoy = new Date();
		var pon = function ( k, v ) {
			var el = id( k );
			if ( el ) {
				el.value = v;
			}
		};
		pon( 'esc-mv-id', m ? m.id : '' );
		pon( 'esc-mv-d', m ? m.descripcion : '' );
		pon( 'esc-mv-ref', m ? ( m.referencia || '' ) : '' );
		pon( 'esc-mv-m', m ? miles( m.monto ) : '' );
		pon( 'esc-mv-f', m ? m.fecha : hoy.getFullYear() + '-' + ( '0' + ( hoy.getMonth() + 1 ) ).slice( -2 ) + '-' + ( '0' + hoy.getDate() ).slice( -2 ) );
		pon( 'esc-mv-c', m ? m.categoria : 'costo_variable' );
		var ctaM = id( 'esc-mv-cta' );
		if ( ctaM ) {
			if ( m && m.cuenta ) {
				ctaM.value = String( m.cuenta );
			} else {
				ctaM.selectedIndex = Math.max( 0, Array.prototype.findIndex.call( ctaM.options, function ( o ) {
					return o.defaultSelected;
				} ) );
			}
		}
		var tipo = m ? m.tipo : 'salida';
		pon( 'esc-mv-t', tipo );
		modal.querySelectorAll( 'input[name="esc-mv-tipo"]' ).forEach( function ( r ) {
			r.checked = r.value === tipo;
		} );
		var rec = id( 'esc-mv-r' );
		if ( rec ) {
			rec.checked = !! ( m && m.recurrente );
		}

		// La moneda primero: decide qué número se escribe en el monto.
		var mon = id( 'esc-mv-mon' );
		var moneda = ( m && m.moneda ) ? m.moneda : 'CLP';

		if ( mon ) {
			mon.value = moneda;
			if ( ! mon.value ) {
				mon.value = 'CLP';
				moneda    = 'CLP';
			}
		}

		if ( m && 'CLP' !== moneda && m.original > 0 ) {
			pon( 'esc-mv-m', String( m.original ) );
		}

		// Puntual, recurrente o en cuotas: una sola cosa, no dos casillas.
		var forma = 'puntual';

		if ( m ) {
			if ( m.cuota_total > 0 && m.cuota_n > 0 ) {
				forma = 'cuota';
			} else if ( m.recurrente ) {
				forma = 'recurrente';
			}
		}

		pon( 'esc-mv-fo', forma );
		pon( 'esc-mv-cn', ( m && m.cuota_n ) ? m.cuota_n : '' );
		pon( 'esc-mv-ct', ( m && m.cuota_total ) ? m.cuota_total : '' );
		pon( 'esc-mv-est', ( m && m.estado ) ? m.estado : 'hecho' );
		pintarForma();
		var h = id( 'esc-mv-h' );
		if ( h ) {
			h.textContent = h.getAttribute( m ? 'data-editar' : 'data-nuevo' );
		}
		var del = id( 'esc-mv-del' );
		if ( del ) {
			del.hidden = ! m;
		}
		modal.classList.add( 'open' );
		var foco = id( m ? 'esc-mv-d' : 'esc-mv-m' );
		if ( foco ) {
			foco.focus();
		}
	}

	// Salida / entrada como botones; el select escondido es el que se lee.
	if ( modal ) {
		modal.addEventListener( 'change', function ( e ) {
			if ( 'esc-mv-tipo' === e.target.name ) {
				var t = id( 'esc-mv-t' );
				if ( t ) {
					t.value = e.target.value;
				}
				var c = id( 'esc-mv-c' );
				if ( c && 'entrada' === e.target.value && 'costo_variable' === c.value ) {
					c.value = 'ingreso';
				}
			}
		} );
		var mm = id( 'esc-mv-m' );
		if ( mm ) {
			mm.addEventListener( 'input', function () {
				var n = soloNumeros( mm.value );
				mm.value = n ? miles( n ) : '';
			} );
		}
	}

	// Tocar un movimiento lo abre para corregirlo, venga de la lista del
	// inicio o de la tabla de Movimientos.
	window.MalkaEscAbrirMov = abrirModal;

	document.addEventListener( 'click', function ( e ) {
		var fila = e.target.closest ? e.target.closest( '.esc-mov[data-mov]' ) : null;
		if ( ! fila || e.target.closest( 'a' ) ) {
			return;
		}
		e.stopPropagation();
		try {
			abrirModal( JSON.parse( fila.getAttribute( 'data-mov' ) ) );
		} catch ( x ) {}
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Enter' === e.key && e.target.matches && e.target.matches( '.esc-mov[data-mov]' ) ) {
			abrirModal( JSON.parse( e.target.getAttribute( 'data-mov' ) ) );
		}
	} );

	function cerrarModal() {
		if ( modal ) {
			modal.classList.remove( 'open' );
		}
	}

	/**
	 * Deja el modal mostrando lo que corresponde a lo elegido: las cuotas
	 * sólo si va en cuotas, el símbolo de la moneda y el aviso del pendiente.
	 */
	function pintarForma() {
		if ( ! modal ) {
			return;
		}

		var fo   = id( 'esc-mv-fo' );
		var cuo  = modal.querySelector( '[data-mv-cuotas]' );
		var mon  = id( 'esc-mv-mon' );
		var sig  = id( 'esc-mv-sig' );
		var est  = id( 'esc-mv-est' );
		var nota = id( 'esc-mv-nota' );

		if ( cuo ) {
			cuo.hidden = ! fo || 'cuota' !== fo.value;
		}

		if ( sig ) {
			sig.textContent = ( mon && 'CLP' !== mon.value ) ? mon.value : '$';
		}

		if ( nota ) {
			nota.hidden = ! est || 'pendiente' !== est.value;
		}
	}

	[ 'esc-mv-fo', 'esc-mv-mon', 'esc-mv-est' ].forEach( function ( x ) {
		var el = id( x );
		if ( el ) {
			el.addEventListener( 'change', pintarForma );
		}
	} );

	document.querySelectorAll( '[data-abrir-mov]' ).forEach( function ( b ) {
		b.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			abrirModal();
		} );
	} );

	[ 'esc-open-mov', 'esc-open-mov2' ].forEach( function ( x ) {
		var el = id( x );
		if ( el ) {
			el.addEventListener( 'click', abrirModal );
		}
	} );

	var cancelar = id( 'esc-mv-x' );
	if ( cancelar ) {
		cancelar.addEventListener( 'click', cerrarModal );
	}

	if ( modal ) {
		modal.addEventListener( 'click', function ( e ) {
			if ( e.target === modal ) {
				cerrarModal();
			}
		} );
	}

	/**
	 * Agrega la fila recién creada arriba de la lista, con el mismo formato que
	 * imprime PHP, para no tener que recargar la página.
	 */
	function pintarMovimiento( datos ) {
		var lista = id( 'esc-movs' );
		if ( ! lista ) {
			return;
		}

		var vacio = lista.parentNode.querySelector( '.esc-empty' );
		if ( vacio ) {
			vacio.remove();
		}

		var entra = 'entrada' === datos.tipo;
		var fila = document.createElement( 'div' );
		fila.className = 'esc-mov esc-mov--n' + ( entra ? ' is-in' : '' );
		var ic = document.createElement( 'span' );
		ic.className = 'esc-mov__ic';
		ic.innerHTML = entra
			? '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M11.5 4.5l-7 7M4.5 6v5.5H10"/></svg>'
			: '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 11.5l7-7M6 4.5h5.5V10"/></svg>';
		var izq = document.createElement( 'span' );
		izq.className = 'esc-mov__d';
		var tit = document.createElement( 'b' );
		tit.textContent = datos.descripcion;
		var sub = document.createElement( 'i' );
		sub.textContent = datos.fecha + ( datos.categoria ? ' · ' + datos.categoria + ( datos.recurrente ? ' · mensual' : '' ) : '' );
		izq.appendChild( tit );
		izq.appendChild( sub );
		var der = document.createElement( 'span' );
		der.className   = 'esc-mov__v' + ( entra ? ' is-in' : '' );
		der.textContent = ( entra ? '+' : '−' ) + '$' + miles( datos.monto );
		fila.appendChild( ic );
		fila.appendChild( izq );
		fila.appendChild( der );
		lista.insertBefore( fila, lista.firstChild );
	}

	var form = id( 'esc-mov-form' );

	if ( form ) {
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var mid   = id( 'esc-mv-id' ) ? id( 'esc-mv-id' ).value : '';
			var texto = id( 'esc-mv-d' ) ? id( 'esc-mv-d' ).value.trim() : '';
			var valor = id( 'esc-mv-m' ) ? soloNumeros( id( 'esc-mv-m' ).value ) : 0;
			if ( '' === texto || valor <= 0 ) {
				aviso( I18N.falta || 'Falta la descripción o el monto' );
				return;
			}
			var boton = id( 'esc-mv-ok' );
			if ( boton ) {
				boton.disabled = true;
			}

			var moneda = id( 'esc-mv-mon' ) ? id( 'esc-mv-mon' ).value : 'CLP';
			var forma  = id( 'esc-mv-fo' ) ? id( 'esc-mv-fo' ).value : 'puntual';
			var crudo  = id( 'esc-mv-m' ) ? id( 'esc-mv-m' ).value.replace( /[^\d.,]/g, '' ).replace( ',', '.' ) : '0';

			post( {
				action:      'malka_crm_movement',
				nonce:       CFG.nonceFin,
				op:          mid ? 'editar' : 'movimiento',
				id:          mid,
				descripcion: texto,
				referencia:  id( 'esc-mv-ref' ) ? id( 'esc-mv-ref' ).value.trim() : '',
				// En pesos manda el monto; en otra moneda, el original.
				monto:       'CLP' === moneda ? valor : 0,
				moneda:      moneda,
				original:    'CLP' === moneda ? 0 : crudo,
				tipo:        id( 'esc-mv-t' ) ? id( 'esc-mv-t' ).value : 'salida',
				categoria:   id( 'esc-mv-c' ) ? id( 'esc-mv-c' ).value : 'sin_categoria',
				recurrente:  'recurrente' === forma ? 1 : 0,
				cuota_n:     'cuota' === forma && id( 'esc-mv-cn' ) ? id( 'esc-mv-cn' ).value : 0,
				cuota_total: 'cuota' === forma && id( 'esc-mv-ct' ) ? id( 'esc-mv-ct' ).value : 0,
				estado:      id( 'esc-mv-est' ) ? id( 'esc-mv-est' ).value : 'hecho',
				fecha:       id( 'esc-mv-f' ) ? id( 'esc-mv-f' ).value : '',
				cuenta:      id( 'esc-mv-cta' ) ? id( 'esc-mv-cta' ).value : 0
			} ).then( function () {
				cerrarModal();
				aviso( I18N.guardado || 'Guardado' );
				// Saldo, retiro, aviso de devolución y listas cambian: se recarga.
				window.setTimeout( function () {
					window.location.reload();
				}, 400 );
			} ).catch( function ( err ) {
				aviso( err.message );
				if ( boton ) {
					boton.disabled = false;
				}
			} );
		} );

		var borrar = id( 'esc-mv-del' );
		if ( borrar ) {
			borrar.addEventListener( 'click', function () {
				var mid = id( 'esc-mv-id' ) ? id( 'esc-mv-id' ).value : '';
				if ( ! mid || ! window.confirm( '¿Eliminar este movimiento? El saldo se ajusta.' ) ) {
					return;
				}
				post( { action: 'malka_crm_movement', nonce: CFG.nonceFin, op: 'borrar', id: mid } ).then( function () {
					cerrarModal();
					window.location.reload();
				} ).catch( function ( err ) {
					aviso( err.message );
				} );
			} );
		}
	}

	/* ──────────────────────  Costos fijos  ─────────────────────────── */

	var costos = id( 'esc-costos' );

	if ( costos ) {
		var agregar = id( 'esc-costo-add' );

		/**
		 * Renumera los campos para que PHP reciba un arreglo sin huecos.
		 *
		 * Se cambia sólo el número del nombre, nunca la clave. Antes se
		 * reasignaban las claves por el orden de los campos contra una lista
		 * escrita a mano, y esa lista se quedó corta: el select de «sale de»
		 * terminaba llamándose «variable» en cuanto se agregaba o se quitaba
		 * una fila, y la cuenta de la que sale el costo se perdía.
		 */
		function renumerar() {
			var filas = costos.querySelectorAll( '.esc-costo:not(.esc-costo--h)' );
			Array.prototype.forEach.call( filas, function ( fila, i ) {
				var campos = fila.querySelectorAll( '[name^="costos["]' );
				Array.prototype.forEach.call( campos, function ( el ) {
					el.name = el.name.replace( /^costos\[\d+\]/, 'costos[' + i + ']' );
				} );
			} );
		}

		if ( agregar ) {
			agregar.addEventListener( 'click', function () {
				var base = costos.querySelector( '.esc-costo:not(.esc-costo--h)' );
				if ( ! base ) {
					return;
				}
				var nueva = base.cloneNode( true );
				Array.prototype.forEach.call( nueva.querySelectorAll( 'input' ), function ( i ) {
					if ( 'checkbox' === i.type ) {
						i.checked = false;
					} else {
						i.value = '';
					}
				} );
				/*
				 * Lo que la fila lleva oculto no se vacía: se pone en lo que
				 * corresponde a un cobro nuevo. Un id vacío sería «0», y la
				 * etiqueta y el ciclo vacíos quedarían en estudio y mensual
				 * en PHP, pero mejor decirlo acá que confiar en eso.
				 */
				var ini = { id: '0', ambito: 'estudio', ciclo: 'mensual', mes: '0' };
				Object.keys( ini ).forEach( function ( k ) {
					var h = nueva.querySelector( '[name$="[' + k + ']"]' );
					if ( h && 'hidden' === h.type ) {
						h.value = ini[ k ];
					}
				} );
				Array.prototype.forEach.call( nueva.querySelectorAll( 'select' ), function ( i ) {
					i.selectedIndex = 0;
				} );
				var eq = nueva.querySelector( '.esc-costo__clp' );
				if ( eq ) {
					eq.textContent = '';
				}
				costos.appendChild( nueva );
				renumerar();
				var primero = nueva.querySelector( 'input' );
				if ( primero ) {
					primero.focus();
				}
			} );
		}

		costos.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.esc-costo__x' );
			if ( ! btn ) {
				return;
			}
			var filas = costos.querySelectorAll( '.esc-costo:not(.esc-costo--h)' );
			if ( filas.length <= 1 ) {
				// La última no se borra: se vacía, para no dejar el bloque sin campos.
				Array.prototype.forEach.call( filas[ 0 ].querySelectorAll( 'input' ), function ( i ) {
					i.value = '';
				} );
				return;
			}
			btn.closest( '.esc-costo' ).remove();
			renumerar();
		} );
	}

	/* ────────────────  Tarifa en vivo (Configuración)  ─────────────── */

	var tarifa = id( 'esc-tarifa' );

	if ( tarifa ) {
		/**
		 * Mismo cálculo que hace PHP al guardar, para que lo que ve mientras
		 * escribe sea exactamente lo que va a quedar guardado.
		 */
		var calc = ( function () {
			function num( x ) {
				var el = id( 'f-' + x );
				if ( ! el ) {
					return 0;
				}
				var v = parseFloat( String( el.value ).replace( ',', '.' ) );
				return isNaN( v ) ? 0 : v;
			}

			function minutos( ini, fin ) {
				var a = ( id( 'f-' + ini ) || {} ).value || '';
				var b = ( id( 'f-' + fin ) || {} ).value || '';
				var pa = a.split( ':' );
				var pb = b.split( ':' );
				if ( 2 !== pa.length || 2 !== pb.length ) {
					return 0;
				}
				var m = ( ( +pb[ 0 ] * 60 ) + +pb[ 1 ] ) - ( ( +pa[ 0 ] * 60 ) + +pa[ 1 ] );
				return m > 0 ? m : 0;
			}

			/**
			 * Suma los costos pasando cada uno a pesos con el tipo de cambio
			 * que entregó el servidor, el mismo que usará al guardar.
			 */
			function costosTotal() {
				var fx = CFG.fx || {};
				var t  = 0;

				var filas = document.querySelectorAll( '#esc-costos .esc-costo:not(.esc-costo--h)' );
				Array.prototype.forEach.call( filas, function ( f ) {
					// Por nombre y no por posición: la fila lleva campos
					// ocultos adelante, y contar casillas se rompe solo.
					var campo = function ( k ) {
						return f.querySelector( '[name$="[' + k + ']"]' );
					};
					var nombre = campo( 'nombre' );
					var monto  = campo( 'monto' );
					var moneda = campo( 'moneda' );
					var ciclo  = campo( 'ciclo' );
					var ambito = campo( 'ambito' );
					var eq     = f.querySelector( '.esc-costo__clp' );

					if ( ! nombre || '' === nombre.value.trim() || ! monto ) {
						if ( eq ) {
							eq.textContent = '';
						}
						return;
					}

					var v   = parseFloat( String( monto.value ).replace( ',', '.' ) ) || 0;
					var cod = moneda ? moneda.value : 'CLP';
					var tc  = 'CLP' === cod ? 1 : ( parseFloat( fx[ cod ] ) || 0 );
					var clp = Math.round( v * tc );

					/*
					 * Este total es el costo del estudio al mes: es el que
					 * entra a la tarifa. Lo personal no suma, y lo que se
					 * paga una vez al año se reparte en doce.
					 */
					if ( ! ambito || 'personal' !== ambito.value ) {
						t += ciclo && 'anual' === ciclo.value ? Math.round( clp / 12 ) : clp;
					}

					if ( ! eq ) {
						return;
					}

					// Sin tipo de cambio la fila valdría cero y el total mentiría:
					// mejor decirlo que sumar mal en silencio.
					if ( 'CLP' !== cod && v > 0 && tc <= 0 ) {
						eq.textContent = 'falta el tipo de cambio del ' + cod;
						eq.classList.add( 'is-alert' );
					} else {
						eq.textContent = ( 'CLP' === cod || ! clp ) ? '' : '= $' + miles( clp );
						eq.classList.remove( 'is-alert' );
					}
				} );

				var tot = id( 'esc-c-total' );
				if ( tot ) {
					tot.textContent = '$' + miles( t );
				}

				return t;
			}

			/**
			 * Días del año que caen en los días marcados como laborales.
			 */
			function diasHabiles( anio ) {
				var sel = [];
				Array.prototype.forEach.call(
					document.querySelectorAll( 'input[name="dias_semana[]"]:checked' ),
					function ( i ) {
						sel.push( parseInt( i.value, 10 ) );
					}
				);
				if ( ! sel.length ) {
					return 0;
				}
				var n = 0;
				var d = new Date( anio, 0, 1 );
				while ( d.getFullYear() === anio ) {
					// getDay(): domingo 0 … sábado 6. PHP 'N': lunes 1 … domingo 7.
					var dow = 0 === d.getDay() ? 7 : d.getDay();
					if ( sel.indexOf( dow ) !== -1 ) {
						n++;
					}
					d.setDate( d.getDate() + 1 );
				}
				return n;
			}

			return function () {
				var anio    = new Date().getFullYear();
				var sueldo  = num( 'sueldo_objetivo' );
				var costos  = costosTotal();
				var carga   = Math.min( 90, Math.max( 0, num( 'carga_prev' ) ) ) / 100;
				var colchon = Math.max( 0, num( 'colchon' ) ) / 100;
				var ocup    = Math.min( 100, Math.max( 1, num( 'ocupacion' ) ) ) / 100;
				var vac     = Math.max( 0, num( 'vacaciones_dias' ) );
				var donadas = Math.max( 0, num( 'tope_donadas' ) );
				var cobrada = Math.max( 0, num( 'tarifa_cobrada' ) );

				var completo = ( sueldo > 0 && costos > 0 );

				var bruto   = carga < 1 ? ( sueldo * 12 ) / ( 1 - carga ) : sueldo * 12;
				var aCubrir = ( bruto + ( costos * 12 ) ) * ( 1 + colchon );

				var horasDia    = ( minutos( 'bloque_am_ini', 'bloque_am_fin' ) + minutos( 'bloque_pm_ini', 'bloque_pm_fin' ) ) / 60;
				var disponibles = diasHabiles( anio ) * horasDia;
				var trabajables = Math.max( 0, disponibles - ( vac * horasDia ) );
				var facturables = Math.max( 1, ( trabajables * ocup ) - ( donadas * 12 ) );

				var exacta = aCubrir / facturables;
				var minima = Math.round( exacta / 1000 ) * 1000;
				var usada  = cobrada > 0 ? cobrada : minima;
				var margen = Math.round( ( facturables * usada ) - aCubrir );

				return {
					completo:    completo,
					minima:      minima,
					cobrada:     cobrada,
					aCubrir:     Math.round( aCubrir ),
					horasAnio:   Math.round( facturables ),
					horasMes:    Math.round( facturables / 12 ),
					costos:      costos,
					margen:      margen,
					bajoElPiso:  ( cobrada > 0 && minima > 0 && cobrada < minima )
				};
			};
		}() );

		function texto( x, v ) {
			var el = id( x );
			if ( el ) {
				el.textContent = v;
			}
		}

		function plata( n ) {
			return ( n < 0 ? '-$' : '$' ) + miles( n );
		}

		function pintarTarifa() {
			var r = calc();

			var kv    = id( 'esc-t-kv' );
			var falta = id( 'esc-t-falta' );
			var aviso = id( 'esc-t-aviso' );

			if ( kv ) {
				kv.hidden = ! r.completo;
			}
			if ( falta ) {
				falta.hidden = r.completo;
			}
			if ( aviso ) {
				aviso.hidden = ! ( r.completo && r.bajoElPiso );
				aviso.classList.toggle( 'is-alert', r.bajoElPiso );
			}

			texto( 'esc-t-min', r.completo ? plata( r.minima ) : '—' );

			if ( ! r.completo ) {
				return;
			}

			texto( 'esc-t-cobrada', r.cobrada > 0 ? plata( r.cobrada ) : plata( r.minima ) );
			texto( 'esc-t-cubrir', plata( r.aCubrir ) );
			texto( 'esc-t-hanio', miles( r.horasAnio ) );
			texto( 'esc-t-hmes', miles( r.horasMes ) );
			texto( 'esc-t-costos', plata( r.costos ) );
			texto( 'esc-t-margen', plata( r.margen ) );

			var margen = id( 'esc-t-margen' );
			if ( margen ) {
				margen.classList.toggle( 'is-alert', r.margen < 0 );
				margen.classList.toggle( 'is-ok', r.margen >= 0 );
			}

			var cobrada = id( 'esc-t-cobrada' );
			if ( cobrada ) {
				cobrada.classList.toggle( 'is-alert', r.bajoElPiso );
			}

		}

		var formCfg = document.querySelector( '.esc-cfg' );
		if ( formCfg ) {
			formCfg.addEventListener( 'input', pintarTarifa );
			formCfg.addEventListener( 'change', pintarTarifa );
			formCfg.addEventListener( 'click', function ( e ) {
				if ( e.target.closest( '.esc-costo__x' ) || e.target.closest( '#esc-costo-add' ) ) {
					window.setTimeout( pintarTarifa, 0 );
				}
			} );
		}

		pintarTarifa();
	}

	/* ──────────────────  Copiar mensaje de cobranza  ───────────────── */

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.esc-copy' );
		if ( ! btn ) {
			return;
		}

		var texto = btn.getAttribute( 'data-msg' ) || '';
		var antes = btn.textContent;

		function listo() {
			btn.textContent = 'Copiado';
			btn.classList.add( 'is-ok' );
			window.setTimeout( function () {
				btn.textContent = antes;
				btn.classList.remove( 'is-ok' );
			}, 1800 );
		}

		// Algunos navegadores niegan el portapapeles: entonces se muestra el
		// texto seleccionado para que lo copie a mano.
		function aMano() {
			var ta = document.createElement( 'textarea' );
			ta.value = texto;
			ta.setAttribute( 'readonly', '' );
			ta.style.position = 'fixed';
			ta.style.left = '-9999px';
			document.body.appendChild( ta );
			ta.select();
			var ok = false;
			try {
				ok = document.execCommand( 'copy' );
			} catch ( err ) {
				ok = false;
			}
			document.body.removeChild( ta );
			if ( ok ) {
				listo();
			} else {
				aviso( texto );
			}
		}

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( texto ).then( listo ).catch( aMano );
		} else {
			aMano();
		}
	} );

	/* ────────────────  Mensajes de cobranza  ───────────────────────── */

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.esc-copy' );
		if ( ! btn ) {
			return;
		}

		var texto = btn.getAttribute( 'data-msg' ) || '';
		if ( ! texto ) {
			return;
		}

		// El texto original se guarda una sola vez: si esto corre dos veces
		// seguidas, el botón no se queda pegado en "Copiado".
		if ( ! btn.dataset.rotulo ) {
			btn.dataset.rotulo = btn.textContent;
		}

		var listo = function () {
			window.clearTimeout( btn._volver );
			btn.textContent = I18N.copiado || 'Copiado';
			btn.classList.add( 'is-ok' );
			btn._volver = window.setTimeout( function () {
				btn.textContent = btn.dataset.rotulo;
				btn.classList.remove( 'is-ok' );
			}, 2000 );
			aviso( I18N.copiado || 'Copiado' );
		};

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( texto ).then( listo ).catch( function () {
				respaldo( texto, listo );
			} );
		} else {
			respaldo( texto, listo );
		}
	} );

	/**
	 * Cuando el navegador no deja copiar, deja el texto seleccionado para que
	 * ella lo copie con el teclado.
	 */
	function respaldo( texto, listo ) {
		var ta = document.createElement( 'textarea' );
		ta.value = texto;
		ta.setAttribute( 'readonly', '' );
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild( ta );
		ta.select();

		var ok = false;
		try {
			ok = document.execCommand( 'copy' );
		} catch ( err ) {
			ok = false;
		}

		document.body.removeChild( ta );

		if ( ok ) {
			listo();
		} else {
			aviso( I18N.copiarManual || 'No se pudo copiar solo: copia el texto a mano' );
		}
	}

	/* ──────────────────────────  Teclado  ──────────────────────────── */

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' !== e.key ) {
			return;
		}
		if ( modal && modal.classList.contains( 'open' ) ) {
			cerrarModal();
			return;
		}
		if ( focus && focus.classList.contains( 'open' ) ) {
			cerrarFoco();
			return;
		}
		cerrarMenus();
	} );
}() );

/**
 * El editor de cotizaciones.
 *
 * Dos cosas: el repetidor de ítems con su total en vivo, y la confirmación
 * antes de mandar algo a la papelera. El total que se pinta acá es para que
 * Malka lo vea mientras escribe; el que vale lo recalcula el servidor al
 * guardar, porque lo que se calcula en el navegador se puede cambiar desde
 * el navegador.
 */
( function () {
	'use strict';

	var CFG = window.malkaEscritorio || {};

	/* ──────────────────────  Confirmar antes de borrar  ────────────── */

	document.addEventListener( 'click', function ( e ) {
		var a = e.target.closest ? e.target.closest( '[data-confirmar]' ) : null;

		if ( ! a ) {
			return;
		}

		if ( ! window.confirm( a.getAttribute( 'data-confirmar' ) ) ) {
			e.preventDefault();
		}
	} );

	/* ────────────────────────────  Ítems  ──────────────────────────── */

	var lista = document.getElementById( 'esc-items' );

	if ( ! lista ) {
		return;
	}

	var salida = document.getElementById( 'esc-ed-total' );
	var iva    = parseFloat( CFG.iva );

	if ( isNaN( iva ) ) {
		iva = 19;
	}

	function plata( n ) {
		var abs = Math.round( Math.abs( n || 0 ) );
		return ( n < 0 ? '-$' : '$' ) + String( abs ).replace( /\B(?=(\d{3})+(?!\d))/g, '.' );
	}

	function num( fila, clave ) {
		var el = fila.querySelector( '[data-k="' + clave + '"]' );
		if ( ! el ) {
			return 0;
		}
		return el.hasAttribute( 'data-miles' ) ? window.escLeer( el.value ) : ( parseFloat( el.value ) || 0 );
	}

	function recalcular() {
		var neto = 0;

		Array.prototype.forEach.call( lista.querySelectorAll( '.esc-item' ), function ( fila ) {
			var cant = num( fila, 'cantidad' );
			var unit = num( fila, 'unitario' );
			var desc = Math.min( 100, Math.max( 0, num( fila, 'descuento' ) ) );
			var sub  = Math.round( cant * unit * ( 1 - ( desc / 100 ) ) );

			neto += sub;

			var celda = fila.querySelector( '[data-neto]' );
			if ( celda ) {
				celda.textContent = plata( sub );
			}
		} );

		var impuesto = Math.round( neto * ( iva / 100 ) );
		var cNeto    = document.getElementById( 'esc-ed-neto' );
		var cIva     = document.getElementById( 'esc-ed-iva' );

		if ( cNeto ) {
			cNeto.textContent = plata( neto );
		}
		if ( cIva ) {
			cIva.textContent = plata( impuesto );
		}
		if ( salida ) {
			salida.textContent = plata( neto + impuesto );
		}
	}

	/**
	 * Renumera los name="item[N][...]" para que el orden no deje huecos.
	 *
	 * Sin esto, borrar la fila 2 de tres deja item[0] e item[2]; PHP lo
	 * recibe igual, pero el índice deja de decir nada y una fila nueva
	 * pisaría a otra.
	 */
	function renumerar() {
		Array.prototype.forEach.call( lista.querySelectorAll( '.esc-item' ), function ( fila, i ) {
			Array.prototype.forEach.call( fila.querySelectorAll( '[name^="item["]' ), function ( campo ) {
				campo.setAttribute( 'name', campo.getAttribute( 'name' ).replace( /^item\[[^\]]*\]/, 'item[' + i + ']' ) );
			} );
		} );
	}

	lista.addEventListener( 'input', recalcular );

	lista.addEventListener( 'click', function ( e ) {
		var x = e.target.closest ? e.target.closest( '.esc-item__x' ) : null;

		if ( ! x ) {
			return;
		}

		var filas = lista.querySelectorAll( '.esc-item' );

		// Siempre queda una: una cotización sin ninguna fila no se puede escribir.
		if ( filas.length < 2 ) {
			Array.prototype.forEach.call( filas[ 0 ].querySelectorAll( 'input, textarea' ), function ( campo ) {
				campo.value = 'number' === campo.type ? '0' : '';
			} );
			recalcular();
			return;
		}

		x.closest( '.esc-item' ).remove();
		renumerar();
		recalcular();
	} );

	var agregar = document.getElementById( 'esc-item-add' );

	if ( agregar && CFG.itemTpl ) {
		agregar.addEventListener( 'click', function () {
			var caja = document.createElement( 'div' );

			caja.innerHTML = CFG.itemTpl.replace( /__i__/g, String( lista.querySelectorAll( '.esc-item' ).length ) );

			var fila = caja.firstElementChild;

			if ( ! fila ) {
				return;
			}

			lista.appendChild( fila );
			renumerar();

			var primero = fila.querySelector( 'input[type="text"]' );
			if ( primero ) {
				primero.focus();
			}
		} );
	}

	recalcular();
}() );

/**
 * Las cuotas del editor.
 *
 * Agregar, quitar y programar en mensualidades. Lo que se arma acá es sólo el
 * formulario: al guardar, el servidor vuelve a limpiar y a validar todo.
 */
( function () {
	'use strict';

	var CFG   = window.malkaEscritorio || {};
	var lista = document.getElementById( 'esc-cuotas' );

	if ( ! lista ) {
		return;
	}

	var I18N = CFG.i18nCuota || {};

	function filas() {
		return lista.querySelectorAll( '.esc-cuota:not(.esc-cuota--h)' );
	}

	/**
	 * Renumera los name="pago[N][...]" para que no queden huecos.
	 */
	function renumerar() {
		Array.prototype.forEach.call( filas(), function ( fila, i ) {
			Array.prototype.forEach.call( fila.querySelectorAll( '[name^="pago["]' ), function ( campo ) {
				campo.setAttribute( 'name', campo.getAttribute( 'name' ).replace( /^pago\[[^\]]*\]/, 'pago[' + i + ']' ) );
			} );
		} );
	}

	/**
	 * Contra entrega la fecha la pone la entrega, no ella: se desactiva para
	 * que no parezca que escribirla sirve de algo.
	 */
	function ajustarFecha( fila ) {
		var sel = fila.querySelector( '[data-disparo]' );
		var fec = fila.querySelector( '[data-fecha]' );

		if ( ! sel || ! fec ) {
			return;
		}

		fec.disabled = ( 'entrega' === sel.value );

		if ( fec.disabled ) {
			fec.value = '';
		}
	}

	function nuevaFila( i ) {
		var caja = document.createElement( 'div' );

		caja.innerHTML = ( CFG.cuotaTpl || '' ).replace( /__i__/g, String( i ) );

		return caja.firstElementChild;
	}

	lista.addEventListener( 'change', function ( e ) {
		if ( e.target.closest( '[data-disparo]' ) ) {
			ajustarFecha( e.target.closest( '.esc-cuota' ) );
		}
	} );

	lista.addEventListener( 'click', function ( e ) {
		var x = e.target.closest ? e.target.closest( '.esc-cuota__x' ) : null;

		if ( ! x ) {
			return;
		}

		// Siempre queda una: sin ninguna cuota no hay nada que cobrar.
		if ( filas().length < 2 ) {
			return;
		}

		x.closest( '.esc-cuota' ).remove();
		renumerar();
	} );

	var agregar = document.getElementById( 'esc-cuota-add' );

	if ( agregar && CFG.cuotaTpl ) {
		agregar.addEventListener( 'click', function () {
			var fila = nuevaFila( filas().length );

			if ( ! fila ) {
				return;
			}

			lista.appendChild( fila );
			ajustarFecha( fila );
			renumerar();

			var primero = fila.querySelector( 'input[type="text"]' );
			if ( primero ) {
				primero.focus();
			}
		} );
	}

	/* ────────────────────  Dividir en cuotas mensuales  ─────────────── */

	var dividir = document.getElementById( 'esc-cuotas-dividir' );

	/**
	 * Suma meses a una fecha sin que el 31 se desborde al mes siguiente.
	 *
	 * new Date(2026,0,31) + 1 mes da el 3 de marzo, no el 28 de febrero. Por
	 * eso se fija el día 1 antes de mover el mes y se recorta al final.
	 */
	function sumarMeses( iso, n ) {
		var p = String( iso ).split( '-' );
		var a = parseInt( p[ 0 ], 10 );
		var m = parseInt( p[ 1 ], 10 ) - 1;
		var d = parseInt( p[ 2 ], 10 );

		if ( isNaN( a ) || isNaN( m ) || isNaN( d ) ) {
			return '';
		}

		var destino = new Date( a, m + n, 1 );
		var ultimo  = new Date( destino.getFullYear(), destino.getMonth() + 1, 0 ).getDate();

		destino.setDate( Math.min( d, ultimo ) );

		var mm = String( destino.getMonth() + 1 );
		var dd = String( destino.getDate() );

		return destino.getFullYear() + '-' + ( mm.length < 2 ? '0' + mm : mm ) + '-' + ( dd.length < 2 ? '0' + dd : dd );
	}

	if ( dividir ) {
		dividir.addEventListener( 'click', function () {
			var salida = document.getElementById( 'esc-ed-total' );
			var total  = salida ? ( parseInt( String( salida.textContent ).replace( /[^\d]/g, '' ), 10 ) || 0 ) : 0;
			var cuantas = parseInt( ( document.getElementById( 'esc-cuotas-n' ) || {} ).value, 10 ) || 0;
			var desde   = ( document.getElementById( 'esc-cuotas-desde' ) || {} ).value || '';

			if ( total <= 0 || cuantas < 2 || ! desde ) {
				return;
			}

			cuantas = Math.min( 24, cuantas );

			// Cuotas iguales hacia abajo; la última se queda con lo que sobra
			// para que la suma dé exactamente el total.
			var base  = Math.floor( total / cuantas );
			var resto = total - ( base * ( cuantas - 1 ) );
			var nuevas = [];

			/*
			 * Se arman todas antes de tocar las que hay. Si algo falla a la
			 * mitad —la plantilla no llegó, por ejemplo— ella se queda con
			 * sus cuotas de siempre en vez de con ninguna.
			 */
			for ( var i = 0; i < cuantas; i++ ) {
				var fila = nuevaFila( i );

				if ( ! fila || ! fila.querySelector( '[data-monto]' ) || ! fila.querySelector( '[data-fecha]' ) ) {
					return;
				}

				fila.querySelector( 'input[type="text"]' ).value = ( I18N.cuota || 'Cuota %d' ).replace( '%d', String( i + 1 ) );
				fila.querySelector( '[data-monto]' ).value = window.escMiles( i === cuantas - 1 ? resto : base );
				fila.querySelector( '[data-fecha]' ).value = sumarMeses( desde, i );

				nuevas.push( fila );
			}

			Array.prototype.forEach.call( filas(), function ( f ) { f.remove(); } );

			nuevas.forEach( function ( f ) { lista.appendChild( f ); } );

			renumerar();
		} );
	}

	Array.prototype.forEach.call( filas(), ajustarFecha );
}() );

/**
 * El formulario de documentos tributarios.
 *
 * Cambiar el tipo cambia qué significa el monto: en una factura es el neto y
 * el IVA se suma, en una boleta es el total y el IVA ya viene adentro. Acá se
 * repinta el desglose y el rótulo del campo mientras ella escribe, con la
 * misma cuenta que hace PHP al guardar.
 */
( function () {
	'use strict';

	var CFG  = window.malkaEscritorio || {};
	var form = document.getElementById( 'esc-doc' );

	if ( ! form ) {
		return;
	}

	var selTipo  = form.querySelector( '[data-doc-tipo]' );
	var campo    = form.querySelector( '[data-doc-base]' );
	var salida   = document.getElementById( 'esc-desglose' );
	var rotulo   = document.getElementById( 'esc-doc-rotulo' );
	var ayuda    = document.getElementById( 'esc-doc-ayuda' );
	var cajaRef  = document.getElementById( 'esc-doc-ref' );

	if ( ! selTipo || ! campo || ! salida ) {
		return;
	}

	var IVA = parseFloat( CFG.iva );
	var RET = parseFloat( CFG.retencion );

	if ( isNaN( IVA ) ) { IVA = 19; }
	if ( isNaN( RET ) ) { RET = 15.25; }

	var CAMPOS = CFG.docCampos || {};
	var FILAS  = CFG.docFilas || {};

	function plata( n ) {
		var abs = Math.round( Math.abs( n || 0 ) );
		return ( n < 0 ? '-$' : '$' ) + String( abs ).replace( /\B(?=(\d{3})+(?!\d))/g, '.' );
	}

	/**
	 * La misma cuenta que Malka_CRM_Documents::desglose(). Si una de las dos
	 * cambia, la otra tiene que cambiar con ella.
	 */
	function desglose( tipo, base ) {
		// El campo muestra «1.390.000»: sólo cuentan los dígitos.
		base = parseFloat( String( base ).replace( /\D/g, '' ) ) || 0;

		if ( 'boleta' === tipo ) {
			var total = Math.round( base );
			var neto  = Math.round( base / ( 1 + ( IVA / 100 ) ) );
			return { neto: neto, iva: total - neto, retencion: 0, total: total };
		}

		if ( 'exenta' === tipo ) {
			return { neto: Math.round( base ), iva: 0, retencion: 0, total: Math.round( base ) };
		}

		if ( 'honorarios' === tipo ) {
			return {
				neto: Math.round( base ),
				iva: 0,
				retencion: Math.round( base * ( RET / 100 ) ),
				total: Math.round( base )
			};
		}

		var n = Math.round( base );
		var i = Math.round( base * ( IVA / 100 ) );

		return { neto: n, iva: i, retencion: 0, total: n + i };
	}

	function fila( etiqueta, valor, clase ) {
		var s = document.createElement( 'span' );

		s.className = 'esc-desglose__r' + ( clase ? ' ' + clase : '' );

		var b = document.createElement( 'b' );
		b.textContent = etiqueta;

		var u = document.createElement( 'u' );
		u.textContent = plata( valor );

		s.appendChild( b );
		s.appendChild( u );

		return s;
	}

	function pintar() {
		var tipo = selTipo.value;
		var g    = desglose( tipo, campo.value );

		salida.textContent = '';

		if ( 'honorarios' === tipo ) {
			salida.appendChild( fila( FILAS.brutos || 'Honorarios brutos', g.neto ) );
			salida.appendChild( fila( FILAS.retencion || 'Retención', -g.retencion ) );
			salida.appendChild( fila( FILAS.deposita || 'Te depositan', g.total - g.retencion, 'is-total' ) );
		} else {
			salida.appendChild( fila( 'exenta' === tipo ? 'Monto exento' : ( FILAS.neto || 'Neto' ), g.neto ) );
			salida.appendChild( fila( 'exenta' === tipo ? 'IVA · exenta' : ( FILAS.iva || 'IVA' ), g.iva ) );
			salida.appendChild( fila(
				'nota' === tipo ? ( FILAS.anula || 'Total que se anula' ) : ( FILAS.total || 'Total del documento' ),
				g.total,
				'is-total'
			) );
		}

		var c = CAMPOS[ tipo ];

		if ( c ) {
			if ( rotulo ) { rotulo.textContent = c.label; }
			if ( ayuda ) { ayuda.textContent = c.ayuda; }
		}

		if ( cajaRef ) {
			cajaRef.hidden = ( 'nota' !== tipo );
		}
	}

	/*
	 * El monto se escribe y se ve con puntos de miles: «1.390.000». El
	 * cursor se queda detrás del mismo dígito aunque aparezca un punto.
	 */
	function miles( v ) {
		var d = String( v || '' ).replace( /\D/g, '' ).replace( /^0+(?=\d)/, '' );
		return d.replace( /\B(?=(\d{3})+(?!\d))/g, '.' );
	}

	function formatear() {
		var antes   = campo.value;
		var cursor  = campo.selectionStart || antes.length;
		var digitos = antes.slice( 0, cursor ).replace( /\D/g, '' ).length;
		var nuevo   = miles( antes );

		if ( nuevo === antes ) {
			return;
		}

		campo.value = nuevo;

		var pos = 0;
		for ( var vistos = 0; pos < nuevo.length && vistos < digitos; pos++ ) {
			if ( /\d/.test( nuevo[ pos ] ) ) {
				vistos++;
			}
		}
		if ( document.activeElement === campo ) {
			campo.setSelectionRange( pos, pos );
		}
	}

	selTipo.addEventListener( 'change', pintar );
	campo.addEventListener( 'input', function () {
		formatear();
		pintar();
	} );

	campo.value = miles( campo.value );
	pintar();
}() );

/**
 * El árbol de etapas y tareas.
 *
 * Agregar, quitar y renumerar. El número es lo que define la jerarquía —1 es
 * etapa, 1.1 su tarea, 1.1.1 una subtarea— así que al agregar se propone el
 * que corresponde y la sangría se ajusta sola mientras ella escribe.
 */
( function () {
	'use strict';

	var CFG   = window.malkaEscritorio || {};
	var arbol = document.getElementById( 'esc-arbol' );

	if ( ! arbol ) {
		return;
	}

	function filas() {
		return arbol.querySelectorAll( '.esc-tarea' );
	}

	function nivel( numero ) {
		var partes = String( numero || '' ).split( '.' ).filter( function ( x ) {
			return '' !== x;
		} );

		return Math.max( 1, Math.min( 4, partes.length ) );
	}

	/**
	 * Pone la sangría y el color según lo que diga la fila.
	 */
	function ajustar( fila ) {
		var num = fila.querySelector( '[data-numero]' );
		var tip = fila.querySelector( '[data-tipo]' );
		var pct = fila.querySelector( 'input[type="number"][name$="[progress]"]' );
		var esEtapa = !! tip && 'stage' === tip.value;

		fila.setAttribute( 'data-nivel', String( esEtapa ? 1 : nivel( num ? num.value : '' ) ) );
		fila.classList.toggle( 'is-etapa', esEtapa );

		// El avance de una etapa lo calcula el servidor desde sus tareas.
		if ( pct ) {
			pct.readOnly = esEtapa;
		}
	}

	/*
	 * El mismo árbol vive en dos pantallas: la ficha del negocio, donde los
	 * campos se llaman tarea[…], y el editor de plantillas, donde se llaman
	 * tpl[…]. Antes esto daba por sentado «tarea», así que en plantillas no
	 * renumeraba nada y la fila que agregaba el botón se guardaba con el
	 * nombre equivocado: al grabar, desaparecía sin decir por qué.
	 */
	function prefijo() {
		// Con el árbol vacío no hay campo del que sacarlo: lo dice el árbol.
		if ( arbol.getAttribute( 'data-prefijo' ) ) {
			return arbol.getAttribute( 'data-prefijo' );
		}
		var campo = arbol.querySelector( '[data-numero][name]' );
		var m     = campo ? String( campo.getAttribute( 'name' ) ).match( /^([a-z_]+)\[/i ) : null;

		return m ? m[ 1 ] : 'tarea';
	}

	function renumerar() {
		var pre = prefijo();
		var re  = new RegExp( '^' + pre + '\\[[^\\]]*\\]' );

		Array.prototype.forEach.call( filas(), function ( fila, i ) {
			Array.prototype.forEach.call( fila.querySelectorAll( '[name^="' + pre + '["]' ), function ( campo ) {
				campo.setAttribute( 'name', campo.getAttribute( 'name' ).replace( re, pre + '[' + i + ']' ) );
			} );
		} );
	}

	/**
	 * El número que le toca a una etapa nueva: la siguiente de primer nivel.
	 */
	function proximaEtapa() {
		var max = 0;

		Array.prototype.forEach.call( arbol.querySelectorAll( '[data-numero]' ), function ( c ) {
			var n = parseInt( String( c.value ).split( '.' )[ 0 ], 10 );

			if ( ! isNaN( n ) && n > max ) {
				max = n;
			}
		} );

		return String( max + 1 );
	}

	/**
	 * El número de una tarea nueva: cuelga de la última etapa que haya.
	 */
	function proximaTarea() {
		var etapa = proximaEtapa();
		var ultima = parseInt( etapa, 10 ) - 1;

		if ( ultima < 1 ) {
			return '1.1';
		}

		var max = 0;

		Array.prototype.forEach.call( arbol.querySelectorAll( '[data-numero]' ), function ( c ) {
			var p = String( c.value ).split( '.' );

			if ( parseInt( p[ 0 ], 10 ) === ultima && p.length > 1 ) {
				var n = parseInt( p[ 1 ], 10 );
				if ( ! isNaN( n ) && n > max ) {
					max = n;
				}
			}
		} );

		return ultima + '.' + ( max + 1 );
	}

	function agregar( tipo ) {
		if ( ! CFG.tareaTpl ) {
			return;
		}

		var caja = document.createElement( 'div' );

		/*
		 * La plantilla viene con los nombres de la ficha del negocio. En el
		 * editor de plantillas los campos se llaman tpl[…], así que se traduce
		 * antes de insertarla: si no, la fila nueva no se guarda.
		 */
		var pre = prefijo();

		caja.innerHTML = CFG.tareaTpl
			.replace( /__i__/g, String( filas().length ) )
			.replace( /(name=")tarea\[/g, '$1' + pre + '[' );

		var fila = caja.firstElementChild;

		if ( ! fila ) {
			return;
		}

		var num = fila.querySelector( '[data-numero]' );
		var tip = fila.querySelector( '[data-tipo]' );

		if ( num ) {
			num.value = ( 'stage' === tipo ) ? proximaEtapa() : proximaTarea();
		}
		if ( tip ) {
			tip.value = tipo;
		}

		arbol.appendChild( fila );
		ajustar( fila );
		renumerar();

		var nombre = fila.querySelector( '.esc-tarea__n' );
		if ( nombre ) {
			nombre.focus();
		}
	}

	arbol.addEventListener( 'input', function ( e ) {
		if ( e.target.closest( '[data-numero]' ) ) {
			ajustar( e.target.closest( '.esc-tarea' ) );
		}
	} );

	arbol.addEventListener( 'change', function ( e ) {
		if ( e.target.closest( '[data-tipo]' ) ) {
			ajustar( e.target.closest( '.esc-tarea' ) );
		}
	} );

	/*
	 * «+» en una fila: agrega adentro. En una etapa (1), la tarea 1.N; en
	 * una tarea (1.2), la subtarea 1.2.N. Va al final de su grupo.
	 */
	arbol.addEventListener( 'click', function ( e ) {
		var b = e.target.closest ? e.target.closest( '[data-sub]' ) : null;
		if ( ! b || ! CFG.tareaTpl ) {
			return;
		}
		e.preventDefault();
		var padre = b.closest( '.esc-tarea' );
		var pnum  = padre.querySelector( '[data-numero]' );
		var base  = pnum ? String( pnum.value ).trim() : '';
		if ( ! base || base.split( '.' ).length >= 4 ) {
			return;
		}
		var junto = grupo( padre );
		var max   = 0;
		junto.forEach( function ( f ) {
			var n = f.querySelector( '[data-numero]' );
			var v = n ? String( n.value ) : '';
			if ( 0 === v.indexOf( base + '.' ) ) {
				var resto = v.slice( base.length + 1 ).split( '.' );
				if ( 1 === resto.length ) {
					max = Math.max( max, parseInt( resto[ 0 ], 10 ) || 0 );
				}
			}
		} );
		var caja = document.createElement( 'div' );
		caja.innerHTML = CFG.tareaTpl
			.replace( /__i__/g, String( filas().length ) )
			.replace( /(name=")tarea\[/g, '$1' + prefijo() + '[' );
		var fila = caja.firstElementChild;
		if ( ! fila ) {
			return;
		}
		var num = fila.querySelector( '[data-numero]' );
		var tip = fila.querySelector( '[data-tipo]' );
		if ( num ) {
			num.value = base + '.' + ( max + 1 );
		}
		if ( tip ) {
			tip.value = 'task';
		}
		var ultimo = junto[ junto.length - 1 ];
		arbol.insertBefore( fila, ultimo.nextSibling );
		ajustar( fila );
		renumerar();
		var nombre = fila.querySelector( '.esc-tarea__n' );
		if ( nombre ) {
			nombre.focus();
		}
	} );

	/*
	 * Al elegir el formato de una publicación (reel, historia, carrusel…)
	 * sus pasos de siempre aparecen como subtareas. Los pasos se editan en
	 * Negocios › Plantillas. Sólo si todavía no tiene ninguna: nunca se
	 * duplican ni se pisan las que ya se armaron a mano.
	 */
	arbol.addEventListener( 'change', function ( e ) {
		if ( ! e.target.matches || ! e.target.matches( '[data-pub-formato]' ) || ! CFG.tareaTpl ) {
			return;
		}
		var PASOS = ( CFG.pasosPub && CFG.pasosPub[ e.target.value ] ) || [];
		if ( ! PASOS.length ) {
			return;
		}
		var padre = e.target.closest( '.esc-tarea' );
		var pnum  = padre ? padre.querySelector( '[data-numero]' ) : null;
		var base  = pnum ? String( pnum.value ).trim() : '';
		if ( ! base || base.split( '.' ).length >= 4 ) {
			return;
		}
		var junto = grupo( padre );
		if ( junto.length > 1 ) {
			return;
		}
		var ini = padre.querySelector( '[name$="[start]"]' );
		var fin = padre.querySelector( '[name$="[end]"]' );
		var ant = padre;
		PASOS.forEach( function ( paso, k ) {
			var caja = document.createElement( 'div' );
			caja.innerHTML = CFG.tareaTpl
				.replace( /__i__/g, String( filas().length ) )
				.replace( /(name=")tarea\[/g, '$1' + prefijo() + '[' );
			var fila = caja.firstElementChild;
			if ( ! fila ) {
				return;
			}
			var num = fila.querySelector( '[data-numero]' );
			var tip = fila.querySelector( '[data-tipo]' );
			var nom = fila.querySelector( '.esc-tarea__n' );
			var own = fila.querySelector( '[name$="[owner]"]' );
			var fi  = fila.querySelector( '[name$="[start]"]' );
			var ff  = fila.querySelector( '[name$="[end]"]' );
			if ( num ) {
				num.value = base + '.' + ( k + 1 );
			}
			if ( tip ) {
				tip.value = 'task';
			}
			if ( nom ) {
				nom.value = paso[ 0 ];
			}
			if ( own ) {
				own.value = paso[ 1 ];
			}
			var prv = fila.querySelector( '[name$="[privada]"]' );
			if ( prv ) {
				prv.value = paso[ 2 ] ? '1' : '';
				if ( paso[ 2 ] ) {
					fila.classList.add( 'is-privada' );
				}
			}
			if ( fi && ini && ini.value ) {
				fi.value = ini.value;
			}
			if ( ff && fin && fin.value ) {
				ff.value = fin.value;
			}
			arbol.insertBefore( fila, ant.nextSibling );
			ant = fila;
			ajustar( fila );
		} );
		renumerar();
		padre.classList.add( 'has-hijos' );
	} );

	/*
	 * Duplicar: copia la fila con todo lo que cuelga de ella y la deja al
	 * final de su grupo con el número siguiente (2.8 → 2.13 si la última es
	 * 2.12). La copia parte sin marcar y sin lo que escribió el cliente.
	 */
	arbol.addEventListener( 'click', function ( e ) {
		var b = e.target.closest ? e.target.closest( '[data-dup]' ) : null;
		if ( ! b ) {
			return;
		}
		e.preventDefault();
		var padre = b.closest( '.esc-tarea' );
		var pnum  = padre.querySelector( '[data-numero]' );
		var base  = pnum ? String( pnum.value ).trim() : '';
		var junto = grupo( padre );
		var partes = base.split( '.' );
		var pre    = partes.length > 1 ? partes.slice( 0, -1 ).join( '.' ) + '.' : '';
		var max    = 0;
		var ultima = junto[ junto.length - 1 ];
		Array.prototype.forEach.call( filas(), function ( f ) {
			var n = f.querySelector( '[data-numero]' );
			var v = n ? String( n.value ).trim() : '';
			if ( ! v || ( pre && 0 !== v.indexOf( pre ) ) ) {
				return;
			}
			var resto = v.slice( pre.length ).split( '.' );
			max = Math.max( max, parseInt( resto[ 0 ], 10 ) || 0 );
			ultima = f;
		} );
		var nuevo = base ? pre + ( max + 1 ) : '';
		var tras  = ultima;
		junto.forEach( function ( f ) {
			var c = f.cloneNode( true );
			var n = c.querySelector( '[data-numero]' );
			if ( n && base ) {
				n.value = nuevo + String( n.value ).trim().slice( base.length );
			}
			c.classList.remove( 'is-lista', 'has-nota' );
			c.querySelectorAll( '[name$="[id]"]' ).forEach( function ( x ) {
				x.value = 't' + Math.random().toString( 36 ).slice( 2, 11 );
			} );
			c.querySelectorAll( '[data-pub-dia], [data-pub-hora]' ).forEach( function ( x ) {
				x.value = '';
			} );
			[ 'done_at', 'done_by', 'nota_cliente', 'nota_cliente_at', 'ultima', 'veces', 'pub_fecha', 'pub_audio', 'pub_audio_at', 'resp_audio', 'resp_audio_at' ].forEach( function ( k ) {
				c.querySelectorAll( '[name$="[' + k + ']"]' ).forEach( function ( x ) {
					x.value = '';
				} );
			} );
			c.querySelectorAll( 'input[type="checkbox"][name$="[done]"]' ).forEach( function ( x ) {
				x.checked = false;
			} );
			c.querySelectorAll( 'input[name$="[progress]"]' ).forEach( function ( x ) {
				x.value = '0';
			} );
			c.querySelectorAll( '.esc-td__nc, .esc-fx__nc' ).forEach( function ( x ) {
				x.remove();
			} );
			var det = c.querySelector( '.esc-tarea__det' );
			if ( det ) {
				det.hidden = true;
			}
			var ab = c.querySelector( '.esc-tarea__ab' );
			if ( ab ) {
				ab.setAttribute( 'aria-expanded', 'false' );
			}
			arbol.insertBefore( c, tras.nextSibling );
			tras = c;
			ajustar( c );
		} );
		renumerar();
		var primera = junto.length ? tras : null;
		var aviso = arbol.querySelector( '[data-numero]' );
		if ( aviso ) {
			aviso.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
		if ( primera ) {
			primera.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
		}
	} );

	arbol.addEventListener( 'click', function ( e ) {
		var x = e.target.closest ? e.target.closest( '.esc-tarea__x' ) : null;

		if ( ! x ) {
			return;
		}

		x.closest( '.esc-tarea' ).remove();
		renumerar();
	} );

	var btnEtapa = document.getElementById( 'esc-arbol-etapa' );
	var btnTarea = document.getElementById( 'esc-arbol-tarea' );

	if ( btnEtapa ) {
		btnEtapa.addEventListener( 'click', function () { agregar( 'stage' ); } );
	}
	if ( btnTarea ) {
		btnTarea.addEventListener( 'click', function () { agregar( 'task' ); } );
	}

	/* ────────────────────  Cambiar filas de lugar  ─────────────────── */

	/*
	 * El orden en que quedan las filas es el orden en que se guardan: el
	 * servidor recorre lo que llega del formulario y lo escribe así. Mover una
	 * fila es, entonces, moverla de verdad en la página.
	 *
	 * Los números se rehacen después de cada movimiento, porque en este árbol
	 * el número no es un rótulo sino la jerarquía: 2.4 significa «la cuarta de
	 * la etapa 2». Si la fila cambia de lugar y el número no, el árbol pasa a
	 * decir una cosa y a mostrar otra. Sólo se rehacen al mover: escribir un
	 * número a mano sigue mandando.
	 */

	/** El nivel que declara una fila. */
	function nivelDe( fila ) {
		var tip = fila.querySelector( '[data-tipo]' );

		if ( tip && 'stage' === tip.value ) {
			return 1;
		}

		return Math.max( 1, Math.min( 4, parseInt( fila.getAttribute( 'data-nivel' ), 10 ) || 1 ) );
	}

	/**
	 * La fila y todo lo que cuelga de ella.
	 *
	 * Arrastrar una etapa tiene que llevarse sus tareas: dejarlas atrás
	 * desarmaría el árbol en el acto.
	 */
	function grupo( fila ) {
		var todas = Array.prototype.slice.call( filas() );
		var desde = todas.indexOf( fila );

		if ( desde < 0 ) {
			return [];
		}

		var mio   = nivelDe( fila );
		var junto = [ fila ];
		var i;

		for ( i = desde + 1; i < todas.length; i++ ) {
			if ( nivelDe( todas[ i ] ) <= mio ) {
				break;
			}

			junto.push( todas[ i ] );
		}

		return junto;
	}

	/**
	 * Rehace los números siguiendo el orden de la página.
	 *
	 * Una fila no puede saltarse un nivel: si la primera que hay es una
	 * subtarea, se la trata como lo más hondo que permite lo que viene antes.
	 */
	function renumerarArbol() {
		var cuenta = [ 0, 0, 0, 0 ];
		var previo = 0;

		Array.prototype.forEach.call( filas(), function ( fila ) {
			var n = Math.min( nivelDe( fila ), previo + 1 );
			var i;

			n = Math.max( 1, n );
			cuenta[ n - 1 ]++;

			for ( i = n; i < 4; i++ ) {
				cuenta[ i ] = 0;
			}

			var num = fila.querySelector( '[data-numero]' );

			if ( num ) {
				num.value = cuenta.slice( 0, n ).join( '.' );
			}

			fila.setAttribute( 'data-nivel', String( n ) );
			previo = n;
		} );

		renumerar();
	}

	/** Deja el grupo justo antes de `ante`, o al final si no hay. */
	function soltar( junto, ante ) {
		junto.forEach( function ( f ) {
			if ( ante ) {
				arbol.insertBefore( f, ante );
			} else {
				arbol.appendChild( f );
			}
		} );
	}

	/* ── Con el teclado: Alt y las flechas ── */

	arbol.addEventListener( 'keydown', function ( e ) {
		var asa = e.target.closest ? e.target.closest( '[data-mover]' ) : null;

		if ( ! asa || ! e.altKey ) {
			return;
		}

		if ( 'ArrowUp' !== e.key && 'ArrowDown' !== e.key ) {
			return;
		}

		e.preventDefault();

		var fila  = asa.closest( '.esc-tarea' );
		var junto = grupo( fila );
		var todas = Array.prototype.slice.call( filas() );
		var ini   = todas.indexOf( junto[ 0 ] );
		var fin   = todas.indexOf( junto[ junto.length - 1 ] );

		if ( 'ArrowUp' === e.key ) {
			if ( ini < 1 ) {
				return;
			}

			// Sube por encima del grupo entero de arriba, no de una fila suelta.
			var arriba = todas[ ini - 1 ];
			var jefe   = arriba;

			while ( jefe && nivelDe( jefe ) > nivelDe( fila ) ) {
				jefe = jefe.previousElementSibling;
			}

			soltar( junto, jefe || arriba );
		} else {
			if ( fin >= todas.length - 1 ) {
				return;
			}

			var abajo  = todas[ fin + 1 ];
			var suyo   = grupo( abajo );
			var ultimo = suyo[ suyo.length - 1 ];

			soltar( junto, ultimo.nextElementSibling );
		}

		renumerarArbol();
		asa.focus();
	} );

	/* ── Con el dedo o el ratón ── */

	var llevando = null;

	/** Dónde iría el grupo si se soltara a esta altura. */
	function destino( y, junto ) {
		var fuera = Array.prototype.filter.call( filas(), function ( f ) {
			return -1 === junto.indexOf( f );
		} );
		var i;

		for ( i = 0; i < fuera.length; i++ ) {
			var caja = fuera[ i ].getBoundingClientRect();

			if ( y < caja.top + ( caja.height / 2 ) ) {
				return fuera[ i ];
			}
		}

		return null;
	}

	arbol.addEventListener( 'pointerdown', function ( e ) {
		var asa = e.target.closest ? e.target.closest( '[data-mover]' ) : null;

		if ( ! asa || ( e.button && 0 !== e.button ) ) {
			return;
		}

		e.preventDefault();

		var fila = asa.closest( '.esc-tarea' );

		llevando = { junto: grupo( fila ), asa: asa };

		llevando.junto.forEach( function ( f ) {
			f.classList.add( 'is-moviendo' );
		} );

		arbol.classList.add( 'is-ordenando' );

		// preventDefault() se comió el foco; acá se devuelve, para que después
		// de arrastrar se pueda seguir con Alt y las flechas.
		asa.focus();

		if ( asa.setPointerCapture ) {
			asa.setPointerCapture( e.pointerId );
		}
	} );

	arbol.addEventListener( 'pointermove', function ( e ) {
		if ( ! llevando ) {
			return;
		}

		e.preventDefault();

		var ante   = destino( e.clientY, llevando.junto );
		var ultimo = llevando.junto[ llevando.junto.length - 1 ];

		/*
		 * Si el grupo ya está justo donde iría, no se toca. Sin esta guarda se
		 * reinsertaba en cada píxel de movimiento, que además de inútil pierde
		 * la selección y la posición del cursor de los campos que arrastra.
		 * Al final de la lista el vecino siguiente es null en los dos lados,
		 * así que la comparación también cubre ese caso.
		 */
		if ( ante !== ultimo.nextElementSibling ) {
			soltar( llevando.junto, ante );
		}
	} );

	function terminar() {
		if ( ! llevando ) {
			return;
		}

		llevando.junto.forEach( function ( f ) {
			f.classList.remove( 'is-moviendo' );
		} );

		arbol.classList.remove( 'is-ordenando' );
		llevando = null;

		renumerarArbol();
	}

	arbol.addEventListener( 'pointerup', terminar );
	arbol.addEventListener( 'pointercancel', terminar );

	/* ──────────────────  Abrir y cerrar el detalle  ────────────────── */

	function abrir( fila, si ) {
		var det = fila.querySelector( '.esc-tarea__det' );
		var bot = fila.querySelector( '.esc-tarea__ab' );

		if ( ! det || ! bot ) {
			return;
		}

		det.hidden = ! si;
		bot.setAttribute( 'aria-expanded', si ? 'true' : 'false' );
		fila.classList.toggle( 'is-abierta', !! si );
		if ( si && window.malkaFicha ) {
			window.malkaFicha( fila );
		}
	}

	arbol.addEventListener( 'click', function ( e ) {
		var bot = e.target.closest ? e.target.closest( '.esc-tarea__ab' ) : null;

		if ( ! bot ) {
			return;
		}

		var fila = bot.closest( '.esc-tarea' );
		abrir( fila, 'true' !== bot.getAttribute( 'aria-expanded' ) );
	} );

	/* ────────────────────────  El resumen  ─────────────────────────── */

	var MESES = [ 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic' ];

	function dia( iso ) {
		var p = String( iso || '' ).split( '-' );

		if ( 3 !== p.length ) {
			return '';
		}

		return parseInt( p[ 2 ], 10 ) + ' ' + ( MESES[ parseInt( p[ 1 ], 10 ) - 1 ] || '' );
	}

	/*
	 * Tiene que decir lo mismo que resumen_tarea() en PHP: eso es lo que se ve
	 * al recargar. Si cambia el formato allá, cambia acá.
	 */
	function resumir( fila ) {
		var caja = fila.querySelector( '[data-resumen]' );

		if ( ! caja ) {
			return;
		}

		function val( sufijo ) {
			var el = fila.querySelector( '[name$="[' + sufijo + ']"]' );
			return el ? el.value : '';
		}

		var partes = [];
		var quien  = fila.querySelector( 'select[name$="[owner]"]' );

		if ( quien && 'none' !== quien.value ) {
			partes.push( quien.options[ quien.selectedIndex ].text );
		}

		/*
		 * La misma fila sirve para el árbol de un negocio y para una
		 * plantilla. El negocio tiene fechas y avance; la plantilla no sabe
		 * cuándo parte nada y en su lugar guarda cuántos días toma. Sin este
		 * reparto, al abrir una plantilla el JS le borraba los días.
		 */
		var ini = dia( val( 'start' ) );
		var fin = dia( val( 'end' ) );

		if ( ini && fin && ini !== fin ) {
			partes.push( ini + ' – ' + fin );
		} else if ( fin || ini ) {
			partes.push( fin || ini );
		}

		var dias = parseInt( val( 'due_days' ), 10 );

		if ( ! ini && ! fin && dias > 0 ) {
			partes.push( dias + ( 1 === dias ? ' día' : ' días' ) );
		}

		var pct = parseInt( val( 'progress' ), 10 );

		if ( pct > 0 ) {
			partes.push( pct + '%' );
		}

		var ap = fila.querySelector( '[name$="[needs_approval]"]' );

		if ( ap && ( 'SELECT' === ap.tagName ? '1' === ap.value : ap.checked ) ) {
			partes.push( 'la aprueba el cliente' );
		}

		var pub = val( 'pub_fecha' );

		if ( pub ) {
			var dt = new Date( pub );
			if ( ! isNaN( dt ) ) {
				partes.push( 'sale ' + dt.toLocaleDateString( 'es-CL', { day: 'numeric', month: 'short' } ) + ' ' + dt.toLocaleTimeString( 'es-CL', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' } ) );
			}
		}

		caja.textContent = partes.join( ' · ' );
	}

	arbol.addEventListener( 'change', function ( e ) {
		var fila = e.target.closest ? e.target.closest( '.esc-tarea' ) : null;

		if ( ! fila ) {
			return;
		}

		resumir( fila );

		var listo = fila.querySelector( 'input[name$="[done]"]' );

		if ( listo ) {
			fila.classList.toggle( 'is-lista', listo.checked );
		}
	} );

	Array.prototype.forEach.call( filas(), function ( f ) {
		ajustar( f );
		resumir( f );
	} );

	/*
	 * Una etapa con tareas adentro se da por lista sola (promedio de sus
	 * tareas), así que esconde su círculo. Una etapa sola, sin tareas, se
	 * marca a mano como cualquier tarea.
	 */
	function marcarHijos() {
		var lista = Array.prototype.slice.call( filas() );
		lista.forEach( function ( f, k ) {
			var tip = f.querySelector( '[data-tipo]' );
			var num = f.querySelector( '[data-numero]' );
			var sig = lista[ k + 1 ];
			var sn  = sig ? sig.querySelector( '[data-numero]' ) : null;
			var st  = sig ? sig.querySelector( '[data-tipo]' ) : null;
			var esEtapa = f.classList.contains( 'is-etapa' ) || ( tip && 'stage' === tip.value );
			var conHijos = esEtapa && !! sn && ! ( st && 'stage' === st.value ) &&
				!! num && '' !== num.value && 0 === String( sn.value ).indexOf( String( num.value ).replace( /\.$/, '' ) + '.' );
			f.classList.toggle( 'has-hijos', !! conHijos );
		} );
	}
	marcarHijos();
	arbol.addEventListener( 'input', marcarHijos );
	arbol.addEventListener( 'change', marcarHijos );
	if ( window.MutationObserver ) {
		new MutationObserver( marcarHijos ).observe( arbol, { childList: true } );
	}
}() );

/**
 * La hoja de «Más», en el teléfono.
 *
 * La barra lateral se convierte en pestañas abajo y sólo caben cinco. Este
 * botón despliega el resto como una hoja, con el velo detrás para que quede
 * claro que lo de abajo no se puede tocar mientras tanto.
 */
( function () {
	'use strict';

	var barra = document.querySelector( '.esc-side' );
	var boton = document.getElementById( 'esc-mas' );
	var velo  = document.getElementById( 'esc-velo' );

	if ( ! barra || ! boton ) {
		return;
	}

	function abrir( si ) {
		barra.classList.toggle( 'is-abierta', si );
		boton.setAttribute( 'aria-expanded', si ? 'true' : 'false' );

		if ( velo ) {
			velo.hidden = ! si;
		}

		if ( ! si ) {
			barra.scrollTop = 0;
		}
	}

	boton.addEventListener( 'click', function () {
		abrir( ! barra.classList.contains( 'is-abierta' ) );
	} );

	if ( velo ) {
		velo.addEventListener( 'click', function () { abrir( false ); } );
	}

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && barra.classList.contains( 'is-abierta' ) ) {
			abrir( false );
		}
	} );

	// Al pasar a pantalla ancha la hoja deja de tener sentido.
	window.addEventListener( 'resize', function () {
		if ( window.innerWidth > 900 && barra.classList.contains( 'is-abierta' ) ) {
			abrir( false );
		}
	} );
}() );

/**
 * El logo de la marca, en Configuración.
 *
 * Si WordPress trae su biblioteca de medios se usa; si no —por ejemplo si el
 * tema la deja fuera— el campo de URL sigue sirviendo escrito a mano, así que
 * no se pierde la función.
 */
( function () {
	'use strict';

	function enganchar( idCampo, idVista, idSubir, idQuitar, vacio, titulo ) {
		var campo  = document.getElementById( idCampo );
		var vista  = document.getElementById( idVista );
		var subir  = document.getElementById( idSubir );
		var quitar = document.getElementById( idQuitar );

		if ( ! campo || ! vista ) {
			return;
		}

		function pintar( url ) {
			var antes = campo.value;
			campo.value = url || '';

			if ( url ) {
				vista.innerHTML = '';
				var img = document.createElement( 'img' );
				img.src = url;
				img.alt = '';
				vista.appendChild( img );
			} else {
				vista.textContent = vacio;
			}
			if ( antes !== campo.value ) {
				campo.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			}
		}

		if ( quitar ) {
			quitar.addEventListener( 'click', function () { pintar( '' ); } );
		}

		campo.addEventListener( 'change', function () { pintar( campo.value ); } );

		if ( ! subir ) {
			return;
		}

		if ( ! window.wp || ! window.wp.media ) {
			subir.hidden = true;
			return;
		}

		var marco = null;

		subir.addEventListener( 'click', function () {
			if ( ! marco ) {
				marco = window.wp.media( {
					title: titulo,
					button: { text: 'Usar este' },
					library: { type: 'image' },
					multiple: false
				} );

				marco.on( 'select', function () {
					var a = marco.state().get( 'selection' ).first().toJSON();
					pintar( a.url );
				} );
			}

			marco.open();
		} );
	}

	enganchar( 'f-logo_url', 'esc-logo-v', 'esc-logo-subir', 'esc-logo-quitar', 'Sin logo', 'Elige el logo' );
	enganchar( 'f-icono_app', 'esc-icono-v', 'esc-icono-subir', 'esc-icono-quitar', 'Sin ícono', 'Elige el ícono de la app' );
}() );

/**
 * Los campos que sólo tienen sentido en una compra.
 *
 * Van siempre en el HTML y se esconden, igual que el campo de referencia de
 * una nota de crédito: así cambiar de venta a compra no obliga a recargar y
 * perder lo que ya escribió.
 */
( function () {
	'use strict';

	var form = document.getElementById( 'esc-doc' );

	if ( ! form ) {
		return;
	}

	var sentido  = form.querySelector( '[data-doc-sentido]' );
	var emito    = form.querySelector( '[data-doc-emito]' );
	var estado   = form.querySelector( '[data-doc-estado]' );
	var cajaEm   = document.getElementById( 'esc-doc-emito' );
	var cajaFol  = document.getElementById( 'esc-doc-folio-mio' );
	var cajaSii  = document.getElementById( 'esc-doc-sii' );

	function pintar() {
		var esCompra = !! sentido && 'compra' === sentido.value;
		var lado     = esCompra ? 'compra' : 'venta';

		if ( cajaEm ) {
			cajaEm.hidden = ! esCompra;
		}

		var cajaPago = document.getElementById( 'esc-doc-pago' );
		if ( cajaPago ) {
			cajaPago.hidden = ! esCompra;
		}

		if ( cajaSii ) {
			// Los datos del SII, mientras le toque emitirla.
			cajaSii.hidden = ! ( esCompra && emito && '' !== emito.value );
		}

		if ( cajaFol ) {
			// El folio propio sólo cuando de verdad ya la emitió.
			cajaFol.hidden = ! ( esCompra && emito && 'emitida' === emito.value );
		}

		/*
		 * Los rótulos que cambian de nombre según el lado. Sin esto, el
		 * formulario decía «Cliente» y «Qué emitiste» mientras se registraba
		 * una compra.
		 */
		form.querySelectorAll( '[data-venta][data-compra]' ).forEach( function ( el ) {
			var t = el.getAttribute( 'data-' + lado );

			if ( t ) {
				el.textContent = t;
			}
		} );

		// Y los estados que no corresponden a este lado.
		if ( estado ) {
			var vivos = [];

			Array.prototype.forEach.call( estado.options, function ( o ) {
				var suyo = o.dataset.lado;
				var vale = ! suyo || 'ambos' === suyo || suyo === lado;

				o.hidden   = ! vale;
				o.disabled = ! vale;

				if ( vale ) {
					vivos.push( o.value );
				}
			} );

			// Si el elegido dejó de valer, se pasa al primero que sí vale.
			if ( vivos.length && -1 === vivos.indexOf( estado.value ) ) {
				estado.value = vivos[ 0 ];
			}
		}
	}

	if ( sentido ) {
		sentido.addEventListener( 'change', pintar );
	}
	if ( emito ) {
		emito.addEventListener( 'change', pintar );
	}

	pintar();
}() );

/**
 * La casilla de «sólo para el estudio», al agregar una tarea.
 *
 * Sin negocio no hay cliente que pueda verla, así que la casilla queda
 * marcada y bloqueada: dejarla desmarcable ofrecería una opción que no
 * cambia nada.
 */
( function () {
	'use strict';

	var negocio = document.querySelector( '[data-tarea-negocio]' );
	var privada = document.querySelector( '[data-tarea-privada]' );
	var nota    = document.querySelector( '[data-tarea-nota]' );

	if ( ! negocio || ! privada ) {
		return;
	}

	var NOTA_SIN = nota ? nota.textContent : '';

	function pintar() {
		// «personal» tampoco es un negocio: no hay cliente que pueda verla.
		var mia    = 'personal' === negocio.value;
		var suelta = mia || '0' === negocio.value || '' === negocio.value;

		privada.disabled = suelta;

		if ( suelta ) {
			privada.checked = true;
		}

		if ( nota ) {
			nota.textContent = mia
				? 'una tarea tuya no la ve nadie más'
				: ( suelta ? NOTA_SIN : '' );
		}
	}

	negocio.addEventListener( 'change', pintar );
	pintar();
}() );

/**
 * Autocompletado del cliente en el editor de cotizaciones.
 *
 * El `datalist` del navegador propone el nombre, pero sólo sabe llenar el
 * campo donde está. Esto completa los otros cinco con los datos de la ficha,
 * y lo hace con dos reglas para no pisar lo que ella escribió:
 *
 *  1. Rellena un campo si está vacío, o si todavía tiene exactamente el valor
 *     que puso un autocompletado anterior. Lo que ella escribió a mano se
 *     queda.
 *  2. Deja un aviso con «Deshacer» que devuelve los campos tal como estaban
 *     antes de rellenar. Eso es lo que permite completar mientras escribe sin
 *     que sea una imposición.
 */
( function () {
	'use strict';

	var datos = document.getElementById( 'esc-ed-fichas' );
	var aviso = document.getElementById( 'esc-ficha' );

	if ( ! datos ) {
		return;
	}

	var fichas;

	try {
		fichas = JSON.parse( datos.textContent );
	} catch ( e ) {
		return;
	}

	if ( ! fichas || ! fichas.length ) {
		return;
	}

	// Campo del formulario ← clave de la ficha.
	var CAMPOS = {
		'f-cliente_nombre': 'nombre',
		'f-cliente_empresa': 'empresa',
		'f-cliente_rut': 'rut',
		'f-cliente_ciudad': 'ciudad',
		'f-cliente_email': 'email',
		'f-cliente_fono': 'fono'
	};

	var campo = {};
	var k;

	for ( k in CAMPOS ) {
		if ( Object.prototype.hasOwnProperty.call( CAMPOS, k ) ) {
			campo[ k ] = document.getElementById( k );
		}
	}

	if ( ! campo['f-cliente_nombre'] ) {
		return;
	}

	/** Minúsculas y sin tildes, igual que en PHP. */
	function plegar( v ) {
		return String( v || '' )
			.toLowerCase()
			.replace( /[áàä]/g, 'a' )
			.replace( /[éèë]/g, 'e' )
			.replace( /[íìï]/g, 'i' )
			.replace( /[óòö]/g, 'o' )
			.replace( /[úùü]/g, 'u' )
			.replace( /ñ/g, 'n' )
			.trim();
	}

	/** Un RUT reducido a dígitos y verificador. */
	function soloRut( v ) {
		return String( v || '' ).replace( /[^0-9kK]/g, '' ).toLowerCase();
	}

	var puesto = null; // La ficha que rellenó la última vez.
	var antes  = null; // Cómo estaban los campos antes de eso.

	/**
	 * La ficha que corresponde a lo que hay escrito en un campo.
	 *
	 * Primero busca el calce exacto —es lo que deja elegir de la lista— y si
	 * no hay, acepta que lo escrito sea el principio del nombre de una sola
	 * ficha. Con dos o más candidatas no completa: adivinar entre dos es
	 * peor que no hacer nada.
	 */
	function calce( clave, texto ) {
		var aguja = 'rut' === clave ? soloRut( texto ) : plegar( texto );

		if ( aguja.length < ( 'rut' === clave ? 7 : 3 ) ) {
			return null;
		}

		var exacta  = null;
		var empieza = [];

		fichas.forEach( function ( f ) {
			var v = 'rut' === clave ? soloRut( f[ clave ] ) : plegar( f[ clave ] );

			if ( ! v ) {
				return;
			}

			if ( v === aguja && ! exacta ) {
				exacta = f;
			} else if ( 0 === v.indexOf( aguja ) ) {
				empieza.push( f );
			}
		} );

		if ( exacta ) {
			return exacta;
		}

		return 1 === empieza.length ? empieza[ 0 ] : null;
	}

	/** Lee los seis campos tal como están ahora. */
	function foto() {
		var f = {};
		var i;

		for ( i in CAMPOS ) {
			if ( Object.prototype.hasOwnProperty.call( CAMPOS, i ) && campo[ i ] ) {
				f[ i ] = campo[ i ].value;
			}
		}

		return f;
	}

	var ROTULO = aviso ? ( aviso.getAttribute( 'data-rotulo' ) || '%s' ) : '%s';

	function mostrar( f ) {
		if ( ! aviso ) {
			return;
		}

		var t = aviso.querySelector( '.esc-ficha__t' );

		if ( t ) {
			t.textContent = ROTULO.replace( '%s', f.nombre || f.empresa );
		}

		aviso.hidden = false;
	}

	function esconder() {
		if ( aviso ) {
			aviso.hidden = true;
		}
	}

	/**
	 * Rellena desde una ficha, sin tocar lo escrito a mano.
	 *
	 * @param {Object} f      La ficha.
	 * @param {string} salvo  Campo que disparó esto: ese no se toca.
	 */
	function rellenar( f, salvo ) {
		var guardar = foto();
		var cambio  = false;
		var i;

		for ( i in CAMPOS ) {
			if ( ! Object.prototype.hasOwnProperty.call( CAMPOS, i ) || ! campo[ i ] || i === salvo ) {
				continue;
			}

			var valor = String( f[ CAMPOS[ i ] ] || '' );
			var hay   = campo[ i ].value;
			var mio   = puesto && hay === String( puesto[ CAMPOS[ i ] ] || '' );

			if ( '' !== hay && ! mio ) {
				continue; // Es de ella: se queda.
			}

			if ( hay === valor ) {
				continue;
			}

			campo[ i ].value = valor;
			cambio = true;
		}

		if ( ! cambio ) {
			return;
		}

		if ( ! puesto ) {
			antes = guardar; // El «deshacer» vuelve a antes del primer relleno.
		}

		puesto = f;
		mostrar( f );
	}

	/**
	 * Suelta lo que puso el autocompletado y deja lo demás.
	 *
	 * Un campo se devuelve sólo si todavía tiene exactamente lo que pusimos
	 * nosotros. Lo que ella escribió después —incluido el nombre, que sigue
	 * tecleando mientras esto completa— no se toca nunca: deshacer no puede
	 * rebobinarle lo que acaba de escribir.
	 */
	function deshacer() {
		var i;

		if ( antes && puesto ) {
			for ( i in antes ) {
				if ( ! Object.prototype.hasOwnProperty.call( antes, i ) || ! campo[ i ] ) {
					continue;
				}

				if ( campo[ i ].value !== String( puesto[ CAMPOS[ i ] ] || '' ) ) {
					continue;
				}

				campo[ i ].value = antes[ i ];
			}
		}

		puesto = null;
		antes  = null;
		esconder();
	}

	var DISPARAN = {
		'f-cliente_nombre': 'nombre',
		'f-cliente_empresa': 'empresa',
		'f-cliente_rut': 'rut'
	};

	Object.keys( DISPARAN ).forEach( function ( cual ) {
		if ( ! campo[ cual ] ) {
			return;
		}

		campo[ cual ].addEventListener( 'input', function () {
			var f = calce( DISPARAN[ cual ], campo[ cual ].value );

			if ( f ) {
				rellenar( f, cual );
			} else if ( puesto ) {
				/*
				 * Se salió de la ficha. Dejar los otros campos con los datos
				 * del cliente anterior es peor que no haber completado nada:
				 * se devuelven los que todavía tienen lo que pusimos.
				 */
				deshacer();
			}
		} );
	} );

	if ( aviso ) {
		var x = aviso.querySelector( '.esc-ficha__x' );

		if ( x ) {
			x.addEventListener( 'click', deshacer );
		}
	}
}() );

/**
 * El método de pago que ella corrige a mano deja de ser automático.
 *
 * El panel anota solo con qué dijo el cliente que iba a pagar. Si ella lo
 * cambia, esa corrección tiene que mandar: sin esto, el próximo aviso del
 * cliente la pisaría de vuelta.
 */
( function () {
	'use strict';

	var cuotas = document.getElementById( 'esc-cuotas' );

	if ( ! cuotas ) {
		return;
	}

	cuotas.addEventListener( 'change', function ( e ) {
		var sel = e.target.closest( '[data-metodo]' );

		if ( ! sel ) {
			return;
		}

		var fila = sel.closest( '.esc-cuota' );
		var ban  = fila ? fila.querySelector( '[data-metodo-auto]' ) : null;

		if ( ban ) {
			ban.value = '0';
		}
	} );
}() );

/**
 * El cuadre de las cuotas contra el total, en vivo.
 *
 * Las cuotas casi nunca suman el total a la primera: un descuento, un
 * redondeo, una cuota que se movió. Hasta acá esa diferencia había que
 * sacarla a mano, y una cotización podía quedar cobrando de menos sin que
 * nada lo dijera.
 *
 * El «Pago total» no se suma: es la alternativa de pagar todo de una, no una
 * cuota más. Sumarlo daría siempre el doble.
 */
( function () {
	'use strict';

	var CFG    = window.malkaEscritorio || {};
	var lista  = document.getElementById( 'esc-cuotas' );
	var caja   = document.getElementById( 'esc-cuadre' );
	var total  = document.getElementById( 'esc-ed-total' );
	var boton  = document.getElementById( 'esc-cuadrar' );

	if ( ! lista || ! caja || ! total ) {
		return;
	}

	var texto = caja.querySelector( '.esc-cuadre__t' );

	function plata( n ) {
		var abs = Math.round( Math.abs( n || 0 ) );
		return ( n < 0 ? '-$' : '$' ) + String( abs ).replace( /\B(?=(\d{3})+(?!\d))/g, '.' );
	}

	function filas() {
		return lista.querySelectorAll( '.esc-cuota:not(.esc-cuota--h)' );
	}

	/** El rol de una fila: una fila sin selector es una cuota. */
	function rol( fila ) {
		var s = fila.querySelector( '[name$="[rol]"]' );
		return s ? s.value : 'cuota';
	}

	function suma() {
		var n = 0;

		Array.prototype.forEach.call( filas(), function ( f ) {
			if ( 'total' === rol( f ) ) {
				return;
			}

			var m = f.querySelector( '[data-monto]' );
			n += m ? window.escLeer( m.value ) : 0;
		} );

		return Math.round( n );
	}

	function totalCot() {
		return parseInt( String( total.textContent ).replace( /[^\d]/g, '' ), 10 ) || 0;
	}

	function pintar() {
		var t = totalCot();
		var s = suma();

		// Sin total todavía —una cotización recién abierta— no hay nada que
		// comparar, y decir «faltan $0» sólo estorba.
		if ( t <= 0 || ! filas().length ) {
			caja.hidden = true;
			return;
		}

		var dif = t - s;

		caja.hidden = false;
		caja.classList.toggle( 'is-dispar', 0 !== dif );

		if ( 0 === dif ) {
			texto.textContent = ( caja.getAttribute( 'data-cuadra' ) || '%s' ).replace( '%s', plata( s ) );
		} else if ( dif > 0 ) {
			texto.textContent = ( caja.getAttribute( 'data-falta' ) || '' )
				.replace( '%1$s', plata( s ) ).replace( '%2$s', plata( t ) ).replace( '%3$s', plata( dif ) );
		} else {
			texto.textContent = ( caja.getAttribute( 'data-sobra' ) || '' )
				.replace( '%1$s', plata( s ) ).replace( '%2$s', plata( t ) ).replace( '%3$s', plata( -dif ) );
		}

		/*
		 * El botón sólo cuando falta. Si se pasaron, no hay un arreglo
		 * obvio —¿cuál cuota se achica?— y ofrecer uno sería elegir por ella.
		 */
		if ( boton ) {
			boton.hidden = dif <= 0;
		}
	}

	/** Una fila nueva a partir de la plantilla del servidor. */
	function nuevaFila() {
		if ( ! CFG.cuotaTpl ) {
			return null;
		}

		var caja2 = document.createElement( 'div' );
		caja2.innerHTML = CFG.cuotaTpl.replace( /__i__/g, String( filas().length ) );

		return caja2.firstElementChild;
	}

	/**
	 * Pone la diferencia donde corresponda.
	 *
	 * Si ya hay una fila «Pendiente» sin pagar, se le suma ahí en vez de
	 * crear una segunda: apretar dos veces no puede dejar dos pendientes.
	 */
	function cuadrar() {
		var dif = totalCot() - suma();

		if ( dif <= 0 ) {
			return;
		}

		var rotulo = caja.getAttribute( 'data-pendiente' ) || 'Pendiente';
		var ya     = null;

		Array.prototype.forEach.call( filas(), function ( f ) {
			var n = f.querySelector( 'input[type="text"]' );
			var e = f.querySelector( '[name$="[status]"]' );

			if ( ya || ! n || 'total' === rol( f ) ) {
				return;
			}

			if ( n.value.trim().toLowerCase() === rotulo.toLowerCase() && ( ! e || 'pagado' !== e.value ) ) {
				ya = f;
			}
		} );

		if ( ya ) {
			var m = ya.querySelector( '[data-monto]' );
			m.value = window.escMiles( window.escLeer( m.value ) + dif );
			pintar();
			return;
		}

		var fila = nuevaFila();

		if ( ! fila ) {
			return;
		}

		fila.querySelector( 'input[type="text"]' ).value = rotulo;
		fila.querySelector( '[data-monto]' ).value = window.escMiles( dif );

		// Hereda la fecha de la última cuota: un pendiente sin fecha no se
		// cobra nunca, porque la cobranza necesita un vencimiento.
		var ultimas = filas();
		var previa  = ultimas.length ? ultimas[ ultimas.length - 1 ].querySelector( '[data-fecha]' ) : null;
		var fecha   = fila.querySelector( '[data-fecha]' );

		if ( fecha && previa && previa.value ) {
			fecha.value = previa.value;
		}

		lista.appendChild( fila );
		pintar();
	}

	if ( boton ) {
		boton.addEventListener( 'click', cuadrar );
	}

	// El total cambia con los ítems y la suma con las cuotas: se escucha
	// todo el editor y se recalcula, que es más barato que coordinar dos
	// módulos que no se conocen.
	var ed = document.getElementById( 'esc-ed' ) || document;
	ed.addEventListener( 'input', pintar );
	ed.addEventListener( 'change', pintar );

	// Agregar, quitar o rehacer cuotas no dispara «input».
	if ( window.MutationObserver ) {
		new MutationObserver( pintar ).observe( lista, { childList: true } );
	}

	pintar();
}() );

/**
 * Los mensajes al cliente.
 *
 * Insertar un campo donde está el cursor, volver al texto de fábrica, y ver
 * el correo armado mientras escribe.
 *
 * El correo lo arma el servidor, no este archivo: el marcado —el recuadro,
 * las viñetas, la negrita, el botón— se convierte en un solo sitio, así que
 * lo que ella ve en la pantalla es exactamente lo que se envía. El precio es
 * una ida y vuelta por cada pausa al escribir, que es barato.
 */
( function () {
	'use strict';

	var CFG  = window.malkaEscritorio || {};
	var caja = document.querySelector( '.esc-msgs' );

	if ( ! caja ) {
		return;
	}

	var CAMPOS = [ 'asunto', 'pre', 'titulo', 'boton', 'interes', 'cuerpo' ];

	/** El texto original de un mensaje, para poder volver. */
	function datos( panel ) {
		var et = panel.querySelector( '[data-mensaje-datos]' );

		if ( ! et ) {
			return null;
		}

		try {
			return JSON.parse( et.textContent );
		} catch ( e ) {
			return null;
		}
	}

	/** El campo de un mensaje, por su nombre. */
	function campo( panel, nombre ) {
		return panel.querySelector( '[name$="[' + nombre + ']"]' );
	}

	/** Lo que hay escrito ahora mismo. */
	function borrador( panel ) {
		var out = {};

		CAMPOS.forEach( function ( k ) {
			var c = campo( panel, k );

			if ( c ) {
				out[ k ] = c.value;
			}
		} );

		return out;
	}

	/* ──────────────────  Pedirle el correo al servidor  ────────────────── */

	function repintar( panel ) {
		if ( ! CFG.ajax || ! CFG.previaNonce ) {
			return;
		}

		var marco = panel.querySelector( '[data-previa-html]' );

		if ( ! marco ) {
			return;
		}

		var cuerpo = new URLSearchParams();
		cuerpo.set( 'action', 'malka_crm_previa_mensaje' );
		cuerpo.set( 'nonce', CFG.previaNonce );
		cuerpo.set( 'mensaje', panel.getAttribute( 'data-mensaje' ) );

		var b = borrador( panel );

		CAMPOS.forEach( function ( k ) {
			cuerpo.set( k, undefined === b[ k ] ? '' : b[ k ] );
		} );

		// Cada panel cancela su propia petición anterior: si escribe rápido,
		// la que llegue tarde no puede pisar a la que salió después.
		if ( panel.__corta ) {
			panel.__corta.abort();
		}

		panel.__corta = ( 'undefined' !== typeof AbortController ) ? new AbortController() : null;

		fetch( CFG.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: cuerpo.toString(),
			signal: panel.__corta ? panel.__corta.signal : undefined,
		} )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( j ) {
				if ( ! j || ! j.success || ! j.data ) {
					return;
				}

				marco.setAttribute( 'srcdoc', j.data.html );

				var asunto = panel.querySelector( '[data-previa-asunto]' );

				if ( asunto ) {
					asunto.textContent = ( CFG.rotuloAsunto || 'Asunto:' ) + ' ' + j.data.asunto;
				}
			} )
			.catch( function () {
				// Si la previa no llega, lo escrito no se toca: se queda la
				// última que sí llegó. Perder el texto por un fallo de red
				// sería mucho peor que ver un correo desactualizado.
			} );
	}

	/** Espera a que deje de escribir antes de pedirla. */
	function repintar_pronto( panel ) {
		clearTimeout( panel.__espera );
		panel.__espera = setTimeout( function () {
			repintar( panel );
		}, 400 );
	}

	/* ────────────────────  Meter un campo en el texto  ─────────────────── */

	function insertar( panel, texto ) {
		var destino = panel.__ultimo;

		if ( ! destino || ! panel.contains( destino ) ) {
			destino = campo( panel, 'cuerpo' );
		}

		if ( ! destino ) {
			return;
		}

		var ini = destino.selectionStart;
		var fin = destino.selectionEnd;

		if ( 'number' !== typeof ini ) {
			destino.value += texto;
		} else {
			destino.value = destino.value.slice( 0, ini ) + texto + destino.value.slice( fin );
			destino.selectionStart = ini + texto.length;
			destino.selectionEnd   = ini + texto.length;
		}

		destino.focus();
		repintar_pronto( panel );
	}

	// Recordar en qué campo estaba, para saber dónde escribir.
	caja.addEventListener( 'focusin', function ( e ) {
		var d = e.target.closest ? e.target.closest( '[data-campo-destino]' ) : null;

		if ( ! d ) {
			return;
		}

		var panel = d.closest( '.esc-msg' );

		if ( panel ) {
			panel.__ultimo = d;
		}
	} );

	caja.addEventListener( 'input', function ( e ) {
		var panel = e.target.closest ? e.target.closest( '.esc-msg' ) : null;

		if ( panel ) {
			repintar_pronto( panel );
		}
	} );

	caja.addEventListener( 'click', function ( e ) {
		var campito = e.target.closest ? e.target.closest( '[data-campo]' ) : null;

		if ( campito ) {
			insertar( campito.closest( '.esc-msg' ), campito.getAttribute( 'data-campo' ) );
			return;
		}

		var volver = e.target.closest ? e.target.closest( '[data-original]' ) : null;

		if ( ! volver ) {
			return;
		}

		var panel = volver.closest( '.esc-msg' );
		var d     = datos( panel );

		if ( ! d ) {
			return;
		}

		CAMPOS.forEach( function ( k ) {
			var c = campo( panel, k );

			if ( c && undefined !== d.original[ k ] ) {
				c.value = d.original[ k ];
			}
		} );

		repintar( panel );
	} );
}() );


/* ──────────────────  El Inicio, ordenado por ella  ───────────────────────
 *
 * Qué se ve, en qué orden y de qué ancho. Se arrastra por el asa, se mueve
 * con Alt y las flechas, y cada cambio se guarda solo: un botón «Guardar» en
 * algo que se toca de a un empujón obliga a acordarse de apretarlo.
 *
 * Los cuadros escondidos siguen en la página. Mientras personaliza se ven en
 * fantasma, en el sitio donde volverían; al salir desaparecen. Así esconder
 * y volver a poner es instantáneo y nunca hay que buscar dónde se fue algo.
 */
( function () {
	'use strict';

	var CFG     = window.malkaEscritorio || {};
	var mosaico = document.getElementById( 'esc-mosaic' );
	var boton   = document.getElementById( 'esc-personalizar' );

	if ( ! mosaico || ! boton ) {
		return;
	}

	var ANCHOS = [ 4, 6, 8, 12 ];
	var T      = CFG.inicio || {};
	var nonce  = mosaico.getAttribute( 'data-nonce' ) || '';
	var pista  = document.getElementById( 'esc-personalizar-pista' );

	function rotulo( a ) {
		var nombres = T.anchos || [ 'Un tercio', 'La mitad', 'Dos tercios', 'Todo el ancho' ];

		return nombres[ ANCHOS.indexOf( a ) ] || nombres[ 1 ];
	}

	function cuadros( soloVisibles ) {
		var todos = Array.prototype.slice.call( mosaico.querySelectorAll( '.esc-cuadro' ) );

		return soloVisibles
			? todos.filter( function ( c ) { return ! c.classList.contains( 'is-oculto' ); } )
			: todos;
	}

	/* ── Guardar ── */

	var pendiente = null;

	function guardar() {
		// Varios empujones seguidos son un solo guardado.
		window.clearTimeout( pendiente );

		pendiente = window.setTimeout( function () {
			if ( ! CFG.ajax || ! nonce ) {
				return;
			}

			var orden   = [];
			var ocultos = [];
			var anchos  = [];

			cuadros( false ).forEach( function ( c ) {
				var k = c.getAttribute( 'data-bloque' );

				orden.push( k );
				anchos.push( k + ':' + ( c.getAttribute( 'data-ancho' ) || '6' ) );

				if ( c.classList.contains( 'is-oculto' ) ) {
					ocultos.push( k );
				}
			} );

			var cuerpo = new URLSearchParams();
			cuerpo.append( 'action', 'malka_crm_inicio' );
			cuerpo.append( 'nonce', nonce );
			cuerpo.append( 'orden', orden.join( ',' ) );
			cuerpo.append( 'ocultos', ocultos.join( ',' ) );
			cuerpo.append( 'anchos', anchos.join( ',' ) );

			window.fetch( CFG.ajax, {
				method:      'POST',
				credentials: 'same-origin',
				headers:     { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body:        cuerpo.toString()
			} )['catch']( function () {
				/*
				 * Si no se pudo guardar, lo que está en pantalla sigue siendo
				 * lo que ella acaba de armar: no se deshace nada. Al recargar
				 * volvería el reparto anterior, que es preferible a que el
				 * panel se le mueva solo mientras trabaja.
				 */
			} );
		}, 500 );
	}

	/* ── Entrar y salir de personalizar ── */

	function editando( si ) {
		mosaico.classList.toggle( 'is-editando', si );
		boton.setAttribute( 'aria-pressed', si ? 'true' : 'false' );
		// Con ícono, sólo cambia el texto: textContent en el botón borraba el SVG.
		var etiqueta = boton.querySelector( 'span' ) || boton;
		etiqueta.textContent = si ? ( T.listo || 'Listo' ) : ( T.editar || 'Personalizar' );

		if ( pista ) {
			pista.hidden = ! si;
		}
	}

	boton.addEventListener( 'click', function () {
		editando( 'true' !== boton.getAttribute( 'aria-pressed' ) );
	} );

	/* ── Los botones de cada cuadro ── */

	mosaico.addEventListener( 'click', function ( e ) {
		if ( ! e.target.closest ) {
			return;
		}

		var ancho = e.target.closest( '[data-ancho-btn]' );

		if ( ancho ) {
			var cuadro = ancho.closest( '.esc-cuadro' );
			var actual = parseInt( cuadro.getAttribute( 'data-ancho' ), 10 ) || 6;
			var nuevo  = ANCHOS[ ( ANCHOS.indexOf( actual ) + 1 ) % ANCHOS.length ];

			cuadro.classList.remove( 'esc-cuadro--w' + actual );
			cuadro.classList.add( 'esc-cuadro--w' + nuevo );
			cuadro.setAttribute( 'data-ancho', String( nuevo ) );
			ancho.textContent = rotulo( nuevo );

			guardar();
			return;
		}

		var ver = e.target.closest( '[data-ver]' );

		if ( ver ) {
			var tapa   = ver.closest( '.esc-cuadro' );
			var oculto = tapa.classList.toggle( 'is-oculto' );

			ver.textContent = oculto ? ( T.mostrar || 'Mostrar' ) : ( T.esconder || 'Esconder' );
			guardar();
		}
	} );

	/* ── Mover de sitio ── */

	function mover( cuadro, paso ) {
		var visibles = cuadros( true );
		var i        = visibles.indexOf( cuadro );
		var j        = i + paso;

		if ( i < 0 || j < 0 || j >= visibles.length ) {
			return false;
		}

		if ( paso > 0 ) {
			mosaico.insertBefore( cuadro, visibles[ j ].nextElementSibling );
		} else {
			mosaico.insertBefore( cuadro, visibles[ j ] );
		}

		return true;
	}

	/* Con el teclado: Alt y las flechas, igual que en el árbol de tareas. */
	mosaico.addEventListener( 'keydown', function ( e ) {
		if ( ! e.altKey || ! e.target.closest ) {
			return;
		}

		var asa = e.target.closest( '.esc-cuadro__a' );

		if ( ! asa ) {
			return;
		}

		var paso = 0;

		if ( 'ArrowLeft' === e.key || 'ArrowUp' === e.key ) {
			paso = -1;
		} else if ( 'ArrowRight' === e.key || 'ArrowDown' === e.key ) {
			paso = 1;
		}

		if ( ! paso ) {
			return;
		}

		e.preventDefault();

		if ( mover( asa.closest( '.esc-cuadro' ), paso ) ) {
			asa.focus();
			guardar();
		}
	} );

	/* Con el dedo o el ratón. */
	var llevando = null;

	/**
	 * Delante de qué cuadro caería el puntero, leyendo el mosaico como se lee
	 * un texto: de izquierda a derecha y de arriba abajo.
	 */
	function destino( x, y, salvo ) {
		var otros = cuadros( true ).filter( function ( c ) { return c !== salvo; } );
		var i;

		for ( i = 0; i < otros.length; i++ ) {
			var caja = otros[ i ].getBoundingClientRect();

			// Más arriba que toda su fila.
			if ( y < caja.top ) {
				return otros[ i ];
			}

			// Dentro de su franja: decide la mitad en la que cayó.
			if ( y <= caja.bottom && x < caja.left + ( caja.width / 2 ) ) {
				return otros[ i ];
			}
		}

		return null;
	}

	mosaico.addEventListener( 'pointerdown', function ( e ) {
		var asa = e.target.closest ? e.target.closest( '.esc-cuadro__a' ) : null;

		if ( ! asa || ! mosaico.classList.contains( 'is-editando' ) || ( e.button && 0 !== e.button ) ) {
			return;
		}

		e.preventDefault();

		llevando = asa.closest( '.esc-cuadro' );
		llevando.classList.add( 'is-arrastrando' );

		// preventDefault() se comió el foco: se devuelve para poder seguir
		// con Alt y las flechas después de soltar.
		asa.focus();

		if ( asa.setPointerCapture ) {
			asa.setPointerCapture( e.pointerId );
		}
	} );

	mosaico.addEventListener( 'pointermove', function ( e ) {
		if ( ! llevando ) {
			return;
		}

		e.preventDefault();

		var ante = destino( e.clientX, e.clientY, llevando );

		/*
		 * Si ya está justo donde iría, no se toca: reinsertar en cada píxel
		 * perdería el foco del asa y haría parpadear el panel entero. Al
		 * final de la fila el vecino es null en los dos lados, así que la
		 * comparación también cubre ese caso.
		 */
		if ( ante !== llevando.nextElementSibling ) {
			mosaico.insertBefore( llevando, ante );
		}
	} );

	function terminar() {
		if ( ! llevando ) {
			return;
		}

		llevando.classList.remove( 'is-arrastrando' );
		llevando = null;
		guardar();
	}

	mosaico.addEventListener( 'pointerup', terminar );
	mosaico.addEventListener( 'pointercancel', terminar );
}() );


/* ── Inicio: play del reloj y tareas listas desde «Tu día» ── */
( function () {
	'use strict';

	var CFG = window.malkaEscritorio || {};

	// El play del encabezado hace lo mismo que el botón grande del pomodoro.
	var go = document.getElementById( 'esc-timer-go' );
	if ( go ) {
		go.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			var grande = document.getElementById( 'esc-pom-go' );
			if ( grande ) {
				grande.click();
			}
		} );
	}

	var mosaico = document.getElementById( 'esc-mosaic' );
	var nonce   = mosaico ? mosaico.getAttribute( 'data-nonce' ) : '';

	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest ? e.target.closest( '.esc-dia__chk' ) : null;
		if ( ! b || b.disabled ) {
			return;
		}
		e.preventDefault();

		var fila = b.closest( '.esc-dia-w' );
		b.disabled = true;
		b.classList.add( 'is-ok' );
		if ( fila ) {
			fila.classList.add( 'is-lista' );
		}

		if ( ! CFG.ajax || ! nonce ) {
			return;
		}

		var datos = new FormData();
		datos.append( 'nonce', nonce );
		if ( 'cobro' === b.getAttribute( 'data-tipo' ) ) {
			datos.append( 'action', 'malka_crm_cobros_hechos' );
		} else {
			datos.append( 'action', 'malka_crm_tarea_lista' );
			datos.append( 'pid', b.getAttribute( 'data-pid' ) );
			datos.append( 'tid', b.getAttribute( 'data-tid' ) );
		}

		fetch( CFG.ajax, { method: 'POST', credentials: 'same-origin', body: datos } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( r ) {
				if ( ! r || ! r.success ) {
					throw new Error( 'no' );
				}
			} )
			.catch( function () {
				// No se guardó: vuelve como estaba para que lo intente de nuevo.
				b.disabled = false;
				b.classList.remove( 'is-ok' );
				if ( fila ) {
					fila.classList.remove( 'is-lista' );
				}
			} );
	} );
}() );

/* ── Ventanas: un botón con data-abre abre su <dialog>. ── */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( e ) {
		var abre = e.target.closest ? e.target.closest( '[data-abre]' ) : null;
		if ( abre ) {
			var dlg = document.getElementById( abre.getAttribute( 'data-abre' ) );
			if ( dlg && typeof dlg.showModal === 'function' ) {
				e.preventDefault();
				dlg.showModal();
			}
			return;
		}

		var cierra = e.target.closest ? e.target.closest( '[data-cierra]' ) : null;
		if ( cierra ) {
			var d = cierra.closest( 'dialog' );
			if ( d ) {
				d.close();
			}
			return;
		}

		// Clic en el fondo oscuro: cierra.
		if ( e.target.tagName === 'DIALOG' && e.target.classList.contains( 'esc-dlg' ) ) {
			e.target.close();
		}
	} );
}() );

/* ── Guardado automático ──────────────────────────────────────────────
   Todo formulario del panel con barra de guardar se guarda solo: al
   escribir (con una pausa corta) y al cambiar una opción. Usa el mismo
   envío del botón —mismo destino, mismo permiso—, así que el servidor no
   distingue uno de otro. Un registro nuevo (todavía sin número) no se
   autoguarda: crearía uno distinto en cada pausa. Ése se crea con el botón
   y desde ahí en adelante se guarda solo. */
( function () {
	'use strict';

	// Sin «proveedor» acá, cada pausa creaba una ficha nueva del mismo.
	var IDS     = [ 'negocio', 'quote', 'doc', 'cliente', 'plantilla', 'proveedor' ];
	// Casillas que rehacen o reemplazan cosas: nunca por su cuenta.
	var MANUAL  = [ 'rehacer_plan', 'reemplazar' ];
	var PAUSA   = 900;

	function esNuevo( form ) {
		for ( var i = 0; i < IDS.length; i++ ) {
			var h = form.querySelector( 'input[type="hidden"][name="' + IDS[ i ] + '"]' );
			if ( h ) {
				return ! h.value || '0' === h.value;
			}
		}
		return false;
	}

	function uid() {
		return 'auto-' + Date.now().toString( 36 ) + Math.random().toString( 36 ).slice( 2, 6 );
	}

	function preparar( form ) {
		var accion = form.querySelector( 'input[name="action"]' );
		var barra  = form.querySelector( '.esc-ed__save, .esc-cfg__save' );

		/*
		 * Un registro nuevo se autoguarda sólo si el formulario dice qué
		 * campo lo hace «de verdad» (data-auto-nuevo, p. ej. el monto): el
		 * primer guardado lo crea, el servidor contesta su número y desde
		 * ahí se sigue guardando el mismo. Sin eso, cada pausa crearía uno.
		 */
		var clave = form.getAttribute( 'data-auto-nuevo' );

		if ( ! accion || ! barra || ! /^malka_crm_(save|guardar)/.test( accion.value ) || ( esNuevo( form ) && ! clave ) ) {
			return;
		}

		form.classList.add( 'is-auto' );

		// «Cancelar» ya no deshace nada: lo escrito quedó guardado.
		barra.querySelectorAll( 'a.esc-btn' ).forEach( function ( a ) {
			if ( 'Cancelar' === a.textContent.trim() ) {
				a.textContent = 'Volver';
			}
		} );

		/*
		 * Sin barra: con guardado automático no hay nada que confirmar. El
		 * estado aparece como un aviso chico abajo a la derecha mientras
		 * guarda, y se va solo. La barra vuelve sólo si hay que confirmar
		 * algo a mano (rehacer el plan, reemplazar).
		 */
		var estado = document.querySelector( '.esc-auto' );
		if ( ! estado ) {
			estado = document.createElement( 'span' );
			estado.className = 'esc-auto';
			estado.setAttribute( 'role', 'status' );
			estado.setAttribute( 'aria-live', 'polite' );
			// Dentro del panel: ahí viven sus colores y su letra.
			( document.querySelector( '.malka-esc' ) || document.body ).appendChild( estado );
		}
		var ocultar = null;
		var recargar = false;

		var boton = barra.querySelector( 'button[type="submit"]' );

		var timer    = null;
		var enCurso  = false;
		var otraVez  = false;
		var sucio    = false;

		function pintar( clase, texto ) {
			estado.className   = 'esc-auto is-vis' + ( clase ? ' is-' + clase : '' );
			estado.textContent = texto;
			window.clearTimeout( ocultar );
			// «Guardado» se muestra un momento y se va; un error se queda.
			if ( 'ok' === clase ) {
				ocultar = window.setTimeout( function () {
					estado.classList.remove( 'is-vis' );
				}, 2200 );
			}
		}

		function manualActivo() {
			return MANUAL.some( function ( n ) {
				var c = form.querySelector( 'input[name="' + n + '"]' );
				return c && c.checked;
			} );
		}

		function guardar( alSalir ) {
			window.clearTimeout( timer );

			if ( enCurso ) {
				otraVez = true;
				return;
			}
			if ( ! sucio ) {
				return;
			}

			// Nuevo y todavía sin lo mínimo: espera, no crea uno vacío.
			if ( clave && esNuevo( form ) ) {
				var minimo = form.querySelector( clave );
				if ( minimo && ! String( minimo.value ).replace( /[\s0.$]/g, '' ) ) {
					pintar( 'pend', form.getAttribute( 'data-auto-msg' ) || 'Se guarda solo apenas completes lo mínimo' );
					return;
				}
			}

			// Las filas nuevas llevan su id desde ya: si no, el servidor les
			// daría uno distinto en cada guardado.
			form.querySelectorAll( 'input[type="hidden"][name$="[id]"]' ).forEach( function ( h ) {
				if ( ! h.value ) {
					h.value = uid();
				}
			} );

			var datos = empacarTareas( new FormData( form ) );
			MANUAL.forEach( function ( n ) {
				datos.delete( n );
			} );
			// El servidor que lo sabe contesta datos en vez de redirigir.
			datos.append( '_auto', '1' );

			// Un archivo elegido viaja una sola vez.
			var archivos = Array.prototype.filter.call( form.querySelectorAll( 'input[type="file"]' ), function ( f ) {
				return f.files && f.files.length;
			} );
			form.querySelectorAll( 'input[type="file"]' ).forEach( function ( f ) {
				if ( f.hasAttribute( 'data-solo-leer' ) ) {
					datos.delete( f.name );
				}
			} );

			enCurso = true;
			sucio   = false;
			pintar( 'save', 'Guardando…' );

			fetch( form.getAttribute( 'action' ), {
				method: 'POST',
				body: datos,
				credentials: 'same-origin',
				keepalive: !! alSalir
			} )
				.then( function ( r ) {
					// Si la sesión venció, la respuesta es la pantalla de ingreso.
					if ( ! r.ok || /wp-login\.php/.test( r.url ) ) {
						throw new Error( 'no' );
					}
					var json = /json/.test( r.headers.get( 'content-type' ) || '' );
					return ( json ? r.json() : Promise.resolve( null ) ).then( function ( res ) {
						var d = res && res.success ? res.data : null;

						archivos.forEach( function ( f ) {
							f.value = '';
							f.removeAttribute( 'data-solo-leer' );
						} );

						if ( d ) {
							// Recién creado: queda con su número y su dirección.
							if ( d.id ) {
								IDS.forEach( function ( n ) {
									var h = form.querySelector( 'input[type="hidden"][name="' + n + '"]' );
									if ( h && ( ! h.value || '0' === h.value ) ) {
										h.value = String( d.id );
									}
								} );
								if ( d.url && window.history && window.history.replaceState ) {
									window.history.replaceState( null, '', d.url );
								}
							}
							form.dispatchEvent( new CustomEvent( 'esc:guardado', { detail: d } ) );
						}

						if ( d && d.aviso ) {
							pintar( 'err', d.aviso );
						} else {
							var h = new Date();
							pintar( 'ok', 'Guardado · ' + ( '0' + h.getHours() ).slice( -2 ) + ':' + ( '0' + h.getMinutes() ).slice( -2 ) );
						}
						if ( recargar ) {
							window.location.reload();
						}
					} );
				} )
				.catch( function () {
					sucio = true;
					pintar( 'err', 'No se pudo guardar. Revisa tu conexión; reintento al próximo cambio.' );
				} )
				.then( function () {
					enCurso = false;
					if ( otraVez ) {
						otraVez = false;
						guardar();
					}
				} );
		}

		function cambio( e ) {
			var t = e.target;
			if ( ! t || ! t.name ) {
				return;
			}
			if ( MANUAL.indexOf( t.name ) !== -1 ) {
				// Esa casilla se confirma con el botón, que vuelve a aparecer.
				form.classList.toggle( 'is-manual', manualActivo() );
				return;
			}
			sucio = true;
			// Un cambio que reordena la página (asociar una cotización)
			// recarga al quedar guardado, para mostrar lo nuevo.
			if ( t.hasAttribute( 'data-recarga' ) ) {
				recargar = true;
			}
			pintar( 'pend', 'Cambios sin guardar…' );
			window.clearTimeout( timer );
			timer = window.setTimeout( guardar, 'input' === e.type ? PAUSA : 150 );
		}

		form.addEventListener( 'input', cambio );
		form.addEventListener( 'change', cambio );

		// Filas que se agregan, borran o mueven con botones también cuentan.
		new MutationObserver( function ( m ) {
			var cuenta = m.some( function ( x ) {
				return x.addedNodes.length || x.removedNodes.length;
			} );
			if ( cuenta && ! estado.contains( m[ 0 ].target ) ) {
				sucio = true;
				window.clearTimeout( timer );
				timer = window.setTimeout( guardar, PAUSA );
			}
		} ).observe( form, { childList: true, subtree: true } );

		// Lo que quede pendiente al irse, se manda igual.
		window.addEventListener( 'pagehide', function () {
			if ( sucio ) {
				guardar( true );
			}
		} );

		// El botón sigue sirviendo para lo manual (rehacer el plan, etc.).
		if ( boton ) {
			boton.addEventListener( 'click', function () {
				sucio = false;
			} );
		}
	}

	document.querySelectorAll( 'form' ).forEach( preparar );
}() );

/* ── Buscador del proyecto del pomodoro ──────────────────────────────
   El desplegable se reemplaza por un campo donde se escribe parte del
   proyecto o del cliente y la lista se filtra. El <select> de siempre
   sigue debajo, escondido, y es el que lee el pomodoro. */
( function () {
	'use strict';

	var sel = document.getElementById( 'esc-pom-proj' );
	if ( ! sel ) {
		return;
	}

	function normal( t ) {
		return ( t || '' ).toLowerCase().normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' );
	}

	var caja = document.createElement( 'div' );
	caja.className = 'spom__buscar';

	var campo = document.createElement( 'input' );
	campo.type = 'search';
	campo.className = 'spom__q';
	campo.placeholder = 'Busca un proyecto o cliente';
	campo.setAttribute( 'role', 'combobox' );
	campo.setAttribute( 'aria-expanded', 'false' );
	campo.setAttribute( 'aria-controls', 'esc-pom-lista' );
	campo.setAttribute( 'autocomplete', 'off' );

	var lista = document.createElement( 'ul' );
	lista.className = 'spom__lista';
	lista.id = 'esc-pom-lista';
	lista.setAttribute( 'role', 'listbox' );
	lista.hidden = true;

	caja.appendChild( campo );
	caja.appendChild( lista );
	sel.parentNode.insertBefore( caja, sel );
	sel.hidden = true;

	var activo = -1;

	function elegida() {
		var o = sel.options[ sel.selectedIndex ];
		return o ? o.textContent : '';
	}

	function pintar() {
		var q = normal( campo.value.trim() );
		lista.innerHTML = '';
		activo = -1;

		Array.prototype.forEach.call( sel.options, function ( o ) {
			var texto = o.textContent + ' ' + ( o.getAttribute( 'data-cliente' ) || '' );
			if ( q && normal( texto ).indexOf( q ) === -1 ) {
				return;
			}
			var li = document.createElement( 'li' );
			li.setAttribute( 'role', 'option' );
			li.setAttribute( 'data-v', o.value );
			li.setAttribute( 'aria-selected', o.selected ? 'true' : 'false' );

			var b = document.createElement( 'b' );
			b.textContent = o.textContent;
			li.appendChild( b );

			var c = o.getAttribute( 'data-cliente' );
			if ( c ) {
				var s = document.createElement( 'span' );
				s.textContent = c;
				li.appendChild( s );
			}
			lista.appendChild( li );
		} );

		if ( ! lista.children.length ) {
			var vacio = document.createElement( 'li' );
			vacio.className = 'is-vacio';
			vacio.textContent = 'No encontré nada con eso';
			lista.appendChild( vacio );
		}
	}

	function abrir() {
		pintar();
		lista.hidden = false;
		campo.setAttribute( 'aria-expanded', 'true' );
	}

	function cerrar() {
		lista.hidden = true;
		campo.setAttribute( 'aria-expanded', 'false' );
		campo.value = '';
		campo.placeholder = elegida() || 'Busca un proyecto o cliente';
	}

	function elegir( v ) {
		sel.value = v;
		sel.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		cerrar();
	}

	campo.placeholder = elegida() || campo.placeholder;
	campo.addEventListener( 'focus', abrir );
	campo.addEventListener( 'input', abrir );

	campo.addEventListener( 'keydown', function ( e ) {
		var items = lista.querySelectorAll( 'li[data-v]' );
		if ( 'ArrowDown' === e.key || 'ArrowUp' === e.key ) {
			e.preventDefault();
			if ( ! items.length ) {
				return;
			}
			activo = ( activo + ( 'ArrowDown' === e.key ? 1 : -1 ) + items.length ) % items.length;
			items.forEach( function ( li, i ) {
				li.classList.toggle( 'is-activo', i === activo );
			} );
			items[ activo ].scrollIntoView( { block: 'nearest' } );
		} else if ( 'Enter' === e.key ) {
			e.preventDefault();
			var li = items[ activo >= 0 ? activo : 0 ];
			if ( li ) {
				elegir( li.getAttribute( 'data-v' ) );
			}
		} else if ( 'Escape' === e.key ) {
			cerrar();
			campo.blur();
		}
	} );

	lista.addEventListener( 'mousedown', function ( e ) {
		var li = e.target.closest( 'li[data-v]' );
		if ( li ) {
			e.preventDefault();
			elegir( li.getAttribute( 'data-v' ) );
		}
	} );

	campo.addEventListener( 'blur', function () {
		window.setTimeout( cerrar, 120 );
	} );
}() );

/* ── Lista de negocios: pestañas y buscador ── */
( function () {
	'use strict';

	var lista = document.getElementById( 'esc-nl' );
	if ( ! lista ) {
		return;
	}

	var q     = document.getElementById( 'esc-nl-q' );
	// Sólo las pestañas que filtran (data-grupo). Las de arriba de la
	// sección (Negocios · Plantillas, Finanzas…) son enlaces y no cuentan.
	var tabs  = document.querySelectorAll( '.esc-nl__tab[data-grupo]' );
	var vacio = lista.querySelector( '.esc-nl__vacio' );
	// Parte en la pestaña que viene marcada (en negocios, «En curso»).
	var marcada  = document.querySelector( '.esc-nl__tab.is-on[data-grupo]' );
	// Sin pestañas que filtren (Proveedores), se ven todos.
	var grupo    = marcada ? marcada.getAttribute( 'data-grupo' ) : ( tabs.length ? 'curso' : 'todos' );
	var etiqueta = '';

	function normal( t ) {
		return ( t || '' ).toLowerCase().normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' );
	}

	function filtrar() {
		var texto = normal( q ? q.value.trim() : '' );
		var hay   = 0;

		lista.querySelectorAll( 'a.esc-nl, div.esc-nl[data-buscar]' ).forEach( function ( f ) {
			// Buscando, se busca en todos; si no, en la pestaña elegida.
			var enGrupo = texto || 'todos' === grupo || f.getAttribute( 'data-grupo' ) === grupo ||
				( 'facturar' === grupo && f.hasAttribute( 'data-facturar' ) ) ||
				( ' ' + ( f.getAttribute( 'data-grupos' ) || '' ) + ' ' ).indexOf( ' ' + grupo + ' ' ) !== -1;
			var calza   = ! texto || ( f.getAttribute( 'data-buscar' ) || '' ).indexOf( texto ) !== -1;
			var conTag  = ! etiqueta || ( '|' + ( f.getAttribute( 'data-tags' ) || '' ) + '|' ).indexOf( '|' + etiqueta + '|' ) !== -1;
			f.hidden = ! ( enGrupo && calza && conTag );
			if ( ! f.hidden ) {
				hay++;
			}
		} );

		if ( vacio ) {
			vacio.hidden = hay > 0;
		}
	}

	tabs.forEach( function ( t ) {
		t.addEventListener( 'click', function () {
			grupo = t.getAttribute( 'data-grupo' );
			tabs.forEach( function ( o ) {
				o.classList.toggle( 'is-on', o === t );
				o.setAttribute( 'aria-selected', o === t ? 'true' : 'false' );
			} );
			filtrar();
		} );
	} );

	if ( q ) {
		q.addEventListener( 'input', filtrar );
	}

	// Etiquetas: una a la vez; apretar la que está puesta la quita.
	document.querySelectorAll( '.esc-tagf__b' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			var k = b.getAttribute( 'data-etiqueta' );
			etiqueta = ( etiqueta === k ) ? '' : k;
			document.querySelectorAll( '.esc-tagf__b' ).forEach( function ( o ) {
				var on = o.getAttribute( 'data-etiqueta' ) === etiqueta;
				o.classList.toggle( 'is-on', on );
				o.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			} );
			filtrar();
			lista.dispatchEvent( new CustomEvent( 'esc:filtrado' ) );
		} );
	} );

	// «Ver cuáles» del aviso: abre la pestaña Por facturar.
	document.querySelectorAll( '[data-ir-grupo]' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			var t = document.querySelector( '.esc-nl__tab[data-grupo="' + b.getAttribute( 'data-ir-grupo' ) + '"]' );
			if ( t ) {
				t.click();
				t.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
			}
		} );
	} );

	filtrar();
}() );

/* ── Selectores como botones ──────────────────────────────────────────
   Un <select data-chips> se muestra como una fila de botones (uno por
   opción). El select sigue ahí, escondido: es el que viaja en el
   formulario y el que escuchan los demás scripts. */
( function () {
	'use strict';

	/*
	 * data-seg es la misma idea en forma de interruptor: una cápsula gris
	 * con la opción elegida en blanco. Va para dos o tres opciones cortas.
	 */
	document.querySelectorAll( 'select[data-chips], select[data-seg]' ).forEach( function ( sel ) {
		var fila = document.createElement( 'div' );
		fila.className = sel.hasAttribute( 'data-seg' ) ? 'esc-seg' : 'esc-chips';
		fila.setAttribute( 'role', 'radiogroup' );

		function pintar() {
			fila.querySelectorAll( 'button' ).forEach( function ( b ) {
				var on = b.getAttribute( 'data-v' ) === sel.value;
				b.classList.toggle( 'is-on', on );
				b.setAttribute( 'aria-checked', on ? 'true' : 'false' );
			} );
		}

		Array.prototype.forEach.call( sel.options, function ( o ) {
			var b = document.createElement( 'button' );
			b.type = 'button';
			b.className = 'esc-chip-op';
			b.setAttribute( 'role', 'radio' );
			b.setAttribute( 'data-v', o.value );
			b.textContent = o.getAttribute( 'data-corto' ) || o.textContent;
			b.addEventListener( 'click', function () {
				if ( sel.value !== o.value ) {
					sel.value = o.value;
					sel.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				}
				pintar();
			} );
			fila.appendChild( b );
		} );

		sel.addEventListener( 'change', pintar );
		sel.parentNode.insertBefore( fila, sel );
		sel.hidden = true;
		pintar();
	} );
}() );


/* ── El archivo del documento: arrastrar, leer y completar ───────────
   La zona punteada recibe el PDF (o el XML del SII). Si el documento está
   en blanco, se leen sus datos y se completan solos: tipo, folio, fecha,
   venta o compra, cliente o proveedor, RUT y monto. Lo que ella ya
   escribió no se pisa. El PDF además queda guardado con el documento; el
   XML sólo se lee. */
( function () {
	'use strict';

	var CFG  = window.malkaEscritorio || {};
	var zona = document.querySelector( '[data-drop]' );
	var form = document.getElementById( 'esc-doc' );

	if ( ! zona || ! form ) {
		return;
	}

	var input  = zona.querySelector( 'input[type="file"]' );
	var msg    = document.querySelector( '[data-drop-msg]' );
	var hay    = document.getElementById( 'esc-arch-hay' );
	var quitar = form.querySelector( '[data-quitar-campo]' );
	var titulo = zona.querySelector( '[data-drop-t]' );

	var MESES = { enero: 1, febrero: 2, marzo: 3, abril: 4, mayo: 5, junio: 6, julio: 7, agosto: 8, septiembre: 9, setiembre: 9, octubre: 10, noviembre: 11, diciembre: 12 };
	var DTE   = { 33: 'factura', 34: 'exenta', 39: 'boleta', 41: 'boleta', 61: 'nota' };
	var NOMBRES = { factura: 'Factura afecta', exenta: 'Factura exenta', boleta: 'Boleta', honorarios: 'Boleta de honorarios', nota: 'Nota de crédito' };

	function aviso( texto, mal ) {
		if ( ! msg ) {
			return;
		}
		msg.textContent = texto;
		msg.className   = 'esc-drop__msg' + ( mal ? ' is-err' : '' );
		msg.hidden      = ! texto;
	}

	function rutLimpio( r ) {
		return String( r || '' ).replace( /[^0-9kK]/g, '' ).toUpperCase();
	}

	function rutBonito( r ) {
		var l = rutLimpio( r );
		if ( l.length < 2 ) {
			return r || '';
		}
		return l.slice( 0, -1 ).replace( /\B(?=(\d{3})+(?!\d))/g, '.' ) + '-' + l.slice( -1 );
	}

	function numero( t ) {
		return parseInt( String( t || '' ).replace( /\D/g, '' ), 10 ) || 0;
	}

	function pad( n ) {
		return ( '0' + n ).slice( -2 );
	}

	/* ── Leer ── */

	function leerXml( texto ) {
		var doc = new DOMParser().parseFromString( texto, 'text/xml' );

		function v( nombre ) {
			var el = doc.getElementsByTagNameNS( '*', nombre )[ 0 ] || doc.getElementsByTagName( nombre )[ 0 ];
			return el ? el.textContent.trim() : '';
		}

		if ( ! v( 'TipoDTE' ) ) {
			return null;
		}

		return {
			tipo: DTE[ v( 'TipoDTE' ) ] || '',
			folio: v( 'Folio' ),
			fecha: v( 'FchEmis' ),
			emisor: { rut: v( 'RUTEmisor' ), nombre: v( 'RznSoc' ) || v( 'RznSocEmisor' ) },
			receptor: { rut: v( 'RUTRecep' ), nombre: v( 'RznSocRecep' ) },
			neto: numero( v( 'MntNeto' ) ),
			exento: numero( v( 'MntExe' ) ),
			total: numero( v( 'MntTotal' ) )
		};
	}

	function cargarPdfjs() {
		if ( window.pdfjsLib ) {
			return Promise.resolve( window.pdfjsLib );
		}
		if ( ! CFG.pdfjs ) {
			return Promise.reject( new Error( 'sin pdfjs' ) );
		}
		return new Promise( function ( ok, mal ) {
			var s = document.createElement( 'script' );
			s.src = CFG.pdfjs;
			s.onload = function () {
				if ( ! window.pdfjsLib ) {
					mal( new Error( 'sin pdfjs' ) );
					return;
				}
				window.pdfjsLib.GlobalWorkerOptions.workerSrc = CFG.pdfjsWorker;
				ok( window.pdfjsLib );
			};
			s.onerror = mal;
			document.head.appendChild( s );
		} );
	}

	// El texto del PDF, una línea por renglón (los trozos se juntan por altura).
	function textoPdf( buf ) {
		return cargarPdfjs().then( function ( lib ) {
			return lib.getDocument( { data: buf } ).promise;
		} ).then( function ( pdf ) {
			var paginas = [];
			for ( var i = 1; i <= Math.min( pdf.numPages, 2 ); i++ ) {
				paginas.push( pdf.getPage( i ).then( function ( p ) {
					return p.getTextContent();
				} ) );
			}
			return Promise.all( paginas );
		} ).then( function ( contenidos ) {
			var lineas = [];
			contenidos.forEach( function ( c ) {
				var filas = {};
				c.items.forEach( function ( it ) {
					if ( ! it.str || ! it.str.trim() ) {
						return;
					}
					var y = Math.round( it.transform[ 5 ] / 3 );
					( filas[ y ] = filas[ y ] || [] ).push( it );
				} );
				Object.keys( filas ).map( Number ).sort( function ( a, b ) {
					return b - a;
				} ).forEach( function ( y ) {
					lineas.push( filas[ y ].sort( function ( a, b ) {
						return a.transform[ 4 ] - b.transform[ 4 ];
					} ).map( function ( it ) {
						return it.str.trim();
					} ).join( ' ' ) );
				} );
			} );
			return lineas.join( '\n' );
		} );
	}

	function leerTexto( t ) {
		var plano = t.replace( /[ \t]+/g, ' ' );
		var d     = { emisor: {}, receptor: {} };

		if ( /BOLETA\s+DE\s+HONORARIOS/i.test( plano ) ) {
			d.tipo = 'honorarios';
		} else if ( /NOTA\s+DE\s+CR[ÉE]DITO/i.test( plano ) ) {
			d.tipo = 'nota';
		} else if ( /FACTURA\s+(NO\s+AFECTA|EXENTA)/i.test( plano ) ) {
			d.tipo = 'exenta';
		} else if ( /FACTURA/i.test( plano ) ) {
			d.tipo = 'factura';
		} else if ( /BOLETA/i.test( plano ) ) {
			d.tipo = 'boleta';
		}

		var f = plano.match( /N\s*[°ºo]\s*\.?\s*:?\s*(\d{1,10})\b/i );
		if ( f ) {
			d.folio = f[ 1 ];
		}

		var fe = plano.match( /(\d{1,2})\s+de\s+([a-záéíóú]+)\s+(?:de|del)?\s*(\d{4})/i );
		var fn = plano.match( /\b(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})\b/ );
		if ( fe && MESES[ fe[ 2 ].toLowerCase() ] ) {
			d.fecha = fe[ 3 ] + '-' + pad( MESES[ fe[ 2 ].toLowerCase() ] ) + '-' + pad( fe[ 1 ] );
		} else if ( fn ) {
			d.fecha = fn[ 3 ] + '-' + pad( fn[ 2 ] ) + '-' + pad( fn[ 1 ] );
		}

		var ruts = [];
		( plano.match( /\b\d{1,2}\.?\d{3}\.?\d{3}\s*-\s*[\dkK]\b/g ) || [] ).forEach( function ( r ) {
			if ( ruts.indexOf( rutLimpio( r ) ) === -1 ) {
				ruts.push( rutLimpio( r ) );
			}
		} );
		d.emisor.rut   = ruts[ 0 ] || '';
		d.receptor.rut = ruts[ 1 ] || '';

		var sr = plano.match( /SE[ÑN]OR(?:\(ES\)|ES|A)?\s*:?\s*([^\n]+?)\s*(?:R\.?U\.?T|GIRO|\n|$)/i );
		if ( sr ) {
			d.receptor.nombre = sr[ 1 ].replace( /^:\s*/, '' ).trim();
		}
		// El emisor encabeza el documento: el primer renglón con letras.
		var primera = t.split( '\n' ).map( function ( l ) {
			return l.trim();
		} ).filter( function ( l ) {
			return /[a-záéíóúñ]{3}/i.test( l ) && ! /R\.?U\.?T|FACTURA|BOLETA|NOTA DE|S\.I\.I/i.test( l );
		} )[ 0 ];
		if ( primera ) {
			d.emisor.nombre = primera;
		}

		function monto( re ) {
			var m = plano.match( re );
			return m ? numero( m[ 1 ] ) : 0;
		}

		d.neto   = monto( /MONTO\s+NETO\s*:?\s*\$?\s*([\d.]+)/i ) || monto( /\bNETO\s*:?\s*\$?\s*([\d.]+)/i );
		d.exento = monto( /(?:MONTO\s+)?EXENTO\s*:?\s*\$?\s*([\d.]+)/i );
		d.total  = monto( /TOTAL\s+HONORARIOS\s*:?\s*\$?\s*([\d.]+)/i );

		if ( ! d.total ) {
			var todos = plano.match( /\bTOTAL\s*:?\s*\$?\s*([\d.]{3,})/gi ) || [];
			if ( todos.length ) {
				d.total = numero( todos[ todos.length - 1 ].replace( /TOTAL/i, '' ) );
			}
		}

		return ( d.tipo || d.folio || d.total ) ? d : null;
	}

	/* ── Completar ── */

	function poner( sel, valor, pisar ) {
		var el = form.querySelector( sel );
		if ( ! el || valor === '' || valor === undefined || valor === null ) {
			return false;
		}
		if ( ! pisar && String( el.value ).trim() ) {
			return false;
		}
		if ( 'SELECT' === el.tagName && ! el.querySelector( 'option[value="' + valor + '"]' ) ) {
			return false;
		}
		el.value = valor;
		el.dispatchEvent( new Event( 'SELECT' === el.tagName ? 'change' : 'input', { bubbles: true } ) );
		return true;
	}

	function completar( d ) {
		var base    = form.querySelector( '[data-doc-base]' );
		var blanco  = ! base || ! numero( base.value );
		var mio     = rutLimpio( CFG.miRut );
		var sentido = form.querySelector( '[data-doc-sentido]' );
		var lado    = sentido ? sentido.value : 'venta';

		if ( mio && rutLimpio( d.emisor.rut ) === mio ) {
			lado = 'venta';
		} else if ( mio && rutLimpio( d.receptor.rut ) === mio ) {
			lado = 'compra';
		} else if ( mio && d.emisor.rut ) {
			// Ni emisor ni receptor es ella, pero el emisor no es ella: compra.
			lado = 'compra';
		}

		var otro = 'venta' === lado ? d.receptor : d.emisor;

		if ( blanco ) {
			poner( '[data-doc-sentido]', lado, true );
			poner( '[data-doc-tipo]', d.tipo, true );
			poner( '#f-folio', d.folio, true );
			poner( '#f-fecha', d.fecha, true );
		}
		poner( '#f-cliente', otro.nombre, false );
		poner( '#f-rut', otro.rut ? rutBonito( otro.rut ) : '', false );

		var tipo = ( form.querySelector( '[data-doc-tipo]' ) || {} ).value || d.tipo;
		var cifra;
		if ( 'exenta' === tipo ) {
			cifra = d.exento || d.total;
		} else if ( 'boleta' === tipo || 'honorarios' === tipo ) {
			cifra = d.total;
		} else {
			cifra = d.neto || ( d.total ? Math.round( d.total / 1.19 ) : 0 );
		}
		if ( blanco && cifra && base ) {
			poner( '[data-doc-base]', String( cifra ), true );
		}

		var nombre = NOMBRES[ tipo ] || 'Documento';
		return nombre + ( d.folio ? ' N° ' + d.folio : '' ) + ( cifra ? ' · $' + String( cifra ).replace( /\B(?=(\d{3})+(?!\d))/g, '.' ) : '' );
	}

	function recibir( archivo ) {
		if ( ! archivo ) {
			return;
		}

		var esXml = /\.xml$/i.test( archivo.name ) || /xml/.test( archivo.type );
		var esPdf = /\.pdf$/i.test( archivo.name ) || 'application/pdf' === archivo.type;

		if ( ! esXml && ! esPdf ) {
			input.value = '';
			aviso( 'Sólo PDF o el XML del SII.', true );
			return;
		}

		// El XML se lee pero no se guarda: el archivo del documento es el PDF.
		if ( esXml ) {
			input.setAttribute( 'data-solo-leer', '' );
		}

		aviso( 'Leyendo el documento…' );

		var lectura = esXml
			? archivo.text().then( leerXml )
			: archivo.arrayBuffer().then( textoPdf ).then( leerTexto );

		lectura.then( function ( d ) {
			if ( ! d ) {
				aviso( esPdf ? 'El PDF queda guardado, pero no traía datos que leer (¿es un escaneo?). Complétalo a mano.' : 'Ese XML no es un documento del SII.', ! esPdf );
				return;
			}
			aviso( 'Leído: ' + completar( d ) + '. Revisa que esté todo bien.' );
		} ).catch( function () {
			aviso( esPdf ? 'El PDF queda guardado, pero no pude leer sus datos. Complétalo a mano.' : 'No pude leer ese XML.', true );
		} );
	}

	/* ── Arrastrar ── */

	[ 'dragenter', 'dragover' ].forEach( function ( ev ) {
		zona.addEventListener( ev, function ( e ) {
			e.preventDefault();
			zona.classList.add( 'is-sobre' );
		} );
	} );
	[ 'dragleave', 'drop' ].forEach( function ( ev ) {
		zona.addEventListener( ev, function ( e ) {
			e.preventDefault();
			zona.classList.remove( 'is-sobre' );
		} );
	} );
	zona.addEventListener( 'drop', function ( e ) {
		var f = e.dataTransfer && e.dataTransfer.files;
		if ( ! f || ! f.length ) {
			return;
		}
		try {
			var dt = new DataTransfer();
			dt.items.add( f[ 0 ] );
			input.files = dt.files;
		} catch ( err ) {
			return;
		}
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	} );

	input.addEventListener( 'change', function () {
		recibir( input.files && input.files[ 0 ] );
	} );

	/* ── El archivo guardado ── */

	var x = document.querySelector( '[data-quitar-pdf]' );
	if ( x && quitar ) {
		x.addEventListener( 'click', function () {
			quitar.value = '1';
			if ( hay ) {
				hay.hidden = true;
			}
			if ( titulo ) {
				titulo.textContent = 'Arrastra el PDF aquí';
			}
			quitar.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
	}

	form.addEventListener( 'esc:guardado', function ( e ) {
		var d = e.detail || {};
		if ( quitar ) {
			quitar.value = '';
		}
		if ( ! hay ) {
			return;
		}
		if ( d.archivo ) {
			hay.querySelector( 'b' ).textContent = d.archivo.nombre;
			hay.querySelector( 'a' ).href = d.archivo.url;
			hay.hidden = false;
			if ( titulo ) {
				titulo.textContent = 'Arrastra otro para reemplazarlo';
			}
		}
	} );
}() );


/* ── Copiar al portapapeles y espejos de un campo ─────────────────────
   [data-copiar] copia su valor y avisa con el mismo botón. Un elemento con
   data-espejo-de="id" repite lo que se escribe en ese campo (el nombre del
   cliente en la cabecera de su ficha). */
( function () {
	'use strict';

	document.querySelectorAll( '[data-copiar]' ).forEach( function ( b ) {
		var rotulo = b.textContent;
		b.addEventListener( 'click', function () {
			var hecho = function () {
				b.textContent = 'Copiado';
				window.setTimeout( function () {
					b.textContent = rotulo;
				}, 1600 );
			};
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( b.getAttribute( 'data-copiar' ) ).then( hecho, function () {} );
			}
		} );
	} );

	document.querySelectorAll( '[data-espejo-de]' ).forEach( function ( el ) {
		var campo = document.getElementById( el.getAttribute( 'data-espejo-de' ) );
		if ( ! campo ) {
			return;
		}
		campo.addEventListener( 'input', function () {
			if ( campo.value.trim() ) {
				el.textContent = campo.value;
			}
		} );
	} );
}() );


/* ── Pagos del estudio en «Tu día» ────────────────────────────────────
   La casilla de un pago lo marca pagado y lo anota en Movimientos. Si el
   monto varía (otra moneda, la luz, el IVA) primero pregunta cuánto fue, en
   una línea que se abre bajo la fila, con el monto estimado ya escrito. */
( function () {
	'use strict';

	var CFG = window.malkaEscritorio || {};

	function guardar( b, monto, fecha ) {
		var fila    = b.closest( '.esc-dia-w' );
		var mosaico = document.getElementById( 'esc-mosaic' );
		// En Tareas no hay mosaico: el panel de pagos trae su propia llave.
		var caja    = b.closest( '[data-nonce]' );
		var nonce   = caja ? caja.getAttribute( 'data-nonce' ) : ( mosaico ? mosaico.getAttribute( 'data-nonce' ) : '' );

		b.disabled = true;
		b.classList.add( 'is-ok' );
		if ( fila ) {
			fila.classList.add( 'is-lista' );
		}

		if ( ! CFG.ajax || ! nonce ) {
			return;
		}

		var datos = new FormData();
		datos.append( 'action', 'malka_crm_pago_hecho' );
		datos.append( 'nonce', nonce );
		datos.append( 'mes', b.getAttribute( 'data-mes' ) );
		datos.append( 'clave', b.getAttribute( 'data-clave' ) );
		datos.append( 'monto', String( monto ) );
		// Cuándo se pagó de verdad. Sin esto el movimiento queda con la fecha
		// del día en que se marca, y un pago del día 1 marcado el 20 baja el
		// saldo tres semanas tarde.
		datos.append( 'fecha', fecha || b.getAttribute( 'data-fecha' ) || '' );

		fetch( CFG.ajax, { method: 'POST', credentials: 'same-origin', body: datos } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( r ) {
				if ( ! r || ! r.success ) {
					throw new Error( 'no' );
				}
				// En Pagos, la fila baja al montón «Pagados» tras un momento,
				// el tiempo de que se vea la marca donde estaba.
				var panel = fila ? fila.closest( '.esc-pms' ) : null;
				var hecho = panel ? panel.querySelector( '[data-pms-g="pagado"]' ) : null;
				if ( ! hecho ) {
					return;
				}

				window.setTimeout( function () {
					hecho.querySelector( '.esc-pms__l' ).appendChild( fila );

					// Los montos los manda el servidor ya sumados: el monto
					// que ella escribió al marcar puede no ser el del costo,
					// así que la pantalla no los recalcula por su cuenta.
					var res = r.resumen || {};
					[ 'atrasado', 'pendiente', 'pagado' ].forEach( function ( k ) {
						var g = panel.querySelector( '[data-pms-g="' + k + '"]' );
						if ( ! g ) {
							return;
						}
						var d = res[ k ];
						var n = d ? d.n : g.querySelectorAll( '.esc-pm' ).length;
						g.hidden = ! n;
						g.querySelector( '[data-pms-n]' ).textContent = String( n );
						if ( d && d.txt ) {
							g.querySelector( '[data-pms-m]' ).textContent = d.txt;
						}
					} );

					var vac = panel.querySelector( '[data-pms-vacio]' );
					if ( vac ) {
						vac.hidden = !! panel.querySelector( '[data-pms-g="atrasado"] .esc-pm, [data-pms-g="pendiente"] .esc-pm' );
					}

					var cab = panel.querySelector( '.esc-ph__r em' );
					if ( cab && res.pagado && res.pagado.txt ) {
						cab.textContent = res.pagado.txt;
					}
				}, 900 );
			} )
			.catch( function () {
				b.disabled = false;
				b.classList.remove( 'is-ok' );
				if ( fila ) {
					fila.classList.remove( 'is-lista' );
				}
			} );
	}

	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest ? e.target.closest( '.esc-dia__chk[data-tipo="pago"]' ) : null;
		if ( ! b ) {
			return;
		}
		// Este botón lo maneja este módulo, no el de las tareas.
		e.preventDefault();
		e.stopImmediatePropagation();
		if ( b.disabled ) {
			return;
		}

		var estimado = parseInt( b.getAttribute( 'data-monto' ), 10 ) || 0;

		if ( ! b.hasAttribute( 'data-variable' ) ) {
			guardar( b, estimado );
			return;
		}

		var fila = b.closest( '.esc-dia-w' );
		var ya   = fila.nextElementSibling;
		if ( ya && ya.classList.contains( 'esc-dia__pagar' ) ) {
			ya.querySelector( 'input' ).focus();
			return;
		}

		var form = document.createElement( 'form' );
		form.className = 'esc-dia__pagar';
		var hoy = new Date();
		var iso = hoy.getFullYear() + '-' +
			String( hoy.getMonth() + 1 ).padStart( 2, '0' ) + '-' +
			String( hoy.getDate() ).padStart( 2, '0' );

		form.innerHTML = '<label>¿Cuánto pagaste?</label>' +
			'<span class="esc-dia__pagar-w"><i>$</i><input type="text" inputmode="numeric" data-miles autocomplete="off" /></span>' +
			'<label class="esc-dia__pagar-f">¿Cuándo?<input type="date" data-cuando max="' + iso + '" /></label>' +
			'<button type="submit" class="esc-btn esc-btn--primary">Listo</button>' +
			'<button type="button" class="esc-btn esc-btn--ghost" data-cancelar>Cancelar</button>';
		fila.parentNode.insertBefore( form, fila.nextSibling );

		var campo = form.querySelector( 'input[data-miles]' );
		var dia   = form.querySelector( '[data-cuando]' );
		campo.value = estimado ? window.escMiles( estimado ) : '';
		// Viene puesto el día en que vencía, que casi siempre es el correcto.
		dia.value = b.getAttribute( 'data-fecha' ) || iso;
		campo.focus();
		campo.select();

		form.addEventListener( 'submit', function ( ev ) {
			ev.preventDefault();
			guardar( b, window.escLeer( campo.value ), dia.value );
			form.remove();
		} );
		form.querySelector( '[data-cancelar]' ).addEventListener( 'click', function () {
			form.remove();
		} );
	}, true );
}() );


/* ── Etiquetas de una cotización: chips que se agregan con Enter ────── */
( function () {
	'use strict';

	document.querySelectorAll( '[data-tags]' ).forEach( function ( caja ) {
		var box    = caja.querySelector( '.esc-tags__box' );
		var input  = box ? box.querySelector( 'input' ) : null;
		var campo  = caja.querySelector( '[data-tags-campo]' );
		var lista  = document.getElementById( 'esc-tags-lista' );

		if ( ! box || ! input || ! campo ) {
			return;
		}

		function actuales() {
			return Array.prototype.map.call( box.querySelectorAll( '.esc-tag' ), function ( t ) {
				return t.getAttribute( 'data-tag' );
			} );
		}

		function guardar() {
			campo.value = actuales().join( ',' );
			campo.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}

		function agregar( texto ) {
			texto = String( texto || '' ).replace( /,/g, ' ' ).trim();
			if ( ! texto ) {
				return;
			}
			// Si ya existe con otras mayúsculas, se usa la que ya existe.
			if ( lista ) {
				Array.prototype.forEach.call( lista.options, function ( o ) {
					if ( o.value.toLowerCase() === texto.toLowerCase() ) {
						texto = o.value;
					}
				} );
			}
			var ya = actuales().some( function ( t ) {
				return t.toLowerCase() === texto.toLowerCase();
			} );
			if ( ya ) {
				input.value = '';
				return;
			}
			var chip = document.createElement( 'span' );
			chip.className = 'esc-tag';
			chip.setAttribute( 'data-tag', texto );
			chip.textContent = texto;
			var x = document.createElement( 'button' );
			x.type = 'button';
			x.setAttribute( 'aria-label', 'Quitar' );
			x.innerHTML = '&times;';
			chip.appendChild( x );
			box.insertBefore( chip, input );
			input.value = '';
			guardar();
		}

		input.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key || ',' === e.key ) {
				e.preventDefault();
				agregar( input.value );
			} else if ( 'Backspace' === e.key && ! input.value ) {
				var ultimo = box.querySelector( '.esc-tag:last-of-type' );
				if ( ultimo ) {
					ultimo.remove();
					guardar();
				}
			}
		} );
		// Elegir una de la lista propuesta también la agrega.
		input.addEventListener( 'change', function ( e ) {
			e.stopPropagation();
			if ( input.value ) {
				agregar( input.value );
			}
		} );
		input.addEventListener( 'input', function ( e ) {
			// No es un campo del formulario: que el guardado no lo mire.
			e.stopPropagation();
		} );
		box.addEventListener( 'click', function ( e ) {
			var x = e.target.closest( '.esc-tag button' );
			if ( x ) {
				x.parentNode.remove();
				guardar();
			} else {
				input.focus();
			}
		} );
	} );
}() );

/* ── Escribir a varios clientes ───────────────────────────────────────
   «Escribir a clientes» muestra una casilla en cada fila y deja marcados los
   que se ven (con la etiqueta, la pestaña o la búsqueda que haya). La barra
   de abajo cuenta cuántos van; «Escribir correo» abre la ventana. */
( function () {
	'use strict';

	var boton = document.getElementById( 'esc-cl-escribir' );
	var lista = document.getElementById( 'esc-nl' );
	var barra = document.getElementById( 'esc-cl-envio' );
	var dlg   = document.getElementById( 'esc-com-dlg' );

	if ( ! boton || ! lista || ! barra || ! dlg ) {
		return;
	}

	var n       = document.getElementById( 'esc-cl-n' );
	var redact  = document.getElementById( 'esc-cl-redactar' );
	var form    = document.getElementById( 'esc-com-form' );
	var para    = document.getElementById( 'esc-com-para' );

	function filas() {
		return Array.prototype.slice.call( lista.querySelectorAll( '.esc-cl[data-id]' ) );
	}

	function marcados() {
		return filas().filter( function ( f ) {
			var c = f.querySelector( '.esc-cl__chk' );
			return ! f.hidden && c && c.checked && ! c.disabled;
		} );
	}

	function contar() {
		var m = marcados().length;
		n.textContent = String( m );
		redact.disabled = 0 === m;
	}

	function marcarVisibles() {
		filas().forEach( function ( f ) {
			var c = f.querySelector( '.esc-cl__chk' );
			if ( c && ! c.disabled ) {
				c.checked = ! f.hidden;
			}
		} );
		contar();
	}

	boton.addEventListener( 'click', function () {
		var on = ! lista.classList.contains( 'is-sel' );
		lista.classList.toggle( 'is-sel', on );
		barra.hidden = ! on;
		boton.classList.toggle( 'is-on', on );
		if ( on ) {
			marcarVisibles();
		}
	} );

	document.getElementById( 'esc-cl-salir' ).addEventListener( 'click', function () {
		lista.classList.remove( 'is-sel' );
		barra.hidden = true;
		boton.classList.remove( 'is-on' );
	} );
	document.getElementById( 'esc-cl-todos' ).addEventListener( 'click', marcarVisibles );

	lista.addEventListener( 'change', function ( e ) {
		if ( e.target.classList.contains( 'esc-cl__chk' ) ) {
			contar();
		}
	} );
	// Al filtrar en modo envío, se marcan los que quedan a la vista.
	lista.addEventListener( 'esc:filtrado', function () {
		if ( lista.classList.contains( 'is-sel' ) ) {
			marcarVisibles();
		}
	} );
	var q = document.getElementById( 'esc-nl-q' );
	if ( q ) {
		q.addEventListener( 'input', function () {
			window.setTimeout( contar, 0 );
		} );
	}
	document.querySelectorAll( '.esc-nl__tab[data-grupo]' ).forEach( function ( t ) {
		t.addEventListener( 'click', function () {
			window.setTimeout( function () {
				if ( lista.classList.contains( 'is-sel' ) ) {
					marcarVisibles();
				}
			}, 0 );
		} );
	} );

	redact.addEventListener( 'click', function () {
		var m = marcados();
		var nombres = m.map( function ( f ) {
			return f.querySelector( '.esc-cl__p b a' ).textContent;
		} );
		para.textContent = 'Para ' + m.length + ( 1 === m.length ? ' cliente: ' : ' clientes: ' ) +
			nombres.slice( 0, 6 ).join( ', ' ) + ( nombres.length > 6 ? ' y ' + ( nombres.length - 6 ) + ' más' : '' ) +
			'. Cada uno recibe su propio correo.';
		if ( dlg.showModal ) {
			dlg.showModal();
		}
	} );

	form.addEventListener( 'submit', function ( e ) {
		var ids = marcados().map( function ( f ) {
			return f.getAttribute( 'data-id' );
		} );
		if ( ! ids.length ) {
			e.preventDefault();
			return;
		}
		if ( ! window.confirm( '¿Enviar este correo a ' + ids.length + ( 1 === ids.length ? ' cliente?' : ' clientes?' ) ) ) {
			e.preventDefault();
			return;
		}
		var h = form.querySelector( 'input[name="ids"]' ) || document.createElement( 'input' );
		h.type  = 'hidden';
		h.name  = 'ids';
		h.value = ids.join( ',' );
		form.appendChild( h );
		var env = document.getElementById( 'esc-com-enviar' );
		env.disabled = true;
		env.textContent = 'Enviando…';
	} );

	/* Cómo se ve: lo arma el servidor con el diseño de los correos. */
	var ver = document.getElementById( 'esc-com-ver' );
	var prev = document.getElementById( 'esc-com-prev' );
	ver.addEventListener( 'click', function () {
		var datos = new FormData();
		datos.append( 'action', 'malka_crm_previa_mensaje' );
		datos.append( 'nonce', ver.getAttribute( 'data-nonce' ) );
		datos.append( 'mensaje', 'comunicado' );
		datos.append( 'asunto', document.getElementById( 'esc-com-asunto' ).value );
		datos.append( 'cuerpo', document.getElementById( 'esc-com-cuerpo' ).value );
		fetch( ver.getAttribute( 'data-ajax' ), { method: 'POST', credentials: 'same-origin', body: datos } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( r ) {
				if ( r && r.success ) {
					prev.hidden = false;
					prev.querySelector( 'iframe' ).srcdoc = r.data.html;
				}
			} );
	} );
}() );


/* ── Configuración: pestañas del formulario y un correo a la vez ───── */
( function () {
	'use strict';

	var tabs = document.querySelectorAll( '[data-cfg-tab]' );
	if ( tabs.length ) {
		var abrir = function ( k ) {
			var hay = document.querySelector( '[data-cfg-tab="' + k + '"]' );
			if ( ! hay ) {
				k = tabs[ 0 ].getAttribute( 'data-cfg-tab' );
			}
			tabs.forEach( function ( t ) {
				t.hidden = t.getAttribute( 'data-cfg-tab' ) !== k;
			} );
			document.querySelectorAll( '[data-cfg-ir]' ).forEach( function ( a ) {
				var on = a.getAttribute( 'data-cfg-ir' ) === k;
				a.classList.toggle( 'is-on', on );
				if ( on ) {
					a.setAttribute( 'aria-current', 'page' );
				} else {
					a.removeAttribute( 'aria-current' );
				}
			} );
		};

		document.addEventListener( 'click', function ( e ) {
			var a = e.target.closest ? e.target.closest( '[data-cfg-ir]' ) : null;
			if ( ! a ) {
				return;
			}
			e.preventDefault();
			var k = a.getAttribute( 'data-cfg-ir' );
			abrir( k );
			try {
				history.replaceState( null, '', '#' + k );
			} catch ( x ) {}
		} );

		abrir( ( location.hash || '' ).slice( 1 ) );
		window.addEventListener( 'hashchange', function () {
			abrir( ( location.hash || '' ).slice( 1 ) );
		} );
	}

	var nav = document.querySelector( '.esc-msgs__nav' );
	if ( nav ) {
		var cajas = document.querySelectorAll( '.esc-msgs__uno > .esc-msg' );
		var ver = function ( k ) {
			var hay = false;
			cajas.forEach( function ( c ) {
				var on = c.getAttribute( 'data-mensaje' ) === k;
				c.hidden = ! on;
				hay = hay || on;
			} );
			if ( ! hay && cajas.length ) {
				k = cajas[ 0 ].getAttribute( 'data-mensaje' );
				cajas[ 0 ].hidden = false;
			}
			nav.querySelectorAll( '[data-msg-ir]' ).forEach( function ( b ) {
				var on = b.getAttribute( 'data-msg-ir' ) === k;
				b.classList.toggle( 'is-on', on );
				if ( on ) {
					b.setAttribute( 'aria-current', 'true' );
				} else {
					b.removeAttribute( 'aria-current' );
				}
			} );
		};

		nav.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( '[data-msg-ir]' );
			if ( ! b ) {
				return;
			}
			var k = b.getAttribute( 'data-msg-ir' );
			ver( k );
			try {
				history.replaceState( null, '', '#' + k );
			} catch ( x ) {}
		} );

		var eleg = document.querySelector( '.esc-msgs__uno > .esc-msg[data-elegida]' );
		ver( ( location.hash || '' ).slice( 1 ) || ( eleg ? eleg.getAttribute( 'data-mensaje' ) : '' ) );

		// Duplicar, eliminar o restablecer, y renombrar las propias.
		var w = document.querySelector( '.esc-msgs__w' );
		var pedir = function ( datos ) {
			var fd = new FormData();
			fd.append( 'action', 'malka_crm_msg_plantilla' );
			fd.append( 'nonce', w.getAttribute( 'data-msg-nonce' ) );
			Object.keys( datos ).forEach( function ( k ) {
				fd.append( k, datos[ k ] );
			} );
			return fetch( ( window.malkaEscritorio || {} ).ajax, { method: 'POST', credentials: 'same-origin', body: fd } ).then( function ( r ) {
				return r.json();
			} );
		};
		document.addEventListener( 'click', function ( e ) {
			var b = e.target.closest ? e.target.closest( '[data-msg-op]' ) : null;
			if ( ! b || ! w ) {
				return;
			}
			e.preventDefault();
			var conf = b.getAttribute( 'data-confirmar' );
			if ( conf && ! window.confirm( conf ) ) {
				return;
			}
			b.disabled = true;
			pedir( { op: b.getAttribute( 'data-msg-op' ), clave: b.getAttribute( 'data-clave' ) } ).then( function ( j ) {
				if ( j && j.success && j.data && j.data.url ) {
					window.location.href = j.data.url;
				} else {
					b.disabled = false;
				}
			} );
		} );
		document.querySelectorAll( '[data-msg-nombre]' ).forEach( function ( inp ) {
			var antes = inp.value;
			inp.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key ) {
					e.preventDefault();
					inp.blur();
				}
			} );
			inp.addEventListener( 'blur', function () {
				var v = inp.value.trim();
				if ( ! v || v === antes ) {
					inp.value = antes;
					return;
				}
				pedir( { op: 'renombrar', clave: inp.getAttribute( 'data-msg-nombre' ), nombre: v } ).then( function () {
					antes = v;
					var bn = nav.querySelector( '[data-msg-ir="' + inp.getAttribute( 'data-msg-nombre' ) + '"] b' );
					if ( bn ) {
						bn.textContent = v;
					}
				} );
			} );
		} );
	}
}() );


/* ── Facturas del extranjero: arrastrarlas y buscar sus datos en el SII ──
   En la pestaña «Facturas de compra por emitir» se sueltan uno o varios PDF. Se
   lee lo que se puede (proveedor, número, fecha, monto y moneda) y cada
   uno queda anotado como compra pendiente de emitir. En la ficha del
   documento, si faltan los datos del SII, se buscan en su nómina. */
( function () {
	'use strict';

	var CFG = window.malkaEscritorio || {};
	var MES = { jan: 1, january: 1, feb: 2, february: 2, mar: 3, march: 3, apr: 4, april: 4, may: 5, jun: 6, june: 6, jul: 7, july: 7, aug: 8, august: 8, sep: 9, sept: 9, september: 9, oct: 10, october: 10, nov: 11, november: 11, dec: 12, december: 12,
		ene: 1, enero: 1, febrero: 2, marzo: 3, abr: 4, abril: 4, mayo: 5, junio: 6, julio: 7, ago: 8, agosto: 8, septiembre: 9, setiembre: 9, octubre: 10, noviembre: 11, dic: 12, diciembre: 12 };

	function pad( n ) {
		return ( '0' + n ).slice( -2 );
	}

	function cargarPdfjs() {
		if ( window.pdfjsLib ) {
			return Promise.resolve( window.pdfjsLib );
		}
		if ( ! CFG.pdfjs ) {
			return Promise.reject( new Error( 'sin pdfjs' ) );
		}
		return new Promise( function ( ok, mal ) {
			var s = document.createElement( 'script' );
			s.src = CFG.pdfjs;
			s.onload = function () {
				if ( ! window.pdfjsLib ) {
					mal( new Error( 'sin pdfjs' ) );
					return;
				}
				window.pdfjsLib.GlobalWorkerOptions.workerSrc = CFG.pdfjsWorker;
				ok( window.pdfjsLib );
			};
			s.onerror = mal;
			document.head.appendChild( s );
		} );
	}

	function textoPdf( buf ) {
		return cargarPdfjs().then( function ( lib ) {
			return lib.getDocument( { data: buf } ).promise;
		} ).then( function ( pdf ) {
			var ps = [];
			for ( var i = 1; i <= Math.min( pdf.numPages, 2 ); i++ ) {
				ps.push( pdf.getPage( i ).then( function ( p ) {
					return p.getTextContent();
				} ) );
			}
			return Promise.all( ps );
		} ).then( function ( cs ) {
			var lineas = [];
			cs.forEach( function ( c ) {
				var filas = {};
				c.items.forEach( function ( it ) {
					if ( it.str && it.str.trim() ) {
						var y = Math.round( it.transform[ 5 ] / 3 );
						( filas[ y ] = filas[ y ] || [] ).push( it );
					}
				} );
				Object.keys( filas ).map( Number ).sort( function ( a, b ) {
					return b - a;
				} ).forEach( function ( y ) {
					lineas.push( filas[ y ].sort( function ( a, b ) {
						return a.transform[ 4 ] - b.transform[ 4 ];
					} ).map( function ( it ) {
						return it.str.trim();
					} ).join( ' ' ) );
				} );
			} );
			return lineas.join( '\n' );
		} );
	}

	// «1,234.56» o «1.234,56» → 1234.56
	function cifra( t ) {
		t = String( t || '' ).replace( /\s/g, '' );
		var c = t.lastIndexOf( ',' );
		var p = t.lastIndexOf( '.' );
		if ( c > p ) {
			t = ( t.length - c - 1 === 3 && p === -1 && t.indexOf( ',' ) === c && t.length > 4 ) ? t.replace( /,/g, '' ) : t.replace( /\./g, '' ).replace( ',', '.' );
		} else {
			t = t.replace( /,/g, '' );
		}
		return parseFloat( t ) || 0;
	}

	function fecha( s, ingles ) {
		var m;
		s = String( s || '' );
		if ( ( m = s.match( /(\d{4})-(\d{2})-(\d{2})/ ) ) ) {
			return m[ 1 ] + '-' + m[ 2 ] + '-' + m[ 3 ];
		}
		if ( ( m = s.match( /([A-Za-zé]{3,10})\.?\s+(\d{1,2}),?\s+(\d{4})/ ) ) && MES[ m[ 1 ].toLowerCase() ] ) {
			return m[ 3 ] + '-' + pad( MES[ m[ 1 ].toLowerCase() ] ) + '-' + pad( m[ 2 ] );
		}
		if ( ( m = s.match( /(\d{1,2})\s+(?:de\s+)?([A-Za-zé]{3,10})\.?,?\s+(?:de\s+)?(\d{4})/ ) ) && MES[ m[ 2 ].toLowerCase() ] ) {
			return m[ 3 ] + '-' + pad( MES[ m[ 2 ].toLowerCase() ] ) + '-' + pad( m[ 1 ] );
		}
		if ( ( m = s.match( /(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})/ ) ) ) {
			var a = parseInt( m[ 1 ], 10 );
			var b = parseInt( m[ 2 ], 10 );
			// En inglés el mes va primero, salvo que no pueda serlo.
			var mes = ( ingles && a <= 12 && b > 12 ) || ( ingles && a <= 12 && b <= 12 ) ? a : b;
			var dia = mes === a ? b : a;
			return m[ 3 ] + '-' + pad( mes ) + '-' + pad( dia );
		}
		return '';
	}

	// Lo que se alcanza a leer de una factura del extranjero.
	function leer( t, archivo ) {
		var plano  = t.replace( /[ \t]+/g, ' ' );
		var ingles = /invoice|receipt|amount|bill to/i.test( plano );
		var d      = { proveedor: '', folio: '', fecha: '', monto: '', moneda: '' };
		var m;

		if ( ( m = plano.match( /(?:invoice|receipt|factura|bill)\s*(?:number|no\.?|n[°º]\.?|#|id)\s*:?\s*([A-Z0-9][A-Z0-9\-\/_.]{2,})/i ) ) ) {
			d.folio = m[ 1 ];
		}

		if ( ( m = plano.match( /(?:invoice date|date of issue|date issued|issue date|date paid|billing date|fecha(?: de emisi[oó]n)?)\s*:?\s*([^\n]{6,40})/i ) ) ) {
			d.fecha = fecha( m[ 1 ], ingles );
		}
		if ( ! d.fecha ) {
			d.fecha = fecha( plano, ingles );
		}

		// El total: la última línea que lo nombra y trae una cifra.
		var tot = plano.split( '\n' ).filter( function ( l ) {
			return /(grand total|total due|amount due|amount paid|total amount|\btotal\b|importe total|monto total)/i.test( l ) && /\d[\d.,]*\d/.test( l );
		} );
		var linea = tot.length ? tot[ tot.length - 1 ] : '';
		if ( linea && ( m = linea.match( /(US\$|USD|EUR|€|£|GBP|CLP|\$)?\s*([\d][\d.,]*\d)\s*(USD|EUR|GBP|CLP)?\s*$/i ) || linea.match( /(US\$|USD|EUR|€|£|GBP|CLP|\$)\s*([\d][\d.,]*\d)/i ) ) ) {
			d.monto = String( cifra( m[ 2 ] ) );
			var mon = ( m[ 1 ] || m[ 3 ] || '' ).toUpperCase();
			d.moneda = /EUR|€/.test( mon ) ? 'EUR' : ( /CLP/.test( mon ) ? 'CLP' : 'USD' );
		}
		if ( ! d.moneda ) {
			d.moneda = /€|EUR\b/.test( plano ) ? 'EUR' : 'USD';
		}

		// El proveedor encabeza la factura: el primer renglón con letras que
		// no sea un rótulo. Si no hay, el nombre del archivo.
		var prim = t.split( '\n' ).map( function ( l ) {
			return l.trim();
		} ).filter( function ( l ) {
			return /[a-z]{3}/i.test( l ) && l.length < 80 && ! /^(invoice|receipt|factura|tax invoice|bill to|page|p[aá]gina|date|fecha|paid|amount|total)\b/i.test( l ) && ! /@|www\.|https?:/i.test( l );
		} )[ 0 ];
		var empresa = plano.match( /([A-Z][A-Za-z0-9&.,' \-]{2,60}\b(?:Inc|LLC|L\.L\.C|Ltd|Limited|GmbH|B\.V|BV|S\.?A\.?R\.?L|Pty|Pte|Corp|Corporation|AG|PLC|Company)\b\.?)/ );
		d.proveedor = empresa ? empresa[ 1 ].trim() : ( prim || archivo.replace( /\.pdf$/i, '' ) );

		// Su identificación tributaria (VAT, Tax ID…), que va como su RUT.
		var tid = plano.match( /(?:VAT\s*(?:No\.?|number|ID|reg\.?(?:istration)?(?: no\.?)?)?|Tax\s*ID|TIN|EIN|ABN|GST\s*(?:No\.?|ID)?)\s*[:#]?\s*([A-Z]{0,3}\s?[0-9][0-9A-Z\- ]{5,18}[0-9A-Z])/i );
		d.rut = tid ? tid[ 1 ].replace( /\s+/g, '' ) : '';

		// La dirección: el renglón que sigue al proveedor, si parece una.
		var rs = t.split( '\n' ).map( function ( l ) {
			return l.trim();
		} );
		var k = rs.findIndex( function ( l ) {
			return d.proveedor && l.indexOf( d.proveedor ) !== -1;
		} );
		if ( k !== -1 ) {
			var dir = [];
			for ( var j = k + 1; j < Math.min( rs.length, k + 4 ); j++ ) {
				if ( /\d/.test( rs[ j ] ) && /[a-z]{3}/i.test( rs[ j ] ) && ! /invoice|receipt|factura|date|fecha|vat|tax|@|www\./i.test( rs[ j ] ) ) {
					dir.push( rs[ j ] );
				} else if ( dir.length ) {
					break;
				}
			}
			d.direccion = dir.join( ', ' ).slice( 0, 180 );
		}
		d.direccion = d.direccion || '';

		// «760 Market St, San Francisco, CA 94102» → dirección, comuna, ciudad.
		var partes = d.direccion.split( /\s*,\s*/ ).filter( Boolean );
		var limpio = function ( x ) {
			return String( x || '' ).replace( /\b[A-Z]{1,3}\s*\d{3,6}(-\d{3,4})?\b|\b\d{4,6}\b/g, '' ).replace( /\s{2,}/g, ' ' ).trim();
		};
		d.comuna = '';
		d.ciudad = '';
		if ( partes.length >= 3 ) {
			d.direccion = partes[ 0 ];
			d.comuna    = limpio( partes[ 1 ] );
			d.ciudad    = limpio( partes[ 2 ] ) || limpio( partes[ 1 ] );
		} else if ( 2 === partes.length ) {
			d.direccion = partes[ 0 ];
			d.ciudad    = limpio( partes[ 1 ] );
		}

		return d;
	}

	/* ── El cuadro de la lista ── */
	var zona = document.querySelector( '[data-drop-ext]' );
	if ( zona ) {
		var input = zona.querySelector( 'input[type="file"]' );
		var msg   = document.querySelector( '[data-drop-ext-msg]' );

		var decir = function ( t, mal ) {
			if ( msg ) {
				msg.textContent = t;
				msg.className   = 'esc-drop__msg' + ( mal ? ' is-err' : '' );
				msg.hidden      = ! t;
			}
		};

		var subir = function ( archivo, datos ) {
			var fd = new FormData();
			fd.append( 'action', 'malka_crm_doc_extranjero' );
			fd.append( 'nonce', zona.getAttribute( 'data-nonce' ) );
			Object.keys( datos ).forEach( function ( k ) {
				fd.append( k, datos[ k ] );
			} );
			fd.append( 'pdf', archivo );
			return fetch( zona.getAttribute( 'data-ajax' ), { method: 'POST', credentials: 'same-origin', body: fd } )
				.then( function ( r ) {
					return r.json();
				} );
		};

		var recibir = function ( lista ) {
			var archivos = Array.prototype.filter.call( lista || [], function ( f ) {
				return /\.pdf$/i.test( f.name ) || 'application/pdf' === f.type;
			} );
			if ( ! archivos.length ) {
				decir( 'Sólo facturas en PDF.', true );
				return;
			}

			zona.classList.add( 'is-trabajando' );
			var hechos = 0;
			var fallas = [];
			var notas  = [];
			var cambio = 0;

			var siguiente = function ( i ) {
				if ( i >= archivos.length ) {
					zona.classList.remove( 'is-trabajando' );
					// Lo que ya estaba o se juntó con una emitida se dice tal cual.
					var extra = notas.length ? ' ' + notas.join( ' ' ) : '';
					if ( fallas.length ) {
						decir( 'Se agregaron ' + hechos + ' de ' + archivos.length + '. No se pudo con: ' + fallas.join( ', ' ) + '.' + extra, true );
					} else if ( ! hechos ) {
						decir( extra.trim() );
					} else {
						decir( ( hechos === 1 ? 'Agregada.' : 'Se agregaron ' + hechos + '.' ) + extra + ( cambio ? ' Actualizando la lista…' : '' ) );
					}
					if ( cambio ) {
						window.setTimeout( function () {
							window.location.reload();
						}, notas.length ? 3500 : 700 );
					}
					return;
				}

				var f = archivos[ i ];
				decir( 'Leyendo ' + f.name + ' (' + ( i + 1 ) + ' de ' + archivos.length + ')…' );

				f.arrayBuffer()
					.then( function ( buf ) {
						return textoPdf( buf ).catch( function () {
							return '';
						} );
					} )
					.then( function ( t ) {
						decir( 'Buscando sus datos en el SII (' + ( i + 1 ) + ' de ' + archivos.length + ')…' );
						return subir( f, leer( t, f.name ) );
					} )
					.then( function ( r ) {
						if ( r && r.success ) {
							if ( r.data && r.data.nota ) {
								notas.push( r.data.nota );
							}
							if ( r.data && r.data.ya ) {
								return;
							}
							cambio++;
							if ( ! ( r.data && r.data.enlazada ) ) {
								hechos++;
							}
						} else {
							fallas.push( f.name );
						}
					} )
					.catch( function () {
						fallas.push( f.name );
					} )
					.then( function () {
						siguiente( i + 1 );
					} );
			};

			siguiente( 0 );
		};

		input.addEventListener( 'change', function () {
			recibir( input.files );
			input.value = '';
		} );
		[ 'dragenter', 'dragover' ].forEach( function ( ev ) {
			zona.addEventListener( ev, function ( e ) {
				e.preventDefault();
				zona.classList.add( 'is-sobre' );
			} );
		} );
		[ 'dragleave', 'drop' ].forEach( function ( ev ) {
			zona.addEventListener( ev, function ( e ) {
				e.preventDefault();
				zona.classList.remove( 'is-sobre' );
			} );
		} );
		zona.addEventListener( 'drop', function ( e ) {
			recibir( e.dataTransfer && e.dataTransfer.files );
		} );
	}

	/* ── En la ficha: buscar en el SII si faltan los datos ── */
	var caja = document.getElementById( 'esc-doc-sii' );
	if ( ! caja ) {
		return;
	}

	var nombre = document.getElementById( 'f-sii_nombre' );
	var rut    = document.getElementById( 'f-sii_rut' );
	var origen = document.getElementById( 'f-sii_origen' );
	var nota   = document.getElementById( 'esc-sii-nota' );
	var res    = document.getElementById( 'esc-sii-res' );
	var prov   = document.getElementById( 'f-cliente' );
	var ultimo = '';
	var reloj  = 0;

	var NOTAS = {
		sii: 'Encontrado en la nómina de prestadores extranjeros del SII.',
		generico: 'No aparece inscrito en el SII: se emite con el RUT genérico 55.555.555-5.',
		mano: 'Escritos a mano.'
	};

	var usar = function ( n, r, o ) {
		nombre.value = n;
		rut.value    = r;
		origen.value = o;
		if ( nota ) {
			nota.textContent = NOTAS[ o ] || '';
		}
		[ nombre, rut ].forEach( function ( el ) {
			el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
	};

	var boton = function ( texto, sub, fn ) {
		var b = document.createElement( 'button' );
		b.type = 'button';
		b.textContent = texto;
		if ( sub ) {
			var i = document.createElement( 'i' );
			i.textContent = sub;
			b.appendChild( i );
		}
		b.addEventListener( 'click', fn );
		return b;
	};

	var buscar = function () {
		var q = prov ? prov.value.trim() : '';
		if ( caja.hidden || q.length < 3 || q === ultimo ) {
			return;
		}
		ultimo = q;

		var fd = new FormData();
		fd.append( 'action', 'malka_crm_sii_buscar' );
		fd.append( 'nonce', caja.getAttribute( 'data-sii-nonce' ) );
		fd.append( 'nombre', q );

		fetch( caja.getAttribute( 'data-sii' ), { method: 'POST', credentials: 'same-origin', body: fd } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( r ) {
				if ( ! r || ! r.success ) {
					return;
				}
				var d     = r.data;
				var vacio = ! nombre.value.trim() && ! rut.value.trim();
				res.innerHTML = '';

				// Está en Proveedores: se completa todo lo que falte desde su ficha.
				if ( d.ficha ) {
					var pon = function ( idc, v ) {
						var el = document.getElementById( idc );
						if ( el && ! el.value.trim() && v ) {
							el.value = v;
							el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
						}
					};
					pon( 'f-sii_nombre', d.ficha.sii_nombre );
					pon( 'f-sii_rut', d.ficha.sii_rut );
					pon( 'f-sii_giro', d.ficha.sii_giro );
					pon( 'f-sii_dir', d.ficha.sii_dir );
					pon( 'f-sii_comuna', d.ficha.sii_comuna );
					pon( 'f-sii_ciudad', d.ficha.sii_ciudad );
					pon( 'f-rut', d.ficha.tax );
					pon( 'f-giro', d.ficha.giro );
					pon( 'f-dir', d.ficha.dir );
					pon( 'f-comuna', d.ficha.comuna );
					pon( 'f-ciudad', d.ficha.ciudad );
					origen.value = 'previo';
					if ( nota ) {
						nota.textContent = 'De la ficha del proveedor. Si corriges algo acá, la ficha se actualiza para la próxima.';
					}
					res.hidden = true;
					return;
				}

				if ( ! d.ok ) {
					res.appendChild( document.createTextNode( 'No se pudo consultar la nómina del SII ahora. Complétalos a mano.' ) );
					res.hidden = false;
					return;
				}

				if ( d.mejor ) {
					if ( vacio ) {
						usar( d.mejor.nombre, d.mejor.rut, 'sii' );
					} else if ( d.mejor.rut !== rut.value.trim() ) {
						res.appendChild( boton( 'Usar lo del SII: ' + d.mejor.nombre, d.mejor.rut, function () {
							usar( d.mejor.nombre, d.mejor.rut, 'sii' );
							res.hidden = true;
						} ) );
					}
				} else if ( vacio && ! ( d.otros && d.otros.length ) ) {
					// Ni parecida: no está inscrito, va con el RUT genérico.
					usar( q.toUpperCase(), d.generico, 'generico' );
				}

				if ( d.otros && d.otros.length ) {
					var t = document.createElement( 'span' );
					t.textContent = d.mejor ? '¿Era otra de estas?' : 'No la encontré tal cual. ¿Es alguna de estas?';
					res.appendChild( t );
					d.otros.forEach( function ( o ) {
						res.appendChild( boton( o.nombre, o.rut, function () {
							usar( o.nombre, o.rut, 'sii' );
							res.hidden = true;
						} ) );
					} );
					if ( ! d.mejor ) {
						res.appendChild( boton( 'Ninguna: no está inscrito', d.generico, function () {
							usar( q.toUpperCase(), d.generico, 'generico' );
							res.hidden = true;
						} ) );
					}
				}

				res.hidden = ! res.childNodes.length;
			} )
			.catch( function () {} );
	};

	// Lo que se cambia a mano queda como escrito a mano.
	[ nombre, rut ].forEach( function ( el ) {
		el.addEventListener( 'input', function ( e ) {
			if ( e.isTrusted ) {
				origen.value = 'mano';
				if ( nota ) {
					nota.textContent = NOTAS.mano;
				}
			}
		} );
	} );

	if ( prov ) {
		prov.addEventListener( 'input', function () {
			window.clearTimeout( reloj );
			reloj = window.setTimeout( buscar, 700 );
		} );
	}
	var emito = document.querySelector( '[data-doc-emito]' );
	if ( emito ) {
		emito.addEventListener( 'change', function () {
			window.setTimeout( buscar, 50 );
		} );
	}

	if ( ! nombre.value.trim() && ! rut.value.trim() ) {
		buscar();
	}

	/* ── El neto en pesos: original × cambio del Banco Central del día ── */
	var fem   = document.getElementById( 'f-fecha_mia' );
	var mon   = document.getElementById( 'f-moneda' );
	var orig  = document.getElementById( 'f-original' );
	var tc    = document.getElementById( 'f-tc' );
	var tcDe  = document.getElementById( 'f-tc_fecha' );
	var tcNo  = document.getElementById( 'esc-tc-nota' );
	var neto  = document.getElementById( 'esc-sii-neto' );
	var cta   = document.getElementById( 'esc-sii-cuenta' );
	var base  = document.querySelector( '[data-doc-base]' );
	var fecha = document.getElementById( 'f-fecha' );

	if ( ! fem || ! mon || ! orig || ! tc ) {
		return;
	}

	var num = function ( v ) {
		v = String( v || '' ).replace( /[^0-9.,]/g, '' );
		var c = v.lastIndexOf( ',' );
		var p = v.lastIndexOf( '.' );
		v = c > p ? v.replace( /\./g, '' ).replace( ',', '.' ) : v.replace( /,/g, '' );
		return parseFloat( v ) || 0;
	};
	var dos = function ( n ) {
		return n.toLocaleString( 'es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
	};
	var dmy = function ( f ) {
		var m = String( f || '' ).match( /(\d{4})-(\d{2})-(\d{2})/ );
		return m ? m[ 3 ] + '/' + m[ 2 ] + '/' + m[ 1 ] : f;
	};

	var calcular = function () {
		var extranjera = 'CLP' !== mon.value;
		var o = num( orig.value );
		var t = extranjera ? num( tc.value ) : 1;
		tc.closest( '.esc-f' ).hidden = ! extranjera;
		if ( o > 0 && t > 0 ) {
			var pesos = Math.round( o * t );
			neto.textContent = '$' + window.escMiles( pesos );
			cta.textContent  = extranjera ? mon.value + ' ' + dos( o ) + ' × ' + dos( t ) : '';
			if ( base ) {
				base.value = window.escMiles( pesos );
				base.readOnly = true;
				base.classList.add( 'is-calculado' );
				base.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			}
		} else {
			neto.textContent = '—';
			cta.textContent  = extranjera && o > 0 ? 'Falta el tipo de cambio' : '';
			if ( base ) {
				base.readOnly = false;
				base.classList.remove( 'is-calculado' );
			}
		}
	};

	var pedirTc = function () {
		if ( 'CLP' === mon.value ) {
			calcular();
			return;
		}
		var dia = fem.value || ( fecha ? fecha.value : '' );
		if ( ! dia ) {
			return;
		}
		tcNo.textContent = 'Consultando el Banco Central…';
		var fd = new FormData();
		fd.append( 'action', 'malka_crm_tc' );
		fd.append( 'nonce', caja.getAttribute( 'data-sii-nonce' ) );
		fd.append( 'moneda', mon.value );
		fd.append( 'fecha', dia );
		fetch( caja.getAttribute( 'data-sii' ), { method: 'POST', credentials: 'same-origin', body: fd } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( r ) {
				if ( r && r.success ) {
					tc.value = dos( r.data.valor );
					tcDe.value = r.data.fecha;
					tcNo.textContent = 'Banco Central, valor del ' + dmy( r.data.fecha ) + ( r.data.fecha !== dia ? ' (último día hábil antes del ' + dmy( dia ) + ')' : '' ) + '.';
				} else {
					tcNo.textContent = 'No se pudo consultar el Banco Central. Escríbelo a mano.';
				}
				calcular();
				tc.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			} )
			.catch( function () {
				tcNo.textContent = 'No se pudo consultar el Banco Central. Escríbelo a mano.';
			} );
	};

	fem.addEventListener( 'change', pedirTc );
	mon.addEventListener( 'change', pedirTc );
	orig.addEventListener( 'input', calcular );
	tc.addEventListener( 'input', function ( e ) {
		if ( e.isTrusted ) {
			tcDe.value = '';
			tcNo.textContent = 'Escrito a mano.';
		}
		calcular();
	} );

	calcular();
	if ( 'CLP' !== mon.value && num( orig.value ) > 0 && ! num( tc.value ) ) {
		pedirTc();
	}
}() );


/* ── «Facturas de compra por emitir»: editar en la lista y copiar con un toque ──
   Cada dato es un campo: se corrige ahí mismo y se guarda al salir. La
   fila vuelve recalculada (neto, cambio, estado) y la ficha del proveedor
   queda al día para la factura del mes siguiente. */
( function () {
	'use strict';

	var zona  = document.querySelector( '[data-drop-ext]' );
	var ajax  = zona ? zona.getAttribute( 'data-ajax' ) : '';
	var nonce = zona ? zona.getAttribute( 'data-nonce' ) : '';

	function copiar( texto, el ) {
		var listo = function () {
			el.classList.add( 'is-copiado' );
			window.setTimeout( function () {
				el.classList.remove( 'is-copiado' );
			}, 900 );
		};
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( texto ).then( listo ).catch( function () {} );
			return;
		}
		var t = document.createElement( 'textarea' );
		t.value = texto;
		document.body.appendChild( t );
		t.select();
		try {
			document.execCommand( 'copy' );
			listo();
		} catch ( x ) {}
		t.remove();
	}

	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest ? e.target.closest( '.esc-em [data-copiar], .esc-em [data-copiar-campo]' ) : null;
		if ( ! b ) {
			return;
		}
		e.preventDefault();
		var texto = b.hasAttribute( 'data-copiar' ) ? b.getAttribute( 'data-copiar' ) : '';
		if ( ! texto ) {
			var inp = b.parentNode.querySelector( 'input' );
			texto = inp ? inp.value : '';
			// Una fecha se copia como la pide el SII: dd-mm-aaaa.
			if ( inp && 'date' === inp.type && /^\d{4}-\d{2}-\d{2}$/.test( texto ) ) {
				texto = texto.slice( 8, 10 ) + '-' + texto.slice( 5, 7 ) + '-' + texto.slice( 0, 4 );
			}
		}
		if ( texto ) {
			copiar( texto, b );
		}
	} );

	if ( ! ajax ) {
		return;
	}

	document.addEventListener( 'change', function ( e ) {
		var inp = e.target;
		if ( ! inp.matches || ! inp.matches( '.esc-em input[data-campo], .esc-em select[data-campo]' ) ) {
			return;
		}
		var fila = inp.closest( '.esc-em' );
		var fd   = new FormData();
		fd.append( 'action', 'malka_crm_doc_campo' );
		fd.append( 'nonce', nonce );
		fd.append( 'doc', fila.getAttribute( 'data-doc' ) );
		fd.append( 'campo', inp.getAttribute( 'data-campo' ) );
		fd.append( 'valor', inp.value );
		fila.classList.add( 'is-guardando' );

		fetch( ajax, { method: 'POST', credentials: 'same-origin', body: fd } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( r ) {
				if ( ! r || ! r.success ) {
					throw new Error( 'no' );
				}
				// Se reemplaza la fila, pero sin perder dónde estaba el cursor.
				var foco = document.activeElement && fila.contains( document.activeElement ) ? document.activeElement.getAttribute( 'data-campo' ) : '';
				var tmp  = document.createElement( 'div' );
				tmp.innerHTML = r.data.fila;
				var nueva = tmp.firstElementChild;
				fila.replaceWith( nueva );
				nueva.classList.add( 'is-guardado' );
				window.setTimeout( function () {
					nueva.classList.remove( 'is-guardado' );
				}, 900 );
				if ( foco ) {
					var otro = nueva.querySelector( '[data-campo="' + foco + '"]' );
					if ( otro ) {
						otro.focus();
					}
				}
			} )
			.catch( function () {
				fila.classList.remove( 'is-guardando' );
				fila.classList.add( 'is-error' );
			} );
	} );

	// Enter guarda y pasa al siguiente, como en una planilla.
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Enter' !== e.key || ! e.target.matches || ! e.target.matches( '.esc-em input[data-campo]' ) ) {
			return;
		}
		e.preventDefault();
		var todos = Array.prototype.slice.call( e.target.closest( '.esc-em' ).querySelectorAll( 'input[data-campo]' ) );
		var i     = todos.indexOf( e.target );
		e.target.blur();
		if ( todos[ i + 1 ] ) {
			window.setTimeout( function () {
				var sig = document.querySelector( '.esc-em [data-campo="' + todos[ i + 1 ].getAttribute( 'data-campo' ) + '"]' );
				if ( sig ) {
					sig.focus();
				}
			}, 350 );
		}
	} );
}() );

/* ── En el documento: el enlace para corroborar el cambio sigue la fecha ── */
( function () {
	'use strict';

	var ver = document.getElementById( 'esc-tc-ver' );
	var fem = document.getElementById( 'f-fecha_mia' );
	var mon = document.getElementById( 'f-moneda' );
	if ( ! ver || ! fem || ! mon ) {
		return;
	}
	var poner = function () {
		var anio = ( fem.value || '' ).slice( 0, 4 ) || String( new Date().getFullYear() );
		ver.href = 'EUR' === mon.value ? ver.getAttribute( 'data-eur' ) : ver.getAttribute( 'data-usd' ) + anio + '.htm';
		ver.hidden = 'CLP' === mon.value;
	};
	fem.addEventListener( 'change', poner );
	mon.addEventListener( 'change', poner );
	poner();
}() );


/* ── Proveedores: elegir varios y mandarlos a la papelera ───────────── */
( function () {
	'use strict';

	var form = document.getElementById( 'esc-pv-sel' );
	if ( ! form ) {
		return;
	}
	var barra = document.getElementById( 'esc-pv-barra' );
	var cuenta = document.getElementById( 'esc-pv-n' );
	var todos = document.getElementById( 'esc-pv-todos' );

	function marcados() {
		return form.querySelectorAll( 'input[name="ids[]"]:checked' );
	}
	function pintar() {
		var n = marcados().length;
		barra.hidden = ! n;
		cuenta.textContent = n === 1 ? '1 proveedor elegido' : n + ' proveedores elegidos';
		if ( todos ) {
			var visibles = Array.prototype.filter.call( form.querySelectorAll( 'input[name="ids[]"]' ), function ( c ) {
				return ! c.closest( '.esc-pv' ).hidden;
			} );
			todos.checked = visibles.length && visibles.every( function ( c ) {
				return c.checked;
			} );
		}
	}
	form.addEventListener( 'change', function ( e ) {
		if ( e.target === todos ) {
			form.querySelectorAll( 'input[name="ids[]"]' ).forEach( function ( c ) {
				if ( ! c.closest( '.esc-pv' ).hidden ) {
					c.checked = todos.checked;
				}
			} );
		}
		pintar();
	} );
	form.addEventListener( 'submit', function ( e ) {
		var n = marcados().length;
		if ( ! n || ! window.confirm( n === 1 ? '¿Mandar este proveedor a la papelera? Sus facturas no se tocan.' : '¿Mandar estos ' + n + ' proveedores a la papelera? Sus facturas no se tocan.' ) ) {
			e.preventDefault();
		}
	} );
	document.getElementById( 'esc-pv-nada' ).addEventListener( 'click', function () {
		marcados().forEach( function ( c ) {
			c.checked = false;
		} );
		pintar();
	} );
	pintar();
}() );


/* ── Facturas de compra por emitir: filtrar por estado y ordenar ───────────── */
( function () {
	'use strict';

	var lista = document.getElementById( 'esc-em-lista' );
	var ord   = document.getElementById( 'esc-em-ord' );
	if ( ! lista || ! ord ) {
		return;
	}
	var RANGO  = { faltan: 0, lista: 1, emitida: 2 };
	var estado = '';
	var GUARDA = ord.getAttribute( 'data-guarda' ) || 'malka-em-orden';

	try {
		var g = window.localStorage.getItem( GUARDA );
		if ( g && ord.querySelector( 'option[value="' + g + '"]' ) ) {
			ord.value = g;
		}
	} catch ( x ) {}

	function aplicar() {
		var filas = Array.prototype.slice.call( lista.querySelectorAll( '.esc-em' ) );
		var modo  = ord.value;
		filas.sort( function ( a, b ) {
			var fa = a.getAttribute( 'data-fecha' ) || '';
			var fb = b.getAttribute( 'data-fecha' ) || '';
			if ( 'fecha_asc' === modo ) {
				return fa.localeCompare( fb );
			}
			if ( 'fecha_desc' === modo ) {
				return fb.localeCompare( fa );
			}
			if ( 'emision' === modo ) {
				// El orden en que van al SII: fecha de emisión, la más antigua primero.
				return ( a.getAttribute( 'data-emision' ) || '' ).localeCompare( b.getAttribute( 'data-emision' ) || '' ) || ( +a.getAttribute( 'data-doc' ) - +b.getAttribute( 'data-doc' ) );
			}
			if ( 'folio' === modo ) {
				return ( +b.getAttribute( 'data-folio' ) || 0 ) - ( +a.getAttribute( 'data-folio' ) || 0 ) || fb.localeCompare( fa );
			}
			if ( 'empresa' === modo ) {
				return ( a.getAttribute( 'data-empresa' ) || '' ).localeCompare( b.getAttribute( 'data-empresa' ) || '' ) || fb.localeCompare( fa );
			}
			return ( RANGO[ a.getAttribute( 'data-estado' ) ] - RANGO[ b.getAttribute( 'data-estado' ) ] ) || fb.localeCompare( fa );
		} );
		var hay = 0;
		var fin = lista.querySelector( '.esc-em__nada' );
		filas.forEach( function ( f ) {
			lista.insertBefore( f, fin );
			f.hidden = !! estado && f.getAttribute( 'data-estado' ) !== estado && f.getAttribute( 'data-sub' ) !== estado;
			if ( ! f.hidden ) {
				hay++;
			}
		} );
		if ( fin ) {
			fin.hidden = hay > 0;
		}
	}

	ord.addEventListener( 'change', function () {
		try {
			window.localStorage.setItem( GUARDA, ord.value );
		} catch ( x ) {}
		aplicar();
	} );
	document.querySelectorAll( '.esc-em__chip' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			estado = b.getAttribute( 'data-estado' );
			document.querySelectorAll( '.esc-em__chip' ).forEach( function ( o ) {
				o.classList.toggle( 'is-on', o === b );
			} );
			aplicar();
		} );
	} );
	// Una fila que se guarda vuelve con su estado nuevo: se reordena.
	new MutationObserver( function ( m ) {
		if ( m.some( function ( x ) {
			return x.addedNodes.length;
		} ) ) {
			window.clearTimeout( aplicar.t );
			aplicar.t = window.setTimeout( aplicar, 50 );
		}
	} ).observe( lista, { childList: true } );
	aplicar();
}() );


/* ── Facturas de compra por emitir: ocultar el detalle y dejar sólo la cabecera ── */
( function () {
	'use strict';

	var lista = document.getElementById( 'esc-em-lista' );
	if ( ! lista ) {
		return;
	}
	var LLAVE = 'malka-em-plegadas';
	var plegadas = {};
	try {
		plegadas = JSON.parse( window.localStorage.getItem( LLAVE ) || '{}' ) || {};
	} catch ( x ) {}

	function guardar() {
		try {
			window.localStorage.setItem( LLAVE, JSON.stringify( plegadas ) );
		} catch ( x ) {}
	}
	function poner( fila, si ) {
		fila.classList.toggle( 'is-plegada', si );
		var b = fila.querySelector( '[data-plegar]' );
		if ( b ) {
			b.setAttribute( 'aria-expanded', si ? 'false' : 'true' );
		}
		var id = fila.getAttribute( 'data-doc' );
		if ( si ) {
			plegadas[ id ] = 1;
		} else {
			delete plegadas[ id ];
		}
	}
	function pintarBoton() {
		var todas = document.getElementById( 'esc-em-todas' );
		if ( ! todas ) {
			return;
		}
		var filas = lista.querySelectorAll( '.esc-em' );
		var todasOcultas = filas.length && Array.prototype.every.call( filas, function ( f ) {
			return f.classList.contains( 'is-plegada' );
		} );
		todas.textContent = todasOcultas ? 'Mostrar todos los detalles' : 'Ocultar todos los detalles';
		todas.setAttribute( 'data-ocultas', todasOcultas ? '1' : '0' );
	}
	function restaurar() {
		lista.querySelectorAll( '.esc-em' ).forEach( function ( f ) {
			if ( plegadas[ f.getAttribute( 'data-doc' ) ] ) {
				poner( f, true );
			}
		} );
		pintarBoton();
	}

	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest ? e.target.closest( '.esc-em [data-plegar]' ) : null;
		if ( b ) {
			var fila = b.closest( '.esc-em' );
			poner( fila, ! fila.classList.contains( 'is-plegada' ) );
			guardar();
			pintarBoton();
			return;
		}
		var t = e.target.closest ? e.target.closest( '#esc-em-todas' ) : null;
		if ( t ) {
			var ocultar = '1' !== t.getAttribute( 'data-ocultas' );
			lista.querySelectorAll( '.esc-em' ).forEach( function ( f ) {
				poner( f, ocultar );
			} );
			guardar();
			pintarBoton();
		}
	} );

	// Una fila que vuelve del guardado conserva cómo estaba.
	new MutationObserver( restaurar ).observe( lista, { childList: true } );
	restaurar();
}() );

/* ── Importar el listado de documentos del SII (emitidos o recibidos) ──
   Se lee el PDF de «Administración de documentos» acá mismo, renglón por
   renglón, y el servidor dice qué es nuevo y qué ya estaba anotado. Nada
   se guarda hasta que ella aprieta «Importar». */
( function () {
	'use strict';

	var dlg = document.getElementById( 'esc-imp' );
	var abrir = document.querySelector( '[data-imp-abrir]' );
	if ( ! dlg || ! abrir ) {
		return;
	}
	var CFG = window.malkaEscritorio || {};
	var drop = dlg.querySelector( '[data-imp-drop]' );
	var input = drop.querySelector( 'input' );
	var msg = dlg.querySelector( '[data-imp-msg]' );
	var res = dlg.querySelector( '[data-imp-res]' );
	var pie = dlg.querySelector( '[data-imp-pie]' );
	var cuenta = dlg.querySelector( '[data-imp-cuenta]' );
	var ok = dlg.querySelector( '[data-imp-ok]' );
	var lotes = []; // [{lado, filas, vista}]
	var f29s = []; // F29 leídos, por mes
	var hubo = false;

	var DOC = /(Factura\s+de\s+Compra\s+Electr[oó]nica|Factura\s+(?:No\s+Afecta\s+o\s+)?Exenta\s+Electr[oó]nica|Factura\s+Electr[oó]nica|Nota\s+de\s+Cr[eé]dito\s+Electr[oó]nica|Nota\s+de\s+D[eé]bito\s+Electr[oó]nica|Boleta(?:\s+de\s+Honorarios)?(?:\s+Exenta)?\s+Electr[oó]nica|Gu[ií]a\s+de\s+Despacho\s+Electr[oó]nica|Liquidaci[oó]n\s+Factura\s+Electr[oó]nica)/i;

	function aviso( t, malo ) {
		msg.hidden = ! t;
		msg.textContent = t || '';
		msg.classList.toggle( 'is-mal', !! malo );
	}
	function plata( n ) {
		return '$' + String( Math.round( n ) ).replace( /\B(?=(\d{3})+(?!\d))/g, '.' );
	}
	function fechaBonita( f ) {
		var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec( f || '' );
		return m ? m[ 3 ] + '/' + m[ 2 ] + '/' + m[ 1 ] : f;
	}
	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"]/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ c ];
		} );
	}

	function cargarPdfjs() {
		if ( window.pdfjsLib ) {
			return Promise.resolve( window.pdfjsLib );
		}
		return new Promise( function ( bien, mal ) {
			var s = document.createElement( 'script' );
			s.src = CFG.pdfjs;
			s.onload = function () {
				if ( ! window.pdfjsLib ) {
					mal( new Error( 'sin pdfjs' ) );
					return;
				}
				window.pdfjsLib.GlobalWorkerOptions.workerSrc = CFG.pdfjsWorker;
				bien( window.pdfjsLib );
			};
			s.onerror = mal;
			document.head.appendChild( s );
		} );
	}

	// Todas las páginas, una línea por renglón.
	function lineas( buf ) {
		return cargarPdfjs().then( function ( lib ) {
			return lib.getDocument( { data: buf } ).promise;
		} ).then( function ( pdf ) {
			var p = [];
			for ( var i = 1; i <= pdf.numPages; i++ ) {
				p.push( pdf.getPage( i ).then( function ( pg ) {
					return pg.getTextContent();
				} ) );
			}
			return Promise.all( p );
		} ).then( function ( paginas ) {
			var out = [];
			paginas.forEach( function ( c ) {
				// Se agrupan por cercanía: la razón social viene un punto más arriba que el resto.
				var items = c.items.filter( function ( it ) {
					return it.str && it.str.trim();
				} ).sort( function ( a, b ) {
					return b.transform[ 5 ] - a.transform[ 5 ];
				} );
				var grupos = [];
				items.forEach( function ( it ) {
					var g = grupos[ grupos.length - 1 ];
					if ( g && Math.abs( g.y - it.transform[ 5 ] ) <= 4 ) {
						g.items.push( it );
					} else {
						grupos.push( { y: it.transform[ 5 ], items: [ it ] } );
					}
				} );
				grupos.forEach( function ( g ) {
					out.push( g.items.sort( function ( a, b ) {
						return a.transform[ 4 ] - b.transform[ 4 ];
					} ).map( function ( it ) {
						return it.str.trim();
					} ).join( '  ' ) );
				} );
			} );
			return out;
		} );
	}

	function leer( ls ) {
		var todo = ls.join( '\n' );
		// El título o la columna dicen de qué lado es («Recibido» aparece también como estado en emitidos).
		var lado = /Documentos\s+Emitidos|\bReceptor\s+Raz[oó]n/i.test( todo ) ? 'emitidos'
			: ( /Documentos\s+Recibidos|^\s*Recibidos\s*$|\bEmisor\s+Raz[oó]n/im.test( todo ) ? 'recibidos' : '' );
		var filas = [];
		ls.forEach( function ( l ) {
			l = l.replace( /\s+/g, ' ' ).trim();
			var r = /(\d{1,2}\.?\d{3}\.?\d{3}-[\dkK])\s+(.+)$/.exec( l );
			if ( ! r ) {
				return;
			}
			var resto = r[ 2 ];
			var d = DOC.exec( resto );
			if ( ! d ) {
				return;
			}
			var nombre = resto.slice( 0, d.index ).trim();
			var cola = resto.slice( d.index + d[ 0 ].length ).trim();
			var m = /^(\d+)\s+(\d{4}-\d{2}-\d{2}|\d{2}[-/]\d{2}[-/]\d{4})\s+([\d.]+)\s*(.*)$/.exec( cola );
			if ( ! m ) {
				return;
			}
			var fecha = m[ 2 ];
			var dm = /^(\d{2})[-/](\d{2})[-/](\d{4})$/.exec( fecha );
			if ( dm ) {
				fecha = dm[ 3 ] + '-' + dm[ 2 ] + '-' + dm[ 1 ];
			}
			filas.push( {
				rut: r[ 1 ],
				nombre: nombre,
				doc: d[ 0 ].replace( /\s+/g, ' ' ),
				folio: m[ 1 ],
				fecha: fecha,
				monto: m[ 3 ].replace( /\./g, '' ),
				estado: m[ 4 ]
			} );
		} );
		return { lado: lado, filas: filas };
	}

	function pedir( lote, probar, elegidas ) {
		var fd = new FormData();
		fd.append( 'action', 'malka_crm_doc_importar' );
		fd.append( 'nonce', dlg.getAttribute( 'data-nonce' ) );
		fd.append( 'lado', lote.lado );
		fd.append( 'filas', JSON.stringify( lote.filas ) );
		if ( probar ) {
			fd.append( 'probar', '1' );
		}
		if ( elegidas ) {
			fd.append( 'elegidas', JSON.stringify( elegidas ) );
		}
		return fetch( dlg.getAttribute( 'data-ajax' ), { method: 'POST', credentials: 'same-origin', body: fd } )
			.then( function ( r ) {
				return r.json();
			} ).then( function ( j ) {
				if ( ! j || ! j.success ) {
					throw new Error( ( j && j.data && j.data.msg ) || 'error' );
				}
				return j.data;
			} );
	}

	var ROT = {
		nuevo: [ 'nuevo', 'Nuevo' ],
		emitir: [ 'emitir', 'Se marca emitida' ],
		periodo: [ 'emitir', 'Cambia de mes' ],
		fuera: [ 'emitir', 'No está en el libro' ],
		duplicado: [ 'dup', 'Ya estaba' ],
		repetido: [ 'dup', 'Repetido en los archivos' ],
		omitir: [ 'omit', 'No se importa' ]
	};

	function pintar() {
		var html = '';
		var n = 0;
		lotes.forEach( function ( lote, li ) {
			var nuevos = lote.vista.filter( function ( f ) {
				return 'nuevo' === f.accion || 'emitir' === f.accion || 'periodo' === f.accion || 'fuera' === f.accion;
			} ).length;
			html += '<div class="esc-imp__lote"><p class="esc-imp__h">' + ( 'recibidos' === lote.lado ? 'Recibidos' : 'Emitidos' ) +
				' <span>' + lote.vista.length + ' en los archivos · ' + nuevos + ' por importar · ' + ( lote.vista.length - nuevos ) + ' ya estaban, repetidos u omitidos</span></p>';
			html += '<div class="esc-imp__tabla"><table><thead><tr><th></th><th>Fecha</th><th>Documento</th><th>' + ( 'recibidos' === lote.lado ? 'Emisor' : 'Receptor' ) + '</th><th class="r">Total</th><th>Estado</th></tr></thead><tbody>';
			lote.vista.forEach( function ( f ) {
				var puede = 'nuevo' === f.accion || 'emitir' === f.accion || 'periodo' === f.accion || 'fuera' === f.accion;
				var rot = ROT[ f.accion ] || ROT.omitir;
				if ( puede ) {
					n++;
				}
				html += '<tr class="' + ( puede ? '' : 'is-off' ) + '">' +
					'<td><input type="checkbox" data-lote="' + li + '" value="' + f.i + '"' + ( puede ? ' checked' : ' disabled' ) + ' /></td>' +
					'<td>' + esc( fechaBonita( f.fecha ) ) + '</td>' +
					'<td><b>' + esc( f.rotulo ) + '</b><small>N° ' + esc( f.folio ) + ' · ' + esc( f.lado ) + '</small></td>' +
					'<td>' + esc( f.nombre ) + '<small>' + esc( f.rut ) + '</small></td>' +
					'<td class="r">' + plata( f.total ) + '</td>' +
					'<td><span class="esc-imp__st esc-imp__st--' + rot[ 0 ] + '"' + ( f.motivo ? ' title="' + esc( f.motivo ) + '"' : '' ) + '>' +
					( f.url && ! puede ? '<a href="' + esc( f.url ) + '" target="_blank" rel="noopener">' + rot[ 1 ] + ' ↗</a>' : rot[ 1 ] ) + '</span>' +
					( f.motivo && ( 'periodo' === f.accion || 'fuera' === f.accion ) ? '<small>' + esc( f.motivo ) + '</small>' : '' ) + '</td></tr>';
			} );
			html += '</tbody></table></div></div>';
		} );
		var f29h = f29s.map( function ( x, i ) {
			var mes = new Date( x.mes + '-15' ).toLocaleDateString( 'es-CL', { month: 'long', year: 'numeric' } );
			return '<label class="esc-imp__f29"><input type="checkbox" data-f29="' + i + '" checked /><b>F29 de ' + esc( mes ) + '</b>' +
				'<span>Débito ' + plata( x.debito ) + '</span><span>Crédito ' + plata( x.credito ) + ( x.rem_ant ? ' (con remanente ' + plata( x.rem_ant ) + ')' : '' ) + '</span>' +
				'<span>IVA ' + plata( x.iva ) + '</span><span>PPM ' + plata( x.ppm ) + ( x.tasa ? ' (' + String( x.tasa ).replace( '.', ',' ) + '%)' : '' ) + '</span><b>Total ' + plata( x.total ) + '</b></label>';
		} ).join( '' );
		if ( f29h ) {
			html = '<div class="esc-imp__lote"><p class="esc-imp__h">Formulario 29 <span>se guarda como lo declarado en ese mes</span></p>' + f29h + '</div>' + html;
		}
		res.innerHTML = html;
		res.hidden = ! lotes.length && ! f29s.length;
		pie.hidden = ! lotes.length && ! f29s.length;
		contar();
	}

	function contar() {
		var n = res.querySelectorAll( 'input[type=checkbox]:checked' ).length;
		cuenta.textContent = n ? n + ( 1 === n ? ' cambio se va a guardar' : ' cambios se van a guardar' ) : 'No hay nada nuevo que importar';
		ok.disabled = ! n;
	}
	res.addEventListener( 'change', contar );

	// El CSV del Registro de Compras y Ventas («Descargar detalles»).
	var TIPO_DTE = {
		'33': 'Factura Electronica',
		'34': 'Factura Exenta Electronica',
		'46': 'Factura de Compra Electronica',
		'61': 'Nota de Credito Electronica',
		'56': 'Nota de Debito Electronica',
		'39': 'Boleta Electronica',
		'41': 'Boleta Exenta Electronica',
		'30': 'Factura',
		'32': 'Factura Exenta',
		'60': 'Nota de Credito'
	};
	function sinTilde( t ) {
		return String( t || '' ).trim().toLowerCase().normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' );
	}

	// Una tabla (filas de celdas) del SII → documentos. Sirve para el TXT,
	// el CSV y el XLS: busca la fila de títulos y reconoce las columnas por nombre.
	function deTabla( tabla ) {
		var h = -1;
		for ( var i = 0; i < Math.min( tabla.length, 40 ); i++ ) {
			var fila = tabla[ i ].map( sinTilde );
			if ( fila.some( function ( c ) {
				return /^folio/.test( c );
			} ) && fila.some( function ( c ) {
				return /monto|total/.test( c );
			} ) ) {
				h = i;
				break;
			}
		}
		if ( h < 0 ) {
			return null;
		}
		var cab = tabla[ h ].map( sinTilde );
		function col( re, salvo ) {
			for ( var i = 0; i < cab.length; i++ ) {
				if ( re.test( cab[ i ] ) && ! ( salvo && salvo.test( cab[ i ] ) ) ) {
					return i;
				}
			}
			return -1;
		}
		var cTipo = col( /^tipo doc|^documento$|^tipo dte|^tipo$/ );
		var cRut = col( /^rut|^emisor$|^receptor$/ );
		var cNom = col( /razon social|^nombre/ );
		var cFol = col( /^folio/ );
		var cFec = col( /^fecha doc|^fecha emision|^fecha$/ );
		if ( cFec < 0 ) {
			cFec = col( /^fecha/, /recepcion|acuse|reclamo/ );
		}
		var cTot = col( /^monto total|^total/ );
		if ( cTot < 0 ) {
			cTot = col( /^monto$/ );
		}
		var cEst = col( /^estado/ );
		if ( cTipo < 0 || cFol < 0 || cTot < 0 ) {
			return null;
		}
		var antes = tabla.slice( 0, h ).map( function ( f ) {
			return f.join( ' ' );
		} ).join( ' ' );
		var lado = '';
		if ( cab.some( function ( c ) {
			return /^receptor|rut cliente|tipo venta/.test( c );
		} ) || /emitid/i.test( antes ) ) {
			lado = 'emitidos';
		} else if ( cab.some( function ( c ) {
			return /^emisor|proveedor|tipo compra/.test( c );
		} ) || /recibid/i.test( antes ) ) {
			lado = 'recibidos';
		}
		var filas = [];
		tabla.slice( h + 1 ).forEach( function ( c ) {
			var tipo = String( c[ cTipo ] == null ? '' : c[ cTipo ] ).trim();
			var folio = String( c[ cFol ] == null ? '' : c[ cFol ] ).replace( /\D/g, '' );
			if ( ! tipo || ! folio ) {
				return;
			}
			var f = String( cFec >= 0 && c[ cFec ] != null ? c[ cFec ] : '' ).trim();
			var dm = /^(\d{1,2})[-/](\d{1,2})[-/](\d{4})/.exec( f );
			var iso = /^(\d{4})-(\d{2})-(\d{2})/.exec( f );
			var fecha = dm ? dm[ 3 ] + '-' + ( '0' + dm[ 2 ] ).slice( -2 ) + '-' + ( '0' + dm[ 1 ] ).slice( -2 ) : ( iso ? iso[ 0 ] : '' );
			filas.push( {
				rut: cRut >= 0 ? String( c[ cRut ] || '' ).trim() : '',
				nombre: cNom >= 0 ? String( c[ cNom ] || '' ).trim() : '',
				doc: TIPO_DTE[ tipo ] || tipo,
				folio: folio,
				fecha: fecha,
				monto: String( c[ cTot ] == null ? '0' : c[ cTot ] ).replace( /[^\d-]/g, '' ),
				estado: cEst >= 0 ? String( c[ cEst ] || '' ).trim() : ''
			} );
		} );
		return { lado: lado, filas: filas };
	}

	// TXT o CSV: tabulación, punto y coma, barra o coma.
	function leerTexto( texto ) {
		var ls = texto.replace( /^﻿/, '' ).split( /\r?\n/ ).filter( function ( l ) {
			return l.trim();
		} );
		var muestra = ls.slice( 0, 15 ).join( '\n' );
		var sep = [ '\t', ';', '|', ',' ].map( function ( x ) {
			return [ x, muestra.split( x ).length ];
		} ).sort( function ( a, b ) {
			return b[ 1 ] - a[ 1 ];
		} )[ 0 ][ 0 ];
		return deTabla( ls.map( function ( l ) {
			return l.split( sep ).map( function ( x ) {
				return x.trim().replace( /^"|"$/g, '' );
			} );
		} ) );
	}

	function rutLlave( r ) {
		return String( r || '' ).replace( /[^0-9kK]/g, '' ).toUpperCase();
	}

	/*
	 * La descarga de documentos del SII (DTE_DOWN…, en .xls o .txt): una
	 * tabla HTML donde cada documento trae su fila de títulos (TipoDTE,
	 * Folio, RutEmisor…), su fila de datos y debajo el detalle de ítems.
	 * De qué lado es se sabe por el RUT propio: si emite ella, es venta.
	 */
	function deDTE( tabla, archivo ) {
		var docs = [];
		var cab = null;
		tabla.forEach( function ( c ) {
			if ( 'tipodte' === sinTilde( c[ 0 ] ) ) {
				cab = c.map( sinTilde );
				return;
			}
			if ( ! cab || ! /^\d+$/.test( String( c[ 0 ] || '' ).trim() ) ) {
				return;
			}
			var v = {};
			var vistos = {};
			cab.forEach( function ( k, i ) {
				// Dirección, comuna y ciudad vienen dos veces: emisor y receptor.
				var lado = vistos.rutreceptor ? 'r' : 'e';
				vistos[ k ] = 1;
				v[ /^(direccion|comuna|ciudad)$/.test( k ) ? k + '_' + lado : k ] = String( c[ i ] == null ? '' : c[ i ] ).trim();
			} );
			docs.push( v );
			cab = null;
		} );
		if ( ! docs.length ) {
			return null;
		}
		// El nombre de la descarga trae el RUT de quien la bajó: DTE_DOWN76293473….
		var mio = '';
		var delNombre = /DTE_DOWN(\d{7,8})/i.exec( archivo || '' );
		if ( delNombre ) {
			docs.some( function ( d ) {
				[ d.rutemisor, d.rutreceptor ].some( function ( r ) {
					if ( rutLlave( r ).slice( 0, -1 ) === delNombre[ 1 ] ) {
						mio = rutLlave( r );
					}
					return mio;
				} );
				return mio;
			} );
		}
		mio = mio || rutLlave( CFG.miRut );
		if ( ! mio || ! docs.some( function ( d ) {
			return rutLlave( d.rutemisor ) === mio || rutLlave( d.rutreceptor ) === mio;
		} ) ) {
			// Sin RUT en Configuración: el que más se repite como emisor o receptor es el propio.
			var n = {};
			docs.forEach( function ( d ) {
				n[ rutLlave( d.rutemisor ) ] = ( n[ rutLlave( d.rutemisor ) ] || 0 ) + 1;
				n[ rutLlave( d.rutreceptor ) ] = ( n[ rutLlave( d.rutreceptor ) ] || 0 ) + 1;
			} );
			mio = Object.keys( n ).sort( function ( a, b ) {
				return n[ b ] - n[ a ];
			} )[ 0 ];
		}
		var porLado = { emitidos: [], recibidos: [] };
		docs.forEach( function ( d ) {
			var emito = rutLlave( d.rutemisor ) === mio;
			var o = emito ? 'r' : 'e';
			( emito ? porLado.emitidos : porLado.recibidos ).push( {
				rut: emito ? d.rutreceptor : d.rutemisor,
				nombre: emito ? d.razonsocialreceptor : d.razonsocialemisor,
				doc: TIPO_DTE[ d.tipodte ] || d.tipodte,
				folio: String( d.folio || '' ).replace( /\D/g, '' ),
				fecha: String( d.fechaemision || '' ).slice( 0, 10 ),
				monto: String( d[ 'total-montototal' ] || '0' ).replace( /[^\d-]/g, '' ),
				neto: String( d[ 'total-neto' ] || '' ).replace( /[^\d-]/g, '' ),
				exento: String( d[ 'total-exento' ] || '' ).replace( /[^\d-]/g, '' ),
				giro: emito ? d.giroreceptor : d.giroemisor,
				dir: d[ 'direccion_' + o ] || '',
				comuna: d[ 'comuna_' + o ] || '',
				ciudad: d[ 'ciudad_' + o ] || '',
				estado: 'Descarga de documentos del SII'
			} );
		} );
		return [ { lado: 'emitidos', filas: porLado.emitidos }, { lado: 'recibidos', filas: porLado.recibidos } ].filter( function ( l ) {
			return l.filas.length;
		} );
	}

	// Un «Excel» del SII que por dentro es una tabla HTML.
	function leerHtml( texto, archivo ) {
		var doc = new DOMParser().parseFromString( texto, 'text/html' );
		var mejor = null;
		var dte = null;
		Array.prototype.forEach.call( doc.querySelectorAll( 'table' ), function ( t ) {
			var tabla = Array.prototype.map.call( t.querySelectorAll( 'tr' ), function ( tr ) {
				return Array.prototype.map.call( tr.querySelectorAll( 'th,td' ), function ( c ) {
					return c.textContent.replace( /\s+/g, ' ' ).trim();
				} );
			} );
			if ( ! dte && tabla.some( function ( f ) {
				return 'tipodte' === sinTilde( f[ 0 ] );
			} ) ) {
				dte = deDTE( tabla, archivo );
				return;
			}
			var r = deTabla( tabla );
			if ( r && ( ! mejor || r.filas.length > mejor.filas.length ) ) {
				mejor = r;
			}
		} );
		if ( dte && dte.length ) {
			return dte;
		}
		if ( mejor && ! mejor.lado ) {
			mejor.lado = /recibid/i.test( doc.body.textContent.slice( 0, 400 ) ) ? 'recibidos' : ( /emitid/i.test( doc.body.textContent.slice( 0, 400 ) ) ? 'emitidos' : '' );
		}
		return mejor;
	}

	function decodificar( buf ) {
		try {
			return new TextDecoder( 'utf-8', { fatal: true } ).decode( buf );
		} catch ( x ) {
			return new TextDecoder( 'windows-1252' ).decode( buf );
		}
	}

	/*
	 * El libro de compras o de ventas que prepara la contadora (Nubox y
	 * similares). Además de los documentos dice en qué mes se declara cada
	 * uno, que no siempre es el de su fecha: eso es lo que cuadra el F29.
	 */
	var TIPO_LIBRO = {
		'FAC-EL': 'Factura Electronica',
		'FAC-EE': 'Factura Exenta Electronica',
		'N/C-EL': 'Nota de Credito Electronica',
		'N/D-EL': 'Nota de Debito Electronica',
		'FCO-EL': 'Factura de Compra Electronica',
		'FC-EL': 'Factura de Compra Electronica',
		'BOL-EL': 'Boleta Electronica',
		'BOL-EE': 'Boleta Exenta Electronica'
	};
	function plataLibro( t ) {
		return parseInt( String( t ).replace( /[^\d]/g, '' ), 10 ) || 0;
	}
	function leerLibro( ls ) {
		var todo = ls.join( '\n' );
		var cab = /LIBRO DE (COMPRAS|VENTAS)\s+DESDE\s+(\d{2})-(\d{2})-(\d{4})/i.exec( todo );
		var periodo = cab[ 4 ] + '-' + cab[ 3 ];
		var lado = /COMPRAS/i.test( cab[ 1 ] ) ? 'recibidos' : 'emitidos';
		var filas = [];
		ls.forEach( function ( l ) {
			l = l.replace( /\s+/g, ' ' ).trim();
			var m = /^(\d{2})-(\d{2})-(\d{4}) ([A-Z]{1,4}\/?[A-Z]{0,2}-E[LE]) (\d+) (\d{1,2}\.\d{3}\.\d{3}-[\dkK]) (.+)$/.exec( l );
			if ( ! m ) {
				return;
			}
			var resto = m[ 7 ];
			var montos = [];
			var t;
			var re = /\s(\(?\d{1,3}(?:\.\d{3})*\)?)$/;
			while ( ( t = re.exec( ' ' + resto ) ) ) {
				montos.unshift( t[ 1 ] );
				resto = resto.slice( 0, resto.length - t[ 1 ].length ).trim();
			}
			if ( ! montos.length ) {
				return;
			}
			var tipo = TIPO_LIBRO[ m[ 4 ] ] || m[ 4 ];
			var afecta = ! /Exenta/i.test( tipo ) && montos.length >= 3;
			filas.push( {
				rut: m[ 6 ],
				nombre: resto,
				doc: tipo,
				folio: m[ 5 ],
				fecha: m[ 3 ] + '-' + m[ 2 ] + '-' + m[ 1 ],
				monto: String( plataLibro( montos[ montos.length - 1 ] ) ),
				neto: afecta ? String( plataLibro( montos[ montos.length - 3 ] ) ) : '',
				exento: afecta ? ( montos.length >= 4 ? String( plataLibro( montos[ 0 ] ) ) : '' ) : String( plataLibro( montos[ 0 ] ) ),
				periodo: periodo,
				estado: 'Libro de ' + ( 'recibidos' === lado ? 'compras' : 'ventas' ) + ' de la contadora'
			} );
		} );
		return { lado: lado, filas: filas };
	}

	// El borrador del F29: los códigos que importan para el IVA y el PPM.
	var CODIGOS_F29 = [ 538, 537, 504, 77, 89, 62, 595, 91, 115, 756, 755, 563, 750, 30, 68, 502, 503, 511, 514, 519, 520, 527, 528, 564, 521, 562, 584 ];
	function leerF29( texto ) {
		var t = texto.replace( /[ \t]+/g, ' ' );
		var per = /Periodo Tributario[\s\S]{0,200}?\b(0[1-9]|1[0-2])\s+(20\d{2})\b/i.exec( t );
		if ( ! per ) {
			return null;
		}
		function cod( c ) {
			var re = new RegExp( '(?:^|\\s)' + c + '\\s+(\\d{1,3}(?:\\.\\d{3})+|\\d{1,3})(?=\\s|$)', 'gm' );
			var m;
			while ( ( m = re.exec( t ) ) ) {
				var v = parseInt( m[ 1 ].replace( /\./g, '' ), 10 );
				if ( m[ 1 ].indexOf( '.' ) < 0 && CODIGOS_F29.indexOf( v ) >= 0 ) {
					continue;
				}
				return v;
			}
			return 0;
		}
		var tasa = /(?:^|\s)115\s+(\d+(?:,\d+)?)/m.exec( t );
		var iva = cod( 89 );
		var ppm = cod( 62 );
		var total = cod( 595 ) || cod( 91 );
		return {
			mes: per[ 2 ] + '-' + per[ 1 ],
			debito: cod( 538 ),
			credito: cod( 537 ),
			rem_ant: cod( 504 ),
			rem_sig: cod( 77 ),
			iva: iva,
			ppm: ppm,
			tasa: tasa ? parseFloat( tasa[ 1 ].replace( ',', '.' ) ) : 0,
			total: total || ( iva + ppm )
		};
	}

	/*
	 * El F29 compacto del SII: renglones «código GLOSA valor», a veces dos
	 * por línea. El valor es el primer número después de la glosa; lo que
	 * sigue, si es otro código con su glosa, es el par siguiente.
	 */
	function leerF29Compacto( ls ) {
		var todo = ls.join( '\n' );
		var per = /PERIODO\s*\[15\]\s*(\d{4})(\d{2})/i.exec( todo );
		if ( ! per ) {
			return null;
		}
		var esNum = function ( t ) {
			return /^-?\d{1,3}(\.\d{3})+$|^-?\d+([.,]\d+)?$/.test( t || '' );
		};
		var esLetra = function ( t ) {
			return /^[A-Za-zÁÉÍÓÚÑáéíóúñ(]/.test( t || '' );
		};
		var v = {};
		ls.forEach( function ( l ) {
			var t = l.replace( /\s+/g, ' ' ).trim().split( ' ' );
			for ( var i = 0; i < t.length - 1; i++ ) {
				if ( ! /^\d{2,4}$/.test( t[ i ] ) || ! esLetra( t[ i + 1 ] ) ) {
					continue;
				}
				var j = i + 2;
				while ( j < t.length && ! ( esNum( t[ j ] ) && ( j === t.length - 1 || ! esLetra( t[ j + 1 ] ) || ( /^\d{2,4}$/.test( t[ j + 1 ] ) ) ) ) ) {
					j++;
				}
				// Un número seguido de otro «código glosa» o del fin de línea es el valor.
				if ( j < t.length && esNum( t[ j ] ) ) {
					var cod = parseInt( t[ i ], 10 );
					if ( ! ( cod in v ) ) {
						v[ cod ] = t[ j ];
					}
					i = j;
				}
			}
			// «TOTAL A PAGAR DENTRO DEL PLAZO LEGAL 91 128.395 +»
			var tp = /\b91\s+(\d{1,3}(?:\.\d{3})*)\b/.exec( l );
			if ( tp && /TOTAL A PAGAR DENTRO/i.test( l ) ) {
				v[ 91 ] = tp[ 1 ];
			}
		} );
		var n = function ( c ) {
			return c in v ? parseInt( String( v[ c ] ).replace( /[.,]/g, '' ), 10 ) || 0 : 0;
		};
		var tasa = 115 in v ? parseFloat( String( v[ 115 ] ).replace( ',', '.' ) ) || 0 : 0;
		var iva = n( 89 );
		var ppm = n( 62 );
		return {
			mes: per[ 1 ] + '-' + per[ 2 ],
			debito: n( 538 ),
			credito: n( 537 ),
			rem_ant: n( 504 ),
			rem_sig: n( 77 ),
			iva: iva,
			ppm: ppm,
			tasa: tasa,
			total: n( 595 ) || n( 547 ) || n( 91 ) || ( iva + ppm )
		};
	}

	function leerArchivo( f ) {
		var nombre = f.name;
		var esPdf = /\.pdf$/i.test( nombre ) || /pdf$/i.test( f.type );
		if ( ! esPdf ) {
			return f.arrayBuffer().then( function ( buf ) {
				var b = new Uint8Array( buf.slice( 0, 4 ) );
				// D0 CF 11 E0 = Excel antiguo de verdad; 50 4B = xlsx (zip).
				if ( ( 0xD0 === b[ 0 ] && 0xCF === b[ 1 ] ) || ( 0x50 === b[ 0 ] && 0x4B === b[ 1 ] ) ) {
					throw new Error( '«' + nombre + '» es un Excel que no puedo leer acá. Descárgalo en TXT desde el SII, o ábrelo y guárdalo como CSV.' );
				}
				var t = decodificar( buf );
				var l = /<table|<html/i.test( t.slice( 0, 4000 ) ) ? leerHtml( t, nombre ) : leerTexto( t );
				if ( Array.isArray( l ) ) {
					if ( ! l.length ) {
						throw new Error( 'No encontré documentos en «' + nombre + '».' );
					}
					return l;
				}
				if ( ! l || ! l.filas.length ) {
					throw new Error( 'No encontré documentos en «' + nombre + '». Tiene que ser el listado del SII (columnas Folio, Fecha y Monto).' );
				}
				if ( ! l.lado ) {
					l.lado = /recib|compra/i.test( nombre ) ? 'recibidos' : 'emitidos';
				}
				return l;
			} );
		}
		return f.arrayBuffer().then( lineas ).then( function ( ls ) {
			var todo = ls.join( '\n' );
			if ( /LIBRO DE (COMPRAS|VENTAS)\s+DESDE/i.test( todo ) ) {
				var lib = leerLibro( ls );
				if ( ! lib.filas.length ) {
					throw new Error( 'No encontré documentos en el libro «' + nombre + '».' );
				}
				return lib;
			}
			// El F29 ya presentado, como lo baja el SII («formulario compacto»).
			if ( /FORMULARIO 29/i.test( todo ) && /PERIODO\s*\[15\]\s*\d{6}/i.test( todo ) ) {
				var fc = leerF29Compacto( ls );
				if ( ! fc ) {
					throw new Error( 'No pude leer el F29 «' + nombre + '».' );
				}
				return { f29: fc };
			}
			if ( /Periodo Tributario/i.test( todo ) && /TOTAL D[ÉE]BITOS/i.test( todo ) ) {
				var f29 = leerF29( todo );
				if ( ! f29 ) {
					throw new Error( 'No pude leer el F29 «' + nombre + '».' );
				}
				return { f29: f29 };
			}
			var l = leer( ls );
			if ( ! l.filas.length ) {
				throw new Error( 'No encontré documentos en «' + nombre + '». Tiene que ser el listado de Administración de documentos del SII.' );
			}
			if ( ! l.lado ) {
				l.lado = 'emitidos';
			}
			return l;
		} );
	}

	function recibir( files ) {
		files = Array.prototype.filter.call( files || [], function ( f ) {
			return /pdf$|csv|text\/plain|excel|spreadsheet|html/i.test( f.type ) || /\.(pdf|csv|txt|tsv|xls|xlsx|html?)$/i.test( f.name );
		} );
		if ( ! files.length ) {
			return;
		}
		aviso( 'Leyendo ' + ( 1 === files.length ? 'el archivo' : 'los ' + files.length + ' archivos' ) + '…' );
		Promise.all( files.map( leerArchivo ) ).then( function ( ls ) {
			// Varios archivos del mismo lado (una página o un mes cada uno) se suman.
			var tocados = {};
			ls = [].concat.apply( [], ls );
			ls = ls.filter( function ( l ) {
				if ( l.f29 ) {
					f29s = f29s.filter( function ( x ) {
						return x.mes !== l.f29.mes;
					} ).concat( [ l.f29 ] );
					return false;
				}
				return true;
			} );
			ls.forEach( function ( l ) {
				var lote = lotes.filter( function ( x ) {
					return x.lado === l.lado;
				} )[ 0 ];
				if ( ! lote ) {
					lote = { lado: l.lado, filas: [], vista: [] };
					lotes.push( lote );
				}
				lote.filas = lote.filas.concat( l.filas );
				tocados[ l.lado ] = lote;
			} );
			aviso( 'Revisando qué ya está anotado…' );
			return Promise.all( Object.keys( tocados ).map( function ( k ) {
				return pedir( tocados[ k ], true ).then( function ( data ) {
					tocados[ k ].vista = data.filas;
				} );
			} ) );
		} ).then( function () {
			aviso( '' );
			pintar();
		} ).catch( function ( e ) {
			aviso( e && e.message && 'error' !== e.message && 'sin pdfjs' !== e.message ? e.message : 'No se pudo leer el archivo.', true );
		} );
	}

	input.addEventListener( 'change', function () {
		recibir( input.files );
		input.value = '';
	} );
	[ 'dragenter', 'dragover' ].forEach( function ( ev ) {
		drop.addEventListener( ev, function ( e ) {
			e.preventDefault();
			drop.classList.add( 'is-sobre' );
		} );
	} );
	[ 'dragleave', 'drop' ].forEach( function ( ev ) {
		drop.addEventListener( ev, function () {
			drop.classList.remove( 'is-sobre' );
		} );
	} );
	drop.addEventListener( 'drop', function ( e ) {
		e.preventDefault();
		recibir( e.dataTransfer.files );
	} );

	// De a 80 por vez, para que cientos de documentos no corten el servidor.
	function importarLote( lote, elegidas, hechos ) {
		if ( ! elegidas.length ) {
			return pedir( lote, true ).then( function ( d ) {
				return { hechos: hechos, filas: d.filas };
			} );
		}
		var trozo = elegidas.slice( 0, 80 );
		return pedir( lote, false, trozo ).then( function ( d ) {
			hechos += d.hechos;
			aviso( 'Importando… ' + hechos + ' listos' );
			return importarLote( lote, elegidas.slice( 80 ), hechos );
		} );
	}

	ok.addEventListener( 'click', function () {
		ok.disabled = true;
		aviso( 'Importando…' );
		var total = 0;
		var marcados = Array.prototype.map.call( res.querySelectorAll( 'input[data-f29]:checked' ), function ( c ) {
			return f29s[ parseInt( c.getAttribute( 'data-f29' ), 10 ) ];
		} );
		var inicio = Promise.resolve();
		if ( marcados.length ) {
			var fd = new FormData();
			fd.append( 'action', 'malka_crm_f29_guardar' );
			fd.append( 'nonce', dlg.getAttribute( 'data-nonce' ) );
			fd.append( 'f29', JSON.stringify( marcados ) );
			inicio = fetch( dlg.getAttribute( 'data-ajax' ), { method: 'POST', credentials: 'same-origin', body: fd } ).then( function ( r ) {
				return r.json();
			} ).then( function ( j ) {
				if ( j && j.success ) {
					total += j.data.guardados;
					f29s = [];
				}
			} );
		}
		lotes.reduce( function ( cadena, lote, li ) {
			return cadena.then( function () {
				var el = Array.prototype.map.call( res.querySelectorAll( 'input[data-lote="' + li + '"]:checked' ), function ( c ) {
					return parseInt( c.value, 10 );
				} );
				if ( ! el.length ) {
					return null;
				}
				return importarLote( lote, el, 0 ).then( function ( r ) {
					total += r.hechos;
					lote.vista = r.filas;
				} );
			} );
		}, inicio ).then( function () {
			hubo = hubo || total > 0;
			pintar();
			aviso( total ? 'Listo: ' + total + ( 1 === total ? ' documento importado.' : ' documentos importados.' ) + ' Al cerrar se actualiza la lista.' : 'No se importó nada.' );
		} ).catch( function () {
			hubo = true;
			aviso( 'Se cortó a la mitad. Lo importado quedó guardado: vuelve a soltar los archivos y sigue con lo que falta.', true );
			ok.disabled = false;
		} );
	} );

	abrir.addEventListener( 'click', function () {
		if ( dlg.showModal ) {
			dlg.showModal();
		} else {
			dlg.setAttribute( 'open', '' );
		}
	} );
	dlg.addEventListener( 'close', function () {
		if ( hubo ) {
			window.location.reload();
		}
	} );
	dlg.querySelector( '[data-imp-cerrar]' ).addEventListener( 'click', function () {
		dlg.close();
	} );
	dlg.addEventListener( 'click', function ( e ) {
		if ( e.target === dlg ) {
			dlg.close();
		}
	} );
}() );

/* ── Gráficos con período: 12 meses · Año · Años · Días ── */
( function () {
	'use strict';

	function ver( panel, clave ) {
		var hay = panel.querySelector( '.esc-gv[data-vista="' + clave + '"]' );
		if ( ! hay ) {
			clave = 'anio-' + new Date().getFullYear();
		}
		panel.querySelectorAll( '.esc-gv' ).forEach( function ( v ) {
			v.hidden = v.getAttribute( 'data-vista' ) !== clave;
		} );
		var grupo = 0 === clave.indexOf( 'anio-' ) ? 'anio' : clave;
		panel.querySelectorAll( '.esc-gp__b' ).forEach( function ( b ) {
			b.classList.toggle( 'is-on', b.getAttribute( 'data-gper' ) === grupo );
		} );
		var sel = panel.querySelector( '.esc-gp__anio select' );
		if ( sel && 'anio' === grupo ) {
			sel.value = clave;
		}
		try {
			window.localStorage.setItem( 'malka-graf-' + panel.getAttribute( 'data-graf' ), clave );
		} catch ( x ) {}
	}

	document.querySelectorAll( '.esc-gp' ).forEach( function ( panel ) {
		var guardado = null;
		try {
			guardado = window.localStorage.getItem( 'malka-graf-' + panel.getAttribute( 'data-graf' ) );
		} catch ( x ) {}
		if ( guardado ) {
			ver( panel, guardado );
		}
		panel.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( 'button.esc-gp__b' );
			if ( b ) {
				e.stopPropagation();
				ver( panel, b.getAttribute( 'data-gper' ) );
			}
		} );
		var sel = panel.querySelector( '.esc-gp__anio select' );
		if ( sel ) {
			sel.addEventListener( 'change', function () {
				ver( panel, sel.value );
			} );
			// Tocar «Año» con el año ya elegido también cambia la vista.
			sel.addEventListener( 'click', function () {
				if ( ! sel.closest( '.esc-gp__b' ).classList.contains( 'is-on' ) ) {
					ver( panel, sel.value );
				}
			} );
		}
	} );
}() );

/* ── Tareas: cada cuánto se repiten (se guarda solo) ── */
( function () {
	'use strict';

	var form = document.querySelector( 'form.esc-tareas[data-ajax]' );
	if ( ! form ) {
		return;
	}
	form.addEventListener( 'click', function ( e ) {
		// Abrir el selector no marca la tarea.
		if ( e.target.closest( '.esc-pend__r' ) ) {
			e.stopPropagation();
		}
	} );
	// El botón de guardar sólo hace falta sin javascript. Con él, estorba.
	var pie = form.querySelector( '[data-marcar-pie]' );
	if ( pie ) {
		pie.remove();
	}

	/**
	 * El aviso de abajo. Con `accion` lleva además un botón —«Deshacer»— y
	 * se queda más rato en pantalla: si la fila ya no está, el aviso es el
	 * único camino de vuelta y 2,4 segundos no alcanzan para leerlo y
	 * apuntarle.
	 *
	 * @param {string}   texto  Lo que dice.
	 * @param {Object=}  accion { texto, al } del botón, si lo lleva.
	 */
	function avisar( texto, accion ) {
		var t = document.getElementById( 'esc-toast' );
		if ( ! t ) {
			return;
		}

		t.textContent = texto;
		window.clearTimeout( avisar.t );

		if ( accion && accion.al ) {
			var b = document.createElement( 'button' );
			b.type        = 'button';
			b.className   = 'esc-toast__b';
			b.textContent = accion.texto || 'Deshacer';
			b.addEventListener( 'click', function () {
				window.clearTimeout( avisar.t );
				t.classList.remove( 'show' );
				accion.al();
			} );
			t.appendChild( b );
		}

		t.classList.add( 'show' );
		avisar.t = window.setTimeout( function () {
			t.classList.remove( 'show' );
		}, accion ? 7000 : 2400 );
	}

	/**
	 * Saca una tarea de «Lo que falta» y devuelve cómo volver a ponerla.
	 *
	 * Se lleva también el encabezado del negocio cuando era su última tarea:
	 * un título con nada debajo parece una lista que no cargó.
	 *
	 * @param {Element} fila La fila a archivar.
	 * @return {Function} Llamarla reinserta todo donde estaba.
	 */
	function archivar( fila ) {
		var sig = fila.nextElementSibling;
		var hay = sig && sig.classList && sig.classList.contains( 'esc-pend' );

		var cab = fila.previousElementSibling;
		while ( cab && cab.classList && ! cab.classList.contains( 'esc-pend' ) && ! cab.classList.contains( 'esc-tareas__p' ) ) {
			cab = cab.previousElementSibling;
		}
		if ( ! cab || ! cab.classList || ! cab.classList.contains( 'esc-tareas__p' ) || hay ) {
			cab = null;
		}

		// De dónde colgaba, para poder devolverla al mismo lugar.
		var padre = fila.parentNode;
		var ancla = ( cab || fila ).previousElementSibling;
		var vacio = document.querySelector( '[data-falta-vacio]' );

		( cab ? [ cab, fila ] : [ fila ] ).forEach( function ( n ) {
			n.classList.add( 'is-yendose' );
		} );

		window.setTimeout( function () {
			if ( cab ) {
				cab.remove();
			}
			fila.remove();
			if ( vacio && ! padre.querySelector( '.esc-pend' ) ) {
				vacio.hidden = false;
			}
		}, 260 );

		return function () {
			[ fila, cab ].forEach( function ( n ) {
				if ( ! n ) {
					return;
				}
				n.classList.remove( 'is-yendose' );
				if ( ancla && ancla.parentNode === padre ) {
					ancla.insertAdjacentElement( 'afterend', n );
				} else {
					padre.insertBefore( n, padre.firstChild );
				}
				ancla = n;
			} );
			if ( vacio ) {
				vacio.hidden = true;
			}
		};
	}

	// Al destildar sola una que se repite no hay que volver a guardar.
	var callado = false;

	form.addEventListener( 'change', function ( e ) {
		var chk = e.target.matches && e.target.matches( '.esc-pend input[name="listo[]"]' ) ? e.target : null;
		if ( chk ) {
			if ( callado ) {
				return;
			}

			var fila = chk.closest( '.esc-pend' );
			var dia  = fila.querySelector( '.esc-pend__d' );
			var era  = ! chk.checked;

			fila.setAttribute( 'aria-busy', 'true' );

			var fdm = new FormData();
			fdm.append( 'action', 'malka_crm_tarea_marcar' );
			fdm.append( 'nonce', form.getAttribute( 'data-nonce' ) );
			fdm.append( 'pid', fila.getAttribute( 'data-pid' ) || '0' );
			fdm.append( 'tid', fila.getAttribute( 'data-tid' ) || '' );
			fdm.append( 'listo', chk.checked ? '1' : '' );

			fetch( form.getAttribute( 'data-ajax' ), {
				method: 'POST',
				credentials: 'same-origin',
				body: fdm
			} ).then( function ( r ) {
				return r.json();
			} ).then( function ( j ) {
				fila.removeAttribute( 'aria-busy' );

				if ( ! j || ! j.success ) {
					throw new Error( 'no' );
				}

				// Los contadores del filtro, al día.
				if ( j.data && j.data.cuenta ) {
					Object.keys( j.data.cuenta ).forEach( function ( k ) {
						var n = document.querySelector( '[data-falta-n="' + k + '"]' );
						if ( n ) {
							n.textContent = j.data.cuenta[ k ];
							n.hidden = ! j.data.cuenta[ k ];
						}
					} );
				}

				if ( j.data && j.data.repite && j.data.listo ) {
					// No se terminó: saltó a su próxima fecha y sigue ahí.
					if ( dia && j.data.vuelve ) {
						dia.textContent = j.data.vuelve;
						dia.classList.remove( 'is-alert' );
					}
					window.setTimeout( function () {
						callado = true;
						chk.checked = false;
						callado = false;
					}, 1200 );
					avisar( 'Listo. Vuelve en su próxima fecha.' );
					return;
				}

				var listo = !! ( j.data && j.data.listo );
				fila.classList.toggle( 'is-lista', listo );

				if ( ! listo ) {
					avisar( 'Vuelve a estar pendiente.' );
					return;
				}

				/*
				 * Hecha es hecha: se archiva y deja de ocupar lugar en «Lo
				 * que falta». Antes se quedaba tachada hasta recargar, y una
				 * lista de pendientes llena de cosas ya hechas deja de servir
				 * para lo único que sirve, que es mirarla y saber qué queda.
				 * El tachado dura un momento para que se vea qué pasó, y el
				 * aviso de abajo trae la vuelta por si fue sin querer.
				 */
				window.setTimeout( function () {
					var volver = archivar( fila );
					avisar( 'Hecha y archivada.', {
						texto: 'Deshacer',
						al: function () {
							volver();
							chk.checked = false;
							// Se destilda de verdad: el change vuelve a guardar.
							chk.dispatchEvent( new Event( 'change', { bubbles: true } ) );
						}
					} );
				}, 650 );
			} ).catch( function () {
				fila.removeAttribute( 'aria-busy' );
				callado = true;
				chk.checked = era;
				callado = false;
				avisar( 'No se pudo guardar. Revisa tu conexión.' );
			} );

			return;
		}
		var s = e.target.closest ? e.target.closest( 'select[data-repite]' ) : null;
		if ( ! s ) {
			return;
		}
		var fd = new FormData();
		fd.append( 'action', 'malka_crm_tarea_repite' );
		fd.append( 'nonce', form.getAttribute( 'data-nonce' ) );
		fd.append( 'pid', s.getAttribute( 'data-pid' ) );
		fd.append( 'tid', s.getAttribute( 'data-tid' ) );
		fd.append( 'repite', s.value );
		s.closest( '.esc-pend__r' ).classList.toggle( 'is-on', !! s.value );
		fetch( form.getAttribute( 'data-ajax' ), { method: 'POST', credentials: 'same-origin', body: fd } );
	} );
}() );

/* ── Modo enfoque: anotar lo que se viene a la cabeza ── */
( function () {
	'use strict';

	var der = document.querySelector( '.esc-focus__der' );
	if ( ! der ) {
		return;
	}
	var form = document.getElementById( 'esc-f-add' );
	var input = document.getElementById( 'esc-f-tarea' );
	var lista = document.getElementById( 'esc-f-lista' );
	var aviso = document.getElementById( 'esc-f-a' );

	function post( datos ) {
		var fd = new FormData();
		fd.append( 'nonce', der.getAttribute( 'data-nonce' ) );
		Object.keys( datos ).forEach( function ( k ) {
			fd.append( k, datos[ k ] );
		} );
		return fetch( der.getAttribute( 'data-ajax' ), { method: 'POST', credentials: 'same-origin', body: fd } ).then( function ( r ) {
			return r.json();
		} );
	}
	function proyecto() {
		var para = document.getElementById( 'esc-f-para' );
		if ( para && 'auto' !== para.value ) {
			return parseInt( para.value, 10 ) || 0;
		}
		var sel = document.getElementById( 'esc-pom-proj' );
		return sel ? ( parseInt( sel.value, 10 ) || 0 ) : 0;
	}
	function fila( nombre, pid, tid, donde ) {
		var li = document.createElement( 'li' );
		li.className = 'is-nueva';
		var c = document.createElement( 'input' );
		c.type = 'checkbox';
		c.id = 'esc-f-' + tid;
		c.setAttribute( 'data-pid', pid );
		c.setAttribute( 'data-tid', tid );
		var caja = document.createElement( 'div' );
		caja.className = 'esc-focus__t';
		var lab = document.createElement( 'label' );
		lab.htmlFor = c.id;
		lab.textContent = nombre;
		var as = document.createElement( 'select' );
		as.className = 'esc-focus__as';
		as.setAttribute( 'data-buscable', '' );
		as.setAttribute( 'aria-label', 'Asignar a' );
		var tpl = document.getElementById( 'esc-f-ops' );
		as.innerHTML = tpl ? tpl.innerHTML : '';
		as.value = String( pid );
		caja.appendChild( lab );
		caja.appendChild( as );
		li.appendChild( c );
		li.appendChild( caja );
		lista.insertBefore( li, lista.firstChild );
	}

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		var nombre = input.value.trim();
		if ( ! nombre ) {
			return;
		}
		input.value = '';
		input.focus();
		post( { action: 'malka_crm_tarea_rapida', nombre: nombre, pid: proyecto() } ).then( function ( j ) {
			if ( j && j.success ) {
				fila( nombre, j.data.pid, j.data.id, j.data.donde );
				aviso.hidden = false;
				aviso.textContent = 'Anotada · ' + j.data.donde;
				window.clearTimeout( aviso.t );
				aviso.t = window.setTimeout( function () {
					aviso.hidden = true;
				}, 3000 );
			} else {
				input.value = nombre;
				aviso.textContent = ( j && j.data && j.data.msg ) || 'No se pudo anotar.';
			}
		} ).catch( function () {
			input.value = nombre;
			aviso.textContent = 'No se pudo anotar.';
		} );
	} );

	// Marcarla hecha desde acá mismo, o asignarla a otro negocio.
	lista.addEventListener( 'change', function ( e ) {
		var c = e.target;
		if ( c.matches( 'select.esc-focus__as' ) ) {
			var chk = c.closest( 'li' ).querySelector( 'input[type=checkbox]' );
			var de = chk.getAttribute( 'data-pid' );
			post( { action: 'malka_crm_tarea_mover', pid: de, a: c.value, tid: chk.getAttribute( 'data-tid' ) } ).then( function ( j ) {
				if ( j && j.success ) {
					chk.setAttribute( 'data-pid', String( j.data.pid ) );
					aviso.hidden = false;
					aviso.textContent = 'Asignada a ' + c.options[ c.selectedIndex ].text;
					window.clearTimeout( aviso.t );
					aviso.t = window.setTimeout( function () {
						aviso.hidden = true;
					}, 3000 );
				} else {
					c.value = de;
					aviso.textContent = 'No se pudo asignar.';
				}
			} );
			return;
		}
		if ( ! c.matches( 'input[type=checkbox]' ) || ! c.checked ) {
			return;
		}
		var li = c.closest( 'li' );
		li.classList.add( 'is-hecha' );
		post( { action: 'malka_crm_tarea_lista', pid: c.getAttribute( 'data-pid' ), tid: c.getAttribute( 'data-tid' ) } ).then( function ( j ) {
			if ( j && j.success ) {
				window.setTimeout( function () {
					li.remove();
				}, 700 );
			} else {
				c.checked = false;
				li.classList.remove( 'is-hecha' );
			}
		} );
	} );
}() );


/* ── Selector con buscador: cualquier <select data-buscable> ──
   Al abrirlo aparece un campo para escribir parte del nombre (negocio o
   cliente) y la lista se filtra. El <select> sigue siendo el que manda:
   al elegir se le pone el valor y se avisa el cambio como siempre. */
( function () {
	'use strict';

	var caja = null;
	var actual = null;

	function normal( t ) {
		return ( t || '' ).toLowerCase().normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' );
	}
	function cerrar() {
		if ( caja ) {
			caja.remove();
			caja = null;
			actual = null;
		}
	}
	function elegir( sel, v ) {
		sel.value = v;
		sel.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		cerrar();
		sel.focus();
	}
	function abrir( sel ) {
		cerrar();
		actual = sel;
		caja = document.createElement( 'div' );
		caja.className = 'esc-bsel';
		var q = document.createElement( 'input' );
		q.type = 'search';
		q.placeholder = 'Buscar…';
		q.autocomplete = 'off';
		var ul = document.createElement( 'ul' );
		caja.appendChild( q );
		caja.appendChild( ul );
		var activo = 0;
		function pintar() {
			var t = normal( q.value );
			ul.innerHTML = '';
			Array.prototype.forEach.call( sel.options, function ( o ) {
				if ( t && normal( o.textContent ).indexOf( t ) === -1 ) {
					return;
				}
				var li = document.createElement( 'li' );
				li.textContent = o.textContent;
				li.setAttribute( 'data-v', o.value );
				if ( o.value === sel.value ) {
					li.className = 'is-sel';
				}
				ul.appendChild( li );
			} );
			activo = 0;
			marcar();
		}
		function marcar() {
			Array.prototype.forEach.call( ul.children, function ( li, i ) {
				li.classList.toggle( 'is-act', i === activo );
			} );
		}
		q.addEventListener( 'input', pintar );
		q.addEventListener( 'keydown', function ( e ) {
			var n = ul.children.length;
			if ( 'ArrowDown' === e.key ) {
				e.preventDefault();
				activo = Math.min( n - 1, activo + 1 );
				marcar();
			} else if ( 'ArrowUp' === e.key ) {
				e.preventDefault();
				activo = Math.max( 0, activo - 1 );
				marcar();
			} else if ( 'Enter' === e.key ) {
				e.preventDefault();
				if ( ul.children[ activo ] ) {
					elegir( sel, ul.children[ activo ].getAttribute( 'data-v' ) );
				}
			} else if ( 'Escape' === e.key ) {
				cerrar();
				sel.focus();
			}
		} );
		ul.addEventListener( 'mousedown', function ( e ) {
			var li = e.target.closest( 'li' );
			if ( li ) {
				e.preventDefault();
				elegir( sel, li.getAttribute( 'data-v' ) );
			}
		} );
		var r = sel.getBoundingClientRect();
		caja.style.top = ( r.bottom + 4 ) + 'px';
		caja.style.left = Math.max( 8, Math.min( r.left, window.innerWidth - 280 ) ) + 'px';
		( sel.closest( '.malka-esc' ) || document.body ).appendChild( caja );
		pintar();
		q.focus();
	}

	document.addEventListener( 'mousedown', function ( e ) {
		var sel = e.target.closest ? e.target.closest( 'select[data-buscable]' ) : null;
		if ( sel ) {
			e.preventDefault();
			if ( actual === sel ) {
				cerrar();
			} else {
				abrir( sel );
			}
			return;
		}
		if ( caja && ! caja.contains( e.target ) ) {
			cerrar();
		}
	}, true );
	document.addEventListener( 'keydown', function ( e ) {
		var sel = e.target.matches && e.target.matches( 'select[data-buscable]' ) ? e.target : null;
		if ( sel && ( 'Enter' === e.key || ' ' === e.key || 'ArrowDown' === e.key ) ) {
			e.preventDefault();
			abrir( sel );
		}
	} );
	window.addEventListener( 'resize', cerrar );
}() );


/* ── Cartola del banco: arrastrar, revisar y registrar ──
   Se leen las filas con fecha y monto (PDF, Excel en tabla, CSV o TXT).
   Si hay columna de saldo, el signo sale de cómo cambia el saldo y el
   último saldo se propone como el saldo actual de la cuenta. */
( function () {
	'use strict';

	var caja = document.getElementById( 'esc-cart' );
	if ( ! caja ) {
		return;
	}
	var CFG = window.malkaEscritorio || {};
	var drop = caja.querySelector( '.esc-cart__drop' );
	var input = drop.querySelector( 'input' );
	var msg = caja.querySelector( '.esc-cart__msg' );
	var res = caja.querySelector( '.esc-cart__res' );
	var filas = [];
	var vista = [];
	var cats = {};
	var saldoFinal = null;

	function aviso( t, mal ) {
		msg.hidden = ! t;
		msg.textContent = t || '';
		msg.classList.toggle( 'is-mal', !! mal );
	}
	function plata( n ) {
		return '$' + String( Math.round( n ) ).replace( /\B(?=(\d{3})+(?!\d))/g, '.' );
	}
	function esc( t ) {
		return String( t == null ? '' : t ).replace( /[&<>"]/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ c ];
		} );
	}
	function sinTilde( t ) {
		return String( t || '' ).trim().toLowerCase().normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' );
	}
	// "1.234.567", "-1.234", "1234,50", "$ 12.000" → número.
	function num( t ) {
		t = String( t == null ? '' : t ).trim();
		if ( ! t ) {
			return null;
		}
		var neg = /^-|\(.*\)|-$/.test( t );
		t = t.replace( /[^\d,.]/g, '' );
		if ( ! t ) {
			return null;
		}
		if ( /,\d{1,2}$/.test( t ) ) {
			t = t.replace( /\./g, '' ).replace( ',', '.' );
		} else {
			t = t.replace( /[.,]/g, '' );
		}
		var v = parseFloat( t );
		return isNaN( v ) ? null : ( neg ? -v : v );
	}
	var ANIO = new Date().getFullYear();
	function fecha( t ) {
		var m = /(\d{1,2})[\/\-.](\d{1,2})(?:[\/\-.](\d{2,4}))?/.exec( t || '' );
		var iso = /(\d{4})-(\d{2})-(\d{2})/.exec( t || '' );
		if ( iso ) {
			return iso[ 0 ];
		}
		if ( ! m ) {
			return '';
		}
		var a = m[ 3 ] ? ( 2 === m[ 3 ].length ? '20' + m[ 3 ] : m[ 3 ] ) : String( ANIO );
		return a + '-' + ( '0' + m[ 2 ] ).slice( -2 ) + '-' + ( '0' + m[ 1 ] ).slice( -2 );
	}

	// Una tabla (filas de celdas) → movimientos.
	function deTabla( tabla ) {
		var h = -1;
		for ( var i = 0; i < Math.min( tabla.length, 60 ); i++ ) {
			var c = tabla[ i ].map( sinTilde );
			if ( c.some( function ( x ) {
				return /^fecha/.test( x );
			} ) && c.some( function ( x ) {
				return /cargo|abono|monto|debito|credito|giro|deposito|importe|egreso|ingreso/.test( x );
			} ) ) {
				h = i;
				break;
			}
		}
		if ( h < 0 ) {
			return null;
		}
		var cab = tabla[ h ].map( sinTilde );
		function col( re ) {
			for ( var k = 0; k < cab.length; k++ ) {
				if ( re.test( cab[ k ] ) ) {
					return k;
				}
			}
			return -1;
		}
		var cF = col( /^fecha/ ), cD = col( /descrip|glosa|detalle|concepto|movimiento|transacc/ ),
			cC = col( /cargo|debito|giro|egreso|retiro/ ), cA = col( /abono|credito|deposito|ingreso/ ),
			cM = col( /^monto|importe/ ), cS = col( /^saldo/ );
		var out = [];
		tabla.slice( h + 1 ).forEach( function ( r ) {
			var f = fecha( r[ cF ] );
			if ( ! f ) {
				return;
			}
			var cargo = cC >= 0 ? num( r[ cC ] ) : null;
			var abono = cA >= 0 ? num( r[ cA ] ) : null;
			var monto = cM >= 0 ? num( r[ cM ] ) : null;
			var v = 0;
			if ( abono ) {
				v = Math.abs( abono );
			} else if ( cargo ) {
				v = -Math.abs( cargo );
			} else if ( monto ) {
				v = monto;
			}
			if ( ! v ) {
				return;
			}
			out.push( { fecha: f, descripcion: cD >= 0 ? String( r[ cD ] || '' ).trim() : '', valor: v, saldo: cS >= 0 ? num( r[ cS ] ) : null } );
		} );
		return out;
	}

	// Líneas de un PDF: fecha al comienzo y montos al final.
	function deLineas( ls ) {
		var todo = ls.join( '\n' );
		var per = /(\d{1,2})[\/\-](\d{1,2})[\/\-](20\d{2})/.exec( todo );
		if ( per ) {
			ANIO = parseInt( per[ 3 ], 10 );
		}
		var out = [];
		ls.forEach( function ( l ) {
			l = l.replace( /\s+/g, ' ' ).trim();
			var m = /^(\d{1,2}[\/\-.]\d{1,2}(?:[\/\-.]\d{2,4})?)\s+(.*)$/.exec( l );
			if ( ! m ) {
				return;
			}
			var resto = m[ 2 ];
			var montos = [];
			var t;
			var re = /\s(-?\$?\s?\(?\d{1,3}(?:\.\d{3})+(?:,\d{1,2})?\)?-?|-?\$?\s?\d{1,3}(?:,\d{1,2})?)$/;
			while ( ( t = re.exec( ' ' + resto ) ) ) {
				montos.unshift( t[ 1 ] );
				resto = resto.slice( 0, resto.length - t[ 1 ].length ).trim();
				if ( montos.length > 3 ) {
					break;
				}
			}
			montos = montos.map( num ).filter( function ( x ) {
				return null !== x;
			} );
			if ( ! montos.length ) {
				return;
			}
			var saldo = montos.length >= 2 ? montos[ montos.length - 1 ] : null;
			var v = montos.length >= 2 ? montos[ montos.length - 2 ] : montos[ 0 ];
			// Quita un N° de operación largo al comienzo de la glosa.
			resto = resto.replace( /^\d{5,}\s+/, '' );
			// «Saldo anterior» no es un movimiento: sólo sirve de punto de partida.
			if ( /saldo (anterior|inicial)/i.test( sinTilde( resto ) ) ) {
				out.push( { inicio: true, saldo: montos[ montos.length - 1 ] } );
				return;
			}
			out.push( { fecha: fecha( m[ 1 ] ), descripcion: resto, valor: v, saldo: saldo } );
		} );
		// El signo, por cómo cambia el saldo de una fila a la otra.
		for ( var i = 0; i < out.length; i++ ) {
			var a = out[ i ];
			if ( a.inicio ) {
				continue;
			}
			var prev = i > 0 ? out[ i - 1 ].saldo : null;
			if ( null !== a.saldo && null !== prev ) {
				var d = a.saldo - prev;
				if ( Math.abs( Math.abs( d ) - Math.abs( a.valor ) ) < 1 ) {
					a.valor = d;
					continue;
				}
			}
			if ( a.valor > 0 && ! /abono|deposito|transferencia de|recibid|pago de cliente/i.test( sinTilde( a.descripcion ) ) ) {
				a.valor = -a.valor;
			}
		}
		return out.filter( function ( x ) {
			return ! x.inicio;
		} );
	}

	function cargarPdfjs() {
		if ( window.pdfjsLib ) {
			return Promise.resolve( window.pdfjsLib );
		}
		return new Promise( function ( ok, mal ) {
			var s = document.createElement( 'script' );
			s.src = CFG.pdfjs;
			s.onload = function () {
				window.pdfjsLib.GlobalWorkerOptions.workerSrc = CFG.pdfjsWorker;
				ok( window.pdfjsLib );
			};
			s.onerror = mal;
			document.head.appendChild( s );
		} );
	}
	function lineasPdf( buf ) {
		return cargarPdfjs().then( function ( lib ) {
			return lib.getDocument( { data: buf } ).promise;
		} ).then( function ( pdf ) {
			var ps = [];
			for ( var i = 1; i <= pdf.numPages; i++ ) {
				ps.push( pdf.getPage( i ).then( function ( pg ) {
					return pg.getTextContent();
				} ) );
			}
			return Promise.all( ps );
		} ).then( function ( pags ) {
			var out = [];
			pags.forEach( function ( c ) {
				var items = c.items.filter( function ( it ) {
					return it.str && it.str.trim();
				} ).sort( function ( a, b ) {
					return b.transform[ 5 ] - a.transform[ 5 ];
				} );
				var gr = [];
				items.forEach( function ( it ) {
					var g = gr[ gr.length - 1 ];
					if ( g && Math.abs( g.y - it.transform[ 5 ] ) <= 3 ) {
						g.items.push( it );
					} else {
						gr.push( { y: it.transform[ 5 ], items: [ it ] } );
					}
				} );
				gr.forEach( function ( g ) {
					out.push( g.items.sort( function ( a, b ) {
						return a.transform[ 4 ] - b.transform[ 4 ];
					} ).map( function ( it ) {
						return it.str.trim();
					} ).join( '  ' ) );
				} );
			} );
			return out;
		} );
	}
	function texto( buf ) {
		try {
			return new TextDecoder( 'utf-8', { fatal: true } ).decode( buf );
		} catch ( x ) {
			return new TextDecoder( 'windows-1252' ).decode( buf );
		}
	}
	function leer( f ) {
		return f.arrayBuffer().then( function ( buf ) {
			if ( /\.pdf$/i.test( f.name ) || /pdf$/i.test( f.type ) ) {
				// En un PDF las columnas vacías desaparecen al leer el texto: se usan
				// la fecha al comienzo, los montos al final y el saldo para el signo.
				return lineasPdf( buf ).then( deLineas );
			}
			var b = new Uint8Array( buf.slice( 0, 4 ) );
			if ( ( 0xD0 === b[ 0 ] && 0xCF === b[ 1 ] ) || ( 0x50 === b[ 0 ] && 0x4B === b[ 1 ] ) ) {
				throw new Error( '«' + f.name + '» es un Excel que no puedo leer acá. Descárgala en PDF, CSV o TXT, o ábrela y guárdala como CSV.' );
			}
			var t = texto( buf );
			if ( /<table/i.test( t.slice( 0, 5000 ) ) ) {
				var doc = new DOMParser().parseFromString( t, 'text/html' );
				var tabla = Array.prototype.map.call( doc.querySelectorAll( 'tr' ), function ( tr ) {
					return Array.prototype.map.call( tr.querySelectorAll( 'th,td' ), function ( c ) {
						return c.textContent.replace( /\s+/g, ' ' ).trim();
					} );
				} );
				return deTabla( tabla ) || [];
			}
			var ls = t.replace( /^﻿/, '' ).split( /\r?\n/ ).filter( function ( l ) {
				return l.trim();
			} );
			var muestra = ls.slice( 0, 30 ).join( '\n' );
			var sep = [ '\t', ';', '|', ',' ].map( function ( x ) {
				return [ x, muestra.split( x ).length ];
			} ).sort( function ( a, b ) {
				return b[ 1 ] - a[ 1 ];
			} )[ 0 ][ 0 ];
			return deTabla( ls.map( function ( l ) {
				return l.split( sep ).map( function ( x ) {
					return x.trim().replace( /^"|"$/g, '' );
				} );
			} ) ) || deLineas( ls );
		} );
	}

	function post( probar, elegidas ) {
		var fd = new FormData();
		fd.append( 'action', 'malka_crm_cartola' );
		var ctaSel = document.getElementById( 'esc-cart-cta' );
		if ( ctaSel ) {
			fd.append( 'cuenta', ctaSel.value );
		}
		fd.append( 'nonce', caja.getAttribute( 'data-nonce' ) );
		fd.append( 'filas', JSON.stringify( filas ) );
		if ( probar ) {
			fd.append( 'probar', '1' );
		}
		if ( elegidas ) {
			fd.append( 'elegidas', JSON.stringify( elegidas ) );
			var c = {};
			res.querySelectorAll( 'select[data-i]' ).forEach( function ( s ) {
				c[ s.getAttribute( 'data-i' ) ] = s.value;
			} );
			fd.append( 'cats', JSON.stringify( c ) );
			var usar = res.querySelector( '[data-usar-saldo]' );
			if ( usar && usar.checked && null !== saldoFinal ) {
				fd.append( 'saldo', String( Math.round( saldoFinal ) ) );
			}
		}
		return fetch( caja.getAttribute( 'data-ajax' ), { method: 'POST', credentials: 'same-origin', body: fd } ).then( function ( r ) {
			return r.json();
		} ).then( function ( j ) {
			if ( ! j || ! j.success ) {
				throw new Error( 'error' );
			}
			cats = j.data.cats;
			return j.data;
		} );
	}

	function pintar() {
		var nuevos = vista.filter( function ( v ) {
			return 'nuevo' === v.accion;
		} ).length;
		var html = '<p class="esc-cart__h">' + vista.length + ' movimientos en la cartola · <b>' + nuevos + ' nuevos</b> · ' + ( vista.length - nuevos ) + ' ya estaban</p>';
		html += '<div class="esc-cart__t">';
		vista.forEach( function ( v ) {
			var puede = 'nuevo' === v.accion;
			var opts = Object.keys( cats ).map( function ( k ) {
				return '<option value="' + k + '"' + ( k === v.categoria ? ' selected' : '' ) + '>' + esc( cats[ k ] ) + '</option>';
			} ).join( '' );
			html += '<div class="esc-cart__r' + ( puede ? '' : ' is-off' ) + '">' +
				'<input type="checkbox" value="' + v.i + '"' + ( puede ? ' checked' : ' disabled' ) + ' />' +
				'<span class="esc-cart__f">' + esc( v.fecha.split( '-' ).reverse().join( '/' ) ) + '</span>' +
				'<span class="esc-cart__d">' + esc( v.descripcion || '—' ) + '</span>' +
				( puede ? '<select data-i="' + v.i + '">' + opts + '</select>' : '<span class="esc-cart__ya">Ya estaba</span>' ) +
				'<b class="esc-cart__v' + ( 'entrada' === v.tipo ? ' is-in' : '' ) + '">' + ( 'entrada' === v.tipo ? '+' : '−' ) + plata( v.monto ) + '</b></div>';
		} );
		html += '</div>';
		html += '<div class="esc-cart__pie">';
		if ( null !== saldoFinal ) {
			html += '<label class="esc-chk"><input type="checkbox" data-usar-saldo checked /> Dejar el saldo de la cuenta en ' + plata( saldoFinal ) + ' (el último saldo de la cartola)</label>';
		}
		html += '<span class="esc-cart__sp"></span><button type="button" class="esc-btn" data-cart-x>Cancelar</button><button type="button" class="esc-btn esc-btn--primary" data-cart-ok' + ( nuevos ? '' : ' disabled' ) + '>Registrar ' + nuevos + '</button></div>';
		res.innerHTML = html;
		res.hidden = false;
	}

	function recibir( files ) {
		files = Array.prototype.slice.call( files || [] );
		if ( ! files.length ) {
			return;
		}
		aviso( 'Leyendo la cartola…' );
		Promise.all( files.map( leer ) ).then( function ( ls ) {
			var todos = [].concat.apply( [], ls ).filter( function ( m ) {
				return m && m.fecha && m.valor;
			} );
			if ( ! todos.length ) {
				throw new Error( 'No encontré movimientos (fecha y monto) en el archivo. Mándame un ejemplo de tu cartola y lo ajusto a ese formato.' );
			}
			var conSaldo = todos.filter( function ( m ) {
				return null !== m.saldo && undefined !== m.saldo;
			} );
			// El saldo final: el de la fila más nueva.
			saldoFinal = null;
			if ( conSaldo.length ) {
				var ord = conSaldo.slice().sort( function ( a, b ) {
					return a.fecha < b.fecha ? -1 : ( a.fecha > b.fecha ? 1 : 0 );
				} );
				saldoFinal = ord[ ord.length - 1 ].saldo;
			}
			filas = todos.map( function ( m ) {
				return { fecha: m.fecha, descripcion: m.descripcion, monto: Math.round( Math.abs( m.valor ) ), tipo: m.valor > 0 ? 'entrada' : 'salida' };
			} );
			return post( true );
		} ).then( function ( d ) {
			vista = d.filas;
			aviso( '' );
			pintar();
		} ).catch( function ( e ) {
			aviso( e && e.message && 'error' !== e.message ? e.message : 'No se pudo leer la cartola.', true );
		} );
	}

	input.addEventListener( 'change', function () {
		recibir( input.files );
		input.value = '';
	} );
	[ 'dragenter', 'dragover' ].forEach( function ( ev ) {
		drop.addEventListener( ev, function ( e ) {
			e.preventDefault();
			drop.classList.add( 'is-sobre' );
		} );
	} );
	[ 'dragleave', 'drop' ].forEach( function ( ev ) {
		drop.addEventListener( ev, function () {
			drop.classList.remove( 'is-sobre' );
		} );
	} );
	drop.addEventListener( 'drop', function ( e ) {
		e.preventDefault();
		recibir( e.dataTransfer.files );
	} );

	res.addEventListener( 'click', function ( e ) {
		if ( e.target.closest( '[data-cart-x]' ) ) {
			res.hidden = true;
			res.innerHTML = '';
			return;
		}
		var ok = e.target.closest( '[data-cart-ok]' );
		if ( ! ok ) {
			return;
		}
		var el = Array.prototype.map.call( res.querySelectorAll( '.esc-cart__r input[type=checkbox]:checked' ), function ( c ) {
			return parseInt( c.value, 10 );
		} );
		ok.disabled = true;
		aviso( 'Registrando…' );
		post( false, el ).then( function ( d ) {
			aviso( 'Listo: ' + d.hechos + ( 1 === d.hechos ? ' movimiento registrado.' : ' movimientos registrados.' ) + ' Recargando…' );
			window.setTimeout( function () {
				window.location.reload();
			}, 900 );
		} ).catch( function () {
			ok.disabled = false;
			aviso( 'No se pudo registrar. Prueba de nuevo.', true );
		} );
	} );
	res.addEventListener( 'change', function ( e ) {
		if ( e.target.matches( '.esc-cart__r input[type=checkbox]' ) ) {
			var n = res.querySelectorAll( '.esc-cart__r input[type=checkbox]:checked' ).length;
			var ok = res.querySelector( '[data-cart-ok]' );
			ok.textContent = 'Registrar ' + n;
			ok.disabled = ! n;
		}
	} );
}() );


/* ── Finanzas: cada cifra del resumen lleva a su detalle ── */
( function () {
	'use strict';
	function ir( e ) {
		var c = e.target.closest ? e.target.closest( '.esc-fin__cell[data-href], .esc-in[data-href]' ) : null;
		if ( ! c || e.target.closest( 'a, .esc-info' ) ) {
			return;
		}
		if ( 'keydown' === e.type && 'Enter' !== e.key ) {
			return;
		}
		window.location.href = c.getAttribute( 'data-href' );
	}
	document.addEventListener( 'click', ir );
	document.addEventListener( 'keydown', ir );
}() );

/* Documentos: «Ver más» abre el siguiente bloque de 40. */
( function () {
	'use strict';
	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest ? e.target.closest( '[data-dl-mas]' ) : null;
		if ( ! b ) {
			return;
		}
		var lista = b.closest( '.esc-nl__list' ) || document;
		var sig   = lista.querySelector( '.esc-dl__mas[hidden]' );
		if ( sig ) {
			sig.hidden = false;
		}
		var total  = lista.querySelectorAll( '.esc-dl__mas' ).length;
		var quedan = lista.querySelectorAll( '.esc-dl__mas[hidden]' ).length;
		var span   = b.nextElementSibling;
		var visibles = lista.querySelectorAll( '.esc-nl:not(.esc-nl--h)' ).length - Array.prototype.reduce.call( lista.querySelectorAll( '.esc-dl__mas[hidden]' ), function ( n, d ) {
			return n + d.querySelectorAll( '.esc-nl' ).length;
		}, 0 );
		if ( span ) {
			span.textContent = span.textContent.replace( /\d+(?= de )/, String( visibles ) );
		}
		if ( ! quedan ) {
			b.parentNode.hidden = true;
		}
		void total;
	} );
}() );


/* Cuentas bancarias: saldo de cada una con autoguardado, agregar y quitar. */
( function () {
	'use strict';
	var caja = document.getElementById( 'esc-cuentas' );
	if ( ! caja || ! window.malkaEscritorio ) {
		return;
	}
	var CFG = window.malkaEscritorio;
	var miles = function ( n ) {
		return String( Math.round( n ) ).replace( /\B(?=(\d{3})+(?!\d))/g, '.' );
	};
	function enviar( datos ) {
		var fd = new FormData();
		fd.append( 'action', 'malka_crm_movement' );
		fd.append( 'nonce', CFG.nonceFin );
		Object.keys( datos ).forEach( function ( k ) {
			fd.append( k, datos[ k ] );
		} );
		return fetch( CFG.ajax, { method: 'POST', credentials: 'same-origin', body: fd } ).then( function ( r ) {
			return r.json();
		} ).then( function ( j ) {
			if ( ! j || ! j.success ) {
				throw new Error( ( j && j.data && j.data.message ) || 'Error' );
			}
			return j.data;
		} );
	}
	function total( d ) {
		var t = document.getElementById( 'esc-cta-total' );
		if ( t && d && d.total_t ) {
			t.textContent = d.total_t;
		}
	}
	caja.querySelectorAll( '[data-cuenta-saldo]' ).forEach( function ( inp ) {
		var previo = inp.value;
		inp.addEventListener( 'focus', function () {
			previo = inp.value;
			inp.select();
		} );
		inp.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key ) {
				e.preventDefault();
				inp.blur();
			}
		} );
		inp.addEventListener( 'blur', function () {
			var n = parseInt( inp.value.replace( /[^\d-]/g, '' ), 10 ) || 0;
			if ( miles( n ) === previo ) {
				inp.value = previo;
				return;
			}
			var id = inp.closest( '[data-cuenta]' ).getAttribute( 'data-cuenta' );
			enviar( { op: 'saldo', cuenta: id, monto: n } ).then( function ( d ) {
				inp.value = miles( n );
				previo = inp.value;
				total( { total_t: d.total_t } );
				inp.classList.add( 'is-ok' );
				setTimeout( function () {
					inp.classList.remove( 'is-ok' );
				}, 1200 );
			} ).catch( function () {
				inp.value = previo;
			} );
		} );
	} );
	caja.addEventListener( 'click', function ( e ) {
		var b = e.target.closest( '[data-cuenta-borrar]' );
		if ( ! b ) {
			return;
		}
		var fila = b.closest( '[data-cuenta]' );
		if ( ! window.confirm( 'Quitar esta cuenta? Sus movimientos pasan a la principal.' ) ) {
			return;
		}
		enviar( { op: 'cuenta_borrar', id: fila.getAttribute( 'data-cuenta' ) } ).then( function () {
			window.location.reload();
		} ).catch( function ( err ) {
			window.alert( err.message );
		} );
	} );
	var f = document.getElementById( 'esc-cta-form' );
	if ( f ) {
		f.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var d = { op: 'cuenta' };
			new FormData( f ).forEach( function ( v, k ) {
				d[ k ] = v;
			} );
			var btn = f.querySelector( 'button[type="submit"]' );
			btn.disabled = true;
			enviar( d ).then( function () {
				window.location.reload();
			} ).catch( function ( err ) {
				btn.disabled = false;
				window.alert( err.message );
			} );
		} );
	}

	/*
	 * Editar una cuenta que ya existe. Va delegado en el documento porque las
	 * filas se vuelven a dibujar al recargar y enganchar una por una se
	 * perdería. El saldo no se manda: ése se escribe en la fila, y mandarlo
	 * vacío acá haría que el servidor lo dejara como está, que es lo que
	 * queremos.
	 */
	document.addEventListener( 'submit', function ( e ) {
		var form = e.target.closest ? e.target.closest( '[data-cuenta-editar]' ) : null;
		if ( ! form ) {
			return;
		}
		e.preventDefault();

		var d = { op: 'cuenta', id: form.getAttribute( 'data-cuenta-editar' ) };
		new FormData( form ).forEach( function ( v, k ) {
			d[ k ] = v;
		} );
		/*
		 * Una casilla sin marcar no viaja en el FormData, y el servidor leería
		 * «no es tuya» donde antes decía que sí. Hay que decirlo explícito.
		 */
		[ 'personal', 'principal' ].forEach( function ( k ) {
			var caja = form.querySelector( '[name="' + k + '"]' );
			d[ k ] = caja && caja.checked && ! caja.disabled ? '1' : '';
		} );

		var btn = form.querySelector( 'button[type="submit"]' );
		if ( btn ) {
			btn.disabled = true;
		}
		enviar( d ).then( function () {
			window.location.reload();
		} ).catch( function ( err ) {
			if ( btn ) {
				btn.disabled = false;
			}
			window.alert( err.message );
		} );
	} );

	/* Una cuenta tuya no puede ser la principal del estudio: se nota al tiro. */
	document.addEventListener( 'change', function ( e ) {
		var caja = e.target;
		if ( ! caja.name || 'personal' !== caja.name ) {
			return;
		}
		var form = caja.closest ? caja.closest( '[data-cuenta-editar]' ) : null;
		if ( ! form ) {
			return;
		}
		var prin = form.querySelector( '[name="principal"]' );
		if ( prin ) {
			prin.disabled = caja.checked;
			if ( caja.checked ) {
				prin.checked = false;
			}
		}
	} );
}() );

/* Negocios: omitir la factura con una nota, o deshacerlo. */
( function () {
	'use strict';
	var dlg = document.getElementById( 'esc-omit' );
	if ( ! dlg || ! window.malkaEscritorio ) {
		return;
	}
	var actual = 0;
	function enviar( datos ) {
		var fd = new FormData();
		fd.append( 'action', 'malka_crm_omitir_factura' );
		fd.append( 'nonce', dlg.getAttribute( 'data-nonce' ) );
		Object.keys( datos ).forEach( function ( k ) {
			fd.append( k, datos[ k ] );
		} );
		return fetch( window.malkaEscritorio.ajax, { method: 'POST', credentials: 'same-origin', body: fd } ).then( function ( r ) {
			return r.json();
		} ).then( function ( j ) {
			if ( ! j || ! j.success ) {
				throw new Error( ( j && j.data && j.data.message ) || 'Error' );
			}
		} );
	}
	function abrir( b ) {
		actual = b.getAttribute( 'data-omitir' );
		if ( b.hasAttribute( 'data-deshacer' ) ) {
			enviar( { pid: actual, deshacer: 1 } ).then( function () {
				window.location.reload();
			} );
			return;
		}
		dlg.querySelector( 'textarea' ).value = '';
		dlg.showModal();
		dlg.querySelector( 'textarea' ).focus();
	}
	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest ? e.target.closest( '[data-omitir]' ) : null;
		if ( b ) {
			e.preventDefault();
			e.stopPropagation();
			abrir( b );
		}
	}, true );
	document.addEventListener( 'keydown', function ( e ) {
		var b = e.target.closest ? e.target.closest( '[data-omitir]' ) : null;
		if ( b && ( 'Enter' === e.key || ' ' === e.key ) ) {
			e.preventDefault();
			abrir( b );
		}
	} );
	dlg.querySelector( '[data-omit-x]' ).addEventListener( 'click', function () {
		dlg.close();
	} );
	dlg.querySelector( 'form' ).addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		var nota = dlg.querySelector( 'textarea' ).value.trim();
		if ( ! nota ) {
			return;
		}
		var btn = dlg.querySelector( 'button[type="submit"]' );
		btn.disabled = true;
		enviar( { pid: actual, nota: nota } ).then( function () {
			window.location.reload();
		} ).catch( function ( err ) {
			btn.disabled = false;
			window.alert( err.message );
		} );
	} );
}() );

/* Ficha de cliente: «Recordar» una tarea suya por correo. */
( function () {
	'use strict';
	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest ? e.target.closest( '[data-recordar]' ) : null;
		if ( ! b || b.disabled || ! window.malkaEscritorio ) {
			return;
		}
		e.preventDefault();
		var caja = b.closest( '[data-nonce]' );
		var fd   = new FormData();
		fd.append( 'action', 'malka_crm_recordar_tarea' );
		fd.append( 'nonce', caja ? caja.getAttribute( 'data-nonce' ) : '' );
		fd.append( 'pid', b.getAttribute( 'data-pid' ) );
		fd.append( 'tid', b.getAttribute( 'data-tid' ) );
		var txt = b.textContent;
		b.disabled = true;
		b.textContent = '…';
		fetch( window.malkaEscritorio.ajax, { method: 'POST', credentials: 'same-origin', body: fd } ).then( function ( r ) {
			return r.json();
		} ).then( function ( j ) {
			b.textContent = j && j.success ? 'Enviado ✓' : txt;
			if ( ! ( j && j.success ) ) {
				b.disabled = false;
				window.alert( ( j && j.data && j.data.message ) || 'Error' );
			}
		} );
	} );
}() );

/* Escribir a clientes: partir de una plantilla propia. */
( function () {
	'use strict';
	var sel = document.getElementById( 'esc-com-tpl' );
	if ( ! sel ) {
		return;
	}
	sel.addEventListener( 'change', function () {
		var o = sel.options[ sel.selectedIndex ];
		var a = document.getElementById( 'esc-com-asunto' );
		var c = document.getElementById( 'esc-com-cuerpo' );
		if ( a ) {
			a.value = o.getAttribute( 'data-asunto' ) || '';
		}
		if ( c ) {
			c.value = o.getAttribute( 'data-cuerpo' ) || '';
		}
	} );
}() );

/* Ventana emergente genérica: [data-modal] abre la página en un iframe encima. */
( function () {
	'use strict';
	var dlg = null;
	function abrir( url, titulo ) {
		if ( ! dlg ) {
			dlg = document.createElement( 'dialog' );
			dlg.className = 'esc-pop';
			dlg.innerHTML = '<div class="esc-pop__h"><b></b><span><button type="button" class="esc-pop__x" aria-label="Cerrar">&times;</button></span></div><iframe title=""></iframe>';
			( document.querySelector( '.malka-esc' ) || document.body ).appendChild( dlg );
			dlg.querySelector( '.esc-pop__x' ).addEventListener( 'click', function () {
				dlg.close();
			} );
			// Lo de adentro se guarda solo: al cerrar, la página de atrás se pone al día.
			dlg.addEventListener( 'close', function () {
				window.location.reload();
			} );
			dlg.addEventListener( 'click', function ( e ) {
				if ( e.target === dlg ) {
					dlg.close();
				}
			} );
		}
		dlg.querySelector( '.esc-pop__h b' ).textContent = titulo || '';
		dlg.querySelector( 'iframe' ).src = url;
		dlg.showModal();
	}
	document.addEventListener( 'click', function ( e ) {
		var a = e.target.closest ? e.target.closest( '[data-modal]' ) : null;
		if ( ! a || e.metaKey || e.ctrlKey ) {
			return;
		}
		e.preventDefault();
		abrir( a.getAttribute( 'data-modal' ), a.getAttribute( 'data-modal-t' ) );
	} );
	window.addEventListener( 'message', function ( e ) {
		if ( 'malka-cerrar-modal' === e.data && dlg ) {
			dlg.close();
		}
	} );
}() );

/* Facturas de un negocio: mandarlas a la papelera desde la fila. */
( function () {
	'use strict';
	function borrar( b ) {
		if ( ! window.confirm( '¿Mandar esta factura a la papelera?' ) ) {
			return;
		}
		var fd = new FormData();
		fd.append( 'action', 'malka_crm_doc_borrar' );
		fd.append( 'doc', b.getAttribute( 'data-doc-borrar' ) );
		fd.append( 'nonce', b.getAttribute( 'data-nonce' ) );
		fetch( ( window.malkaEscritorio || {} ).ajax, { method: 'POST', credentials: 'same-origin', body: fd } ).then( function ( r ) {
			return r.json();
		} ).then( function ( j ) {
			if ( j && j.success ) {
				window.location.reload();
			} else {
				window.alert( ( j && j.data && j.data.message ) || 'Error' );
			}
		} );
	}
	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest ? e.target.closest( '[data-doc-borrar]' ) : null;
		if ( b ) {
			e.preventDefault();
			e.stopPropagation();
			borrar( b );
		}
	}, true );
}() );


/* Dentro de una ventana emergente, «Cancelar» o «Volver» la cierran. */
( function () {
	'use strict';
	if ( ! document.querySelector( '.malka-esc.is-embed' ) || window.parent === window ) {
		return;
	}
	document.addEventListener( 'click', function ( e ) {
		var a = e.target.closest ? e.target.closest( '.esc-ed__save a.esc-btn, .esc-cfg__save a.esc-btn' ) : null;
		if ( a ) {
			e.preventDefault();
			window.parent.postMessage( 'malka-cerrar-modal', '*' );
		}
	} );
}() );

/* Negocio: enlaces y archivos para el cliente. */
( function () {
	'use strict';
	var caja = document.getElementById( 'esc-enl' );
	if ( ! caja || ! window.malkaEscritorio ) {
		return;
	}
	function enviar( fd ) {
		fd.append( 'action', 'malka_crm_enlace' );
		fd.append( 'pid', caja.getAttribute( 'data-pid' ) );
		fd.append( 'nonce', caja.getAttribute( 'data-nonce' ) );
		return fetch( window.malkaEscritorio.ajax, { method: 'POST', credentials: 'same-origin', body: fd } ).then( function ( r ) {
			return r.json();
		} );
	}
	var tit = caja.querySelector( '[data-enl-titulo]' );
	var url = caja.querySelector( '[data-enl-url]' );
	var arc = caja.querySelector( '[data-enl-archivo]' );
	var btn = caja.querySelector( '[data-enl-agregar]' );
	caja.addEventListener( 'keydown', function ( e ) {
		if ( 'Enter' === e.key && e.target.matches( '.esc-enl__in' ) ) {
			e.preventDefault();
			btn.click();
		}
	} );
	// Lo que se escribe acá no es parte del negocio: que no dispare su guardado.
	[ 'input', 'change' ].forEach( function ( ev ) {
		caja.addEventListener( ev, function ( e ) {
			e.stopPropagation();
		} );
	} );
	function agregar( archivo ) {
		var fd = new FormData();
		fd.append( 'op', 'agregar' );
		fd.append( 'titulo', tit.value.trim() );
		fd.append( 'url', url.value.trim() );
		if ( archivo ) {
			fd.append( 'archivo', archivo );
		}
		btn.disabled = true;
		btn.textContent = archivo ? 'Subiendo…' : 'Agregando…';
		enviar( fd ).then( function ( j ) {
			if ( j && j.success ) {
				window.location.reload();
			} else {
				btn.disabled = false;
				btn.textContent = '+ Agregar';
				window.alert( ( j && j.data && j.data.message ) || 'Error' );
			}
		} );
	}
	btn.addEventListener( 'click', function () {
		agregar( null );
	} );
	arc.addEventListener( 'change', function () {
		if ( arc.files && arc.files[ 0 ] ) {
			agregar( arc.files[ 0 ] );
		}
	} );
	caja.addEventListener( 'click', function ( e ) {
		var x = e.target.closest( '[data-enl-borrar]' );
		if ( ! x ) {
			return;
		}
		e.preventDefault();
		if ( ! window.confirm( '¿Quitar este enlace del portal del cliente?' ) ) {
			return;
		}
		var fd = new FormData();
		fd.append( 'op', 'borrar' );
		fd.append( 'id', x.closest( '[data-id]' ).getAttribute( 'data-id' ) );
		enviar( fd ).then( function () {
			x.closest( '[data-id]' ).remove();
		} );
	} );
}() );

/* Cliente: próximos proyectos (ideas que pasan a negocio o cotización). */
( function () {
	'use strict';
	var caja = document.getElementById( 'esc-ideas' );
	if ( ! caja || ! window.malkaEscritorio ) {
		return;
	}
	function enviar( datos ) {
		var fd = new FormData();
		fd.append( 'action', 'malka_crm_cli_idea' );
		fd.append( 'cliente', caja.getAttribute( 'data-cliente' ) );
		fd.append( 'nonce', caja.getAttribute( 'data-nonce' ) );
		Object.keys( datos ).forEach( function ( k ) {
			fd.append( k, datos[ k ] );
		} );
		return fetch( window.malkaEscritorio.ajax, { method: 'POST', credentials: 'same-origin', body: fd } ).then( function ( r ) {
			return r.json();
		} );
	}
	function pintar( j ) {
		if ( j && j.success && j.data && j.data.html ) {
			var tmp = document.createElement( 'div' );
			tmp.innerHTML = j.data.html;
			var nuevo = tmp.firstElementChild;
			caja.innerHTML = nuevo.innerHTML;
			alto();
		} else if ( j && ! j.success ) {
			window.alert( ( j.data && j.data.message ) || 'Error' );
		}
	}
	function miles( v ) {
		var n = String( v ).replace( /\D/g, '' );
		return n ? Number( n ).toLocaleString( 'es-CL' ) : '';
	}
	function alto() {
		Array.prototype.forEach.call( caja.querySelectorAll( '.esc-ideas__n' ), function ( t ) {
			t.style.height = 'auto';
			t.style.height = t.scrollHeight + 'px';
		} );
	}
	alto();
	// Esto no es parte de la ficha: que no dispare su guardado.
	[ 'input', 'change' ].forEach( function ( ev ) {
		caja.addEventListener( ev, function ( e ) {
			e.stopPropagation();
			if ( 'input' === ev && e.target.matches( '[data-campo="monto"], [data-idea-monto]' ) ) {
				e.target.value = miles( e.target.value );
			}
			if ( 'input' === ev && e.target.matches( '.esc-ideas__n' ) ) {
				alto();
			}
			if ( 'change' === ev && e.target.matches( '[data-campo]' ) ) {
				var fila = e.target.closest( '[data-id]' );
				enviar( { op: 'editar', id: fila.getAttribute( 'data-id' ), campo: e.target.getAttribute( 'data-campo' ), valor: e.target.value } );
			}
		} );
	} );
	function agregar() {
		var t = caja.querySelector( '[data-idea-nueva]' );
		var m = caja.querySelector( '[data-idea-monto]' );
		if ( ! t.value.trim() ) {
			t.focus();
			return;
		}
		enviar( { op: 'agregar', titulo: t.value.trim(), monto: m ? m.value : '' } ).then( pintar );
	}
	caja.addEventListener( 'keydown', function ( e ) {
		if ( 'Enter' === e.key && e.target.matches( '.esc-ideas__in, .esc-ideas__t, [data-campo="monto"]' ) ) {
			e.preventDefault();
			if ( e.target.matches( '.esc-ideas__in' ) ) {
				agregar();
			} else {
				e.target.blur();
			}
		}
	} );
	caja.addEventListener( 'click', function ( e ) {
		if ( e.target.closest( '[data-idea-agregar]' ) ) {
			e.preventDefault();
			agregar();
			return;
		}
		var b = e.target.closest( '[data-idea-op]' );
		if ( ! b ) {
			return;
		}
		e.preventDefault();
		var op = b.getAttribute( 'data-idea-op' );
		var id = b.closest( '[data-id]' ).getAttribute( 'data-id' );
		if ( 'borrar' === op ) {
			if ( window.confirm( '¿Quitar esta idea? El negocio o la cotización que haya creado no se tocan.' ) ) {
				enviar( { op: 'borrar', id: id } ).then( pintar );
			}
			return;
		}
		b.disabled = true;
		enviar( { op: op, id: id } ).then( function ( j ) {
			if ( j && j.success && j.data.url ) {
				window.location.href = j.data.url;
			} else {
				b.disabled = false;
				pintar( j );
			}
		} );
	} );
}() );

/* Negocios: mandar uno a la papelera desde su fila. */
document.addEventListener( 'click', function ( e ) {
	var b = e.target.closest ? e.target.closest( '[data-nl-borrar]' ) : null;
	if ( ! b ) {
		return;
	}
	e.preventDefault();
	e.stopPropagation();
	var fila = b.closest( '.esc-nl' );
	var nombre = fila && fila.querySelector( '.esc-nl__p b' ) ? fila.querySelector( '.esc-nl__p b' ).textContent : 'este negocio';
	if ( window.confirm( '¿Mandar «' + nombre + '» a la papelera? Sus cotizaciones y facturas no se tocan.' ) ) {
		window.location.href = b.getAttribute( 'data-nl-borrar' );
	}
}, true );

/* Asistente: el chat con Claude. */
( function () {
	'use strict';
	var raiz = document.getElementById( 'esc-ia' );
	if ( ! raiz || ! window.malkaEscritorio ) {
		return;
	}
	var panel = raiz.querySelector( '.esc-ia__p' );
	var lista = raiz.querySelector( '[data-ia-lista]' );
	var form  = raiz.querySelector( '[data-ia-form]' );
	var campo = raiz.querySelector( '[data-ia-in]' );
	var CLAVE = 'malka_ia_v1';
	var estado = { mensajes: [], vista: [] };
	var ocupado = false;

	function guardar() {
		try {
			sessionStorage.setItem( CLAVE, JSON.stringify( estado ) );
		} catch ( e ) {}
	}
	function cargar() {
		try {
			var s = JSON.parse( sessionStorage.getItem( CLAVE ) || 'null' );
			if ( s && s.mensajes ) {
				estado = s;
			}
		} catch ( e ) {}
	}
	function esc( t ) {
		var d = document.createElement( 'div' );
		d.textContent = t;
		return d.innerHTML;
	}
	// Markdown mínimo: negritas, listas, saltos de línea.
	function md( t ) {
		var h = esc( t ).replace( /\*\*(.+?)\*\*/g, '<b>$1</b>' );
		var lineas = h.split( '\n' );
		var out = '';
		var enLista = false;
		lineas.forEach( function ( l ) {
			var m = l.match( /^\s*[-•*]\s+(.*)$/ );
			if ( m ) {
				if ( ! enLista ) {
					out += '<ul>';
					enLista = true;
				}
				out += '<li>' + m[ 1 ] + '</li>';
			} else {
				if ( enLista ) {
					out += '</ul>';
					enLista = false;
				}
				out += l.trim() ? '<p>' + l + '</p>' : '';
			}
		} );
		return out + ( enLista ? '</ul>' : '' );
	}
	function burbuja( quien, html, extra ) {
		var d = document.createElement( 'div' );
		d.className = 'esc-ia__m esc-ia__m--' + quien + ( extra ? ' ' + extra : '' );
		d.innerHTML = html;
		lista.appendChild( d );
		lista.scrollTop = lista.scrollHeight;
		return d;
	}
	function pintarVista() {
		if ( ! estado.vista.length ) {
			return;
		}
		var hola = lista.querySelector( '.esc-ia__hola' );
		if ( hola ) {
			hola.remove();
		}
		lista.querySelectorAll( '.esc-ia__m' ).forEach( function ( x ) {
			x.remove();
		} );
		estado.vista.forEach( function ( v ) {
			burbuja( v.q, v.h, v.c );
		} );
		raiz.querySelectorAll( '[data-ia-resp]' ).forEach( function ( b ) {
			b.disabled = !! estado.resuelto;
		} );
	}
	function anotar( q, h, c ) {
		estado.vista.push( { q: q, h: h, c: c || '' } );
		guardar();
		var hola = lista.querySelector( '.esc-ia__hola' );
		if ( hola ) {
			hola.remove();
		}
		return burbuja( q, h, c );
	}
	function enviar( datos ) {
		ocupado = true;
		var pensando = burbuja( 'ia', '<span class="esc-ia__dots"><i></i><i></i><i></i></span>', 'is-pensando' );
		var fd = new FormData();
		fd.append( 'action', 'malka_crm_asistente' );
		fd.append( 'nonce', raiz.getAttribute( 'data-nonce' ) );
		fd.append( 'mensajes', JSON.stringify( estado.mensajes ) );
		Object.keys( datos ).forEach( function ( k ) {
			fd.append( k, datos[ k ] );
		} );
		fetch( window.malkaEscritorio.ajax, { method: 'POST', credentials: 'same-origin', body: fd } ).then( function ( r ) {
			return r.json();
		} ).then( function ( j ) {
			pensando.remove();
			ocupado = false;
			if ( ! j || ! j.success ) {
				anotar( 'ia', '<p>' + esc( ( j && j.data && j.data.message ) || 'No pude conectarme. Intenta de nuevo.' ) + '</p>', 'is-error' );
				return;
			}
			var d = j.data;
			estado.mensajes = d.mensajes || [];
			( d.hecho || [] ).forEach( function ( h ) {
				anotar( 'ia', '<p>' + ( h.ok ? '✓ ' : '✗ ' ) + esc( h.texto ) + ( h.url ? ' · <a href="' + esc( h.url ) + '">Abrir</a>' : '' ) + '</p>', h.ok ? 'is-hecho' : 'is-error' );
			} );
			if ( d.texto ) {
				anotar( 'ia', md( d.texto ) );
			}
			if ( d.confirmar && d.confirmar.length ) {
				estado.resuelto = false;
				var h = '<p class="esc-ia__k">Antes de hacerlo, confirma:</p><ul>' + d.confirmar.map( function ( c ) {
					return '<li>' + esc( c ) + '</li>';
				} ).join( '' ) + '</ul><div class="esc-ia__acc"><button type="button" class="esc-btn esc-btn--soft" data-ia-resp="no">Cancelar</button><button type="button" class="esc-btn esc-btn--primary" data-ia-resp="si">Confirmar</button></div>';
				anotar( 'ia', h, 'is-confirmar' );
			}
			guardar();
		} ).catch( function () {
			pensando.remove();
			ocupado = false;
			anotar( 'ia', '<p>No pude conectarme. Intenta de nuevo.</p>', 'is-error' );
		} );
	}
	function preguntar( t ) {
		t = ( t || '' ).trim();
		if ( ! t || ocupado || '1' !== raiz.getAttribute( 'data-listo' ) ) {
			return;
		}
		if ( estado.resuelto === false ) {
			// Una confirmación quedó sin responder: se cancela antes de seguir.
			estado.resuelto = true;
			anotar( 'yo', '<p>' + esc( t ) + '</p>' );
			var fdTexto = t;
			ocupado = true;
			var fd = new FormData();
			fd.append( 'action', 'malka_crm_asistente' );
			fd.append( 'nonce', raiz.getAttribute( 'data-nonce' ) );
			fd.append( 'mensajes', JSON.stringify( estado.mensajes ) );
			fd.append( 'respuesta', 'no' );
			fetch( window.malkaEscritorio.ajax, { method: 'POST', credentials: 'same-origin', body: fd } ).then( function ( r ) {
				return r.json();
			} ).then( function ( j ) {
				ocupado = false;
				if ( j && j.success ) {
					estado.mensajes = j.data.mensajes || [];
				}
				enviar( { texto: fdTexto } );
			} );
			return;
		}
		anotar( 'yo', '<p>' + esc( t ) + '</p>' );
		enviar( { texto: t } );
	}

	cargar();
	pintarVista();

	raiz.addEventListener( 'click', function ( e ) {
		if ( e.target.closest( '[data-ia-abrir]' ) ) {
			panel.hidden = ! panel.hidden;
			raiz.classList.toggle( 'is-open', ! panel.hidden );
			if ( ! panel.hidden ) {
				lista.scrollTop = lista.scrollHeight;
				if ( campo && ! campo.disabled ) {
					campo.focus();
				}
			}
			return;
		}
		if ( e.target.closest( '[data-ia-cerrar]' ) ) {
			panel.hidden = true;
			raiz.classList.remove( 'is-open' );
			return;
		}
		if ( e.target.closest( '[data-ia-nuevo]' ) ) {
			estado = { mensajes: [], vista: [] };
			guardar();
			window.location.reload();
			return;
		}
		var ej = e.target.closest( '[data-ia-ej]' );
		if ( ej ) {
			preguntar( ej.textContent );
			return;
		}
		var r = e.target.closest( '[data-ia-resp]' );
		if ( r && ! ocupado && estado.resuelto === false ) {
			estado.resuelto = true;
			raiz.querySelectorAll( '[data-ia-resp]' ).forEach( function ( b ) {
				b.disabled = true;
			} );
			var acc = r.closest( '.esc-ia__acc' );
			if ( acc ) {
				acc.outerHTML = '<p class="esc-ia__k">' + ( 'si' === r.getAttribute( 'data-ia-resp' ) ? 'Confirmado' : 'Cancelado' ) + '</p>';
			}
			var v = estado.vista[ estado.vista.length - 1 ];
			if ( v ) {
				v.h = v.h.replace( /<div class="esc-ia__acc">[\s\S]*<\/div>$/, '<p class="esc-ia__k">' + ( 'si' === r.getAttribute( 'data-ia-resp' ) ? 'Confirmado' : 'Cancelado' ) + '</p>' );
			}
			guardar();
			enviar( { respuesta: r.getAttribute( 'data-ia-resp' ) } );
		}
	} );
	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		var t = campo.value;
		campo.value = '';
		campo.style.height = '';
		preguntar( t );
	} );
	campo.addEventListener( 'keydown', function ( e ) {
		if ( 'Enter' === e.key && ! e.shiftKey ) {
			e.preventDefault();
			form.requestSubmit ? form.requestSubmit() : form.dispatchEvent( new Event( 'submit' ) );
		}
	} );
	campo.addEventListener( 'input', function () {
		campo.style.height = 'auto';
		campo.style.height = Math.min( 140, campo.scrollHeight ) + 'px';
	} );
	// Lo que se escribe acá no es parte de ningún formulario de la página.
	[ 'input', 'change' ].forEach( function ( ev ) {
		raiz.addEventListener( ev, function ( e ) {
			e.stopPropagation();
		} );
	} );
}() );

/* Tarea: «Es una publicación» muestra u oculta el bloque de copy, archivo y fecha. */
document.addEventListener( 'change', function ( e ) {
	if ( ! e.target.matches || ! e.target.matches( '[data-es-pub]' ) ) {
		return;
	}
	var c = e.target.closest( '.esc-tarea__det' );
	var b = c ? c.querySelector( '.esc-td__pub, .esc-fx__pubs' ) : null;
	var si = 'SELECT' === e.target.tagName ? '1' === e.target.value : e.target.checked;
	// Ella lo decidió: deja de deducirse del nombre.
	var h = e.target.parentNode.querySelector( '[data-es-pub-h]' );
	if ( h ) {
		h.value = 'SELECT' === e.target.tagName ? e.target.value : '0';
	}
	if ( b ) {
		b.hidden = ! si;
		if ( si && 'DETAILS' === b.tagName ) {
			b.open = true;
		}
	}
} );

/* Plantilla: al ponerle la etiqueta RRSS, las tareas de publicaciones se marcan solas. */
( function () {
	'use strict';
	var tags = document.getElementById( 'tpl-tags' );
	if ( ! tags ) {
		return;
	}
	var re = /publicaci|\bpost\b|\breel|carrusel|historia|stor(y|ies)/i;
	tags.addEventListener( 'change', function () {
		var rrss = /rrss|redes|social|instagram|tiktok/i.test( tags.value );
		document.querySelectorAll( '#esc-arbol .esc-tarea' ).forEach( function ( fila ) {
			var cb = fila.querySelector( '[data-es-pub]' );
			var h  = fila.querySelector( '[data-es-pub-h]' );
			var n  = fila.querySelector( '.esc-tarea__n' );
			if ( ! cb || ! h || '' !== h.value || fila.classList.contains( 'is-etapa' ) ) {
				return;
			}
			var nombre = n ? n.value.normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' ) : '';
			cb.checked = rrss && re.test( nombre );
			var b = fila.querySelector( '.esc-td__pub' );
			if ( b ) {
				b.hidden = ! cb.checked;
			}
		} );
	} );
}() );

/* Negocios: cambiar el estado desde la lista, sin entrar al negocio. */
( function () {
	'use strict';
	var lista = document.getElementById( 'esc-nl' );
	if ( ! lista || ! lista.getAttribute( 'data-estados' ) || ! window.malkaEscritorio ) {
		return;
	}
	var estados = JSON.parse( lista.getAttribute( 'data-estados' ) );
	var menu = null;
	function cerrar() {
		if ( menu ) {
			menu.remove();
			menu = null;
		}
	}
	function abrir( chip ) {
		cerrar();
		menu = document.createElement( 'div' );
		menu.className = 'esc-stmenu';
		Object.keys( estados ).forEach( function ( k ) {
			var b = document.createElement( 'button' );
			b.type = 'button';
			b.className = 'esc-stmenu__i' + ( k === chip.getAttribute( 'data-estado' ) ? ' is-on' : '' );
			b.setAttribute( 'data-k', k );
			b.innerHTML = '<span class="esc-st esc-st--' + ( 'entregado' === k ? 'ok' : ( 'pausado' === k ? 'off' : 'run' ) ) + '">' + estados[ k ] + '</span>';
			menu.appendChild( b );
		} );
		( document.querySelector( '.malka-esc' ) || document.body ).appendChild( menu );
		var r = chip.getBoundingClientRect();
		menu.style.top = ( window.scrollY + r.bottom + 6 ) + 'px';
		menu.style.left = ( window.scrollX + r.left - 8 ) + 'px';
		menu.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( '[data-k]' );
			if ( ! b ) {
				return;
			}
			e.preventDefault();
			e.stopPropagation();
			var k = b.getAttribute( 'data-k' );
			cerrar();
			if ( k === chip.getAttribute( 'data-estado' ) ) {
				return;
			}
			var fd = new FormData();
			fd.append( 'action', 'malka_crm_neg_estado' );
			fd.append( 'pid', chip.getAttribute( 'data-estado-neg' ) );
			fd.append( 'estado', k );
			fd.append( 'nonce', lista.getAttribute( 'data-nonce-estado' ) );
			chip.style.opacity = '.5';
			fetch( window.malkaEscritorio.ajax, { method: 'POST', credentials: 'same-origin', body: fd } ).then( function ( r ) {
				return r.json();
			} ).then( function ( j ) {
				chip.style.opacity = '';
				if ( ! j || ! j.success ) {
					window.alert( ( j && j.data && j.data.message ) || 'Error' );
					return;
				}
				chip.setAttribute( 'data-estado', k );
				chip.className = 'esc-st esc-st--' + j.data.tono + ' esc-st--cambia';
				chip.textContent = j.data.etiqueta;
				var fila = chip.closest( '.esc-nl' );
				if ( fila ) {
					fila.setAttribute( 'data-grupo', 'entregado' === k ? 'entregado' : 'curso' );
				}
			} );
		} );
	}
	document.addEventListener( 'click', function ( e ) {
		var chip = e.target.closest ? e.target.closest( '[data-estado-neg]' ) : null;
		if ( chip ) {
			e.preventDefault();
			e.stopPropagation();
			if ( menu && menu.__de === chip ) {
				cerrar();
				return;
			}
			abrir( chip );
			menu.__de = chip;
			return;
		}
		if ( menu && ! e.target.closest( '.esc-stmenu' ) ) {
			cerrar();
		}
	}, true );
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			cerrar();
		}
	} );
	window.addEventListener( 'scroll', cerrar, { passive: true } );
}() );

/* Publicación: lo propio de un reel (de prueba, se enlaza con) sólo si el formato es reel. */
document.addEventListener( 'change', function ( e ) {
	if ( ! e.target.matches || ! e.target.matches( '[data-pub-formato]' ) ) {
		return;
	}
	var g = e.target.closest( '.esc-td__pubg, .esc-fx__pub' );
	if ( g ) {
		g.querySelectorAll( '[data-solo-reel]' ).forEach( function ( r ) {
			r.hidden = 'reel' !== e.target.value;
		} );
	}
} );


/* ── Facturas de compra: juntar la del proveedor con la emitida, o separarlas ── */
( function () {
	'use strict';

	var zona = document.querySelector( '[data-drop-ext]' );
	if ( ! zona ) {
		return;
	}
	var ajax  = zona.getAttribute( 'data-ajax' );
	var nonce = zona.getAttribute( 'data-nonce' );

	function pedir( que, a, b, el ) {
		var fd = new FormData();
		fd.append( 'action', 'malka_crm_doc_juntar' );
		fd.append( 'nonce', nonce );
		fd.append( 'que', que );
		fd.append( 'a', a );
		fd.append( 'b', b || '' );
		var fila = el && el.closest( '.esc-em' );
		if ( fila ) {
			fila.classList.add( 'is-guardando' );
		}
		return fetch( ajax, { method: 'POST', credentials: 'same-origin', body: fd } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( r ) {
				if ( r && r.success ) {
					window.location.reload();
					return;
				}
				if ( fila ) {
					fila.classList.remove( 'is-guardando' );
				}
				window.alert( ( r && r.data && r.data.msg ) || 'No se pudo.' );
			} );
	}

	document.addEventListener( 'click', function ( e ) {
		var si = e.target.closest ? e.target.closest( '[data-juntar-si]' ) : null;
		if ( si ) {
			e.preventDefault();
			pedir( 'juntar', si.getAttribute( 'data-juntar-si' ), si.getAttribute( 'data-con' ), si );
			return;
		}
		var no = e.target.closest ? e.target.closest( '[data-juntar-no]' ) : null;
		if ( no ) {
			e.preventDefault();
			pedir( 'no', no.getAttribute( 'data-juntar-no' ), '', no );
			return;
		}
		var sep = e.target.closest ? e.target.closest( '[data-separar]' ) : null;
		if ( sep ) {
			e.preventDefault();
			if ( window.confirm( sep.getAttribute( 'data-confirmar-js' ) ) ) {
				pedir( 'separar', sep.getAttribute( 'data-separar' ), '', sep );
			}
		}
	} );

	document.addEventListener( 'change', function ( e ) {
		var sel = e.target;
		if ( ! sel.matches || ! sel.matches( 'select[data-juntar]' ) || ! sel.value ) {
			return;
		}
		e.stopPropagation();
		var texto = sel.options[ sel.selectedIndex ].text;
		var pend  = 'pendiente' === sel.getAttribute( 'data-lado' );
		if ( ! window.confirm( '¿Juntar con ' + texto + '? La emitida se queda con su número y su neto, y recibe la factura del proveedor. Se puede separar después.' ) ) {
			sel.value = '';
			return;
		}
		pedir( 'juntar', pend ? sel.getAttribute( 'data-juntar' ) : sel.value, pend ? sel.value : sel.getAttribute( 'data-juntar' ), sel );
	}, true );
}() );

/* Campos de una línea que crecen hacia abajo (el nombre del negocio). Enter
   no salta de línea: guarda como cualquier otro campo de texto. */
( function () {
	var campos = document.querySelectorAll( 'textarea[data-una-linea]' );
	if ( ! campos.length ) {
		return;
	}
	var nativo = window.CSS && CSS.supports && CSS.supports( 'field-sizing', 'content' );
	function medir( t ) {
		if ( nativo ) {
			return;
		}
		t.style.height = 'auto';
		t.style.height = t.scrollHeight + 2 + 'px';
	}
	Array.prototype.forEach.call( campos, function ( t ) {
		t.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key ) {
				e.preventDefault();
				t.blur();
			}
		} );
		t.addEventListener( 'input', function () {
			if ( /[\r\n]/.test( t.value ) ) {
				t.value = t.value.replace( /[\r\n]+/g, ' ' );
			}
			medir( t );
		} );
		medir( t );
		window.addEventListener( 'resize', function () { medir( t ); } );
	} );
} )();


/* ── Plantillas › Pasos de cada publicación: se guarda solo ── */
( function () {
	'use strict';

	var caja = document.querySelector( '[data-pasos]' );
	if ( ! caja ) {
		return;
	}
	var tpl    = caja.querySelector( '[data-paso-tpl]' );
	var timers = {};

	function contar( f ) {
		var n = f.querySelectorAll( '.esc-paso' ).length;
		var c = f.querySelector( '[data-pasos-cuenta]' );
		if ( c ) {
			c.textContent = n ? n + ( 1 === n ? ' paso' : ' pasos' ) : 'Sin pasos';
		}
	}

	function guardar( f ) {
		var k = f.getAttribute( 'data-formato' );
		clearTimeout( timers[ k ] );
		var st = f.querySelector( '[data-pasos-st]' );
		timers[ k ] = setTimeout( function () {
			var pasos = [];
			Array.prototype.forEach.call( f.querySelectorAll( '.esc-paso' ), function ( r ) {
				var n = r.querySelector( '[data-paso-n]' ).value.trim();
				if ( n ) {
					var pv = r.querySelector( '[data-paso-v]' );
					pasos.push( [ n, r.querySelector( '[data-paso-o]' ).value, pv && '1' === pv.value ? 1 : 0 ] );
				}
			} );
			var fd = new FormData();
			fd.append( 'action', 'malka_crm_pasos_pub' );
			fd.append( 'nonce', caja.getAttribute( 'data-nonce' ) );
			fd.append( 'formato', k );
			fd.append( 'pasos', JSON.stringify( pasos ) );
			if ( st ) {
				st.textContent = 'Guardando…';
			}
			fetch( caja.getAttribute( 'data-ajax' ), { method: 'POST', credentials: 'same-origin', body: fd } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( r ) {
					if ( st ) {
						st.textContent = r && r.success ? ( r.data && r.data.tocadas ? 'Guardado · ' + r.data.tocadas + ( 1 === r.data.tocadas ? ' subtarea actualizada' : ' subtareas actualizadas' ) : 'Guardado' ) : 'No se pudo guardar';
					}
					if ( r && r.success && window.malkaEscritorio && window.malkaEscritorio.pasosPub ) {
						window.malkaEscritorio.pasosPub[ k ] = pasos;
					}
				} )
				.catch( function () {
					if ( st ) {
						st.textContent = 'No se pudo guardar';
					}
				} );
		}, 600 );
	}

	caja.addEventListener( 'input', function ( e ) {
		var f = e.target.closest( '.esc-pasos__f' );
		if ( f ) {
			guardar( f );
		}
	} );
	caja.addEventListener( 'change', function ( e ) {
		if ( e.target.matches( '[data-paso-v]' ) ) {
			e.target.classList.toggle( 'is-priv', '1' === e.target.value );
		}
		var f = e.target.closest( '.esc-pasos__f' );
		if ( f ) {
			guardar( f );
		}
	} );
	caja.addEventListener( 'click', function ( e ) {
		var f = e.target.closest( '.esc-pasos__f' );
		if ( ! f ) {
			return;
		}
		if ( e.target.closest( '[data-paso-x]' ) ) {
			e.target.closest( '.esc-paso' ).remove();
			contar( f );
			guardar( f );
			return;
		}
		if ( e.target.closest( '[data-paso-add]' ) && tpl ) {
			var l = f.querySelector( '[data-pasos-l]' );
			l.appendChild( tpl.content.firstElementChild.cloneNode( true ) );
			contar( f );
			var n = l.lastElementChild.querySelector( '[data-paso-n]' );
			if ( n ) {
				n.focus();
			}
		}
	} );
}() );

/*
 * Negocio: plegar las subtareas de una tarea (o las tareas de una etapa) y
 * dejar a la vista sólo la principal. Se recuerda por tarea en este
 * navegador; las filas plegadas se siguen guardando igual.
 */
( function () {
	'use strict';

	var arbol = document.getElementById( 'esc-arbol' );
	if ( ! arbol ) {
		return;
	}
	var clave = 'malka-plegadas-' + ( new URLSearchParams( window.location.search ).get( 'id' ) || '0' );
	var plegadas = {};
	try {
		plegadas = JSON.parse( window.localStorage.getItem( clave ) || '{}' ) || {};
	} catch ( e ) {
		plegadas = {};
	}
	function recordar() {
		try {
			window.localStorage.setItem( clave, JSON.stringify( plegadas ) );
		} catch ( e ) {}
	}
	function filas() {
		return Array.prototype.slice.call( arbol.querySelectorAll( ':scope > .esc-tarea' ) );
	}
	function nivel( f ) {
		return parseInt( f.getAttribute( 'data-nivel' ), 10 ) || 1;
	}
	function idDe( f ) {
		var h = f.querySelector( 'input[type="hidden"][name$="[id]"]' );
		return h && h.value ? h.value : '';
	}
	var ICONO = '<svg viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 4l4 4-4 4"/></svg>';

	function pintar() {
		var todas = filas();
		var techo = 99; // Nivel desde el que se esconde (el de una madre plegada).
		todas.forEach( function ( f, i ) {
			var n    = nivel( f );
			var hijos = 0;
			for ( var k = i + 1; k < todas.length && nivel( todas[ k ] ) > n; k++ ) {
				hijos++;
			}
			// ¿La esconde una madre plegada?
			if ( n <= techo ) {
				techo = 99;
			}
			f.classList.toggle( 'is-oculta', techo < 99 && n > techo );

			var b = f.querySelector( ':scope > .esc-tarea__plg' );
			if ( ! hijos ) {
				if ( b ) {
					b.remove();
				}
				var r0 = f.querySelector( '.esc-tarea__res[data-subs]' );
				if ( r0 ) {
					r0.removeAttribute( 'data-subs' );
					r0.removeAttribute( 'title' );
				}
				f.classList.remove( 'is-plegada', 'tiene-plg' );
				return;
			}
			if ( ! b ) {
				b = document.createElement( 'button' );
				b.type = 'button';
				b.className = 'esc-tarea__plg';
				b.innerHTML = ICONO;
				f.appendChild( b );
			}
			var id = idDe( f );
			var cerrada = !! ( id && plegadas[ id ] );
			f.classList.add( 'tiene-plg' );
			f.classList.toggle( 'is-plegada', cerrada );
			b.setAttribute( 'aria-expanded', cerrada ? 'false' : 'true' );
			var txt = cerrada
				? 'Mostrar ' + hijos + ( 1 === hijos ? ' subtarea' : ' subtareas' )
				: 'Esconder ' + ( 1 === hijos ? 'su subtarea' : 'sus ' + hijos + ' subtareas' );
			b.title = txt;
			b.setAttribute( 'aria-label', txt );
			// En el resumen también se lee y se toca: «10 subtareas · …».
			var res = f.querySelector( '.esc-tarea__res' );
			if ( res ) {
				res.setAttribute( 'data-subs', ( cerrada ? '▸ ' : '▾ ' ) + hijos + ( 1 === hijos ? ' subtarea' : ' subtareas' ) );
				res.title = txt;
			}
			// En el escritorio va delante del número.
			var num = f.querySelector( '.esc-tarea__num' );
			if ( num ) {
				f.style.setProperty( '--plg-x', num.offsetLeft + 'px' );
				f.style.setProperty( '--plg-y', ( num.offsetTop + num.offsetHeight / 2 - 10 ) + 'px' );
			}
			if ( cerrada && ! f.classList.contains( 'is-oculta' ) ) {
				techo = n;
			}
		} );
	}

	var pendiente = false;
	function luego() {
		if ( pendiente ) {
			return;
		}
		pendiente = true;
		window.requestAnimationFrame( function () {
			pendiente = false;
			pintar();
		} );
	}

	arbol.addEventListener( 'click', function ( e ) {
		var b = e.target.closest ? e.target.closest( '.esc-tarea__plg, .tiene-plg > .esc-tarea__res' ) : null;
		if ( ! b ) {
			return;
		}
		e.preventDefault();
		e.stopPropagation();
		var f = b.closest( '.esc-tarea' );
		var id = idDe( f );
		if ( ! id ) {
			// Una fila nueva todavía sin id: se le da uno, como al guardar.
			var h = f.querySelector( 'input[type="hidden"][name$="[id]"]' );
			if ( ! h ) {
				return;
			}
			h.value = 't' + Date.now().toString( 36 ) + Math.random().toString( 36 ).slice( 2, 6 );
			id = h.value;
		}
		if ( plegadas[ id ] ) {
			delete plegadas[ id ];
		} else {
			plegadas[ id ] = 1;
		}
		recordar();
		pintar();
	} );

	new MutationObserver( luego ).observe( arbol, { childList: true, subtree: true, attributes: true, attributeFilter: [ 'data-nivel' ] } );
	window.addEventListener( 'resize', luego );
	pintar();
}() );


/* La altura de la cabecera, para que lo que se pega arriba quede justo debajo. */
( function () {
	'use strict';
	var raiz = document.querySelector( '.malka-esc' );
	var cab = document.querySelector( '.esc-head' );
	if ( ! raiz || ! cab ) {
		return;
	}
	function medir() {
		var fija = 'sticky' === getComputedStyle( cab ).position || 'fixed' === getComputedStyle( cab ).position;
		raiz.style.setProperty( '--esc-head-h', ( fija ? Math.round( cab.getBoundingClientRect().height ) : 0 ) + 'px' );
	}
	medir();
	window.addEventListener( 'resize', medir );
}() );

/* Tareas › «Lo que hizo el cliente»: «Visto» la saca de la lista. */
( function () {
	'use strict';
	var caja = document.querySelector( '[data-hc]' );
	if ( ! caja ) {
		return;
	}
	caja.addEventListener( 'click', function ( e ) {
		var b = e.target.closest( '[data-hc-visto]' );
		if ( ! b ) {
			return;
		}
		e.preventDefault();
		var fila = b.closest( '.esc-hc__w' );
		var fd = new FormData();
		fd.append( 'action', 'malka_crm_hc_visto' );
		fd.append( 'nonce', caja.getAttribute( 'data-nonce' ) );
		fd.append( 'clave', b.getAttribute( 'data-hc-visto' ) );
		fila.classList.add( 'is-saliendo' );
		fetch( caja.getAttribute( 'data-ajax' ), { method: 'POST', credentials: 'same-origin', body: fd } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( r ) {
				if ( ! r || ! r.success ) {
					throw new Error( 'no' );
				}
				window.setTimeout( function () {
					fila.remove();
					if ( ! caja.querySelector( '.esc-hc__w' ) ) {
						caja.remove();
					}
				}, 250 );
			} )
			.catch( function () {
				fila.classList.remove( 'is-saliendo' );
			} );
	} );
}() );

/* Detalle de tarea: «Qué le preguntas» sólo si se le pide nota o grabación. */
document.addEventListener( 'change', function ( e ) {
	if ( ! e.target.matches || ! e.target.matches( '[data-respuesta]' ) ) {
		return;
	}
	var c = e.target.closest( '.esc-td' );
	var p = c ? c.querySelector( '.esc-td__preg' ) : null;
	if ( p ) {
		p.hidden = [ 'nota', 'audio', 'ambas' ].indexOf( e.target.value ) < 0;
	}
} );


/* ── Detalle de tarea como ficha: fechas, publicación, extras y avance en su lugar ── */
( function () {
	'use strict';
	var arbol = document.getElementById( 'esc-arbol' );
	if ( ! arbol ) {
		return;
	}
	var MES  = [ 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic' ];
	var DSEM = [ 'Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado' ];

	function dia( iso ) {
		var p = String( iso || '' ).slice( 0, 10 ).split( '-' );
		return 3 === p.length && p[ 2 ] ? parseInt( p[ 2 ], 10 ) + ' ' + MES[ parseInt( p[ 1 ], 10 ) - 1 ] : '';
	}

	function crecer( t ) {
		if ( ! t || ! t.offsetParent || ( window.CSS && CSS.supports && CSS.supports( 'field-sizing', 'content' ) ) ) {
			return;
		}
		t.style.height = 'auto';
		if ( t.scrollHeight > 0 ) {
			t.style.height = t.scrollHeight + 'px';
		}
	}

	// «La tarea se entrega el … · se publica el …»: son fechas distintas.
	function fechas( fila ) {
		var caja = fila.querySelector( '[data-pub-dif]' );
		if ( ! caja ) {
			return;
		}
		var ent = fila.querySelector( '[data-entrega]' );
		var pub = fila.querySelector( '[data-pub-fecha]' );
		var e   = dia( ent ? ent.value : '' );
		var p   = dia( pub ? pub.value : '' );
		var h   = '';
		if ( e ) {
			h += 'La tarea se entrega el <b>' + e + '</b>';
		}
		if ( p ) {
			h += ( h ? ' · se publica el ' : 'Se publica el ' ) + '<b>' + p + '</b>';
		}
		caja.innerHTML = h;
	}

	// Subtareas de una fila: las que siguen y empiezan con su número.
	function subtareas( fila ) {
		var num  = fila.querySelector( '[data-numero]' );
		var base = num ? String( num.value ).trim() : '';
		var out  = [];
		if ( ! base ) {
			return out;
		}
		var sig = fila.nextElementSibling;
		while ( sig && sig.classList.contains( 'esc-tarea' ) ) {
			var n = sig.querySelector( '[data-numero]' );
			var v = n ? String( n.value ).trim() : '';
			if ( 0 !== v.indexOf( base + '.' ) ) {
				break;
			}
			if ( v.split( '.' ).length === base.split( '.' ).length + 1 ) {
				out.push( sig );
			}
			sig = sig.nextElementSibling;
		}
		return out;
	}

	function avance( fila ) {
		var r = fila.querySelector( '[data-av-subs]' );
		if ( ! r || fila.classList.contains( 'is-etapa' ) ) {
			return;
		}
		var subs = subtareas( fila );
		if ( ! subs.length ) {
			r.textContent = 'Avance';
			return;
		}
		var ok = subs.filter( function ( s ) {
			var c = s.querySelector( 'input[name$="[done]"]' );
			return c && c.checked;
		} ).length;
		r.textContent = 'Subtareas · ' + ok + ' de ' + subs.length + ( 1 === subs.length ? ' lista' : ' listas' );
	}

	function ficha( fila ) {
		fila.querySelectorAll( '.esc-fx textarea' ).forEach( crecer );
		fechas( fila );
		avance( fila );
	}
	window.malkaFicha = ficha;

	arbol.querySelectorAll( '.esc-tarea' ).forEach( fechas );

	arbol.addEventListener( 'input', function ( e ) {
		if ( e.target.matches( '.esc-fx textarea' ) ) {
			crecer( e.target );
		}
		if ( e.target.matches( '[data-av]' ) ) {
			var v   = Math.max( 0, Math.min( 100, parseInt( e.target.value, 10 ) || 0 ) );
			var bar = e.target.parentNode.querySelector( '[data-av-bar]' );
			if ( bar ) {
				bar.style.width = v + '%';
			}
		}
	} );

	arbol.addEventListener( 'change', function ( e ) {
		var fila = e.target.closest( '.esc-tarea' );
		if ( ! fila ) {
			return;
		}
		// Fecha y hora de publicación: se juntan en el campo que se guarda.
		if ( e.target.matches( '[data-pub-dia], [data-pub-hora]' ) ) {
			var f  = e.target.closest( '[data-pub-f]' );
			var d  = f.querySelector( '[data-pub-dia]' ).value;
			var hh = f.querySelector( '[data-pub-hora]' );
			var h  = f.querySelector( '[data-pub-fecha]' );
			if ( d && hh && ! hh.value ) {
				hh.value = '09:00';
			}
			h.value = d ? d + 'T' + ( hh ? hh.value : '09:00' ) : '';
			var p  = d ? d.split( '-' ) : null;
			var dt = p ? new Date( +p[ 0 ], +p[ 1 ] - 1, +p[ 2 ] ) : null;
			f.querySelector( '[data-pub-num]' ).textContent = dt ? dt.getDate() : '—';
			f.querySelector( '[data-pub-dsem]' ).textContent = dt ? DSEM[ dt.getDay() ] : 'Sin fecha';
			h.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
		if ( e.target.matches( '[data-entrega], [data-pub-fecha]' ) ) {
			fechas( fila );
		}
		if ( e.target.matches( 'input[name$="[done]"]' ) ) {
			// La madre más cercana vuelve a contar sus subtareas.
			var ant = fila.previousElementSibling;
			while ( ant && ant.classList.contains( 'esc-tarea' ) ) {
				if ( subtareas( ant ).indexOf( fila ) > -1 ) {
					avance( ant );
					break;
				}
				ant = ant.previousElementSibling;
			}
		}
	} );

	arbol.addEventListener( 'click', function ( e ) {
		// Encuesta y voz en off: aparecen al tocar su «+».
		var mas = e.target.closest( '[data-extra-abre]' );
		if ( mas ) {
			var k   = mas.getAttribute( 'data-extra-abre' );
			var box = mas.closest( '.esc-fx__pc' ).querySelector( '[data-extra="' + k + '"]' );
			if ( box ) {
				box.hidden = false;
				var t = box.querySelector( 'textarea' );
				if ( t ) {
					t.focus();
				}
			}
			var fila = mas.parentNode;
			mas.remove();
			if ( fila && ! fila.children.length ) {
				fila.remove();
			}
			return;
		}
		// Tocar una celda de fecha abre su calendario, no sólo el iconito.
		var c = e.target.closest( '.esc-fx__d.is-fecha, .esc-fx__dia, .esc-fx__hora' );
		if ( c ) {
			var inp = c.matches( 'input' ) ? c : c.querySelector( 'input' );
			if ( inp && inp.showPicker ) {
				try {
					inp.showPicker();
				} catch ( x ) {}
			}
		}
	} );
}() );

/* ──────────────────  Cobros en tu saldo  ───────────────────────────────
 *
 * La suma de abajo se mueve a medida que marca y desmarca: así ve en qué
 * número va a quedar el saldo antes de guardar, no después.
 */
( function () {
	'use strict';

	var form = document.getElementById( 'esc-cobros-form' );

	if ( ! form ) {
		return;
	}

	var cajas = [].slice.call( form.querySelectorAll( 'input[name="cobro[]"]' ) );
	var pie   = document.getElementById( 'esc-cobros-suma' );
	var plant = pie ? pie.textContent : '';

	/** Formato chileno: 1.234.567. */
	function pesos( n ) {
		return '$' + String( Math.round( n ) ).replace( /\B(?=(\d{3})+(?!\d))/g, '.' );
	}

	function contar() {
		var total = 0;

		cajas.forEach( function ( c ) {
			if ( c.checked ) {
				total += parseInt( c.getAttribute( 'data-monto' ), 10 ) || 0;
			}
		} );

		if ( pie ) {
			// Se reemplaza sólo la cifra: el resto de la frase lo pone PHP,
			// que es quien sabe en qué idioma está.
			pie.textContent = plant.replace( /\$[\d.]+/, pesos( total ) );
		}
	}

	cajas.forEach( function ( c ) {
		c.addEventListener( 'change', contar );
	} );

	function todas( valor ) {
		cajas.forEach( function ( c ) {
			c.checked = valor;
		} );
		contar();
	}

	var si = document.getElementById( 'esc-cobros-todos' );
	var no = document.getElementById( 'esc-cobros-ninguno' );

	if ( si ) {
		si.addEventListener( 'click', function () {
			todas( true );
		} );
	}
	if ( no ) {
		no.addEventListener( 'click', function () {
			todas( false );
		} );
	}

	contar();
}() );

/* ──────────────────  Abrir una sección sin salir de la página  ─────────
 *
 * Las fichas de Estudio y de Bienestar dejan de llevarte a otra pantalla:
 * abren la sección ahí mismo, debajo.
 *
 * Adentro va la vista de siempre en modo «sólo contenido» (?embed=1),
 * dentro de un marco. Podría pegarse el HTML directamente, pero cada
 * sección trae su propio JavaScript —el buscador de negocios, las casillas
 * de tareas, arrastrar para reordenar— y ese código se engancha una sola
 * vez, al cargar la página: pegado quedaría todo mudo. Dentro del marco
 * cada sección arranca como si fuera su propia página, y funciona entera.
 *
 * El marco crece hasta donde llega su contenido, así que no hay una barra
 * de scroll adentro de otra.
 */
( function () {
	'use strict';

	var caja = document.getElementById( 'esc-inline' );

	if ( ! caja ) {
		return;
	}

	var fichas = [].slice.call( document.querySelectorAll( '[data-inline]' ) );
	var marco  = caja.querySelector( '.esc-inline__f' );
	var titulo = caja.querySelector( '.esc-inline__t' );
	var entero = caja.querySelector( '.esc-inline__full' );
	var cargando = caja.querySelector( '.esc-inline__cargando' );
	var abierta = '';

	/** La misma dirección, pero pidiendo sólo el contenido. */
	function sinMarco( href ) {
		var u = new URL( href, window.location.href );
		u.searchParams.set( 'embed', '1' );
		return u.toString();
	}

	function cerrar() {
		caja.hidden = true;
		marco.removeAttribute( 'src' );
		abierta = '';
		fichas.forEach( function ( f ) {
			f.setAttribute( 'aria-expanded', 'false' );
			f.classList.remove( 'is-on' );
		} );
		if ( window.history.replaceState ) {
			window.history.replaceState( null, '', window.location.pathname + window.location.search );
		}
	}

	function abrir( ficha, mover ) {
		var href = ficha.getAttribute( 'href' );

		fichas.forEach( function ( f ) {
			var suya = f === ficha;
			f.setAttribute( 'aria-expanded', suya ? 'true' : 'false' );
			f.classList.toggle( 'is-on', suya );
		} );

		titulo.textContent = ficha.getAttribute( 'data-inline' ) || '';
		entero.setAttribute( 'href', href );
		caja.hidden = false;
		cargando.hidden = false;
		// Se parte con un alto razonable para que no salte de golpe; el
		// marco avisa el suyo apenas termina de cargar.
		marco.style.height = '420px';
		marco.setAttribute( 'src', sinMarco( href ) );
		abierta = ficha.getAttribute( 'data-vista' ) || href;

		if ( window.history.replaceState ) {
			window.history.replaceState( null, '', '#' + encodeURIComponent( href.replace( /\/$/, '' ).split( '/' ).pop() ) );
		}

		if ( mover !== false ) {
			caja.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
		}
	}

	fichas.forEach( function ( ficha ) {
		ficha.addEventListener( 'click', function ( e ) {
			/*
			 * Ctrl, cmd, shift o el botón del medio: que haga lo de siempre
			 * y abra en otra pestaña. Interceptar eso sería quitarle algo
			 * que ya tenía.
			 */
			if ( e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0 ) {
				return;
			}
			e.preventDefault();
			if ( ficha.classList.contains( 'is-on' ) ) {
				cerrar();
				return;
			}
			abrir( ficha );
		} );
	} );

	caja.querySelector( '.esc-inline__x' ).addEventListener( 'click', cerrar );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && ! caja.hidden ) {
			cerrar();
		}
	} );

	/* El marco dice cuánto mide y acá se le da ese alto. */
	window.addEventListener( 'message', function ( e ) {
		if ( e.origin !== window.location.origin || ! e.data || 'malka-alto' !== e.data.malka ) {
			return;
		}
		if ( e.source !== marco.contentWindow ) {
			return;
		}
		cargando.hidden = true;
		marco.style.height = Math.max( 220, parseInt( e.data.alto, 10 ) || 0 ) + 'px';
	} );

	// Si el marco no alcanza a avisar, igual se quita el «Cargando…».
	marco.addEventListener( 'load', function () {
		cargando.hidden = true;
	} );

	/* Al volver con el enlace puesto, se reabre la misma sección. */
	if ( window.location.hash ) {
		var quiere = decodeURIComponent( window.location.hash.slice( 1 ) );
		fichas.some( function ( f ) {
			if ( f.getAttribute( 'href' ).replace( /\/$/, '' ).split( '/' ).pop() === quiere ) {
				abrir( f, false );
				return true;
			}
			return false;
		} );
	}

	void abierta;
}() );

/* ── Del lado de adentro: avisar cuánto mide ──────────────────────────
 *
 * Sin esto el marco se queda con el alto que le pusieron y aparece una
 * barra de scroll dentro de otra, que es justo lo que no se quiere.
 */
( function () {
	'use strict';

	if ( window.parent === window || ! document.querySelector( '.malka-esc.is-embed' ) ) {
		return;
	}

	var ultimo = 0;

	function avisar() {
		var alto = Math.max(
			document.body.scrollHeight,
			document.documentElement.scrollHeight,
			document.body.offsetHeight
		);

		// Sólo cuando cambia de verdad: si no, dos píxeles de diferencia
		// disparan mensajes sin parar.
		if ( Math.abs( alto - ultimo ) < 4 ) {
			return;
		}

		ultimo = alto;
		try {
			window.parent.postMessage( { malka: 'malka-alto', alto: alto }, window.location.origin );
		} catch ( err ) {}
	}

	avisar();
	window.addEventListener( 'load', avisar );

	if ( window.ResizeObserver ) {
		new ResizeObserver( avisar ).observe( document.body );
	} else {
		setInterval( avisar, 600 );
	}
}() );

/*
 * La ventana de tarea nueva.
 *
 * Vive en todas las pantallas y la abre el + del encabezado. Es el mismo
 * patrón de la ventana de movimientos: la clase «open» la muestra, tocar
 * el fondo o Escape la cierra. No usa <dialog> para no quedar por encima
 * del menú del +, que es lo que la abre.
 */
( function () {
	'use strict';

	var caja = document.getElementById( 'esc-modal-tarea' );

	if ( ! caja ) {
		return;
	}

	function cerrar() {
		caja.classList.remove( 'open' );
	}

	function abrir() {
		// El menú del + se queda abierto detrás si no se cierra a mano.
		var menu = document.getElementById( 'esc-menu' );
		var mas  = document.getElementById( 'esc-add' );
		if ( menu ) { menu.classList.remove( 'open' ); }
		if ( mas ) { mas.setAttribute( 'aria-expanded', 'false' ); }

		caja.classList.add( 'open' );

		// El cursor en el primer campo: es una ventana para escribir.
		var primero = caja.querySelector( 'input[name="nombre"]' );
		if ( primero ) {
			primero.focus();
			primero.select();
		}
	}

	var boton = document.getElementById( 'esc-open-tarea' );
	if ( boton ) {
		boton.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			abrir();
		} );
	}

	caja.addEventListener( 'click', function ( e ) {
		if ( e.target === caja || ( e.target.closest && e.target.closest( '[data-cerrar-modal]' ) ) ) {
			cerrar();
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && caja.classList.contains( 'open' ) ) {
			cerrar();
		}
	} );
}() );

/*
 * Una tarea hecha ya no se está haciendo: marcar una apaga la otra.
 */
( function () {
	'use strict';

	document.addEventListener( 'change', function ( e ) {
		if ( ! e.target.matches ) {
			return;
		}

		var caja = e.target.closest( '#esc-modal-tarea-det' );

		if ( ! caja ) {
			return;
		}

		var hecha  = caja.querySelector( '[data-det-hecha]' );
		var hacien = caja.querySelector( '[data-det-haciendo]' );

		if ( ! hecha || ! hacien ) {
			return;
		}

		if ( e.target === hecha && hecha.checked ) {
			hacien.checked = false;
		}

		if ( e.target === hacien && hacien.checked ) {
			hecha.checked = false;
		}
	} );
}() );

/*
 * La ventana de detalle de una tarea.
 *
 * Tocar una fila la abre; la casilla de marcar y el resto de controles de
 * la fila siguen haciendo lo suyo. Una sola ventana para todas las filas:
 * se pide la tarea al abrir y se devuelve lo editado al guardar.
 */
( function () {
	'use strict';

	var caja = document.getElementById( 'esc-modal-tarea-det' );

	if ( ! caja ) {
		return;
	}

	var form  = caja.querySelector( '[data-det-form]' );
	var error = caja.querySelector( '[data-det-error]' );

	/*
	 * La llave y la dirección del servidor viven en el formulario de la
	 * lista; si en esta pantalla no hay lista, sirve cualquier panel que
	 * las lleve.
	 */
	var lista = document.querySelector( '.esc-tareas' ) || document.querySelector( '[data-nonce][data-ajax]' );

	if ( ! lista ) { return; }

	function q( sel ) {
		return caja.querySelector( sel );
	}

	function avisar( texto ) {
		var t = document.getElementById( 'esc-toast' );
		if ( ! t ) { return; }
		t.textContent = texto;
		t.classList.add( 'show' );
		window.clearTimeout( avisar.t );
		avisar.t = window.setTimeout( function () { t.classList.remove( 'show' ); }, 2400 );
	}

	function fallo( texto ) {
		if ( ! error ) { return; }
		error.textContent = texto || '';
		error.hidden = ! texto;
	}

	function cerrar() {
		caja.classList.remove( 'open' );
		fallo( '' );
	}

	// Lo del cliente sólo existe si hay un negocio detrás.
	function pintar() {
		var suelta = '0' === q( '[data-det-negocio]' ).value || 'personal' === q( '[data-det-negocio]' ).value;

		Array.prototype.forEach.call( caja.querySelectorAll( '[data-det-solo-negocio]' ), function ( e ) {
			e.hidden = suelta;
		} );
	}

	function llenar( d ) {
		q( '[data-det-pid]' ).value    = d.pid;
		q( '[data-det-tid]' ).value    = d.tid;
		q( '[data-det-nombre]' ).value = d.nombre;
		q( '[data-det-fin]' ).value    = d.fin;
		q( '[data-det-repite]' ).value = d.repite;
		q( '[data-det-owner]' ).value  = d.owner;
		q( '[data-det-notas]' ).value  = d.notas;

		q( '[data-det-negocio]' ).value = d.pid ? String( d.pid ) : ( d.personal ? 'personal' : '0' );
		q( '[data-det-privada]' ).checked = !! d.privada;
		q( '[data-det-hecha]' ).checked   = !! d.hecha;
		q( '[data-det-importante]' ).checked = !! d.importante;
		q( '[data-det-haciendo]' ).checked   = !! d.haciendo;
		q( '[data-det-borrar]' ).setAttribute( 'href', d.borrar );

		pintar();
	}

	function abrir( pid, tid ) {
		fallo( '' );

		var f = new FormData();
		f.append( 'action', 'malka_crm_tarea_detalle' );
		f.append( 'nonce', lista.getAttribute( 'data-nonce' ) );
		f.append( 'pid', pid );
		f.append( 'tid', tid );

		fetch( lista.getAttribute( 'data-ajax' ), {
			method: 'POST',
			credentials: 'same-origin',
			body: f
		} ).then( function ( r ) {
			return r.json();
		} ).then( function ( j ) {
			if ( ! j || ! j.success ) { throw new Error( 'no' ); }

			llenar( j.data );
			caja.classList.add( 'open' );
			q( '[data-det-nombre]' ).focus();
			q( '[data-det-nombre]' ).select();
		} ).catch( function () {
			avisar( 'No pude abrir esa tarea.' );
		} );
	}

	/*
	 * Se escucha en el documento y no en la lista: la misma ventana la
	 * abren las filas de «Lo que falta» y las de «Lo que hizo el cliente»,
	 * que son dos paneles distintos.
	 */
	document.addEventListener( 'click', function ( e ) {
		if ( ! e.target.closest ) { return; }

		var fila = e.target.closest( '.esc-pend[data-abrir], [data-abrir-tarea]' );

		if ( ! fila ) { return; }

		/*
		 * Lo que la fila ya sabía hacer manda: la casilla marca, el
		 * selector repite, la cruz borra, «Visto» archiva. Abrir es lo que
		 * pasa al tocar el resto.
		 */
		if ( e.target.closest( 'input, select, a, button, label' ) ) { return; }

		abrir( fila.getAttribute( 'data-pid' ) || '0', fila.getAttribute( 'data-tid' ) || '' );
	} );

	// Con el teclado, Enter y espacio hacen lo mismo que el clic.
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Enter' !== e.key && ' ' !== e.key ) { return; }
		if ( ! e.target.closest ) { return; }

		var fila = e.target.closest( '[data-abrir-tarea] .esc-hc__r' );

		if ( ! fila ) { return; }

		e.preventDefault();
		var w = fila.closest( '[data-abrir-tarea]' );
		abrir( w.getAttribute( 'data-pid' ) || '0', w.getAttribute( 'data-tid' ) || '' );
	} );

	caja.addEventListener( 'change', function ( e ) {
		if ( e.target.matches( '[data-det-negocio]' ) ) { pintar(); }
	} );

	caja.addEventListener( 'click', function ( e ) {
		if ( e.target === caja || ( e.target.closest && e.target.closest( '[data-cerrar-modal]' ) ) ) {
			cerrar();
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && caja.classList.contains( 'open' ) ) { cerrar(); }
	} );

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		fallo( '' );

		var f = new FormData();
		f.append( 'action', 'malka_crm_tarea_editar' );
		f.append( 'nonce', lista.getAttribute( 'data-nonce' ) );
		f.append( 'pid', q( '[data-det-pid]' ).value );
		f.append( 'tid', q( '[data-det-tid]' ).value );
		f.append( 'nombre', q( '[data-det-nombre]' ).value );
		f.append( 'negocio', q( '[data-det-negocio]' ).value );
		f.append( 'fin', q( '[data-det-fin]' ).value );
		f.append( 'repite', q( '[data-det-repite]' ).value );
		f.append( 'owner', q( '[data-det-owner]' ).value );
		f.append( 'notas', q( '[data-det-notas]' ).value );
		f.append( 'privada', q( '[data-det-privada]' ).checked ? '1' : '' );
		f.append( 'hecha', q( '[data-det-hecha]' ).checked ? '1' : '' );
		f.append( 'importante', q( '[data-det-importante]' ).checked ? '1' : '' );
		f.append( 'haciendo', q( '[data-det-haciendo]' ).checked ? '1' : '' );

		fetch( lista.getAttribute( 'data-ajax' ), {
			method: 'POST',
			credentials: 'same-origin',
			body: f
		} ).then( function ( r ) {
			return r.json();
		} ).then( function ( j ) {
			if ( ! j || ! j.success ) {
				fallo( ( j && j.data && j.data.message ) || 'No se pudo guardar.' );
				return;
			}

			/*
			 * Cambió de negocio o de lado: la fila tiene que cambiar de
			 * grupo y de orden, así que se recarga en vez de parchar el
			 * html y arriesgar que quede en el grupo equivocado.
			 */
			window.location.reload();
		} ).catch( function () {
			fallo( 'No se pudo guardar. Revisa tu conexión.' );
		} );
	} );
}() );

/*
 * La ventana de un costo del mes.
 *
 * Tocar una fila de «Pagos» la abre. El círculo de marcar pagado sigue
 * siendo lo único que marca: eso ya tenía su propio flujo y no se toca.
 */
( function () {
	'use strict';

	var caja = document.getElementById( 'esc-modal-pago' );

	if ( ! caja ) {
		return;
	}

	var form  = caja.querySelector( '[data-pago-form]' );
	var fijo  = caja.querySelector( '[data-pago-fijo]' );
	var error = caja.querySelector( '[data-pago-error]' );
	var pms   = document.querySelector( '.esc-pms' );

	if ( ! pms ) {
		return;
	}

	function q( sel ) {
		return caja.querySelector( sel );
	}

	function avisar( texto ) {
		var t = document.getElementById( 'esc-toast' );
		if ( ! t ) { return; }
		t.textContent = texto;
		t.classList.add( 'show' );
		window.clearTimeout( avisar.t );
		avisar.t = window.setTimeout( function () { t.classList.remove( 'show' ); }, 2400 );
	}

	function fallo( texto ) {
		if ( ! error ) { return; }
		error.textContent = texto || '';
		error.hidden = ! texto;
	}

	function cerrar() {
		caja.classList.remove( 'open' );
		fallo( '' );
	}

	function pedir( datos ) {
		var f = new FormData();
		Object.keys( datos ).forEach( function ( k ) { f.append( k, datos[ k ] ); } );
		f.append( 'nonce', pms.getAttribute( 'data-nonce' ) );

		return fetch( pms.getAttribute( 'data-ajax' ), {
			method: 'POST',
			credentials: 'same-origin',
			body: f
		} ).then( function ( r ) { return r.json(); } );
	}

	function abrir( clave ) {
		fallo( '' );

		pedir( { action: 'malka_crm_pago_detalle', clave: clave } ).then( function ( j ) {
			if ( ! j || ! j.success ) { throw new Error( 'no' ); }

			var d = j.data;

			// Un impuesto no se edita: se explica por qué y se ofrece dónde mirar.
			fijo.hidden = !! d.editable;
			form.hidden = ! d.editable;

			if ( ! d.editable ) {
				caja.querySelector( '[data-pago-ir]' ).setAttribute( 'href', d.donde );
				caja.classList.add( 'open' );
				return;
			}

			q( '[data-pago-clave]' ).value    = d.clave;
			q( '[data-pago-nombre]' ).value   = d.nombre;
			q( '[data-pago-monto]' ).value    = d.monto;
			q( '[data-pago-moneda]' ).value   = d.moneda;
			q( '[data-pago-dia]' ).value      = d.dia ? d.dia : '';
			q( '[data-pago-cuenta]' ).value   = String( d.cuenta );
			q( '[data-pago-variable]' ).checked = !! d.variable;

			caja.classList.add( 'open' );
			q( '[data-pago-nombre]' ).focus();
			q( '[data-pago-nombre]' ).select();
		} ).catch( function () {
			avisar( 'No pude abrir ese costo.' );
		} );
	}

	pms.addEventListener( 'click', function ( e ) {
		var fila = e.target.closest ? e.target.closest( '[data-pago]' ) : null;

		if ( ! fila ) { return; }

		// El círculo de pagar y lo que cuelga de él siguen haciendo lo suyo.
		if ( e.target.closest( 'input, select, a, button, label, summary' ) ) { return; }

		abrir( fila.getAttribute( 'data-pago' ) );
	} );

	caja.addEventListener( 'click', function ( e ) {
		if ( e.target === caja || ( e.target.closest && e.target.closest( '[data-cerrar-modal]' ) ) ) {
			cerrar();
			return;
		}

		if ( ! e.target.closest || ! e.target.closest( '[data-pago-borrar]' ) ) { return; }

		if ( ! window.confirm( 'Se borra este costo del mes y de los que vienen. ¿Lo borramos?' ) ) { return; }

		pedir( {
			action: 'malka_crm_pago_editar',
			clave: q( '[data-pago-clave]' ).value,
			borrar: '1'
		} ).then( function ( j ) {
			if ( ! j || ! j.success ) { throw new Error( 'no' ); }
			window.location.reload();
		} ).catch( function () {
			fallo( 'No se pudo borrar.' );
		} );
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && caja.classList.contains( 'open' ) ) { cerrar(); }
	} );

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		fallo( '' );

		pedir( {
			action: 'malka_crm_pago_editar',
			clave: q( '[data-pago-clave]' ).value,
			nombre: q( '[data-pago-nombre]' ).value,
			monto: q( '[data-pago-monto]' ).value,
			moneda: q( '[data-pago-moneda]' ).value,
			dia: q( '[data-pago-dia]' ).value,
			cuenta: q( '[data-pago-cuenta]' ).value,
			variable: q( '[data-pago-variable]' ).checked ? '1' : ''
		} ).then( function ( j ) {
			if ( ! j || ! j.success ) {
				fallo( ( j && j.data && j.data.message ) || 'No se pudo guardar.' );
				return;
			}

			// Cambia el monto, el día y hasta el orden: se vuelve a dibujar.
			window.location.reload();
		} ).catch( function () {
			fallo( 'No se pudo guardar. Revisa tu conexión.' );
		} );
	} );
}() );

/*
 * El filtro de «Lo que falta», sin recargar.
 *
 * Los botones siguen siendo enlaces de verdad —sin javascript navegan y
 * funcionan igual—, pero acá se intercepta el clic y se cambia sólo esa
 * columna. La dirección se actualiza con el historial, así que el botón
 * «atrás» del navegador vuelve al filtro anterior y el enlace se puede
 * copiar y compartir.
 */
( function () {
	'use strict';

	var col = document.querySelector( '[data-falta-col]' );

	if ( ! col ) {
		return;
	}

	var yendo = false;

	function llave() {
		// La llave viaja en la lista; sin tareas pendientes no hay lista.
		var l = col.querySelector( '.esc-tareas' ) || document.querySelector( '[data-nonce][data-ajax]' );

		return l ? { nonce: l.getAttribute( 'data-nonce' ), url: l.getAttribute( 'data-ajax' ) } : null;
	}

	function cambiar( filtro, enlace, empujar ) {
		var k = llave();

		if ( ! k || ! k.nonce || yendo ) {
			return false;
		}

		yendo = true;
		col.setAttribute( 'aria-busy', 'true' );

		var f = new FormData();
		f.append( 'action', 'malka_crm_falta' );
		f.append( 'nonce', k.nonce );
		f.append( 'falta', filtro );

		fetch( k.url, { method: 'POST', credentials: 'same-origin', body: f } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) {
				if ( ! j || ! j.success ) { throw new Error( 'no' ); }

				var temp = document.createElement( 'div' );
				temp.innerHTML = j.data.html;

				var nueva = temp.firstElementChild;

				if ( ! nueva ) { throw new Error( 'vacio' ); }

				col.replaceWith( nueva );
				col = nueva;
				yendo = false;

				/*
				 * Actualizar la dirección es un lujo, no el trabajo: si el
				 * navegador lo rechaza, la columna ya está puesta y no hay
				 * por qué deshacerla recargando.
				 */
				if ( empujar && enlace && window.history && window.history.pushState ) {
					try {
						window.history.pushState( { falta: filtro }, '', enlace );
					} catch ( err ) {
						// Se queda la dirección vieja y no pasa nada más.
					}
				}

				// El foco se queda en el botón que apretó, no se va al cuerpo.
				var b = col.querySelector( '[data-falta="' + filtro + '"]' );
				if ( b ) { b.focus(); }
			} )
			.catch( function () {
				// Si falla, que el enlace haga lo que siempre hizo.
				yendo = false;
				col.removeAttribute( 'aria-busy' );

				if ( enlace ) { window.location.href = enlace; }
			} );

		return true;
	}

	document.addEventListener( 'click', function ( e ) {
		if ( ! e.target.closest ) { return; }

		var b = e.target.closest( '[data-falta]' );

		if ( ! b || ! col.contains( b ) ) { return; }

		// Con ⌘ o Ctrl quiere abrirlo en otra pestaña: que lo haga.
		if ( e.metaKey || e.ctrlKey || e.shiftKey || 1 === e.button ) { return; }

		if ( cambiar( b.getAttribute( 'data-falta' ), b.getAttribute( 'href' ), true ) ) {
			e.preventDefault();
		}
	} );

	window.addEventListener( 'popstate', function ( e ) {
		var f = e.state && e.state.falta;

		if ( f ) { cambiar( f, null, false ); }
	} );
}() );

/*
 * Finanzas sin recargar.
 *
 * El lente —Todo, Estudio, Personal— y el período cambian toda la
 * pantalla, pero no hace falta volver a pedir la página entera: se piden
 * las dos partes que cambian, la barra y el contenido, y se reemplazan.
 * Los enlaces y el formulario siguen existiendo tal cual: sin javascript
 * navegan como siempre.
 */
( function () {
	'use strict';

	var bar = document.querySelector( '[data-fin-bar]' );

	if ( ! bar ) {
		return;
	}

	var vista = bar.getAttribute( 'data-fin-vista' ) || 'finanzas';
	var yendo = false;

	function cuerpo() {
		return document.querySelector( '[data-fin-cuerpo]' );
	}

	function llave() {
		var l = document.querySelector( '[data-nonce][data-ajax]' );

		return l ? { nonce: l.getAttribute( 'data-nonce' ), url: l.getAttribute( 'data-ajax' ) } : null;
	}

	function pedir( datos, enlace ) {
		var k = llave();
		var c = cuerpo();

		if ( ! k || ! k.nonce || ! c || yendo ) {
			return false;
		}

		yendo = true;
		c.setAttribute( 'aria-busy', 'true' );

		var f = new FormData();
		f.append( 'action', 'malka_crm_finanzas' );
		f.append( 'nonce', k.nonce );
		f.append( 'vista', vista );

		Object.keys( datos ).forEach( function ( x ) {
			if ( '' !== datos[ x ] && null !== datos[ x ] ) {
				f.append( x, datos[ x ] );
			}
		} );

		fetch( k.url, { method: 'POST', credentials: 'same-origin', body: f } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) {
				if ( ! j || ! j.success ) { throw new Error( 'no' ); }

				var dentro = cuerpo();

				if ( ! dentro || ! j.data.html ) { throw new Error( 'vacio' ); }

				dentro.innerHTML = j.data.html;
				dentro.removeAttribute( 'aria-busy' );

				var caja = document.createElement( 'div' );
				caja.innerHTML = j.data.barra;

				if ( caja.firstElementChild ) {
					bar.replaceWith( caja.firstElementChild );
					bar = document.querySelector( '[data-fin-bar]' );
				}

				yendo = false;

				if ( enlace && window.history && window.history.pushState ) {
					try {
						window.history.pushState( {}, '', enlace );
					} catch ( err ) {
						// La dirección se queda como estaba y no pasa nada más.
					}
				}
			} )
			.catch( function () {
				yendo = false;

				var c2 = cuerpo();
				if ( c2 ) { c2.removeAttribute( 'aria-busy' ); }

				// Si falla, que el enlace haga lo que siempre hizo.
				if ( enlace ) { window.location.href = enlace; }
			} );

		return true;
	}

	// El lente.
	document.addEventListener( 'click', function ( e ) {
		if ( ! e.target.closest ) { return; }

		var b = e.target.closest( '[data-ambito]' );

		if ( ! b || ! bar || ! bar.contains( b ) ) { return; }
		if ( e.metaKey || e.ctrlKey || e.shiftKey ) { return; }

		if ( pedir( { ambito: b.getAttribute( 'data-ambito' ) }, b.getAttribute( 'href' ) ) ) {
			e.preventDefault();
		}
	} );

	// El período: los botones y los selectores de mes, año o día.
	document.addEventListener( 'submit', function ( e ) {
		var form = e.target.closest ? e.target.closest( '.esc-per' ) : null;

		if ( ! form ) { return; }

		var d = {};
		var apretado = form.querySelector( 'button[data-per]:focus' ) ||
			( e.submitter && e.submitter.matches( '[data-per]' ) ? e.submitter : null );

		if ( apretado ) {
			d.periodo = apretado.getAttribute( 'data-per' );
		} else {
			var puesto = form.querySelector( '[data-per-actual]' );
			d.periodo = puesto ? puesto.value : '';
		}

		[ 'mes', 'anio', 'dia' ].forEach( function ( k ) {
			var campo = form.querySelector( '[name="' + k + '"]' );
			if ( campo ) { d[ k ] = campo.value; }
		} );

		var enlace = form.getAttribute( 'action' ) + '?' +
			Object.keys( d ).filter( function ( k ) { return '' !== d[ k ]; } )
				.map( function ( k ) { return k + '=' + encodeURIComponent( d[ k ] ); } ).join( '&' );

		if ( pedir( d, enlace ) ) {
			e.preventDefault();
		}
	} );
}() );

/*
 * Movimientos: buscar, filtrar, seleccionar y el menú de cada fila.
 *
 * Todo lo que se puede hacer acá existe también sin javascript —los filtros
 * son parámetros de la dirección y el PDF es un enlace—; esto sólo evita la
 * recarga y agrega lo que sin javascript no tendría sentido, como marcar
 * veinte movimientos de una vez.
 */
( function () {
	'use strict';

	var panel = document.querySelector( '[data-movs]' );

	if ( ! panel ) {
		return;
	}

	var tabla  = panel.querySelector( '[data-movs-tabla]' );
	var cajon  = panel.querySelector( '[data-movs-cajon]' );
	var imp    = panel.querySelector( '[data-movs-imp]' );
	var busca  = document.getElementById( 'esc-movs-q' );
	var espera = null;
	var menu   = null;

	function cfg() {
		var g = window.malkaEscritorio || {};
		var l = document.querySelector( '[data-nonce][data-ajax]' );

		return {
			url:  g.ajax || ( l ? l.getAttribute( 'data-ajax' ) : '' ),
			n:    l ? l.getAttribute( 'data-nonce' ) : '',
			nFin: g.nonceFin || ''
		};
	}

	/** Lo que hay puesto ahora mismo, leído de la pantalla. */
	function puestos() {
		var d = { q: busca ? busca.value.trim() : '' };

		if ( cajon ) {
			cajon.querySelectorAll( '[data-movs-f]' ).forEach( function ( sel ) {
				if ( sel.value ) {
					d[ sel.getAttribute( 'data-movs-f' ) ] = sel.value;
				}
			} );
		}

		return d;
	}

	/** La dirección que deja la pantalla igual a como se está viendo. */
	function direccion( d ) {
		var base = window.location.pathname;
		var qs   = [];

		Object.keys( d ).forEach( function ( k ) {
			if ( d[ k ] ) {
				qs.push( encodeURIComponent( k ) + '=' + encodeURIComponent( d[ k ] ) );
			}
		} );

		// La vista se conserva: sin ella la dirección vuelve al inicio.
		var vista = new URLSearchParams( window.location.search ).get( 'vista' );

		if ( vista ) {
			qs.unshift( 'vista=' + encodeURIComponent( vista ) );
		}

		return base + ( qs.length ? '?' + qs.join( '&' ) : '' );
	}

	function pedir() {
		var k = cfg();
		var d = puestos();

		if ( ! tabla || ! k.url || ! k.n ) {
			return;
		}

		tabla.setAttribute( 'aria-busy', 'true' );

		var f = new FormData();
		f.append( 'action', 'malka_crm_movs' );
		f.append( 'nonce', k.n );

		Object.keys( d ).forEach( function ( x ) {
			if ( d[ x ] ) {
				f.append( x, d[ x ] );
			}
		} );

		fetch( k.url, { method: 'POST', credentials: 'same-origin', body: f } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) {
				if ( ! j || ! j.success || ! j.data.html ) {
					throw new Error( 'no' );
				}

				var caja = document.createElement( 'div' );
				caja.innerHTML = j.data.html;

				var nuevo = caja.firstElementChild;

				if ( ! nuevo ) {
					throw new Error( 'vacio' );
				}

				tabla.replaceWith( nuevo );
				tabla = nuevo;
				tabla.removeAttribute( 'aria-busy' );
				marcar();

				var boton = panel.querySelector( '[data-movs-filtros]' );

				if ( boton ) {
					var n = parseInt( j.data.cuantos, 10 ) || 0;
					boton.classList.toggle( 'is-on', n > 0 );
					boton.innerHTML = boton.innerHTML.replace( /<i>\d+<\/i>/, '' );
					if ( n > 0 ) {
						boton.insertAdjacentHTML( 'beforeend', '<i>' + n + '</i>' );
					}
				}

				try {
					window.history.replaceState( {}, '', direccion( d ) );
				} catch ( err ) {
					// La dirección se queda como estaba; la tabla ya cambió.
				}
			} )
			.catch( function () {
				if ( tabla ) {
					tabla.removeAttribute( 'aria-busy' );
				}
				window.location.href = direccion( puestos() );
			} );
	}

	if ( busca ) {
		busca.addEventListener( 'input', function () {
			window.clearTimeout( espera );
			espera = window.setTimeout( pedir, 320 );
		} );
		busca.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key ) {
				e.preventDefault();
				window.clearTimeout( espera );
				pedir();
			}
		} );
	}

	/* ── El cajón de filtros ── */
	function verCajon( si ) {
		if ( ! cajon ) {
			return;
		}

		cajon.hidden = ! si;

		var b = panel.querySelector( '[data-movs-filtros]' );

		if ( b ) {
			b.setAttribute( 'aria-expanded', si ? 'true' : 'false' );
		}
	}

	panel.addEventListener( 'click', function ( e ) {
		if ( ! e.target.closest ) {
			return;
		}

		if ( e.target.closest( '[data-movs-filtros]' ) ) {
			verCajon( cajon && cajon.hidden );
			return;
		}

		if ( e.target.closest( '[data-movs-cerrar], [data-movs-listo]' ) ) {
			verCajon( false );
			return;
		}

		if ( e.target.closest( '[data-movs-limpiar]' ) ) {
			cajon.querySelectorAll( '[data-movs-f]' ).forEach( function ( s ) { s.value = ''; } );
			pedir();
			verCajon( false );
			return;
		}

		if ( e.target.closest( '[data-movs-importar]' ) && imp ) {
			imp.hidden = ! imp.hidden;
		}
	} );

	if ( cajon ) {
		cajon.addEventListener( 'change', function ( e ) {
			if ( e.target.matches( '[data-movs-f]' ) ) {
				pedir();
			}
		} );
	}

	/* ── Seleccionar filas ── */
	function elegidas() {
		return tabla ? Array.prototype.slice.call( tabla.querySelectorAll( '.esc-tm__ck:checked' ) ) : [];
	}

	function marcar() {
		var sel   = elegidas();
		var barra = tabla ? tabla.querySelector( '[data-movs-bulk]' ) : null;
		var todos = tabla ? tabla.querySelector( '[data-movs-todos]' ) : null;
		var cajas = tabla ? tabla.querySelectorAll( '.esc-tm__ck' ) : [];

		if ( barra ) {
			barra.hidden = ! sel.length;
			var n = barra.querySelector( '[data-movs-cuantos]' );
			if ( n ) {
				n.textContent = sel.length + ( 1 === sel.length ? ' seleccionado' : ' seleccionados' );
			}
		}

		if ( todos ) {
			todos.checked = cajas.length > 0 && sel.length === cajas.length;
			todos.indeterminate = sel.length > 0 && sel.length < cajas.length;
		}

		Array.prototype.forEach.call( cajas, function ( c ) {
			var fila = c.closest( '.esc-tm__f' );
			if ( fila ) {
				fila.classList.toggle( 'is-sel', c.checked );
			}
		} );
	}

	function lote( que ) {
		var k   = cfg();
		var ids = elegidas().map( function ( c ) { return c.value; } );

		if ( ! ids.length || ! k.url || ! k.nFin ) {
			return;
		}

		if ( 'borrar' === que && ! window.confirm( '¿Borrar ' + ids.length + ' movimiento' + ( 1 === ids.length ? '' : 's' ) + '? El saldo se ajusta.' ) ) {
			return;
		}

		var f = new FormData();
		f.append( 'action', 'malka_crm_movs_lote' );
		f.append( 'nonce', k.nFin );
		f.append( 'que', que );
		f.append( 'ids', ids.join( ',' ) );

		fetch( k.url, { method: 'POST', credentials: 'same-origin', body: f } )
			.then( function ( r ) { return r.json(); } )
			.then( function () { window.location.reload(); } )
			.catch( function () { window.location.reload(); } );
	}

	/* ── El menú de los tres puntos ── */
	function cerrarMenu() {
		if ( menu ) {
			menu.remove();
			menu = null;
		}
	}

	function abrirMenu( boton, fila ) {
		cerrarMenu();

		menu = document.createElement( 'div' );
		menu.className = 'esc-fmenu';
		menu.innerHTML =
			'<button type="button" data-m="editar">Editar</button>' +
			'<button type="button" data-m="copiar">Duplicar con fecha de hoy</button>' +
			'<button type="button" class="is-mal" data-m="borrar">Borrar</button>';

		var caja = boton.getBoundingClientRect();
		var ref  = panel.getBoundingClientRect();

		menu.style.top  = ( caja.bottom - ref.top + 4 ) + 'px';
		menu.style.left = Math.max( 0, caja.right - ref.left - 160 ) + 'px';

		panel.appendChild( menu );

		menu.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( '[data-m]' );

			if ( ! b ) {
				return;
			}

			e.stopPropagation();

			var que = b.getAttribute( 'data-m' );
			cerrarMenu();

			if ( 'editar' === que ) {
				fila.click();
				return;
			}

			var caja2 = fila.querySelector( '.esc-tm__ck' );

			if ( caja2 ) {
				tabla.querySelectorAll( '.esc-tm__ck' ).forEach( function ( c ) { c.checked = false; } );
				caja2.checked = true;
				marcar();
				lote( que );
			}
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		if ( ! e.target.closest ) {
			return;
		}

		var mas = e.target.closest( '[data-movs-menu]' );

		if ( mas ) {
			e.stopPropagation();
			abrirMenu( mas, mas.closest( '.esc-tm__f' ) );
			return;
		}

		if ( ! e.target.closest( '.esc-fmenu' ) ) {
			cerrarMenu();
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			cerrarMenu();
		}
	} );

	panel.addEventListener( 'change', function ( e ) {
		if ( e.target.matches( '[data-movs-todos]' ) ) {
			var v = e.target.checked;
			tabla.querySelectorAll( '.esc-tm__ck' ).forEach( function ( c ) { c.checked = v; } );
			marcar();
			return;
		}

		if ( e.target.matches( '.esc-tm__ck' ) ) {
			marcar();
		}
	} );

	panel.addEventListener( 'click', function ( e ) {
		var b = e.target.closest ? e.target.closest( '[data-movs-acc]' ) : null;

		if ( b ) {
			lote( b.getAttribute( 'data-movs-acc' ) );
			return;
		}

		if ( e.target.closest && e.target.closest( '[data-movs-nada]' ) ) {
			tabla.querySelectorAll( '.esc-tm__ck' ).forEach( function ( c ) { c.checked = false; } );
			marcar();
		}
	} );

	/* ── Tocar una fila la abre para corregirla ── */
	function abrir( fila ) {
		try {
			var datos = JSON.parse( fila.getAttribute( 'data-mov' ) );

			if ( window.MalkaEscAbrirMov ) {
				window.MalkaEscAbrirMov( datos );
			}
		} catch ( x ) {}
	}

	panel.addEventListener( 'click', function ( e ) {
		var fila = e.target.closest ? e.target.closest( '.esc-tm__f[data-mov]' ) : null;

		if ( ! fila || e.target.closest( 'input, button, label, a, select, .esc-fmenu' ) ) {
			return;
		}

		abrir( fila );
	} );

	panel.addEventListener( 'keydown', function ( e ) {
		if ( 'Enter' === e.key && e.target.matches && e.target.matches( '.esc-tm__f[data-mov]' ) ) {
			e.preventDefault();
			abrir( e.target );
		}
	} );

	marcar();
}() );

/*
 * La agenda: la ventana de un evento.
 *
 * Una sola ventana para los dos casos. Desde el «+» se abre en blanco con
 * el día de hoy puesto; desde un evento de la lista se pide al servidor y
 * se llena, y entonces aparece el botón de borrar. Sin javascript el
 * formulario igual funciona: es un formulario de verdad que se manda.
 */
( function () {
	'use strict';

	var caja = document.getElementById( 'esc-modal-evento' );

	if ( ! caja ) {
		return;
	}

	var form  = caja.querySelector( '[data-ev-form]' );
	var tit   = caja.querySelector( '[data-ev-titulo]' );
	var borra = caja.querySelector( '[data-ev-x]' );

	function q( sel ) {
		return caja.querySelector( sel );
	}

	function pon( sel, v ) {
		var el = q( sel );

		if ( el ) {
			el.value = ( null === v || undefined === v ) ? '' : v;
		}
	}

	function hoy() {
		var d = new Date();
		var m = String( d.getMonth() + 1 ).padStart( 2, '0' );
		var x = String( d.getDate() ).padStart( 2, '0' );

		return d.getFullYear() + '-' + m + '-' + x;
	}

	function abrir( e ) {
		pon( '[data-ev-id]', e ? e.id : 0 );
		pon( '[data-ev-t]', e ? e.titulo : '' );
		pon( '[data-ev-tipo]', e ? e.tipo : 'reunion' );
		pon( '[data-ev-f]', e ? e.fecha : hoy() );
		pon( '[data-ev-h]', e ? e.hora : '' );
		pon( '[data-ev-d]', e ? e.dura : 60 );
		pon( '[data-ev-q]', e ? e.quien : '' );
		pon( '[data-ev-w]', e ? e.donde : '' );
		pon( '[data-ev-n]', e ? e.negocio : 0 );
		pon( '[data-ev-nota]', e ? e.nota : '' );
		pon( '[data-ev-bid]', e ? e.id : 0 );

		if ( tit ) {
			tit.textContent = e ? 'El evento' : 'Nuevo evento';
		}

		if ( borra ) {
			borra.hidden = ! e;
		}

		caja.classList.add( 'open' );

		var primero = q( '[data-ev-t]' );

		if ( primero ) {
			primero.focus();
			primero.select();
		}
	}

	function cerrar() {
		caja.classList.remove( 'open' );
	}

	var nuevo = document.getElementById( 'esc-open-evento' );

	if ( nuevo ) {
		nuevo.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			abrir( null );
		} );
	}

	// Tocar un evento de la lista lo abre con lo que tiene adentro.
	function traer( id ) {
		var l = document.querySelector( '[data-nonce][data-ajax]' );

		if ( ! l ) {
			return;
		}

		var f = new FormData();
		f.append( 'action', 'malka_crm_evento_detalle' );
		f.append( 'nonce', l.getAttribute( 'data-nonce' ) );
		f.append( 'id', id );

		fetch( l.getAttribute( 'data-ajax' ), { method: 'POST', credentials: 'same-origin', body: f } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) {
				if ( j && j.success ) {
					abrir( j.data );
				}
			} )
			.catch( function () {} );
	}

	document.addEventListener( 'click', function ( e ) {
		var fila = e.target.closest ? e.target.closest( '[data-evento]' ) : null;

		if ( fila ) {
			traer( fila.getAttribute( 'data-evento' ) );
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Enter' === e.key && e.target.matches && e.target.matches( '[data-evento]' ) ) {
			e.preventDefault();
			traer( e.target.getAttribute( 'data-evento' ) );
		}

		if ( 'Escape' === e.key && caja.classList.contains( 'open' ) ) {
			cerrar();
		}
	} );

	caja.addEventListener( 'click', function ( e ) {
		if ( e.target === caja || ( e.target.closest && e.target.closest( '[data-cerrar-modal]' ) ) ) {
			cerrar();
		}
	} );

	// Sin día no hay evento: el navegador ya lo exige, pero por si acaso.
	if ( form ) {
		form.addEventListener( 'submit', function ( e ) {
			var f = q( '[data-ev-f]' );

			if ( f && ! f.value ) {
				e.preventDefault();
				f.focus();
			}
		} );
	}
}() );

/*
 * La ⓘ de un panel.
 *
 * Abre y cierra la nota que lleva al lado. Sin javascript la nota se queda
 * cerrada, que es lo que corresponde: es una aclaración, no el contenido.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest ? e.target.closest( '[data-nota-btn]' ) : null;

		if ( ! b ) {
			return;
		}

		var nota = document.getElementById( b.getAttribute( 'aria-controls' ) );

		if ( ! nota ) {
			return;
		}

		var abre = nota.hidden;

		nota.hidden = ! abre;
		b.setAttribute( 'aria-expanded', abre ? 'true' : 'false' );
		b.classList.toggle( 'is-on', abre );
	} );
}() );

/*
 * «Anotar una deuda», desde el + de arriba.
 *
 * El formulario es el mismo de siempre y se manda igual; lo único que hace
 * esto es abrirlo y cerrarlo.
 */
( function () {
	'use strict';

	var caja  = document.getElementById( 'esc-modal-deuda' );
	var boton = document.getElementById( 'esc-open-deuda' );

	if ( ! caja || ! boton ) {
		return;
	}

	boton.addEventListener( 'click', function ( e ) {
		e.stopPropagation();
		caja.classList.add( 'open' );

		var primero = caja.querySelector( 'input[name="nombre"]' );

		if ( primero ) {
			primero.focus();
		}
	} );

	caja.addEventListener( 'click', function ( e ) {
		if ( e.target === caja || ( e.target.closest && e.target.closest( '[data-cerrar-modal]' ) ) ) {
			caja.classList.remove( 'open' );
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			caja.classList.remove( 'open' );
		}
	} );
}() );

/*
 * «Faltan el cupo, la tasa o el vencimiento»: abre el detalle de esa misma
 * cuenta, que es donde se escriben. Sin javascript el aviso igual se lee;
 * lo único que se pierde es el atajo.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest ? e.target.closest( '[data-cta-abrir]' ) : null;

		if ( ! b ) {
			return;
		}

		var fila = b.closest( '[data-cuenta]' );
		var det  = fila ? fila.querySelector( 'details.esc-cta__ed' ) : null;

		if ( ! det ) {
			return;
		}

		det.open = true;

		var campo = det.querySelector( '.esc-cta__tarj input' );

		if ( campo ) {
			campo.focus();
			campo.select();
		}
	} );
}() );

/*
 * Lo que se paga solo: anotar y corregir una suscripción o un pago fijo.
 *
 * El formulario es uno solo y se manda como siempre. Esto nada más lo abre,
 * lo llena con lo que ya está guardado cuando se está corrigiendo, y esconde
 * el mes mientras el cobro sea mensual, porque ahí no significa nada. Sin
 * javascript la lista igual se lee; lo que se pierde es el atajo.
 */
( function () {
	'use strict';

	var caja = document.getElementById( 'esc-modal-sus' );

	if ( ! caja ) {
		return;
	}

	var form = caja.querySelector( 'form' );
	var tit  = caja.querySelector( '[data-sus-titulo]' );
	var del  = caja.querySelector( '[data-sus-borrar]' );
	var mesC = caja.querySelector( '[data-sus-mes-campo]' );
	var cic  = caja.querySelector( '[data-sus-ciclo]' );

	function campo( k ) {
		return caja.querySelector( '[data-sus-' + k + ']' );
	}

	function cfg() {
		// El panel los trae puestos; la variable global es el respaldo.
		var pan = document.getElementById( 'esc-suscripciones' );
		var g   = window.malkaEscritorio || {};

		return {
			url: ( pan && pan.getAttribute( 'data-sus-ajax' ) ) || g.ajax || '',
			n:   ( pan && pan.getAttribute( 'data-sus-nonce' ) ) || g.nonceFin || ''
		};
	}

	/** El mes sólo se pregunta en un cobro anual. */
	function mes() {
		if ( ! mesC || ! cic ) {
			return;
		}
		mesC.hidden = 'anual' !== cic.value;
	}

	function abrir() {
		caja.classList.add( 'open' );
		mes();

		var primero = campo( 'nombre' );

		if ( primero ) {
			primero.focus();
		}
	}

	function cerrar() {
		caja.classList.remove( 'open' );
	}

	/** Deja el formulario como para anotar uno nuevo. */
	function limpiar() {
		if ( ! form ) {
			return;
		}

		form.reset();

		var id = campo( 'id' );

		if ( id ) {
			id.value = '0';
		}

		if ( del ) {
			del.hidden = true;
		}

		if ( tit ) {
			tit.textContent = tit.getAttribute( 'data-nuevo' ) || 'Anotar un cobro fijo';
		}
	}

	/** Pone en el formulario lo que ya está guardado. */
	function llenar( d ) {
		/*
		 * En pesos se escribe con puntos, como en todo el resto del panel;
		 * en dólares o euros, con sus decimales y sin separador de miles,
		 * que es como viene del servidor.
		 */
		var monto = 'CLP' === d.moneda
			? String( d.monto ).replace( /\B(?=(\d{3})+(?!\d))/g, '.' )
			: String( d.monto );

		var pares = {
			id:     d.id,
			nombre: d.nombre,
			monto:  monto,
			moneda: d.moneda,
			ciclo:  d.ciclo,
			ambito: d.ambito,
			tipo:   d.tipo,
			cuenta: d.cuenta,
			mes:    d.mes
		};

		Object.keys( pares ).forEach( function ( k ) {
			var el = campo( k );

			if ( el ) {
				el.value = String( pares[ k ] );
			}
		} );

		// El día en cero es «no hay día», no el día cero.
		var dia = campo( 'dia' );

		if ( dia ) {
			dia.value = d.dia ? String( d.dia ) : '';
		}

		var v = campo( 'variable' );

		if ( v ) {
			v.checked = !! d.variable;
		}

		if ( del ) {
			del.hidden = false;
		}

		if ( tit ) {
			tit.textContent = d.nombre;
		}
	}

	if ( tit ) {
		tit.setAttribute( 'data-nuevo', tit.textContent );
	}

	if ( cic ) {
		cic.addEventListener( 'change', mes );
	}

	document.addEventListener( 'click', function ( e ) {
		if ( ! e.target.closest ) {
			return;
		}

		if ( e.target.closest( '[data-sus-nueva]' ) ) {
			e.stopPropagation();
			limpiar();
			abrir();
			return;
		}

		var ed = e.target.closest( '[data-sus-editar]' );

		if ( ! ed ) {
			return;
		}

		e.stopPropagation();

		var c = cfg();

		if ( ! c.url || ! c.n ) {
			return;
		}

		var cuerpo = new URLSearchParams();
		cuerpo.append( 'action', 'malka_crm_sus_detalle' );
		cuerpo.append( 'nonce', c.n );
		cuerpo.append( 'id', ed.getAttribute( 'data-sus-editar' ) );

		window.fetch( c.url, {
			method:      'POST',
			credentials: 'same-origin',
			headers:     { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body:        cuerpo.toString()
		} ).then( function ( r ) {
			return r.json();
		} ).then( function ( j ) {
			if ( ! j || ! j.success || ! j.data ) {
				return;
			}

			limpiar();
			llenar( j.data );
			abrir();
			mes();
		} ).catch( function () {} );
	} );

	caja.addEventListener( 'click', function ( e ) {
		if ( e.target === caja || ( e.target.closest && e.target.closest( '[data-cerrar-modal]' ) ) ) {
			cerrar();
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			cerrar();
		}
	} );
}() );

/*
 * «Ya lo pagué», desde la lista de suscripciones.
 *
 * Tres datos y ninguno se da por sabido: cuánto salió de verdad —que en un
 * cargo en dólares no es el monto por el cambio de hoy—, qué día, y de qué
 * cuenta. Lo que se propone es lo último que pagó, que es lo más parecido a
 * lo que va a llegar esta vez.
 */
( function () {
	'use strict';

	var caja = document.getElementById( 'esc-modal-suspago' );
	var pan  = document.getElementById( 'esc-suscripciones' );

	if ( ! caja || ! pan ) {
		return;
	}

	var form = caja.querySelector( '[data-sp-form]' );

	function campo( k ) {
		return caja.querySelector( '[data-sp-' + k + ']' );
	}

	function cfg() {
		var g = window.malkaEscritorio || {};

		return {
			url:   pan.getAttribute( 'data-sus-ajax' ) || g.ajax || '',
			n:     pan.getAttribute( 'data-sus-nonce' ) || g.nonceFin || '',
			// Anotar el pago es de «Tu día» y pide su propia llave.
			npago: pan.getAttribute( 'data-sus-npago' ) || ''
		};
	}

	function pedir( datos ) {
		var c = cfg();
		var n = 'malka_crm_pago_hecho' === datos.action ? c.npago : c.n;

		if ( ! c.url || ! n ) {
			return Promise.reject( new Error( 'sin llave' ) );
		}

		var cuerpo = new URLSearchParams();
		cuerpo.append( 'nonce', n );
		Object.keys( datos ).forEach( function ( k ) {
			cuerpo.append( k, datos[ k ] );
		} );

		return window.fetch( c.url, {
			method:      'POST',
			credentials: 'same-origin',
			headers:     { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body:        cuerpo.toString()
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	/** Los puntos de los miles, sin depender del resto del archivo. */
	function miles( n ) {
		return String( n || 0 ).replace( /\B(?=(\d{3})+(?!\d))/g, '.' );
	}

	function cerrar() {
		caja.classList.remove( 'open' );
	}

	document.addEventListener( 'click', function ( e ) {
		if ( ! e.target.closest ) {
			return;
		}

		var b = e.target.closest( '[data-sus-pagar]' );

		if ( b ) {
			e.stopPropagation();

			var pet = { action: 'malka_crm_sus_pagar', id: b.getAttribute( 'data-sus-pagar' ) };
			var qm  = b.getAttribute( 'data-sus-mes' );

			// Desde el calendario del año llega el mes de esa fila; desde la
			// lista no viene ninguno y el servidor usa el mes que corre.
			if ( qm ) {
				pet.mes = qm;
			}

			pedir( pet ).then( function ( j ) {
				if ( ! j || ! j.success || ! j.data ) {
					return;
				}

				var d = j.data;

				campo( 'clave' ).value  = d.clave;
				campo( 'mes' ).value    = d.mes;
				campo( 'monto' ).value  = d.sugerido ? miles( d.sugerido ) : '';
				campo( 'fecha' ).value  = d.fecha || d.hoy;
				campo( 'fecha' ).max    = d.hoy;
				campo( 'cuenta' ).value = String( d.cuenta || 0 );

				var ay = caja.querySelector( '[data-sp-ayuda]' );
				ay.textContent = d.ayuda || '';
				ay.hidden      = ! d.ayuda;

				caja.querySelector( '[data-sp-titulo]' ).textContent = d.nombre;

				caja.classList.add( 'open' );
				campo( 'monto' ).focus();
				campo( 'monto' ).select();
			} ).catch( function () {} );

			return;
		}

		// Tocar lo ya pagado lo deshace: se quita la marca y el movimiento.
		var u = e.target.closest( '[data-sus-deshacer]' );

		if ( ! u ) {
			return;
		}

		e.stopPropagation();

		if ( u.disabled ) {
			return;
		}

		u.disabled = true;

		pedir( {
			action: 'malka_crm_sus_deshacer',
			clave:  u.getAttribute( 'data-sus-deshacer' ),
			mes:    u.getAttribute( 'data-sus-desmes' )
		} ).then( function ( j ) {
			if ( ! j || ! j.success ) {
				u.disabled = false;
				return;
			}
			window.location.reload();
		} ).catch( function () {
			u.disabled = false;
		} );
	} );

	caja.addEventListener( 'click', function ( e ) {
		if ( e.target === caja || ( e.target.closest && e.target.closest( '[data-cerrar-modal]' ) ) ) {
			cerrar();
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			cerrar();
		}
	} );

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();

		var btn = form.querySelector( 'button[type="submit"]' );

		if ( btn ) {
			btn.disabled = true;
		}

		pedir( {
			action: 'malka_crm_pago_hecho',
			mes:    campo( 'mes' ).value,
			clave:  campo( 'clave' ).value,
			monto:  campo( 'monto' ).value.replace( /\D/g, '' ),
			fecha:  campo( 'fecha' ).value,
			cuenta: campo( 'cuenta' ).value
		} ).then( function ( j ) {
			if ( ! j || ! j.success ) {
				throw new Error( 'no' );
			}
			window.location.reload();
		} ).catch( function () {
			if ( btn ) {
				btn.disabled = false;
			}
		} );
	} );
}() );

/*
 * Casa: al elegir «Otra…» aparece el campo para escribirla.
 *
 * Los selectores se llenan con lo que ya se usó, pero siempre tiene que
 * poder anotarse algo nuevo sin ir a configurar nada antes.
 */
( function () {
	'use strict';

	var form = document.querySelector( '.esc-casa__n2' );

	if ( ! form ) {
		return;
	}

	// La tarea sigue siendo una sola: lista desplegable con su «Otra…».
	( function () {
		var sel   = form.querySelector( '[data-casa-tarea]' );
		var campo = form.querySelector( '[data-casa-tarea-nueva]' );

		if ( ! sel || ! campo ) {
			return;
		}

		function mirar() {
			var otra = sel.value === '__otra';

			campo.hidden   = ! otra;
			campo.required = otra;

			if ( otra ) {
				campo.focus();
			}
		}

		sel.addEventListener( 'change', mirar );
		mirar();
	}() );

	/*
	 * Quién, en cambio, puede ser más de uno: son casillas. La de «Otra
	 * persona…» sólo abre el campo de al lado; no es un nombre y no se
	 * manda, así que no reemplaza a nadie que ya esté marcado.
	 */
	( function () {
		var otro  = form.querySelector( '[data-casa-otro]' );
		var campo = form.querySelector( '[data-casa-quien-nuevo]' );

		if ( ! otro || ! campo ) {
			return;
		}

		function mirar() {
			campo.hidden   = ! otro.checked;
			campo.required = otro.checked;

			if ( otro.checked ) {
				campo.focus();
			} else {
				campo.value = '';
			}
		}

		otro.addEventListener( 'change', mirar );
		mirar();
	}() );

	/*
	 * Y al menos una persona tiene que quedar marcada: sin eso el envío
	 * vuelve con «falta algo» y no se entiende qué faltaba.
	 */
	form.addEventListener( 'submit', function ( e ) {
		var cajas = form.querySelectorAll( 'input[name="quien[]"]' );
		var nuevo = form.querySelector( '[data-casa-quien-nuevo]' );
		var hay   = false;
		var i;

		if ( ! cajas.length ) {
			return;
		}

		for ( i = 0; i < cajas.length; i++ ) {
			if ( 'checkbox' !== cajas[ i ].type || cajas[ i ].checked ) {
				hay = true;
			}
		}

		if ( ! hay && nuevo && nuevo.value.trim() ) {
			hay = true;
		}

		if ( ! hay ) {
			e.preventDefault();
			cajas[ 0 ].focus();

			if ( cajas[ 0 ].setCustomValidity ) {
				cajas[ 0 ].setCustomValidity( 'Marca al menos a una persona.' );
				cajas[ 0 ].reportValidity();
				setTimeout( function () {
					cajas[ 0 ].setCustomValidity( '' );
				}, 2000 );
			}
		}
	} );
}() );

/*
 * Agenda: lista o calendario.
 *
 * Las dos vistas vienen dibujadas desde el servidor y acá sólo se muestra
 * una. Así cambiar es instantáneo, y sin javascript igual se ve la lista,
 * que es la que contesta «qué viene ahora».
 *
 * Cuál estaba elegida se guarda en el navegador: es una comodidad de
 * quien mira, no un dato del panel, así que no tiene por qué viajar al
 * servidor ni seguirla de un computador a otro.
 */
( function () {
	'use strict';

	var sel = document.querySelector( '.esc-ag__sel' );

	if ( ! sel ) {
		return;
	}

	var LLAVE = 'malka-agenda-vista';

	function poner( cual ) {
		Array.prototype.forEach.call( sel.querySelectorAll( '[data-ag-vista]' ), function ( b ) {
			var suya = b.getAttribute( 'data-ag-vista' ) === cual;
			b.setAttribute( 'aria-selected', suya ? 'true' : 'false' );
		} );

		Array.prototype.forEach.call( document.querySelectorAll( '[data-ag-panel]' ), function ( pan ) {
			pan.hidden = pan.getAttribute( 'data-ag-panel' ) !== cual;
		} );

		try {
			window.localStorage.setItem( LLAVE, cual );
		} catch ( e ) {
			// Sin dónde guardarlo, se elige cada vez. No es grave.
		}
	}

	sel.addEventListener( 'click', function ( e ) {
		var b = e.target.closest ? e.target.closest( '[data-ag-vista]' ) : null;

		if ( b ) {
			poner( b.getAttribute( 'data-ag-vista' ) );
		}
	} );

	try {
		var guardada = window.localStorage.getItem( LLAVE );

		if ( 'mes' === guardada || 'lista' === guardada ) {
			poner( guardada );
		}
	} catch ( e ) {}
}() );

/*
 * Los campos de la ficha de un préstamo se guardan solos.
 *
 * Al salir de un campo se manda ese campo y nada más, y lo que vuelve es
 * lo que quedó guardado de verdad: si el servidor lo normaliza o lo
 * rechaza, el campo se corrige solo en pantalla. Con un botón «Guardar»
 * la pantalla decía «guardado» antes de saberlo, y un valor que el
 * servidor no aceptaba se quedaba escrito ahí como si estuviera grabado.
 */
( function () {
	'use strict';

	var cajas = document.querySelectorAll( '[data-presto-campos]' );

	if ( ! cajas.length ) {
		return;
	}

	Array.prototype.forEach.call( cajas, function ( caja ) {
		var id    = caja.getAttribute( 'data-presto-campos' );
		var url   = caja.getAttribute( 'data-presto-ajax' ) || '';
		var llave = caja.getAttribute( 'data-presto-nonce' ) || '';
		var aviso = caja.querySelector( '[data-presto-aviso]' );
		var reloj = null;

		function decir( texto, mal ) {
			if ( ! aviso ) {
				return;
			}

			aviso.textContent = texto;
			aviso.classList.toggle( 'is-mal', !! mal );

			window.clearTimeout( reloj );

			if ( ! mal ) {
				reloj = window.setTimeout( function () {
					aviso.textContent = aviso.getAttribute( 'data-quieto' ) || '';
				}, 2600 );
			}
		}

		if ( aviso ) {
			aviso.setAttribute( 'data-quieto', aviso.textContent );
		}

		function guardar( campo ) {
			var k = campo.getAttribute( 'data-pr-campo' );

			// No se manda lo que no cambió.
			if ( campo.value === campo.getAttribute( 'data-pr-guardado' ) ) {
				return;
			}

			var cuerpo = new URLSearchParams();
			cuerpo.append( 'action', 'malka_crm_prestamo_campo' );
			cuerpo.append( 'nonce', llave );
			cuerpo.append( 'id', id );
			cuerpo.append( 'campo', k );
			cuerpo.append( 'valor', campo.value );

			campo.classList.remove( 'is-mal' );
			decir( 'Guardando…' );

			window.fetch( url, {
				method:      'POST',
				credentials: 'same-origin',
				headers:     { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body:        cuerpo.toString()
			} ).then( function ( r ) {
				return r.json();
			} ).then( function ( j ) {
				if ( ! j || ! j.success || ! j.data ) {
					throw new Error( ( j && j.data && j.data.message ) || 'no' );
				}

				/*
				 * Lo que quedó guardado manda sobre lo que se escribió: así
				 * el campo nunca muestra algo que no está en la base.
				 */
				campo.value = j.data.valor;
				campo.setAttribute( 'data-pr-guardado', j.data.valor );
				campo.classList.add( 'is-ok' );
				window.setTimeout( function () {
					campo.classList.remove( 'is-ok' );
				}, 1400 );

				decir( 'Guardado.' );
			} ).catch( function ( e ) {
				// Se devuelve el campo a lo último que sí quedó guardado.
				campo.value = campo.getAttribute( 'data-pr-guardado' ) || '';
				campo.classList.add( 'is-mal' );
				decir( ( e && e.message && 'no' !== e.message ) ? e.message : 'No se pudo guardar.', true );
			} );
		}

		Array.prototype.forEach.call( caja.querySelectorAll( '[data-pr-campo]' ), function ( campo ) {
			campo.setAttribute( 'data-pr-guardado', campo.value );

			campo.addEventListener( 'change', function () {
				guardar( campo );
			} );

			campo.addEventListener( 'blur', function () {
				guardar( campo );
			} );

			campo.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key ) {
					e.preventDefault();
					campo.blur();
				}
			} );
		} );
	} );
}() );

/*
 * La ficha de un préstamo.
 *
 * Cada fila de «lo que te deben» y «lo que debes a personas» abre su
 * ficha, con las cuotas una por una. Antes cada préstamo llevaba tres
 * botones apilados debajo y la lista era una columna de botones.
 *
 * La ficha va dibujada en la página, no pedida por ajax: son pocas y así
 * los formularios de adentro —abonar, corregir, borrar— siguen siendo los
 * mismos de siempre, que se mandan solos sin javascript.
 */
( function () {
	'use strict';

	function abrir( id ) {
		var f = document.querySelector( '[data-presto-ficha="' + id + '"]' );

		if ( f ) {
			f.classList.add( 'open' );
		}
	}

	function cerrar() {
		var abiertas = document.querySelectorAll( '[data-presto-ficha].open' );

		Array.prototype.forEach.call( abiertas, function ( f ) {
			f.classList.remove( 'open' );
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		if ( ! e.target.closest ) {
			return;
		}

		var b = e.target.closest( '[data-presto-ver]' );

		if ( b ) {
			e.stopPropagation();
			abrir( b.getAttribute( 'data-presto-ver' ) );
			return;
		}

		var f = e.target.closest( '[data-presto-ficha]' );

		// Tocar fuera de la tarjeta, o el botón de cerrar.
		if ( f && ( e.target === f || e.target.closest( '[data-cerrar-modal]' ) ) ) {
			cerrar();
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			cerrar();
		}
	} );
}() );

/*
 * El año de un cobro fijo: los doce cargos, cuáles se pagaron y cuáles no.
 *
 * La lista de suscripciones dice cuánto cuesta algo y cuándo toca la
 * próxima vez. Lo que no decía es el año: cuáles quedaron sin marcar
 * —que es lo que pasa cuando uno se olvida un mes— y cuánto llevas
 * gastado. Esto lo abre desde la celda del próximo cobro.
 *
 * Marcar y desmarcar no se reimplementan acá: cada fila lleva los mismos
 * atributos que usa la lista de arriba (data-sus-pagar con su mes, y
 * data-sus-deshacer), así que los contesta el mismo código de siempre y
 * no hay dos caminos para guardar un pago.
 */
( function () {
	'use strict';

	var caja = document.getElementById( 'esc-modal-susano' );
	var pan  = document.getElementById( 'esc-suscripciones' );

	if ( ! caja || ! pan ) {
		return;
	}

	function campo( k ) {
		return caja.querySelector( '[data-sa-' + k + ']' );
	}

	function pedir( datos ) {
		var g   = window.malkaEscritorio || {};
		var url = pan.getAttribute( 'data-sus-ajax' ) || g.ajax || '';
		var n   = pan.getAttribute( 'data-sus-nonce' ) || g.nonceFin || '';

		if ( ! url || ! n ) {
			return Promise.reject( new Error( 'sin llave' ) );
		}

		var cuerpo = new URLSearchParams();
		cuerpo.append( 'nonce', n );
		Object.keys( datos ).forEach( function ( k ) {
			cuerpo.append( k, datos[ k ] );
		} );

		return window.fetch( url, {
			method:      'POST',
			credentials: 'same-origin',
			headers:     { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body:        cuerpo.toString()
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	function cerrar() {
		caja.classList.remove( 'open' );
	}

	/** Una fila del año. Lo que se puede hacer depende de en qué está. */
	function fila( f, id, clave ) {
		var li = document.createElement( 'div' );

		li.className = 'esc-sa__f is-' + f.estado + ( f.toca ? ' is-toca' : '' );

		var cuando = document.createElement( 'span' );
		cuando.className = 'esc-sa__d';
		cuando.textContent = f.rotulo;
		li.appendChild( cuando );

		var monto = document.createElement( 'span' );
		monto.className = 'esc-sa__m';
		monto.textContent = f.monto;
		li.appendChild( monto );

		var acc = document.createElement( 'span' );
		acc.className = 'esc-sa__a';

		if ( 'pagada' === f.estado ) {
			var des = document.createElement( 'button' );
			des.type = 'button';
			des.className = 'esc-sa__des';
			des.setAttribute( 'data-sus-deshacer', clave );
			des.setAttribute( 'data-sus-desmes', f.mes );
			des.title = 'Marcado pagado' + ( f.cuando ? ' el ' + f.cuando : '' ) + '. Tócalo para deshacer.';
			des.textContent = f.cuando ? 'Pagado ' + f.cuando : 'Pagado';
			acc.appendChild( des );
		} else if ( 'futura' === f.estado ) {
			/*
			 * Un cobro que todavía no ocurrió no se puede haber pagado: no
			 * se ofrece marcarlo. El servidor igual no lo dejaría.
			 */
			var sig = document.createElement( 'span' );
			sig.className = 'esc-sa__pend';
			sig.textContent = f.toca ? 'Es la próxima' : 'Todavía no';
			acc.appendChild( sig );
		} else {
			var pag = document.createElement( 'button' );
			pag.type = 'button';
			pag.className = 'esc-sus__pagar';
			pag.setAttribute( 'data-sus-pagar', String( id ) );
			pag.setAttribute( 'data-sus-mes', f.mes );
			pag.textContent = 'Marcar pagado';
			acc.appendChild( pag );
		}

		li.appendChild( acc );
		return li;
	}

	function pintar( d ) {
		campo( 'titulo' ).textContent = d.nombre;
		campo( 'sub' ).textContent    = 'Lo que ya pagaste de ' + d.anio + ' y lo que falta';
		campo( 'ciclo' ).textContent  = d.ciclo || '';

		campo( 'cuenta' ).textContent = d.pagadas
			? d.pagadas + ( 1 === d.pagadas ? ' pagada · ' : ' pagadas · ' ) + d.pagado
			: 'Ninguna marcada todavía';

		var px = campo( 'prox' );
		px.textContent = d.proxima || '—';
		px.closest( '.esc-susano__px' ).hidden = ! d.proxima;

		var lista = campo( 'lista' );
		lista.textContent = '';

		campo( 'vacio' ).hidden = ! d.sin_dia;
		lista.hidden            = !! d.sin_dia;

		d.filas.forEach( function ( f ) {
			lista.appendChild( fila( f, d.id, d.clave ) );
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		if ( ! e.target.closest ) {
			return;
		}

		/*
		 * Abrir «ya lo pagué» desde acá cierra el año: dos ventanas una
		 * encima de la otra no se entienden, y al guardar la página se
		 * recarga igual.
		 */
		if ( caja.classList.contains( 'open' ) && e.target.closest( '[data-sus-pagar]' ) ) {
			cerrar();
			return;
		}

		var b = e.target.closest( '[data-sus-ano]' );

		if ( ! b ) {
			return;
		}

		e.stopPropagation();

		pedir( { action: 'malka_crm_sus_calendario', id: b.getAttribute( 'data-sus-ano' ) } ).then( function ( j ) {
			if ( ! j || ! j.success || ! j.data ) {
				return;
			}
			pintar( j.data );
			caja.classList.add( 'open' );
		} ).catch( function () {} );
	} );

	caja.addEventListener( 'click', function ( e ) {
		if ( e.target === caja || ( e.target.closest && e.target.closest( '[data-cerrar-modal]' ) ) ) {
			cerrar();
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			cerrar();
		}
	} );
}() );

/*
 * Devolverte lo que pusiste: se eligen los cargos, no un monto.
 *
 * El total se arma con lo marcado y el botón no se puede apretar hasta que
 * haya algo elegido: así nunca se anota una devolución que no corresponde a
 * ningún cargo, que era la forma de que el saldo y la lista dijeran cosas
 * distintas.
 */
( function () {
	'use strict';

	var form = document.querySelector( '[data-reemb]' );

	if ( ! form ) {
		return;
	}

	var total = form.querySelector( '[data-reemb-total]' );
	var ok    = form.querySelector( '[data-reemb-ok]' );
	var todos = form.querySelector( '[data-reemb-todos]' );

	function marcadas() {
		return form.querySelectorAll( '[data-reemb-ck]:checked' );
	}

	function miles( n ) {
		return String( n || 0 ).replace( /\B(?=(\d{3})+(?!\d))/g, '.' );
	}

	function contar() {
		var suma = 0;

		Array.prototype.forEach.call( marcadas(), function ( c ) {
			suma += parseInt( c.getAttribute( 'data-monto' ), 10 ) || 0;
		} );

		if ( total ) {
			total.textContent = '$' + miles( suma );
		}

		if ( ok ) {
			ok.disabled = 0 === suma;
		}

		if ( todos ) {
			var hay = form.querySelectorAll( '[data-reemb-ck]' ).length;
			todos.textContent = marcadas().length === hay ? 'Desmarcar todos' : 'Marcar todos';
		}
	}

	form.addEventListener( 'change', function ( e ) {
		if ( e.target.hasAttribute && e.target.hasAttribute( 'data-reemb-ck' ) ) {
			contar();
		}
	} );

	if ( todos ) {
		todos.addEventListener( 'click', function () {
			var cks  = form.querySelectorAll( '[data-reemb-ck]' );
			var pone = marcadas().length !== cks.length;

			Array.prototype.forEach.call( cks, function ( c ) {
				c.checked = pone;
			} );

			contar();
		} );
	}

	contar();
}() );

/* ─────────────  Ordenar la lista de negocios  ─────────────────────────
   Se ordena en el navegador, igual que se filtra: la lista entera ya está
   en la página y los negocios de un estudio no son miles de filas. No se
   esconde ninguna, sólo cambian de orden. */
( function () {
	'use strict';

	var sel = document.querySelector( '[data-nl-orden]' );
	var lista = document.getElementById( 'esc-nl' );

	if ( ! sel || ! lista ) {
		return;
	}

	/** El valor por el que se compara, según el orden elegido. */
	function valor( fila, campo ) {
		if ( 'monto' === campo ) {
			return parseInt( fila.getAttribute( 'data-monto' ) || '0', 10 );
		}
		if ( 'etapa' === campo ) {
			return parseInt( fila.getAttribute( 'data-etapa' ) || '0', 10 );
		}
		if ( 'nombre' === campo ) {
			return fila.getAttribute( 'data-nombre' ) || '';
		}
		// Entrega: la fecha en texto ya se compara bien, es aaaa-mm-dd.
		return fila.getAttribute( 'data-fin' ) || '9999-12-31';
	}

	function ordenar() {
		var v     = sel.value || 'entrega';
		var desc  = '-' === v.slice( -1 );
		var campo = desc ? v.slice( 0, -1 ) : v;
		var filas = Array.prototype.slice.call( lista.querySelectorAll( 'a.esc-nl--neg' ) );

		filas.sort( function ( a, b ) {
			var x = valor( a, campo );
			var y = valor( b, campo );
			var d = 0;

			if ( x < y ) {
				d = -1;
			} else if ( x > y ) {
				d = 1;
			}

			// A igualdad, por nombre: así dos negocios del mismo monto no se
			// turnan de lugar cada vez que se reordena.
			if ( 0 === d ) {
				var na = valor( a, 'nombre' );
				var nb = valor( b, 'nombre' );
				return na < nb ? -1 : ( na > nb ? 1 : 0 );
			}

			return desc ? -d : d;
		} );

		// El vacío se queda al final; el encabezado, arriba.
		var fin = lista.querySelector( '.esc-nl__vacio' );
		filas.forEach( function ( f ) {
			if ( fin ) {
				lista.insertBefore( f, fin );
			} else {
				lista.appendChild( f );
			}
		} );
	}

	sel.addEventListener( 'change', ordenar );
}() );

/* ─────────────  Marcar un evento de la agenda como hecho  ─────────────
   Hecho no es pasado: una reunión de ayer puede no haber ocurrido. El
   círculo es un botón aparte del que abre el evento, así que acá se
   detiene la propagación para que marcar no abra la ficha. */
( function () {
	'use strict';

	var cfg = document.querySelector( '[data-ev-ajax]' ) || document.getElementById( 'esc-agenda' );
	var url = ( cfg && cfg.getAttribute( 'data-ev-ajax' ) ) || ( window.MalkaEsc && window.MalkaEsc.ajax ) || '';
	var nonce = ( cfg && cfg.getAttribute( 'data-ev-nonce' ) ) || '';

	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest ? e.target.closest( '[data-ev-hecho]' ) : null;

		if ( ! b ) {
			return;
		}

		e.preventDefault();
		e.stopPropagation();

		var fila  = b.closest( '.esc-ev__i' );
		var hecho = 'true' !== b.getAttribute( 'aria-pressed' );

		// Se pinta al toque y se corrige si el servidor dice que no.
		b.setAttribute( 'aria-pressed', hecho ? 'true' : 'false' );
		b.classList.toggle( 'is-ok', hecho );
		if ( fila ) {
			fila.classList.toggle( 'is-hecho', hecho );
		}

		var d = new FormData();
		d.append( 'action', 'malka_crm_evento_hecho' );
		d.append( 'nonce', nonce );
		d.append( 'id', b.getAttribute( 'data-ev-hecho' ) );
		d.append( 'hecho', hecho ? '1' : '' );

		fetch( url, { method: 'POST', body: d, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) {
				if ( ! j || ! j.success ) {
					throw new Error( 'no' );
				}
			} )
			.catch( function () {
				b.setAttribute( 'aria-pressed', hecho ? 'false' : 'true' );
				b.classList.toggle( 'is-ok', ! hecho );
				if ( fila ) {
					fila.classList.toggle( 'is-hecho', ! hecho );
				}
			} );
	}, true );
}() );
