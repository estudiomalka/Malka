<?php
/**
 * Lo que el estudio te debe.
 *
 * Hay costos del estudio que no puede pagar el estudio: las suscripciones
 * en dólares no pasan por la cuenta de la empresa, así que salen de una
 * tarjeta tuya. Esa plata la pusiste tú y el estudio te la debe.
 *
 * Acá se lleva esa cuenta: cuánto pusiste, qué te devolvieron y cuánto
 * queda. No es un gasto nuevo —el costo ya está contado donde corresponde—,
 * es un saldo entre dos bolsillos.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

class Malka_CRM_Reembolsos {

	public function __construct() {
		add_action( 'admin_post_malka_crm_devolver', array( __CLASS__, 'handle_devolver' ) );
	}

	/**
	 * Lo que pusiste tú y todavía no te devuelven.
	 *
	 * @return array puesto, devuelto, debe, movimientos.
	 */
	public static function cuenta() {
		$out = array(
			'puesto'    => 0,
			'devuelto'  => 0,
			'debe'      => 0,
			'movs'      => array(),
			'pendientes' => array(),
		);

		if ( ! class_exists( 'Malka_CRM_Finance' ) ) {
			return $out;
		}

		$tuyas = Malka_CRM_Finance::cuentas_de( true );

		if ( ! $tuyas ) {
			return $out;
		}

		$puestos  = array();
		$saldados = array();

		// Lo devuelto por movimientos que no dicen a qué cargo corresponden.
		$suelto = 0;

		foreach ( Malka_CRM_Finance::recent( 400 ) as $m ) {
			$cuenta = isset( $m->cuenta ) ? (int) $m->cuenta : 0;

			// Una entrada marcada como devolución: te la pagaron. Y dice
			// cuáles cargos saldó, para no tener que adivinarlo.
			if ( 'entrada' === $m->tipo && 'reembolso' === $m->categoria && isset( $tuyas[ $cuenta ] ) ) {
				$out['devuelto'] += (int) $m->monto;

				$ids = self::ids_de( isset( $m->referencia ) ? $m->referencia : '' );

				if ( ! $ids ) {
					$suelto += (int) $m->monto;
				}

				foreach ( $ids as $id ) {
					$saldados[ $id ] = (int) $m->id;
				}

				continue;
			}

			if ( ! isset( $tuyas[ $cuenta ] ) ) {
				continue;
			}

			// Una salida desde tu tarjeta por algo del estudio: lo pusiste tú.
			if ( 'salida' === $m->tipo && self::es_del_estudio( $m->categoria ) ) {
				$out['puesto'] += (int) $m->monto;
				$puestos[]     = $m;
			}
		}

		/*
		 * Cada cargo sabe si ya te lo devolvieron. Antes esto era un saldo
		 * global —puesto menos devuelto— y no había forma de mirar la lista
		 * y saber cuál de los cargos seguía pendiente.
		 */
		foreach ( $puestos as $m ) {
			$m->devuelto = isset( $saldados[ (int) $m->id ] ) ? (int) $saldados[ (int) $m->id ] : 0;

			$out['movs'][] = $m;

			if ( ! $m->devuelto ) {
				$out['pendientes'][] = $m;
			}
		}

		/*
		 * Las devoluciones anotadas antes de que esto llevara el detalle no
		 * dicen qué cargos saldaron. Sin esto, al actualizar el plugin esos
		 * cargos volverían a aparecer pendientes y el panel pediría devolver
		 * dos veces la misma plata. Se saldan los más viejos, que son los
		 * que esa devolución pagó.
		 */
		if ( $suelto > 0 && $out['pendientes'] ) {
			$viejos = $out['pendientes'];

			// Del más viejo al más nuevo, sin fiarse del orden en que vengan.
			usort(
				$viejos,
				function ( $a, $b ) {
					$f = strcmp( (string) $a->fecha, (string) $b->fecha );
					return 0 !== $f ? $f : ( (int) $a->id <=> (int) $b->id );
				}
			);

			$queda = array();

			foreach ( $viejos as $m ) {
				if ( $suelto >= (int) $m->monto ) {
					$suelto     -= (int) $m->monto;
					$m->devuelto = -1;
					continue;
				}
				$queda[] = $m;
			}

			// Y se devuelven en el orden en que llegaron, el más nuevo arriba.
			$out['pendientes'] = array_reverse( $queda );
		}

		// Lo que falta son los cargos que nadie saldó, uno por uno.
		$debe = 0;

		foreach ( $out['pendientes'] as $m ) {
			$debe += (int) $m->monto;
		}

		$out['debe'] = $debe;

		return $out;
	}

	/**
	 * Los ids de cargo que lleva anotada una devolución.
	 *
	 * @param string $ref Referencia, de la forma «reemb:12,15».
	 * @return int[]
	 */
	public static function ids_de( $ref ) {
		$ref = (string) $ref;

		if ( 0 !== strpos( $ref, 'reemb:' ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'intval', explode( ',', substr( $ref, 6 ) ) ) ) );
	}

	/**
	 * ¿Esta categoría es un costo del estudio?
	 *
	 * Lo que compras para ti con tu propia tarjeta no lo debe el estudio.
	 *
	 * @param string $cat Categoría.
	 * @return bool
	 */
	public static function es_del_estudio( $cat ) {
		return in_array( (string) $cat, array( 'costo_fijo', 'costo_variable', 'impuesto' ), true );
	}

	/**
	 * Registra que el estudio te devolvió plata.
	 *
	 * Son dos movimientos porque son dos bolsillos: sale de la cuenta de la
	 * empresa y entra a la tuya. Con uno solo, el día que quieras cuadrar
	 * tu tarjeta te faltaría la mitad.
	 *
	 * @param int    $monto  Cuánto.
	 * @param int    $cuenta A qué cuenta tuya entró.
	 * @param string $fecha  Y-m-d.
	 * @return bool
	 */
	public static function devolver( $cuenta, $ids, $fecha = '' ) {
		if ( ! class_exists( 'Malka_CRM_Finance' ) ) {
			return false;
		}

		$tuyas = Malka_CRM_Finance::cuentas_de( true );

		if ( ! isset( $tuyas[ (int) $cuenta ] ) ) {
			return false;
		}

		/*
		 * Se devuelven cargos, no un monto suelto. El monto sale de los
		 * cargos elegidos: así no hay forma de que la suma devuelta y la
		 * lista de lo pendiente digan cosas distintas, y después se puede
		 * mirar la lista y ver cuál quedó saldado.
		 */
		$pendientes = array();

		foreach ( self::cuenta()['pendientes'] as $m ) {
			$pendientes[ (int) $m->id ] = $m;
		}

		$elegidos = array();
		$monto    = 0;

		foreach ( (array) $ids as $id ) {
			$id = (int) $id;

			if ( ! isset( $pendientes[ $id ] ) ) {
				continue;
			}

			$elegidos[] = $id;
			$monto     += (int) $pendientes[ $id ]->monto;
		}

		if ( ! $elegidos || $monto <= 0 ) {
			return false;
		}

		$hoy   = current_time( 'Y-m-d' );
		$fecha = $fecha && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $fecha ) ? $fecha : $hoy;
		$fecha = $fecha > $hoy ? $hoy : $fecha;
		$nombre = Malka_CRM_Finance::nombre_cuenta( (int) $cuenta );
		$ref    = 'reemb:' . implode( ',', $elegidos );

		$que = count( $elegidos ) === 1
			? $pendientes[ $elegidos[0] ]->descripcion
			: sprintf( /* translators: cuántos */ _n( '%d cargo', '%d cargos', count( $elegidos ), 'malka-crm' ), count( $elegidos ) );

		/*
		 * Son dos movimientos porque son dos bolsillos: sale de la cuenta de
		 * la empresa y entra a la tuya. Con uno solo, el día que quieras
		 * cuadrar tu tarjeta te faltaría la mitad.
		 */
		Malka_CRM_Finance::add(
			array(
				'descripcion' => sprintf( /* translators: 1: cuenta, 2: qué se devuelve */ __( 'Devolución a %1$s · %2$s', 'malka-crm' ), $nombre, $que ),
				'referencia'  => $ref,
				'monto'       => $monto,
				'tipo'        => 'salida',
				'categoria'   => 'reembolso',
				'fecha'       => $fecha,
				'cuenta'      => Malka_CRM_Finance::principal(),
			)
		);

		Malka_CRM_Finance::add(
			array(
				'descripcion' => sprintf( /* translators: qué se devuelve */ __( 'Te devolvieron: %s', 'malka-crm' ), $que ),
				'referencia'  => $ref,
				'monto'       => $monto,
				'tipo'        => 'entrada',
				'categoria'   => 'reembolso',
				'fecha'       => $fecha,
				'cuenta'      => (int) $cuenta,
			)
		);

		return $monto;
	}

	public static function handle_devolver() {
		check_admin_referer( 'malka_crm_devolver' );

		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ) );
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$cuenta = isset( $_POST['cuenta'] ) ? (int) $_POST['cuenta'] : 0;
		$fecha  = isset( $_POST['fecha'] ) ? Malka_CRM_Helpers::sanitize_date( wp_unslash( $_POST['fecha'] ) ) : '';
		$ids    = isset( $_POST['cargos'] ) && is_array( $_POST['cargos'] ) ? array_map( 'intval', wp_unslash( $_POST['cargos'] ) ) : array();
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$hecho = self::devolver( $cuenta, $ids, $fecha );

		wp_safe_redirect(
			add_query_arg(
				'devuelto',
				$hecho ? (int) $hecho : 0,
				Malka_CRM_Dashboard::view_url( 'cuentas' ) . '#esc-reembolso'
			)
		);
		exit;
	}

	/**
	 * El panel, para Movimientos.
	 *
	 * @return string
	 */
	public static function render() {
		if ( ! class_exists( 'Malka_CRM_Finance' ) ) {
			return '';
		}

		$tuyas = Malka_CRM_Finance::cuentas_de( true );

		if ( ! $tuyas ) {
			return '';
		}

		$c = self::cuenta();

		/*
		 * Sin nada puesto, este panel ocupaba media pantalla para decir «$0».
		 * Aparece solo cuando hay algo que contar; mientras tanto el espacio
		 * se lo queda la lista de cuentas, que sí tiene qué mostrar.
		 */
		if ( ! $c['puesto'] ) {
			return '';
		}

		ob_start();
		echo '<section class="esc-panel esc-reembolso" id="esc-reembolso">';
		echo Malka_CRM_Dashboard::head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'peso',
			__( 'El estudio te debe', 'malka-crm' ),
			__( 'Costos del estudio que pagaste con una tarjeta tuya', 'malka-crm' ),
			Malka_CRM_Settings::money( $c['debe'] )
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$hecho = isset( $_GET['devuelto'] ) ? (int) $_GET['devuelto'] : 0;

		if ( $hecho > 0 ) {
			printf(
				'<p class="esc-reembolso__ok">%s</p>',
				esc_html( sprintf( /* translators: monto */ __( 'Anotado: te devolviste %s.', 'malka-crm' ), Malka_CRM_Settings::money( $hecho ) ) )
			);
		}

		printf(
			'<div class="esc-reembolso__r"><span>%1$s <b>%2$s</b></span><span>%3$s <b>%4$s</b></span></div>',
			esc_html__( 'Pusiste', 'malka-crm' ),
			esc_html( Malka_CRM_Settings::money( $c['puesto'] ) ),
			esc_html__( 'Te devolvieron', 'malka-crm' ),
			esc_html( Malka_CRM_Settings::money( $c['devuelto'] ) )
		);

		if ( $c['debe'] > 0 ) {
			/*
			 * Viene puesta la cuenta de la que salió más plata: es la que
			 * hay que reponer, y casi siempre es la única. Antes aparecía
			 * la primera de la lista, que podía no tener nada que ver.
			 */
			$por_cuenta = array();

			foreach ( $c['movs'] as $m ) {
				$k = isset( $m->cuenta ) ? (int) $m->cuenta : 0;

				$por_cuenta[ $k ] = ( isset( $por_cuenta[ $k ] ) ? $por_cuenta[ $k ] : 0 ) + (int) $m->monto;
			}

			arsort( $por_cuenta );
			$elegida = $por_cuenta ? (int) array_key_first( $por_cuenta ) : 0;

			$opciones = '';
			foreach ( $tuyas as $id => $x ) {
				$opciones .= sprintf(
					'<option value="%1$d"%3$s>%2$s</option>',
					(int) $id,
					esc_html( Malka_CRM_Finance::nombre_cuenta( (int) $id ) ),
					selected( (int) $id, $elegida, false )
				);
			}

			printf(
				'<form method="post" action="%1$s" class="esc-reembolso__f" data-reemb>%2$s' .
				'<input type="hidden" name="action" value="malka_crm_devolver" />',
				esc_url( admin_url( 'admin-post.php' ) ),
				wp_nonce_field( 'malka_crm_devolver', '_wpnonce', true, false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);

			/*
			 * Qué se está devolviendo, cargo por cargo. Un monto suelto
			 * dejaba el saldo cuadrado y la lista sin saber cuál de los
			 * cargos había quedado saldado.
			 */
			printf(
				'<div class="esc-reembolso__sel"><div class="esc-reembolso__selh"><b>%1$s</b>' .
				'<button type="button" class="esc-mini" data-reemb-todos>%2$s</button></div><ul>',
				esc_html__( 'Qué te estás devolviendo', 'malka-crm' ),
				esc_html__( 'Marcar todos', 'malka-crm' )
			);

			foreach ( $c['pendientes'] as $m ) {
				printf(
					'<li><label><input type="checkbox" name="cargos[]" value="%1$d" data-reemb-ck data-monto="%2$d" />' .
					'<span><b>%3$s</b><em>%4$s</em></span><b>%5$s</b></label></li>',
					(int) $m->id,
					(int) $m->monto,
					esc_html( $m->descripcion ),
					esc_html(
						date_i18n( 'j M Y', strtotime( $m->fecha ) ) . ' · ' .
						Malka_CRM_Finance::nombre_cuenta( isset( $m->cuenta ) ? (int) $m->cuenta : 0 )
					),
					esc_html( Malka_CRM_Settings::money( (int) $m->monto ) )
				);
			}

			printf(
				'</ul><p class="esc-reembolso__tot">%1$s <b data-reemb-total>%2$s</b></p></div>',
				esc_html__( 'Te devuelves', 'malka-crm' ),
				esc_html( Malka_CRM_Settings::money( 0 ) )
			);

			printf(
				'<div class="esc-reembolso__campos">' .
				'<label><span>%1$s</span><select name="cuenta">%2$s</select></label>' .
				'<label><span>%3$s</span><input type="date" name="fecha" value="%4$s" max="%4$s" /></label>' .
				'<button type="submit" class="esc-btn esc-btn--primary" data-reemb-ok disabled>%5$s</button></div></form>',
				esc_html__( 'A qué cuenta', 'malka-crm' ),
				$opciones, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html__( 'Cuándo', 'malka-crm' ),
				esc_attr( current_time( 'Y-m-d' ) ),
				esc_html__( 'Anotar', 'malka-crm' )
			);

			echo '<p class="esc-hint">' . esc_html__( 'Anótalo después de hacer la transferencia desde la cuenta del estudio. Baja el saldo de la empresa y deja esos cargos saldados.', 'malka-crm' ) . '</p>';
		} else {
			echo '<p class="esc-empty">' . esc_html__( 'Estás a mano: no te deben nada.', 'malka-crm' ) . '</p>';
		}

		// Y lo ya devuelto, para poder mirar atrás.
		$hechos = array_filter(
			$c['movs'],
			function ( $m ) {
				return ! empty( $m->devuelto );
			}
		);

		if ( $hechos ) {
			printf(
				'<details class="esc-reembolso__ya"><summary>%s</summary><ul>',
				esc_html(
					sprintf(
						/* translators: cuántos */
						_n( 'Ver %d cargo ya devuelto', 'Ver %d cargos ya devueltos', count( $hechos ), 'malka-crm' ),
						count( $hechos )
					)
				)
			);

			foreach ( array_slice( $hechos, 0, 24 ) as $m ) {
				printf(
					'<li><span><b>%1$s</b><em>%2$s</em></span><b>%3$s</b></li>',
					esc_html( $m->descripcion ),
					esc_html(
						date_i18n( 'j M Y', strtotime( $m->fecha ) ) . ' · ' .
						Malka_CRM_Finance::nombre_cuenta( isset( $m->cuenta ) ? (int) $m->cuenta : 0 )
					),
					esc_html( Malka_CRM_Settings::money( (int) $m->monto ) )
				);
			}

			echo '</ul></details>';
		}

		echo '</section>';
		return ob_get_clean();
	}
}
