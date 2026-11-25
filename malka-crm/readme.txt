=== Malka CRM ===
Contributors: estudiomalka
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 4.96.0
License: GPLv2 or later

CRM de clientes para estudios creativos: cotizaciones y abonos, proyectos con Carta Gantt por etapas, tareas del cliente con plazos, reprogramación automática por atrasos, aprobaciones con rondas y facturas HTML seguras.

== Instalación ==

1. Comprime la carpeta `malka-crm` en un .ZIP.
2. Plugins → Añadir nuevo → Subir plugin → Activar.
3. Ajustes → Enlaces permanentes → Guardar cambios (regenera los endpoints /facturas/ y /proyectos/).
4. Malka CRM → Ajustes Generales: define color de acento, vista del Gantt y duración por defecto.

== Endpoints ==

* /mi-cuenta/proyectos/
* /mi-cuenta/facturas/

Alternativa sin WooCommerce My Account: shortcodes [malka_crm_projects] y [malka_crm_invoices].

== Seguridad de las facturas HTML ==

* El tipo MIME .html NO se habilita globalmente en la Media Library.
* Los archivos se guardan en /wp-content/uploads/malka-crm-invoices/ con .htaccess, web.config e index.php de bloqueo.
* Nombre de archivo con 20 caracteres aleatorios (no adivinable).
* Se rechaza cualquier archivo que contenga `<?php` o `<?=`.
* La entrega pasa por admin-post.php con: sesión iniciada + nonce + verificación de propietario o `manage_options`.
* Se sirven con cabecera `Content-Security-Policy: sandbox` (origen opaco: el JS del HTML no puede leer cookies ni el DOM del sitio) + `X-Content-Type-Options: nosniff`.
* Opción alternativa en Ajustes: filtrar el HTML con wp_kses_post.

== Aprobaciones ==

El cliente aprueba cada etapa y la entrega final desde /mi-cuenta/proyectos/. Cada decisión guarda estado, comentario, fecha, usuario e IP en el meta `_malka_pm_approvals`, independiente de las tareas: editar el Gantt en WP-Admin nunca borra el historial.

* Por defecto una etapa solo se puede aprobar cuando su avance llega al 100% (configurable).
* La entrega final se desbloquea cuando todas las etapas están al 100%.
* "Solicitar cambios" exige un comentario obligatorio y avisa al estudio.
* Desde el metabox "Aprobaciones del cliente" puedes reabrir una etapa tras entregar los cambios.

== Etapas, tareas y responsables ==

* Cada fila del proyecto es Etapa, Tarea o Hito. Las tareas se asignan a una etapa.
* Las etapas NO se editan a mano: sus fechas son el mín/máx de sus tareas y su avance el promedio. El avance del proyecto es el promedio de todas las tareas (las etapas no cuentan dos veces).
* Cada tarea tiene responsable: Estudio (con nombre de la persona asignada) o Cliente.
* Las tareas del cliente muestran plazo, cuenta regresiva y botón "Marcar como hecha", que avisa al estudio y recalcula la etapa.
* El Gantt del portal es de solo lectura: el arrastre está bloqueado en fase de captura, conservando el popup informativo.

== Plazos y reprogramación automática ==

* "Plazo (d)" en una tarea del cliente define su fecha límite = inicio + días.
* Aviso al cliente X días antes (por defecto 1) y aviso de vencimiento una sola vez.
* Por cada día de atraso de cualquier tarea incompleta, el cron desplaza automáticamente todas las tareas que empezaban después, y registra la reprogramación en la bitácora del proyecto y en el portal.
* Máximo una reprogramación por día y por proyecto. Botón "Reprogramar ahora por atrasos" en WP-Admin.
* Los proyectos en estado Pausado o Entregado se excluyen.

== Cotizaciones y abonos ==

* CPT "Cotizaciones" en el menú Malka CRM: N°, cliente, proyecto vinculado, URL de la cotización (la página hecha con tu plantilla), total, vencimiento, estado y calendario de abonos.
* Pestaña "Cotizaciones" en Mi Cuenta con estado, total y abonos de cada una.
* Dentro del proyecto aparece un banner clicable cuando hay un abono por vencer o atrasado, que lleva directo a la cotización donde están las fechas y las formas de pago.
* El cron avisa por correo una sola vez por abono y estado (por vencer / vencido).

== Rondas de revisión ==

Cada solicitud de cambios consume una ronda. Al agotar las incluidas, el botón "Solicitar cambios" desaparece y se muestra el mensaje comercial configurado con un botón opcional para cotizar.

* Techo por defecto en Ajustes; sobrescribible por producto (`Rondas de revisión incluidas`) y por proyecto.
* Sugerencia de configuración: Esencial 1 · Identidad 2 · Branding 3. 0 = ilimitadas.
* El control se valida en servidor, no solo en la interfaz: manipular el HTML no habilita rondas extra.
* Al agotarse dispara `malka_crm_rounds_exhausted` y avisa al estudio para cotizar.
* "Reabrir" una etapa NO devuelve la ronda consumida. Para regalar o vender una ronda usa `Malka_CRM_Approvals::adjust_rounds( $project_id, $key, -1 )`.

== Venta de rondas adicionales ==

Cierra el ciclo: el cliente agota sus rondas, compra una y el sistema la acredita solo.

1. Crea un producto simple, virtual, con visibilidad "Oculto" (ej. "Ronda de revisión adicional").
2. Malka CRM → Ajustes → Venta de rondas adicionales: activa y selecciona ese producto.
3. En el portal aparece "Comprar ronda adicional — $XX.XXX". El enlace añade el producto al carrito con el proyecto y la etapa adjuntos y lleva directo al checkout.
4. Al pasar el pedido a "Procesando" o "Completado" se acredita la ronda, la etapa vuelve a estado Pendiente y se envían dos correos: confirmación al cliente y aviso al estudio.

Controles:
* Nonce por proyecto + verificación de propiedad al añadir al carrito y otra vez al acreditar.
* Idempotencia por línea de pedido (`_malka_crm_granted`): un pedido nunca acredita dos veces, ni aunque pase de procesando a completado.
* Las rondas compradas se guardan aparte (`_malka_pm_extra_rounds`), así el historial distingue cupo del plan de cupo pagado.
* Botón "+1 ronda" en el metabox para acreditar manualmente (cortesía o cobro por transferencia).

== Recordatorios automáticos (cron) ==

Una tarea diaria (`malka_crm_daily_check`, 09:00 hora local) revisa las etapas al 100% con aprobación pendiente.

* Primer recordatorio a los X días (por defecto 3), repetición cada Y días (4), máximo N envíos (3).
* A los Z días (10) escala al estudio con un aviso interno único.
* Los proyectos en estado "Pausado" se saltan.
* Botón "Ejecutar ahora" en Ajustes para probar sin esperar 24 h.
* Si el avance de una etapa baja del 100%, se reinicia el contador y los recordatorios.
* WP-Cron depende de las visitas al sitio. Con poco tráfico, define `DISABLE_WP_CRON` y agenda un cron real del servidor cada hora.

== Notificaciones por correo ==

Plantilla HTML tabular con la identidad Malka (acento configurable, base #F9F8F6). Activables una a una:

* Al cliente: nueva factura disponible.
* Al cliente: proyecto abierto.
* Al cliente: cambio de estado del proyecto.
* Al estudio: aprobación recibida y solicitud de cambios.
* Al estudio: rondas agotadas (oportunidad de cotizar).
* Al cliente: recordatorio de aprobación pendiente.
* Al estudio: escalamiento por falta de respuesta.

Botón "Enviar correo de prueba" en Ajustes Generales. Si falla, instala un SMTP transaccional (Fluent SMTP, WP Mail SMTP + Postmark/Brevo): wp_mail() por PHP nativo acaba en spam.

== Hooks disponibles ==

* malka_crm_admin_capability (filter) — capacidad requerida, por defecto manage_options.
* malka_crm_default_task_blocks (filter) — plantilla de fases al crear proyectos automáticos.
* malka_crm_frappe_js / malka_crm_frappe_css (filter) — sustituir el CDN por copias locales.
* malka_crm_invoice_max_size (filter) — tamaño máximo de factura.
* malka_crm_load_assets (filter) — forzar carga de assets en otras páginas.
* malka_crm_project_created (action) — $project_id, $customer_id, $order.
* malka_crm_invoice_uploaded (action) — $invoice_id, $user_id, $order_id.
* malka_crm_project_status_changed (action) — $project_id, $new_status, $old_status.
* malka_crm_item_approved (action) — $project_id, $key, $record.
* malka_crm_changes_requested (action) — $project_id, $key, $record.
* malka_crm_rounds_exhausted (action) — $project_id, $key, $record.
* malka_crm_approval_reminder (action) — $project_id, $key, $days, $count.
* malka_crm_approval_escalated (action) — $project_id, $key, $days.
* malka_crm_extra_round_granted (action) — $project_id, $key, $quantity, $order.
* malka_crm_client_task_done (action) — $project_id, $task, $late.
* malka_crm_task_due_soon (action) — $project_id, $task, $days.
* malka_crm_task_overdue (action) — $project_id, $task, $days.
* malka_crm_schedule_shifted (action) — $project_id, $shifted.
* malka_crm_cron_batch_size (filter) — proyectos revisados por ejecución (200).
* malka_crm_email_html / malka_crm_email_subject (filter) — personalizar los correos.

== Changelog ==

= 4.96.0 =
* Una tarea marcada como hecha se archiva: deja la lista de «Lo que falta» en vez de quedarse tachada hasta recargar. El aviso de abajo trae un «Deshacer» por si fue sin querer. Las que se repiten no se archivan: siguen en la lista con su próxima fecha, como antes.
* El encabezado del negocio se va con su última tarea, y si no queda nada el panel lo dice en vez de quedarse en blanco.
* El botón del aviso se podía ver pero no apretar: el aviso entero bloqueaba el clic para no tapar la página de abajo. Ahora el clic llega al botón y sólo a él.
* La casilla de marcar es un círculo, no un cuadrado, en los dos estados.
* El círculo de «Revisar» pasa del negro macizo al mismo verde de la casilla: las dos cosas dicen «esto ya está».

= 4.95.0 =
* La casilla de algo ya hecho deja de ser un cuadrado macizo: va con el verde de «pagado» y el visto adentro se ve. Estaba rellena con el color de acento —negro puro en claro, blanco puro en oscuro— y el visto se dibujaba con el color de la tinta, que en cada tema es casi ese mismo color: el visto nunca se vio, en ningún tema, desde que existe la casilla.

= 4.94.0 =
* La pestaña «El cliente» pasa a llamarse «Revisar». Chocaba con el botón «Cliente» del filtro de Tareas, que está diez centímetros más abajo y quiere decir otra cosa: ahí «Cliente» es de quién es la tarea pendiente, y arriba era lo que el cliente ya entregó.
* Tareas usa todo el ancho de la pantalla, igual que Revisar y Pagos. Antes se quedaba en una columna angosta y las tres pestañas de la misma barra parecían tres pantallas distintas.
* Pagos muestra el mes entero en tres montones —Atrasados, Por pagar y Pagados—, cada uno con su cuenta y su monto, y lo atrasado arriba. Antes era una lista sola con lo pagado escondido tras un desplegable.
* Un montón con cobros sin monto conocido (el F29, que no tiene cifra hasta declarar) muestra una raya y no «$0», que decía justo lo contrario.
* El botón «Visto» ya no se pinta de negro al pasar el mouse, y su globito dice qué hace en vez de repetir la palabra.

= 4.93.0 =
* Las pestañas (Tareas · Horas · Pagos · El cliente, y también las de Finanzas y Negocios) usan el mismo gesto que el filtro «Todo · Cliente · Estudio»: pista gris, píldora blanca y redonda para la activa, número en gris. Antes la activa era un rectángulo negro y parecían dos componentes distintos haciendo lo mismo.
* La palmera del modo vacaciones se redibuja con las hojas como contornos cerrados; de un solo trazo se leía como un paraguas. Sin línea de suelo.

= 4.92.0 =
* El Inicio se reordena: «Tu meta del mes» pasa a ser un bloque movible como los demás —antes estaba clavada arriba— y se suma «Lo que hay», con el saldo de cada cuenta, el del estudio y el tuyo separados. Las tarjetas de crédito no se cuentan como saldo.
* «Lo que hizo el cliente» sale de la pantalla de Tareas y tiene su propia pestaña, junto a Tareas, Horas y Pagos.
* Un bloque nuevo del Inicio aparece donde le corresponde y no desterrado al final de los que ya tenías ordenados.
* El botón de vacaciones es una palmera y no una maleta.
* CORRECCIÓN: un bloque con un ancho no permitido se dibujaba sin columnas y el texto salía en vertical, una letra por línea.
* CORRECCIÓN: dos elementos compartían el id «esc-saldo», lo que rompe el salto por ancla y confunde a un lector de pantalla.

= 4.91.0 =
* Nueva entrada «Agenda» en el menú lateral, con su propia pantalla: las reuniones, llamadas y visitas de los próximos dos meses, y el + para anotar una ahí mismo. El panel del Inicio sigue mostrando dos semanas.
* El número al lado de Agenda cuenta lo que viene en los próximos siete días.

= 4.90.0 =
* Las tareas del trabajo, las horas y los pagos del estudio cuelgan de Estudio en el menú lateral, que es de donde son.
* Los pagos del mes dejan de ser una columna dentro de Tareas y tienen su propia pantalla.
* Tareas queda partida en dos: dentro de Estudio, sólo lo del trabajo; arriba, «Mis tareas», sólo lo tuyo y en una columna.
* Se va el botón «Trabajo» del filtro: daba siempre el mismo número que «Todo» en una pantalla donde todo es trabajo. Quedan Todo · Cliente · Estudio.
* En «Lo que se paga solo», a los cobros sin día se les avisa arriba y se agrupan al final: siguen contando en el total, pero no entran al calendario ni se pueden marcar pagados.

= 4.89.0 =
* Devolverte lo que pusiste ya no es escribir un monto: marcas cuáles cargos te estás devolviendo, con una casilla cada uno, y el total se suma solo. El botón no se activa hasta que elijas alguno.
* Los cargos marcados quedan saldados y salen de la lista; los que no, siguen pendientes. Los saldados se miran en «Ver cargos ya devueltos».
* Los dos movimientos de la devolución —el que sale de la empresa y el que entra a tu cuenta— llevan anotado a qué cargos corresponden.
* Las devoluciones anotadas con versiones anteriores no dicen a qué cargo corresponden: se usan para saldar los cargos más antiguos, para que al actualizar no se vuelva a pedir la misma plata.
* La cuenta a la que devolver viene puesta con aquella de la que salió más plata, no con la primera de la lista.

= 4.88.0 =
* Un cobro tuyo pagado con tu tarjeta se anota como «Gasto tuyo» y no como costo del estudio. Antes el estudio te quedaba debiendo el Netflix.
* El panel de suscripciones avisa cuánto pusiste de tu bolsillo en cobros del estudio, con el camino para saldarlo.
* El + para anotar un cobro fijo pasa al encabezado del panel; se fue el botón del pie.

= 4.87.0 =
* Cada suscripción muestra en qué va este mes: «Pagar» si está pendiente, o lo que ya pagaste con la cuenta de la que salió.
* Al marcar un pago se preguntan tres cosas: cuánto te salió de verdad, qué día y de qué cuenta salió. La cuenta se puede cambiar: la configurada es la costumbre, no la verdad.
* Las suscripciones personales se marcan igual que las del estudio, y siguen fuera de la campana y del correo de la mañana.
* Con tres meses de pagos reales, el panel deja de estimar con el tipo de cambio del día y usa el promedio de lo que de verdad llegó a la cartola. Un cobro anual usa el último cargo, no el promedio de años con precios distintos.
* Un pago marcado por error se deshace: se va la marca, el movimiento y el saldo vuelve. Antes no había forma.
* CORRECCIÓN: el borrado de un movimiento vivía dentro del borrado en lote y no se podía usar desde otro lado.

= 4.86.0 =
* Nuevo panel «Lo que se paga solo» en Finanzas → Deudas: las suscripciones y los pagos fijos, con los totales del estudio y los tuyos separados, y el próximo cobro de cada uno.
* Cada cobro lleva etiqueta: del estudio o personal. Sólo lo del estudio entra al costo con el que se calcula la tarifa por hora.
* Los cobros pueden ser mensuales o anuales. Lo anual se reparte en doce para los totales y se cobra entero el mes que toca.
* El panel respeta el lente de arriba: en Estudio sólo lo del estudio, en Personal sólo lo tuyo.
* Avisa cuando hay dos cobros con el mismo nombre, porque lo pagado se recuerda por el nombre y marcar uno daba por pagado el otro.
* CORRECCIÓN: el formulario de costos de WP-Admin mandaba sólo el nombre y el monto, así que guardar ahí borraba la moneda, el día, el «varía» y la cuenta de cada costo.
* CORRECCIÓN: en la tabla de costos del escritorio, agregar o quitar una fila renombraba el campo «sale de» como «varía» y se perdía la cuenta.
* Se quitan del código los cuatro últimos dígitos del RUT, que figuraban como número de la cuenta MACH.

= 4.78.0 – 4.85.0 =
* Movimientos rehecho: buscador, filtros en un cajón, selección múltiple con acciones en lote, menú por fila y edición en ventana. Antes la pantalla no tenía ni estilos ni comportamiento.
* Los movimientos aceptan otra moneda, estado pendiente y cuotas.
* Finanzas filtra sin recargar la página.
* Los créditos aparecen en Finanzas con cuándo los pediste, cuánto llevas pagado y cuántas cuotas quedan.
* Agenda: las reuniones y los eventos del día, con su ventana para anotarlos desde el + de arriba.
* Deudas: cada deuda trae «¿Cómo la pago?» con su propia simulación; anotar una deuda pasa al + de arriba; las condiciones de cada tarjeta se editan en el detalle de su cuenta.
* Las advertencias que hay que leer una vez quedan detrás de un ⓘ en el encabezado, en vez de ocupar tres líneas cada vez.
* Estudio abre directo en la lista de negocios; el menú de atajos se fue porque la barra lateral dice lo mismo.
* Las tareas tienen estado «la estoy haciendo» y marca de importante.
* CORRECCIÓN: el formulario de una cuenta salía de una letra de ancho en los navegadores nuevos.
* CORRECCIÓN: varios grises y rojos no llegaban al mínimo de contraste sobre las filas con tinte.

= 4.77.0 =
* En Estudio y en Bienestar, tocar una ficha ya no te lleva a otra pantalla: abre esa sección ahí mismo, debajo. La ficha elegida se rellena de negro y arriba del cajón queda «Abrir completo» por si la quieres a pantalla entera.
* Tocar la misma ficha otra vez, el botón «Cerrar» o la tecla Escape cierran el cajón. El enlace de la página recuerda cuál quedó abierta, así que al recargar vuelve.
* Lo de adentro funciona entero: el buscador de negocios filtra, las casillas marcan, los enlaces navegan sin sacarte de la página. Va en un marco con la vista en modo «sólo contenido», y no pegando el HTML, justamente porque pegado el JavaScript de cada sección quedaría mudo.
* El marco crece hasta donde llega su contenido: no hay una barra de scroll dentro de otra.
* Las fichas siguen siendo enlaces de verdad: con cmd/ctrl, el botón del medio o sin JavaScript abren la sección completa como antes.

= 4.76.0 =
* Nueva línea gráfica del escritorio, monocroma. El café de la marca sale del panel y el acento pasa a ser el negro: lo seleccionado se rellena de negro con texto blanco, y el color queda reservado para lo que significa algo (verde si entra plata, rojo si sale o algo está atrasado).
* Rótulos de sección en mayúscula chica y espaciada; cifras más grandes y apretadas, con la unidad en menor tamaño.
* Los botones del encabezado se agrupan en una sola píldora, y el chip de fecha también es píldora. Tarjetas sin sombra, con borde de un pelo y esquinas de 14px.
* Modo oscuro rehecho en el mismo idioma: fondo #0A0A0A, y de noche lo seleccionado se invierte a blanco con texto negro.
* CORRECCIÓN: el chip «En curso» usaba un café fijo que con los grises nuevos daba 4,4:1 en claro y 4,1:1 en oscuro. Ahora los estados van por token, y «en curso» es gris: estar trabajando no es una alarma.
* La portada de Estudio y la de Bienestar ya no repiten el título que la página acaba de decir.
* Nada cambia en lo que ve el cliente: cotizaciones, correos y portal siguen con el café de la marca.

= 4.75.0 =
* Reestructuración del menú: la barra de abajo pasa a ser Inicio · Estudio · Finanzas · Bienestar · Más.
* ESTUDIO: una portada nueva que junta todo el trabajo —negocios, tareas, cotizaciones, clientes, proveedores y horas—, que antes estaba repartido entre dos pestañas y el menú lateral. Negocios abiertos y tareas pendientes se ven desde la portada.
* FINANZAS suma dos solapas: «Personal» (tus cuentas y lo que el estudio te debe, separado de la plata de la empresa) y «Crecer» (dónde pusiste plata para que rinda).
* BIENESTAR, sección nueva con cuatro solapas: Cuerpo, Mente, Vínculos y Vos. Por ahora es la estructura, no el contenido, y cada pantalla lo dice en vez de fingir que funciona.
* Cuerpo guarda fechas y recordatorios, nunca resultados ni diagnósticos: un sitio WordPress no es lugar para eso.
* Nada se perdió: las 24 pantallas que ya existían siguen donde estaban y se llegan igual.

= 4.74.0 =
* Las cuentas pueden ser tuyas y no del estudio: al agregarla, «Es mía». Su plata no entra en el saldo de la empresa ni en lo que puedes retirar, pero desde ahí se pueden pagar costos del estudio.
* Dos tipos nuevos de cuenta: tarjeta de prepago o débito (Mach, Tenpo) y tarjeta de crédito. En una tarjeta de crédito el saldo es lo que debes: una compra lo sube y pagar la tarjeta lo baja, y nunca cuenta como plata disponible.
* Cada costo fijo dice de qué cuenta o tarjeta sale. Lo que pagas con una tarjeta tuya cuenta igual como costo del estudio, pero no baja el saldo de la empresa.
* Panel nuevo en Movimientos, «El estudio te debe»: cuánto pusiste de tu bolsillo, qué te devolvieron y cuánto queda. Con el botón para anotar la devolución, que baja el saldo del estudio y sube el de tu cuenta.

= 4.73.0 =
* Al marcar un pago del estudio ahora se puede decir CUÁNDO se pagó, no sólo cuánto. Viene puesto el día en que vencía, que casi siempre es el correcto.
* CORRECCIÓN: el movimiento quedaba con la fecha del día en que se marcaba. Un cobro mensual que salió el día 1 y se marcaba el 20 descuadraba el mes y bajaba el saldo tres semanas tarde.
* Un pago anterior al último cuadre con el banco ya no vuelve a bajar el saldo: esa plata ya está descontada en la cartola. Queda igual en Movimientos para el costo del mes.
* Una fecha de pago en el futuro se corrige a hoy.

= 4.72.0 =
* CORRECCIÓN de fondo: el saldo no es la suma de tus cobros, es lo que dice el banco. Sumar cobros viejos encima de un saldo escrito a mano contaba dos veces la misma plata, y por eso el panel no calzaba con la cartola. Ahora el saldo es una foto del banco con su fecha, y sólo se mueve con lo que pasa después de esa fecha. Es la misma regla con la que ya entraba una cartola.
* «Cuadrar con el banco», arriba de Cobros en tu saldo: copias el saldo que te muestra tu banco y la fecha, y el panel queda en ese número exacto. Lo cobrado hasta ese día pasa a la lista «anteriores a tu cuadre» y deja de sumarse: ya está dentro.
* Un cobro del mismo día del cuadre sí se suma. Una cuota guarda el día pero no la hora, así que no hay forma de saber si esa plata ya estaba en la cartola; de los dos errores posibles, este se ve el mismo día y se desmarca, y el contrario no se vería nunca.
* Desmarcar un cobro anterior al cuadre ya no baja el saldo: nunca lo había subido.

= 4.71.0 =
* CORRECCIÓN: «Sumarlos al saldo» era todo o nada y no se podía deshacer. Apretarlo metía de golpe todos los cobros viejos y dejaba el saldo en un número imposible de explicar, sin vuelta atrás. Culpa del botón, no de quien lo apretó.
* Nueva pantalla «Cobros en tu saldo»: una casilla por cuota cobrada, con cliente, número de cotización, fecha y monto. Marcada quiere decir «esta plata está dentro del saldo». El total de abajo se mueve mientras marcas, así ves en qué número va a quedar antes de guardar.
* Desmarcar saca el cobro del saldo y borra su movimiento: el saldo vuelve exactamente a donde estaba. Y lo que sacaste no vuelve solo al guardar la cotización.
* Se llega desde «Ver qué cobros están sumados», debajo del saldo en el Inicio. El aviso de cobros viejos ahora lleva ahí en vez de sumar a ciegas.

= 4.70.0 =
* Modo vacaciones, con botón propio en el encabezado (la maleta, junto a la campana). Mientras está puesto, a ti no te llega ningún correo del panel; a tus clientes les llega todo igual: las cuotas vencen en su fecha, los intereses corren y la cobranza les sigue saliendo sola.
* Los avisos que no te llegaron no se pierden: quedan guardados y, al volver, el Inicio abre con «Mientras no estabas» — asunto, una línea de qué era y la fecha, del más nuevo al más viejo.
* Excepción, marcada de fábrica: los avisos de impuestos y de pagos del estudio sí te llegan. Un F29 que se pasa cuesta multa y no espera a la vuelta. Se puede desmarcar al activarlo.
* La fecha de vuelta es opcional: con fecha se apaga solo ese día; sin fecha, se apaga cuando toques el botón. Mientras esté activo, se ve bajo el saludo («De vacaciones · vuelves el 19 de noviembre»).

= 4.69.0 =
* CORRECCIÓN: marcar una cuota como «Completado» ya mueve el saldo de la cuenta. El Inicio prometía «los pagos de cotizaciones entran solos» y no entraba ninguno: la función que lo hacía estaba escrita pero no la llamaba nadie, así que el saldo quedaba igual que antes del pago.
* Cada cuota cobrada deja su movimiento en Movimientos, con el cliente, el medio de pago y la fecha en que entró (no la de hoy).
* Desmarcar una cuota devuelve el saldo y borra su movimiento. Si el movimiento se editó a mano, no se toca.
* El «Pago total» de una cotización con cuotas no se anota aparte: contaría la plata dos veces.
* Lo que ya estaba pagado antes de esta versión no entra solo —ese saldo ya está escrito a mano—, pero se ofrece una vez en el Inicio: «3 pagos marcados como completados no están sumados acá», con «Sumarlos al saldo» o «Ya están».

= 4.68.2 =
* Pasos de cada publicación: nueva columna «Lo ve el cliente / Sólo el estudio». Los pasos «Sólo el estudio» se crean como tareas privadas y no le aparecen al cliente en su panel.
* Al cambiar quién ve un paso, se aplica también a las subtareas con ese nombre que ya existen en publicaciones de ese formato.

= 4.68.1 =
* Las subtareas (1.1.1) ya no se toman como publicación por su nombre («Historia de la joya» es un paso): sólo si se marca «Publicación: Sí» o tiene datos de publicación.

= 4.68.0 =
* Detalle de tarea rediseñado como ficha: una franja de datos (qué es, quién, parte, entrega, si el cliente responde con nota escrita o de voz, aprobación, se repite, rondas, quién la ve, publicación) que se edita ahí mismo, sin paneles.
* La publicación se ve con el mismo diseño del panel del cliente y se escribe en su lugar: fecha y hora grandes de «Se publica» (distinta de la entrega), formato, quién publica, copy, hashtags, música, archivo, portada, encuesta y voz en off con su «+», y la nota del estudio.
* Notas internas arriba de las subtareas y el avance con su barra al final («Subtareas · 3 de 7 listas»).
* Las tareas listas se marcan con un visto fino en vez del círculo negro.
* Panel del cliente, «Te toca a ti»: sólo lo de hoy y lo atrasado, de a cuatro; al terminarlas aparecen las siguientes. Color sólo si está atrasada o por vencer; lo demás en blanco.
* Panel del cliente, «Por pagar»: sólo cuotas de cotizaciones firmadas o aceptadas.

= 4.67.1 =
* Portal, Por pagar: los pagos atrasados y los que vencen en los próximos 7 días van arriba, destacados («Atrasado», «Vence hoy», «Vence pronto»); los demás, más abajo y con fondo claro.

= 4.67.0 =
* Tareas: nuevo campo «Respuesta del cliente» (nota opcional, pedirle una nota, pedirle una grabación, nota o grabación, sin nota) y «Qué le preguntas». En el portal, el cliente ve la pregunta y puede escribir o grabar su respuesta con el micrófono; el estudio la escucha en el detalle de la tarea y le llega el aviso.

= 4.66.4 =
* Tareas › «Lo que hizo el cliente»: cada fila tiene un botón «Visto»; al tocarlo sale de la lista y no vuelve.

= 4.66.3 =
* Negocio: se quita la flecha de plegar a la izquierda del número; se pliega y despliega con «▸ N subtareas» del resumen.

= 4.66.2 =
* Publicaciones: nuevo campo «Quién publica» (el estudio o el cliente). Si es el cliente, le aparece «Publicar: …» en «Te toca a ti» con la fecha de publicación, y puede marcarla como lista.
* Portal, «Te toca a ti»: si lo pedido es una publicación, la ventana muestra todo su detalle (fecha, formato, copy, hashtags, encuesta, música, nota y descargas).

= 4.66.1 =
* Tareas › Pagos del mes: lo ya pagado sale de la lista y queda guardado en «Ver N pagos hechos», plegado al final. Al marcar uno, se mueve solo.

= 4.66.0 =
* Portal, ficha del proyecto: cabecera compacta. La barra lleva el porcentaje al lado; etapa, lo próximo y entrega en una línea; y en otra la cotización, el monto, lo pagado y lo pendiente con un ícono de factura (verde con factura, ámbar en parte, gris sin factura; al pasar el mouse dice qué falta) y el enlace a la factura.
* Tareas: la tarea va en negrita y sus subtareas más adentro; cada fila tiene una × roja para borrarla (con sus subtareas, si tiene). Los nombres ya no terminan en «·».

= 4.65.8 =
* Portal, ficha del proyecto: bajo los datos se ve el monto, lo pagado, lo pendiente y la factura (se abre al tocarla).
* Portal: al abrir la ficha ya no aparece un borde alrededor del botón de la cotización.

= 4.65.7 =
* Ícono de factura de «Pagos» con tres colores, contra el neto contratado: gris sin nada facturado, ámbar si falta una parte (al pasar el mouse dice cuánto) y verde si está todo facturado. Al pasar el mouse (o tocarlo) aparece un recuadro con lo facturado, lo contratado y lo que falta.

= 4.65.6 =
* Ficha del negocio: junto a «Pagos», un ícono de factura. Verde si lo cobrado ya está facturado; gris si todavía no hay factura o falta facturar parte (al pasar el mouse dice cuánto).

= 4.65.5 =
* Ficha del negocio (pantalla ancha): nombre, fechas, tareas, pagos y avance en una sola franja (la mitad de alto) que queda fija arriba al bajar por la página.

= 4.65.4 =
* Portal, Proyectos: bajo cada proyecto se ve su monto, lo pagado, lo pendiente y su factura (se abre al tocarla).

= 4.65.3 =
* Portal, ficha del proyecto: bajo el nombre aparece la cotización enlazada (o las cotizaciones); al tocarla se abre en una ventana.

= 4.65.2 =
* Publicaciones: nuevos campos «Música» (canción, artista o enlace) y «Notas para el cliente». El cliente los ve en el detalle de la publicación en su portal.
* Publicaciones: «Portada del reel» y lo propio del reel ya no aparecen cuando el formato es otro.

= 4.65.1 =
* Portal, ficha del proyecto: bajo la barra de avance, Avance, Etapa actual, Lo próximo y Entrega van en cuatro columnas (dos en el teléfono), como en la maqueta.

= 4.65.0 =
* Portal: nueva sección «Calendario» (debajo de Proyectos) con todo lo programado día por día: publicaciones y tareas de todos los proyectos, pagos por hacer, cotizaciones por firmar y entregas. Al tocar algo, el detalle se abre al lado (o en una ventana en el teléfono).
* Portal, ficha del proyecto: en pantallas anchas el calendario muestra el detalle al lado, como en la maqueta; abre con la próxima publicación. Las publicaciones que sólo tienen nombre también aparecen en su fecha.
* Ventanas emergentes del portal y del escritorio: más grandes, con el mismo margen a los lados que arriba y abajo.
* Teléfono: la barra de abajo del portal con siete secciones usa letra un poco menor y «Cotiz.» en vez de cortar los nombres.

= 4.64.0 =
* Portal: la ficha del proyecto tiene vista de calendario del mes, con las publicaciones en su día (con su formato) y lo que le toca al cliente en su fecha. Los proyectos de redes sociales abren en el calendario; los demás, en la lista. Se cambia con «Calendario | Lista».
* Portal: en la lista, cada publicación es una fila; al tocarla se abre una ventana con su detalle (copy, archivo, voz en off, tareas). Lo mismo al tocar algo en el calendario. En el teléfono, el mes se ve con puntos y debajo la agenda del día tocado.

= 4.63.8 =
* Portal, detalle del proyecto: las tareas principales van en una franja gris, como las etapas del escritorio, y sus subtareas en blanco debajo.

= 4.63.7 =
* Ventanas emergentes del escritorio: el contenido se abre siempre sin el menú de abajo, el encabezado ni el reloj, también si adentro se sigue un enlace (por ejemplo, de la cotización al negocio).
* Negocio en el teléfono: «Parte el» y «Se entrega el» quedan alineados con su fecha, a la izquierda, en dos columnas iguales.
* Aro de avance: el porcentaje y «avance» quedan centrados y con aire hasta el borde.

= 4.63.6 =
* Portal, «Te toca a ti»: mismo diseño que «Por pagar». Panel blanco sin degradado ni borde que late, y cada cosa pedida en su propia tarjeta (cálida; rosada si está atrasada).
* Portal: la cotización por firmar ya no muestra «· ·» cuando su título termina en separador.

= 4.63.5 =
* Tareas con subtareas: ya no se marcan solas como hechas cuando terminan sus subtareas. Su avance llega al 100 %, pero sólo el círculo la da por lista; así no desaparece de «Próxima publicación» en el portal.
* Plegar subtareas: la flecha delante del número se ve como botón, y el resumen de la fila empieza con «▾ 10 subtareas», que también se toca para plegar o desplegar.

= 4.63.4 =
* Negocio: cada tarea con subtareas (y cada etapa) lleva una flecha a la izquierda para plegarlas y dejar a la vista sólo la principal. Se recuerda en este navegador.
* Portal: una tarea sólo pasa a «completadas» cuando se marca con el círculo; llegar al 100 % de avance (por ejemplo, porque sus subtareas están listas) ya no la esconde.

= 4.63.3 =
* Portal, detalle del proyecto: las tareas ya hechas se guardan en «Ver N tareas completadas» al final de cada etapa y de cada publicación; a la vista queda sólo lo que falta.

= 4.63.2 =
* Portal: el archivo de la publicación se descarga con el mismo botón que la portada («Descargar el archivo»), juntos al final del detalle.

= 4.63.1 =
* Publicaciones tipo reel: nuevo campo «Portada del reel» con el enlace de descarga de la portada. En el portal, el cliente ve el botón «Descargar la portada».
* El campo «Archivo de la publicación» ya no queda corrido hacia abajo.

= 4.63.0 =
* Publicaciones: al elegir el formato (reel, historia, carrusel…) sus pasos aparecen solos como subtareas, con quién responde y las fechas de la publicación. Sólo si todavía no tiene subtareas: nunca duplica ni pisa las que ya armaste.
* Negocios › Plantillas: nuevo panel «Pasos de cada publicación» para editar los pasos de cada formato (agregar, quitar, renombrar y elegir quién). Se guarda solo. El reel viene con sus 11 pasos de siempre.
* Configuración › Tu marca: «Ícono de la app», el que aparece al usar «Agregar a inicio» en el teléfono, para el escritorio y el portal del cliente. Si no eliges uno, usa el ícono del sitio de WordPress.
* Detalle de tarea en el teléfono: los interruptores (aprobación, visibilidad, publicación) vuelven a verse completos, y en el iPhone la fecha queda centrada en su campo.
* Tareas en el teléfono: el número ya no se corta desde la décima subtarea (2.8.10).
* Negocios grandes: el árbol de tareas se guarda en un solo campo. Antes, con muchas publicaciones y subtareas, se pasaba el tope de 1.000 campos por envío de PHP y las últimas tareas podían perderse al guardar.

= 4.62.6 =
* Portal: en «Tus proyectos» los entregados van al final (primero los que están andando, luego los próximos y los pausados).
* Portal: los nombres ya no terminan con un separador colgando («RRSS N°3 ·», «Publicación 1 ·»).
* Portal: el texto de los botones de pago queda centrado.

= 4.62.5 =
* Móvil: el reloj (pomodoro) vuelve al borde derecho cuando no está el botón del asistente; sólo se corre si ese botón existe.
* Cobranza › Dadas por perdidas: el texto del botón «Abrir» queda centrado.

= 4.62.4 =
* Ficha del negocio (móvil): cada tarea en dos líneas alineadas: casilla y nombre arriba; número, resumen, + y asa abajo, con los botones siempre en el mismo lugar en etapas y tareas.
* La casilla de «hecha» es negra, sin verde relleno (también en «Lo que hizo el cliente»).
* El nombre del negocio se lee entero (crece en varias líneas) y la × roja de borrar pasa a la esquina del panel en el teléfono.
* Detalle de tarea en el teléfono: las fechas Parte/Termina ya no se salen por la derecha (iPhone) y el encabezado «Publicación» no se parte.
* Portal: las subtareas pendientes aparecen bajo el nombre de su tarea, y en «Te toca a ti» se ve de qué tarea es parte cada una.
* Escritorio móvil: el reloj y el botón del asistente ya no tapan «Más» ni el final de la página.
* Cotización: los ítems y las cuotas se reacomodan según el ancho de su columna (a 1024 px el unitario quedaba en cero y las cuotas cortadas).
* Ficha del negocio en tablet y teléfono horizontal: las fechas van en su propia franja, sin cortarse.
* Botones secundarios beige pasan a botones de línea negros.

= 4.62.3 =
* Portal móvil, Pagos (y toda fila con botones): el nombre de la cotización y su detalle ya no desaparecen; arriba nombre y monto, debajo el estado y los botones a todo el ancho.
* Escritorio móvil: las pestañas bajan de fila en vez de esconderse a la derecha, y en Contabilidad el monto de cada código baja bajo su nombre.
* Revisión de todas las pantallas del portal y del escritorio a ancho de teléfono (390 y 360 px), sin columnas en cero ni desbordes.

= 4.62.2 =
* Portal: el nombre de un proyecto o tarea ya no se subraya al tocarlo o pasar el mouse.

= 4.62.1 =
* Portal móvil, Proyectos: el nombre y los datos del proyecto ya no desaparecen (una regla de escritorio dejaba su columna en 0). Ahora van a todo el ancho, con el avance y los enlaces debajo, y las pestañas bajan a otra fila en vez de cortarse.

= 4.62.0 =
* Facturas del extranjero: ya no se juntan solas con una factura de compra emitida. Si parece la misma, la fila pregunta «Parece que ya la emitiste… ¿Es esta?» con «Sí, juntarlas» o «No es esa».
* La búsqueda es más estricta: mismo RUT, fecha a 5 días o menos y monto casi exacto (2 %) con el dólar del día en que se emitió.
* Juntar y separar a mano: en cada por emitir, «Ya la emití: juntar con…»; en cada emitida sin factura del extranjero, «¿Cuál es su factura del extranjero?»; y en las juntadas, «Separar su factura del extranjero», que deja todo como estaba.

= 4.61.0 =
* Documentos: nueva pestaña «Facturas de compra emitidas», aparte de «Compras» (lo que te facturan tus proveedores) y de «Facturas de compra por emitir» (las del extranjero pendientes). Se filtran por «con su factura del extranjero» o «sin».
* Facturas del extranjero: al arrastrarlas se revisa si ya estaban anotadas (no se duplican) o si ya tienen su factura de compra emitida importada del SII (mismo RUT, fecha cercana y monto parecido); en ese caso se juntan con ella.
* Por emitir: van en el orden en que hay que emitirlas en el SII (fecha de emisión más antigua primero), con su turno, el número que le toca y un aviso si la fecha queda antes de la última emitida.
* Teléfono: los datos de las facturas de compra se ven completos (un dato por línea) y las tareas van en dos líneas: nombre y vencimiento arriba, quién y repetición abajo.

= 4.60.2 =
* Finanzas en el teléfono: «Cobrado sin facturar» y «Lo próximo por cobrar» ya no se desbordan ni se montan los textos; nombre y monto arriba, botón o estado debajo.
* Barra de abajo en el teléfono: la sección activa se marca en negro, con trazo más grueso y una rayita arriba.

= 4.60.1 =
* Correo al estudio por tarea vencida: el asunto ahora dice quién y qué, por ejemplo «Café Raíz tiene una tarea atrasada: Subir fotos para el carrusel».

= 4.60.0 =
* Correos: los avisos automáticos (tarea vencida, nuevas fechas, facturas y los que te llegan al estudio) usan el mismo diseño que las plantillas de correo: logo, antetítulo dorado, título en versalitas, recuadro y botón negro redondeado.
* Correo «Tarea del cliente vencida»: la tarea, el proyecto y el atraso van en un recuadro.

= 4.59.1 =
* Portal móvil: lo que se puede pagar ya va dentro de «Te toca a ti», bajo «Por pagar», y cuenta en sus pendientes. El panel «Por pagar» aparte solo queda si hay cuotas al entregar o en confirmación.
* Portal: el detalle de una cuota ya no muestra «· ·» cuando el título del proyecto termina en separador.

= 4.59.0 =
* Cotizaciones: nuevo interruptor «Aceptó de palabra» en Aceptación. Las cuotas con fecha aparecen en el portal como pendientes y se pueden pagar aunque falte la firma; la cotización no caduca y la firma le sigue apareciendo al cliente.

= 4.58.4 =
* Portal móvil: la barra de abajo ya no muestra el nombre del cliente; los iconos vuelven a tener espacio.

= 4.58.3 =
* Portal: cada publicación en las etapas muestra su detalle abierto, sin colores, y sus subtareas quedan plegadas bajo «Tareas · X de Y listas», con aviso si alguna te toca a ti.

= 4.58.2 =
* Portal: al desplegar cualquier publicación en las etapas se ve la misma tarjeta (fecha grande + detalle).
* Portal: las tareas y etapas hechas muestran solo un ticket fino, sin el círculo verde.

= 4.58.1 =
* Publicación: se quita el checklist (de la tarea y del portal).

= 4.58.0 =
* Portal › próxima publicación (diseño A aprobado en la vista en vivo): recuadro de línea fina con la fecha grande a la izquierda (día, día de la semana, mes y hora) y el detalle a la derecha, con «Próxima publicación · en N días».
* Portal › publicación: el copy es lo principal y los hashtags van debajo; arriba el formato, el tema y con qué se enlaza; abrir el archivo es un ícono redondo. La voz en off se toca entera para abrir el modo enfoque y grabar («Toca para leerla en pantalla completa y grabarla»).
* Publicación: checklist (Video, Historia, Voz en off, Música, Subtítulos, Copy, Encuesta, Hashtags, Programada). El estudio lo marca en la tarea; el cliente ve cómo va («7 de 9» con barra).
* Portal › etapas: la próxima publicación queda marcada en la lista y plegada, porque arriba ya se ve completa.

= 4.57.2 =
* Portal: se pone al día en segundos. Cada cambio en el CRM sube un contador y el portal pregunta cada 3 segundos (una consulta liviana que no carga el sitio); si algo cambió, recarga dejando todo como estaba.

= 4.57.1 =
* Portal › publicaciones: rediseño con la línea de la plataforma. Los datos van en filas (rótulo a la izquierda, valor a la derecha) y los textos largos con su rótulo arriba; sin cajas de color ni botones. «Abrir» el archivo y «Grabar» la voz en off pasan a ser enlaces de texto con ícono fino. La próxima publicación lleva su antetítulo ámbar y una línea a la izquierda.
* Portal: «Enlaces y archivos» como enlaces de texto con ícono, sin pastillas grises.

= 4.57.0 =
* Portal: se actualiza solo cada 15 segundos y al volver a la pestaña; al recargar deja todo como estaba (sección, ventana abierta y posición). Si el cliente está escribiendo no lo interrumpe: aparece «Hay novedades · Actualizar». Si la página quedó abierta mucho tiempo, se renueva sola.
* Portal › voz en off: botón «Clic para grabar la voz en off» que abre un modo enfoque a pantalla completa con el texto en grande (A−/A+), graba desde el micrófono del navegador, permite escucharla, volver a grabar y enviarla al estudio.
* Tareas: la voz en off grabada por el cliente aparece en el bloque de la publicación, con reproductor y enlace para descargarla; al estudio le llega un aviso por correo.

= 4.56.0 =
* Publicación (tareas y plantillas): nuevos campos Formato (Reel, Carrusel, Post, Historia, Instants, En vivo), Tema, Hashtags, Texto de la voz en off y Encuesta; en un reel, «Reel de prueba» y «Se enlaza con».
* Portal › detalle del proyecto: el detalle de cada publicación se ve abierto y se puede plegar. Arriba, bajo «Lo próximo», aparece destacada la próxima publicación con todo su detalle.
* Portal › detalle del proyecto: en las tareas que le tocan, el cliente puede marcarlas como listas con el círculo y dejar una nota, sin salir de la ventana.

= 4.55.2 =
* Portal › detalle del proyecto: las subtareas van más adentro que su tarea, con una guía fina y «↳», para que se lea que cuelgan de ella.
* Portal › detalle del proyecto: se quita el botón «Ver la cotización».

= 4.55.1 =
* Portal: la ventana con el detalle de un proyecto es más grande.
* Portal: las tareas marcadas como publicación se despliegan y muestran el día y la hora en que se publica, el copy y el botón «Ver el archivo».

= 4.55.0 =
* Etapas y tareas (negocios y plantillas): ícono para duplicar una fila con todo lo que cuelga de ella. La copia queda al final de su grupo con el número siguiente, sin marcar, sin fecha de publicación y sin la nota del cliente.

= 4.54.1 =
* Plantillas: al aplicarlas, el número ya no se repite en el nombre de cada tarea («2.1 Publicación 1» → «Publicación 1»). Los negocios a los que ya se les aplicó se corrigen solos.

= 4.54.0 =
* Plantillas: etiquetas (por ejemplo «RRSS»), con sugerencias, visibles en la lista de plantillas. En una plantilla RRSS, las tareas de publicaciones (Publicación, Post, Reel, Carrusel, Historia) traen marcado «Es una publicación» y su bloque de copy, archivo y fecha; al aplicarla, las tareas del negocio quedan igual.
* Tareas: interruptor «Es una publicación» que muestra u oculta el bloque de publicación. Si no se decide a mano, se deduce del nombre de la tarea.
* Borrar, en toda la plataforma: la papelera pasa a ser una × de línea fina en el rojo de Malka (#EA3336).
* Negocios: el estado se cambia desde la columna Estado de la lista, con un menú, sin entrar al negocio.

= 4.53.1 =
* Portal: arriba del menú va sólo el nombre de la empresa del cliente (o su nombre si no tiene empresa), sin «Hola».

= 4.53.0 =
* Tareas: nuevo bloque «Publicación» en el detalle de cada tarea, con el copy, el enlace al archivo y la fecha y hora en que se publica (sale en el resumen de la fila: «sale 25 oct 18:30»). Se pliega mientras está vacío.
* Plantillas: cada línea puede traer notas, copy y enlace al archivo; al aplicar la plantilla pasan a las tareas del negocio.

= 4.52.1 =
* Portal: arriba del menú dice «Hola» y debajo el nombre del cliente con «!»; el logo del estudio pasa abajo, sobre «¿Dudas? Escríbenos».

= 4.52.0 =
* Portal: se pone al día solo. Cada medio minuto, y al volver a la pestaña, revisa si el estudio cambió algo y recarga en la misma sección (no recarga con una ventana abierta ni con algo escrito a medias).
* Portal › Proyectos: barras, porcentajes y flechas en columnas fijas, alineadas en todas las filas.
* Portal › Cotizaciones: pestañas por estado (Todas, Sin respuesta, En curso, Finalizada…). Las cotizaciones, y «Revisar y firmar», se abren en ventana emergente; al cerrarla la página se actualiza.
* Portal › Facturas: cada documento tiene ver (ventana emergente), descargar e imprimir. Si no hay PDF subido, se muestra una representación del documento con los datos del estudio y del cliente, lista para imprimir o guardar en PDF.

= 4.51.0 =
* Portal › Próximos proyectos: botón «Empecemos» en cada próximo proyecto. Abre una ventana para confirmar y dejar una nota opcional; al confirmar, la fila queda como «Lo pediste».
* Al estudio le llega un correo, se crea la tarea «Cotizar … (lo pidió desde su portal)» para hoy y la idea queda destacada en la ficha del cliente con «El cliente quiere empezar» y lo que escribió.

= 4.50.3 =
* Asistente: el botón del chat sólo aparece si hay una clave de la API guardada. Sin clave no se muestra nada.

= 4.50.2 =
* Portal: línea separadora entre el encabezado de cada panel y su contenido.
* Portal › Proyectos: nueva pestaña «Próximos proyectos» con los negocios que todavía no parten (Briefing) y los próximos proyectos que el estudio deja ver. Cada pestaña vacía muestra un aviso.
* Cliente › Próximos proyectos: un ojo en cada idea para elegir si el cliente ve su nombre en el portal (la nota y el monto nunca se muestran).

= 4.50.1 =
* Plantillas: arreglado que las etapas y tareas agregadas a una plantilla vacía se perdieran al guardar (se creaban con el nombre de campo del negocio).
* Plantillas: el editor de una plantilla muestra las pestañas Negocios · Plantillas para volver a la lista.
* Portal: «Necesitamos esto de ti para avanzar» se destaca: fondo cálido, borde ámbar que respira suavemente, aparece con una animación y lleva el antetítulo «Te toca a ti · N pendientes» con un punto que late.

= 4.50.0 =
* Asistente: chat con Claude en todo el escritorio (botón abajo a la derecha). Busca clientes, negocios y cotizaciones; dice cuánto falta cobrar y lo cobrado; lista las tareas pendientes; crea tareas, anota próximos proyectos, da tareas por listas y arma cotizaciones con los precios que ya usaste. Antes de crear o cambiar algo pide confirmación con un botón. Redacta correos para copiar. Se configura con una clave de la API de Anthropic en Configuración › Estudio › Asistente.
* Negocios: ícono de papelera al final de cada fila para mandar un negocio a la papelera (pide confirmación).
* Estados en toda la plataforma (En proceso, Briefing, Entregado, Sin respuesta…): sin la pastilla de color de fondo; ahora un punto y el texto en versalitas del color del estado.

= 4.49.3 =
* Negocio: se quita «Ver cotización →» del cuadro de pagos; la cotización se abre desde su fila en «Cotización y facturas».

= 4.49.2 =
* Negocio: el enlace «Ver la cotización como la ve el cliente» se reemplaza por un ícono de ojo en la fila de la cotización, que abre la vista del cliente en ventana emergente.

= 4.49.1 =
* Negocio › Cotización y facturas: la cotización, cada factura, «Ver cotización» y «Ver la cotización como la ve el cliente» se abren en ventana emergente.

= 4.49.0 =
* Cliente: nuevo panel «Próximos proyectos» arriba de su ficha, sólo para el estudio. Se anota lo que viene (nombre, nota, monto aprox.) y cada idea pasa a negocio o a cotización con un clic, con el cliente y el nombre ya puestos.
* Negocio: «Generar cotización» crea la cotización ya enlazada al negocio y con los datos del cliente. Cuando el cliente la acepta, se usa ese mismo negocio (no se crea otro).
* Portal: una cotización recién creada y todavía sin ítems no se le muestra al cliente hasta que tenga contenido.
* Portal: cada proyecto tiene una flecha que despliega y oculta sus tareas pendientes (las del cliente en ámbar).
* Portal: en la tarea que le toca al cliente puede dejar una nota, al marcarla lista o por sí sola («Enviar sólo la nota»). Al estudio le llega por correo y se ve en Tareas y en el detalle de la tarea del negocio.

= 4.48.4 =
* Negocio › Etapas y tareas: una etapa sin tareas adentro tiene su círculo para darla por lista; la que tiene tareas lo esconde (se completa sola).
* Ficha del negocio: el número y el estado pasan a ser una línea pequeña en mayúsculas sobre el título (N° 612 · ● ENTREGADO), sin chips sueltos. Siguen siendo editables.
* Tareas: nuevo panel «Lo que hizo el cliente» con las tareas del cliente que quedaron listas en las últimas dos semanas; las que marcó desde su portal dicen «La marcó el cliente».

= 4.48.3 =
* Portal del cliente: las cotizaciones por firmar llaman la atención con una animación suave: un lápiz dibuja una rúbrica en el botón «Revisar y firmar» y el punto ámbar late. Se apaga si el equipo tiene activado «reducir movimiento».

= 4.48.2 =
* Portal del cliente: el botón principal (Revisar y firmar, Pagar con transferencia, Marcar como listo) pasa de relleno negro a línea fina, más liviano a la vista.

= 4.48.1 =
* Finanzas › Resumen: «Cobrado sin facturar» y «Lo próximo por cobrar» se abren en ventana: el negocio, la factura nueva o la cotización, sin salir de la página.

= 4.48.0 =
* Negocio: «Enlaces y archivos para el cliente»: enlaces con nombre (Ver informe) o archivos para descargar (Informe RRSS). Se ven en el portal, en el proyecto y en su ventana de detalle.
* El aviso de devolver dice cuánto hay en la cuenta, cuánto hay que guardar y de qué (F29, IVA del mes, costos, colchón).
* El conteo de tareas de un negocio incluye las etapas sin tareas adentro.

= 4.47.1 =
* Portal: al tocar un proyecto (en Inicio o en Proyectos) se abre su detalle en una ventana: estado, avance, etapa actual, lo próximo, entrega y las etapas con sus tareas (quién y cuándo), más el enlace a la cotización.

= 4.47.0 =
* Portal: «Necesitamos esto de ti» y Tareas muestran primero las cotizaciones pendientes de firma, con «Revisar y firmar».
* Portal › Proyectos: pestañas En curso / Entregados / Todos; el estado de cada proyecto con su color (entregado en verde).
* Portal: lo pendiente (cuotas por pagar) resalta con fondo y punto de color; lo ya pagado queda en un tono suave.

= 4.46.6 =
* Portal: en las listas (proyectos, pagos, documentos, cotizaciones) el estado también va como antetítulo sobre el nombre, sin etiquetas sueltas.

= 4.46.5 =
* Portal › Tareas: línea de separación entre el título del panel y las tareas; en «Hechas» el «Listo» va en el texto y no como etiqueta.

= 4.46.4 =
* Portal: en todas las tarjetas (proyectos, por pagar, aprobaciones) el estado va como antetítulo chico sobre el nombre en vez de una etiqueta abajo; los botones de pago van a lo ancho.

= 4.46.3 =
* Portal: en la ventana de una tarea, «Te toca a ti» pasa a ser un antetítulo chico (en rojo si está atrasada) y se quita el botón «Cerrar» (queda la ×), también en el detalle de pagos. El contacto de abajo va en líneas y el correo ya no se corta.

= 4.46.2 =
* Portal del cliente: abajo se ve el correo del estudio escrito (hola@…) en vez de «Correo».

= 4.46.1 =
* Portal del cliente: cada tarea suya se abre en una ventana con su detalle (proyecto, fecha y lo que se necesita, desde las notas de la tarea) y el botón «Marcar como listo». Sin botón «Ver»; la fecha va dentro de la tarea, en rojo si está atrasada.

= 4.46.0 =
* Cotización: panel «Negocio enlazado» con el negocio (o los negocios) que salió de ella, su estado y su avance; si no tiene, botón para crearlo.

= 4.45.0 =
* Negocio › Cotización y facturas: basurero a la derecha de cada factura para mandarla a la papelera sin salir de la página.
* «Registrar factura» se abre en una ventana encima del negocio; al anotar se cierra y la lista se pone al día. Abrirla y cerrarla no crea nada.

= 4.44.1 =
* Si una cotización quedó con dos negocios (el armado a mano y uno creado solo), se queda con el que se trabaja y el vacío va a la papelera; el portal muestra el correcto.
* Un negocio armado sólo con etapas calcula su avance con las etapas marcadas.

= 4.44.0 =
* Ficha del cliente: «Tareas pendientes del cliente» en todos sus negocios, con «Recordar» para mandarle el aviso por correo.
* Plantillas editables para el recordatorio de una tarea por vencer y el aviso de una tarea atrasada.
* Plantillas de correo: duplicar cualquiera, renombrar y eliminar las propias; las de fábrica se pueden devolver a su texto original. Las propias se eligen al escribir a clientes.
* Portal del cliente: casilla para marcar como lista cada tarea de «Necesitamos esto de ti»; el estudio recibe el aviso.

= 4.43.0 =
* Al asignarle una tarea al cliente le llega un correo (varias juntas van en uno solo), con plantilla editable en Configuración › Correos.
* Registro de correos: cada correo que sale queda anotado, y en la ficha del cliente se ven los que se le enviaron, con su contenido.
* Teléfono: las pestañas se deslizan de lado, la lista de negocios y la de clientes se acomodan, y ninguna página se desborda.
* Corregidos avisos de PHP en tareas sin avance.

= 4.42.0 =
* Cuentas bancarias: varias cuentas, cada una con su saldo editable; los movimientos y las cartolas van a una cuenta; el saldo total es la suma. Categoría «Traspaso entre tus cuentas».
* Clientes: columna «Portal» primera; si está abierto, un clic lleva al portal del cliente.
* Cada cotización aceptada o firmada tiene su negocio enlazado, creado solo y sin duplicar.
* Negocios: «Omitir factura» con nota del porqué; deja de contar como por facturar.
* Portal del cliente › Pagos: el título es la cotización y el detalle dice la cuota, el negocio y la fecha.
* Árbol de tareas: «+» en cada fila para agregar una tarea dentro de una etapa o una subtarea dentro de una tarea.
* Íconos lineales de trazo fino en todo el panel y el portal; play y pausa ahora lineales.
* Espaciado parejo entre filtros, etiquetas y tablas.

= 4.41.0 =
* «Qué veo en tus números» ahora es análisis del negocio con consejos: si tus ventas cubren sueldo y costos, tendencia trimestral, peso y alza de las compras, concentración, clientes que dejaron de comprar, recurrencia, factura promedio, tasa de cierre, estacionalidad y tarifa. Lo operativo (F29, facturas por emitir) pasó a «Tu día».
* Nuevo cuadro «Tus retiros» (Movimientos e Inicio): lo retirado en el año contra lo que ganó el negocio, mes a mes, y cuánto devolver.
* Contabilidad › Mes a mes: el detalle muestra la cuenta del F29 en simple, ventas y compras del mes, y las casillas del SII plegadas.

= 4.40.0 =
* Meta del mes: suma los pagos de cotizaciones recibidos en el mes (sin IVA), con la lista desplegable. Finanzas › Cobrado usa la misma lista.
* Las cuotas pagadas sin fecha de pago cuentan en su vencimiento.
* La fila «Total» de cotizaciones antiguas ya no se cuenta como otra cuota por cobrar.
* Inicio › Por cobrar: el total coincide con Finanzas; los saldos que esperan la entrega van aparte.
* Documentos: el resumen del mes muestra el F29 real (crédito, remanente, PPM); la lista carga de a 40 con «Ver más».
* Razones sociales largas abreviadas (EIRL, SpA, Ltda.).
* «Qué veo en tus números» sin avisos repetidos.
* Diseño: botones de crear con «+», sin botón duplicado en Movimientos, formulario de tareas en una fila, Horas a lo ancho.
* Corregidos avisos de PHP en tareas sin número.

= 4.39.3 =
* Meta del mes: el neto de cada cotización firmada sale de sus ítems (antes un total guardado con puntos se leía mal) y se puede desplegar la lista de las que suman.

= 4.39.2 =
* Configuración: «Tu nombre en la cartola» para reconocer tus transferencias como retiro, aunque el banco corte el nombre.
* Las transferencias a tu nombre que quedaron como gasto se corrigen a retiro, y con eso aparece el aviso de devolver.

= 4.39.1 =
* Meta del mes: cuenta toda cotización con la firma completa (nombre, RUT y fecha), electrónica o cargada a mano, en el mes de la firma.

= 4.39.0 =
* El importador lee el F29 compacto que baja del SII.
* Inicio: la meta del mes cuenta solo cotizaciones firmadas, aceptadas o en curso.
* «Qué veo en tus números» lee facturas, F29, compras por emitir y ventas; las filas se abren con un clic.
* El F29 de «Tu día» usa el mismo total que Contabilidad.

= 4.38.0 =
* Registrar movimiento rediseñado: Salida o Entrada como botones, monto con puntos de miles, fecha, descripción, referencia (N° de operación, factura…) y categoría, todo con su rótulo.
* Cada movimiento se abre al tocarlo para corregirlo o eliminarlo (el saldo se ajusta por la diferencia). La referencia aparece en la lista.
* Las transferencias a tu propio nombre (TEF a …) se reconocen como «Retiro personal», también las que ya estaban anotadas como gasto: así el aviso de cuánto devolver aparece cuando corresponde.

= 4.37.2 =
* Finanzas parte mostrando el año en curso (y los gráficos de Ventas y Compras del inicio también).
* Cada cifra del resumen de Finanzas lleva a su detalle al tocarla: Cobrado y Saldo a Movimientos, Por cobrar a Cobranza, Dado por perdido a Perdidas, Facturado y Compras a Documentos, IVA a Contabilidad.

= 4.37.1 =
* Contabilidad reordenada: arriba el próximo F29 en grande con su desglose y, al lado, los próximos vencimientos; debajo «Mes a mes» con cuatro columnas (IVA ventas, IVA compras, a pagar y estado), los últimos seis meses a la vista y el resto plegado; cada mes se abre con sus casillas. Al final, el importador de libros y F29 y «Cómo se calcula» plegado.

= 4.37.0 =
* Movimientos de la cuenta: botón «Registrar movimiento» en el mismo panel, y el movimiento ahora lleva fecha (no sólo la de hoy).
* Cartola del banco: se arrastra (PDF, CSV, TXT o Excel en tabla) y se ven sus movimientos con la categoría sugerida (impuesto, ingreso, costo fijo si coincide con uno de tus costos, etc.) para corregir antes de registrar. Lo que ya estaba (mismo monto y tipo, hasta 3 días de diferencia, como los pagos que entran solos) no se duplica. Si la cartola trae saldo, se propone dejar la cuenta en el último saldo.

= 4.36.7 =
* Modo enfoque: botones de Pausa y Reiniciar junto a Iniciar; «Salir del enfoque» queda aparte, como enlace.

= 4.36.6 =
* Modo enfoque más ordenado: un solo cuadro para escribir con «Para» y «Anotar» abajo, y la lista de pendientes con el nombre arriba y a quién está asignada debajo (se cambia tocándolo).

= 4.36.5 =
* Modo enfoque con el mismo estilo de la plataforma: fondo gris, panel blanco redondeado, campos y filas como en el resto.

= 4.36.4 =
* Modo enfoque: «Asignar a» tiene buscador (se escribe parte del negocio o del cliente, flechas y Enter para elegir).

= 4.36.3 =
* Modo enfoque: al anotar se elige a quién se asigna la tarea (lo del pomodoro, el estudio o cualquier negocio abierto), y cada tarea de la lista se puede reasignar ahí mismo.

= 4.36.2 =
* «Lo que se paga pronto»: cada pago separado por una raya.

= 4.36.1 =
* «Cuánto puedes retirar»: cada línea de la resta va separada por una raya.

= 4.36.0 =
* Tareas que se repiten: todos los días, días hábiles, cada semana, cada 2 semanas, cada mes, cada 3 meses o cada año. Se elige al agregarla, en la lista (se guarda solo) o en la tarea dentro del negocio. Al marcarla hecha no desaparece: pasa a su próxima fecha.
* Modo enfoque en dos columnas: el reloj a la izquierda y a la derecha un lugar para anotar lo que se te viene a la cabeza; queda como tarea del negocio en que estás trabajando (o del estudio) y se puede marcar hecha ahí mismo.
* El pomodoro parte por defecto en «Trabajo del estudio».

= 4.35.0 =
* Contabilidad pasa a su propia pestaña en Finanzas, al lado de Movimientos (que queda solo para la cuenta bancaria y el retiro), con un botón para importar libros y F29 ahí mismo.
* Al importar el libro de la contadora, lo anotado en ese mes que ella no incluyó se propone mover al mes siguiente («No está en el libro»), para que el mes cuadre con su declaración.

= 4.34.1 =
* Finanzas: los gráficos de ventas y compras van separados por una línea al medio.
* Cada cifra del resumen (Cobrado, Por cobrar, Dado por perdido, Facturado, Compras, IVA y Saldo) tiene una «i» que explica qué incluye al pasar el mouse.

= 4.34.0 =
* Contabilidad: cada mes se abre para ver su F29 casilla por casilla, con los códigos del formulario (586/142, 503/502, 110/111, 509/510, 538, 584/562, 519/520, 527/528, 504, 537, 89, 77, 151, 39, 563/115/62 y 595), listo para declarar en sii.cl o compararlo con la propuesta del SII.
* Próximos vencimientos: F29 del mes con su monto, DJ 1879 si retuviste honorarios y la renta (F22) de abril.
* Los documentos en /bin/bash no se cuentan en el F29.

= 4.33.1 =
* Contabilidad cuadra con el F29 de la contadora: el importador lee su libro de compras y de ventas (Nubox y similares) y asigna cada documento al mes en que se declara, que no siempre es el de su fecha.
* El importador también lee el borrador del F29: guarda débito, crédito, remanente, IVA, PPM y total de ese mes, y su remanente pasa al mes siguiente. Los meses con F29 llevan la marca «F29».
* Nueva tasa de PPM en Configuración (se toma sola del F29 importado); el PPM se suma al total de cada mes.

= 4.33.0 =
* Inicio: Ventas y Compras se ven por 12 meses, por año (a elegir), por años o por los últimos 30 días; cada columna mensual lleva su mes (Ene, Feb…). La vista elegida se recuerda.
* Movimientos más ordenados: ícono de entrada o salida, fecha y categoría en una línea, y la factura enlazada debajo.
* Si retiraste más de lo seguro, el inicio y el panel de retiro dicen cuánto devolver a la cuenta de la empresa.
* Finanzas: el resumen se ve por día, mes, 12 meses, año o todos los años, con ventas y compras en barras y el detalle por tipo de documento (facturas, exentas, boletas, honorarios, notas de crédito, facturas de compra).
* Nueva Contabilidad en Movimientos: el F29 de cada mes con IVA de ventas, IVA de compras, remanente que pasa al mes siguiente, retenciones (honorarios y facturas de compra), lo que hay que pagar y si ya está pagado. El retiro usa este mismo cálculo.
* Corregido: los nombres de mes salían corridos uno hacia atrás (por la zona horaria) en gráficos, retiro y selectores.

= 4.32.14 =
* Importar del SII: lee la descarga de documentos del SII (DTE_DOWN…, en .xls o .txt). Distingue ventas de compras por el RUT de quien descargó, toma el neto, exento e IVA tal cual y guarda giro, dirección, comuna y ciudad de la contraparte.

= 4.32.13 =
* Importar del SII: acepta también los archivos TXT y XLS que exporta el SII (además de CSV y PDF). Las columnas se reconocen por su nombre.

= 4.32.12 =
* Importar del SII: se pueden soltar varios archivos a la vez (todas las páginas de emitidos y recibidos) y se suman; también acepta el CSV del Registro de Compras y Ventas, uno o varios meses. Se importa de a 80 para que cientos de documentos no corten el servidor.
* Se quitó el tope de 500 documentos, clientes, proveedores y cotizaciones.

= 4.32.11 =
* Lista de documentos: el estado queda centrado bajo su columna.

= 4.32.10 =
* Lista de documentos: el Nº va arriba en grande y el tipo de documento abajo, más pequeño.

= 4.32.9 =
* Finanzas: botón «Importar del SII». Se arrastra el PDF de Administración de documentos (emitidos, recibidos o los dos) y antes de guardar se ve cada documento como Nuevo, Ya estaba o Repetido. Las facturas de compra emitidas van a «Facturas de compra por emitir» y, si había una pendiente del mismo proveedor, se marca emitida con su número.
* Los nombres de clientes y proveedores se muestran con mayúscula inicial en cada palabra (las siglas como SpA, S.A. o LLC se respetan).
* «Facturas que emito yo» pasa a llamarse «Facturas de compra por emitir».

= 4.32.8 =
* Facturas que emito yo: cada factura se puede plegar y deja a la vista sólo la cabecera; botón para ocultar o mostrar todos los detalles (se recuerda).
* El Nº de factura emitida pasa a la cabecera, visible aun con el detalle oculto.

= 4.32.7 =
* «Facturas que emito yo»: filtro por estado (Todas, Faltan datos, Listas para emitir, Emitidas) y orden por estado, fecha (nueva o antigua) o empresa. El orden elegido se recuerda.
* «Cómo la pagaste» en su propio recuadro, igual al cálculo del SII: lo pagado en pesos, el cambio que cobró la tarjeta, la diferencia con el SII y «Pagada con». Los datos vacíos muestran una raya en vez de quedar en blanco.

= 4.32.6 =
* «Facturas que emito yo» reordenada: cada factura es una tarjeta con cabecera (proveedor, N°, fecha, precio y estado), a la izquierda «Su factura» y «Cómo la pagaste», a la derecha «Para emitir en el SII» con el cálculo del neto en su propio recuadro, y abajo el Nº de factura emitida, abrir y papelera.

= 4.32.5 =
* «Facturas que emito yo»: nuevo campo «Lo que pagaste en pesos», en la lista y en el documento. Muestra el dólar o euro que te cobró la tarjeta (MACH u otra) y la diferencia con el neto que va al SII. Si la factura se enlaza a un pago o movimiento ya pagado, se completa con ese monto.

= 4.32.4 =
* «Facturas que emito yo»: la moneda del precio se elige junto al precio (USD, EUR o $). Al pasar a dólar o euro se busca el cambio del Banco Central del día y aparece el enlace para corroborarlo.
* CORRECCIÓN: una factura en dólares sin moneda guardada quedaba en pesos al guardar el documento.
* El pie de cada factura queda ordenado: estado, Nº de factura, abrir y papelera, «Pagada con» y el enlace al cambio.

= 4.32.3 =
* «Facturas que emito yo»: al escribir el proveedor, los datos para el SII (y los de su factura que falten) se completan solos desde su ficha en Proveedores. Las pendientes sin datos cuyo proveedor ya tiene ficha se completan al abrir la lista. Lo ya escrito no se pisa.
* Se reconoce al proveedor aunque se escriba distinto («Anthropic» encuentra «Anthropic, PBC»).
* Las facturas de compra se enlazan con un pago del estudio o un movimiento («Pagada con», en la lista y en el documento). La de Adobe se enlaza sola con el pago «Adobe CC» de ese mes; al marcar ese pago como pagado, queda enlazada también al movimiento. En Movimientos y en los pagos de Tareas se ve la factura enlazada.
* Cotizaciones: ícono para duplicar, en la lista y dentro de la cotización. La copia sale con número nuevo, fecha de hoy, «Sin respuesta» y sin pagos.

= 4.32.2 =
* CORRECCIÓN: al crear un proveedor, el guardado automático creaba una ficha nueva en cada pausa. Ahora se crea una sola y se sigue guardando esa; y si ya existe una con el mismo nombre, se usa esa.
* Proveedores: papelera en cada fila y casillas para elegir varios y mandarlos a la papelera de una vez (con «elegir todos», que respeta la búsqueda).

= 4.32.1 =
* «Facturas que emito yo»: cada factura tiene su papelera, con confirmación. Al borrar, se vuelve a la misma pestaña.

= 4.32.0 =
* Configuración y Mensajes son una sola entrada, con pestañas: Estudio, Marca, Cobros, Tarifa y costos, Correos y Textos de la cotización. Los correos se editan de a uno, con la lista a la izquierda.
* Plantillas pasa a ser una pestaña de Negocios, y Horas una pestaña de Tareas. El menú queda más corto.
* Nueva sección Proveedores: cómo aparece cada uno en sus facturas y con qué datos se le emite la factura de compra en el SII. Cada factura nueva de ese proveedor se completa desde su ficha, y lo que se corrige en una factura vuelve a la ficha.
* «Facturas que emito yo»: la factura del proveedor a la izquierda y lo que va en el SII a la derecha (razón social, RUT, giro, dirección, comuna, ciudad, fecha de emisión, tipo de cambio y neto en pesos). Todo se edita en la misma lista, cada dato del SII se copia con un ícono, y al poner el Nº de factura queda emitida y cambia el fondo.
* Cuadro para arrastrar facturas del extranjero (una o varias, en PDF): se leen proveedor, número, fecha, monto y moneda.
* Los datos de un proveedor nuevo se buscan en la nómina de prestadores extranjeros del SII; si no está inscrito se usa el RUT 55.555.555-5. Si hay dudas, se muestran las parecidas para elegir.
* El neto en pesos sale del tipo de cambio del Banco Central del día de emisión (último día hábil si es fin de semana o feriado), con un enlace para corroborarlo.
* Tareas muestra los pagos del mes (costos fijos e impuestos) para marcarlos al pagar; los atrasados cuentan en el círculo del menú.
* El logo de la barra lateral se ve más chico.
* CORRECCIÓN: las pestañas de arriba de una sección dejaban vacía la lista de negocios.

= 4.31.0 =
* Portal del cliente rehecho con la misma tipografía y los mismos componentes del escritorio: menú a la izquierda (Inicio, Proyectos, Tareas, Pagos, Facturas, Cotizaciones) y, en el teléfono, una barra abajo.
* Inicio del portal: resumen arriba (Por pagar, Próximo pago, Avance) y tres columnas con el mismo orden: «Necesitamos esto de ti para avanzar», «Tus proyectos» y «Por pagar».
* Nueva sección Tareas, con las aprobaciones adentro: lo pendiente del cliente y lo que ya entregó o aprobó.
* Tareas y Pagos muestran un círculo rojo con lo pendiente. Pagos cuenta también lo que se cobra al entregar.
* Cada pago pendiente tiene «Pagar con transferencia» (destacado) y «Pagar con SumUp». Los dos llevan a la cotización con la cuota y el medio ya elegidos.
* Los pagos realizados tienen «Ver detalle»: el comprobante en una ventana, sin salir del portal.
* Las facturas se pueden ver, descargar o imprimir desde su fila.
* Las cotizaciones se abren tocando la fila; se quitó el botón «Abrir». En proyectos se quitó «Ver avance».
* CORRECCIÓN: una factura que el portal mostraba por su cotización no le abría al cliente si el RUT anotado en el documento era otro.

= 4.21.0 =
* El Inicio lo armas tú: cada cuadro se arrastra por su asa —o se mueve con Alt y las flechas—, se le elige el ancho entre un tercio, la mitad, dos tercios y todo el ancho, y se esconde el que no mires. Se guarda solo, sin botón que apretar. Mientras personalizas, lo escondido se ve en fantasma en su sitio para saber dónde volvería.
* Tres cuadros nuevos: «Ventas por mes» y «Compras por mes» —doce meses de barras, netos, desde lo que anotaste en Contabilidad— y «Lo que más vendes», por ítem de las cotizaciones que ganaste. Con un solo mes de datos no se dibuja un gráfico: se dice el número, porque una barra sola no se compara con nada.
* Nueva sección «Pérdidas», dentro de Cobranza. Una cuota que ya no vas a cobrar se da por perdida: sale de Cobranza, deja de sumar al contador del menú, desaparece de «Por cobrar» del inicio y los avisos automáticos se callan. Para el cliente no cambia nada —su cotización, su portal y sus correos siguen diciendo lo mismo—, porque es una decisión tuya, no un perdón. Se deshace cuando quieras, y si al final paga sale sola de la lista.
* En Cobranza, cuando hay cuotas dadas por perdidas se dice cuántas son y por cuánto, con un enlace para verlas: una cuota que desaparece sin dejar rastro hace dudar del panel entero.
* El PDF de un documento que pese de más ahora se comprime solo, si el servidor tiene Ghostscript. El tope ya no es un número escrito a mano: es el más chico entre el del plugin —20 MB— y el que de verdad deja pasar tu hosting. Y cuando no cabe, el aviso dice cuánto pesa, cuánto cabe y por qué, en vez de «revisa que sea de menos de 3 MB».
* CORRECCIÓN: un PDF que PHP cortaba antes de llegar —por pasarse del límite del servidor— se perdía en silencio. El documento quedaba guardado y nadie decía que el archivo no estaba.
* El aviso de la ficha de documento ocupa el ancho completo, no la mitad.
* CORRECCIÓN: la vista previa de los mensajes ignoraba la frase del interés que estabas escribiendo y mostraba la de fábrica.
* {interes_monto} ahora se lleva su frase: si esa cuota no tiene interés acumulado, la oración entera donde lo pongas desaparece sola, en vez de dejar «un monto de  de interés por atraso».
* El campo «La frase del interés» sólo aparece mientras el cuerpo del mensaje use {interes}. Si escribiste la frase a mano, ese cuadro no hacía nada.
* El plazo del saldo de fábrica pasa de 1 a 3 días hábiles, y el ejemplo de la plantilla de entrega ya no dice «10 días hábiles» pase lo que pase: lee tu ajuste de Configuración. Una vista previa que miente es peor que no tenerla.
* Movimientos cambia de reparto: «Cuánto puedes retirar» se abre en dos columnas —la resta a un lado y lo que la explica al otro— en vez de dejar medio panel en blanco, y la lista de movimientos se lleva el ancho que necesita. El saldo ya no sale dos veces en la misma tarjeta.
* CORRECCIÓN: la etiqueta de categoría de un movimiento quedaba en 4,2:1 sobre el relleno gris. Texto chico necesita 4,5.

= 4.20.0 =
* La frase del interés es editable: {interes_monto} trae el monto y tú escribes la oración.
* En la cotización, el RUT del cliente baja a su propia línea, debajo del nombre.
* Nuevo texto del aviso de transferencia y botón «Transferencia realizada».
* Mensajes se abre en dos: «Plantillas correos» y «Mensajes». Y las nueve frases que el cliente lee dentro de la cotización pasan a ser editables.

= 4.19.0 =
* Los correos a clientes dejan de ser texto plano: recuadros, títulos dorados, botón y la marca arriba. Van como correo multiparte, así que quien tenga el HTML apagado recibe el texto limpio.

= 4.18.0 =
* Ningún enlace del panel lleva a WP-Admin. Eran dieciocho, repartidos entre análisis, notificaciones, aprobaciones, el programador, plantillas, el gantt y finanzas.

= 4.17.0 =
* Las plantillas de los mensajes a clientes se crean y se editan desde el panel, con vista previa en vivo.

= 4.16.0 =
* En Contabilidad se sube el PDF de cada factura y el cliente lo descarga desde su portal. Queda en carpeta protegida, fuera de la raíz del sitio.

= 4.15.0 =
* Portal del cliente: entra por su RUT sin puntos y un código de cuatro dígitos. Ve sus facturas, sus cotizaciones, el avance de cada negocio y lo que queda por pagar. Se activa desde su ficha con un botón que le manda el correo.
* Las tareas de un negocio se reordenan arrastrándolas, y una etapa se lleva sus tareas consigo.


= 4.14.0 =
* CORRECCIÓN: los clientes sin RUT se duplicaban. El alta automática sólo buscaba una ficha existente cuando había RUT, así que cada cotización aceptada creaba otra ficha igual. Ahora desempata por nombre y empresa, y en Clientes hay un botón para juntar las repetidas que ya están: se queda la más antigua, hereda los datos que le falten y se le reapuntan sus cotizaciones y negocios.
* En Negocios, un sello de facturación junto al estado: verde cuando ya emitiste el documento —con cuántos y por cuánto— y ámbar con punto cuando el cliente pagó todo y todavía falta emitirla. Con saldo pendiente no aparece nada, porque ahí la factura todavía no corresponde.
* Nuevos estilos de párrafo del documento, editables en Configuración: tamaño y peso del texto base, el título, los rótulos de sección, las tablas, los totales y la letra chica.
* El botón «Ya hice la transferencia» ahora vive dentro de la caja de la cuenta, no suelto debajo.
* Fuera las líneas negras de acento: la del borde izquierdo de la condición de anticipo, la de arriba del selector de pago y la de la nota de avisos en el panel.

= 4.13.0 =
* CORRECCIÓN: la caja «Cotización firmada y aceptada» nunca se veía. La regla `display: none` venía de la plantilla original, donde la encendía el JavaScript; acá la imprime el servidor sólo cuando hay firma, así que quedaba escondida siempre.
* Esa caja ahora dice «RUT: …» y «Firmado el … a las …», como la plantilla. Una aceptación cargada a mano, que sólo trae el día, dice «Aceptada el …» en vez de inventar una hora.
* El logo de la cotización mide 150 px de ancho. Antes salía al tamaño natural del archivo.
* Nueva tipografía configurable: la cotización se sirve como página propia, sin el tema, así que la fuente del sitio no llegaba hasta ahí. En Configuración se escribe el nombre de la familia y la URL del .css que la define.

= 4.12.0 =
* Nuevo bloque «Aceptación del cliente» en el editor: nombre, RUT y fecha. Sirve para las cotizaciones que ya venían firmadas del sistema anterior y entraban como «no firmadas». Valida el RUT y, con los dos campos vacíos, borra la aceptación.
* La página del cliente ya no llama «firma electrónica» a una aceptación cargada desde el panel: dice que la registró el estudio.
* Primero la firma y después el pago: la forma de pago y los datos bancarios aparecen recién cuando la cotización está aceptada. Antes se le ofrecía pagar a quien no había aceptado nada.
* CORRECCIÓN: «Entregado» caía en «En curso» cuando ninguna cuota era contra entrega. Entregar es un hecho aparte del vencimiento de cada cuota: ahora decide si queda saldo, no de qué tipo es.
* Fuera el cartel «Elige arriba qué deseas pagar»: los datos siguen apareciendo al elegir, sin el texto.
* La comisión de SumUp se puede editar desde Configuración. Sólo estaba en WP-Admin, y en 0 la cotización ofrecía «+0,00%» y el descuento salía de tu bolsillo.

= 4.11.0 =
* CORRECCIÓN: una cuota marcada «Pago total» desaparecía de la cotización del cliente y no contaba como deuda, así que la cotización se daba por finalizada antes de tiempo. Ese rol estaba en un campo oculto; ahora es una columna visible, «Qué es», y se puede cambiar.
* CORRECCIÓN: elegir «Entregado» en el estado y guardar no servía de nada. Ahora marca la fecha de entrega de verdad y le avisa al cliente, igual que el botón de siempre. Volver a un estado anterior la deshace.
* Si los hechos mandan a otro estado del que elegiste, se dice en pantalla y se explica por qué, en vez de sobrescribirlo en silencio.
* Nuevo cuadre en vivo bajo las cuotas: cuánto suman, cuánto es el total y cuánto falta. Un botón pone la diferencia como cuota «Pendiente» o se la suma a la que ya exista.
* En la cotización, los datos para pagar aparecen recién cuando el cliente elige qué cuota va a pagar. Antes venía una preseleccionada y le mostraba el monto de algo que no había pedido.
* El enlace de «términos y condiciones» ya no se pierde: si no hay documento configurado, baja a las notas de la misma cotización.
* CORRECCIÓN: guardar la cotización borraba los datos del cliente que el formulario no trajera —el correo entre ellos, del que cuelgan todos los avisos—.

= 4.10.0 =
* Cada cuota guarda con qué se pagó: transferencia, SumUp, efectivo u otro. Se elige en el editor, al lado del estado, y sale en la cotización del cliente.
* El panel lo anota solo. Si el cliente elige tarjeta y aprieta pagar, queda SumUp; si avisa que transfirió, queda transferencia. Lo que escribas a mano nunca lo pisa un aviso posterior.
* Botón nuevo en la cotización: «Ya hice la transferencia». Deja la cuota «Por confirmar» —nunca pagada— con el medio anotado, y te manda un correo.
* Mientras una cuota está «Por confirmar» los recordatorios automáticos se callan, y en Cobranza la fila muestra «avisó que pagó».
* CORRECCIÓN: guardar una cotización borraba el link de pago de sus cuotas, porque el formulario no lo devolvía. Ahora viaja de vuelta.

= 4.9.0 =
* La línea de «Avance del proyecto» que ve el cliente usa la estrella de la marca en vez del rombo, en tres tonos de café: el más oscuro —con halo— es lo que está pasando ahora, el del medio lo hecho y el claro lo que viene. Se fue el verde: no es un color de la marca.
* El editor de cotizaciones reconoce al cliente mientras escribes el nombre y completa empresa, RUT, ciudad, correo y teléfono. También funciona escribiendo el RUT o la empresa.
* Propone clientes de las fichas y también de cotizaciones anteriores, así que sirve desde el primer día sin fichar a nadie a mano.
* Nunca pisa lo que escribiste a mano, y deja un «Deshacer» al lado. Si el nombre deja de calzar con la ficha, suelta solo los datos que había puesto.

= 3.4.2 =
* CORRECCIÓN: /escritorio/ devolvía 404 cuando no se regeneraban los enlaces permanentes. Las reglas ahora se regeneran solas al cambiar la versión del plugin o el endpoint.
* Respaldo adicional: si las reglas aún no existen, la página se reconoce comparando la URL directamente, así que funciona igual.

= 3.4.1 =
* El panel pasa a llamarse Escritorio y vive en /escritorio/. El submenú y el enlace de la barra de administración usan el mismo nombre.

= 3.4.0 =
* Nuevo Escritorio de dirección en estudiomalka.cl/escritorio/, privado y solo para quien administra el plugin. También disponible en Malka CRM → Escritorio y con el shortcode [malka_crm_dashboard].
* Indicador de capacidad mensual: compara los proyectos activos con el tope configurable (3 por defecto) y avisa cuando no queda cupo.
* Dos listas de bloqueo: "Te toca a ti" y "Esperando al cliente", con lo vencido y lo que vence dentro de 7 días, ordenado por urgencia.
* Bloque de cobranza: cobrado en el mes, total por cobrar y los abonos vencidos o por vencer con enlace a su cotización.
* Tarjetas de proyecto con semáforo por días de atraso, etapa actual, avance y día transcurrido sobre el total.
* Enlace "Dashboard Malka" en la barra de administración.

= 3.2.8 =
* Las fechas reparten su espacio desde los propios bloques y no desde la celda: el aire arriba, en el filete y abajo es idéntico.
* Se elimina el chip "Vence en X días" de la columna Estado. Ahora la fecha de término se tiñe con el rojo de marca (#C64536) y muestra un globo informativo al pasar el cursor.
* Los elementos de la columna Aprobación se separan con un ritmo constante de 0,5rem, sin márgenes heredados.

= 3.2.7 =
* Las fechas quedan ópticamente centradas: mismo espacio entre el borde superior y el filete, y entre el filete y el borde inferior.
* La columna Responsable centra su contenido, igual que Estado y Aprobación.

= 3.2.6 =
* "Tu tarea" adopta el mismo estilo neutro que "En curso".
* Las celdas de responsable, fechas, avance, estado y aprobación se centran verticalmente en la fila.
* El contador de correcciones pasa a dos líneas: etiqueta arriba y "0/1" debajo.

= 3.2.5 =
* Las etiquetas de estado adoptan la forma del botón: píldora con borde, mismo relleno y misma tipografía en mayúsculas.
* "Corrección enviada" y demás estados largos dejan de desbordar: ocupan el ancho de su columna y parten en dos líneas.
* Todo el texto de los botones va en mayúsculas con el espaciado de la web.
* La columna de fechas queda centrada, con un filete de separación entre inicio y término.

= 3.2.4 =
* La etiqueta "Tu tarea" deja de duplicarse: aparece solo en la columna Responsable.
* Botones con la forma del botón "Mi Cuenta" de la web: píldora con borde arena, relleno al pasar el cursor, y ancho contenido dentro de su columna.
* "Ver solicitud" pasa a ser botón.
* Aprobar, Corregir y Ver solicitud abren un diálogo modal centrado en lugar de desplegarse dentro de la celda.
* Se corrige el desborde de la barra de etapas: nombre flexible con corte de palabra y metadatos fijos con margen derecho.

= 3.2.3 =
* CORRECCIÓN: las etapas y los paneles mostraban dos flechas porque convivían dos implementaciones del indicador. Queda una sola.
* Las fechas pasan a formato de dos líneas (etiqueta arriba, fecha abajo) y dejan de desbordar su columna.
* Se elimina la negrita heredada del tema en los nombres de tarea: todo el cuerpo en peso 300.
* Padding unificado en cabeceras, celdas y barras de etapa, con margen lateral en la primera y la última columna.
* El porcentaje de la etapa deja de pegarse al borde derecho.
* Los botones de la columna de aprobación se apilan a ancho fijo para que la fila no crezca de más.

= 3.2.2 =
* La columna "Etapa / Tarea" pasa al 38% del ancho para que los nombres largos se lean completos.
* Inicio y Término se unifican en una sola columna con las fechas apiladas y su etiqueta al costado.
* Los colores se heredan de la paleta global del tema (--ast-global-color-*), con la paleta Malka como respaldo.
* Tipografía alineada al tema: familia heredada, cuerpo en peso 300 y títulos y botones en 500, un punto por debajo de la web para que la tabla respire.
* Botones con el preajuste del tema: 500, sin mayúsculas forzadas, radio de 3px y relleno equivalente al de la web.

= 3.2.1 =
* El detalle de tareas vuelve a una tabla en columnas: Etapa/Tarea, Responsable, Inicio, Término, Avance, Estado y Aprobación, con anchos fijos para que nada se desborde.
* Cada etapa es una barra plegable de ancho completo que agrupa sus tareas.
* Los controles de aprobación y las acciones del cliente quedan en línea dentro de su columna, en formato compacto.
* En pantallas menores a 860px la tabla se convierte en fichas con etiquetas, sin scroll horizontal.

= 3.2.0 =
* Rediseño completo del portal con el lenguaje visual de estudiomalka.cl: líneas de 1px, esquinas rectas, versalitas espaciadas, mucho aire y un solo acento arena.
* CORRECCIÓN: la cabecera de etapa heredaba el estilo de botón del tema y al pasar el cursor el texto quedaba ilegible. Ahora es un encabezado plano con los estilos del tema neutralizados.
* CORRECCIÓN: el bloque de aprobación acumulaba estados contradictorios (Pendiente, Bloqueada, "Se habilita al completarse" y rondas agotadas a la vez). Ahora cada estado es excluyente.
* CORRECCIÓN: el número aparecía duplicado ("0.1 0.1 Primer abono") cuando ya venía dentro del nombre.
* Botones unificados en un solo componente, con estados hover consistentes y sin interferencia de Astra.

= 3.1.1 =
* Editor de plantillas rediseñado con el mismo formato de fichas del editor de tareas: nombre legible en la línea principal y datos en rejilla con etiquetas.
* Se corrigen los anchos: "Días" y "Correcciones" pasan a campos estrechos y el nombre ocupa el espacio real que necesita.
* Color de borde por tipo también en plantillas.

= 3.1.0 =
* Detalle de tareas del portal rediseñado: se abandona la tabla de siete columnas y cada etapa pasa a ser una sección plegable con sus tareas como fichas.
* La primera etapa aparece abierta al entrar; el resto se despliega al tocarlo.
* CORRECCIÓN: las tareas asignadas al cliente no esperaban al 100% para mostrar el botón de aprobar, por lo que la aprobación nunca aparecía. Ahora se habilita de inmediato.
* Cada tarea muestra nombre, responsable y fechas a la izquierda, y avance, estado y acciones alineados a la derecha.
* Las subtareas se distinguen con un filete lateral en vez de sangrías dentro de celdas.

= 3.0.1 =
* Editor de tareas rediseñado: cada tarea es una ficha con el nombre en una línea legible y sus datos en una rejilla con etiquetas, en lugar de 14 columnas comprimidas.
* Las fichas se adaptan al ancho disponible y se reordenan igual arrastrando el asa.
* Color de borde por tipo: etapa, hito, corrección y completada se distinguen de un vistazo.
* Las subtareas quedan indentadas respecto de su tarea padre.

= 3.0.0 =
* La carta Gantt abre en vista de días por defecto.
* Detalle de tareas reordenado: nueva columna "Estado" con la etiqueta de plazo y el botón corto "Hecha"; la etiqueta "Tu tarea" pasa a la columna Responsable.
* Aprobación con nombre y RUT (antes firma libre), con aviso previo de bloqueo y constancia de conformidad.
* Botón "Corregir" para las filas con correcciones habilitadas: el cliente escribe su solicitud y la fila queda en revisión, bloqueada hasta que el estudio la reabra.
* Historial de movimientos en el portal y en WP-Admin: cada aprobación y cada corrección con fecha, nombre, RUT y texto exacto.
* La aprobación de entrega final usa el mismo flujo de nombre y RUT.
* Botón "Pagar" en cada abono no cursado, con enlace propio, URL global o la cotización como destino.

= 2.9.0 =
* Los paneles del portal arrancan cerrados: el cliente ve la estructura y abre lo que necesita.
* Aprobación con nombre y firma: aviso previo de que quedará bloqueada, campos de nombre y firma obligatorios, y registro de firmante, firma e IP visible en WP-Admin.
* Las etapas del detalle de tareas se pliegan y despliegan; sus tareas aparecen al abrirlas.
* Botón "Pagar" por abono en el portal, con enlace propio por cuota o URL de pago global configurable.
* Barra de administración dentro de /mi-cuenta/proyectos/ con accesos directos a editar proyecto, editar tareas, crear cotización, subir factura y crear proyecto.
* Cotizaciones sin número ya no aparecen sin identificar: se usa el título o el ID.
* Detalle de tareas con más aire y jerarquía visible por etapa.

= 2.8.0 =
* CORRECCIÓN CRÍTICA: el portal tenía una tabla duplicada residual que rompía el marcado y hacía que en móvil todo apareciera como texto corrido.
* El portal se organiza en paneles desplegables: Carta Gantt, Detalle de tareas, Estado de pagos y Documentos del proyecto. Se abren al tocarlos.
* Las tareas sin aprobación requerida ya no muestran la palabra "Aprobación" ni celda vacía en el portal, tampoco en móvil.
* Una vez que el cliente aprueba, la fila queda bloqueada con marca visible. El servidor rechaza cualquier cambio posterior; solo el estudio puede reabrirla desde "Aprobaciones del cliente".
* Tabla del portal rediseñada para móvil: cada tarea es una tarjeta con etiquetas legibles.

= 2.7.0 =
* Columna "Aprob." por fila: decides tú qué etapas, tareas, hitos o correcciones debe aprobar el cliente. Las no marcadas no muestran ningún control en su portal.
* Casilla "Pedir aprobación de entrega final" por proyecto: si la desmarcas, el cliente no ve el bloque de cierre.
* La columna "Corr." pasa de casilla a número. Vacío significa sin control: no aparece el contador de correcciones ni el mensaje de rondas agotadas.
* El mensaje comercial de correcciones agotadas solo se muestra cuando asignaste un número y el cliente lo consumió.
* Ambos campos viajan en las plantillas y se aplican al proyecto.
* Validación en servidor: una fila sin aprobación requerida rechaza cualquier intento de aprobación o solicitud de cambios.

= 2.6.0 =
* Tercer nivel jerárquico derivado de la numeración: "0" es etapa, "0.4" tarea y "0.4.1" subtarea. No hay campos nuevos que llenar.
* Cálculo hacia arriba: la subtarea alimenta a su tarea padre, la tarea a su etapa y la etapa al proyecto. Las filas calculadas quedan bloqueadas para evitar datos contradictorios.
* El avance del proyecto promedia solo tareas hoja: una subtarea ya no se cuenta dos veces.
* Sangría visual por nivel en el editor y en el portal del cliente.
* Vuelve la columna "Equipo" para anotar quién ejecuta, ahora separada del rol (Malka/Cliente).
* Los avisos de plazo y el conteo de aprobaciones ignoran las filas calculadas.

= 2.5.0 =
* Casilla "Hecha" por tarea: marca al 100%, recalcula el avance del proyecto en vivo y se sincroniza con el campo de porcentaje. El recálculo se dispara también al añadir o eliminar filas.
* Nuevo tipo de fila "Corrección" y casilla "Corr." para decidir en qué tareas puede el cliente pedir cambios.
* Botón "Ver solicitud" en el portal y en WP-Admin con el texto exacto que escribió el cliente y su fecha.
* "Asignado a" pasa a ser un selector: Malka, Cliente, Malka y Cliente, Ninguno.
* Período de gracia configurable (2 días): aviso en el portal y cierre automático de la tarea con correo al cliente y al estudio.
* El desplazamiento automático de fechas se aplica a los atrasos de ambas partes y respeta las tareas ya completadas.
* Estadísticas por proyecto: duración total, días transcurridos, tareas completadas y días de atraso separados por cliente y por Malka, visibles en el proyecto y en el resumen.
* Etapas plegables en el editor de tareas, con botones "Comprimir" y "Expandir".
* El administrador ve todos los proyectos, cotizaciones y facturas desde su propio panel de Mi Cuenta.

= 2.4.0 =
* CORRECCIÓN: "Aplicar plantilla" desde la pantalla del proyecto llevaba a Entradas. El formulario estaba anidado dentro del formulario de WordPress, algo inválido en HTML. Ahora se aplica mediante enlace firmado y se vuelve a la misma pantalla, con la tabla ya cargada.
* Responsable "— Ninguno —" disponible en plantillas y en tareas.
* Plantillas duplicables desde el listado, con columna de número de ítems.
* Cotizaciones del portal rediseñadas: tarjeta compacta con número, proyecto, fechas, total y estado en la cabecera, y el registro de pagos dentro de su propia cotización.
* CORRECCIÓN: icono duplicado en las pestañas del portal. Ahora se elimina cualquier icono del tema (pseudo-elemento o etiqueta) antes de aplicar el propio.

= 2.3.0 =
* Nuevo módulo de plantillas de proyecto (CPT malka_template) con menú propio.
* Plantilla "Plan Branding — The Branding Method" precargada al activar: 6 etapas, 2 hitos de reunión y 40 ítems numerados.
* Botón "Aplicar plantilla" dentro del editor de tareas, con fecha de inicio y opción de reemplazar o añadir.
* Las tareas guardan su número jerárquico (0.4.1) junto al nombre.
* Filas reordenables arrastrando el asa, tanto en proyectos como en plantillas.

= 2.2.0 =
* CORRECCIÓN: el resumen se pintaba dos veces. El submenú "Resumen" repetía el callback del menú principal; ahora hereda el de la página padre.
* CORRECCIÓN: el editor de tareas se reescribió por completo. Botones arriba y abajo de la tabla, plantillas de fila inmediatamente después, y ninguna dependencia externa que pueda cortar el render.
* Facturas en PDF además de HTML: validación de firma %PDF-, entrega inline con su propio tipo MIME y descarga con la extensión correcta.
* Buscador en vivo sobre los selectores de cliente y de proyecto al asignar facturas.
* Rediseño completo del admin con la identidad Malka: acento #B8A792, base #F9F8F6, titulares en serif con filete, tarjetas, tablas y botones coherentes.
* CORRECCIÓN: iconos duplicados en el portal del cliente. Se anula el icono genérico del tema antes de aplicar el propio.

= 2.1.3 =
* CORRECCIÓN: los botones "Añadir tarea" y "Añadir etapa" no hacían nada. Las plantillas de fila se imprimían al final del bloque, después del registro de reprogramaciones; si esa consulta fallaba, los botones quedaban sin plantilla que insertar. Ahora las plantillas van inmediatamente después de la tabla.
* Las filas se parsean con jQuery.parseHTML y, si aún así faltara la plantilla, el JS clona la última fila y renumera sus campos.
* task_row() normaliza todas las claves con wp_parse_args: se acabaron los avisos por índice indefinido en PHP 8.
* El registro de reprogramaciones se consulta una sola vez y tolera datos vacíos o malformados.

= 2.1.2 =
* Blindaje: cada metabox, cada página del plugin y cada pestaña del portal del cliente se ejecuta dentro de un try/catch. Un fallo puntual ya no puede tumbar la pantalla ni hacer que WordPress pause el plugin.
* Cuando algo falla se muestra el mensaje exacto con archivo y línea en el propio bloque, en vez de un error críptico.
* Nuevo panel "Diagnóstico" en Ajustes Generales: versión del plugin, PHP, WordPress, WooCommerce y último error registrado.

= 2.1.1 =
* CORRECCIÓN CRÍTICA: cuatro métodos estaban enganchados pero no existían en la clase de administración (render_project_approvals, render_project_docs, handle_test_email, handle_grant_round). El fatal en el metabox de aprobaciones cortaba el render de la pantalla y por eso el bloque de tareas no aparecía.
* Nuevo submenú "Tareas": selector de proyecto + editor completo de tareas y etapas con guardado propio, sin entrar al proyecto.

= 2.1.0 =
* Resumen ampliado: totales, en curso, terminados, aprobaciones pendientes, tareas del cliente atrasadas y tabla de proyectos activos con etapa y avance.
* Icono propio (SVG) en el menú lateral: `dashicons-chart-gantt` no existe en WordPress y dejaba el menú sin marca.
* Iconos diferenciados por pestaña en Mi Cuenta (Proyectos, Cotizaciones, Facturas).
* Facturas vinculables a un proyecto; nuevo metabox "Cotizaciones y facturas vinculadas" y bloque de documentos en el portal del cliente.
* Metabox de tareas renombrado a "Tareas y etapas · asignación al cliente" para hacerlo evidente.

= 2.0.0 =
* Nuevo modelo de tareas: etapas contenedoras con fechas y avance calculados automáticamente.
* Nuevo: responsable por tarea (estudio con persona asignada / cliente) y botón "Marcar como hecha" para el cliente.
* Nuevo: plazos por tarea del cliente con aviso previo y aviso de vencimiento.
* Nuevo: reprogramación automática de todo el calendario por cada día de atraso, con bitácora.
* Nuevo: módulo de Cotizaciones con calendario de abonos, pestaña en Mi Cuenta y alertas de pago por vencer o atrasado.
* Corregido: el cliente ya no puede arrastrar las barras del Gantt.

= 1.3.0 =
* Nuevo: venta y acreditación automática de rondas adicionales vía WooCommerce.
* Nuevo: límite efectivo = rondas del plan + rondas compradas; el portal se reabre solo tras el pago.
* Nuevo: botón "+1 ronda" en WP-Admin para acreditar manualmente.

= 1.2.0 =
* Nuevo: rondas de revisión limitadas por plan, con validación en servidor y mensaje comercial de upsell.
* Nuevo: cron diario de recordatorios de aprobación con escalamiento interno.
* Nuevo: campo "Rondas incluidas" en producto y en proyecto; ejecución manual del cron desde Ajustes.
* Cambio: "Reabrir" conserva el contador de rondas en vez de borrar el registro.

= 1.1.0 =
* Nuevo: módulo de aprobaciones del cliente con trazabilidad (fecha, usuario, IP).
* Nuevo: notificaciones por correo con plantilla de marca y correo de prueba.
* Renombrado a Malka CRM. Las claves de base de datos (`_malka_pm_*`, `malka_pm_settings`, CPT `malka_project` / `malka_invoice`) se mantienen para no perder datos existentes.

= 1.0.0 =
* Versión inicial: facturas HTML, proyectos con Gantt, vinculación con productos WooCommerce.
