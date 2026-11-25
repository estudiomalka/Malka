<?php
/**
 * Los mensajes que les llegan a los clientes.
 *
 * Hasta ahora cada texto estaba escrito dentro del código que lo mandaba:
 * el recordatorio de pago en Recordatorios, el de cobranza en el escritorio,
 * el de entrega en la cotización. Cambiar una coma era tocar PHP.
 *
 * Acá viven todos juntos, con sus campos entre llaves. Lo que ella escriba se
 * guarda en los ajustes; lo que deje en blanco usa el texto de fábrica, que
 * sigue siendo el de siempre. Así, un mensaje sin tocar no cambia, y uno
 * tocado no se pierde al actualizar el plugin.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Catálogo, relleno y guardado de los mensajes al cliente.
 */
class Malka_CRM_Messages {

	/** Dónde viven los textos propios dentro de los ajustes. */
	const AJUSTE = 'mensajes';

	/** Las plantillas que ella creó duplicando otra. */
	const OPT_PROPIAS = 'malka_crm_mensajes_propios';

	/**
	 * Lo que se puede escribir de un mensaje.
	 *
	 * «pre» es el antetítulo chico y dorado, «titulo» el nombre grande: los
	 * dos de arriba del correo.
	 */
	const CAMPOS = array( 'asunto', 'pre', 'titulo', 'boton', 'interes', 'cuerpo' );

	/**
	 * Los campos que se llevan su frase cuando esta vez no hay nada que poner.
	 *
	 * Un campo normal vacío deja un hueco y la frase que lo rodea aguanta:
	 * «Vence el {fecha}» sin fecha queda coja pero se entiende. El monto del
	 * interés no: «Al día de hoy se suma  de interés por atraso, así que el
	 * total queda en $304.» no es una frase coja, es una frase falsa —dice
	 * que hay interés cuando no lo hay—.
	 *
	 * Por eso, cuando no hay interés que cobrar, se va la oración entera
	 * donde ella lo haya puesto, sin que tenga que saber nada de {interes}.
	 */
	const FRASEADOS = array( 'interes_monto' );

	/**
	 * Todos los mensajes que se le mandan a un cliente.
	 *
	 * Cada uno trae su texto de fábrica, qué campos acepta y un ejemplo con
	 * el que se ve la vista previa sin tener que mandar nada.
	 *
	 * @return array
	 */
	public static function plantillas() {
		/*
		 * El plazo del saldo es un ajuste suyo, así que el ejemplo lo lee de
		 * ahí en vez de llevarlo escrito. Antes decía «10 días hábiles»
		 * pasara lo que pasara en Configuración, y al mirar la plantilla
		 * parecía que ese era el plazo de verdad. Una vista previa que miente
		 * es peor que no tenerla.
		 */
		$habiles = class_exists( 'Malka_CRM_Settings' ) ? (int) Malka_CRM_Settings::get( 'saldo_habiles', 3 ) : 3;
		$habiles = max( 0, $habiles );
		$plazo   = sprintf(
			/* translators: días hábiles */
			_n( '%d día hábil', '%d días hábiles', $habiles, 'malka-crm' ),
			$habiles
		);

		$plantillas = array(

			'cobro_vencido' => array(
				'rotulo'  => __( 'Cobro de una cuota vencida', 'malka-crm' ),
				'pre'     => __( 'Pago pendiente', 'malka-crm' ),
				'boton'   => __( 'Pagar ahora', 'malka-crm' ),
				'interes' => __( 'Al día de hoy se suma {interes_monto} de interés por atraso, así que el total queda en {total}.', 'malka-crm' ),
				'titulo'  => __( '{cliente}', 'malka-crm' ),
				'ayuda'   => __( 'El que copias en Cobranza para pegar en WhatsApp, y el que sale al apretar «Avisar» en una cuota atrasada.', 'malka-crm' ),
				'donde'   => __( 'Cobranza · Copiar mensaje y Avisar', 'malka-crm' ),
				'asunto'  => __( '{cuota} pendiente — Cotización N° {numero}', 'malka-crm' ),
				'cuerpo'  => __(
					"Te escribo por {cuota} de la cotización {numero}. Venció el {fecha}, hace {atraso}.{interes}\n\n---\n## Lo que queda pendiente\n- {cuota}: {monto}\n- Venció el {fecha}\n- Total a hoy: {total}\n---\n\n¿Me confirmas cuándo lo puedes transferir?\n\n{enlace}\n\nSaludos,\n**{estudio}**",
					'malka-crm'
				),
				'ejemplo' => array(
					'cliente' => 'Alejandro',
					'cuota'   => 'Cuota 2',
					'numero'  => '613',
					'monto'   => '$304',
					'total'   => '$307',
					'fecha'   => '2 de septiembre',
					'atraso'  => '44 días',
					'dias'    => '44',
					'interes_monto' => '$3',
					'interes' => ' Al día de hoy se suma $3 de interés por atraso, así que el total queda en $307.',
					'enlace'  => 'https://estudiomalka.cl/cotizacion-613/',
					'estudio' => 'malka.',
				),
			),

			'cobro_proximo' => array(
				'rotulo'  => __( 'Aviso de una cuota que vence pronto', 'malka-crm' ),
				'pre'     => __( 'Un recordatorio', 'malka-crm' ),
				'boton'   => __( 'Ver y pagar', 'malka-crm' ),
				'interes' => '',
				'titulo'  => __( '{cliente}', 'malka-crm' ),
				'ayuda'   => __( 'El recordatorio amable, antes de que se pase la fecha.', 'malka-crm' ),
				'donde'   => __( 'Cobranza y los avisos automáticos', 'malka-crm' ),
				'asunto'  => __( '{cuota} de la cotización N° {numero} vence pronto', 'malka-crm' ),
				'cuerpo'  => __(
					"Te recuerdo que se viene el vencimiento de {cuota} de la cotización {numero}. Cualquier cosa me avisas.\n\n---\n## Lo que vence\n- {cuota}: {monto}\n- Vence el {fecha}\n---\n\n{enlace}\n\nSaludos,\n**{estudio}**",
					'malka-crm'
				),
				'ejemplo' => array(
					'cliente' => 'Viviana',
					'cuota'   => 'Cuota 3',
					'numero'  => '608',
					'monto'   => '$178.504',
					'total'   => '$178.504',
					'fecha'   => '14 de noviembre',
					'faltan'  => '29 días',
					'dias'    => '29',
					'interes' => '',
					'enlace'  => 'https://estudiomalka.cl/cotizacion-608/',
					'estudio' => 'malka.',
				),
			),

			'entrega' => array(
				'rotulo'  => __( 'Aviso de que el proyecto está entregado', 'malka-crm' ),
				'pre'     => __( 'Tu proyecto está listo', 'malka-crm' ),
				'boton'   => __( 'Ver y pagar el saldo', 'malka-crm' ),
				'interes' => '',
				'titulo'  => __( '{cliente}', 'malka-crm' ),
				'ayuda'   => __( 'Sale solo al poner la cotización en «entregado». Es el que le dice que el saldo empieza a correr, así que conviene que quede claro cuánto y hasta cuándo.', 'malka-crm' ),
				'donde'   => __( 'Al marcar una cotización como entregada', 'malka-crm' ),
				'asunto'  => __( 'Tu proyecto está entregado — Cotización N° {numero}', 'malka-crm' ),
				'cuerpo'  => __(
					"{proyecto} quedó entregado el {fecha}. Gracias por la confianza.\n\n---\n## El saldo\n- Monto: {monto}\n- Plazo: {plazo}\n- Hasta el {vence}\n---\n\n{enlace}\n\nSaludos,\n**{estudio}**",
					'malka-crm'
				),
				'ejemplo' => array(
					'cliente'  => 'Magdalena',
					'numero'   => '613',
					'proyecto' => 'Identidad de marca y sitio web',
					'fecha'    => '16 de octubre',
					'monto'    => '$1.190.000',
					'vence'    => '30 de octubre',
					'plazo'    => $plazo,
					'enlace'   => 'https://estudiomalka.cl/cotizacion-613/',
					'estudio'  => 'malka.',
				),
			),

			'portal' => array(
				'rotulo'  => __( 'Invitación al portal del cliente', 'malka-crm' ),
				'pre'     => __( 'Hola', 'malka-crm' ),
				'boton'   => __( 'Entrar a mi página', 'malka-crm' ),
				'interes' => '',
				'titulo'  => __( '{cliente}', 'malka-crm' ),
				'ayuda'   => __( 'El correo con la dirección y el código, que sale al apretar «Activar portal cliente» en su ficha.', 'malka-crm' ),
				'donde'   => __( 'Clientes · Activar portal cliente', 'malka-crm' ),
				'asunto'  => __( 'Tu página de cliente en {estudio}', 'malka-crm' ),
				'cuerpo'  => __(
					"Ya puedes entrar a tu página en {estudio}. Ahí están tus cotizaciones, el avance de cada proyecto, tus documentos y lo que quede por pagar.\n\n---\n## Tu código de acceso\n**{codigo}**\nSe pide una vez por navegador y queda guardado un mes.\n---\n\n{enlace}\n\nCualquier duda, escríbenos a {correo}\n\nSaludos,\n**{estudio}**",
					'malka-crm'
				),
				'ejemplo' => array(
					'cliente' => 'Magdalena',
					'estudio' => 'malka.',
					'enlace'  => 'https://estudiomalka.cl/ejemplo-cliente',
					'codigo'  => '0000',
					'correo'  => 'hola@estudiomalka.cl',
				),
			),

			'firma_aviso' => array(
				'rotulo'  => __( 'Aviso de firma (te llega a ti)', 'malka-crm' ),
				'pre'     => __( 'Cotización firmada', 'malka-crm' ),
				'boton'   => __( 'Ver cotización', 'malka-crm' ),
				'interes' => '',
				'titulo'  => __( '{cliente}', 'malka-crm' ),
				'ayuda'   => __( 'Te llega a ti cuando un cliente acepta y firma. Trae el respaldo de la firma electrónica: quién, cuándo, desde qué IP y la huella del documento.', 'malka-crm' ),
				'donde'   => __( 'Al firmar el cliente una cotización', 'malka-crm' ),
				'asunto'  => __( 'Firmaron la cotización N° {numero} · {cliente}', 'malka-crm' ),
				'cuerpo'  => __(
					"{cliente} acaba de aceptar y firmar la cotización {numero}.\n\n---\n## La cotización\n- Proyecto: {proyecto}\n- Total: {total}\n---\n\n---\n## Respaldo de la firma\n- Nombre: {cliente}\n- RUT: {rut}\n- Correo: {correo}\n- Fecha: {fecha}, {hora}\n- IP: {ip}\n- Navegador: {navegador}\n- Huella del documento: {huella}\n---\n\n---\n## Lo que viene por cobrar\n{cobros}\n---\n\n{enlace}",
					'malka-crm'
				),
				'ejemplo' => array(
					'cliente'   => 'Belén Muñoz Socha',
					'rut'       => '12.345.678-9',
					'correo'    => 'cliente@correo.cl',
					'numero'    => '615',
					'proyecto'  => 'Identidad de marca',
					'total'     => '$1.190.000',
					'fecha'     => '17 de octubre de 2026',
					'hora'      => '12:07',
					'ip'        => '190.22.145.8',
					'navegador' => 'Safari en iPhone',
					'huella'    => '9F2C41A7…5B0E7D13',
					'cobros'    => "- Anticipo: $714.000 · 27 de octubre\n- Saldo: $476.000 · al entregar",
					'enlace'    => 'https://estudiomalka.cl/cotizacion-615/',
					'estudio'   => 'malka.',
				),
			),

			'comunicado' => array(
				'rotulo'  => __( 'Comunicado a clientes', 'malka-crm' ),
				'pre'     => __( 'Novedades', 'malka-crm' ),
				'boton'   => __( 'Escríbenos', 'malka-crm' ),
				'interes' => '',
				'titulo'  => __( '{cliente}', 'malka-crm' ),
				'ayuda'   => __( 'El correo que mandas desde Clientes a los que elijas (por ejemplo, a todos los que hicieron una web contigo). Cada uno lo recibe por separado, con su nombre.', 'malka-crm' ),
				'donde'   => __( 'Clientes · Escribir a clientes', 'malka-crm' ),
				'asunto'  => __( 'Una novedad que te puede interesar — {estudio}', 'malka-crm' ),
				'cuerpo'  => __(
					"Te escribimos porque trabajamos juntos y hay algo que te conviene saber.\n\n(Escribe acá la novedad.)\n\nSi quieres revisarlo con nosotros, respóndenos este correo o escríbenos.\n\n{enlace}\n\nSaludos,\n**{estudio}**",
					'malka-crm'
				),
				'ejemplo' => array(
					'cliente' => 'Magdalena',
					'enlace'  => 'mailto:hola@estudiomalka.cl',
					'estudio' => 'malka.',
				),
			),

			'tarea_asignada' => array(
				'rotulo'  => __( 'Aviso de una tarea nueva para el cliente', 'malka-crm' ),
				'pre'     => __( 'Te toca a ti', 'malka-crm' ),
				'boton'   => __( 'Ver mis tareas', 'malka-crm' ),
				'interes' => '',
				'titulo'  => __( '{cliente}', 'malka-crm' ),
				'ayuda'   => __( 'Sale solo cuando en un negocio le asignas una tarea al cliente. Si asignas varias de una vez, van todas en un solo correo.', 'malka-crm' ),
				'donde'   => __( 'Al asignarle una tarea al cliente', 'malka-crm' ),
				'asunto'  => __( 'Tienes una tarea en tu proyecto {proyecto}', 'malka-crm' ),
				'cuerpo'  => __(
					"Para seguir avanzando con {proyecto} necesito que me ayudes con esto:\n\n---\n## Lo que te toca\n{tareas}\n---\n\nCuando lo tengas, márcalo como listo en tu portal o respóndeme este correo.\n\n{enlace}\n\nSaludos,\n**{estudio}**",
					'malka-crm'
				),
				'ejemplo' => array(
					'cliente'  => 'Magdalena',
					'proyecto' => 'Identidad de marca',
					'tareas'   => "- Enviar referencias de marcas que te gusten · para el 22 de octubre\n- Aprobar la paleta de colores · para el 25 de octubre",
					'enlace'   => 'https://estudiomalka.cl/ejemplo-cliente/',
					'estudio'  => 'malka.',
				),
			),

			'tarea_por_vencer' => array(
				'rotulo'  => __( 'Recordatorio de una tarea del cliente', 'malka-crm' ),
				'pre'     => __( 'Un recordatorio', 'malka-crm' ),
				'boton'   => __( 'Ver mis tareas', 'malka-crm' ),
				'interes' => '',
				'titulo'  => __( '{cliente}', 'malka-crm' ),
				'ayuda'   => __( 'Sale solo unos días antes de que venza una tarea a cargo del cliente, y cuando aprietas «Recordar» en su ficha.', 'malka-crm' ),
				'donde'   => __( 'Antes de que venza una tarea del cliente', 'malka-crm' ),
				'asunto'  => __( 'Recordatorio: {tarea} — {proyecto}', 'malka-crm' ),
				'cuerpo'  => __(
					"Te recuerdo que tienes pendiente esto en {proyecto}:\n\n---\n## Lo que te toca\n- {tarea}\n- Vence el {fecha} ({faltan})\n---\n\nSi se atrasa, el calendario del proyecto se corre la misma cantidad de días.\n\n{enlace}\n\nSaludos,\n**{estudio}**",
					'malka-crm'
				),
				'ejemplo' => array(
					'cliente'  => 'Magdalena',
					'tarea'    => 'Enviar referencias de marcas',
					'proyecto' => 'Identidad de marca',
					'fecha'    => '22 de octubre',
					'faltan'   => 'quedan 2 días',
					'enlace'   => 'https://estudiomalka.cl/ejemplo-cliente/',
					'estudio'  => 'malka.',
				),
			),

			'tarea_atrasada' => array(
				'rotulo'  => __( 'Aviso de una tarea del cliente atrasada', 'malka-crm' ),
				'pre'     => __( 'Quedó pendiente', 'malka-crm' ),
				'boton'   => __( 'Completar ahora', 'malka-crm' ),
				'interes' => '',
				'titulo'  => __( '{cliente}', 'malka-crm' ),
				'ayuda'   => __( 'Sale solo cuando una tarea a cargo del cliente se pasa de la fecha.', 'malka-crm' ),
				'donde'   => __( 'Cuando se atrasa una tarea del cliente', 'malka-crm' ),
				'asunto'  => __( 'Tu tarea está atrasada — {proyecto}', 'malka-crm' ),
				'cuerpo'  => __(
					"La tarea **{tarea}** de {proyecto} venció el {fecha} y lleva {atraso} de atraso.\n\nEl calendario del proyecto se está corriendo mientras tanto. Apenas la completes, retomamos el ritmo.\n\n{enlace}\n\nSaludos,\n**{estudio}**",
					'malka-crm'
				),
				'ejemplo' => array(
					'cliente'  => 'Magdalena',
					'tarea'    => 'Aprobar la paleta de colores',
					'proyecto' => 'Identidad de marca',
					'fecha'    => '15 de octubre',
					'atraso'   => '3 días',
					'enlace'   => 'https://estudiomalka.cl/ejemplo-cliente/',
					'estudio'  => 'malka.',
				),
			),

			'firma_copia' => array(
				'rotulo'  => __( 'Copia de la firma (le llega al cliente)', 'malka-crm' ),
				'pre'     => __( 'Gracias por tu confianza', 'malka-crm' ),
				'boton'   => __( 'Ver mi cotización', 'malka-crm' ),
				'interes' => '',
				'titulo'  => __( '{cliente}', 'malka-crm' ),
				'ayuda'   => __( 'Le llega al cliente apenas firma. Es su comprobante: le deja una copia de lo que aceptó y con qué datos quedó registrada la firma.', 'malka-crm' ),
				'donde'   => __( 'Al firmar el cliente una cotización', 'malka-crm' ),
				'asunto'  => __( 'Firmaste la cotización N° {numero} — {estudio}', 'malka-crm' ),
				'cuerpo'  => __(
					"Recibimos tu aceptación de la cotización {numero}. Este correo es tu comprobante.\n\n---\n## Lo que aceptaste\n- Proyecto: {proyecto}\n- Total: {total}\n---\n\n---\n## Datos de tu firma\n- Nombre: {cliente}\n- RUT: {rut}\n- Fecha: {fecha}, {hora}\n- IP: {ip}\n- Huella del documento: {huella}\n---\n\nFirmaste electrónicamente (firma electrónica simple, Ley 19.799). Si no fuiste tú, o algo no corresponde, respóndenos este correo.\n\n{enlace}\n\nSaludos,\n**{estudio}**",
					'malka-crm'
				),
				'ejemplo' => array(
					'cliente'   => 'Belén Muñoz Socha',
					'rut'       => '12.345.678-9',
					'numero'    => '615',
					'proyecto'  => 'Identidad de marca',
					'total'     => '$1.190.000',
					'fecha'     => '17 de octubre de 2026',
					'hora'      => '12:07',
					'ip'        => '190.22.145.8',
					'huella'    => '9F2C41A7…5B0E7D13',
					'enlace'    => 'https://estudiomalka.cl/cotizacion-615/',
					'estudio'   => 'malka.',
				),
			),
		);

		// Las que ella duplicó: heredan los campos y el diseño de la original.
		foreach ( self::propias() as $k => $pr ) {
			$base = isset( $plantillas[ $pr['base'] ] ) ? $plantillas[ $pr['base'] ] : ( isset( $plantillas['comunicado'] ) ? $plantillas['comunicado'] : reset( $plantillas ) );
			$plantillas[ $k ] = array_merge(
				$base,
				array(
					'rotulo' => $pr['rotulo'],
					'donde'  => __( 'Plantilla tuya · para escribir a clientes', 'malka-crm' ),
					'ayuda'  => __( 'Es una copia: no sale sola. La eliges al escribirles a tus clientes desde Clientes → Escribir a clientes.', 'malka-crm' ),
					'propia' => true,
				),
				array_intersect_key( $pr, array_flip( self::CAMPOS ) )
			);
		}

		/**
		 * Permite agregar o cambiar mensajes desde fuera.
		 *
		 * @param array $plantillas Catálogo.
		 */
		return apply_filters( 'malka_crm_mensajes', $plantillas );
	}

	/**
	 * Las plantillas que ella creó.
	 *
	 * @return array clave => array( rotulo, base, asunto, pre, titulo, boton, interes, cuerpo )
	 */
	public static function propias() {
		$v = get_option( self::OPT_PROPIAS, array() );
		return is_array( $v ) ? $v : array();
	}

	/**
	 * Duplica una plantilla con lo que dice hoy.
	 *
	 * @param string $clave Plantilla.
	 * @return string La clave nueva, o ''.
	 */
	public static function duplicar( $clave ) {
		$todas = self::plantillas();
		if ( ! isset( $todas[ $clave ] ) ) {
			return '';
		}
		$hoy   = self::texto( $clave );
		$prop  = self::propias();
		$nueva = 'propia_' . substr( md5( uniqid( '', true ) ), 0, 8 );
		$prop[ $nueva ] = array_merge(
			array(
				/* translators: nombre de la plantilla */
				'rotulo' => sprintf( __( 'Copia de %s', 'malka-crm' ), $todas[ $clave ]['rotulo'] ),
				'base'   => ! empty( $todas[ $clave ]['propia'] ) && isset( $prop[ $clave ]['base'] ) ? $prop[ $clave ]['base'] : $clave,
			),
			$hoy
		);
		update_option( self::OPT_PROPIAS, $prop, false );
		return $nueva;
	}

	/**
	 * Borra una plantilla suya, o devuelve una de fábrica a su texto original.
	 *
	 * @param string $clave Plantilla.
	 * @return bool
	 */
	public static function eliminar( $clave ) {
		$prop = self::propias();
		if ( isset( $prop[ $clave ] ) ) {
			unset( $prop[ $clave ] );
			update_option( self::OPT_PROPIAS, $prop, false );
		}
		// Lo que haya escrito encima, en cualquier caso, se va.
		$s = get_option( Malka_CRM_Settings::OPTION, array() );
		if ( is_array( $s ) && isset( $s[ self::AJUSTE ][ $clave ] ) ) {
			unset( $s[ self::AJUSTE ][ $clave ] );
			update_option( Malka_CRM_Settings::OPTION, $s );
		}
		return true;
	}

	/**
	 * Cambia el nombre de una plantilla suya.
	 *
	 * @param string $clave  Plantilla.
	 * @param string $nombre Nombre.
	 */
	public static function renombrar( $clave, $nombre ) {
		$prop   = self::propias();
		$nombre = trim( sanitize_text_field( (string) $nombre ) );
		if ( isset( $prop[ $clave ] ) && '' !== $nombre ) {
			$prop[ $clave ]['rotulo'] = $nombre;
			update_option( self::OPT_PROPIAS, $prop, false );
		}
	}

	/* ─────────────────  Los textos sueltos del documento  ─────────────── */

	/** Dónde viven los textos del documento que ella haya cambiado. */
	const AJUSTE_TEXTOS = 'textos';

	/**
	 * Las frases que el cliente lee en la cotización y en su portal.
	 *
	 * No son correos: son los rótulos y las explicaciones del documento. Como
	 * los escribe ella y no un programador, viven acá y no dentro del código
	 * que los pinta.
	 *
	 * @return array
	 */
	public static function textos() {
		$textos = array(

			'pago_titulo' => array(
				'rotulo' => __( 'Título de la sección de pago', 'malka-crm' ),
				'donde'  => __( 'Cotización, cuando elige qué pagar', 'malka-crm' ),
				'valor'  => __( 'Cómo deseas pagar', 'malka-crm' ),
			),

			'pago_transferencia' => array(
				'rotulo' => __( 'La opción de transferencia', 'malka-crm' ),
				'donde'  => __( 'Cotización, al lado del punto de transferencia', 'malka-crm' ),
				'valor'  => __( 'Transferencia bancaria (sin comisión)', 'malka-crm' ),
			),

			'transferir_monto' => array(
				'rotulo' => __( 'Rótulo del monto a transferir', 'malka-crm' ),
				'donde'  => __( 'Cotización, sobre los datos bancarios', 'malka-crm' ),
				'valor'  => __( 'Monto a transferir:', 'malka-crm' ),
			),

			'aviso_transferencia' => array(
				'rotulo' => __( 'Qué decirle para que avise la transferencia', 'malka-crm' ),
				'donde'  => __( 'Cotización, sobre el botón de aviso', 'malka-crm' ),
				'valor'  => __( 'Cuando hayas transferido, avísanos haciendo clic en el botón «Transferencia realizada» para confirmarlo en la cuenta bancaria.', 'malka-crm' ),
			),

			'aviso_boton' => array(
				'rotulo' => __( 'El botón para avisar la transferencia', 'malka-crm' ),
				'donde'  => __( 'Cotización, bajo los datos bancarios', 'malka-crm' ),
				'valor'  => __( 'Transferencia realizada', 'malka-crm' ),
			),

			'aviso_gracias' => array(
				'rotulo' => __( 'Lo que ve después de avisar', 'malka-crm' ),
				'donde'  => __( 'Cotización, cuando ya avisó', 'malka-crm' ),
				'valor'  => __( 'Gracias: nos llegó tu aviso. Lo confirmamos en cuanto veamos el abono en la cuenta.', 'malka-crm' ),
			),

			'pago_tarjeta' => array(
				'rotulo' => __( 'El botón de pago con tarjeta', 'malka-crm' ),
				'donde'  => __( 'Cotización, al elegir tarjeta', 'malka-crm' ),
				'valor'  => __( 'Proceder al pago seguro', 'malka-crm' ),
			),

			'firma_titulo' => array(
				'rotulo' => __( 'Título de la firma', 'malka-crm' ),
				'donde'  => __( 'Cotización, antes de firmar', 'malka-crm' ),
				'valor'  => __( 'Aceptación y firma digital', 'malka-crm' ),
			),

			'firma_texto' => array(
				'rotulo' => __( 'Qué le explicas antes de firmar', 'malka-crm' ),
				'donde'  => __( 'Cotización, bajo el título de la firma', 'malka-crm' ),
				'valor'  => __( 'Para proceder con el pago, ingresa tus datos y confirma la aceptación de esta cotización. Una vez firmada, los datos quedan bloqueados.', 'malka-crm' ),
			),

			'firma_legal' => array(
				'rotulo' => __( 'Aviso legal bajo el botón de firmar', 'malka-crm' ),
				'donde'  => __( 'Cotización, debajo de «Firmar y aceptar»', 'malka-crm' ),
				'valor'  => __( 'Al firmar se registran tu nombre, RUT, fecha y hora, dirección IP y navegador, junto con una copia de esta cotización, como respaldo de tu firma electrónica simple (Ley 19.799). Te enviaremos un comprobante por correo.', 'malka-crm' ),
			),

			'caducada' => array(
				'rotulo' => __( 'Cuando la cotización ya venció', 'malka-crm' ),
				'donde'  => __( 'Cotización caducada', 'malka-crm' ),
				'valor'  => __( 'Esta cotización ha caducado. El pago ya no está disponible.', 'malka-crm' ),
			),
		);

		/**
		 * Permite agregar o cambiar textos del documento desde fuera.
		 *
		 * @param array $textos Catálogo.
		 */
		return apply_filters( 'malka_crm_textos', $textos );
	}

	/**
	 * Un texto del documento, el suyo o el de fábrica.
	 *
	 * @param string $clave Texto.
	 * @return string
	 */
	public static function texto_doc( $clave ) {
		$todos = self::textos();

		if ( ! isset( $todos[ $clave ] ) ) {
			return '';
		}

		$mios = class_exists( 'Malka_CRM_Settings' ) ? Malka_CRM_Settings::get( self::AJUSTE_TEXTOS, array() ) : array();
		$mios = is_array( $mios ) ? $mios : array();

		$mio = isset( $mios[ $clave ] ) ? trim( (string) $mios[ $clave ] ) : '';

		return '' !== $mio ? $mio : $todos[ $clave ]['valor'];
	}

	/**
	 * Deja limpios los textos que vienen del formulario.
	 *
	 * Igual que con los correos: el que quedó idéntico al de fábrica no se
	 * guarda, para que una mejora futura del texto original le llegue.
	 *
	 * @param array $crudo Lo que llegó, ya sin barras.
	 * @return array
	 */
	public static function sanear_textos( $crudo ) {
		$fuera = array();

		if ( ! is_array( $crudo ) ) {
			return $fuera;
		}

		foreach ( self::textos() as $clave => $fab ) {
			if ( ! isset( $crudo[ $clave ] ) ) {
				continue;
			}

			$v = trim( sanitize_text_field( $crudo[ $clave ] ) );

			if ( '' !== $v && $v !== $fab['valor'] ) {
				$fuera[ $clave ] = $v;
			}
		}

		return $fuera;
	}

	/**
	 * Los campos que acepta un mensaje, sacados de su propio ejemplo.
	 *
	 * Así no hay dos listas que mantener: el ejemplo es la lista.
	 *
	 * @param string $clave Mensaje.
	 * @return string[]
	 */
	public static function campos( $clave ) {
		$todas = self::plantillas();

		return isset( $todas[ $clave ] ) ? array_keys( $todas[ $clave ]['ejemplo'] ) : array();
	}

	/* ─────────────────────────────  Guardado  ─────────────────────────── */

	/**
	 * Lo que ella escribió, mensaje por mensaje.
	 *
	 * @return array
	 */
	public static function guardados() {
		$v = class_exists( 'Malka_CRM_Settings' ) ? Malka_CRM_Settings::get( self::AJUSTE, array() ) : array();

		return is_array( $v ) ? $v : array();
	}

	/**
	 * El asunto y el cuerpo que manda hoy para un mensaje.
	 *
	 * Si ella no lo tocó, o lo dejó en blanco, vuelve el de fábrica: un
	 * mensaje vacío sería peor que uno genérico.
	 *
	 * @param string $clave   Mensaje.
	 * @param array  $propios Un borrador sin guardar que manda sobre todo.
	 * @return array {asunto, pre, titulo, cuerpo}
	 */
	public static function texto( $clave, $propios = array() ) {
		$todas = self::plantillas();

		if ( ! isset( $todas[ $clave ] ) ) {
			return array_fill_keys( self::CAMPOS, '' );
		}

		$fab   = $todas[ $clave ];
		$mias  = self::guardados();
		$mio   = isset( $mias[ $clave ] ) && is_array( $mias[ $clave ] ) ? $mias[ $clave ] : array();

		$fuera = array();

		foreach ( self::CAMPOS as $k ) {
			// Primero el borrador que viene de la pantalla, después lo
			// guardado, y al final el de fábrica.
			$suyo = isset( $propios[ $k ] ) ? trim( (string) $propios[ $k ] ) : '';

			if ( '' === $suyo ) {
				$suyo = isset( $mio[ $k ] ) ? trim( (string) $mio[ $k ] ) : '';
			}

			$fuera[ $k ] = '' !== $suyo ? $suyo : ( isset( $fab[ $k ] ) ? $fab[ $k ] : '' );
		}

		return $fuera;
	}

	/**
	 * Si un mensaje está cambiado respecto del de fábrica.
	 *
	 * @param string $clave Mensaje.
	 * @return bool
	 */
	public static function tocado( $clave ) {
		$todas = self::plantillas();

		if ( ! isset( $todas[ $clave ] ) ) {
			return false;
		}

		$hoy = self::texto( $clave );

		foreach ( self::CAMPOS as $k ) {
			$fab = isset( $todas[ $clave ][ $k ] ) ? $todas[ $clave ][ $k ] : '';

			if ( $hoy[ $k ] !== $fab ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Deja limpio lo que viene del formulario.
	 *
	 * Un mensaje igual al de fábrica no se guarda: así, si algún día se
	 * mejora el texto original, el que nunca lo tocó se lleva la mejora.
	 *
	 * Lo que llega ya viene sin barras: el guardado de los ajustes pasa el
	 * POST entero por wp_unslash() antes de repartirlo. Volver a quitarlas
	 * acá se comería una barra escrita a propósito.
	 *
	 * @param array $crudo Lo que llegó, ya sin barras.
	 * @return array
	 */
	public static function sanear( $crudo ) {
		$fuera = array();

		if ( ! is_array( $crudo ) ) {
			return $fuera;
		}

		foreach ( self::plantillas() as $clave => $fab ) {
			if ( ! isset( $crudo[ $clave ] ) || ! is_array( $crudo[ $clave ] ) ) {
				continue;
			}

			$fila = array();

			foreach ( self::CAMPOS as $k ) {
				if ( ! isset( $crudo[ $clave ][ $k ] ) ) {
					continue;
				}

				// El cuerpo lleva saltos de línea; los otros tres son de una.
				$v = ( 'cuerpo' === $k )
					? sanitize_textarea_field( $crudo[ $clave ][ $k ] )
					: sanitize_text_field( $crudo[ $clave ][ $k ] );

				$v   = trim( $v );
				$def = isset( $fab[ $k ] ) ? $fab[ $k ] : '';

				if ( '' !== $v && $v !== $def ) {
					$fila[ $k ] = $v;
				}
			}

			if ( $fila ) {
				$fuera[ $clave ] = $fila;
			}
		}

		return $fuera;
	}

	/* ─────────────────────────────  Relleno  ──────────────────────────── */

	/**
	 * Reemplaza los campos entre llaves por lo que valen hoy.
	 *
	 * Lo que no se conoce se borra, no se deja escrito: un correo que diga
	 * «vence el {fecha}» es peor que uno que no diga la fecha.
	 *
	 * Y si una línea entera se queda sin nada que decir —«Puedes pagar acá:»
	 * sin enlace detrás— se va completa. Esa es la diferencia entre un texto
	 * editable que se puede soltar al mundo y uno que hay que revisar a mano
	 * cada vez.
	 *
	 * @param string $texto Plantilla.
	 * @param array  $datos Campo => valor.
	 * @return string
	 */
	public static function rellenar( $texto, $datos ) {
		$lineas = explode( "\n", str_replace( "\r\n", "\n", (string) $texto ) );
		$fuera  = array();

		foreach ( $lineas as $linea ) {
			$habia = 0;
			$vacio = 0;

			/*
			 * Primero los campos que se llevan su frase. Va antes del
			 * reemplazo porque después ya no se sabe dónde estaba el campo:
			 * lo único que quedaría es el hueco.
			 */
			$antes = $linea;

			foreach ( self::FRASEADOS as $campo ) {
				$v = isset( $datos[ $campo ] ) ? trim( (string) $datos[ $campo ] ) : '';

				if ( '' === $v ) {
					$linea = self::sin_frase( $linea, $campo );
				}
			}

			// Si la línea tenía algo y se quedó sin nada, se va completa.
			if ( '' !== trim( $antes ) && '' === trim( $linea ) ) {
				continue;
			}

			$resuelta = preg_replace_callback(
				'/\{([a-z_]+)\}/',
				function ( $m ) use ( $datos, &$habia, &$vacio ) {
					$habia++;
					$v = isset( $datos[ $m[1] ] ) ? trim( (string) $datos[ $m[1] ] ) : '';

					if ( '' === $v ) {
						$vacio++;
					}

					return isset( $datos[ $m[1] ] ) ? (string) $datos[ $m[1] ] : '';
				},
				$linea
			);

			/*
			 * Una línea se va sólo si existía para traer un dato que esta vez
			 * no hay: o no queda nada, o lo que queda es la etiqueta que lo
			 * presentaba —«Puedes pagar acá:» sin enlace detrás—.
			 *
			 * El primer intento borraba toda línea cuyos campos estuvieran
			 * vacíos, y con eso «Venció el 2 de septiembre.{interes} ¿Me
			 * confirmas?» desaparecía entera por no haber interés que cobrar:
			 * el dato era opcional, la frase no.
			 */
			if ( $habia > 0 && $habia === $vacio ) {
				$resto = trim( preg_replace( '/\{[a-z_]+\}/', '', $linea ) );

				if ( '' === $resto || ':' === substr( $resto, -1 ) ) {
					continue;
				}
			}

			// Los huecos que dejó un campo vacío a media frase.
			$resuelta = preg_replace( '/[ \t]{2,}/', ' ', $resuelta );

			$fuera[] = rtrim( $resuelta );
		}

		$texto = implode( "\n", $fuera );

		/*
		 * Dos puntos seguidos quedan uno.
		 *
		 * Su nombre de fantasía es «malka.», con punto, así que «…tu página
		 * en {estudio}.» salía «en malka...». Pasa con cualquier valor que
		 * termine en punto delante de uno escrito en la plantilla. Tres se
		 * respetan: unos puntos suspensivos son a propósito.
		 */
		$texto = preg_replace( '/(^|[^.])\.\.(?!\.)/m', '$1.', $texto );

		// Nunca más de un renglón en blanco seguido.
		return trim( preg_replace( "/\n{3,}/", "\n\n", $texto ) );
	}

	/**
	 * Quita de una línea la oración donde esté puesto un campo.
	 *
	 * La línea se parte en oraciones —cada una con su punto, su signo de
	 * pregunta o de exclamación— y se devuelven todas menos la que lleva el
	 * campo. Si la línea no cierra con puntuación, lo que queda al final
	 * cuenta como una oración más.
	 *
	 * @param string $linea Línea de la plantilla, todavía con llaves.
	 * @param string $campo Nombre del campo, sin llaves.
	 * @return string
	 */
	private static function sin_frase( $linea, $campo ) {
		$marca = '{' . $campo . '}';

		if ( false === strpos( $linea, $marca ) ) {
			return $linea;
		}

		if ( ! preg_match_all( '/[^.!?¡¿]*[.!?]+[ \t]*|[^.!?]+$/u', $linea, $m ) || empty( $m[0] ) ) {
			// Una línea sin puntuación es una sola frase: se va entera.
			return '';
		}

		$fuera = array();

		foreach ( $m[0] as $trozo ) {
			if ( false !== strpos( $trozo, $marca ) ) {
				continue;
			}

			$fuera[] = $trozo;
		}

		return rtrim( implode( '', $fuera ) );
	}

	/**
	 * Resuelve los campos que salen de otros campos.
	 *
	 * Hoy es uno: la frase del interés. El dato que llega es el monto
	 * —{interes_monto}—; la frase la escribe ella en su propio campo, y lo
	 * que acaba en {interes} es esa frase ya rellenada, o nada si esta vez
	 * no hay interés que cobrar.
	 *
	 * Antes la frase estaba escrita en PHP y entraba armada en {interes}:
	 * ella veía el resultado en el correo y no podía tocarle una coma.
	 *
	 * @param string $clave Mensaje.
	 * @param array  $datos Campo => valor.
	 * @param array  $t     Los textos de este mensaje.
	 * @return array
	 */
	private static function derivados( $clave, $datos, $t ) {
		if ( isset( $datos['interes'] ) ) {
			// Quien ya mandó la frase hecha manda: así un sitio que llame a
			// esto a la antigua sigue funcionando igual.
			return $datos;
		}

		$monto = isset( $datos['interes_monto'] ) ? trim( (string) $datos['interes_monto'] ) : '';
		$frase = isset( $t['interes'] ) ? trim( (string) $t['interes'] ) : '';

		$datos['interes'] = ( '' !== $monto && '' !== $frase )
			? ' ' . self::rellenar( $frase, $datos )
			: '';

		return $datos;
	}

	/**
	 * El mensaje listo para mandar.
	 *
	 * @param string $clave Mensaje.
	 * @param array  $datos Campo => valor.
	 * @return array {asunto, cuerpo}
	 */
	public static function armar( $clave, $datos, $propios = array() ) {
		$t     = self::texto( $clave, $propios );
		$datos = self::derivados( $clave, $datos, $t );

		return array(
			'asunto' => self::rellenar( $t['asunto'], $datos ),
			// Limpio: es el que se pega en WhatsApp y el que viaja como
			// alternativa del correo con diseño.
			'cuerpo' => self::plano( self::rellenar( $t['cuerpo'], $datos ) ),
		);
	}

	/**
	 * El mensaje en texto limpio, sin las marcas del diseño.
	 *
	 * Es lo que se pega en WhatsApp y lo que recibe quien tenga el HTML
	 * apagado. Sin esto, el «Copiar mensaje» de Cobranza pegaría los «---» y
	 * los «##» tal cual en la conversación con el cliente.
	 *
	 * @param string $texto Mensaje ya rellenado.
	 * @return string
	 */
	public static function plano( $texto ) {
		$fuera = array();

		foreach ( self::bloques( $texto ) as $b ) {
			switch ( $b['tipo'] ) {
				case 'abre':
				case 'cierra':
					// El recuadro es diseño: en texto no existe.
					break;

				case 'titulo':
					$fuera[] = self::sin_marcas( $b['texto'] ) . ':';
					break;

				case 'lista':
					$items = array();

					foreach ( $b['items'] as $i => $it ) {
						$items[] = ( $b['orden'] ? ( $i + 1 ) . '. ' : '• ' ) . self::sin_marcas( $it );
					}

					$fuera[] = implode( "\n", $items );
					break;

				case 'boton':
					$fuera[] = $b['url'];
					break;

				default:
					$lineas = isset( $b['lineas'] ) ? $b['lineas'] : array( $b['texto'] );
					$limpio = array();

					foreach ( $lineas as $l ) {
						$limpio[] = self::sin_marcas( $l );
					}

					$fuera[] = implode( "\n", $limpio );
			}
		}

		return trim( implode( "\n\n", array_filter( $fuera, 'strlen' ) ) );
	}

	/**
	 * Quita la negrita de una línea.
	 *
	 * @param string $t Texto.
	 * @return string
	 */
	private static function sin_marcas( $t ) {
		return trim( preg_replace( '/\*\*(.+?)\*\*/u', '$1', (string) $t ) );
	}

	/**
	 * Cómo se ve un mensaje con el ejemplo de su plantilla.
	 *
	 * @param string $clave  Mensaje.
	 * @param string $asunto Asunto a probar, o '' para el guardado.
	 * @param string $cuerpo Cuerpo a probar, o '' para el guardado.
	 * @return array {asunto, cuerpo}
	 */
	public static function vista_previa( $clave, $propios = array() ) {
		$todas = self::plantillas();

		if ( ! isset( $todas[ $clave ] ) ) {
			return array_fill_keys( array_merge( self::CAMPOS, array( 'html' ) ), '' );
		}

		$t  = self::texto( $clave, $propios );
		$ej = $todas[ $clave ]['ejemplo'];

		/*
		 * La frase del interés que trae el ejemplo es la de fábrica, y si se
		 * deja puesta `derivados()` se da por servido y no mira la que ella
		 * está escribiendo: editaba su frase y la previa seguía mostrando la
		 * vieja. Acá se quita para que la previa la arme con su texto, que es
		 * justo lo que está pasando en pantalla.
		 */
		unset( $ej['interes'] );

		$ej  = self::derivados( $clave, $ej, $t );
		$out = array();

		foreach ( self::CAMPOS as $k ) {
			$out[ $k ] = self::rellenar( $t[ $k ], $ej );
		}

		// El mismo camino que el envío de verdad, con el borrador puesto.
		$out['html'] = self::html( $clave, $ej, $propios );

		return $out;
	}

	/* ──────────────────  Lo que cada mensaje necesita saber  ──────────── */

	/* ───────────────────────  El correo con diseño  ───────────────────── */

	/**
	 * La paleta del correo.
	 *
	 * Sale de la referencia que ella pasó, con dos ajustes: ahí el oro daba
	 * 2,6:1 y el gris del cuerpo 4,4:1 contra el blanco, los dos por debajo
	 * del mínimo legible. Se bajaron al tono vecino que sí pasa —4,7 y 4,6—,
	 * que a ojo es el mismo color. El oro claro se queda para los filetes y
	 * los bordes, donde no hay que leer nada.
	 *
	 * @return array
	 */
	public static function paleta() {
		return apply_filters(
			'malka_crm_mensajes_paleta',
			array(
				'tinta'  => '#333333',
				'cuerpo' => '#757575',
				'oro'    => '#82725C',
				'filete' => '#B99B75',
				'borde'  => '#EAEAEA',
				'fondo'  => '#FFFFFF',
				'papel'  => '#FAFAFA',
			)
		);
	}

	/**
	 * Pasa el texto de un mensaje a los bloques del correo.
	 *
	 * El marcado es el mínimo que ella necesita y puede recordar:
	 *
	 *   línea en blanco   un párrafo nuevo
	 *   - algo            una viñeta
	 *   1. algo           una lista numerada
	 *   ## Título         el título dorado de un recuadro
	 *   ---               abre y cierra el recuadro
	 *   **algo**          negrita
	 *   una URL sola      un botón
	 *
	 * Nada de HTML: ella escribe prosa y el diseño lo pone el correo. Así un
	 * mensaje suyo nunca puede romper la maqueta ni colar una etiqueta.
	 *
	 * @param string $texto Mensaje ya rellenado.
	 * @return array[] Bloques {tipo, ...}.
	 */
	public static function bloques( $texto ) {
		$lineas  = explode( "\n", str_replace( "\r\n", "\n", (string) $texto ) );
		$bloques = array();
		$parrafo = array();
		$lista   = null;
		$dentro  = false;

		$cerrar_parrafo = function () use ( &$parrafo, &$bloques, &$dentro ) {
			if ( $parrafo ) {
				/*
				 * Las líneas de un párrafo se guardan aparte: ella escribió
				 * un salto y quiere un salto. Juntarlas con un espacio hacía
				 * que «Saludos,» y la firma salieran en el mismo renglón.
				 */
				$bloques[] = array(
					'tipo'    => 'parrafo',
					'lineas'  => $parrafo,
					'texto'   => implode( ' ', $parrafo ),
					'dentro'  => $dentro,
				);
				$parrafo = array();
			}
		};

		$cerrar_lista = function () use ( &$lista, &$bloques, &$dentro ) {
			if ( $lista ) {
				$bloques[] = array(
					'tipo'   => 'lista',
					'orden'  => $lista['orden'],
					'items'  => $lista['items'],
					'dentro' => $dentro,
				);
				$lista = null;
			}
		};

		foreach ( $lineas as $linea ) {
			$t = trim( $linea );

			// El recuadro se abre y se cierra con la misma marca.
			if ( '---' === $t ) {
				$cerrar_parrafo();
				$cerrar_lista();
				$dentro    = ! $dentro;
				$bloques[] = array( 'tipo' => $dentro ? 'abre' : 'cierra' );
				continue;
			}

			if ( '' === $t ) {
				$cerrar_parrafo();
				$cerrar_lista();
				continue;
			}

			if ( 0 === strpos( $t, '## ' ) ) {
				$cerrar_parrafo();
				$cerrar_lista();
				$bloques[] = array(
					'tipo'   => 'titulo',
					'texto'  => trim( substr( $t, 3 ) ),
					'dentro' => $dentro,
				);
				continue;
			}

			if ( 0 === strpos( $t, '- ' ) ) {
				$cerrar_parrafo();

				if ( ! $lista || $lista['orden'] ) {
					$cerrar_lista();
					$lista = array(
						'orden' => false,
						'items' => array(),
					);
				}

				$lista['items'][] = trim( substr( $t, 2 ) );
				continue;
			}

			if ( preg_match( '/^(\d+)\.\s+(.*)$/', $t, $m ) ) {
				$cerrar_parrafo();

				if ( ! $lista || ! $lista['orden'] ) {
					$cerrar_lista();
					$lista = array(
						'orden' => true,
						'items' => array(),
					);
				}

				$lista['items'][] = trim( $m[2] );
				continue;
			}

			// Una dirección sola en su línea se convierte en botón.
			if ( preg_match( '#^https?://\S+$#', $t ) ) {
				$cerrar_parrafo();
				$cerrar_lista();
				$bloques[] = array(
					'tipo'   => 'boton',
					'url'    => $t,
					'dentro' => $dentro,
				);
				continue;
			}

			$cerrar_lista();
			$parrafo[] = $t;
		}

		$cerrar_parrafo();
		$cerrar_lista();

		// Un recuadro que quedó abierto se cierra solo.
		if ( $dentro ) {
			$bloques[] = array( 'tipo' => 'cierra' );
		}

		return $bloques;
	}

	/**
	 * Negrita y enlaces dentro de una línea, ya escapada.
	 *
	 * @param string $t Texto.
	 * @return string
	 */
	private static function en_linea( $t ) {
		$t = esc_html( $t );
		$p = self::paleta();

		$t = preg_replace(
			'/\*\*(.+?)\*\*/u',
			'<strong style="color:' . $p['tinta'] . ';font-weight:600;">$1</strong>',
			$t
		);

		$t = preg_replace_callback(
			'#(https?://[^\s<]+)#',
			function ( $m ) use ( $p ) {
				$u = rtrim( $m[1], '.,;:' );
				$cola = substr( $m[1], strlen( $u ) );

				return sprintf(
					'<a href="%1$s" style="color:%2$s;text-decoration:underline;">%1$s</a>%3$s',
					$u,
					$p['oro'],
					$cola
				);
			},
			$t
		);

		// Un correo también se puede apretar.
		return preg_replace(
			'/([\w.+-]+@[\w-]+\.[\w.]+)/',
			'<a href="mailto:$1" style="color:' . $p['oro'] . ';text-decoration:underline;">$1</a>',
			$t
		);
	}

	/**
	 * El cuerpo del correo en HTML, a partir de los bloques.
	 *
	 * Todo con estilos en la propia etiqueta y con tablas donde hace falta:
	 * un correo no tiene hoja de estilos y Outlook ignora la mitad de lo que
	 * se ponga en un <style>.
	 *
	 * @param array[] $bloques      Bloques.
	 * @param string  $rotulo_boton Qué dice el botón de este mensaje.
	 * @return string
	 */
	private static function cuerpo_html( $bloques, $rotulo_boton = '' ) {
		$p    = self::paleta();
		$out  = '';
		$caja = false;
		$n    = count( $bloques );

		foreach ( $bloques as $i => $b ) {
			/*
			 * El margen de abajo sobra cuando el bloque es el último de un
			 * recuadro: se suma al relleno de la celda y el recuadro queda
			 * con una franja vacía al pie.
			 */
			$ultimo = isset( $bloques[ $i + 1 ] ) && 'cierra' === $bloques[ $i + 1 ]['tipo'];
			if ( 'abre' === $b['tipo'] ) {
				$caja = true;
				$out .= sprintf(
					'<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%%" style="margin:28px 0;border-collapse:separate;">' .
					'<tr><td style="padding:22px 24px;border:1px solid %s;border-radius:4px;background:%s;">',
					$p['borde'],
					$p['fondo']
				);
				continue;
			}

			if ( 'cierra' === $b['tipo'] ) {
				if ( $caja ) {
					$out .= '</td></tr></table>';
					$caja = false;
				}
				continue;
			}

			switch ( $b['tipo'] ) {
				case 'titulo':
					$out .= sprintf(
						'<p style="margin:0 0 %s;color:%s;font-size:15px;font-weight:600;line-height:1.5;">%s</p>',
						$ultimo ? '0' : '14px',
						$p['oro'],
						self::en_linea( $b['texto'] )
					);
					break;

				case 'lista':
					$etiqueta = $b['orden'] ? 'ol' : 'ul';

					$out .= sprintf(
						'<%s style="margin:0 0 %s;padding-left:20px;color:%s;font-size:14px;line-height:1.7;">',
						$etiqueta,
						$ultimo ? '0' : '14px',
						$p['cuerpo']
					);

					foreach ( $b['items'] as $i ) {
						$out .= '<li style="margin:0 0 6px;">' . self::en_linea( $i ) . '</li>';
					}

					$out .= '</' . $etiqueta . '>';
					break;

				case 'boton':
					$out .= sprintf(
						'<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:%4$s 0 %5$s;">' .
						'<tr><td style="background:%1$s;border-radius:100px;">' .
						'<a href="%2$s" style="display:inline-block;padding:14px 28px;color:#FFFFFF;font-size:14px;font-weight:600;text-decoration:none;">%3$s</a>' .
						'</td></tr></table>',
						$p['tinta'],
						esc_url( $b['url'] ),
						esc_html( $rotulo_boton ),
						'8px',
						$ultimo ? '0' : '22px'
					);
					break;

				default:
					$lineas = isset( $b['lineas'] ) ? $b['lineas'] : array( $b['texto'] );
					$dentro = array();

					foreach ( $lineas as $l ) {
						$dentro[] = self::en_linea( $l );
					}

					$out .= sprintf(
						'<p style="margin:0 0 %s;color:%s;font-size:14px;line-height:1.75;">%s</p>',
						$ultimo ? '0' : '14px',
						$p['cuerpo'],
						implode( '<br />', $dentro )
					);
			}
		}

		if ( $caja ) {
			$out .= '</td></tr></table>';
		}

		return $out;
	}

	/**
	 * El correo entero, listo para mandar.
	 *
	 * @param string $clave   Mensaje.
	 * @param array  $datos   Campo => valor.
	 * @param array  $propios Un borrador sin guardar, para la vista previa.
	 * @return string
	 */
	public static function html( $clave, $datos, $propios = array() ) {
		$todas = self::plantillas();

		if ( ! isset( $todas[ $clave ] ) ) {
			return '';
		}

		$p     = self::paleta();
		$t     = self::texto( $clave, $propios );
		$fab   = $todas[ $clave ];
		$datos = self::derivados( $clave, $datos, $t );

		$pre    = self::rellenar( isset( $t['pre'] ) ? $t['pre'] : $fab['pre'], $datos );
		$titulo = self::rellenar( isset( $t['titulo'] ) ? $t['titulo'] : $fab['titulo'], $datos );
		$boton  = self::rellenar( isset( $t['boton'] ) && '' !== $t['boton'] ? $t['boton'] : __( 'Ver', 'malka-crm' ), $datos );
		$cuerpo = self::cuerpo_html( self::bloques( self::rellenar( $t['cuerpo'], $datos ) ), $boton );

		$s    = class_exists( 'Malka_CRM_Settings' ) ? Malka_CRM_Settings::all() : array();
		$logo = ! empty( $s['logo_url'] ) ? $s['logo_url'] : '';

		ob_start();
		?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?php echo esc_html( $titulo ); ?></title>
</head>
<body style="margin:0;padding:0;background:<?php echo esc_attr( $p['papel'] ); ?>;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:<?php echo esc_attr( $p['papel'] ); ?>;">
<tr><td align="center" style="padding:32px 16px;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="width:100%;max-width:600px;background:<?php echo esc_attr( $p['fondo'] ); ?>;">
<tr><td style="padding:36px 36px 40px;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">

	<?php if ( $logo ) : ?>
	<p style="margin:0 0 28px;"><img src="<?php echo esc_url( $logo ); ?>" width="150" alt="" style="display:block;width:150px;max-width:150px;height:auto;border:0;" /></p>
	<?php endif; ?>

	<?php if ( '' !== $pre ) : ?>
	<p style="margin:0 0 6px;color:<?php echo esc_attr( $p['oro'] ); ?>;font-size:11px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;">
		<?php echo esc_html( $pre ); ?>
	</p>
	<?php endif; ?>

	<?php if ( '' !== $titulo ) : ?>
	<h1 style="margin:0 0 22px;color:<?php echo esc_attr( $p['tinta'] ); ?>;font-size:26px;font-weight:700;line-height:1.2;letter-spacing:-.01em;text-transform:uppercase;">
		<?php echo esc_html( $titulo ); ?>
	</h1>
	<?php endif; ?>

	<?php echo $cuerpo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
		<?php
		return trim( ob_get_clean() );
	}

	/**
	 * Manda el correo: con diseño, y en texto plano para quien no lo vea.
	 *
	 * Van los dos en el mismo envío. Un cliente con el HTML apagado, o un
	 * reloj, recibe el texto de siempre en vez de una página en blanco.
	 *
	 * @param string $para  Destinatario.
	 * @param string $clave Mensaje.
	 * @param array  $datos Campo => valor.
	 * @return bool
	 */
	public static function enviar( $para, $clave, $datos, $propios = array() ) {
		if ( ! is_email( $para ) ) {
			return false;
		}

		// $propios: un texto escrito para este envío (un comunicado), que
		// manda sobre el guardado en Plantillas de correo.
		$m    = self::armar( $clave, $datos, $propios );
		$html = self::html( $clave, $datos, $propios );

		if ( '' === $html ) {
			return (bool) wp_mail( $para, $m['asunto'], $m['cuerpo'] );
		}

		$plano = $m['cuerpo'];

		$tipo = function () {
			return 'text/html';
		};

		$alterno = function ( $phpmailer ) use ( $plano ) {
			$phpmailer->AltBody = $plano; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.NotSnakeCase
		};

		add_filter( 'wp_mail_content_type', $tipo );
		add_action( 'phpmailer_init', $alterno );

		$salio = wp_mail( $para, $m['asunto'], $html );

		// Se quitan siempre: dejarlos puestos mandaría en HTML cualquier otro
		// correo que saliera después en la misma carga de la página.
		remove_filter( 'wp_mail_content_type', $tipo );
		remove_action( 'phpmailer_init', $alterno );

		return (bool) $salio;
	}

	/**
	 * Una cantidad de días dicha en palabras: «1 día», «44 días».
	 *
	 * Va como campo ya armado para que la plantilla no tenga que resolver el
	 * plural, que es justo lo que un texto editable no puede hacer.
	 *
	 * @param int $n Días.
	 * @return string
	 */
	public static function dias( $n ) {
		$n = abs( (int) $n );

		/* translators: cantidad de días */
		return sprintf( _n( '%d día', '%d días', $n, 'malka-crm' ), $n );
	}

	/**
	 * Cómo se llama el estudio hacia fuera.
	 *
	 * @return string
	 */
	public static function estudio() {
		if ( ! class_exists( 'Malka_CRM_Settings' ) ) {
			return get_bloginfo( 'name' );
		}

		$n = (string) Malka_CRM_Settings::get( 'nombre_fantasia', '' );

		if ( '' === trim( $n ) ) {
			$n = (string) Malka_CRM_Settings::get( 'razon_social', '' );
		}

		return '' !== trim( $n ) ? $n : get_bloginfo( 'name' );
	}
}
