<?php
/**
 * Primeros pasos y estados vacíos.
 *
 * Un cero es una mentira: dice que facturaste nada cuando en realidad no le has
 * contado nada al sistema. Mientras falte un dato, el panel que depende de él
 * explica qué falta en vez de mostrar números inventados.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

class Malka_CRM_Onboarding {

	const OPT_DISMISS = 'malka_crm_onboarding_oculto';

	public function __construct() {
		add_action( 'admin_post_malka_crm_skip_guide', array( $this, 'handle_skip' ) );
	}

	/**
	 * Enlace firmado para saltarse la guía.
	 *
	 * @return string
	 */
	public static function skip_url() {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=malka_crm_skip_guide' ),
			'malka_crm_skip_guide'
		);
	}

	/**
	 * Oculta la guía y vuelve al escritorio.
	 */
	public function handle_skip() {
		if ( ! Malka_CRM_Helpers::current_user_is_manager() ) {
			wp_die( esc_html__( 'No tienes permisos para esto.', 'malka-crm' ) );
		}

		check_admin_referer( 'malka_crm_skip_guide' );
		self::dismiss();

		wp_safe_redirect( home_url( '/escritorio/' ) );
		exit;
	}

	/**
	 * Los seis pasos, con su estado calculado.
	 *
	 * @return array[]
	 */
	public static function steps() {
		$s = Malka_CRM_Settings::all();

		$pasos = array(
			array(
				'id'      => 'empresa',
				'titulo'  => __( 'Los datos de tu estudio', 'malka-crm' ),
				'texto'   => __( 'Razón social, RUT, giro y dirección. Aparecen en cada cotización y en cada documento que emitas, así que se escriben una vez y nunca más.', 'malka-crm' ),
				'tiempo'  => __( '2 minutos', 'malka-crm' ),
				'listo'   => ( '' !== trim( $s['razon_social'] ) && '' !== trim( $s['rut'] ) ),
				'url'     => Malka_CRM_Dashboard::view_url( 'configuracion' ),
				'accion'  => __( 'Completar', 'malka-crm' ),
			),
			array(
				'id'      => 'costos',
				'titulo'  => __( 'Tus costos fijos', 'malka-crm' ),
				'texto'   => __( 'Arriendo, internet, software, contadora, hosting. Es el paso que más cuesta y el que más vale: sin esto no se puede calcular tu tarifa, y sin tarifa todo lo demás son números sueltos.', 'malka-crm' ),
				'tiempo'  => __( '10 minutos · ten a mano la cartola del mes pasado', 'malka-crm' ),
				'listo'   => ( Malka_CRM_Settings::costs_total() > 0 ),
				'url'     => Malka_CRM_Dashboard::view_url( 'configuracion' ),
				'accion'  => __( 'Completar', 'malka-crm' ),
			),
			array(
				'id'      => 'jornada',
				'titulo'  => __( 'Tu sueldo objetivo y tu horario', 'malka-crm' ),
				'texto'   => __( 'Cuánto quieres que te quede al mes y en qué bloques trabajas. De acá sale el plan del día y la meta que vas a ver arriba del escritorio.', 'malka-crm' ),
				'tiempo'  => __( '2 minutos', 'malka-crm' ),
				'listo'   => ( (int) $s['sueldo_objetivo'] > 0 && Malka_CRM_Settings::minutes_per_day() > 0 ),
				'url'     => Malka_CRM_Dashboard::view_url( 'configuracion' ),
				'accion'  => __( 'Completar', 'malka-crm' ),
			),
			array(
				'id'      => 'tarifa',
				'titulo'  => __( 'Revisa tu tarifa por hora', 'malka-crm' ),
				'texto'   => __( 'Ya quedó calculada con los tres pasos anteriores. Solo tienes que mirarla y decidir si la cobras tal cual o la redondeas hacia arriba.', 'malka-crm' ),
				'tiempo'  => __( '1 minuto', 'malka-crm' ),
				'listo'   => ( (int) $s['tarifa_cobrada'] > 0 ),
				'url'     => Malka_CRM_Dashboard::view_url( 'configuracion' ),
				'accion'  => __( 'Ver mi tarifa', 'malka-crm' ),
			),
			array(
				'id'      => 'cliente',
				'titulo'  => __( 'Tu primer cliente y su cotización', 'malka-crm' ),
				'texto'   => __( 'Acá empieza a funcionar de verdad. Al crear la cotización con sus etapas y cuotas, el escritorio se llena solo: negocios, cobranza, plan del día y análisis.', 'malka-crm' ),
				'tiempo'  => __( '10 minutos', 'malka-crm' ),
				'listo'   => self::has_quotes(),
				'url'     => class_exists( 'Malka_CRM_Quote_Editor' )
					? Malka_CRM_Quote_Editor::edit_url()
					: Malka_CRM_Dashboard::view_url( 'cotizacion' ),
				'accion'  => __( 'Crear cotización', 'malka-crm' ),
			),
			array(
				'id'       => 'sii',
				'titulo'   => __( 'Conecta la facturación electrónica', 'malka-crm' ),
				'texto'    => __( 'Para emitir facturas desde el panel. Requiere inscribir el sistema en el SII y tu certificado digital. Puedes usar todo lo demás sin esto y dejarlo para cuando tengas el trámite listo.', 'malka-crm' ),
				'tiempo'   => __( 'Depende del SII, no de ti', 'malka-crm' ),
				'listo'    => false,
				'opcional' => true,
				'url'      => Malka_CRM_Dashboard::view_url( 'configuracion' ),
				'accion'   => __( 'Ver cómo', 'malka-crm' ),
			),
		);

		return $pasos;
	}

	/**
	 * Cuántos pasos obligatorios están completos.
	 *
	 * @return array array( 'listos' => int, 'total' => int, 'pct' => int )
	 */
	public static function progress() {
		$listos = 0;
		$total  = 0;

		foreach ( self::steps() as $p ) {
			if ( ! empty( $p['opcional'] ) ) {
				continue;
			}
			$total++;
			if ( $p['listo'] ) {
				$listos++;
			}
		}

		return array(
			'listos' => $listos,
			'total'  => $total,
			'pct'    => $total > 0 ? (int) round( ( $listos / $total ) * 100 ) : 0,
		);
	}

	/**
	 * Si el escritorio debe mostrar la guía en vez del mosaico.
	 */
	public static function should_guide() {
		if ( get_option( self::OPT_DISMISS ) ) {
			return false;
		}
		$p = self::progress();
		return ( $p['listos'] < $p['total'] );
	}

	/**
	 * Si hay al menos una cotización creada.
	 */
	public static function has_quotes() {
		if ( ! class_exists( 'Malka_CRM_Quotes' ) ) {
			return false;
		}
		$q = get_posts(
			array(
				'post_type'      => Malka_CRM_Quotes::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		return ! empty( $q );
	}

	/**
	 * Qué le falta a un panel para poder calcular sus números.
	 *
	 * @param string $panel Identificador del panel.
	 * @return string Texto vacío si el panel tiene todo lo que necesita.
	 */
	public static function missing_for( $panel ) {
		$s = Malka_CRM_Settings::all();

		switch ( $panel ) {
			case 'objetivo':
				if ( (int) $s['sueldo_objetivo'] <= 0 ) {
					return __( 'Falta tu sueldo objetivo para saber contra qué comparar el mes.', 'malka-crm' );
				}
				if ( Malka_CRM_Settings::costs_total() <= 0 ) {
					return __( 'Faltan tus costos fijos: sin ellos no se puede separar la venta de lo que te queda.', 'malka-crm' );
				}
				return '';

			case 'tarifa':
				$r = Malka_CRM_Settings::rate();
				return $r['completo'] ? '' : __( 'Falta tu sueldo objetivo o tus costos fijos para calcular la tarifa.', 'malka-crm' );

			case 'negocios':
				return self::has_quotes() ? '' : __( 'Todavía no hay cotizaciones. Crea la primera y este panel se llena solo.', 'malka-crm' );

			case 'cobranza':
				return self::has_quotes() ? '' : __( 'La cobranza aparece cuando una cotización tiene cuotas con fecha.', 'malka-crm' );

			case 'dia':
				if ( Malka_CRM_Settings::minutes_per_day() <= 0 ) {
					return __( 'Falta tu horario de trabajo para poder armar el plan del día.', 'malka-crm' );
				}
				return '';

			case 'horas':
				return ( Malka_CRM_Settings::hourly_rate() > 0 )
					? ''
					: __( 'Las horas donadas se valorizan con tu tarifa. Configúrala primero.', 'malka-crm' );

			case 'finanzas':
				return ( Malka_CRM_Finance::balance() > 0 || ! empty( Malka_CRM_Finance::recent( 1 ) ) )
					? ''
					: __( 'Ingresa el saldo de tu cuenta para empezar a llevar los movimientos.', 'malka-crm' );
		}

		return '';
	}

	/**
	 * Marca la guía como vista.
	 */
	public static function dismiss() {
		update_option( self::OPT_DISMISS, 1, false );
	}
}
