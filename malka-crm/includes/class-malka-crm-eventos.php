<?php
/**
 * Eventos: reuniones, llamadas y visitas.
 *
 * Un evento no es una tarea. Una tarea se hace cuando se puede y se tacha;
 * un evento pasa a una hora, dure lo que dure, y después queda atrás solo.
 * Por eso vive aparte y no se «completa»: se mira antes y se olvida después.
 *
 * Se guardan en una opción, como las deudas: son pocos y se leen enteros
 * cada vez que se dibuja el escritorio. Si algún día son cientos habrá que
 * moverlos a una tabla, pero hoy una tabla sería una tabla vacía.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

class Malka_CRM_Eventos {

	/** Dónde se guardan. */
	const OPT = 'malka_crm_eventos';

	/** Cuántos días hacia adelante muestra el panel del inicio. */
	const DIAS = 14;

	/** Cuánto dura un evento si no se dice nada. */
	const DURA = 60;

	public function __construct() {
		add_action( 'admin_post_malka_crm_evento_guardar', array( __CLASS__, 'handle_guardar' ) );
		add_action( 'admin_post_malka_crm_evento_borrar', array( __CLASS__, 'handle_borrar' ) );
		add_action( 'wp_ajax_malka_crm_evento_detalle', array( __CLASS__, 'ajax_detalle' ) );
		add_action( 'wp_ajax_malka_crm_evento_hecho', array( __CLASS__, 'ajax_hecho' ) );
	}

	/* ──────────────────────────────  Datos  ──────────────────────────────── */

	/**
	 * Qué clase de evento es. Sólo cambia el ícono y cómo se lee.
	 *
	 * @return array
	 */
	public static function tipos() {
		return array(
			'reunion' => __( 'Reunión', 'malka-crm' ),
			'llamada' => __( 'Llamada', 'malka-crm' ),
			'visita'  => __( 'Visita', 'malka-crm' ),
			'entrega' => __( 'Entrega', 'malka-crm' ),
			'otro'    => __( 'Otra cosa', 'malka-crm' ),
		);
	}

	/**
	 * Todos los eventos guardados, ordenados por cuándo son.
	 *
	 * @return array
	 */
	public static function todos() {
		$g = get_option( self::OPT, array() );

		if ( ! is_array( $g ) ) {
			return array();
		}

		$out = array();

		foreach ( $g as $id => $e ) {
			if ( ! is_array( $e ) || empty( $e['fecha'] ) ) {
				continue;
			}

			$e['id']    = (int) $id;
			$e['hora']  = isset( $e['hora'] ) ? (string) $e['hora'] : '';
			$e['dura']  = isset( $e['dura'] ) ? (int) $e['dura'] : self::DURA;
			$e['tipo']  = isset( $e['tipo'] ) && array_key_exists( $e['tipo'], self::tipos() ) ? $e['tipo'] : 'reunion';
			$e['desde'] = $e['fecha'] . ' ' . ( $e['hora'] ? $e['hora'] : '00:00' );
			$e['hecho'] = ! empty( $e['hecho'] );

			$out[] = $e;
		}

		usort(
			$out,
			function ( $a, $b ) {
				return strcmp( $a['desde'], $b['desde'] );
			}
		);

		return $out;
	}

	/**
	 * Uno solo.
	 *
	 * @param int $id Cuál.
	 * @return array|null
	 */
	public static function uno( $id ) {
		foreach ( self::todos() as $e ) {
			if ( (int) $e['id'] === (int) $id ) {
				return $e;
			}
		}

		return null;
	}

	/**
	 * Los de un día.
	 *
	 * @param string $fecha Y-m-d.
	 * @return array
	 */
	public static function del_dia( $fecha ) {
		$out = array();

		foreach ( self::todos() as $e ) {
			if ( $e['fecha'] === $fecha ) {
				$out[] = $e;
			}
		}

		return $out;
	}

	/**
	 * Lo que viene: de hoy en adelante, hasta donde se pida.
	 *
	 * Lo de hoy entra completo aunque ya haya pasado la hora: uno mira la
	 * agenda para saber qué tuvo el día, no sólo qué le queda.
	 *
	 * @param int $dias Cuántos días hacia adelante.
	 * @return array
	 */
	public static function proximos( $dias = self::DIAS ) {
		$hoy   = current_time( 'Y-m-d' );
		$tope  = gmdate( 'Y-m-d', strtotime( $hoy . ' +' . max( 0, (int) $dias ) . ' days' ) );
		$out   = array();

		foreach ( self::todos() as $e ) {
			if ( $e['fecha'] >= $hoy && $e['fecha'] <= $tope ) {
				$out[] = $e;
			}
		}

		return $out;
	}

	/* ─────────────────────────────  Guardar  ─────────────────────────────── */

	/**
	 * Anota o corrige un evento.
	 *
	 * Sin título o sin fecha no hay evento: un bloque de hora sin nombre no
	 * le dice nada a nadie dentro de una semana.
	 *
	 * @param array $d Lo que viene del formulario.
	 * @return int|WP_Error El id.
	 */
	public static function guardar( $d ) {
		$g = get_option( self::OPT, array() );
		$g = is_array( $g ) ? $g : array();

		$id = isset( $d['id'] ) ? (int) $d['id'] : 0;
		$x  = ( $id && isset( $g[ $id ] ) && is_array( $g[ $id ] ) ) ? $g[ $id ] : array();

		$titulo = isset( $d['titulo'] ) ? sanitize_text_field( (string) $d['titulo'] ) : ( isset( $x['titulo'] ) ? $x['titulo'] : '' );
		$fecha  = isset( $d['fecha'] ) ? (string) $d['fecha'] : ( isset( $x['fecha'] ) ? $x['fecha'] : '' );

		if ( '' === trim( $titulo ) ) {
			return new WP_Error( 'malka_crm_evento_sin_titulo', __( 'Ponle un nombre al evento.', 'malka-crm' ) );
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $fecha ) ) {
			return new WP_Error( 'malka_crm_evento_sin_fecha', __( 'Falta el día del evento.', 'malka-crm' ) );
		}

		$hora = isset( $d['hora'] ) ? (string) $d['hora'] : ( isset( $x['hora'] ) ? $x['hora'] : '' );
		$hora = preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $hora ) ? $hora : '';

		$dura = isset( $d['dura'] ) ? (int) $d['dura'] : ( isset( $x['dura'] ) ? (int) $x['dura'] : self::DURA );
		$dura = max( 0, min( 1440, $dura ) );

		$tipo = isset( $d['tipo'] ) ? sanitize_key( (string) $d['tipo'] ) : ( isset( $x['tipo'] ) ? $x['tipo'] : 'reunion' );

		$x = array(
			'titulo'  => $titulo,
			'fecha'   => $fecha,
			'hora'    => $hora,
			'dura'    => $dura,
			'tipo'    => array_key_exists( $tipo, self::tipos() ) ? $tipo : 'reunion',
			'donde'   => isset( $d['donde'] ) ? sanitize_text_field( (string) $d['donde'] ) : ( isset( $x['donde'] ) ? $x['donde'] : '' ),
			'quien'   => isset( $d['quien'] ) ? sanitize_text_field( (string) $d['quien'] ) : ( isset( $x['quien'] ) ? $x['quien'] : '' ),
			'negocio' => isset( $d['negocio'] ) ? (int) $d['negocio'] : ( isset( $x['negocio'] ) ? (int) $x['negocio'] : 0 ),
			'nota'    => isset( $d['nota'] ) ? sanitize_textarea_field( (string) $d['nota'] ) : ( isset( $x['nota'] ) ? $x['nota'] : '' ),
			'creado'  => isset( $x['creado'] ) ? $x['creado'] : current_time( 'Y-m-d H:i:s' ),
			/*
			 * Hecho no es lo mismo que pasado. Una reunión de ayer puede no
			 * haber ocurrido, y una de hoy a las 9 puede estar lista a las
			 * 9:40. Por eso se guarda aparte de la fecha.
			 */
			'hecho'   => isset( $d['hecho'] ) ? (int) (bool) $d['hecho'] : ( isset( $x['hecho'] ) ? (int) $x['hecho'] : 0 ),
		);

		if ( ! $id ) {
			$id = 1;

			foreach ( array_keys( $g ) as $k ) {
				$id = max( $id, (int) $k + 1 );
			}
		}

		$g[ $id ] = $x;
		update_option( self::OPT, $g, false );

		return $id;
	}

	/**
	 * Marca o desmarca un evento como hecho.
	 *
	 * @param int  $id    Cuál.
	 * @param bool $hecho Si queda hecho.
	 * @return bool Si existía.
	 */
	public static function marcar( $id, $hecho = true ) {
		$g  = get_option( self::OPT, array() );
		$g  = is_array( $g ) ? $g : array();
		$id = (int) $id;

		if ( ! isset( $g[ $id ] ) || ! is_array( $g[ $id ] ) ) {
			return false;
		}

		$g[ $id ]['hecho'] = (int) (bool) $hecho;
		update_option( self::OPT, $g, false );

		return true;
	}

	/**
	 * Marcar un evento desde la agenda, sin recargar.
	 */
	public static function ajax_hecho() {
		check_ajax_referer( 'malka_crm_inicio', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permiso.', 'malka-crm' ) ), 403 );
		}

		$id    = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$hecho = isset( $_POST['hecho'] ) && '1' === (string) $_POST['hecho'];

		if ( ! self::marcar( $id, $hecho ) ) {
			wp_send_json_error( array( 'message' => __( 'Ese evento ya no está.', 'malka-crm' ) ), 404 );
		}

		wp_send_json_success( array( 'hecho' => $hecho ) );
	}

	/**
	 * Lo saca de la agenda.
	 *
	 * @param int $id Cuál.
	 * @return bool
	 */
	public static function borrar( $id ) {
		$g = get_option( self::OPT, array() );

		if ( ! is_array( $g ) || ! isset( $g[ (int) $id ] ) ) {
			return false;
		}

		unset( $g[ (int) $id ] );
		update_option( self::OPT, $g, false );

		return true;
	}

	/* ────────────────────────────  Cómo se lee  ──────────────────────────── */

	/**
	 * Qué día es, dicho como lo diría una persona.
	 *
	 * @param array $e El evento.
	 * @return string
	 */
	public static function dia( $e ) {
		$hoy    = current_time( 'Y-m-d' );
		$manana = gmdate( 'Y-m-d', strtotime( $hoy . ' +1 day' ) );
		$f      = isset( $e['fecha'] ) ? $e['fecha'] : '';

		if ( $f === $hoy ) {
			return __( 'hoy', 'malka-crm' );
		}

		if ( $f === $manana ) {
			return __( 'mañana', 'malka-crm' );
		}

		return date_i18n( 'D j M', strtotime( $f ) );
	}

	/**
	 * Cuándo es, con día y hora. Para donde no se vea la hora aparte.
	 *
	 * @param array $e El evento.
	 * @return string
	 */
	public static function cuando( $e ) {
		$dia = self::dia( $e );

		return empty( $e['hora'] ) ? $dia : $dia . ' · ' . $e['hora'];
	}

	/**
	 * A qué hora termina, si se sabe cuánto dura.
	 *
	 * @param array $e El evento.
	 * @return string Vacío si no se puede saber.
	 */
	public static function termina( $e ) {
		if ( empty( $e['hora'] ) || empty( $e['dura'] ) ) {
			return '';
		}

		return gmdate( 'H:i', strtotime( $e['fecha'] . ' ' . $e['hora'] ) + ( (int) $e['dura'] * 60 ) );
	}

	/**
	 * La segunda línea: con quién, dónde y de qué negocio.
	 *
	 * @param array $e El evento.
	 * @return string
	 */
	public static function detalle( $e ) {
		$p = array();

		if ( ! empty( $e['quien'] ) ) {
			$p[] = $e['quien'];
		}

		if ( ! empty( $e['donde'] ) ) {
			$p[] = $e['donde'];
		}

		if ( ! empty( $e['negocio'] ) && class_exists( 'Malka_CRM_Projects' ) ) {
			$n = get_post( (int) $e['negocio'] );

			if ( $n ) {
				$p[] = $n->post_title;
			}
		}

		return implode( ' · ', $p );
	}

	/* ──────────────────────────────  Pantalla  ───────────────────────────── */

	/**
	 * El bloque del inicio: lo que viene en la agenda.
	 *
	 * @return string
	 */
	/**
	 * La Agenda como pantalla propia, con dos meses por delante.
	 *
	 * El panel del Inicio muestra dos semanas, que es lo que cabe sin tapar
	 * el resto. Acá hay sitio: se ve lo que viene, agrupado por día, y lo
	 * de hoy arriba.
	 *
	 * @return string
	 */
	public static function render() {
		ob_start();
		echo '<div class="esc-vista esc-vista--ancha esc-vista--total">';
		echo self::panel( 60 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * El panel de la agenda.
	 *
	 * @param int $dias Cuántos días hacia adelante mirar.
	 * @return string
	 */
	public static function panel( $dias = self::DIAS ) {
		$prox = self::proximos( $dias );

		ob_start();
		printf(
			'<section class="esc-panel esc-agenda" id="esc-agenda" data-ev-ajax="%1$s" data-ev-nonce="%2$s">',
			esc_url( admin_url( 'admin-ajax.php' ) ),
			esc_attr( wp_create_nonce( 'malka_crm_inicio' ) )
		);

		echo Malka_CRM_Dashboard::head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'calendario',
			__( 'Tu agenda', 'malka-crm' ),
			$dias > 30
				? __( 'Reuniones, llamadas y visitas de los próximos dos meses', 'malka-crm' )
				: __( 'Reuniones, llamadas y visitas de las próximas dos semanas', 'malka-crm' ),
			$prox ? (string) count( $prox ) : '',
			$prox ? _n( 'evento', 'eventos', count( $prox ), 'malka-crm' ) : '',
			'',
			// El + para anotar uno, que abre la misma ventana del menú.
			sprintf(
				'<button type="button" class="esc-ph__mas" id="esc-open-evento" aria-label="%1$s" title="%1$s">' .
				'<svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true">' .
				'<path d="M8 3.2v9.6M3.2 8h9.6"/></svg></button>',
				esc_attr__( 'Anotar una reunión', 'malka-crm' )
			)
		);

		if ( ! $prox ) {
			printf(
				'<p class="esc-empty">%s</p>',
				esc_html__( 'No tienes nada agendado. Lo que anotes acá aparece el día que toca.', 'malka-crm' )
			);
			echo '</section>';
			return ob_get_clean();
		}

		$hoy   = current_time( 'Y-m-d' );
		$ahora = current_time( 'H:i' );

		/*
		 * Dos formas de mirar lo mismo. Las dos se dibujan y el javascript
		 * muestra una: así cambiar de vista es instantáneo y, sin
		 * javascript, igual se ve la lista.
		 */
		printf(
			'<div class="esc-ag__sel" role="tablist" aria-label="%1$s">' .
			'<button type="button" role="tab" data-ag-vista="lista" aria-selected="true">%2$s</button>' .
			'<button type="button" role="tab" data-ag-vista="mes" aria-selected="false">%3$s</button>' .
			'</div>',
			esc_attr__( 'Cómo ver la agenda', 'malka-crm' ),
			esc_html__( 'Lista', 'malka-crm' ),
			esc_html__( 'Calendario', 'malka-crm' )
		);

		echo '<ul class="esc-ev" data-ag-panel="lista">';

		foreach ( $prox as $e ) {
			// Lo de hoy es de hoy aunque ya haya pasado: lo que cambia es
			// que se apaga, no que deje de ser del día.
			$es_hoy = $e['fecha'] === $hoy;
			$paso   = $es_hoy && $e['hora'] && $e['hora'] < $ahora;
			$fin  = self::termina( $e );
			$det  = self::detalle( $e );

			printf(
				/*
				 * Dos botones hermanos, no uno dentro de otro: el círculo
				 * marca hecho y el texto abre el evento. Antes la fila
				 * entera era el botón; meterle el círculo adentro habría
				 * dejado un botón dentro de otro, que el lector de pantalla
				 * no anuncia y el teclado no alcanza.
				 */
				'<li class="esc-ev__i%1$s">' .
				'<button type="button" class="esc-dia__chk esc-ev__ok%9$s" data-ev-hecho="%2$d" aria-pressed="%10$s" aria-label="%11$s" title="%11$s"></button>' .
				'<button type="button" class="esc-ev__a" data-evento="%2$d">' .
				'<span class="esc-ev__h"><b>%3$s</b>%4$s</span>' .
				'<span class="esc-ev__t"><b>%5$s</b>%6$s</span>' .
				'<span class="esc-ev__c">%7$s</span></button></li>',
				( $es_hoy ? ' is-hoy' : '' ) . ( $paso ? ' is-paso' : '' ) . ( ! empty( $e['hecho'] ) ? ' is-hecho' : '' ),
				(int) $e['id'],
				esc_html( $e['hora'] ? $e['hora'] : '—' ),
				$fin ? '<i>' . esc_html( $fin ) . '</i>' : '',
				esc_html( $e['titulo'] ),
				$det ? '<i>' . esc_html( $det ) . '</i>' : '',
				esc_html( self::dia( $e ) ),
				'', // Hueco: el 8 lo ocupaba el role/tabindex que se fue.
				! empty( $e['hecho'] ) ? ' is-ok' : '',
				! empty( $e['hecho'] ) ? 'true' : 'false',
				esc_attr(
					! empty( $e['hecho'] )
						? __( 'Hecho. Toca para desmarcar', 'malka-crm' )
						: __( 'Marcar como hecho', 'malka-crm' )
				)
			);
		}

		echo '</ul>';
		echo self::vista_mes( $prox, $hoy ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</section>';

		return ob_get_clean();
	}

	/**
	 * La agenda como calendario: un cuadro por mes, con los días.
	 *
	 * Los mismos eventos que la lista, puestos en su casilla. Sirve para
	 * otra pregunta: la lista contesta «qué viene ahora» y el calendario
	 * «cómo viene la semana», que es lo que se mira para saber si cabe una
	 * reunión más.
	 *
	 * @param array[] $prox Los eventos que vienen.
	 * @param string  $hoy  Y-m-d.
	 * @return string
	 */
	private static function vista_mes( $prox, $hoy ) {
		// Por día, para no recorrer la lista entera en cada casilla.
		$porfecha = array();

		foreach ( $prox as $e ) {
			$porfecha[ $e['fecha'] ][] = $e;
		}

		$meses = array();

		foreach ( array_keys( $porfecha ) as $f ) {
			$meses[ substr( $f, 0, 7 ) ] = true;
		}

		// Siempre el mes que corre, tenga o no eventos: si no, al abrir el
		// calendario en un mes vacío no se vería nada.
		$meses[ substr( $hoy, 0, 7 ) ] = true;
		$meses = array_keys( $meses );
		sort( $meses );

		$dias = array(
			__( 'lu', 'malka-crm' ),
			__( 'ma', 'malka-crm' ),
			__( 'mi', 'malka-crm' ),
			__( 'ju', 'malka-crm' ),
			__( 'vi', 'malka-crm' ),
			__( 'sá', 'malka-crm' ),
			__( 'do', 'malka-crm' ),
		);

		ob_start();
		echo '<div data-ag-panel="mes" hidden>';

		foreach ( $meses as $ym ) {
			$primero = strtotime( $ym . '-01' );
			$largo   = (int) gmdate( 't', $primero );
			// La semana empieza el lunes: gmdate('N') da 1 el lunes.
			$hueco   = (int) gmdate( 'N', $primero ) - 1;

			printf( '<div class="esc-cal"><h3 class="esc-cal__h">%s</h3><div class="esc-cal__g">', esc_html( date_i18n( 'F Y', $primero ) ) );

			foreach ( $dias as $d ) {
				printf( '<span class="esc-cal__d">%s</span>', esc_html( $d ) );
			}

			for ( $i = 0; $i < $hueco; $i++ ) {
				echo '<span class="esc-cal__c esc-cal__c--vacia"></span>';
			}

			for ( $n = 1; $n <= $largo; $n++ ) {
				$f    = sprintf( '%s-%02d', $ym, $n );
				$hay  = isset( $porfecha[ $f ] ) ? $porfecha[ $f ] : array();
				$case = '<span class="esc-cal__n">' . esc_html( (string) $n ) . '</span>';

				foreach ( $hay as $e ) {
					$case .= sprintf(
						'<button type="button" class="esc-cal__e%4$s" data-evento="%1$d" title="%3$s">%2$s</button>',
						(int) $e['id'],
						esc_html( ( $e['hora'] ? $e['hora'] . ' ' : '' ) . $e['titulo'] ),
						esc_attr( $e['titulo'] ),
						! empty( $e['hecho'] ) ? ' is-hecho' : ''
					);
				}

				printf(
					'<div class="esc-cal__c%2$s">%1$s</div>',
					$case, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					( $f === $hoy ? ' is-hoy' : '' ) . ( $hay ? ' tiene' : '' )
				);
			}

			echo '</div></div>';
		}

		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * La ventana para anotar o corregir un evento.
	 *
	 * Una sola para las dos cosas: el javascript la llena cuando se abre
	 * desde un evento que ya existe, y la deja en blanco cuando viene del «+».
	 *
	 * @return string
	 */
	public static function modal() {
		$negocios = class_exists( 'Malka_CRM_Projects' ) ? Malka_CRM_Projects::all() : array();

		ob_start();
		echo '<div class="esc-modal" id="esc-modal-evento"><div class="esc-modal__c esc-nuevo-t">';
		printf( '<h3 class="esc-modal__h" data-ev-titulo>%s</h3>', esc_html__( 'Nuevo evento', 'malka-crm' ) );
		printf( '<p class="esc-modal__p">%s</p>', esc_html__( 'Una reunión, una llamada, una visita: algo que pasa a una hora', 'malka-crm' ) );

		printf( '<form class="esc-nueva" method="post" action="%s" data-ev-form>', esc_url( admin_url( 'admin-post.php' ) ) );
		printf( '<input type="hidden" name="desde" value="%s" />', esc_attr( Malka_CRM_Dashboard::current_view() ) );
		wp_nonce_field( 'malka_crm_evento_guardar', '_wpnonce' );
		echo '<input type="hidden" name="action" value="malka_crm_evento_guardar" />';
		echo '<input type="hidden" name="id" value="0" data-ev-id />';

		printf(
			'<label class="esc-nueva__q"><span>%1$s</span>' .
			'<input type="text" name="titulo" data-ev-t placeholder="%2$s" required /></label>',
			esc_html__( 'Qué es', 'malka-crm' ),
			esc_attr__( 'Reunión de avance con Javiera…', 'malka-crm' )
		);

		printf( '<label class="esc-nueva__c"><span>%s</span><select name="tipo" data-ev-tipo>', esc_html__( 'Tipo', 'malka-crm' ) );
		foreach ( self::tipos() as $k => $r ) {
			printf( '<option value="%1$s">%2$s</option>', esc_attr( $k ), esc_html( $r ) );
		}
		echo '</select></label>';

		printf(
			'<label class="esc-nueva__c"><span>%1$s</span><input type="date" name="fecha" data-ev-f required /></label>',
			esc_html__( 'Qué día', 'malka-crm' )
		);

		printf(
			'<label class="esc-nueva__c"><span>%1$s</span><input type="time" name="hora" data-ev-h /></label>',
			esc_html__( 'A qué hora', 'malka-crm' )
		);

		printf( '<label class="esc-nueva__c"><span>%s</span><select name="dura" data-ev-d>', esc_html__( 'Cuánto dura', 'malka-crm' ) );
		foreach ( array(
			15  => __( '15 minutos', 'malka-crm' ),
			30  => __( 'Media hora', 'malka-crm' ),
			60  => __( 'Una hora', 'malka-crm' ),
			90  => __( 'Hora y media', 'malka-crm' ),
			120 => __( 'Dos horas', 'malka-crm' ),
			240 => __( 'Media jornada', 'malka-crm' ),
			480 => __( 'Todo el día', 'malka-crm' ),
		) as $k => $r ) {
			printf( '<option value="%1$d"%3$s>%2$s</option>', (int) $k, esc_html( $r ), 60 === $k ? ' selected' : '' );
		}
		echo '</select></label>';

		printf(
			'<label class="esc-nueva__c"><span>%1$s</span><input type="text" name="quien" data-ev-q placeholder="%2$s" /></label>',
			esc_html__( 'Con quién', 'malka-crm' ),
			esc_attr__( 'Javiera, el contador…', 'malka-crm' )
		);

		printf(
			'<label class="esc-nueva__c"><span>%1$s</span><input type="text" name="donde" data-ev-w placeholder="%2$s" /></label>',
			esc_html__( 'Dónde', 'malka-crm' ),
			esc_attr__( 'Meet, su oficina, el estudio…', 'malka-crm' )
		);

		printf( '<label class="esc-nueva__c"><span>%1$s</span><select name="negocio" data-ev-n>', esc_html__( 'De qué negocio', 'malka-crm' ) );
		printf( '<option value="0">%s</option>', esc_html__( 'De ninguno', 'malka-crm' ) );

		foreach ( $negocios as $n ) {
			printf(
				'<option value="%1$d">%2$s</option>',
				(int) $n['id'],
				esc_html( trim( $n['titulo'] . ( $n['cliente'] ? ' · ' . $n['cliente'] : '' ) ) )
			);
		}

		echo '</select></label>';

		printf(
			'<label class="esc-nueva__q"><span>%1$s</span><textarea name="nota" data-ev-nota rows="2" placeholder="%2$s"></textarea></label>',
			esc_html__( 'Nota', 'malka-crm' ),
			esc_attr__( 'Qué hay que llevar, qué hay que preguntar…', 'malka-crm' )
		);

		echo '<div class="esc-modal__f esc-nueva__pie">';
		printf(
			'<button type="submit" form="esc-ev-borrar" class="esc-btn esc-btn--borrar" data-ev-x hidden>%s</button>',
			esc_html__( 'Borrar', 'malka-crm' )
		);
		printf( '<button type="button" class="esc-btn" data-cerrar-modal>%s</button>', esc_html__( 'Cancelar', 'malka-crm' ) );
		printf( '<button type="submit" class="esc-btn esc-btn--primary" data-ev-ok>%s</button>', esc_html__( 'Agendar', 'malka-crm' ) );
		echo '</div>';
		echo '</form>';

		// El borrar va en su propio formulario para que no arrastre el resto.
		printf( '<form method="post" action="%s" id="esc-ev-borrar" hidden>', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'malka_crm_evento_borrar', '_wpnonce' );
		echo '<input type="hidden" name="action" value="malka_crm_evento_borrar" />';
		echo '<input type="hidden" name="id" value="0" data-ev-bid />';
		printf( '<input type="hidden" name="desde" value="%s" />', esc_attr( Malka_CRM_Dashboard::current_view() ) );
		echo '</form>';

		echo '</div></div>';

		return ob_get_clean();
	}

	/* ─────────────────────────────  Handlers  ────────────────────────────── */

	/**
	 * Vuelve a donde estaba, diciendo qué pasó.
	 *
	 * @param string $aviso Qué decir.
	 * @param bool   $mal   Si fue un problema.
	 */
	private static function volver( $aviso, $mal = false ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$vista = isset( $_POST['desde'] ) ? sanitize_key( wp_unslash( $_POST['desde'] ) ) : 'inicio';

		wp_safe_redirect(
			add_query_arg(
				array( $mal ? 'malka_error' : 'malka_ok' => rawurlencode( $aviso ) ),
				Malka_CRM_Dashboard::view_url( $vista )
			)
		);
		exit;
	}

	/**
	 * Guarda lo que vino del formulario.
	 */
	public static function handle_guardar() {
		check_admin_referer( 'malka_crm_evento_guardar' );

		if ( ! Malka_CRM_Helpers::current_user_is_manager() ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$r = self::guardar(
			array(
				'id'      => isset( $_POST['id'] ) ? (int) $_POST['id'] : 0,
				'titulo'  => isset( $_POST['titulo'] ) ? wp_unslash( $_POST['titulo'] ) : '',
				'fecha'   => isset( $_POST['fecha'] ) ? sanitize_text_field( wp_unslash( $_POST['fecha'] ) ) : '',
				'hora'    => isset( $_POST['hora'] ) ? sanitize_text_field( wp_unslash( $_POST['hora'] ) ) : '',
				'dura'    => isset( $_POST['dura'] ) ? (int) $_POST['dura'] : self::DURA,
				'tipo'    => isset( $_POST['tipo'] ) ? sanitize_key( wp_unslash( $_POST['tipo'] ) ) : 'reunion',
				'quien'   => isset( $_POST['quien'] ) ? wp_unslash( $_POST['quien'] ) : '',
				'donde'   => isset( $_POST['donde'] ) ? wp_unslash( $_POST['donde'] ) : '',
				'negocio' => isset( $_POST['negocio'] ) ? (int) $_POST['negocio'] : 0,
				'nota'    => isset( $_POST['nota'] ) ? wp_unslash( $_POST['nota'] ) : '',
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( is_wp_error( $r ) ) {
			self::volver( $r->get_error_message(), true );
		}

		self::volver( __( 'Lo anoté en tu agenda.', 'malka-crm' ) );
	}

	/**
	 * Lo saca.
	 */
	public static function handle_borrar() {
		check_admin_referer( 'malka_crm_evento_borrar' );

		if ( ! Malka_CRM_Helpers::current_user_is_manager() ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		self::borrar( $id );
		self::volver( __( 'Lo saqué de la agenda.', 'malka-crm' ) );
	}

	/**
	 * Lo que necesita la ventana para abrirse con un evento adentro.
	 */
	public static function ajax_detalle() {
		if ( ! Malka_CRM_Helpers::current_user_is_manager() ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'malka-crm' ) ), 403 );
		}

		check_ajax_referer( 'malka_crm_inicio', 'nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$e = self::uno( isset( $_POST['id'] ) ? (int) $_POST['id'] : 0 );

		if ( ! $e ) {
			wp_send_json_error( array( 'message' => __( 'Ese evento ya no está.', 'malka-crm' ) ), 404 );
		}

		wp_send_json_success( $e );
	}
}
