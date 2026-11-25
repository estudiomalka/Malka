<?php
/**
 * La evidencia de la firma electrónica simple.
 *
 * En Chile, la Ley 19.799 reconoce la firma electrónica simple (cualquier
 * proceso electrónico que permita identificar formalmente al autor) y le da
 * el mismo valor que la firma manuscrita en contratos privados como una
 * cotización. La ley no fija qué hay que guardar; lo que decide si sirve es
 * qué se puede probar si alguien la desconoce. Por eso acá se guarda:
 *
 *   - Quién: nombre, RUT (validado) y el correo al que se mandó la cotización.
 *   - Cuándo: fecha y hora local y en UTC, desde el servidor.
 *   - Desde dónde: la IP y el navegador.
 *   - Qué aceptó: el texto exacto de la casilla y el enlace a los términos.
 *   - Qué firmó: una copia de la cotización tal como estaba y su huella
 *     SHA-256. Si después se edita, la huella ya no coincide y se nota.
 *   - Copia al firmante: le llega un correo con todo esto, lo que deja
 *     registro fuera del sitio.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

class Malka_CRM_Firma {

	const META_COPIA = '_malka_pm_quote_sign_snapshot';

	/**
	 * La IP del cliente.
	 *
	 * Detrás de Cloudflare o de un proxy, REMOTE_ADDR es la del proxy. Se
	 * toma la cabecera del proxy si trae una IP pública válida; si no, la
	 * directa. Las dos se guardan, porque la cabecera la puede escribir
	 * cualquiera y la directa no.
	 *
	 * @return array {ip, remota}
	 */
	public static function ip() {
		$remota = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip     = $remota;

		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' ) as $h ) {
			if ( empty( $_SERVER[ $h ] ) ) {
				continue;
			}

			foreach ( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) ) ) as $cand ) {
				$cand = trim( $cand );
				if ( filter_var( $cand, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					$ip = $cand;
					break 2;
				}
			}
		}

		return array(
			'ip'     => $ip,
			'remota' => $remota,
		);
	}

	/**
	 * El navegador y el sistema, en palabras.
	 *
	 * @param string $ua User agent.
	 * @return string
	 */
	public static function navegador( $ua ) {
		$ua = (string) $ua;
		$n  = '';
		$so = '';

		$navs = array(
			'Edg/'     => 'Edge',
			'OPR/'     => 'Opera',
			'SamsungBrowser' => 'Samsung Internet',
			'CriOS'    => 'Chrome',
			'FxiOS'    => 'Firefox',
			'Firefox/' => 'Firefox',
			'Chrome/'  => 'Chrome',
			'Safari/'  => 'Safari',
		);
		foreach ( $navs as $k => $v ) {
			if ( false !== strpos( $ua, $k ) ) {
				$n = $v;
				break;
			}
		}

		$sos = array(
			'iPhone'    => 'iPhone',
			'iPad'      => 'iPad',
			'Android'   => 'Android',
			'Mac OS X'  => 'Mac',
			'Windows'   => 'Windows',
			'Linux'     => 'Linux',
		);
		foreach ( $sos as $k => $v ) {
			if ( false !== strpos( $ua, $k ) ) {
				$so = $v;
				break;
			}
		}

		$txt = trim( $n . ( $so ? ' en ' . $so : '' ) );

		return '' !== $txt ? $txt : __( 'Desconocido', 'malka-crm' );
	}

	/**
	 * Lo que el cliente está firmando, en datos.
	 *
	 * Sólo lo que forma parte del acuerdo: ítems, montos, cuotas, notas y
	 * términos. El estado de pago no entra, porque cambia sin que cambie lo
	 * firmado.
	 *
	 * @param array $d Datos de la cotización.
	 * @return array
	 */
	public static function copia( $d ) {
		$s     = class_exists( 'Malka_CRM_Settings' ) ? Malka_CRM_Settings::all() : array();
		$pagos = array();

		foreach ( (array) $d['pagos'] as $p ) {
			$pagos[] = array(
				'concepto' => isset( $p['label'] ) ? (string) $p['label'] : '',
				'monto'    => isset( $p['amount'] ) ? (float) $p['amount'] : 0,
				'rol'      => isset( $p['rol'] ) ? (string) $p['rol'] : '',
				'disparo'  => isset( $p['disparo'] ) ? (string) $p['disparo'] : '',
			);
		}

		return array(
			'numero'   => (string) $d['numero'],
			'titulo'   => (string) $d['titulo'],
			'emitida'  => (string) $d['emitida'],
			'vence'    => (string) $d['vence'],
			'cliente'  => $d['cliente'],
			'emisor'   => $d['empresa'],
			'items'    => $d['items'],
			'neto'     => (float) $d['neto'],
			'iva_pct'  => (float) $d['iva_pct'],
			'iva'      => (float) $d['iva'],
			'total'    => (float) $d['total'],
			'notas'    => (string) $d['notas'],
			'pagos'    => $pagos,
			'terminos' => isset( $s['terminos_url'] ) ? (string) $s['terminos_url'] : '',
		);
	}

	/**
	 * La huella SHA-256 de una copia.
	 *
	 * @param array $copia Copia.
	 * @return string
	 */
	public static function huella( $copia ) {
		return hash( 'sha256', (string) wp_json_encode( $copia, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * La huella, corta, para leerla en un correo o en la página.
	 *
	 * @param string $h Huella.
	 * @return string
	 */
	public static function corta( $h ) {
		return $h ? strtoupper( substr( $h, 0, 8 ) . '…' . substr( $h, -8 ) ) : '';
	}

	/**
	 * ¿La cotización sigue siendo la que se firmó?
	 *
	 * @param int $qid Cotización.
	 * @return bool|null null si no hay huella guardada (firmas de antes).
	 */
	public static function intacta( $qid ) {
		$firma = get_post_meta( $qid, '_malka_pm_quote_sign', true );

		if ( ! is_array( $firma ) || empty( $firma['huella'] ) || ! class_exists( 'Malka_CRM_Quote_Page' ) ) {
			return null;
		}

		return hash_equals( (string) $firma['huella'], self::huella( self::copia( Malka_CRM_Quote_Page::data( $qid ) ) ) );
	}

	/**
	 * Toda la evidencia que se guarda al firmar.
	 *
	 * @param array  $d       Datos de la cotización (antes de firmar).
	 * @param string $nombre  Nombre escrito.
	 * @param string $rut     RUT ya formateado.
	 * @param string $acepto  Texto exacto de la casilla.
	 * @return array {firma, copia}
	 */
	public static function evidencia( $d, $nombre, $rut, $acepto ) {
		$ip    = self::ip();
		$ua    = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 400 ) : '';
		$copia = self::copia( $d );

		return array(
			'firma' => array(
				'nombre'    => $nombre,
				'rut'       => $rut,
				'fecha'     => current_time( 'mysql' ),
				'fecha_utc' => gmdate( 'c' ),
				'zona'      => wp_timezone_string(),
				'ip'        => $ip['ip'],
				'ip_remota' => $ip['remota'],
				'ua'        => $ua,
				'correo'    => isset( $d['cliente']['email'] ) ? (string) $d['cliente']['email'] : '',
				'acepto'    => $acepto,
				'terminos'  => $copia['terminos'],
				'huella'    => self::huella( $copia ),
				'metodo'    => 'firma_electronica_simple',
			),
			'copia' => $copia,
		);
	}

	/* ────────────────────────────  Correos  ───────────────────────────── */

	/**
	 * Los datos que rellenan los dos correos de firma.
	 *
	 * @param array $d Datos de la cotización (ya firmada).
	 * @return array
	 */
	public static function datos_correo( $d ) {
		$f      = (array) $d['firma'];
		$marca  = ! empty( $f['fecha'] ) ? strtotime( $f['fecha'] ) : time();
		$cobros = array();

		foreach ( (array) $d['pagos'] as $p ) {
			if ( isset( $p['status'] ) && 'pagado' === $p['status'] ) {
				continue;
			}
			$e = Malka_CRM_Quote_Page::payment_view( $d['id'], $p );
			if ( Malka_CRM_Quote_Page::total_cerrado( $d['pagos'], $p ) ) {
				continue;
			}
			$cuando   = ! empty( $e['espera'] ) ? __( 'al entregar', 'malka-crm' ) : ( ! empty( $e['fecha'] ) ? date_i18n( 'j \d\e F', strtotime( $e['fecha'] ) ) : '' );
			$cobros[] = '- ' . $p['label'] . ': ' . Malka_CRM_Settings::money( $p['amount'] ) . ( $cuando ? ' · ' . $cuando : '' );
		}

		return array(
			'cliente'   => isset( $f['nombre'] ) ? $f['nombre'] : '',
			'rut'       => isset( $f['rut'] ) ? $f['rut'] : '',
			'correo'    => ! empty( $f['correo'] ) ? $f['correo'] : '—',
			'numero'    => $d['numero'],
			'proyecto'  => $d['titulo'],
			'total'     => Malka_CRM_Settings::money( $d['total'] ),
			'fecha'     => date_i18n( 'j \d\e F \d\e Y', $marca ),
			'hora'      => date_i18n( 'H:i', $marca ),
			'ip'        => ! empty( $f['ip'] ) ? $f['ip'] : '—',
			'navegador' => self::navegador( isset( $f['ua'] ) ? $f['ua'] : '' ),
			'huella'    => self::corta( isset( $f['huella'] ) ? $f['huella'] : '' ),
			'cobros'    => $cobros ? implode( "\n", $cobros ) : '- ' . __( 'Nada pendiente', 'malka-crm' ),
			'enlace'    => $d['url'],
			'estudio'   => Malka_CRM_Messages::estudio(),
		);
	}

	/**
	 * Manda los dos correos: el aviso a ella y la copia al cliente.
	 *
	 * @param int $qid Cotización.
	 */
	public static function avisar( $qid ) {
		$d     = Malka_CRM_Quote_Page::data( $qid );
		$datos = self::datos_correo( $d );
		$s     = class_exists( 'Malka_CRM_Settings' ) ? Malka_CRM_Settings::all() : array();
		$ella  = ! empty( $s['email'] ) ? $s['email'] : get_option( 'admin_email' );

		if ( $ella ) {
			Malka_CRM_Messages::enviar( $ella, 'firma_aviso', $datos );
		}

		$cliente = isset( $d['cliente']['email'] ) ? $d['cliente']['email'] : '';

		if ( $cliente && is_email( $cliente ) ) {
			Malka_CRM_Messages::enviar( $cliente, 'firma_copia', $datos );
		}
	}
}
