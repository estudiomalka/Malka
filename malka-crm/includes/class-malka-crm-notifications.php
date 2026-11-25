<?php
/**
 * Módulo 5: Notificaciones por correo.
 *
 * Todos los envíos son HTML con plantilla de marca y se pueden activar o
 * desactivar uno a uno desde Malka CRM → Ajustes Generales.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

class Malka_CRM_Notifications {

	public function __construct() {
		add_action( 'malka_crm_invoice_uploaded', array( $this, 'invoice_uploaded' ), 10, 3 );
		add_action( 'malka_crm_project_created', array( $this, 'project_created' ), 10, 3 );
		add_action( 'malka_crm_project_status_changed', array( $this, 'project_status_changed' ), 10, 3 );
		add_action( 'malka_crm_item_approved', array( $this, 'item_approved' ), 10, 3 );
		add_action( 'malka_crm_changes_requested', array( $this, 'changes_requested' ), 10, 3 );
		add_action( 'malka_crm_rounds_exhausted', array( $this, 'rounds_exhausted' ), 10, 3 );
		add_action( 'malka_crm_approval_reminder', array( $this, 'approval_reminder' ), 10, 4 );
		add_action( 'malka_crm_approval_escalated', array( $this, 'approval_escalated' ), 10, 3 );
		add_action( 'malka_crm_task_auto_closed', array( $this, 'task_auto_closed' ), 10, 3 );
		add_action( 'malka_crm_extra_round_granted', array( $this, 'extra_round_granted' ), 10, 4 );
		add_action( 'malka_crm_client_task_done', array( $this, 'client_task_done' ), 10, 3 );
		add_action( 'malka_crm_client_task_nota', array( $this, 'client_task_nota' ), 10, 2 );
		add_action( 'malka_crm_idea_pedida', array( $this, 'idea_pedida' ), 10, 2 );
		add_action( 'malka_crm_task_due_soon', array( $this, 'task_due_soon' ), 10, 3 );
		add_action( 'malka_crm_task_overdue', array( $this, 'task_overdue' ), 10, 3 );
		add_action( 'malka_crm_schedule_shifted', array( $this, 'schedule_shifted' ), 10, 2 );
	}

	/**
	 * Destinatario interno del estudio.
	 *
	 * @return string
	 */
	public static function studio_email() {
		$email = Malka_CRM_Helpers::get_setting( 'admin_email', '' );
		$email = $email ? $email : get_option( 'admin_email' );
		return sanitize_email( $email );
	}

	/**
	 * Nombre del remitente.
	 *
	 * @return string
	 */
	private function from_name() {
		return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * Envía un correo HTML con la plantilla de marca.
	 *
	 * @param string $to      Destinatario.
	 * @param string $subject Asunto.
	 * @param string $heading Título dentro del correo.
	 * @param string $body    HTML del cuerpo (ya escapado).
	 * @param array  $cta     array( 'url' => '', 'label' => '' ).
	 * @return bool
	 */
	public function send( $to, $subject, $heading, $body, $cta = array(), $pre = null ) {

		$to = sanitize_email( $to );
		if ( ! is_email( $to ) ) {
			return false;
		}

		// El antetítulo, si no viene: el asunto sin la marca «[Malka CRM]».
		if ( null === $pre ) {
			$pre = trim( preg_replace( '/^\[[^\]]*\]\s*/', '', (string) $subject ) );
			if ( 0 === strcasecmp( $pre, (string) $heading ) ) {
				$pre = '';
			}
		}

		$html = $this->wrap_template( $heading, $body, $cta, $pre );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $this->from_name(), self::studio_email() ),
		);

		$subject = apply_filters( 'malka_crm_email_subject', $subject, $to );
		$html    = apply_filters( 'malka_crm_email_html', $html, $subject, $to );

		return wp_mail( $to, $subject, $html, $headers );
	}

	/**
	 * Plantilla HTML del correo (tabular, compatible con Outlook y Gmail).
	 *
	 * @param string $heading Título.
	 * @param string $body    Cuerpo.
	 * @param array  $cta     Botón.
	 * @return string
	 */
	private function wrap_template( $heading, $body, $cta = array(), $pre = '' ) {
		/*
		 * El mismo diseño que los correos de Plantillas de correo: papel gris
		 * claro, hoja blanca, logo, antetítulo dorado en versalitas, título
		 * grande y botón negro redondeado. Antes estos avisos tenían su propia
		 * maqueta (borde, filete y pie) y se veían de otra marca.
		 */
		$p = class_exists( 'Malka_CRM_Messages' ) ? Malka_CRM_Messages::paleta() : array(
			'tinta'  => '#333333',
			'cuerpo' => '#757575',
			'oro'    => '#82725C',
			'borde'  => '#EAEAEA',
			'fondo'  => '#FFFFFF',
			'papel'  => '#FAFAFA',
		);

		$s    = class_exists( 'Malka_CRM_Settings' ) ? Malka_CRM_Settings::all() : array();
		$logo = ! empty( $s['logo_url'] ) ? $s['logo_url'] : '';
		$font = "'Helvetica Neue',Helvetica,Arial,sans-serif";

		$button = '';
		if ( ! empty( $cta['url'] ) && ! empty( $cta['label'] ) ) {
			$button = sprintf(
				'<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:26px 0 0;"><tr><td style="background:%1$s;border-radius:100px;">' .
				'<a href="%2$s" style="display:inline-block;padding:14px 28px;color:#FFFFFF;font-family:%4$s;font-size:14px;font-weight:600;text-decoration:none;">%3$s</a></td></tr></table>',
				esc_attr( $p['tinta'] ),
				esc_url( $cta['url'] ),
				esc_html( $cta['label'] ),
				$font
			);
		}

		// Lo que venga en negrita va en el color del título, como en las plantillas.
		$body = str_replace( '<strong>', '<strong style="color:' . esc_attr( $p['tinta'] ) . ';font-weight:600;">', $body );

		return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" /><title>' . esc_html( $heading ) . '</title></head>' .
			'<body style="margin:0;padding:0;background:' . esc_attr( $p['papel'] ) . ';">' .
			'<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:' . esc_attr( $p['papel'] ) . ';">' .
			'<tr><td align="center" style="padding:32px 16px;">' .
			'<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="width:100%;max-width:600px;background:' . esc_attr( $p['fondo'] ) . ';">' .
			'<tr><td style="padding:36px 36px 40px;font-family:' . $font . ';font-size:14px;line-height:1.75;color:' . esc_attr( $p['cuerpo'] ) . ';">' .
			( $logo ? '<p style="margin:0 0 28px;"><img src="' . esc_url( $logo ) . '" width="150" alt="" style="display:block;width:150px;max-width:150px;height:auto;border:0;" /></p>' : '' ) .
			( '' !== $pre ? '<p style="margin:0 0 6px;color:' . esc_attr( $p['oro'] ) . ';font-size:11px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;">' . esc_html( $pre ) . '</p>' : '' ) .
			( '' !== $heading ? '<h1 style="margin:0 0 22px;color:' . esc_attr( $p['tinta'] ) . ';font-size:26px;font-weight:700;line-height:1.2;letter-spacing:-.01em;text-transform:uppercase;">' . esc_html( $heading ) . '</h1>' : '' ) .
			$body . $button .
			'</td></tr></table></td></tr></table></body></html>';
	}

	/**
	 * El nombre del cliente de un negocio: la empresa, o su nombre.
	 *
	 * @param int $pid Negocio.
	 * @return string
	 */
	public static function empresa_de( $pid ) {
		$qid = class_exists( 'Malka_CRM_Quotes' ) ? (int) Malka_CRM_Quotes::get_project_quote_id( $pid ) : 0;
		$cid = (int) get_post_meta( $pid, '_malka_pm_client_id', true );
		if ( ( ! $cid || 'malka_client' !== get_post_type( $cid ) ) && $qid ) {
			$cid = (int) get_post_meta( $qid, '_malka_pm_client_id', true );
		}
		$n = '';
		if ( $cid && 'malka_client' === get_post_type( $cid ) && class_exists( 'Malka_CRM_Clients' ) ) {
			$c = Malka_CRM_Clients::get( $cid );
			$n = $c['empresa'] ? $c['empresa'] : $c['nombre'];
		}
		if ( '' === $n && $qid ) {
			$n = (string) get_post_meta( $qid, '_malka_pm_client_company', true );
			$n = $n ? $n : (string) get_post_meta( $qid, '_malka_pm_client_name', true );
		}
		if ( $n && class_exists( 'Malka_CRM_Documents' ) && method_exists( 'Malka_CRM_Documents', 'nombre_bonito' ) ) {
			$n = Malka_CRM_Documents::nombre_bonito( $n );
		}
		return trim( wp_strip_all_tags( $n ) );
	}

	/**
	 * Un recuadro como el de las plantillas: título dorado y líneas debajo.
	 *
	 * @param string $titulo Título del recuadro.
	 * @param array  $lineas Líneas (texto plano).
	 * @return string
	 */
	public static function recuadro( $titulo, $lineas ) {
		$p   = class_exists( 'Malka_CRM_Messages' ) ? Malka_CRM_Messages::paleta() : array( 'oro' => '#82725C', 'borde' => '#EAEAEA', 'fondo' => '#FFFFFF', 'cuerpo' => '#757575' );
		$out = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 22px;border-collapse:separate;"><tr><td style="padding:22px 24px;border:1px solid ' . esc_attr( $p['borde'] ) . ';border-radius:4px;background:' . esc_attr( $p['fondo'] ) . ';">';
		$out .= '<p style="margin:0 0 ' . ( $lineas ? '10px' : '0' ) . ';color:' . esc_attr( $p['oro'] ) . ';font-size:15px;font-weight:600;line-height:1.5;">' . esc_html( $titulo ) . '</p>';
		$n = count( $lineas );
		foreach ( array_values( $lineas ) as $k => $l ) {
			$out .= '<p style="margin:0 0 ' . ( $k === $n - 1 ? '0' : '4px' ) . ';color:' . esc_attr( $p['cuerpo'] ) . ';font-size:14px;line-height:1.6;">' . esc_html( $l ) . '</p>';
		}
		return $out . '</td></tr></table>';
	}

	/**
	 * URL de la pestaña de proyectos.
	 *
	 * @param int $project_id ID opcional.
	 * @return string
	 */
	private function projects_url( $project_id = 0 ) {
		$url = function_exists( 'wc_get_account_endpoint_url' )
			? wc_get_account_endpoint_url( Malka_CRM_WooCommerce::EP_PROJECTS )
			: home_url( '/mi-cuenta/proyectos/' );

		return $project_id ? add_query_arg( 'malka_project', (int) $project_id, $url ) : $url;
	}

	/**
	 * URL de la pestaña de facturas.
	 *
	 * @return string
	 */
	private function invoices_url() {
		return function_exists( 'wc_get_account_endpoint_url' )
			? wc_get_account_endpoint_url( Malka_CRM_WooCommerce::EP_INVOICES )
			: home_url( '/mi-cuenta/facturas/' );
	}

	/**
	 * Aviso al cliente: nueva factura disponible.
	 *
	 * @param int $invoice_id ID.
	 * @param int $user_id    Cliente.
	 * @param int $order_id   Pedido.
	 */
	public function invoice_uploaded( $invoice_id, $user_id, $order_id ) {

		if ( ! Malka_CRM_Helpers::get_setting( 'notify_invoice' ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$body  = '<p style="margin:0 0 14px;">' . sprintf(
			/* translators: %s: nombre del cliente */
			esc_html__( 'Hola %s, ya tienes un nuevo documento disponible en tu portal privado.', 'malka-crm' ),
			esc_html( $user->first_name ? $user->first_name : $user->display_name )
		) . '</p>';
		$body .= '<p style="margin:0;"><strong>' . esc_html( get_the_title( $invoice_id ) ) . '</strong>';
		$body .= $order_id ? ' · ' . esc_html( sprintf( __( 'Pedido #%d', 'malka-crm' ), $order_id ) ) : '';
		$body .= '</p>';

		$this->send(
			$user->user_email,
			__( 'Nueva factura disponible', 'malka-crm' ),
			__( 'Tu factura está lista', 'malka-crm' ),
			$body,
			array(
				'url'   => $this->invoices_url(),
				'label' => __( 'Ver mi factura', 'malka-crm' ),
			)
		);
	}

	/**
	 * Aviso al cliente: proyecto creado.
	 *
	 * @param int           $project_id  ID.
	 * @param int           $customer_id Cliente.
	 * @param WC_Order|null $order       Pedido.
	 */
	public function project_created( $project_id, $customer_id, $order = null ) {

		if ( ! Malka_CRM_Helpers::get_setting( 'notify_project' ) ) {
			return;
		}

		$user = get_userdata( $customer_id );
		if ( ! $user ) {
			return;
		}

		$body  = '<p style="margin:0 0 14px;">' . sprintf(
			/* translators: %s: nombre del cliente */
			esc_html__( 'Hola %s, ya abrimos tu proyecto en el portal.', 'malka-crm' ),
			esc_html( $user->first_name ? $user->first_name : $user->display_name )
		) . '</p>';
		$body .= '<p style="margin:0 0 14px;"><strong>' . esc_html( get_the_title( $project_id ) ) . '</strong></p>';
		$body .= '<p style="margin:0;">' . esc_html__( 'Ahí verás la carta Gantt con cada etapa, su fecha y su avance en tiempo real. Cuando una etapa esté completa podrás aprobarla desde el mismo panel.', 'malka-crm' ) . '</p>';

		$this->send(
			$user->user_email,
			__( 'Tu proyecto ya está abierto', 'malka-crm' ),
			__( 'Comenzamos', 'malka-crm' ),
			$body,
			array(
				'url'   => $this->projects_url( $project_id ),
				'label' => __( 'Ver mi proyecto', 'malka-crm' ),
			)
		);
	}

	/**
	 * Aviso al cliente: cambió el estado del proyecto.
	 *
	 * @param int    $project_id ID.
	 * @param string $new_status Nuevo estado.
	 * @param string $old_status Estado anterior.
	 */
	public function project_status_changed( $project_id, $new_status, $old_status ) {

		if ( ! Malka_CRM_Helpers::get_setting( 'notify_status' ) ) {
			return;
		}

		$user = get_userdata( (int) get_post_meta( $project_id, '_malka_pm_client_id', true ) );
		if ( ! $user ) {
			return;
		}

		$statuses = Malka_CRM_Helpers::project_statuses();
		$label    = isset( $statuses[ $new_status ] ) ? $statuses[ $new_status ] : $new_status;
		$progress = Malka_CRM_Helpers::get_project_progress( $project_id );

		$body  = '<p style="margin:0 0 14px;">' . esc_html__( 'Actualizamos el estado de tu proyecto:', 'malka-crm' ) . '</p>';
		$body .= '<p style="margin:0 0 6px;"><strong>' . esc_html( get_the_title( $project_id ) ) . '</strong></p>';
		$body .= '<p style="margin:0;">' . esc_html__( 'Estado:', 'malka-crm' ) . ' <strong>' . esc_html( $label ) . '</strong> · ' . esc_html( $progress . '% ' . __( 'completado', 'malka-crm' ) ) . '</p>';

		$this->send(
			$user->user_email,
			__( 'Actualización de tu proyecto', 'malka-crm' ),
			$label,
			$body,
			array(
				'url'   => $this->projects_url( $project_id ),
				'label' => __( 'Ver avance', 'malka-crm' ),
			)
		);
	}

	/**
	 * Aviso al estudio: el cliente aprobó.
	 *
	 * @param int    $project_id ID.
	 * @param string $key        Clave.
	 * @param array  $record     Registro.
	 */
	public function item_approved( $project_id, $key, $record ) {

		if ( ! Malka_CRM_Helpers::get_setting( 'notify_approval' ) ) {
			return;
		}

		$user  = get_userdata( (int) $record['user'] );
		$label = Malka_CRM_Approvals::key_label( $project_id, $key );

		$body  = '<p style="margin:0 0 14px;">' . sprintf(
			/* translators: 1: cliente, 2: etapa */
			esc_html__( '%1$s aprobó "%2$s".', 'malka-crm' ),
			esc_html( $user ? $user->display_name : __( 'El cliente', 'malka-crm' ) ),
			esc_html( $label )
		) . '</p>';
		$body .= '<p style="margin:0;">' . esc_html( get_the_title( $project_id ) ) . ' · ' . esc_html( date_i18n( 'd/m/Y H:i', strtotime( $record['date'] ) ) ) . '</p>';

		$this->send(
			self::studio_email(),
			sprintf( '[Malka CRM] %s', __( 'Aprobación recibida', 'malka-crm' ) ),
			__( 'Aprobación recibida', 'malka-crm' ),
			$body,
			array(
				'url'   => Malka_CRM_Helpers::panel_project_url( $project_id ),
				'label' => __( 'Abrir proyecto', 'malka-crm' ),
			)
		);
	}

	/**
	 * Aviso al estudio: el cliente pidió cambios.
	 *
	 * @param int    $project_id ID.
	 * @param string $key        Clave.
	 * @param array  $record     Registro.
	 */
	public function changes_requested( $project_id, $key, $record ) {

		if ( ! Malka_CRM_Helpers::get_setting( 'notify_approval' ) ) {
			return;
		}

		$user  = get_userdata( (int) $record['user'] );
		$label = Malka_CRM_Approvals::key_label( $project_id, $key );

		$body  = '<p style="margin:0 0 14px;">' . sprintf(
			/* translators: 1: cliente, 2: etapa */
			esc_html__( '%1$s solicitó cambios en "%2$s".', 'malka-crm' ),
			esc_html( $user ? $user->display_name : __( 'El cliente', 'malka-crm' ) ),
			esc_html( $label )
		) . '</p>';
		$body .= '<blockquote style="margin:0 0 14px;padding:12px 16px;background:#F9F8F6;border-left:3px solid #B8A792;">' . nl2br( esc_html( $record['note'] ) ) . '</blockquote>';
		$body .= '<p style="margin:0;">' . esc_html( get_the_title( $project_id ) ) . ' · ' . esc_html( date_i18n( 'd/m/Y H:i', strtotime( $record['date'] ) ) ) . '</p>';

		$this->send(
			self::studio_email(),
			sprintf( '[Malka CRM] %s', __( 'Solicitud de cambios', 'malka-crm' ) ),
			__( 'El cliente pidió cambios', 'malka-crm' ),
			$body,
			array(
				'url'   => Malka_CRM_Helpers::panel_project_url( $project_id ),
				'label' => __( 'Abrir proyecto', 'malka-crm' ),
			)
		);
	}

	/**
	 * Aviso al estudio: el cliente agotó las rondas incluidas (oportunidad de upsell).
	 *
	 * @param int    $project_id ID.
	 * @param string $key        Clave.
	 * @param array  $record     Registro.
	 */
	public function rounds_exhausted( $project_id, $key, $record ) {

		if ( ! Malka_CRM_Helpers::get_setting( 'notify_approval' ) ) {
			return;
		}

		$user  = get_userdata( (int) get_post_meta( $project_id, '_malka_pm_client_id', true ) );
		$label = Malka_CRM_Approvals::key_label( $project_id, $key );
		$limit = Malka_CRM_Approvals::get_round_limit( $project_id );

		$body  = '<p style="margin:0 0 14px;">' . sprintf(
			/* translators: 1: cliente, 2: etapa, 3: rondas incluidas */
			esc_html__( '%1$s agotó las %3$d rondas incluidas en "%2$s".', 'malka-crm' ),
			esc_html( $user ? $user->display_name : __( 'El cliente', 'malka-crm' ) ),
			esc_html( $label ),
			(int) $limit
		) . '</p>';
		$body .= '<p style="margin:0 0 14px;">' . esc_html__( 'Toda ronda adicional se cotiza aparte. Envía la cotización antes de ejecutar cualquier cambio nuevo.', 'malka-crm' ) . '</p>';
		$body .= '<p style="margin:0;">' . esc_html( get_the_title( $project_id ) ) . '</p>';

		$this->send(
			self::studio_email(),
			sprintf( '[Malka CRM] %s', __( 'Rondas agotadas — oportunidad de cotizar', 'malka-crm' ) ),
			__( 'Rondas de revisión agotadas', 'malka-crm' ),
			$body,
			array(
				'url'   => Malka_CRM_Helpers::panel_project_url( $project_id ),
				'label' => __( 'Abrir proyecto', 'malka-crm' ),
			)
		);
	}

	/**
	 * Recordatorio al cliente: tiene una aprobación pendiente.
	 *
	 * @param int    $project_id ID.
	 * @param string $key        Clave.
	 * @param int    $days       Días esperando.
	 * @param int    $count      Recordatorio previo nº.
	 */
	public function approval_reminder( $project_id, $key, $days, $count = 0 ) {

		$user = get_userdata( (int) get_post_meta( $project_id, '_malka_pm_client_id', true ) );
		if ( ! $user ) {
			return;
		}

		$label = Malka_CRM_Approvals::key_label( $project_id, $key );
		$final = Malka_CRM_Approvals::FINAL_KEY === $key;

		$body  = '<p style="margin:0 0 14px;">' . sprintf(
			/* translators: %s: nombre del cliente */
			esc_html__( 'Hola %s, tenemos una entrega esperando tu revisión.', 'malka-crm' ),
			esc_html( $user->first_name ? $user->first_name : $user->display_name )
		) . '</p>';
		$body .= '<p style="margin:0 0 14px;"><strong>' . esc_html( $label ) . '</strong> · ' . esc_html( get_the_title( $project_id ) ) . '</p>';
		$body .= '<p style="margin:0 0 14px;">' . sprintf(
			/* translators: %d: días */
			esc_html( _n( 'Está lista desde hace %d día.', 'Está lista desde hace %d días.', (int) $days, 'malka-crm' ) ),
			(int) $days
		) . '</p>';
		$body .= '<p style="margin:0;">' . (
			$final
				? esc_html__( 'Tu aprobación cierra el proyecto y libera la entrega de los archivos finales.', 'malka-crm' )
				: esc_html__( 'Tu aprobación nos habilita a avanzar a la siguiente etapa dentro del plazo comprometido.', 'malka-crm' )
		) . '</p>';

		$this->send(
			$user->user_email,
			__( 'Tienes una aprobación pendiente', 'malka-crm' ),
			__( 'Esperando tu revisión', 'malka-crm' ),
			$body,
			array(
				'url'   => $this->projects_url( $project_id ),
				'label' => __( 'Revisar y aprobar', 'malka-crm' ),
			)
		);
	}

	/**
	 * Escalamiento interno: una aprobación lleva demasiado tiempo detenida.
	 *
	 * @param int    $project_id ID.
	 * @param string $key        Clave.
	 * @param int    $days       Días esperando.
	 */
	public function approval_escalated( $project_id, $key, $days ) {

		$user  = get_userdata( (int) get_post_meta( $project_id, '_malka_pm_client_id', true ) );
		$label = Malka_CRM_Approvals::key_label( $project_id, $key );

		$body  = '<p style="margin:0 0 14px;">' . sprintf(
			/* translators: 1: etapa, 2: días */
			esc_html__( '"%1$s" lleva %2$d días esperando aprobación del cliente.', 'malka-crm' ),
			esc_html( $label ),
			(int) $days
		) . '</p>';
		$body .= '<p style="margin:0 0 14px;">' . esc_html( get_the_title( $project_id ) );
		$body .= $user ? ' · ' . esc_html( $user->display_name ) : '';
		$body .= '</p>';
		$body .= '<p style="margin:0;">' . esc_html__( 'El bloqueo ya no es de producción: es de respuesta. Levanta el teléfono.', 'malka-crm' ) . '</p>';

		$this->send(
			self::studio_email(),
			sprintf( '[Malka CRM] %s', __( 'Aprobación detenida', 'malka-crm' ) ),
			__( 'Proyecto bloqueado por falta de respuesta', 'malka-crm' ),
			$body,
			array(
				'url'   => Malka_CRM_Helpers::panel_project_url( $project_id ),
				'label' => __( 'Abrir proyecto', 'malka-crm' ),
			)
		);
	}

	/**
	 * Aviso doble al acreditarse una ronda comprada: confirmación al cliente y
	 * alerta al estudio para no ejecutar cambios sin registro.
	 *
	 * @param int      $project_id ID.
	 * @param string   $key        Clave.
	 * @param int      $quantity   Cantidad.
	 * @param WC_Order $order      Pedido.
	 */
	public function extra_round_granted( $project_id, $key, $quantity, $order = null ) {

		$user  = get_userdata( (int) get_post_meta( $project_id, '_malka_pm_client_id', true ) );
		$label = Malka_CRM_Approvals::key_label( $project_id, $key );

		if ( $user ) {
			$body  = '<p style="margin:0 0 14px;">' . sprintf(
				/* translators: 1: nombre, 2: cantidad */
				esc_html( _n( 'Hola %1$s, acreditamos %2$d ronda adicional en tu proyecto.', 'Hola %1$s, acreditamos %2$d rondas adicionales en tu proyecto.', (int) $quantity, 'malka-crm' ) ),
				esc_html( $user->first_name ? $user->first_name : $user->display_name ),
				(int) $quantity
			) . '</p>';
			$body .= '<p style="margin:0 0 14px;"><strong>' . esc_html( $label ) . '</strong> · ' . esc_html( get_the_title( $project_id ) ) . '</p>';
			$body .= '<p style="margin:0;">' . esc_html__( 'Ya puedes volver a solicitar cambios desde tu portal.', 'malka-crm' ) . '</p>';

			$this->send(
				$user->user_email,
				__( 'Ronda adicional habilitada', 'malka-crm' ),
				__( 'Todo listo', 'malka-crm' ),
				$body,
				array(
					'url'   => $this->projects_url( $project_id ),
					'label' => __( 'Ir a mi proyecto', 'malka-crm' ),
				)
			);
		}

		if ( ! Malka_CRM_Helpers::get_setting( 'notify_approval' ) ) {
			return;
		}

		$admin_body  = '<p style="margin:0 0 14px;">' . sprintf(
			/* translators: 1: cantidad, 2: etapa */
			esc_html__( 'Se acreditaron %1$d ronda(s) pagada(s) en "%2$s".', 'malka-crm' ),
			(int) $quantity,
			esc_html( $label )
		) . '</p>';
		$admin_body .= '<p style="margin:0;">' . esc_html( get_the_title( $project_id ) );
		$admin_body .= $order ? ' · ' . esc_html( sprintf( __( 'Pedido #%s', 'malka-crm' ), $order->get_order_number() ) ) : '';
		$admin_body .= '</p>';

		$this->send(
			self::studio_email(),
			sprintf( '[Malka CRM] %s', __( 'Ronda adicional vendida', 'malka-crm' ) ),
			__( 'Ronda adicional pagada y acreditada', 'malka-crm' ),
			$admin_body,
			array(
				'url'   => Malka_CRM_Helpers::panel_project_url( $project_id ),
				'label' => __( 'Abrir proyecto', 'malka-crm' ),
			)
		);
	}

	/**
	 * Aviso al estudio: el cliente completó su tarea.
	 *
	 * @param int   $project_id ID.
	 * @param array $task       Tarea.
	 * @param bool  $late       Fuera de plazo.
	 */
	public function client_task_done( $project_id, $task, $late = false ) {

		if ( ! Malka_CRM_Helpers::get_setting( 'notify_client_task' ) ) {
			return;
		}

		$user = get_userdata( (int) get_post_meta( $project_id, '_malka_pm_client_id', true ) );

		$body  = '<p style="margin:0 0 14px;">' . sprintf(
			/* translators: 1: cliente, 2: tarea */
			esc_html__( '%1$s marcó como completada su tarea "%2$s".', 'malka-crm' ),
			esc_html( $user ? $user->display_name : __( 'El cliente', 'malka-crm' ) ),
			esc_html( $task['name'] )
		) . '</p>';

		if ( ! empty( $task['nota_cliente'] ) ) {
			$body .= '<p style="margin:0 0 14px;padding:12px 14px;background:#FBF7EF;border-radius:10px;">' . esc_html__( 'Su nota:', 'malka-crm' ) . ' ' . nl2br( esc_html( $task['nota_cliente'] ) ) . '</p>';
		}

		if ( $late ) {
			$body .= '<p style="margin:0 0 14px;color:#97552E;">' . esc_html__( 'Se entregó fuera del plazo acordado: revisa si el calendario ya se reprogramó.', 'malka-crm' ) . '</p>';
		}

		$body .= '<p style="margin:0;">' . esc_html( get_the_title( $project_id ) ) . '</p>';

		$this->send(
			self::studio_email(),
			sprintf( '[Malka CRM] %s', __( 'El cliente completó una tarea', 'malka-crm' ) ),
			__( 'Tarea del cliente completada', 'malka-crm' ),
			$body,
			array(
				'url'   => Malka_CRM_Helpers::panel_project_url( $project_id ),
				'label' => __( 'Abrir proyecto', 'malka-crm' ),
			)
		);
	}

	/**
	 * Aviso al estudio: un cliente quiere empezar un próximo proyecto.
	 *
	 * @param int   $cid  Cliente.
	 * @param array $idea Idea.
	 */
	public function idea_pedida( $cid, $idea ) {
		$c      = Malka_CRM_Clients::get( (int) $cid );
		$nombre = $c['empresa'] ? $c['empresa'] : $c['nombre'];
		$body   = '<p style="margin:0 0 14px;">' . sprintf(
			/* translators: 1: cliente, 2: proyecto */
			esc_html__( '%1$s quiere empezar con «%2$s». Lo pidió desde su portal.', 'malka-crm' ),
			esc_html( $nombre ),
			esc_html( $idea['titulo'] )
		) . '</p>';
		if ( ! empty( $idea['pedida_nota'] ) ) {
			$body .= '<p style="margin:0 0 14px;padding:12px 14px;background:#FBF7EF;border-radius:10px;">' . nl2br( esc_html( $idea['pedida_nota'] ) ) . '</p>';
		}
		$body .= '<p style="margin:0;">' . esc_html__( 'Desde su ficha puedes crear el negocio o la cotización con un clic.', 'malka-crm' ) . '</p>';
		$this->send(
			self::studio_email(),
			sprintf( '[Malka CRM] %s', sprintf( /* translators: cliente */ __( '%s quiere empezar un proyecto', 'malka-crm' ), $nombre ) ),
			__( 'Un cliente quiere empezar', 'malka-crm' ),
			$body,
			array(
				'url'   => Malka_CRM_Clients::edit_url( (int) $cid ),
				'label' => __( 'Abrir su ficha', 'malka-crm' ),
			)
		);
	}

	/**
	 * Aviso al estudio: el cliente dejó una nota en su tarea.
	 *
	 * @param int   $project_id ID.
	 * @param array $task       Tarea.
	 */
	public function client_task_nota( $project_id, $task ) {
		$body  = '<p style="margin:0 0 14px;">' . sprintf(
			/* translators: tarea */
			esc_html__( 'El cliente dejó una nota en su tarea "%s":', 'malka-crm' ),
			esc_html( $task['name'] )
		) . '</p>';
		$body .= '<p style="margin:0 0 14px;padding:12px 14px;background:#FBF7EF;border-radius:10px;">' . nl2br( esc_html( (string) $task['nota_cliente'] ) ) . '</p>';
		$body .= '<p style="margin:0;">' . esc_html( get_the_title( $project_id ) ) . '</p>';

		$this->send(
			self::studio_email(),
			sprintf( '[Malka CRM] %s', __( 'Nota del cliente en una tarea', 'malka-crm' ) ),
			__( 'Nota del cliente', 'malka-crm' ),
			$body,
			array(
				'url'   => Malka_CRM_Helpers::panel_project_url( $project_id ),
				'label' => __( 'Abrir proyecto', 'malka-crm' ),
			)
		);
	}

	/**
	 * Aviso al cliente: su tarea está por vencer.
	 *
	 * @param int   $project_id ID.
	 * @param array $task       Tarea.
	 * @param int   $days       Días restantes.
	 */
	public function task_due_soon( $project_id, $task, $days ) {
		Malka_CRM_Projects::recordar_tarea( $project_id, $task, 'tarea_por_vencer' );
	}

	/**
	 * Aviso al cliente (con copia al estudio): tarea vencida.
	 *
	 * @param int   $project_id ID.
	 * @param array $task       Tarea.
	 * @param int   $days       Días de atraso.
	 */
	public function task_overdue( $project_id, $task, $days ) {

		// Al cliente, con la plantilla editable de Configuración › Correos.
		Malka_CRM_Projects::recordar_tarea( $project_id, $task, 'tarea_atrasada' );
		$user = null;

		$body  = '<p style="margin:0 0 14px;"><strong>' . esc_html( $task['name'] ) . '</strong></p>';
		$body .= '<p style="margin:0 0 14px;">' . sprintf(
			/* translators: %d: días */
			esc_html( _n( 'Lleva %d día de atraso.', 'Lleva %d días de atraso.', (int) $days, 'malka-crm' ) ),
			(int) $days
		) . '</p>';
		$body .= '<p style="margin:0;">' . esc_html__( 'El calendario del proyecto se está reprogramando automáticamente. Apenas la completes, retomamos el ritmo.', 'malka-crm' ) . '</p>';

		if ( $user ) {
			$this->send(
				$user->user_email,
				__( 'Tarea pendiente vencida', 'malka-crm' ),
				__( 'Tu tarea está atrasada', 'malka-crm' ),
				$body,
				array(
					'url'   => $this->projects_url( $project_id ),
					'label' => __( 'Completar ahora', 'malka-crm' ),
				)
			);
		}

		$estudio  = self::recuadro(
			$task['name'],
			array(
				get_the_title( $project_id ),
				/* translators: %d: días */
				sprintf( _n( 'Lleva %d día de atraso.', 'Lleva %d días de atraso.', (int) $days, 'malka-crm' ), (int) $days ),
			)
		);
		$estudio .= '<p style="margin:0;">' . esc_html__( 'El calendario del proyecto se está reprogramando automáticamente. Apenas el cliente la complete, retoman el ritmo.', 'malka-crm' ) . '</p>';

		// El asunto se lee solo en la bandeja: quién, y qué tarea.
		$quien  = self::empresa_de( $project_id );
		$asunto = $quien
			/* translators: 1: cliente, 2: tarea */
			? sprintf( __( '%1$s tiene una tarea atrasada: %2$s', 'malka-crm' ), $quien, $task['name'] )
			/* translators: %s: tarea */
			: sprintf( __( 'Un cliente tiene una tarea atrasada: %s', 'malka-crm' ), $task['name'] );

		$this->send(
			self::studio_email(),
			$asunto,
			__( 'Proyecto detenido por el cliente', 'malka-crm' ),
			$estudio,
			array(
				'url'   => Malka_CRM_Helpers::panel_project_url( $project_id ),
				'label' => __( 'Abrir proyecto', 'malka-crm' ),
			),
			__( 'Tarea del cliente vencida', 'malka-crm' )
		);
	}

	/**
	 * Aviso al cliente: se reprogramó el calendario.
	 *
	 * @param int   $project_id ID.
	 * @param array $shifted    Detalle.
	 */
	public function schedule_shifted( $project_id, $shifted ) {

		if ( ! Malka_CRM_Helpers::get_setting( 'notify_shift' ) ) {
			return;
		}

		$user = get_userdata( (int) get_post_meta( $project_id, '_malka_pm_client_id', true ) );

		if ( ! $user ) {
			return;
		}

		$items = '';
		foreach ( $shifted as $item ) {
			$items .= '<li>' . esc_html( sprintf( '%s (+%d)', $item['task'], (int) $item['days'] ) ) . '</li>';
		}

		$body  = '<p style="margin:0 0 14px;">' . esc_html__( 'Actualizamos las fechas de tu proyecto para reflejar el avance real:', 'malka-crm' ) . '</p>';
		$body .= '<ul style="margin:0 0 14px;padding-left:18px;">' . $items . '</ul>';
		$body .= '<p style="margin:0;">' . esc_html__( 'Puedes ver el calendario actualizado en tu portal.', 'malka-crm' ) . '</p>';

		$this->send(
			$user->user_email,
			__( 'Calendario del proyecto actualizado', 'malka-crm' ),
			__( 'Nuevas fechas', 'malka-crm' ),
			$body,
			array(
				'url'   => $this->projects_url( $project_id ),
				'label' => __( 'Ver calendario', 'malka-crm' ),
			)
		);
	}

	/**
	 * Aviso de abono por vencer o atrasado.
	 *
	 * @param int   $quote_id ID de la cotización.
	 * @param array $alert    Abono con estado.
	 */
	public function payment_alert( $quote_id, $alert ) {

		if ( ! Malka_CRM_Helpers::get_setting( 'notify_payment' ) ) {
			return;
		}

		$user = get_userdata( (int) get_post_meta( $quote_id, '_malka_pm_client_id', true ) );

		if ( ! $user ) {
			return;
		}

		$url      = Malka_CRM_Quotes::get_url( $quote_id );
		$overdue  = ( 'overdue' === $alert['state'] );

		$body  = '<p style="margin:0 0 14px;">' . sprintf(
			/* translators: %s: nombre */
			esc_html__( 'Hola %s, un aviso sobre el calendario de pagos de tu proyecto.', 'malka-crm' ),
			esc_html( $user->first_name ? $user->first_name : $user->display_name )
		) . '</p>';
		$body .= '<p style="margin:0 0 14px;"><strong>' . esc_html( $alert['label'] ) . '</strong> · ' . esc_html( Malka_CRM_Quotes::money( $alert['amount'] ) ) . '</p>';
		$body .= '<p style="margin:0;">' . esc_html(
			$overdue
				? __( 'Este abono está vencido. Puedes revisar las formas de pago en tu cotización.', 'malka-crm' )
				: __( 'Este abono vence pronto. En tu cotización están las fechas y las formas de pago.', 'malka-crm' )
		) . '</p>';

		$this->send(
			$user->user_email,
			$overdue ? __( 'Abono pendiente vencido', 'malka-crm' ) : __( 'Abono por vencer', 'malka-crm' ),
			$overdue ? __( 'Pago atrasado', 'malka-crm' ) : __( 'Pago por vencer', 'malka-crm' ),
			$body,
			array(
				'url'   => $url ? $url : $this->projects_url(),
				'label' => __( 'Ver cotización y pagar', 'malka-crm' ),
			)
		);
	}

	/**
	 * Aviso doble: la tarea del cliente se dio por finalizada por vencimiento.
	 *
	 * @param int   $project_id ID.
	 * @param array $task       Tarea.
	 * @param int   $grace      Días de gracia.
	 */
	public function task_auto_closed( $project_id, $task, $grace ) {

		$user  = get_userdata( (int) get_post_meta( $project_id, '_malka_pm_client_id', true ) );
		$title = isset( $task['name'] ) ? $task['name'] : '';

		if ( $user ) {
			$body  = '<p style="margin:0 0 14px;">' . sprintf(
				/* translators: 1: tarea, 2: días de gracia */
				esc_html__( 'La tarea "%1$s" superó su plazo en más de %2$d días y se dio por finalizada para no detener el proyecto.', 'malka-crm' ),
				esc_html( $title ),
				(int) $grace
			) . '</p>';
			$body .= '<p style="margin:0;">' . esc_html__( 'Si aún necesitas entregar ese material, escríbenos y lo coordinamos.', 'malka-crm' ) . '</p>';

			$this->send(
				$user->user_email,
				__( 'Tarea cerrada por vencimiento de plazo', 'malka-crm' ),
				__( 'Cerramos esta etapa', 'malka-crm' ),
				$body,
				array(
					'url'   => $this->projects_url( $project_id ),
					'label' => __( 'Ver mi proyecto', 'malka-crm' ),
				)
			);
		}

		$admin_body = '<p style="margin:0;">' . sprintf(
			/* translators: 1: tarea, 2: proyecto */
			esc_html__( 'Se cerró automáticamente "%1$s" en %2$s por vencimiento del plazo del cliente.', 'malka-crm' ),
			esc_html( $title ),
			esc_html( get_the_title( $project_id ) )
		) . '</p>';

		$this->send(
			self::studio_email(),
			sprintf( '[Malka CRM] %s', __( 'Tarea cerrada por vencimiento', 'malka-crm' ) ),
			__( 'Cierre automático', 'malka-crm' ),
			$admin_body,
			array(
				'url'   => Malka_CRM_Helpers::panel_project_url( $project_id ),
				'label' => __( 'Abrir proyecto', 'malka-crm' ),
			)
		);
	}

	/**
	 * Envía un correo de prueba (usado desde Ajustes).
	 *
	 * @param string $to Destinatario.
	 * @return bool
	 */
	public function send_test( $to ) {
		$body = '<p style="margin:0;">' . esc_html__( 'Si estás leyendo esto, las notificaciones de Malka CRM salen correctamente desde tu servidor.', 'malka-crm' ) . '</p>';

		return $this->send(
			$to,
			__( 'Prueba de notificaciones — Malka CRM', 'malka-crm' ),
			__( 'Todo funcionando', 'malka-crm' ),
			$body
		);
	}
}
