(function () {
  if ( ! window.tcgKioskData || ! window.tcgKioskData.cards ) {
    return;
  }

  const typeSelect = document.getElementById( 'tcg-kiosk-type' );
  const setSelect = document.getElementById( 'tcg-kiosk-set' );
  const searchInput = document.getElementById( 'tcg-kiosk-search' );
  const resultsContainer = document.getElementById( 'tcg-kiosk-results' );
  const paginationContainer = document.getElementById( 'tcg-kiosk-pagination' );

  if ( ! typeSelect || ! setSelect || ! searchInput || ! resultsContainer || ! paginationContainer ) {
    return;
  }

  const data = window.tcgKioskData.cards;
  const i18n = window.tcgKioskData.i18n || {};
  const CARDS_PER_PAGE = 10;
  let hasInteracted = false;
  let currentPage = 1;

  function createOption( value, label ) {
    const option = document.createElement( 'option' );
    option.value = value;
    option.textContent = label;
    return option;
  }

  function populateTypeOptions() {
    data.forEach( ( type ) => {
      typeSelect.appendChild( createOption( type.slug, type.label ) );
    } );
  }

  function getFilteredCards() {
    const typeValue = typeSelect.value;
    const setValue = setSelect.value;
    const searchTerm = searchInput.value.trim().toLowerCase();
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

    if ( searchTerm ) {
      cards = cards.filter( ( card ) => card.name.toLowerCase().includes( searchTerm ) );
    }

    return cards;
  }

  function updateSetOptions() {
    const typeValue = typeSelect.value;
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

    pageCards.forEach( ( card ) => {
      const item = document.createElement( 'article' );
      item.className = 'tcg-kiosk__card';

      const img = document.createElement( 'img' );
      img.src = card.imageUrl;
      img.alt = card.name || 'Trading card image';
      img.loading = 'lazy';
      img.decoding = 'async';
      img.referrerPolicy = 'no-referrer';
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
    if ( img.dataset.retry ) {
      return;
    }

    const proxied = getProxiedImageUrl( card.imageUrl );

    if ( proxied ) {
      img.dataset.retry = 'true';
      img.src = proxied;
    } else {
      img.dataset.retry = 'failed';
    }
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

    if ( ! host.endsWith( 'gundam-gcg.com' ) ) {
      return '';
    }

    return 'https://images.weserv.nl/?url=' + encodeURIComponent( url );
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

  typeSelect.addEventListener( 'change', () => {
    hasInteracted = true;
    currentPage = 1;
    updateSetOptions();
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
  typeSelect.dataset.placeholder = placeholders.allGames || typeSelect.dataset.placeholder;
  setSelect.dataset.placeholder = placeholders.allSets || setSelect.dataset.placeholder;
  typeSelect.querySelector( 'option[value=""]' ).textContent = typeSelect.dataset.placeholder;
  setSelect.querySelector( 'option[value=""]' ).textContent = setSelect.dataset.placeholder;

  populateTypeOptions();
  updateSetOptions();
  renderPagination( 0 );
})();
