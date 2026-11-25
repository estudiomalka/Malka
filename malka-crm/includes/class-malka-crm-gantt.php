<?php
/**
 * Módulo 2: Carta Gantt interactiva (Frappe Gantt).
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

class Malka_CRM_Gantt {

	const FRAPPE_VERSION = '0.6.1';

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ), 20 );
	}

	/**
	 * Registra CSS/JS (CDN por defecto, filtrable a local).
	 */
	public function register_assets() {

		$gantt_css = apply_filters( 'malka_crm_frappe_css', 'https://cdn.jsdelivr.net/npm/frappe-gantt@' . self::FRAPPE_VERSION . '/dist/frappe-gantt.css' );
		$gantt_js  = apply_filters( 'malka_crm_frappe_js', 'https://cdn.jsdelivr.net/npm/frappe-gantt@' . self::FRAPPE_VERSION . '/dist/frappe-gantt.min.js' );

		wp_register_style( 'frappe-gantt', $gantt_css, array(), self::FRAPPE_VERSION );
		wp_register_script( 'frappe-gantt', $gantt_js, array(), self::FRAPPE_VERSION, true );

		wp_register_style( 'malka-crm-frontend', MALKA_CRM_URL . 'assets/css/malka-crm-frontend.css', array(), MALKA_CRM_VERSION );
		wp_register_script( 'malka-crm-gantt-init', MALKA_CRM_URL . 'assets/js/malka-gantt-init.js', array( 'frappe-gantt' ), MALKA_CRM_VERSION, true );
		wp_register_script( 'malka-crm-approvals', MALKA_CRM_URL . 'assets/js/malka-crm-approvals.js', array(), MALKA_CRM_VERSION, true );
		wp_register_script( 'malka-crm-tasks', MALKA_CRM_URL . 'assets/js/malka-crm-tasks.js', array(), MALKA_CRM_VERSION, true );

		$accent = Malka_CRM_Helpers::get_setting( 'accent_color', '#B8A792' );
		wp_add_inline_style( 'malka-crm-frontend', ':root{--malka-crm-accent:' . esc_attr( $accent ) . ';}' );
	}

	/**
	 * Encola solo donde hace falta.
	 */
	public function maybe_enqueue() {

		$load = false;

		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			$load = true;
		}

		global $post;
		if ( $post instanceof WP_Post && ( has_shortcode( $post->post_content, 'malka_crm_projects' ) || has_shortcode( $post->post_content, 'malka_crm_invoices' ) ) ) {
			$load = true;
		}

		if ( ! apply_filters( 'malka_crm_load_assets', $load ) ) {
			return;
		}

		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'malka-crm-frontend' );
		wp_enqueue_style( 'frappe-gantt' );
		wp_enqueue_script( 'frappe-gantt' );
		wp_enqueue_script( 'malka-crm-gantt-init' );
		wp_enqueue_script( 'malka-crm-tasks' );

		wp_localize_script(
			'malka-crm-tasks',
			'MalkaCRMTasks',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => 'malka_crm_client_task',
				'i18n'    => array(
					'confirm' => __( '¿Confirmas que ya completaste esta tarea? Avisaremos al estudio.', 'malka-crm' ),
					'sending' => __( 'Enviando…', 'malka-crm' ),
					'error'   => __( 'No se pudo registrar. Inténtalo de nuevo.', 'malka-crm' ),
					'done'    => __( 'Completada', 'malka-crm' ),
				),
			)
		);

		if ( Malka_CRM_Helpers::get_setting( 'approvals_enabled' ) ) {
			wp_enqueue_script( 'malka-crm-approvals' );
			wp_localize_script(
				'malka-crm-approvals',
				'MalkaCRMApprovals',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'action'  => 'malka_crm_approval',
					'i18n'    => array(
						'confirm'  => __( '¿Confirmas la aprobación? Quedará registrada con fecha y hora.', 'malka-crm' ),
						'error'    => __( 'No se pudo procesar. Inténtalo de nuevo.', 'malka-crm' ),
						'sending'  => __( 'Enviando…', 'malka-crm' ),
						'noteHint' => __( 'Describe brevemente qué cambios necesitas.', 'malka-crm' ),
					),
				)
			);
		}

		wp_localize_script(
			'malka-crm-gantt-init',
			'MalkaCRMGantt',
			array(
				'defaultViewMode' => Malka_CRM_Helpers::get_setting( 'gantt_view_mode', 'Week' ),
				'accent'          => Malka_CRM_Helpers::get_setting( 'accent_color', '#B8A792' ),
				'i18n'            => array(
					'day'      => __( 'Día', 'malka-crm' ),
					'week'     => __( 'Semana', 'malka-crm' ),
					'month'    => __( 'Mes', 'malka-crm' ),
					'progress' => __( 'Avance', 'malka-crm' ),
					'owner'    => __( 'Responsable', 'malka-crm' ),
					'start'    => __( 'Inicio', 'malka-crm' ),
					'end'      => __( 'Término', 'malka-crm' ),
					'empty'    => __( 'Este proyecto aún no tiene tareas cargadas.', 'malka-crm' ),
				),
			)
		);
	}

	/**
	 * Documentos del proyecto: cotización vinculada y facturas emitidas.
	 *
	 * @param int $project_id ID.
	 * @param int $quote_id   Cotización vinculada.
	 * @return string
	 */
	public static function render_project_documents( $project_id, $quote_id = 0 ) {

		$invoices = get_posts(
			array(
				'post_type'      => 'malka_invoice',
				'post_status'    => array( 'private', 'publish' ),
				'posts_per_page' => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_malka_pm_project_id',
						'value' => (int) $project_id,
					),
				),
			)
		);

		$quote_url = $quote_id ? Malka_CRM_Quotes::get_url( $quote_id ) : '';

		if ( empty( $invoices ) && ! $quote_url ) {
			return '';
		}

		ob_start();
		?>
		<details class="malka-crm-panel malka-crm-docs">
			<summary><?php esc_html_e( 'Documentos del proyecto', 'malka-crm' ); ?></summary>
			<div class="malka-crm-panel__body">
			<ul class="malka-crm-doclist">
				<?php if ( $quote_url ) : ?>
					<li>
						<span class="dashicons dashicons-media-spreadsheet"></span>
						<a href="<?php echo esc_url( $quote_url ); ?>" target="_blank" rel="noopener noreferrer">
							<?php echo esc_html( get_the_title( $quote_id ) ); ?>
						</a>
						<span class="malka-crm-hint"><?php esc_html_e( 'Cotización con las fechas de pago', 'malka-crm' ); ?></span>
					</li>
				<?php endif; ?>

				<?php foreach ( $invoices as $invoice ) : ?>
					<li>
						<span class="dashicons dashicons-media-text"></span>
						<a href="<?php echo esc_url( Malka_CRM_Invoices::get_secure_url( $invoice->ID, 'view' ) ); ?>" target="_blank" rel="noopener noreferrer">
							<?php echo esc_html( $invoice->post_title ); ?>
						</a>
						<a class="malka-crm-hint" href="<?php echo esc_url( Malka_CRM_Invoices::get_secure_url( $invoice->ID, 'download' ) ); ?>">
							<?php esc_html_e( 'descargar', 'malka-crm' ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
			</div>
		</details>
		<?php
		return ob_get_clean();
	}

	/**
	 * Convierte las tareas guardadas al formato de Frappe Gantt.
	 *
	 * @param int $project_id ID.
	 * @return array
	 */
	public function get_gantt_data( $project_id ) {

		$tasks  = Malka_CRM_Helpers::get_project_tasks( $project_id );
		$output = array();

		// Primero las etapas, luego sus tareas, para que el Gantt se lea en bloques.
		$ordered = array();

		foreach ( $tasks as $task ) {
			if ( 'stage' === $task['type'] ) {
				$ordered[] = $task;
				foreach ( $tasks as $child ) {
					if ( 'stage' !== $child['type'] && $child['stage'] === $task['id'] ) {
						$ordered[] = $child;
					}
				}
			}
		}

		foreach ( $tasks as $task ) {
			if ( 'stage' !== $task['type'] && ( '' === $task['stage'] || ! $this->stage_exists( $tasks, $task['stage'] ) ) ) {
				$ordered[] = $task;
			}
		}

		foreach ( $ordered as $task ) {

			$class = 'malka-crm-task';

			if ( 'stage' === $task['type'] ) {
				$class = 'malka-crm-stage';
			} elseif ( 'milestone' === $task['type'] ) {
				$class = 'malka-crm-milestone';
			}

			if ( 'client' === $task['owner'] ) {
				$class .= ' malka-crm-owner-client';
			}

			$output[] = array(
				'id'           => (string) $task['id'],
				'name'         => ( 'stage' === $task['type'] ? '▸ ' : '' ) . (string) $task['name'],
				'start'        => (string) $task['start'],
				'end'          => (string) $task['end'],
				'progress'     => (int) $task['progress'],
				'dependencies' => (string) $task['dependencies'],
				'custom_class' => $class,
				'owner'        => (string) $task['owner'],
				'ownerLabel'   => Malka_CRM_Helpers::owner_label( $task ),
				'notes'        => isset( $task['notes'] ) ? (string) $task['notes'] : '',
			);
		}

		return $output;
	}

	/**
	 * Comprueba si una etapa existe en el array.
	 *
	 * @param array  $tasks Tareas.
	 * @param string $id    Id de etapa.
	 * @return bool
	 */
	private function stage_exists( $tasks, $id ) {

		foreach ( $tasks as $task ) {
			if ( 'stage' === $task['type'] && $task['id'] === $id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * HTML del panel de proyectos del cliente.
	 *
	 * @param int $user_id ID.
	 * @return string
	 */
	public function get_projects_html( $user_id ) {

		$is_manager = Malka_CRM_Helpers::current_user_is_manager();

		$projects = $is_manager
			? get_posts(
				array(
					'post_type'      => 'malka_project',
					'post_status'    => array( 'publish', 'private' ),
					'posts_per_page' => 100,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			)
			: Malka_CRM_Helpers::get_client_projects( $user_id );

		ob_start();

		if ( empty( $projects ) ) {
			echo '<div class="malka-crm-empty"><p>' . esc_html__( 'Aún no tienes proyectos activos.', 'malka-crm' ) . '</p></div>';
			return ob_get_clean();
		}

		$intro    = Malka_CRM_Helpers::get_setting( 'portal_intro' );
		$statuses = Malka_CRM_Helpers::project_statuses();

		$current = isset( $_GET['malka_project'] ) ? absint( $_GET['malka_project'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $current && ! $is_manager && ! Malka_CRM_Helpers::user_owns_project( $current, $user_id ) ) {
			$current = 0;
		}
		if ( ! $current ) {
			$current = (int) $projects[0]->ID;
		}
		?>
		<div class="malka-crm-projects">

			<?php if ( $intro ) : ?>
				<p class="malka-crm-intro"><?php echo esc_html( $intro ); ?></p>
			<?php endif; ?>

			<?php if ( count( $projects ) > 1 ) : ?>
				<nav class="malka-crm-project-nav">
					<?php foreach ( $projects as $project ) : ?>
						<a class="malka-crm-chip <?php echo ( (int) $project->ID === $current ) ? 'is-active' : ''; ?>"
							href="<?php echo esc_url( add_query_arg( 'malka_project', $project->ID ) ); ?>">
							<?php echo esc_html( $project->post_title ); ?>
						</a>
					<?php endforeach; ?>
				</nav>
			<?php endif; ?>

			<?php
			$project  = get_post( $current );
			$status   = get_post_meta( $current, '_malka_pm_status', true );
			$progress     = Malka_CRM_Helpers::get_project_progress( $current );
			$data         = $this->get_gantt_data( $current );
			$approvals_on = (bool) Malka_CRM_Helpers::get_setting( 'approvals_enabled' );

			if ( $approvals_on ) {
				$any_approval = false;
				foreach ( Malka_CRM_Helpers::get_project_tasks( $current ) as $check_task ) {
					if ( ! empty( $check_task['needs_approval'] ) ) {
						$any_approval = true;
						break;
					}
				}
				$approvals_on = $any_approval || Malka_CRM_Approvals::needs_approval( $current, Malka_CRM_Approvals::FINAL_KEY );
			}
			?>

			<header class="malka-crm-project-head">
				<h3><?php echo esc_html( $project->post_title ); ?></h3>
				<span class="malka-crm-status"><?php echo esc_html( isset( $statuses[ $status ] ) ? $statuses[ $status ] : __( 'En proceso', 'malka-crm' ) ); ?></span>
			</header>

			<div class="malka-crm-progress" role="progressbar" aria-valuenow="<?php echo esc_attr( $progress ); ?>" aria-valuemin="0" aria-valuemax="100">
				<div class="malka-crm-progress__bar" style="width:<?php echo esc_attr( $progress ); ?>%"></div>
				<span class="malka-crm-progress__label"><?php echo esc_html( $progress . '% ' . __( 'completado', 'malka-crm' ) ); ?></span>
			</div>

			<?php if ( $project->post_content ) : ?>
				<div class="malka-crm-project-desc"><?php echo wp_kses_post( wpautop( $project->post_content ) ); ?></div>
			<?php endif; ?>

			<?php
			$quote_id = Malka_CRM_Quotes::get_project_quote_id( $current );
			if ( $quote_id ) {
				echo Malka_CRM_Quotes::render_alert_banner( $quote_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>

			<?php
			$shift_log = Malka_CRM_Scheduler::get_log( $current );
			if ( ! empty( $shift_log ) ) :
				$last = $shift_log[0];
				?>
				<div class="malka-crm-alert malka-crm-alert--info">
					<span class="malka-crm-alert__title"><?php esc_html_e( 'Calendario actualizado', 'malka-crm' ); ?></span>
					<span class="malka-crm-alert__detail">
						<?php
						$names = array();
						foreach ( $last['items'] as $item ) {
							$names[] = $item['task'];
						}
						printf(
							/* translators: 1: fecha, 2: tareas */
							esc_html__( 'El %1$s se reprogramaron las fechas por el atraso en: %2$s.', 'malka-crm' ),
							esc_html( date_i18n( 'd/m/Y', strtotime( $last['date'] ) ) ),
							esc_html( implode( ', ', $names ) )
						);
						?>
					</span>
				</div>
			<?php endif; ?>

			<?php if ( $is_manager ) : ?>
				<div class="malka-crm-adminbar">
					<span class="malka-crm-adminbar__tag"><?php esc_html_e( 'Vista de administración', 'malka-crm' ); ?></span>
					<a class="malka-crm-btn malka-crm-btn--ghost" href="<?php echo esc_url( Malka_CRM_Helpers::panel_project_url( $current ) ); ?>"><?php esc_html_e( 'Editar proyecto', 'malka-crm' ); ?></a>
					<?php // Todo al escritorio: estos cuatro botones iban a WP-Admin. ?>
					<a class="malka-crm-btn malka-crm-btn--ghost" href="<?php echo esc_url( Malka_CRM_Dashboard::view_url( 'tareas' ) ); ?>"><?php esc_html_e( 'Editar tareas', 'malka-crm' ); ?></a>
					<a class="malka-crm-btn malka-crm-btn--ghost" href="<?php echo esc_url( Malka_CRM_Quote_Editor::edit_url( 0 ) ); ?>"><?php esc_html_e( 'Nueva cotización', 'malka-crm' ); ?></a>
					<a class="malka-crm-btn malka-crm-btn--ghost" href="<?php echo esc_url( Malka_CRM_Documents::edit_url( 0 ) ); ?>"><?php esc_html_e( 'Anotar documento', 'malka-crm' ); ?></a>
					<a class="malka-crm-btn malka-crm-btn--ghost" href="<?php echo esc_url( Malka_CRM_Projects::edit_url( 0 ) ); ?>"><?php esc_html_e( 'Nuevo negocio', 'malka-crm' ); ?></a>
				</div>
			<?php endif; ?>

			<details class="malka-crm-panel">
				<summary><?php esc_html_e( 'Carta Gantt', 'malka-crm' ); ?></summary>
				<div class="malka-crm-panel__body">
					<div class="malka-crm-gantt-toolbar">
						<button type="button" class="malka-crm-btn malka-crm-btn--ghost is-active" data-malka-view="Day"><?php esc_html_e( 'Día', 'malka-crm' ); ?></button>
						<button type="button" class="malka-crm-btn malka-crm-btn--ghost" data-malka-view="Week"><?php esc_html_e( 'Semana', 'malka-crm' ); ?></button>
						<button type="button" class="malka-crm-btn malka-crm-btn--ghost" data-malka-view="Month"><?php esc_html_e( 'Mes', 'malka-crm' ); ?></button>
					</div>

					<div class="malka-crm-gantt-wrap">
						<svg class="malka-crm-gantt"
							data-malka-gantt="<?php echo esc_attr( wp_json_encode( $data ) ); ?>"
							data-project-id="<?php echo esc_attr( $current ); ?>"></svg>
					</div>
				</div>
			</details>

			<?php if ( ! empty( $data ) ) : ?>
			<details class="malka-crm-panel">
				<summary>
					<?php esc_html_e( 'Detalle de tareas', 'malka-crm' ); ?>
					<span class="malka-crm-panel__count"><?php echo esc_html( count( $data ) ); ?></span>
				</summary>
				<div class="malka-crm-panel__body">
					<?php
					// Agrupa las tareas bajo su etapa respetando el orden de carga.
					$all_tasks = Malka_CRM_Helpers::get_project_tasks( $current );
					$groups    = array();
					$loose     = array();

					foreach ( $all_tasks as $row_task ) {
						if ( 'stage' === $row_task['type'] ) {
							$groups[ $row_task['id'] ] = array(
								'stage' => $row_task,
								'items' => array(),
							);
						}
					}

					foreach ( $all_tasks as $row_task ) {
						if ( 'stage' === $row_task['type'] ) {
							continue;
						}
						if ( ! empty( $row_task['stage'] ) && isset( $groups[ $row_task['stage'] ] ) ) {
							$groups[ $row_task['stage'] ]['items'][] = $row_task;
						} else {
							$loose[] = $row_task;
						}
					}

					if ( ! empty( $loose ) ) {
						$groups['__loose__'] = array(
							'stage' => null,
							'items' => $loose,
						);
					}

					$group_index = 0;
					?>

					<table class="malka-crm-table malka-crm-tasklist">
						<thead>
							<tr>
								<th class="col-name"><?php esc_html_e( 'Etapa / Tarea', 'malka-crm' ); ?></th>
								<th class="col-owner"><?php esc_html_e( 'Responsable', 'malka-crm' ); ?></th>
								<th class="col-date"><?php esc_html_e( 'Fechas', 'malka-crm' ); ?></th>
								<th class="col-pct"><?php esc_html_e( 'Avance', 'malka-crm' ); ?></th>
								<th class="col-state"><?php esc_html_e( 'Estado', 'malka-crm' ); ?></th>
								<?php if ( $approvals_on ) : ?>
									<th class="col-appr"><?php esc_html_e( 'Aprobación', 'malka-crm' ); ?></th>
								<?php endif; ?>
							</tr>
						</thead>

						<?php $group_index = 0; ?>
						<?php foreach ( $groups as $group_id => $group ) : ?>
							<?php
							$group_index++;
							$is_open = ( 1 === $group_index );
							$stage   = $group['stage'];
							$done_n  = 0;

							foreach ( $group['items'] as $item ) {
								if ( ! empty( $item['done'] ) || 100 === (int) $item['progress'] ) {
									$done_n++;
								}
							}

							$colspan = $approvals_on ? 6 : 5;
							?>
							<tbody class="malka-crm-stage <?php echo $is_open ? 'is-open' : ''; ?>">
								<tr class="malka-crm-row-stage">
									<td colspan="<?php echo esc_attr( $colspan ); ?>">
										<button type="button" class="malka-crm-stage-head" aria-expanded="<?php echo $is_open ? 'true' : 'false'; ?>">
											<span class="malka-crm-stage-chev" aria-hidden="true"></span>
											<span class="malka-crm-stage-name">
												<?php
												echo esc_html(
													$stage
														? Malka_CRM_Helpers::display_name( $stage )
														: __( 'Otras tareas', 'malka-crm' )
												);
												?>
											</span>
											<span class="malka-crm-stage-meta">
												<?php if ( $stage && $stage['start'] ) : ?>
													<span class="malka-crm-stage-dates">
														<?php echo esc_html( date_i18n( 'd/m', strtotime( $stage['start'] ) ) . ' — ' . date_i18n( 'd/m/Y', strtotime( $stage['end'] ) ) ); ?>
													</span>
												<?php endif; ?>
												<span class="malka-crm-stage-count"><?php echo esc_html( $done_n . '/' . count( $group['items'] ) ); ?></span>
												<span class="malka-crm-stage-pct"><?php echo esc_html( ( $stage ? (int) $stage['progress'] : 0 ) . '%' ); ?></span>
											</span>
										</button>
									</td>
								</tr>

								<?php foreach ( $group['items'] as $item ) : ?>
									<?php
									$level      = Malka_CRM_Helpers::number_level( $item['number'] );
									$item_done  = ! empty( $item['done'] ) || 100 === (int) $item['progress'];
									$is_client  = Malka_CRM_Helpers::involves_client( $item );
									$needs_appr = $approvals_on && ! empty( $item['needs_approval'] );
									$appr_ready = ! Malka_CRM_Helpers::get_setting( 'approve_at_100' ) || 100 === (int) $item['progress'] || $is_client;
									$appr_html  = $needs_appr ? Malka_CRM_Approvals::render_actions( $current, $item['id'], $appr_ready ) : '';
									?>
									<tr class="malka-crm-row-task is-level-<?php echo esc_attr( $level ); ?> <?php echo $item_done ? 'is-done' : ''; ?>">
										<td class="col-name" data-title="<?php esc_attr_e( 'Tarea', 'malka-crm' ); ?>">
											<span class="malka-crm-item__name"><?php echo esc_html( Malka_CRM_Helpers::display_name( $item ) ); ?></span>
										</td>
										<td class="col-owner" data-title="<?php esc_attr_e( 'Responsable', 'malka-crm' ); ?>">
											<?php if ( $is_client ) : ?>
												<span class="malka-crm-tag"><?php esc_html_e( 'Tu tarea', 'malka-crm' ); ?></span>
											<?php else : ?>
												<?php echo esc_html( Malka_CRM_Helpers::owner_label( $item ) ); ?>
											<?php endif; ?>
										</td>
										<?php
										$due_state = 'ok';
										$due_title = '';

										if ( ! $item_done ) {
											$days_left = Malka_CRM_Helpers::days_to_deadline( $item );

											if ( $days_left < 0 ) {
												$due_state = 'late';
												$due_title = __( 'Plazo vencido', 'malka-crm' );
											} elseif ( $days_left <= (int) Malka_CRM_Helpers::get_setting( 'client_warn_days', 1 ) ) {
												$due_state = 'soon';
												$due_title = sprintf(
													/* translators: %d: días */
													_n( 'Vence en %d día', 'Vence en %d días', max( 0, $days_left ), 'malka-crm' ),
													max( 0, $days_left )
												);
											}
										}
										?>
										<td class="col-date" data-title="<?php esc_attr_e( 'Fechas', 'malka-crm' ); ?>">
											<span class="malka-crm-date">
												<em><?php esc_html_e( 'Inicio', 'malka-crm' ); ?></em>
												<b><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $item['start'] ) ) ); ?></b>
											</span>
											<span class="malka-crm-date is-<?php echo esc_attr( $due_state ); ?>">
												<em><?php esc_html_e( 'Término', 'malka-crm' ); ?></em>
												<b<?php echo $due_title ? ' title="' . esc_attr( $due_title ) . '" tabindex="0"' : ''; ?>>
													<?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $item['end'] ) ) ); ?>
													<?php if ( 'ok' !== $due_state ) : ?>
														<span class="malka-crm-tip" role="tooltip"><?php echo esc_html( $due_title ); ?></span>
													<?php endif; ?>
												</b>
											</span>
										</td>
										<td class="col-pct" data-title="<?php esc_attr_e( 'Avance', 'malka-crm' ); ?>">
											<?php echo esc_html( (int) $item['progress'] . '%' ); ?>
										</td>
										<td class="col-state" data-title="<?php esc_attr_e( 'Estado', 'malka-crm' ); ?>">
											<?php
											if ( $is_client ) {
												echo Malka_CRM_Tasks::render_client_action( $current, $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
											} else {
												echo '<span class="malka-crm-chip' . ( $item_done ? ' malka-crm-chip--ok' : '' ) . '">'
													. esc_html( $item_done ? __( 'Completada', 'malka-crm' ) : __( 'En curso', 'malka-crm' ) )
													. '</span>';
											}
											?>
										</td>
										<?php if ( $approvals_on ) : ?>
											<td class="col-appr <?php echo $appr_html ? '' : 'malka-crm-cell-empty'; ?>" <?php echo $appr_html ? 'data-title="' . esc_attr__( 'Aprobación', 'malka-crm' ) . '"' : ''; ?>>
												<?php echo $appr_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											</td>
										<?php endif; ?>
									</tr>
								<?php endforeach; ?>
							</tbody>
						<?php endforeach; ?>
					</table>

				</div>
			</details>

				<?php if ( $approvals_on ) : ?>
					<?php echo Malka_CRM_Approvals::render_final_block( $current ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>
			<?php endif; ?>

			<?php
			if ( $quote_id ) {
				echo Malka_CRM_Quotes::render_payments_panel( $quote_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			echo Malka_CRM_Approvals::render_history( $current ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo self::render_project_documents( $current, $quote_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</div>
		<?php
		return ob_get_clean();
	}
}
