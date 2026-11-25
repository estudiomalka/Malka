<?php
/**
 * Módulo 6: Cron de recordatorios de aprobación.
 *
 * Una sola tarea diaria recorre los proyectos activos y busca etapas al 100%
 * cuya aprobación sigue pendiente. Envía hasta N recordatorios al cliente y,
 * pasado el umbral de escalamiento, avisa al estudio una única vez.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

class Malka_CRM_Cron {

	const HOOK = 'malka_crm_daily_check';

	public function __construct() {
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'admin_post_malka_crm_run_cron', array( $this, 'handle_manual_run' ) );
	}

	/**
	 * Programa la tarea si no existe.
	 */
	public static function maybe_schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			// Primera ejecución mañana a las 09:00 hora local.
			$timestamp = strtotime( 'tomorrow 09:00', current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
			wp_schedule_event( $timestamp ? $timestamp : time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Cancela la tarea (desactivación del plugin).
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::HOOK );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
			$timestamp = wp_next_scheduled( self::HOOK );
		}
	}

	/**
	 * Ejecución manual desde Ajustes (útil para probar sin esperar 24 h).
	 */
	public function handle_manual_run() {

		if ( ! Malka_CRM_Helpers::current_user_is_manager() ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ), 403 );
		}

		check_admin_referer( 'malka_crm_run_cron' );

		$sent = $this->run();

		wp_safe_redirect(
			add_query_arg(
				array(
					'malka_crm_msg'  => 'cron_ok',
					'malka_crm_sent' => (int) $sent,
				),
				admin_url( 'admin.php?page=malka-crm-settings' )
			)
		);
		exit;
	}

	/**
	 * Recorre proyectos y dispara recordatorios.
	 *
	 * @return int Número de correos enviados.
	 */
	public function run() {

		if ( ! Malka_CRM_Helpers::get_setting( 'reminders_enabled' ) || ! Malka_CRM_Helpers::get_setting( 'approvals_enabled' ) ) {
			return 0;
		}

		$delay    = max( 1, (int) Malka_CRM_Helpers::get_setting( 'reminder_delay', 3 ) );
		$repeat   = max( 1, (int) Malka_CRM_Helpers::get_setting( 'reminder_repeat', 4 ) );
		$max      = max( 1, (int) Malka_CRM_Helpers::get_setting( 'reminder_max', 3 ) );
		$escalate = max( 1, (int) Malka_CRM_Helpers::get_setting( 'reminder_escalate', 10 ) );
		$now      = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$sent     = 0;

		$projects = get_posts(
			array(
				'post_type'      => 'malka_project',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => (int) apply_filters( 'malka_crm_cron_batch_size', 200 ),
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		foreach ( $projects as $project_id ) {

			$status = (string) get_post_meta( $project_id, '_malka_pm_status', true );
			if ( 'pausado' === $status ) {
				continue;
			}

			$client_id = (int) get_post_meta( $project_id, '_malka_pm_client_id', true );
			if ( ! $client_id ) {
				continue;
			}

			foreach ( $this->get_pending_keys( $project_id ) as $key ) {

				$completed_at = Malka_CRM_Approvals::get_completed_at( $project_id, $key );
				if ( ! $completed_at ) {
					continue;
				}

				$days = (int) floor( ( $now - strtotime( $completed_at ) ) / DAY_IN_SECONDS );
				if ( $days < $delay ) {
					continue;
				}

				$reminder = Malka_CRM_Approvals::get_reminder( $project_id, $key );
				$count    = (int) $reminder['count'];
				$last     = $reminder['last'] ? strtotime( $reminder['last'] ) : 0;

				$due = ( 0 === $count )
					? true
					: ( $count < $max && ( ( $now - $last ) >= ( $repeat * DAY_IN_SECONDS ) ) );

				if ( $due ) {
					/**
					 * Toca recordar una aprobación pendiente.
					 *
					 * @param int    $project_id ID del proyecto.
					 * @param string $key        Clave pendiente.
					 * @param int    $days       Días desde que quedó lista.
					 * @param int    $count      Recordatorio nº (0 = el primero).
					 */
					do_action( 'malka_crm_approval_reminder', $project_id, $key, $days, $count );

					$reminder['count'] = $count + 1;
					$reminder['last']  = current_time( 'mysql' );
					Malka_CRM_Approvals::set_reminder( $project_id, $key, $reminder );
					$sent++;
				}

				// Escalamiento interno, una sola vez por clave.
				if ( $days >= $escalate && empty( $reminder['escalated'] ) ) {
					do_action( 'malka_crm_approval_escalated', $project_id, $key, $days );

					$reminder['escalated'] = 1;
					Malka_CRM_Approvals::set_reminder( $project_id, $key, $reminder );
					$sent++;
				}
			}
		}

		$sent += $this->check_payments();

		update_option( 'malka_crm_last_cron', current_time( 'mysql' ), false );

		return $sent;
	}

	/**
	 * Avisa de abonos por vencer o vencidos, una vez por abono y estado.
	 *
	 * @return int Avisos enviados.
	 */
	private function check_payments() {

		if ( ! Malka_CRM_Helpers::get_setting( 'notify_payment' ) || ! Malka_CRM_Helpers::get_setting( 'quotes_enabled' ) ) {
			return 0;
		}

		$quotes = get_posts(
			array(
				'post_type'      => Malka_CRM_Quotes::POST_TYPE,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => (int) apply_filters( 'malka_crm_cron_batch_size', 200 ),
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$sent = 0;

		foreach ( $quotes as $quote_id ) {

			$notified = get_post_meta( $quote_id, '_malka_pm_payment_notified', true );
			$notified = is_array( $notified ) ? $notified : array();
			$changed  = false;

			foreach ( Malka_CRM_Quotes::get_alerts( $quote_id ) as $alert ) {

				$flag = $alert['id'] . ':' . $alert['state'];

				if ( in_array( $flag, $notified, true ) ) {
					continue;
				}

				malka_crm()->notifications->payment_alert( $quote_id, $alert );

				$notified[] = $flag;
				$changed    = true;
				$sent++;
			}

			if ( $changed ) {
				update_post_meta( $quote_id, '_malka_pm_payment_notified', $notified );
			}
		}

		return $sent;
	}

	/**
	 * Claves listas para aprobar y todavía pendientes.
	 *
	 * @param int $project_id ID.
	 * @return array
	 */
	private function get_pending_keys( $project_id ) {

		$keys       = array();
		$require100 = (bool) Malka_CRM_Helpers::get_setting( 'approve_at_100' );

		foreach ( Malka_CRM_Helpers::get_project_tasks( $project_id ) as $task ) {

			if ( $require100 && 100 !== (int) $task['progress'] ) {
				continue;
			}

			$record = Malka_CRM_Approvals::get_approval( $project_id, $task['id'] );

			if ( 'pending' === $record['status'] ) {
				$keys[] = $task['id'];
			}
		}

		if ( Malka_CRM_Approvals::is_ready_for_final( $project_id ) ) {
			$final = Malka_CRM_Approvals::get_approval( $project_id, Malka_CRM_Approvals::FINAL_KEY );
			if ( 'pending' === $final['status'] ) {
				$keys[] = Malka_CRM_Approvals::FINAL_KEY;
			}
		}

		return $keys;
	}
}
