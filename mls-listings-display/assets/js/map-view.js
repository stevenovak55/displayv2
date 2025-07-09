/**
 * MLS Listings Display Map View v3.3.0
 * - FIX: Corrects a fatal TypeError by properly accessing the google.maps.ControlPosition enum.
 * - Implements a full keyword search, filtering, and "fit-to-bounds" system.
 * - Updates map options to remove fullscreen and reposition map type controls.
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
        let hoverPanState = null;
        let activeFilters = {}; 
        let autocompleteRequest;
        let debounceTimer;

        initMap();
        initSearchAndFilters();

        /**
         * --- UPDATED: Initializes the map with the corrected Google Maps API calls ---
         */
        async function initMap() {
            if (bmeMapData.provider === 'google') {
                // --- CRITICAL FIX: Only destructure 'Map'. Access other enums from the global google.maps object. ---
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
                        // --- CRITICAL FIX: Use the full object path ---
                        position: google.maps.ControlPosition.BOTTOM_LEFT, 
                    },
                    streetViewControl: false,
                    zoomControlOptions: {
                        // --- CRITICAL FIX: Use the full object path ---
                        position: google.maps.ControlPosition.LEFT_BOTTOM 
                    }
                });
                map.addListener('idle', refreshMapListings);
                map.addListener('dragstart', () => hoverPanState = null);
                map.addListener('zoom_changed', () => hoverPanState = null);
            } else {
                mapboxgl.accessToken = bmeMapData.mapbox_key;
                map = new mapboxgl.Map({
                    container: 'bme-map-container',
                    style: 'mapbox://styles/mapbox/streets-v11',
                    center: [-71.0589, 42.3601],
                    zoom: 10
                });
                map.addControl(new mapboxgl.NavigationControl(), 'bottom-left');
                
                map.on('idle', refreshMapListings);
                map.on('dragstart', () => hoverPanState = null);
                map.on('zoomstart', () => hoverPanState = null);
            }
        }

        function initSearchAndFilters() {
            const $searchInput = $('#bme-search-input');
            const $suggestionsContainer = $('#bme-autocomplete-suggestions');
            const $filtersButton = $('#bme-filters-button');
            const $filtersModal = $('#bme-filters-modal-overlay');
            const $closeModalButton = $('#bme-filters-modal-close');

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

            $(document).on('click', function(e) {
                if (!$(e.target).closest('#bme-search-bar-wrapper').length) {
                    $suggestionsContainer.hide();
                }
            });

            $filtersButton.on('click', function() {
                $filtersModal.css('display', 'flex');
            });

            $closeModalButton.on('click', function() {
                $filtersModal.hide();
            });
            
            $filtersModal.on('click', function(e) {
                if ($(e.target).is($filtersModal)) {
                    $filtersModal.hide();
                }
            });
        }

        function fetchAutocompleteSuggestions(term) {
            if (autocompleteRequest) {
                autocompleteRequest.abort();
            }
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

            let html = '';
            suggestions.forEach(suggestion => {
                html += `<div class="bme-suggestion-item" data-type="${suggestion.type}" data-value="${suggestion.value}">
                            <span>${suggestion.value}</span>
                            <span class="bme-suggestion-type">${suggestion.type}</span>
                         </div>`;
            });

            $suggestionsContainer.html(html).show();

            $('.bme-suggestion-item').on('click', function() {
                const type = $(this).data('type');
                const value = $(this).data('value');
                addFilter(type, value);
                $suggestionsContainer.hide().empty();
                $('#bme-search-input').val('').focus();
            });
        }

        function addFilter(type, value) {
            if (!activeFilters[type]) {
                activeFilters[type] = new Set();
            }
            activeFilters[type].add(value);
            renderFilterTags();
            refreshMapListings();
        }

        function removeFilter(type, value) {
            if (activeFilters[type]) {
                activeFilters[type].delete(value);
                if (activeFilters[type].size === 0) {
                    delete activeFilters[type];
                }
            }
            renderFilterTags();
            refreshMapListings();
        }

        function renderFilterTags() {
            const $tagsContainer = $('#bme-filter-tags-container');
            $tagsContainer.empty();
            for (const type in activeFilters) {
                activeFilters[type].forEach(value => {
                    const tagHTML = `<div class="bme-filter-tag" data-type="${type}" data-value="${value}">
                                        ${value}
                                        <span class="bme-filter-tag-remove">&times;</span>
                                     </div>`;
                    const $tag = $(tagHTML);
                    $tag.find('.bme-filter-tag-remove').on('click', () => removeFilter(type, value));
                    $tagsContainer.append($tag);
                });
            }
        }

        const refreshMapListings = () => {
            const hasFilters = Object.keys(activeFilters).length > 0;
            let requestData = {
                action: 'get_map_listings',
                security: bmeMapData.security
            };

            if (hasFilters) {
                const filtersForJson = {};
                for (const type in activeFilters) {
                    filtersForJson[type] = Array.from(activeFilters[type]);
                }
                requestData.filters = JSON.stringify(filtersForJson);
            } else {
                const bounds = getMapBounds();
                if (!bounds) return;
                requestData = { ...requestData, ...bounds };
            }

            $.post(bmeMapData.ajax_url, requestData, function(response) {
                if (response.success && response.data) {
                    allListingsInView = response.data;
                    groupAndDisplayMarkers(allListingsInView);
                    updateSidebarList(allListingsInView);

                    if (hasFilters && allListingsInView.length > 0) {
                        fitMapToBounds(allListingsInView);
                    }
                }
            });
        };

        function fitMapToBounds(listings) {
            if (bmeMapData.provider === 'google') {
                const bounds = new google.maps.LatLngBounds();
                listings.forEach(l => {
                    bounds.extend(new google.maps.LatLng(parseFloat(l.Latitude), parseFloat(l.Longitude)));
                });
                map.fitBounds(bounds);
            } else {
                const bounds = new mapboxgl.LngLatBounds();
                listings.forEach(l => {
                    bounds.extend([parseFloat(l.Longitude), parseFloat(l.Latitude)]);
                });
                map.fitBounds(bounds, { padding: 100 });
            }
        }

        function groupAndDisplayMarkers(listings) {
            clearMarkers();
            const grouped = {};
            listings.forEach(listing => {
                const key = `${parseFloat(listing.Latitude).toFixed(6)},${parseFloat(listing.Longitude).toFixed(6)}`;
                if (!grouped[key]) grouped[key] = [];
                grouped[key].push(listing);
            });

            for (const key in grouped) {
                const group = grouped[key];
                const [lat, lng] = key.split(',');
                if (group.length > 1) {
                    createCountMarker(group, parseFloat(lng), parseFloat(lat));
                } else {
                    createPriceMarker(group[0], parseFloat(lng), parseFloat(lat));
                }
            }
            reapplyActiveHighlights();
        }

        function createPriceMarker(listing, lng, lat) {
            const priceTag = document.createElement('div');
            priceTag.className = 'bme-price-marker';
            priceTag.textContent = formatPrice(listing.ListPrice);
            priceTag.onclick = () => handleMarkerClick(listing);
            createMarkerElement(priceTag, lng, lat, listing.ListingId);
        }

        function createCountMarker(group, lng, lat) {
            const countTag = document.createElement('div');
            countTag.className = 'bme-cluster-marker';
            countTag.textContent = group.length;
            countTag.onclick = () => {
                group.forEach(listing => {
                    if (!openPopupIds.has(listing.ListingId)) {
                        showListingPopup(listing);
                    }
                });
            };
            createMarkerElement(countTag, lng, lat);
        }

        function createMarkerElement(element, lng, lat, listingId = null) {
            let marker;
            if (bmeMapData.provider === 'google') {
                marker = new google.maps.marker.AdvancedMarkerElement({ position: { lat, lng }, map, content: element });
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
            return { north: bounds.getNorth(), south: bounds.getSouth(), east: bounds.getEast(), west: bounds.getWest() };
        }

        function clearMarkers() {
            markers.forEach(({ marker }) => {
                if (bmeMapData.provider === 'google' && marker.setMap) marker.setMap(null);
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
            if (!markerData) return;
            
            const { element } = markerData;
            element.classList.remove('highlighted-active', 'highlighted-hover');
            element.style.zIndex = 1;

            if (state === 'active') {
                element.classList.add('highlighted-active');
                element.style.zIndex = 20;
            } else if (state === 'hover') {
                if (!element.classList.contains('highlighted-active')) {
                    element.classList.add('highlighted-hover');
                    element.style.zIndex = 10;
                }
            }
        }

        function reapplyActiveHighlights() {
            markers.forEach(markerData => {
                if (openPopupIds.has(markerData.listingId)) {
                    highlightMarker(markerData.listingId, 'active');
                }
            });
        }

        $('body').on('mouseenter', '.bme-listing-card', function() {
            const listingId = $(this).data('listing-id');
            const listing = allListingsInView.find(l => l.ListingId === listingId);
            if (listing) {
                highlightMarker(listingId, 'hover');
                
                if (!hoverPanState) { 
                    hoverPanState = { center: map.getCenter(), zoom: map.getZoom() };
                    panTo(listing, true);
                }
            }
        }).on('mouseleave', '.bme-listing-card', function() {
            const listingId = $(this).data('listing-id');
            highlightMarker(listingId, 'none');
            
            if (hoverPanState) {
                if (bmeMapData.provider === 'google') map.moveCamera({ center: hoverPanState.center, zoom: hoverPanState.zoom });
                else map.easeTo(hoverPanState);
                hoverPanState = null;
            }
            reapplyActiveHighlights();
        });

        function handleMarkerClick(listing) {
            hoverPanState = null; 

            if (openPopupIds.has(listing.ListingId)) {
                closeListingPopup(listing.ListingId);
            } 
            else {
                panTo(listing, false);
                showListingPopup(listing);
            }
        }

        function panTo(listing, useEase = false) {
            const position = { lat: parseFloat(listing.Latitude), lng: parseFloat(listing.Longitude) };
            if (bmeMapData.provider === 'google') {
                 useEase ? map.moveCamera({ center: position }) : map.panTo(position);
            } else {
                 useEase ? map.easeTo({ center: [position.lng, position.lat] }) : map.panTo([position.lng, position.lat]);
            }
        }

        function showListingPopup(listing) {
            if (openPopupIds.has(listing.ListingId)) return;
            
            openPopupIds.add(listing.ListingId);
            highlightMarker(listing.ListingId, 'active');

            const cardHTML = createCardHTML(listing);
            
            const $popupWrapper = $(`<div class="bme-popup-card-wrapper" data-listing-id="${listing.ListingId}"></div>`);
            $popupWrapper.html(cardHTML);

            const $closeButton = $('<button class="bme-popup-close">&times;</button>');
            $closeButton.on('click', function(e) {
                e.stopPropagation();
                handleMarkerClick(listing); 
            });
            $popupWrapper.append($closeButton);

            const stagger = (openPopupIds.size - 1) * 15;
            $popupWrapper.css({
                bottom: `${20 + stagger}px`,
                left: `calc(50% - ${stagger}px)`,
                transform: 'translateX(-50%)'
            });

            const popupContainer = $('#bme-popup-container');
            popupContainer.append($popupWrapper).show();
            
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

            const dragMouseDown = (e) => {
                e = e || window.event;
                e.preventDefault();
                pos3 = e.clientX;
                pos4 = e.clientY;

                $('.bme-popup-card-wrapper').css('z-index', 1001);
                $element.css('z-index', 1002);
                dragHandle.addClass('is-dragging');

                $(document).on('mouseup', closeDragElement);
                $(document).on('mousemove', elementDrag);
            };

            const elementDrag = (e) => {
                e = e || window.event;
                e.preventDefault();
                
                pos1 = pos3 - e.clientX;
                pos2 = pos4 - e.clientY;
                pos3 = e.clientX;
                pos4 = e.clientY;
                
                const nativeElement = $element.get(0);
                
                if ($element.css('bottom') !== 'auto') {
                    const currentOffset = $element.offset();
                    $element.css({
                        top: currentOffset.top + 'px',
                        left: currentOffset.left + 'px',
                        bottom: 'auto',
                        transform: 'none'
                    });
                }
                
                $element.css({
                    top: (nativeElement.offsetTop - pos2) + "px",
                    left: (nativeElement.offsetLeft - pos1) + "px"
                });
            };

            const closeDragElement = () => {
                dragHandle.removeClass('is-dragging');
                $(document).off('mouseup', closeDragElement);
                $(document).off('mousemove', elementDrag);
            };

            dragHandle.on('mousedown', dragMouseDown);
        }
        
        function updateCloseAllButton() {
            let closeAllBtn = $('#bme-close-all-btn');
            if (openPopupIds.size > 1) {
                if (closeAllBtn.length === 0) {
                    closeAllBtn = $('<button id="bme-close-all-btn">Close All Listings</button>')
                        .on('click', () => {
                            const idsToClose = new Set(openPopupIds);
                            idsToClose.forEach(id => closeListingPopup(id));
                        })
                        .appendTo('body');
                }
            } else {
                closeAllBtn.remove();
            }
        }

        function createCardHTML(listing) {
            const photoUrl = (JSON.parse(listing.Media || '[]')[0] || {}).MediaURL || 'https://via.placeholder.com/400x300.png?text=No+Image';
            const address = `${listing.StreetNumber || ''} ${listing.StreetName || ''} ${listing.UnitNumber || ''}`.trim();
            return `<div class="bme-listing-card" data-listing-id="${listing.ListingId}">
                        <div class="bme-card-image"><img src="${photoUrl}" alt="${address}" loading="lazy" onerror="this.onerror=null;this.src='https://via.placeholder.com/400x300.png?text=No+Image';"></div>
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
            if (price < 10000) return `$${parseInt(price).toLocaleString()}`;
            if (price < 1000000) return `$${Math.round(price / 1000)}k`;
            const millions = price / 1000000;
            return `$${millions.toFixed(millions < 10 ? 2 : 1)}m`;
        }
    });
})(jQuery);
