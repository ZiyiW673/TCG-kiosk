(function () {
  if ( ! window.tcgKioskData || ! window.tcgKioskData.cards ) {
    return;
  }

  const typeSelect = document.getElementById( 'tcg-kiosk-type' );
  const setSelect = document.getElementById( 'tcg-kiosk-set' );
  const searchInput = document.getElementById( 'tcg-kiosk-search' );
  const resultsContainer = document.getElementById( 'tcg-kiosk-results' );

  if ( ! typeSelect || ! setSelect || ! searchInput || ! resultsContainer ) {
    return;
  }

  const data = window.tcgKioskData.cards;

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
    const cards = getFilteredCards();

    resultsContainer.innerHTML = '';

    if ( ! cards.length ) {
      const emptyState = document.createElement( 'p' );
      emptyState.className = 'tcg-kiosk__empty';
      emptyState.textContent = window.tcgKioskData.i18n?.noCards || 'No cards match your filters.';
      resultsContainer.appendChild( emptyState );
      return;
    }

    const fragment = document.createDocumentFragment();

    cards.forEach( ( card ) => {
      const item = document.createElement( 'article' );
      item.className = 'tcg-kiosk__card';

      const img = document.createElement( 'img' );
      img.src = card.imageUrl;
      img.alt = card.name || 'Trading card image';
      img.loading = 'lazy';

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
  }

  typeSelect.addEventListener( 'change', () => {
    updateSetOptions();
    renderCards();
  } );

  setSelect.addEventListener( 'change', renderCards );
  searchInput.addEventListener( 'input', renderCards );

  const placeholders = window.tcgKioskData.i18n || {};
  typeSelect.dataset.placeholder = placeholders.allGames || typeSelect.dataset.placeholder;
  setSelect.dataset.placeholder = placeholders.allSets || setSelect.dataset.placeholder;
  typeSelect.querySelector( 'option[value=""]' ).textContent = typeSelect.dataset.placeholder;
  setSelect.querySelector( 'option[value=""]' ).textContent = setSelect.dataset.placeholder;

  populateTypeOptions();
  updateSetOptions();
  renderCards();
})();
