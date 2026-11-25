<?php
/**
 * Con qué datos se le emite una factura de compra a un proveedor extranjero.
 *
 * El SII publica la nómina de los prestadores sin domicilio en Chile que se
 * inscribieron en el régimen simplificado de IVA (Google, Adobe, Meta…), con
 * el RUT que les asignó. Esta clase baja esa nómina, la guarda una semana y
 * busca en ella el proveedor de cada factura. Si no aparece, el SII indica
 * usar el RUT genérico 55.555.555-5.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

/**
 * La nómina de prestadores extranjeros del SII.
 */
class Malka_CRM_SII {

	/** La página del SII con la nómina. */
	const URL = 'https://www.sii.cl/vat/dwn_esp.html';

	/** Dónde queda guardada: [ 'ts' => int, 'filas' => [ [nombre, rut, tipo] ] ]. */
	const OPT = 'malka_crm_sii_nomina';

	/** El último error al bajarla, para decirlo en vez de fallar callado. */
	const OPT_ERROR = 'malka_crm_sii_nomina_error';

	/** El RUT que indica el SII para un prestador que no está inscrito. */
	const RUT_GENERICO = '55.555.555-5';

	/** Palabras que comparten muchas empresas y no identifican a ninguna. */
	const GENERICAS = array( 'international', 'ireland', 'irl', 'services', 'service', 'technologies', 'technology', 'payments', 'platforms', 'online', 'operations', 'software', 'systems', 'digital', 'global', 'holdings', 'group', 'europe', 'emea', 'latam', 'america', 'americas', 'usa', 'us', 'uk', 'media', 'commerce', 'solutions', 'network', 'networks', 'labs', 'studio', 'studios', 'apps', 'app', 'web', 'cloud', 'marketing' );

	/** Desde qué parecido se da por encontrado (0 a 1). */
	const PARECIDO = 0.5;

	/** Tipos de cambio ya consultados: [ 'USD' => [ 'Y-m-d' => [valor, fecha] ] ]. */
	const OPT_TC = 'malka_crm_tc_dia';

	public function __construct() {
		add_action( 'wp_ajax_malka_crm_sii_buscar', array( __CLASS__, 'ajax_buscar' ) );
		add_action( 'wp_ajax_malka_crm_tc', array( __CLASS__, 'ajax_tc' ) );
	}

	/**
	 * El tipo de cambio del Banco Central para un día.
	 *
	 * El dólar observado (o el euro) que publica el Banco Central, leído de
	 * mindicador.cl, que lo toma de ahí. Un sábado, domingo o feriado no
	 * tiene valor propio: se usa el último día hábil anterior. Lo consultado
	 * se guarda, porque el valor de un día pasado ya no cambia.
	 *
	 * @param string $moneda USD|EUR.
	 * @param string $fecha  Y-m-d.
	 * @return array|null {valor, fecha} fecha es el día del que salió el valor.
	 */
	public static function tipo_cambio( $moneda, $fecha ) {
		$moneda = strtoupper( (string) $moneda );
		$serie  = array(
			'USD' => 'dolar',
			'EUR' => 'euro',
		);

		if ( ! isset( $serie[ $moneda ] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $fecha ) ) {
			return null;
		}

		$guardado = get_option( self::OPT_TC, array() );
		$guardado = is_array( $guardado ) ? $guardado : array();
		if ( isset( $guardado[ $moneda ][ $fecha ] ) ) {
			return $guardado[ $moneda ][ $fecha ];
		}

		// No hay valor para mañana: un día futuro usa el de hoy.
		$dia = min( strtotime( $fecha ), strtotime( current_time( 'Y-m-d' ) ) );

		for ( $i = 0; $i < 10; $i++ ) {
			$d = gmdate( 'd-m-Y', $dia - $i * DAY_IN_SECONDS );
			$r = wp_remote_get(
				'https://mindicador.cl/api/' . $serie[ $moneda ] . '/' . $d,
				array(
					'timeout' => 6,
					'headers' => array( 'Accept' => 'application/json' ),
				)
			);

			if ( is_wp_error( $r ) ) {
				return null;
			}

			$j = json_decode( (string) wp_remote_retrieve_body( $r ), true );
			if ( ! empty( $j['serie'][0]['valor'] ) ) {
				$out = array(
					'valor' => (float) $j['serie'][0]['valor'],
					'fecha' => gmdate( 'Y-m-d', $dia - $i * DAY_IN_SECONDS ),
				);

				// Sólo se guarda lo que ya no puede cambiar (antes de hoy).
				if ( $fecha < current_time( 'Y-m-d' ) ) {
					$guardado[ $moneda ][ $fecha ] = $out;
					update_option( self::OPT_TC, $guardado, false );
				}

				return $out;
			}
		}

		return null;
	}

	/**
	 * El tipo de cambio que pide el formulario al cambiar la fecha.
	 */
	public static function ajax_tc() {
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'malka_crm_sii', 'nonce' );

		$tc = self::tipo_cambio(
			isset( $_POST['moneda'] ) ? sanitize_key( wp_unslash( $_POST['moneda'] ) ) : '',
			isset( $_POST['fecha'] ) ? sanitize_text_field( wp_unslash( $_POST['fecha'] ) ) : ''
		);

		if ( ! $tc ) {
			wp_send_json_error( array( 'msg' => __( 'No se pudo consultar el Banco Central.', 'malka-crm' ) ) );
		}

		wp_send_json_success( $tc );
	}

	/**
	 * La nómina, bajada como mucho una vez por semana.
	 *
	 * @param bool $forzar Bajarla aunque esté vigente.
	 * @return array[]|null Null si nunca se pudo bajar.
	 */
	public static function nomina( $forzar = false ) {
		$guardada = get_option( self::OPT, array() );
		$hay      = is_array( $guardada ) && ! empty( $guardada['filas'] );
		$vigente  = $hay && ! empty( $guardada['ts'] ) && ( time() - (int) $guardada['ts'] ) < WEEK_IN_SECONDS;

		if ( $vigente && ! $forzar ) {
			return $guardada['filas'];
		}

		$r = wp_remote_get(
			self::URL,
			array(
				'timeout'    => 12,
				'user-agent' => 'Mozilla/5.0 (compatible; MalkaCRM)',
			)
		);

		$filas = array();
		if ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) {
			$filas = self::leer( (string) wp_remote_retrieve_body( $r ) );
		}

		/*
		 * Una lista que llega casi vacía es una página que cambió de forma,
		 * no un SII sin inscritos: se sigue usando la de antes.
		 */
		if ( count( $filas ) < 50 ) {
			update_option(
				self::OPT_ERROR,
				array(
					'cuando' => current_time( 'mysql' ),
					'msg'    => is_wp_error( $r ) ? $r->get_error_message() : sprintf( 'HTTP %s, %d filas', wp_remote_retrieve_response_code( $r ), count( $filas ) ),
				),
				false
			);

			return $hay ? $guardada['filas'] : null;
		}

		update_option(
			self::OPT,
			array(
				'ts'    => time(),
				'filas' => $filas,
			),
			false
		);
		delete_option( self::OPT_ERROR );

		return $filas;
	}

	/**
	 * Saca las filas de la página del SII.
	 *
	 * Lee tanto una tabla HTML como el texto en columnas: la página ha
	 * cambiado de forma antes y no conviene depender de una sola.
	 *
	 * @param string $html Página.
	 * @return array[] [nombre, rut, tipo]
	 */
	public static function leer( $html ) {
		$t = preg_replace( '#<\s*/\s*t[dh]\s*>#i', ' | ', $html );
		$t = preg_replace( '#<\s*(/\s*tr|br\s*/?|/\s*p|/\s*div|/\s*li)\s*>#i', "\n", $t );
		$t = html_entity_decode( wp_strip_all_tags( $t, false ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $t, 'UTF-8' ) ) {
			$t = mb_convert_encoding( $t, 'UTF-8', 'ISO-8859-1' );
		}

		$out = array();
		$ya  = array();

		foreach ( preg_split( '/\R/u', $t ) as $linea ) {
			$linea = trim( $linea, " \t|" );
			if ( '' === $linea ) {
				continue;
			}

			$tipo = '';
			$id   = '';
			$nom  = '';

			if ( false !== strpos( $linea, '|' ) ) {
				$celdas = array_values( array_filter( array_map( 'trim', explode( '|', $linea ) ), 'strlen' ) );
				foreach ( $celdas as $i => $c ) {
					if ( '' === $tipo && preg_match( '/^(bienes y servicios|servicios|bienes)$/i', $c ) ) {
						$tipo = $c;
						continue;
					}
					if ( '' === $id && preg_match( '/^\d{1,2}\.?\d{3}\.?\d{3}-?[\dkK]$/', $c ) ) {
						$id = $c;
						continue;
					}
					if ( '' !== $tipo && '' === $nom ) {
						$nom = $c;
					}
				}
			} elseif ( preg_match( '/^(bienes y servicios|servicios|bienes)\s+(?:(\d{1,2}\.?\d{3}\.?\d{3}-?[\dkK])\s+)?(.+)$/iu', $linea, $m ) ) {
				$tipo = $m[1];
				$id   = $m[2];
				$nom  = $m[3];
			}

			if ( '' === $tipo || '' === $nom || '' === $id ) {
				// Sin RUT no sirve para emitir: los de «Bienes» vienen sin él.
				continue;
			}

			$rut = self::rut_bonito( $id );
			if ( isset( $ya[ $rut . $nom ] ) ) {
				continue;
			}
			$ya[ $rut . $nom ] = true;

			$out[] = array(
				'nombre' => preg_replace( '/\s+/u', ' ', $nom ),
				'rut'    => $rut,
				'tipo'   => $tipo,
			);
		}

		return $out;
	}

	/**
	 * 592925605 → 59.292.560-5.
	 *
	 * @param string $rut RUT en cualquier forma.
	 * @return string
	 */
	public static function rut_bonito( $rut ) {
		$l = strtoupper( preg_replace( '/[^0-9kK]/', '', (string) $rut ) );
		if ( strlen( $l ) < 2 ) {
			return (string) $rut;
		}

		$cuerpo = substr( $l, 0, -1 );
		$dv     = substr( $l, -1 );

		return number_format( (int) $cuerpo, 0, ',', '.' ) . '-' . $dv;
	}

	/**
	 * Las palabras que identifican a una empresa, sin «Inc», «Ltd» ni «LLC».
	 *
	 * @param string $nombre Nombre.
	 * @return string[]
	 */
	public static function palabras( $nombre ) {
		$n = strtolower( remove_accents( (string) $nombre ) );
		$n = preg_replace( '/\b([a-z])\.(?=[a-z]\.)/', '$1', $n );
		$n = preg_replace( '/[^a-z0-9]+/', ' ', $n );

		$fuera = array( 'inc', 'llc', 'l', 'c', 'ltd', 'ltda', 'limited', 'co', 'company', 'corp', 'corporation', 'sa', 's', 'a', 'srl', 'sarl', 'bv', 'b', 'v', 'gmbh', 'ag', 'plc', 'pte', 'pty', 'unlimited', 'the', 'of', 'de', 'y', 'and', 'spa', 'sl', 'ab', 'oy', 'as', 'nv', 'kk', 'sas', 'se', 'pbc', 'lp', 'llp', 'pllc' );

		return array_values( array_unique( array_diff( array_filter( explode( ' ', $n ), 'strlen' ), $fuera ) ) );
	}

	/**
	 * Busca un proveedor en la nómina.
	 *
	 * @param string $nombre Como viene en su factura.
	 * @return array {ok: bool se pudo consultar, mejor: fila|null, parecido: float, otros: fila[]}
	 */
	public static function buscar( $nombre ) {
		$filas = self::nomina();
		$out   = array(
			'ok'       => null !== $filas,
			'mejor'    => null,
			'parecido' => 0.0,
			'otros'    => array(),
		);

		$a = self::palabras( $nombre );
		if ( ! $filas || ! $a ) {
			return $out;
		}

		$puntos = array();
		foreach ( $filas as $i => $f ) {
			$b = self::palabras( $f['nombre'] );
			if ( ! $b ) {
				continue;
			}
			$comparten = array_intersect( $a, $b );
			$comun     = count( $comparten );
			// Compartir sólo «International» o «Ireland» no es parecerse.
			if ( ! $comun || ! array_diff( $comparten, self::GENERICAS ) ) {
				continue;
			}
			// Parecido de Jaccard, con un empujón si parten igual.
			$p = $comun / count( array_unique( array_merge( $a, $b ) ) );
			if ( $a[0] === $b[0] ) {
				$p = min( 1, $p + 0.15 );
			}
			$puntos[ $i ] = $p;
		}

		if ( ! $puntos ) {
			return $out;
		}

		arsort( $puntos );
		$orden = array_keys( $puntos );
		$top   = $orden[0];

		if ( $puntos[ $top ] >= self::PARECIDO ) {
			$out['mejor']    = $filas[ $top ];
			$out['parecido'] = round( $puntos[ $top ], 2 );
			$orden           = array_slice( $orden, 1 );
		}

		foreach ( array_slice( $orden, 0, 4 ) as $i ) {
			if ( $puntos[ $i ] >= 0.25 ) {
				$out['otros'][] = $filas[ $i ];
			}
		}

		return $out;
	}

	/**
	 * Con qué datos emitirle, en una sola respuesta.
	 *
	 * @param string $nombre Proveedor.
	 * @return array|null {nombre, rut, origen: sii|generico}; null si no se
	 *                    pudo consultar el SII o si hay parecidas y conviene
	 *                    que ella elija (Microsoft Ireland ≠ Microsoft Corp).
	 */
	public static function datos_para( $nombre ) {
		$r = self::buscar( $nombre );

		if ( ! $r['ok'] || ( ! $r['mejor'] && $r['otros'] ) ) {
			return null;
		}

		if ( $r['mejor'] ) {
			return array(
				'nombre' => $r['mejor']['nombre'],
				'rut'    => $r['mejor']['rut'],
				'origen' => 'sii',
			);
		}

		return array(
			'nombre' => strtoupper( trim( (string) $nombre ) ),
			'rut'    => self::RUT_GENERICO,
			'origen' => 'generico',
		);
	}

	/**
	 * La búsqueda que hace el formulario mientras se escribe el proveedor.
	 */
	public static function ajax_buscar() {
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'malka_crm_sii', 'nonce' );

		$nombre = isset( $_POST['nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['nombre'] ) ) : '';

		// Primero la ficha del proveedor, si ya está en Proveedores.
		if ( class_exists( 'Malka_CRM_Providers' ) ) {
			$pid = Malka_CRM_Providers::buscar( $nombre );
			if ( $pid ) {
				$f = Malka_CRM_Providers::get( $pid );
				if ( '' !== $f['sii_nombre'] || '' !== $f['sii_rut'] ) {
					wp_send_json_success(
						array(
							'ok'    => true,
							'ficha' => $f,
						)
					);
				}
			}
		}

		$r = self::buscar( $nombre );

		wp_send_json_success(
			array(
				'ok'       => $r['ok'],
				'mejor'    => $r['mejor'],
				'parecido' => $r['parecido'],
				'otros'    => $r['otros'],
				'generico' => self::RUT_GENERICO,
				'fuente'   => self::URL,
			)
		);
	}
}
