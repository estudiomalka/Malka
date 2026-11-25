<?php
/**
 * Módulo 9: Planificador — avisos de plazo y desplazamiento por atraso.
 *
 * Se ejecuta dentro del cron diario:
 *   1. Avisa al cliente cuando su tarea está por vencer y cuando se atrasa.
 *   2. Por cada día de atraso desplaza automáticamente todas las tareas
 *      posteriores, manteniendo la secuencia real del proyecto.
 *
 * El desplazamiento se registra en `_malka_pm_shift_log` y se ejecuta una vez
 * al día como máximo (`_malka_pm_last_shift`) para no acumular saltos.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

class Malka_CRM_Scheduler {

	const META_LAST_SHIFT = '_malka_pm_last_shift';
	const META_SHIFT_LOG  = '_malka_pm_shift_log';
	const META_WARNED     = '_malka_pm_deadline_warned';

	public function __construct() {
		add_action( 'malka_crm_daily_check', array( $this, 'process_all' ), 5 );
		add_action( 'admin_post_malka_crm_shift_project', array( $this, 'handle_manual_shift' ) );
	}

	/**
	 * Recorre los proyectos activos.
	 *
	 * @return int Acciones realizadas.
	 */
	public function process_all() {

		$projects = get_posts(
			array(
				'post_type'      => 'malka_project',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => (int) apply_filters( 'malka_crm_cron_batch_size', 200 ),
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$actions = 0;

		foreach ( $projects as $project_id ) {

			$status = (string) get_post_meta( $project_id, '_malka_pm_status', true );

			if ( in_array( $status, array( 'pausado', 'entregado' ), true ) ) {
				continue;
			}

			$actions += $this->check_deadlines( $project_id );
			$actions += $this->shift_project( $project_id ) ? 1 : 0;
		}

		return $actions;
	}

	/**
	 * Avisos de plazo para las tareas del cliente.
	 *
	 * @param int $project_id ID.
	 * @return int Avisos enviados.
	 */
	public function check_deadlines( $project_id ) {

		if ( ! Malka_CRM_Helpers::get_setting( 'notify_deadline' ) ) {
			return 0;
		}

		$warn_days = max( 0, (int) Malka_CRM_Helpers::get_setting( 'task_warn_days', 1 ) );
		$warned    = get_post_meta( $project_id, self::META_WARNED, true );
		$warned    = is_array( $warned ) ? $warned : array();
		$sent      = 0;

		foreach ( Malka_CRM_Helpers::get_leaf_tasks( $project_id ) as $task ) {

			if ( ! Malka_CRM_Helpers::involves_client( $task ) || 100 === (int) $task['progress'] || ! empty( $task['done'] ) ) {
				continue;
			}

			$days  = Malka_CRM_Helpers::days_to_deadline( $task );
			$flags = isset( $warned[ $task['id'] ] ) ? (array) $warned[ $task['id'] ] : array();

			// Aviso previo: dentro de la ventana de alerta y todavía en plazo.
			if ( $days >= 0 && $days <= $warn_days && empty( $flags['warned'] ) ) {

				/**
				 * Una tarea del cliente está por vencer.
				 *
				 * @param int   $project_id ID.
				 * @param array $task       Tarea.
				 * @param int   $days       Días restantes.
				 */
				do_action( 'malka_crm_task_due_soon', $project_id, $task, $days );

				$flags['warned']         = 1;
				$warned[ $task['id'] ]   = $flags;
				$sent++;
			}

			// Aviso de atraso: una sola vez, el sistema ya reprograma solo.
			if ( $days < 0 && empty( $flags['late'] ) ) {

				do_action( 'malka_crm_task_overdue', $project_id, $task, abs( $days ) );

				$flags['late']         = 1;
				$warned[ $task['id'] ] = $flags;
				$sent++;
			}

			// Cierre automático pasado el período de gracia.
			$grace = max( 0, (int) Malka_CRM_Helpers::get_setting( 'client_grace_days', 2 ) );

			if ( $days < 0 && abs( $days ) > $grace && empty( $flags['closed'] ) && Malka_CRM_Helpers::get_setting( 'auto_close_overdue' ) ) {

				Malka_CRM_Helpers::update_task(
					$project_id,
					$task['id'],
					array(
						'done'     => 1,
						'progress' => 100,
						'done_at'  => current_time( 'mysql' ),
						'notes'    => trim( $task['notes'] . ' ' . __( '[Cerrada automáticamente por vencimiento del plazo]', 'malka-crm' ) ),
					)
				);

				/**
				 * Una tarea del cliente se dio por finalizada al superar el período de gracia.
				 *
				 * @param int   $project_id ID.
				 * @param array $task       Tarea.
				 * @param int   $grace      Días de gracia.
				 */
				do_action( 'malka_crm_task_auto_closed', $project_id, $task, $grace );

				$flags['closed']       = 1;
				$warned[ $task['id'] ] = $flags;
				$sent++;
			}
		}

		if ( $sent ) {
			update_post_meta( $project_id, self::META_WARNED, $warned );
		}

		return $sent;
	}

	/**
	 * Desplaza las tareas posteriores por cada día de atraso.
	 *
	 * @param int  $project_id ID.
	 * @param bool $force      Ignorar el control de una vez al día.
	 * @return bool Si hubo cambios.
	 */
	public function shift_project( $project_id, $force = false ) {

		if ( ! $force && ! Malka_CRM_Helpers::get_setting( 'autoshift_enabled' ) ) {
			return false;
		}

		$today = current_time( 'Y-m-d' );

		if ( ! $force && get_post_meta( $project_id, self::META_LAST_SHIFT, true ) === $today ) {
			return false;
		}

		$tasks = Malka_CRM_Helpers::get_project_tasks( $project_id );

		if ( empty( $tasks ) ) {
			return false;
		}

		$today_ts = strtotime( $today );
		$shifted  = array();

		foreach ( $tasks as $i => $task ) {

			if ( 'stage' === $task['type'] || 100 === (int) $task['progress'] || ! empty( $task['done'] ) ) {
				continue;
			}

			$end_ts = strtotime( $task['end'] );

			if ( $end_ts >= $today_ts ) {
				continue;
			}

			$delta = (int) floor( ( $today_ts - $end_ts ) / DAY_IN_SECONDS );

			if ( $delta < 1 ) {
				continue;
			}

			// La tarea atrasada se estira hasta hoy.
			$tasks[ $i ]['end'] = $today;

			// Todo lo que empezaba después de su fin original se corre igual.
			foreach ( $tasks as $j => $other ) {

				if ( $j === $i || 'stage' === $other['type'] || ! empty( $other['done'] ) ) {
					continue;
				}

				if ( strtotime( $other['start'] ) < $end_ts ) {
					continue;
				}

				$tasks[ $j ]['start'] = gmdate( 'Y-m-d', strtotime( $other['start'] ) + ( $delta * DAY_IN_SECONDS ) );
				$tasks[ $j ]['end']   = gmdate( 'Y-m-d', strtotime( $other['end'] ) + ( $delta * DAY_IN_SECONDS ) );
			}

			$shifted[] = array(
				'task'  => $task['name'],
				'owner' => $task['owner'],
				'days'  => $delta,
			);
		}

		update_post_meta( $project_id, self::META_LAST_SHIFT, $today );

		if ( empty( $shifted ) ) {
			return false;
		}

		Malka_CRM_Helpers::save_tasks( $project_id, $tasks );

		$log = get_post_meta( $project_id, self::META_SHIFT_LOG, true );
		$log = is_array( $log ) ? $log : array();

		array_unshift(
			$log,
			array(
				'date'  => current_time( 'mysql' ),
				'items' => $shifted,
			)
		);

		update_post_meta( $project_id, self::META_SHIFT_LOG, array_slice( $log, 0, 30 ) );

		/**
		 * Se reprogramó el calendario por atraso.
		 *
		 * @param int   $project_id ID.
		 * @param array $shifted    Detalle de lo desplazado.
		 */
		do_action( 'malka_crm_schedule_shifted', $project_id, $shifted );

		return true;
	}

	/**
	 * Historial de reprogramaciones.
	 *
	 * @param int $project_id ID.
	 * @return array
	 */
	public static function get_log( $project_id ) {
		$log = get_post_meta( (int) $project_id, self::META_SHIFT_LOG, true );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Reprogramación manual desde WP-Admin.
	 */
	public function handle_manual_shift() {

		if ( ! Malka_CRM_Helpers::current_user_is_manager() ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ), 403 );
		}

		$project_id = isset( $_GET['project_id'] ) ? absint( $_GET['project_id'] ) : 0;

		check_admin_referer( 'malka_crm_shift_project_' . $project_id );

		$this->shift_project( $project_id, true );

		wp_safe_redirect( Malka_CRM_Helpers::panel_project_url( $project_id ) );
		exit;
	}
}
