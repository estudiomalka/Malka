<?php
/**
 * Actualizar el plugin desde donde se publica, en un clic.
 *
 * Hasta ahora cada cambio era: armar un ZIP, bajarlo, subirlo a WordPress
 * y recargar. Con esto el panel pregunta solo si hay una versión más
 * nueva y WordPress la ofrece en Plugins como cualquier otro plugin.
 *
 * Mientras no haya un repositorio configurado esto no hace absolutamente
 * nada: no consulta, no avisa y no se mete en las actualizaciones. Se
 * enciende poniendo el repositorio en Configuración.
 *
 * Por qué GitHub y no el sitio: el ZIP tiene que estar en algún lado que
 * WordPress pueda bajar. Un repositorio privado con sus «releases» sirve
 * para eso, no cuesta nada y deja el historial de cada versión.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

class Malka_CRM_Updater {

	/**
	 * Cuánto se guarda la respuesta antes de volver a preguntar.
	 *
	 * Seis horas: WordPress consulta las actualizaciones varias veces al
	 * día y no tiene sentido golpear la API en cada carga. Al apretar
	 * «Buscar actualizaciones» se borra y se pregunta de nuevo.
	 */
	const RATO = 6 * HOUR_IN_SECONDS;

	/** Dónde se guarda la última respuesta. */
	const CACHE = 'malka_crm_ultima_version';

	/** Por qué falló la última consulta, para poder decirlo. */
	private static $motivo = '';

	/** La ficha con la versión publicada, dentro del repositorio. */
	const FICHA = 'publicar/version.json';

	/** Y el ZIP, al lado. */
	const PAQUETE = 'publicar/malka-crm.zip';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'avisar' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'ficha' ), 10, 3 );
		// Al terminar de instalar, el directorio tiene que volver a llamarse
		// como antes o WordPress lo da por otro plugin y lo desactiva.
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'renombrar' ), 10, 4 );
		add_action( 'malka_crm_olvidar_version', array( __CLASS__, 'olvidar' ) );
		add_action( 'admin_post_malka_crm_buscar_version', array( __CLASS__, 'handle_buscar' ) );
		// Para bajar el ZIP de un repositorio privado hace falta la llave.
		add_filter( 'http_request_args', array( __CLASS__, 'firmar' ), 10, 2 );
	}

	/**
	 * Prepara la descarga del ZIP: cómo pedirlo, y con qué llave si hace falta.
	 *
	 * Dos cosas distintas, y conviene no confundirlas:
	 *
	 * - **Cómo se pide.** Siempre. La API de GitHub, si no se le dice que se
	 *   quiere el archivo, contesta con una ficha en JSON y WordPress intenta
	 *   descomprimir eso. Pasa igual con el repositorio público: ahí no falta
	 *   ninguna credencial, falta decir qué se quiere.
	 * - **Con qué llave.** Sólo si hay una guardada, o sea sólo cuando el
	 *   repositorio es privado. Uno público entrega el archivo a cualquiera.
	 *
	 * En los dos casos se toca únicamente las direcciones de este
	 * repositorio, nunca otra petición del sitio.
	 *
	 * @param array  $args Con qué se va a pedir.
	 * @param string $url  A dónde.
	 * @return array
	 */
	public static function firmar( $args, $url ) {
		$llave = self::llave();
		$repo  = self::repo();

		if ( '' === $repo ) {
			return $args;
		}

		/*
		 * Dos formas de bajar el ZIP, según de dónde venga:
		 *
		 * - El adjunto de una release, que pide «octet-stream».
		 * - Un archivo guardado en el repositorio, que pide «raw».
		 */
		$base = 'https://api.github.com/repos/' . $repo . '/';
		$como = '';

		/*
		 * Sin distinguir mayúsculas, porque GitHub tampoco: el repositorio
		 * se llama «Malka» y escrito «malka» entra igual, pero las
		 * direcciones que devuelve la API vienen con el nombre tal cual
		 * está registrado. Comparado letra por letra, la llave no se
		 * adjuntaba y la descarga fallaba con un «no existe».
		 *
		 * Lo que no se afloja es dónde empieza: tiene que ser el comienzo
		 * de la dirección, no aparecer en alguna parte. Una dirección
		 * ajena que lleve la nuestra escrita adentro no se firma.
		 */
		if ( 0 === stripos( (string) $url, $base . 'releases/assets/' ) ) {
			$como = 'application/octet-stream';
		} elseif ( 0 === stripos( (string) $url, $base . 'contents/' ) ) {
			$como = 'application/vnd.github.raw';
		} else {
			return $args;
		}

		if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
			$args['headers'] = array();
		}

		// Cómo se pide: siempre, haya llave o no.
		$args['headers']['Accept'] = $como;

		// La llave: sólo si hay una, o sea sólo si el repositorio es privado.
		if ( '' !== $llave ) {
			$args['headers']['Authorization'] = 'Bearer ' . $llave;
		}

		return $args;
	}

	/**
	 * Lo publicado como archivo dentro del repositorio.
	 *
	 * Una release se crea a mano en GitHub. Esto es la otra vía: dejar el
	 * ZIP y una ficha con la versión guardados en el repositorio, y que el
	 * panel los lea de ahí. Se publica con un commit, que es lo que de
	 * verdad se hace cada vez que hay un arreglo.
	 *
	 * @return array|null Lo mismo que «ultima», o null.
	 */
	private static function publicado() {
		$repo = self::repo();

		if ( '' === $repo ) {
			return null;
		}

		$args = array(
			'timeout' => 10,
			'headers' => array(
				'Accept'     => 'application/vnd.github.raw',
				'User-Agent' => 'Malka-CRM/' . MALKA_CRM_VERSION,
			),
		);

		$llave = self::llave();

		if ( '' !== $llave ) {
			$args['headers']['Authorization'] = 'Bearer ' . $llave;
		}

		$base = 'https://api.github.com/repos/' . $repo . '/contents/';
		$r    = wp_remote_get( $base . self::FICHA, $args );

		if ( is_wp_error( $r ) ) {
			self::$motivo = sprintf(
				/* translators: el error de red */
				__( 'El sitio no pudo conectarse a GitHub: %s. Suele ser el hosting bloqueando las salidas a internet.', 'malka-crm' ),
				$r->get_error_message()
			);
			return null;
		}

		$codigo = (int) wp_remote_retrieve_response_code( $r );

		if ( 200 !== $codigo ) {
			self::$motivo = 404 === $codigo
				? __( 'GitHub dice que ahí no hay nada publicado. Revisa el nombre del repositorio, y que sea público o que la llave sirva.', 'malka-crm' )
				: sprintf(
					/* translators: el código de respuesta */
					__( 'GitHub contestó %d al pedir el archivo de versión.', 'malka-crm' ),
					$codigo
				);
			return null;
		}

		$j = json_decode( wp_remote_retrieve_body( $r ), true );

		if ( ! is_array( $j ) || empty( $j['version'] ) ) {
			self::$motivo = __( 'Se leyó el archivo de versión pero no se entendió lo que trae.', 'malka-crm' );
			return null;
		}

		return array(
			'repo'    => $repo,
			'version' => ltrim( (string) $j['version'], 'vV' ),
			'zip'     => $base . self::PAQUETE,
			'notas'   => isset( $j['notas'] ) ? (string) $j['notas'] : '',
			'fecha'   => isset( $j['fecha'] ) ? (string) $j['fecha'] : '',
		);
	}

	/**
	 * El repositorio configurado, como «usuario/repositorio».
	 *
	 * @return string Vacío si no hay ninguno.
	 */
	public static function repo() {
		$v = (string) Malka_CRM_Settings::get( 'repo', '' );
		$v = trim( $v );

		if ( '' === $v ) {
			return '';
		}

		// Se acepta pegar la dirección completa del navegador.
		if ( preg_match( '#github\.com/([^/\s]+/[^/\s\?\#]+)#i', $v, $m ) ) {
			$v = $m[1];
		}

		$v = preg_replace( '/\.git$/i', '', $v );
		$v = trim( $v, '/' );

		return preg_match( '#^[\w.-]+/[\w.-]+$#', $v ) ? $v : '';
	}

	/**
	 * La llave para un repositorio privado, si hace falta.
	 *
	 * Un repositorio público no la necesita. Uno privado sí, y sin ella la
	 * consulta vuelve «no encontrado», que es lo mismo que ver GitHub sin
	 * haber entrado.
	 *
	 * @return string
	 */
	private static function llave() {
		return trim( (string) Malka_CRM_Settings::get( 'repo_token', '' ) );
	}

	/**
	 * Pregunta cuál es la última versión publicada.
	 *
	 * @param bool $forzar Saltarse lo guardado y preguntar igual.
	 * @return array|null version, zip, notas, fecha; o null si no se pudo.
	 */
	public static function ultima( $forzar = false ) {
		$repo = self::repo();

		if ( '' === $repo ) {
			return null;
		}

		if ( ! $forzar ) {
			$ya = get_site_transient( self::CACHE );

			if ( is_array( $ya ) && isset( $ya['repo'] ) && $ya['repo'] === $repo ) {
				return empty( $ya['version'] ) ? null : $ya;
			}
		}

		$args = array(
			'timeout' => 10,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'Malka-CRM/' . MALKA_CRM_VERSION,
			),
		);

		$llave = self::llave();

		if ( '' !== $llave ) {
			$args['headers']['Authorization'] = 'Bearer ' . $llave;
		}

		$r = wp_remote_get( 'https://api.github.com/repos/' . $repo . '/releases/latest', $args );

		/*
		 * Por qué falló, en palabras. Antes todos los fracasos decían lo
		 * mismo —«no se pudo leer ese repositorio»— y eso vale igual para
		 * un nombre mal escrito que para un hosting que no deja salir a
		 * internet. Son problemas distintos y se arreglan distinto.
		 */
		self::$motivo = '';

		if ( is_wp_error( $r ) ) {
			self::$motivo = sprintf(
				/* translators: el error de red */
				__( 'El sitio no pudo conectarse a GitHub: %s. Suele ser el hosting bloqueando las salidas a internet.', 'malka-crm' ),
				$r->get_error_message()
			);
		} elseif ( 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
			$codigo = (int) wp_remote_retrieve_response_code( $r );

			if ( 404 !== $codigo ) {
				self::$motivo = sprintf(
					/* translators: el código de respuesta */
					__( 'GitHub contestó %d al pedir las versiones.', 'malka-crm' ),
					$codigo
				);
			}
		}

		$j = ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) )
			? null
			: json_decode( wp_remote_retrieve_body( $r ), true );

		if ( ! is_array( $j ) || empty( $j['tag_name'] ) ) {
			/*
			 * Sin release, se mira lo publicado como archivo dentro del
			 * repositorio, que es la vía que se usa acá: cada arreglo se
			 * publica con un commit, no creando una release a mano.
			 */
			$alt = self::publicado();

			/*
			 * Se guarda también el fracaso, un rato: si el repositorio no
			 * existe o la llave venció, no tiene sentido preguntar en cada
			 * carga de cada pantalla.
			 */
			set_site_transient(
				self::CACHE,
				$alt ? $alt : array( 'repo' => $repo, 'version' => '' ),
				self::RATO
			);

			return $alt;
		}

		/*
		 * El ZIP: primero uno subido a mano a la release, que es el que
		 * trae el plugin listo. El que arma GitHub solo trae el código tal
		 * cual está en el repositorio, que sirve igual.
		 */
		$zip = '';

		if ( ! empty( $j['assets'] ) && is_array( $j['assets'] ) ) {
			foreach ( $j['assets'] as $a ) {
				if ( empty( $a['browser_download_url'] ) || ! preg_match( '/\.zip$/i', $a['browser_download_url'] ) ) {
					continue;
				}

				/*
				 * Con llave se usa la dirección de la API, que es la única
				 * que entrega el archivo de un repositorio privado; «firmar»
				 * le agrega la credencial. Sin llave, la de siempre.
				 */
				$zip = ( '' !== $llave && ! empty( $a['url'] ) )
					? (string) $a['url']
					: (string) $a['browser_download_url'];
				break;
			}
		}

		if ( '' === $zip && ! empty( $j['zipball_url'] ) ) {
			$zip = (string) $j['zipball_url'];
		}

		$datos = array(
			'repo'    => $repo,
			'version' => ltrim( (string) $j['tag_name'], 'vV' ),
			'zip'     => $zip,
			'notas'   => isset( $j['body'] ) ? (string) $j['body'] : '',
			'fecha'   => isset( $j['published_at'] ) ? (string) $j['published_at'] : '',
		);

		set_site_transient( self::CACHE, $datos, self::RATO );

		return $datos;
	}

	/**
	 * Por qué falló la última consulta.
	 *
	 * @return string Vacío si no falló, o si no se consultó en esta carga.
	 */
	public static function motivo() {
		return self::$motivo;
	}

	/** Borra lo guardado para que la próxima consulta pregunte de nuevo. */
	public static function olvidar() {
		delete_site_transient( self::CACHE );
	}

	/**
	 * «Buscar ahora»: olvidar lo guardado y preguntar de verdad.
	 *
	 * Borra las dos memorias, la nuestra y la de WordPress: con una sola
	 * el panel diría que hay una versión nueva y la lista de plugins
	 * seguiría sin ofrecerla, que es peor que no decir nada.
	 */
	public static function handle_buscar() {
		check_admin_referer( 'malka_crm_buscar_version' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No puedes hacer eso.', 'malka-crm' ) );
		}

		self::olvidar();
		self::ultima( true );
		delete_site_transient( 'update_plugins' );

		$volver = isset( $_POST['malka_volver'] ) ? esc_url_raw( wp_unslash( $_POST['malka_volver'] ) ) : '';

		wp_safe_redirect( $volver ? $volver : admin_url( 'plugins.php' ) );
		exit;
	}

	/**
	 * Le dice a WordPress que hay una versión más nueva.
	 *
	 * @param object $t Lo que WordPress tiene de actualizaciones.
	 * @return object
	 */
	public static function avisar( $t ) {
		if ( ! is_object( $t ) ) {
			return $t;
		}

		$u = self::ultima();

		if ( ! $u || empty( $u['zip'] ) ) {
			return $t;
		}

		if ( ! version_compare( $u['version'], MALKA_CRM_VERSION, '>' ) ) {
			return $t;
		}

		$ficha = array(
			'id'          => MALKA_CRM_BASENAME,
			'slug'        => dirname( MALKA_CRM_BASENAME ),
			'plugin'      => MALKA_CRM_BASENAME,
			'new_version' => $u['version'],
			'url'         => 'https://github.com/' . $u['repo'],
			'package'     => $u['zip'],
			'tested'      => get_bloginfo( 'version' ),
		);

		$t->response[ MALKA_CRM_BASENAME ] = (object) $ficha;

		return $t;
	}

	/**
	 * Lo que se ve al apretar «Ver detalles» en la lista de plugins.
	 *
	 * @param mixed  $res  Lo que venga de antes.
	 * @param string $que  Qué se está pidiendo.
	 * @param object $args Con qué.
	 * @return mixed
	 */
	public static function ficha( $res, $que, $args ) {
		if ( 'plugin_information' !== $que ) {
			return $res;
		}

		if ( ! isset( $args->slug ) || dirname( MALKA_CRM_BASENAME ) !== $args->slug ) {
			return $res;
		}

		$u = self::ultima();

		if ( ! $u ) {
			return $res;
		}

		return (object) array(
			'name'          => 'Malka CRM',
			'slug'          => $args->slug,
			'version'       => $u['version'],
			'author'        => 'Estudio Malka',
			'homepage'      => 'https://github.com/' . $u['repo'],
			'download_link' => $u['zip'],
			'last_updated'  => $u['fecha'],
			'sections'      => array(
				'changelog' => wpautop( esc_html( $u['notas'] ) ),
			),
		);
	}

	/**
	 * Deja el directorio con el nombre de siempre.
	 *
	 * GitHub arma el ZIP con un nombre propio —«malka-crm-5.0.0» o
	 * «usuario-malka-crm-a1b2c3»—. Si se instala así, WordPress cree que
	 * es otro plugin: deja el viejo desactivado y el panel desaparece.
	 *
	 * @param string $origen  Dónde quedó descomprimido.
	 * @param string $remoto  Sin uso.
	 * @param object $quien   Quien está instalando.
	 * @param array  $extra   Con qué.
	 * @return string|WP_Error
	 */
	public static function renombrar( $origen, $remoto, $quien = null, $extra = array() ) {
		global $wp_filesystem;

		if ( ! isset( $extra['plugin'] ) || MALKA_CRM_BASENAME !== $extra['plugin'] ) {
			return $origen;
		}

		$quiero = trailingslashit( dirname( $origen ) ) . dirname( MALKA_CRM_BASENAME );

		if ( untrailingslashit( $origen ) === untrailingslashit( $quiero ) ) {
			return $origen;
		}

		if ( $wp_filesystem && $wp_filesystem->move( $origen, $quiero, true ) ) {
			return trailingslashit( $quiero );
		}

		return new WP_Error(
			'malka_crm_rename',
			__( 'No se pudo dejar la carpeta con su nombre de siempre. La actualización se detuvo para no desactivar el panel.', 'malka-crm' )
		);
	}
}
