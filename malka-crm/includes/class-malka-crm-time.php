<?php
/**
 * Tiempo: pomodoro y horas por proyecto.
 *
 * Cada sesión completada queda asociada a un proyecto. De acá salen las horas
 * reales que permiten comparar lo cotizado con lo trabajado, y las horas
 * donadas con su tope mensual.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

class Malka_CRM_Time {

	const TABLE      = 'malka_crm_horas';
	const DB_VERSION = '1.0';
	const OPT_DB     = 'malka_crm_time_db';
	const META_PRO_BONO = '_malka_pm_pro_bono';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ), 22 );
		add_action( 'wp_ajax_malka_crm_time', array( $this, 'handle_ajax' ) );
	}

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function install() {
		global $wpdb;

		$table   = self::table_name();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			fecha date NOT NULL,
			minutos int(11) NOT NULL DEFAULT 0,
			project_id bigint(20) unsigned NOT NULL DEFAULT 0,
			task_id varchar(60) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			nota varchar(190) NOT NULL DEFAULT '',
			creado datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY fecha (fecha),
			KEY project_id (project_id),
			KEY user_id (user_id)
		) {$collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::OPT_DB, self::DB_VERSION, false );
	}

	public static function maybe_install() {
		if ( get_option( self::OPT_DB ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/* ─────────────────────────────  Registro  ─────────────────────────── */

	/**
	 * Guarda una sesión de trabajo.
	 *
	 * @param int    $minutos    Minutos trabajados.
	 * @param int    $project_id Proyecto asociado, 0 si ninguno.
	 * @param string $nota       Nota opcional.
	 * @return int|WP_Error
	 */
	public static function log( $minutos, $project_id = 0, $nota = '' ) {
		global $wpdb;

		self::maybe_install();

		$minutos = (int) $minutos;
		if ( $minutos <= 0 || $minutos > 600 ) {
			return new WP_Error( 'malka_crm_time_invalido', __( 'Duración fuera de rango.', 'malka-crm' ) );
		}

		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table_name(),
			array(
				'fecha'      => current_time( 'Y-m-d' ),
				'minutos'    => $minutos,
				'project_id' => (int) $project_id,
				'user_id'    => get_current_user_id(),
				'nota'       => sanitize_text_field( $nota ),
				'creado'     => current_time( 'mysql' ),
			)
		);

		if ( false === $ok ) {
			return new WP_Error( 'malka_crm_time_db', __( 'No se pudo guardar la sesión.', 'malka-crm' ) );
		}

		do_action( 'malka_crm_time_logged', (int) $wpdb->insert_id, $minutos, (int) $project_id );

		return (int) $wpdb->insert_id;
	}

	/* ─────────────────────────────  Totales  ──────────────────────────── */

	/**
	 * Minutos registrados entre dos fechas.
	 *
	 * @param string $desde Y-m-d.
	 * @param string $hasta Y-m-d.
	 * @param int    $project_id Filtra por proyecto, 0 = todos.
	 */
	public static function minutes_between( $desde, $hasta, $project_id = 0 ) {
		global $wpdb;
		self::maybe_install();

		if ( $project_id > 0 ) {
			$total = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
				$wpdb->prepare(
					'SELECT SUM(minutos) FROM ' . self::table_name() . ' WHERE fecha BETWEEN %s AND %s AND project_id = %d',
					$desde,
					$hasta,
					(int) $project_id
				)
			);
		} else {
			$total = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
				$wpdb->prepare(
					'SELECT SUM(minutos) FROM ' . self::table_name() . ' WHERE fecha BETWEEN %s AND %s',
					$desde,
					$hasta
				)
			);
		}

		return (int) $total;
	}

	public static function minutes_today( $project_id = 0 ) {
		$hoy = current_time( 'Y-m-d' );
		return self::minutes_between( $hoy, $hoy, $project_id );
	}

	public static function minutes_this_week( $project_id = 0 ) {
		$hoy   = current_time( 'Y-m-d' );
		$lunes = gmdate( 'Y-m-d', strtotime( 'monday this week', strtotime( $hoy ) ) );
		return self::minutes_between( $lunes, $hoy, $project_id );
	}

	public static function minutes_this_month( $project_id = 0 ) {
		$ini = current_time( 'Y-m-01' );
		$fin = gmdate( 'Y-m-t', strtotime( $ini ) );
		return self::minutes_between( $ini, $fin, $project_id );
	}

	/**
	 * Minutos acumulados de un proyecto en toda su vida.
	 *
	 * @param int $project_id Proyecto.
	 */
	public static function minutes_for_project( $project_id ) {
		global $wpdb;
		self::maybe_install();

		$total = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->prepare(
				'SELECT SUM(minutos) FROM ' . self::table_name() . ' WHERE project_id = %d',
				(int) $project_id
			)
		);

		return (int) $total;
	}

	/* ─────────────────────────────  Pro bono  ─────────────────────────── */

	/**
	 * Marca o desmarca un proyecto como sin costo.
	 *
	 * @param int  $project_id Proyecto.
	 * @param bool $es         Si es pro bono.
	 * @param int  $horas      Horas asignadas.
	 */
	public static function set_pro_bono( $project_id, $es, $horas = 0 ) {
		if ( $es ) {
			update_post_meta( (int) $project_id, self::META_PRO_BONO, max( 0, (int) $horas ) );
		} else {
			delete_post_meta( (int) $project_id, self::META_PRO_BONO );
		}
	}

	/**
	 * Horas asignadas a un proyecto sin costo, o null si no lo es.
	 *
	 * @param int $project_id Proyecto.
	 * @return int|null
	 */
	public static function pro_bono_hours( $project_id ) {
		$v = get_post_meta( (int) $project_id, self::META_PRO_BONO, true );
		return ( '' === $v || null === $v ) ? null : (int) $v;
	}

	/**
	 * Proyectos sin costo con sus horas usadas y valorizadas.
	 *
	 * @return array
	 */
	public static function pro_bono_projects() {
		$ids = get_posts(
			array(
				'post_type'      => 'malka_project',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => self::META_PRO_BONO,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$tarifa = class_exists( 'Malka_CRM_Settings' ) ? Malka_CRM_Settings::hourly_rate() : 0;
		$out    = array();

		foreach ( $ids as $id ) {
			$asignadas = (int) self::pro_bono_hours( $id );
			$usadas    = self::minutes_for_project( $id ) / 60;
			$out[]     = array(
				'id'         => (int) $id,
				'nombre'     => get_the_title( $id ),
				'asignadas'  => $asignadas,
				'usadas'     => round( $usadas, 1 ),
				'excedida'   => ( $asignadas > 0 && $usadas > $asignadas ),
				'valorizado' => (int) round( $usadas * $tarifa ),
			);
		}

		return $out;
	}

	/**
	 * Resumen de horas donadas del mes contra el tope configurado.
	 *
	 * @return array
	 */
	public static function pro_bono_summary() {
		$proyectos = self::pro_bono_projects();
		$tope      = class_exists( 'Malka_CRM_Settings' ) ? (int) Malka_CRM_Settings::get( 'tope_donadas', 0 ) : 0;
		$tarifa    = class_exists( 'Malka_CRM_Settings' ) ? Malka_CRM_Settings::hourly_rate() : 0;

		$usadas = 0;
		foreach ( $proyectos as $p ) {
			$usadas += self::minutes_this_month( $p['id'] ) / 60;
		}
		$usadas = round( $usadas, 1 );

		return array(
			'proyectos'  => $proyectos,
			'tope'       => $tope,
			'usadas'     => $usadas,
			'excedido'   => ( $tope > 0 && $usadas > $tope ),
			'exceso'     => ( $tope > 0 && $usadas > $tope ) ? round( $usadas - $tope, 1 ) : 0,
			'valorizado' => (int) round( $usadas * $tarifa ),
			'pct'        => $tope > 0 ? min( 100, round( ( $usadas / $tope ) * 100 ) ) : 0,
		);
	}

	/* ─────────────────────────────  AJAX  ─────────────────────────────── */

	public function handle_ajax() {
		if ( ! Malka_CRM_Helpers::current_user_is_manager() ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'malka-crm' ) ), 403 );
		}

		check_ajax_referer( 'malka_crm_time', 'nonce' );

		$minutos    = isset( $_POST['minutos'] ) ? (int) $_POST['minutos'] : 0;
		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;

		$res = self::log( $minutos, $project_id );

		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'id'      => $res,
				'hoy'     => self::minutes_today(),
				'semana'  => self::minutes_this_week(),
				'texto'   => self::format_minutes( self::minutes_today() ),
			)
		);
	}

	/* ─────────────────────────────  Utilidad  ─────────────────────────── */

	/**
	 * 130 → "2 h 10 m".
	 *
	 * @param int $min Minutos.
	 */
	public static function format_minutes( $min ) {
		$min = max( 0, (int) $min );
		$h   = (int) floor( $min / 60 );
		$m   = $min % 60;

		if ( $h && $m ) {
			/* translators: 1: horas, 2: minutos */
			return sprintf( __( '%1$d h %2$d m', 'malka-crm' ), $h, $m );
		}
		if ( $h ) {
			/* translators: horas */
			return sprintf( __( '%d h', 'malka-crm' ), $h );
		}
		/* translators: minutos */
		return sprintf( __( '%d m', 'malka-crm' ), $m );
	}

	/* ─────────────────────────────  Admin  ────────────────────────────── */

	public function menu() {
		add_submenu_page(
			'malka-crm',
			__( 'Horas', 'malka-crm' ),
			__( 'Horas', 'malka-crm' ),
			Malka_CRM_Settings::cap(),
			'malka-crm-horas',
			array( $this, 'render_page' )
		);
	}

	public function render_page() {
		if ( ! current_user_can( Malka_CRM_Settings::cap() ) ) {
			wp_die( esc_html__( 'No tienes permisos para ver esta página.', 'malka-crm' ) );
		}

		self::maybe_install();

		$resumen = self::pro_bono_summary();
		$tarifa  = Malka_CRM_Settings::hourly_rate();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Horas', 'malka-crm' ) . '</h1>';

		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: hoy, 2: semana, 3: mes */
				__( 'Hoy %1$s · Esta semana %2$s · Este mes %3$s', 'malka-crm' ),
				self::format_minutes( self::minutes_today() ),
				self::format_minutes( self::minutes_this_week() ),
				self::format_minutes( self::minutes_this_month() )
			)
		) . '</p>';

		echo '<h2>' . esc_html__( 'Proyectos sin costo', 'malka-crm' ) . '</h2>';

		if ( empty( $resumen['proyectos'] ) ) {
			echo '<p>' . esc_html__( 'Ningún proyecto está marcado como sin costo todavía.', 'malka-crm' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Proyecto', 'malka-crm' ) . '</th>';
			echo '<th>' . esc_html__( 'Horas', 'malka-crm' ) . '</th>';
			echo '<th style="text-align:right">' . esc_html__( 'Valorizado', 'malka-crm' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $resumen['proyectos'] as $p ) {
				echo '<tr>';
				echo '<td>' . esc_html( $p['nombre'] ) . '</td>';
				echo '<td>' . esc_html( $p['usadas'] . ' / ' . $p['asignadas'] . ' h' );
				if ( $p['excedida'] ) {
					echo ' <strong style="color:#d13d3d">' . esc_html__( 'excedida', 'malka-crm' ) . '</strong>';
				}
				echo '</td>';
				echo '<td style="text-align:right">' . esc_html( Malka_CRM_Settings::money( $p['valorizado'] ) ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody><tfoot><tr>';
			echo '<th>' . esc_html(
				sprintf(
					/* translators: 1: usadas, 2: tope */
					__( 'Este mes: %1$s h de un tope de %2$s h', 'malka-crm' ),
					$resumen['usadas'],
					$resumen['tope']
				)
			) . '</th>';
			echo '<th></th>';
			echo '<th style="text-align:right">' . esc_html( Malka_CRM_Settings::money( $resumen['valorizado'] ) ) . '</th>';
			echo '</tr></tfoot></table>';

			if ( $resumen['excedido'] ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html(
					sprintf(
						/* translators: 1: horas de exceso, 2: monto */
						__( 'Excedido en %1$s h este mes. A tu tarifa son %2$s que no entraron.', 'malka-crm' ),
						$resumen['exceso'],
						Malka_CRM_Settings::money( (int) round( $resumen['exceso'] * $tarifa ) )
					)
				) . '</p></div>';
			}
		}

		echo '</div>';
	}
}
