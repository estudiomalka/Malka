/**
 * La cotización que ve el cliente.
 *
 * Solo hace una cosa: cuando el cliente elige qué cuota pagar y por qué medio,
 * recalcula el monto. Todo lo demás lo decide el servidor, porque lo que se
 * decide en el navegador se puede cambiar desde el navegador.
 */
( function () {
	'use strict';

	var raiz = document.getElementById( 'malka-master-quote' );

	if ( ! raiz ) {
		return;
	}

	var CFG = window.malkaCotizacion || {};

	function id( x ) {
		return document.getElementById( x );
	}

	function plata( n ) {
		return '$' + Math.round( n || 0 ).toString().replace( /\B(?=(\d{3})+(?!\d))/g, '.' );
	}

	function elegida() {
		return raiz.querySelector( 'input[name="mq-pago"]:checked' );
	}

	function porTarjeta() {
		var t = raiz.querySelector( 'input[name="mq-metodo"]:checked' );
		return !! t && 'tarjeta' === t.value;
	}

	/** Enciende o apaga todo el aparato de pago de una vez. */
	function mostrarPago( si ) {
		[ 'boxSelectorPago', 'boxDatosBancarios', 'btnProcesarPago', 'formAvisoPago', 'boxPagarAhora' ]
			.forEach( function ( x ) {
				var el = id( x );
				if ( el ) {
					el.hidden = ! si;
				}
			} );

	}

	function pintar() {
		var cuota = elegida();

		/*
		 * Sin cuota elegida no se muestran ni el medio de pago ni los datos
		 * bancarios: el monto que saldría ahí no es el de nada que el
		 * cliente haya pedido.
		 */
		if ( ! cuota ) {
			mostrarPago( false );
			return;
		}

		mostrarPago( true );

		var base   = parseInt( cuota.getAttribute( 'data-monto' ), 10 ) || 0;
		var tasa   = parseFloat( CFG.comision ) || 0;
		var tarjeta = porTarjeta();

		/*
		 * Fórmula inversa, la misma de la plantilla: SumUp descuenta su
		 * comisión de lo que cobra, así que hay que cobrar un poco más para
		 * que a Malka le llegue el monto completo.
		 */
		var total = ( tarjeta && tasa > 0 ) ? Math.round( base / ( 1 - ( tasa / 100 ) ) ) : base;

		var sub = id( 'lblPagarAhoraSub' );
		if ( sub ) {
			sub.textContent = plata( base );
		}

		var etq = id( 'lblTextoPagarAhoraSub' );
		if ( etq ) {
			etq.textContent = cuota.getAttribute( 'data-label' ) || '';
		}

		var fila = id( 'rowComisionPagar' );
		var com  = id( 'lblComision' );
		if ( fila ) {
			fila.hidden = ! tarjeta || total <= base;
		}
		if ( com ) {
			com.textContent = plata( total - base );
		}

		var fin = id( 'lblPagarAhoraTotal' );
		if ( fin ) {
			fin.textContent = plata( total );
		}

		var transf = id( 'txtMontoTransferir' );
		if ( transf ) {
			transf.textContent = plata( base );
		}

		var banco = id( 'boxDatosBancarios' );
		if ( banco ) {
			banco.hidden = tarjeta;
		}

		/*
		 * El aviso de «ya transferí» es lo contrario del botón de tarjeta:
		 * la tarjeta vuelve sola con su resultado, la transferencia pasa
		 * fuera del sitio y alguien tiene que contarlo.
		 */
		var aviso = id( 'formAvisoPago' );
		if ( aviso ) {
			aviso.hidden = tarjeta;

			var cual = id( 'avisoCuota' );
			if ( cual ) {
				cual.value = cuota.value;
			}
		}

		var boton = id( 'btnProcesarPago' );
		if ( boton ) {
			boton.hidden = ! tarjeta;

			// El link lleva qué cuota, pero el monto lo calcula el servidor.
			var url = boton.getAttribute( 'data-base' ) || boton.getAttribute( 'href' );
			if ( ! boton.getAttribute( 'data-base' ) ) {
				boton.setAttribute( 'data-base', url );
			}
			boton.setAttribute(
				'href',
				url.replace( /([?&])cuota=[^&]*/, '$1cuota=' + encodeURIComponent( cuota.value ) )
			);
		}
	}

	raiz.addEventListener( 'change', function ( e ) {
		if ( e.target.closest( 'input[name="mq-pago"], input[name="mq-metodo"]' ) ) {
			pintar();
		}
	} );

	/*
	 * Desde el portal el cliente llega con la cuota y el medio ya elegidos
	 * (?cuota=…&pagar=tarjeta|transferencia): se marcan y se baja a pagar.
	 */
	( function () {
		var q = new URLSearchParams( window.location.search );
		var cuota = q.get( 'cuota' );
		var medio = q.get( 'pagar' );
		var hizo = false;

		if ( cuota ) {
			raiz.querySelectorAll( 'input[name="mq-pago"]' ).forEach( function ( r ) {
				if ( r.value === cuota ) {
					r.checked = true;
					hizo = true;
				}
			} );
		}

		if ( 'tarjeta' === medio || 'transferencia' === medio ) {
			var m = raiz.querySelector( 'input[name="mq-metodo"][value="' + medio + '"]' );
			if ( m ) {
				m.checked = true;
				hizo = true;
			}
		}

		if ( hizo ) {
			pintar();
			var caja = id( 'boxSelectorPago' );
			if ( caja && ! caja.hidden ) {
				setTimeout( function () {
					// Un poco más arriba, para que se vea también la cuota elegida.
					var y = caja.getBoundingClientRect().top + window.pageYOffset - 220;
					window.scrollTo( { top: Math.max( 0, y ), behavior: 'smooth' } );
				}, 60 );
			}
		}
	}() );

	pintar();
}() );
