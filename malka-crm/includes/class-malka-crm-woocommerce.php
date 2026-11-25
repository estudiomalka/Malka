<?php
/**
 * Módulo 3: WooCommerce (endpoints de Mi Cuenta + venta que genera proyecto).
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

class Malka_CRM_WooCommerce {

	const EP_INVOICES = 'facturas';
	const EP_PROJECTS = 'proyectos';
	const EP_QUOTES   = 'cotizaciones';

	public function __construct() {
		add_action( 'init', array( __CLASS__, 'register_endpoints' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ), 0 );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'account_menu_items' ) );
		add_action( 'woocommerce_account_' . self::EP_INVOICES . '_endpoint', array( $this, 'render_invoices' ) );
		add_action( 'woocommerce_account_' . self::EP_PROJECTS . '_endpoint', array( $this, 'render_projects' ) );
		add_action( 'woocommerce_account_' . self::EP_QUOTES . '_endpoint', array( $this, 'render_quotes' ) );

		// Producto: casilla de vinculación.
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'product_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product_field' ) );

		// Pedido completado -> proyecto.
		add_action( 'woocommerce_order_status_completed', array( $this, 'maybe_create_projects' ), 10, 1 );

		// Shortcodes alternativos (si no se usa Mi Cuenta).
		add_shortcode( 'malka_crm_invoices', array( $this, 'shortcode_invoices' ) );
		add_shortcode( 'malka_crm_projects', array( $this, 'shortcode_projects' ) );
		add_shortcode( 'malka_crm_quotes', array( $this, 'shortcode_quotes' ) );

		// Enlace directo a facturas desde el detalle del pedido.
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'order_invoice_links' ) );
	}

	/**
	 * Registra los endpoints de Mi Cuenta.
	 */
	public static function register_endpoints() {
		add_rewrite_endpoint( self::EP_INVOICES, EP_ROOT | EP_PAGES );
		add_rewrite_endpoint( self::EP_PROJECTS, EP_ROOT | EP_PAGES );
		add_rewrite_endpoint( self::EP_QUOTES, EP_ROOT | EP_PAGES );
	}

	/**
	 * Query vars.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public function add_query_vars( $vars ) {
		$vars[] = self::EP_INVOICES;
		$vars[] = self::EP_PROJECTS;
		$vars[] = self::EP_QUOTES;
		return $vars;
	}

	/**
	 * Añade las pestañas al menú de Mi Cuenta.
	 *
	 * @param array $items Items.
	 * @return array
	 */
	public function account_menu_items( $items ) {
		$new = array();

		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'orders' === $key ) {
				$new[ self::EP_PROJECTS ] = __( 'Proyectos', 'malka-crm' );
				if ( Malka_CRM_Helpers::get_setting( 'quotes_enabled' ) ) {
					$new[ self::EP_QUOTES ] = __( 'Cotizaciones', 'malka-crm' );
				}
				$new[ self::EP_INVOICES ] = __( 'Tus Facturas', 'malka-crm' );
			}
		}

		if ( ! isset( $new[ self::EP_PROJECTS ] ) ) {
			$new[ self::EP_PROJECTS ] = __( 'Proyectos', 'malka-crm' );
			if ( Malka_CRM_Helpers::get_setting( 'quotes_enabled' ) ) {
				$new[ self::EP_QUOTES ] = __( 'Cotizaciones', 'malka-crm' );
			}
			$new[ self::EP_INVOICES ] = __( 'Tus Facturas', 'malka-crm' );
		}

		// Mantener "Cerrar sesión" al final.
		if ( isset( $new['customer-logout'] ) ) {
			$logout = $new['customer-logout'];
			unset( $new['customer-logout'] );
			$new['customer-logout'] = $logout;
		}

		return $new;
	}

	/**
	 * Pestaña "Tus Facturas".
	 */
	public function render_invoices() {
		try {
			echo $this->get_invoices_html( get_current_user_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} catch ( Throwable $e ) {
			echo '<div class="malka-crm-empty"><p>' . esc_html__( 'No pudimos cargar esta sección. Ya avisamos al estudio.', 'malka-crm' ) . '</p></div>';
			update_option( 'malka_crm_last_error', array( 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(), 'context' => 'render_invoices', 'time' => current_time( 'mysql' ) ), false );
		}
	}

	/**
	 * Pestaña "Proyectos".
	 */
	public function render_projects() {
		try {
			echo malka_crm()->gantt->get_projects_html( get_current_user_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} catch ( Throwable $e ) {
			echo '<div class="malka-crm-empty"><p>' . esc_html__( 'No pudimos cargar esta sección. Ya avisamos al estudio.', 'malka-crm' ) . '</p></div>';
			update_option( 'malka_crm_last_error', array( 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(), 'context' => 'render_projects', 'time' => current_time( 'mysql' ) ), false );
		}
	}

	/**
	 * Pestaña "Cotizaciones".
	 */
	public function render_quotes() {
		try {
			echo Malka_CRM_Quotes::render_client_quotes( get_current_user_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} catch ( Throwable $e ) {
			echo '<div class="malka-crm-empty"><p>' . esc_html__( 'No pudimos cargar esta sección. Ya avisamos al estudio.', 'malka-crm' ) . '</p></div>';
			update_option( 'malka_crm_last_error', array( 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(), 'context' => 'render_quotes', 'time' => current_time( 'mysql' ) ), false );
		}
	}

	/**
	 * Shortcode de cotizaciones.
	 *
	 * @return string
	 */
	public function shortcode_quotes() {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Inicia sesión para ver tus cotizaciones.', 'malka-crm' ) . '</p>';
		}
		return Malka_CRM_Quotes::render_client_quotes( get_current_user_id() );
	}

	/**
	 * Shortcode de facturas.
	 *
	 * @return string
	 */
	public function shortcode_invoices() {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Inicia sesión para ver tus facturas.', 'malka-crm' ) . '</p>';
		}
		return $this->get_invoices_html( get_current_user_id() );
	}

	/**
	 * Shortcode de proyectos.
	 *
	 * @return string
	 */
	public function shortcode_projects() {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Inicia sesión para ver tus proyectos.', 'malka-crm' ) . '</p>';
		}
		return malka_crm()->gantt->get_projects_html( get_current_user_id() );
	}

	/**
	 * HTML del listado de facturas del cliente.
	 *
	 * @param int $user_id ID.
	 * @return string
	 */
	public function get_invoices_html( $user_id ) {

		wp_enqueue_style( 'malka-crm-frontend' );

		$invoices = Malka_CRM_Invoices::get_client_invoices( $user_id );

		ob_start();

		if ( empty( $invoices ) ) {
			echo '<div class="malka-crm-empty"><p>' . esc_html__( 'Todavía no hay facturas disponibles.', 'malka-crm' ) . '</p></div>';
			return ob_get_clean();
		}
		?>
		<table class="malka-crm-table woocommerce-orders-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Documento', 'malka-crm' ); ?></th>
					<th><?php esc_html_e( 'Fecha', 'malka-crm' ); ?></th>
					<th><?php esc_html_e( 'Pedido', 'malka-crm' ); ?></th>
					<th><?php esc_html_e( 'Acciones', 'malka-crm' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $invoices as $invoice ) : ?>
				<?php
				$order_id = (int) get_post_meta( $invoice->ID, '_malka_pm_order_id', true );
				?>
				<tr>
					<td data-title="<?php esc_attr_e( 'Documento', 'malka-crm' ); ?>"><?php echo esc_html( $invoice->post_title ); ?></td>
					<td data-title="<?php esc_attr_e( 'Fecha', 'malka-crm' ); ?>"><?php echo esc_html( get_the_date( 'd/m/Y', $invoice ) ); ?></td>
					<td data-title="<?php esc_attr_e( 'Pedido', 'malka-crm' ); ?>">
						<?php echo $order_id ? '#' . esc_html( $order_id ) : '&mdash;'; ?>
					</td>
					<td data-title="<?php esc_attr_e( 'Acciones', 'malka-crm' ); ?>">
						<a class="malka-crm-btn" target="_blank" rel="noopener noreferrer"
							href="<?php echo esc_url( Malka_CRM_Invoices::get_secure_url( $invoice->ID, 'view' ) ); ?>">
							<?php esc_html_e( 'Ver', 'malka-crm' ); ?>
						</a>
						<a class="malka-crm-btn malka-crm-btn--ghost"
							href="<?php echo esc_url( Malka_CRM_Invoices::get_secure_url( $invoice->ID, 'download' ) ); ?>">
							<?php esc_html_e( 'Descargar', 'malka-crm' ); ?>
						</a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		return ob_get_clean();
	}

	/**
	 * Enlaces a facturas en el detalle del pedido.
	 *
	 * @param WC_Order $order Pedido.
	 */
	public function order_invoice_links( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$invoices = get_posts(
			array(
				'post_type'      => 'malka_invoice',
				'post_status'    => array( 'private', 'publish' ),
				'posts_per_page' => 20,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_malka_pm_order_id',
						'value' => $order->get_id(),
					),
				),
			)
		);

		if ( empty( $invoices ) || (int) $order->get_customer_id() !== get_current_user_id() ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Facturas de este pedido', 'malka-crm' ) . '</h2><ul class="malka-crm-order-invoices">';
		foreach ( $invoices as $invoice ) {
			printf(
				'<li><a target="_blank" rel="noopener noreferrer" href="%1$s">%2$s</a></li>',
				esc_url( Malka_CRM_Invoices::get_secure_url( $invoice->ID, 'view' ) ),
				esc_html( $invoice->post_title )
			);
		}
		echo '</ul>';
	}

	/**
	 * Casilla en la ficha de producto.
	 */
	public function product_field() {
		woocommerce_wp_checkbox(
			array(
				'id'          => '_malka_pm_create_project',
				'label'       => __( 'Crear proyecto Malka', 'malka-crm' ),
				'description' => __( 'Al completar el pedido se generará automáticamente un proyecto para el cliente.', 'malka-crm' ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => '_malka_pm_rounds',
				'label'             => __( 'Rondas de revisión incluidas', 'malka-crm' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'description'       => __( '0 = ilimitadas. Define el techo de revisiones del plan que vende este producto.', 'malka-crm' ),
				'desc_tip'          => true,
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => '_malka_pm_project_duration',
				'label'             => __( 'Duración estimada (días)', 'malka-crm' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '1',
					'step' => '1',
				),
				'description'       => __( 'Se usa para prellenar las fechas de las tareas base.', 'malka-crm' ),
				'desc_tip'          => true,
			)
		);
	}

	/**
	 * Guarda los campos del producto.
	 *
	 * @param WC_Product $product Producto.
	 */
	public function save_product_field( $product ) {
		// WooCommerce ya verifica nonce y capacidades en el guardado del producto.
		$enabled = isset( $_POST['_malka_pm_create_project'] ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification
		$product->update_meta_data( '_malka_pm_create_project', $enabled );

		$duration = isset( $_POST['_malka_pm_project_duration'] ) ? absint( $_POST['_malka_pm_project_duration'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$product->update_meta_data( '_malka_pm_project_duration', $duration );

		if ( isset( $_POST['_malka_pm_rounds'] ) && '' !== $_POST['_malka_pm_rounds'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			$product->update_meta_data( '_malka_pm_rounds', absint( $_POST['_malka_pm_rounds'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
	}

	/**
	 * Genera proyectos al completar el pedido.
	 *
	 * @param int $order_id ID del pedido.
	 */
	public function maybe_create_projects( $order_id ) {

		if ( ! Malka_CRM_Helpers::get_setting( 'auto_project' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( 'yes' === $order->get_meta( '_malka_pm_projects_created' ) ) {
			return;
		}

		$customer_id = (int) $order->get_customer_id();
		if ( ! $customer_id ) {
			return;
		}

		$created = 0;

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			$parent_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
			$flag      = $product->get_meta( '_malka_pm_create_project' );
			if ( 'yes' !== $flag ) {
				$flag = get_post_meta( $parent_id, '_malka_pm_create_project', true );
			}

			if ( 'yes' !== $flag ) {
				continue;
			}

			$duration = (int) $product->get_meta( '_malka_pm_project_duration' );
			if ( ! $duration ) {
				$duration = (int) Malka_CRM_Helpers::get_setting( 'project_duration', 30 );
			}

			$rounds = $product->get_meta( '_malka_pm_rounds' );
			if ( '' === $rounds || null === $rounds ) {
				$rounds = get_post_meta( $parent_id, '_malka_pm_rounds', true );
			}
			if ( '' === $rounds || null === $rounds ) {
				$rounds = Malka_CRM_Helpers::get_setting( 'round_limit_default', 2 );
			}

			$this->create_project( $customer_id, $order, $item->get_name(), $duration, (int) $rounds );
			$created++;
		}

		if ( $created ) {
			$order->update_meta_data( '_malka_pm_projects_created', 'yes' );
			$order->add_order_note(
				sprintf(
					/* translators: %d: número de proyectos */
					__( 'Malka CRM: se generaron %d proyecto(s) para el cliente.', 'malka-crm' ),
					$created
				)
			);
			$order->save();
		}
	}

	/**
	 * Crea un proyecto con tareas base.
	 *
	 * @param int      $customer_id Cliente.
	 * @param WC_Order $order       Pedido.
	 * @param string   $name        Nombre del producto.
	 * @param int      $duration    Días.
	 * @param int|null $rounds      Rondas incluidas.
	 * @return int
	 */
	public function create_project( $customer_id, $order, $name, $duration = 30, $rounds = null ) {

		$title = sprintf(
			/* translators: 1: producto, 2: nº de pedido */
			__( '%1$s — Pedido #%2$s', 'malka-crm' ),
			$name,
			$order ? $order->get_order_number() : '—'
		);

		$project_id = wp_insert_post(
			array(
				'post_type'   => 'malka_project',
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_author' => get_current_user_id() ? get_current_user_id() : 1,
			),
			true
		);

		if ( is_wp_error( $project_id ) ) {
			return 0;
		}

		update_post_meta( $project_id, '_malka_pm_client_id', (int) $customer_id );
		update_post_meta( $project_id, '_malka_pm_order_id', $order ? (int) $order->get_id() : 0 );
		update_post_meta( $project_id, '_malka_pm_status', 'briefing' );
		update_post_meta(
			$project_id,
			Malka_CRM_Approvals::META_LIMIT,
			null === $rounds ? (int) Malka_CRM_Helpers::get_setting( 'round_limit_default', 2 ) : max( 0, (int) $rounds )
		);
		update_post_meta( $project_id, '_malka_pm_tasks', $this->default_tasks( $duration ) );

		do_action( 'malka_crm_project_created', $project_id, $customer_id, $order );

		return (int) $project_id;
	}

	/**
	 * Plantilla de tareas base (metodología Malka).
	 *
	 * @param int $duration Días totales.
	 * @return array
	 */
	private function default_tasks( $duration ) {

		$blocks = apply_filters(
			'malka_crm_default_task_blocks',
			array(
				array( 'Kickoff y briefing estratégico', 0.10 ),
				array( 'Investigación y diagnóstico', 0.20 ),
				array( 'Estrategia de marca', 0.25 ),
				array( 'Diseño y ejecución', 0.30 ),
				array( 'Entrega final', 0.15 ),
			)
		);

		$tasks   = array();
		$cursor  = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$counter = 0;
		$prev_id = '';

		foreach ( $blocks as $block ) {
			$counter++;
			$days  = max( 1, (int) round( $duration * (float) $block[1] ) );
			$start = gmdate( 'Y-m-d', $cursor );
			$end   = gmdate( 'Y-m-d', $cursor + ( $days * DAY_IN_SECONDS ) );
			$id    = 'fase-' . $counter;

			$tasks[] = array(
				'id'           => $id,
				'name'         => $block[0],
				'start'        => $start,
				'end'          => $end,
				'progress'     => 0,
				'type'         => 'task',
				'dependencies' => $prev_id,
				'notes'        => '',
			);

			$prev_id = $id;
			$cursor  = $cursor + ( ( $days + 1 ) * DAY_IN_SECONDS );
		}

		return $tasks;
	}
}
