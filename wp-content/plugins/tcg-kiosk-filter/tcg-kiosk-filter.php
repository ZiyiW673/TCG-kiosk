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

        wp_localize_script(
            'tcg-kiosk-filter',
            'tcgKioskData',
            array(
                'cards'        => $data['cards'],
                'lastModified' => $data['lastModified'],
                'i18n'         => array(
                    'allGames' => __( 'All Games', 'tcg-kiosk-filter' ),
                    'allSets'  => __( 'All Sets', 'tcg-kiosk-filter' ),
                    'allTypeTemplate' => __( 'All %s', 'tcg-kiosk-filter' ),
                    'noCards'  => __( 'No cards match your filters.', 'tcg-kiosk-filter' ),
                    'previous' => __( 'Previous', 'tcg-kiosk-filter' ),
                    'next'     => __( 'Next', 'tcg-kiosk-filter' ),
                    'pageStatus' => __( 'Page %1$s of %2$s', 'tcg-kiosk-filter' ),
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
    flex-wrap: wrap;
    gap: 0.5rem;
}

.tcg-kiosk__type-button {
    border-radius: 999px;
    border: 1px solid #ccd0d4;
    background-color: #fff;
    color: #1d2327;
    padding: 0.35rem 0.9rem;
    font-size: 13px;
    cursor: pointer;
    transition: background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease;
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

.tcg-kiosk__search {
    flex: 0 0 25%;
    position: relative;
}

.tcg-kiosk__search input[type="search"] {
    width: 100%;
}

.tcg-kiosk__grid {
    display: grid;
    gap: 1.5rem;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
}

.tcg-kiosk__card {
    background: #fff;
    border: 1px solid #dcdcde;
    border-radius: 6px;
    box-shadow: 0 1px 1px rgba(0, 0, 0, 0.08);
    overflow: hidden;
    padding: 1rem;
    display: grid;
    gap: 0.75rem;
}

.tcg-kiosk__card img {
    width: 100%;
    height: auto;
    border-radius: 4px;
    background: #f6f7f7;
}

.tcg-kiosk__card h3 {
    margin: 0;
    font-size: 1rem;
}

.tcg-kiosk__meta {
    margin: 0;
    font-size: 0.875rem;
    color: #50575e;
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
    .tcg-kiosk__search {
        flex: 1 1 100%;
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

  const gameSelect = document.getElementById( 'tcg-kiosk-game' );
  const setSelect = document.getElementById( 'tcg-kiosk-set' );
  const typeFilterWrapper = document.getElementById( 'tcg-kiosk-type-filter' );
  const typeFilterLabel = document.getElementById( 'tcg-kiosk-type-label' );
  const typeOptionsContainer = document.getElementById( 'tcg-kiosk-type-options' );
  const searchInput = document.getElementById( 'tcg-kiosk-search' );
  const resultsContainer = document.getElementById( 'tcg-kiosk-results' );
  const paginationContainer = document.getElementById( 'tcg-kiosk-pagination' );

  if ( ! gameSelect || ! setSelect || ! typeFilterWrapper || ! typeFilterLabel || ! typeOptionsContainer || ! searchInput || ! resultsContainer || ! paginationContainer ) {
    return;
  }

  const data = window.tcgKioskData.cards;
  const i18n = window.tcgKioskData.i18n || {};
  const CARDS_PER_PAGE = 10;
  let hasInteracted = false;
  let currentPage = 1;
  let selectedTypeValue = '';

  function createOption( value, label ) {
    const option = document.createElement( 'option' );
    option.value = value;
    option.textContent = label;
    return option;
  }

  function populateGameOptions() {
    data.forEach( ( type ) => {
      gameSelect.appendChild( createOption( type.slug, type.label ) );
    } );
  }

  function getFilteredCards() {
    const typeValue = gameSelect.value;
    const setValue = setSelect.value;
    const searchTerm = searchInput.value.trim().toLowerCase();
    const selectedGroup = typeValue ? data.find( ( group ) => group.slug === typeValue ) : null;
    let filtered = [];

    if ( typeValue ) {
      filtered = data.filter( ( group ) => group.slug === typeValue );
    } else {
      filtered = data;
    }

    let cards = [];
    filtered.forEach( ( group ) => {
      if ( Array.isArray( group.cards ) ) {
        cards = cards.concat( group.cards );
      }
    } );

    if ( setValue ) {
      cards = cards.filter( ( card ) => card.set === setValue );
    }

    if ( selectedTypeValue ) {
      if ( selectedGroup ) {
        cards = cards.filter( ( card ) => cardMatchesSelectedType( card, selectedGroup ) );
      } else {
        cards = [];
      }
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

    Array.from( sets )
      .sort()
      .forEach( ( setName ) => {
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
    button.textContent = label;
    button.setAttribute( 'aria-pressed', 'false' );
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

        options.push( { value, label: option.label || value } );
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

    typeOptionsContainer.appendChild( createTypeButton( '', allLabel ) );

    options.forEach( ( option ) => {
      typeOptionsContainer.appendChild( createTypeButton( option.value, option.label ) );
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
    if ( ! url ) {
      return '';
    }

    let parsed;

    try {
      parsed = new URL( url );
    } catch ( error ) {
      return '';
    }

    const host = parsed.hostname.toLowerCase();

    if ( 'images.weserv.nl' === host ) {
      return '';
    }

    if ( ! host.endsWith( 'gundam-gcg.com' ) ) {
      return '';
    }

    return 'https://images.weserv.nl/?url=' + encodeURIComponent( url );
  }

  function renderCards() {
    if ( ! hasInteracted ) {
      resultsContainer.innerHTML = '';
      renderPagination( 0 );
      return;
    }

    const cards = getFilteredCards();

    const totalPages = Math.ceil( cards.length / CARDS_PER_PAGE );

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

    const startIndex = ( currentPage - 1 ) * CARDS_PER_PAGE;
    const pageCards = cards.slice( startIndex, startIndex + CARDS_PER_PAGE );

    pageCards.forEach( ( card, index ) => {
      const item = document.createElement( 'article' );
      item.className = 'tcg-kiosk__card';

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

      const name = document.createElement( 'h3' );
      name.textContent = card.name || 'Untitled Card';

      const meta = document.createElement( 'p' );
      meta.className = 'tcg-kiosk__meta';
      meta.textContent = card.set || '';

      item.appendChild( img );
      item.appendChild( name );
      if ( card.set ) {
        item.appendChild( meta );
      }

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

  const placeholders = i18n;
  gameSelect.dataset.placeholder = placeholders.allGames || gameSelect.dataset.placeholder;
  setSelect.dataset.placeholder = placeholders.allSets || setSelect.dataset.placeholder;
  gameSelect.querySelector( 'option[value=""]' ).textContent = gameSelect.dataset.placeholder;
  setSelect.querySelector( 'option[value=""]' ).textContent = setSelect.dataset.placeholder;

  populateGameOptions();
  updateSetOptions();
  updateTypeOptions();
  renderPagination( 0 );
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
        <div class="tcg-kiosk">
            <header class="tcg-kiosk__header">
                <div class="tcg-kiosk__filters" role="group" aria-label="<?php esc_attr_e( 'Filter cards', 'tcg-kiosk-filter' ); ?>">
                    <label>
                        <span><?php esc_html_e( 'Trading Card Game', 'tcg-kiosk-filter' ); ?></span>
                        <select id="tcg-kiosk-game" class="tcg-kiosk__select" data-placeholder="<?php echo esc_attr__( 'All Games', 'tcg-kiosk-filter' ); ?>">
                            <option value=""><?php esc_html_e( 'All Games', 'tcg-kiosk-filter' ); ?></option>
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
                <div class="tcg-kiosk__search" role="search">
                    <label class="screen-reader-text" for="tcg-kiosk-search"><?php esc_html_e( 'Search by card name', 'tcg-kiosk-filter' ); ?></label>
                    <input type="search" id="tcg-kiosk-search" placeholder="<?php echo esc_attr__( 'Search cards…', 'tcg-kiosk-filter' ); ?>" />
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
