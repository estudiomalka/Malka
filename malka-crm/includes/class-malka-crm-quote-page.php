<?php
/**
 * La cotización como página propia: estudiomalka.cl/cotizacion-608
 *
 * Hasta ahora la cotización era una página estática que había que crear a mano
 * copiando HTML. Acá deja de serlo: el número se asigna solo, la URL existe
 * desde que se guarda, y lo que el cliente ve sale de la base de datos.
 *
 * El permiso de edición también cambia de naturaleza. Antes bastaba con poner
 * #editar en la URL; ahora edita quien WordPress reconoce, y nadie más.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

class Malka_CRM_Quote_Page {

	/**
	 * La cotización se aceptó de palabra: se cobra aunque falte la firma.
	 * Guarda la fecha en que se marcó.
	 */
	const META_PALABRA = '_malka_pm_quote_palabra';


	/** Prefijo de la URL pública. */
	const SLUG = 'cotizacion';

	/** Desde dónde sigue la numeración si no hay ninguna guardada. */
	const OPT_DESDE = 'malka_crm_quote_desde';

	/** Meta donde vive el número. */
	const META_NUM = '_malka_pm_quote_number';

	/** Fecha en que el proyecto quedó entregado y aprobado. */
	const META_ENTREGA = '_malka_pm_quote_delivered';

	public function __construct() {
		add_action( 'init', array( __CLASS__, 'add_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );

		// Al guardar una cotización sin número, se le pone el que toca.
		add_action( 'save_post_' . Malka_CRM_Quotes::POST_TYPE, array( __CLASS__, 'assign_number' ), 20, 3 );

		// La firma y el pago los inicia el cliente, que no tiene sesión.
		add_action( 'admin_post_nopriv_malka_crm_sign_quote', array( $this, 'handle_sign' ) );
		add_action( 'admin_post_malka_crm_sign_quote', array( $this, 'handle_sign' ) );
		add_action( 'admin_post_nopriv_malka_crm_pay', array( $this, 'handle_pay' ) );
		add_action( 'admin_post_malka_crm_pay', array( $this, 'handle_pay' ) );
		add_action( 'admin_post_nopriv_malka_crm_pago_avisado', array( $this, 'handle_pago_avisado' ) );
		add_action( 'admin_post_malka_crm_pago_avisado', array( $this, 'handle_pago_avisado' ) );

		// La entrega la marca ella, con sesión iniciada.
		add_action( 'admin_post_malka_crm_entrega', array( $this, 'handle_delivered' ) );

		// Avisarle a Malka cuando alguien firma.
		add_action( 'malka_crm_quote_signed', array( __CLASS__, 'notify_signature' ), 10, 2 );
		add_action( 'malka_crm_pago_avisado', array( __CLASS__, 'notify_pago_avisado' ), 10, 3 );

		// Los estados se mueven solos.
		add_action( 'malka_crm_daily', array( __CLASS__, 'sync_all' ) );
		add_action( 'init', array( __CLASS__, 'schedule_sync' ) );
	}

	/**
	 * Deja programada la puesta al día diaria.
	 */
	public static function schedule_sync() {
		if ( ! wp_next_scheduled( 'malka_crm_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'malka_crm_daily' );
		}
	}

	/* ─────────────────────────────  Estados  ──────────────────────────── */

	/**
	 * Pone al día el estado comercial de una cotización.
	 *
	 * La vigencia (vigente o caducada) nunca se guarda: se calcula comparando
	 * la fecha, así no puede quedar desfasada. El estado comercial sí se
	 * guarda, y es el que se mueve solo según lo que vaya pasando:
	 *
	 *   firma del cliente   → aceptada
	 *   primera cuota pagada → en curso
	 *   proyecto entregado y saldo pendiente → entregado
	 *   todas las cuotas pagadas → finalizada
	 *   venció sin respuesta → caducada
	 *
	 * «Entregado» se pone por delante de «en curso» a propósito: las dos
	 * dicen que el trabajo avanza, pero sólo una avisa que hay un saldo con
	 * el reloj corriendo, que es lo que ella necesita ver en la lista.
	 *
	 * Lo que Malka haya puesto a mano manda: si marcó rechazada o finalizada,
	 * no se le cambia por debajo.
	 *
	 * @param int $qid Cotización.
	 * @return string El estado que quedó.
	 */
	public static function sync_status( $qid ) {
		$actual = (string) get_post_meta( $qid, '_malka_pm_quote_status', true );

		// Decisiones de ella: no se tocan.
		if ( in_array( $actual, array( 'rechazada', 'finalizada' ), true ) ) {
			return $actual;
		}

		$pagos   = Malka_CRM_Quotes::get_payments( $qid );
		$cuotas  = 0;
		$pagadas = 0;
		$total   = false;

		foreach ( $pagos as $p ) {
			$es_total = isset( $p['rol'] ) && 'total' === $p['rol'];
			$pagada   = isset( $p['status'] ) && 'pagado' === $p['status'];

			if ( $es_total ) {
				// El pago total no es una cuota más: si está pagado, no queda
				// nada por cobrar; si no, no cuenta como deuda aparte.
				$total = $total || $pagada;
				continue;
			}

			$cuotas++;

			if ( $pagada ) {
				$pagadas++;
			}
		}

		$nuevo = $actual ? $actual : 'sin_respuesta';

		// Aceptada de palabra: ya no está caducada, espera la firma.
		if ( 'caducada' === $nuevo && get_post_meta( $qid, self::META_PALABRA, true ) ) {
			$nuevo = 'sin_respuesta';
		}

		/*
		 * ¿Queda algo por cobrar?
		 *
		 * Esto miraba sólo las cuotas marcadas «contra entrega», y por eso un
		 * proyecto entregado con todas sus cuotas a fecha fija caía en «en
		 * curso» por mucho que la entrega estuviera marcada. Entregar es un
		 * hecho aparte de cuándo vence cada cuota: lo que decide entre
		 * «entregado» y «finalizada» es si queda saldo, no de qué tipo es.
		 */
		$saldo_vivo = false;

		foreach ( $pagos as $p ) {
			if ( isset( $p['rol'] ) && 'total' === $p['rol'] ) {
				continue;
			}

			if ( ! isset( $p['status'] ) || 'pagado' !== $p['status'] ) {
				$saldo_vivo = true;
				break;
			}
		}

		$entregado = (bool) get_post_meta( $qid, self::META_ENTREGA, true );

		if ( $total || ( $cuotas > 0 && $pagadas === $cuotas ) ) {
			$nuevo = 'finalizada';
		} elseif ( $entregado && $saldo_vivo ) {
			$nuevo = 'entregado';
		} elseif ( $pagadas > 0 ) {
			$nuevo = 'en_curso';
		} else {
			$firma = get_post_meta( $qid, '_malka_pm_quote_sign', true );

			if ( is_array( $firma ) && ! empty( $firma['rut'] ) ) {
				$nuevo = 'aceptada';
			} else {
				// Sin firma y pasada de fecha: caducó.
				$vence = (string) get_post_meta( $qid, '_malka_pm_quote_expires', true );

				if ( $vence && strtotime( $vence ) < strtotime( current_time( 'Y-m-d' ) ) && 'sin_respuesta' === $nuevo && ! get_post_meta( $qid, self::META_PALABRA, true ) ) {
					$nuevo = 'caducada';
				}
			}
		}

		if ( $nuevo !== $actual ) {
			update_post_meta( $qid, '_malka_pm_quote_status', $nuevo );
		}

		// Una cotización ganada es un proyecto: si no tiene negocio, se crea.
		if ( in_array( $nuevo, array( 'aceptada', 'en_curso', 'entregado', 'finalizada' ), true ) ) {
			self::asegurar_negocio( $qid );
		}

		return $nuevo;
	}

	/**
	 * El negocio de una cotización ganada, creándolo si no existe.
	 *
	 * Hasta acá el negocio había que crearlo a mano y enlazarlo, y si se
	 * olvidaba, el proyecto no aparecía en el inicio, ni en el plan del día,
	 * ni en las tareas: el panel creía que no había trabajo que hacer.
	 *
	 * @param int $qid Cotización.
	 * @return int ID del negocio, o 0 si no se pudo.
	 */
	public static function asegurar_negocio( $qid ) {
		$pid = (int) get_post_meta( $qid, '_malka_pm_project_id', true );

		// Si ya tiene uno y sigue existiendo, no se toca.
		if ( $pid ) {
			$p = get_post( $pid );

			if ( $p && 'malka_project' === $p->post_type && 'trash' !== $p->post_status ) {
				return self::el_bueno( $qid, $pid );
			}
		}

		/*
		 * Antes de crear, buscar uno que ya sea de esta cotización: enlazado
		 * sólo desde el negocio, o hecho a mano con el mismo número y sin
		 * otra cotización. Así no se duplica.
		 */
		$ya = get_posts(
			array(
				'post_type'      => 'malka_project',
				'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_malka_pm_quote_id',
						'value' => (int) $qid,
					),
				),
			)
		);
		$num = (string) get_post_meta( $qid, self::META_NUM, true );
		if ( ! $ya && '' !== $num ) {
			foreach ( get_posts(
				array(
					'post_type'      => 'malka_project',
					'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future' ),
					'posts_per_page' => 5,
					'fields'         => 'ids',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'   => '_malka_pm_number',
							'value' => $num,
						),
					),
				)
			) as $cand ) {
				$otra = (int) get_post_meta( $cand, '_malka_pm_quote_id', true );
				if ( ! $otra || $otra === (int) $qid ) {
					$ya = array( $cand );
					break;
				}
			}
		}
		if ( $ya ) {
			update_post_meta( $qid, '_malka_pm_project_id', (int) $ya[0] );
			update_post_meta( (int) $ya[0], '_malka_pm_quote_id', (int) $qid );
			return (int) $ya[0];
		}

		$titulo = get_the_title( $qid );

		if ( '' === trim( (string) $titulo ) ) {
			$titulo = sprintf(
				/* translators: número de la cotización */
				__( 'Proyecto de la cotización N° %s', 'malka-crm' ),
				(string) get_post_meta( $qid, self::META_NUM, true )
			);
		}

		$nuevo = wp_insert_post(
			array(
				'post_type'   => 'malka_project',
				'post_title'  => $titulo,
				'post_status' => 'publish',
			),
			true
		);

		if ( is_wp_error( $nuevo ) ) {
			return 0;
		}

		// Lo que ya sabemos del cliente viaja con él.
		$empresa = (string) get_post_meta( $qid, '_malka_pm_client_company', true );
		$persona = (string) get_post_meta( $qid, '_malka_pm_client_name', true );

		update_post_meta( $nuevo, '_malka_pm_client_name', $empresa ? $empresa : $persona );
		update_post_meta( $nuevo, '_malka_pm_number', (string) get_post_meta( $qid, self::META_NUM, true ) );
		update_post_meta( $nuevo, '_malka_pm_status', 'briefing' );
		update_post_meta( $nuevo, '_malka_pm_start', current_time( 'Y-m-d' ) );

		/*
		 * La fecha de entrega sale del plazo más largo de los ítems cotizados,
		 * contado en días hábiles desde hoy. Es una estimación que ella puede
		 * corregir, pero es la que el cliente ya leyó en la cotización.
		 */
		$plazo = 0;

		foreach ( (array) get_post_meta( $qid, '_malka_pm_quote_items', true ) as $it ) {
			if ( isset( $it['plazo'] ) ) {
				$plazo = max( $plazo, (int) $it['plazo'] );
			}
		}

		if ( $plazo > 0 ) {
			update_post_meta( $nuevo, '_malka_pm_deadline', Malka_CRM_Helpers::add_days( current_time( 'Y-m-d' ), $plazo ) );
		}

		// Hecho solo: si después aparece uno armado a mano, este es el que sobra.
		update_post_meta( $nuevo, '_malka_pm_auto', 1 );

		// El enlace va en los dos sentidos.
		update_post_meta( $qid, '_malka_pm_project_id', (int) $nuevo );
		update_post_meta( $nuevo, '_malka_pm_quote_id', (int) $qid );

		/**
		 * Se creó un negocio solo, a partir de una cotización ganada.
		 *
		 * @param int $nuevo Negocio.
		 * @param int $qid   Cotización.
		 */
		do_action( 'malka_crm_negocio_desde_cotizacion', (int) $nuevo, (int) $qid );

		return (int) $nuevo;
	}

	/**
	 * Pone al día todas las cotizaciones abiertas.
	 *
	 * Corre una vez al día: es lo que hace que una cotización vencida aparezca
	 * como caducada sin que nadie la abra.
	 */
	public static function sync_all() {
		self::asegurar_negocios();

		$ids = get_posts(
			array(
				'post_type'      => Malka_CRM_Quotes::POST_TYPE,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 300,
				'fields'         => 'ids',
			)
		);

		foreach ( $ids as $id ) {
			self::sync_status( $id );
		}
	}

	/**
	 * Si una cotización tiene más de un negocio (uno hecho a mano y otro
	 * creado solo), se queda con el que de verdad se trabaja y manda el
	 * vacío a la papelera. Vacío es: sin tareas avanzadas, sin facturas y
	 * en «Briefing». Lo de la papelera se puede recuperar.
	 *
	 * @param int $qid Cotización.
	 * @param int $pid El negocio que hoy dice la cotización.
	 * @return int El que queda.
	 */
	public static function el_bueno( $qid, $pid ) {
		$otros = get_posts(
			array(
				'post_type'      => 'malka_project',
				'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'posts_per_page' => 10,
				'fields'         => 'ids',
				'post__not_in'   => array( (int) $pid ),
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_malka_pm_quote_id',
						'value' => (int) $qid,
					),
				),
			)
		);
		if ( ! $otros ) {
			return (int) $pid;
		}
		$todos = array_merge( array( (int) $pid ), array_map( 'intval', $otros ) );
		$peso  = function ( $id ) {
			$p = 0;
			foreach ( (array) get_post_meta( $id, '_malka_pm_tasks', true ) as $t ) {
				if ( is_array( $t ) ) {
					$p += 1 + ( ! empty( $t['done'] ) || ( isset( $t['progress'] ) && (int) $t['progress'] > 0 ) ? 5 : 0 );
				}
			}
			if ( 'briefing' !== (string) get_post_meta( $id, '_malka_pm_status', true ) ) {
				$p += 20;
			}
			if ( class_exists( 'Malka_CRM_Documents' ) ) {
				$p += 10 * count(
					get_posts(
						array(
							'post_type'      => Malka_CRM_Documents::POST_TYPE,
							'posts_per_page' => 5,
							'fields'         => 'ids',
							'meta_key'       => Malka_CRM_Documents::META_PROYECTO, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
							'meta_value'     => (int) $id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
						)
					)
				);
			}
			// Lo creado solo pesa menos: a igualdad, gana el armado a mano.
			return $p - ( get_post_meta( $id, '_malka_pm_auto', true ) ? 1 : 0 );
		};
		usort(
			$todos,
			function ( $a, $b ) use ( $peso ) {
				return $peso( $b ) <=> $peso( $a );
			}
		);
		$bueno = $todos[0];
		foreach ( array_slice( $todos, 1 ) as $id ) {
			$tareas = (array) get_post_meta( $id, '_malka_pm_tasks', true );
			$vacio  = 'briefing' === (string) get_post_meta( $id, '_malka_pm_status', true );
			foreach ( $tareas as $t ) {
				if ( is_array( $t ) && ( ! empty( $t['done'] ) || ( isset( $t['progress'] ) && (int) $t['progress'] > 0 ) ) ) {
					$vacio = false;
				}
			}
			if ( $vacio ) {
				// Sus tareas (si tenía) no se pierden: pasan al que queda si ése no tiene.
				if ( $tareas && ! get_post_meta( $bueno, '_malka_pm_tasks', true ) ) {
					update_post_meta( $bueno, '_malka_pm_tasks', $tareas );
				}
				delete_post_meta( $id, '_malka_pm_quote_id' );
				wp_trash_post( $id );
			}
		}
		update_post_meta( $qid, '_malka_pm_project_id', (int) $bueno );
		update_post_meta( (int) $bueno, '_malka_pm_quote_id', (int) $qid );
		return (int) $bueno;
	}

	/**
	 * Toda cotización aceptada (o firmada) tiene su negocio, enlazado en
	 * los dos sentidos. Las que no lo tengan, se les crea; las que tienen
	 * uno, se dejan como están.
	 *
	 * @return int Cuántos negocios se crearon o enlazaron.
	 */
	public static function asegurar_negocios() {
		$n = 0;
		foreach ( get_posts(
			array(
				'post_type'      => Malka_CRM_Quotes::POST_TYPE,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		) as $qid ) {
			$estado  = (string) get_post_meta( $qid, '_malka_pm_quote_status', true );
			$firma   = get_post_meta( $qid, '_malka_pm_quote_sign', true );
			$ganada  = in_array( $estado, array( 'aceptada', 'en_curso', 'entregado', 'finalizada' ), true )
				|| ( is_array( $firma ) && ! empty( $firma['rut'] ) && ! in_array( $estado, array( 'rechazada' ), true ) );
			if ( ! $ganada ) {
				continue;
			}
			$antes = (int) get_post_meta( $qid, '_malka_pm_project_id', true );
			$pid   = self::asegurar_negocio( $qid );
			if ( $pid && $pid !== $antes ) {
				$n++;
			}
		}
		return $n;
	}

	/* ──────────────────────  Plazos de cada cuota  ─────────────────────── */

	/**
	 * Cuándo vence una cuota, de verdad.
	 *
	 * No todas las cuotas vencen por el mismo reloj. El anticipo y el pago
	 * total vencen con la cotización: son la condición para empezar, y si
	 * nadie paga en esos días la cotización caduca y se acabó. El saldo no
	 * puede funcionar así, porque el cliente todavía no tiene nada que pagar:
	 * su plazo recién empieza cuando el proyecto está entregado, y desde ahí
	 * corren los días hábiles que ella haya definido.
	 *
	 * Mientras el proyecto no esté entregado, el saldo no tiene fecha. No es
	 * que venza muy lejos: es que todavía no empieza a correr, y por eso no
	 * puede estar atrasado ni generar interés.
	 *
	 * @param int   $qid Cotización.
	 * @param array $p   Cuota.
	 * @return array {
	 *     @type string $fecha  Y-m-d, o '' si todavía no corre.
	 *     @type bool   $espera Si está esperando la entrega.
	 *     @type string $desde  Y-m-d de la entrega, si la hay.
	 * }
	 */
	public static function payment_due( $qid, $p ) {
		$disparo = isset( $p['disparo'] ) ? $p['disparo'] : 'emision';

		if ( 'entrega' !== $disparo ) {
			// Su propia fecha si la tiene; si no, la vigencia de la cotización.
			$fecha = ! empty( $p['due'] )
				? $p['due']
				: (string) get_post_meta( $qid, '_malka_pm_quote_expires', true );

			return array(
				'fecha'  => $fecha,
				'espera' => false,
				'desde'  => '',
			);
		}

		$entrega = (string) get_post_meta( $qid, self::META_ENTREGA, true );

		if ( ! $entrega ) {
			return array(
				'fecha'  => '',
				'espera' => true,
				'desde'  => '',
			);
		}

		$habiles = (int) Malka_CRM_Settings::get( 'saldo_habiles', 1 );

		return array(
			'fecha'  => Malka_CRM_Settings::sumar_habiles( $entrega, $habiles ),
			'espera' => false,
			'desde'  => $entrega,
		);
	}

	/**
	 * El estado de una cuota con su plazo ya resuelto.
	 *
	 * Envuelve a Quotes::payment_state() en vez de duplicarla: le pasa la
	 * cuota con la fecha que de verdad corresponde, así los rótulos de
	 * atraso y de aviso siguen saliendo de un solo lugar.
	 *
	 * @param int   $qid Cotización.
	 * @param array $p   Cuota.
	 * @return array {state, label, days, fecha, espera}
	 */
	public static function payment_view( $qid, $p ) {
		$pagada = isset( $p['status'] ) && 'pagado' === $p['status'];

		/*
		 * Si ella la dio por perdida, viaja dicho acá y nada más: el estado,
		 * la fecha y el monto siguen siendo los mismos. Lo que mira el
		 * cliente —su cotización, su portal, sus correos— no lee esta clave,
		 * así que para él no cambia nada; lo que la lee son las pantallas de
		 * cobranza de ella, para dejar de insistirle con algo que ya decidió
		 * no perseguir.
		 */
		$perdida = ! $pagada
			&& class_exists( 'Malka_CRM_Losses' )
			&& isset( $p['id'] )
			&& Malka_CRM_Losses::es( $qid, (string) $p['id'] );

		if ( $pagada ) {
			$e            = Malka_CRM_Quotes::payment_state( $p );
			$e['fecha']   = isset( $p['due'] ) ? $p['due'] : '';
			$e['espera']  = false;
			$e['perdida'] = false;
			return $e;
		}

		$plazo = self::payment_due( $qid, $p );

		if ( $plazo['espera'] ) {
			return array(
				'state'   => 'waiting',
				'label'   => __( 'Se activa al entregar el proyecto', 'malka-crm' ),
				'days'    => 0,
				'fecha'   => '',
				'espera'  => true,
				'avisado' => false,
				'perdida' => $perdida,
			);
		}

		$copia        = $p;
		$copia['due'] = $plazo['fecha'];

		$e            = Malka_CRM_Quotes::payment_state( $copia );
		$e['fecha']   = $plazo['fecha'];
		$e['espera']  = false;
		$e['perdida'] = $perdida;

		return $e;
	}

	/**
	 * Marca el proyecto como entregado y le avisa al cliente.
	 *
	 * Es el momento en que el saldo empieza a correr, así que no puede ser un
	 * cambio silencioso: el cliente tiene que enterarse el mismo día de que
	 * le quedan N días hábiles, o el interés le llegaría de sorpresa.
	 */
	public function handle_delivered() {
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'No tienes permisos para esto.', 'malka-crm' ) );
		}

		$qid = isset( $_GET['quote'] ) ? (int) $_GET['quote'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		check_admin_referer( 'malka_crm_entrega_' . $qid );

		if ( ! $qid || Malka_CRM_Quotes::POST_TYPE !== get_post_type( $qid ) ) {
			wp_die( esc_html__( 'Esa cotización no existe.', 'malka-crm' ) );
		}

		$ya = (string) get_post_meta( $qid, self::META_ENTREGA, true );

		if ( $ya ) {
			// Se marcó por error: se deshace y el saldo vuelve a esperar.
			delete_post_meta( $qid, self::META_ENTREGA );
		} else {
			$hoy = current_time( 'Y-m-d' );
			update_post_meta( $qid, self::META_ENTREGA, $hoy );
			self::notify_delivery( $qid, $hoy );
		}

		self::sync_status( $qid );

		$volver = isset( $_GET['volver'] ) ? esc_url_raw( wp_unslash( $_GET['volver'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		wp_safe_redirect(
			$volver ? $volver : Malka_CRM_Dashboard::view_url( 'cotizaciones' )
		);
		exit;
	}

	/**
	 * Pone o quita la fecha de entrega según el estado que ella eligió.
	 *
	 * «Entregado» no es una etiqueta que se pegue encima: es una fecha, y de
	 * esa fecha salen el estado, el plazo del saldo y el aviso al cliente.
	 * Elegirlo en el selector y no tocar la fecha dejaba la elección sin
	 * efecto —`sync_status` recalculaba desde los hechos y la borraba— sin
	 * que nada lo dijera.
	 *
	 * Sólo «entregado» marca y sólo los estados anteriores desmarcan:
	 * «finalizada», «rechazada» y «caducada» no dicen nada sobre si el
	 * trabajo se entregó, así que no tocan la fecha.
	 *
	 * @param int    $qid    Cotización.
	 * @param string $estado Estado elegido en el editor.
	 * @return string '' si no cambió nada, 'marcada' o 'desmarcada'.
	 */
	public static function fijar_entrega( $qid, $estado ) {
		$ya = (string) get_post_meta( $qid, self::META_ENTREGA, true );

		if ( 'entregado' === $estado ) {
			if ( $ya ) {
				return '';
			}

			$hoy = current_time( 'Y-m-d' );
			update_post_meta( $qid, self::META_ENTREGA, $hoy );
			self::notify_delivery( $qid, $hoy );

			return 'marcada';
		}

		if ( in_array( $estado, array( 'sin_respuesta', 'aceptada', 'en_curso' ), true ) && $ya ) {
			delete_post_meta( $qid, self::META_ENTREGA );

			return 'desmarcada';
		}

		return '';
	}

	/**
	 * Le avisa al cliente que el proyecto está listo y hasta cuándo tiene.
	 *
	 * @param int    $qid     Cotización.
	 * @param string $entrega Y-m-d.
	 * @return bool Si se pudo enviar.
	 */
	public static function notify_delivery( $qid, $entrega ) {
		$d = self::data( $qid );

		if ( empty( $d['cliente']['email'] ) ) {
			return false;
		}

		// La misma llave que los avisos de cobro: si esta cotización tiene los
		// avisos apagados, o hay una pausa general, no se le escribe.
		if ( class_exists( 'Malka_CRM_Reminders' ) && ! Malka_CRM_Reminders::puede_avisar( $qid ) ) {
			return false;
		}

		$habiles = (int) Malka_CRM_Settings::get( 'saldo_habiles', 1 );
		$vence   = Malka_CRM_Settings::sumar_habiles( $entrega, $habiles );
		$saldo   = 0;

		foreach ( $d['pagos'] as $p ) {
			if ( isset( $p['disparo'] ) && 'entrega' === $p['disparo']
				&& ( ! isset( $p['status'] ) || 'pagado' !== $p['status'] ) ) {
				$saldo += (int) $p['amount'];
			}
		}

		if ( ! class_exists( 'Malka_CRM_Messages' ) ) {
			return false;
		}

		$datos = array(
			'cliente'  => $d['cliente']['nombre'] ? $d['cliente']['nombre'] : __( 'estimado', 'malka-crm' ),
			'numero'   => $d['numero'],
			'proyecto' => $d['titulo'] ? $d['titulo'] : __( 'Tu proyecto', 'malka-crm' ),
			'fecha'    => $entrega ? date_i18n( 'j \\d\\e F', strtotime( $entrega ) ) : '',
			'monto'    => $saldo > 0 ? Malka_CRM_Settings::money( $saldo ) : '',
			'vence'    => ( $saldo > 0 && $vence ) ? date_i18n( 'j \\d\\e F', strtotime( $vence ) ) : '',
			'plazo'    => $saldo > 0 ? sprintf(
				/* translators: días hábiles */
				_n( '%d día hábil', '%d días hábiles', $habiles, 'malka-crm' ),
				$habiles
			) : '',
			'enlace'   => $saldo > 0 ? $d['url'] : '',
			'estudio'  => Malka_CRM_Messages::estudio(),
		);

		return Malka_CRM_Messages::enviar( $d['cliente']['email'], 'entrega', $datos );
	}

	/**
	 * URL del botón de entrega.
	 *
	 * @param int    $qid    Cotización.
	 * @param string $volver A dónde regresar.
	 * @return string
	 */
	public static function delivered_link( $qid, $volver = '' ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'malka_crm_entrega',
					'quote'  => (int) $qid,
					'volver' => $volver,
				),
				admin_url( 'admin-post.php' )
			),
			'malka_crm_entrega_' . (int) $qid
		);
	}

	/* ─────────────────────────────  Pago  ─────────────────────────────── */

	/**
	 * Lleva al cliente a pagar la cuota, con el monto de hoy.
	 */
	/**
	 * ¿El pago total dejó de tener sentido?
	 *
	 * «Pago total» es un atajo para pagar todo de una, no una cuota más. En
	 * cuanto entra el anticipo, ofrecerlo es ofrecerle al cliente que pague
	 * dos veces lo mismo, así que desaparece.
	 *
	 * @param array $pagos Todas las cuotas.
	 * @param array $p     La cuota en cuestión.
	 * @return bool
	 */
	public static function total_cerrado( $pagos, $p ) {
		if ( ! isset( $p['rol'] ) || 'total' !== $p['rol'] ) {
			return false;
		}

		foreach ( $pagos as $otro ) {
			$es_total = isset( $otro['rol'] ) && 'total' === $otro['rol'];

			if ( ! $es_total && isset( $otro['status'] ) && 'pagado' === $otro['status'] ) {
				return true;
			}
		}

		return false;
	}

	public function handle_pay() {
		$qid   = isset( $_GET['quote'] ) ? (int) $_GET['quote'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cuota = isset( $_GET['cuota'] ) ? sanitize_key( wp_unslash( $_GET['cuota'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $qid || Malka_CRM_Quotes::POST_TYPE !== get_post_type( $qid ) ) {
			wp_die( esc_html__( 'Esa cotización no existe.', 'malka-crm' ) );
		}

		$d      = self::data( $qid );
		$url    = $d['url'] ? $d['url'] : home_url( '/' );
		$objeto = null;

		foreach ( $d['pagos'] as $p ) {
			if ( isset( $p['id'] ) && $p['id'] === $cuota ) {
				$objeto = $p;
				break;
			}
		}

		// Una cuota ya pagada, o el pago total cuando ya entró una cuota, no
		// se vuelven a cobrar: el cliente pagaría de más.
		$pagada = isset( $objeto['status'] ) && 'pagado' === $objeto['status'];

		if ( ! $objeto || $d['caducada'] || $pagada || self::total_cerrado( $d['pagos'], $objeto ) ) {
			wp_safe_redirect( $url );
			exit;
		}

		$due = self::amount_due( $qid, $objeto );

		/*
		 * Queda anotado con qué se va a pagar antes de mandarlo a la pasarela.
		 * No es que haya pagado —eso lo confirma ella, o la pasarela cuando
		 * esté conectada—, pero cuando el pago entre el medio ya está puesto
		 * y ella no tiene que acordarse de cuál fue.
		 */
		$metodo = isset( $_GET['metodo'] ) ? sanitize_key( wp_unslash( $_GET['metodo'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $metodo ) {
			Malka_CRM_Quotes::set_payment_method( $qid, $cuota, $metodo, true );
		}

		/**
		 * Permite enchufar la pasarela.
		 *
		 * Quien escuche esto recibe el monto de hoy y devuelve la URL donde
		 * hay que mandar al cliente. Mientras no haya pasarela conectada, se
		 * usa el link que esté guardado en la cuota.
		 *
		 * @param string $destino URL de pago.
		 * @param int    $qid     Cotización.
		 * @param array  $objeto  Cuota.
		 * @param array  $due     base, interes, monto, dias.
		 */
		$destino = apply_filters(
			'malka_crm_payment_url',
			isset( $objeto['pay_url'] ) ? $objeto['pay_url'] : '',
			$qid,
			$objeto,
			$due
		);

		if ( ! $destino ) {
			// Sin pasarela: se vuelve al documento con el monto a la vista.
			wp_safe_redirect( add_query_arg( 'pagar', (int) $due['monto'], $url ) );
			exit;
		}

		wp_redirect( $destino ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * El cliente avisa que ya pagó, y con qué.
	 *
	 * Una transferencia no deja rastro en el sitio: si el cliente no lo dice,
	 * el panel no tiene cómo saber ni que pagó ni por qué medio. Esto le da
	 * el botón para decirlo.
	 *
	 * Lo que declara el cliente no da una cuota por pagada —eso lo confirma
	 * ella, que es la que mira la cuenta—: la deja «Por confirmar» con el
	 * medio ya anotado. Así el aviso sirve y, al mismo tiempo, nadie con el
	 * link puede darse por pagado.
	 */
	public function handle_pago_avisado() {
		$qid = isset( $_POST['quote'] ) ? (int) $_POST['quote'] : 0;

		if ( ! $qid ) {
			wp_die( esc_html__( 'Falta la cotización.', 'malka-crm' ) );
		}

		check_admin_referer( 'malka_crm_avisar_pago_' . $qid, 'malka_pago_nonce' );

		if ( Malka_CRM_Quotes::POST_TYPE !== get_post_type( $qid ) ) {
			wp_die( esc_html__( 'Esa cotización no existe.', 'malka-crm' ) );
		}

		$cuota  = isset( $_POST['cuota'] ) ? sanitize_key( wp_unslash( $_POST['cuota'] ) ) : '';
		$metodo = isset( $_POST['metodo'] ) ? sanitize_key( wp_unslash( $_POST['metodo'] ) ) : '';
		$d      = self::data( $qid );
		$url    = $d['url'] ? $d['url'] : home_url( '/' );

		if ( $d['caducada'] ) {
			wp_safe_redirect( $url );
			exit;
		}

		$objeto = null;

		foreach ( $d['pagos'] as $p ) {
			if ( isset( $p['id'] ) && $p['id'] === $cuota ) {
				$objeto = $p;
				break;
			}
		}

		// Una cuota que ya está pagada no se vuelve a avisar.
		if ( ! $objeto || ( isset( $objeto['status'] ) && 'pagado' === $objeto['status'] ) ) {
			wp_safe_redirect( $url );
			exit;
		}

		/*
		 * Un aviso por cuota cada diez minutos. Sin esto, recargar la página
		 * de confirmación repite el aviso y ella termina con ruido en el
		 * panel por algo que el cliente hizo una sola vez.
		 */
		$llave = 'malka_crm_avisado_' . $qid . '_' . $cuota;

		if ( get_transient( $llave ) ) {
			wp_safe_redirect( add_query_arg( 'avisado', '1', $url ) );
			exit;
		}

		set_transient( $llave, 1, 10 * MINUTE_IN_SECONDS );

		Malka_CRM_Quotes::set_payment_method( $qid, $cuota, $metodo, true );

		// De «En espera» a «Por confirmar». Lo demás se queda como está.
		$pagos = Malka_CRM_Quotes::get_payments( $qid );

		foreach ( $pagos as $i => $p ) {
			if ( isset( $p['id'] ) && $p['id'] === $cuota && 'espera' === $p['status'] ) {
				$pagos[ $i ]['status'] = 'confirmar';
				update_post_meta( $qid, Malka_CRM_Quotes::META_PAYMENTS, $pagos );
				break;
			}
		}

		/**
		 * El cliente dice que pagó una cuota.
		 *
		 * @param int    $qid    Cotización.
		 * @param string $cuota  Cuota.
		 * @param string $metodo Medio declarado.
		 */
		do_action( 'malka_crm_pago_avisado', $qid, $cuota, $metodo );

		wp_safe_redirect( add_query_arg( 'avisado', '1', $url ) );
		exit;
	}

	/* ─────────────────────────────  Aviso  ────────────────────────────── */

	/**
	 * Le manda un correo a Malka cuando un cliente firma.
	 *
	 * La plantilla anterior solo hacía un POST a un webhook, y con no-cors
	 * nunca sabía si había llegado. Esto sale del servidor y WordPress sí
	 * informa si el envío falló.
	 *
	 * @param int    $qid    Cotización.
	 * @param string $nombre Quién firmó.
	 */
	public static function notify_signature( $qid, $nombre ) {
		// Los dos correos salen de Plantillas de correo: «Aviso de firma»
		// para ella y «Copia de la firma» para el cliente.
		Malka_CRM_Firma::avisar( $qid );
	}

	/**
	 * El texto exacto que el cliente marca al firmar.
	 *
	 * Se guarda tal cual con la firma: si alguien pregunta qué aceptó, la
	 * respuesta es esta frase, no «una casilla».
	 *
	 * @return string
	 */
	public static function texto_aceptacion() {
		$s = class_exists( 'Malka_CRM_Settings' ) ? Malka_CRM_Settings::all() : array();
		$t = ! empty( $s['terminos_url'] ) ? ' (' . $s['terminos_url'] . ')' : '';

		return __( 'He leído y acepto los términos y condiciones', 'malka-crm' ) . $t . '. ' .
			__( 'Acepto esta cotización y la firmo electrónicamente con mi nombre y RUT.', 'malka-crm' );
	}

	/**
	 * Le avisa a Malka que un cliente dice que ya pagó.
	 *
	 * Sin este correo el aviso se queda esperando dentro del panel, y lo que
	 * tiene que gatillar es que ella mire la cuenta.
	 *
	 * @param int    $qid    Cotización.
	 * @param string $cuota  Cuota.
	 * @param string $metodo Medio declarado.
	 */
	public static function notify_pago_avisado( $qid, $cuota, $metodo ) {
		$d    = self::data( $qid );
		$s    = class_exists( 'Malka_CRM_Settings' ) ? Malka_CRM_Settings::all() : array();
		$para = ! empty( $s['email'] ) ? $s['email'] : get_option( 'admin_email' );

		if ( ! $para ) {
			return;
		}

		$concepto = '';
		$monto    = 0;

		foreach ( $d['pagos'] as $p ) {
			if ( isset( $p['id'] ) && $p['id'] === $cuota ) {
				$concepto = isset( $p['label'] ) ? (string) $p['label'] : '';
				$monto    = (int) round( (float) $p['amount'] );
				break;
			}
		}

		$medios = Malka_CRM_Quotes::payment_methods();
		$medio  = isset( $medios[ $metodo ] ) && $metodo ? $medios[ $metodo ] : __( 'sin especificar', 'malka-crm' );

		$asunto = sprintf(
			/* translators: 1: número de cotización, 2: cliente */
			__( 'Avisan un pago · N° %1$s · %2$s', 'malka-crm' ),
			$d['numero'],
			$d['cliente']['nombre'] ? $d['cliente']['nombre'] : $d['cliente']['empresa']
		);

		$lineas = array(
			__( 'El cliente marcó en su cotización que ya pagó. Queda «Por confirmar» hasta que lo veas en la cuenta.', 'malka-crm' ),
			'',
			sprintf( /* translators: número */ __( 'Cotización N° %s', 'malka-crm' ), $d['numero'] ),
			$d['titulo'],
			sprintf(
				/* translators: 1: concepto de la cuota, 2: monto */
				__( 'Cuota: %1$s · %2$s', 'malka-crm' ),
				$concepto,
				Malka_CRM_Settings::money( $monto )
			),
			sprintf( /* translators: medio de pago */ __( 'Dice que pagó por: %s', 'malka-crm' ), $medio ),
			'',
			$d['url'],
		);

		wp_mail( $para, $asunto, implode( "\n", $lineas ) );
	}

	/* ─────────────────────────────  Firma  ────────────────────────────── */

	/**
	 * Valida un RUT chileno con su dígito verificador.
	 *
	 * Mismo algoritmo que ya usaba la plantilla: módulo 11 con multiplicadores
	 * de 2 a 7, que es el que define el Servicio de Registro Civil.
	 *
	 * @param string $rut RUT en cualquier formato.
	 * @return bool
	 */
	public static function valid_rut( $rut ) {
		$s = strtoupper( preg_replace( '/[^0-9kK]/', '', (string) $rut ) );

		if ( strlen( $s ) < 8 ) {
			return false;
		}

		$cuerpo = substr( $s, 0, -1 );
		$dv     = substr( $s, -1 );

		if ( ! ctype_digit( $cuerpo ) ) {
			return false;
		}

		$suma = 0;
		$mult = 2;

		for ( $i = strlen( $cuerpo ) - 1; $i >= 0; $i-- ) {
			$suma += (int) $cuerpo[ $i ] * $mult;
			$mult  = ( $mult < 7 ) ? $mult + 1 : 2;
		}

		$esperado = 11 - ( $suma % 11 );

		if ( 11 === $esperado ) {
			$esperado = '0';
		} elseif ( 10 === $esperado ) {
			$esperado = 'K';
		} else {
			$esperado = (string) $esperado;
		}

		return ( $esperado === $dv );
	}

	/**
	 * Formatea un RUT como 12.345.678-9.
	 *
	 * @param string $rut RUT.
	 * @return string
	 */
	public static function format_rut( $rut ) {
		$s = strtoupper( preg_replace( '/[^0-9kK]/', '', (string) $rut ) );

		if ( strlen( $s ) < 2 ) {
			return $s;
		}

		$cuerpo = substr( $s, 0, -1 );
		$dv     = substr( $s, -1 );

		return number_format( (float) $cuerpo, 0, ',', '.' ) . '-' . $dv;
	}

	/**
	 * Guarda la aceptación del cliente.
	 *
	 * A diferencia de antes, queda en el servidor: con fecha, hora y la
	 * dirección desde donde se firmó. Lo que vive solo en el navegador del
	 * cliente no sirve de respaldo para nadie.
	 */
	public function handle_sign() {
		$qid = isset( $_POST['quote'] ) ? (int) $_POST['quote'] : 0;

		if ( ! $qid ) {
			wp_die( esc_html__( 'Falta la cotización.', 'malka-crm' ) );
		}

		check_admin_referer( 'malka_crm_sign_' . $qid, 'malka_sign_nonce' );

		if ( Malka_CRM_Quotes::POST_TYPE !== get_post_type( $qid ) ) {
			wp_die( esc_html__( 'Esa cotización no existe.', 'malka-crm' ) );
		}

		$url = self::public_url( $qid );

		// Firmada una vez, no se vuelve a firmar.
		$previa = get_post_meta( $qid, '_malka_pm_quote_sign', true );
		if ( is_array( $previa ) && ! empty( $previa['rut'] ) ) {
			wp_safe_redirect( $url ? $url : home_url( '/' ) );
			exit;
		}

		$d = self::data( $qid );
		if ( $d['caducada'] ) {
			wp_safe_redirect( add_query_arg( 'firma', 'caducada', $url ) );
			exit;
		}

		$nombre = isset( $_POST['nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['nombre'] ) ) : '';
		$rut    = isset( $_POST['rut'] ) ? sanitize_text_field( wp_unslash( $_POST['rut'] ) ) : '';
		$acepta = ! empty( $_POST['acepta'] );

		if ( '' === $nombre || ! $acepta ) {
			wp_safe_redirect( add_query_arg( 'firma', 'faltan', $url ) );
			exit;
		}

		if ( ! self::valid_rut( $rut ) ) {
			wp_safe_redirect( add_query_arg( 'firma', 'rut', $url ) );
			exit;
		}

		/*
		 * La evidencia completa de la firma electrónica simple: quién,
		 * cuándo, desde dónde, qué aceptó y una copia con huella de lo que
		 * firmó. Ver Malka_CRM_Firma.
		 */
		$ev = Malka_CRM_Firma::evidencia( $d, $nombre, self::format_rut( $rut ), self::texto_aceptacion() );

		update_post_meta( $qid, '_malka_pm_quote_sign', $ev['firma'] );
		update_post_meta( $qid, Malka_CRM_Firma::META_COPIA, $ev['copia'] );

		self::sync_status( $qid );

		do_action( 'malka_crm_quote_signed', $qid, $nombre );

		wp_safe_redirect( add_query_arg( 'firma', 'ok', $url ) );
		exit;
	}

	/* ─────────────────────────────  Ruta  ─────────────────────────────── */

	public static function add_rewrite() {
		add_rewrite_rule(
			'^' . self::SLUG . '-(\d+)/?$',
			'index.php?malka_quote_num=$matches[1]',
			'top'
		);
	}

	/**
	 * @param array $vars Variables.
	 * @return array
	 */
	public function query_vars( $vars ) {
		$vars[] = 'malka_quote_num';
		return $vars;
	}

	/**
	 * El número pedido en la URL, mirando también la ruta por si las reglas
	 * de reescritura todavía no se regeneran.
	 *
	 * @return string
	 */
	public static function requested_number() {
		$n = get_query_var( 'malka_quote_num' );

		if ( ! $n && ! empty( $_SERVER['REQUEST_URI'] ) ) {
			$uri = (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
			if ( preg_match( '#/' . self::SLUG . '-(\d+)/?$#', $uri, $m ) ) {
				$n = $m[1];
			}
		}

		return preg_replace( '/\D/', '', (string) $n );
	}

	/* ──────────────────────────  Numeración  ──────────────────────────── */

	/**
	 * El próximo número correlativo.
	 *
	 * Toma el mayor que ya exista y suma uno. Si no hay ninguno, parte del
	 * número que ella haya configurado, para seguir la serie que ya venía
	 * usando en las páginas hechas a mano.
	 *
	 * @return int
	 */
	public static function next_number() {
		global $wpdb;

		$max = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT MAX(CAST(meta_value AS UNSIGNED))
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s
				   AND p.post_type = %s
				   AND p.post_status NOT IN ('trash','auto-draft')
				   AND pm.meta_value REGEXP '^[0-9]+$'",
				self::META_NUM,
				Malka_CRM_Quotes::POST_TYPE
			)
		);

		$desde = (int) get_option( self::OPT_DESDE, 608 );

		return max( $max + 1, $desde );
	}

	/**
	 * Le pone número a una cotización que todavía no lo tiene.
	 *
	 * @param int     $post_id ID.
	 * @param WP_Post $post    Post.
	 * @param bool    $update  Si es una actualización.
	 */
	public static function assign_number( $post_id, $post = null, $update = false ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( $post && in_array( $post->post_status, array( 'auto-draft', 'trash' ), true ) ) {
			return;
		}

		$actual = trim( (string) get_post_meta( $post_id, self::META_NUM, true ) );

		if ( '' !== $actual ) {
			return;
		}

		update_post_meta( $post_id, self::META_NUM, (string) self::reserve_number( $post_id ) );
	}

	/**
	 * Toma el próximo número comprobando que nadie más lo tenga.
	 *
	 * Dos cotizaciones guardadas en el mismo instante podrían leer el mismo
	 * máximo y quedarse las dos con el mismo número, y entonces compartirían
	 * URL. Acá se avanza hasta encontrar uno libre.
	 *
	 * @param int $post_id Cotización que lo pide.
	 * @return int
	 */
	public static function reserve_number( $post_id = 0 ) {
		$n = self::next_number();

		for ( $i = 0; $i < 50; $i++ ) {
			$ocupado = self::find_by_number( (string) $n );

			if ( ! $ocupado || (int) $ocupado === (int) $post_id ) {
				return $n;
			}

			$n++;
		}

		return $n;
	}

	/**
	 * La URL pública de una cotización.
	 *
	 * @param int $qid Cotización.
	 * @return string Vacío si todavía no tiene número.
	 */
	public static function public_url( $qid ) {
		$n = trim( (string) get_post_meta( $qid, self::META_NUM, true ) );

		if ( '' === $n || ! ctype_digit( $n ) ) {
			return '';
		}

		return home_url( '/' . self::SLUG . '-' . $n );
	}

	/**
	 * Busca la cotización que lleva ese número.
	 *
	 * @param string $numero Número.
	 * @return int 0 si no existe.
	 */
	public static function find_by_number( $numero ) {
		$numero = preg_replace( '/\D/', '', (string) $numero );

		if ( '' === $numero ) {
			return 0;
		}

		$ids = get_posts(
			array(
				'post_type'      => Malka_CRM_Quotes::POST_TYPE,
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'   => self::META_NUM,
						'value' => $numero,
					),
				),
			)
		);

		return ! empty( $ids ) ? (int) $ids[0] : 0;
	}

	/* ─────────────────────────────  Render  ───────────────────────────── */

	/**
	 * Intercepta /cotizacion-608 y pinta la cotización.
	 */
	public function maybe_render() {
		$numero = self::requested_number();

		if ( '' === $numero ) {
			return;
		}

		$qid = self::find_by_number( $numero );

		if ( ! $qid ) {
			return; // Que WordPress muestre su 404 de siempre.
		}

		$estado = get_post_status( $qid );

		// Un borrador solo lo ve quien puede editarlo.
		if ( 'publish' !== $estado && ! Malka_CRM_Helpers::current_user_is_manager() ) {
			return;
		}

		status_header( 200 );
		nocache_headers();

		self::render( $qid );
		exit;
	}

	/**
	 * Pinta el documento completo.
	 *
	 * @param int $qid Cotización.
	 */
	public static function render( $qid ) {
		$d = self::data( $qid );

		header( 'Content-Type: text/html; charset=utf-8' );

		echo '<!DOCTYPE html><html ' . get_language_attributes() . '><head><meta charset="utf-8" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<meta name="viewport" content="width=device-width, initial-scale=1" />';
		echo '<meta name="robots" content="noindex, nofollow" />';
		printf(
			'<title>%s</title>',
			esc_html(
				sprintf(
					/* translators: 1: número, 2: estudio */
					__( 'Cotización N° %1$s · %2$s', 'malka-crm' ),
					$d['numero'],
					$d['empresa']['razon_social'] ? $d['empresa']['razon_social'] : get_bloginfo( 'name' )
				)
			)
		);
		$s = class_exists( 'Malka_CRM_Settings' ) ? Malka_CRM_Settings::all() : array();

		/*
		 * La hoja de la tipografía va antes que la del documento: si trae un
		 * @font-face, la familia tiene que existir cuando el documento la
		 * pide. Esta página se sirve sola, sin el tema, así que lo que carga
		 * el sitio en sus otras páginas no llega hasta acá.
		 */
		if ( ! empty( $s['tipografia_css'] ) ) {
			printf( '<link rel="stylesheet" href="%s" />', esc_url( $s['tipografia_css'] ) );
		}

		printf(
			'<link rel="stylesheet" href="%s" />',
			esc_url( MALKA_CRM_URL . 'assets/css/malka-crm-cotizacion.css?v=' . MALKA_CRM_VERSION )
		);

		$reglas = array();
		$pila   = self::font_stack( isset( $s['tipografia'] ) ? $s['tipografia'] : '' );

		if ( $pila ) {
			$reglas[] = '--font-family:' . $pila;
		}

		/*
		 * Los estilos de párrafo. Van como variables y no como reglas
		 * sueltas: cada una manda sobre varios selectores de la hoja, así
		 * que acá no hay que repetir la lista.
		 */
		if ( class_exists( 'Malka_CRM_Settings' ) ) {
			$defs = Malka_CRM_Settings::doc_estilos();

			foreach ( Malka_CRM_Settings::doc_estilos_valores() as $k => $v ) {
				// Lo que ya es de fábrica no se escribe: la hoja lo trae.
				if ( (int) $v['px'] === (int) $defs[ $k ]['px'] && (int) $v['peso'] === (int) $defs[ $k ]['peso'] ) {
					continue;
				}

				$reglas[] = sprintf( '--doc-%1$s:%2$dpx', sanitize_key( $k ), (int) $v['px'] );
				$reglas[] = sprintf( '--doc-%1$s-w:%2$d', sanitize_key( $k ), (int) $v['peso'] );
			}
		}

		if ( $reglas ) {
			printf( '<style>#malka-master-quote{%s;}</style>', implode( ';', $reglas ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '</head><body class="malka-cot-body">';

		echo self::document( $d ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		printf(
			'<script>window.malkaCotizacion=%s;</script>',
			wp_json_encode(
				array(
					'comision' => isset( $s['comision_tarjeta'] ) ? (float) $s['comision_tarjeta'] : 3.81,
				)
			)
		);
		printf(
			'<script src="%s"></script>',
			esc_url( MALKA_CRM_URL . 'assets/js/malka-crm-cotizacion.js?v=' . MALKA_CRM_VERSION )
		);

		echo '</body></html>';
	}

	/**
	 * La pila tipográfica del documento, a partir del nombre configurado.
	 *
	 * Esto termina dentro de un <style>, así que del nombre sólo sobreviven
	 * letras, números, espacios y guiones: cualquier otra cosa podría cerrar
	 * la regla y escribir CSS de su cuenta.
	 *
	 * @param string $nombre Familia que ella escribió.
	 * @return string Pila lista, o '' si no hay nada que poner.
	 */
	public static function font_stack( $nombre ) {
		$nombre = preg_replace( '/[^A-Za-z0-9 \-]/', '', (string) $nombre );
		// Lo que se quitó deja huecos: «Neris  Light» no es una familia.
		$nombre = trim( preg_replace( '/\s+/', ' ', $nombre ) );

		if ( '' === $nombre ) {
			return '';
		}

		// El respaldo es el mismo de siempre: si la familia no carga, el
		// documento se ve como se veía y no como Times New Roman.
		return '"' . $nombre . '", system-ui, -apple-system, "Helvetica Neue", sans-serif';
	}

	/**
	 * Todo lo que el documento necesita, resuelto de una vez.
	 *
	 * @param int $qid Cotización.
	 * @return array
	 */
	public static function data( $qid ) {
		// Antes de leer nada, el estado se pone al día.
		self::sync_status( $qid );

		$s   = class_exists( 'Malka_CRM_Settings' ) ? Malka_CRM_Settings::all() : array();
		$iva = isset( $s['iva'] ) ? (float) $s['iva'] : 19.0;

		$items = get_post_meta( $qid, '_malka_pm_quote_items', true );
		$items = is_array( $items ) ? $items : array();

		$neto = 0;
		$limpios = array();

		foreach ( $items as $it ) {
			if ( ! is_array( $it ) || '' === trim( (string) ( isset( $it['titulo'] ) ? $it['titulo'] : '' ) ) ) {
				continue;
			}

			$cant = isset( $it['cantidad'] ) ? (float) $it['cantidad'] : 1;
			$unit = isset( $it['unitario'] ) ? (float) $it['unitario'] : 0;
			$desc = isset( $it['descuento'] ) ? (float) $it['descuento'] : 0;
			$sub  = (int) round( $cant * $unit * ( 1 - ( $desc / 100 ) ) );

			$neto     += $sub;
			$limpios[] = array(
				'titulo'    => (string) $it['titulo'],
				'detalle'   => isset( $it['detalle'] ) ? (string) $it['detalle'] : '',
				'plazo'     => isset( $it['plazo'] ) ? (int) $it['plazo'] : 0,
				'cantidad'  => $cant,
				'unitario'  => (int) round( $unit ),
				'descuento' => $desc,
				'neto'      => $sub,
			);
		}

		$monto_iva = (int) round( $neto * ( $iva / 100 ) );

		$vence = (string) get_post_meta( $qid, '_malka_pm_quote_expires', true );
		$dias  = $vence ? (int) floor( ( strtotime( $vence ) - strtotime( current_time( 'Y-m-d' ) ) ) / DAY_IN_SECONDS ) : null;

		$firma = get_post_meta( $qid, '_malka_pm_quote_sign', true );
		$firma = is_array( $firma ) ? $firma : array();

		/*
		 * Caducar es dejar de estar en oferta, no dejar de existir.
		 *
		 * Esto miraba sólo la fecha, así que una cotización aceptada y con
		 * cuotas pagadas se daba por caducada al pasar su vigencia, y con eso
		 * desaparecían los botones para elegir y pagar la cuota siguiente: el
		 * cliente veía su cuota pendiente sin ninguna forma de pagarla.
		 *
		 * Una vez firmada o con el primer pago entrado, la vigencia ya cumplió
		 * su función y deja de contar.
		 */
		$estado_q = (string) get_post_meta( $qid, '_malka_pm_quote_status', true );
		$palabra  = (bool) get_post_meta( $qid, self::META_PALABRA, true );
		$ganada   = ! empty( $firma['rut'] )
			|| $palabra
			|| in_array( $estado_q, array( 'aceptada', 'en_curso', 'entregado', 'finalizada' ), true );

		$caducada = ( null !== $dias && $dias < 0 && ! $ganada );

		return array(
			'id'       => (int) $qid,
			'numero'   => (string) get_post_meta( $qid, self::META_NUM, true ),
			'titulo'   => get_the_title( $qid ),
			'cliente'  => array(
				'nombre'  => (string) get_post_meta( $qid, '_malka_pm_client_name', true ),
				'empresa' => (string) get_post_meta( $qid, '_malka_pm_client_company', true ),
				'rut'     => (string) get_post_meta( $qid, '_malka_pm_client_rut', true ),
				'ciudad'  => (string) get_post_meta( $qid, '_malka_pm_client_city', true ),
				'email'   => (string) get_post_meta( $qid, '_malka_pm_client_email', true ),
				'fono'    => (string) get_post_meta( $qid, '_malka_pm_client_phone', true ),
			),
			'empresa'  => array(
				'razon_social' => isset( $s['razon_social'] ) ? $s['razon_social'] : '',
				'rut'          => isset( $s['rut'] ) ? $s['rut'] : '',
				'email'        => isset( $s['email'] ) ? $s['email'] : '',
				'telefono'     => isset( $s['telefono'] ) ? $s['telefono'] : '',
			),
			'items'    => $limpios,
			'neto'     => $neto,
			'iva_pct'  => $iva,
			'iva'      => $monto_iva,
			'total'    => $neto + $monto_iva,
			'notas'    => (string) get_post_meta( $qid, '_malka_pm_quote_notes', true ),
			'pagos'    => class_exists( 'Malka_CRM_Quotes' ) ? Malka_CRM_Quotes::get_payments( $qid ) : array(),
			'estado'   => (string) get_post_meta( $qid, '_malka_pm_quote_status', true ),
			'emitida'  => (string) get_post_meta( $qid, '_malka_pm_quote_issued', true ),
			'vence'    => $vence,
			'dias'     => $dias,
			'caducada' => $caducada,
			'firma'    => $firma,
			'firmada'  => ! empty( $firma['rut'] ),
			'palabra'  => $palabra && empty( $firma['rut'] ),
			'pagable'  => ! empty( $firma['rut'] ) || $palabra,
			'url'      => self::public_url( $qid ),
		);
	}

	/**
	 * El cuerpo del documento, con el marcado y los textos de la plantilla.
	 *
	 * @param array $d Datos.
	 * @return string
	 */
	public static function document( $d ) {
		$estados = class_exists( 'Malka_CRM_Quotes' ) ? Malka_CRM_Quotes::quote_statuses() : array();
		$etq     = isset( $estados[ $d['estado'] ] ) ? $estados[ $d['estado'] ] : __( 'Sin respuesta', 'malka-crm' );
		$s       = class_exists( 'Malka_CRM_Settings' ) ? Malka_CRM_Settings::all() : array();

		ob_start();
		echo '<div id="malka-master-quote"><div class="document-container">';

		if ( $d['caducada'] ) {
			echo '<div class="ribbon-caducada">' . esc_html__( 'CADUCADA', 'malka-crm' ) . '</div>';
		}

		/* ── Encabezado: logo y los dos estados ── */
		echo '<div class="header"><div class="header-top">';

		// El cliente conoce el nombre de fantasía, no la razón social.
		$marca = ! empty( $s['nombre_fantasia'] ) ? $s['nombre_fantasia'] : $d['empresa']['razon_social'];

		echo '<div class="logo">';
		if ( ! empty( $s['logo_url'] ) ) {
			printf( '<img src="%s" alt="%s" />', esc_url( $s['logo_url'] ), esc_attr( $marca ) );
		} else {
			echo esc_html( $marca );
		}
		echo '</div>';

		echo '<div class="doc-status-badge">';
		printf(
			'<div class="status-group"><span class="status-label">%s</span><span class="status-value">%s</span></div>',
			esc_html__( 'Estado cotización', 'malka-crm' ),
			esc_html( $etq )
		);
		printf(
			'<div class="status-group"><span class="status-label">%s</span><span class="status-value">%s</span></div>',
			esc_html__( 'Vigencia', 'malka-crm' ),
			esc_html( $d['caducada'] ? __( 'Caducada', 'malka-crm' ) : __( 'Vigente', 'malka-crm' ) )
		);
		echo '</div></div>';

		echo '<div class="title-section">';
		printf(
			'<div class="doc-number">%s %s</div>',
			esc_html__( 'Cotización N°', 'malka-crm' ),
			esc_html( $d['numero'] )
		);
		printf(
			'<div class="doc-title">%s <span>%s</span></div>',
			esc_html__( 'PROYECTO:', 'malka-crm' ),
			esc_html( $d['titulo'] )
		);
		echo '</div></div>';

		/* ── Para y fechas ── */
		echo '<div class="info-grid">';

		echo '<div class="info-block"><strong>' . esc_html__( 'Para', 'malka-crm' ) . '</strong>';
		$c = $d['cliente'];

		// El RUT va en su propio renglón: metido entre paréntesis detrás de
		// la empresa se leía como un apunte y no como el dato que es.
		$lineas = array(
			$c['nombre'],
			$c['empresa'],
			$c['rut'] ? 'RUT: ' . $c['rut'] : '',
			$c['ciudad'],
			$c['fono'],
			$c['email'],
		);
		foreach ( $lineas as $i => $linea ) {
			if ( trim( (string) $linea ) ) {
				printf( '<p%s>%s</p>', 0 === $i ? ' class="info-nombre"' : '', esc_html( $linea ) );
			}
		}
		echo '</div>';

		echo '<div class="info-block">';
		if ( $d['emitida'] ) {
			printf(
				'<strong>%s</strong><p>%s</p>',
				esc_html__( 'Fecha de emisión', 'malka-crm' ),
				esc_html( date_i18n( 'd/m/Y', strtotime( $d['emitida'] ) ) )
			);
		}
		if ( $d['vence'] ) {
			printf(
				'<strong>%s</strong><p>%s</p>',
				esc_html__( 'Fecha de vencimiento', 'malka-crm' ),
				esc_html( date_i18n( 'd/m/Y', strtotime( $d['vence'] ) ) )
			);
		}
		echo '</div></div>';

		/* ── Ítems ── */
		echo '<div class="table-container"><table class="svc-table"><thead><tr>';
		printf( '<th class="col-desc">%s</th>', esc_html__( 'Item / Descripción', 'malka-crm' ) );
		printf( '<th class="col-qty">%s</th>', esc_html__( 'Cantidad', 'malka-crm' ) );
		printf( '<th class="col-unit">%s</th>', esc_html__( 'Unitario', 'malka-crm' ) );
		printf( '<th class="col-disc">%s</th>', esc_html__( 'Desc. %', 'malka-crm' ) );
		printf( '<th class="col-net">%s</th>', esc_html__( 'Neto', 'malka-crm' ) );
		echo '</tr></thead><tbody id="servicesTable">';

		foreach ( $d['items'] as $it ) {
			echo '<tr class="service-row"><td class="col-desc">';
			echo '<span class="item-title">' . esc_html( $it['titulo'] ) . '</span>';

			if ( trim( $it['detalle'] ) ) {
				echo '<ul class="item-details">';
				foreach ( preg_split( '/\r\n|\r|\n/', $it['detalle'] ) as $linea ) {
					if ( trim( $linea ) ) {
						echo '<li>' . esc_html( trim( $linea ) ) . '</li>';
					}
				}
				echo '</ul>';
			}

			if ( $it['plazo'] > 0 ) {
				printf(
					'<div class="item-plazo">%s</div>',
					esc_html(
						sprintf(
							/* translators: días hábiles */
							_n( 'Plazo estimado: %d día hábil', 'Plazo estimado: %d días hábiles', $it['plazo'], 'malka-crm' ),
							$it['plazo']
						)
					)
				);
			}

			echo '</td>';
			printf( '<td class="col-qty" data-th="%s">%s</td>', esc_attr__( 'Cantidad', 'malka-crm' ), esc_html( number_format_i18n( $it['cantidad'] ) ) );
			printf( '<td class="col-unit" data-th="%s">%s</td>', esc_attr__( 'Unitario', 'malka-crm' ), esc_html( Malka_CRM_Settings::money( $it['unitario'] ) ) );
			printf( '<td class="col-disc" data-th="%s">%s</td>', esc_attr__( 'Descuento', 'malka-crm' ), esc_html( $it['descuento'] > 0 ? $it['descuento'] . ' %' : '—' ) );
			printf( '<td class="col-net row-total" data-th="%s">%s</td>', esc_attr__( 'Neto', 'malka-crm' ), esc_html( Malka_CRM_Settings::money( $it['neto'] ) ) );
			echo '</tr>';
		}

		if ( empty( $d['items'] ) ) {
			printf(
				'<tr class="service-row"><td class="col-desc" colspan="5">%s</td></tr>',
				esc_html__( 'Esta cotización todavía no tiene ítems.', 'malka-crm' )
			);
		}

		echo '</tbody></table></div>';

		/* ── Notas ── */
		if ( trim( $d['notas'] ) ) {
			// El id es el destino del enlace de «términos y condiciones»
			// cuando no hay uno configurado: lo que el cliente acepta, si no
			// hay documento aparte, son estas notas.
			echo '<div class="notes-box" id="condiciones"><div class="section-title">' . esc_html__( 'Notas y observaciones del proyecto', 'malka-crm' ) . '</div>';
			echo '<ul class="notes-list">';
			foreach ( preg_split( '/\r\n|\r|\n/', $d['notas'] ) as $linea ) {
				if ( trim( $linea ) ) {
					echo '<li>' . esc_html( trim( $linea ) ) . '</li>';
				}
			}
			echo '</ul></div>';
		}

		/* ── Condición de anticipo ── */
		$anticipo = isset( $s['anticipo_pct'] ) ? (int) $s['anticipo_pct'] : 60;
		if ( $anticipo > 0 ) {
			printf(
				'<div class="payment-condition">%s</div>',
				esc_html(
					sprintf(
						/* translators: porcentaje de anticipo */
						__( 'Para iniciar cualquier trabajo es requisito obligatorio realizar el pago del %d%% de anticipo.', 'malka-crm' ),
						$anticipo
					)
				)
			);
		}

		/* ── Pagos y facturación ── */
		echo '<div class="module-grid">';
		echo self::payments_block( $d ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::billing_block( $d ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		/* ── Firma + totales ── */
		echo '<div class="footer-grid">';

		echo '<div class="bank-details">';
		if ( $d['caducada'] ) {
			echo '<div class="msg-caducada">' . esc_html( self::t( 'caducada', __( 'Esta cotización ha caducado. El pago ya no está disponible.', 'malka-crm' ) ) ) . '</div>';
		}
		echo self::sign_block( $d ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::bank_block( $d, $s ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		echo '<div class="totals-box">';
		printf(
			'<div class="total-row"><span>%s</span><span>%s</span></div>',
			esc_html__( 'Subtotal neto', 'malka-crm' ),
			esc_html( Malka_CRM_Settings::money( $d['neto'] ) )
		);
		printf(
			'<div class="total-row"><span>%s</span><span>%s</span></div>',
			esc_html( sprintf( 'IVA (%s%%)', number_format_i18n( $d['iva_pct'] ) ) ),
			esc_html( Malka_CRM_Settings::money( $d['iva'] ) )
		);
		printf(
			'<div class="total-row final"><span>%s</span><span>%s</span></div>',
			esc_html__( 'Total proyecto', 'malka-crm' ),
			esc_html( Malka_CRM_Settings::money( $d['total'] ) )
		);
		echo self::pay_now_block( $d ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div></div>';

		/*
		 * ── El avance del proyecto ──
		 *
		 * Va al final: arriba está lo que el cliente vino a ver —qué cotizó,
		 * qué debe y cómo paga— y el avance es lo que viene a mirar después,
		 * cuando el trabajo ya está en marcha.
		 */
		echo self::progress_block( $d ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		/* ── Pie ── */
		$pie = array_filter(
			array(
				$d['empresa']['razon_social'],
				$d['empresa']['rut'] ? 'RUT ' . $d['empresa']['rut'] : '',
				$d['empresa']['email'],
				$d['empresa']['telefono'],
			)
		);
		printf( '<div class="doc-footer">%s</div>', esc_html( implode( '   |   ', $pie ) ) );

		echo '</div></div>';
		return ob_get_clean();
	}

	/**
	 * El registro de pagos, con la opción de elegir cuál pagar.
	 *
	 * @param array $d Datos.
	 * @return string
	 */
	private static function payments_block( $d ) {
		ob_start();
		echo '<div class="table-container">';

		$todo = self::todo_pagado( $d );

		printf( '<div class="section-title">%s</div>', esc_html__( 'Registro de pagos', 'malka-crm' ) );

		// La vuelta desde SumUp. Va acá y no en el selector de pago: si el
		// pago cerró la cuenta, el selector ya no se muestra.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sumup = isset( $_GET['sumup'] ) ? sanitize_key( wp_unslash( $_GET['sumup'] ) ) : '';
		if ( 'ok' === $sumup ) {
			printf( '<p class="aviso-ok">%s</p>', esc_html( self::t( 'sumup_ok', __( '¡Listo! Recibimos tu pago con tarjeta. Gracias.', 'malka-crm' ) ) ) );
		} elseif ( 'pendiente' === $sumup ) {
			printf( '<p class="aviso-ok">%s</p>', esc_html( self::t( 'sumup_pendiente', __( 'Todavía no vemos el pago con tarjeta. Si lo completaste, lo confirmamos en cuanto SumUp nos avise; si no, puedes intentarlo de nuevo.', 'malka-crm' ) ) ) );
		}

		/*
		 * Todo pagado: no queda nada que elegir. En vez de la tabla con
		 * casillas —donde el pago total y las cuotas se leían como deudas
		 * distintas—, una sola confirmación.
		 */
		if ( $todo ) {
			echo self::paid_summary( $d ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div>';
			return ob_get_clean();
		}

		if ( empty( $d['pagos'] ) ) {
			echo '<p class="pay-vacio">' . esc_html__( 'Todavía no hay cuotas definidas.', 'malka-crm' ) . '</p></div>';
			return ob_get_clean();
		}

		/*
		 * Lo ya pagado va arriba, con su detalle (cuándo y con qué), y sale
		 * de la tabla de abajo: ahí sólo queda lo que todavía se puede elegir.
		 */
		$hechos = array();
		foreach ( $d['pagos'] as $p ) {
			if ( isset( $p['status'] ) && 'pagado' === $p['status'] && ! Malka_CRM_Quotes::es_resumen( $d['id'], $p ) ) {
				$hechos[] = $p;
			}
		}

		if ( $hechos ) {
			printf( '<p class="pay-sub">%s</p>', esc_html__( 'Pagos realizados', 'malka-crm' ) );
			echo self::paid_list( $d, $hechos ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			printf( '<p class="pay-sub">%s</p>', esc_html__( 'Por pagar (selecciona qué deseas pagar)', 'malka-crm' ) );
		} else {
			printf( '<p class="pay-sub pay-sub--solo">%s</p>', esc_html__( 'Selecciona qué deseas pagar', 'malka-crm' ) );
		}

		echo '<table class="pay-table"><thead><tr>';
		printf( '<th class="col-desc">%s</th>', esc_html__( 'Concepto', 'malka-crm' ) );
		printf( '<th class="col-qty">%s</th>', esc_html__( '%', 'malka-crm' ) );
		printf( '<th class="col-unit">%s</th>', esc_html__( 'Monto', 'malka-crm' ) );
		printf( '<th class="col-qty">%s</th>', esc_html__( 'Fecha', 'malka-crm' ) );
		printf( '<th class="col-qty">%s</th>', esc_html__( 'Método', 'malka-crm' ) );
		printf( '<th class="col-net">%s</th>', esc_html__( 'Estado', 'malka-crm' ) );
		echo '</tr></thead><tbody>';

		foreach ( $d['pagos'] as $p ) {
			// Ya se pagó una cuota: el pago total sobra y se saca de la tabla.
			if ( self::total_cerrado( $d['pagos'], $p ) ) {
				continue;
			}

			$e      = self::payment_view( $d['id'], $p );
			$pagada = ( 'paid' === $e['state'] );

			// Ya está arriba, en «Pagos realizados».
			if ( $pagada ) {
				continue;
			}

			$pct    = $d['total'] > 0 ? round( ( (int) $p['amount'] / $d['total'] ) * 100 ) : 0;
			$due    = self::amount_due( $d['id'], $p );

			echo '<tr class="payment-row">';
			printf(
				'<td class="col-desc"><label class="pay-pick">%1$s<strong class="pay-name">%2$s</strong></label></td>',
				( $pagada || $d['caducada'] )
					? ''
					: sprintf(
						// Ninguna viene marcada: los datos para pagar salen
						// recién cuando el cliente elige, y preseleccionar
						// una le mostraba el monto de una cuota que no eligió.
						'<input class="concept-radio" type="radio" name="mq-pago" value="%1$s" data-monto="%2$d" data-label="%3$s" />',
						esc_attr( $p['id'] ),
						(int) $due['monto'],
						esc_attr( $p['label'] )
					),
				esc_html( $p['label'] )
			);

			printf( '<td class="col-qty" data-th="%s">%s</td>', esc_attr__( 'Porcentaje', 'malka-crm' ), esc_html( $pct . ' %' ) );
			printf(
				'<td class="col-unit" data-th="' . esc_attr__( 'Monto', 'malka-crm' ) . '">%1$s%2$s</td>',
				esc_html( Malka_CRM_Settings::money( $p['amount'] ) ),
				$due['interes'] > 0
					? '<i class="pay-mora">' . esc_html(
						sprintf(
							/* translators: interés acumulado */
							__( '+ %s de interés', 'malka-crm' ),
							Malka_CRM_Settings::money( $due['interes'] )
						)
					) . '</i>'
					: ''
			);
			if ( $e['espera'] ) {
				$cuando = __( 'Al entregar', 'malka-crm' );
			} elseif ( $e['fecha'] ) {
				$cuando = date_i18n( 'd/m/Y', strtotime( $e['fecha'] ) );
			} else {
				$cuando = '—';
			}

			printf( '<td class="col-qty" data-th="%s">%s</td>', esc_attr__( 'Fecha', 'malka-crm' ), esc_html( $cuando ) );
			printf(
				'<td class="col-qty" data-th="%1$s">%2$s</td>',
				esc_attr__( 'Método', 'malka-crm' ),
				esc_html( self::metodo_pago( $d['id'], $p, $pagada ) )
			);
			// Si el cliente ya avisó que pagó, eso es lo que tiene que leer:
			// repetirle «atrasado» después de que lo dijo parece que su aviso
			// no llegó. El interés, si lo hay, sigue a la vista en el monto.
			$avisado = ! empty( $e['avisado'] ) && ! $pagada;

			printf(
				'<td class="col-net" data-th="' . esc_attr__( 'Estado', 'malka-crm' ) . '"><span class="pay-estado is-%1$s">%2$s</span></td>',
				esc_attr( $avisado ? 'confirm' : $e['state'] ),
				esc_html( $avisado ? __( 'Por confirmar', 'malka-crm' ) : $e['label'] )
			);
			echo '</tr>';
		}

		echo '</tbody></table>';

		// Sin firma la tabla se puede mirar pero no lleva a ninguna parte:
		// se dice dónde está el paso que falta.
		if ( empty( $d['pagable'] ) && ! $d['caducada'] ) {
			printf(
				'<p class="pay-firma-antes">%s</p>',
				esc_html__( 'Para pagar, primero acepta y firma la cotización más abajo. Al firmar aparecen la forma de pago y los datos de transferencia.', 'malka-crm' )
			);
		}

		echo self::terms_note( $d ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * La línea de tiempo del proyecto, para el cliente.
	 *
	 * Aparece recién cuando el cliente firmó: antes de eso no hay proyecto que
	 * mostrar y la cotización tiene que leerse como una propuesta, no como un
	 * trabajo en curso.
	 *
	 * Los hitos salen de lo que el panel ya sabe —la firma, las cuotas, las
	 * tareas del negocio, la entrega— y no de una lista inventada: si ella
	 * cambia las etapas en la ficha, el cliente ve el cambio.
	 *
	 * @param array $d Datos de la cotización.
	 * @return string
	 */
	private static function progress_block( $d ) {
		$ganada = in_array( $d['estado'], array( 'aceptada', 'en_curso', 'entregado', 'finalizada' ), true );

		if ( ! $ganada ) {
			return '';
		}

		$hitos = self::hitos( $d );

		if ( ! $hitos ) {
			return '';
		}

		$hechos = 0;

		foreach ( $hitos as $h ) {
			if ( 'listo' === $h['estado'] ) {
				$hechos++;
			}
		}

		$pct = count( $hitos ) > 0 ? (int) round( ( $hechos / count( $hitos ) ) * 100 ) : 0;

		$rotulos = array(
			'listo'    => __( 'Completado', 'malka-crm' ),
			'curso'    => __( 'En proceso', 'malka-crm' ),
			'pendiente' => __( 'Pendiente', 'malka-crm' ),
		);

		ob_start();
		echo '<div class="avance-module">';

		printf(
			'<div class="avance-head"><div class="section-title">%1$s</div>' .
			'<div class="avance-pct"><strong>%2$d%%</strong><span>%3$s</span></div></div>',
			esc_html__( 'Avance del proyecto', 'malka-crm' ),
			(int) $pct,
			esc_html(
				sprintf(
					/* translators: 1: hitos listos, 2: hitos en total */
					__( '%1$d de %2$d hitos', 'malka-crm' ),
					$hechos,
					count( $hitos )
				)
			)
		);

		echo '<ol class="avance-linea">';

		foreach ( $hitos as $h ) {
			printf(
				'<li class="avance-hito is-%1$s">%2$s' .
				'<span class="avance-nombre">%3$s</span>' .
				'<span class="avance-estado">%4$s</span></li>',
				esc_attr( $h['estado'] ),
				self::marca(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( $h['nombre'] ),
				esc_html( $rotulos[ $h['estado'] ] )
			);
		}

		echo '</ol>';
		printf(
			'<p class="avance-pie">%s</p>',
			esc_html__( 'Se actualiza solo a medida que avanzamos. Si algo no calza con lo que esperabas, escríbenos.', 'malka-crm' )
		);
		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * Un texto del documento, editable desde el panel.
	 *
	 * @param string $clave  Texto.
	 * @param string $fabrica Lo que decía antes, por si el módulo no está.
	 * @return string
	 */
	private static function t( $clave, $fabrica ) {
		return class_exists( 'Malka_CRM_Messages' )
			? Malka_CRM_Messages::texto_doc( $clave )
			: $fabrica;
	}

	/**
	 * La figura que marca cada hito: la estrella de la marca.
	 *
	 * Va en SVG y no en CSS porque un `clip-path` no se imprime igual en todos
	 * los navegadores, y esta página se imprime. El color lo pone la hoja de
	 * estilos según el estado, por eso el relleno es `currentColor`.
	 *
	 * La curva es la de la figura que ella mandó: cada punta abre 47° y el
	 * talle se hunde hasta el 47 % del radio.
	 *
	 * @return string
	 */
	private static function marca() {
		return '<svg class="avance-marca" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">' .
			'<path d="M12 0C16.2 9.6 14.4 7.8 24 12C14.4 16.2 16.2 14.4 12 24C7.8 14.4 9.6 16.2 0 12C9.6 7.8 7.8 9.6 12 0Z"/>' .
			'</svg>';
	}

	/**
	 * Los hitos que ve el cliente, en orden.
	 *
	 * @param array $d Datos de la cotización.
	 * @return array[] nombre y estado (listo|curso|pendiente).
	 */
	private static function hitos( $d ) {
		$hitos = array();

		// 1. Lo que ya pasó para llegar hasta acá.
		$hitos[] = array(
			'nombre' => __( 'Cotización enviada', 'malka-crm' ),
			'estado' => 'listo',
		);

		$firma = get_post_meta( $d['id'], '_malka_pm_quote_sign', true );
		$hitos[] = array(
			'nombre' => __( 'Aceptación y firma', 'malka-crm' ),
			'estado' => ( is_array( $firma ) && ! empty( $firma['rut'] ) ) ? 'listo' : 'pendiente',
		);

		/*
		 * 2. Las cuotas se reparten: las que se cobran al emitir van arriba,
		 * y las que se cobran contra entrega van después de la entrega. Si no,
		 * el cliente ve «Pago: Saldo» antes de las tareas y parece que se le
		 * está cobrando algo que todavía no corresponde.
		 */
		$antes   = array();
		$despues = array();

		foreach ( $d['pagos'] as $p ) {
			if ( isset( $p['rol'] ) && 'cuota' !== $p['rol'] ) {
				continue;
			}

			$e      = self::payment_view( $d['id'], $p );
			$pagada = ( 'paid' === $e['state'] );

			$hito = array(
				'nombre' => sprintf(
					/* translators: concepto de la cuota */
					__( 'Pago: %s', 'malka-crm' ),
					$p['label']
				),
				'estado' => $pagada ? 'listo' : ( ! empty( $e['espera'] ) ? 'pendiente' : 'curso' ),
			);

			if ( isset( $p['disparo'] ) && 'entrega' === $p['disparo'] ) {
				$despues[] = $hito;
			} else {
				$antes[] = $hito;
			}
		}

		$hitos = array_merge( $hitos, $antes );

		// 3. Las etapas y tareas del negocio, si ya hay uno.
		$pid = (int) get_post_meta( $d['id'], '_malka_pm_project_id', true );

		if ( $pid && class_exists( 'Malka_CRM_Helpers' ) ) {
			$primera_abierta = true;

			foreach ( Malka_CRM_Helpers::get_project_tasks( $pid ) as $t ) {
				// Las etapas son títulos: el cliente sigue las tareas.
				if ( isset( $t['type'] ) && 'stage' === $t['type'] ) {
					continue;
				}

				// Lo que Malka marcó como suyo no sale de la casa.
				if ( ! empty( $t['privada'] ) ) {
					continue;
				}

				$listo = ! empty( $t['done'] );

				if ( $listo ) {
					$estado = 'listo';
				} elseif ( $primera_abierta ) {
					$estado          = 'curso';
					$primera_abierta = false;
				} else {
					$estado = 'pendiente';
				}

				$hitos[] = array(
					'nombre' => isset( $t['name'] ) ? (string) $t['name'] : '',
					'estado' => $estado,
				);
			}
		}

		// 4. La entrega, y detrás lo que se cobra contra ella.
		$hitos[] = array(
			'nombre' => __( 'Entrega del proyecto', 'malka-crm' ),
			'estado' => get_post_meta( $d['id'], self::META_ENTREGA, true ) ? 'listo' : 'pendiente',
		);

		$hitos = array_merge( $hitos, $despues );

		// Una línea sin nombre no le dice nada a nadie.
		return array_values(
			array_filter(
				$hitos,
				function ( $h ) {
					return '' !== trim( (string) $h['nombre'] );
				}
			)
		);
	}

	/**
	 * Con qué se pagó una cuota.
	 *
	 * Mientras no esté pagada no hay método que mostrar: ponerle «transferencia»
	 * por adelantado sería decirle al cliente cómo tiene que pagar, y abajo
	 * puede elegir.
	 *
	 * @param int   $qid    Cotización.
	 * @param array $p      Cuota.
	 * @param bool  $pagada Si ya está pagada.
	 * @return string
	 */
	private static function metodo_pago( $qid, $p, $pagada ) {
		if ( ! $pagada ) {
			return '—';
		}

		$metodo  = isset( $p['method'] ) ? (string) $p['method'] : '';
		$nombres = Malka_CRM_Quotes::payment_methods();

		if ( '' !== $metodo && isset( $nombres[ $metodo ] ) ) {
			return $nombres[ $metodo ];
		}

		// Pagada sin registrar el medio: se dice que está pagada, no se inventa.
		return __( 'Pagada', 'malka-crm' );
	}

	/**
	 * ¿No queda nada por pagar?
	 *
	 * @param array $d Datos.
	 * @return bool
	 */
	private static function todo_pagado( $d ) {
		if ( empty( $d['pagos'] ) ) {
			return false;
		}

		foreach ( $d['pagos'] as $p ) {
			if ( Malka_CRM_Quotes::es_resumen( $d['id'], $p ) ) {
				continue;
			}
			if ( ! isset( $p['status'] ) || 'pagado' !== $p['status'] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * La confirmación de que está todo pagado.
	 *
	 * @param array $d Datos.
	 * @return string
	 */
	private static function paid_summary( $d ) {
		$total = null;

		foreach ( $d['pagos'] as $p ) {
			if ( isset( $p['rol'], $p['status'] ) && 'total' === $p['rol'] && 'pagado' === $p['status'] ) {
				$total = $p;
			}
		}

		// Se pagó de una: eso es lo que pasó, no tres pagos.
		$filas = array();
		if ( $total ) {
			$filas[] = $total;
		} else {
			foreach ( $d['pagos'] as $p ) {
				if ( ! Malka_CRM_Quotes::es_resumen( $d['id'], $p ) ) {
					$filas[] = $p;
				}
			}
		}

		$suma = 0;
		foreach ( $filas as $p ) {
			$suma += (float) $p['amount'];
		}

		$html  = '<div class="pay-done">';
		$html .= '<div class="pay-done__head"><span class="pay-done__check" aria-hidden="true">✓</span><div>';
		$html .= '<strong>' . esc_html__( 'Pagado en su totalidad', 'malka-crm' ) . '</strong>';
		$html .= '<span>' . esc_html( Malka_CRM_Settings::money( $suma ) ) . '</span>';
		$html .= '</div></div>';
		$html .= self::paid_list( $d, $filas );
		$html .= '<p class="pay-done__gracias">' . esc_html( self::t( 'pagado_gracias', __( 'Gracias. No tienes nada pendiente en esta cotización.', 'malka-crm' ) ) ) . '</p></div>';

		return $html;
	}

	/**
	 * El detalle de los pagos hechos: concepto, monto, fecha, medio y estado.
	 *
	 * @param array $d     Datos.
	 * @param array $filas Cuotas pagadas.
	 * @return string
	 */
	private static function paid_list( $d, $filas ) {
		$nombres = Malka_CRM_Quotes::payment_methods();

		/*
		 * Con pocos pagos, cada uno abierto con su detalle. Con muchos, la
		 * lista se hacía eterna en el teléfono: pasan a una línea cada uno
		 * (concepto, monto y fecha) y se abren al tocarlos.
		 */
		$compacto = count( $filas ) > 3;
		$html     = '<div class="pay-hechos' . ( $compacto ? ' is-compacto' : '' ) . '">';

		foreach ( $filas as $p ) {
			$fecha = '';
			if ( ! empty( $p['paid_at'] ) ) {
				$fecha = $p['paid_at'];
			} elseif ( ! empty( $p['sumup_fecha'] ) ) {
				$fecha = substr( $p['sumup_fecha'], 0, 10 );
			}

			$metodo = isset( $p['method'], $nombres[ $p['method'] ] ) ? $nombres[ $p['method'] ] : '—';
			$monto  = ! empty( $p['sumup_monto'] ) && (float) $p['sumup_monto'] > (float) $p['amount']
				? sprintf(
					/* translators: 1: monto de la cuota, 2: cobrado con comisión */
					__( '%1$s (cobrado %2$s con comisión)', 'malka-crm' ),
					Malka_CRM_Settings::money( $p['amount'] ),
					Malka_CRM_Settings::money( $p['sumup_monto'] )
				)
				: Malka_CRM_Settings::money( $p['amount'] );

			$campos = array(
				__( 'Monto', 'malka-crm' )        => $monto,
				__( 'Fecha de pago', 'malka-crm' ) => $fecha ? date_i18n( 'd/m/Y', strtotime( $fecha ) ) : '—',
				__( 'Medio de pago', 'malka-crm' ) => $metodo,
			);

			if ( ! empty( $p['sumup_tx'] ) ) {
				$campos[ __( 'Comprobante', 'malka-crm' ) ] = $p['sumup_tx'];
			}

			$resumen = Malka_CRM_Settings::money( $p['amount'] ) . ( $fecha ? ' · ' . date_i18n( 'd/m/Y', strtotime( $fecha ) ) : '' );

			$html .= '<details class="pay-hecho"' . ( $compacto ? '' : ' open' ) . '><summary class="pay-hecho__top">';
			$html .= '<span class="pay-hecho__t"><strong>' . esc_html( $p['label'] ) . '</strong>';
			$html .= '<small class="pay-hecho__res">' . esc_html( $resumen ) . '</small></span>';
			$html .= '<span class="pay-estado is-paid">' . esc_html__( 'Pagado', 'malka-crm' ) . '</span></summary><dl>';

			foreach ( $campos as $k => $v ) {
				$html .= '<div><dt>' . esc_html( $k ) . '</dt><dd>' . esc_html( $v ) . '</dd></div>';
			}

			$html .= '</dl></details>';
		}

		return $html . '</div>';
	}

	/**
	 * La regla del plazo, en una línea, bajo el registro de pagos.
	 *
	 * Que el saldo no tenga fecha es raro de ver si no se explica. Dicho acá,
	 * el cliente entiende que no es un olvido y sabe de antemano qué pasa
	 * cuando el proyecto se entregue.
	 *
	 * @param array $d Datos.
	 * @return string
	 */
	private static function terms_note( $d ) {
		$hay_saldo = false;

		foreach ( $d['pagos'] as $p ) {
			// Un saldo ya pagado (o cubierto por el pago total) no tiene
			// plazo que explicar.
			if ( isset( $p['status'] ) && 'pagado' === $p['status'] ) {
				continue;
			}
			if ( isset( $p['disparo'] ) && 'entrega' === $p['disparo'] ) {
				$hay_saldo = true;
				break;
			}
		}

		if ( ! $hay_saldo ) {
			return '';
		}

		$habiles = (int) Malka_CRM_Settings::get( 'saldo_habiles', 1 );
		$entrega = (string) get_post_meta( $d['id'], self::META_ENTREGA, true );

		if ( $entrega ) {
			// Ya entregado: la fecha exacta, no un plazo que haya que contar.
			$texto = sprintf(
				/* translators: 1: fecha de entrega, 2: días hábiles, 3: fecha de vencimiento */
				_n(
					'Proyecto entregado el %1$s. El saldo tiene %2$d día hábil de plazo: vence el %3$s.',
					'Proyecto entregado el %1$s. El saldo tiene %2$d días hábiles de plazo: vence el %3$s.',
					$habiles,
					'malka-crm'
				),
				date_i18n( 'd/m/Y', strtotime( $entrega ) ),
				$habiles,
				date_i18n( 'd/m/Y', strtotime( Malka_CRM_Settings::sumar_habiles( $entrega, $habiles ) ) )
			);
		} else {
			$texto = sprintf(
				/* translators: %d: días hábiles */
				_n(
					'El saldo todavía no tiene fecha: empieza a correr cuando el proyecto esté entregado, y desde ahí hay %d día hábil para pagarlo.',
					'El saldo todavía no tiene fecha: empieza a correr cuando el proyecto esté entregado, y desde ahí hay %d días hábiles para pagarlo.',
					$habiles,
					'malka-crm'
				),
				$habiles
			);
		}

		return sprintf( '<p class="pay-regla">%s</p>', esc_html( $texto ) );
	}

	/**
	 * Estado de la factura ante el SII.
	 *
	 * @param array $d Datos.
	 * @return string
	 */
	private static function billing_block( $d ) {
		$estado = (string) get_post_meta( $d['id'], '_malka_pm_quote_dte_status', true );
		$numero = (string) get_post_meta( $d['id'], '_malka_pm_quote_dte_number', true );

		$mapa = array(
			'emitida' => __( 'Emitida', 'malka-crm' ),
			'anulada' => __( 'Anulada', 'malka-crm' ),
		);

		ob_start();
		echo '<div class="billing-module">';
		printf( '<div class="section-title">%s</div>', esc_html__( 'Facturación (SII)', 'malka-crm' ) );
		// Misma estructura que el registro de pagos de al lado: título con
		// línea y, debajo, la tarjeta. Así los dos arrancan a la misma altura.
		$clave = isset( $mapa[ $estado ] ) ? $estado : 'pendiente';
		echo '<div class="billing-card">';
		printf(
			'<div class="billing-row"><span class="billing-k">%1$s</span><span class="billing-state is-%2$s">%3$s</span></div>',
			esc_html__( 'Estado', 'malka-crm' ),
			esc_attr( $clave ),
			esc_html( isset( $mapa[ $estado ] ) ? $mapa[ $estado ] : __( 'No emitida', 'malka-crm' ) )
		);
		printf(
			'<div class="billing-row"><span class="billing-k">%1$s</span><span class="billing-v">%2$s</span></div>',
			esc_html__( 'N° factura', 'malka-crm' ),
			esc_html( $numero ? $numero : '—' )
		);
		echo '</div></div>';
		return ob_get_clean();
	}

	/**
	 * Cómo desea pagar y los datos bancarios.
	 *
	 * @param array $d Datos.
	 * @param array $s Ajustes.
	 * @return string
	 */
	private static function bank_block( $d, $s ) {
		if ( $d['caducada'] || empty( $d['pagos'] ) ) {
			return '';
		}

		/*
		 * Primero la firma, después el pago. Mostrarle la cuenta antes de
		 * que acepte es pedirle plata por un trabajo que todavía no
		 * encargó, y deja sin registro quién lo encargó.
		 */
		if ( empty( $d['pagable'] ) ) {
			return '';
		}

		$pendientes = 0;
		foreach ( $d['pagos'] as $p ) {
			$e = self::payment_view( $d['id'], $p );
			if ( 'paid' !== $e['state'] ) {
				$pendientes++;
			}
		}

		if ( ! $pendientes ) {
			return '';
		}

		$tasa = isset( $s['comision_tarjeta'] ) ? (float) $s['comision_tarjeta'] : 3.81;

		ob_start();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['avisado'] ) ) {
			printf(
				'<p class="aviso-ok">%s</p>',
				esc_html( self::t( 'aviso_gracias', __( 'Gracias: nos llegó tu aviso. Lo confirmamos en cuanto veamos el abono en la cuenta.', 'malka-crm' ) ) )
			);
		}

		echo '<div class="payment-selector" id="boxSelectorPago">';
		printf( '<div class="section-title">%s</div>', esc_html( self::t( 'pago_titulo', __( 'Cómo deseas pagar', 'malka-crm' ) ) ) );
		printf(
			'<label><input class="radio-pay" type="radio" name="mq-metodo" value="transferencia" checked /> %s</label>',
			esc_html( self::t( 'pago_transferencia', __( 'Transferencia bancaria (sin comisión)', 'malka-crm' ) ) )
		);
		printf(
			'<label><input class="radio-pay" type="radio" name="mq-metodo" value="tarjeta" /> %s</label>',
			esc_html(
				sprintf(
					/* translators: comisión */
					__( 'Tarjeta de crédito / débito (+%s%% SumUp)', 'malka-crm' ),
					number_format_i18n( $tasa, 2 )
				)
			)
		);
		echo '</div>';

		echo '<div class="bank-info-box" id="boxDatosBancarios">';
		printf(
			'<p>%s <span id="txtMontoTransferir"></span></p>',
			esc_html( self::t( 'transferir_monto', __( 'Monto a transferir:', 'malka-crm' ) ) )
		);

		foreach (
			array(
				$s['banco_nombre'],
				$s['banco_tipo'],
				$s['banco_cuenta'] ? 'N° ' . $s['banco_cuenta'] : '',
				$s['banco_titular'],
				$s['banco_rut'] ? 'RUT ' . $s['banco_rut'] : '',
				$s['email'],
			) as $linea
		) {
			if ( trim( (string) $linea ) ) {
				echo '<p>' . esc_html( $linea ) . '</p>';
			}
		}
		echo self::aviso_pago_form( $d ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		printf(
			'<a class="btn-pay" id="btnProcesarPago" href="%1$s" rel="noopener">%2$s</a>',
			esc_url( self::pay_link( $d['id'], $d['pagos'][0] ) ),
			esc_html( self::t( 'pago_tarjeta', __( 'Proceder al pago seguro', 'malka-crm' ) ) )
		);

		return ob_get_clean();
	}

	/**
	 * El botón con que el cliente avisa que ya transfirió.
	 *
	 * Una transferencia ocurre fuera del sitio. Sin este botón, el panel sólo
	 * se entera cuando ella revisa la cuenta, y el medio de pago no queda
	 * registrado en ninguna parte.
	 *
	 * @param array $d Datos de la cotización.
	 * @return string
	 */
	private static function aviso_pago_form( $d ) {
		$primera = '';

		foreach ( $d['pagos'] as $p ) {
			$e = self::payment_view( $d['id'], $p );

			if ( 'paid' !== $e['state'] ) {
				$primera = isset( $p['id'] ) ? (string) $p['id'] : '';
				break;
			}
		}

		if ( '' === $primera ) {
			return '';
		}

		ob_start();

		printf(
			'<form class="aviso-pago" id="formAvisoPago" method="post" action="%s">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		echo '<input type="hidden" name="action" value="malka_crm_pago_avisado" />';
		printf( '<input type="hidden" name="quote" value="%d" />', (int) $d['id'] );
		printf( '<input type="hidden" name="cuota" id="avisoCuota" value="%s" />', esc_attr( $primera ) );
		echo '<input type="hidden" name="metodo" id="avisoMetodo" value="transferencia" />';
		wp_nonce_field( 'malka_crm_avisar_pago_' . $d['id'], 'malka_pago_nonce' );

		printf(
			'<p class="aviso-pago__h">%s</p>',
			esc_html( self::t( 'aviso_transferencia', __( 'Cuando hayas transferido, avísanos acá y lo confirmamos al ver la cuenta.', 'malka-crm' ) ) )
		);
		printf(
			'<button type="submit" class="btn-aviso">%s</button>',
			esc_html( self::t( 'aviso_boton', __( 'Ya hice la transferencia', 'malka-crm' ) ) )
		);

		echo '</form>';

		return ob_get_clean();
	}

	/**
	 * El desglose de lo que se va a pagar ahora.
	 *
	 * @param array $d Datos.
	 * @return string
	 */
	private static function pay_now_block( $d ) {
		if ( $d['caducada'] || empty( $d['pagos'] ) || empty( $d['pagable'] ) ) {
			return '';
		}

		ob_start();
		echo '<div class="pay-now-box" id="boxPagarAhora">';
		printf(
			'<div class="pay-now-row"><span id="lblTextoPagarAhoraSub">%s</span><span id="lblPagarAhoraSub"></span></div>',
			esc_html__( 'Monto seleccionado', 'malka-crm' )
		);
		printf(
			'<div class="pay-now-row surcharge" id="rowComisionPagar" hidden><span id="lblComisionTexto">%s</span><span id="lblComision"></span></div>',
			esc_html__( 'Comisión tarjeta', 'malka-crm' )
		);
		printf(
			'<div class="pay-now-row main-amt"><span>%s</span><span id="lblPagarAhoraTotal"></span></div>',
			esc_html__( 'Total a pagar', 'malka-crm' )
		);
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * El link de pago de una cuota.
	 *
	 * No apunta a SumUp directamente: apunta al panel, que recién ahí calcula
	 * cuánto hay que pagar hoy. Esto importa cuando la cuota está atrasada,
	 * porque el interés corre día a día y un link con el monto congelado
	 * cobraría de menos sin que nadie se diera cuenta.
	 *
	 * @param int   $qid Cotización.
	 * @param array $p   Cuota.
	 * @return string
	 */
	public static function pay_link( $qid, $p ) {
		return add_query_arg(
			array(
				'action' => 'malka_crm_pay',
				'quote'  => (int) $qid,
				'cuota'  => isset( $p['id'] ) ? $p['id'] : '',
				// Este botón sólo aparece con la tarjeta elegida, así que el
				// medio no se adivina: se sabe antes de mandarlo a pagar.
				'metodo' => 'sumup',
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Cuánto hay que pagar hoy por una cuota.
	 *
	 * @param int   $qid Cotización.
	 * @param array $p   Cuota.
	 * @return array monto, interes, base
	 */
	public static function amount_due( $qid, $p ) {
		$base = (int) $p['amount'];
		// Con el plazo resuelto: un saldo cuyo reloj no ha empezado nunca
		// puede estar atrasado, por mucho que la cotización sea de hace meses.
		$estado  = self::payment_view( $qid, $p );
		$interes = 0;

		if ( 'overdue' === $estado['state'] && class_exists( 'Malka_CRM_Dashboard' ) ) {
			$interes = (int) Malka_CRM_Dashboard::late_fee( $base, abs( (int) $estado['days'] ) );
		}

		return array(
			'base'    => $base,
			'interes' => $interes,
			'monto'   => $base + $interes,
			'dias'    => abs( (int) $estado['days'] ),
		);
	}

	/**
	 * El bloque de aceptación y firma.
	 *
	 * @param array $d Datos.
	 * @return string
	 */
	private static function sign_block( $d ) {
		$s = class_exists( 'Malka_CRM_Settings' ) ? Malka_CRM_Settings::all() : array();

		ob_start();

		if ( $d['firmada'] ) {
			echo '<div class="firma-lock" id="boxFirmaBloqueada">';
			printf( '<div class="section-title">%s</div>', esc_html__( 'Cotización firmada y aceptada', 'malka-crm' ) );
			printf( '<p class="firma-nombre">%s</p>', esc_html( isset( $d['firma']['nombre'] ) ? $d['firma']['nombre'] : '' ) );
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: RUT de quien firmó */
						__( 'RUT: %s', 'malka-crm' ),
						isset( $d['firma']['rut'] ) ? $d['firma']['rut'] : ''
					)
				)
			);

			/*
			 * Una aceptación cargada a mano trae sólo el día: decir «a las
			 * 00:00» sería inventarle una hora que nadie registró.
			 */
			$cuando = isset( $d['firma']['fecha'] ) ? (string) $d['firma']['fecha'] : '';

			if ( $cuando ) {
				$marca   = strtotime( $cuando );
				// Una fecha sin hora («2026-10-12») no trae nada que cortar, y
				// la comparación suelta la daba por hora válida: salía
				// «a las 00:00», que nadie registró.
				$con_hora = ( strlen( $cuando ) > 10 && '00:00:00' !== substr( $cuando, 11 ) );

				printf(
					'<p>%s</p>',
					esc_html(
						$con_hora
							? sprintf(
								/* translators: 1: fecha, 2: hora */
								__( 'Firmado el %1$s a las %2$s', 'malka-crm' ),
								date_i18n( 'd/m/Y', $marca ),
								date_i18n( 'H:i', $marca )
							)
							: sprintf(
								/* translators: fecha de aceptación */
								__( 'Aceptada el %s', 'malka-crm' ),
								date_i18n( 'd/m/Y', $marca )
							)
					)
				);
			}
			$suya = ( isset( $d['firma']['origen'] ) && 'panel' === $d['firma']['origen'] );

			// El respaldo de la firma electrónica, a la vista de los dos.
			if ( ! $suya && ( ! empty( $d['firma']['ip'] ) || ! empty( $d['firma']['huella'] ) ) ) {
				$filas = array();
				if ( ! empty( $d['firma']['ip'] ) ) {
					$filas[ __( 'IP', 'malka-crm' ) ] = $d['firma']['ip'];
				}
				if ( ! empty( $d['firma']['ua'] ) ) {
					$filas[ __( 'Navegador', 'malka-crm' ) ] = Malka_CRM_Firma::navegador( $d['firma']['ua'] );
				}
				if ( ! empty( $d['firma']['huella'] ) ) {
					$filas[ __( 'Huella', 'malka-crm' ) ] = Malka_CRM_Firma::corta( $d['firma']['huella'] );
				}

				echo '<dl class="firma-evidencia">';
				$nav = __( 'Navegador', 'malka-crm' );
				foreach ( $filas as $k => $v ) {
					printf( '<div><dt>%1$s</dt><dd%3$s>%2$s</dd></div>', esc_html( $k ), esc_html( $v ), $k === $nav ? '' : ' class="mono"' );
				}
				echo '</dl>';

				// Si alguien editó la cotización después de la firma, se dice.
				if ( false === Malka_CRM_Firma::intacta( $d['id'] ) ) {
					printf(
						'<p class="firma-cambio">%s</p>',
						esc_html__( 'Esta cotización se modificó después de la firma. La versión firmada queda guardada tal como estaba.', 'malka-crm' )
					);
				}
			}

			printf(
				'<p class="firma-nota">%s</p>',
				esc_html(
					sprintf(
						$suya
							/* translators: correo de contacto */
							? __( 'Aceptación registrada por el estudio. Si necesitas corregir algo, escribe a %s', 'malka-crm' )
							/* translators: correo de contacto */
							: __( 'Firma registrada de forma electrónica. Los datos ya no pueden modificarse. Si necesitas corregir algo, escribe a %s', 'malka-crm' ),
						isset( $s['email'] ) ? $s['email'] : get_option( 'admin_email' )
					)
				)
			);
			echo '</div>';
			return ob_get_clean();
		}

		if ( $d['caducada'] ) {
			// El búfer está abierto desde arriba: salirse sin cerrarlo dejaba
			// el resto del documento escribiéndose dentro de él, y lo que
			// document() devolvía se cortaba justo acá.
			ob_end_clean();

			return '';
		}

		$aviso = isset( $_GET['firma'] ) ? sanitize_key( wp_unslash( $_GET['firma'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="signature-box" id="boxFirmaCliente">';
		printf( '<div class="section-title">%s</div>', esc_html( self::t( 'firma_titulo', __( 'Aceptación y firma digital', 'malka-crm' ) ) ) );
		printf(
			'<p class="firma-intro">%s</p>',
			esc_html( self::t( 'firma_texto', __( 'Para proceder con el pago, ingresa tus datos y confirma la aceptación de esta cotización. Una vez firmada, los datos quedan bloqueados.', 'malka-crm' ) ) )
		);

		if ( 'rut' === $aviso ) {
			printf( '<p class="error" id="rutError">%s</p>', esc_html__( 'RUT inválido. Revisa el formato.', 'malka-crm' ) );
		} elseif ( 'faltan' === $aviso ) {
			printf( '<p class="error">%s</p>', esc_html__( 'Falta tu nombre o aceptar los términos.', 'malka-crm' ) );
		}

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		echo '<input type="hidden" name="action" value="malka_crm_sign_quote" />';
		printf( '<input type="hidden" name="quote" value="%d" />', (int) $d['id'] );
		wp_nonce_field( 'malka_crm_sign_' . $d['id'], 'malka_sign_nonce' );

		printf(
			'<input class="sign-input" type="text" name="nombre" id="signName" required placeholder="%s" autocomplete="name" />',
			esc_attr__( 'Nombre y apellido', 'malka-crm' )
		);
		printf(
			'<input class="sign-input" type="text" name="rut" id="signRut" required placeholder="%s" />',
			esc_attr__( 'RUT (12.345.678-9)', 'malka-crm' )
		);

		/*
		 * El enlace de los términos nunca puede faltar: lo que se marca es
		 * una aceptación, y una aceptación sin nada que leer no vale.
		 *
		 * Si hay un documento configurado, va ése. Si no, el enlace baja a
		 * las notas de esta misma cotización, que es lo que el cliente está
		 * aceptando de verdad. Sólo cuando no hay ni documento ni notas se
		 * queda sin enlace, porque ahí no habría a dónde mandarlo.
		 */
		$terminos = ! empty( $s['terminos_url'] ) ? $s['terminos_url'] : '';
		$destino  = $terminos ? $terminos : ( trim( $d['notas'] ) ? '#condiciones' : '' );
		$fuera    = (bool) $terminos;

		printf(
			'<label class="terms-label"><input type="checkbox" name="acepta" id="chkTerms" value="1" required /><span>%s</span></label>',
			$destino
				? sprintf(
					/* translators: enlace a términos y condiciones */
					esc_html__( 'He leído y acepto los %s.', 'malka-crm' ),
					sprintf(
						'<a href="%1$s"%2$s>%3$s</a>',
						esc_url( $destino ),
						$fuera ? ' target="_blank" rel="noopener"' : '',
						esc_html__( 'términos y condiciones', 'malka-crm' )
					)
				)
				: esc_html__( 'He leído y acepto los términos y condiciones.', 'malka-crm' )
		);

		printf(
			'<button class="btn-firmar" type="submit">%s</button>',
			esc_html__( 'Firmar y aceptar cotización', 'malka-crm' )
		);

		/*
		 * Se le dice qué se registra antes de que firme: es parte de que el
		 * consentimiento sea informado (y de la Ley 19.628 sobre datos
		 * personales).
		 */
		printf(
			'<p class="firma-legal">%s</p>',
			esc_html( self::t( 'firma_legal', __( 'Al firmar se registran tu nombre, RUT, fecha y hora, dirección IP y navegador, junto con una copia de esta cotización, como respaldo de tu firma electrónica simple (Ley 19.799). Te enviaremos un comprobante por correo.', 'malka-crm' ) ) )
		);
		echo '</form></div>';

		return ob_get_clean();
	}
}
