<?php
/**
 * Módulo 7: Venta de rondas de revisión adicionales.
 *
 * Flujo completo:
 *   1. El cliente agota sus rondas y ve el botón "Comprar ronda adicional".
 *   2. El botón añade al carrito el producto configurado, con el proyecto y la
 *      etapa adjuntos como datos del artículo.
 *   3. Al pagarse el pedido (processing o completed) se acredita la ronda al
 *      proyecto y el botón "Solicitar cambios" vuelve a aparecer solo.
 *
 * Las rondas compradas se guardan en `_malka_pm_extra_rounds` (clave => cantidad),
 * separadas del cupo del plan, para que el historial siga siendo auditable.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

class Malka_CRM_Extra_Rounds {

	const META_EXTRA = '_malka_pm_extra_rounds';

	public function __construct() {
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 3 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_line_item' ), 10, 4 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'grant_rounds' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'grant_rounds' ), 20, 1 );
	}

	/* ──────────────────── Cupo comprado ──────────────────── */

	/**
	 * Rondas adicionales acreditadas a una clave.
	 *
	 * @param int    $project_id ID.
	 * @param string $key        Clave.
	 * @return int
	 */
	public static function get_extra( $project_id, $key ) {

		$all = get_post_meta( (int) $project_id, self::META_EXTRA, true );
		$all = is_array( $all ) ? $all : array();
		$key = sanitize_key( $key );

		return isset( $all[ $key ] ) ? (int) $all[ $key ] : 0;
	}

	/**
	 * Acredita rondas adicionales.
	 *
	 * @param int    $project_id ID.
	 * @param string $key        Clave.
	 * @param int    $quantity   Cantidad.
	 * @return int Total acumulado.
	 */
	public static function add_extra( $project_id, $key, $quantity = 1 ) {

		$all = get_post_meta( (int) $project_id, self::META_EXTRA, true );
		$all = is_array( $all ) ? $all : array();
		$key = sanitize_key( $key );

		$all[ $key ] = max( 0, ( isset( $all[ $key ] ) ? (int) $all[ $key ] : 0 ) + (int) $quantity );

		update_post_meta( (int) $project_id, self::META_EXTRA, $all );

		return (int) $all[ $key ];
	}

	/* ──────────────────── Producto configurado ──────────────────── */

	/**
	 * ¿Está operativa la venta de rondas?
	 *
	 * @return bool
	 */
	public static function is_active() {

		if ( ! Malka_CRM_Helpers::get_setting( 'extra_rounds_enabled' ) || ! function_exists( 'wc_get_product' ) ) {
			return false;
		}

		$product = self::get_product();

		return $product && $product->is_purchasable();
	}

	/**
	 * Producto configurado para la ronda extra.
	 *
	 * @return WC_Product|false
	 */
	public static function get_product() {

		$product_id = (int) Malka_CRM_Helpers::get_setting( 'extra_round_product', 0 );

		if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) {
			return false;
		}

		return wc_get_product( $product_id );
	}

	/**
	 * URL de compra: añade al carrito y lleva al checkout.
	 *
	 * @param int    $project_id ID.
	 * @param string $key        Clave.
	 * @return string Cadena vacía si no está configurado.
	 */
	public static function get_purchase_url( $project_id, $key ) {

		if ( ! self::is_active() ) {
			return '';
		}

		$product = self::get_product();

		$url = add_query_arg(
			array(
				'add-to-cart'       => $product->get_id(),
				'malka_crm_project' => (int) $project_id,
				'malka_crm_key'     => rawurlencode( $key ),
			),
			wc_get_checkout_url()
		);

		return wp_nonce_url( $url, 'malka_crm_buy_round_' . $project_id, 'malka_crm_buy_nonce' );
	}

	/**
	 * Precio formateado para el botón.
	 *
	 * @return string
	 */
	public static function get_price_html() {
		$product = self::get_product();
		return $product ? wp_strip_all_tags( $product->get_price_html() ) : '';
	}

	/* ──────────────────── Carrito y pedido ──────────────────── */

	/**
	 * Impide comprar una ronda para un proyecto ajeno.
	 *
	 * @param bool $passed     Validación previa.
	 * @param int  $product_id Producto.
	 * @param int  $quantity   Cantidad.
	 * @return bool
	 */
	public function validate_add_to_cart( $passed, $product_id, $quantity ) {

		if ( ! isset( $_GET['malka_crm_project'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $passed;
		}

		$project_id = absint( $_GET['malka_crm_project'] ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! is_user_logged_in() ) {
			wc_add_notice( __( 'Inicia sesión para comprar una ronda adicional.', 'malka-crm' ), 'error' );
			return false;
		}

		$nonce = isset( $_GET['malka_crm_buy_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['malka_crm_buy_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'malka_crm_buy_round_' . $project_id ) ) {
			wc_add_notice( __( 'El enlace de compra caducó. Vuelve a tu proyecto e inténtalo de nuevo.', 'malka-crm' ), 'error' );
			return false;
		}

		if ( ! Malka_CRM_Helpers::user_owns_project( $project_id, get_current_user_id() ) ) {
			wc_add_notice( __( 'Este proyecto no está asociado a tu cuenta.', 'malka-crm' ), 'error' );
			return false;
		}

		return $passed;
	}

	/**
	 * Adjunta proyecto y etapa al artículo del carrito.
	 *
	 * @param array $cart_item_data Datos.
	 * @param int   $product_id     Producto.
	 * @param int   $variation_id   Variación.
	 * @return array
	 */
	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {

		if ( ! isset( $_GET['malka_crm_project'], $_GET['malka_crm_key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $cart_item_data;
		}

		$project_id = absint( $_GET['malka_crm_project'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$key        = sanitize_key( wp_unslash( $_GET['malka_crm_key'] ) ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! $project_id || ! $key ) {
			return $cart_item_data;
		}

		$cart_item_data['malka_crm_round'] = array(
			'project_id' => $project_id,
			'key'        => $key,
			'unique'     => wp_generate_password( 8, false, false ),
		);

		return $cart_item_data;
	}

	/**
	 * Muestra el contexto en carrito y checkout.
	 *
	 * @param array $item_data Datos visibles.
	 * @param array $cart_item Artículo.
	 * @return array
	 */
	public function display_cart_item_data( $item_data, $cart_item ) {

		if ( empty( $cart_item['malka_crm_round'] ) ) {
			return $item_data;
		}

		$project_id = (int) $cart_item['malka_crm_round']['project_id'];
		$key        = (string) $cart_item['malka_crm_round']['key'];

		$item_data[] = array(
			'key'   => __( 'Proyecto', 'malka-crm' ),
			'value' => get_the_title( $project_id ),
		);

		$item_data[] = array(
			'key'   => __( 'Etapa', 'malka-crm' ),
			'value' => Malka_CRM_Approvals::key_label( $project_id, $key ),
		);

		return $item_data;
	}

	/**
	 * Traslada los datos al pedido.
	 *
	 * @param WC_Order_Item_Product $item          Artículo del pedido.
	 * @param string                $cart_item_key Clave del carrito.
	 * @param array                 $values        Valores.
	 * @param WC_Order              $order         Pedido.
	 */
	public function save_order_line_item( $item, $cart_item_key, $values, $order ) {

		if ( empty( $values['malka_crm_round'] ) ) {
			return;
		}

		$project_id = (int) $values['malka_crm_round']['project_id'];
		$key        = (string) $values['malka_crm_round']['key'];

		$item->add_meta_data( '_malka_crm_project', $project_id, true );
		$item->add_meta_data( '_malka_crm_key', $key, true );

		// Metadatos visibles para el cliente y para ti en el pedido.
		$item->add_meta_data( __( 'Proyecto', 'malka-crm' ), get_the_title( $project_id ), true );
		$item->add_meta_data( __( 'Etapa', 'malka-crm' ), Malka_CRM_Approvals::key_label( $project_id, $key ), true );
	}

	/**
	 * Acredita las rondas cuando el pedido queda pagado.
	 *
	 * @param int $order_id ID del pedido.
	 */
	public function grant_rounds( $order_id ) {

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		foreach ( $order->get_items() as $item_id => $item ) {

			$project_id = (int) $item->get_meta( '_malka_crm_project', true );
			$key        = (string) $item->get_meta( '_malka_crm_key', true );

			if ( ! $project_id || ! $key ) {
				continue;
			}

			// Idempotencia: nunca acreditar dos veces el mismo artículo.
			if ( 'yes' === $item->get_meta( '_malka_crm_granted', true ) ) {
				continue;
			}

			// El comprador debe seguir siendo el dueño del proyecto.
			if ( ! Malka_CRM_Helpers::user_owns_project( $project_id, (int) $order->get_customer_id() ) ) {
				$order->add_order_note(
					sprintf(
						/* translators: %d: ID del proyecto */
						__( 'Malka CRM: no se acreditó la ronda; el proyecto #%d no pertenece al comprador.', 'malka-crm' ),
						$project_id
					)
				);
				continue;
			}

			$quantity = max( 1, (int) $item->get_quantity() );
			$total    = self::add_extra( $project_id, $key, $quantity );

			$item->add_meta_data( '_malka_crm_granted', 'yes', true );
			$item->save();

			$order->add_order_note(
				sprintf(
					/* translators: 1: cantidad, 2: etapa, 3: total acumulado */
					__( 'Malka CRM: %1$d ronda(s) adicional(es) acreditada(s) en "%2$s". Total comprado: %3$d.', 'malka-crm' ),
					$quantity,
					Malka_CRM_Approvals::key_label( $project_id, $key ),
					$total
				)
			);

			// La etapa vuelve a estar abierta a revisión.
			Malka_CRM_Approvals::set_approval( $project_id, $key, 'pending', '', (int) $order->get_customer_id() );

			/**
			 * Se acreditó una ronda comprada.
			 *
			 * @param int      $project_id ID del proyecto.
			 * @param string   $key        Clave.
			 * @param int      $quantity   Cantidad acreditada.
			 * @param WC_Order $order      Pedido.
			 */
			do_action( 'malka_crm_extra_round_granted', $project_id, $key, $quantity, $order );
		}
	}
}
