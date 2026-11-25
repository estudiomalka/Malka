<?php
/**
 * Cobro con SumUp, con el monto ya puesto.
 *
 * El botón «Pagar» de la cotización pasa por handle_pay(), que calcula cuánto
 * se debe hoy y pregunta por el filtro malka_crm_payment_url adónde mandar al
 * cliente. Nadie contestaba ese filtro, así que el cliente volvía a la misma
 * cotización con ?pagar= y nada más: el botón «recargaba la página».
 *
 * Esto contesta el filtro de dos formas:
 *
 *   1. Con la API de SumUp (clave + código de comercio): se crea un checkout
 *      alojado por SumUp con el monto exacto, comisión incluida. El cliente
 *      no escribe nada y no puede cambiar el monto. Al volver —o cuando
 *      SumUp avisa por webhook— se consulta el checkout a SumUp y, si quedó
 *      pagado, la cuota pasa a «Por confirmar» con SumUp como medio.
 *
 *   2. Sin API, con el enlace de pago fijo (pay.sumup.com/b2c/…): se manda
 *      ahí con el monto en la URL. Es un respaldo: ese tipo de enlace no
 *      garantiza leer el monto, por eso la vía buena es la 1.
 *
 * @package Malka_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Malka_CRM_SumUp {

	const API = 'https://api.sumup.com/v0.1/checkouts';

	public function __construct() {
		add_filter( 'malka_crm_payment_url', array( $this, 'payment_url' ), 10, 4 );

		// La vuelta del cliente después de pagar.
		add_action( 'admin_post_nopriv_malka_crm_sumup_vuelta', array( $this, 'handle_return' ) );
		add_action( 'admin_post_malka_crm_sumup_vuelta', array( $this, 'handle_return' ) );

		// El aviso de SumUp, servidor a servidor, aunque el cliente cierre la pestaña.
		add_action( 'admin_post_nopriv_malka_crm_sumup_webhook', array( $this, 'handle_webhook' ) );
		add_action( 'admin_post_malka_crm_sumup_webhook', array( $this, 'handle_webhook' ) );
	}

	/* ─────────────────────────────  Ajustes  ──────────────────────────── */

	private static function s( $k ) {
		return class_exists( 'Malka_CRM_Settings' ) ? trim( (string) Malka_CRM_Settings::get( $k, '' ) ) : '';
	}

	/** ¿Está la API configurada? */
	public static function api_ready() {
		return '' !== self::s( 'sumup_api_key' ) && '' !== self::s( 'sumup_merchant' );
	}

	/**
	 * Lo que se cobra por tarjeta para que a ella le llegue $base completo.
	 *
	 * La misma fórmula inversa que muestra la cotización en «Total a pagar»:
	 * si el cliente vio $X en pantalla, SumUp le cobra $X.
	 *
	 * @param int $base Monto de la cuota (con interés, si lo hay).
	 * @return int
	 */
	public static function total_con_comision( $base ) {
		$tasa = class_exists( 'Malka_CRM_Settings' ) ? (float) Malka_CRM_Settings::get( 'comision_tarjeta', 3.81 ) : 0;

		if ( $tasa <= 0 || $tasa >= 100 ) {
			return (int) $base;
		}

		return (int) round( $base / ( 1 - ( $tasa / 100 ) ) );
	}

	/* ─────────────────────────────  Filtro  ───────────────────────────── */

	/**
	 * Adónde mandar al cliente.
	 *
	 * @param string $destino Lo que ya traía (el link propio de la cuota).
	 * @param int    $qid     Cotización.
	 * @param array  $cuota   Cuota.
	 * @param array  $due     base, interes, monto, dias.
	 * @return string
	 */
	public function payment_url( $destino, $qid, $cuota, $due ) {
		/*
		 * Un link puesto a mano en la cuota manda, salvo que sea un enlace
		 * de SumUp de monto libre: con la API lista, eso es justo lo que se
		 * quiere reemplazar (las cuotas viejas lo traen guardado).
		 */
		$es_sumup = $destino && false !== stripos( (string) $destino, 'sumup.com' );

		if ( $destino && ! ( $es_sumup && self::api_ready() ) ) {
			return $destino;
		}

		$total = self::total_con_comision( (int) $due['monto'] );

		if ( $total <= 0 ) {
			return '';
		}

		if ( self::api_ready() ) {
			$url = $this->crear_checkout( $qid, $cuota, $total );
			if ( $url ) {
				return $url;
			}

			/*
			 * Si quien aprieta es ella (sesión iniciada en el panel), se le
			 * muestra qué contestó SumUp en vez de mandarla al enlace de
			 * respaldo: si no, el error no se ve nunca.
			 */
			if ( current_user_can( 'manage_options' ) || ( class_exists( 'Malka_CRM_Settings' ) && method_exists( 'Malka_CRM_Settings', 'cap' ) && current_user_can( Malka_CRM_Settings::cap() ) ) ) {
				$err = get_option( 'malka_crm_sumup_ultimo_error' );
				wp_die(
					'<h1>' . esc_html__( 'SumUp rechazó el cobro', 'malka-crm' ) . '</h1>' .
					'<p>' . esc_html__( 'Sólo tú ves este mensaje (el cliente iría al enlace de respaldo). Respuesta de SumUp:', 'malka-crm' ) . '</p>' .
					'<pre style="white-space:pre-wrap">' . esc_html( is_array( $err ) ? $err['msg'] : '' ) . '</pre>' .
					'<p>' . esc_html__( 'Revisa la clave de la API y el código de comercio en Configuración.', 'malka-crm' ) . '</p>',
					esc_html__( 'Error de SumUp', 'malka-crm' ),
					array( 'response' => 200, 'back_link' => true )
				);
			}
		}

		$link = self::s( 'link_sumup' );

		if ( $link ) {
			return add_query_arg( 'amount', $total, $link );
		}

		return '';
	}

	/* ─────────────────────────────  API  ──────────────────────────────── */

	private static function pedir( $metodo, $url, $body = null ) {
		$args = array(
			'method'  => $metodo,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . self::s( 'sumup_api_key' ),
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$r = wp_remote_request( $url, $args );

		if ( is_wp_error( $r ) ) {
			self::log( $r->get_error_message() );
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $r );
		$json = json_decode( wp_remote_retrieve_body( $r ), true );

		if ( $code < 200 || $code >= 300 ) {
			self::log( 'HTTP ' . $code . ' ' . wp_remote_retrieve_body( $r ) );
			return null;
		}

		return is_array( $json ) ? $json : null;
	}

	private static function log( $msg ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Malka CRM · SumUp] ' . $msg ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		update_option( 'malka_crm_sumup_ultimo_error', array( 'cuando' => current_time( 'mysql' ), 'msg' => substr( (string) $msg, 0, 500 ) ), false );
	}

	/**
	 * Crea el checkout alojado y devuelve su URL.
	 *
	 * @param int   $qid   Cotización.
	 * @param array $cuota Cuota.
	 * @param int   $total Monto final en pesos.
	 * @return string
	 */
	private function crear_checkout( $qid, $cuota, $total ) {
		$cid = isset( $cuota['id'] ) ? (string) $cuota['id'] : '';

		// Va la cotización y la cuota dentro: al volver, lo que dice SumUp
		// (con la clave de ella) es lo que manda, no lo que traiga la URL.
		$ref = sprintf( 'malka-%d-%s-%s', (int) $qid, $cid, wp_generate_password( 6, false, false ) );

		$d      = class_exists( 'Malka_CRM_Quote_Page' ) ? Malka_CRM_Quote_Page::data( $qid ) : array();
		$numero = ! empty( $d['numero'] ) ? $d['numero'] : '';
		$desc   = sprintf(
			/* translators: 1: número o id de cotización, 2: cuota */
			__( 'Cotización %1$s · %2$s', 'malka-crm' ),
			$numero ? $numero : $qid,
			isset( $cuota['label'] ) ? $cuota['label'] : $cid
		);

		$body = array(
			'checkout_reference' => $ref,
			'amount'             => (int) $total,
			'currency'           => self::s( 'sumup_moneda' ) ? strtoupper( self::s( 'sumup_moneda' ) ) : 'CLP',
			'merchant_code'      => self::s( 'sumup_merchant' ),
			'description'        => wp_strip_all_tags( $desc ),
			'redirect_url'       => add_query_arg( array( 'action' => 'malka_crm_sumup_vuelta', 'ref' => rawurlencode( $ref ) ), admin_url( 'admin-post.php' ) ),
			'return_url'         => add_query_arg( 'action', 'malka_crm_sumup_webhook', admin_url( 'admin-post.php' ) ),
			'hosted_checkout'    => array( 'enabled' => true ),
		);

		$r = self::pedir( 'POST', self::API, $body );

		if ( empty( $r['hosted_checkout_url'] ) || empty( $r['id'] ) ) {
			return '';
		}

		// Para encontrar el checkout desde la referencia al volver.
		set_transient( 'malka_crm_sumup_' . md5( $ref ), (string) $r['id'], DAY_IN_SECONDS );

		return esc_url_raw( $r['hosted_checkout_url'] );
	}

	/**
	 * Consulta un checkout a SumUp y, si está pagado, lo anota.
	 *
	 * @param string $checkout_id Id del checkout en SumUp.
	 * @return array|null qid, url, pagado
	 */
	private function verificar( $checkout_id ) {
		if ( ! $checkout_id || ! self::api_ready() ) {
			return null;
		}

		$c = self::pedir( 'GET', self::API . '/' . rawurlencode( $checkout_id ) );

		if ( ! $c || empty( $c['checkout_reference'] ) ) {
			return null;
		}

		if ( ! preg_match( '/^malka-(\d+)-([a-z0-9_\-]*?)-[A-Za-z0-9]{6}$/', (string) $c['checkout_reference'], $m ) ) {
			return null;
		}

		$qid   = (int) $m[1];
		$cuota = sanitize_key( $m[2] );

		if ( Malka_CRM_Quotes::POST_TYPE !== get_post_type( $qid ) ) {
			return null;
		}

		$pagado = isset( $c['status'] ) && 'PAID' === strtoupper( (string) $c['status'] );

		if ( $pagado ) {
			$this->anotar( $qid, $cuota, $c );
		}

		return array(
			'qid'    => $qid,
			'pagado' => $pagado,
		);
	}

	/**
	 * La cuota queda pagada, con SumUp como medio.
	 *
	 * Sólo se llega acá después de preguntarle a SumUp, con la clave de ella,
	 * y que SumUp diga PAID: no hay nada que confirmar a mano. Si lo pagado
	 * es el total, las cuotas se cierran con él (Quotes::cerrar_por_total) y
	 * el anticipo aparece completado en el avance del proyecto.
	 */
	private function anotar( $qid, $cuota, $c ) {
		$llave = 'malka_crm_sumup_ok_' . md5( (string) $c['id'] );

		// Webhook y vuelta del cliente pueden llegar los dos: una sola vez.
		if ( get_transient( $llave ) ) {
			return;
		}
		set_transient( $llave, 1, WEEK_IN_SECONDS );

		$pagos = Malka_CRM_Quotes::get_payments( $qid );
		$tx    = isset( $c['transaction_code'] ) ? (string) $c['transaction_code'] : '';

		if ( ! $tx && ! empty( $c['transactions'][0]['transaction_code'] ) ) {
			$tx = (string) $c['transactions'][0]['transaction_code'];
		}

		$concepto = '';
		$hallada  = false;

		foreach ( $pagos as $i => $p ) {
			if ( isset( $p['id'] ) && (string) $p['id'] === $cuota ) {
				$pagos[ $i ]['status']      = 'pagado';
				$pagos[ $i ]['method']      = 'sumup';
				$pagos[ $i ]['method_auto'] = 1;
				$pagos[ $i ]['sumup_tx']    = sanitize_text_field( $tx );
				$pagos[ $i ]['sumup_monto'] = isset( $c['amount'] ) ? (float) $c['amount'] : 0;
				$pagos[ $i ]['sumup_fecha'] = current_time( 'mysql' );
				$pagos[ $i ]['paid_at']     = current_time( 'Y-m-d' );
				$concepto                   = isset( $p['label'] ) ? (string) $p['label'] : '';
				$hallada                    = true;
				break;
			}
		}

		if ( ! $hallada ) {
			return;
		}

		update_post_meta( $qid, Malka_CRM_Quotes::META_PAYMENTS, Malka_CRM_Quotes::cerrar_por_total( $pagos ) );

		// Estado de la cotización (en curso, finalizada…) al día.
		if ( method_exists( 'Malka_CRM_Quote_Page', 'sync_status' ) ) {
			Malka_CRM_Quote_Page::sync_status( $qid );
		}

		$this->avisar( $qid, $concepto, isset( $c['amount'] ) ? (float) $c['amount'] : 0, $tx );

		/**
		 * Entró un pago con SumUp.
		 *
		 * @param int    $qid   Cotización.
		 * @param string $cuota Cuota.
		 * @param array  $c     Checkout tal como lo devolvió SumUp.
		 */
		do_action( 'malka_crm_pago_sumup', $qid, $cuota, $c );
	}

	/** Correo a ella: entró un pago, ya quedó anotado. */
	private function avisar( $qid, $concepto, $monto, $tx ) {
		$s    = class_exists( 'Malka_CRM_Settings' ) ? Malka_CRM_Settings::all() : array();
		$para = ! empty( $s['email'] ) ? $s['email'] : get_option( 'admin_email' );

		if ( ! $para ) {
			return;
		}

		$d      = Malka_CRM_Quote_Page::data( $qid );
		$numero = ! empty( $d['numero'] ) ? $d['numero'] : $qid;

		$asunto = sprintf(
			/* translators: 1: número, 2: concepto */
			__( 'Pago con SumUp recibido · Cotización N° %1$s · %2$s', 'malka-crm' ),
			$numero,
			$concepto
		);

		$lineas = array(
			sprintf( /* translators: 1: monto, 2: concepto */ __( 'Entró un pago con tarjeta por %1$s (%2$s).', 'malka-crm' ), Malka_CRM_Settings::money( $monto ), $concepto ),
			__( 'Ya quedó marcado como pagado en la cotización.', 'malka-crm' ),
		);

		if ( $tx ) {
			$lineas[] = sprintf( /* translators: código */ __( 'Código de transacción SumUp: %s', 'malka-crm' ), $tx );
		}

		if ( ! empty( $d['url'] ) ) {
			$lineas[] = $d['url'];
		}

		wp_mail( $para, $asunto, implode( "\n\n", $lineas ) );
	}

	/* ─────────────────────────────  Vueltas  ──────────────────────────── */

	public function handle_return() {
		$ref = isset( $_GET['ref'] ) ? sanitize_text_field( wp_unslash( $_GET['ref'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id  = $ref ? get_transient( 'malka_crm_sumup_' . md5( $ref ) ) : '';
		$r   = $id ? $this->verificar( $id ) : null;

		$qid = $r ? $r['qid'] : 0;

		if ( ! $qid && preg_match( '/^malka-(\d+)-/', $ref, $m ) ) {
			$qid = (int) $m[1];
		}

		$url = $qid ? Malka_CRM_Quotes::get_url( $qid ) : home_url( '/' );

		wp_safe_redirect( add_query_arg( 'sumup', ( $r && $r['pagado'] ) ? 'ok' : 'pendiente', $url ) );
		exit;
	}

	public function handle_webhook() {
		$raw  = file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$json = json_decode( (string) $raw, true );
		$id   = is_array( $json ) && ! empty( $json['id'] ) ? sanitize_text_field( $json['id'] ) : '';

		// Lo que diga el cuerpo no se cree: se vuelve a preguntar a SumUp.
		if ( $id ) {
			$this->verificar( $id );
		}

		status_header( 200 );
		exit;
	}
}
