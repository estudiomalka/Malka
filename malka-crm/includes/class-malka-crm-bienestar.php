<?php
/**
 * Bienestar: hábitos y medidas del cuerpo.
 *
 * Dos cosas distintas que comparten pantalla:
 *
 * 1. Hábitos. Algo que se quiere hacer seguido. Se marca el día que se
 *    hizo y se ve la racha. No hay castigo por cortarla: cortar una racha
 *    es normal y una pantalla que lo retara serviría para abandonarla.
 *
 * 2. Medidas del cuerpo. Un registro con fecha y su línea en el tiempo.
 *    A propósito NO tiene metas, ni rangos «buenos» y «malos», ni le pone
 *    nombre a ningún número. Es un cuaderno: anota lo que ella mide y le
 *    muestra cómo viene. Qué significa eso lo dice quien corresponde, que
 *    no es un plugin.
 *
 * Antes esta sección decía «fechas y recordatorios, nunca resultados».
 * Cambió porque ella pidió llevar sus medidas acá; lo que no cambió es
 * que el panel no opina sobre ellas.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

class Malka_CRM_Bienestar {

	/** Dónde se guarda todo. */
	const OPCION = 'malka_crm_bienestar';

	/** Cuántos registros de medidas se conservan. */
	const TOPE = 1000;

	public static function init() {
		add_action( 'admin_post_malka_crm_habito_nuevo', array( __CLASS__, 'handle_habito_nuevo' ) );
		add_action( 'admin_post_malka_crm_habito_borrar', array( __CLASS__, 'handle_habito_borrar' ) );
		add_action( 'admin_post_malka_crm_medida_anotar', array( __CLASS__, 'handle_medida_anotar' ) );
		add_action( 'admin_post_malka_crm_medida_borrar', array( __CLASS__, 'handle_medida_borrar' ) );
		add_action( 'admin_post_malka_crm_medida_tipo', array( __CLASS__, 'handle_medida_tipo' ) );
		add_action( 'admin_post_malka_crm_medida_meta', array( __CLASS__, 'handle_medida_meta' ) );
		add_action( 'admin_post_malka_crm_medida_punto', array( __CLASS__, 'handle_medida_punto' ) );
		add_action( 'wp_ajax_malka_crm_habito_marcar', array( __CLASS__, 'ajax_marcar' ) );
	}

	/**
	 * Todo lo guardado.
	 *
	 * @return array habitos, hechos, tipos, medidas.
	 */
	public static function datos() {
		$d = get_option( self::OPCION, array() );

		if ( ! is_array( $d ) ) {
			$d = array();
		}

		return array(
			'habitos' => isset( $d['habitos'] ) && is_array( $d['habitos'] ) ? $d['habitos'] : array(),
			// hechos[ id del hábito ][ Y-m-d ] = true
			'hechos'  => isset( $d['hechos'] ) && is_array( $d['hechos'] ) ? $d['hechos'] : array(),
			'tipos'   => isset( $d['tipos'] ) && is_array( $d['tipos'] ) ? $d['tipos'] : self::tipos_de_fabrica(),
			'medidas' => isset( $d['medidas'] ) && is_array( $d['medidas'] ) ? $d['medidas'] : array(),
		);
	}

	private static function guardar_todo( $d ) {
		update_option( self::OPCION, $d, false );
	}

	/**
	 * Con qué se arranca, para no empezar con la pantalla en blanco.
	 *
	 * @return array[]
	 */
	public static function tipos_de_fabrica() {
		return array(
			array( 'clave' => 'peso', 'nombre' => 'Peso', 'unidad' => 'kg', 'dec' => 1 ),
			array( 'clave' => 'grasa', 'nombre' => 'Grasa', 'unidad' => '%', 'dec' => 1 ),
		);
	}

	/* ──────────────────────────────  Hábitos  ─────────────────────────── */

	/**
	 * Los hábitos, con su racha y si hoy está marcado.
	 *
	 * @return array[]
	 */
	public static function habitos() {
		$d   = self::datos();
		$hoy = current_time( 'Y-m-d' );
		$out = array();

		foreach ( $d['habitos'] as $h ) {
			$id     = (int) $h['id'];
			$hechos = isset( $d['hechos'][ $id ] ) ? $d['hechos'][ $id ] : array();

			$out[] = array(
				'id'     => $id,
				'nombre' => $h['nombre'],
				'hoy'    => ! empty( $hechos[ $hoy ] ),
				'racha'  => self::racha( $hechos, $hoy ),
				'mes'    => self::ultimos_dias( $hechos, 28, $hoy ),
				'total'  => count( $hechos ),
			);
		}

		return $out;
	}

	/**
	 * Cuántos días seguidos viene marcado, contando hasta hoy.
	 *
	 * Si hoy todavía no está marcado, la racha no se corta: el día no ha
	 * terminado. Se cuenta desde ayer. Cortar una racha porque son las
	 * diez de la mañana sería mentir sobre lo que pasó.
	 *
	 * @param array  $hechos Y-m-d => true.
	 * @param string $hoy    Y-m-d.
	 * @return int
	 */
	public static function racha( $hechos, $hoy ) {
		$n    = 0;
		$dia  = $hoy;

		if ( empty( $hechos[ $hoy ] ) ) {
			$dia = gmdate( 'Y-m-d', strtotime( $hoy . ' -1 day' ) );
		}

		while ( ! empty( $hechos[ $dia ] ) ) {
			$n++;
			$dia = gmdate( 'Y-m-d', strtotime( $dia . ' -1 day' ) );
		}

		return $n;
	}

	/**
	 * Los últimos días, para el cuadrito.
	 *
	 * @param array  $hechos Y-m-d => true.
	 * @param int    $dias   Cuántos.
	 * @param string $hoy    Y-m-d.
	 * @return array[] fecha, hecho.
	 */
	public static function ultimos_dias( $hechos, $dias, $hoy ) {
		$out = array();

		for ( $i = $dias - 1; $i >= 0; $i-- ) {
			$f     = gmdate( 'Y-m-d', strtotime( $hoy . ' -' . $i . ' day' ) );
			$out[] = array(
				'fecha' => $f,
				'hecho' => ! empty( $hechos[ $f ] ),
			);
		}

		return $out;
	}

	/**
	 * Anota un hábito nuevo.
	 *
	 * @param string $nombre Cómo se llama.
	 * @return int id, o 0.
	 */
	public static function habito_nuevo( $nombre ) {
		$nombre = trim( sanitize_text_field( (string) $nombre ) );

		if ( '' === $nombre ) {
			return 0;
		}

		$d  = self::datos();
		$id = 1;

		foreach ( $d['habitos'] as $h ) {
			if ( (int) $h['id'] >= $id ) {
				$id = (int) $h['id'] + 1;
			}
		}

		$d['habitos'][] = array(
			'id'     => $id,
			'nombre' => $nombre,
		);

		self::guardar_todo( $d );

		return $id;
	}

	/**
	 * Borra un hábito y lo que llevaba marcado.
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public static function habito_borrar( $id ) {
		$id = (int) $id;
		$d  = self::datos();

		foreach ( $d['habitos'] as $i => $h ) {
			if ( (int) $h['id'] === $id ) {
				unset( $d['habitos'][ $i ] );
				$d['habitos'] = array_values( $d['habitos'] );
				unset( $d['hechos'][ $id ] );
				self::guardar_todo( $d );
				return true;
			}
		}

		return false;
	}

	/**
	 * Marca o desmarca un hábito en un día.
	 *
	 * @param int    $id    Hábito.
	 * @param string $fecha Y-m-d. Vacío: hoy.
	 * @param bool   $si    Marcar o desmarcar.
	 * @return bool Si existe el hábito.
	 */
	public static function marcar( $id, $fecha = '', $si = true ) {
		$id    = (int) $id;
		$d     = self::datos();
		$fecha = Malka_CRM_Helpers::sanitize_date( $fecha );

		if ( '' === $fecha ) {
			$fecha = current_time( 'Y-m-d' );
		}

		// Hacia adelante no: un hábito de mañana no se hizo todavía.
		if ( $fecha > current_time( 'Y-m-d' ) ) {
			return false;
		}

		$existe = false;

		foreach ( $d['habitos'] as $h ) {
			if ( (int) $h['id'] === $id ) {
				$existe = true;
			}
		}

		if ( ! $existe ) {
			return false;
		}

		if ( $si ) {
			$d['hechos'][ $id ][ $fecha ] = true;
		} else {
			unset( $d['hechos'][ $id ][ $fecha ] );
		}

		self::guardar_todo( $d );

		return true;
	}

	/* ──────────────────────────────  Medidas  ─────────────────────────── */

	/**
	 * Qué se está midiendo.
	 *
	 * @return array[]
	 */
	public static function tipos() {
		$d = self::datos();

		return $d['tipos'];
	}

	/**
	 * Pone —o saca— la meta de una medida.
	 *
	 * La pone ella, no el panel. Esa es toda la diferencia: un número que
	 * una elige es una decisión suya, y uno que aparece solo en la
	 * pantalla es alguien opinando sobre su cuerpo sin que se lo pidan.
	 *
	 * Por eso la meta se dibuja como una raya de referencia y se dice la
	 * distancia, y nada más: no hay barra de progreso, ni porcentaje
	 * cumplido, ni felicitaciones, ni avisos de que va quedando lejos.
	 *
	 * @param string $clave Cuál.
	 * @param string $valor El número. Vacío: saca la meta.
	 * @return bool Si existe esa medida.
	 */
	public static function meta( $clave, $valor ) {
		$clave = sanitize_key( $clave );
		$valor = str_replace( ',', '.', trim( (string) $valor ) );
		$d     = self::datos();
		$hubo  = false;

		foreach ( $d['tipos'] as $i => $t ) {
			if ( $t['clave'] !== $clave ) {
				continue;
			}

			$hubo = true;

			if ( '' === $valor || ! is_numeric( $valor ) || (float) $valor <= 0 ) {
				// Sacar la meta tiene que ser tan fácil como ponerla.
				unset( $d['tipos'][ $i ]['meta'] );
			} else {
				$d['tipos'][ $i ]['meta'] = round( (float) $valor, 2 );
			}
		}

		if ( $hubo ) {
			$d['tipos'] = array_values( $d['tipos'] );
			self::guardar_todo( $d );
		}

		return $hubo;
	}

	/**
	 * Agrega algo que medir.
	 *
	 * @param string $nombre Cómo se llama.
	 * @param string $unidad kg, %, cm…
	 * @return string La clave, o '' si no se pudo.
	 */
	public static function tipo_nuevo( $nombre, $unidad = '' ) {
		$nombre = trim( sanitize_text_field( (string) $nombre ) );

		if ( '' === $nombre ) {
			return '';
		}

		$clave = sanitize_key( remove_accents( $nombre ) );

		if ( '' === $clave ) {
			return '';
		}

		$d = self::datos();

		foreach ( $d['tipos'] as $t ) {
			if ( $t['clave'] === $clave ) {
				return $clave;
			}
		}

		$d['tipos'][] = array(
			'clave'  => $clave,
			'nombre' => $nombre,
			'unidad' => trim( sanitize_text_field( (string) $unidad ) ),
			'dec'    => 1,
		);

		self::guardar_todo( $d );

		return $clave;
	}

	/**
	 * Anota una medición.
	 *
	 * Los valores vacíos no se guardan: anotar sólo el peso un día y el
	 * peso y la grasa en otro es lo normal, y un cero por «no medí» haría
	 * que la línea se desplomara.
	 *
	 * @param array  $valores clave => número.
	 * @param string $fecha   Y-m-d. Vacío: hoy.
	 * @return int id, o 0 si no venía ningún valor.
	 */
	public static function medir( $valores, $fecha = '' ) {
		$fecha = Malka_CRM_Helpers::sanitize_date( $fecha );

		if ( '' === $fecha ) {
			$fecha = current_time( 'Y-m-d' );
		}

		$d       = self::datos();
		$limpios = array();

		foreach ( (array) $valores as $k => $v ) {
			$k = sanitize_key( $k );
			$v = str_replace( ',', '.', trim( (string) $v ) );

			if ( '' === $v || ! is_numeric( $v ) ) {
				continue;
			}

			$n = (float) $v;

			// Un número negativo no es una medida del cuerpo.
			if ( $n <= 0 ) {
				continue;
			}

			$limpios[ $k ] = round( $n, 2 );
		}

		if ( ! $limpios ) {
			return 0;
		}

		$id = 1;

		foreach ( $d['medidas'] as $m ) {
			if ( (int) $m['id'] >= $id ) {
				$id = (int) $m['id'] + 1;
			}
		}

		/*
		 * Dos mediciones el mismo día se juntan en una: se mide una vez al
		 * día, y dos puntos en la misma fecha harían una línea que va y
		 * vuelve sin querer decir nada.
		 */
		foreach ( $d['medidas'] as $i => $m ) {
			if ( $m['fecha'] === $fecha ) {
				$d['medidas'][ $i ]['valores'] = array_merge( $m['valores'], $limpios );
				self::guardar_todo( $d );
				return (int) $m['id'];
			}
		}

		$d['medidas'][] = array(
			'id'      => $id,
			'fecha'   => $fecha,
			'valores' => $limpios,
		);

		if ( count( $d['medidas'] ) > self::TOPE ) {
			$d['medidas'] = array_slice( $d['medidas'], count( $d['medidas'] ) - self::TOPE );
		}

		self::guardar_todo( $d );

		return $id;
	}

	/**
	 * Borra una medición.
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public static function medida_borrar( $id ) {
		$id = (int) $id;
		$d  = self::datos();

		foreach ( $d['medidas'] as $i => $m ) {
			if ( (int) $m['id'] === $id ) {
				unset( $d['medidas'][ $i ] );
				$d['medidas'] = array_values( $d['medidas'] );
				self::guardar_todo( $d );
				return true;
			}
		}

		return false;
	}

	/**
	 * Lo medido, de lo más nuevo a lo más viejo.
	 *
	 * @return array[]
	 */
	public static function medidas() {
		$d = self::datos();
		$m = $d['medidas'];

		usort(
			$m,
			function ( $a, $b ) {
				return strcmp( $b['fecha'], $a['fecha'] );
			}
		);

		return $m;
	}

	/**
	 * La serie de una medida, de la más vieja a la más nueva.
	 *
	 * @param string $clave Cuál.
	 * @param int    $tope  Cuántos puntos como máximo.
	 * @return array[] fecha, valor.
	 */
	public static function serie( $clave, $tope = 40 ) {
		$clave = sanitize_key( $clave );
		$out   = array();

		foreach ( array_reverse( self::medidas() ) as $m ) {
			if ( isset( $m['valores'][ $clave ] ) ) {
				$out[] = array(
					'fecha' => $m['fecha'],
					'valor' => (float) $m['valores'][ $clave ],
				);
			}
		}

		if ( count( $out ) > $tope ) {
			$out = array_slice( $out, count( $out ) - $tope );
		}

		return $out;
	}

	/* ─────────────────────────────  Pantallas  ────────────────────────── */

	/**
	 * El resumen: cómo viene hoy, en dos líneas.
	 *
	 * No repite los nombres de las solapas —eso ya está arriba— sino el
	 * estado: cuántos hábitos van hoy y cuál fue la última medición.
	 *
	 * @return string
	 */
	public static function resumen() {
		$habitos = self::habitos();
		$medidas = self::medidas();
		$tipos   = self::tipos();

		ob_start();
		echo '<div class="esc-bien__r">';

		// Hábitos de hoy.
		echo '<div class="esc-bien__c">';
		printf( '<span class="esc-lbl">%s</span>', esc_html__( 'Hábitos de hoy', 'malka-crm' ) );

		if ( ! $habitos ) {
			printf(
				'<p class="esc-bien__v"><a href="%1$s">%2$s</a></p>',
				esc_url( Malka_CRM_Dashboard::view_url( 'habitos' ) ),
				esc_html__( 'Todavía no hay ninguno', 'malka-crm' )
			);
		} else {
			$van = 0;

			foreach ( $habitos as $h ) {
				if ( $h['hoy'] ) {
					$van++;
				}
			}

			printf(
				'<p class="esc-bien__n"><a href="%1$s"><em>%2$d</em> <span>%3$s</span></a></p>',
				esc_url( Malka_CRM_Dashboard::view_url( 'habitos' ) ),
				(int) $van,
				esc_html(
					sprintf(
						/* translators: cuántos hábitos hay en total */
						__( 'de %d', 'malka-crm' ),
						count( $habitos )
					)
				)
			);

			// Los que faltan, por su nombre: el número solo no dice qué hacer.
			$faltan = array();

			foreach ( $habitos as $h ) {
				if ( ! $h['hoy'] ) {
					$faltan[] = $h['nombre'];
				}
			}

			if ( $faltan ) {
				printf(
					'<p class="esc-bien__d">%s</p>',
					esc_html(
						sprintf(
							/* translators: los hábitos que faltan */
							__( 'Falta: %s', 'malka-crm' ),
							implode( ', ', array_slice( $faltan, 0, 4 ) )
						)
					)
				);
			}
		}

		echo '</div>';

		// La última medición.
		echo '<div class="esc-bien__c">';
		printf( '<span class="esc-lbl">%s</span>', esc_html__( 'Última medición', 'malka-crm' ) );

		if ( ! $medidas ) {
			printf(
				'<p class="esc-bien__v"><a href="%1$s">%2$s</a></p>',
				esc_url( Malka_CRM_Dashboard::view_url( 'cuerpo' ) ),
				esc_html__( 'Todavía no hay nada anotado', 'malka-crm' )
			);
		} else {
			$u     = $medidas[0];
			$dice  = array();

			foreach ( $tipos as $t ) {
				if ( ! isset( $u['valores'][ $t['clave'] ] ) ) {
					continue;
				}

				$dice[] = $t['nombre'] . ' ' .
					number_format_i18n( (float) $u['valores'][ $t['clave'] ], isset( $t['dec'] ) ? (int) $t['dec'] : 1 ) .
					( $t['unidad'] ? ' ' . $t['unidad'] : '' );
			}

			printf(
				'<p class="esc-bien__n"><a href="%1$s"><em>%2$s</em></a></p><p class="esc-bien__d">%3$s</p>',
				esc_url( Malka_CRM_Dashboard::view_url( 'cuerpo' ) ),
				esc_html( implode( ' · ', $dice ) ),
				esc_html( date_i18n( 'j \d\e F', strtotime( $u['fecha'] ) ) )
			);
		}

		echo '</div></div>';

		printf(
			'<p class="esc-hint">%s</p>',
			esc_html__( 'Mente, Vínculos y Vos todavía están vacíos: por ahora son el lugar, no el contenido.', 'malka-crm' )
		);

		return ob_get_clean();
	}

	/**
	 * Hábitos: marcar el día y ver la racha.
	 *
	 * @return string
	 */
	public static function render_habitos() {
		$habitos = self::habitos();

		ob_start();
		echo '<section class="esc-panel esc-hab" id="esc-habitos">';

		echo Malka_CRM_Dashboard::head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'bienestar',
			__( 'Hábitos', 'malka-crm' ),
			__( 'Lo que quieres hacer seguido. Se marca el día que lo hiciste.', 'malka-crm' ),
			$habitos ? (string) count( array_filter( $habitos, function ( $h ) { return $h['hoy']; } ) ) . '/' . count( $habitos ) : '',
			$habitos ? __( 'hoy', 'malka-crm' ) : '',
			''
		);

		if ( ! $habitos ) {
			printf(
				'<p class="esc-empty">%s</p>',
				esc_html__( 'Todavía no hay ninguno. Agrega uno abajo: con marcarlo los días que lo hagas alcanza, no hay nada más que llenar.', 'malka-crm' )
			);
		} else {
			echo '<ul class="esc-hab__l">';

			foreach ( $habitos as $h ) {
				echo '<li class="esc-hab__i">';
				printf(
					'<button type="button" class="esc-dia__chk esc-hab__ck%1$s" data-habito="%2$d" aria-pressed="%3$s" aria-label="%4$s" title="%4$s"></button>',
					$h['hoy'] ? ' is-on' : '',
					(int) $h['id'],
					$h['hoy'] ? 'true' : 'false',
					esc_attr(
						$h['hoy']
							/* translators: nombre del hábito */
							? sprintf( __( 'Desmarcar %s de hoy', 'malka-crm' ), $h['nombre'] )
							/* translators: nombre del hábito */
							: sprintf( __( 'Marcar %s como hecho hoy', 'malka-crm' ), $h['nombre'] )
					)
				);

				echo '<span class="esc-hab__t">';
				printf( '<b>%s</b>', esc_html( $h['nombre'] ) );

				/*
				 * La racha se dice, no se celebra ni se reclama. Cortar una
				 * racha es normal; una pantalla que lo retara serviría para
				 * dejar de abrirla.
				 */
				if ( $h['racha'] > 1 ) {
					printf(
						'<i>%s</i>',
						esc_html(
							sprintf(
								/* translators: cantidad de días */
								_n( '%d día seguido', '%d días seguidos', (int) $h['racha'], 'malka-crm' ),
								(int) $h['racha']
							)
						)
					);
				} elseif ( $h['total'] > 0 ) {
					printf(
						'<i>%s</i>',
						esc_html(
							sprintf(
								/* translators: cantidad de veces */
								_n( '%d vez en total', '%d veces en total', (int) $h['total'], 'malka-crm' ),
								(int) $h['total']
							)
						)
					);
				}

				echo '</span>';

				// Las últimas cuatro semanas, de un vistazo.
				echo '<span class="esc-hab__m" aria-hidden="true">';

				foreach ( $h['mes'] as $d ) {
					printf( '<i class="%s"></i>', $d['hecho'] ? 'on' : '' );
				}

				echo '</span>';
				echo self::form_borrar_habito( $h['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '</li>';
			}

			echo '</ul>';
		}

		echo self::form_habito_nuevo(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</section>';

		return ob_get_clean();
	}

	/**
	 * El formulario para agregar un hábito.
	 *
	 * @return string
	 */
	private static function form_habito_nuevo() {
		ob_start();
		printf( '<form class="esc-hab__n" method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'malka_crm_habito_nuevo', '_wpnonce' );
		echo '<input type="hidden" name="action" value="malka_crm_habito_nuevo" />';
		printf(
			'<label><span>%1$s</span><input type="text" name="nombre" placeholder="%2$s" required /></label>',
			esc_html__( 'Agregar un hábito', 'malka-crm' ),
			esc_attr__( 'Caminar, leer, tomar agua…', 'malka-crm' )
		);
		printf( '<button type="submit" class="esc-btn esc-btn--primary">%s</button>', esc_html__( 'Agregar', 'malka-crm' ) );
		echo '</form>';

		return ob_get_clean();
	}

	/**
	 * El botón de sacar un hábito de la lista.
	 *
	 * @param int $id Id.
	 * @return string
	 */
	private static function form_borrar_habito( $id ) {
		return sprintf(
			'<form method="post" action="%1$s" class="esc-hab__x">%2$s' .
			'<input type="hidden" name="action" value="malka_crm_habito_borrar" />' .
			'<input type="hidden" name="id" value="%3$d" />' .
			'<button type="submit" class="esc-mini" aria-label="%4$s" title="%4$s">%5$s</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'malka_crm_habito_borrar', '_wpnonce', true, false ),
			(int) $id,
			esc_attr__( 'Sacar de la lista', 'malka-crm' ),
			esc_html__( 'Sacar', 'malka-crm' )
		);
	}

	/**
	 * Cuerpo: el registro de medidas y cómo vienen.
	 *
	 * A propósito sin metas, sin rangos y sin decir si un número está bien
	 * o mal. Es un cuaderno con su línea en el tiempo: lo que significan
	 * esos números lo dice quien corresponde.
	 *
	 * @return string
	 */
	public static function render_cuerpo() {
		$tipos   = self::tipos();
		$medidas = self::medidas();

		ob_start();
		echo '<section class="esc-panel esc-med" id="esc-medidas">';

		echo Malka_CRM_Dashboard::head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'cuerpo',
			__( 'Tus medidas', 'malka-crm' ),
			__( 'Lo que mides, cuándo lo mediste y cómo viene', 'malka-crm' ),
			$medidas ? (string) count( $medidas ) : '',
			$medidas ? __( 'registros', 'malka-crm' ) : '',
			''
		);

		/*
		 * El orden de la pantalla es el orden en que se usa:
		 *
		 * 1. Anotar, que es lo de todos los días y va primero.
		 * 2. Lo anotado: los gráficos y la tabla. Es a lo que se entra.
		 * 3. Los ajustes —qué mides, tus metas— detrás de UNA sola
		 *    puerta, porque se tocan una vez cada tanto.
		 *
		 * Antes los tres formularios de configuración estaban abiertos
		 * arriba y los datos quedaban al final: se entraba a ver cómo
		 * viene el peso y lo primero eran tres formularios.
		 */
		echo self::form_medir( $tipos ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( ! $medidas ) {
			printf(
				'<div class="esc-med__vacio"><p>%1$s</p><p class="esc-hint">%2$s</p></div>',
				esc_html__( 'Todavía no hay nada anotado. Anota arriba y acá aparece cómo viene.', 'malka-crm' ),
				esc_html__( 'Vienen peso y grasa. En Ajustes agregas las que quieras seguir.', 'malka-crm' )
			);
		} else {
			$graficos = '';

			foreach ( $tipos as $t ) {
				$serie = self::serie( $t['clave'] );

				if ( count( $serie ) < 2 ) {
					continue;
				}

				$graficos .= self::linea( $t, $serie );
			}

			if ( $graficos ) {
				echo '<div class="esc-med__gs">' . $graficos . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} else {
				printf(
					'<p class="esc-hint esc-med__p">%s</p>',
					esc_html__( 'Con una sola medición no hay línea todavía.', 'malka-crm' )
				);
			}

			echo self::tabla_medidas( $tipos, array_slice( $medidas, 0, 40 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo self::ajustes( $tipos ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</section>';

		return ob_get_clean();
	}

	/**
	 * Todo lo que se configura, detrás de una sola puerta.
	 *
	 * Qué mides, tus metas y el dibujo de dónde se mide cada cosa. Son
	 * cosas que se tocan una vez cada tanto, así que no pueden estar
	 * compitiendo con lo de todos los días, que es anotar y mirar.
	 *
	 * @param array[] $tipos Qué se mide.
	 * @return string
	 */
	private static function ajustes( $tipos ) {
		ob_start();
		printf(
			'<details class="esc-med__cfg"><summary>%s</summary><div class="esc-med__cfgc">',
			esc_html__( 'Ajustes de medidas', 'malka-crm' )
		);

		/* ── Qué mides ── */
		printf( '<h3 class="esc-lbl">%s</h3>', esc_html__( 'Qué mides', 'malka-crm' ) );
		echo self::diagrama( $tipos ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		/* ── Tus metas ── */
		printf( '<h3 class="esc-lbl">%s</h3>', esc_html__( 'Tus metas', 'malka-crm' ) );
		printf(
			'<p class="esc-hint">%s</p>',
			esc_html__( 'Salen como una raya en el gráfico. Para sacar una, deja el campo vacío.', 'malka-crm' )
		);
		printf( '<form class="esc-med__n" method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'malka_crm_medida_meta', '_wpnonce' );
		echo '<input type="hidden" name="action" value="malka_crm_medida_meta" />';

		foreach ( $tipos as $t ) {
			printf(
				'<label><span>%1$s</span><input type="text" inputmode="decimal" name="m[%2$s]" value="%3$s" placeholder="%4$s" /></label>',
				esc_html( $t['nombre'] . ( $t['unidad'] ? ' (' . $t['unidad'] . ')' : '' ) ),
				esc_attr( $t['clave'] ),
				esc_attr( isset( $t['meta'] ) ? $t['meta'] : '' ),
				esc_attr__( 'sin meta', 'malka-crm' )
			);
		}

		printf( '<button type="submit" class="esc-btn">%s</button>', esc_html__( 'Guardar metas', 'malka-crm' ) );
		echo '</form>';

		/* ── Algo que no está en el dibujo ── */
		printf( '<h3 class="esc-lbl">%s</h3>', esc_html__( 'Medir otra cosa', 'malka-crm' ) );
		printf( '<form class="esc-med__n" method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'malka_crm_medida_tipo', '_wpnonce' );
		echo '<input type="hidden" name="action" value="malka_crm_medida_tipo" />';
		printf(
			'<label><span>%1$s</span><input type="text" name="nombre" placeholder="%2$s" required /></label>',
			esc_html__( 'Qué', 'malka-crm' ),
			esc_attr__( 'Presión, horas de sueño…', 'malka-crm' )
		);
		printf(
			'<label><span>%1$s</span><input type="text" name="unidad" placeholder="%2$s" /></label>',
			esc_html__( 'En qué', 'malka-crm' ),
			esc_attr__( 'cm, kg, %…', 'malka-crm' )
		);
		printf( '<button type="submit" class="esc-btn">%s</button>', esc_html__( 'Agregar', 'malka-crm' ) );
		echo '</form>';

		/*
		 * Dónde quedan estos datos. Va acá abajo, una vez, en vez de
		 * arriba de todo: lo importante es decirlo, no repetirlo cada
		 * vez que entra a anotar su peso.
		 */
		printf(
			'<p class="esc-hint esc-med__priv">%s</p>',
			esc_html__( 'Se guarda en tu propio sitio y no sale a ninguna parte. Las metas las pones tú; el panel no opina sobre tus números.', 'malka-crm' )
		);

		echo '</div></details>';

		return ob_get_clean();
	}

	/**
	 * Los puntos del dibujo, en orden.
	 *
	 * El número del dibujo es la posición en esta lista. Agregar uno al
	 * final no mueve los que ya están.
	 *
	 * @return array[] clave => nombre, unidad.
	 */
	public static function puntos() {
		return array(
			'cuello'      => array( __( 'Cuello', 'malka-crm' ), 'cm' ),
			'pecho'       => array( __( 'Pecho', 'malka-crm' ), 'cm' ),
			'cintura'     => array( __( 'Cintura', 'malka-crm' ), 'cm' ),
			'cadera'      => array( __( 'Cadera', 'malka-crm' ), 'cm' ),
			'muslo'       => array( __( 'Muslo', 'malka-crm' ), 'cm' ),
			'pantorrilla' => array( __( 'Pantorrilla', 'malka-crm' ), 'cm' ),
			'brazo'       => array( __( 'Brazo', 'malka-crm' ), 'cm' ),
			'antebrazo'   => array( __( 'Antebrazo', 'malka-crm' ), 'cm' ),
			'muneca'      => array( __( 'Muñeca', 'malka-crm' ), 'cm' ),
			'altura'      => array( __( 'Altura', 'malka-crm' ), 'cm' ),
		);
	}

	/**
	 * Agrega la medida de un punto del dibujo.
	 *
	 * Sólo los del dibujo. El valor llega de un formulario, así que lo
	 * que no esté en la lista no entra: si no, cualquiera podría crear
	 * medidas con el nombre que quisiera mandando otro valor.
	 *
	 * @param string $punto La clave.
	 * @return string La clave creada, o '' si no era del dibujo.
	 */
	public static function agregar_punto( $punto ) {
		$puntos = self::puntos();
		$punto  = sanitize_key( (string) $punto );

		if ( ! isset( $puntos[ $punto ] ) ) {
			return '';
		}

		/*
		 * El mismo botón pone y saca. Si ya la mide, la saca de la lista
		 * —pero NO borra lo anotado: dejar de seguir la cintura no es lo
		 * mismo que tirar seis meses de mediciones. Si la vuelve a
		 * agregar, aparecen de nuevo.
		 */
		foreach ( self::tipos() as $t ) {
			if ( $t['clave'] === $punto ) {
				self::quitar_tipo( $punto );
				return '';
			}
		}

		return self::tipo_nuevo( $puntos[ $punto ][0], $puntos[ $punto ][1] );
	}

	/**
	 * Deja de seguir una medida, sin borrar lo anotado.
	 *
	 * @param string $clave Cuál.
	 * @return bool Si estaba.
	 */
	public static function quitar_tipo( $clave ) {
		$clave = sanitize_key( $clave );
		$d     = self::datos();

		foreach ( $d['tipos'] as $i => $t ) {
			if ( $t['clave'] !== $clave ) {
				continue;
			}

			unset( $d['tipos'][ $i ] );
			$d['tipos'] = array_values( $d['tipos'] );
			self::guardar_todo( $d );

			return true;
		}

		return false;
	}

	/**
	 * El dibujo con dónde se mide cada cosa.
	 *
	 * No es un adorno: cada número del dibujo es un botón que agrega esa
	 * medida a tu lista. Así no hay que acordarse de cómo se llama cada
	 * punto ni escribirlo bien, que es justo lo que hace que una lista de
	 * medidas se llene a medias.
	 *
	 * Los que ya mides salen marcados y sin botón.
	 *
	 * @return string
	 */
	public static function diagrama( $tipos = null ) {
		$puntos = self::puntos();
		$tengo  = wp_list_pluck( null === $tipos ? self::tipos() : $tipos, 'clave' );

		ob_start();
		echo '<div class="esc-fig">';
		echo <<<'SVG'
			<svg aria-hidden="true" focusable="false" viewBox="0 0 260 500" xmlns="http://www.w3.org/2000/svg">
			<g class="esc-fig__b">
			<path d="M 107.0 245.0 C 101.2 249.5, 106.8 254.5, 107.0 272.0 C 107.2 289.5, 108.2 330.0, 108.0 350.0 C 107.8 370.0, 105.8 375.0, 106.0 392.0 C 106.2 409.0, 108.8 441.2, 109.0 452.0 C 109.2 462.8, 107.5 454.3, 107.0 457.0 C 106.5 459.7, 103.2 466.2, 106.0 468.0 C 108.8 469.8, 121.3 469.8, 124.0 468.0 C 126.7 466.2, 122.5 459.7, 122.0 457.0 C 121.5 454.3, 120.4 462.8, 121.2 452.0 C 122.1 441.2, 125.4 409.0, 127.0 392.0 C 128.6 375.0, 129.1 370.0, 130.8 350.0 C 132.4 330.0, 134.9 289.5, 136.8 272.0 C 138.6 254.5, 147.0 249.5, 142.0 245.0 C 137.0 240.5, 112.8 240.5, 107.0 245.0 Z"/>
			<path d="M 153.0 245.0 C 158.8 249.5, 153.2 254.5, 153.0 272.0 C 152.8 289.5, 151.8 330.0, 152.0 350.0 C 152.2 370.0, 154.2 375.0, 154.0 392.0 C 153.8 409.0, 151.2 441.2, 151.0 452.0 C 150.8 462.8, 152.5 454.3, 153.0 457.0 C 153.5 459.7, 156.8 466.2, 154.0 468.0 C 151.2 469.8, 138.7 469.8, 136.0 468.0 C 133.3 466.2, 137.5 459.7, 138.0 457.0 C 138.5 454.3, 139.6 462.8, 138.8 452.0 C 137.9 441.2, 134.6 409.0, 133.0 392.0 C 131.4 375.0, 130.9 370.0, 129.2 350.0 C 127.6 330.0, 125.1 289.5, 123.2 272.0 C 121.4 254.5, 113.0 249.5, 118.0 245.0 C 123.0 240.5, 147.2 240.5, 153.0 245.0 Z"/>
			<path d="M 75.0 106.0 C 68.8 112.7, 66.7 132.3, 64.0 146.0 C 61.3 159.7, 60.5 176.0, 59.0 188.0 C 57.5 200.0, 55.8 207.5, 55.0 218.0 C 54.2 228.5, 54.7 240.5, 54.0 251.0 C 53.3 261.5, 50.8 272.0, 51.0 281.0 C 51.2 290.0, 52.7 301.0, 55.0 305.0 C 57.3 309.0, 63.3 309.0, 65.0 305.0 C 66.7 301.0, 64.8 290.0, 65.0 281.0 C 65.2 272.0, 65.0 261.5, 66.0 251.0 C 67.0 240.5, 69.2 228.5, 71.0 218.0 C 72.8 207.5, 74.5 200.0, 77.0 188.0 C 79.5 176.0, 82.0 159.7, 86.0 146.0 C 90.0 132.3, 102.8 112.7, 101.0 106.0 C 99.2 99.3, 81.2 99.3, 75.0 106.0 Z"/>
			<path d="M 185.0 106.0 C 191.2 112.7, 193.3 132.3, 196.0 146.0 C 198.7 159.7, 199.5 176.0, 201.0 188.0 C 202.5 200.0, 204.2 207.5, 205.0 218.0 C 205.8 228.5, 205.3 240.5, 206.0 251.0 C 206.7 261.5, 209.2 272.0, 209.0 281.0 C 208.8 290.0, 207.3 301.0, 205.0 305.0 C 202.7 309.0, 196.7 309.0, 195.0 305.0 C 193.3 301.0, 195.2 290.0, 195.0 281.0 C 194.8 272.0, 195.0 261.5, 194.0 251.0 C 193.0 240.5, 190.8 228.5, 189.0 218.0 C 187.2 207.5, 185.5 200.0, 183.0 188.0 C 180.5 176.0, 178.0 159.7, 174.0 146.0 C 170.0 132.3, 157.2 112.7, 159.0 106.0 C 160.8 99.3, 178.8 99.3, 185.0 106.0 Z"/>
			<path d="M 119.0 80.0 C 114.8 82.0, 121.8 87.0, 116.0 92.0 C 110.2 97.0, 88.3 102.0, 84.0 110.0 C 79.7 118.0, 88.2 131.0, 90.0 140.0 C 91.8 149.0, 93.2 156.0, 95.0 164.0 C 96.8 172.0, 101.0 181.0, 101.0 188.0 C 101.0 195.0, 97.3 200.0, 95.0 206.0 C 92.7 212.0, 87.8 217.5, 87.0 224.0 C 86.2 230.5, 76.2 241.5, 90.0 245.0 C 103.8 248.5, 156.2 248.5, 170.0 245.0 C 183.8 241.5, 173.8 230.5, 173.0 224.0 C 172.2 217.5, 167.3 212.0, 165.0 206.0 C 162.7 200.0, 159.0 195.0, 159.0 188.0 C 159.0 181.0, 163.2 172.0, 165.0 164.0 C 166.8 156.0, 168.2 149.0, 170.0 140.0 C 171.8 131.0, 180.3 118.0, 176.0 110.0 C 171.7 102.0, 149.8 97.0, 144.0 92.0 C 138.2 87.0, 145.2 82.0, 141.0 80.0 C 136.8 78.0, 123.2 78.0, 119.0 80.0 Z"/>
			<ellipse cx="130" cy="48" rx="24" ry="31"/>
			</g>
			<line class="esc-fig__m" x1="150" y1="88" x2="232" y2="88"/>
			<circle class="esc-fig__p" cx="242" cy="88" r="9"/><text class="esc-fig__n" x="242" y="91.5">1</text>
			<line class="esc-fig__m" x1="150" y1="140" x2="232" y2="140"/>
			<circle class="esc-fig__p" cx="242" cy="140" r="9"/><text class="esc-fig__n" x="242" y="143.5">2</text>
			<line class="esc-fig__m" x1="150" y1="188" x2="232" y2="188"/>
			<circle class="esc-fig__p" cx="242" cy="188" r="9"/><text class="esc-fig__n" x="242" y="191.5">3</text>
			<line class="esc-fig__m" x1="150" y1="224" x2="232" y2="224"/>
			<circle class="esc-fig__p" cx="242" cy="224" r="9"/><text class="esc-fig__n" x="242" y="227.5">4</text>
			<line class="esc-fig__m" x1="112" y1="290" x2="52" y2="290"/>
			<circle class="esc-fig__p" cx="42" cy="290" r="9"/><text class="esc-fig__n" x="42" y="293.5">5</text>
			<line class="esc-fig__m" x1="112" y1="400" x2="52" y2="400"/>
			<circle class="esc-fig__p" cx="42" cy="400" r="9"/><text class="esc-fig__n" x="42" y="403.5">6</text>
			<line class="esc-fig__m" x1="200" y1="150" x2="232" y2="150"/>
			<circle class="esc-fig__p" cx="242" cy="150" r="9"/><text class="esc-fig__n" x="242" y="153.5">7</text>
			<line class="esc-fig__m" x1="200" y1="205" x2="232" y2="205"/>
			<circle class="esc-fig__p" cx="242" cy="205" r="9"/><text class="esc-fig__n" x="242" y="208.5">8</text>
			<line class="esc-fig__m" x1="200" y1="255" x2="232" y2="255"/>
			<circle class="esc-fig__p" cx="242" cy="255" r="9"/><text class="esc-fig__n" x="242" y="258.5">9</text>
			<path class="esc-fig__m" d="M18 14 V482 M12 14 H24 M12 482 H24"/>
			<circle class="esc-fig__p" cx="18" cy="250" r="9"/><text class="esc-fig__n" x="18" y="253.5">10</text></svg>
SVG;

		printf( '<form method="post" action="%s" class="esc-fig__l">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'malka_crm_medida_punto', '_wpnonce' );
		echo '<input type="hidden" name="action" value="malka_crm_medida_punto" />';
		echo '<ol>';

		$n = 0;

		foreach ( $puntos as $clave => $d ) {
			$n++;
			$ya = in_array( $clave, $tengo, true );

			printf(
				'<li%1$s><i>%2$d</i><span>%3$s</span>' .
				'<button type="submit" class="esc-mini" name="punto" value="%4$s" aria-label="%6$s">%5$s</button></li>',
				$ya ? ' class="is-ya"' : '',
				$n,
				esc_html( $d[0] ),
				esc_attr( $clave ),
				$ya ? esc_html__( 'Quitar', 'malka-crm' ) : esc_html__( 'Agregar', 'malka-crm' ),
				esc_attr(
					$ya
						/* translators: la medida */
						? sprintf( __( 'Dejar de medir %s', 'malka-crm' ), $d[0] )
						/* translators: la medida */
						: sprintf( __( 'Empezar a medir %s', 'malka-crm' ), $d[0] )
				)
			);
		}

		echo '</ol></form></div>';

		return ob_get_clean();
	}

	/**
	 * El formulario para anotar una medición.
	 *
	 * @param array[] $tipos Qué se mide.
	 * @return string
	 */
	private static function form_medir( $tipos ) {
		ob_start();
		printf( '<form class="esc-med__n esc-med__anotar" method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'malka_crm_medida_anotar', '_wpnonce' );
		echo '<input type="hidden" name="action" value="malka_crm_medida_anotar" />';

		printf(
			'<label><span>%1$s</span><input type="date" name="fecha" value="%2$s" max="%2$s" /></label>',
			esc_html__( 'Cuándo', 'malka-crm' ),
			esc_attr( current_time( 'Y-m-d' ) )
		);

		foreach ( $tipos as $t ) {
			printf(
				'<label><span>%1$s</span><input type="text" inputmode="decimal" name="v[%2$s]" placeholder="%3$s" /></label>',
				esc_html( $t['nombre'] . ( $t['unidad'] ? ' (' . $t['unidad'] . ')' : '' ) ),
				esc_attr( $t['clave'] ),
				esc_attr__( '—', 'malka-crm' )
			);
		}

		printf( '<button type="submit" class="esc-btn esc-btn--primary">%s</button>', esc_html__( 'Anotar', 'malka-crm' ) );
		echo '</form>';

		return ob_get_clean();
	}

	/**
	 * La línea de una medida en el tiempo.
	 *
	 * Sin eje con cero forzado: lo que interesa es el movimiento, y un peso
	 * dibujado desde cero es una línea plana que no dice nada. Los dos
	 * extremos van escritos para que la escala no engañe.
	 *
	 * @param array   $t     El tipo.
	 * @param array[] $serie fecha, valor.
	 * @return string
	 */
	private static function linea( $t, $serie ) {
		$vals = wp_list_pluck( $serie, 'valor' );
		$min  = min( $vals );
		$max  = max( $vals );
		$alto = 90;
		$ancho = 600;

		// Todo igual: una raya al medio, no una división por cero.
		$rango = ( $max - $min ) > 0 ? ( $max - $min ) : 1;
		$pasos = count( $serie ) > 1 ? count( $serie ) - 1 : 1;

		$puntos = array();

		foreach ( $serie as $i => $s ) {
			$x = ( $i / $pasos ) * $ancho;
			$y = $alto - ( ( $s['valor'] - $min ) / $rango ) * ( $alto - 12 ) - 6;

			$puntos[] = round( $x, 1 ) . ',' . round( $y, 1 );
		}

		$ult   = end( $serie );
		$prim  = reset( $serie );
		$dec   = isset( $t['dec'] ) ? (int) $t['dec'] : 1;
		$marca = function ( $v ) use ( $t, $dec ) {
			return number_format_i18n( (float) $v, $dec ) . ( $t['unidad'] ? ' ' . $t['unidad'] : '' );
		};

		/*
		 * La meta que ella puso, si puso una. Entra en la escala del
		 * dibujo: si queda fuera del rango de lo medido, el gráfico se
		 * estira para que la raya se vea; si no, la raya quedaría en el
		 * borde o fuera y no serviría de referencia.
		 */
		$meta = isset( $t['meta'] ) ? (float) $t['meta'] : null;

		if ( null !== $meta ) {
			$min = min( $min, $meta );
			$max = max( $max, $meta );
			// Recalcular con la meta adentro, antes de ubicar los puntos.
			$rango  = ( $max - $min ) > 0 ? ( $max - $min ) : 1;
			$puntos = array();

			foreach ( $serie as $i => $s ) {
				$x = ( $i / $pasos ) * $ancho;
				$y = $alto - ( ( $s['valor'] - $min ) / $rango ) * ( $alto - 12 ) - 6;

				$puntos[] = round( $x, 1 ) . ',' . round( $y, 1 );
			}
		}

		ob_start();
		echo '<div class="esc-med__g">';
		printf(
			'<div class="esc-med__gh"><b>%1$s</b><span>%2$s</span></div>',
			esc_html( $t['nombre'] ),
			esc_html(
				sprintf(
					/* translators: 1: valor más reciente, 2: fecha */
					__( 'Último: %1$s · %2$s', 'malka-crm' ),
					$marca( $ult['valor'] ),
					date_i18n( 'j M Y', strtotime( $ult['fecha'] ) )
				)
			)
		);

		// La raya de la meta, punteada, por debajo de la línea de datos.
		$raya = '';

		if ( null !== $meta ) {
			$ym = $alto - ( ( $meta - $min ) / $rango ) * ( $alto - 12 ) - 6;

			$raya = sprintf(
				'<line x1="0" y1="%1$s" x2="%2$d" y2="%1$s" stroke="currentColor" stroke-width="1" ' .
				'stroke-dasharray="4 4" opacity=".45" vector-effect="non-scaling-stroke" />',
				esc_attr( round( $ym, 1 ) ),
				(int) $ancho
			);
		}

		printf(
			'<svg viewBox="0 0 %1$d %2$d" preserveAspectRatio="none" role="img" aria-label="%3$s">%5$s' .
			'<polyline points="%4$s" fill="none" stroke="currentColor" stroke-width="2" ' .
			'stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" /></svg>',
			(int) $ancho,
			(int) $alto,
			esc_attr(
				sprintf(
					/* translators: 1: qué, 2: cuántas mediciones, 3: desde, 4: hasta, 5: la meta */
					__( '%1$s: %2$d mediciones, de %3$s a %4$s%5$s', 'malka-crm' ),
					$t['nombre'],
					count( $serie ),
					$marca( $prim['valor'] ),
					$marca( $ult['valor'] ),
					null === $meta
						? ''
						/* translators: la meta */
						: '. ' . sprintf( __( 'Tu meta: %s', 'malka-crm' ), $marca( $meta ) )
				)
			),
			esc_attr( implode( ' ', $puntos ) ),
			$raya // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		printf(
			'<div class="esc-med__ge"><span>%1$s</span><span>%2$s</span></div>',
			esc_html( $marca( $min ) ),
			esc_html( $marca( $max ) )
		);

		/*
		 * La distancia a la meta, dicha y nada más. Sin barra de progreso
		 * ni porcentaje cumplido: la meta es de ella y el panel sólo
		 * resta. Tampoco dice «te falta poco» ni «vas lejos» — eso ya
		 * sería opinar.
		 */
		if ( null !== $meta ) {
			$falta = round( abs( $ult['valor'] - $meta ), 2 );

			printf(
				'<p class="esc-med__meta">%s</p>',
				esc_html(
					$falta < 0.005
						/* translators: la meta */
						? sprintf( __( 'Tu meta: %s. Estás ahí.', 'malka-crm' ), $marca( $meta ) )
						: sprintf(
							/* translators: 1: la meta, 2: la diferencia */
							__( 'Tu meta: %1$s · %2$s de diferencia', 'malka-crm' ),
							$marca( $meta ),
							$marca( $falta )
						)
				)
			);
		}

		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * La tabla con lo anotado.
	 *
	 * @param array[] $tipos   Las columnas.
	 * @param array[] $medidas Las filas.
	 * @return string
	 */
	private static function tabla_medidas( $tipos, $medidas ) {
		ob_start();
		/*
		 * Nada de «esc-tabla»: en este panel esa clase es una grilla CSS
		 * para listas, no una tabla, y ponérsela a un <table> de verdad lo
		 * convertía en grid y le deshacía las filas.
		 */
		echo '<div class="esc-med__tw"><table class="esc-med__t"><thead><tr>';
		printf( '<th>%s</th>', esc_html__( 'Cuándo', 'malka-crm' ) );

		foreach ( $tipos as $t ) {
			printf( '<th>%s</th>', esc_html( $t['nombre'] ) );
		}

		printf( '<th><span class="screen-reader-text">%s</span></th>', esc_html__( 'Borrar', 'malka-crm' ) );
		echo '</tr></thead><tbody>';

		foreach ( $medidas as $m ) {
			echo '<tr>';
			printf( '<td>%s</td>', esc_html( date_i18n( 'j M Y', strtotime( $m['fecha'] ) ) ) );

			foreach ( $tipos as $t ) {
				$v = isset( $m['valores'][ $t['clave'] ] ) ? $m['valores'][ $t['clave'] ] : null;

				printf(
					'<td>%s</td>',
					null === $v
						? '—'
						: esc_html( number_format_i18n( (float) $v, isset( $t['dec'] ) ? (int) $t['dec'] : 1 ) . ( $t['unidad'] ? ' ' . $t['unidad'] : '' ) )
				);
			}

			printf(
				'<td><form method="post" action="%1$s">%2$s' .
				'<input type="hidden" name="action" value="malka_crm_medida_borrar" />' .
				'<input type="hidden" name="id" value="%3$d" />' .
				'<button type="submit" class="esc-mini" aria-label="%4$s" title="%4$s">%5$s</button></form></td>',
				esc_url( admin_url( 'admin-post.php' ) ),
				wp_nonce_field( 'malka_crm_medida_borrar', '_wpnonce', true, false ),
				(int) $m['id'],
				esc_attr__( 'Borrar esta medición', 'malka-crm' ),
				esc_html__( 'Borrar', 'malka-crm' )
			);

			echo '</tr>';
		}

		echo '</tbody></table></div>';

		return ob_get_clean();
	}

	/* ──────────────────────────  Los formularios  ─────────────────────── */

	/** Vuelve a la pantalla de donde se venía. */
	private static function volver( $vista ) {
		wp_safe_redirect( Malka_CRM_Dashboard::view_url( $vista ) );
		exit;
	}

	public static function handle_habito_nuevo() {
		check_admin_referer( 'malka_crm_habito_nuevo' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No puedes hacer eso.', 'malka-crm' ) );
		}

		self::habito_nuevo( isset( $_POST['nombre'] ) ? wp_unslash( $_POST['nombre'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		self::volver( 'habitos' );
	}

	public static function handle_habito_borrar() {
		check_admin_referer( 'malka_crm_habito_borrar' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No puedes hacer eso.', 'malka-crm' ) );
		}

		self::habito_borrar( isset( $_POST['id'] ) ? (int) $_POST['id'] : 0 );
		self::volver( 'habitos' );
	}

	public static function handle_medida_anotar() {
		check_admin_referer( 'malka_crm_medida_anotar' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No puedes hacer eso.', 'malka-crm' ) );
		}

		self::medir(
			isset( $_POST['v'] ) ? (array) wp_unslash( $_POST['v'] ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			isset( $_POST['fecha'] ) ? wp_unslash( $_POST['fecha'] ) : '' // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		);
		self::volver( 'cuerpo' );
	}

	public static function handle_medida_borrar() {
		check_admin_referer( 'malka_crm_medida_borrar' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No puedes hacer eso.', 'malka-crm' ) );
		}

		self::medida_borrar( isset( $_POST['id'] ) ? (int) $_POST['id'] : 0 );
		self::volver( 'cuerpo' );
	}

	public static function handle_medida_tipo() {
		check_admin_referer( 'malka_crm_medida_tipo' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No puedes hacer eso.', 'malka-crm' ) );
		}

		self::tipo_nuevo(
			isset( $_POST['nombre'] ) ? wp_unslash( $_POST['nombre'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			isset( $_POST['unidad'] ) ? wp_unslash( $_POST['unidad'] ) : '' // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		);
		self::volver( 'cuerpo' );
	}

	public static function handle_medida_meta() {
		check_admin_referer( 'malka_crm_medida_meta' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No puedes hacer eso.', 'malka-crm' ) );
		}

		$m = isset( $_POST['m'] ) ? (array) wp_unslash( $_POST['m'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		foreach ( $m as $clave => $valor ) {
			self::meta( $clave, sanitize_text_field( (string) $valor ) );
		}

		self::volver( 'cuerpo' );
	}

	public static function handle_medida_punto() {
		check_admin_referer( 'malka_crm_medida_punto' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No puedes hacer eso.', 'malka-crm' ) );
		}

		self::agregar_punto( isset( $_POST['punto'] ) ? sanitize_key( wp_unslash( $_POST['punto'] ) ) : '' );
		self::volver( 'cuerpo' );
	}

	/** Marcar o desmarcar un hábito, sin recargar. */
	public static function ajax_marcar() {
		check_ajax_referer( 'malka_crm_inicio', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'No puedes hacer eso.', 'malka-crm' ) ), 403 );
		}

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$si = ! empty( $_POST['si'] );

		if ( ! self::marcar( $id, '', $si ) ) {
			wp_send_json_error( array( 'message' => __( 'Ese hábito ya no está.', 'malka-crm' ) ), 404 );
		}

		// Se devuelve lo que quedó guardado, no lo que se pidió.
		$d      = self::datos();
		$hechos = isset( $d['hechos'][ $id ] ) ? $d['hechos'][ $id ] : array();
		$hoy    = current_time( 'Y-m-d' );

		wp_send_json_success(
			array(
				'hoy'   => ! empty( $hechos[ $hoy ] ),
				'racha' => self::racha( $hechos, $hoy ),
			)
		);
	}
}
