<?php
/**
 * Desinstalación.
 *
 * Por defecto solo elimina los ajustes. Para borrar TODO (proyectos, facturas
 * y archivos HTML), define en wp-config.php:
 *   define( 'MALKA_CRM_REMOVE_ALL_DATA', true );
 *
 * @package Malka_CRM
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/*
 * Por defecto no se borra NADA.
 *
 * Antes esto borraba los ajustes apenas desinstalabas. Con varias copias del
 * plugin instaladas —que es justo lo que pasa cuando el ZIP no trae carpeta
 * propia— borrar la copia sobrante se llevaba los ajustes de la copia buena.
 * Un borrado silencioso no vale lo que cuesta: si de verdad quieres limpiar,
 * se pide a mano en wp-config.php.
 */
if ( ! defined( 'MALKA_CRM_REMOVE_ALL_DATA' ) || ! MALKA_CRM_REMOVE_ALL_DATA ) {
	return;
}

delete_option( 'malka_pm_settings' );

$malka_crm_posts = get_posts(
	array(
		'post_type'      => array( 'malka_project', 'malka_invoice' ),
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);

foreach ( $malka_crm_posts as $malka_crm_post_id ) {
	wp_delete_post( $malka_crm_post_id, true );
}

$malka_crm_uploads = wp_upload_dir();
$malka_crm_dir     = trailingslashit( $malka_crm_uploads['basedir'] ) . 'malka-crm-invoices';

if ( is_dir( $malka_crm_dir ) ) {
	$malka_crm_iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $malka_crm_dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $malka_crm_iterator as $malka_crm_item ) {
		if ( $malka_crm_item->isDir() ) {
			@rmdir( $malka_crm_item->getRealPath() ); // phpcs:ignore
		} else {
			@unlink( $malka_crm_item->getRealPath() ); // phpcs:ignore
		}
	}

	@rmdir( $malka_crm_dir ); // phpcs:ignore
}
