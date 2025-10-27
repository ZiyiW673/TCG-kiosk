<?php
/**
 * Plugin Name:       TCG Kiosk Filter
 * Description:       Generates a trading card browser page with filtering options sourced from the bundled JSON database.
 * Version:           1.0.0
 * Author:            OpenAI Assistant
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/tcg-kiosk-database.php';

class TCG_Kiosk_Filter_Plugin {
    const SHORTCODE   = 'tcg_kiosk_browser';
    const PAGE_SLUG   = 'tcg-kiosk-browser';
    const PAGE_TITLE  = 'TCG Kiosk Browser';
    const PAGE_OPTION = 'tcg_kiosk_browser_page_id';

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
        add_action( 'init', array( $this, 'ensure_page_exists' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
    }

    /**
     * Plugin activation callback.
     */
    public function activate_plugin() {
        $this->ensure_page_exists();
    }

    /**
     * Ensure the kiosk page exists and matches the expected content.
     */
    public function ensure_page_exists() {
        $page_id = (int) get_option( self::PAGE_OPTION );

        if ( $page_id ) {
            $page = get_post( $page_id );

            if ( $page instanceof WP_Post ) {
                $this->synchronize_page( $page_id );
                return;
            }
        }

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

        wp_register_style( 'tcg-kiosk-filter', false, array(), '1.0.0' );
        wp_enqueue_style( 'tcg-kiosk-filter' );
        wp_add_inline_style( 'tcg-kiosk-filter', $this->get_inline_styles() );

        wp_register_script( 'tcg-kiosk-filter', '', array(), '1.0.0', true );
        wp_enqueue_script( 'tcg-kiosk-filter' );
        wp_add_inline_script( 'tcg-kiosk-filter', $this->get_inline_script() );

        $data = $this->database->get_tcg_data();
        $type_icon_base_url = trailingslashit( plugins_url( 'assets/icon', __FILE__ ) );
        $type_icon_map      = array(
            'colorless' => 'colorless.png',
            'darkness'  => 'darkness.png',
            'dragon'    => 'dragon.png',
            'fairy'     => 'fairy.png',
            'fighting'  => 'fighting.png',
            'fire'      => 'fire.png',
            'grass'     => 'grass.png',
            'lightning' => 'lightning.png',
            'metal'     => 'metal.png',
            'psychic'   => 'psychic.png',
            'water'     => 'water.png',
        );

        wp_localize_script(
            'tcg-kiosk-filter',
            'tcgKioskData',
            array(
                'cards'        => $data['cards'],
                'lastModified' => $data['lastModified'],
                'typeIcons'    => array(
                    'baseUrl' => $type_icon_base_url,
                    'map'     => $type_icon_map,
                ),
                'i18n'         => array(
                    'allSets'  => __( 'All Sets', 'tcg-kiosk-filter' ),
                    'allTypeTemplate' => __( 'All %s', 'tcg-kiosk-filter' ),
                    'noCards'  => __( 'No cards match your filters.', 'tcg-kiosk-filter' ),
                    'previous' => __( 'Previous', 'tcg-kiosk-filter' ),
                    'next'     => __( 'Next', 'tcg-kiosk-filter' ),
                    'pageStatus' => __( 'Page %1$s of %2$s', 'tcg-kiosk-filter' ),
                    'cardsPerPage' => __( 'Cards per page', 'tcg-kiosk-filter' ),
                    'chooseGame' => __( 'Choose a game', 'tcg-kiosk-filter' ),
                    'chooseGameSubtitle' => __( 'Select a game to start browsing cards.', 'tcg-kiosk-filter' ),
                    'cardDetailsTitle' => __( 'Card Details', 'tcg-kiosk-filter' ),
                    'noDetails' => __( 'No additional details available for this card.', 'tcg-kiosk-filter' ),
                    'viewDetails' => __( 'View details for %s', 'tcg-kiosk-filter' ),
                    'viewDetailsFallback' => __( 'View card details', 'tcg-kiosk-filter' ),
                    'gameLabel' => __( 'Game', 'tcg-kiosk-filter' ),
                    'setLabel' => __( 'Set', 'tcg-kiosk-filter' ),
                    'idLabel' => __( 'Card ID', 'tcg-kiosk-filter' ),
                    'typeLabel' => __( 'Type', 'tcg-kiosk-filter' ),
                ),
            )
        );
    }

    /**
     * Retrieve the inline stylesheet for the kiosk UI.
     *
     * @return string
     */
    protected function get_inline_styles() {
        return <<<'CSS'
.tcg-kiosk {
    --tcg-gap: 1.5rem;
    display: grid;
    gap: var(--tcg-gap);
    height: 100vh;
    max-height: 100dvh;
    min-height: 100vh;
    grid-template-rows: auto minmax(0, 1fr) auto;
    overflow: hidden;
}

.tcg-kiosk__header {
    display: flex;
    align-items: flex-start;
    gap: var(--tcg-gap);
}

.tcg-kiosk__filters {
    flex: 0 0 25%;
    display: flex;
    align-items: flex-end;
    flex-wrap: wrap;
    gap: 1rem;
}

.tcg-kiosk__type-filter {
    flex: 1 1 50%;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.tcg-kiosk__type-filter[hidden] {
    display: none;
}

.tcg-kiosk__type-filter-label {
    font-weight: 600;
    color: #1d2327;
}

.tcg-kiosk__type-options {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.tcg-kiosk__type-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    justify-content: flex-start;
}

.tcg-kiosk__type-row--primary {
    justify-content: center;
}

.tcg-kiosk__type-row--trainer {
    justify-content: center;
}

.tcg-kiosk__overlay {
    position: fixed;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem;
    background-color: rgba(0, 0, 0, 0.75);
    z-index: 9999;
}

.tcg-kiosk__card-overlay {
    position: fixed;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem;
    background-color: rgba(0, 0, 0, 0.85);
    z-index: 10000;
}

.tcg-kiosk__card-overlay[hidden] {
    display: none;
}

.tcg-kiosk__overlay[hidden] {
    display: none;
}

.tcg-kiosk__card-overlay-panel {
    position: relative;
    display: flex;
    gap: 2rem;
    width: 100%;
    max-width: min(1040px, 90vw);
    max-height: min(90vh, 90dvh);
    background-color: #fff;
    border-radius: 1rem;
    padding: 2rem;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.45);
    overflow: hidden;
    align-items: stretch;
}

.tcg-kiosk__card-overlay-image {
    flex: 1 1 56%;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    background: transparent;
    border-radius: 0;
    min-height: 0;
}

.tcg-kiosk__card-overlay-image img {
    width: 70%;
    height: auto;
    max-height: 70%;
    object-fit: contain;
}

.tcg-kiosk__card-overlay-meta {
    flex: 1 1 52%;
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
    overflow-y: auto;
}

.tcg-kiosk__card-overlay-title {
    margin: 0;
    font-size: 2rem;
    font-weight: 700;
    color: #1d2327;
}

.tcg-kiosk__card-overlay-details {
    margin: 0;
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 0.5rem 1.5rem;
    font-size: 0.95rem;
}

.tcg-kiosk__card-overlay-term {
    font-weight: 600;
    color: #1d2327;
}

.tcg-kiosk__card-overlay-definition {
    margin: 0;
    color: #1d2327;
    white-space: pre-line;
}

.tcg-kiosk__card-overlay-empty {
    grid-column: 1 / -1;
    margin: 0;
    font-style: italic;
    color: #50575e;
}

.tcg-kiosk__card-overlay-close {
    position: absolute;
    top: 0.75rem;
    right: 0.75rem;
    appearance: none;
    border: none;
    background: transparent;
    color: #1d2327;
    font-size: 2rem;
    line-height: 1;
    cursor: pointer;
    padding: 0.25rem;
    border-radius: 999px;
    transition: background-color 0.2s ease, color 0.2s ease;
}

.tcg-kiosk__card-overlay-close:hover,
.tcg-kiosk__card-overlay-close:focus-visible {
    background-color: rgba(34, 113, 177, 0.1);
    color: #135e96;
    outline: none;
}

.tcg-kiosk__overlay-panel {
    max-width: 640px;
    width: 100%;
    background-color: #fff;
    border-radius: 1rem;
    padding: 2.5rem 2rem;
    text-align: center;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35);
}

.tcg-kiosk__overlay-title {
    margin: 0 0 0.5rem;
    font-size: 1.75rem;
    font-weight: 700;
    color: #1d2327;
}

.tcg-kiosk__overlay-subtitle {
    margin: 0 0 2rem;
    color: #50575e;
    font-size: 1rem;
}

.tcg-kiosk__overlay-options {
    display: flex;
    flex-direction: row;
    gap: 1.5rem;
    justify-content: center;
    align-items: stretch;
    flex-wrap: nowrap;
}

.tcg-kiosk__overlay-button {
    appearance: none;
    border: none;
    background: transparent;
    color: inherit;
    border-radius: 1.25rem;
    padding: 0;
    cursor: pointer;
    position: relative;
    overflow: hidden;
    box-shadow: 0 18px 35px -18px rgba(0, 0, 0, 0.45);
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.tcg-kiosk__overlay-button:hover,
.tcg-kiosk__overlay-button:focus {
    transform: translateY(-4px);
    box-shadow: 0 25px 45px -18px rgba(0, 0, 0, 0.55);
    outline: none;
}

.tcg-kiosk__overlay-button:focus-visible {
    outline: 3px solid #f0b849;
    outline-offset: 4px;
}

.tcg-kiosk__overlay-button img {
    display: block;
    width: 100%;
    height: auto;
    pointer-events: none;
}

.tcg-kiosk__overlay-button-text {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

.tcg-kiosk__overlay-button--fallback {
    background: #2271b1;
    color: #fff;
    padding: 0.85rem 1rem;
    box-shadow: none;
}

.tcg-kiosk__overlay-button--fallback:hover,
.tcg-kiosk__overlay-button--fallback:focus {
    background-color: #135e96;
    box-shadow: 0 10px 25px -10px rgba(19, 94, 150, 0.6);
    transform: translateY(-1px);
}

.tcg-kiosk__overlay-button--fallback .tcg-kiosk__overlay-button-text {
    position: static;
    width: auto;
    height: auto;
    margin: 0;
    clip: auto;
    white-space: normal;
}

.tcg-kiosk__type-button {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    border: 1px solid #ccd0d4;
    background-color: #fff;
    color: #1d2327;
    padding: 0.35rem 0.9rem;
    font-size: 13px;
    cursor: pointer;
    transition: background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease;
    min-height: 2.25rem;
}

.tcg-kiosk__type-button--has-icon {
    padding: 0.25rem;
    min-width: 3.5rem;
}

.tcg-kiosk__type-button--large-icon {
    min-width: 4.5rem;
    border: none;
}

.tcg-kiosk__type-button--compact-icon {
    border: none;
    padding: 0.2rem;
}

.tcg-kiosk__type-button-image {
    display: block;
    max-height: 2.25rem;
    width: auto;
}

.tcg-kiosk__type-button--large-icon .tcg-kiosk__type-button-image {
    max-height: 3.75rem;
}

.tcg-kiosk__type-button--compact-icon .tcg-kiosk__type-button-image {
    max-height: 1.5rem;
}

.tcg-kiosk__type-button-text {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

.tcg-kiosk__type-button:hover,
.tcg-kiosk__type-button:focus {
    border-color: #2271b1;
    color: #135e96;
    outline: none;
}

.tcg-kiosk__type-button.is-active {
    background-color: #2271b1;
    border-color: #0a4b78;
    color: #fff;
}

.tcg-kiosk__filters label {
    display: flex;
    flex-direction: column;
    flex: 1 1 0;
    font-weight: 600;
    color: #1d2327;
}

.tcg-kiosk__select,
.tcg-kiosk__search input[type="search"] {
    border-radius: 4px;
    border: 1px solid #ccd0d4;
    padding: 0.5rem 0.75rem;
    font-size: 14px;
    color: #1d2327;
}

.tcg-kiosk__select {
    width: 100%;
}


.tcg-kiosk__actions {
    flex: 0 0 25%;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.tcg-kiosk__search {
    position: relative;
}

.tcg-kiosk__search input[type="search"] {
    width: 100%;
}

.tcg-kiosk__page-size {
    display: flex;
    flex-direction: column;
    font-weight: 600;
    color: #1d2327;
}

.tcg-kiosk__page-size select {
    margin-top: 0.35rem;
}

.tcg-kiosk__grid {
    --tcg-card-columns: 5;
    --tcg-card-rows: 2;
    display: grid;
    gap: var(--tcg-gap);
    grid-template-columns: repeat(var(--tcg-card-columns), minmax(0, 1fr));
    grid-template-rows: repeat(var(--tcg-card-rows), minmax(0, 1fr));
    align-items: stretch;
    justify-items: stretch;
    overflow: hidden;
}

.tcg-kiosk[data-page-size="10"] .tcg-kiosk__grid {
    --tcg-card-columns: 5;
    --tcg-card-rows: 2;
}

.tcg-kiosk[data-page-size="12"] {
    --tcg-gap: 1.35rem;
}

.tcg-kiosk[data-page-size="12"] .tcg-kiosk__grid {
    --tcg-card-columns: 6;
    --tcg-card-rows: 2;
}

.tcg-kiosk[data-page-size="16"] {
    --tcg-gap: 1.25rem;
}

.tcg-kiosk[data-page-size="16"] .tcg-kiosk__grid {
    --tcg-card-columns: 8;
    --tcg-card-rows: 2;
}

.tcg-kiosk[data-page-size="20"] {
    --tcg-gap: 1.15rem;
}

.tcg-kiosk[data-page-size="20"] .tcg-kiosk__grid {
    --tcg-card-columns: 10;
    --tcg-card-rows: 2;
}



.tcg-kiosk__card {
    background: transparent;
    border: none;
    border-radius: 0;
    box-shadow: none;
    overflow: hidden;
    padding: 0;
    display: flex;
    flex-direction: column;
    align-items: stretch;
    height: 100%;
    min-height: 0;
    cursor: pointer;
}

.tcg-kiosk__card img {
    width: 100%;
    height: 100%;
    min-height: 0;
    flex: 1 1 auto;
    border-radius: 0;
    object-fit: contain;
}

.tcg-kiosk__card:focus-visible {
    outline: 3px solid #2271b1;
    outline-offset: 4px;
}

.tcg-kiosk__empty {
    margin: 0;
    font-style: italic;
    color: #50575e;
}

.tcg-kiosk__pagination {
    display: flex;
    gap: 1rem;
    align-items: center;
    justify-content: center;
}

.tcg-kiosk__page-button {
    border-radius: 4px;
    border: 1px solid #2271b1;
    background-color: #2271b1;
    color: #fff;
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
    cursor: pointer;
    transition: background-color 0.2s ease, border-color 0.2s ease;
}

.tcg-kiosk__page-button:not([disabled]):hover,
.tcg-kiosk__page-button:not([disabled]):focus {
    background-color: #135e96;
    border-color: #0a4b78;
    outline: none;
}

.tcg-kiosk__page-button[disabled] {
    background-color: #f0f0f1;
    border-color: #dcdcde;
    color: #a7aaad;
    cursor: default;
}

.tcg-kiosk__page-status {
    font-size: 0.875rem;
    font-weight: 600;
    color: #1d2327;
}

@media (max-width: 900px) {
    .tcg-kiosk__header {
        flex-direction: column;
        align-items: stretch;
    }

    .tcg-kiosk__filters,
    .tcg-kiosk__type-filter,
    .tcg-kiosk__actions {
        flex: 1 1 100%;
    }
}

@media (max-width: 900px) {
    .tcg-kiosk__card-overlay-panel {
        flex-direction: column;
        max-height: min(92vh, 92dvh);
        align-items: stretch;
    }

    .tcg-kiosk__card-overlay-image,
    .tcg-kiosk__card-overlay-meta {
        flex: 1 1 auto;
    }

    .tcg-kiosk__card-overlay-image {
        width: 100%;
    }

    .tcg-kiosk__card-overlay-image img {
        width: 70%;
        height: auto;
        max-height: 70%;
    }
}

body.page-tcg-kiosk-browser .entry-title {
    display: none;
}
CSS;
    }

    /**
     * Retrieve the inline script powering the kiosk interactions.
     *
     * @return string
     */
    protected function get_inline_script() {
        return <<<'JS'
(function () {
  if ( ! window.tcgKioskData || ! window.tcgKioskData.cards ) {
    return;
  }

  const kioskRoot = document.querySelector( '.tcg-kiosk' );
  const gameSelect = document.getElementById( 'tcg-kiosk-game' );
  const setSelect = document.getElementById( 'tcg-kiosk-set' );
  const typeFilterWrapper = document.getElementById( 'tcg-kiosk-type-filter' );
  const typeFilterLabel = document.getElementById( 'tcg-kiosk-type-label' );
  const typeOptionsContainer = document.getElementById( 'tcg-kiosk-type-options' );
  const searchInput = document.getElementById( 'tcg-kiosk-search' );
  const pageSizeSelect = document.getElementById( 'tcg-kiosk-page-size' );
  const pageSizeLabel = document.querySelector( 'label[for="tcg-kiosk-page-size"] span' );
  const resultsContainer = document.getElementById( 'tcg-kiosk-results' );
  const paginationContainer = document.getElementById( 'tcg-kiosk-pagination' );
  const overlay = document.getElementById( 'tcg-kiosk-overlay' );
  const overlayOptions = document.getElementById( 'tcg-kiosk-overlay-options' );
  const overlayTitle = document.getElementById( 'tcg-kiosk-overlay-title' );
  const overlaySubtitle = document.getElementById( 'tcg-kiosk-overlay-subtitle' );
  const cardOverlay = document.getElementById( 'tcg-kiosk-card-overlay' );
  const cardOverlayPanel = cardOverlay ? cardOverlay.querySelector( '.tcg-kiosk__card-overlay-panel' ) : null;
  const cardOverlayImage = document.getElementById( 'tcg-kiosk-detail-image' );
  const cardOverlayTitle = document.getElementById( 'tcg-kiosk-detail-title' );
  const cardOverlayDetails = document.getElementById( 'tcg-kiosk-detail-metadata' );
  const cardOverlayClose = document.getElementById( 'tcg-kiosk-detail-close' );
  const typeIconConfig = window.tcgKioskData.typeIcons || {};
  const typeIconBaseUrl = 'string' === typeof typeIconConfig.baseUrl ? typeIconConfig.baseUrl : '';
  const typeIconMap = typeIconConfig.map && 'object' === typeof typeIconConfig.map ? typeIconConfig.map : {};
  const LARGE_TYPE_ICON_KEYS = new Set( [
    'colorless',
    'darkness',
    'dragon',
    'fairy',
    'fighting',
    'fire',
    'grass',
    'lightning',
    'metal',
    'psychic',
    'water',
  ] );
  const COMPACT_TYPE_ICON_KEYS = new Set( [
    'pokemon_tool',
    'stadium',
    'supporter',
    'item',
  ] );

  if ( ! kioskRoot || ! gameSelect || ! setSelect || ! typeFilterWrapper || ! typeFilterLabel || ! typeOptionsContainer || ! searchInput || ! pageSizeSelect || ! resultsContainer || ! paginationContainer ) {
    return;
  }

  const data = window.tcgKioskData.cards;
  const i18n = window.tcgKioskData.i18n || {};
  const DEFAULT_PAGE_SIZE = parseInt( pageSizeSelect.value, 10 ) || 10;
  let cardsPerPage = DEFAULT_PAGE_SIZE;
  let hasInteracted = false;
  let currentPage = 1;
  let selectedTypeValue = '';
  let lastFocusedCard = null;

  function applyPageSizeLayout() {
    const normalized = [ 10, 12, 16, 20 ].includes( cardsPerPage ) ? cardsPerPage : 10;
    cardsPerPage = normalized;
    kioskRoot.dataset.pageSize = String( normalized );
    if ( pageSizeSelect.value !== String( normalized ) ) {
      pageSizeSelect.value = String( normalized );
    }
  }

  function createOption( value, label ) {
    const option = document.createElement( 'option' );
    option.value = value;
    option.textContent = label;
    return option;
  }

  function normalizeTypeIconKey( value ) {
    if ( 'string' !== typeof value && 'number' !== typeof value ) {
      return '';
    }

    let processed = String( value ).trim().toLowerCase();

    if ( ! processed ) {
      return '';
    }

    if ( processed.normalize ) {
      processed = processed.normalize( 'NFD' );
    }

    processed = processed.replace( /[\u0300-\u036f]/g, '' );
    processed = processed.replace( /[^a-z0-9]+/g, '_' );
    processed = processed.replace( /^_+|_+$/g, '' );

    return processed;
  }

  function getTypeIconUrl( label, value ) {
    if ( ! typeIconBaseUrl || ! typeIconMap || 'object' !== typeof typeIconMap ) {
      return '';
    }

    const candidates = [];

    if ( value ) {
      candidates.push( value );
    }

    if ( label && label !== value ) {
      candidates.push( label );
    }

    for ( let index = 0; index < candidates.length; index += 1 ) {
      const key = normalizeTypeIconKey( candidates[ index ] );

      if ( key && Object.prototype.hasOwnProperty.call( typeIconMap, key ) ) {
        const file = typeIconMap[ key ];

        if ( file ) {
          return typeIconBaseUrl + file;
        }
      }
    }

    return '';
  }

  function populateGameOptions() {
    data.forEach( ( type ) => {
      if ( ! type || ! type.slug ) {
        return;
      }

      gameSelect.appendChild( createOption( type.slug, type.label || type.slug ) );
    } );
  }

  function closeCardOverlay( options ) {
    if ( ! cardOverlay ) {
      return;
    }

    const trigger = lastFocusedCard;
    const shouldRestoreFocus = ! options || options.restoreFocus !== false;

    if ( trigger && typeof trigger.setAttribute === 'function' ) {
      trigger.setAttribute( 'aria-expanded', 'false' );
    }

    if ( cardOverlay.hidden ) {
      if (
        options && options.restoreFocus === false ||
        ! trigger ||
        ! ( 'isConnected' in trigger ? trigger.isConnected : document.contains( trigger ) ) ||
        ! shouldRestoreFocus
      ) {
        lastFocusedCard = null;
      }

      return;
    }

    cardOverlay.hidden = true;
    cardOverlay.setAttribute( 'aria-hidden', 'true' );

    if ( cardOverlayImage ) {
      cardOverlayImage.removeAttribute( 'src' );
      cardOverlayImage.removeAttribute( 'srcset' );
      cardOverlayImage.removeAttribute( 'sizes' );
      cardOverlayImage.alt = '';
    }

    if ( cardOverlayDetails ) {
      cardOverlayDetails.innerHTML = '';
    }

    if ( cardOverlayTitle ) {
      cardOverlayTitle.textContent = '';
    }

    document.removeEventListener( 'keydown', handleCardOverlayKeydown, true );

    const canRestoreFocus =
      shouldRestoreFocus &&
      trigger &&
      typeof trigger.focus === 'function' &&
      ( 'isConnected' in trigger ? trigger.isConnected : document.contains( trigger ) );

    if ( canRestoreFocus ) {
      trigger.focus();
    }

    lastFocusedCard = null;
  }

  function handleCardOverlayKeydown( event ) {
    if ( 'Escape' === event.key || 'Esc' === event.key ) {
      event.preventDefault();
      closeCardOverlay();
    }
  }

  function buildCardDetailEntries( card ) {
    if ( card && Array.isArray( card.details ) ) {
      return card.details
        .map( ( entry ) => {
          if ( ! entry || 'string' !== typeof entry.label || 'string' !== typeof entry.value ) {
            return null;
          }

          const label = entry.label.trim();
          const value = entry.value.trim();

          if ( ! label || ! value ) {
            return null;
          }

          return { label, value };
        } )
        .filter( Boolean );
    }

    if ( ! card ) {
      return [];
    }

    const entries = [];

    if ( card.game ) {
      entries.push( { label: i18n.gameLabel || 'Game', value: card.game } );
    }

    if ( card.set ) {
      entries.push( { label: i18n.setLabel || 'Set', value: card.set } );
    }

    if ( Array.isArray( card.typeValues ) && card.typeValues.length ) {
      const typeLabel = typeFilterLabel && typeFilterLabel.textContent ? typeFilterLabel.textContent.trim() : '';
      entries.push( {
        label: typeLabel || i18n.typeLabel || 'Type',
        value: card.typeValues.join( ', ' ),
      } );
    }

    if ( card.id ) {
      entries.push( { label: i18n.idLabel || 'Card ID', value: card.id } );
    }

    return entries;
  }

  function openCardOverlay( card, triggerElement ) {
    if ( ! cardOverlay || ! card ) {
      return;
    }

    lastFocusedCard = triggerElement || null;

    if ( lastFocusedCard && typeof lastFocusedCard.setAttribute === 'function' ) {
      lastFocusedCard.setAttribute( 'aria-expanded', 'true' );
    }

    const titleText = card.name || i18n.cardDetailsTitle || 'Card Details';
    const entries = buildCardDetailEntries( card );

    if ( cardOverlayTitle ) {
      cardOverlayTitle.textContent = titleText;
    }

    if ( cardOverlayDetails ) {
      cardOverlayDetails.innerHTML = '';

      if ( entries.length ) {
        entries.forEach( ( entry ) => {
          const term = document.createElement( 'dt' );
          term.className = 'tcg-kiosk__card-overlay-term';
          term.textContent = entry.label;

          const definition = document.createElement( 'dd' );
          definition.className = 'tcg-kiosk__card-overlay-definition';
          definition.textContent = entry.value;

          cardOverlayDetails.appendChild( term );
          cardOverlayDetails.appendChild( definition );
        } );
      } else {
        const placeholderTerm = document.createElement( 'dt' );
        placeholderTerm.className = 'tcg-kiosk__card-overlay-term';
        placeholderTerm.textContent = i18n.cardDetailsTitle || 'Card Details';

        const placeholderDefinition = document.createElement( 'dd' );
        placeholderDefinition.className = 'tcg-kiosk__card-overlay-definition tcg-kiosk__card-overlay-empty';
        placeholderDefinition.textContent = i18n.noDetails || 'No additional details available for this card.';

        cardOverlayDetails.appendChild( placeholderTerm );
        cardOverlayDetails.appendChild( placeholderDefinition );
      }
    }

    if ( cardOverlayImage ) {
      const preferredImage = card.imageFullUrl || card.imageUrl || '';
      const proxied = getProxiedImageUrl( preferredImage );
      const source = proxied || preferredImage;

      if ( source ) {
        cardOverlayImage.src = source;
      } else {
        cardOverlayImage.removeAttribute( 'src' );
      }

      if ( card.imageSrcset ) {
        const proxiedSrcset = proxied ? buildProxiedSrcset( card.imageSrcset ) : '';

        if ( proxiedSrcset ) {
          cardOverlayImage.srcset = proxiedSrcset;
        } else if ( ! proxied ) {
          cardOverlayImage.srcset = card.imageSrcset;
        } else {
          cardOverlayImage.removeAttribute( 'srcset' );
        }
      } else {
        cardOverlayImage.removeAttribute( 'srcset' );
      }

      if ( card.imageSizes ) {
        cardOverlayImage.sizes = card.imageSizes;
      } else {
        cardOverlayImage.removeAttribute( 'sizes' );
      }

      cardOverlayImage.alt = titleText;
      cardOverlayImage.loading = 'eager';
      cardOverlayImage.decoding = 'async';
      cardOverlayImage.referrerPolicy = 'no-referrer';
    }

    cardOverlay.hidden = false;
    cardOverlay.removeAttribute( 'aria-hidden' );

    document.addEventListener( 'keydown', handleCardOverlayKeydown, true );

    if ( cardOverlayClose && typeof cardOverlayClose.focus === 'function' ) {
      cardOverlayClose.focus();
    } else if ( cardOverlay && typeof cardOverlay.focus === 'function' ) {
      cardOverlay.focus();
    }
  }

  function attachCardOverlayHandlers( element, card ) {
    if ( ! element ) {
      return;
    }

    element.tabIndex = 0;
    element.setAttribute( 'role', 'button' );
    element.setAttribute( 'aria-haspopup', 'dialog' );
    element.setAttribute( 'aria-expanded', 'false' );

    const template = i18n.viewDetails || '';
    const fallbackLabel = i18n.viewDetailsFallback || 'View card details';
    let ariaLabel = fallbackLabel;

    if ( card && card.name ) {
      if ( template && template.includes( '%s' ) ) {
        ariaLabel = template.replace( '%s', card.name );
      } else {
        ariaLabel = card.name;
      }
    }

    element.setAttribute( 'aria-label', ariaLabel );

    element.addEventListener( 'click', () => openCardOverlay( card, element ) );
    element.addEventListener( 'keydown', ( event ) => {
      if ( 'Enter' === event.key || ' ' === event.key || 'Spacebar' === event.key ) {
        event.preventDefault();
        openCardOverlay( card, element );
      }
    } );
  }

  function hideOverlay() {
    if ( ! overlay ) {
      return;
    }

    overlay.hidden = true;
    overlay.setAttribute( 'aria-hidden', 'true' );
  }

  function showOverlay() {
    if ( ! overlay ) {
      return;
    }

    closeCardOverlay( { restoreFocus: false } );

    overlay.hidden = false;
    overlay.removeAttribute( 'aria-hidden' );

    const firstButton = overlay.querySelector( 'button' );

    if ( firstButton ) {
      firstButton.focus();
    }
  }

  function handleOverlaySelection( slug ) {
    if ( ! slug ) {
      return;
    }

    hideOverlay();

    const matchingOption = gameSelect.querySelector( 'option[value="' + slug + '"]' );

    if ( matchingOption && matchingOption.disabled ) {
      matchingOption.disabled = false;
    }

    if ( gameSelect.value !== slug ) {
      gameSelect.value = slug;
    }

    gameSelect.dispatchEvent( new Event( 'change', { bubbles: true } ) );
    gameSelect.focus();
  }

  function populateOverlayOptions() {
    if ( ! overlay || ! overlayOptions ) {
      return;
    }

    overlayOptions.innerHTML = '';

    if ( overlayTitle && i18n.chooseGame ) {
      overlayTitle.textContent = i18n.chooseGame;
    }

    if ( overlaySubtitle ) {
      const subtitle = i18n.chooseGameSubtitle || '';
      overlaySubtitle.textContent = subtitle;
      overlaySubtitle.hidden = ! subtitle;
    }

    data.forEach( ( type ) => {
      if ( ! type || ! type.slug ) {
        return;
      }

      const label = type.label || type.slug;
      const button = document.createElement( 'button' );
      button.type = 'button';
      button.className = 'tcg-kiosk__overlay-button';
      button.setAttribute( 'aria-label', label );

      if ( type.overlayImage ) {
        const img = document.createElement( 'img' );
        img.src = type.overlayImage;
        img.alt = '';
        img.decoding = 'async';
        img.loading = 'lazy';
        button.appendChild( img );
      } else {
        button.classList.add( 'tcg-kiosk__overlay-button--fallback' );
      }

      const text = document.createElement( 'span' );
      text.className = 'tcg-kiosk__overlay-button-text';
      text.textContent = label;
      button.appendChild( text );

      button.addEventListener( 'click', () => handleOverlaySelection( type.slug ) );
      overlayOptions.appendChild( button );
    } );
  }

  function updatePageSizeLabel() {
    if ( pageSizeLabel && i18n.cardsPerPage ) {
      pageSizeLabel.textContent = i18n.cardsPerPage;
    }
  }

  function getFilteredCards() {
    const typeValue = gameSelect.value;
    const setValue = setSelect.value;
    const searchTerm = searchInput.value.trim().toLowerCase();

    if ( ! typeValue ) {
      return [];
    }

    const selectedGroup = data.find( ( group ) => group.slug === typeValue );

    if ( ! selectedGroup ) {
      return [];
    }

    let cards = Array.isArray( selectedGroup.cards ) ? selectedGroup.cards.slice() : [];

    if ( setValue ) {
      cards = cards.filter( ( card ) => card.set === setValue );
    }

    if ( selectedTypeValue ) {
      cards = cards.filter( ( card ) => cardMatchesSelectedType( card, selectedGroup ) );
    }

    if ( searchTerm ) {
      cards = cards.filter( ( card ) => card.name.toLowerCase().includes( searchTerm ) );
    }

    return cards;
  }

  function updateSetOptions() {
    const typeValue = gameSelect.value;
    setSelect.innerHTML = '';
    setSelect.appendChild( createOption( '', window.tcgKioskData.i18n?.allSets || setSelect.dataset.placeholder ) );

    const sets = new Set();

    if ( ! typeValue ) {
      setSelect.disabled = true;
      return;
    }

    const selected = data.find( ( group ) => group.slug === typeValue );

    if ( ! selected ) {
      setSelect.disabled = true;
      return;
    }

    selected.cards.forEach( ( card ) => {
      if ( card.set ) {
        sets.add( card.set );
      }
    } );

    const availableSets = Array.from( sets );
    const configuredOrder = Array.isArray( selected.setOrder ) ? selected.setOrder : [];
    const orderedSets = [];

    if ( configuredOrder.length ) {
      const seen = new Set();

      configuredOrder.forEach( ( setName ) => {
        if ( sets.has( setName ) && ! seen.has( setName ) ) {
          orderedSets.push( setName );
          seen.add( setName );
        }
      } );

      availableSets.forEach( ( setName ) => {
        if ( ! seen.has( setName ) ) {
          orderedSets.push( setName );
        }
      } );
    } else {
      availableSets.sort( ( a, b ) => a.localeCompare( b ) );
      orderedSets.push( ...availableSets );
    }

    orderedSets.forEach( ( setName ) => {
      setSelect.appendChild( createOption( setName, setName ) );
    } );

    setSelect.disabled = sets.size === 0;
  }

  function updateActiveTypeButton() {
    const buttons = typeOptionsContainer.querySelectorAll( '.tcg-kiosk__type-button' );

    buttons.forEach( ( button ) => {
      const value = button.dataset.value || '';
      const isActive = value ? value === selectedTypeValue : selectedTypeValue === '';
      button.classList.toggle( 'is-active', isActive );
      button.setAttribute( 'aria-pressed', isActive ? 'true' : 'false' );
    } );
  }

  function createTypeButton( value, label ) {
    const button = document.createElement( 'button' );
    button.type = 'button';
    button.className = 'tcg-kiosk__type-button';
    button.dataset.value = value;
    button.setAttribute( 'aria-pressed', 'false' );
    button.title = label;

    const iconUrl = getTypeIconUrl( label, value );
    const normalizedIconKey = normalizeTypeIconKey( value ) || normalizeTypeIconKey( label );

    if ( iconUrl ) {
      button.classList.add( 'tcg-kiosk__type-button--has-icon' );

      if ( normalizedIconKey && LARGE_TYPE_ICON_KEYS.has( normalizedIconKey ) ) {
        button.classList.add( 'tcg-kiosk__type-button--large-icon' );
      } else if ( normalizedIconKey && COMPACT_TYPE_ICON_KEYS.has( normalizedIconKey ) ) {
        button.classList.add( 'tcg-kiosk__type-button--compact-icon' );
      }

      const image = document.createElement( 'img' );
      image.className = 'tcg-kiosk__type-button-image';
      image.src = iconUrl;
      image.alt = '';
      image.loading = 'lazy';
      image.setAttribute( 'aria-hidden', 'true' );
      button.appendChild( image );

      const hiddenLabel = document.createElement( 'span' );
      hiddenLabel.className = 'tcg-kiosk__type-button-text';
      hiddenLabel.textContent = label;
      button.appendChild( hiddenLabel );
    } else {
      button.textContent = label;
    }

    button.addEventListener( 'click', () => {
      if ( value && selectedTypeValue === value ) {
        selectedTypeValue = '';
      } else {
        selectedTypeValue = value;
      }

      hasInteracted = true;
      currentPage = 1;
      updateActiveTypeButton();
      renderCards();
    } );

    return button;
  }

  function normalizeTypeValue( value, caseInsensitive ) {
    if ( 'string' !== typeof value ) {
      return '';
    }

    const cleaned = value.trim();

    if ( ! cleaned ) {
      return '';
    }

    return caseInsensitive ? cleaned.toLowerCase() : cleaned;
  }

  function cardMatchesSelectedType( card, group ) {
    if ( ! group || ! Array.isArray( card.typeValues ) || ! card.typeValues.length ) {
      return false;
    }

    const matchMode = group.typeMatchMode || 'exact';
    const caseInsensitive = Boolean( group.typeCaseInsensitive );
    const selection = normalizeTypeValue( selectedTypeValue, caseInsensitive );

    if ( ! selection ) {
      return false;
    }

    return card.typeValues.some( ( rawValue ) => {
      const candidate = normalizeTypeValue( rawValue, caseInsensitive );

      if ( ! candidate ) {
        return false;
      }

      if ( 'contains' === matchMode ) {
        return candidate.includes( selection );
      }

      return candidate === selection;
    } );
  }

  function updateTypeOptions() {
    selectedTypeValue = '';
    typeOptionsContainer.innerHTML = '';
    typeFilterWrapper.hidden = true;

    const defaultLabel = typeFilterWrapper.dataset.defaultLabel || 'Type';
    typeFilterLabel.textContent = defaultLabel;

    const typeValue = gameSelect.value;

    if ( ! typeValue ) {
      return;
    }

    const selected = data.find( ( group ) => group.slug === typeValue );

    if ( ! selected ) {
      return;
    }

    const label = selected.typeLabel || defaultLabel;
    typeFilterLabel.textContent = label;

    const presetOptions = Array.isArray( selected.typeOptions ) ? selected.typeOptions : [];
    const template = i18n.allTypeTemplate || 'All %s';
    const allLabel = template.includes( '%s' ) ? template.replace( '%s', label ) : template;
    const includeAllOption = selected.typeIncludeAllOption !== false;
    const options = [];

    if ( presetOptions.length ) {
      presetOptions.forEach( ( option ) => {
        if ( ! option ) {
          return;
        }

        if ( 'string' === typeof option ) {
          options.push( { value: option, label: option } );
          return;
        }

        const value = option.value;

        if ( ! value ) {
          return;
        }

        const entry = { value, label: option.label || value };

        if ( Object.prototype.hasOwnProperty.call( option, 'row' ) ) {
          const rawRow = option.row;

          if ( 'string' === typeof rawRow || 'number' === typeof rawRow ) {
            const normalizedRow = String( rawRow ).trim();

            if ( normalizedRow ) {
              entry.row = normalizedRow;
            }
          }
        }

        options.push( entry );
      } );
    } else {
      const typeValues = new Set();

      selected.cards.forEach( ( card ) => {
        if ( Array.isArray( card.typeValues ) ) {
          card.typeValues.forEach( ( value ) => {
            if ( value ) {
              typeValues.add( value );
            }
          } );
        }
      } );

      if ( ! typeValues.size ) {
        return;
      }

      Array.from( typeValues )
        .sort( ( a, b ) => a.localeCompare( b ) )
        .forEach( ( value ) => {
          options.push( { value, label: value } );
        } );
    }

    if ( ! options.length ) {
      return;
    }

    const rowContainers = new Map();
    const DEFAULT_ROW_KEY = '__default__';

    function normalizeRowKey( value ) {
      if ( 'string' === typeof value || 'number' === typeof value ) {
        const normalized = String( value ).trim();

        if ( normalized ) {
          return normalized;
        }
      }

      return '';
    }

    function appendToRow( element, rawRowKey ) {
      const normalizedKey = normalizeRowKey( rawRowKey );
      const resolvedKey = normalizedKey || DEFAULT_ROW_KEY;
      let row = rowContainers.get( resolvedKey );

      if ( ! row ) {
        row = document.createElement( 'div' );
        row.className = 'tcg-kiosk__type-row';

        if ( resolvedKey !== DEFAULT_ROW_KEY ) {
          const modifier = normalizeTypeIconKey( resolvedKey );

          if ( modifier ) {
            row.classList.add( `tcg-kiosk__type-row--${modifier}` );
          }

          row.dataset.rowKey = resolvedKey;
        }

        rowContainers.set( resolvedKey, row );
      }

      if ( ! row.isConnected ) {
        typeOptionsContainer.appendChild( row );
      }

      row.appendChild( element );
    }

    if ( includeAllOption ) {
      appendToRow( createTypeButton( '', allLabel ) );
    }

    options.forEach( ( option ) => {
      appendToRow( createTypeButton( option.value, option.label ), option.row );
    } );

    updateActiveTypeButton();
    typeFilterWrapper.hidden = false;
  }

  function buildProxiedSrcset( srcset ) {
    if ( 'string' !== typeof srcset || ! srcset.trim() ) {
      return '';
    }

    const candidates = srcset.split( ',' );
    const rewritten = candidates
      .map( ( candidate ) => {
        const trimmed = candidate.trim();

        if ( ! trimmed ) {
          return '';
        }

        const parts = trimmed.split( /\s+/ );

        if ( ! parts.length ) {
          return '';
        }

        const proxied = getProxiedImageUrl( parts[0] );

        if ( ! proxied ) {
          return trimmed;
        }

        return [ proxied ].concat( parts.slice( 1 ) ).join( ' ' );
      } )
      .filter( Boolean );

    return rewritten.join( ', ' );
  }

  function getProxiedImageUrl( url ) {
    return '';
  }

  function renderCards() {
    applyPageSizeLayout();

    closeCardOverlay( { restoreFocus: false } );

    if ( ! hasInteracted ) {
      resultsContainer.innerHTML = '';
      renderPagination( 0 );
      return;
    }

    const cards = getFilteredCards();

    const totalPages = Math.ceil( cards.length / cardsPerPage );

    if ( totalPages === 0 ) {
      currentPage = 1;
    } else if ( currentPage > totalPages ) {
      currentPage = totalPages;
    }

    resultsContainer.innerHTML = '';

    if ( ! cards.length ) {
      const emptyState = document.createElement( 'p' );
      emptyState.className = 'tcg-kiosk__empty';
      emptyState.textContent = i18n.noCards || 'No cards match your filters.';
      resultsContainer.appendChild( emptyState );
      renderPagination( 0 );
      return;
    }

    const fragment = document.createDocumentFragment();

    const startIndex = ( currentPage - 1 ) * cardsPerPage;
    const pageCards = cards.slice( startIndex, startIndex + cardsPerPage );

    pageCards.forEach( ( card, index ) => {
      const item = document.createElement( 'article' );
      item.className = 'tcg-kiosk__card';

      attachCardOverlayHandlers( item, card );

      const img = document.createElement( 'img' );
      const proxiedUrl = getProxiedImageUrl( card.imageUrl );
      img.src = proxiedUrl || card.imageUrl;
      img.alt = card.name || 'Trading card image';
      img.loading = 'lazy';
      img.decoding = 'async';
      img.referrerPolicy = 'no-referrer';
      if ( proxiedUrl ) {
        img.dataset.usingProxy = 'true';
      } else {
        delete img.dataset.usingProxy;
      }
      if ( card.imageSrcset ) {
        const proxiedSrcset = proxiedUrl ? buildProxiedSrcset( card.imageSrcset ) : '';

        if ( proxiedSrcset ) {
          img.srcset = proxiedSrcset;
        } else if ( proxiedUrl ) {
          img.removeAttribute( 'srcset' );
        } else {
          img.srcset = card.imageSrcset;
        }
      }
      if ( card.imageSizes ) {
        img.sizes = card.imageSizes;
      }
      if ( card.imageFullUrl && card.imageFullUrl !== card.imageUrl ) {
        img.dataset.fullSrc = card.imageFullUrl;
      }
      if ( 0 === startIndex && index < 2 ) {
        img.fetchPriority = 'high';
      }
      img.addEventListener( 'error', () => handleImageError( img, card ) );
      item.appendChild( img );

      fragment.appendChild( item );
    } );

    resultsContainer.appendChild( fragment );
    renderPagination( totalPages );
  }

  function handleImageError( img, card ) {
    const attempts = img.dataset.attempts
      ? img.dataset.attempts
          .split( ',' )
          .map( ( token ) => token.trim() )
          .filter( Boolean )
      : [];
    const usingProxy = img.dataset.usingProxy === 'true';

    const recordAttempt = ( token ) => {
      if ( attempts.includes( token ) ) {
        return false;
      }

      attempts.push( token );
      img.dataset.attempts = attempts.join( ',' );
      return true;
    };

    if ( ! usingProxy && card.imageFullUrl && img.src !== card.imageFullUrl && recordAttempt( 'full' ) ) {
      img.src = card.imageFullUrl;
      return;
    }

    const proxied = getProxiedImageUrl( card.imageFullUrl || card.imageUrl );

    if ( proxied && img.src !== proxied && recordAttempt( 'proxy' ) ) {
      img.dataset.usingProxy = 'true';
      if ( card.imageSrcset ) {
        const proxiedSrcset = buildProxiedSrcset( card.imageSrcset );

        if ( proxiedSrcset ) {
          img.srcset = proxiedSrcset;
        } else {
          img.removeAttribute( 'srcset' );
        }
      }

      img.src = proxied;
      return;
    }

    if ( usingProxy ) {
      const origin = card.imageFullUrl || card.imageUrl;

      if ( origin && img.src !== origin && recordAttempt( 'origin' ) ) {
        delete img.dataset.usingProxy;

        if ( card.imageSrcset ) {
          img.srcset = card.imageSrcset;
        } else {
          img.removeAttribute( 'srcset' );
        }

        img.src = origin;
        return;
      }
    }

    recordAttempt( 'failed' );
  }

  function renderPagination( totalPages ) {
    paginationContainer.innerHTML = '';

    if ( totalPages <= 1 ) {
      paginationContainer.hidden = true;
      return;
    }

    paginationContainer.hidden = false;

    const prevButton = document.createElement( 'button' );
    prevButton.type = 'button';
    prevButton.className = 'tcg-kiosk__page-button';
    prevButton.textContent = i18n.previous || 'Previous';
    prevButton.disabled = currentPage === 1;
    prevButton.addEventListener( 'click', () => {
      if ( currentPage > 1 ) {
        currentPage -= 1;
        renderCards();
      }
    } );

    const status = document.createElement( 'span' );
    status.className = 'tcg-kiosk__page-status';
    const statusTemplate = i18n.pageStatus || 'Page %1$s of %2$s';
    status.textContent = statusTemplate.replace( '%1$s', currentPage ).replace( '%2$s', totalPages );

    const nextButton = document.createElement( 'button' );
    nextButton.type = 'button';
    nextButton.className = 'tcg-kiosk__page-button';
    nextButton.textContent = i18n.next || 'Next';
    nextButton.disabled = currentPage === totalPages;
    nextButton.addEventListener( 'click', () => {
      if ( currentPage < totalPages ) {
        currentPage += 1;
        renderCards();
      }
    } );

    paginationContainer.appendChild( prevButton );
    paginationContainer.appendChild( status );
    paginationContainer.appendChild( nextButton );
  }

  if ( cardOverlay ) {
    cardOverlay.addEventListener( 'click', ( event ) => {
      if ( event.target === cardOverlay ) {
        closeCardOverlay();
      }
    } );
  }

  if ( cardOverlayPanel ) {
    cardOverlayPanel.addEventListener( 'click', ( event ) => {
      event.stopPropagation();
    } );
  }

  if ( cardOverlayClose ) {
    cardOverlayClose.addEventListener( 'click', () => closeCardOverlay() );
  }

  gameSelect.addEventListener( 'change', () => {
    hasInteracted = true;
    currentPage = 1;
    updateSetOptions();
    updateTypeOptions();
    renderCards();
  } );

  setSelect.addEventListener( 'change', () => {
    hasInteracted = true;
    currentPage = 1;
    renderCards();
  } );

  searchInput.addEventListener( 'input', () => {
    hasInteracted = true;
    currentPage = 1;
    renderCards();
  } );

  pageSizeSelect.addEventListener( 'change', () => {
    const requested = parseInt( pageSizeSelect.value, 10 );

    if ( Number.isInteger( requested ) ) {
      cardsPerPage = requested;
      applyPageSizeLayout();
      hasInteracted = true;
      currentPage = 1;
      renderCards();
    }
  } );

  const placeholders = i18n;
  if ( setSelect.dataset ) {
    setSelect.dataset.placeholder = placeholders.allSets || setSelect.dataset.placeholder;
  }

  const setPlaceholderOption = setSelect.querySelector( 'option[value=""]' );

  if ( setPlaceholderOption ) {
    setPlaceholderOption.textContent = setSelect.dataset.placeholder || placeholders.allSets || 'All Sets';
  }

  updatePageSizeLabel();
  populateGameOptions();
  populateOverlayOptions();
  updateSetOptions();
  updateTypeOptions();
  applyPageSizeLayout();
  renderPagination( 0 );
  if ( gameSelect.value ) {
    hideOverlay();
  } else {
    showOverlay();
  }
})();
JS;
    }

    /**
     * Render shortcode output.
     *
     * @return string
     */
    public function render_shortcode() {
        ob_start();
        ?>
        <div class="tcg-kiosk" data-page-size="10">
            <div id="tcg-kiosk-overlay" class="tcg-kiosk__overlay" role="dialog" aria-modal="true" aria-labelledby="tcg-kiosk-overlay-title" aria-describedby="tcg-kiosk-overlay-subtitle" hidden>
                <div class="tcg-kiosk__overlay-panel">
                    <h2 id="tcg-kiosk-overlay-title" class="tcg-kiosk__overlay-title"><?php esc_html_e( 'Choose a game', 'tcg-kiosk-filter' ); ?></h2>
                    <p id="tcg-kiosk-overlay-subtitle" class="tcg-kiosk__overlay-subtitle"><?php esc_html_e( 'Select a game to start browsing cards.', 'tcg-kiosk-filter' ); ?></p>
                    <div id="tcg-kiosk-overlay-options" class="tcg-kiosk__overlay-options" role="group" aria-label="<?php esc_attr_e( 'Available games', 'tcg-kiosk-filter' ); ?>"></div>
                </div>
            </div>
            <div id="tcg-kiosk-card-overlay" class="tcg-kiosk__card-overlay" role="dialog" aria-modal="true" aria-labelledby="tcg-kiosk-detail-title" aria-hidden="true" hidden tabindex="-1">
                <div class="tcg-kiosk__card-overlay-panel">
                    <button type="button" id="tcg-kiosk-detail-close" class="tcg-kiosk__card-overlay-close" aria-label="<?php esc_attr_e( 'Close card details', 'tcg-kiosk-filter' ); ?>">&times;</button>
                    <div class="tcg-kiosk__card-overlay-image">
                        <img id="tcg-kiosk-detail-image" alt="" />
                    </div>
                    <div class="tcg-kiosk__card-overlay-meta">
                        <h2 id="tcg-kiosk-detail-title" class="tcg-kiosk__card-overlay-title"></h2>
                        <dl id="tcg-kiosk-detail-metadata" class="tcg-kiosk__card-overlay-details"></dl>
                    </div>
                </div>
            </div>
            <header class="tcg-kiosk__header">
                <div class="tcg-kiosk__filters" role="group" aria-label="<?php esc_attr_e( 'Filter cards', 'tcg-kiosk-filter' ); ?>">
                    <label>
                        <span><?php esc_html_e( 'Trading Card Game', 'tcg-kiosk-filter' ); ?></span>
                        <select id="tcg-kiosk-game" class="tcg-kiosk__select" data-placeholder="<?php echo esc_attr__( 'Choose a game', 'tcg-kiosk-filter' ); ?>">
                            <option value="" disabled selected hidden><?php esc_html_e( 'Choose a game', 'tcg-kiosk-filter' ); ?></option>
                        </select>
                    </label>
                    <label>
                        <span><?php esc_html_e( 'Set', 'tcg-kiosk-filter' ); ?></span>
                        <select id="tcg-kiosk-set" class="tcg-kiosk__select" data-placeholder="<?php echo esc_attr__( 'All Sets', 'tcg-kiosk-filter' ); ?>" disabled>
                            <option value=""><?php esc_html_e( 'All Sets', 'tcg-kiosk-filter' ); ?></option>
                        </select>
                    </label>
                </div>
                <div id="tcg-kiosk-type-filter" class="tcg-kiosk__type-filter" role="group" aria-labelledby="tcg-kiosk-type-label" data-default-label="<?php echo esc_attr__( 'Type', 'tcg-kiosk-filter' ); ?>" hidden>
                    <span id="tcg-kiosk-type-label" class="tcg-kiosk__type-filter-label"><?php esc_html_e( 'Type', 'tcg-kiosk-filter' ); ?></span>
                    <div id="tcg-kiosk-type-options" class="tcg-kiosk__type-options" role="presentation"></div>
                </div>
                <div class="tcg-kiosk__actions">
                    <div class="tcg-kiosk__search" role="search">
                        <label class="screen-reader-text" for="tcg-kiosk-search"><?php esc_html_e( 'Search by card name', 'tcg-kiosk-filter' ); ?></label>
                        <input type="search" id="tcg-kiosk-search" placeholder="<?php echo esc_attr__( 'Search cards…', 'tcg-kiosk-filter' ); ?>" />
                    </div>
                    <label class="tcg-kiosk__page-size" for="tcg-kiosk-page-size">
                        <span><?php esc_html_e( 'Cards per page', 'tcg-kiosk-filter' ); ?></span>
                        <select id="tcg-kiosk-page-size" class="tcg-kiosk__select">
                            <option value="10" selected>10</option>
                            <option value="12">12</option>
                            <option value="16">16</option>
                            <option value="20">20</option>
                        </select>
                    </label>
                </div>
            </header>
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

        if ( $page instanceof WP_Post ) {
            $this->synchronize_page( $page->ID );
            return;
        }

        $trashed_page = get_posts(
            array(
                'post_type'      => 'page',
                'post_status'    => 'trash',
                'name'           => self::PAGE_SLUG,
                'posts_per_page' => 1,
            )
        );

        if ( ! empty( $trashed_page ) ) {
            $page_id = (int) $trashed_page[0]->ID;
            wp_untrash_post( $page_id );
            $this->synchronize_page( $page_id );
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

        update_option( self::PAGE_OPTION, (int) $page_id );
    }

    /**
     * Normalise the kiosk page content, slug and publication status.
     *
     * @param int $page_id Page identifier to synchronise.
     */
    protected function synchronize_page( $page_id ) {
        $page = get_post( $page_id );

        if ( ! $page instanceof WP_Post ) {
            return;
        }

        if ( 'trash' === $page->post_status ) {
            wp_untrash_post( $page_id );
            $page = get_post( $page_id );
        }

        $update = array( 'ID' => $page_id );
        $needs_update = false;

        if ( self::PAGE_TITLE !== $page->post_title ) {
            $update['post_title'] = self::PAGE_TITLE;
            $needs_update         = true;
        }

        $expected_content = '[' . self::SHORTCODE . ']';

        if ( $expected_content !== trim( $page->post_content ) ) {
            $update['post_content'] = $expected_content;
            $needs_update           = true;
        }

        if ( self::PAGE_SLUG !== $page->post_name ) {
            $update['post_name'] = self::PAGE_SLUG;
            $needs_update        = true;
        }

        if ( 'publish' !== $page->post_status ) {
            $update['post_status'] = 'publish';
            $needs_update          = true;
        }

        if ( $needs_update ) {
            wp_update_post( $update );
        }

        update_option( self::PAGE_OPTION, (int) $page_id );
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
