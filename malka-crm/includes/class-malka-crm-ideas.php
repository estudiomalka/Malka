<?php
/**
 * Próximos proyectos de un cliente, y cotizar desde un negocio.
 *
 * Con algunos clientes el trabajo llega por partes: hoy un sitio, en unos
 * meses las redes, después un catálogo. Acá se anota lo que viene en la
 * ficha del cliente (sólo lo ve el estudio) y, cuando llega el momento,
 * cada idea pasa a negocio o a cotización con un clic, con el cliente y el
 * nombre ya puestos. Y un negocio que partió sin cotización la genera
 * desde su ficha, ya enlazada.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ideas y cotizar desde negocio.
 */
class Malka_CRM_Ideas {

	const META = '_malka_pm_cli_ideas';

	/**
	 * Ganchos.
	 */
	public static function init() {
		add_action( 'wp_ajax_malka_crm_cli_idea', array( __CLASS__, 'ajax' ) );
		add_action( 'admin_post_malka_crm_cotizar_negocio', array( __CLASS__, 'handle_cotizar_negocio' ) );
	}

	/* ───────────────────────────  Crear cosas  ─────────────────────── */

	/**
	 * Busca la ficha de cliente de un negocio.
	 *
	 * @param int $pid Negocio.
	 * @return int Cliente (0 si no hay).
	 */
	public static function cliente_de_negocio( $pid ) {
		$cid = (int) get_post_meta( $pid, '_malka_pm_client_id', true );
		if ( $cid && Malka_CRM_Clients::POST_TYPE === get_post_type( $cid ) ) {
			return $cid;
		}
		$nombre = trim( (string) get_post_meta( $pid, '_malka_pm_client_name', true ) );
		if ( '' === $nombre ) {
			return 0;
		}
		foreach ( Malka_CRM_Clients::all() as $c ) {
			foreach ( array( $c['empresa'], $c['nombre'], $c['alias'] ) as $n ) {
				if ( '' !== $n && 0 === strcasecmp( trim( $n ), $nombre ) ) {
					return (int) $c['id'];
				}
			}
		}
		return 0;
	}

	/**
	 * Una cotización nueva, con el cliente y el nombre puestos.
	 *
	 * @param int    $cid    Cliente (ficha), o 0.
	 * @param string $titulo Nombre.
	 * @param int    $monto  Monto aproximado (0 si no hay).
	 * @param int    $pid    Negocio a enlazar, o 0.
	 * @return int
	 */
	public static function crear_cotizacion( $cid, $titulo, $monto = 0, $pid = 0 ) {
		$qid = wp_insert_post(
			array(
				'post_type'   => Malka_CRM_Quotes::POST_TYPE,
				'post_title'  => $titulo ? $titulo : __( 'Cotización', 'malka-crm' ),
				'post_status' => 'publish',
			),
			true
		);
		if ( is_wp_error( $qid ) || ! $qid ) {
			return 0;
		}

		if ( $cid ) {
			$c = Malka_CRM_Clients::get( $cid );
			$m = array(
				'_malka_pm_client_name'    => $c['nombre'],
				'_malka_pm_client_company' => $c['empresa'],
				'_malka_pm_client_email'   => $c['email'],
				'_malka_pm_client_rut'     => $c['rut'],
				'_malka_pm_client_city'    => $c['ciudad'],
				'_malka_pm_client_phone'   => $c['fono'],
				'_malka_pm_client_id'      => (int) $cid,
			);
			foreach ( $m as $k => $v ) {
				if ( '' !== $v && 0 !== $v ) {
					update_post_meta( $qid, $k, $v );
				}
			}
		} elseif ( $pid ) {
			update_post_meta( $qid, '_malka_pm_client_company', (string) get_post_meta( $pid, '_malka_pm_client_name', true ) );
		}

		$hoy  = current_time( 'Y-m-d' );
		$dias = (int) Malka_CRM_Settings::get( 'validez_dias', 10 );
		update_post_meta( $qid, '_malka_pm_quote_issued', $hoy );
		update_post_meta( $qid, '_malka_pm_quote_expires', gmdate( 'Y-m-d', strtotime( $hoy ) + ( max( 1, $dias ) * DAY_IN_SECONDS ) ) );
		update_post_meta( $qid, '_malka_pm_quote_status', 'sin_respuesta' );
		update_post_meta( $qid, '_malka_pm_quote_total', max( 0, (int) $monto ) );
		update_post_meta( $qid, Malka_CRM_Reminders::META_DESDE, $hoy );
		update_post_meta( $qid, Malka_CRM_Quotes::META_PAYMENTS, Malka_CRM_Quotes::default_plan( max( 0, (int) $monto ) ) );

		if ( $pid ) {
			update_post_meta( $qid, '_malka_pm_project_id', (int) $pid );
			update_post_meta( $pid, '_malka_pm_quote_id', (int) $qid );
		}

		Malka_CRM_Quote_Page::assign_number( $qid, get_post( $qid ), true );
		Malka_CRM_Quote_Page::sync_status( $qid );

		return (int) $qid;
	}

	/**
	 * Un negocio nuevo para un cliente.
	 *
	 * @param int    $cid    Cliente.
	 * @param string $titulo Nombre.
	 * @return int
	 */
	public static function crear_negocio( $cid, $titulo ) {
		$c   = Malka_CRM_Clients::get( $cid );
		$pid = wp_insert_post(
			array(
				'post_type'   => Malka_CRM_Projects::POST_TYPE,
				'post_title'  => $titulo ? $titulo : __( 'Negocio sin nombre', 'malka-crm' ),
				'post_status' => 'publish',
			),
			true
		);
		if ( is_wp_error( $pid ) || ! $pid ) {
			return 0;
		}
		update_post_meta( $pid, '_malka_pm_client_id', (int) $cid );
		update_post_meta( $pid, '_malka_pm_client_name', $c['empresa'] ? $c['empresa'] : $c['nombre'] );
		update_post_meta( $pid, '_malka_pm_status', 'briefing' );
		update_post_meta( $pid, '_malka_pm_start', current_time( 'Y-m-d' ) );
		return (int) $pid;
	}

	/**
	 * Dirección para generar la cotización de un negocio.
	 *
	 * @param int $pid Negocio.
	 * @return string
	 */
	public static function cotizar_negocio_url( $pid ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'malka_crm_cotizar_negocio',
					'negocio' => (int) $pid,
				),
				admin_url( 'admin-post.php' )
			),
			'malka_crm_cotizar_negocio_' . (int) $pid
		);
	}

	/**
	 * Genera la cotización de un negocio y abre el editor.
	 */
	public static function handle_cotizar_negocio() {
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'No tienes permisos para esto.', 'malka-crm' ) );
		}
		$pid = isset( $_GET['negocio'] ) ? absint( $_GET['negocio'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'malka_crm_cotizar_negocio_' . $pid );
		if ( ! $pid || Malka_CRM_Projects::POST_TYPE !== get_post_type( $pid ) ) {
			wp_die( esc_html__( 'Ese negocio no existe.', 'malka-crm' ) );
		}
		// Si ya tiene, no se hace otra: se abre la que está.
		$ya = (int) Malka_CRM_Quotes::get_project_quote_id( $pid );
		if ( $ya && 'trash' !== get_post_status( $ya ) ) {
			wp_safe_redirect( Malka_CRM_Quote_Editor::edit_url( $ya ) );
			exit;
		}
		$qid = self::crear_cotizacion( self::cliente_de_negocio( $pid ), get_the_title( $pid ), 0, $pid );
		if ( ! $qid ) {
			wp_die( esc_html__( 'No se pudo crear la cotización.', 'malka-crm' ) );
		}
		// Las ideas que apuntaban a este negocio quedan con su cotización.
		$cid = self::cliente_de_negocio( $pid );
		if ( $cid ) {
			$ideas = self::de( $cid );
			foreach ( $ideas as $i => $idea ) {
				if ( (int) $idea['pid'] === $pid && ! $idea['qid'] ) {
					$ideas[ $i ]['qid'] = $qid;
				}
			}
			update_post_meta( $cid, self::META, $ideas );
		}
		wp_safe_redirect( add_query_arg( 'desde_negocio', 1, Malka_CRM_Quote_Editor::edit_url( $qid ) ) );
		exit;
	}

	/* ─────────────────────────────  Ideas  ─────────────────────────── */

	/**
	 * Las ideas de un cliente.
	 *
	 * @param int $cid Cliente.
	 * @return array[]
	 */
	public static function de( $cid ) {
		$l   = get_post_meta( $cid, self::META, true );
		$out = array();
		foreach ( is_array( $l ) ? $l : array() as $i ) {
			if ( empty( $i['id'] ) ) {
				continue;
			}
			$out[] = wp_parse_args(
				$i,
				array(
					'titulo' => '',
					'nota'   => '',
					'monto'  => 0,
					'creada' => '',
					'pid'    => 0,
					'qid'    => 0,
					'portal' => 1,
					'pedida' => '',
					'pedida_nota' => '',
				)
			);
		}
		return $out;
	}

	/**
	 * El cliente pidió empezar con una idea desde su portal.
	 *
	 * @param int    $cid  Cliente.
	 * @param string $id   Idea.
	 * @param string $nota Lo que escribió.
	 * @return array|null La idea, o null si no está.
	 */
	public static function pedir( $cid, $id, $nota = '' ) {
		$ideas = self::de( $cid );
		foreach ( $ideas as $k => $i ) {
			if ( $i['id'] !== $id || empty( $i['portal'] ) ) {
				continue;
			}
			$primera                    = empty( $i['pedida'] );
			$ideas[ $k ]['pedida']      = current_time( 'mysql' );
			$ideas[ $k ]['pedida_nota'] = sanitize_textarea_field( $nota );
			update_post_meta( $cid, self::META, $ideas );
			if ( $primera && class_exists( 'Malka_CRM_Projects' ) ) {
				$c = Malka_CRM_Clients::get( $cid );
				Malka_CRM_Projects::agregar_estudio(
					sprintf( /* translators: 1: proyecto, 2: cliente */ __( 'Cotizar «%1$s» para %2$s (lo pidió desde su portal)', 'malka-crm' ), $i['titulo'], $c['empresa'] ? $c['empresa'] : $c['nombre'] ),
					current_time( 'Y-m-d' )
				);
			}
			do_action( 'malka_crm_idea_pedida', (int) $cid, $ideas[ $k ] );
			return $ideas[ $k ];
		}
		return null;
	}

	/**
	 * Las que el cliente ve en su portal: las marcadas visibles que todavía
	 * no pasaron a negocio ni a cotización. Sólo se muestra el nombre.
	 *
	 * @param int $cid Cliente.
	 * @return array[]
	 */
	public static function visibles( $cid ) {
		$out = array();
		foreach ( self::de( $cid ) as $i ) {
			if ( empty( $i['portal'] ) ) {
				continue;
			}
			$vivo = function ( $id ) {
				return $id && false !== get_post_status( $id ) && 'trash' !== get_post_status( $id );
			};
			if ( $vivo( $i['pid'] ) || $vivo( $i['qid'] ) ) {
				continue;
			}
			$out[] = $i;
		}
		return $out;
	}

	/**
	 * Anota una idea nueva.
	 *
	 * @param int    $cid    Cliente.
	 * @param string $titulo Nombre.
	 * @param string $nota   Nota.
	 * @param int    $monto  Monto aproximado.
	 * @return string Id de la idea.
	 */
	public static function agregar( $cid, $titulo, $nota = '', $monto = 0 ) {
		$ideas   = self::de( $cid );
		$id      = 'i' . strtolower( wp_generate_password( 8, false, false ) );
		$ideas[] = array(
			'id'     => $id,
			'titulo' => sanitize_text_field( $titulo ),
			'nota'   => sanitize_textarea_field( $nota ),
			'monto'  => max( 0, (int) $monto ),
			'creada' => current_time( 'Y-m-d' ),
			'pid'    => 0,
			'qid'    => 0,
		);
		update_post_meta( $cid, self::META, $ideas );
		return $id;
	}

	/**
	 * Agregar, editar, borrar, pasar a negocio o cotizar.
	 */
	public static function ajax() {
		$cid = isset( $_POST['cliente'] ) ? absint( $_POST['cliente'] ) : 0;
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) || ! $cid || Malka_CRM_Clients::POST_TYPE !== get_post_type( $cid ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'malka-crm' ) ), 403 );
		}
		check_ajax_referer( 'malka_crm_cli_idea_' . $cid, 'nonce' );

		$op    = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$id    = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$ideas = self::de( $cid );
		$pos   = null;
		foreach ( $ideas as $k => $i ) {
			if ( $i['id'] === $id ) {
				$pos = $k;
			}
		}

		if ( 'agregar' === $op ) {
			$titulo = isset( $_POST['titulo'] ) ? sanitize_text_field( wp_unslash( $_POST['titulo'] ) ) : '';
			if ( '' === trim( $titulo ) ) {
				wp_send_json_error( array( 'message' => __( 'Ponle un nombre.', 'malka-crm' ) ), 400 );
			}
			$ideas[] = array(
				'id'     => 'i' . strtolower( wp_generate_password( 8, false, false ) ),
				'titulo' => $titulo,
				'nota'   => isset( $_POST['nota'] ) ? sanitize_textarea_field( wp_unslash( $_POST['nota'] ) ) : '',
				'monto'  => isset( $_POST['monto'] ) ? (int) preg_replace( '/\D/', '', wp_unslash( $_POST['monto'] ) ) : 0, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'creada' => current_time( 'Y-m-d' ),
				'pid'    => 0,
				'qid'    => 0,
			);
			update_post_meta( $cid, self::META, $ideas );
			wp_send_json_success( array( 'html' => self::panel( $cid ) ) );
		}

		if ( null === $pos ) {
			wp_send_json_error( array( 'message' => __( 'No encontré esa idea.', 'malka-crm' ) ), 404 );
		}

		if ( 'editar' === $op ) {
			$campo = isset( $_POST['campo'] ) ? sanitize_key( wp_unslash( $_POST['campo'] ) ) : '';
			$valor = isset( $_POST['valor'] ) ? wp_unslash( $_POST['valor'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( 'titulo' === $campo && '' !== trim( $valor ) ) {
				$ideas[ $pos ]['titulo'] = sanitize_text_field( $valor );
			} elseif ( 'nota' === $campo ) {
				$ideas[ $pos ]['nota'] = sanitize_textarea_field( $valor );
			} elseif ( 'monto' === $campo ) {
				$ideas[ $pos ]['monto'] = (int) preg_replace( '/\D/', '', $valor );
			}
			update_post_meta( $cid, self::META, $ideas );
			wp_send_json_success();
		}

		if ( 'portal' === $op ) {
			$ideas[ $pos ]['portal'] = empty( $ideas[ $pos ]['portal'] ) ? 1 : 0;
			update_post_meta( $cid, self::META, $ideas );
			wp_send_json_success( array( 'html' => self::panel( $cid ) ) );
		}

		if ( 'borrar' === $op ) {
			array_splice( $ideas, $pos, 1 );
			update_post_meta( $cid, self::META, $ideas );
			wp_send_json_success( array( 'html' => self::panel( $cid ) ) );
		}

		if ( 'negocio' === $op ) {
			$pid = (int) $ideas[ $pos ]['pid'];
			if ( ! $pid || 'trash' === get_post_status( $pid ) || false === get_post_status( $pid ) ) {
				$pid = self::crear_negocio( $cid, $ideas[ $pos ]['titulo'] );
				if ( ! $pid ) {
					wp_send_json_error( array( 'message' => __( 'No se pudo crear el negocio.', 'malka-crm' ) ), 500 );
				}
				$ideas[ $pos ]['pid'] = $pid;
				// Si ya estaba cotizada, el negocio queda con esa cotización.
				$qid = (int) $ideas[ $pos ]['qid'];
				if ( $qid && 'trash' !== get_post_status( $qid ) ) {
					update_post_meta( $qid, '_malka_pm_project_id', $pid );
					update_post_meta( $pid, '_malka_pm_quote_id', $qid );
				}
				update_post_meta( $cid, self::META, $ideas );
			}
			wp_send_json_success( array( 'url' => Malka_CRM_Projects::edit_url( $pid ) ) );
		}

		if ( 'cotizar' === $op ) {
			$qid = (int) $ideas[ $pos ]['qid'];
			if ( ! $qid || 'trash' === get_post_status( $qid ) || false === get_post_status( $qid ) ) {
				$pid = (int) $ideas[ $pos ]['pid'];
				$pid = ( $pid && 'trash' !== get_post_status( $pid ) && false !== get_post_status( $pid ) ) ? $pid : 0;
				$qid = self::crear_cotizacion( $cid, $ideas[ $pos ]['titulo'], (int) $ideas[ $pos ]['monto'], $pid );
				if ( ! $qid ) {
					wp_send_json_error( array( 'message' => __( 'No se pudo crear la cotización.', 'malka-crm' ) ), 500 );
				}
				$ideas[ $pos ]['qid'] = $qid;
				update_post_meta( $cid, self::META, $ideas );
			}
			wp_send_json_success( array( 'url' => Malka_CRM_Quote_Editor::edit_url( $qid ) ) );
		}

		wp_send_json_error( array( 'message' => __( 'Operación desconocida.', 'malka-crm' ) ), 400 );
	}

	/**
	 * El panel «Próximos proyectos» de la ficha del cliente.
	 *
	 * @param int $cid Cliente.
	 * @return string
	 */
	public static function panel( $cid ) {
		$ideas = self::de( $cid );
		$ic    = array(
			'neg' => '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="4.5" width="12" height="8.5" rx="1.5"/><path d="M5.5 4.5V3h5v1.5"/></svg>',
			'cot' => '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 2h5.5L12 4.5V14H4z"/><path d="M6 8h4M6 10.5h4"/></svg>',
			'ojo'    => '<svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1.5 8S4 3.5 8 3.5 14.5 8 14.5 8 12 12.5 8 12.5 1.5 8 1.5 8z"/><circle cx="8" cy="8" r="2"/></svg>',
			'ojo_no' => '<svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1.5 8S4 3.5 8 3.5 14.5 8 14.5 8 12 12.5 8 12.5 1.5 8 1.5 8z"/><circle cx="8" cy="8" r="2"/><path d="M2.5 13.5l11-11"/></svg>',
			'bin' => '<svg class="esc-x-ic" viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.15" stroke-linecap="round" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8"/></svg>',
		);

		ob_start();
		printf(
			'<section class="esc-panel esc-ideas" id="esc-ideas" data-cliente="%1$d" data-nonce="%2$s">',
			(int) $cid,
			esc_attr( wp_create_nonce( 'malka_crm_cli_idea_' . $cid ) )
		);
		echo '<div class="esc-ph"><div><h2>' . esc_html__( 'Próximos proyectos', 'malka-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Lo que viene con este cliente. Con el ojo eliges si ve el nombre en su portal (la nota y el monto nunca); cuando llegue el momento, pásalo a negocio o cotízalo', 'malka-crm' ) . '</p></div></div>';

		echo '<div class="esc-ideas__l">';
		foreach ( $ideas as $i ) {
			$pid_ok = $i['pid'] && false !== get_post_status( $i['pid'] ) && 'trash' !== get_post_status( $i['pid'] );
			$qid_ok = $i['qid'] && false !== get_post_status( $i['qid'] ) && 'trash' !== get_post_status( $i['qid'] );
			$pre    = array();
			if ( $pid_ok ) {
				$pre[] = __( 'Negocio creado', 'malka-crm' );
			}
			if ( ! empty( $i['pedida'] ) && ! $pid_ok && ! $qid_ok ) {
				$pre[] = sprintf( /* translators: fecha */ __( 'El cliente quiere empezar · %s', 'malka-crm' ), date_i18n( 'j M', strtotime( $i['pedida'] ) ) );
			}
			if ( $qid_ok ) {
				$num   = (string) get_post_meta( $i['qid'], Malka_CRM_Quote_Page::META_NUM, true );
				$pre[] = $num ? sprintf( /* translators: número */ __( 'Cotización N° %s', 'malka-crm' ), $num ) : __( 'Cotizada', 'malka-crm' );
			}
			printf(
				'<div class="esc-ideas__i%1$s" data-id="%2$s">' .
				'<div class="esc-ideas__c">%3$s<input class="esc-ideas__t" data-campo="titulo" value="%4$s" aria-label="%5$s" />' .
				'<textarea class="esc-ideas__n" data-campo="nota" rows="1" placeholder="%6$s" aria-label="%7$s">%8$s</textarea>%20$s</div>' .
				'<label class="esc-ideas__m"><span>$</span><input data-campo="monto" inputmode="numeric" value="%9$s" placeholder="%10$s" aria-label="%10$s" /></label>' .
				'<div class="esc-ideas__acc">' .
				'<button type="button" class="esc-ideas__b" data-idea-op="negocio">%11$s<span>%12$s</span></button>' .
				'<button type="button" class="esc-ideas__b" data-idea-op="cotizar">%13$s<span>%14$s</span></button>' .
				'<button type="button" class="esc-ideas__x esc-ideas__ojo%17$s" data-idea-op="portal" title="%18$s" aria-label="%18$s">%19$s</button>' .
				'<button type="button" class="esc-ideas__x" data-idea-op="borrar" title="%15$s" aria-label="%15$s">%16$s</button></div></div>',
				( ( $pid_ok || $qid_ok ) ? ' is-hecha' : '' ) . ( ! empty( $i['pedida'] ) && ! $pid_ok && ! $qid_ok ? ' is-pedida' : '' ),
				esc_attr( $i['id'] ),
				$pre ? '<span class="esc-ideas__pre">' . esc_html( implode( ' · ', $pre ) ) . '</span>' : '',
				esc_attr( $i['titulo'] ),
				esc_attr__( 'Nombre', 'malka-crm' ),
				esc_attr__( 'Una nota: qué incluye, cuándo lo quiere…', 'malka-crm' ),
				esc_attr__( 'Nota', 'malka-crm' ),
				esc_textarea( $i['nota'] ),
				$i['monto'] ? esc_attr( number_format( (int) $i['monto'], 0, ',', '.' ) ) : '',
				esc_attr__( 'Monto aprox.', 'malka-crm' ),
				$ic['neg'], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( $pid_ok ? __( 'Ver negocio', 'malka-crm' ) : __( 'Crear negocio', 'malka-crm' ) ),
				$ic['cot'], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( $qid_ok ? __( 'Ver cotización', 'malka-crm' ) : __( 'Cotizar', 'malka-crm' ) ),
				esc_attr__( 'Quitar', 'malka-crm' ),
				$ic['bin'], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				empty( $i['portal'] ) ? ' is-off' : '',
				esc_attr( empty( $i['portal'] ) ? __( 'Oculta para el cliente. Clic para mostrar el nombre en su portal', 'malka-crm' ) : __( 'El cliente ve el nombre en su portal (Próximos proyectos). Clic para ocultarla', 'malka-crm' ) ),
				empty( $i['portal'] ) ? $ic['ojo_no'] : $ic['ojo'], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				( ! empty( $i['pedida_nota'] ) && ! $pid_ok && ! $qid_ok ) ? '<span class="esc-ideas__pedida">' . esc_html__( 'El cliente escribió:', 'malka-crm' ) . ' «' . esc_html( $i['pedida_nota'] ) . '»</span>' : ''
			);
		}
		if ( ! $ideas ) {
			echo '<p class="esc-empty">' . esc_html__( 'Nada anotado todavía. ¿Qué otro proyecto podría venir con este cliente?', 'malka-crm' ) . '</p>';
		}
		echo '</div>';

		printf(
			'<div class="esc-ideas__f"><input type="text" class="esc-ideas__in" data-idea-nueva placeholder="%1$s" />' .
			'<input type="text" class="esc-ideas__in esc-ideas__in--m" data-idea-monto inputmode="numeric" placeholder="%2$s" />' .
			'<button type="button" class="esc-btn esc-btn--soft" data-idea-agregar>+ %3$s</button></div>',
			esc_attr__( 'Ej.: Catálogo de verano, Redes sociales 2027…', 'malka-crm' ),
			esc_attr__( '$ aprox. (opcional)', 'malka-crm' ),
			esc_html__( 'Anotar', 'malka-crm' )
		);
		echo '</section>';
		return ob_get_clean();
	}
}
