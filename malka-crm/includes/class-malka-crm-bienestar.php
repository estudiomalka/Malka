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
}
