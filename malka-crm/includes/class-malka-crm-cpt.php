<?php
/**
 * Custom Post Types: proyectos y facturas.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

class Malka_CRM_CPT {

	public function __construct() {
		add_action( 'init', array( $this, 'register_post_types' ) );
	}

	/**
	 * Registra los CPT del plugin.
	 */
	public function register_post_types() {

		register_post_type(
			'malka_project',
			array(
				'labels'              => array(
					'name'               => __( 'Proyectos', 'malka-crm' ),
					'singular_name'      => __( 'Proyecto', 'malka-crm' ),
					'add_new'            => __( 'Añadir proyecto', 'malka-crm' ),
					'add_new_item'       => __( 'Añadir nuevo proyecto', 'malka-crm' ),
					'edit_item'          => __( 'Editar proyecto', 'malka-crm' ),
					'new_item'           => __( 'Nuevo proyecto', 'malka-crm' ),
					'view_item'          => __( 'Ver proyecto', 'malka-crm' ),
					'search_items'       => __( 'Buscar proyectos', 'malka-crm' ),
					'not_found'          => __( 'No hay proyectos', 'malka-crm' ),
					'menu_name'          => __( 'Proyectos', 'malka-crm' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => false, // Se cuelga del menú Malka CRM.
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'has_archive'         => false,
				'hierarchical'        => false,
				'menu_icon'           => 'dashicons-chart-gantt',
				'supports'            => array( 'title', 'editor' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);

		register_post_type(
			'malka_invoice',
			array(
				'labels'              => array(
					'name'          => __( 'Facturas', 'malka-crm' ),
					'singular_name' => __( 'Factura', 'malka-crm' ),
					'menu_name'     => __( 'Facturas', 'malka-crm' ),
				),
				'public'              => false,
				'show_ui'             => false,
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

		$this->register_meta();
	}

	/**
	 * Registra los metadatos con sus callbacks de saneamiento.
	 */
	private function register_meta() {

		register_post_meta(
			'malka_project',
			'_malka_pm_client_id',
			array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'auth_callback'     => array( 'Malka_CRM_Helpers', 'current_user_is_manager' ),
			)
		);

		register_post_meta(
			'malka_project',
			'_malka_pm_order_id',
			array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'auth_callback'     => array( 'Malka_CRM_Helpers', 'current_user_is_manager' ),
			)
		);

		register_post_meta(
			'malka_project',
			'_malka_pm_status',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'sanitize_key',
				'auth_callback'     => array( 'Malka_CRM_Helpers', 'current_user_is_manager' ),
			)
		);
	}
}
