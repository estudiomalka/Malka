<?php
/**
 * El contenedor del plugin, aparte del archivo principal.
 *
 * Vive acá y no en malka-crm.php por una razón concreta: PHP adelanta al
 * compilar las clases y funciones que están sueltas en un archivo, antes de
 * ejecutar ni una línea. Con el contenedor allá, una segunda copia del plugin
 * reventaba al compilar —«Cannot redeclare»— y ninguna guarda alcanzaba a
 * correr. Acá adentro, el archivo principal revisa primero si ya hay otra
 * copia cargada y, si la hay, nunca llega a pedir este archivo.
 *
 * @package Malka_CRM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contenedor principal del plugin.
 */
final class Malka_CRM_Core {

	/** @var Malka_CRM_Core|null */
	private static $instance = null;

	/** @var Malka_CRM_CPT */
	public $cpt;

	/** @var Malka_CRM_Admin */
	public $admin;

	/** @var Malka_CRM_Invoices */
	public $invoices;

	/** @var Malka_CRM_WooCommerce */
	public $woocommerce;

	/** @var Malka_CRM_Gantt */
	public $gantt;

	/** @var Malka_CRM_Approvals */
	public $approvals;

	/** @var Malka_CRM_Notifications */
	public $notifications;

	/** @var Malka_CRM_Templates */
	public $templates;

	/** @var Malka_CRM_Templates_Panel */
	public $templates_panel;

	/** @var Malka_CRM_Clients */
	public $clients;

	/** @var Malka_CRM_Portal */
	public $portal;
	public $losses;
	public $home;

	/** @var Malka_CRM_Vacaciones */
	public $vacaciones;

	/** @var Malka_CRM_Reembolsos */
	public $reembolsos;

	/** @var Malka_CRM_Deudas */
	public $deudas;

	/** @var Malka_CRM_Eventos */
	public $eventos;

	/** @var Malka_CRM_Suscripciones */
	public $suscripciones;

	/** @var Malka_CRM_Prestamos */
	public $prestamos;

	/** @var Malka_CRM_Cargar */
	public $cargar;

	/** @var Malka_CRM_Dashboard */
	public $dashboard;

	/** @var Malka_CRM_Cron */
	public $cron;

	/** @var Malka_CRM_Extra_Rounds */
	public $extra_rounds;

	/** @var Malka_CRM_Tasks */
	public $tasks;

	/** @var Malka_CRM_Scheduler */
	public $scheduler;

	/** @var Malka_CRM_Quotes */
	public $quotes;

	/** @var Malka_CRM_Quote_Page */
	public $quote_page;
	public $sumup;
	/** @var Malka_CRM_Quote_Editor */
	public $quote_edit;

	/** @var Malka_CRM_Reminders */
	public $reminders;

	/** @var Malka_CRM_Documents */
	public $documents;

	/** @var Malka_CRM_Projects */
	public $projects;

	/** @var Malka_CRM_Settings */
	public $settings;

	/** @var Malka_CRM_Finance */
	public $finance;

	/** Pagos del estudio (costos del mes e impuestos). */
	public $pagos;

	/** La nómina de prestadores extranjeros del SII. */
	public $sii;

	/** Las fichas de proveedores. */
	public $proveedores;

	/** @var Malka_CRM_Time */
	public $time;

	/** @var Malka_CRM_Onboarding */
	public $onboarding;

	/**
	 * Singleton.
	 *
	 * @return Malka_CRM_Core
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->includes();
		$this->init_modules();
		$this->hooks();
	}

	private function includes() {
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-helpers.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-cpt.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-quotes.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-quote-page.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-sumup.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-firma.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-pagos.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-sii.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-providers.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-quote-editor.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-reminders.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-documents.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-projects.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-retiro.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-contabilidad.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-projects.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-settings.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-finance.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-time.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-analysis.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-onboarding.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-tasks.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-scheduler.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-invoices.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-gantt.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-approvals.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-extra-rounds.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-notifications.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-cron.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-dashboard.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-templates.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-templates-panel.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-clients.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-portal.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-messages.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-correos.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-ideas.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-asistente.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-losses.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-home.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-vacaciones.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-reembolsos.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-deudas.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-eventos.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-suscripciones.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-prestamos.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-cargar.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-updater.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-casa.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-woocommerce.php';

		if ( is_admin() ) {
			require_once MALKA_CRM_PATH . 'includes/class-malka-crm-admin.php';
		}
	}

	private function init_modules() {
		Malka_CRM_Correos::init();
		Malka_CRM_Ideas::init();
		// Actualizar en un clic. Queda dormido mientras no haya repositorio.
		Malka_CRM_Updater::init();
		Malka_CRM_Casa::init();
		Malka_CRM_Asistente::init();
		$this->cpt         = new Malka_CRM_CPT();
		$this->quotes      = new Malka_CRM_Quotes();
		$this->quote_page  = new Malka_CRM_Quote_Page();
		$this->sumup       = new Malka_CRM_SumUp();
		$this->quote_edit  = new Malka_CRM_Quote_Editor();
		$this->reminders   = new Malka_CRM_Reminders();
		$this->documents   = new Malka_CRM_Documents();
		$this->projects    = new Malka_CRM_Projects();
		$this->settings    = new Malka_CRM_Settings();
		$this->finance     = new Malka_CRM_Finance();
		$this->pagos       = new Malka_CRM_Pagos();
		$this->sii         = new Malka_CRM_SII();
		$this->proveedores = new Malka_CRM_Providers();
		$this->time        = new Malka_CRM_Time();
		$this->onboarding  = new Malka_CRM_Onboarding();
		$this->tasks       = new Malka_CRM_Tasks();
		$this->scheduler   = new Malka_CRM_Scheduler();
		$this->invoices    = new Malka_CRM_Invoices();
		$this->gantt         = new Malka_CRM_Gantt();
		$this->approvals     = new Malka_CRM_Approvals();
		$this->extra_rounds  = new Malka_CRM_Extra_Rounds();
		$this->notifications = new Malka_CRM_Notifications();
		$this->cron          = new Malka_CRM_Cron();
		$this->dashboard     = new Malka_CRM_Dashboard();
		$this->templates       = new Malka_CRM_Templates();
		$this->templates_panel = new Malka_CRM_Templates_Panel();
		$this->clients         = new Malka_CRM_Clients();
		$this->portal          = new Malka_CRM_Portal();
		$this->losses          = new Malka_CRM_Losses();
		$this->home            = new Malka_CRM_Home();
		$this->vacaciones      = new Malka_CRM_Vacaciones();
		$this->reembolsos      = new Malka_CRM_Reembolsos();
		$this->deudas          = new Malka_CRM_Deudas();
		$this->eventos         = new Malka_CRM_Eventos();
		$this->suscripciones   = new Malka_CRM_Suscripciones();
		$this->prestamos       = new Malka_CRM_Prestamos();
		$this->cargar          = new Malka_CRM_Cargar();
		$this->woocommerce   = new Malka_CRM_WooCommerce();

		if ( is_admin() ) {
			$this->admin = new Malka_CRM_Admin();
		}
	}

	private function hooks() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
		add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
		add_filter( 'plugin_action_links_' . MALKA_CRM_BASENAME, array( $this, 'action_links' ) );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'malka-crm', false, dirname( MALKA_CRM_BASENAME ) . '/languages' );
	}

	/**
	 * Compatibilidad con HPOS (High Performance Order Storage).
	 */
	public function declare_hpos_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', MALKA_CRM_FILE, true );
		}
	}

	/**
	 * Aviso si WooCommerce no está activo.
	 */
	public function dependency_notice() {
		if ( class_exists( 'WooCommerce' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>';
		esc_html_e( 'Malka CRM requiere WooCommerce activo para las pestañas de Mi Cuenta.', 'malka-crm' );
		echo '</p></div>';
	}

	/**
	 * Enlaces rápidos en la lista de plugins.
	 *
	 * @param array $links Enlaces.
	 * @return array
	 */
	public function action_links( $links ) {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=malka-crm-settings' ) ),
			esc_html__( 'Ajustes', 'malka-crm' )
		);
		array_unshift( $links, $settings );
		return $links;
	}

	/**
	 * Activación: endpoints, CPT, carpeta protegida y flush de reglas.
	 */
	public static function activate() {
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-helpers.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-cpt.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-quotes.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-tasks.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-scheduler.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-invoices.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-woocommerce.php';

		$cpt = new Malka_CRM_CPT();
		$cpt->register_post_types();

		Malka_CRM_WooCommerce::register_endpoints();
		Malka_CRM_Invoices::protect_storage_directory();

		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-dashboard.php';
		Malka_CRM_Dashboard::add_rewrite();

		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-quote-page.php';
		Malka_CRM_Quote_Page::add_rewrite();

		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-clients.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-portal.php';
		Malka_CRM_Portal::add_rewrite();

		// Tablas propias del escritorio: movimientos de la cuenta y horas trabajadas.
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-settings.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-finance.php';
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-time.php';
		Malka_CRM_Finance::install();
		Malka_CRM_Time::install();

		if ( false === get_option( Malka_CRM_Settings::OPTION ) ) {
			add_option( Malka_CRM_Settings::OPTION, Malka_CRM_Settings::defaults() );
		}

		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-cron.php';
		Malka_CRM_Cron::maybe_schedule();

		if ( false === get_option( 'malka_pm_settings' ) ) {
			add_option( 'malka_pm_settings', Malka_CRM_Helpers::default_settings() );
		}

		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-templates.php';
		$templates = new Malka_CRM_Templates();
		$templates->register_post_type();
		Malka_CRM_Templates::seed_default_templates();

		flush_rewrite_rules();
	}

	/**
	 * Desactivación.
	 */
	public static function deactivate() {
		require_once MALKA_CRM_PATH . 'includes/class-malka-crm-cron.php';
		Malka_CRM_Cron::unschedule();
		flush_rewrite_rules();
	}
}

register_activation_hook( MALKA_CRM_FILE, array( 'Malka_CRM_Core', 'activate' ) );
register_deactivation_hook( MALKA_CRM_FILE, array( 'Malka_CRM_Core', 'deactivate' ) );

/**
 * Acceso global.
 *
 * @return Malka_CRM_Core
 */
function malka_crm() {
	return Malka_CRM_Core::instance();
}
