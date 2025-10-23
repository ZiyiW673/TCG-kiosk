<?php
/**
 * Plugin Name:       TCG Kiosk Filter
 * Description:       Generates a trading card browser page with filtering options sourced from the bundled JSON database.
 * Version:           1.0.0
 * Author:            OpenAI Assistant
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-tcg-kiosk-database.php';

class TCG_Kiosk_Filter_Plugin {
    const SHORTCODE  = 'tcg_kiosk_browser';
    const PAGE_SLUG  = 'tcg-kiosk-browser';
    const PAGE_TITLE = 'TCG Kiosk Browser';

    /**
     * Singleton instance.
     *
     * @var TCG_Kiosk_Filter_Plugin
     */
    protected static $instance = null;

    /**
     * Database helper.
     *
     * @var TCG_Kiosk_Database
     */
    protected $database;

    /**
     * Get singleton instance.
     *
     * @return TCG_Kiosk_Filter_Plugin
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new static();
        }

        return static::$instance;
    }

    /**
     * TCG_Kiosk_Filter_Plugin constructor.
     */
    protected function __construct() {
        $this->database = new TCG_Kiosk_Database( plugin_dir_path( __FILE__ ) . 'database' );

        register_activation_hook( __FILE__, array( $this, 'activate_plugin' ) );
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
        add_action( 'init', array( $this, 'register_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
    }

    /**
     * Plugin activation callback.
     */
    public function activate_plugin() {
        $this->maybe_create_page();
    }

    /**
     * Load plugin translations.
     */
    public function load_textdomain() {
        load_plugin_textdomain( 'tcg-kiosk-filter', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    /**
     * Register shortcode handler.
     */
    public function register_shortcode() {
        add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
    }

    /**
     * Register assets when needed.
     */
    public function register_assets() {
        if ( ! $this->should_enqueue_assets() ) {
            return;
        }

        $asset_url  = plugin_dir_url( __FILE__ ) . 'assets/';
        $asset_path = plugin_dir_path( __FILE__ ) . 'assets/';

        wp_enqueue_style(
            'tcg-kiosk-filter',
            $asset_url . 'css/tcg-kiosk.css',
            array(),
            filemtime( $asset_path . 'css/tcg-kiosk.css' )
        );

        wp_enqueue_script(
            'tcg-kiosk-filter',
            $asset_url . 'js/tcg-kiosk.js',
            array(),
            filemtime( $asset_path . 'js/tcg-kiosk.js' ),
            true
        );

        $data = $this->database->get_tcg_data();

        wp_localize_script(
            'tcg-kiosk-filter',
            'tcgKioskData',
            array(
                'cards'        => $data['cards'],
                'lastModified' => $data['lastModified'],
                'i18n'         => array(
                    'allGames' => __( 'All Games', 'tcg-kiosk-filter' ),
                    'allSets'  => __( 'All Sets', 'tcg-kiosk-filter' ),
                    'noCards'  => __( 'No cards match your filters.', 'tcg-kiosk-filter' ),
                    'previous' => __( 'Previous', 'tcg-kiosk-filter' ),
                    'next'     => __( 'Next', 'tcg-kiosk-filter' ),
                    'pageStatus' => __( 'Page %1$s of %2$s', 'tcg-kiosk-filter' ),
                ),
            )
        );
    }

    /**
     * Render shortcode output.
     *
     * @return string
     */
    public function render_shortcode() {
        ob_start();
        ?>
        <div class="tcg-kiosk">
            <div class="tcg-kiosk__filters">
                <label>
                    <span><?php esc_html_e( 'Trading Card Game', 'tcg-kiosk-filter' ); ?></span>
                    <select id="tcg-kiosk-type" class="tcg-kiosk__select" data-placeholder="<?php echo esc_attr__( 'All Games', 'tcg-kiosk-filter' ); ?>">
                        <option value=""><?php esc_html_e( 'All Games', 'tcg-kiosk-filter' ); ?></option>
                    </select>
                </label>
                <label>
                    <span><?php esc_html_e( 'Set', 'tcg-kiosk-filter' ); ?></span>
                    <select id="tcg-kiosk-set" class="tcg-kiosk__select" data-placeholder="<?php echo esc_attr__( 'All Sets', 'tcg-kiosk-filter' ); ?>" disabled>
                        <option value=""><?php esc_html_e( 'All Sets', 'tcg-kiosk-filter' ); ?></option>
                    </select>
                </label>
                <label class="tcg-kiosk__search">
                    <span class="screen-reader-text"><?php esc_html_e( 'Search by card name', 'tcg-kiosk-filter' ); ?></span>
                    <input type="search" id="tcg-kiosk-search" placeholder="<?php echo esc_attr__( 'Search cards…', 'tcg-kiosk-filter' ); ?>" />
                </label>
            </div>
            <div id="tcg-kiosk-results" class="tcg-kiosk__grid" aria-live="polite"></div>
            <nav id="tcg-kiosk-pagination" class="tcg-kiosk__pagination" aria-label="<?php esc_attr_e( 'Card results pagination', 'tcg-kiosk-filter' ); ?>" hidden></nav>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Create the TCG browser page if it does not exist.
     */
    protected function maybe_create_page() {
        $page = get_page_by_path( self::PAGE_SLUG );

        if ( $page ) {
            return;
        }

        $page_id = wp_insert_post(
            array(
                'post_title'   => self::PAGE_TITLE,
                'post_name'    => self::PAGE_SLUG,
                'post_content' => '[' . self::SHORTCODE . ']',
                'post_status'  => 'publish',
                'post_type'    => 'page',
            )
        );

        if ( is_wp_error( $page_id ) ) {
            return;
        }
    }

    /**
     * Determine whether assets should be enqueued.
     *
     * @return bool
     */
    protected function should_enqueue_assets() {
        if ( is_page( self::PAGE_SLUG ) ) {
            return true;
        }

        global $post;

        if ( ! class_exists( 'WP_Post' ) ) {
            return false;
        }

        if ( ! $post instanceof WP_Post ) {
            return false;
        }

        return has_shortcode( $post->post_content, self::SHORTCODE );
    }
}

TCG_Kiosk_Filter_Plugin::instance();
