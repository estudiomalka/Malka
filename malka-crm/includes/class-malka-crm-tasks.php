<?php
/**
 * Módulo 8: Tareas del cliente.
 *
 * Cada tarea tiene un responsable (`studio` o `client`). Las del cliente
 * muestran plazo, cuenta regresiva y un botón para marcarlas como hechas,
 * lo que avisa al estudio y recalcula el avance de la etapa.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

class Malka_CRM_Tasks {

	public function __construct() {
		add_action( 'wp_ajax_malka_crm_client_task', array( $this, 'handle_ajax' ) );
	}

	/**
	 * El cliente marca su tarea como completada.
	 */
	public function handle_ajax() {

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Debes iniciar sesión.', 'malka-crm' ) ), 401 );
		}

		$project_id = isset( $_POST['project_id'] ) ? absint( $_POST['project_id'] ) : 0;
		$task_id    = isset( $_POST['task_id'] ) ? sanitize_key( wp_unslash( $_POST['task_id'] ) ) : '';
		$nonce      = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! $project_id || ! $task_id ) {
			wp_send_json_error( array( 'message' => __( 'Petición incompleta.', 'malka-crm' ) ), 400 );
		}

		if ( ! wp_verify_nonce( $nonce, 'malka_crm_client_task_' . $project_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Sesión caducada. Recarga la página.', 'malka-crm' ) ), 403 );
		}

		$user_id = get_current_user_id();

		if ( ! Malka_CRM_Helpers::user_owns_project( $project_id, $user_id ) && ! Malka_CRM_Helpers::current_user_is_manager() ) {
			wp_send_json_error( array( 'message' => __( 'No tienes acceso a este proyecto.', 'malka-crm' ) ), 403 );
		}

		$tasks = Malka_CRM_Helpers::get_project_tasks( $project_id );
		$found = null;

		foreach ( $tasks as $i => $task ) {

			if ( $task['id'] !== $task_id ) {
				continue;
			}

			if ( 'client' !== $task['owner'] ) {
				wp_send_json_error( array( 'message' => __( 'Esta tarea la ejecuta el estudio.', 'malka-crm' ) ), 403 );
			}

			$tasks[ $i ]['progress'] = 100;
			$tasks[ $i ]['done_at']  = current_time( 'mysql' );
			$found                   = $tasks[ $i ];
			break;
		}

		if ( ! $found ) {
			wp_send_json_error( array( 'message' => __( 'Tarea no encontrada.', 'malka-crm' ) ), 404 );
		}

		Malka_CRM_Helpers::save_tasks( $project_id, $tasks );
		Malka_CRM_Approvals::track_completions( $project_id );

		$late = Malka_CRM_Helpers::days_to_deadline( $found ) < 0;

		/**
		 * El cliente completó una tarea que le correspondía.
		 *
		 * @param int   $project_id ID del proyecto.
		 * @param array $task       Tarea completada.
		 * @param bool  $late       Si se entregó fuera de plazo.
		 */
		do_action( 'malka_crm_client_task_done', $project_id, $found, $late );

		wp_send_json_success(
			array(
				'message'  => __( 'Listo. El estudio ya fue notificado.', 'malka-crm' ),
				'progress' => Malka_CRM_Helpers::get_project_progress( $project_id ),
			)
		);
	}

	/**
	 * Estado visual del plazo de una tarea.
	 *
	 * @param array $task Tarea.
	 * @return array {class, label}
	 */
	public static function deadline_state( $task ) {

		if ( 100 === (int) $task['progress'] ) {
			return array(
				'class' => 'done',
				'label' => __( 'Completada', 'malka-crm' ),
			);
		}

		$days = Malka_CRM_Helpers::days_to_deadline( $task );

		if ( $days < 0 ) {
			return array(
				'class' => 'late',
				/* translators: %d: días de atraso */
				'label' => sprintf( _n( '%d día de atraso', '%d días de atraso', abs( $days ), 'malka-crm' ), abs( $days ) ),
			);
		}

		if ( 0 === $days ) {
			return array(
				'class' => 'today',
				'label' => __( 'Vence hoy', 'malka-crm' ),
			);
		}

		if ( $days <= (int) Malka_CRM_Helpers::get_setting( 'task_warn_days', 1 ) ) {
			return array(
				'class' => 'soon',
				/* translators: %d: días restantes */
				'label' => sprintf( _n( 'Vence en %d día', 'Vence en %d días', $days, 'malka-crm' ), $days ),
			);
		}

		return array(
			'class' => 'ok',
			/* translators: %s: fecha */
			'label' => sprintf( __( 'Hasta el %s', 'malka-crm' ), date_i18n( 'd/m/Y', strtotime( Malka_CRM_Helpers::get_task_deadline( $task ) ) ) ),
		);
	}

	/**
	 * Botón "Marcar como hecha" para el portal.
	 *
	 * @param int   $project_id ID.
	 * @param array $task       Tarea.
	 * @return string
	 */
	public static function render_client_action( $project_id, $task ) {

		if ( ! Malka_CRM_Helpers::involves_client( $task ) ) {
			return '';
		}

		$state = self::deadline_state( $task );
		$nonce = wp_create_nonce( 'malka_crm_client_task_' . $project_id );
		$grace = (int) Malka_CRM_Helpers::get_setting( 'client_grace_days', 2 );
		$days  = Malka_CRM_Helpers::days_to_deadline( $task );
		$done  = ! empty( $task['done'] ) || 100 === (int) $task['progress'];

		ob_start();
		?>
		<div class="malka-crm-clienttask" data-project="<?php echo esc_attr( $project_id ); ?>" data-task="<?php echo esc_attr( $task['id'] ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">

			<?php if ( $done ) : ?>
				<span class="malka-crm-chip malka-crm-chip--ok"><?php esc_html_e( 'Completada', 'malka-crm' ); ?></span>
			<?php endif; ?>

			<?php if ( ! $done ) : ?>
				<button type="button" class="malka-crm-btn malka-crm-task-done"><?php esc_html_e( 'Hecha', 'malka-crm' ); ?></button>
			<?php elseif ( ! empty( $task['done_at'] ) ) : ?>
				<span class="malka-crm-approval__date"><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $task['done_at'] ) ) ); ?></span>
			<?php endif; ?>


			<?php if ( ! $done && $days < 0 && Malka_CRM_Helpers::get_setting( 'auto_close_overdue' ) ) : ?>
				<?php $left = $grace - abs( $days ) + 1; ?>
				<p class="malka-crm-grace">
					<?php
					echo esc_html(
						$left > 0
							? sprintf(
								/* translators: %d: días */
								_n( 'Quedan %d día antes del cierre automático.', 'Quedan %d días antes del cierre automático.', $left, 'malka-crm' ),
								$left
							)
							: __( 'Se dará por finalizada automáticamente.', 'malka-crm' )
					);
					?>
				</p>
			<?php endif; ?>

			<p class="malka-crm-approval__feedback" role="status" aria-live="polite"></p>
		</div>
		<?php
		return ob_get_clean();
	}
}
