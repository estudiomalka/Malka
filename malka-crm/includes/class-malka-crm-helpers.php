<?php
/**
 * Utilidades compartidas.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

class Malka_CRM_Helpers {

	const SETTINGS_KEY = 'malka_pm_settings';

	/**
	 * Ajustes por defecto.
	 *
	 * @return array
	 */
	public static function default_settings() {
		return array(
			'business_days'        => 1,
			'monthly_capacity'     => 3,
			'payment_url'          => '',
			'client_grace_days'    => 2,
			'auto_close_overdue'   => 1,
			'shift_studio_delays'  => 1,
			'accent_color'      => '#B8A792',
			'gantt_view_mode'   => 'Day',
			'invoice_render'    => 'sandbox', // sandbox | kses
			'auto_project'      => 1,
			'project_duration'  => 30,
			'portal_intro'      => __( 'Aquí puedes seguir el avance de tu proyecto en tiempo real.', 'malka-crm' ),
			// Aprobaciones.
			'approvals_enabled' => 1,
			'approve_at_100'    => 0,
			// Notificaciones.
			'admin_email'       => get_option( 'admin_email' ),
			'notify_invoice'    => 1,
			'notify_project'    => 1,
			'notify_status'     => 1,
			'notify_approval'   => 1,
			'email_footer'      => __( 'Este correo se generó automáticamente desde tu portal de cliente.', 'malka-crm' ),
			// Rondas de revisión.
			'round_limit_default' => 2,
			'rounds_message'      => __( 'Has usado todas las rondas de revisión incluidas en tu plan. Las rondas adicionales se cotizan aparte: escríbenos y te enviamos el detalle.', 'malka-crm' ),
			'rounds_cta_url'      => '',
			// Recordatorios automáticos.
			'reminders_enabled'   => 1,
			'reminder_delay'      => 3,
			'reminder_repeat'     => 4,
			'reminder_max'        => 3,
			'reminder_escalate'   => 10,
			// Venta de rondas adicionales.
			'extra_rounds_enabled' => 0,
			'extra_round_product'  => 0,
			// Plazos, atrasos y pagos.
			'autoshift_enabled'    => 1,
			'task_warn_days'       => 1,
			'payment_warn_days'    => 5,
			'notify_client_task'   => 1,
			'notify_deadline'      => 1,
			'notify_shift'         => 1,
			'notify_payment'       => 1,
			'quotes_enabled'       => 1,
		);
	}

	/**
	 * Devuelve un ajuste concreto.
	 *
	 * @param string $key     Clave.
	 * @param mixed  $default Valor por defecto.
	 * @return mixed
	 */
	public static function get_setting( $key, $default = null ) {
		$settings = wp_parse_args( (array) get_option( self::SETTINGS_KEY, array() ), self::default_settings() );
		if ( ! isset( $settings[ $key ] ) ) {
			return $default;
		}
		return $settings[ $key ];
	}

	/**
	 * Capacidad requerida para gestionar el plugin.
	 *
	 * @return string
	 */
	public static function admin_capability() {
		return apply_filters( 'malka_crm_admin_capability', 'manage_options' );
	}

	/**
	 * La ficha de un negocio, dentro del panel.
	 *
	 * Todo el trabajo pasa por /escritorio/: ningún enlace de este plugin
	 * puede dejarla en WP-Admin. Esto existe para que ese destino se escriba
	 * una sola vez; cuando estaba repetido con get_edit_post_link(), corregir
	 * unos pocos dejó otra docena apuntando al editor de WordPress.
	 *
	 * @param int $pid Negocio.
	 * @return string
	 */
	public static function panel_project_url( $pid ) {
		if ( class_exists( 'Malka_CRM_Projects' ) ) {
			return Malka_CRM_Projects::edit_url( (int) $pid );
		}

		return class_exists( 'Malka_CRM_Dashboard' ) ? Malka_CRM_Dashboard::view_url( 'negocios' ) : home_url( '/' );
	}

	/**
	 * Comprueba si el usuario actual administra el plugin.
	 *
	 * @return bool
	 */
	public static function current_user_is_manager() {
		return current_user_can( self::admin_capability() );
	}

	/**
	 * Valida una fecha en formato Y-m-d.
	 *
	 * @param string $date Fecha.
	 * @return string Fecha válida o cadena vacía.
	 */
	/**
	 * Suma días a una fecha. Si "días hábiles" está activo, salta fines de semana.
	 *
	 * @param string $date Fecha Y-m-d.
	 * @param int    $days Días a sumar (puede ser negativo).
	 * @return string
	 */
	public static function add_days( $date, $days ) {

		$date = self::sanitize_date( $date );

		if ( ! $date ) {
			$date = current_time( 'Y-m-d' );
		}

		$days = (int) $days;

		if ( 0 === $days ) {
			return $date;
		}

		$business = (bool) self::get_setting( 'business_days', 1 );

		if ( ! $business ) {
			return gmdate( 'Y-m-d', strtotime( $date ) + ( $days * DAY_IN_SECONDS ) );
		}

		$step      = $days > 0 ? 1 : -1;
		$remaining = abs( $days );
		$cursor    = strtotime( $date );

		while ( $remaining > 0 ) {
			$cursor += $step * DAY_IN_SECONDS;
			if ( (int) gmdate( 'N', $cursor ) < 6 ) {
				$remaining--;
			}
		}

		return gmdate( 'Y-m-d', $cursor );
	}

	/**
	 * Responsables posibles de una tarea.
	 *
	 * @return array
	 */
	public static function task_owners() {
		return array(
			'studio' => __( 'Malka', 'malka-crm' ),
			'client' => __( 'Cliente', 'malka-crm' ),
			'both'   => __( 'Malka y Cliente', 'malka-crm' ),
			'none'   => __( '— Ninguno —', 'malka-crm' ),
		);
	}

	/**
	 * ¿La tarea involucra al cliente? (incluye las compartidas)
	 *
	 * @param array $task Tarea.
	 * @return bool
	 */
	public static function involves_client( $task ) {
		$owner = isset( $task['owner'] ) ? $task['owner'] : 'studio';
		return in_array( $owner, array( 'client', 'both' ), true );
	}

	/**
	 * ¿La tarea involucra al estudio?
	 *
	 * @param array $task Tarea.
	 * @return bool
	 */
	public static function involves_studio( $task ) {
		$owner = isset( $task['owner'] ) ? $task['owner'] : 'studio';
		return in_array( $owner, array( 'studio', 'both' ), true );
	}

	/**
	 * Tipos de fila disponibles.
	 *
	 * @return array
	 */
	public static function task_types() {
		return array(
			'stage'      => __( 'Etapa', 'malka-crm' ),
			'task'       => __( 'Tarea', 'malka-crm' ),
			'milestone'  => __( 'Hito', 'malka-crm' ),
			'correction' => __( 'Corrección', 'malka-crm' ),
		);
	}

	public static function sanitize_date( $date ) {
		$date = sanitize_text_field( $date );
		$d    = DateTime::createFromFormat( 'Y-m-d', $date );
		if ( $d && $d->format( 'Y-m-d' ) === $date ) {
			return $date;
		}
		return '';
	}

	/**
	 * Sanitiza el array completo de tareas de un proyecto.
	 *
	 * @param array $raw Datos crudos del formulario.
	 * @return array
	 */
	public static function sanitize_tasks( $raw ) {

		$clean     = array();
		$inferidas = array();

		if ( ! is_array( $raw ) ) {
			return $clean;
		}

		$index = 0;
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$name = isset( $row['name'] ) ? sanitize_text_field( wp_unslash( $row['name'] ) ) : '';
			if ( '' === $name ) {
				continue;
			}

			$index++;

			$id = isset( $row['id'] ) ? sanitize_key( wp_unslash( $row['id'] ) ) : '';
			if ( '' === $id ) {
				$id = sanitize_key( 'item-' . $index . '-' . wp_generate_password( 4, false, false ) );
			}

			$type = isset( $row['type'] ) ? sanitize_key( $row['type'] ) : 'task';
			if ( ! in_array( $type, array( 'stage', 'task', 'milestone', 'correction' ), true ) ) {
				$type = 'task';
			}

			$start = isset( $row['start'] ) ? self::sanitize_date( wp_unslash( $row['start'] ) ) : '';
			$end   = isset( $row['end'] ) ? self::sanitize_date( wp_unslash( $row['end'] ) ) : '';

			if ( '' === $start ) {
				$start = current_time( 'Y-m-d' );
			}
			if ( '' === $end || strtotime( $end ) < strtotime( $start ) ) {
				$end = $start;
			}

			$progress = isset( $row['progress'] ) ? (int) $row['progress'] : 0;
			$progress = max( 0, min( 100, $progress ) );

			$owner = isset( $row['owner'] ) ? sanitize_key( $row['owner'] ) : 'studio';
			if ( ! in_array( $owner, array( 'studio', 'client', 'both', 'none' ), true ) ) {
				$owner = 'studio';
			}

			$dependencies = isset( $row['dependencies'] ) ? sanitize_text_field( wp_unslash( $row['dependencies'] ) ) : '';
			$dependencies = implode(
				',',
				array_filter(
					array_map( 'sanitize_key', array_map( 'trim', explode( ',', $dependencies ) ) )
				)
			);

			$clean[] = array(
				'id'           => $id,
				'name'         => $name,
				'type'         => $type,
				'stage'        => ( 'stage' === $type ) ? '' : ( isset( $row['stage'] ) ? sanitize_key( wp_unslash( $row['stage'] ) ) : '' ),
				'start'        => $start,
				'end'          => $end,
				'progress'     => $progress,
				'dependencies' => $dependencies,
				'owner'        => $owner,
				'assignee'     => isset( $row['assignee'] ) ? sanitize_text_field( wp_unslash( $row['assignee'] ) ) : '',
				'due_days'     => isset( $row['due_days'] ) ? max( 0, (int) $row['due_days'] ) : 0,
				'done_at'      => isset( $row['done_at'] ) ? sanitize_text_field( wp_unslash( $row['done_at'] ) ) : '',
				'done_by'      => ( isset( $row['done_by'] ) && 'client' === $row['done_by'] && ! empty( $row['done'] ) ) ? 'client' : '',
				'nota_cliente'    => isset( $row['nota_cliente'] ) ? sanitize_textarea_field( wp_unslash( $row['nota_cliente'] ) ) : '',
				'respuesta'       => ( isset( $row['respuesta'] ) && in_array( $row['respuesta'], array( 'no', 'nota', 'audio', 'ambas' ), true ) ) ? $row['respuesta'] : '',
				'respuesta_preg'  => isset( $row['respuesta_preg'] ) ? sanitize_text_field( wp_unslash( $row['respuesta_preg'] ) ) : '',
				'resp_audio'      => isset( $row['resp_audio'] ) ? esc_url_raw( wp_unslash( $row['resp_audio'] ) ) : '',
				'resp_audio_at'   => isset( $row['resp_audio_at'] ) ? sanitize_text_field( wp_unslash( $row['resp_audio_at'] ) ) : '',
				'es_pub'          => isset( $row['es_pub'] ) && '' !== (string) $row['es_pub'] ? ( (int) $row['es_pub'] ? 1 : 0 ) : '',
				'pub_copy'        => isset( $row['pub_copy'] ) ? sanitize_textarea_field( wp_unslash( $row['pub_copy'] ) ) : '',
				'pub_url'         => isset( $row['pub_url'] ) ? esc_url_raw( wp_unslash( $row['pub_url'] ) ) : '',
				'pub_formato'     => isset( $row['pub_formato'] ) ? sanitize_key( wp_unslash( $row['pub_formato'] ) ) : '',
				'pub_tema'        => isset( $row['pub_tema'] ) ? sanitize_text_field( wp_unslash( $row['pub_tema'] ) ) : '',
				'pub_hashtags'    => isset( $row['pub_hashtags'] ) ? sanitize_textarea_field( wp_unslash( $row['pub_hashtags'] ) ) : '',
				'pub_voz'         => isset( $row['pub_voz'] ) ? sanitize_textarea_field( wp_unslash( $row['pub_voz'] ) ) : '',
				'pub_encuesta'    => isset( $row['pub_encuesta'] ) ? sanitize_textarea_field( wp_unslash( $row['pub_encuesta'] ) ) : '',
				'pub_prueba'      => ! empty( $row['pub_prueba'] ) ? '1' : '',
				'pub_enlace_reel' => isset( $row['pub_enlace_reel'] ) ? sanitize_text_field( wp_unslash( $row['pub_enlace_reel'] ) ) : '',
				'pub_portada'     => isset( $row['pub_portada'] ) ? esc_url_raw( wp_unslash( $row['pub_portada'] ) ) : '',
				'pub_musica'      => isset( $row['pub_musica'] ) ? sanitize_text_field( wp_unslash( $row['pub_musica'] ) ) : '',
				'pub_quien'       => ( isset( $row['pub_quien'] ) && 'client' === $row['pub_quien'] ) ? 'client' : '',
				'pub_nota'        => isset( $row['pub_nota'] ) ? sanitize_textarea_field( wp_unslash( $row['pub_nota'] ) ) : '',
				'pub_check'       => isset( $row['pub_check'] ) ? implode( ',', array_filter( array_map( 'sanitize_key', is_array( $row['pub_check'] ) ? $row['pub_check'] : explode( ',', (string) $row['pub_check'] ) ) ) ) : '',
				'pub_audio'       => isset( $row['pub_audio'] ) ? esc_url_raw( wp_unslash( $row['pub_audio'] ) ) : '',
				'pub_audio_at'    => isset( $row['pub_audio_at'] ) ? sanitize_text_field( wp_unslash( $row['pub_audio_at'] ) ) : '',
				'pub_fecha'       => ( isset( $row['pub_fecha'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', (string) $row['pub_fecha'] ) ) ? substr( str_replace( 'T', ' ', (string) $row['pub_fecha'] ), 0, 16 ) : '',
				'nota_cliente_at' => isset( $row['nota_cliente_at'] ) ? sanitize_text_field( wp_unslash( $row['nota_cliente_at'] ) ) : '',
				'notes'        => isset( $row['notes'] ) ? sanitize_textarea_field( wp_unslash( $row['notes'] ) ) : '',
				'number'        => isset( $row['number'] ) ? sanitize_text_field( wp_unslash( $row['number'] ) ) : '',
				'done'           => ! empty( $row['done'] ) ? 1 : 0,
				'needs_approval' => ! empty( $row['needs_approval'] ) ? 1 : 0,
				// Una tarea privada no sale en la página que ve el cliente.
				'privada'        => ! empty( $row['privada'] ) ? 1 : 0,
				// Cada cuánto se repite: al marcarla hecha pasa a la próxima fecha.
				'repite'         => isset( $row['repite'] ) ? sanitize_key( wp_unslash( $row['repite'] ) ) : '',
				'ultima'         => isset( $row['ultima'] ) ? sanitize_text_field( wp_unslash( $row['ultima'] ) ) : '',
				'veces'          => isset( $row['veces'] ) ? (int) $row['veces'] : 0,
				'rounds'         => ( isset( $row['rounds'] ) && '' !== trim( (string) $row['rounds'] ) )
					? (string) max( 0, (int) $row['rounds'] )
					: '',
			);

			// La casilla manda sobre el porcentaje.
			$last = count( $clean );
			if ( $last ) {
				$item = $clean[ $last - 1 ];
				if ( $item['done'] ) {
					$clean[ $last - 1 ]['progress'] = 100;
					if ( ! $item['done_at'] ) {
						$clean[ $last - 1 ]['done_at'] = current_time( 'mysql' );
					}
				} elseif ( 100 === (int) $item['progress'] ) {
					$clean[ $last - 1 ]['done'] = 1;
					$inferidas[]                = $last - 1;
				}
			}
		}

		/*
		 * Una tarea con subtareas llega al 100 % sola cuando sus subtareas
		 * están listas, pero eso no la da por hecha: sólo el círculo la marca.
		 * Si no, desaparecía del portal sin que nadie la hubiera marcado.
		 */
		if ( ! empty( $inferidas ) ) {
			$madres = array();
			foreach ( $clean as $c ) {
				if ( ! empty( $c['number'] ) && 'stage' !== $c['type'] ) {
					$madres[ (string) self::number_parent( $c['number'] ) ] = true;
				}
			}
			foreach ( $inferidas as $k ) {
				if ( ! empty( $clean[ $k ]['number'] ) && isset( $madres[ (string) $clean[ $k ]['number'] ] ) ) {
					$clean[ $k ]['done'] = 0;
				}
			}
		}

		return self::recalculate_stages( $clean );
	}

	/**
	 * Recalcula etapas: fechas = min/max de sus tareas, avance = promedio.
	 * Las etapas nunca se editan a mano: siempre se derivan de sus hijas.
	 *
	 * @param array $tasks Tareas.
	 * @return array
	 */
	/**
	 * Nivel jerárquico según el número: "0" → 1, "0.4" → 2, "0.4.1" → 3.
	 *
	 * @param string $number Número.
	 * @return int 0 si no tiene número.
	 */
	/**
	 * Nombre para mostrar: antepone el número solo si el nombre no lo trae ya.
	 *
	 * @param array $task Tarea o ítem.
	 * @return string
	 */
	public static function display_name( $task ) {

		$number = isset( $task['number'] ) ? trim( (string) $task['number'] ) : '';
		$name   = isset( $task['name'] ) ? trim( (string) $task['name'] ) : '';

		if ( '' === $number ) {
			return $name;
		}

		// El nombre ya empieza por su número: no lo repitas.
		if ( 0 === strpos( $name, $number . ' ' ) || $name === $number ) {
			return $name;
		}

		return $number . ' ' . $name;
	}

	public static function number_level( $number ) {

		$number = trim( (string) $number );

		if ( '' === $number ) {
			return 0;
		}

		return count( array_filter( explode( '.', $number ), 'strlen' ) );
	}

	/**
	 * Número del padre: "0.4.1" → "0.4".
	 *
	 * @param string $number Número.
	 * @return string Cadena vacía si no tiene padre.
	 */
	public static function number_parent( $number ) {

		$parts = array_filter( explode( '.', trim( (string) $number ) ), 'strlen' );

		if ( count( $parts ) < 2 ) {
			return '';
		}

		array_pop( $parts );

		return implode( '.', $parts );
	}

	/**
	 * ¿La tarea tiene subtareas dentro del listado?
	 *
	 * @param array $tasks Listado completo.
	 * @param array $task  Tarea.
	 * @return bool
	 */
	public static function has_children( $tasks, $task ) {

		if ( empty( $task['number'] ) || ( isset( $task['type'] ) && 'stage' === $task['type'] ) ) {
			return false;
		}

		foreach ( $tasks as $candidate ) {
			// Las tareas sueltas (del estudio, rápidas) no tienen número.
			if ( empty( $candidate['number'] ) || ( isset( $candidate['type'] ) && 'stage' === $candidate['type'] ) ) {
				continue;
			}
			if ( self::number_parent( $candidate['number'] ) === $task['number'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tareas hoja: las que no tienen subtareas. Son las únicas que se promedian.
	 *
	 * @param int $project_id ID.
	 * @return array
	 */
	public static function get_leaf_tasks( $project_id ) {

		$tasks = self::get_project_tasks( $project_id );
		$out   = array();

		foreach ( $tasks as $task ) {
			if ( 'stage' === $task['type'] ) {
				continue;
			}
			if ( self::has_children( $tasks, $task ) ) {
				continue;
			}
			$out[] = $task;
		}

		/*
		 * Una etapa sin tareas adentro también es trabajo: si el negocio se
		 * arma sólo con etapas y se van marcando, el avance tiene que subir.
		 */
		foreach ( $tasks as $task ) {
			if ( 'stage' !== $task['type'] ) {
				continue;
			}
			$num   = trim( (string) ( isset( $task['number'] ) ? $task['number'] : '' ) );
			$tiene = false;
			foreach ( $tasks as $o ) {
				if ( 'stage' === $o['type'] ) {
					continue;
				}
				if ( ( ! empty( $o['stage'] ) && ! empty( $task['id'] ) && $o['stage'] === $task['id'] ) || ( '' !== $num && 0 === strpos( (string) ( isset( $o['number'] ) ? $o['number'] : '' ), $num . '.' ) ) ) {
					$tiene = true;
					break;
				}
			}
			if ( ! $tiene ) {
				$out[] = $task;
			}
		}

		return $out;
	}

	public static function recalculate_stages( $tasks ) {

		if ( ! is_array( $tasks ) ) {
			return array();
		}

		// 1) Rollup de subtareas hacia su tarea padre, de más profundo a menos.
		$levels = array();

		foreach ( $tasks as $i => $task ) {
			if ( 'stage' === $task['type'] || empty( $task['number'] ) ) {
				continue;
			}
			$levels[ $i ] = self::number_level( $task['number'] );
		}

		if ( ! empty( $levels ) ) {

			$max_level = max( $levels );

			for ( $level = $max_level; $level >= 2; $level-- ) {

				foreach ( $tasks as $i => $task ) {

					if ( 'stage' === $task['type'] || empty( $task['number'] ) ) {
						continue;
					}

					if ( self::number_level( $task['number'] ) !== $level - 1 ) {
						continue;
					}

					$children = array();

					foreach ( $tasks as $child ) {
						if ( 'stage' === $child['type'] || empty( $child['number'] ) ) {
							continue;
						}
						if ( self::number_parent( $child['number'] ) === $task['number'] ) {
							$children[] = $child;
						}
					}

					if ( empty( $children ) ) {
						continue;
					}

					$starts   = array();
					$ends     = array();
					$progress = 0;

					foreach ( $children as $child ) {
						$starts[]  = strtotime( $child['start'] );
						$ends[]    = strtotime( $child['end'] );
						$progress += ! empty( $child['done'] ) ? 100 : (int) $child['progress'];
					}

					$average = (int) round( $progress / count( $children ) );

					$tasks[ $i ]['start']    = gmdate( 'Y-m-d', min( $starts ) );
					$tasks[ $i ]['end']      = gmdate( 'Y-m-d', max( $ends ) );
					// El avance sale de sus subtareas; «hecha» lo decide el círculo.
					$tasks[ $i ]['progress'] = ! empty( $task['done'] ) ? 100 : $average;
				}
			}
		}

		foreach ( $tasks as $i => $task ) {

			if ( 'stage' !== $task['type'] ) {
				continue;
			}

			$children = array();
			foreach ( $tasks as $child ) {

				if ( 'stage' === $child['type'] || ! isset( $child['stage'] ) || $child['stage'] !== $task['id'] ) {
					continue;
				}

				// Si su padre está en la lista, ya se contó dentro de él.
				$parent_number = self::number_parent( isset( $child['number'] ) ? (string) $child['number'] : '' );

				if ( $parent_number ) {
					$has_parent = false;
					foreach ( $tasks as $maybe ) {
						if ( 'stage' !== $maybe['type'] && isset( $maybe['number'] ) && $maybe['number'] === $parent_number ) {
							$has_parent = true;
							break;
						}
					}
					if ( $has_parent ) {
						continue;
					}
				}

				$children[] = $child;
			}

			if ( empty( $children ) ) {
				$tasks[ $i ]['progress'] = 0;
				continue;
			}

			$starts   = array();
			$ends     = array();
			$progress = 0;

			foreach ( $children as $child ) {
				$starts[]  = strtotime( $child['start'] );
				$ends[]    = strtotime( $child['end'] );
				$progress += ! empty( $child['done'] ) ? 100 : (int) $child['progress'];
			}

			$tasks[ $i ]['start']    = gmdate( 'Y-m-d', min( $starts ) );
			$tasks[ $i ]['end']      = gmdate( 'Y-m-d', max( $ends ) );
			$tasks[ $i ]['progress'] = (int) round( $progress / count( $children ) );
			$tasks[ $i ]['owner']    = 'studio';
		}

		return $tasks;
	}

	/**
	 * Tareas reales (excluye etapas contenedoras).
	 *
	 * @param int $project_id ID.
	 * @return array
	 */
	public static function get_real_tasks( $project_id ) {

		$out = array();

		foreach ( self::get_project_tasks( $project_id ) as $task ) {
			if ( 'stage' !== $task['type'] ) {
				$out[] = $task;
			}
		}

		return $out;
	}

	/**
	 * Etapas del proyecto.
	 *
	 * @param int $project_id ID.
	 * @return array
	 */
	/**
	 * Etapa en curso: la primera que no está al 100%.
	 *
	 * @param int $project_id ID.
	 * @return array|null
	 */
	public static function get_current_stage( $project_id ) {

		$stages = self::get_stages( $project_id );

		foreach ( $stages as $stage ) {
			if ( 100 > (int) $stage['progress'] ) {
				return $stage;
			}
		}

		return ! empty( $stages ) ? end( $stages ) : null;
	}

	/**
	 * ¿El proyecto está terminado?
	 *
	 * @param int $project_id ID.
	 * @return bool
	 */
	public static function is_project_finished( $project_id ) {

		$status = (string) get_post_meta( $project_id, '_malka_pm_status', true );

		if ( 'entregado' === $status ) {
			return true;
		}

		return 100 === (int) self::get_project_progress( $project_id );
	}

	public static function get_stages( $project_id ) {

		$out = array();

		foreach ( self::get_project_tasks( $project_id ) as $task ) {
			if ( 'stage' === $task['type'] ) {
				$out[] = $task;
			}
		}

		return $out;
	}

	/**
	 * Devuelve una tarea por su id.
	 *
	 * @param int    $project_id ID.
	 * @param string $task_id    Id de la tarea.
	 * @return array|null
	 */
	public static function get_task( $project_id, $task_id ) {

		foreach ( self::get_project_tasks( $project_id ) as $task ) {
			if ( $task['id'] === $task_id ) {
				return $task;
			}
		}

		return null;
	}

	/**
	 * Guarda el array de tareas recalculando etapas.
	 *
	 * @param int   $project_id ID.
	 * @param array $tasks      Tareas.
	 */
	/**
	 * Actualiza campos concretos de una tarea.
	 *
	 * @param int    $project_id ID.
	 * @param string $task_id    Id de tarea.
	 * @param array  $changes    Campos a modificar.
	 * @return bool
	 */
	public static function update_task( $project_id, $task_id, $changes ) {

		$tasks = self::get_project_tasks( $project_id );
		$found = false;

		foreach ( $tasks as $i => $task ) {
			if ( isset( $task['id'] ) && $task['id'] === $task_id ) {
				$tasks[ $i ] = array_merge( $task, (array) $changes );
				$found       = true;
				break;
			}
		}

		if ( $found ) {
			self::save_tasks( $project_id, self::recalculate_stages( $tasks ) );
		}

		return $found;
	}

	public static function save_tasks( $project_id, $tasks ) {
		$antes = get_post_meta( (int) $project_id, '_malka_pm_tasks', true );
		$antes = is_array( $antes ) ? $antes : array();
		update_post_meta( (int) $project_id, '_malka_pm_tasks', self::recalculate_stages( $tasks ) );

		/*
		 * Las tareas que recién quedaron a cargo del cliente (nuevas, o que
		 * antes eran del estudio) se le avisan por correo, todas juntas.
		 */
		$previas = array();
		foreach ( $antes as $t ) {
			if ( ! empty( $t['id'] ) ) {
				$previas[ $t['id'] ] = isset( $t['owner'] ) ? $t['owner'] : 'studio';
			}
		}
		$nuevas = array();
		foreach ( (array) $tasks as $t ) {
			if ( ( isset( $t['type'] ) && 'stage' === $t['type'] ) || ! empty( $t['done'] ) || ! empty( $t['privada'] ) ) {
				continue;
			}
			if ( 'client' !== ( isset( $t['owner'] ) ? $t['owner'] : '' ) || '' === trim( (string) ( isset( $t['name'] ) ? $t['name'] : '' ) ) ) {
				continue;
			}
			$id = isset( $t['id'] ) ? $t['id'] : '';
			if ( $id && isset( $previas[ $id ] ) && 'client' === $previas[ $id ] ) {
				continue;
			}
			$nuevas[] = $t;
		}
		if ( $nuevas ) {
			/**
			 * Se le asignaron tareas nuevas al cliente en un negocio.
			 *
			 * @param int     $project_id Negocio.
			 * @param array[] $nuevas     Tareas.
			 */
			do_action( 'malka_crm_tareas_cliente', (int) $project_id, $nuevas );
		}
	}

	/**
	 * Fecha límite de una tarea del cliente (inicio + plazo, o fin).
	 *
	 * @param array $task Tarea.
	 * @return string Y-m-d
	 */
	public static function get_task_deadline( $task ) {

		if ( ! empty( $task['due_days'] ) ) {
			return gmdate( 'Y-m-d', strtotime( $task['start'] ) + ( (int) $task['due_days'] * DAY_IN_SECONDS ) );
		}

		return $task['end'];
	}

	/**
	 * Días que faltan (positivo) o de atraso (negativo) hasta la fecha límite.
	 *
	 * @param array $task Tarea.
	 * @return int
	 */
	public static function days_to_deadline( $task ) {

		$deadline = strtotime( self::get_task_deadline( $task ) . ' 23:59:59' );
		$now      = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp

		return (int) floor( ( $deadline - $now ) / DAY_IN_SECONDS );
	}

	public static function get_project_tasks( $project_id ) {
		$tasks = get_post_meta( (int) $project_id, '_malka_pm_tasks', true );
		if ( ! is_array( $tasks ) ) {
			return array();
		}

		/*
		 * Las tareas guardadas antes de que existiera una clave no la traen, y
		 * quien las lea con $t['privada'] recibe un aviso de PHP. Se rellenan
		 * acá, que es por donde pasan todas.
		 */
		foreach ( $tasks as $i => $t ) {
			if ( ! is_array( $t ) ) {
				unset( $tasks[ $i ] );
				continue;
			}

			if ( ! isset( $t['privada'] ) ) {
				$tasks[ $i ]['privada'] = 0;
			}

			// Las que vinieron de una plantilla antigua traían el número
			// pegado al nombre («2.1 Publicación 1»): se le quita al leer.
			$num = isset( $t['number'] ) ? trim( (string) $t['number'] ) : '';
			if ( '' !== $num && isset( $t['name'] ) && 0 === strpos( (string) $t['name'], $num . ' ' ) ) {
				$tasks[ $i ]['name'] = trim( substr( (string) $t['name'], strlen( $num ) + 1 ) );
			}
		}

		return array_values( $tasks );
	}

	/**
	 * Calcula el % de avance global de un proyecto.
	 *
	 * @param int $project_id ID del proyecto.
	 * @return int
	 */
	/**
	 * Estadísticas del proyecto: duración total y días de atraso por parte.
	 *
	 * @param int $project_id ID.
	 * @return array
	 */
	public static function get_project_stats( $project_id ) {

		$tasks = self::get_leaf_tasks( $project_id );

		$stats = array(
			'total_days'   => 0,
			'elapsed_days' => 0,
			'start'        => '',
			'end'          => '',
			'delay_client' => 0,
			'delay_studio' => 0,
			'done'         => 0,
			'pending'      => 0,
			'progress'     => self::get_project_progress( $project_id ),
		);

		if ( empty( $tasks ) ) {
			return $stats;
		}

		$starts = array();
		$ends   = array();
		$today  = current_time( 'Y-m-d' );

		foreach ( $tasks as $task ) {

			if ( ! empty( $task['start'] ) ) {
				$starts[] = strtotime( $task['start'] );
			}
			if ( ! empty( $task['end'] ) ) {
				$ends[] = strtotime( $task['end'] );
			}

			$is_done = ! empty( $task['done'] ) || ( isset( $task['progress'] ) && 100 === (int) $task['progress'] );

			if ( $is_done ) {
				$stats['done']++;
			} else {
				$stats['pending']++;

				$late = (int) floor( ( strtotime( $today ) - strtotime( $task['end'] ) ) / DAY_IN_SECONDS );

				if ( $late > 0 ) {
					if ( self::involves_client( $task ) ) {
						$stats['delay_client'] += $late;
					}
					if ( self::involves_studio( $task ) ) {
						$stats['delay_studio'] += $late;
					}
				}
			}
		}

		if ( $starts && $ends ) {
			$stats['start']      = gmdate( 'Y-m-d', min( $starts ) );
			$stats['end']        = gmdate( 'Y-m-d', max( $ends ) );
			$stats['total_days'] = (int) round( ( max( $ends ) - min( $starts ) ) / DAY_IN_SECONDS ) + 1;

			$elapsed              = (int) round( ( strtotime( $today ) - min( $starts ) ) / DAY_IN_SECONDS ) + 1;
			$stats['elapsed_days'] = max( 0, min( $elapsed, $stats['total_days'] ) );
		}

		return $stats;
	}

	public static function get_project_progress( $project_id ) {

		$tasks = self::get_leaf_tasks( $project_id );

		if ( empty( $tasks ) ) {
			return 0;
		}

		$total = 0;
		foreach ( $tasks as $task ) {
			if ( ! empty( $task['done'] ) ) {
				$total += 100;
			} else {
				$total += isset( $task['progress'] ) ? (int) $task['progress'] : 0;
			}
		}

		return (int) round( $total / count( $tasks ) );
	}

	/**
	 * Etiqueta del responsable de una tarea.
	 *
	 * @param array $task Tarea.
	 * @return string
	 */
	public static function owner_label( $task ) {

		if ( isset( $task['owner'] ) && 'client' === $task['owner'] ) {
			return __( 'Tú', 'malka-crm' );
		}

		return ! empty( $task['assignee'] ) ? $task['assignee'] : __( 'Estudio', 'malka-crm' );
	}

	public static function project_statuses() {
		return array(
			'briefing'   => __( 'Briefing', 'malka-crm' ),
			'en_proceso' => __( 'En proceso', 'malka-crm' ),
			'revision'   => __( 'En revisión', 'malka-crm' ),
			'entregado'  => __( 'Entregado', 'malka-crm' ),
			'pausado'    => __( 'Pausado', 'malka-crm' ),
		);
	}

	/**
	 * Devuelve los proyectos de un cliente.
	 *
	 * @param int $user_id ID de usuario.
	 * @return WP_Post[]
	 */
	public static function get_client_projects( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return array();
		}

		return get_posts(
			array(
				'post_type'      => 'malka_project',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 50,
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
	 * Verifica si un usuario es el dueño del proyecto.
	 *
	 * @param int $project_id ID del proyecto.
	 * @param int $user_id    ID del usuario.
	 * @return bool
	 */
	public static function user_owns_project( $project_id, $user_id ) {
		$owner = (int) get_post_meta( (int) $project_id, '_malka_pm_client_id', true );
		return $owner && $owner === (int) $user_id;
	}
}
