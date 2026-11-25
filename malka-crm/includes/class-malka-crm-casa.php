<?php
/**
 * Casa: las tareas del hogar, quién las hizo y cuándo.
 *
 * Esto no es una lista de pendientes. Es un registro de lo que ya se
 * hizo: se anota después, no antes. La pregunta que contesta no es «qué
 * falta» sino «quién está haciendo qué», que es la que se discute en una
 * casa donde viven dos.
 *
 * Por eso el gráfico no es decoración: el reparto del mes es el dato. Un
 * número solo —«hiciste 14»— no dice nada sin el al lado.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

class Malka_CRM_Casa {

	/** Dónde se guarda todo. */
	const OPCION = 'malka_crm_casa';

	/** Cuántos registros se conservan. */
	const TOPE = 2000;

	public static function init() {
		add_action( 'admin_post_malka_crm_casa_anotar', array( __CLASS__, 'handle_anotar' ) );
		add_action( 'admin_post_malka_crm_casa_borrar', array( __CLASS__, 'handle_borrar' ) );
	}

	/**
	 * Todo lo guardado: la gente, las tareas y los registros.
	 *
	 * @return array
	 */
	public static function datos() {
		$d = get_option( self::OPCION, array() );

		if ( ! is_array( $d ) ) {
			$d = array();
		}

		return array(
			'gente'    => isset( $d['gente'] ) && is_array( $d['gente'] ) ? array_values( $d['gente'] ) : array(),
			'tareas'   => isset( $d['tareas'] ) && is_array( $d['tareas'] ) ? array_values( $d['tareas'] ) : array(),
			'registro' => isset( $d['registro'] ) && is_array( $d['registro'] ) ? $d['registro'] : array(),
		);
	}

	private static function guardar_todo( $d ) {
		update_option( self::OPCION, $d, false );
	}

	/**
	 * Quiénes viven en la casa.
	 *
	 * Salen de lo que se haya anotado: nadie configura una lista de
	 * personas antes de empezar. La primera vez se escribe el nombre y
	 * desde la segunda ya está para elegir.
	 *
	 * @return string[]
	 */
	public static function gente() {
		$d = self::datos();

		return $d['gente'];
	}

	/**
	 * Las tareas que ya se usaron alguna vez.
	 *
	 * @return string[]
	 */
	public static function tareas() {
		$d = self::datos();

		return $d['tareas'];
	}

	/**
	 * Anota que alguien hizo algo.
	 *
	 * @param string $tarea Qué se hizo.
	 * @param string $quien Quién lo hizo.
	 * @param string $fecha Y-m-d. Vacío: hoy.
	 * @param string $nota  Opcional.
	 * @return int El id, o 0 si faltaba algo.
	 */
	public static function anotar( $tarea, $quien, $fecha = '', $nota = '' ) {
		$tarea = trim( sanitize_text_field( (string) $tarea ) );
		$quien = trim( sanitize_text_field( (string) $quien ) );

		if ( '' === $tarea || '' === $quien ) {
			return 0;
		}

		$fecha = Malka_CRM_Helpers::sanitize_date( $fecha );

		if ( '' === $fecha ) {
			$fecha = current_time( 'Y-m-d' );
		}

		$d  = self::datos();
		$id = 1;

		foreach ( $d['registro'] as $r ) {
			if ( isset( $r['id'] ) && (int) $r['id'] >= $id ) {
				$id = (int) $r['id'] + 1;
			}
		}

		$d['registro'][] = array(
			'id'    => $id,
			'tarea' => $tarea,
			'quien' => $quien,
			'fecha' => $fecha,
			'nota'  => trim( sanitize_text_field( (string) $nota ) ),
		);

		// Las listas para elegir crecen solas con lo que se usa.
		if ( ! in_array( $quien, $d['gente'], true ) ) {
			$d['gente'][] = $quien;
		}

		if ( ! in_array( $tarea, $d['tareas'], true ) ) {
			$d['tareas'][] = $tarea;
		}

		// Un registro de años no se mira nunca y la opción crecería sola.
		if ( count( $d['registro'] ) > self::TOPE ) {
			$d['registro'] = array_slice( $d['registro'], count( $d['registro'] ) - self::TOPE );
		}

		self::guardar_todo( $d );

		return $id;
	}

	/**
	 * Borra un registro.
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public static function borrar( $id ) {
		$id = (int) $id;
		$d  = self::datos();

		foreach ( $d['registro'] as $i => $r ) {
			if ( isset( $r['id'] ) && (int) $r['id'] === $id ) {
				unset( $d['registro'][ $i ] );
				$d['registro'] = array_values( $d['registro'] );
				self::guardar_todo( $d );
				return true;
			}
		}

		return false;
	}

	/**
	 * Lo anotado, de lo más nuevo a lo más viejo.
	 *
	 * @param string $desde Y-m-d, o vacío.
	 * @param string $hasta Y-m-d, o vacío.
	 * @return array[]
	 */
	public static function lista( $desde = '', $hasta = '' ) {
		$d   = self::datos();
		$out = array();

		foreach ( $d['registro'] as $r ) {
			if ( $desde && $r['fecha'] < $desde ) {
				continue;
			}

			if ( $hasta && $r['fecha'] > $hasta ) {
				continue;
			}

			$out[] = $r;
		}

		usort(
			$out,
			function ( $a, $b ) {
				if ( $a['fecha'] === $b['fecha'] ) {
					return (int) $b['id'] - (int) $a['id'];
				}

				return strcmp( $b['fecha'], $a['fecha'] );
			}
		);

		return $out;
	}

	/**
	 * Cuántas hizo cada quien en un período, y qué parte del total.
	 *
	 * @param string $desde Y-m-d.
	 * @param string $hasta Y-m-d.
	 * @return array[] quien, n, pct; de mayor a menor.
	 */
	public static function reparto( $desde = '', $hasta = '' ) {
		$cuenta = array();

		foreach ( self::lista( $desde, $hasta ) as $r ) {
			$q = $r['quien'];

			if ( ! isset( $cuenta[ $q ] ) ) {
				$cuenta[ $q ] = 0;
			}

			$cuenta[ $q ]++;
		}

		$total = array_sum( $cuenta );

		if ( ! $total ) {
			return array();
		}

		$out = array();

		foreach ( $cuenta as $q => $n ) {
			$out[] = array(
				'quien' => $q,
				'n'     => $n,
				'pct'   => (int) round( ( $n / $total ) * 100 ),
			);
		}

		usort(
			$out,
			function ( $a, $b ) {
				if ( $a['n'] === $b['n'] ) {
					// Empatados, por nombre: el orden no puede bailar entre cargas.
					return strcmp( $a['quien'], $b['quien'] );
				}

				return $b['n'] - $a['n'];
			}
		);

		return $out;
	}

	/**
	 * Cuántas se hicieron cada semana, para ver el ritmo.
	 *
	 * @param int $semanas Cuántas semanas hacia atrás.
	 * @return array[] desde, rotulo, por quien, total.
	 */
	public static function por_semana( $semanas = 8 ) {
		$semanas = max( 1, min( 26, (int) $semanas ) );
		$hoy     = current_time( 'Y-m-d' );
		// El lunes de esta semana.
		$lunes   = gmdate( 'Y-m-d', strtotime( 'monday this week', strtotime( $hoy ) ) );
		$out     = array();

		for ( $i = $semanas - 1; $i >= 0; $i-- ) {
			$desde = gmdate( 'Y-m-d', strtotime( $lunes . ' -' . $i . ' week' ) );
			$hasta = gmdate( 'Y-m-d', strtotime( $desde . ' +6 day' ) );
			$quien = array();

			foreach ( self::lista( $desde, $hasta ) as $r ) {
				if ( ! isset( $quien[ $r['quien'] ] ) ) {
					$quien[ $r['quien'] ] = 0;
				}

				$quien[ $r['quien'] ]++;
			}

			$out[] = array(
				'desde'  => $desde,
				'hasta'  => $hasta,
				'rotulo' => date_i18n( 'j M', strtotime( $desde ) ),
				'quien'  => $quien,
				'total'  => array_sum( $quien ),
			);
		}

		return $out;
	}

	/* ─────────────────────────────  Guardar  ──────────────────────────── */

	public static function handle_anotar() {
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'malka-crm' ) );
		}

		check_admin_referer( 'malka_crm_casa_anotar' );

		$tarea = isset( $_POST['tarea'] ) ? sanitize_text_field( wp_unslash( $_POST['tarea'] ) ) : '';
		$quien = isset( $_POST['quien'] ) ? sanitize_text_field( wp_unslash( $_POST['quien'] ) ) : '';
		$fecha = isset( $_POST['fecha'] ) ? sanitize_text_field( wp_unslash( $_POST['fecha'] ) ) : '';
		$nota  = isset( $_POST['nota'] ) ? sanitize_text_field( wp_unslash( $_POST['nota'] ) ) : '';

		// Los selectores traen «otra»: ahí vale lo que se escribió al lado.
		if ( '__otra' === $tarea && isset( $_POST['tarea_nueva'] ) ) {
			$tarea = sanitize_text_field( wp_unslash( $_POST['tarea_nueva'] ) );
		}

		if ( '__otro' === $quien && isset( $_POST['quien_nuevo'] ) ) {
			$quien = sanitize_text_field( wp_unslash( $_POST['quien_nuevo'] ) );
		}

		$id = self::anotar( $tarea, $quien, $fecha, $nota );

		self::volver( $id ? 'ok' : 'falta' );
	}

	public static function handle_borrar() {
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'malka-crm' ) );
		}

		check_admin_referer( 'malka_crm_casa_borrar' );

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		self::volver( self::borrar( $id ) ? 'borrado' : 'falta' );
	}

	private static function volver( $que ) {
		$url = add_query_arg( 'casa', $que, Malka_CRM_Dashboard::view_url( 'casa' ) );

		wp_safe_redirect( $url );
		exit;
	}

	/* ─────────────────────────────  Pantalla  ─────────────────────────── */

	/**
	 * La pantalla de Casa.
	 *
	 * @return string
	 */
	public static function render() {
		$mes   = substr( current_time( 'Y-m-d' ), 0, 7 );
		$desde = $mes . '-01';
		$hasta = $mes . '-' . gmdate( 't', strtotime( $desde ) );

		$reparto = self::reparto( $desde, $hasta );
		$todo    = self::lista();

		ob_start();
		echo '<section class="esc-panel esc-casa" id="esc-casa">';

		echo Malka_CRM_Dashboard::head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'casa',
			__( 'La casa', 'malka-crm' ),
			__( 'Lo que se hizo, quién lo hizo y cuándo', 'malka-crm' ),
			$todo ? (string) count( self::lista( $desde, $hasta ) ) : '',
			$todo ? __( 'este mes', 'malka-crm' ) : '',
			''
		);

		echo self::form_anotar(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( ! $todo ) {
			printf(
				'<p class="esc-empty">%s</p>',
				esc_html__( 'Todavía no hay nada anotado. Esto no es una lista de pendientes: se anota lo que ya se hizo, y con eso aparece el reparto.', 'malka-crm' )
			);
			echo '</section>';
			return ob_get_clean();
		}

		echo self::grafico( $reparto, date_i18n( 'F', strtotime( $desde ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::ritmo(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::tabla( array_slice( $todo, 0, 60 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '</section>';

		return ob_get_clean();
	}

	/**
	 * El reparto del mes: quién hizo cuánto.
	 *
	 * Barras y no una torta: con dos o tres personas lo que se compara es
	 * el largo, y el largo se compara bien; los ángulos de una torta no.
	 * El porcentaje va escrito, porque el color y el largo no son una
	 * respuesta para quien no los distingue.
	 *
	 * @param array[] $reparto Lo que devuelve reparto().
	 * @param string  $mes     Cómo se llama el mes.
	 * @return string
	 */
	private static function grafico( $reparto, $mes ) {
		if ( ! $reparto ) {
			return '';
		}

		$total = 0;

		foreach ( $reparto as $r ) {
			$total += $r['n'];
		}

		ob_start();
		echo '<div class="esc-casa__g">';
		printf(
			'<div class="esc-ph"><div><h3>%1$s</h3><p>%2$s</p></div><b class="esc-casa__tot">%3$d</b></div>',
			esc_html__( 'Cómo va el reparto', 'malka-crm' ),
			esc_html(
				sprintf(
					/* translators: mes */
					__( 'Lo anotado en %s', 'malka-crm' ),
					$mes
				)
			),
			(int) $total
		);

		foreach ( $reparto as $r ) {
			printf(
				'<div class="esc-casa__b"><span class="esc-casa__q">%1$s</span>' .
				'<span class="esc-casa__t"><i style="width:%2$d%%"></i></span>' .
				'<span class="esc-casa__n">%3$s</span></div>',
				esc_html( $r['quien'] ),
				(int) $r['pct'],
				esc_html(
					sprintf(
						/* translators: 1: cuántas, 2: porcentaje */
						__( '%1$d · %2$d%%', 'malka-crm' ),
						$r['n'],
						$r['pct']
					)
				)
			);
		}

		/*
		 * Con una sola persona anotando no hay reparto que mostrar, y una
		 * barra al 100% diría algo que no se preguntó.
		 */
		if ( 1 === count( $reparto ) ) {
			printf(
				'<p class="esc-hint">%s</p>',
				esc_html__( 'Por ahora hay una sola persona anotada. Cuando aparezca otra, acá se ve cómo se reparte.', 'malka-crm' )
			);
		}

		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * Las últimas semanas, para ver si el ritmo cambia.
	 *
	 * @return string
	 */
	private static function ritmo() {
		$semanas = self::por_semana( 8 );
		$techo   = 1;

		foreach ( $semanas as $s ) {
			$techo = max( $techo, $s['total'] );
		}

		ob_start();
		echo '<div class="esc-casa__r">';
		printf( '<h3>%s</h3>', esc_html__( 'Las últimas ocho semanas', 'malka-crm' ) );
		echo '<div class="esc-casa__rg">';

		foreach ( $semanas as $s ) {
			$alto = (int) round( ( $s['total'] / $techo ) * 100 );

			printf(
				'<div class="esc-casa__rc" title="%3$s"><span class="esc-casa__rb"><i style="height:%1$d%%"></i></span>' .
				'<span class="esc-casa__rl">%2$s</span></div>',
				$s['total'] ? max( 4, $alto ) : 0,
				esc_html( $s['rotulo'] ),
				esc_attr(
					sprintf(
						/* translators: 1: cuántas, 2: semana del */
						_n( '%1$d tarea · semana del %2$s', '%1$d tareas · semana del %2$s', $s['total'], 'malka-crm' ),
						$s['total'],
						date_i18n( 'j M', strtotime( $s['desde'] ) )
					)
				)
			);
		}

		echo '</div></div>';

		return ob_get_clean();
	}

	/**
	 * Lo anotado, fila por fila.
	 *
	 * @param array[] $filas Registros.
	 * @return string
	 */
	private static function tabla( $filas ) {
		ob_start();
		echo '<div class="esc-casa__l">';

		foreach ( $filas as $r ) {
			printf(
				'<div class="esc-casa__f"><span class="esc-casa__fq"><b>%1$s</b>%2$s</span>' .
				'<span class="esc-casa__fw">%3$s</span><span class="esc-casa__fd">%4$s</span>%5$s</div>',
				esc_html( $r['tarea'] ),
				$r['nota'] ? '<em>' . esc_html( $r['nota'] ) . '</em>' : '',
				esc_html( $r['quien'] ),
				esc_html( date_i18n( 'j M', strtotime( $r['fecha'] ) ) ),
				self::boton_borrar( (int) $r['id'] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}

		echo '</div>';

		return ob_get_clean();
	}

	private static function boton_borrar( $id ) {
		return sprintf(
			'<form method="post" action="%1$s" class="esc-casa__x">%2$s' .
			'<input type="hidden" name="action" value="malka_crm_casa_borrar" />' .
			'<input type="hidden" name="id" value="%3$d" />' .
			'<button type="submit" aria-label="%4$s" title="%4$s">×</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'malka_crm_casa_borrar', '_wpnonce', true, false ),
			$id,
			esc_attr__( 'Borrar del registro', 'malka-crm' )
		);
	}

	/**
	 * Anotar algo que se hizo.
	 *
	 * Los selectores se llenan con lo que ya se usó, y siempre dejan
	 * escribir algo nuevo: la primera vez no hay nada en la lista y pedir
	 * que se configure antes de empezar es la forma más segura de que no
	 * se empiece nunca.
	 *
	 * @return string
	 */
	private static function form_anotar() {
		$gente  = self::gente();
		$tareas = self::tareas();

		ob_start();
		printf( '<form class="esc-casa__n2" method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'malka_crm_casa_anotar', '_wpnonce' );
		echo '<input type="hidden" name="action" value="malka_crm_casa_anotar" />';

		echo '<label><span>' . esc_html__( 'Qué se hizo', 'malka-crm' ) . '</span>';

		if ( $tareas ) {
			echo '<select name="tarea" data-casa-tarea>';

			foreach ( $tareas as $t ) {
				printf( '<option value="%1$s">%1$s</option>', esc_attr( $t ) );
			}

			printf( '<option value="__otra">%s</option>', esc_html__( 'Otra…', 'malka-crm' ) );
			echo '</select>';
			printf(
				'<input type="text" name="tarea_nueva" placeholder="%s" data-casa-tarea-nueva hidden />',
				esc_attr__( 'Qué se hizo', 'malka-crm' )
			);
		} else {
			printf( '<input type="text" name="tarea" placeholder="%s" required />', esc_attr__( 'Lavar la loza', 'malka-crm' ) );
		}

		echo '</label>';

		echo '<label><span>' . esc_html__( 'Quién', 'malka-crm' ) . '</span>';

		if ( $gente ) {
			echo '<select name="quien" data-casa-quien>';

			foreach ( $gente as $g ) {
				printf( '<option value="%1$s">%1$s</option>', esc_attr( $g ) );
			}

			printf( '<option value="__otro">%s</option>', esc_html__( 'Otra persona…', 'malka-crm' ) );
			echo '</select>';
			printf(
				'<input type="text" name="quien_nuevo" placeholder="%s" data-casa-quien-nuevo hidden />',
				esc_attr__( 'Quién', 'malka-crm' )
			);
		} else {
			printf( '<input type="text" name="quien" placeholder="%s" required />', esc_attr__( 'Tu nombre', 'malka-crm' ) );
		}

		echo '</label>';

		printf(
			'<label><span>%1$s</span><input type="date" name="fecha" value="%2$s" max="%2$s" /></label>',
			esc_html__( 'Cuándo', 'malka-crm' ),
			esc_attr( current_time( 'Y-m-d' ) )
		);

		printf(
			'<label class="esc-casa__n2--nota"><span>%1$s</span><input type="text" name="nota" placeholder="%2$s" /></label>',
			esc_html__( 'Nota', 'malka-crm' ),
			esc_attr__( 'Opcional', 'malka-crm' )
		);

		printf( '<button type="submit" class="esc-btn esc-btn--primary">%s</button>', esc_html__( 'Anotar', 'malka-crm' ) );
		echo '</form>';

		return ob_get_clean();
	}

	/**
	 * El aviso de vuelta, en palabras.
	 *
	 * @param string $que Qué pasó.
	 * @return string
	 */
	public static function mensaje( $que ) {
		$m = array(
			'ok'      => __( 'Anotado.', 'malka-crm' ),
			'borrado' => __( 'Borrado del registro.', 'malka-crm' ),
			'falta'   => __( 'Falta qué se hizo o quién lo hizo.', 'malka-crm' ),
		);

		return isset( $m[ $que ] ) ? $m[ $que ] : '';
	}
}
