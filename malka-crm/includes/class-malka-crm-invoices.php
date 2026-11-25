<?php
/**
 * Módulo 1: Facturas HTML.
 *
 * Estrategia de seguridad:
 * 1. Los .html NUNCA se registran como MIME permitido a nivel global de WordPress.
 * 2. Se guardan fuera del alcance directo del navegador (carpeta protegida por .htaccess / web.config).
 * 3. Se sirven mediante un proxy PHP que valida login, propiedad y nonce.
 * 4. Se entregan con cabecera CSP `sandbox` para neutralizar JS malicioso dentro del HTML.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

class Malka_CRM_Invoices {

	const DIR_NAME = 'malka-crm-invoices';

	public function __construct() {
		add_action( 'admin_post_malka_pm_upload_invoice', array( $this, 'handle_upload' ) );
		add_action( 'admin_post_malka_pm_delete_invoice', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_malka_pm_view_invoice', array( $this, 'serve_invoice' ) );
		add_action( 'before_delete_post', array( $this, 'delete_attached_file' ) );
	}

	/**
	 * Ruta base de almacenamiento.
	 *
	 * @return array {basedir, baseurl}
	 */
	public static function storage_path() {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . self::DIR_NAME;
	}

	/**
	 * Crea la carpeta y la blinda ante accesos directos.
	 */
	public static function protect_storage_directory() {
		$dir = self::storage_path();

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules  = "# Malka CRM - acceso directo denegado\n";
			$rules .= "<IfModule mod_authz_core.c>\n Require all denied\n</IfModule>\n";
			$rules .= "<IfModule !mod_authz_core.c>\n Order deny,allow\n Deny from all\n</IfModule>\n";
			file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php // Silence is golden." ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$webconfig = trailingslashit( $dir ) . 'web.config';
		if ( ! file_exists( $webconfig ) ) {
			$xml = '<configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>';
			file_put_contents( $webconfig, $xml ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * Procesa la subida desde WP-Admin.
	 */
	public function handle_upload() {

		if ( ! Malka_CRM_Helpers::current_user_is_manager() ) {
			wp_die( esc_html__( 'No tienes permisos para subir facturas.', 'malka-crm' ), 403 );
		}

		check_admin_referer( 'malka_crm_upload_invoice' );

		$redirect = admin_url( 'admin.php?page=malka-crm-invoices' );

		$user_id  = isset( $_POST['malka_crm_user_id'] ) ? absint( $_POST['malka_crm_user_id'] ) : 0;
		$order_id = isset( $_POST['malka_crm_order_id'] ) ? absint( $_POST['malka_crm_order_id'] ) : 0;
		$title    = isset( $_POST['malka_crm_invoice_title'] ) ? sanitize_text_field( wp_unslash( $_POST['malka_crm_invoice_title'] ) ) : '';
		$project  = isset( $_POST['malka_crm_project_id'] ) ? absint( $_POST['malka_crm_project_id'] ) : 0;

		// Si se vincula un proyecto y no se eligió cliente, hereda el del proyecto.
		if ( $project && ! $user_id ) {
			$user_id = (int) get_post_meta( $project, '_malka_pm_client_id', true );
		}

		if ( ! $user_id && $order_id && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$user_id = (int) $order->get_customer_id();
			}
		}

		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			wp_safe_redirect( add_query_arg( 'malka_crm_msg', 'no_user', $redirect ) );
			exit;
		}

		if ( empty( $_FILES['malka_crm_invoice_file']['name'] ) ) {
			wp_safe_redirect( add_query_arg( 'malka_crm_msg', 'no_file', $redirect ) );
			exit;
		}

		$stored = self::guardar_archivo( $_FILES['malka_crm_invoice_file'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( is_wp_error( $stored ) ) {
			wp_safe_redirect( add_query_arg( 'malka_crm_msg', rawurlencode( $stored->get_error_code() ), $redirect ) );
			exit;
		}

		if ( '' === $title ) {
			/* translators: %s: fecha */
			$title = sprintf( __( 'Factura %s', 'malka-crm' ), date_i18n( 'd/m/Y' ) );
		}

		$invoice_id = wp_insert_post(
			array(
				'post_type'   => 'malka_invoice',
				'post_title'  => $title,
				'post_status' => 'private',
				'post_author' => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $invoice_id ) ) {
			@unlink( trailingslashit( self::storage_path() ) . $stored['relative'] ); // phpcs:ignore
			wp_safe_redirect( add_query_arg( 'malka_crm_msg', 'db_error', $redirect ) );
			exit;
		}

		update_post_meta( $invoice_id, '_malka_pm_client_id', $user_id );
		update_post_meta( $invoice_id, '_malka_pm_order_id', $order_id );
		update_post_meta( $invoice_id, '_malka_pm_project_id', $project );
		update_post_meta( $invoice_id, '_malka_pm_file', $stored['relative'] );
		update_post_meta( $invoice_id, '_malka_pm_original_name', $stored['original'] );

		do_action( 'malka_crm_invoice_uploaded', $invoice_id, $user_id, $order_id );

		wp_safe_redirect( add_query_arg( 'malka_crm_msg', 'uploaded', $redirect ) );
		exit;
	}

	/**
	 * Mueve y valida el archivo subido, dejándolo en la carpeta blindada.
	 *
	 * Lo usan las facturas HTML de siempre y los PDF que se adjuntan a un
	 * documento de Contabilidad: el mismo sitio, las mismas comprobaciones
	 * —tamaño, extensión, que un PDF empiece de verdad por %PDF-— y el mismo
	 * nombre de archivo imposible de adivinar.
	 *
	 * @param array $file Entrada de $_FILES.
	 * @return array|WP_Error {relative, original}
	 */
	public static function guardar_archivo( $file ) {

		if ( ! isset( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'invalid_upload', __( 'Subida inválida.', 'malka-crm' ) );
		}

		if ( ! empty( $file['error'] ) ) {
			return new WP_Error( 'upload_error', __( 'Error en la subida.', 'malka-crm' ) );
		}

		$max_size  = self::tope_bytes();
		$original  = sanitize_file_name( $file['name'] );
		$extension = strtolower( pathinfo( $original, PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, array( 'html', 'htm', 'pdf' ), true ) ) {
			return new WP_Error( 'bad_extension', __( 'Solo se aceptan archivos .pdf, .html o .htm.', 'malka-crm' ) );
		}

		/*
		 * Un PDF que se pasa del tope no se rechaza de entrada: primero se
		 * intenta achicar. Las facturas escaneadas pesan por las imágenes que
		 * llevan dentro, y bajarles la resolución las deja igual de legibles
		 * ocupando una fracción. Si el servidor no tiene con qué comprimir, o
		 * aun comprimido sigue sin caber, recién ahí se dice que no —y se
		 * dice cuánto pesa y cuánto cabe, no «revisa que sea menos de 3 MB»—.
		 */
		$ruta_tmp = $file['tmp_name'];
		$peso     = (int) $file['size'];

		if ( $peso > $max_size ) {
			if ( 'pdf' !== $extension ) {
				return self::error_grande( $peso, $max_size, false );
			}

			$chico = self::comprimir_pdf( $ruta_tmp );

			if ( ! $chico ) {
				return self::error_grande( $peso, $max_size, false );
			}

			$nuevo = (int) filesize( $chico );

			if ( $nuevo > $max_size ) {
				wp_delete_file( $chico );
				return self::error_grande( $nuevo, $max_size, true );
			}

			$ruta_tmp = $chico;
			$peso     = $nuevo;
		}

		// Doble control: el contenido no debe ser un binario ni contener aperturas PHP.
		$contents = file_get_contents( $ruta_tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $contents ) {
			return new WP_Error( 'unreadable', __( 'No se pudo leer el archivo.', 'malka-crm' ) );
		}

		if ( 'pdf' === $extension ) {
			// Un PDF real siempre empieza por %PDF-.
			if ( 0 !== strpos( $contents, '%PDF-' ) ) {
				return new WP_Error( 'bad_pdf', __( 'El archivo no es un PDF válido.', 'malka-crm' ) );
			}
		} elseif ( false !== stripos( $contents, '<?php' ) || false !== stripos( $contents, '<?=' ) ) {
			return new WP_Error( 'php_detected', __( 'El archivo contiene código PHP y fue rechazado.', 'malka-crm' ) );
		}

		self::protect_storage_directory();

		$subdir = gmdate( 'Y/m' );
		$dir    = trailingslashit( self::storage_path() ) . $subdir;
		wp_mkdir_p( $dir );

		$filename = wp_generate_password( 20, false, false ) . '-' . pathinfo( $original, PATHINFO_FILENAME ) . '.' . ( 'pdf' === $extension ? 'pdf' : 'html' );
		$filename = sanitize_file_name( $filename );
		$target   = trailingslashit( $dir ) . $filename;

		/*
		 * Si se comprimió, lo que hay que mover ya no es el archivo subido
		 * sino el que dejó el compresor: move_uploaded_file() lo rechazaría
		 * justamente por no venir de la subida.
		 */
		$movido = ( $ruta_tmp === $file['tmp_name'] )
			? @move_uploaded_file( $ruta_tmp, $target ) // phpcs:ignore
			: @rename( $ruta_tmp, $target ); // phpcs:ignore

		if ( ! $movido ) {
			if ( $ruta_tmp !== $file['tmp_name'] && file_exists( $ruta_tmp ) ) {
				wp_delete_file( $ruta_tmp );
			}

			return new WP_Error( 'move_failed', __( 'No se pudo guardar el archivo.', 'malka-crm' ) );
		}

		@chmod( $target, 0644 ); // phpcs:ignore

		return array(
			'relative'   => $subdir . '/' . $filename,
			'original'   => $original,
			'bytes'      => $peso,
			'comprimido' => ( $ruta_tmp !== $file['tmp_name'] ),
		);
	}

	/**
	 * Cuánto puede pesar un archivo adjunto.
	 *
	 * Dos topes, y manda el más chico: el del plugin y el que de verdad deja
	 * pasar el servidor. Decir 20 MB en un hosting que corta en 8 sería
	 * mandarla a pelear contra una pared.
	 *
	 * @return int Bytes.
	 */
	public static function tope_bytes() {
		$plugin = (int) apply_filters( 'malka_crm_invoice_max_size', 20 * MB_IN_BYTES );

		if ( function_exists( 'wp_max_upload_size' ) ) {
			$php = (int) wp_max_upload_size();

			if ( $php > 0 ) {
				$plugin = min( $plugin, $php );
			}
		}

		return max( 1, $plugin );
	}

	/**
	 * El error de «no cabe», dicho con los números a la vista.
	 *
	 * @param int  $pesa       Lo que pesa, en bytes.
	 * @param int  $tope       Lo que cabe, en bytes.
	 * @param bool $comprimido Si ya se intentó achicarlo.
	 * @return WP_Error
	 */
	private static function error_grande( $pesa, $tope, $comprimido ) {
		$mb = function ( $b ) {
			return number_format_i18n( $b / MB_IN_BYTES, ( $b < 10 * MB_IN_BYTES ) ? 1 : 0 ) . ' MB';
		};

		if ( $comprimido ) {
			$texto = sprintf(
				/* translators: 1: peso ya comprimido, 2: tope */
				__( 'Ni comprimido cabe: quedó en %1$s y el máximo es %2$s.', 'malka-crm' ),
				$mb( $pesa ),
				$mb( $tope )
			);
		} elseif ( self::hay_compresor() ) {
			$texto = sprintf(
				/* translators: 1: peso, 2: tope */
				__( 'Pesa %1$s y el máximo es %2$s. No se pudo comprimir.', 'malka-crm' ),
				$mb( $pesa ),
				$mb( $tope )
			);
		} else {
			$texto = sprintf(
				/* translators: 1: peso, 2: tope */
				__( 'Pesa %1$s y el máximo es %2$s. Este servidor no tiene con qué comprimir PDF, así que hay que achicarlo antes de subirlo.', 'malka-crm' ),
				$mb( $pesa ),
				$mb( $tope )
			);
		}

		return new WP_Error( 'too_big', $texto );
	}

	/**
	 * Si el servidor sabe comprimir PDF.
	 *
	 * Ghostscript es el único que vale acá: rebaja las imágenes de adentro y
	 * deja el texto como texto. Convertir el PDF a imagen pesaría menos pero
	 * devolvería una factura que no se puede ni buscar ni copiar, y eso no es
	 * comprimir, es romper.
	 *
	 * @return bool
	 */
	public static function hay_compresor() {
		return '' !== self::ruta_gs();
	}

	/**
	 * Dónde está Ghostscript, si está.
	 *
	 * @return string Ruta o '' si no se puede usar.
	 */
	private static function ruta_gs() {
		/*
		 * El filtro se consulta siempre, antes del cache: un hosting puede
		 * tenerlo en un sitio raro, o puede convenir apagarlo a mano, y si
		 * eso quedara congelado en la primera llamada el filtro no serviría
		 * de nada según cuándo se enganchara. Devolver '' lo deja sin
		 * compresor.
		 */
		$puesto = apply_filters( 'malka_crm_gs_path', null );

		if ( null !== $puesto ) {
			return ( is_string( $puesto ) && '' !== $puesto && is_executable( $puesto ) ) ? $puesto : '';
		}

		// Buscarlo sí se hace una vez: son llamadas al sistema de archivos y
		// un exec, y esto se consulta en cada carga de la ficha.
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$cache = '';

		if ( ! function_exists( 'exec' ) ) {
			return $cache;
		}

		// Muchos hostings dejan la función declarada pero apagada.
		$apagadas = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

		if ( in_array( 'exec', $apagadas, true ) ) {
			return $cache;
		}

		foreach ( array( '/usr/bin/gs', '/usr/local/bin/gs', '/opt/homebrew/bin/gs' ) as $ruta ) {
			if ( is_executable( $ruta ) ) {
				$cache = $ruta;
				return $cache;
			}
		}

		$salida = array();
		$codigo = 1;
		@exec( 'command -v gs 2>/dev/null', $salida, $codigo ); // phpcs:ignore

		if ( 0 === $codigo && ! empty( $salida[0] ) && is_executable( trim( $salida[0] ) ) ) {
			$cache = trim( $salida[0] );
		}

		return $cache;
	}

	/**
	 * Achica un PDF sin convertirlo en una foto.
	 *
	 * Se prueban dos calidades, de mejor a peor, y se usa la primera que de
	 * verdad pese menos que el original: a veces Ghostscript devuelve un
	 * archivo más grande que el que entró, y quedarse con ese sería empeorar
	 * las cosas en silencio.
	 *
	 * @param string $origen Archivo a comprimir.
	 * @return string Ruta del comprimido, o '' si no se pudo.
	 */
	public static function comprimir_pdf( $origen ) {
		$gs = self::ruta_gs();

		if ( '' === $gs || ! is_readable( $origen ) ) {
			return '';
		}

		$pesaba = (int) filesize( $origen );
		$mejor  = '';
		$pesa   = $pesaba;

		foreach ( array( '/ebook', '/screen' ) as $calidad ) {
			$destino = wp_tempnam( 'malka-pdf' );

			if ( ! $destino ) {
				continue;
			}

			$cmd = sprintf(
				'%s -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dPDFSETTINGS=%s ' .
				'-dNOPAUSE -dQUIET -dBATCH -dSAFER -sOutputFile=%s %s 2>/dev/null',
				escapeshellarg( $gs ),
				escapeshellarg( $calidad ),
				escapeshellarg( $destino ),
				escapeshellarg( $origen )
			);

			$salida = array();
			$codigo = 1;
			@exec( $cmd, $salida, $codigo ); // phpcs:ignore

			$quedo = ( 0 === $codigo && file_exists( $destino ) ) ? (int) filesize( $destino ) : 0;
			$sirve = ( $quedo > 0 && $quedo < $pesa );

			if ( $sirve && '%PDF-' === substr( (string) file_get_contents( $destino ), 0, 5 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( $mejor ) {
					wp_delete_file( $mejor );
				}

				$mejor = $destino;
				$pesa  = $quedo;

				// Con la calidad buena ya basta si cabe; si no, se prueba la
				// siguiente, que sólo se queda si pesa todavía menos.
				if ( $quedo <= self::tope_bytes() ) {
					return $mejor;
				}

				continue;
			}

			wp_delete_file( $destino );
		}

		return $mejor;
	}

	/**
	 * Elimina una factura desde el admin.
	 */
	public function handle_delete() {
		if ( ! Malka_CRM_Helpers::current_user_is_manager() ) {
			wp_die( esc_html__( 'Sin permisos.', 'malka-crm' ), 403 );
		}

		$invoice_id = isset( $_GET['invoice_id'] ) ? absint( $_GET['invoice_id'] ) : 0;
		check_admin_referer( 'malka_crm_delete_invoice_' . $invoice_id );

		if ( $invoice_id && 'malka_invoice' === get_post_type( $invoice_id ) ) {
			wp_delete_post( $invoice_id, true );
		}

		wp_safe_redirect( add_query_arg( 'malka_crm_msg', 'deleted', admin_url( 'admin.php?page=malka-crm-invoices' ) ) );
		exit;
	}

	/**
	 * Borra el archivo físico al eliminar el CPT.
	 *
	 * @param int $post_id ID.
	 */
	public function delete_attached_file( $post_id ) {
		if ( 'malka_invoice' !== get_post_type( $post_id ) ) {
			return;
		}
		$relative = get_post_meta( $post_id, '_malka_pm_file', true );
		if ( ! $relative ) {
			return;
		}
		$path = trailingslashit( self::storage_path() ) . ltrim( $relative, '/' );
		if ( file_exists( $path ) && 0 === strpos( realpath( $path ), realpath( self::storage_path() ) ) ) {
			@unlink( $path ); // phpcs:ignore
		}
	}

	/**
	 * Facturas de un cliente.
	 *
	 * @param int $user_id ID de usuario.
	 * @return WP_Post[]
	 */
	public static function get_client_invoices( $user_id ) {

		if ( Malka_CRM_Helpers::current_user_is_manager() ) {
			return get_posts(
				array(
					'post_type'      => 'malka_invoice',
					'post_status'    => array( 'private', 'publish' ),
					'posts_per_page' => 100,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);
		}

		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return array();
		}

		return get_posts(
			array(
				'post_type'      => 'malka_invoice',
				'post_status'    => array( 'private', 'publish' ),
				'posts_per_page' => 100,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_malka_pm_client_id',
						'value' => $user_id,
					),
				),
			)
		);
	}

	/**
	 * URL firmada para ver o descargar una factura.
	 *
	 * @param int    $invoice_id ID.
	 * @param string $mode       view|download.
	 * @return string
	 */
	public static function get_secure_url( $invoice_id, $mode = 'view' ) {
		$url = add_query_arg(
			array(
				'action'     => 'malka_crm_view_invoice',
				'invoice_id' => (int) $invoice_id,
				'mode'       => ( 'download' === $mode ) ? 'download' : 'view',
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, 'malka_crm_invoice_' . (int) $invoice_id, 'malka_crm_nonce' );
	}

	/**
	 * Proxy seguro: valida sesión, propiedad y nonce antes de entregar el HTML.
	 */
	public function serve_invoice() {

		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}

		$invoice_id = isset( $_GET['invoice_id'] ) ? absint( $_GET['invoice_id'] ) : 0;
		$mode       = isset( $_GET['mode'] ) && 'download' === $_GET['mode'] ? 'download' : 'view';

		if ( ! $invoice_id || 'malka_invoice' !== get_post_type( $invoice_id ) ) {
			wp_die( esc_html__( 'Factura no encontrada.', 'malka-crm' ), 404 );
		}

		if ( ! isset( $_GET['malka_crm_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['malka_crm_nonce'] ) ), 'malka_crm_invoice_' . $invoice_id ) ) {
			wp_die( esc_html__( 'Enlace caducado. Vuelve a Mi Cuenta y ábrelo de nuevo.', 'malka-crm' ), 403 );
		}

		$owner_id = (int) get_post_meta( $invoice_id, '_malka_pm_client_id', true );

		if ( get_current_user_id() !== $owner_id && ! Malka_CRM_Helpers::current_user_is_manager() ) {
			wp_die( esc_html__( 'No tienes acceso a este documento.', 'malka-crm' ), 403 );
		}

		$relative = get_post_meta( $invoice_id, '_malka_pm_file', true );
		$path     = trailingslashit( self::storage_path() ) . ltrim( (string) $relative, '/' );
		$real     = realpath( $path );
		$base     = realpath( self::storage_path() );

		if ( ! $real || ! $base || 0 !== strpos( $real, $base ) || ! is_file( $real ) ) {
			wp_die( esc_html__( 'Archivo no disponible.', 'malka-crm' ), 404 );
		}

		nocache_headers();

		if ( 'download' === $mode ) {
			$name = get_post_meta( $invoice_id, '_malka_pm_original_name', true );
			$name = $name ? $name : 'factura-' . $invoice_id . '.' . pathinfo( $real, PATHINFO_EXTENSION );
			header( 'Content-Type: application/octet-stream' );
			header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $name ) . '"' );
			header( 'Content-Length: ' . filesize( $real ) );
			header( 'X-Content-Type-Options: nosniff' );
			readfile( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			exit;
		}

		if ( 'pdf' === strtolower( pathinfo( $real, PATHINFO_EXTENSION ) ) ) {
			header( 'Content-Type: application/pdf' );
			header( 'Content-Disposition: inline; filename="' . sanitize_file_name( basename( $real ) ) . '"' );
			header( 'Content-Length: ' . filesize( $real ) );
			header( 'X-Content-Type-Options: nosniff' );
			readfile( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			exit;
		}

		$html = file_get_contents( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( 'kses' === Malka_CRM_Helpers::get_setting( 'invoice_render' ) ) {
			$html = wp_kses_post( $html );
		}

		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Referrer-Policy: no-referrer' );
		// `sandbox` sin allow-same-origin: el HTML se ejecuta en un origen opaco, sin acceso a cookies ni DOM del sitio.
		header( "Content-Security-Policy: sandbox allow-popups allow-modals allow-forms;" );

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
