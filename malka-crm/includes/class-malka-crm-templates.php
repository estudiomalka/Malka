<?php
/**
 * Módulo 9: Plantillas de proyecto.
 *
 * Una plantilla guarda solo la estructura: número, nombre, tipo y responsable.
 * Las fechas y los porcentajes se generan al aplicarla a un proyecto concreto,
 * porque pertenecen al proyecto y no a la metodología.
 *
 * Meta `_malka_pm_template_items`: array ordenado de
 *   number, name, type (stage|task|milestone), owner (studio|client), due_days.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

class Malka_CRM_Templates {

	const POST_TYPE = 'malka_template';
	const META_ITEMS = '_malka_pm_template_items';
	const META_TAGS  = '_malka_pm_template_tags';

	public function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_template' ), 10, 2 );
		add_action( 'admin_post_malka_crm_apply_template', array( $this, 'handle_apply' ) );
		add_action( 'admin_post_malka_crm_duplicate_template', array( $this, 'handle_duplicate' ) );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
	}

	/**
	 * CPT de plantillas.
	 */
	public function register_post_type() {

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Plantillas', 'malka-crm' ),
					'singular_name' => __( 'Plantilla', 'malka-crm' ),
					'add_new_item'  => __( 'Añadir plantilla', 'malka-crm' ),
					'edit_item'     => __( 'Editar plantilla', 'malka-crm' ),
					'menu_name'     => __( 'Plantillas', 'malka-crm' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'has_archive'         => false,
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	/* ──────────────────── Datos ──────────────────── */

	/**
	 * Todas las plantillas publicadas.
	 *
	 * @return WP_Post[]
	 */
	public static function get_templates() {
		return get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}

	/**
	 * Ítems de una plantilla.
	 *
	 * @param int $template_id ID.
	 * @return array
	 */
	public static function get_tags( $template_id ) {
		$t = get_post_meta( (int) $template_id, self::META_TAGS, true );
		return is_array( $t ) ? array_values( array_filter( array_map( 'strval', $t ) ) ) : array();
	}

	/**
	 * Guarda las etiquetas («RRSS, Mensual»).
	 *
	 * @param int          $template_id Plantilla.
	 * @param string|array $tags        Texto separado por comas, o lista.
	 */
	public static function set_tags( $template_id, $tags ) {
		$lista = is_array( $tags ) ? $tags : explode( ',', (string) $tags );
		$out   = array();
		foreach ( $lista as $t ) {
			$t = trim( sanitize_text_field( $t ) );
			if ( '' !== $t && ! in_array( mb_strtolower( $t ), array_map( 'mb_strtolower', $out ), true ) ) {
				$out[] = $t;
			}
		}
		update_post_meta( (int) $template_id, self::META_TAGS, $out );
	}

	/**
	 * Todas las etiquetas usadas, para sugerirlas.
	 *
	 * @return string[]
	 */
	public static function all_tags() {
		$out = array( 'RRSS' );
		foreach ( self::get_templates() as $t ) {
			$out = array_merge( $out, self::get_tags( $t->ID ) );
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * ¿Es una plantilla de redes sociales?
	 *
	 * @param int $template_id Plantilla.
	 * @return bool
	 */
	public static function es_rrss( $template_id ) {
		foreach ( self::get_tags( $template_id ) as $t ) {
			if ( preg_match( '/rrss|redes|social|instagram|tiktok/i', remove_accents( $t ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Ítems de una plantilla.
	 *
	 * @param int $template_id Plantilla.
	 * @return array
	 */
	public static function get_items( $template_id ) {

		$items = get_post_meta( (int) $template_id, self::META_ITEMS, true );

		if ( ! is_array( $items ) ) {
			return array();
		}

		$out = array();

		foreach ( $items as $item ) {
			$out[] = wp_parse_args(
				(array) $item,
				array(
					'number'   => '',
					'name'     => '',
					'type'     => 'task',
					'owner'          => 'studio',
					'due_days'       => 0,
					'needs_approval' => 0,
					'rounds'         => '',
				)
			);
		}

		return $out;
	}

	/**
	 * Sanitiza los ítems enviados desde el formulario.
	 *
	 * @param array $raw Datos.
	 * @return array
	 */
	public static function sanitize_items( $raw ) {

		$clean = array();

		if ( ! is_array( $raw ) ) {
			return $clean;
		}

		foreach ( $raw as $row ) {

			if ( ! is_array( $row ) ) {
				continue;
			}

			$name = isset( $row['name'] ) ? sanitize_text_field( wp_unslash( $row['name'] ) ) : '';

			if ( '' === $name ) {
				continue;
			}

			$type = isset( $row['type'] ) ? sanitize_key( $row['type'] ) : 'task';
			if ( ! in_array( $type, array( 'stage', 'task', 'milestone' ), true ) ) {
				$type = 'task';
			}

			$clean[] = array(
				'needs_approval' => ! empty( $row['needs_approval'] ) ? 1 : 0,
				'rounds'         => ( isset( $row['rounds'] ) && '' !== trim( (string) $row['rounds'] ) ) ? (string) max( 0, (int) $row['rounds'] ) : '',
				'number'   => isset( $row['number'] ) ? sanitize_text_field( wp_unslash( $row['number'] ) ) : '',
				'name'     => $name,
				'type'     => $type,
				'owner'    => in_array( isset( $row['owner'] ) ? $row['owner'] : '', array( 'client', 'none' ), true ) ? sanitize_key( $row['owner'] ) : 'studio',
				'due_days' => isset( $row['due_days'] ) ? max( 0, (int) $row['due_days'] ) : 0,
				'notes'    => isset( $row['notes'] ) ? sanitize_text_field( wp_unslash( $row['notes'] ) ) : '',
				'pub_copy' => isset( $row['pub_copy'] ) ? sanitize_textarea_field( wp_unslash( $row['pub_copy'] ) ) : '',
				'pub_url'  => isset( $row['pub_url'] ) ? esc_url_raw( wp_unslash( $row['pub_url'] ) ) : '',
				'es_pub'   => isset( $row['es_pub'] ) && '' !== (string) $row['es_pub'] ? ( (int) $row['es_pub'] ? 1 : 0 ) : '',
				'pub_formato'     => isset( $row['pub_formato'] ) ? sanitize_key( wp_unslash( $row['pub_formato'] ) ) : '',
				'pub_tema'        => isset( $row['pub_tema'] ) ? sanitize_text_field( wp_unslash( $row['pub_tema'] ) ) : '',
				'pub_hashtags'    => isset( $row['pub_hashtags'] ) ? sanitize_textarea_field( wp_unslash( $row['pub_hashtags'] ) ) : '',
				'pub_voz'         => isset( $row['pub_voz'] ) ? sanitize_textarea_field( wp_unslash( $row['pub_voz'] ) ) : '',
				'pub_encuesta'    => isset( $row['pub_encuesta'] ) ? sanitize_textarea_field( wp_unslash( $row['pub_encuesta'] ) ) : '',
				'pub_prueba'      => ! empty( $row['pub_prueba'] ) ? '1' : '',
				'pub_enlace_reel' => isset( $row['pub_enlace_reel'] ) ? sanitize_text_field( wp_unslash( $row['pub_enlace_reel'] ) ) : '',
				'pub_portada'     => isset( $row['pub_portada'] ) ? esc_url_raw( wp_unslash( $row['pub_portada'] ) ) : '',
				'pub_musica'      => isset( $row['pub_musica'] ) ? sanitize_text_field( wp_unslash( $row['pub_musica'] ) ) : '',
				'respuesta'       => ( isset( $row['respuesta'] ) && in_array( $row['respuesta'], array( 'no', 'nota', 'audio', 'ambas' ), true ) ) ? $row['respuesta'] : '',
				'respuesta_preg'  => isset( $row['respuesta_preg'] ) ? sanitize_text_field( wp_unslash( $row['respuesta_preg'] ) ) : '',
				'pub_quien'       => ( isset( $row['pub_quien'] ) && 'client' === $row['pub_quien'] ) ? 'client' : '',
				'pub_nota'        => isset( $row['pub_nota'] ) ? sanitize_textarea_field( wp_unslash( $row['pub_nota'] ) ) : '',
			);
		}

		return $clean;
	}

	/* ──────────────────── Metabox ──────────────────── */

	/**
	 * Registra el editor de la plantilla.
	 */
	public function register_metabox() {
		add_meta_box(
			'malka_crm_template_items',
			__( 'Estructura de la plantilla', 'malka-crm' ),
			array( $this, 'render_metabox' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Editor con filas arrastrables.
	 *
	 * @param WP_Post $post Post.
	 */
	public function render_metabox( $post ) {

		wp_nonce_field( 'malka_crm_save_template', 'malka_crm_template_nonce' );

		$items = self::get_items( $post->ID );
		?>
		<div class="malka-crm-tasks-wrap">
			<p class="description malka-crm-tasks-help">
				<?php esc_html_e( 'Solo estructura: número, nombre, tipo y responsable. Las fechas se generan al aplicar la plantilla a un proyecto. Arrastra el asa para reordenar.', 'malka-crm' ); ?>
			</p>

			<p class="malka-crm-tasks-actions">
				<button type="button" class="button button-primary" id="malka-crm-add-tpl-stage"><?php esc_html_e( '+ Añadir etapa', 'malka-crm' ); ?></button>
				<button type="button" class="button button-secondary" id="malka-crm-add-tpl-item"><?php esc_html_e( '+ Añadir ítem', 'malka-crm' ); ?></button>
			</p>

			<div class="malka-crm-rows" id="malka-crm-template-body">
				<?php foreach ( $items as $i => $item ) : ?>
					<?php self::item_row( $i, $item ); ?>
				<?php endforeach; ?>
			</div>

			<script type="text/html" id="tmpl-malka-crm-tpl-item">
				<?php self::item_row( '__INDEX__', array( 'type' => 'task' ) ); ?>
			</script>

			<script type="text/html" id="tmpl-malka-crm-tpl-stage">
				<?php self::item_row( '__INDEX__', array( 'type' => 'stage' ) ); ?>
			</script>

			<p class="malka-crm-tasks-actions">
				<button type="button" class="button button-primary" id="malka-crm-add-tpl-stage-2"><?php esc_html_e( '+ Añadir etapa', 'malka-crm' ); ?></button>
				<button type="button" class="button button-secondary" id="malka-crm-add-tpl-item-2"><?php esc_html_e( '+ Añadir ítem', 'malka-crm' ); ?></button>
			</p>
		</div>
		<?php
	}

	/**
	 * Una fila de la plantilla.
	 *
	 * @param int|string $index Índice.
	 * @param array      $item  Datos.
	 */
	public static function item_row( $index, $item = array() ) {

		$item = wp_parse_args(
			(array) $item,
			array(
				'number'         => '',
				'name'           => '',
				'type'           => 'task',
				'owner'          => 'studio',
				'due_days'       => 0,
				'needs_approval' => 0,
				'rounds'         => '',
			)
		);

		$attr     = 'malka_crm_template[' . $index . ']';
		$is_stage = 'stage' === $item['type'];
		?>
		<div class="malka-crm-template-row malka-crm-task-row <?php echo esc_attr( 'is-' . $item['type'] ); ?>">

			<div class="malka-crm-row-main">
				<span class="malka-crm-handle" title="<?php esc_attr_e( 'Arrastra para reordenar', 'malka-crm' ); ?>">⠿</span>

				<input type="text" class="malka-crm-f-number" name="<?php echo esc_attr( $attr ); ?>[number]" value="<?php echo esc_attr( $item['number'] ); ?>" placeholder="1.2" title="<?php esc_attr_e( 'Número', 'malka-crm' ); ?>">

				<select class="malka-crm-type malka-crm-f-type" name="<?php echo esc_attr( $attr ); ?>[type]">
					<?php foreach ( Malka_CRM_Helpers::task_types() as $type_key => $type_label ) : ?>
						<option value="<?php echo esc_attr( $type_key ); ?>" <?php selected( $item['type'], $type_key ); ?>><?php echo esc_html( $type_label ); ?></option>
					<?php endforeach; ?>
				</select>

				<input type="text" class="malka-crm-f-name" name="<?php echo esc_attr( $attr ); ?>[name]" value="<?php echo esc_attr( $item['name'] ); ?>" placeholder="<?php esc_attr_e( 'Nombre de la etapa o ítem', 'malka-crm' ); ?>">

				<button type="button" class="button-link malka-crm-remove-task" aria-label="<?php esc_attr_e( 'Eliminar', 'malka-crm' ); ?>">&times;</button>
			</div>

			<div class="malka-crm-row-meta">
				<label>
					<span><?php esc_html_e( 'Responsable', 'malka-crm' ); ?></span>
					<select name="<?php echo esc_attr( $attr ); ?>[owner]">
						<?php foreach ( Malka_CRM_Helpers::task_owners() as $owner_key => $owner_label ) : ?>
							<option value="<?php echo esc_attr( $owner_key ); ?>" <?php selected( $item['owner'], $owner_key ); ?>><?php echo esc_html( $owner_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<label class="is-narrow">
					<span><?php esc_html_e( 'Días', 'malka-crm' ); ?></span>
					<input type="number" min="0" name="<?php echo esc_attr( $attr ); ?>[due_days]" value="<?php echo esc_attr( (int) $item['due_days'] ); ?>">
				</label>

				<label class="is-narrow">
					<span><?php esc_html_e( 'Correcciones', 'malka-crm' ); ?></span>
					<input type="number" min="0" name="<?php echo esc_attr( $attr ); ?>[rounds]" value="<?php echo esc_attr( $item['rounds'] ); ?>" placeholder="—">
				</label>

				<label class="is-check">
					<input type="checkbox" name="<?php echo esc_attr( $attr ); ?>[needs_approval]" value="1" <?php checked( ! empty( $item['needs_approval'] ) ); ?>>
					<span><?php esc_html_e( 'La aprueba el cliente', 'malka-crm' ); ?></span>
				</label>
			</div>
		</div>
		<?php
	}

	/**
	 * Guarda la plantilla.
	 *
	 * @param int     $post_id ID.
	 * @param WP_Post $post    Post.
	 */
	public function save_template( $post_id, $post ) {

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST['malka_crm_template_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['malka_crm_template_nonce'] ) ), 'malka_crm_save_template' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) || ! Malka_CRM_Helpers::current_user_is_manager() ) {
			return;
		}

		$raw = isset( $_POST['malka_crm_template'] ) ? (array) wp_unslash( $_POST['malka_crm_template'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		update_post_meta( $post_id, self::META_ITEMS, self::sanitize_items( $raw ) );
	}

	/* ──────────────────── Aplicar ──────────────────── */

	/**
	 * Aplica una plantilla a un proyecto generando fechas correlativas.
	 *
	 * @param int    $template_id ID de plantilla.
	 * @param int    $project_id  ID de proyecto.
	 * @param string $start       Fecha de inicio Y-m-d.
	 * @param bool   $replace     Sustituir las tareas existentes.
	 * @return int Ítems creados.
	 */
	public static function apply( $template_id, $project_id, $start = '', $replace = true ) {

		$items = self::get_items( $template_id );

		if ( empty( $items ) ) {
			return 0;
		}

		$start = Malka_CRM_Helpers::sanitize_date( $start );
		if ( ! $start ) {
			$start = current_time( 'Y-m-d' );
		}

		$existing = $replace ? array() : Malka_CRM_Helpers::get_project_tasks( $project_id );
		$tasks    = $existing;
		$cursor   = $start;
		$stage_id = '';
		$counter  = 0;

		foreach ( $items as $item ) {

			$counter++;

			$slug = $item['number'] ? sanitize_key( str_replace( '.', '-', $item['number'] ) ) : '';
			$id   = sanitize_key( ( $slug ? $slug : 'item-' . $counter ) . '-' . wp_generate_password( 3, false, false ) );

			// El número va en su propia columna: no se repite en el nombre.
			$name = $item['name'];

			if ( 'stage' === $item['type'] ) {
				$stage_id = $id;

				$tasks[] = array(
					'id'           => $id,
					'name'         => $name,
					'type'         => 'stage',
					'stage'        => '',
					'start'        => $cursor,
					'end'          => $cursor,
					'progress'     => 0,
					'dependencies' => '',
					'owner'        => 'studio',
					'assignee'     => '',
					'due_days'     => 0,
					'done_at'      => '',
					'notes'        => '',
					'number'       => $item['number'],
				);

				continue;
			}

			$days = max( 0, (int) $item['due_days'] );
			$end  = $days ? Malka_CRM_Helpers::add_days( $cursor, $days ) : $cursor;

			$tasks[] = array(
				'id'           => $id,
				'name'         => $name,
				'type'         => $item['type'],
				'stage'        => $stage_id,
				'start'        => $cursor,
				'end'          => $end,
				'progress'     => 0,
				'dependencies' => '',
				'owner'        => $item['owner'],
				'assignee'     => '',
				'due_days'     => $days,
				'done_at'      => '',
				'notes'        => isset( $item['notes'] ) ? $item['notes'] : '',
				'pub_copy'     => isset( $item['pub_copy'] ) ? $item['pub_copy'] : '',
				'pub_url'      => isset( $item['pub_url'] ) ? $item['pub_url'] : '',
				'es_pub'       => Malka_CRM_Projects::es_publicacion( $item, self::es_rrss( $template_id ) ) ? 1 : 0,
				'pub_formato'     => isset( $item['pub_formato'] ) ? $item['pub_formato'] : '',
				'pub_tema'        => isset( $item['pub_tema'] ) ? $item['pub_tema'] : '',
				'pub_hashtags'    => isset( $item['pub_hashtags'] ) ? $item['pub_hashtags'] : '',
				'pub_voz'         => isset( $item['pub_voz'] ) ? $item['pub_voz'] : '',
				'pub_encuesta'    => isset( $item['pub_encuesta'] ) ? $item['pub_encuesta'] : '',
				'pub_prueba'      => isset( $item['pub_prueba'] ) ? $item['pub_prueba'] : '',
				'pub_enlace_reel' => isset( $item['pub_enlace_reel'] ) ? $item['pub_enlace_reel'] : '',
				'pub_portada'     => isset( $item['pub_portada'] ) ? $item['pub_portada'] : '',
				'pub_musica'      => isset( $item['pub_musica'] ) ? $item['pub_musica'] : '',
				'respuesta'       => isset( $item['respuesta'] ) ? $item['respuesta'] : '',
				'respuesta_preg'  => isset( $item['respuesta_preg'] ) ? $item['respuesta_preg'] : '',
				'pub_quien'       => isset( $item['pub_quien'] ) ? $item['pub_quien'] : '',
				'pub_nota'        => isset( $item['pub_nota'] ) ? $item['pub_nota'] : '',
				'number'       => $item['number'],
				'needs_approval' => ! empty( $item['needs_approval'] ) ? 1 : 0,
				'rounds'         => isset( $item['rounds'] ) ? (string) $item['rounds'] : '',
			);

			// Cada ítem avanza al siguiente día hábil.
			$cursor = Malka_CRM_Helpers::add_days( $end, 1 );
		}

		Malka_CRM_Helpers::save_tasks( $project_id, Malka_CRM_Helpers::recalculate_stages( $tasks ) );

		if ( class_exists( 'Malka_CRM_Approvals' ) ) {
			Malka_CRM_Approvals::track_completions( $project_id );
		}

		/**
		 * Se aplicó una plantilla a un proyecto.
		 *
		 * @param int $project_id  Proyecto.
		 * @param int $template_id Plantilla.
		 * @param int $count       Ítems creados.
		 */
		do_action( 'malka_crm_template_applied', $project_id, $template_id, count( $items ) );

		return count( $items );
	}

	/**
	 * Handler del botón "Aplicar plantilla".
	 */
	public function handle_apply() {

		if ( ! Malka_CRM_Helpers::current_user_is_manager() ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ), 403 );
		}

		$project_id  = isset( $_REQUEST['project_id'] ) ? absint( $_REQUEST['project_id'] ) : 0;
		$template_id = isset( $_REQUEST['template_id'] ) ? absint( $_REQUEST['template_id'] ) : 0;
		$start       = isset( $_REQUEST['start'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['start'] ) ) : '';
		$replace     = ! empty( $_REQUEST['replace'] );
		$origin      = isset( $_REQUEST['origin'] ) ? sanitize_key( wp_unslash( $_REQUEST['origin'] ) ) : '';

		check_admin_referer( 'malka_crm_apply_template_' . $project_id );

		$count = 0;

		if ( $project_id && $template_id ) {
			$count = self::apply( $template_id, $project_id, $start, $replace );
		}

		// Vuelve a la pantalla desde la que se aplicó.
		if ( 'edit' === $origin ) {
			$redirect = add_query_arg(
				array(
					'malka_crm_msg'  => 'template_applied',
					'malka_crm_sent' => $count,
				),
				Malka_CRM_Helpers::panel_project_url( $project_id )
			);
		} else {
			$redirect = add_query_arg(
				array(
					'malka_crm_msg'  => 'template_applied',
					'malka_crm_sent' => $count,
				),
				Malka_CRM_Helpers::panel_project_url( $project_id )
			);
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Duplica una plantilla con todos sus ítems.
	 */
	public function handle_duplicate() {

		if ( ! Malka_CRM_Helpers::current_user_is_manager() ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ), 403 );
		}

		$template_id = isset( $_GET['template_id'] ) ? absint( $_GET['template_id'] ) : 0;

		check_admin_referer( 'malka_crm_duplicate_template_' . $template_id );

		$source = get_post( $template_id );

		if ( ! $source || self::POST_TYPE !== $source->post_type ) {
			wp_die( esc_html__( 'Plantilla no encontrada.', 'malka-crm' ), 404 );
		}

		$new_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				/* translators: %s: nombre de la plantilla original */
				'post_title'  => sprintf( __( '%s (copia)', 'malka-crm' ), $source->post_title ),
				'post_status' => 'publish',
			),
			true
		);

		if ( is_wp_error( $new_id ) ) {
			wp_die( esc_html__( 'No se pudo duplicar.', 'malka-crm' ) );
		}

		update_post_meta( $new_id, self::META_ITEMS, self::get_items( $template_id ) );

		wp_safe_redirect(
			class_exists( 'Malka_CRM_Templates_Panel' )
				? Malka_CRM_Templates_Panel::edit_url( $new_id )
				: Malka_CRM_Dashboard::view_url( 'plantillas' )
		);
		exit;
	}

	/**
	 * Acción "Duplicar" en el listado de plantillas.
	 *
	 * @param array   $actions Acciones.
	 * @param WP_Post $post    Post.
	 * @return array
	 */
	public function row_actions( $actions, $post ) {

		if ( self::POST_TYPE !== $post->post_type ) {
			return $actions;
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=malka_crm_duplicate_template&template_id=' . $post->ID ),
			'malka_crm_duplicate_template_' . $post->ID
		);

		$actions['malka_duplicate'] = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Duplicar', 'malka-crm' ) );

		return $actions;
	}

	/**
	 * Columna con el número de ítems.
	 *
	 * @param array $columns Columnas.
	 * @return array
	 */
	public function columns( $columns ) {
		$columns['malka_items'] = __( 'Ítems', 'malka-crm' );
		return $columns;
	}

	/**
	 * Contenido de la columna.
	 *
	 * @param string $column  Columna.
	 * @param int    $post_id ID.
	 */
	public function column_content( $column, $post_id ) {
		if ( 'malka_items' === $column ) {
			echo esc_html( count( self::get_items( $post_id ) ) );
		}
	}

	/* ──────────────────── Semilla ──────────────────── */

	/**
	 * Crea la plantilla de Branding la primera vez que se activa el plugin.
	 */
	public static function seed_default_templates() {

		if ( get_option( 'malka_crm_templates_seeded' ) ) {
			return;
		}

		$existing = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		if ( ! empty( $existing ) ) {
			update_option( 'malka_crm_templates_seeded', 1, false );
			return;
		}

		$template_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_title'  => __( 'Plan Branding — The Branding Method', 'malka-crm' ),
				'post_status' => 'publish',
			),
			true
		);

		if ( is_wp_error( $template_id ) ) {
			return;
		}

		update_post_meta( $template_id, self::META_ITEMS, self::branding_items() );
		update_option( 'malka_crm_templates_seeded', 1, false );
	}

	/**
	 * Estructura del plan Branding.
	 *
	 * @return array
	 */
	public static function branding_items() {

		$s = function ( $number, $name ) {
			return array(
				'number'   => $number,
				'name'     => $name,
				'type'     => 'stage',
				'owner'    => 'studio',
				'due_days' => 0,
			);
		};

		$t = function ( $number, $name, $owner = 'studio', $days = 0 ) {
			return array(
				'number'   => $number,
				'name'     => $name,
				'type'     => 'task',
				'owner'    => $owner,
				'due_days' => $days,
			);
		};

		$m = function ( $name ) {
			return array(
				'number'   => '',
				'name'     => $name,
				'type'     => 'milestone',
				'owner'    => 'studio',
				'due_days' => 0,
			);
		};

		return array(
			$s( '0', 'Primeros pasos' ),
			$t( '0.1', 'Primer abono', 'client', 2 ),
			$t( '0.2', 'Envío formulario al cliente', 'studio', 0 ),
			$t( '0.3', 'Recepción del formulario', 'client', 2 ),
			$t( '0.4', 'Análisis de datos', 'studio', 4 ),
			$t( '0.4.1', 'Formulario', 'studio', 0 ),
			$t( '0.4.2', 'Estadísticas WooCommerce', 'studio', 0 ),
			$t( '0.4.3', 'Estadísticas Meta (Facebook e Instagram)', 'studio', 0 ),
			$t( '0.4.4', 'Estadísticas de visitas Jetpack', 'studio', 0 ),
			$t( '0.4.5', 'Estadísticas de ventas totales', 'studio', 0 ),

			$s( '1', 'Análisis del consumidor' ),
			$t( '1.1', 'Herramienta 1' ),
			$t( '1.2', 'Herramienta 2' ),
			$t( '1.3', 'Herramienta 3' ),
			$t( '1.4', 'Herramienta 4' ),
			$t( '1.5', 'Herramienta 5' ),
			$t( '1.6', 'Añadir al informe' ),

			$s( '2', 'Fortalezas competitivas' ),
			$t( '2.1', 'Herramienta 1' ),
			$t( '2.2', 'Herramienta 2' ),
			$t( '2.3', 'Herramienta 3' ),
			$t( '2.4', 'Herramienta 4' ),
			$t( '2.5', 'Herramienta 5' ),
			$t( '2.6', 'Añadir al informe' ),

			$s( '3', 'Plataforma y ADN de la marca' ),
			$t( '3.1', 'Herramienta 1' ),
			$t( '3.2', 'Herramienta 2' ),
			$t( '3.3', 'Herramienta 3' ),
			$t( '3.4', 'Herramienta 4' ),
			$t( '3.5', 'Herramienta 5' ),
			$t( '3.6', 'Añadir al informe' ),

			$m( 'Reunión de informe' ),

			$s( '4', 'Assets e identidad de la marca' ),
			$t( '4.1', 'Herramienta 1 — Símbolo de la marca' ),
			$t( '4.2', 'Herramienta 2 — Carisma de la marca' ),
			$t( '4.3', 'Herramienta 3 — Tono de voz, naming y slogan' ),
			$t( '4.4', 'Herramienta 4' ),
			$t( '4.5', 'Herramienta 5' ),
			$t( '4.6', 'Añadir al informe' ),

			$m( 'Reunión de propuesta de identidad' ),

			$s( '5', 'Activación de la marca' ),
			$t( '5.1', 'Herramienta 1' ),
			$t( '5.2', 'Herramienta 2' ),
			$t( '5.3', 'Herramienta 3' ),
			$t( '5.4', 'Herramienta 4' ),
			$t( '5.5', 'Herramienta 5' ),
			$t( '5.6', 'Añadir al informe' ),
		);
	}
}
