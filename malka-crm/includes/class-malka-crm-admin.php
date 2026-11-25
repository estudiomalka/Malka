<?php
/**
 * Panel de administración.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

class Malka_CRM_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_metaboxes' ) );
		add_action( 'admin_notices', array( $this, 'template_applied_notice' ) );
		add_action( 'save_post_malka_project', array( $this, 'save_project' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_malka_crm_test_email', array( $this, 'handle_test_email' ) );
		add_action( 'admin_post_malka_crm_grant_round', array( $this, 'handle_grant_round' ) );
		add_action( 'admin_post_malka_crm_save_tasks', array( $this, 'handle_save_tasks' ) );
		add_filter( 'manage_malka_project_posts_columns', array( $this, 'project_columns' ) );
		add_action( 'manage_malka_project_posts_custom_column', array( $this, 'project_column_content' ), 10, 2 );
	}

	/**
	 * Icono del menú en SVG (los dashicons no incluyen carta Gantt).
	 *
	 * @return string
	 */
	public static function menu_icon() {

		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><rect x="2" y="3" width="11" height="3" rx="1.5" fill="black"/><rect x="5" y="8.5" width="13" height="3" rx="1.5" fill="black"/><rect x="3" y="14" width="9" height="3" rx="1.5" fill="black"/></svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Menú lateral.
	 */
	public function register_menu() {

		$cap = Malka_CRM_Helpers::admin_capability();

		add_menu_page(
			__( 'Malka CRM', 'malka-crm' ),
			__( 'Malka CRM', 'malka-crm' ),
			$cap,
			'malka-crm',
			function () { $this->safe_page( 'render_dashboard' ); },
			self::menu_icon(),
			56
		);

		add_submenu_page( 'malka-crm', __( 'Resumen', 'malka-crm' ), __( 'Resumen', 'malka-crm' ), $cap, 'malka-crm' );

		add_submenu_page(
			'malka-crm',
			__( 'Escritorio', 'malka-crm' ),
			__( 'Escritorio', 'malka-crm' ),
			$cap,
			'malka-crm-dashboard',
			function () { $this->safe_page( 'render_dashboard_page' ); }
		);

		add_submenu_page(
			'malka-crm',
			__( 'Proyectos', 'malka-crm' ),
			__( 'Proyectos', 'malka-crm' ),
			$cap,
			'edit.php?post_type=malka_project'
		);

		add_submenu_page(
			'malka-crm',
			__( 'Tareas', 'malka-crm' ),
			__( 'Tareas', 'malka-crm' ),
			$cap,
			'malka-crm-tasks',
			function () { $this->safe_page( 'render_tasks_page' ); }
		);

		add_submenu_page(
			'malka-crm',
			__( 'Añadir proyecto', 'malka-crm' ),
			__( 'Añadir proyecto', 'malka-crm' ),
			$cap,
			'post-new.php?post_type=malka_project'
		);

		add_submenu_page(
			'malka-crm',
			__( 'Cotizaciones', 'malka-crm' ),
			__( 'Cotizaciones', 'malka-crm' ),
			$cap,
			'edit.php?post_type=malka_quote'
		);

		add_submenu_page(
			'malka-crm',
			__( 'Añadir cotización', 'malka-crm' ),
			__( 'Añadir cotización', 'malka-crm' ),
			$cap,
			'post-new.php?post_type=malka_quote'
		);

		add_submenu_page(
			'malka-crm',
			__( 'Plantillas', 'malka-crm' ),
			__( 'Plantillas', 'malka-crm' ),
			$cap,
			'edit.php?post_type=malka_template'
		);

		add_submenu_page(
			'malka-crm',
			__( 'Añadir plantilla', 'malka-crm' ),
			__( 'Añadir plantilla', 'malka-crm' ),
			$cap,
			'post-new.php?post_type=malka_template'
		);

		add_submenu_page(
			'malka-crm',
			__( 'Facturas', 'malka-crm' ),
			__( 'Facturas', 'malka-crm' ),
			$cap,
			'malka-crm-invoices',
			function () { $this->safe_page( 'render_invoices_page' ); }
		);

		add_submenu_page(
			'malka-crm',
			__( 'Ajustes Generales', 'malka-crm' ),
			__( 'Ajustes Generales', 'malka-crm' ),
			$cap,
			'malka-crm-settings',
			function () { $this->safe_page( 'render_settings_page' ); }
		);
	}

	/**
	 * Assets del admin.
	 *
	 * @param string $hook Hook actual.
	 */
	public function assets( $hook ) {

		$screen = get_current_screen();
		$is_cpt = $screen && in_array( $screen->post_type, array( 'malka_project', 'malka_quote', 'malka_template' ), true );
		$is_pm  = false !== strpos( (string) $hook, 'malka-crm' );

		if ( ! $is_cpt && ! $is_pm ) {
			return;
		}

		wp_enqueue_style( 'malka-crm-admin', MALKA_CRM_URL . 'assets/css/malka-crm-admin.css', array(), MALKA_CRM_VERSION );
		wp_enqueue_script( 'malka-crm-admin', MALKA_CRM_URL . 'assets/js/malka-crm-admin.js', array( 'jquery', 'jquery-ui-sortable' ), MALKA_CRM_VERSION, true );

		wp_localize_script(
			'malka-crm-admin',
			'MalkaCRMAdmin',
			array(
				'confirmDelete' => __( '¿Eliminar esta fila?', 'malka-crm' ),
				'today'         => current_time( 'Y-m-d' ),
			)
		);
	}

	/**
	 * Metaboxes del CPT proyecto.
	 */
	/**
	 * Aviso tras aplicar una plantilla desde la pantalla del proyecto.
	 */
	public function template_applied_notice() {

		$screen = get_current_screen();

		if ( ! $screen || 'malka_project' !== $screen->post_type ) {
			return;
		}

		$msg = isset( $_GET['malka_crm_msg'] ) ? sanitize_key( wp_unslash( $_GET['malka_crm_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( 'template_applied' !== $msg ) {
			return;
		}

		$count = isset( $_GET['malka_crm_sent'] ) ? absint( $_GET['malka_crm_sent'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				printf(
					/* translators: %d: ítems */
					esc_html__( 'Plantilla aplicada: %d ítems cargados en la tabla de tareas.', 'malka-crm' ),
					$count
				);
				?>
			</p>
		</div>
		<?php
	}

	public function register_metaboxes() {
		add_meta_box(
			'malka_crm_project_data',
			__( 'Datos del proyecto', 'malka-crm' ),
			array( $this, 'boxed' ),
			'malka_project',
			'side',
			'high',
			array( 'cb' => 'render_project_data' )
		);

		add_meta_box(
			'malka_crm_project_approvals',
			__( 'Aprobaciones del cliente', 'malka-crm' ),
			array( $this, 'boxed' ),
			'malka_project',
			'side',
			'default',
			array( 'cb' => 'render_project_approvals' )
		);

		add_meta_box(
			'malka_crm_project_docs',
			__( 'Cotizaciones y facturas vinculadas', 'malka-crm' ),
			array( $this, 'boxed' ),
			'malka_project',
			'side',
			'default',
			array( 'cb' => 'render_project_docs' )
		);

		add_meta_box(
			'malka_crm_project_tasks',
			__( 'Tareas y etapas · asignación al cliente', 'malka-crm' ),
			array( $this, 'boxed' ),
			'malka_project',
			'normal',
			'high',
			array( 'cb' => 'render_project_tasks' )
		);
	}

	/**
	 * Metabox lateral.
	 *
	 * @param WP_Post $post Post.
	 */
	public function render_project_data( $post ) {

		wp_nonce_field( 'malka_crm_save_project', 'malka_crm_project_nonce' );

		$client_id = (int) get_post_meta( $post->ID, '_malka_pm_client_id', true );
		$order_id  = (int) get_post_meta( $post->ID, '_malka_pm_order_id', true );
		$status    = (string) get_post_meta( $post->ID, '_malka_pm_status', true );
		$statuses  = Malka_CRM_Helpers::project_statuses();
		?>
		<p>
			<label for="malka_crm_client_id"><strong><?php esc_html_e( 'Cliente', 'malka-crm' ); ?></strong></label><br>
			<?php
			wp_dropdown_users(
				array(
					'name'              => 'malka_crm_client_id',
					'id'                => 'malka_crm_client_id',
					'selected'          => $client_id,
					'show_option_none'  => __( '— Sin asignar —', 'malka-crm' ),
					'option_none_value' => 0,
					'show'              => 'display_name_with_login',
					'class'             => 'widefat',
				)
			);
			?>
		</p>
		<p>
			<label for="malka_crm_order_id"><strong><?php esc_html_e( 'Pedido asociado (ID)', 'malka-crm' ); ?></strong></label><br>
			<input type="number" min="0" class="widefat" id="malka_crm_order_id" name="malka_crm_order_id" value="<?php echo esc_attr( $order_id ); ?>">
		</p>
		<p>
			<label for="malka_crm_status"><strong><?php esc_html_e( 'Estado', 'malka-crm' ); ?></strong></label><br>
			<select class="widefat" id="malka_crm_status" name="malka_crm_status">
				<?php foreach ( $statuses as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="malka_crm_round_limit"><strong><?php esc_html_e( 'Rondas de revisión incluidas', 'malka-crm' ); ?></strong></label><br>
			<input type="number" min="0" class="widefat" id="malka_crm_round_limit" name="malka_crm_round_limit" value="<?php echo esc_attr( Malka_CRM_Approvals::get_round_limit( $post->ID ) ); ?>">
			<span class="description"><?php esc_html_e( '0 = ilimitadas. Esencial: 1 · Identidad: 2 · Branding: 3.', 'malka-crm' ); ?></span>
		</p>
		<p>
			<label>
				<input type="checkbox" name="malka_crm_final_approval" value="1" <?php checked( '0' !== (string) get_post_meta( $post->ID, '_malka_pm_final_approval', true ) ); ?>>
				<strong><?php esc_html_e( 'Pedir aprobación de entrega final', 'malka-crm' ); ?></strong>
			</label>
			<span class="description"><?php esc_html_e( 'Si lo desmarcas, el cliente no verá el bloque de cierre del proyecto.', 'malka-crm' ); ?></span>
		</p>

		<?php $stats = Malka_CRM_Helpers::get_project_stats( $post->ID ); ?>
		<table class="malka-crm-stats">
			<tbody>
				<tr>
					<td><?php esc_html_e( 'Duración total', 'malka-crm' ); ?></td>
					<td><strong><?php echo esc_html( $stats['total_days'] ); ?></strong> <?php esc_html_e( 'días', 'malka-crm' ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Transcurridos', 'malka-crm' ); ?></td>
					<td><strong><?php echo esc_html( $stats['elapsed_days'] ); ?></strong> <?php esc_html_e( 'días', 'malka-crm' ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Tareas', 'malka-crm' ); ?></td>
					<td><strong><?php echo esc_html( $stats['done'] ); ?></strong> / <?php echo esc_html( $stats['done'] + $stats['pending'] ); ?></td>
				</tr>
				<tr class="<?php echo $stats['delay_client'] ? 'is-late' : ''; ?>">
					<td><?php esc_html_e( 'Atraso del cliente', 'malka-crm' ); ?></td>
					<td><strong><?php echo esc_html( $stats['delay_client'] ); ?></strong> <?php esc_html_e( 'días', 'malka-crm' ); ?></td>
				</tr>
				<tr class="<?php echo $stats['delay_studio'] ? 'is-late' : ''; ?>">
					<td><?php esc_html_e( 'Atraso de Malka', 'malka-crm' ); ?></td>
					<td><strong><?php echo esc_html( $stats['delay_studio'] ); ?></strong> <?php esc_html_e( 'días', 'malka-crm' ); ?></td>
				</tr>
			</tbody>
		</table>
		<p class="description">
			<?php esc_html_e( 'Avance global calculado:', 'malka-crm' ); ?>
			<strong><?php echo esc_html( Malka_CRM_Helpers::get_project_progress( $post->ID ) . '%' ); ?></strong>
		</p>
		<?php
	}

	/**
	 * Metabox repetidor de tareas.
	 *
	 * @param WP_Post $post Post.
	 */
	public function render_project_tasks( $post ) {

		if ( ! $post instanceof WP_Post ) {
			echo '<p class="description">' . esc_html__( 'Proyecto no disponible.', 'malka-crm' ) . '</p>';
			return;
		}

		$tasks  = Malka_CRM_Helpers::get_project_tasks( $post->ID );
		$stages = array();

		foreach ( $tasks as $task ) {
			if ( isset( $task['type'] ) && 'stage' === $task['type'] ) {
				$stages[] = array(
					'id'   => $task['id'],
					'name' => $task['name'],
				);
			}
		}
		?>
		<div class="malka-crm-tasks-wrap">

			<p class="description malka-crm-tasks-help">
				<?php esc_html_e( 'Crea filas de tipo "Etapa" y asigna tareas a cada una. El avance y las fechas de la etapa se calculan solos; el avance del proyecto es el promedio de todas las tareas.', 'malka-crm' ); ?>
			</p>

			<?php $templates = class_exists( 'Malka_CRM_Templates' ) ? Malka_CRM_Templates::get_templates() : array(); ?>
			<?php if ( ! empty( $templates ) ) : ?>
				<div class="malka-crm-template-apply"
					data-project="<?php echo esc_attr( $post->ID ); ?>"
					data-url="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'malka_crm_apply_template_' . $post->ID ) ); ?>"
					data-origin="<?php echo esc_attr( ( isset( $GLOBALS['pagenow'] ) && 'post.php' === $GLOBALS['pagenow'] ) ? 'edit' : 'tasks' ); ?>"
					data-confirm="<?php esc_attr_e( 'Se cargará la estructura de la plantilla en este proyecto. ¿Continuar?', 'malka-crm' ); ?>">
					<strong><?php esc_html_e( 'Aplicar plantilla:', 'malka-crm' ); ?></strong>
					<select class="malka-crm-tpl-select">
						<?php foreach ( $templates as $template ) : ?>
							<option value="<?php echo esc_attr( $template->ID ); ?>"><?php echo esc_html( $template->post_title ); ?></option>
						<?php endforeach; ?>
					</select>
					<label><?php esc_html_e( 'Inicio:', 'malka-crm' ); ?>
						<input type="date" class="malka-crm-tpl-start" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>">
					</label>
					<label><input type="checkbox" class="malka-crm-tpl-replace" checked> <?php esc_html_e( 'Reemplazar tareas actuales', 'malka-crm' ); ?></label>
					<button type="button" class="button malka-crm-apply-template"><?php esc_html_e( 'Aplicar', 'malka-crm' ); ?></button>
					<span class="description"><?php esc_html_e( 'Guarda tus cambios antes de aplicar: la plantilla recarga la tabla.', 'malka-crm' ); ?></span>
				</div>
			<?php endif; ?>

			<div class="malka-crm-tasks-bar">
				<span class="malka-crm-live-progress">
					<?php esc_html_e( 'Avance del proyecto:', 'malka-crm' ); ?>
					<strong data-malka-live="0">0%</strong>
				</span>
				<span class="malka-crm-live-count" data-malka-count=""></span>
				<button type="button" class="button button-small" id="malka-crm-collapse-all"><?php esc_html_e( 'Comprimir etapas', 'malka-crm' ); ?></button>
				<button type="button" class="button button-small" id="malka-crm-expand-all"><?php esc_html_e( 'Expandir', 'malka-crm' ); ?></button>
			</div>

			<p class="malka-crm-tasks-actions">
				<button type="button" class="button button-primary" id="malka-crm-add-stage"><?php esc_html_e( '+ Añadir etapa', 'malka-crm' ); ?></button>
				<button type="button" class="button button-secondary" id="malka-crm-add-task"><?php esc_html_e( '+ Añadir tarea', 'malka-crm' ); ?></button>
				<span class="description"><?php esc_html_e( '"Plazo (d)" aplica solo a tareas del cliente.', 'malka-crm' ); ?></span>
			</p>

			<div class="malka-crm-rows" id="malka-crm-tasks-body">
				<?php foreach ( $tasks as $i => $task ) : ?>
					<?php $this->task_row( $i, $task, $stages ); ?>
				<?php endforeach; ?>
			</div>

			<script type="text/html" id="tmpl-malka-crm-task-row">
				<?php $this->task_row( '__INDEX__', array( 'type' => 'task' ), $stages ); ?>
			</script>

			<script type="text/html" id="tmpl-malka-crm-stage-row">
				<?php $this->task_row( '__INDEX__', array( 'type' => 'stage' ), $stages ); ?>
			</script>

			<p class="malka-crm-tasks-actions">
				<button type="button" class="button button-primary" id="malka-crm-add-stage-2"><?php esc_html_e( '+ Añadir etapa', 'malka-crm' ); ?></button>
				<button type="button" class="button button-secondary" id="malka-crm-add-task-2"><?php esc_html_e( '+ Añadir tarea', 'malka-crm' ); ?></button>
			</p>

			<?php
			$shift_log = array();
			if ( class_exists( 'Malka_CRM_Scheduler' ) && method_exists( 'Malka_CRM_Scheduler', 'get_log' ) ) {
				$shift_log = (array) Malka_CRM_Scheduler::get_log( $post->ID );
			}
			?>

			<?php if ( ! empty( $shift_log ) ) : ?>
				<h4><?php esc_html_e( 'Reprogramaciones automáticas', 'malka-crm' ); ?></h4>
				<ul class="malka-crm-shiftlog">
					<?php foreach ( array_slice( $shift_log, 0, 5 ) as $entry ) : ?>
						<li>
							<strong><?php echo esc_html( isset( $entry['date'] ) ? date_i18n( 'd/m/Y H:i', strtotime( $entry['date'] ) ) : '' ); ?></strong>
							<?php
							$parts = array();
							foreach ( (array) ( isset( $entry['items'] ) ? $entry['items'] : array() ) as $item ) {
								$parts[] = sprintf( '%s (+%dd)', isset( $item['task'] ) ? $item['task'] : '', isset( $item['days'] ) ? (int) $item['days'] : 0 );
							}
							echo esc_html( implode( ' · ', $parts ) );
							?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=malka_crm_shift_project&project_id=' . $post->ID ), 'malka_crm_shift_project_' . $post->ID ) ); ?>">
					<?php esc_html_e( 'Reprogramar ahora por atrasos', 'malka-crm' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Una fila del editor de tareas.
	 *
	 * @param int|string $index  Índice.
	 * @param array      $task   Datos (cualquier clave puede faltar).
	 * @param array      $stages Etapas disponibles.
	 */
	private function task_row( $index, $task, $stages = array() ) {

		$task = wp_parse_args(
			(array) $task,
			array(
				'id'           => '',
				'name'         => '',
				'type'         => 'task',
				'stage'        => '',
				'owner'        => 'studio',
				'assignee'     => '',
				'start'        => '',
				'end'          => '',
				'due_days'     => 0,
				'progress'     => 0,
				'done'         => 0,
				'done_at'      => '',
				'dependencies' => '',
				'notes'        => '',
				'shifted'       => 0,
				'number'        => '',
				'done'           => 0,
				'needs_approval' => 0,
				'rounds'         => '',
			)
		);

		$name_attr = 'malka_crm_tasks[' . $index . ']';
		$is_stage  = 'stage' === $task['type'];
		?>
		<?php $level = Malka_CRM_Helpers::number_level( $task['number'] ); ?>
		<div class="malka-crm-task-row <?php echo esc_attr( 'is-' . $task['type'] ); ?> <?php echo ! empty( $task['done'] ) ? 'is-done' : ''; ?> <?php echo esc_attr( $level > 2 ? 'is-sub' : '' ); ?>"
			data-stage="<?php echo esc_attr( $is_stage ? $task['id'] : $task['stage'] ); ?>"
			data-level="<?php echo esc_attr( $level ); ?>">

			<div class="malka-crm-row-main">
				<span class="malka-crm-handle" title="<?php esc_attr_e( 'Arrastra para reordenar', 'malka-crm' ); ?>">⠿</span>

				<input type="text" class="malka-crm-f-number" name="<?php echo esc_attr( $name_attr ); ?>[number]" value="<?php echo esc_attr( $task['number'] ); ?>" placeholder="1.2" title="<?php esc_attr_e( 'Número', 'malka-crm' ); ?>">

				<select class="malka-crm-type malka-crm-f-type" name="<?php echo esc_attr( $name_attr ); ?>[type]" title="<?php esc_attr_e( 'Tipo', 'malka-crm' ); ?>">
					<?php foreach ( Malka_CRM_Helpers::task_types() as $type_key => $type_label ) : ?>
						<option value="<?php echo esc_attr( $type_key ); ?>" <?php selected( $task['type'], $type_key ); ?>><?php echo esc_html( $type_label ); ?></option>
					<?php endforeach; ?>
				</select>

				<input type="text" class="malka-crm-name malka-crm-f-name" name="<?php echo esc_attr( $name_attr ); ?>[name]" value="<?php echo esc_attr( $task['name'] ); ?>" placeholder="<?php esc_attr_e( 'Nombre de la etapa o tarea', 'malka-crm' ); ?>">

				<button type="button" class="button-link malka-crm-remove-task" aria-label="<?php esc_attr_e( 'Eliminar', 'malka-crm' ); ?>">&times;</button>
			</div>

			<div class="malka-crm-row-meta">
				<label>
					<span><?php esc_html_e( 'Etapa', 'malka-crm' ); ?></span>
					<select class="malka-crm-stage-select" name="<?php echo esc_attr( $name_attr ); ?>[stage]">
						<option value="">—</option>
						<?php foreach ( $stages as $stage ) : ?>
							<option value="<?php echo esc_attr( $stage['id'] ); ?>" <?php selected( $task['stage'], $stage['id'] ); ?>>
								<?php echo esc_html( $stage['name'] ? $stage['name'] : $stage['id'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<label>
					<span><?php esc_html_e( 'Asignado a', 'malka-crm' ); ?></span>
					<select class="malka-crm-owner" name="<?php echo esc_attr( $name_attr ); ?>[owner]">
						<?php foreach ( Malka_CRM_Helpers::task_owners() as $owner_key => $owner_label ) : ?>
							<option value="<?php echo esc_attr( $owner_key ); ?>" <?php selected( $task['owner'], $owner_key ); ?>><?php echo esc_html( $owner_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<label>
					<span><?php esc_html_e( 'Equipo', 'malka-crm' ); ?></span>
					<input type="text" name="<?php echo esc_attr( $name_attr ); ?>[assignee]" value="<?php echo esc_attr( $task['assignee'] ); ?>" placeholder="<?php esc_attr_e( 'Quién ejecuta', 'malka-crm' ); ?>">
				</label>

				<label>
					<span><?php esc_html_e( 'Inicio', 'malka-crm' ); ?></span>
					<input type="date" name="<?php echo esc_attr( $name_attr ); ?>[start]" value="<?php echo esc_attr( $task['start'] ); ?>">
				</label>

				<label>
					<span><?php esc_html_e( 'Término', 'malka-crm' ); ?></span>
					<input type="date" name="<?php echo esc_attr( $name_attr ); ?>[end]" value="<?php echo esc_attr( $task['end'] ); ?>">
				</label>

				<label class="is-narrow">
					<span><?php esc_html_e( 'Plazo', 'malka-crm' ); ?></span>
					<input type="number" min="0" name="<?php echo esc_attr( $name_attr ); ?>[due_days]" value="<?php echo esc_attr( (int) $task['due_days'] ); ?>">
				</label>

				<label class="is-narrow">
					<span><?php esc_html_e( 'Avance %', 'malka-crm' ); ?></span>
					<input type="number" class="malka-crm-progress" min="0" max="100" step="5" name="<?php echo esc_attr( $name_attr ); ?>[progress]" value="<?php echo esc_attr( (int) $task['progress'] ); ?>">
				</label>

				<label class="is-narrow">
					<span><?php esc_html_e( 'Correcciones', 'malka-crm' ); ?></span>
					<input type="number" min="0" class="malka-crm-rounds" name="<?php echo esc_attr( $name_attr ); ?>[rounds]" value="<?php echo esc_attr( $task['rounds'] ); ?>" placeholder="—">
				</label>

				<label class="is-check">
					<input type="checkbox" class="malka-crm-done" name="<?php echo esc_attr( $name_attr ); ?>[done]" value="1" <?php checked( ! empty( $task['done'] ) ); ?>>
					<span><?php esc_html_e( 'Hecha', 'malka-crm' ); ?></span>
				</label>

				<label class="is-check">
					<input type="checkbox" class="malka-crm-approval-flag" name="<?php echo esc_attr( $name_attr ); ?>[needs_approval]" value="1" <?php checked( ! empty( $task['needs_approval'] ) ); ?>>
					<span><?php esc_html_e( 'La aprueba el cliente', 'malka-crm' ); ?></span>
				</label>
			</div>

			<input type="hidden" name="<?php echo esc_attr( $name_attr ); ?>[id]" value="<?php echo esc_attr( $task['id'] ); ?>">
		</div>
		<?php
	}

	public function save_project( $post_id, $post ) {

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST['malka_crm_project_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['malka_crm_project_nonce'] ) ), 'malka_crm_save_project' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) || ! Malka_CRM_Helpers::current_user_is_manager() ) {
			return;
		}

		$old_status = (string) get_post_meta( $post_id, '_malka_pm_status', true );
		$new_status = isset( $_POST['malka_crm_status'] ) ? sanitize_key( wp_unslash( $_POST['malka_crm_status'] ) ) : 'briefing';

		update_post_meta( $post_id, '_malka_pm_client_id', isset( $_POST['malka_crm_client_id'] ) ? absint( $_POST['malka_crm_client_id'] ) : 0 );
		update_post_meta( $post_id, '_malka_pm_order_id', isset( $_POST['malka_crm_order_id'] ) ? absint( $_POST['malka_crm_order_id'] ) : 0 );
		update_post_meta( $post_id, '_malka_pm_status', $new_status );

		if ( $old_status && $old_status !== $new_status ) {
			/**
			 * El estado del proyecto cambió.
			 *
			 * @param int    $post_id    ID del proyecto.
			 * @param string $new_status Nuevo estado.
			 * @param string $old_status Estado anterior.
			 */
			do_action( 'malka_crm_project_status_changed', $post_id, $new_status, $old_status );
		}

		update_post_meta( $post_id, '_malka_pm_final_approval', isset( $_POST['malka_crm_final_approval'] ) ? '1' : '0' );

		if ( isset( $_POST['malka_crm_round_limit'] ) && '' !== $_POST['malka_crm_round_limit'] ) {
			update_post_meta( $post_id, Malka_CRM_Approvals::META_LIMIT, absint( $_POST['malka_crm_round_limit'] ) );
		}

		$raw   = isset( $_POST['malka_crm_tasks'] ) ? (array) wp_unslash( $_POST['malka_crm_tasks'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$tasks = Malka_CRM_Helpers::sanitize_tasks( $raw );

		update_post_meta( $post_id, '_malka_pm_tasks', $tasks );

		// Marca la fecha en que cada etapa llegó al 100%: es la base del cron de recordatorios.
		Malka_CRM_Approvals::track_completions( $post_id );
	}

	/**
	 * Columnas del listado.
	 *
	 * @param array $columns Columnas.
	 * @return array
	 */
	public function project_columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['malka_client']   = __( 'Cliente', 'malka-crm' );
				$new['malka_status']   = __( 'Estado', 'malka-crm' );
				$new['malka_progress'] = __( 'Avance', 'malka-crm' );
			}
		}
		return $new;
	}

	/**
	 * Contenido de columnas.
	 *
	 * @param string $column  Columna.
	 * @param int    $post_id ID.
	 */
	public function project_column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'malka_client':
				$user = get_userdata( (int) get_post_meta( $post_id, '_malka_pm_client_id', true ) );
				echo $user ? esc_html( $user->display_name ) : '&mdash;';
				break;
			case 'malka_status':
				$statuses = Malka_CRM_Helpers::project_statuses();
				$status   = get_post_meta( $post_id, '_malka_pm_status', true );
				echo esc_html( isset( $statuses[ $status ] ) ? $statuses[ $status ] : '—' );
				break;
			case 'malka_progress':
				echo esc_html( Malka_CRM_Helpers::get_project_progress( $post_id ) . '%' );
				break;
		}
	}

	/**
	 * Página resumen.
	 */
	public function render_dashboard() {

		if ( ! Malka_CRM_Helpers::current_user_is_manager() ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ), 403 );
		}

		$projects = get_posts(
			array(
				'post_type'      => 'malka_project',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 200,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$active     = array();
		$finished   = 0;
		$paused     = 0;
		$approvals  = 0;
		$overdue    = 0;
		$statuses   = Malka_CRM_Helpers::project_statuses();

		foreach ( $projects as $project ) {

			$status = (string) get_post_meta( $project->ID, '_malka_pm_status', true );

			if ( Malka_CRM_Helpers::is_project_finished( $project->ID ) ) {
				$finished++;
				continue;
			}

			if ( 'pausado' === $status ) {
				$paused++;
			}

			$summary = Malka_CRM_Approvals::get_summary( $project->ID );
			$pending = 0;

			foreach ( Malka_CRM_Helpers::get_leaf_tasks( $project->ID ) as $task ) {
				if ( 100 === (int) $task['progress'] && 'pending' === Malka_CRM_Approvals::get_approval( $project->ID, $task['id'] )['status'] ) {
					$pending++;
				}
				if ( 'client' === $task['owner'] && empty( $task['done'] ) && Malka_CRM_Helpers::days_to_deadline( $task ) < 0 ) {
					$overdue++;
				}
			}

			$approvals += $pending;

			$active[] = array(
				'post'      => $project,
				'status'    => $status,
				'progress'  => Malka_CRM_Helpers::get_project_progress( $project->ID ),
				'stage'     => Malka_CRM_Helpers::get_current_stage( $project->ID ),
				'pending'   => $pending,
				'client'    => get_userdata( (int) get_post_meta( $project->ID, '_malka_pm_client_id', true ) ),
			);
		}

		$quotes   = wp_count_posts( 'malka_quote' );
		$invoices = wp_count_posts( 'malka_invoice' );
		?>
		<div class="wrap malka-crm-wrap">
			<h1><?php esc_html_e( 'Malka CRM', 'malka-crm' ); ?></h1>

			<div class="malka-crm-cards">
				<div class="malka-crm-card">
					<span class="malka-crm-card__num"><?php echo esc_html( count( $projects ) ); ?></span>
					<span><?php esc_html_e( 'Proyectos totales', 'malka-crm' ); ?></span>
				</div>
				<div class="malka-crm-card malka-crm-card--active">
					<span class="malka-crm-card__num"><?php echo esc_html( count( $active ) ); ?></span>
					<span><?php esc_html_e( 'En curso', 'malka-crm' ); ?></span>
				</div>
				<div class="malka-crm-card malka-crm-card--done">
					<span class="malka-crm-card__num"><?php echo esc_html( $finished ); ?></span>
					<span><?php esc_html_e( 'Terminados', 'malka-crm' ); ?></span>
				</div>
				<div class="malka-crm-card <?php echo $approvals ? 'malka-crm-card--warn' : ''; ?>">
					<span class="malka-crm-card__num"><?php echo esc_html( $approvals ); ?></span>
					<span><?php esc_html_e( 'Aprobaciones pendientes', 'malka-crm' ); ?></span>
				</div>
				<div class="malka-crm-card <?php echo $overdue ? 'malka-crm-card--alert' : ''; ?>">
					<span class="malka-crm-card__num"><?php echo esc_html( $overdue ); ?></span>
					<span><?php esc_html_e( 'Tareas del cliente atrasadas', 'malka-crm' ); ?></span>
				</div>
			</div>

			<p class="malka-crm-quick">
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=malka_project' ) ); ?>"><?php esc_html_e( 'Nuevo proyecto', 'malka-crm' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=malka_quote' ) ); ?>"><?php esc_html_e( 'Nueva cotización', 'malka-crm' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=malka-crm-invoices' ) ); ?>"><?php esc_html_e( 'Subir factura', 'malka-crm' ); ?></a>
				<span class="description">
					<?php
					printf(
						/* translators: 1: cotizaciones, 2: facturas */
						esc_html__( '%1$d cotizaciones · %2$d facturas cargadas', 'malka-crm' ),
						(int) $quotes->publish + (int) $quotes->private + (int) $quotes->draft,
						(int) $invoices->publish + (int) $invoices->private
					);
					?>
				</span>
			</p>

			<h2><?php esc_html_e( 'Proyectos en curso', 'malka-crm' ); ?></h2>

			<?php if ( empty( $active ) ) : ?>
				<p class="description"><?php esc_html_e( 'No hay proyectos activos. Crea uno para empezar a cargar tareas y etapas.', 'malka-crm' ); ?></p>
			<?php else : ?>
				<table class="widefat striped malka-crm-dashboard-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Proyecto', 'malka-crm' ); ?></th>
							<th><?php esc_html_e( 'Cliente', 'malka-crm' ); ?></th>
							<th><?php esc_html_e( 'Etapa actual', 'malka-crm' ); ?></th>
							<th style="width:180px"><?php esc_html_e( 'Avance', 'malka-crm' ); ?></th>
							<th style="width:90px"><?php esc_html_e( 'Días', 'malka-crm' ); ?></th>
							<th style="width:120px"><?php esc_html_e( 'Atrasos', 'malka-crm' ); ?></th>
							<th><?php esc_html_e( 'Estado', 'malka-crm' ); ?></th>
							<th><?php esc_html_e( 'Acciones', 'malka-crm' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $active as $row ) : ?>
						<tr>
							<td>
								<strong><a href="<?php echo esc_url( get_edit_post_link( $row['post']->ID ) ); ?>"><?php echo esc_html( $row['post']->post_title ); ?></a></strong>
								<?php if ( $row['pending'] ) : ?>
									<span class="malka-crm-badge malka-crm-badge--changes">
										<?php
										printf(
											/* translators: %d: aprobaciones */
											esc_html( _n( '%d por aprobar', '%d por aprobar', $row['pending'], 'malka-crm' ) ),
											(int) $row['pending']
										);
										?>
									</span>
								<?php endif; ?>
							</td>
							<td><?php echo $row['client'] ? esc_html( $row['client']->display_name ) : '&mdash;'; ?></td>
							<td>
								<?php
								if ( $row['stage'] ) {
									echo esc_html( $row['stage']['name'] );
									echo ' <span class="description">' . esc_html( (int) $row['stage']['progress'] . '%' ) . '</span>';
								} else {
									echo '<span class="description">' . esc_html__( 'Sin etapas definidas', 'malka-crm' ) . '</span>';
								}
								?>
							</td>
							<td>
								<div class="malka-crm-mini-bar"><span style="width:<?php echo esc_attr( $row['progress'] ); ?>%"></span></div>
								<span class="description"><?php echo esc_html( $row['progress'] . '%' ); ?></span>
							</td>
							<?php $row_stats = Malka_CRM_Helpers::get_project_stats( $row['post']->ID ); ?>
							<td>
								<span class="description">
									<?php echo esc_html( $row_stats['elapsed_days'] . ' / ' . $row_stats['total_days'] ); ?>
								</span>
							</td>
							<td>
								<?php if ( $row_stats['delay_client'] || $row_stats['delay_studio'] ) : ?>
									<span class="malka-crm-badge malka-crm-badge--changes">
										<?php
										printf(
											/* translators: 1: días cliente, 2: días estudio */
											esc_html__( 'C %1$dd · M %2$dd', 'malka-crm' ),
											(int) $row_stats['delay_client'],
											(int) $row_stats['delay_studio']
										);
										?>
									</span>
								<?php else : ?>
									<span class="description"><?php esc_html_e( 'En plazo', 'malka-crm' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( isset( $statuses[ $row['status'] ] ) ? $statuses[ $row['status'] ] : '—' ); ?></td>
							<td>
								<a href="<?php echo esc_url( get_edit_post_link( $row['post']->ID ) ); ?>"><?php esc_html_e( 'Tareas', 'malka-crm' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Las tareas, etapas y su asignación al cliente se editan dentro de cada proyecto, en el bloque "Tareas y etapas".', 'malka-crm' ); ?>
			</p>
		</div>
		<?php
	}

	public function render_invoices_page() {

		if ( ! Malka_CRM_Helpers::current_user_is_manager() ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ), 403 );
		}

		$msg = isset( $_GET['malka_crm_msg'] ) ? sanitize_key( wp_unslash( $_GET['malka_crm_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		$messages = array(
			'uploaded'      => array( 'success', __( 'Factura subida y asignada.', 'malka-crm' ) ),
			'deleted'       => array( 'success', __( 'Factura eliminada.', 'malka-crm' ) ),
			'no_user'       => array( 'error', __( 'Debes seleccionar un cliente válido.', 'malka-crm' ) ),
			'no_file'       => array( 'error', __( 'No se recibió ningún archivo.', 'malka-crm' ) ),
			'bad_extension' => array( 'error', __( 'Solo se aceptan archivos .pdf, .html o .htm.', 'malka-crm' ) ),
			'bad_pdf'       => array( 'error', __( 'El archivo no es un PDF válido.', 'malka-crm' ) ),
			'php_detected'  => array( 'error', __( 'El archivo contenía código PHP y fue rechazado.', 'malka-crm' ) ),
			'too_big'       => array( 'error', __( 'El archivo supera el tamaño máximo permitido.', 'malka-crm' ) ),
			'move_failed'   => array( 'error', __( 'No se pudo guardar el archivo en el servidor.', 'malka-crm' ) ),
		);

		$invoices = get_posts(
			array(
				'post_type'      => 'malka_invoice',
				'post_status'    => array( 'private', 'publish' ),
				'posts_per_page' => 50,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		?>
		<div class="wrap malka-crm-wrap">
			<h1><?php esc_html_e( 'Asignar facturas', 'malka-crm' ); ?></h1>

			<?php if ( $msg && isset( $messages[ $msg ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $messages[ $msg ][0] ); ?> is-dismissible">
					<p><?php echo esc_html( $messages[ $msg ][1] ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="malka_crm_upload_invoice">
				<?php wp_nonce_field( 'malka_crm_upload_invoice' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="malka_crm_invoice_title"><?php esc_html_e( 'Título', 'malka-crm' ); ?></label></th>
						<td><input type="text" class="regular-text" id="malka_crm_invoice_title" name="malka_crm_invoice_title" placeholder="<?php esc_attr_e( 'Factura Nº405 — Agosto', 'malka-crm' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="malka_crm_user_id"><?php esc_html_e( 'Cliente', 'malka-crm' ); ?></label></th>
						<td>
							<input type="search" class="regular-text malka-crm-filter" data-target="#malka_crm_user_id" placeholder="<?php esc_attr_e( 'Buscar cliente por nombre o usuario…', 'malka-crm' ); ?>">
							<br>
							<?php
							wp_dropdown_users(
								array(
									'name'              => 'malka_crm_user_id',
									'id'                => 'malka_crm_user_id',
									'show_option_none'  => __( '— Selecciona —', 'malka-crm' ),
									'option_none_value' => 0,
									'show'              => 'display_name_with_login',
									'number'            => 0,
								)
							);
							?>
							<p class="description"><?php esc_html_e( 'Si dejas el cliente vacío y escribes un ID de pedido, se asignará al comprador de ese pedido.', 'malka-crm' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="malka_crm_project_id"><?php esc_html_e( 'Proyecto vinculado', 'malka-crm' ); ?></label></th>
						<td>
							<?php
							$preselected = isset( $_GET['project_id'] ) ? absint( $_GET['project_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
							$all_projects = get_posts(
								array(
									'post_type'      => 'malka_project',
									'post_status'    => array( 'publish', 'private' ),
									'posts_per_page' => 100,
									'orderby'        => 'date',
									'order'          => 'DESC',
								)
							);
							?>
							<input type="search" class="regular-text malka-crm-filter" data-target="#malka_crm_project_id" placeholder="<?php esc_attr_e( 'Buscar proyecto…', 'malka-crm' ); ?>">
							<br>
							<select name="malka_crm_project_id" id="malka_crm_project_id">
								<option value="0"><?php esc_html_e( '— Sin vincular —', 'malka-crm' ); ?></option>
								<?php foreach ( $all_projects as $project_post ) : ?>
									<option value="<?php echo esc_attr( $project_post->ID ); ?>" <?php selected( $preselected, $project_post->ID ); ?>>
										<?php echo esc_html( $project_post->post_title ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'La factura aparecerá también dentro del proyecto del cliente.', 'malka-crm' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="malka_crm_order_id"><?php esc_html_e( 'Pedido (opcional)', 'malka-crm' ); ?></label></th>
						<td><input type="number" min="0" id="malka_crm_order_id" name="malka_crm_order_id" value="0"></td>
					</tr>
					<tr>
						<th scope="row"><label for="malka_crm_invoice_file"><?php esc_html_e( 'Archivo (PDF o HTML)', 'malka-crm' ); ?></label></th>
						<td>
							<input type="file" id="malka_crm_invoice_file" name="malka_crm_invoice_file" accept=".pdf,.html,.htm" required>
							<p class="description">
							<?php
							printf(
								/* translators: tope de subida */
								esc_html__( 'PDF o HTML. Máx. %s. Se guarda en carpeta protegida y solo el cliente dueño puede abrirla.', 'malka-crm' ),
								esc_html( number_format_i18n( Malka_CRM_Invoices::tope_bytes() / MB_IN_BYTES, 1 ) . ' MB' )
							);
							?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Subir y asignar', 'malka-crm' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Facturas cargadas', 'malka-crm' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Título', 'malka-crm' ); ?></th>
						<th><?php esc_html_e( 'Cliente', 'malka-crm' ); ?></th>
						<th><?php esc_html_e( 'Proyecto', 'malka-crm' ); ?></th>
						<th><?php esc_html_e( 'Pedido', 'malka-crm' ); ?></th>
						<th><?php esc_html_e( 'Fecha', 'malka-crm' ); ?></th>
						<th><?php esc_html_e( 'Acciones', 'malka-crm' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $invoices ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'Sin facturas todavía.', 'malka-crm' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $invoices as $invoice ) : ?>
					<?php
					$user     = get_userdata( (int) get_post_meta( $invoice->ID, '_malka_pm_client_id', true ) );
					$order_id = (int) get_post_meta( $invoice->ID, '_malka_pm_order_id', true );
					$delete   = wp_nonce_url(
						admin_url( 'admin-post.php?action=malka_crm_delete_invoice&invoice_id=' . $invoice->ID ),
						'malka_crm_delete_invoice_' . $invoice->ID
					);
					?>
					<tr>
						<td><?php echo esc_html( $invoice->post_title ); ?></td>
						<td><?php echo $user ? esc_html( $user->display_name ) : '&mdash;'; ?></td>
						<td>
							<?php
							$linked = (int) get_post_meta( $invoice->ID, '_malka_pm_project_id', true );
							echo $linked ? esc_html( get_the_title( $linked ) ) : '&mdash;';
							?>
						</td>
						<td><?php echo $order_id ? esc_html( '#' . $order_id ) : '&mdash;'; ?></td>
						<td><?php echo esc_html( get_the_date( 'd/m/Y H:i', $invoice ) ); ?></td>
						<td>
							<a target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( Malka_CRM_Invoices::get_secure_url( $invoice->ID, 'view' ) ); ?>"><?php esc_html_e( 'Ver', 'malka-crm' ); ?></a> |
							<a href="<?php echo esc_url( $delete ); ?>" onclick="return confirm('<?php echo esc_js( __( '¿Eliminar esta factura de forma permanente?', 'malka-crm' ) ); ?>');"><?php esc_html_e( 'Eliminar', 'malka-crm' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Registra los ajustes.
	 */
	public function register_settings() {
		register_setting(
			'malka_crm_settings_group',
			Malka_CRM_Helpers::SETTINGS_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => Malka_CRM_Helpers::default_settings(),
			)
		);
	}

	/**
	 * Sanitiza los ajustes.
	 *
	 * @param array $input Entrada.
	 * @return array
	 */
	public function sanitize_settings( $input ) {

		$defaults = Malka_CRM_Helpers::default_settings();
		$input    = is_array( $input ) ? $input : array();

		$color = isset( $input['accent_color'] ) ? sanitize_hex_color( $input['accent_color'] ) : '';

		return array(
			'accent_color'     => $color ? $color : $defaults['accent_color'],
			'gantt_view_mode'  => in_array( $input['gantt_view_mode'] ?? '', array( 'Day', 'Week', 'Month' ), true ) ? $input['gantt_view_mode'] : 'Week',
			'invoice_render'   => in_array( $input['invoice_render'] ?? '', array( 'sandbox', 'kses' ), true ) ? $input['invoice_render'] : 'sandbox',
			'auto_project'      => empty( $input['auto_project'] ) ? 0 : 1,
			'project_duration'  => max( 1, absint( $input['project_duration'] ?? 30 ) ),
			'portal_intro'      => sanitize_textarea_field( $input['portal_intro'] ?? '' ),
			'approvals_enabled' => empty( $input['approvals_enabled'] ) ? 0 : 1,
			'approve_at_100'    => empty( $input['approve_at_100'] ) ? 0 : 1,
			'admin_email'       => is_email( $input['admin_email'] ?? '' ) ? sanitize_email( $input['admin_email'] ) : $defaults['admin_email'],
			'notify_invoice'    => empty( $input['notify_invoice'] ) ? 0 : 1,
			'notify_project'    => empty( $input['notify_project'] ) ? 0 : 1,
			'notify_status'     => empty( $input['notify_status'] ) ? 0 : 1,
			'notify_approval'   => empty( $input['notify_approval'] ) ? 0 : 1,
			'email_footer'      => sanitize_textarea_field( $input['email_footer'] ?? '' ),
			'round_limit_default' => absint( $input['round_limit_default'] ?? 2 ),
			'rounds_message'      => sanitize_textarea_field( $input['rounds_message'] ?? '' ),
			'rounds_cta_url'      => esc_url_raw( $input['rounds_cta_url'] ?? '' ),
			'reminders_enabled'   => empty( $input['reminders_enabled'] ) ? 0 : 1,
			'reminder_delay'      => max( 1, absint( $input['reminder_delay'] ?? 3 ) ),
			'reminder_repeat'     => max( 1, absint( $input['reminder_repeat'] ?? 4 ) ),
			'reminder_max'        => max( 1, absint( $input['reminder_max'] ?? 3 ) ),
			'reminder_escalate'   => max( 1, absint( $input['reminder_escalate'] ?? 10 ) ),
			'extra_rounds_enabled' => empty( $input['extra_rounds_enabled'] ) ? 0 : 1,
			'extra_round_product'  => absint( $input['extra_round_product'] ?? 0 ),
			'client_grace_days'    => max( 0, absint( $input['client_grace_days'] ?? 2 ) ),
			'monthly_capacity'     => max( 1, absint( $input['monthly_capacity'] ?? 3 ) ),
			'auto_close_overdue'   => empty( $input['auto_close_overdue'] ) ? 0 : 1,
			'payment_url'          => esc_url_raw( $input['payment_url'] ?? '' ),
			'autoshift_enabled'    => empty( $input['autoshift_enabled'] ) ? 0 : 1,
			'task_warn_days'       => max( 0, absint( $input['task_warn_days'] ?? 1 ) ),
			'payment_warn_days'    => max( 0, absint( $input['payment_warn_days'] ?? 5 ) ),
			'notify_client_task'   => empty( $input['notify_client_task'] ) ? 0 : 1,
			'notify_deadline'      => empty( $input['notify_deadline'] ) ? 0 : 1,
			'notify_shift'         => empty( $input['notify_shift'] ) ? 0 : 1,
			'notify_payment'       => empty( $input['notify_payment'] ) ? 0 : 1,
			'quotes_enabled'       => empty( $input['quotes_enabled'] ) ? 0 : 1,
		);
	}

	/**
	 * Página de ajustes.
	 */
	public function render_settings_page() {

		if ( ! Malka_CRM_Helpers::current_user_is_manager() ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ), 403 );
		}

		$s   = wp_parse_args( (array) get_option( Malka_CRM_Helpers::SETTINGS_KEY, array() ), Malka_CRM_Helpers::default_settings() );
		$msg = isset( $_GET['malka_crm_msg'] ) ? sanitize_key( wp_unslash( $_GET['malka_crm_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="wrap malka-crm-wrap">
			<h1><?php esc_html_e( 'Ajustes Generales', 'malka-crm' ); ?></h1>

			<?php if ( 'test_ok' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Correo de prueba enviado.', 'malka-crm' ); ?></p></div>
			<?php elseif ( 'test_fail' === $msg ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'wp_mail() falló. Instala un plugin SMTP (Fluent SMTP, WP Mail SMTP) para garantizar la entrega.', 'malka-crm' ); ?></p></div>
			<?php elseif ( 'cron_ok' === $msg ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						$sent = isset( $_GET['malka_crm_sent'] ) ? absint( $_GET['malka_crm_sent'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
						printf(
							/* translators: %d: correos enviados */
							esc_html( _n( 'Revisión ejecutada: %d aviso enviado.', 'Revisión ejecutada: %d avisos enviados.', $sent, 'malka-crm' ) ),
							$sent
						);
						?>
					</p>
				</div>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'malka_crm_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Color de acento', 'malka-crm' ); ?></th>
						<td><input type="color" name="malka_pm_settings[accent_color]" value="<?php echo esc_attr( $s['accent_color'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Vista por defecto del Gantt', 'malka-crm' ); ?></th>
						<td>
							<select name="malka_pm_settings[gantt_view_mode]">
								<?php foreach ( array( 'Day', 'Week', 'Month' ) as $mode ) : ?>
									<option value="<?php echo esc_attr( $mode ); ?>" <?php selected( $s['gantt_view_mode'], $mode ); ?>><?php echo esc_html( $mode ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Render de facturas', 'malka-crm' ); ?></th>
						<td>
							<label><input type="radio" name="malka_pm_settings[invoice_render]" value="sandbox" <?php checked( $s['invoice_render'], 'sandbox' ); ?>> <?php esc_html_e( 'HTML completo en sandbox (recomendado)', 'malka-crm' ); ?></label><br>
							<label><input type="radio" name="malka_pm_settings[invoice_render]" value="kses" <?php checked( $s['invoice_render'], 'kses' ); ?>> <?php esc_html_e( 'Filtrar con wp_kses (más estricto, puede romper estilos)', 'malka-crm' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Proyectos automáticos', 'malka-crm' ); ?></th>
						<td><label><input type="checkbox" name="malka_pm_settings[auto_project]" value="1" <?php checked( (int) $s['auto_project'], 1 ); ?>> <?php esc_html_e( 'Crear proyecto al completar pedidos vinculados', 'malka-crm' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Duración por defecto (días)', 'malka-crm' ); ?></th>
						<td><input type="number" min="1" name="malka_pm_settings[project_duration]" value="<?php echo esc_attr( (int) $s['project_duration'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Texto de bienvenida del portal', 'malka-crm' ); ?></th>
						<td><textarea class="large-text" rows="3" name="malka_pm_settings[portal_intro]"><?php echo esc_textarea( $s['portal_intro'] ); ?></textarea></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Aprobaciones', 'malka-crm' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Activar aprobaciones', 'malka-crm' ); ?></th>
						<td>
							<label><input type="checkbox" name="malka_pm_settings[approvals_enabled]" value="1" <?php checked( (int) $s['approvals_enabled'], 1 ); ?>> <?php esc_html_e( 'El cliente puede aprobar etapas y la entrega final desde el portal', 'malka-crm' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Restringir al 100%', 'malka-crm' ); ?></th>
						<td>
							<label><input type="checkbox" name="malka_pm_settings[approve_at_100]" value="1" <?php checked( (int) $s['approve_at_100'], 1 ); ?>> <?php esc_html_e( 'Solo permitir aprobar una etapa cuando su avance llegue al 100%', 'malka-crm' ); ?></label>
							<p class="description"><?php esc_html_e( 'Desactivado, el cliente puede aprobar en cuanto marques la fila como "La aprueba el cliente". Útil para hitos como el primer abono.', 'malka-crm' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Rondas incluidas por defecto', 'malka-crm' ); ?></th>
						<td>
							<input type="number" min="0" name="malka_pm_settings[round_limit_default]" value="<?php echo esc_attr( (int) $s['round_limit_default'] ); ?>">
							<p class="description"><?php esc_html_e( '0 = ilimitadas. Se puede sobrescribir por proyecto y por producto. Sugerencia: Esencial 1 · Identidad 2 · Branding 3.', 'malka-crm' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Mensaje al agotar rondas', 'malka-crm' ); ?></th>
						<td><textarea class="large-text" rows="3" name="malka_pm_settings[rounds_message]"><?php echo esc_textarea( $s['rounds_message'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'URL para cotizar ronda extra', 'malka-crm' ); ?></th>
						<td>
							<input type="url" class="regular-text" name="malka_pm_settings[rounds_cta_url]" value="<?php echo esc_attr( $s['rounds_cta_url'] ); ?>" placeholder="https://estudiomalka.cl/contacto/">
							<p class="description"><?php esc_html_e( 'Si la dejas vacía no se muestra el botón.', 'malka-crm' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Plazos, atrasos y pagos', 'malka-crm' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Reprogramación automática', 'malka-crm' ); ?></th>
						<td>
							<label><input type="checkbox" name="malka_pm_settings[autoshift_enabled]" value="1" <?php checked( (int) $s['autoshift_enabled'], 1 ); ?>> <?php esc_html_e( 'Por cada día de atraso, desplazar todas las tareas posteriores', 'malka-crm' ); ?></label>
							<p class="description"><?php esc_html_e( 'Se ejecuta una vez al día dentro del cron. Las etapas se recalculan solas.', 'malka-crm' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Avisar plazo del cliente con', 'malka-crm' ); ?></th>
						<td><input type="number" min="0" name="malka_pm_settings[task_warn_days]" value="<?php echo esc_attr( (int) $s['task_warn_days'] ); ?>"> <?php esc_html_e( 'día(s) de anticipación', 'malka-crm' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Avisar abono con', 'malka-crm' ); ?></th>
						<td><input type="number" min="0" name="malka_pm_settings[payment_warn_days]" value="<?php echo esc_attr( (int) $s['payment_warn_days'] ); ?>"> <?php esc_html_e( 'día(s) de anticipación', 'malka-crm' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Cotizaciones', 'malka-crm' ); ?></th>
						<td><label><input type="checkbox" name="malka_pm_settings[quotes_enabled]" value="1" <?php checked( (int) $s['quotes_enabled'], 1 ); ?>> <?php esc_html_e( 'Mostrar la pestaña "Cotizaciones" en Mi Cuenta', 'malka-crm' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Avisos adicionales', 'malka-crm' ); ?></th>
						<td>
							<label><input type="checkbox" name="malka_pm_settings[notify_client_task]" value="1" <?php checked( (int) $s['notify_client_task'], 1 ); ?>> <?php esc_html_e( 'Al estudio: el cliente completó su tarea', 'malka-crm' ); ?></label><br>
							<label><input type="checkbox" name="malka_pm_settings[notify_deadline]" value="1" <?php checked( (int) $s['notify_deadline'], 1 ); ?>> <?php esc_html_e( 'Al cliente: plazo por vencer y tarea atrasada', 'malka-crm' ); ?></label><br>
							<label><input type="checkbox" name="malka_pm_settings[notify_shift]" value="1" <?php checked( (int) $s['notify_shift'], 1 ); ?>> <?php esc_html_e( 'Al cliente: calendario reprogramado', 'malka-crm' ); ?></label><br>
							<label><input type="checkbox" name="malka_pm_settings[notify_payment]" value="1" <?php checked( (int) $s['notify_payment'], 1 ); ?>> <?php esc_html_e( 'Al cliente: abono por vencer o vencido', 'malka-crm' ); ?></label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Plazos del cliente', 'malka-crm' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Capacidad mensual', 'malka-crm' ); ?></th>
						<td>
							<input type="number" min="1" name="malka_pm_settings[monthly_capacity]" value="<?php echo esc_attr( (int) $s['monthly_capacity'] ); ?>">
							<p class="description"><?php esc_html_e( 'Máximo de proyectos activos a la vez. El Escritorio avisa cuando se llena.', 'malka-crm' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Días de gracia', 'malka-crm' ); ?></th>
						<td>
							<input type="number" min="0" name="malka_pm_settings[client_grace_days]" value="<?php echo esc_attr( (int) $s['client_grace_days'] ); ?>">
							<p class="description"><?php esc_html_e( 'Días después del plazo antes de dar la tarea por finalizada. El aviso se muestra al cliente en su portal.', 'malka-crm' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'URL de pago por defecto', 'malka-crm' ); ?></th>
						<td>
							<input type="url" class="regular-text" name="malka_pm_settings[payment_url]" value="<?php echo esc_attr( $s['payment_url'] ); ?>" placeholder="https://estudiomalka.cl/pagar/">
							<p class="description"><?php esc_html_e( 'Se usa en el botón "Pagar" del portal cuando el abono no trae su propio enlace.', 'malka-crm' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Cierre automático', 'malka-crm' ); ?></th>
						<td>
							<label><input type="checkbox" name="malka_pm_settings[auto_close_overdue]" value="1" <?php checked( (int) $s['auto_close_overdue'], 1 ); ?>> <?php esc_html_e( 'Dar por finalizada la tarea al superar los días de gracia', 'malka-crm' ); ?></label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Venta de rondas adicionales', 'malka-crm' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Activar venta', 'malka-crm' ); ?></th>
						<td>
							<label><input type="checkbox" name="malka_pm_settings[extra_rounds_enabled]" value="1" <?php checked( (int) $s['extra_rounds_enabled'], 1 ); ?>> <?php esc_html_e( 'Mostrar botón de compra cuando el cliente agote sus rondas', 'malka-crm' ); ?></label>
							<p class="description"><?php esc_html_e( 'Al confirmarse el pago (procesando o completado), la ronda se acredita sola y la etapa se reabre.', 'malka-crm' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Producto de la ronda extra', 'malka-crm' ); ?></th>
						<td>
							<?php
							$products = get_posts(
								array(
									'post_type'      => 'product',
									'post_status'    => 'publish',
									'posts_per_page' => 100,
									'orderby'        => 'title',
									'order'          => 'ASC',
								)
							);
							?>
							<select name="malka_pm_settings[extra_round_product]">
								<option value="0"><?php esc_html_e( '— Selecciona —', 'malka-crm' ); ?></option>
								<?php foreach ( $products as $product_post ) : ?>
									<option value="<?php echo esc_attr( $product_post->ID ); ?>" <?php selected( (int) $s['extra_round_product'], (int) $product_post->ID ); ?>>
										<?php echo esc_html( $product_post->post_title ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Crea un producto simple, virtual y oculto del catálogo (visibilidad: oculto) llamado por ejemplo "Ronda de revisión adicional".', 'malka-crm' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Recordatorios automáticos', 'malka-crm' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Activar', 'malka-crm' ); ?></th>
						<td>
							<label><input type="checkbox" name="malka_pm_settings[reminders_enabled]" value="1" <?php checked( (int) $s['reminders_enabled'], 1 ); ?>> <?php esc_html_e( 'Recordar al cliente las aprobaciones pendientes (tarea diaria)', 'malka-crm' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Primer recordatorio', 'malka-crm' ); ?></th>
						<td><input type="number" min="1" name="malka_pm_settings[reminder_delay]" value="<?php echo esc_attr( (int) $s['reminder_delay'] ); ?>"> <?php esc_html_e( 'días después de quedar lista la etapa', 'malka-crm' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Repetir cada', 'malka-crm' ); ?></th>
						<td><input type="number" min="1" name="malka_pm_settings[reminder_repeat]" value="<?php echo esc_attr( (int) $s['reminder_repeat'] ); ?>"> <?php esc_html_e( 'días', 'malka-crm' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Máximo de recordatorios', 'malka-crm' ); ?></th>
						<td><input type="number" min="1" name="malka_pm_settings[reminder_max]" value="<?php echo esc_attr( (int) $s['reminder_max'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Escalar al estudio a los', 'malka-crm' ); ?></th>
						<td>
							<input type="number" min="1" name="malka_pm_settings[reminder_escalate]" value="<?php echo esc_attr( (int) $s['reminder_escalate'] ); ?>"> <?php esc_html_e( 'días sin respuesta', 'malka-crm' ); ?>
							<p class="description"><?php esc_html_e( 'Aviso interno único: el proyecto ya no está bloqueado por producción sino por respuesta del cliente.', 'malka-crm' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Notificaciones por correo', 'malka-crm' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Correo del estudio', 'malka-crm' ); ?></th>
						<td>
							<input type="email" class="regular-text" name="malka_pm_settings[admin_email]" value="<?php echo esc_attr( $s['admin_email'] ); ?>">
							<p class="description"><?php esc_html_e( 'Remitente de los correos y destinatario de las aprobaciones.', 'malka-crm' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Avisos activos', 'malka-crm' ); ?></th>
						<td>
							<label><input type="checkbox" name="malka_pm_settings[notify_invoice]" value="1" <?php checked( (int) $s['notify_invoice'], 1 ); ?>> <?php esc_html_e( 'Al cliente: nueva factura disponible', 'malka-crm' ); ?></label><br>
							<label><input type="checkbox" name="malka_pm_settings[notify_project]" value="1" <?php checked( (int) $s['notify_project'], 1 ); ?>> <?php esc_html_e( 'Al cliente: proyecto abierto', 'malka-crm' ); ?></label><br>
							<label><input type="checkbox" name="malka_pm_settings[notify_status]" value="1" <?php checked( (int) $s['notify_status'], 1 ); ?>> <?php esc_html_e( 'Al cliente: cambio de estado del proyecto', 'malka-crm' ); ?></label><br>
							<label><input type="checkbox" name="malka_pm_settings[notify_approval]" value="1" <?php checked( (int) $s['notify_approval'], 1 ); ?>> <?php esc_html_e( 'Al estudio: aprobaciones y solicitudes de cambio', 'malka-crm' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Pie de los correos', 'malka-crm' ); ?></th>
						<td><textarea class="large-text" rows="2" name="malka_pm_settings[email_footer]"><?php echo esc_textarea( $s['email_footer'] ); ?></textarea></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Recordatorios: ejecución manual', 'malka-crm' ); ?></h2>
			<p class="description">
				<?php
				$next = wp_next_scheduled( Malka_CRM_Cron::HOOK );
				$last = get_option( 'malka_crm_last_cron' );
				printf(
					/* translators: 1: próxima ejecución, 2: última ejecución */
					esc_html__( 'Próxima ejecución: %1$s · Última: %2$s', 'malka-crm' ),
					esc_html( $next ? date_i18n( 'd/m/Y H:i', $next ) : __( 'no programada', 'malka-crm' ) ),
					esc_html( $last ? date_i18n( 'd/m/Y H:i', strtotime( $last ) ) : __( 'nunca', 'malka-crm' ) )
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="malka_crm_run_cron">
				<?php wp_nonce_field( 'malka_crm_run_cron' ); ?>
				<?php submit_button( __( 'Ejecutar ahora', 'malka-crm' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Probar el envío', 'malka-crm' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="malka_crm_test_email">
				<?php wp_nonce_field( 'malka_crm_test_email' ); ?>
				<input type="email" class="regular-text" name="malka_crm_test_to" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>">
				<?php submit_button( __( 'Enviar correo de prueba', 'malka-crm' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Diagnóstico', 'malka-crm' ); ?></h2>
			<?php $last_error = get_option( 'malka_crm_last_error' ); ?>
			<table class="widefat striped" style="max-width:760px">
				<tbody>
					<tr><td><?php esc_html_e( 'Versión del plugin', 'malka-crm' ); ?></td><td><?php echo esc_html( MALKA_CRM_VERSION ); ?></td></tr>
					<tr><td><?php esc_html_e( 'PHP', 'malka-crm' ); ?></td><td><?php echo esc_html( PHP_VERSION ); ?></td></tr>
					<tr><td><?php esc_html_e( 'WordPress', 'malka-crm' ); ?></td><td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td></tr>
					<tr><td><?php esc_html_e( 'WooCommerce', 'malka-crm' ); ?></td><td><?php echo class_exists( 'WooCommerce' ) ? esc_html__( 'Activo', 'malka-crm' ) : esc_html__( 'No detectado', 'malka-crm' ); ?></td></tr>
					<tr>
						<td><?php esc_html_e( 'Último error registrado', 'malka-crm' ); ?></td>
						<td>
							<?php if ( is_array( $last_error ) ) : ?>
								<code><?php echo esc_html( $last_error['message'] ); ?></code><br>
								<span class="description"><?php echo esc_html( basename( $last_error['file'] ) . ':' . $last_error['line'] . ' · ' . $last_error['context'] . ' · ' . $last_error['time'] ); ?></span>
							<?php else : ?>
								<span class="description"><?php esc_html_e( 'Ninguno.', 'malka-crm' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Shortcodes disponibles', 'malka-crm' ); ?></h2>
			<p><code>[malka_crm_projects]</code> &nbsp; <code>[malka_crm_invoices]</code></p>
			<p class="description"><?php esc_html_e( 'Si cambias los endpoints o no ves las pestañas, ve a Ajustes → Enlaces permanentes y pulsa Guardar.', 'malka-crm' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Metabox: registro de aprobaciones del cliente.
	 *
	 * @param WP_Post $post Post.
	 */
	public function render_project_approvals( $post ) {

		$tasks = Malka_CRM_Helpers::get_real_tasks( $post->ID );

		if ( empty( $tasks ) ) {
			echo '<p class="description">' . esc_html__( 'Añade tareas para habilitar las aprobaciones.', 'malka-crm' ) . '</p>';
			return;
		}

		$summary = Malka_CRM_Approvals::get_summary( $post->ID );

		$rows = array();
		$rows[] = array(
			'id'   => Malka_CRM_Approvals::FINAL_KEY,
			'name' => __( 'Entrega final', 'malka-crm' ),
		);

		foreach ( $tasks as $task ) {
			$rows[] = array(
				'id'   => $task['id'],
				'name' => $task['name'],
			);
		}
		?>
		<p class="description">
			<?php
			printf(
				/* translators: 1: aprobadas, 2: total, 3: cambios, 4: rondas */
				esc_html__( '%1$d de %2$d aprobadas · %3$d con cambios · %4$d rondas consumidas.', 'malka-crm' ),
				(int) $summary['approved'],
				(int) $summary['total'],
				(int) $summary['changes'],
				(int) $summary['rounds']
			);
			?>
		</p>

		<table class="widefat malka-crm-approvals-table">
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<?php
				$record = Malka_CRM_Approvals::get_approval( $post->ID, $row['id'] );
				$reset  = wp_nonce_url(
					admin_url( 'admin-post.php?action=malka_crm_reset_approval&project_id=' . $post->ID . '&task_key=' . rawurlencode( $row['id'] ) ),
					'malka_crm_reset_approval_' . $post->ID . '_' . $row['id']
				);
				$grant  = wp_nonce_url(
					admin_url( 'admin-post.php?action=malka_crm_grant_round&project_id=' . $post->ID . '&task_key=' . rawurlencode( $row['id'] ) ),
					'malka_crm_grant_round_' . $post->ID . '_' . $row['id']
				);
				?>
				<tr>
					<td>
						<strong><?php echo esc_html( $row['name'] ); ?></strong><br>
						<span class="malka-crm-badge malka-crm-badge--<?php echo esc_attr( $record['status'] ); ?>">
							<?php echo esc_html( Malka_CRM_Approvals::status_label( $record['status'] ) ); ?>
						</span>
						<?php if ( ! empty( $record['date'] ) ) : ?>
							<span class="description"><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $record['date'] ) ) ); ?></span>
						<?php endif; ?>
						<br>
						<span class="description"><?php echo esc_html( Malka_CRM_Approvals::rounds_label( $post->ID, $row['id'] ) ); ?></span>
						<a class="malka-crm-reset" href="<?php echo esc_url( $grant ); ?>"><?php esc_html_e( '+1 ronda', 'malka-crm' ); ?></a>
						<?php if ( 'approved' === $record['status'] && ! empty( $record['signer'] ) ) : ?>
							<blockquote class="malka-crm-note">
								<?php
								printf(
									/* translators: 1: nombre, 2: firma, 3: IP */
									esc_html__( 'Firmado por %1$s · firma: "%2$s" · IP %3$s', 'malka-crm' ),
									esc_html( $record['signer'] ),
									esc_html( $record['signature'] ),
									esc_html( $record['ip'] )
								);
								?>
							</blockquote>
						<?php endif; ?>
						<?php if ( 'changes' === $record['status'] && ! empty( $record['note'] ) ) : ?>
							<blockquote class="malka-crm-note"><?php echo esc_html( $record['note'] ); ?></blockquote>
						<?php endif; ?>
						<?php if ( 'pending' !== $record['status'] ) : ?>
							<br><a class="malka-crm-reset" href="<?php echo esc_url( $reset ); ?>"><?php esc_html_e( 'Reabrir', 'malka-crm' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Metabox: documentos vinculados al proyecto.
	 *
	 * @param WP_Post $post Post.
	 */
	public function render_project_docs( $post ) {

		$quotes = get_posts(
			array(
				'post_type'      => 'malka_quote',
				'post_status'    => 'any',
				'posts_per_page' => 20,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_malka_pm_project_id',
						'value' => $post->ID,
					),
				),
			)
		);

		$invoices = get_posts(
			array(
				'post_type'      => 'malka_invoice',
				'post_status'    => array( 'private', 'publish' ),
				'posts_per_page' => 20,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_malka_pm_project_id',
						'value' => $post->ID,
					),
				),
			)
		);
		?>
		<p><strong><?php esc_html_e( 'Cotizaciones', 'malka-crm' ); ?></strong></p>
		<?php if ( empty( $quotes ) ) : ?>
			<p class="description"><?php esc_html_e( 'Ninguna vinculada todavía.', 'malka-crm' ); ?></p>
		<?php else : ?>
			<ul class="malka-crm-doclist">
				<?php foreach ( $quotes as $quote ) : ?>
					<li><a href="<?php echo esc_url( get_edit_post_link( $quote->ID ) ); ?>"><?php echo esc_html( $quote->post_title ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<p><strong><?php esc_html_e( 'Facturas', 'malka-crm' ); ?></strong></p>
		<?php if ( empty( $invoices ) ) : ?>
			<p class="description"><?php esc_html_e( 'Ninguna vinculada todavía.', 'malka-crm' ); ?></p>
		<?php else : ?>
			<ul class="malka-crm-doclist">
				<?php foreach ( $invoices as $invoice ) : ?>
					<li>
						<a href="<?php echo esc_url( Malka_CRM_Invoices::get_secure_url( $invoice->ID, 'view' ) ); ?>" target="_blank" rel="noopener noreferrer">
							<?php echo esc_html( $invoice->post_title ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<p>
			<a class="button button-small" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=malka_quote&malka_project=' . $post->ID ) ); ?>"><?php esc_html_e( 'Nueva cotización', 'malka-crm' ); ?></a>
			<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=malka-crm-invoices&project_id=' . $post->ID ) ); ?>"><?php esc_html_e( 'Subir factura', 'malka-crm' ); ?></a>
		</p>
		<?php
	}

	/**
	 * Acredita una ronda manualmente.
	 */
	public function handle_grant_round() {

		if ( ! Malka_CRM_Helpers::current_user_is_manager() ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ), 403 );
		}

		$project_id = isset( $_GET['project_id'] ) ? absint( $_GET['project_id'] ) : 0;
		$key        = isset( $_GET['task_key'] ) ? sanitize_key( wp_unslash( $_GET['task_key'] ) ) : '';

		check_admin_referer( 'malka_crm_grant_round_' . $project_id . '_' . $key );

		if ( $project_id && $key && class_exists( 'Malka_CRM_Extra_Rounds' ) ) {
			Malka_CRM_Extra_Rounds::add_extra( $project_id, $key, 1 );
		}

		wp_safe_redirect( get_edit_post_link( $project_id, 'redirect' ) );
		exit;
	}

	/**
	 * Envía un correo de prueba.
	 */
	public function handle_test_email() {

		if ( ! Malka_CRM_Helpers::current_user_is_manager() ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ), 403 );
		}

		check_admin_referer( 'malka_crm_test_email' );

		$to   = isset( $_POST['malka_crm_test_to'] ) ? sanitize_email( wp_unslash( $_POST['malka_crm_test_to'] ) ) : '';
		$to   = $to ? $to : wp_get_current_user()->user_email;
		$sent = malka_crm()->notifications->send_test( $to );

		wp_safe_redirect(
			add_query_arg(
				'malka_crm_msg',
				$sent ? 'test_ok' : 'test_fail',
				admin_url( 'admin.php?page=malka-crm-settings' )
			)
		);
		exit;
	}

	/**
	 * Página "Tareas": editor rápido de tareas y etapas sin entrar al proyecto.
	 */
	public function render_tasks_page() {

		if ( ! Malka_CRM_Helpers::current_user_is_manager() ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ), 403 );
		}

		$projects = get_posts(
			array(
				'post_type'      => 'malka_project',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 200,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$current = isset( $_GET['project_id'] ) ? absint( $_GET['project_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! $current && ! empty( $projects ) ) {
			$current = (int) $projects[0]->ID;
		}

		$msg = isset( $_GET['malka_crm_msg'] ) ? sanitize_key( wp_unslash( $_GET['malka_crm_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="wrap malka-crm-wrap">
			<h1><?php esc_html_e( 'Tareas y etapas', 'malka-crm' ); ?></h1>

			<?php if ( 'tasks_saved' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Tareas guardadas.', 'malka-crm' ); ?></p></div>
			<?php elseif ( 'template_applied' === $msg ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						$applied = isset( $_GET['malka_crm_sent'] ) ? absint( $_GET['malka_crm_sent'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
						printf(
							/* translators: %d: ítems */
							esc_html__( 'Plantilla aplicada: %d ítems cargados. Ajusta fechas y responsables antes de guardar.', 'malka-crm' ),
							$applied
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( empty( $projects ) ) : ?>
				<p class="description"><?php esc_html_e( 'Todavía no hay proyectos. Crea uno para cargar sus tareas.', 'malka-crm' ); ?></p>
				<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=malka_project' ) ); ?>"><?php esc_html_e( 'Nuevo proyecto', 'malka-crm' ); ?></a></p>
				</div>
				<?php
				return;
			endif;
			?>

			<form method="get" class="malka-crm-project-picker">
				<input type="hidden" name="page" value="malka-crm-tasks">
				<label for="malka_crm_project_picker"><strong><?php esc_html_e( 'Proyecto:', 'malka-crm' ); ?></strong></label>
				<select name="project_id" id="malka_crm_project_picker" onchange="this.form.submit()">
					<?php foreach ( $projects as $project ) : ?>
						<option value="<?php echo esc_attr( $project->ID ); ?>" <?php selected( $current, $project->ID ); ?>>
							<?php echo esc_html( $project->post_title ); ?>
							(<?php echo esc_html( Malka_CRM_Helpers::get_project_progress( $project->ID ) . '%' ); ?>)
						</option>
					<?php endforeach; ?>
				</select>
				<a class="button" href="<?php echo esc_url( get_edit_post_link( $current ) ); ?>"><?php esc_html_e( 'Abrir proyecto completo', 'malka-crm' ); ?></a>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="malka_crm_save_tasks">
				<input type="hidden" name="project_id" value="<?php echo esc_attr( $current ); ?>">
				<?php wp_nonce_field( 'malka_crm_save_tasks_' . $current ); ?>

				<?php $this->render_project_tasks( get_post( $current ) ); ?>

				<?php submit_button( __( 'Guardar tareas', 'malka-crm' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Guarda las tareas enviadas desde la página "Tareas".
	 */
	public function handle_save_tasks() {

		if ( ! Malka_CRM_Helpers::current_user_is_manager() ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ), 403 );
		}

		$project_id = isset( $_POST['project_id'] ) ? absint( $_POST['project_id'] ) : 0;

		check_admin_referer( 'malka_crm_save_tasks_' . $project_id );

		if ( $project_id && 'malka_project' === get_post_type( $project_id ) ) {

			$raw   = isset( $_POST['malka_crm_tasks'] ) ? (array) wp_unslash( $_POST['malka_crm_tasks'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$tasks = Malka_CRM_Helpers::sanitize_tasks( $raw );

			Malka_CRM_Helpers::save_tasks( $project_id, $tasks );
			Malka_CRM_Approvals::track_completions( $project_id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'malka-crm-tasks',
					'project_id'    => $project_id,
					'malka_crm_msg' => 'tasks_saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Envoltorio de metaboxes: un fallo dentro de una caja no puede tumbar
	 * la pantalla completa ni pausar el plugin. Muestra el diagnóstico exacto.
	 *
	 * @param WP_Post $post Post.
	 * @param array   $box  Datos de la caja.
	 */
	public function boxed( $post, $box = array() ) {

		$cb = isset( $box['args']['cb'] ) ? $box['args']['cb'] : '';

		if ( ! $cb || ! method_exists( $this, $cb ) ) {
			echo '<p class="description">' . esc_html__( 'Bloque no disponible.', 'malka-crm' ) . '</p>';
			return;
		}

		try {
			$this->$cb( $post );
		} catch ( Throwable $e ) {
			$this->render_error( $e, $cb );
		}
	}

	/**
	 * Ejecuta una página del plugin protegida.
	 *
	 * @param string $cb Método.
	 */
	public function safe_page( $cb ) {

		if ( ! method_exists( $this, $cb ) ) {
			return;
		}

		try {
			$this->$cb();
		} catch ( Throwable $e ) {
			echo '<div class="wrap malka-crm-wrap"><h1>Malka CRM</h1>';
			$this->render_error( $e, $cb );
			echo '</div>';
		}
	}

	/**
	 * Muestra y registra un error controlado.
	 *
	 * @param Throwable $e       Excepción.
	 * @param string    $context Método donde ocurrió.
	 */
	private function render_error( $e, $context = '' ) {

		$data = array(
			'message' => $e->getMessage(),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
			'context' => $context,
			'time'    => current_time( 'mysql' ),
		);

		update_option( 'malka_crm_last_error', $data, false );
		?>
		<div class="notice notice-error inline">
			<p><strong><?php esc_html_e( 'Este bloque no pudo cargarse', 'malka-crm' ); ?></strong></p>
			<p><code><?php echo esc_html( $data['message'] ); ?></code></p>
			<p class="description">
				<?php echo esc_html( basename( $data['file'] ) . ':' . $data['line'] . ' · ' . $context ); ?>
			</p>
			<p class="description"><?php esc_html_e( 'El resto de la pantalla sigue funcionando. Copia este texto y envíalo para corregirlo.', 'malka-crm' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Escritorio dentro de WP-Admin (mismo contenido que /escritorio/).
	 */
	public function render_dashboard_page() {

		if ( ! Malka_CRM_Helpers::current_user_is_manager() ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ), 403 );
		}

		echo '<div class="wrap malka-crm-wrap malka-crm-dashboard-wrap">';
		echo Malka_CRM_Dashboard::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
	}
}
