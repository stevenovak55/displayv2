/**
 * MLS Listings Display Map View v4.4.0
 * - FIX: `refreshMapListings` now correctly sends the `is_new_filter` flag to the backend.
 * - FIX: `handleMapIdle` logic is corrected to ensure the initial load works reliably.
 * - STYLE: `createCountMarker` now uses the `bme-price-marker` class for consistent styling.
 */
(function($) {
    $(document).ready(function() {
        // --- SETUP & INITIALIZATION ---
        const mapContainer = document.getElementById('bme-map-container');
        if (!mapContainer || typeof bmeMapData === 'undefined') return;

        document.body.classList.add('mld-map-active');

        let map;
        let markers = [];
        let allListingsInView = [];
        let openPopupIds = new Set();
        let autocompleteRequest;
        let debounceTimer;
        let isInitialLoad = true;

        // --- STATE MANAGEMENT ---
        let keywordFilters = {}; 
        let modalFilters = getModalDefaults();
        let isNewFilterAction = false; 

        // --- INITIALIZE ---
        initMap();
        initSearchAndFilters();

        async function initMap() {
            if (bmeMapData.provider === 'google') {
                const { Map } = await google.maps.importLibrary("maps");
                map = new Map(mapContainer, {
                    center: { lat: 42.3601, lng: -71.0589 },
                    zoom: 11,
                    mapId: 'BME_MAP_ID',
                    gestureHandling: 'greedy',
                    fullscreenControl: false,
                    mapTypeControl: true,
                    mapTypeControlOptions: {
                        style: google.maps.MapTypeControlStyle.HORIZONTAL_BAR,
                        position: google.maps.ControlPosition.BOTTOM_LEFT, 
                    },
                    streetViewControl: false,
                    zoomControlOptions: {
                        position: google.maps.ControlPosition.LEFT_BOTTOM 
                    }
                });
                
                map.addListener('idle', handleMapIdle);

            } else {
                mapboxgl.accessToken = bmeMapData.mapbox_key;
                map = new mapboxgl.Map({
                    container: 'bme-map-container',
                    style: 'mapbox://styles/mapbox/streets-v11',
                    center: [-71.0589, 42.3601],
                    zoom: 10,
                });
                map.addControl(new mapboxgl.NavigationControl(), 'bottom-left');
                map.on('idle', handleMapIdle);
            }
            
            restoreStateFromUrl();
        }
        
        function handleMapIdle() {
            if (isInitialLoad) {
                isInitialLoad = false;
                refreshMapListings(true); 
            } else {
                refreshMapListings(false);
            }
        }


        function initSearchAndFilters() {
            const $searchInput = $('#bme-search-input');
            const $suggestionsContainer = $('#bme-autocomplete-suggestions');
            $searchInput.on('keyup', function(e) {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    const term = $(this).val();
                    if (term.length >= 2) {
                        fetchAutocompleteSuggestions(term);
                    } else {
                        $suggestionsContainer.hide().empty();
                    }
                }, 250);
            });
            $(document).on('click', (e) => {
                if (!$(e.target).closest('#bme-search-bar-wrapper').length) {
                    $suggestionsContainer.hide();
                }
            });

            const $filtersModal = $('#bme-filters-modal-overlay');
            $('#bme-filters-button').on('click', () => $filtersModal.css('display', 'flex'));
            $('#bme-filters-modal-close').on('click', () => $filtersModal.hide());
            $filtersModal.on('click', (e) => {
                if ($(e.target).is($filtersModal)) $filtersModal.hide();
            });

            $('#bme-apply-filters-btn').on('click', applyModalFilters);
            $('#bme-clear-filters-btn').on('click', clearAllFilters);

            $('.bme-button-group button').on('click', function() {
                $(this).siblings().removeClass('active');
                $(this).addClass('active');
            });
        }
        
        const refreshMapListings = (isNewAction = false) => {
            isNewFilterAction = isNewAction;
            const combinedFilters = getCombinedFilters();
            const hasFilters = Object.keys(combinedFilters).length > 0;

            let requestData = {
                action: 'get_map_listings',
                security: bmeMapData.security,
                is_new_filter: isNewFilterAction && hasFilters, // Send true only on a new filter action
            };

            // For all actions that are NOT a new global filter search, we must send the map bounds.
            if (!requestData.is_new_filter) {
                const bounds = getMapBounds();
                if (!bounds) return; // Can't search without bounds
                requestData = { ...requestData, ...bounds };
            }

            // Always include filters if they are active.
            if (hasFilters) {
                requestData.filters = JSON.stringify(combinedFilters);
            }

            $.post(bmeMapData.ajax_url, requestData, function(response) {
                if (response.success && response.data) {
                    allListingsInView = response.data;
                    groupAndDisplayMarkers(allListingsInView);
                    updateSidebarList(allListingsInView);

                    if (isNewFilterAction && hasFilters && allListingsInView.length > 0) {
                        fitMapToBounds(allListingsInView);
                    }
                }
            }).always(function() {
                updateUrlHash();
            });
        };
        
        // --- MARKER RENDERING LOGIC ---

        function groupAndDisplayMarkers(listings) {
            clearMarkers();
            const MAX_PINS = 25;

            const listingsByLocation = {};
            listings.forEach(listing => {
                const key = `${parseFloat(listing.Latitude).toFixed(6)},${parseFloat(listing.Longitude).toFixed(6)}`;
                if (!listingsByLocation[key]) {
                    listingsByLocation[key] = [];
                }
                listingsByLocation[key].push(listing);
            });

            const singleListings = [];
            
            for (const key in listingsByLocation) {
                const group = listingsByLocation[key];
                if (group.length > 1) {
                    const [lat, lng] = key.split(',');
                    createCountMarker(group, parseFloat(lng), parseFloat(lat));
                } else {
                    singleListings.push(group[0]);
                }
            }

            let listingsForPins = [];
            let listingsForDots = [];

            if (singleListings.length > MAX_PINS) {
                listingsForPins = singleListings.slice(0, MAX_PINS);
                listingsForDots = singleListings.slice(MAX_PINS);
            } else {
                listingsForPins = singleListings;
            }

            listingsForDots.forEach(listing => {
                createDotMarker(listing, parseFloat(listing.Longitude), parseFloat(listing.Latitude));
            });
            listingsForPins.forEach(listing => {
                createPriceMarker(listing, parseFloat(listing.Longitude), parseFloat(listing.Latitude));
            });
            
            reapplyActiveHighlights();
        }

        function createDotMarker(listing, lng, lat) {
            const dot = document.createElement('div');
            dot.className = 'bme-dot-marker';
            
            let pricePin = null; 

            $(dot).on('mouseenter', function() {
                if (pricePin) return;

                pricePin = document.createElement('div');
                pricePin.className = 'bme-price-marker';
                pricePin.textContent = formatPrice(listing.ListPrice);
                pricePin.onclick = () => handleMarkerClick(listing);

                const markerData = markers.find(m => m.listingId === `dot-${listing.ListingId}`);
                if (markerData && markerData.marker) {
                    if (bmeMapData.provider === 'google') {
                        markerData.marker.content = pricePin;
                    } else {
                        markerData.marker.getElement().replaceWith(pricePin);
                    }
                }
            }).on('mouseleave', function() {
                if (!pricePin) return;

                const markerData = markers.find(m => m.listingId === `dot-${listing.ListingId}`);
                if (markerData && markerData.marker) {
                     if (bmeMapData.provider === 'google') {
                        markerData.marker.content = dot;
                    } else {
                        markerData.marker.getElement().replaceWith(dot);
                    }
                }
                pricePin = null;
            });

            createMarkerElement(dot, lng, lat, `dot-${listing.ListingId}`);
        }


        // --- URL State Management ---
        function readUrlHash() {
            const hash = window.location.hash.substring(1);
            if (!hash) return { keywordFilters: {}, modalFilters: getModalDefaults() };

            const params = new URLSearchParams(hash);
            const newKeywordFilters = {};
            const newModalFilters = getModalDefaults();

            for (const [key, value] of params.entries()) {
                const values = value.split(',');
                if (['price_min', 'price_max', 'beds_min', 'baths_min'].includes(key)) {
                    newModalFilters[key] = value;
                } else if (key === 'home_type' || key === 'status') {
                    newModalFilters[key] = values;
                } else {
                    newKeywordFilters[key] = new Set(values);
                }
            }
            return { keywordFilters: newKeywordFilters, modalFilters: newModalFilters };
        }

        function restoreStateFromUrl() {
            const { keywordFilters: kf, modalFilters: mf } = readUrlHash();
            keywordFilters = kf;
            modalFilters = mf;
            
            renderFilterTags();
            
            $('#bme-filter-price-min').val(modalFilters.price_min);
            $('#bme-filter-price-max').val(modalFilters.price_max);
            $('#bme-filter-beds button').removeClass('active').filter(`[data-value="${modalFilters.beds_min}"]`).addClass('active');
            $('#bme-filter-baths button').removeClass('active').filter(`[data-value="${modalFilters.baths_min}"]`).addClass('active');
            
            $('#bme-filter-home-type input').prop('checked', false);
            modalFilters.home_type.forEach(ht => $(`#bme-filter-home-type input[value="${ht}"]`).prop('checked', true));

            $('#bme-filter-status input').prop('checked', false);
            modalFilters.status.forEach(s => $(`#bme-filter-status input[value="${s}"]`).prop('checked', true));
        }

        function updateUrlHash() {
            const params = new URLSearchParams();
            const combined = getCombinedFilters();

            for (const key in combined) {
                if (Array.isArray(combined[key]) || combined[key] instanceof Set) {
                    if (Array.from(combined[key]).length > 0) {
                        params.set(key, Array.from(combined[key]).join(','));
                    }
                } else if (combined[key]) {
                     params.set(key, combined[key]);
                }
            }
            
            const newHash = '#' + params.toString();
            
            if (window.location.hash !== newHash) {
                history.replaceState(null, '', newHash);
            }
        }


        // --- Keyword Filter Functions ---
        function fetchAutocompleteSuggestions(term) {
            if (autocompleteRequest) autocompleteRequest.abort();
            autocompleteRequest = $.post(bmeMapData.ajax_url, {
                action: 'get_autocomplete_suggestions',
                security: bmeMapData.security,
                term: term
            }, function(response) {
                if (response.success && response.data) {
                    renderAutocompleteSuggestions(response.data);
                }
            });
        }

        function renderAutocompleteSuggestions(suggestions) {
            const $suggestionsContainer = $('#bme-autocomplete-suggestions');
            if (!suggestions || suggestions.length === 0) {
                $suggestionsContainer.hide().empty();
                return;
            }
            let html = suggestions.map(s => `
                <div class="bme-suggestion-item" data-type="${s.type}" data-value="${s.value}">
                    <span>${s.value}</span>
                    <span class="bme-suggestion-type">${s.type}</span>
                </div>`).join('');
            
            $suggestionsContainer.html(html).show();
            $('.bme-suggestion-item').on('click', function() {
                addKeywordFilter($(this).data('type'), $(this).data('value'));
                $suggestionsContainer.hide().empty();
                $('#bme-search-input').val('').focus();
            });
        }

        function addKeywordFilter(type, value) {
            if (!keywordFilters[type]) {
                keywordFilters[type] = new Set();
            }
            keywordFilters[type].add(value);
            renderFilterTags();
            refreshMapListings(true);
        }

        function removeKeywordFilter(type, value) {
            if (keywordFilters[type]) {
                keywordFilters[type].delete(value);
                if (keywordFilters[type].size === 0) {
                    delete keywordFilters[type];
                }
            }
            renderFilterTags();
            refreshMapListings(true);
        }

        function renderFilterTags() {
            const $tagsContainer = $('#bme-filter-tags-container');
            $tagsContainer.empty();
            for (const type in keywordFilters) {
                keywordFilters[type].forEach(value => {
                    const tagHTML = `<div class="bme-filter-tag" data-type="${type}" data-value="${value}">
                                        ${value} <span class="bme-filter-tag-remove">&times;</span>
                                     </div>`;
                    const $tag = $(tagHTML);
                    $tag.find('.bme-filter-tag-remove').on('click', () => removeKeywordFilter(type, value));
                    $tagsContainer.append($tag);
                });
            }
        }

        // --- Modal Filter Functions ---
        function getModalDefaults() {
            return {
                price_min: '',
                price_max: '',
                beds_min: 0,
                baths_min: 0,
                home_type: [],
                status: ['Active']
            };
        }

        function applyModalFilters() {
            modalFilters.price_min = $('#bme-filter-price-min').val();
            modalFilters.price_max = $('#bme-filter-price-max').val();
            modalFilters.beds_min = $('#bme-filter-beds .active').data('value');
            modalFilters.baths_min = $('#bme-filter-baths .active').data('value');
            modalFilters.home_type = $('#bme-filter-home-type input:checked').map((_, el) => el.value).get();
            modalFilters.status = $('#bme-filter-status input:checked').map((_, el) => el.value).get();

            $('#bme-filters-modal-overlay').hide();
            refreshMapListings(true);
        }

        function clearAllFilters() {
            keywordFilters = {};
            modalFilters = getModalDefaults();
            
            restoreStateFromUrl();
            
            refreshMapListings(true);
        }

        // --- Core Logic & Helpers ---
        function getCombinedFilters() {
            const combined = {};

            for (const type in keywordFilters) {
                if (keywordFilters[type].size > 0) {
                    combined[type] = Array.from(keywordFilters[type]);
                }
            }

            if (modalFilters.price_min) combined.price_min = modalFilters.price_min;
            if (modalFilters.price_max) combined.price_max = modalFilters.price_max;
            if (modalFilters.beds_min > 0) combined.beds_min = modalFilters.beds_min;
            if (modalFilters.baths_min > 0) combined.baths_min = modalFilters.baths_min;
            if (modalFilters.home_type.length > 0) combined.home_type = modalFilters.home_type;
            if (modalFilters.status.length > 0) combined.status = modalFilters.status;

            return combined;
        }

        function fitMapToBounds(listings) {
            if (bmeMapData.provider === 'google') {
                const bounds = new google.maps.LatLngBounds();
                listings.forEach(l => bounds.extend(new google.maps.LatLng(parseFloat(l.Latitude), parseFloat(l.Longitude))));
                if (!bounds.isEmpty()) map.fitBounds(bounds);
            } else {
                const bounds = new mapboxgl.LngLatBounds();
                listings.forEach(l => bounds.extend([parseFloat(l.Longitude), parseFloat(l.Latitude)]));
                if (!bounds.isEmpty()) map.fitBounds(bounds, { padding: 100 });
            }
        }

        // --- Marker & Popup Functions ---
        function createPriceMarker(listing, lng, lat) {
            const priceTag = document.createElement('div');
            priceTag.className = 'bme-price-marker';
            priceTag.textContent = formatPrice(listing.ListPrice);
            priceTag.onclick = () => handleMarkerClick(listing);
            createMarkerElement(priceTag, lng, lat, listing.ListingId);
        }

        function createCountMarker(group, lng, lat) {
            const countTag = document.createElement('div');
            countTag.className = 'bme-price-marker'; // Use same class for styling
            countTag.textContent = `${group.length} Units`;
            countTag.onclick = () => {
                group.forEach(listing => {
                    if (!openPopupIds.has(listing.ListingId)) {
                        showListingPopup(listing);
                    }
                });
            };
            createMarkerElement(countTag, lng, lat, `cluster-${lat}-${lng}`);
        }

        function createMarkerElement(element, lng, lat, listingId = null) {
            let marker;
            if (bmeMapData.provider === 'google') {
                marker = new google.maps.marker.AdvancedMarkerElement({ position: { lat, lng }, map, content: element, zIndex: 1 });
            } else {
                marker = new mapboxgl.Marker({ element }).setLngLat([lng, lat]).addTo(map);
            }
            markers.push({ marker, listingId, element });
        }

        function getMapBounds() {
            if (!map || !map.getBounds()) return null;
            const bounds = map.getBounds();
            if (bmeMapData.provider === 'google') {
                const ne = bounds.getNorthEast();
                const sw = bounds.getSouthWest();
                return { north: ne.lat(), south: sw.lat(), east: ne.lng(), west: sw.lng() };
            }
            const mapboxBounds = map.getBounds();
            return { north: mapboxBounds.getNorth(), south: mapboxBounds.getSouth(), east: mapboxBounds.getEast(), west: mapboxBounds.getWest() };
        }

        function clearMarkers() {
            markers.forEach(({ marker }) => {
                if (bmeMapData.provider === 'google' && marker.map) marker.map = null;
                else if (marker.remove) marker.remove();
            });
            markers = [];
        }

        function updateSidebarList(listings) {
            const listContainer = $('#bme-listings-list-container .bme-listings-grid');
            if (listContainer.length === 0) return;
            listContainer.empty();
            if (!listings || listings.length === 0) {
                listContainer.html('<p class="bme-list-placeholder">No listings found. Try a different search or move the map.</p>');
                return;
            }
            listings.forEach(listing => {
                const cardHTML = createCardHTML(listing);
                const card = $(cardHTML);
                card.on('click', () => handleMarkerClick(listing));
                listContainer.append(card);
            });
        }
        
        function highlightMarker(listingId, state) {
            const markerData = markers.find(m => m.listingId === listingId);
            if (!markerData || (markerData.listingId && markerData.listingId.startsWith('dot-'))) return;
            
            const { element, marker } = markerData;
            element.classList.remove('highlighted-active', 'highlighted-hover');
            if(bmeMapData.provider === 'google') marker.zIndex = 1;

            
            if (state === 'active') {
                element.classList.add('highlighted-active');
                if(bmeMapData.provider === 'google') marker.zIndex = 3;
            } else if (state === 'hover') {
                if (!element.classList.contains('highlighted-active')) {
                    element.classList.add('highlighted-hover');
                    if(bmeMapData.provider === 'google') marker.zIndex = 2;
                }
            }
        }

        function reapplyActiveHighlights() {
            openPopupIds.forEach(id => highlightMarker(id, 'active'));
        }

        $('body').on('mouseenter', '.bme-listing-card', function() {
            highlightMarker($(this).data('listing-id'), 'hover');
        }).on('mouseleave', '.bme-listing-card', function() {
            highlightMarker($(this).data('listing-id'), 'none');
            reapplyActiveHighlights();
        });

        function handleMarkerClick(listing) {
            if (openPopupIds.has(listing.ListingId)) {
                closeListingPopup(listing.ListingId);
            } else {
                panTo(listing);
                showListingPopup(listing);
            }
        }

        function panTo(listing) {
            const position = { lat: parseFloat(listing.Latitude), lng: parseFloat(listing.Longitude) };
            if (bmeMapData.provider === 'google') {
                 map.panTo(position);
            } else {
                 map.panTo([position.lng, position.lat]);
            }
        }

        function showListingPopup(listing) {
            if (openPopupIds.has(listing.ListingId)) return;
            
            openPopupIds.add(listing.ListingId);
            highlightMarker(listing.ListingId, 'active');

            const cardHTML = createCardHTML(listing);
            const $popupWrapper = $(`<div class="bme-popup-card-wrapper" data-listing-id="${listing.ListingId}"></div>`).html(cardHTML);
            const $closeButton = $('<button class="bme-popup-close" aria-label="Close Property Card">&times;</button>').on('click', (e) => {
                e.stopPropagation();
                closeListingPopup(listing.ListingId); 
            });
            $popupWrapper.append($closeButton);

            const stagger = (openPopupIds.size - 1) * 15;
            $popupWrapper.css({
                bottom: `${20 + stagger}px`,
                left: `calc(50% - ${stagger}px)`,
                transform: 'translateX(-50%)'
            });

            $('#bme-popup-container').append($popupWrapper).show();
            makeDraggable($popupWrapper);
            updateCloseAllButton();
        }

        function closeListingPopup(listingId) {
            $(`.bme-popup-card-wrapper[data-listing-id="${listingId}"]`).remove();
            openPopupIds.delete(listingId);
            highlightMarker(listingId, 'none');

            if (openPopupIds.size === 0) $('#bme-popup-container').hide();
            updateCloseAllButton();
        }

        function makeDraggable($element) {
            let pos1 = 0, pos2 = 0, pos3 = 0, pos4 = 0;
            const dragHandle = $element.find('.bme-listing-card');

            dragHandle.on('mousedown', (e) => {
                e.preventDefault();
                pos3 = e.clientX;
                pos4 = e.clientY;
                $('.bme-popup-card-wrapper').css('z-index', 1001);
                $element.css('z-index', 1002);
                dragHandle.addClass('is-dragging');
                $(document).on('mouseup', closeDragElement).on('mousemove', elementDrag);
            });

            const elementDrag = (e) => {
                pos1 = pos3 - e.clientX;
                pos2 = pos4 - e.clientY;
                pos3 = e.clientX;
                pos4 = e.clientY;
                if ($element.css('bottom') !== 'auto') {
                    $element.css({ top: $element.offset().top + 'px', left: $element.offset().left + 'px', bottom: 'auto', transform: 'none' });
                }
                $element.css({ top: ($element.get(0).offsetTop - pos2) + "px", left: ($element.get(0).offsetLeft - pos1) + "px" });
            };

            const closeDragElement = () => {
                dragHandle.removeClass('is-dragging');
                $(document).off('mouseup', closeDragElement).off('mousemove', elementDrag);
            };
        }
        
        function updateCloseAllButton() {
            let closeAllBtn = $('#bme-close-all-btn');
            if (openPopupIds.size > 1) {
                if (closeAllBtn.length === 0) {
                    $('<button id="bme-close-all-btn">Close All Listings</button>')
                        .on('click', () => new Set(openPopupIds).forEach(id => closeListingPopup(id)))
                        .appendTo('body');
                }
            } else {
                closeAllBtn.remove();
            }
        }

        function createCardHTML(listing) {
            const photoUrl = (JSON.parse(listing.Media || '[]')[0] || {}).MediaURL || 'https://placehold.co/400x300/eee/ccc?text=No+Image';
            const address = `${listing.StreetNumber || ''} ${listing.StreetName || ''} ${listing.UnitNumber || ''}`.trim();
            return `<div class="bme-listing-card" data-listing-id="${listing.ListingId}">
                        <div class="bme-card-image"><img src="${photoUrl}" alt="${address}" loading="lazy" onerror="this.onerror=null;this.src='https://placehold.co/400x300/eee/ccc?text=No+Image';"></div>
                        <div class="bme-card-details">
                            <div class="bme-card-price">$${parseInt(listing.ListPrice).toLocaleString()}</div>
                            <div class="bme-card-specs">
                                <span><strong>${listing.BedroomsTotal || 0}</strong> bds</span><span class="bme-spec-divider">|</span>
                                <span><strong>${listing.BathroomsTotalInteger || 0}</strong> ba</span><span class="bme-spec-divider">|</span>
                                <span><strong>${parseInt(listing.LivingArea || 0).toLocaleString()}</strong> sqft</span>
                            </div>
                            <p class="bme-card-address">${address}</p>
                            <p class="bme-card-city">${listing.City}, ${listing.StateOrProvince} ${listing.PostalCode}</p>
                        </div>
                    </div>`;
        }

        function formatPrice(price) {
            price = parseFloat(price);
            if (isNaN(price)) return '';
            if (price < 10000) return `$${parseInt(price).toLocaleString()}`;
            if (price < 1000000) return `$${Math.round(price / 1000)}k`;
            const millions = price / 1000000;
            return `$${millions.toFixed(millions < 10 ? 2 : 1)}m`;
        }

    });
})(jQuery);
