<?php
/**
 * El editor de cotizaciones, dentro del escritorio.
 *
 * Antes había que salir a WP-Admin para escribir una cotización. Eso rompía
 * la promesa del panel y, peor, dejaba a Malka frente a una pantalla hecha
 * para programadores cuando lo único que quiere es escribir tres ítems y
 * mandar el link. Acá está todo: cliente, ítems, notas, cuotas y estado, en
 * una sola página que se guarda y se publica sola.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Formulario y guardado de cotizaciones desde el panel.
 */
class Malka_CRM_Quote_Editor {

	/**
	 * Engancha el guardado y el borrado.
	 */
	public function __construct() {
		add_action( 'admin_post_malka_crm_save_quote', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_malka_crm_delete_quote', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_malka_crm_duplicar_cotizacion', array( __CLASS__, 'handle_duplicate' ) );
		add_action( 'admin_post_malka_crm_quote_negocio', array( __CLASS__, 'handle_negocio' ) );
	}

	/* ─────────────────────────────  Rutas  ──────────────────────────── */

	/**
	 * URL del editor.
	 *
	 * @param int $qid Cotización, o 0 para una nueva.
	 * @return string
	 */
	public static function edit_url( $qid = 0 ) {
		$base = Malka_CRM_Dashboard::view_url( 'cotizacion' );

		return $qid ? add_query_arg( 'id', (int) $qid, $base ) : $base;
	}

	/**
	 * URL para mandar una cotización a la papelera.
	 *
	 * @param int $qid Cotización.
	 * @return string
	 */
	public static function delete_url( $qid ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'malka_crm_delete_quote',
					'quote'  => (int) $qid,
				),
				admin_url( 'admin-post.php' )
			),
			'malka_crm_delete_quote_' . (int) $qid
		);
	}

	/**
	 * URL para duplicar una cotización.
	 *
	 * @param int $qid Cotización.
	 * @return string
	 */
	public static function duplicate_url( $qid ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'malka_crm_duplicar_cotizacion',
					'quote'  => (int) $qid,
				),
				admin_url( 'admin-post.php' )
			),
			'malka_crm_duplicar_cotizacion_' . (int) $qid
		);
	}

	/**
	 * Crea una cotización nueva igual a otra.
	 *
	 * Se copia lo que se arma con trabajo —cliente, ítems, notas, etiquetas y
	 * las cuotas— y nada de lo que es de esa cotización en particular: queda
	 * con número nuevo, fechas de hoy, sin firma, sin negocio, sin pagos y
	 * «Sin respuesta». Se abre en el editor para cambiar lo que haga falta.
	 */
	public static function handle_duplicate() {
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'No tienes permisos para esto.', 'malka-crm' ) );
		}

		$qid = isset( $_GET['quote'] ) ? (int) $_GET['quote'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'malka_crm_duplicar_cotizacion_' . $qid );

		if ( ! $qid || Malka_CRM_Quotes::POST_TYPE !== get_post_type( $qid ) ) {
			wp_die( esc_html__( 'Esa cotización no existe.', 'malka-crm' ) );
		}

		$nueva = wp_insert_post(
			array(
				'post_type'   => Malka_CRM_Quotes::POST_TYPE,
				'post_title'  => get_the_title( $qid ),
				'post_status' => 'publish',
			),
			true
		);

		if ( is_wp_error( $nueva ) || ! $nueva ) {
			wp_die( esc_html__( 'No se pudo duplicar la cotización.', 'malka-crm' ) );
		}

		$copiar = array(
			'_malka_pm_client_name',
			'_malka_pm_client_company',
			'_malka_pm_client_email',
			'_malka_pm_client_rut',
			'_malka_pm_client_city',
			'_malka_pm_client_phone',
			'_malka_pm_client_id',
			'_malka_pm_quote_items',
			'_malka_pm_quote_total',
			'_malka_pm_quote_notes',
			Malka_CRM_Quotes::META_TAGS,
			Malka_CRM_Reminders::META_AVISOS,
		);
		foreach ( $copiar as $meta ) {
			$v = get_post_meta( $qid, $meta, true );
			if ( '' !== $v && null !== $v ) {
				update_post_meta( $nueva, $meta, $v );
			}
		}

		// Fechas de hoy, con la vigencia de siempre.
		$hoy  = current_time( 'Y-m-d' );
		$dias = (int) Malka_CRM_Settings::get( 'validez_dias', 10 );
		update_post_meta( $nueva, '_malka_pm_quote_issued', $hoy );
		update_post_meta( $nueva, '_malka_pm_quote_expires', gmdate( 'Y-m-d', strtotime( $hoy ) + ( max( 1, $dias ) * DAY_IN_SECONDS ) ) );
		update_post_meta( $nueva, '_malka_pm_quote_status', 'sin_respuesta' );
		update_post_meta( $nueva, Malka_CRM_Reminders::META_DESDE, $hoy );

		// Las cuotas, con su nombre y monto, pero sin nada pagado.
		$pagos = array();
		foreach ( Malka_CRM_Quotes::get_payments( $qid ) as $p ) {
			$pagos[] = array(
				'id'          => isset( $p['id'] ) ? $p['id'] : '',
				'label'       => isset( $p['label'] ) ? $p['label'] : '',
				'amount'      => isset( $p['amount'] ) ? $p['amount'] : 0,
				'due'         => '',
				'status'      => 'espera',
				'rol'         => isset( $p['rol'] ) ? $p['rol'] : 'cuota',
				'disparo'     => isset( $p['disparo'] ) ? $p['disparo'] : '',
				'method'      => '',
				'method_auto' => 0,
				'pay_url'     => '',
			);
		}
		if ( ! $pagos ) {
			$pagos = Malka_CRM_Quotes::default_plan( (int) get_post_meta( $qid, '_malka_pm_quote_total', true ) );
		}
		update_post_meta( $nueva, Malka_CRM_Quotes::META_PAYMENTS, $pagos );

		Malka_CRM_Quote_Page::assign_number( $nueva, get_post( $nueva ), true );
		Malka_CRM_Quote_Page::sync_status( $nueva );

		$de = (string) get_post_meta( $qid, Malka_CRM_Quote_Page::META_NUM, true );
		wp_safe_redirect( add_query_arg( 'duplicada', rawurlencode( $de ? $de : (string) $qid ), self::edit_url( $nueva ) ) );
		exit;
	}

	/* ────────────────────────────  Guardado  ────────────────────────── */

	/**
	 * Guarda la cotización y vuelve al panel.
	 *
	 * Los totales no se leen del formulario aunque el navegador los muestre:
	 * se recalculan acá desde los ítems. Lo que se calcula en el navegador se
	 * puede cambiar desde el navegador.
	 */
	public static function handle_save() {
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'No tienes permisos para esto.', 'malka-crm' ) );
		}

		check_admin_referer( 'malka_crm_editor_quote' );

		$qid = isset( $_POST['quote'] ) ? (int) $_POST['quote'] : 0;

		$titulo = isset( $_POST['titulo'] ) ? sanitize_text_field( wp_unslash( $_POST['titulo'] ) ) : '';
		$titulo = '' !== trim( $titulo ) ? $titulo : __( 'Cotización sin título', 'malka-crm' );

		if ( $qid && Malka_CRM_Quotes::POST_TYPE === get_post_type( $qid ) ) {
			wp_update_post(
				array(
					'ID'         => $qid,
					'post_title' => $titulo,
				)
			);
		} else {
			$qid = wp_insert_post(
				array(
					'post_type'   => Malka_CRM_Quotes::POST_TYPE,
					'post_title'  => $titulo,
					'post_status' => 'publish',
				),
				true
			);

			if ( is_wp_error( $qid ) || ! $qid ) {
				wp_die( esc_html__( 'No se pudo crear la cotización.', 'malka-crm' ) );
			}
		}

		/* ── Cliente ── */
		$campos = array(
			'_malka_pm_client_name'    => 'cliente_nombre',
			'_malka_pm_client_company' => 'cliente_empresa',
			'_malka_pm_client_rut'     => 'cliente_rut',
			'_malka_pm_client_city'    => 'cliente_ciudad',
			'_malka_pm_client_phone'   => 'cliente_fono',
		);

		/*
		 * Un campo que no vino en el formulario no se vacía: se deja como
		 * estaba. Vaciarlo por ausencia es la misma trampa que ya se arregló
		 * en Configuración —un guardado parcial borraba datos buenos— y acá
		 * el que más duele es el correo, porque de él cuelgan los avisos.
		 */
		foreach ( $campos as $meta => $campo ) {
			if ( isset( $_POST[ $campo ] ) ) {
				update_post_meta( $qid, $meta, sanitize_text_field( wp_unslash( $_POST[ $campo ] ) ) );
			}
		}

		if ( isset( $_POST['cliente_email'] ) ) {
			update_post_meta( $qid, '_malka_pm_client_email', sanitize_email( wp_unslash( $_POST['cliente_email'] ) ) );
		}

		/* ── Fechas ── */
		$emitida = isset( $_POST['emitida'] ) ? Malka_CRM_Helpers::sanitize_date( wp_unslash( $_POST['emitida'] ) ) : '';
		$emitida = $emitida ? $emitida : current_time( 'Y-m-d' );

		$vence = isset( $_POST['vence'] ) ? Malka_CRM_Helpers::sanitize_date( wp_unslash( $_POST['vence'] ) ) : '';

		if ( ! $vence ) {
			// Sin fecha escrita, la vigencia por defecto del estudio.
			$dias  = (int) Malka_CRM_Settings::get( 'validez_dias', 10 );
			$vence = gmdate( 'Y-m-d', strtotime( $emitida ) + ( max( 1, $dias ) * DAY_IN_SECONDS ) );
		}

		update_post_meta( $qid, '_malka_pm_quote_issued', $emitida );
		update_post_meta( $qid, '_malka_pm_quote_expires', $vence );

		/* ── Ítems y total ── */
		$items = self::clean_items( isset( $_POST['item'] ) ? (array) wp_unslash( $_POST['item'] ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		update_post_meta( $qid, '_malka_pm_quote_items', $items );
		update_post_meta(
			$qid,
			'_malka_pm_quote_notes',
			isset( $_POST['notas'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notas'] ) ) : ''
		);

		$neto  = 0;
		foreach ( $items as $it ) {
			$neto += (int) round( $it['cantidad'] * $it['unitario'] * ( 1 - ( $it['descuento'] / 100 ) ) );
		}

		$iva   = (float) Malka_CRM_Settings::get( 'iva', 19 );
		$total = $neto + (int) round( $neto * ( $iva / 100 ) );

		update_post_meta( $qid, '_malka_pm_quote_total', $total );

		/* ── Cuotas ── */
		$crudo = isset( $_POST['pago'] ) ? (array) wp_unslash( $_POST['pago'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$pagos = Malka_CRM_Quotes::sanitize_payments( $crudo );

		$rehacer = isset( $_POST['rehacer_plan'] ) && '1' === $_POST['rehacer_plan'];
		$tenia   = Malka_CRM_Quotes::get_payments( $qid );

		if ( $rehacer || ( ! $pagos && ! $tenia ) ) {
			// Lo pidió, o la cotización todavía no tenía cuotas: se arma el 60/40.
			$pagos = Malka_CRM_Quotes::default_plan( $total );
		} elseif ( ! $pagos ) {
			/*
			 * El formulario no trajo las cuotas pero la cotización sí las
			 * tiene. Rehacerlas acá borraría qué cuota está pagada, que es
			 * el dato más caro de recuperar, así que se dejan como estaban.
			 */
			$pagos = $tenia;
		}

		$pagos = Malka_CRM_Quotes::conservar_extras( $pagos, $tenia );

		update_post_meta( $qid, Malka_CRM_Quotes::META_PAYMENTS, $pagos );

		/* ── Avisos al cliente ── */
		/*
		 * La casilla viene marcada, así que su ausencia en el POST significa
		 * que ella la desmarcó: se guarda el 0 explícito y no el default.
		 */
		update_post_meta(
			$qid,
			Malka_CRM_Reminders::META_AVISOS,
			empty( $_POST['avisos'] ) ? '0' : '1'
		);

		/*
		 * Desde cuándo esta cotización puede reclamarle al cliente. Se fija
		 * una sola vez, al crearla: así una cotización que ya venía en curso
		 * y se carga hoy no manda recordatorios por cuotas de hace un mes.
		 */
		if ( ! get_post_meta( $qid, Malka_CRM_Reminders::META_DESDE, true ) ) {
			update_post_meta( $qid, Malka_CRM_Reminders::META_DESDE, current_time( 'Y-m-d' ) );
		}

		/* ── Aceptación cargada a mano ── */
		$aviso_firma = self::guardar_firma( $qid );

		/* ── Aceptada de palabra: se cobra aunque falte la firma ── */
		if ( isset( $_POST['palabra'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( '1' === (string) wp_unslash( $_POST['palabra'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				update_post_meta( $qid, Malka_CRM_Quote_Page::META_PALABRA, current_time( 'Y-m-d' ) );
			} else {
				delete_post_meta( $qid, Malka_CRM_Quote_Page::META_PALABRA );
			}
		}

		/* ── Estado ── */
		$pedido = '';

		if ( isset( $_POST['estado'] ) ) {
			$estado  = sanitize_key( wp_unslash( $_POST['estado'] ) );
			$validos = Malka_CRM_Quotes::quote_statuses();

			if ( isset( $validos[ $estado ] ) ) {
				$pedido = $estado;
				update_post_meta( $qid, '_malka_pm_quote_status', $estado );
			}
		}

		/*
		 * El estado sale de los hechos, no al revés. Elegir «Entregado»
		 * tiene que mover el hecho que hay debajo —la fecha de entrega— o
		 * el recálculo de más abajo se lleva la elección por delante.
		 */
		if ( $pedido ) {
			Malka_CRM_Quote_Page::fijar_entrega( $qid, $pedido );
		}

		// El número y la URL pública, si todavía no los tiene.
		// Las etiquetas («Web», «Branding»), si el formulario las trajo.
		if ( isset( $_POST['etiquetas'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			update_post_meta( $qid, Malka_CRM_Quotes::META_TAGS, Malka_CRM_Quotes::limpiar_etiquetas( wp_unslash( $_POST['etiquetas'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		Malka_CRM_Quote_Page::assign_number( $qid, get_post( $qid ), true );
		$quedo = Malka_CRM_Quote_Page::sync_status( $qid );

		$destino = isset( $_POST['seguir'] ) && '1' === $_POST['seguir']
			? add_query_arg( 'guardada', '1', self::edit_url( $qid ) )
			: add_query_arg( 'guardada', (int) $qid, Malka_CRM_Dashboard::view_url( 'cotizaciones' ) );

		/*
		 * Si los hechos mandan a otro estado que el elegido, se dice. Antes
		 * se sobrescribía en silencio y parecía que el guardado no servía.
		 */
		if ( $pedido && $quedo !== $pedido ) {
			$destino = add_query_arg(
				array(
					'pedido' => $pedido,
					'quedo'  => $quedo,
				),
				$destino
			);
		}

		if ( $aviso_firma ) {
			$destino = add_query_arg( 'firma', $aviso_firma, $destino );
		}

		wp_safe_redirect( $destino );
		exit;
	}

	/**
	 * Guarda la aceptación que ella carga a mano.
	 *
	 * Con nombre y RUT la cotización queda aceptada; con los dos vacíos se
	 * borra la que hubiera, que es la única forma de deshacer una firma mal
	 * puesta. Un RUT inválido no se guarda y se avisa: un RUT falso en una
	 * aceptación es peor que ninguna.
	 *
	 * @param int $qid Cotización.
	 * @return string '' si no hay nada que decir, o el código del aviso.
	 */
	private static function guardar_firma( $qid ) {
		// Un formulario que no trae los campos no toca la firma.
		if ( ! isset( $_POST['firma_nombre'] ) && ! isset( $_POST['firma_rut'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return '';
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$nombre = isset( $_POST['firma_nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['firma_nombre'] ) ) : '';
		$rut    = isset( $_POST['firma_rut'] ) ? sanitize_text_field( wp_unslash( $_POST['firma_rut'] ) ) : '';
		$fecha  = isset( $_POST['firma_fecha'] ) ? Malka_CRM_Helpers::sanitize_date( wp_unslash( $_POST['firma_fecha'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$previa = get_post_meta( $qid, '_malka_pm_quote_sign', true );
		$previa = is_array( $previa ) ? $previa : array();

		if ( '' === trim( $nombre ) && '' === trim( $rut ) ) {
			if ( ! empty( $previa['rut'] ) ) {
				delete_post_meta( $qid, '_malka_pm_quote_sign' );

				return 'borrada';
			}

			return '';
		}

		if ( '' === trim( $nombre ) || '' === trim( $rut ) ) {
			return 'incompleta';
		}

		if ( ! Malka_CRM_Quote_Page::valid_rut( $rut ) ) {
			return 'rut';
		}

		$guardada = isset( $previa['fecha'] ) ? (string) $previa['fecha'] : '';
		$misma    = ( isset( $previa['rut'] ) && Malka_CRM_Quote_Page::format_rut( $rut ) === $previa['rut'] )
			&& ( isset( $previa['nombre'] ) && $nombre === $previa['nombre'] );

		/*
		 * Si no cambió nada, la firma se deja intacta: volver a guardar la
		 * cotización no puede reescribir la hora de una firma electrónica ni
		 * convertirla en una carga manual.
		 */
		if ( $misma && ( '' === $fecha || substr( $guardada, 0, 10 ) === $fecha ) ) {
			return '';
		}

		update_post_meta(
			$qid,
			'_malka_pm_quote_sign',
			array(
				'nombre' => $nombre,
				'rut'    => Malka_CRM_Quote_Page::format_rut( $rut ),
				'fecha'  => $fecha ? $fecha . ' 00:00:00' : current_time( 'mysql' ),
				'origen' => 'panel',
			)
		);

		return 'cargada';
	}

	/**
	 * El acuse de lo que pasó con la aceptación al guardar.
	 *
	 * @return string Frase lista, o ''.
	 */
	public static function nota_firma() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$c = isset( $_GET['firma'] ) ? sanitize_key( wp_unslash( $_GET['firma'] ) ) : '';

		$frases = array(
			'cargada'    => __( 'Quedó registrada la aceptación del cliente. Ya puede pagar y ve su avance.', 'malka-crm' ),
			'borrada'    => __( 'Se borró la aceptación: la cotización vuelve a estar sin firmar.', 'malka-crm' ),
			'rut'        => __( 'Ese RUT no es válido, así que la aceptación no se guardó. Revisa el dígito verificador.', 'malka-crm' ),
			'incompleta' => __( 'Para registrar la aceptación hacen falta el nombre y el RUT. No se guardó.', 'malka-crm' ),
		);

		return isset( $frases[ $c ] ) ? $frases[ $c ] : '';
	}

	/**
	 * Por qué la cotización quedó en un estado distinto del elegido.
	 *
	 * @param int $qid Cotización.
	 * @return string Frase lista, o ''.
	 */
	public static function nota_estado( $qid ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$pedido = isset( $_GET['pedido'] ) ? sanitize_key( wp_unslash( $_GET['pedido'] ) ) : '';
		$quedo  = isset( $_GET['quedo'] ) ? sanitize_key( wp_unslash( $_GET['quedo'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$nombres = Malka_CRM_Quotes::quote_statuses();

		if ( ! $pedido || ! $quedo || ! isset( $nombres[ $pedido ], $nombres[ $quedo ] ) ) {
			return '';
		}

		$por = self::motivo_estado( $qid, $quedo );

		return trim(
			sprintf(
				/* translators: 1: estado elegido, 2: estado que quedó */
				__( 'Elegiste «%1$s», pero quedó en «%2$s».', 'malka-crm' ),
				$nombres[ $pedido ],
				$nombres[ $quedo ]
			) . ( $por ? ' ' . $por : '' )
		);
	}

	/**
	 * El hecho que forzó el estado, dicho en una frase.
	 *
	 * @param int    $qid   Cotización.
	 * @param string $quedo Estado resultante.
	 * @return string
	 */
	private static function motivo_estado( $qid, $quedo ) {
		$pagos  = Malka_CRM_Quotes::get_payments( $qid );
		$cuotas = 0;
		$total  = 0;

		foreach ( $pagos as $p ) {
			if ( isset( $p['rol'] ) && 'total' === $p['rol'] ) {
				continue;
			}

			$cuotas++;

			if ( isset( $p['status'] ) && 'pagado' === $p['status'] ) {
				$total++;
			}
		}

		if ( 'finalizada' === $quedo ) {
			return sprintf(
				/* translators: cuántas cuotas hay */
				_n(
					'Está pagada la única cuota que cuenta como deuda, así que no queda nada por cobrar. Revisa que ninguna fila esté marcada como «Pago total» sin serlo.',
					'Están pagadas las %d cuotas que cuentan como deuda, así que no queda nada por cobrar. Revisa que ninguna fila esté marcada como «Pago total» sin serlo.',
					$cuotas,
					'malka-crm'
				),
				$cuotas
			);
		}

		if ( 'entregado' === $quedo ) {
			return __( 'Hay un saldo contra entrega todavía sin pagar.', 'malka-crm' );
		}

		if ( 'en_curso' === $quedo ) {
			return __( 'Ya entró alguna cuota, pero el proyecto no está marcado como entregado.', 'malka-crm' );
		}

		if ( 'aceptada' === $quedo ) {
			return __( 'Está firmada y todavía no entra ninguna cuota.', 'malka-crm' );
		}

		if ( 'caducada' === $quedo ) {
			return __( 'Pasó la fecha de vigencia sin firma.', 'malka-crm' );
		}

		return '';
	}

	/**
	 * Deja los ítems en la forma que espera la página pública.
	 *
	 * Las filas sin título se descartan: son las que quedan vacías cuando
	 * alguien agrega una fila y se arrepiente.
	 *
	 * @param array $crudo Lo que llegó del formulario.
	 * @return array[]
	 */
	public static function clean_items( $crudo ) {
		$out = array();

		foreach ( $crudo as $fila ) {
			if ( ! is_array( $fila ) ) {
				continue;
			}

			$titulo = isset( $fila['titulo'] ) ? sanitize_text_field( $fila['titulo'] ) : '';

			if ( '' === trim( $titulo ) ) {
				continue;
			}

			$out[] = array(
				'titulo'    => $titulo,
				'detalle'   => isset( $fila['detalle'] ) ? sanitize_textarea_field( $fila['detalle'] ) : '',
				// Los números salen siempre del mismo tipo: max() devuelve el
				// literal que gana, así que sin el cast un 0 quedaría int y
				// un 1,5 float, y la comparación más adelante se vuelve rara.
				'plazo'     => isset( $fila['plazo'] ) ? max( 0, (int) $fila['plazo'] ) : 0,
				'cantidad'  => isset( $fila['cantidad'] ) ? (float) max( 0, (float) $fila['cantidad'] ) : 1.0,
				'unitario'  => isset( $fila['unitario'] ) ? (float) max( 0, round( Malka_CRM_Quotes::cifra( $fila['unitario'] ) ) ) : 0.0,
				'descuento' => isset( $fila['descuento'] ) ? (float) min( 100, max( 0, (float) $fila['descuento'] ) ) : 0.0,
			);
		}

		return $out;
	}

	/* ─────────────────────────────  Borrado  ────────────────────────── */

	/**
	 * Manda una cotización a la papelera.
	 *
	 * A la papelera y no al vacío: una cotización borrada por error se
	 * recupera, y su número no se puede reusar sin romper el correlativo.
	 */
	public static function handle_delete() {
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'No tienes permisos para esto.', 'malka-crm' ) );
		}

		$qid = isset( $_GET['quote'] ) ? (int) $_GET['quote'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		check_admin_referer( 'malka_crm_delete_quote_' . $qid );

		if ( ! $qid || Malka_CRM_Quotes::POST_TYPE !== get_post_type( $qid ) ) {
			wp_die( esc_html__( 'Esa cotización no existe.', 'malka-crm' ) );
		}

		$numero = (string) get_post_meta( $qid, Malka_CRM_Quote_Page::META_NUM, true );

		wp_trash_post( $qid );

		wp_safe_redirect(
			add_query_arg(
				'borrada',
				rawurlencode( $numero ? $numero : (string) $qid ),
				Malka_CRM_Dashboard::view_url( 'cotizaciones' )
			)
		);
		exit;
	}

	/* ─────────────────────────────  Pantalla  ───────────────────────── */

	/**
	 * Qué cotización se está editando, si hay alguna.
	 *
	 * @return int 0 si es nueva.
	 */
	public static function current_id() {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return ( $id && Malka_CRM_Quotes::POST_TYPE === get_post_type( $id ) ) ? $id : 0;
	}

	/**
	 * El formulario completo.
	 *
	 * @return string
	 */
	public static function render() {
		$qid   = self::current_id();
		$nueva = ! $qid;

		$d = $nueva ? self::blank() : Malka_CRM_Quote_Page::data( $qid );

		ob_start();
		echo '<div class="esc-ed">';

		if ( isset( $_GET['guardada'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<p class="esc-ok">' . esc_html__( 'Guardada. El cliente ya ve los cambios en su página.', 'malka-crm' ) . '</p>';

			$nota = self::nota_estado( $qid );

			if ( $nota ) {
				echo '<p class="esc-aviso">' . esc_html( $nota ) . '</p>';
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['duplicada'] ) ) {
			printf(
				'<p class="esc-ok">%s</p>',
				esc_html(
					sprintf(
						/* translators: número de la original */
						__( 'Es una copia de la N° %s: mismo cliente, ítems y cuotas, con fecha de hoy y sin pagos. Cambia lo que necesites; se guarda solo.', 'malka-crm' ),
						sanitize_text_field( wp_unslash( $_GET['duplicada'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					)
				)
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['desde_negocio'] ) ) {
			echo '<p class="esc-ok">' . esc_html__( 'Cotización nueva, ya enlazada a su negocio y con los datos del cliente. Agrega los ítems; se guarda sola.', 'malka-crm' ) . '</p>';
		}

		$firma = self::nota_firma();

		if ( $firma ) {
			echo '<p class="esc-aviso">' . esc_html( $firma ) . '</p>';
		}

		printf(
			'<form method="post" action="%s" class="esc-ed__f" id="esc-ed">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		echo '<input type="hidden" name="action" value="malka_crm_save_quote" />';
		printf( '<input type="hidden" name="quote" value="%d" />', (int) $qid );
		wp_nonce_field( 'malka_crm_editor_quote' );

		/*
		 * Dos columnas, como el documento: a la izquierda lo que se escribe
		 * (cliente, ítems, cuotas); a la derecha, fija al bajar, el total, el
		 * estado con sus fechas, la aceptación y los avisos. Antes era una
		 * sola columna larga donde el estado vivía en la barra de abajo y el
		 * total a media página.
		 */
		echo '<div class="esc-docw esc-qe"><div class="esc-docw__main">';
		echo self::block_cliente( $d );  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::block_items( $d );    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::block_pagos( $d, $nueva ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div><aside class="esc-docw__side">';
		echo self::block_total( $d );    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::block_estado( $d, $nueva ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::block_firma( $d );    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( ! $nueva ) {
			echo self::block_negocios( $d ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '<section class="esc-panel">' . self::block_avisos( $d, $nueva ) . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</aside></div>';
		echo self::block_barra( $d, $nueva ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '</form></div>';

		return ob_get_clean();
	}

	/**
	 * Una cotización vacía, con las fechas ya puestas.
	 *
	 * @return array
	 */
	private static function blank() {
		$hoy  = current_time( 'Y-m-d' );
		$dias = max( 1, (int) Malka_CRM_Settings::get( 'validez_dias', 10 ) );

		return array(
			'id'      => 0,
			'numero'  => '',
			'titulo'  => '',
			'cliente' => array(
				'nombre'  => '',
				'empresa' => '',
				'rut'     => '',
				'ciudad'  => '',
				'email'   => '',
				'fono'    => '',
			),
			'firma'   => array(),
			'items'   => array(),
			'neto'    => 0,
			'iva'     => 0,
			'total'   => 0,
			'notas'   => (string) Malka_CRM_Settings::get( 'notas_tipo', '' ),
			'pagos'   => array(),
			'estado'  => 'sin_respuesta',
			'emitida' => $hoy,
			'vence'   => gmdate( 'Y-m-d', strtotime( $hoy ) + ( $dias * DAY_IN_SECONDS ) ),
			'url'     => '',
		);
	}

	/**
	 * Datos del cliente y del proyecto.
	 *
	 * @param array $d Cotización.
	 * @return string
	 */
	private static function block_cliente( $d ) {
		ob_start();
		echo '<section class="esc-panel"><div class="esc-ph"><div><h2>' . esc_html__( 'Para quién y de qué se trata', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Esto es lo que aparece arriba en la página del cliente', 'malka-crm' ) . '</p></div></div>';

		$fichas = self::fichas();

		/*
		 * Una grilla pareja: el proyecto a lo ancho; nombre, empresa y RUT;
		 * correo, teléfono y ciudad. Las fechas pasaron a la columna de la
		 * derecha, junto al estado.
		 */
		echo '<div class="esc-qe__g">';
		echo self::campo( 'titulo', __( 'Proyecto', 'malka-crm' ), $d['titulo'], 'text', '', 'placeholder="' . esc_attr__( 'Por ejemplo: Branding y manual de marca', 'malka-crm' ) . '"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::campo( 'cliente_nombre', __( 'Nombre y apellido', 'malka-crm' ), $d['cliente']['nombre'], 'text', '', $fichas ? 'list="esc-ed-nombres" autocomplete="off"' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::campo( 'cliente_empresa', __( 'Empresa', 'malka-crm' ), $d['cliente']['empresa'], 'text', '', $fichas ? 'list="esc-ed-empresas" autocomplete="off"' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::campo( 'cliente_rut', __( 'RUT', 'malka-crm' ), $d['cliente']['rut'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::campo( 'cliente_email', __( 'Correo', 'malka-crm' ), $d['cliente']['email'], 'email', '', 'title="' . esc_attr__( 'Acá le llegan los avisos de entrega y de cobro.', 'malka-crm' ) . '"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::campo( 'cliente_fono', __( 'Teléfono', 'malka-crm' ), $d['cliente']['fono'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::campo( 'cliente_ciudad', __( 'Ciudad', 'malka-crm' ), $d['cliente']['ciudad'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		echo self::bloque_fichas( $fichas ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</section>';

		return ob_get_clean();
	}

	/**
	 * El total, a la vista mientras se escribe: neto, IVA y total.
	 *
	 * @param array $d Cotización.
	 * @return string
	 */
	private static function block_total( $d ) {
		ob_start();
		echo '<section class="esc-panel esc-docw__total">';
		echo '<span class="esc-nh__k">' . esc_html__( 'Total de la cotización', 'malka-crm' ) . '</span>';
		echo '<div class="esc-desglose">';
		printf(
			'<span class="esc-desglose__r"><b>%1$s</b><u id="esc-ed-neto">%2$s</u></span>',
			esc_html__( 'Neto', 'malka-crm' ),
			esc_html( Malka_CRM_Settings::money( $d['neto'] ) )
		);
		printf(
			'<span class="esc-desglose__r"><b>%1$s</b><u id="esc-ed-iva">%2$s</u></span>',
			esc_html( sprintf( /* translators: porcentaje */ __( 'IVA (%s%%)', 'malka-crm' ), (float) Malka_CRM_Settings::get( 'iva', 19 ) ) ),
			esc_html( Malka_CRM_Settings::money( $d['iva'] ) )
		);
		printf(
			'<span class="esc-desglose__r is-total"><b>%1$s</b><u id="esc-ed-total">%2$s</u></span>',
			esc_html__( 'Total con IVA', 'malka-crm' ),
			esc_html( Malka_CRM_Settings::money( $d['total'] ) )
		);
		echo '</div></section>';

		return ob_get_clean();
	}

	/**
	 * El estado, su número, sus fechas, la página del cliente y la papelera.
	 *
	 * El estado vivía en la barra de abajo, que con el guardado automático
	 * ya no se ve: acá queda a la vista.
	 *
	 * @param array $d     Cotización.
	 * @param bool  $nueva Si todavía no existe.
	 * @return string
	 */
	private static function block_estado( $d, $nueva ) {
		ob_start();
		echo '<section class="esc-panel esc-qe__est">';
		echo '<div class="esc-ph"><div>';
		printf(
			'<h2>%s</h2>',
			esc_html(
				$nueva
					/* translators: número que se asignará */
					? sprintf( __( 'Va a quedar como la N° %s', 'malka-crm' ), Malka_CRM_Quote_Page::next_number() )
					: ( $d['numero'] ? sprintf( /* translators: número */ __( 'Cotización N° %s', 'malka-crm' ), $d['numero'] ) : __( 'Sin número', 'malka-crm' ) )
			)
		);
		echo '</div>';

		if ( ! $nueva ) {
			printf(
				'<a class="esc-nh__bin esc-nh__dup" href="%1$s" title="%2$s" aria-label="%2$s">' .
				'<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/></svg></a>',
				esc_url( self::duplicate_url( $d['id'] ) ),
				esc_attr__( 'Duplicar esta cotización', 'malka-crm' )
			);
			printf(
				'<a class="esc-nh__bin" href="%1$s" data-confirmar="%2$s" title="%3$s" aria-label="%3$s">' .
				'<svg class="esc-x-ic" viewBox="0 0 16 16" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.15" stroke-linecap="round" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8"/></svg></a>',
				esc_url( self::delete_url( $d['id'] ) ),
				esc_attr(
					sprintf(
						/* translators: identificación de la cotización */
						__( '¿Mandar %s a la papelera? La puedes recuperar después.', 'malka-crm' ),
						$d['numero'] ? 'la N° ' . $d['numero'] : __( 'esta cotización', 'malka-crm' )
					)
				),
				esc_attr__( 'Mandar esta cotización a la papelera', 'malka-crm' )
			);
		}
		echo '</div>';

		printf(
			'<p class="esc-f"><label class="esc-f__l" for="f-estado">%s</label><span class="esc-f__w"><select class="esc-f__i" id="f-estado" name="estado">',
			esc_html__( 'Estado', 'malka-crm' )
		);
		foreach ( Malka_CRM_Quotes::quote_statuses() as $k => $rotulo ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $k ),
				selected( $d['estado'] ? $d['estado'] : 'sin_respuesta', $k, false ),
				esc_html( $rotulo )
			);
		}
		echo '</select></span></p>';

		echo '<div class="esc-qe__fechas">';
		echo self::campo( 'emitida', __( 'Emitida', 'malka-crm' ), $d['emitida'], 'date' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::campo( 'vence', __( 'Válida hasta', 'malka-crm' ), $d['vence'], 'date', '', 'title="' . esc_attr__( 'Es la fecha en que vence el anticipo.', 'malka-crm' ) . '"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		/*
		 * Etiquetas: para agrupar trabajos («Web», «Branding») y después
		 * filtrar las cotizaciones y escribirles a esos clientes.
		 */
		$mias   = $nueva ? array() : Malka_CRM_Quotes::etiquetas( $d['id'] );
		$todas  = array_keys( Malka_CRM_Quotes::todas_etiquetas() );
		$chips  = '';
		foreach ( $mias as $t ) {
			$chips .= sprintf( '<span class="esc-tag" data-tag="%1$s">%2$s<button type="button" aria-label="%3$s">&times;</button></span>', esc_attr( $t ), esc_html( $t ), esc_attr__( 'Quitar', 'malka-crm' ) );
		}
		$ops = '';
		foreach ( $todas as $t ) {
			$ops .= '<option value="' . esc_attr( $t ) . '"></option>';
		}
		printf(
			'<div class="esc-f esc-tags" data-tags><label class="esc-f__l" for="f-etiqueta">%1$s</label>' .
			'<div class="esc-tags__box">%2$s<input type="text" id="f-etiqueta" list="esc-tags-lista" placeholder="%3$s" autocomplete="off" /></div>' .
			'<input type="hidden" name="etiquetas" value="%4$s" data-tags-campo />' .
			'<datalist id="esc-tags-lista">%5$s</datalist></div>',
			esc_html__( 'Etiquetas', 'malka-crm' ),
			$chips, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_attr( $mias ? __( 'Agregar…', 'malka-crm' ) : __( 'Web, Branding… (Enter para agregar)', 'malka-crm' ) ),
			esc_attr( implode( ',', $mias ) ),
			$ops // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		if ( ! $nueva && $d['url'] ) {
			printf(
				'<div class="esc-pt__url"><a href="%1$s" target="_blank" rel="noopener">%2$s</a><button type="button" class="esc-pt__copy" data-copiar="%1$s">%3$s</button></div>',
				esc_url( $d['url'] ),
				esc_html__( 'Ver la página del cliente ↗', 'malka-crm' ),
				esc_html__( 'Copiar enlace', 'malka-crm' )
			);
		}

		echo '</section>';

		return ob_get_clean();
	}

	/**
	 * La firma, para las cotizaciones que se aceptaron fuera del panel.
	 *
	 * Las que vienen del sistema anterior ya están firmadas, pero el panel no
	 * tiene cómo saberlo: entraban como «no firmadas» y con eso quedaban sin
	 * avance, sin negocio y sin poder cobrarse.
	 *
	 * @param array $d Cotización.
	 * @return string
	 */
	private static function block_firma( $d ) {
		$f      = isset( $d['firma'] ) && is_array( $d['firma'] ) ? $d['firma'] : array();
		$nombre = isset( $f['nombre'] ) ? (string) $f['nombre'] : '';
		$rut    = isset( $f['rut'] ) ? (string) $f['rut'] : '';
		$fecha  = isset( $f['fecha'] ) ? substr( (string) $f['fecha'], 0, 10 ) : '';
		$suya   = ( isset( $f['origen'] ) && 'panel' === $f['origen'] );

		ob_start();
		$hay = ( $nombre && $rut );

		echo '<section class="esc-panel esc-qe__firma"><div class="esc-ph"><div><h2>' . esc_html__( 'Aceptación', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Si el cliente firma en su página, se llena sola', 'malka-crm' ) . '</p></div>';
		printf(
			'<span class="esc-st esc-st--%1$s">%2$s</span></div>',
			$hay ? 'done' : 'off',
			esc_html( $hay ? __( 'Aceptada', 'malka-crm' ) : __( 'Sin firmar', 'malka-crm' ) )
		);

		if ( $nombre || $rut ) {
			printf(
				'<p class="esc-hint">%s</p>',
				esc_html(
					$suya
						? __( 'Esta aceptación la cargaste tú, no salió de la página del cliente.', 'malka-crm' )
						: __( 'El cliente firmó en su página. Si corriges algo acá, queda como aceptación cargada por el estudio.', 'malka-crm' )
				)
			);
		}

		echo '<div class="esc-qe__fg">';
		echo self::campo( 'firma_nombre', __( 'Quién aceptó', 'malka-crm' ), $nombre ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::campo( 'firma_rut', __( 'Su RUT', 'malka-crm' ), $rut ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::campo( 'firma_fecha', __( 'Cuándo', 'malka-crm' ), $fecha, 'date', '', 'title="' . esc_attr__( 'Si lo dejas vacío se pone la fecha de hoy.', 'malka-crm' ) . '"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		printf(
			'<p class="esc-f__h">%s</p>',
			esc_html__( 'Con nombre y RUT queda aceptada: se le abre el pago y aparece su avance. Para deshacerlo, borra los dos.', 'malka-crm' )
		);

		// Aceptó de palabra: las cuotas con fecha se le cobran en el portal mientras firma.
		if ( ! $hay ) {
			$palabra = ! empty( $d['id'] ) && (string) get_post_meta( (int) $d['id'], Malka_CRM_Quote_Page::META_PALABRA, true );
			printf(
				'<div class="esc-qe__palabra"><label class="esc-td__sw"><input type="hidden" name="palabra" value="0" /><input type="checkbox" class="esc-sw" name="palabra" value="1"%1$s /><b>%2$s</b></label><p class="esc-f__h">%3$s</p></div>',
				checked( $palabra, true, false ),
				esc_html__( 'Aceptó de palabra', 'malka-crm' ),
				esc_html__( 'Las cuotas a las que les pongas fecha le aparecen en su portal como pendientes y las puede pagar, aunque todavía no firme. La firma le sigue apareciendo para que la complete.', 'malka-crm' )
			);
		}

		echo '</section>';

		return ob_get_clean();
	}

	/**
	 * El negocio (o los negocios) enlazado a esta cotización.
	 *
	 * @param array $d Cotización.
	 * @return string
	 */
	private static function block_negocios( $d ) {
		$qid  = (int) $d['id'];
		$pids = array();
		$pid  = (int) get_post_meta( $qid, '_malka_pm_project_id', true );
		if ( $pid && 'malka_project' === get_post_type( $pid ) && 'trash' !== get_post_status( $pid ) ) {
			$pids[] = $pid;
		}
		foreach ( get_posts(
			array(
				'post_type'      => 'malka_project',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => 10,
				'fields'         => 'ids',
				'meta_key'       => '_malka_pm_quote_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $qid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		) as $otro ) {
			$pids[] = (int) $otro;
		}
		$pids    = array_values( array_unique( $pids ) );
		$estados = Malka_CRM_Helpers::project_statuses();

		ob_start();
		echo '<section class="esc-panel esc-qe__neg"><div class="esc-ph"><div><h2>' . esc_html( count( $pids ) > 1 ? __( 'Negocios enlazados', 'malka-crm' ) : __( 'Negocio enlazado', 'malka-crm' ) ) . '</h2>';
		echo '<p>' . esc_html__( 'El trabajo que salió de esta cotización', 'malka-crm' ) . '</p></div></div>';

		if ( ! $pids ) {
			echo '<p class="esc-empty">' . esc_html__( 'Todavía no tiene negocio. Se crea solo cuando el cliente la acepta.', 'malka-crm' ) . '</p>';
			printf(
				'<a class="esc-btn esc-btn--soft" href="%1$s">+ %2$s</a>',
				esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'malka_crm_quote_negocio', 'quote' => $qid ), admin_url( 'admin-post.php' ) ), 'malka_crm_quote_negocio_' . $qid ) ),
				esc_html__( 'Crear el negocio ahora', 'malka-crm' )
			);
			echo '</section>';
			return ob_get_clean();
		}

		foreach ( $pids as $p ) {
			$st  = (string) get_post_meta( $p, '_malka_pm_status', true );
			$st  = isset( $estados[ $st ] ) ? $st : 'briefing';
			$av  = (int) Malka_CRM_Helpers::get_project_progress( $p );
			$num = (string) get_post_meta( $p, '_malka_pm_number', true );
			printf(
				'<a class="esc-qe__ng" href="%1$s"><span class="esc-qe__ngt"><b>%2$s</b><small>%3$s</small></span>' .
				'<span class="esc-st esc-st--%4$s">%5$s</span>' .
				'<span class="esc-qe__ngp"><span class="esc-bar"><i style="width:%6$d%%"></i></span><em>%6$d%%</em></span></a>',
				esc_url( Malka_CRM_Projects::edit_url( $p ) ),
				esc_html( get_the_title( $p ) ),
				esc_html( $num ? sprintf( /* translators: número */ __( 'Negocio %s', 'malka-crm' ), $num ) : __( 'Negocio', 'malka-crm' ) ),
				esc_attr( 'entregado' === $st ? 'done' : ( 'pausado' === $st ? 'off' : 'run' ) ),
				esc_html( $estados[ $st ] ),
				$av
			);
		}
		echo '</section>';
		return ob_get_clean();
	}

	/**
	 * Crea (o encuentra) el negocio de una cotización y lleva a él.
	 */
	public static function handle_negocio() {
		$qid = isset( $_GET['quote'] ) ? absint( $_GET['quote'] ) : 0;
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) || ! $qid ) {
			wp_die( esc_html__( 'No tienes permisos para esto.', 'malka-crm' ) );
		}
		check_admin_referer( 'malka_crm_quote_negocio_' . $qid );
		$pid = Malka_CRM_Quote_Page::asegurar_negocio( $qid );
		wp_safe_redirect( $pid ? Malka_CRM_Projects::edit_url( $pid ) : self::edit_url( $qid ) );
		exit;
	}

	/**
	 * Los clientes que ya existen, para proponérselos mientras escribe.
	 *
	 * @return array[]
	 */
	private static function fichas() {
		return class_exists( 'Malka_CRM_Clients' ) ? Malka_CRM_Clients::sugerencias() : array();
	}

	/**
	 * Los dos roles de una fila, en corto.
	 *
	 * `payment_roles()` los explica entero para quien los lee por primera
	 * vez; en una columna de siete rem esa frase no cabe y el `select` la
	 * corta justo donde está lo importante.
	 *
	 * @return array
	 */
	private static function roles_cortos() {
		return array(
			'cuota' => __( 'Cuota', 'malka-crm' ),
			'total' => __( 'Pago total', 'malka-crm' ),
		);
	}

	/**
	 * Las listas del autocompletado y el aviso de qué ficha se usó.
	 *
	 * Los datos van en un `<script type="application/json">` y no en atributos
	 * de cada opción: así el JS rellena los seis campos con un solo dato, y el
	 * `datalist` se queda con lo único que sabe hacer, que es proponer el
	 * nombre mientras escribe.
	 *
	 * @param array[] $fichas Clientes conocidos.
	 * @return string
	 */
	private static function bloque_fichas( $fichas ) {
		if ( ! $fichas ) {
			return '';
		}

		$nombres  = '';
		$empresas = '';
		$ya       = array();

		foreach ( $fichas as $c ) {
			$pie = trim( $c['empresa'] && $c['nombre'] ? $c['empresa'] : $c['rut'] );

			if ( '' !== trim( $c['nombre'] ) ) {
				$nombres .= sprintf(
					'<option value="%1$s"%2$s></option>',
					esc_attr( $c['nombre'] ),
					$pie ? ' label="' . esc_attr( $pie ) . '"' : ''
				);
			}

			$e = trim( (string) $c['empresa'] );

			if ( '' !== $e && ! isset( $ya[ $e ] ) ) {
				$ya[ $e ]  = true;
				$empresas .= '<option value="' . esc_attr( $e ) . '"></option>';
			}
		}

		ob_start();

		printf( '<datalist id="esc-ed-nombres">%s</datalist>', $nombres ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf( '<datalist id="esc-ed-empresas">%s</datalist>', $empresas ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		printf(
			'<script type="application/json" id="esc-ed-fichas">%s</script>',
			wp_json_encode( $fichas )
		);

		printf(
			'<p class="esc-ficha" id="esc-ficha" data-rotulo="%1$s" hidden><span class="esc-ficha__t"></span>' .
			'<button type="button" class="esc-ficha__x">%2$s</button></p>',
			/* translators: %s: nombre del cliente que ya estaba fichado. */
			esc_attr__( '%s ya estaba en tus clientes: completé el resto.', 'malka-crm' ),
			esc_html__( 'Deshacer', 'malka-crm' )
		);

		return ob_get_clean();
	}

	/**
	 * El repetidor de ítems y las notas.
	 *
	 * @param array $d Cotización.
	 * @return string
	 */
	private static function block_items( $d ) {
		ob_start();
		echo '<section class="esc-panel"><div class="esc-ph"><div><h2>' . esc_html__( 'Qué incluye', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Un ítem por servicio. El total se calcula solo.', 'malka-crm' ) . '</p></div></div>';

		echo '<div class="esc-items" id="esc-items">';

		$items = $d['items'] ? $d['items'] : array( array() );

		foreach ( array_values( $items ) as $i => $it ) {
			echo self::item_row( $i, $it ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '</div>';

		printf(
			'<div class="esc-row-btn"><button type="button" class="esc-btn esc-btn--soft" id="esc-item-add">+ %s</button></div>',
			esc_html__( 'Agregar ítem', 'malka-crm' )
		);

		printf(
			'<p class="esc-f esc-f--full esc-qe__notas"><label class="esc-f__l" for="f-notas">%1$s</label>' .
			'<textarea class="esc-f__i esc-f__t" id="f-notas" name="notas" rows="3">%2$s</textarea>' .
			'<span class="esc-f__h">%3$s</span></p>',
			esc_html__( 'Notas y observaciones', 'malka-crm' ),
			esc_textarea( $d['notas'] ),
			esc_html__( 'Una por línea. Salen en el recuadro gris de la cotización.', 'malka-crm' )
		);

		echo '</section>';

		return ob_get_clean();
	}

	/**
	 * Una fila del repetidor.
	 *
	 * @param int|string $i  Índice, o __i__ para la plantilla.
	 * @param array      $it Ítem.
	 * @return string
	 */
	public static function item_row( $i, $it = array() ) {
		$v = function ( $k, $def = '' ) use ( $it ) {
			return isset( $it[ $k ] ) ? $it[ $k ] : $def;
		};

		ob_start();
		echo '<div class="esc-item">';

		printf(
			'<input class="esc-item__t" type="text" name="item[%1$s][titulo]" value="%2$s" placeholder="%3$s" />',
			esc_attr( $i ),
			esc_attr( $v( 'titulo' ) ),
			esc_attr__( 'Servicio', 'malka-crm' )
		);

		printf(
			'<button type="button" class="esc-costo__x esc-item__x" aria-label="%s">&times;</button>',
			esc_attr__( 'Quitar este ítem', 'malka-crm' )
		);

		printf(
			'<textarea class="esc-item__d" name="item[%1$s][detalle]" rows="2" placeholder="%2$s">%3$s</textarea>',
			esc_attr( $i ),
			esc_attr__( 'Qué incluye, una línea por viñeta', 'malka-crm' ),
			esc_textarea( $v( 'detalle' ) )
		);

		echo '<div class="esc-item__n">';

		$numeros = array(
			'cantidad'  => array( __( 'Cantidad', 'malka-crm' ), $v( 'cantidad', 1 ), '' ),
			'unitario'  => array( __( 'Unitario', 'malka-crm' ), $v( 'unitario', 0 ), '$' ),
			'descuento' => array( __( 'Desc.', 'malka-crm' ), $v( 'descuento', 0 ), '%' ),
			'plazo'     => array( __( 'Días hábiles', 'malka-crm' ), $v( 'plazo', 0 ), '' ),
		);

		foreach ( $numeros as $k => $cfg ) {
			// El unitario es plata: se escribe y se ve con puntos de miles.
			$plata = ( 'unitario' === $k );
			printf(
				'<label class="esc-item__c esc-item__c--%4$s"><span>%1$s%2$s</span>' .
				'<input type="%6$s" step="any" inputmode="%7$s" name="item[%3$s][%4$s]" value="%5$s" data-k="%4$s"%8$s /></label>',
				esc_html( $cfg[0] ),
				$cfg[2] ? ' <i>' . esc_html( $cfg[2] ) . '</i>' : '',
				esc_attr( $i ),
				esc_attr( $k ),
				esc_attr( $plata ? number_format( (float) $cfg[1], 0, ',', '.' ) : (string) $cfg[1] ),
				$plata ? 'text' : 'number',
				$plata ? 'numeric' : 'decimal',
				$plata ? ' data-miles autocomplete="off"' : ''
			);
		}

		printf(
			'<span class="esc-item__s"><span>%s</span><b data-neto>%s</b></span>',
			esc_html__( 'Neto', 'malka-crm' ),
			esc_html( Malka_CRM_Settings::money( (int) $v( 'neto', 0 ) ) )
		);

		echo '</div></div>';

		return ob_get_clean();
	}

	/**
	 * Las cuotas y el estado.
	 *
	 * @param array $d     Cotización.
	 * @param bool  $nueva Si todavía no existe.
	 * @return string
	 */
	private static function block_pagos( $d, $nueva ) {
		$pct = (int) Malka_CRM_Settings::get( 'anticipo_pct', 60 );

		ob_start();
		echo '<section class="esc-panel"><div class="esc-ph"><div><h2>' . esc_html__( 'Cómo se paga', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'El saldo contra entrega no lleva fecha: la toma cuando marcas la entrega.', 'malka-crm' ) . '</p></div></div>';

		if ( $nueva && ! $d['pagos'] ) {
			echo '<p class="esc-empty">' . esc_html(
				sprintf(
					/* translators: 1: porcentaje del anticipo, 2: resto */
					__( 'Al guardar se arma solo: %1$d%% de anticipo y %2$d%% de saldo contra entrega. Después puedes cambiarlo o programar cuotas.', 'malka-crm' ),
					$pct,
					100 - $pct
				)
			) . '</p>';
			echo '</section>';
			return ob_get_clean();
		}

		echo '<div class="esc-cuotas" id="esc-cuotas">';
		echo '<div class="esc-cuota esc-cuota--h" aria-hidden="true">' .
			'<span>' . esc_html__( 'Concepto', 'malka-crm' ) . '</span>' .
			'<span>' . esc_html__( 'Monto', 'malka-crm' ) . '</span>' .
			'<span>' . esc_html__( 'Fecha', 'malka-crm' ) . '</span>' .
			'<span>' . esc_html__( 'El plazo corre', 'malka-crm' ) . '</span>' .
			'<span>' . esc_html__( 'Qué es', 'malka-crm' ) . '</span>' .
			'<span>' . esc_html__( 'Estado', 'malka-crm' ) . '</span>' .
			'<span>' . esc_html__( 'Pagado con', 'malka-crm' ) . '</span>' .
			'<span></span></div>';

		foreach ( array_values( $d['pagos'] ) as $i => $p ) {
			echo self::payment_row( $i, $p ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '</div>';

		printf(
			'<p class="esc-hint">%s</p>',
			esc_html__( '«Pago total» es la opción de pagarlo todo de una: se esconde apenas entra la primera cuota y no cuenta como deuda. Si es un saldo o un redondeo, déjala en «Cuota».', 'malka-crm' )
		);

		/*
		 * El cuadre, en vivo. Las cuotas rara vez suman el total a la
		 * primera —un descuento, un redondeo, una cuota que se movió— y
		 * hasta acá la diferencia había que sacarla a mano y a ojo.
		 *
		 * Los textos viajan en atributos porque los arma el navegador con
		 * los montos de cada momento, y traducirlos dentro del JS los
		 * dejaría fuera del archivo de idioma.
		 */
		printf(
			'<p class="esc-cuadre" id="esc-cuadre" hidden ' .
			'data-cuadra="%1$s" data-falta="%2$s" data-sobra="%3$s" data-pendiente="%4$s">' .
			'<span class="esc-cuadre__t"></span>' .
			'<button type="button" class="esc-mini" id="esc-cuadrar" hidden>%5$s</button></p>',
			/* translators: %s: suma de las cuotas */
			esc_attr__( 'Las cuotas suman %s y cuadran con el total.', 'malka-crm' ),
			/* translators: 1: suma de las cuotas, 2: total, 3: diferencia */
			esc_attr__( 'Las cuotas suman %1$s de %2$s: faltan %3$s por repartir.', 'malka-crm' ),
			/* translators: 1: suma de las cuotas, 2: total, 3: diferencia */
			esc_attr__( 'Las cuotas suman %1$s y el total es %2$s: se pasan por %3$s.', 'malka-crm' ),
			esc_attr__( 'Pendiente', 'malka-crm' ),
			esc_html__( 'Poner lo que falta', 'malka-crm' )
		);

		printf(
			'<div class="esc-row-btn"><button type="button" class="esc-btn esc-btn--soft" id="esc-cuota-add">+ %s</button></div>',
			esc_html__( 'Agregar cuota', 'malka-crm' )
		);

		/* ── Cuotas programadas: plegado, se usa de vez en cuando ── */
		printf( '<details class="esc-qe__mas"><summary>%s</summary>', esc_html__( 'Programar en cuotas mensuales o volver al plan de siempre', 'malka-crm' ) );
		echo '<div class="esc-dividir">';
		printf(
			'<label class="esc-dividir__c"><span>%1$s</span><input type="number" min="2" max="24" step="1" value="3" id="esc-cuotas-n" /></label>',
			esc_html__( 'Cuántas', 'malka-crm' )
		);
		printf(
			'<label class="esc-dividir__c"><span>%1$s</span><input type="date" value="%2$s" id="esc-cuotas-desde" /></label>',
			esc_html__( 'La primera vence', 'malka-crm' ),
			esc_attr( $d['vence'] )
		);
		printf(
			'<button type="button" class="esc-mini" id="esc-cuotas-dividir">%s</button>',
			esc_html__( 'Reemplazar las cuotas', 'malka-crm' )
		);
		printf(
			'<p class="esc-hint">%s</p>',
			esc_html__( 'Divide el total en cuotas iguales, una por mes. La última absorbe los pesos que sobran para que sumen exacto. Reemplaza lo que haya escrito arriba.', 'malka-crm' )
		);
		echo '</div>';

		printf(
			'<label class="esc-chk"><input type="checkbox" name="rehacer_plan" value="1" /> %s</label>',
			esc_html(
				sprintf(
					/* translators: 1: porcentaje del anticipo, 2: resto */
					__( 'Volver al plan %1$d/%2$d con el total de arriba', 'malka-crm' ),
					$pct,
					100 - $pct
				)
			)
		);
		echo '</details>';

		echo '</section>';

		return ob_get_clean();
	}

	/**
	 * Una cuota del plan de pago.
	 *
	 * @param int|string $i Índice, o __i__ para la plantilla del JS.
	 * @param array      $p Cuota.
	 * @return string
	 */
	public static function payment_row( $i, $p = array() ) {
		$v = function ( $k, $def = '' ) use ( $p ) {
			return isset( $p[ $k ] ) ? $p[ $k ] : $def;
		};

		$entrega = ( 'entrega' === $v( 'disparo', 'emision' ) );

		ob_start();
		echo '<div class="esc-cuota">';

		printf(
			'<input type="text" name="pago[%1$s][label]" value="%2$s" placeholder="%3$s" />',
			esc_attr( $i ),
			esc_attr( $v( 'label' ) ),
			esc_attr__( 'Concepto', 'malka-crm' )
		);

		printf(
			'<input type="text" inputmode="numeric" autocomplete="off" name="pago[%1$s][amount]" value="%2$s" data-monto data-miles />',
			esc_attr( $i ),
			esc_attr( number_format( round( (float) $v( 'amount', 0 ) ), 0, ',', '.' ) )
		);

		// Contra entrega la fecha no manda: la pone la entrega.
		printf(
			'<input type="date" name="pago[%1$s][due]" value="%2$s" data-fecha%3$s />',
			esc_attr( $i ),
			esc_attr( $v( 'due' ) ),
			$entrega ? ' disabled' : ''
		);

		printf( '<select name="pago[%s][disparo]" data-disparo>', esc_attr( $i ) );
		foreach ( Malka_CRM_Quotes::payment_triggers() as $k => $rotulo ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $k ),
				selected( $v( 'disparo', 'emision' ), $k, false ),
				esc_html( $rotulo )
			);
		}
		echo '</select>';

		/*
		 * Qué es la fila. Esto vivía en un campo oculto y era una trampa: una
		 * fila marcada «Pago total» desaparece de la cotización del cliente
		 * apenas entra una cuota, y no cuenta como deuda. Si la fila que trae
		 * ese rol se renombra y se le cambia el monto, el panel la sigue
		 * tratando como el pago total sin que nada lo diga en pantalla.
		 */
		printf(
			'<select name="pago[%1$s][rol]" aria-label="%2$s">',
			esc_attr( $i ),
			esc_attr__( 'Qué es esta fila', 'malka-crm' )
		);
		foreach ( self::roles_cortos() as $k => $rotulo ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $k ),
				selected( $v( 'rol', 'cuota' ), $k, false ),
				esc_html( $rotulo )
			);
		}
		echo '</select>';

		printf( '<select name="pago[%s][status]">', esc_attr( $i ) );
		foreach ( Malka_CRM_Quotes::payment_statuses() as $k => $rotulo ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $k ),
				selected( $v( 'status', 'espera' ), $k, false ),
				esc_html( $rotulo )
			);
		}
		echo '</select>';

		/*
		 * Con qué se pagó. El panel lo anota solo cuando el cliente elige
		 * medio en su página, y acá ella lo corrige o lo pone a mano —el
		 * efectivo, por ejemplo, no deja rastro en ninguna parte—.
		 */
		$metodo = (string) $v( 'method' );

		printf(
			'<select name="pago[%1$s][method]" aria-label="%2$s" data-metodo>',
			esc_attr( $i ),
			esc_attr__( 'Con qué se pagó', 'malka-crm' )
		);
		foreach ( Malka_CRM_Quotes::payment_methods() as $k => $rotulo ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $k ),
				selected( $metodo, $k, false ),
				esc_html( $rotulo )
			);
		}
		echo '</select>';

		printf(
			'<button type="button" class="esc-costo__x esc-cuota__x" aria-label="%s">&times;</button>',
			esc_attr__( 'Quitar esta cuota', 'malka-crm' )
		);

		/*
		 * Dos datos que el formulario no edita pero sí tiene que devolver:
		 * sin ellos, `sanitize_payments` los reescribe vacíos y guardar la
		 * cotización borraba el link de pago y de dónde salió el método.
		 */
		printf(
			'<input type="hidden" name="pago[%1$s][method_auto]" value="%2$s" data-metodo-auto />',
			esc_attr( $i ),
			empty( $p['method_auto'] ) ? '0' : '1'
		);
		printf(
			'<input type="hidden" name="pago[%1$s][pay_url]" value="%2$s" />',
			esc_attr( $i ),
			esc_url( (string) $v( 'pay_url' ) )
		);

		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * El interruptor de avisos y lo que calla por su cuenta.
	 *
	 * @param array $d     Cotización.
	 * @param bool  $nueva Si todavía no existe.
	 * @return string
	 */
	private static function block_avisos( $d, $nueva ) {
		$encendido = $nueva
			? (bool) Malka_CRM_Settings::get( 'avisos_default', 1 )
			: Malka_CRM_Reminders::enabled( $d['id'] );

		ob_start();
		echo '<div class="esc-avisos">';

		printf(
			'<div class="esc-ph"><div><h2>%2$s</h2></div><input type="checkbox" class="esc-sw" name="avisos" value="1"%1$s aria-label="%2$s" /></div>',
			$encendido ? ' checked' : '',
			esc_html__( 'Avisos automáticos', 'malka-crm' )
		);

		printf(
			'<p class="esc-hint">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: días de aviso previo, 2: días entre recordatorios */
					__( 'Le avisamos %1$d días antes de cada vencimiento, le recordamos cada %2$d días si se atrasa y le contamos cuando marcas la entrega. Apagado no le llega nada.', 'malka-crm' ),
					(int) Malka_CRM_Settings::get( 'mora_aviso_prev', 3 ),
					(int) Malka_CRM_Settings::get( 'mora_recordar', 7 )
				)
			)
		);

		// Lo que el panel calla por su cuenta, dicho sin que haya que confiar.
		$desde = $nueva ? current_time( 'Y-m-d' ) : Malka_CRM_Reminders::since( $d['id'] );

		if ( $desde ) {
			printf(
				'<p class="esc-hint esc-avisos__silencio">%s</p>',
				esc_html(
					sprintf(
						/* translators: fecha en que se cargó la cotización */
						__( 'Las cuotas que vencieron antes del %s (cuando la cargaste al panel) no se avisan.', 'malka-crm' ),
						date_i18n( 'd/m/Y', strtotime( $desde ) )
					)
				)
			);
		}

		$pausa = Malka_CRM_Reminders::paused_until();

		if ( $pausa ) {
			printf(
				'<p class="esc-hint is-alert">%s</p>',
				esc_html(
					sprintf(
						/* translators: fecha de fin de la pausa */
						__( 'Ahora mismo no sale ningún aviso: están pausados hasta el %s en Configuración.', 'malka-crm' ),
						date_i18n( 'd/m/Y', strtotime( $pausa ) )
					)
				)
			);
		}

		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * La barra de abajo: estado, guardar y el link al cliente.
	 *
	 * @param array $d     Cotización.
	 * @param bool  $nueva Si todavía no existe.
	 * @return string
	 */
	private static function block_barra( $d, $nueva ) {
		ob_start();
		echo '<div class="esc-cfg__save esc-ed__save">';

		echo '<span class="esc-ed__meta">';
		if ( $nueva ) {
			printf(
				/* translators: número que se asignará */
				esc_html__( 'Va a quedar como la N° %s', 'malka-crm' ),
				esc_html( (string) Malka_CRM_Quote_Page::next_number() )
			);
		} else {
			printf(
				'<b>%1$s</b> · <a href="%2$s" target="_blank" rel="noopener">%3$s</a>',
				esc_html( $d['numero'] ? 'N° ' . $d['numero'] : __( 'Sin número', 'malka-crm' ) ),
				esc_url( $d['url'] ),
				esc_html__( 'Ver la página del cliente', 'malka-crm' )
			);
		}
		echo '</span>';

		printf(
			'<a class="esc-btn" href="%s">%s</a>',
			esc_url( Malka_CRM_Dashboard::view_url( 'cotizaciones' ) ),
			esc_html__( 'Cancelar', 'malka-crm' )
		);

		printf(
			'<button type="submit" class="esc-btn esc-btn--primary" name="seguir" value="1">%s</button>',
			esc_html( $nueva ? __( 'Crear cotización', 'malka-crm' ) : __( 'Guardar', 'malka-crm' ) )
		);

		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * Un campo del formulario.
	 *
	 * @param string $nombre   Name.
	 * @param string $etiqueta Rótulo.
	 * @param string $valor    Valor.
	 * @param string $tipo     text|email|date.
	 * @param string $ayuda    Texto de apoyo.
	 * @param string $extra    Atributos sueltos para el input, ya escapados.
	 * @return string
	 */
	private static function campo( $nombre, $etiqueta, $valor, $tipo = 'text', $ayuda = '', $extra = '' ) {
		return sprintf(
			'<p class="esc-f"><label class="esc-f__l" for="f-%1$s">%2$s</label>' .
			'<span class="esc-f__w"><input class="esc-f__i" type="%3$s" id="f-%1$s" name="%1$s" value="%4$s"%6$s /></span>%5$s</p>',
			esc_attr( $nombre ),
			esc_html( $etiqueta ),
			esc_attr( $tipo ),
			esc_attr( (string) $valor ),
			$ayuda ? '<span class="esc-f__h">' . esc_html( $ayuda ) . '</span>' : '',
			$extra ? ' ' . $extra : ''
		);
	}
}
