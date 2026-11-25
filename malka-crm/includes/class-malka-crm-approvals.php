<?php
/**
 * Módulo 4: Aprobaciones del cliente y control de rondas de revisión.
 *
 * El cliente aprueba cada etapa y la entrega final, o solicita cambios con un
 * comentario. Cada solicitud consume una ronda; al agotar las incluidas en su
 * plan el botón se bloquea y aparece el mensaje comercial configurado.
 *
 * Datos persistentes (independientes de `_malka_pm_tasks` para que editar el
 * Gantt en WP-Admin nunca borre el historial):
 *   _malka_pm_approvals    → estado, nota, fecha, usuario, IP y rondas por clave.
 *   _malka_pm_round_limit  → rondas incluidas en el proyecto (0 = ilimitadas).
 *   _malka_pm_completed_at → cuándo cada etapa llegó al 100% (base del cron).
 *   _malka_pm_reminders    → recordatorios enviados por clave.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

class Malka_CRM_Approvals {

	const META_KEY       = '_malka_pm_approvals';
	const META_LIMIT     = '_malka_pm_round_limit';
	const META_COMPLETED = '_malka_pm_completed_at';
	const META_REMINDERS = '_malka_pm_reminders';
	const FINAL_KEY      = '__final__';

	public function __construct() {
		add_action( 'wp_ajax_malka_crm_approval', array( $this, 'handle_ajax' ) );
		add_action( 'admin_post_malka_crm_reset_approval', array( $this, 'handle_reset' ) );
	}

	/**
	 * Estados posibles de una aprobación.
	 *
	 * @return array
	 */
	public static function statuses() {
		return array(
			'pending'  => __( 'Pendiente', 'malka-crm' ),
			'approved' => __( 'Aprobado', 'malka-crm' ),
			'changes'  => __( 'Cambios solicitados', 'malka-crm' ),
		);
	}

	/**
	 * Registro completo de aprobaciones de un proyecto.
	 *
	 * @param int $project_id ID.
	 * @return array
	 */
	public static function get_approvals( $project_id ) {
		$data = get_post_meta( (int) $project_id, self::META_KEY, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Aprobación de una clave concreta.
	 *
	 * @param int    $project_id ID.
	 * @param string $key        Clave.
	 * @return array
	 */
	public static function get_approval( $project_id, $key ) {

		$all = self::get_approvals( $project_id );

		$default = array(
			'status' => 'pending',
			'note'   => '',
			'date'   => '',
			'user'   => 0,
			'ip'        => '',
			'rounds'    => 0,
			'signer'    => '',
			'rut'       => '',
		);

		$key = sanitize_key( $key );

		return isset( $all[ $key ] ) ? wp_parse_args( $all[ $key ], $default ) : $default;
	}

	/**
	 * Guarda una aprobación. Las solicitudes de cambio consumen ronda.
	 *
	 * @param int    $project_id ID.
	 * @param string $key        Clave.
	 * @param string $status     approved|changes|pending.
	 * @param string $note       Comentario.
	 * @param int    $user_id    Usuario.
	 * @return array
	 */
	public static function set_approval( $project_id, $key, $status, $note = '', $user_id = 0, $signer = '', $rut = '' ) {

		$statuses = array_keys( self::statuses() );
		$status   = in_array( $status, $statuses, true ) ? $status : 'pending';
		$key      = sanitize_key( $key );
		$all      = self::get_approvals( $project_id );
		$previous = self::get_approval( $project_id, $key );

		$rounds = (int) $previous['rounds'];
		if ( 'changes' === $status ) {
			$rounds++;
		}

		$record = array(
			'status' => $status,
			'note'   => sanitize_textarea_field( $note ),
			'date'   => current_time( 'mysql' ),
			'user'   => (int) $user_id,
			'ip'        => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			'rounds'    => $rounds,
			'signer'    => sanitize_text_field( $signer ),
			'rut'       => sanitize_text_field( $rut ),
		);

		$all[ $key ] = $record;
		update_post_meta( (int) $project_id, self::META_KEY, $all );

		if ( 'pending' !== $status ) {
			self::log_activity(
				$project_id,
				array(
					'key'    => $key,
					'label'  => self::key_label( $project_id, $key ),
					'status' => $status,
					'note'   => $record['note'],
					'signer' => $record['signer'],
					'rut'    => $record['rut'],
					'date'   => $record['date'],
					'user'   => $record['user'],
				)
			);
		}

		self::log_activity(
			$project_id,
			array(
				'key'    => $key,
				'label'  => self::key_label( $project_id, $key ),
				'status' => $status,
				'note'   => $record['note'],
				'signer' => $record['signer'],
				'rut'    => $record['rut'],
				'date'   => $record['date'],
				'user'   => $record['user'],
			)
		);

		// Resuelto: deja de tener sentido seguir recordando.
		self::clear_reminders( $project_id, $key );

		return $record;
	}

	/* ─────────────────────────── Rondas ─────────────────────────── */

	/**
	 * Rondas incluidas en el proyecto (0 = ilimitadas).
	 *
	 * @param int $project_id ID.
	 * @return int
	 */
	public static function get_round_limit( $project_id ) {

		$limit = get_post_meta( (int) $project_id, self::META_LIMIT, true );

		if ( '' === $limit || null === $limit ) {
			$limit = Malka_CRM_Helpers::get_setting( 'round_limit_default', 2 );
		}

		return max( 0, (int) $limit );
	}

	/**
	 * Límite efectivo de correcciones: las asignadas más las compradas.
	 *
	 * @param int    $project_id ID.
	 * @param string $key        Clave.
	 * @return int|null Null si no se asignó número (sin control de rondas).
	 */
	public static function get_effective_limit( $project_id, $key ) {

		$assigned = self::get_assigned_rounds( $project_id, $key );

		if ( null === $assigned ) {
			return null;
		}

		$extra = class_exists( 'Malka_CRM_Extra_Rounds' )
			? Malka_CRM_Extra_Rounds::get_extra( $project_id, $key )
			: 0;

		return $assigned + (int) $extra;
	}

	/**
	 * ¿Esta fila requiere aprobación del cliente?
	 *
	 * @param int    $project_id ID.
	 * @param string $key        Clave.
	 * @return bool
	 */
	public static function needs_approval( $project_id, $key ) {

		if ( self::FINAL_KEY === $key ) {
			return '0' !== (string) get_post_meta( (int) $project_id, '_malka_pm_final_approval', true );
		}

		foreach ( Malka_CRM_Helpers::get_project_tasks( $project_id ) as $task ) {
			if ( $task['id'] === $key ) {
				return ! empty( $task['needs_approval'] );
			}
		}

		return false;
	}

	/**
	 * Correcciones asignadas a una fila.
	 *
	 * @param int    $project_id ID.
	 * @param string $key        Clave.
	 * @return int|null Null si el campo quedó vacío.
	 */
	public static function get_assigned_rounds( $project_id, $key ) {

		if ( self::FINAL_KEY === $key ) {
			$limit = self::get_round_limit( $project_id );
			return $limit > 0 ? $limit : null;
		}

		foreach ( Malka_CRM_Helpers::get_project_tasks( $project_id ) as $task ) {
			if ( $task['id'] === $key ) {
				$rounds = isset( $task['rounds'] ) ? trim( (string) $task['rounds'] ) : '';
				return ( '' === $rounds ) ? null : max( 0, (int) $rounds );
			}
		}

		return null;
	}

	/**
	 * ¿Se controla el número de correcciones de esta fila?
	 *
	 * @param int    $project_id ID.
	 * @param string $key        Clave.
	 * @return bool
	 */
	public static function tracks_rounds( $project_id, $key ) {
		return null !== self::get_effective_limit( $project_id, $key );
	}


	/**
	 * Rondas consumidas en una clave.
	 *
	 * @param int    $project_id ID.
	 * @param string $key        Clave.
	 * @return int
	 */
	public static function get_rounds_used( $project_id, $key ) {
		$record = self::get_approval( $project_id, $key );
		return (int) $record['rounds'];
	}

	/**
	 * ¿Quedan correcciones disponibles?
	 *
	 * @param int    $project_id ID.
	 * @param string $key        Clave.
	 * @return bool
	 */
	public static function can_request_changes( $project_id, $key ) {

		$limit = self::get_effective_limit( $project_id, $key );

		// Sin número asignado no hay control: la corrección siempre está disponible.
		if ( null === $limit ) {
			return true;
		}

		return self::get_rounds_used( $project_id, $key ) < $limit;
	}


	/**
	 * Mensaje comercial cuando se agotan las rondas.
	 *
	 * @return string
	 */
	public static function rounds_message() {
		$message = Malka_CRM_Helpers::get_setting( 'rounds_message', '' );
		return $message ? $message : __( 'Has usado todas las rondas de revisión incluidas en tu plan. Las rondas adicionales se cotizan aparte: escríbenos y te enviamos el detalle.', 'malka-crm' );
	}

	/* ──────────────────── Estado del proyecto ──────────────────── */

	/**
	 * ¿Están todas las etapas al 100%?
	 *
	 * @param int $project_id ID.
	 * @return bool
	 */
	public static function is_ready_for_final( $project_id ) {

		$tasks = Malka_CRM_Helpers::get_project_tasks( $project_id );

		if ( empty( $tasks ) ) {
			return false;
		}

		foreach ( $tasks as $task ) {
			if ( (int) $task['progress'] < 100 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Resumen de aprobaciones y rondas.
	 *
	 * @param int $project_id ID.
	 * @return array
	 */
	public static function get_summary( $project_id ) {

		$tasks    = Malka_CRM_Helpers::get_project_tasks( $project_id );
		$approved = 0;
		$changes  = 0;
		$rounds   = 0;

		foreach ( $tasks as $task ) {
			$record  = self::get_approval( $project_id, $task['id'] );
			$rounds += (int) $record['rounds'];

			if ( 'approved' === $record['status'] ) {
				$approved++;
			} elseif ( 'changes' === $record['status'] ) {
				$changes++;
			}
		}

		$final   = self::get_approval( $project_id, self::FINAL_KEY );
		$rounds += (int) $final['rounds'];

		return array(
			'total'    => count( $tasks ),
			'approved' => $approved,
			'changes'  => $changes,
			'rounds'   => $rounds,
			'limit'    => (int) self::get_round_limit( $project_id ),
			'final'    => $final,
		);
	}

	/* ───────────────── Tracking de finalización ───────────────── */

	/**
	 * Registra cuándo cada clave quedó lista para aprobación (100%).
	 * Se llama al guardar el proyecto en WP-Admin.
	 *
	 * @param int $project_id ID.
	 */
	public static function track_completions( $project_id ) {

		$completed = get_post_meta( $project_id, self::META_COMPLETED, true );
		$completed = is_array( $completed ) ? $completed : array();
		$now       = current_time( 'mysql' );
		$changed   = false;

		foreach ( Malka_CRM_Helpers::get_project_tasks( $project_id ) as $task ) {
			$key = $task['id'];

			if ( 100 === (int) $task['progress'] ) {
				if ( empty( $completed[ $key ] ) ) {
					$completed[ $key ] = $now;
					$changed           = true;
				}
			} elseif ( isset( $completed[ $key ] ) ) {
				// Volvió atrás: se reinicia el contador y los recordatorios.
				unset( $completed[ $key ] );
				self::clear_reminders( $project_id, $key );
				$changed = true;
			}
		}

		if ( self::is_ready_for_final( $project_id ) ) {
			if ( empty( $completed[ self::FINAL_KEY ] ) ) {
				$completed[ self::FINAL_KEY ] = $now;
				$changed                      = true;
			}
		} elseif ( isset( $completed[ self::FINAL_KEY ] ) ) {
			unset( $completed[ self::FINAL_KEY ] );
			self::clear_reminders( $project_id, self::FINAL_KEY );
			$changed = true;
		}

		if ( $changed ) {
			update_post_meta( $project_id, self::META_COMPLETED, $completed );
		}
	}

	/**
	 * Fecha en que una clave llegó al 100%.
	 *
	 * @param int    $project_id ID.
	 * @param string $key        Clave.
	 * @return string
	 */
	public static function get_completed_at( $project_id, $key ) {
		$completed = get_post_meta( (int) $project_id, self::META_COMPLETED, true );
		$completed = is_array( $completed ) ? $completed : array();
		return isset( $completed[ $key ] ) ? (string) $completed[ $key ] : '';
	}

	/* ──────────────────── Recordatorios ──────────────────── */

	/**
	 * Registro de recordatorios de una clave.
	 *
	 * @param int    $project_id ID.
	 * @param string $key        Clave.
	 * @return array
	 */
	public static function get_reminder( $project_id, $key ) {

		$all = get_post_meta( (int) $project_id, self::META_REMINDERS, true );
		$all = is_array( $all ) ? $all : array();

		$default = array(
			'count'     => 0,
			'last'      => '',
			'escalated' => 0,
		);

		return isset( $all[ $key ] ) ? wp_parse_args( $all[ $key ], $default ) : $default;
	}

	/**
	 * Guarda el registro de recordatorios.
	 *
	 * @param int    $project_id ID.
	 * @param string $key        Clave.
	 * @param array  $data       Datos.
	 */
	public static function set_reminder( $project_id, $key, $data ) {

		$all = get_post_meta( (int) $project_id, self::META_REMINDERS, true );
		$all = is_array( $all ) ? $all : array();

		$all[ $key ] = $data;

		update_post_meta( (int) $project_id, self::META_REMINDERS, $all );
	}

	/**
	 * Limpia los recordatorios de una clave.
	 *
	 * @param int    $project_id ID.
	 * @param string $key        Clave.
	 */
	public static function clear_reminders( $project_id, $key ) {

		$all = get_post_meta( (int) $project_id, self::META_REMINDERS, true );

		if ( ! is_array( $all ) || ! isset( $all[ $key ] ) ) {
			return;
		}

		unset( $all[ $key ] );
		update_post_meta( (int) $project_id, self::META_REMINDERS, $all );
	}

	/* ─────────────────── Historial ─────────────────── */

	const META_LOG = '_malka_pm_approval_log';

	/**
	 * Añade un movimiento al historial del proyecto.
	 *
	 * @param int   $project_id ID.
	 * @param array $entry      Movimiento.
	 */
	public static function log_activity( $project_id, $entry ) {

		$log = get_post_meta( (int) $project_id, self::META_LOG, true );
		$log = is_array( $log ) ? $log : array();

		array_unshift( $log, $entry );

		update_post_meta( (int) $project_id, self::META_LOG, array_slice( $log, 0, 100 ) );
	}

	/**
	 * Historial completo.
	 *
	 * @param int $project_id ID.
	 * @return array
	 */
	public static function get_activity( $project_id ) {
		$log = get_post_meta( (int) $project_id, self::META_LOG, true );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Panel de historial para el portal y el admin.
	 *
	 * @param int $project_id ID.
	 * @return string
	 */
	public static function render_history( $project_id ) {

		$log = self::get_activity( $project_id );

		if ( empty( $log ) ) {
			return '';
		}

		ob_start();
		?>
		<details class="malka-crm-panel malka-crm-history">
			<summary>
				<?php esc_html_e( 'Historial de movimientos', 'malka-crm' ); ?>
				<span class="malka-crm-panel__count"><?php echo esc_html( count( $log ) ); ?></span>
			</summary>
			<div class="malka-crm-panel__body">
				<ul class="malka-crm-timeline">
					<?php foreach ( $log as $entry ) : ?>
						<?php
						$entry = wp_parse_args(
							(array) $entry,
							array(
								'label'  => '',
								'status' => 'pending',
								'note'   => '',
								'signer' => '',
								'rut'    => '',
								'date'   => '',
							)
						);
						?>
						<li class="malka-crm-timeline__item is-<?php echo esc_attr( $entry['status'] ); ?>">
							<span class="malka-crm-timeline__date">
								<?php echo esc_html( $entry['date'] ? date_i18n( 'd/m/Y H:i', strtotime( $entry['date'] ) ) : '' ); ?>
							</span>
							<span class="malka-crm-timeline__text">
								<strong><?php echo esc_html( $entry['label'] ); ?></strong>
								·
								<?php
								if ( 'approved' === $entry['status'] ) {
									printf(
										/* translators: 1: nombre, 2: rut */
										esc_html__( 'Aprobado por %1$s (%2$s)', 'malka-crm' ),
										esc_html( $entry['signer'] ),
										esc_html( $entry['rut'] )
									);
								} elseif ( 'changes' === $entry['status'] ) {
									esc_html_e( 'Corrección solicitada', 'malka-crm' );
								} else {
									esc_html_e( 'Reabierto por el estudio', 'malka-crm' );
								}
								?>
							</span>
							<?php if ( $entry['note'] ) : ?>
								<blockquote class="malka-crm-timeline__note"><?php echo esc_html( $entry['note'] ); ?></blockquote>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</details>
		<?php
		return ob_get_clean();
	}

	/* ──────────────────────── AJAX ──────────────────────── */

	/**
	 * El cliente aprueba o pide cambios.
	 */
	public function handle_ajax() {

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Debes iniciar sesión.', 'malka-crm' ) ), 401 );
		}

		$project_id = isset( $_POST['project_id'] ) ? absint( $_POST['project_id'] ) : 0;
		$key        = isset( $_POST['task_key'] ) ? sanitize_key( wp_unslash( $_POST['task_key'] ) ) : '';
		$decision   = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$note       = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$signer     = isset( $_POST['signer'] ) ? sanitize_text_field( wp_unslash( $_POST['signer'] ) ) : '';
		$rut        = isset( $_POST['rut'] ) ? sanitize_text_field( wp_unslash( $_POST['rut'] ) ) : '';
		$nonce      = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! $project_id || ! $key ) {
			wp_send_json_error( array( 'message' => __( 'Petición incompleta.', 'malka-crm' ) ), 400 );
		}

		if ( ! wp_verify_nonce( $nonce, 'malka_crm_approval_' . $project_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Sesión caducada. Recarga la página.', 'malka-crm' ) ), 403 );
		}

		$user_id = get_current_user_id();

		if ( ! Malka_CRM_Helpers::user_owns_project( $project_id, $user_id ) && ! Malka_CRM_Helpers::current_user_is_manager() ) {
			wp_send_json_error( array( 'message' => __( 'No tienes acceso a este proyecto.', 'malka-crm' ) ), 403 );
		}

		if ( ! in_array( $decision, array( 'approved', 'changes' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Acción no válida.', 'malka-crm' ) ), 400 );
		}

		if ( ! self::needs_approval( $project_id, $key ) ) {
			wp_send_json_error( array( 'message' => __( 'Esta etapa no requiere tu aprobación.', 'malka-crm' ) ), 403 );
		}

		// Una vez aprobado queda bloqueado: solo el estudio puede reabrirlo.
		$existing = self::get_approval( $project_id, $key );

		if ( in_array( $existing['status'], array( 'approved', 'changes' ), true ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Ya registramos tu respuesta en esta entrega. Si necesitas reabrirla, escríbenos.', 'malka-crm' ),
					'locked'  => true,
				),
				409
			);
		}

		if ( 'approved' === $decision && ( '' === trim( $signer ) || '' === trim( $rut ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Escribe tu nombre y tu RUT para aprobar.', 'malka-crm' ) ), 400 );
		}

		if ( 'changes' === $decision && '' === trim( $note ) ) {
			wp_send_json_error( array( 'message' => __( 'Describe brevemente qué cambios necesitas.', 'malka-crm' ) ), 400 );
		}

		// La clave debe existir realmente.
		if ( self::FINAL_KEY !== $key ) {
			$valid = false;
			foreach ( Malka_CRM_Helpers::get_project_tasks( $project_id ) as $task ) {
				if ( $task['id'] === $key ) {
					$valid = true;
					break;
				}
			}
			if ( ! $valid ) {
				wp_send_json_error( array( 'message' => __( 'Etapa no encontrada.', 'malka-crm' ) ), 404 );
			}
		} elseif ( 'approved' === $decision && ! self::is_ready_for_final( $project_id ) ) {
			wp_send_json_error( array( 'message' => __( 'La entrega final aún no está lista para aprobación.', 'malka-crm' ) ), 400 );
		}

		// Control de rondas también en servidor, no solo en la interfaz.
		if ( 'changes' === $decision && ! self::can_request_changes( $project_id, $key ) ) {
			wp_send_json_error(
				array(
					'message'   => self::rounds_message(),
					'exhausted' => true,
				),
				409
			);
		}

		$record = self::set_approval( $project_id, $key, $decision, $note, $user_id, $signer, $rut );
		$limit  = self::get_round_limit( $project_id );

		if ( 'approved' === $decision ) {
			do_action( 'malka_crm_item_approved', $project_id, $key, $record );
		} else {
			do_action( 'malka_crm_changes_requested', $project_id, $key, $record );

			if ( $limit > 0 && (int) $record['rounds'] >= $limit ) {
				/**
				 * El cliente agotó las rondas incluidas: oportunidad de upsell.
				 *
				 * @param int    $project_id ID del proyecto.
				 * @param string $key        Clave.
				 * @param array  $record     Registro.
				 */
				do_action( 'malka_crm_rounds_exhausted', $project_id, $key, $record );
			}
		}

		$can_ask = self::can_request_changes( $project_id, $key );

		wp_send_json_success(
			array(
				'status'    => $record['status'],
				'label'     => self::status_label( $record['status'] ),
				'date'      => date_i18n( 'd/m/Y H:i', strtotime( $record['date'] ) ),
				'rounds'    => (int) $record['rounds'],
				'limit'     => $limit,
				'roundsTxt' => self::rounds_label( $project_id, $key ),
				'tracks'    => self::tracks_rounds( $project_id, $key ),
				'exhausted' => self::tracks_rounds( $project_id, $key ) && ! $can_ask,
				'notice'    => ( self::tracks_rounds( $project_id, $key ) && ! $can_ask ) ? self::rounds_message() : '',
				'message'   => ( 'approved' === $decision )
					? __( 'Aprobación registrada. Gracias.', 'malka-crm' )
					: __( 'Solicitud enviada al estudio.', 'malka-crm' ),
			)
		);
	}

	/* ──────────────────────── Utilidades ──────────────────────── */

	/**
	 * Etiqueta legible de un estado.
	 *
	 * @param string $status Estado.
	 * @return string
	 */
	public static function status_label( $status ) {
		$statuses = self::statuses();
		return isset( $statuses[ $status ] ) ? $statuses[ $status ] : $statuses['pending'];
	}

	/**
	 * Texto "Correcciones: 1 de 2". Vacío si no se controlan.
	 *
	 * @param int    $project_id ID.
	 * @param string $key        Clave.
	 * @return string
	 */
	public static function rounds_label( $project_id, $key ) {

		$limit = self::get_effective_limit( $project_id, $key );

		if ( null === $limit ) {
			return '';
		}

		$used  = self::get_rounds_used( $project_id, $key );
		$extra = class_exists( 'Malka_CRM_Extra_Rounds' ) ? Malka_CRM_Extra_Rounds::get_extra( $project_id, $key ) : 0;

		/* translators: 1: usadas, 2: disponibles */
		$label = sprintf( __( 'Correcciones: %1$d de %2$d', 'malka-crm' ), $used, $limit );

		if ( $extra > 0 ) {
			/* translators: %d: compradas */
			$label .= ' ' . sprintf( __( '(%d comprada/s)', 'malka-crm' ), $extra );
		}

		return $label;
	}


	/**
	 * Nombre legible de una clave.
	 *
	 * @param int    $project_id ID.
	 * @param string $key        Clave.
	 * @return string
	 */
	public static function key_label( $project_id, $key ) {

		if ( self::FINAL_KEY === $key ) {
			return __( 'Entrega final', 'malka-crm' );
		}

		foreach ( Malka_CRM_Helpers::get_project_tasks( $project_id ) as $task ) {
			if ( $task['id'] === $key ) {
				return $task['name'];
			}
		}

		return $key;
	}

	/**
	 * Reabre una aprobación desde WP-Admin conservando el contador de rondas.
	 */
	public function handle_reset() {

		if ( ! Malka_CRM_Helpers::current_user_is_manager() ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ), 403 );
		}

		$project_id = isset( $_GET['project_id'] ) ? absint( $_GET['project_id'] ) : 0;
		$key        = isset( $_GET['task_key'] ) ? sanitize_key( wp_unslash( $_GET['task_key'] ) ) : '';

		check_admin_referer( 'malka_crm_reset_approval_' . $project_id . '_' . $key );

		$all = self::get_approvals( $project_id );

		if ( isset( $all[ $key ] ) ) {
			$all[ $key ]['status'] = 'pending';
			$all[ $key ]['note']   = '';
			$all[ $key ]['date']   = current_time( 'mysql' );
			update_post_meta( $project_id, self::META_KEY, $all );
		}

		self::clear_reminders( $project_id, $key );

		wp_safe_redirect( Malka_CRM_Helpers::panel_project_url( $project_id ) );
		exit;
	}

	/**
	 * Suma o resta rondas manualmente (por ejemplo tras vender una extra).
	 *
	 * @param int    $project_id ID.
	 * @param string $key        Clave.
	 * @param int    $delta      Variación.
	 */
	public static function adjust_rounds( $project_id, $key, $delta ) {

		$all = self::get_approvals( $project_id );
		$key = sanitize_key( $key );

		if ( ! isset( $all[ $key ] ) ) {
			$all[ $key ] = self::get_approval( $project_id, $key );
		}

		$all[ $key ]['rounds'] = max( 0, (int) $all[ $key ]['rounds'] + (int) $delta );

		update_post_meta( (int) $project_id, self::META_KEY, $all );
	}

	/* ──────────────────────── Render ──────────────────────── */

	/**
	 * Bloque de acciones para el frontend.
	 *
	 * @param int    $project_id ID.
	 * @param string $key        Clave.
	 * @param bool   $enabled    Si ya puede aprobarse.
	 * @return string
	 */
	public static function render_actions( $project_id, $key, $enabled = true ) {

		if ( ! self::needs_approval( $project_id, $key ) ) {
			return '';
		}

		$record  = self::get_approval( $project_id, $key );
		$status  = $record['status'];
		$tracks  = self::tracks_rounds( $project_id, $key );
		$can_ask = self::can_request_changes( $project_id, $key );
		$nonce   = wp_create_nonce( 'malka_crm_approval_' . $project_id );
		$buy_url = class_exists( 'Malka_CRM_Extra_Rounds' ) ? Malka_CRM_Extra_Rounds::get_purchase_url( $project_id, $key ) : '';
		$cta_url = Malka_CRM_Helpers::get_setting( 'rounds_cta_url', '' );

		ob_start();
		?>
		<div class="malka-crm-approval is-<?php echo esc_attr( $status ); ?>"
			data-project="<?php echo esc_attr( $project_id ); ?>"
			data-key="<?php echo esc_attr( $key ); ?>"
			data-nonce="<?php echo esc_attr( $nonce ); ?>">

			<?php if ( 'approved' === $status ) : ?>

				<span class="malka-crm-chip malka-crm-chip--ok"><?php esc_html_e( 'Aprobada', 'malka-crm' ); ?></span>
				<span class="malka-crm-approval__note-line">
					<?php
					echo esc_html(
						trim(
							( $record['signer'] ? $record['signer'] . ' · ' : '' )
							. ( $record['date'] ? date_i18n( 'd/m/Y H:i', strtotime( $record['date'] ) ) : '' )
						)
					);
					?>
				</span>

			<?php elseif ( 'changes' === $status ) : ?>

				<span class="malka-crm-chip malka-crm-chip--warn"><?php esc_html_e( 'Corrección enviada', 'malka-crm' ); ?></span>
				<?php if ( $record['note'] ) : ?>
					<button type="button" class="malka-crm-cta malka-crm-cta--ghost malka-crm-view-request"><?php esc_html_e( 'Ver solicitud', 'malka-crm' ); ?></button>
					<blockquote class="malka-crm-approval__comment" hidden data-modal-title="<?php esc_attr_e( 'Tu solicitud', 'malka-crm' ); ?>">
						<?php echo nl2br( esc_html( $record['note'] ) ); ?>
						<cite><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $record['date'] ) ) ); ?></cite>
					</blockquote>
				<?php endif; ?>

			<?php elseif ( ! $enabled ) : ?>

				<span class="malka-crm-chip"><?php esc_html_e( 'Pendiente', 'malka-crm' ); ?></span>

			<?php elseif ( $tracks && ! $can_ask ) : ?>

				<span class="malka-crm-chip"><?php esc_html_e( 'Pendiente', 'malka-crm' ); ?></span>
				<div class="malka-crm-approval__actions">
					<button type="button" class="malka-crm-cta malka-crm-open-approve"><?php esc_html_e( 'Aprobar', 'malka-crm' ); ?></button>
				</div>
				<p class="malka-crm-approval__hint"><?php echo esc_html( self::rounds_message() ); ?></p>
				<?php if ( $buy_url ) : ?>
					<a class="malka-crm-link" href="<?php echo esc_url( $buy_url ); ?>"><?php esc_html_e( 'Comprar corrección adicional', 'malka-crm' ); ?></a>
				<?php elseif ( $cta_url ) : ?>
					<a class="malka-crm-link" href="<?php echo esc_url( $cta_url ); ?>"><?php esc_html_e( 'Cotizar corrección adicional', 'malka-crm' ); ?></a>
				<?php endif; ?>

			<?php else : ?>

				<div class="malka-crm-approval__actions">
					<button type="button" class="malka-crm-cta malka-crm-open-approve"><?php esc_html_e( 'Aprobar', 'malka-crm' ); ?></button>
					<?php if ( $can_ask ) : ?>
						<button type="button" class="malka-crm-cta malka-crm-cta--ghost malka-crm-request"><?php esc_html_e( 'Corregir', 'malka-crm' ); ?></button>
					<?php endif; ?>
				</div>

			<?php endif; ?>

			<?php if ( $tracks && 'approved' !== $status ) : ?>
				<?php
				$used_rounds  = self::get_rounds_used( $project_id, $key );
				$limit_rounds = self::get_effective_limit( $project_id, $key );
				?>
				<span class="malka-crm-approval__rounds">
					<em><?php esc_html_e( 'Correcciones', 'malka-crm' ); ?></em>
					<b><?php echo esc_html( $used_rounds . '/' . (int) $limit_rounds ); ?></b>
				</span>
			<?php endif; ?>

			<div class="malka-crm-approval__sign" hidden data-modal-title="<?php esc_attr_e( 'Aprobar entrega', 'malka-crm' ); ?>">
				<p class="malka-crm-approval__warning">
					<?php esc_html_e( 'Al aprobar, esta entrega queda cerrada y no podrás revertirla. Revísala bien antes de confirmar.', 'malka-crm' ); ?>
				</p>
				<label>
					<span><?php esc_html_e( 'Nombre completo', 'malka-crm' ); ?></span>
					<input type="text" class="malka-crm-signer" autocomplete="name">
				</label>
				<label>
					<span><?php esc_html_e( 'RUT', 'malka-crm' ); ?></span>
					<input type="text" class="malka-crm-rut" placeholder="12.345.678-9">
				</label>
				<div class="malka-crm-approval__actions">
					<button type="button" class="malka-crm-cta malka-crm-approve" data-decision="approved"><?php esc_html_e( 'Confirmar', 'malka-crm' ); ?></button>
					<button type="button" class="malka-crm-cta malka-crm-cta--ghost malka-crm-cancel-approve"><?php esc_html_e( 'Cancelar', 'malka-crm' ); ?></button>
				</div>
			</div>

			<div class="malka-crm-approval__note" hidden data-modal-title="<?php esc_attr_e( 'Solicitar corrección', 'malka-crm' ); ?>">
				<label>
					<span><?php esc_html_e( '¿Qué necesitas ajustar?', 'malka-crm' ); ?></span>
					<textarea rows="3"></textarea>
				</label>
				<div class="malka-crm-approval__actions">
					<button type="button" class="malka-crm-cta malka-crm-send-note"><?php esc_html_e( 'Enviar', 'malka-crm' ); ?></button>
				</div>
			</div>

			<p class="malka-crm-approval__feedback" role="status" aria-live="polite"></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Bloque de aprobación de entrega final.
	 *
	 * @param int $project_id ID.
	 * @return string
	 */
	public static function render_final_block( $project_id ) {

		if ( ! self::needs_approval( $project_id, self::FINAL_KEY ) ) {
			return '';
		}

		$ready  = self::is_ready_for_final( $project_id );
		$record = self::get_approval( $project_id, self::FINAL_KEY );

		ob_start();
		?>
		<section class="malka-crm-final <?php echo 'approved' === $record['status'] ? 'is-approved' : ''; ?>">
			<h4><?php esc_html_e( 'Aprobación de entrega final', 'malka-crm' ); ?></h4>

			<?php if ( 'approved' === $record['status'] ) : ?>
				<p><?php esc_html_e( 'Proyecto aprobado y cerrado. Gracias por la confianza.', 'malka-crm' ); ?></p>
			<?php elseif ( ! $ready ) : ?>
				<p><?php esc_html_e( 'Podrás aprobar la entrega final cuando todas las etapas estén al 100%.', 'malka-crm' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Todas las etapas están completas. Revisa el material entregado y confirma tu aprobación.', 'malka-crm' ); ?></p>
			<?php endif; ?>

			<?php echo self::render_actions( $project_id, self::FINAL_KEY, $ready ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</section>
		<?php
		return ob_get_clean();
	}
}
