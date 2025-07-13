/**
 * MLS Listings Display Map View v12.0.0
 * - FINAL FIX: The entire script is now encapsulated in an MLD_Map_App object.
 * - This object uses a robust polling mechanism to check for the existence of
 * not just `google.maps`, but also the required `marker` and `drawing` libraries
 * before executing the main application logic.
 * - An `ajaxComplete` listener is included to re-trigger this check after any
 * AJAX page navigation, ensuring the map works on all pages at all times.
 */
(function($) {

    const MLD_Map_App = {
        
        isInitialized: false,

        /**
         * Main entry point. Called on document ready and after AJAX calls.
         */
        init: function() {
            const mapContainer = document.getElementById('bme-map-container');
            
            // Exit if there's no map container on the page.
            if (!mapContainer) {
                return;
            }

            // Exit if this specific map has already been initialized.
            if (this.isInitialized || mapContainer.classList.contains('mld-map-initialized')) {
                return;
            }

            // Check if the necessary data from WordPress is available.
            if (typeof bmeMapData === 'undefined') {
                console.error("MLD Error: Map data (bmeMapData) is not available.");
                return;
            }
            
            // Start the initialization process based on the map provider.
            if (bmeMapData.provider === 'google') {
                this.waitForGoogleMaps();
            } else {
                // Mapbox can be run directly.
                this.run();
            }
        },

        /**
         * Polls every 100ms to check if the Google Maps API and its libraries are loaded.
         */
        waitForGoogleMaps: function() {
            const self = this;
            const interval = setInterval(function() {
                // Check for the main API and the specific libraries we need.
                if (typeof google !== 'undefined' && 
                    typeof google.maps !== 'undefined' &&
                    typeof google.maps.marker !== 'undefined' &&
                    typeof google.maps.drawing !== 'undefined'
                ) {
                    clearInterval(interval);
                    self.run();
                }
            }, 100);
        },

        /**
         * This function contains the entire map application logic.
         * It is only called once the necessary APIs are ready.
         */
        run: function() {
            const mapContainer = document.getElementById('bme-map-container');
            mapContainer.classList.add('mld-map-initialized');
            this.isInitialized = true;

            // --- UTILITY FUNCTIONS ---
            function debounce(func, delay) {
                let timeout;
                return function(...args) {
                    const context = this;
                    clearTimeout(timeout);
                    timeout = setTimeout(() => func.apply(context, args), delay);
                };
            }

            function slugify(text) {
                if (typeof text !== 'string') return '';
                return text.toLowerCase().replace(/[^a-z0-9_\-]/g, '');
            }
            
            function formatCurrency(value) {
                const num = Number(value);
                if (isNaN(num)) return '';
                return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(num);
            }

            function getNormalizedCenter(mapInstance) {
                const center = mapInstance.getCenter();
                if (typeof center.lat === 'function') { // Google Maps
                    return { lat: center.lat(), lng: center.lng() };
                }
                return { lat: center.lat, lng: center.lng }; // Mapbox
            }

            // --- SETUP & INITIALIZATION ---
            document.body.classList.add('mld-map-active');

            let map, markers = [], openPopupIds = new Set();
            let autocompleteRequest, debounceTimer, countUpdateTimer;
            let isInitialLoad = true;
            
            let lastMapState = { lat: 0, lng: 0, zoom: 0 };
            let AdvancedMarkerElement;

            // --- CACHING & STATE MANAGEMENT ---
            let allListingsCache = { data: [], timestamp: null, total: 0 };
            const CACHE_EXPIRATION = 15 * 60 * 1000;
            let isFetchingCache = false;
            const BATCH_SIZE = 200;

            let selectedPropertyType = 'Residential';
            let keywordFilters = {}; 
            let modalFilters = getModalDefaults();
            let isUnitFocusMode = false;
            let focusedListings = [];
            let openPopoutWindows = {};
            const subtypeCustomizations = bmeMapData.subtype_customizations || {};
            let priceSliderData = { min: 0, display_max: 0, distribution: [], outlier_count: 0 };

            // --- DYNAMIC DATA & ICONS ---
            const icons = {
                'Single Family': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>',
                'Condominium': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22v-5"></path><path d="M20 17v-5"></path><path d="M4 17v-5"></path><path d="M12 12V2l-7 5v5l7-5z"></path><path d="M20 12V2l-7 5v5l7-5z"></path><path d="M4 12V2l7 5v5l-7-5z"></path></svg>',
                'Townhouse': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 22V8.2c0-.4.2-.8.5-1L10 3l6.5 4.2c.3.2.5.6.5 1V22"/><path d="M14 14v-3.1c0-.4.2-.8.5-1L20 6l-6.5-4.2c-.3-.2-.5-.6-.5-1V-3"/><path d="M10 22v-2.5a.5.5 0 0 1 .5-.5h3a.5.5 0 0 1 .5.5V22"/><path d="M10 14H4v8h6Z"/></svg>',
                'Apartment': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M7 3v18"/><path d="M17 3v18"/><path d="M3 7h18"/><path d="M3 12h18"/><path d="M3 17h18"/></svg>',
                'Stock Cooperative': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M20 8v6m-3-3h6"/></svg>',
                'Multi-Family': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><path d="M9 15h6"></path><path d="M12 12v6"></path></svg>',
                'Mobile Home': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17v-2.1c0-.6.4-1.2 1-1.4l7-3.5c.6-.3 1.4-.3 2 0l7 3.5c.6.2 1 .8 1 1.4V17"/><path d="M22 17H2"/><path d="M2 17v2a1 1 0 0 0 1 1h18a1 1 0 0 0 1-1v-2"/><circle cx="8" cy="20" r="1"/><circle cx="16" cy="20" r="1"/></svg>',
                'Farm': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 5H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2Z"/><path d="M10 5V2"/><path d="M14 5V2"/><path d="M10 19v-5"/><path d="M14 19v-5"/></svg>',
                'Parking': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V6h6.5a4.5 4.5 0 0 1 0 9H9Z"/></svg>',
                'Commercial': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12h-8a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8Z"/><path d="M7 21h10"/><path d="M12 3v9"/><path d="M19 12v9H5v-9Z"/></svg>',
                'Default': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/><path d="M12 8v4l2 2"/></svg>'
            };

            function getIconForType(type) {
                const lowerType = type.toLowerCase();
                if (lowerType.includes('condo')) return icons['Condominium'];
                if (lowerType.includes('single family')) return icons['Single Family'];
                if (lowerType.includes('apartment')) return icons['Apartment'];
                if (lowerType.includes('townhouse') || lowerType.includes('attached') || lowerType.includes('duplex') || lowerType.includes('condex')) return icons['Townhouse'];
                if (lowerType.includes('family') || lowerType.includes('units')) return icons['Multi-Family'];
                if (lowerType.includes('cooperative')) return icons['Stock Cooperative'];
                if (lowerType.includes('mobile')) return icons['Mobile Home'];
                if (lowerType.includes('farm') || lowerType.includes('equestrian') || lowerType.includes('agriculture')) return icons['Farm'];
                if (lowerType.includes('parking')) return icons['Parking'];
                if (lowerType.includes('commercial')) return icons['Commercial'];
                return icons['Default'];
            }

            async function init() {
                await initMap();
                initSearchAndFilters();
                initEventDelegation();
                initPriceSlider();
            }

            async function initMap() {
                if (bmeMapData.provider === 'google') {
                    try {
                        const { Map } = await google.maps.importLibrary("maps");
                        const markerLibrary = await google.maps.importLibrary("marker");
                        await google.maps.importLibrary("drawing");

                        AdvancedMarkerElement = markerLibrary.AdvancedMarkerElement;

                        map = new Map(mapContainer, { 
                            center: { lat: 42.3601, lng: -71.0589 }, 
                            zoom: 11, 
                            mapId: 'BME_MAP_ID', 
                            gestureHandling: 'greedy', 
                            fullscreenControl: false, 
                            mapTypeControl: false, 
                            streetViewControl: false, 
                            zoomControlOptions: { position: google.maps.ControlPosition.LEFT_BOTTOM } 
                        });

                        map.addListener('idle', handleMapIdle);
                        map.addListener('dragstart', exitUnitFocusView);
                        map.addListener('click', exitUnitFocusView);

                    } catch (error) {
                        console.error("Error loading Google Maps libraries:", error);
                        mapContainer.innerHTML = '<p>Error: Could not load the map. Please check the API key and console for details.</p>';
                        return;
                    }
                } else {
                    mapboxgl.accessToken = bmeMapData.mapbox_key;
                    map = new mapboxgl.Map({ container: 'bme-map-container', style: 'mapbox://styles/mapbox/streets-v11', center: [-71.0589, 42.3601], zoom: 10 });
                    map.addControl(new mapboxgl.NavigationControl(), 'bottom-left');
                    map.on('idle', handleMapIdle);
                    map.on('dragstart', exitUnitFocusView);
                    map.on('click', exitUnitFocusView);
                }
                
                const savedType = localStorage.getItem('bmePropertyType');
                if (savedType) {
                    selectedPropertyType = savedType;
                }
                restoreStateFromUrl();
                $('#bme-property-type-select').val(selectedPropertyType);

                updateModalVisibility();
                fetchDynamicFilterOptions();

                window.addEventListener('resize', debounce(handleResize, 250));
            }
            
            function handleResize() {
                if (!map) return;
                if (bmeMapData.provider === 'google') {
                    const center = map.getCenter();
                    google.maps.event.trigger(map, 'resize');
                    map.setCenter(center);
                } else {
                    map.resize();
                }
                refreshMapListings(false);
            }

            function handleMapIdle() {
                if (isUnitFocusMode) return;
                refreshMapListings(isInitialLoad);
                if (isInitialLoad) {
                    isInitialLoad = false;
                }
            }
            
            function updateModalVisibility() {
                const rentalTypes = ['Residential Lease', 'Commercial Lease'];
                const saleTypes = ['Residential', 'Residential Income', 'Commercial Sale', 'Business Opportunity', 'Land'];

                if (rentalTypes.includes(selectedPropertyType)) {
                    $('#bme-rental-filters').show();
                    $('#bme-status-filter-group').hide();
                } else if (saleTypes.includes(selectedPropertyType)) {
                    $('#bme-rental-filters').hide();
                    $('#bme-status-filter-group').show();
                } else {
                    $('#bme-rental-filters').hide();
                    $('#bme-status-filter-group').hide();
                }
            }

            function initSearchAndFilters() {
                $('#bme-property-type-select').on('change', function() {
                    selectedPropertyType = $(this).val();
                    localStorage.setItem('bmePropertyType', selectedPropertyType);
                    modalFilters = getModalDefaults();
                    restoreModalUIToState();
                    updateModalVisibility();
                    fetchDynamicFilterOptions();
                    updateUrlHash();
                    refreshMapListings(true);
                });

                $('#bme-search-input').on('keyup', e => { clearTimeout(debounceTimer); debounceTimer = setTimeout(() => { const term = $(e.target).val(); if (term.length >= 2) fetchAutocompleteSuggestions(term); else $('#bme-autocomplete-suggestions').hide().empty(); }, 250); });
                $(document).on('click', e => { if (!$(e.target).closest('#bme-search-bar-wrapper').length) $('#bme-autocomplete-suggestions').hide(); });

                const $filtersModal = $('#bme-filters-modal-overlay');
                $('#bme-filters-button').on('click', () => { $filtersModal.css('display', 'flex'); updateFilterCount(); fetchDynamicFilterOptions(); });
                $('#bme-filters-modal-close').on('click', () => $filtersModal.hide());
                $filtersModal.on('click', e => {
                    if ($(e.target).is($filtersModal) && !$filtersModal.hasClass('is-dragging')) {
                        $filtersModal.hide();
                    }
                });

                $('#bme-apply-filters-btn').on('click', applyModalFilters);
                $('#bme-clear-filters-btn').on('click', clearAllFilters);
                
                $('body').on('click', '.bme-home-type-btn', function() { $(this).toggleClass('active'); });
                
                $('#bme-filter-beds').on('click', 'button', handleBedsSelection);
                $('#bme-filter-baths').on('click', 'button', handleBathsSelection);

                $('#bme-filters-modal-body').on('change keyup', 'input, select', () => { clearTimeout(countUpdateTimer); countUpdateTimer = setTimeout(updateFilterCount, 400); });
                $('#bme-filters-modal-body').on('click', 'button, input[type="checkbox"]', () => { clearTimeout(countUpdateTimer); countUpdateTimer = setTimeout(updateFilterCount, 100); });
            }

            function initEventDelegation() {
                $('body').on('click', '.bme-card-image a, .bme-view-details-btn', function(e) {
                    e.stopPropagation();
                });

                $('body').on('click', '.bme-popout-btn', function(e) {
                    e.stopPropagation();
                    const listingData = $(this).closest('.bme-popup-card-wrapper').data('listingData');
                    if (listingData) {
                        openPropertyInNewWindow(listingData);
                        closeListingPopup(listingData.ListingId);
                    }
                });

                window.addEventListener('beforeunload', () => {
                    for (const id in openPopoutWindows) {
                        if (openPopoutWindows[id] && !openPopoutWindows[id].closed) {
                            openPopoutWindows[id].close();
                        }
                    }
                });
            }
            
            function fetchAllListingsInBatches(page = 1, filters = getCombinedFilters()) {
                if (isFetchingCache && page === 1) return;
                if (page === 1) {
                    console.log("Starting background cache refresh...");
                    isFetchingCache = true;
                    allListingsCache = { data: [], timestamp: null, total: 0 };
                }
        
                $.post(bmeMapData.ajax_url, {
                    action: 'get_all_listings_for_cache',
                    security: bmeMapData.security,
                    filters: JSON.stringify(filters),
                    page: page,
                    limit: BATCH_SIZE
                }).done(response => {
                    if (response.success && response.data) {
                        allListingsCache.data.push(...response.data.listings);
                        allListingsCache.total = response.data.total;
        
                        if (allListingsCache.data.length < allListingsCache.total) {
                            setTimeout(() => fetchAllListingsInBatches(page + 1, filters), 1500);
                        } else {
                            isFetchingCache = false;
                            allListingsCache.timestamp = new Date().getTime();
                            console.log(`Background cache fully loaded with ${allListingsCache.total} listings.`);
                        }
                    } else {
                        isFetchingCache = false;
                        console.error("Failed to fetch a batch of listings for cache.");
                    }
                }).fail(() => {
                    isFetchingCache = false;
                    console.error("AJAX error while fetching listings for cache.");
                });
            }

            function fetchDynamicFilterOptions() {
                const contextFilters = getCombinedFilters(getModalState(true), true);
                
                $.post(bmeMapData.ajax_url, {
                    action: 'get_filter_options',
                    security: bmeMapData.security,
                    filters: JSON.stringify(contextFilters)
                })
                .done(function(response) {
                    if (response.success && response.data) {
                        populateHomeTypes(response.data.PropertySubType || []);
                        populateStatusTypes(response.data.StandardStatus || []);
                    }
                })
                .fail(function() {
                    console.error("Failed to fetch dynamic filter options.");
                });
                fetchPriceDistribution();
            }
            
            function fetchPriceDistribution() {
                const contextFilters = getCombinedFilters(getModalState(true), true);
                $.post(bmeMapData.ajax_url, {
                    action: 'get_price_distribution',
                    security: bmeMapData.security,
                    filters: JSON.stringify(contextFilters)
                })
                .done(function(response) {
                    if (response.success && response.data) {
                        priceSliderData = response.data;
                        updatePriceSliderUI();
                    }
                })
                .fail(function() {
                    console.error("Failed to fetch price distribution data.");
                });
            }

            function populateHomeTypes(subtypes) {
                const container = $('#bme-filter-home-type');
                container.empty();
                if (!subtypes || subtypes.length === 0) {
                    container.html(`<p class="bme-placeholder">No specific home types available for this selection.</p>`);
                    return;
                }

                let html = subtypes.map(type => {
                    const subtypeSlug = slugify(type);
                    const custom = subtypeCustomizations[subtypeSlug] || {};
                    
                    const label = custom.label || type;
                    const iconHTML = custom.icon 
                        ? `<img src="${custom.icon}" alt="${label}" class="bme-custom-icon">`
                        : getIconForType(type);

                    return `<button class="bme-home-type-btn" data-value="${type}">${iconHTML}<span>${label}</span></button>`;
                }).join('');

                container.html(html);
                restoreModalUIToState();
            }

            function populateStatusTypes(statuses) {
                const container = $('#bme-filter-status');
                container.empty();
                if (!statuses || statuses.length === 0) {
                    container.html(`<p class="bme-placeholder">No statuses available for the current selection.</p>`);
                    return;
                }

                let html = statuses.map(status => `
                    <label><input type="checkbox" value="${status}"> ${status}</label>
                `).join('');

                container.html(html);
                restoreModalUIToState();
            }

            function handleBedsSelection(e) {
                const $button = $(e.currentTarget);
                const $group = $button.closest('.bme-button-group');
                const isAnyButton = $button.data('value') == 0;

                if (isAnyButton) {
                    $group.find('button').removeClass('active');
                    $button.addClass('active');
                } else {
                    $group.find('button[data-value="0"]').removeClass('active');
                    $button.toggleClass('active');
                    if ($group.find('.active').length === 0) {
                        $group.find('button[data-value="0"]').addClass('active');
                    }
                }
            }

            function handleBathsSelection(e) {
                const $button = $(e.currentTarget);
                const $group = $button.closest('.bme-button-group');
                $group.find('button').removeClass('active');
                $button.addClass('active');
            }

            function updateFilterCount() {
                const tempFilters = getModalState(true);
                const combined = getCombinedFilters(tempFilters);

                $.post(bmeMapData.ajax_url, {
                    action: 'get_filtered_count',
                    security: bmeMapData.security,
                    filters: JSON.stringify(combined)
                })
                .done(function(response) {
                    if (response.success) {
                        $('#bme-apply-filters-btn').text(`See ${response.data} Listings`);
                    }
                })
                .fail(function() {
                    console.error("Failed to update filter count.");
                    $('#bme-apply-filters-btn').text(`See Listings`);
                });
            }

            const refreshMapListings = (forceRefresh = false) => {
                if (isUnitFocusMode) return;
            
                const currentZoom = map.getZoom();
                const currentCenter = getNormalizedCenter(map);
            
                if (!forceRefresh) {
                    const centerChanged = Math.abs(currentCenter.lat - lastMapState.lat) > 0.00001 || Math.abs(currentCenter.lng - lastMapState.lng) > 0.00001;
                    const zoomChanged = currentZoom !== lastMapState.zoom;
                    if (!centerChanged && !zoomChanged) {
                        return;
                    }
                }
            
                lastMapState = { lat: currentCenter.lat, lng: currentCenter.lng, zoom: currentZoom };
            
                const isCacheValid = allListingsCache.data.length > 0 && (new Date().getTime() - allListingsCache.timestamp) < CACHE_EXPIRATION;
            
                if (isCacheValid && !forceRefresh) {
                    const bounds = getMapBounds();
                    if (!bounds) return;
            
                    const listingsInView = allListingsCache.data.filter(l => {
                        const lat = parseFloat(l.Latitude);
                        const lng = parseFloat(l.Longitude);
                        return lat >= bounds.south && lat <= bounds.north && lng >= bounds.west && lng <= bounds.east;
                    });
                    
                    updateMarkersOnMap(listingsInView);
                    updateSidebarList(listingsInView.slice(0, 100));
                    return;
                }
            
                const combinedFilters = getCombinedFilters();
                const hasFilters = Object.keys(combinedFilters).length > 0;
                let requestData = { action: 'get_map_listings', security: bmeMapData.security, is_new_filter: forceRefresh && hasFilters };
                
                if (!requestData.is_new_filter) {
                    const bounds = getMapBounds();
                    if (!bounds) return;
                    requestData = { ...requestData, ...bounds };
                }
                if (hasFilters) requestData.filters = JSON.stringify(combinedFilters);
            
                $.post(bmeMapData.ajax_url, requestData)
                .done(function(response) {
                    if (response.success && response.data) {
                        renderNewMarkers(response.data || []);
                        updateSidebarList(response.data || []);
                        if (forceRefresh && hasFilters && (response.data || []).length > 0) {
                            fitMapToBounds(response.data);
                        }
                        if (forceRefresh || !isCacheValid) {
                            fetchAllListingsInBatches();
                        }
                    } else {
                        console.error("Failed to get map listings:", response.data);
                    }
                })
                .fail(function() {
                    console.error("AJAX request to get map listings failed.");
                });
            };

            function getModalDefaults() {
                return {
                    price_min: '', price_max: '', beds: [], baths_min: 0,
                    home_type: [], status: ['Active'], sqft_min: '', sqft_max: '',
                    year_built_min: '', year_built_max: '',
                    keywords: '', stories: '', available_by: '',
                    waterfront_only: false, open_house_only: false, pool_only: false, garage_only: false, fireplace_only: false
                };
            }
            
            function getModalState(isForCountOrOptions = false) {
                const state = {};
                state.price_min = $('#bme-filter-price-min').data('raw-value') || '';
                state.price_max = $('#bme-filter-price-max').data('raw-value') || '';
                
                state.beds = $('#bme-filter-beds button.active:not([data-value="0"])').map((_, el) => $(el).data('value')).get();
                state.baths_min = $('#bme-filter-baths button.active').data('value') || 0;

                state.home_type = $('#bme-filter-home-type .active').map((_, el) => $(el).data('value')).get();
                state.status = $('#bme-filter-status input:checked').map((_, el) => el.value).get();
                state.sqft_min = $('#bme-filter-sqft-min').val();
                state.sqft_max = $('#bme-filter-sqft-max').val();
                state.year_built_min = $('#bme-filter-year-built-min').val();
                state.year_built_max = $('#bme-filter-year-built-max').val();
                
                state.keywords = $('#bme-filter-keywords').val();
                state.stories = $('#bme-filter-stories').val();
                state.available_by = $('#bme-filter-available-by').val();
                state.waterfront_only = $('#bme-filter-amenities input[value="WaterfrontYN"]').is(':checked');
                state.open_house_only = $('#bme-filter-amenities input[value="open_house_only"]').is(':checked');
                state.pool_only = $('#bme-filter-amenities input[value="pool_only"]').is(':checked');
                state.garage_only = $('#bme-filter-amenities input[value="GarageYN"]').is(':checked');
                state.fireplace_only = $('#bme-filter-amenities input[value="FireplaceYN"]').is(':checked');

                if (isForCountOrOptions) return state;
                modalFilters = state;
            }

            function applyModalFilters() { 
                getModalState(); 
                $('#bme-filters-modal-overlay').hide(); 
                updateUrlHash();
                refreshMapListings(true); 
            }
            function clearAllFilters() { 
                keywordFilters = {}; 
                modalFilters = getModalDefaults(); 
                renderFilterTags(); 
                restoreModalUIToState(); 
                $('#bme-filters-modal-overlay').hide(); 
                updateUrlHash();
                refreshMapListings(true); 
            }
            
            function restoreModalUIToState() {
                updatePriceSliderUI();
                
                $('#bme-filter-beds button').removeClass('active');
                if (modalFilters.beds.length > 0) {
                    modalFilters.beds.forEach(bed => $(`#bme-filter-beds button[data-value="${bed}"]`).addClass('active'));
                } else {
                    $('#bme-filter-beds button[data-value="0"]').addClass('active');
                }

                $('#bme-filter-baths button').removeClass('active');
                const bathVal = modalFilters.baths_min || 0;
                $(`#bme-filter-baths button[data-value="${bathVal}"]`).addClass('active');

                $('#bme-filter-home-type .bme-home-type-btn').removeClass('active');
                modalFilters.home_type.forEach(ht => $(`.bme-home-type-btn[data-value="${ht}"]`).addClass('active'));
                
                $('#bme-filter-status input').prop('checked', false);
                modalFilters.status.forEach(s => $(`#bme-filter-status input[value="${s}"]`).prop('checked', true));
                
                $('#bme-filter-sqft-min').val(modalFilters.sqft_min); 
                $('#bme-filter-sqft-max').val(modalFilters.sqft_max); 
                $('#bme-filter-year-built-min').val(modalFilters.year_built_min); 
                $('#bme-filter-year-built-max').val(modalFilters.year_built_max);
                
                $('#bme-filter-keywords').val(modalFilters.keywords);
                $('#bme-filter-stories').val(modalFilters.stories);
                $('#bme-filter-available-by').val(modalFilters.available_by);
                $('#bme-filter-amenities input[value="WaterfrontYN"]').prop('checked', modalFilters.waterfront_only);
                $('#bme-filter-amenities input[value="open_house_only"]').prop('checked', modalFilters.open_house_only);
                $('#bme-filter-amenities input[value="pool_only"]').prop('checked', modalFilters.pool_only);
                $('#bme-filter-amenities input[value="GarageYN"]').prop('checked', modalFilters.garage_only);
                $('#bme-filter-amenities input[value="FireplaceYN"]').prop('checked', modalFilters.fireplace_only);
            }

            function restoreStateFromUrl() { 
                const hash = window.location.hash.substring(1); 
                if (!hash) return; 
                const params = new URLSearchParams(hash); 
                const newKeywordFilters = {}; 
                const newModalFilters = getModalDefaults(); 
                for (const [key, value] of params.entries()) { 
                    const values = value.split(','); 
                    if (key === 'PropertyType') { 
                        selectedPropertyType = value; 
                    } else if (['City', 'Building Name', 'MLS Area Major', 'MLS Area Minor', 'Postal Code', 'Street Name', 'MLS Number', 'Address'].includes(key)) { 
                        newKeywordFilters[key] = new Set(values); 
                    } else { 
                        if (['home_type', 'status', 'beds'].includes(key)) newModalFilters[key] = values; 
                        else if (['waterfront_only', 'open_house_only', 'pool_only', 'garage_only', 'fireplace_only'].includes(key)) newModalFilters[key] = value === 'true'; 
                        else if (getModalDefaults().hasOwnProperty(key)) newModalFilters[key] = value; 
                    } 
                } 
                keywordFilters = newKeywordFilters; 
                modalFilters = newModalFilters; 
                renderFilterTags(); 
                restoreModalUIToState(); 
            }

            function getCombinedFilters(currentModalState = modalFilters, excludeHomeTypeAndStatus = false) {
                const combined = {};
                for (const type in keywordFilters) {
                    if (keywordFilters[type].size > 0) combined[type] = Array.from(keywordFilters[type]);
                }
            
                const tempCombined = { ...combined, ...currentModalState };
                const finalFilters = {};
            
                for (const key in tempCombined) {
                    if (excludeHomeTypeAndStatus && (key === 'home_type' || key === 'status' || key === 'price_min' || key === 'price_max')) continue;
            
                    const value = tempCombined[key];
                    const defaultValue = getModalDefaults()[key];
            
                    if (key === 'status') {
                        if (Array.isArray(value) && value.length > 0) {
                            finalFilters[key] = value;
                        }
                        continue; 
                    }
            
                    if (JSON.stringify(value) !== JSON.stringify(defaultValue)) {
                        if ((Array.isArray(value) && value.length > 0) || (!Array.isArray(value) && value && value != 0)) {
                            finalFilters[key] = value;
                        }
                    }
                }
            
                finalFilters.PropertyType = selectedPropertyType;
            
                const rentalTypes = ['Residential Lease', 'Commercial Lease'];
                if (rentalTypes.includes(selectedPropertyType)) {
                    delete finalFilters.status;
                } else {
                    delete finalFilters.available_by;
                    if (!finalFilters.status || finalFilters.status.length === 0) {
                        finalFilters.status = ['Active'];
                    }
                }
            
                return finalFilters;
            }

            function updateUrlHash() { 
                const params = new URLSearchParams(); 
                const combined = getCombinedFilters(); 
                for (const key in combined) { 
                    const value = combined[key]; 
                    if (Array.isArray(value) || value instanceof Set) { 
                        if (Array.from(value).length > 0) params.set(key, Array.from(value).join(',')); 
                    } else if (value) { 
                        params.set(key, value.toString()); 
                    } 
                } 
                const newHash = '#' + params.toString(); 
                if (window.location.hash !== newHash) {
                    history.replaceState(null, '', newHash); 
                }
            }

            function renderNewMarkers(listings) {
                clearMarkers();
                const markerData = getMarkerDataForListings(listings);
                markerData.forEach(data => {
                     if (data.type === 'price') {
                        createPriceMarker(data.listing, data.lng, data.lat);
                    } else if (data.type === 'dot') {
                        createDotMarker(data.listing, data.lng, data.lat, data.group);
                    } else if (data.type === 'cluster') {
                        createUnitClusterMarker(data.group, data.lng, data.lat);
                    }
                });
                reapplyActiveHighlights();
            }

            function updateMarkersOnMap(listingsInView) {
                const requiredMarkerData = getMarkerDataForListings(listingsInView);
                const requiredMarkerIds = new Set(requiredMarkerData.map(m => m.id));
                const currentMarkerIdsOnMap = new Set(markers.map(m => m.id));
        
                const markersToRemove = markers.filter(m => !requiredMarkerIds.has(m.id));
                markersToRemove.forEach(({ marker }) => {
                    if (bmeMapData.provider === 'google' && marker.map) marker.map = null;
                    else if (marker.remove) marker.remove();
                });
        
                const markersToAdd = requiredMarkerData.filter(m => !currentMarkerIdsOnMap.has(m.id));
                markersToAdd.forEach(data => {
                    if (data.type === 'price') {
                        createPriceMarker(data.listing, data.lng, data.lat);
                    } else if (data.type === 'dot') {
                        createDotMarker(data.listing, data.lng, data.lat, data.group);
                    } else if (data.type === 'cluster') {
                        createUnitClusterMarker(data.group, data.lng, data.lat);
                    }
                });
        
                markers = markers.filter(m => requiredMarkerIds.has(m.id));
                
                reapplyActiveHighlights();
            }

            function getMarkerDataForListings(listings) {
                const MAX_PINS = 75;
                const CLUSTER_ZOOM_THRESHOLD = 16;
                const currentZoom = map.getZoom();
                const markerData = [];
            
                if (!listings || listings.length === 0) {
                    return markerData;
                }
            
                const listingsByLocation = {};
                listings.forEach(listing => {
                    const key = `${parseFloat(listing.Latitude).toFixed(6)},${parseFloat(listing.Longitude).toFixed(6)}`;
                    if (!listingsByLocation[key]) {
                        listingsByLocation[key] = [];
                    }
                    listingsByLocation[key].push(listing);
                });
            
                const totalLocations = Object.keys(listingsByLocation).length;
                const showAllAsPins = totalLocations <= MAX_PINS;
            
                const multiUnitLocations = [];
                const singleUnitLocations = [];
            
                for (const key in listingsByLocation) {
                    const group = listingsByLocation[key];
                    const [lat, lng] = key.split(',').map(parseFloat);
                    const locationData = { key, group, lat, lng };
                    if (group.length > 1) {
                        multiUnitLocations.push(locationData);
                    } else {
                        singleUnitLocations.push(locationData);
                    }
                }
            
                if (showAllAsPins) {
                    multiUnitLocations.forEach(({ group, lat, lng }) => {
                        const clusterBaseId = `cluster-${lat}-${lng}`;
                        if (currentZoom >= CLUSTER_ZOOM_THRESHOLD) {
                            markerData.push({ type: 'cluster', id: clusterBaseId, group, lng, lat });
                        } else {
                            markerData.push({ type: 'dot', id: `dot-${clusterBaseId}`, listing: group[0], group, lng, lat });
                        }
                    });
                    singleUnitLocations.forEach(({ group, lat, lng }) => {
                        const listing = group[0];
                        markerData.push({ type: 'price', id: `price-${listing.ListingId}`, listing, lng, lat });
                    });
                } else {
                    let pinBudget = MAX_PINS;
            
                    multiUnitLocations.forEach(({ group, lat, lng }) => {
                        const clusterBaseId = `cluster-${lat}-${lng}`;
                        if (pinBudget > 0) {
                            if (currentZoom >= CLUSTER_ZOOM_THRESHOLD) {
                                 markerData.push({ type: 'cluster', id: clusterBaseId, group, lng, lat });
                            } else {
                                markerData.push({ type: 'dot', id: `dot-${clusterBaseId}`, listing: group[0], group, lng, lat });
                            }
                            pinBudget--;
                        } else {
                            markerData.push({ type: 'dot', id: `dot-${clusterBaseId}`, listing: group[0], group, lng, lat });
                        }
                    });
            
                    singleUnitLocations.forEach(({ group, lat, lng }) => {
                        const listing = group[0];
                        if (pinBudget > 0) {
                            markerData.push({ type: 'price', id: `price-${listing.ListingId}`, listing, lng, lat });
                            pinBudget--;
                        } else {
                            markerData.push({ type: 'dot', id: `dot-${listing.ListingId}`, listing, lng, lat });
                        }
                    });
                }
            
                return markerData;
            }

            function enterUnitFocusView(group, focusedMarkerId) {
                if (isUnitFocusMode) return;
                isUnitFocusMode = true;
                focusedListings = group;
            
                const focusedMarker = markers.find(m => m.id === focusedMarkerId);
                
                clearMarkers(); 
            
                if (focusedMarker) {
                    if (bmeMapData.provider === 'google') {
                        focusedMarker.marker.map = map;
                    } else {
                        focusedMarker.marker.addTo(map);
                    }
                    markers.push(focusedMarker);
                }
            
                updateSidebarList(group);
            
                const address = `${group[0].StreetNumber||''} ${group[0].StreetName||''}`.trim();
                const overlay = `<div id="bme-focus-overlay">Showing units at: <strong>${address}</strong><span id="bme-focus-exit">(Click map to exit)</span></div>`;
                $('.bme-map-ui-wrapper').append(overlay);
            }

            function exitUnitFocusView() {
                if (!isUnitFocusMode) return;
                isUnitFocusMode = false;
                focusedListings = [];
                $('#bme-focus-overlay').remove();
                
                const bounds = getMapBounds();
                if (!bounds) return;
                const listingsInView = allListingsCache.data.filter(l => {
                    const lat = parseFloat(l.Latitude);
                    const lng = parseFloat(l.Longitude);
                    return lat >= bounds.south && lat <= bounds.north && lng >= bounds.west && lng <= bounds.east;
                });
                
                renderNewMarkers(listingsInView);
                updateSidebarList(listingsInView.slice(0, 100));
            }

            function createDotMarker(listing, lng, lat, group = null) {
                const container = document.createElement('div');
                container.className = 'bme-marker-container';
            
                const dot = document.createElement('div');
                dot.className = 'bme-dot-marker';
            
                const pricePin = document.createElement('div');
                pricePin.className = 'bme-price-marker bme-marker-hover-reveal';
                pricePin.textContent = formatPrice(listing.ListPrice);
                
                container.appendChild(dot);
                container.appendChild(pricePin);
            
                const markerId = group ? `dot-cluster-${lat}-${lng}` : `dot-${listing.ListingId}`;
                const data = group || listing;

                if (group) {
                    container.onclick = () => enterUnitFocusView(group, `cluster-${lat}-${lng}`);
                } else {
                    container.onclick = () => handleMarkerClick(listing);
                }
            
                createMarkerElement(container, lng, lat, markerId, data);
            }

            function fetchAutocompleteSuggestions(term) { 
                if (autocompleteRequest) autocompleteRequest.abort(); 
                autocompleteRequest = $.post(bmeMapData.ajax_url, { action: 'get_autocomplete_suggestions', security: bmeMapData.security, term: term })
                .done(function(response) { 
                    if (response.success && response.data) {
                        renderAutocompleteSuggestions(response.data); 
                    }
                })
                .fail(function(xhr, status, error) {
                    if (status !== 'abort') {
                        console.error("Autocomplete suggestion request failed:", error);
                    }
                });
            }
            function renderAutocompleteSuggestions(suggestions) { const $container = $('#bme-autocomplete-suggestions'); if (!suggestions || suggestions.length === 0) { $container.hide().empty(); return; } let html = suggestions.map(s => `<div class="bme-suggestion-item" data-type="${s.type}" data-value="${s.value}"><span>${s.value}</span><span class="bme-suggestion-type">${s.type}</span></div>`).join(''); $container.html(html).show(); $('.bme-suggestion-item').on('click', function() { addKeywordFilter($(this).data('type'), $(this).data('value')); }); }
            
            function addKeywordFilter(type, value) { 
                if (!keywordFilters[type]) keywordFilters[type] = new Set(); 
                keywordFilters[type].add(value); 
                renderFilterTags(); 
                updateUrlHash();
                refreshMapListings(true); 
            }
            
            function removeKeywordFilter(type, value) { 
                if (keywordFilters[type]) { 
                    keywordFilters[type].delete(value); 
                    if (keywordFilters[type].size === 0) delete keywordFilters[type]; 
                } 
                renderFilterTags(); 
                updateUrlHash();
                refreshMapListings(true); 
            }

            function renderFilterTags() { const $container = $('#bme-filter-tags-container'); $container.empty(); for (const type in keywordFilters) { keywordFilters[type].forEach(value => { const $tag = $(`<div class="bme-filter-tag" data-type="${type}" data-value="${value}">${value} <span class="bme-filter-tag-remove">&times;</span></div>`); $tag.find('.bme-filter-tag-remove').on('click', () => removeKeywordFilter(type, value)); $container.append($tag); }); } }
            function fitMapToBounds(listings) { if (bmeMapData.provider === 'google') { const bounds = new google.maps.LatLngBounds(); listings.forEach(l => bounds.extend(new google.maps.LatLng(parseFloat(l.Latitude), parseFloat(l.Longitude)))); if (!bounds.isEmpty()) map.fitBounds(bounds); } else { const bounds = new mapboxgl.LngLatBounds(); listings.forEach(l => bounds.extend([parseFloat(l.Longitude), parseFloat(l.Latitude)])); if (!bounds.isEmpty()) map.fitBounds(bounds, { padding: 100 }); } }
            
            function createPriceMarker(listing, lng, lat) { 
                const el = document.createElement('div'); 
                el.className = 'bme-price-marker'; 
                el.textContent = formatPrice(listing.ListPrice); 
                el.onclick = (e) => { e.stopPropagation(); handleMarkerClick(listing); }; 
                createMarkerElement(el, lng, lat, `price-${listing.ListingId}`, listing); 
            }
            
            function createUnitClusterMarker(group, lng, lat) {
                const el = document.createElement('div');
                const clusterId = `cluster-${lat}-${lng}`;
                el.className = 'bme-unit-cluster-marker';
                el.textContent = `${group.length} Units`;
                el.onclick = (e) => {
                    e.stopPropagation();
                    enterUnitFocusView(group, clusterId);
                };
                createMarkerElement(el, lng, lat, clusterId, group);
            }

            function createMarkerElement(element, lng, lat, id, data) {
                let marker;
                let rawListingId = null;
                if (data && !Array.isArray(data)) {
                    rawListingId = data.ListingId;
                }

                if (bmeMapData.provider === 'google' && AdvancedMarkerElement) {
                    marker = new AdvancedMarkerElement({ position: { lat, lng }, map, content: element, zIndex: 1 });
                } else if (bmeMapData.provider === 'mapbox') {
                    marker = new mapboxgl.Marker({ element }).setLngLat([lng, lat]).addTo(map);
                }
                if (marker) {
                    markers.push({ marker, id, element, data, rawListingId });
                }
            }

            function getMapBounds() { if (!map || !map.getBounds()) return null; if (bmeMapData.provider === 'google') { const b = map.getBounds(); const ne = b.getNorthEast(); const sw = b.getSouthWest(); return { north: ne.lat(), south: sw.lat(), east: ne.lng(), west: sw.lng() }; } const b = map.getBounds(); return { north: b.getNorth(), south: b.getSouth(), east: b.getEast(), west: b.getWest() }; }
            function clearMarkers() { markers.forEach(({ marker }) => { if (bmeMapData.provider === 'google' && marker.map) marker.map = null; else if (marker.remove) marker.remove(); }); markers = []; }
            function updateSidebarList(listings) { const container = $('#bme-listings-list-container .bme-listings-grid'); if (container.length === 0) return; container.empty(); if (!listings || listings.length === 0) { container.html('<p class="bme-list-placeholder">No listings found.</p>'); return; } listings.forEach(listing => { const card = $(createCardHTML(listing, 'sidebar')); card.on('mouseenter', () => highlightMarker(listing.ListingId, 'hover')).on('mouseleave', () => { highlightMarker(listing.ListingId, 'none'); reapplyActiveHighlights(); }); container.append(card); }); }
            
            function highlightMarker(listingId, state) {
                const markerData = markers.find(m => m.rawListingId === listingId);
                if (!markerData) return;
            
                const { element, marker } = markerData;
                element.classList.remove('highlighted-active', 'highlighted-hover');
                if(bmeMapData.provider === 'google') marker.zIndex = 1;
            
                if (state === 'active') {
                    element.classList.add('highlighted-active');
                    if(bmeMapData.provider === 'google') marker.zIndex = 3;
                } else if (state === 'hover' && !element.classList.contains('highlighted-active')) {
                    element.classList.add('highlighted-hover');
                    if(bmeMapData.provider === 'google') marker.zIndex = 2;
                }
            }

            function reapplyActiveHighlights() { openPopupIds.forEach(id => highlightMarker(id, 'active')); }
            function handleMarkerClick(listing) { if (openPopupIds.has(listing.ListingId)) closeListingPopup(listing.ListingId); else { panTo(listing); showListingPopup(listing); } }
            function panTo(listing) { const pos = { lat: parseFloat(listing.Latitude), lng: parseFloat(listing.Longitude) }; if (bmeMapData.provider === 'google') map.panTo(pos); else map.panTo([pos.lng, pos.lat]); }
            
            function showListingPopup(listing) {
                if (openPopupIds.has(listing.ListingId)) return;
                openPopupIds.add(listing.ListingId);
                highlightMarker(listing.ListingId, 'active');
                const $popupWrapper = $(`<div class="bme-popup-card-wrapper" data-listing-id="${listing.ListingId}"></div>`)
                    .data('listingData', listing)
                    .html(createCardHTML(listing, 'popup'));
                const $closeButton = $('<button class="bme-popup-close" aria-label="Close">&times;</button>').on('click', e => { e.stopPropagation(); closeListingPopup(listing.ListingId); });
                $popupWrapper.append($closeButton);
                const stagger = (openPopupIds.size - 1) * 15;
                $popupWrapper.css({ bottom: `${20 + stagger}px`, left: `calc(50% - ${stagger}px)`, transform: 'translateX(-50%)' });
                $('#bme-popup-container').append($popupWrapper).show();
                makeDraggable($popupWrapper);
                updateCloseAllButton();
            }

            function closeListingPopup(listingId) { $(`.bme-popup-card-wrapper[data-listing-id="${listingId}"]`).remove(); openPopupIds.delete(listingId); highlightMarker(listingId, 'none'); if (openPopupIds.size === 0) $('#bme-popup-container').hide(); updateCloseAllButton(); }
            function makeDraggable($element) { let p1=0, p2=0, p3=0, p4=0; const handle = $element.find('.bme-listing-card'); handle.on('mousedown', e => { e.preventDefault(); p3 = e.clientX; p4 = e.clientY; $('.bme-popup-card-wrapper').css('z-index', 1001); $element.css('z-index', 1002); handle.addClass('is-dragging'); $(document).on('mouseup', closeDrag).on('mousemove', drag); }); const drag = e => { p1 = p3 - e.clientX; p2 = p4 - e.clientY; p3 = e.clientX; p4 = e.clientY; if ($element.css('bottom') !== 'auto') $element.css({ top: $element.offset().top + 'px', left: $element.offset().left + 'px', bottom: 'auto', transform: 'none' }); $element.css({ top: ($element.get(0).offsetTop - p2) + "px", left: ($element.get(0).offsetLeft - p1) + "px" }); }; const closeDrag = () => { handle.removeClass('is-dragging'); $(document).off('mouseup', closeDrag).off('mousemove', drag); }; }
            function updateCloseAllButton() { let btn = $('#bme-close-all-btn'); if (openPopupIds.size > 1) { if (btn.length === 0) $('<button id="bme-close-all-btn">Close All</button>').on('click', () => new Set(openPopupIds).forEach(id => closeListingPopup(id))).appendTo('body'); } else { btn.remove(); } }
            
            function openPropertyInNewWindow(listing) {
                const listingId = listing.ListingId;
                if (openPopoutWindows[listingId] && !openPopoutWindows[listingId].closed) {
                    openPopoutWindows[listingId].focus();
                    return;
                }

                const features = 'width=450,height=480,menubar=no,toolbar=no,location=no,resizable=yes,scrollbars=yes';
                const newWindow = window.open('', listingId, features);

                if (!newWindow) {
                    alert('Please allow pop-ups for this website.');
                    return;
                }

                openPopoutWindows[listingId] = newWindow;
                
                let styles = '';
                Array.from(document.styleSheets).forEach(sheet => {
                    try {
                        if (sheet.href) {
                            styles += `<link rel="stylesheet" href="${sheet.href}">`;
                        }
                    } catch (e) {
                        console.warn('Could not access stylesheet due to CORS policy: ', sheet.href);
                    }
                });

                const popoutHTML = createCardHTML(listing, 'window');
                
                newWindow.document.write(`
                    <html>
                        <head>
                            <title>${listing.StreetNumber} ${listing.StreetName} - Property Details</title>
                            ${styles}
                            <style>
                                body { padding: 15px; background-color: #f0f2f5; }
                                .bme-listing-card { box-shadow: none; border: none; }
                            </style>
                        </head>
                        <body>
                            ${popoutHTML}
                        </body>
                    </html>
                `);
                newWindow.document.close();

                newWindow.addEventListener('beforeunload', () => {
                    highlightMarker(listingId, 'none');
                    delete openPopoutWindows[listingId];
                });
            }

            function createCardHTML(listing, context = 'sidebar') {
                const photo = (JSON.parse(listing.Media || '[]')[0] || {}).MediaURL || 'https://placehold.co/420x280/eee/ccc?text=No+Image';
                const addressLine1 = `${listing.StreetNumber||''} ${listing.StreetName||''}`.trim();
                const addressLine2 = `${listing.City}, ${listing.StateOrProvince} ${listing.PostalCode}`;
                const fullAddress = `${addressLine1}${listing.UnitNumber ? ' #' + listing.UnitNumber : ''}, ${addressLine2}`;

                const price = `$${parseInt(listing.ListPrice).toLocaleString('en-US')}`;
                const totalBaths = (parseInt(listing.BathroomsFull) || 0) + ((parseInt(listing.BathroomsHalf) || 0) * 0.5);
                
                const isPriceDrop = parseFloat(listing.OriginalListPrice) > parseFloat(listing.ListPrice);

                let tagsHTML = '';
                const openHouseData = listing.OpenHouseData ? JSON.parse(listing.OpenHouseData) : null;
                if (openHouseData && Array.isArray(openHouseData) && openHouseData.length > 0) {
                    const now = new Date();
                    const upcoming = openHouseData.map(oh => ({...oh, dateTime: new Date(oh.OpenHouseStartTime.endsWith('Z') ? oh.OpenHouseStartTime : oh.OpenHouseStartTime + 'Z')})).filter(oh => oh.dateTime >= now).sort((a,b) => a.dateTime - b.dateTime);
                    if (upcoming.length > 0) {
                        const nextOpenHouse = upcoming[0];
                        const ohStart = nextOpenHouse.dateTime;
                        const timeZone = 'America/New_York';
                        const day = ohStart.toLocaleDateString('en-US', { weekday: 'short', timeZone }).toUpperCase();
                        const startTime = ohStart.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', timeZone, hour12: true }).replace(' ', '');
                        tagsHTML += `<div class="bme-card-tag open-house">OPEN ${day}, ${startTime}</div>`;
                    }
                }
                if (isPriceDrop) {
                    tagsHTML += `<div class="bme-card-tag price-drop">Price Drop</div>`;
                }
                if (listing.StandardStatus && listing.StandardStatus !== 'Active') {
                     tagsHTML += `<div class="bme-card-tag status">${listing.StandardStatus}</div>`;
                }

                let secondaryInfoHTML = '';
                if (listing.AssociationFee && parseFloat(listing.AssociationFee) > 0) {
                    const frequency = (listing.AssociationFeeFrequency || 'Monthly').slice(0, 2).toLowerCase();
                    secondaryInfoHTML += `<span>$${parseInt(listing.AssociationFee).toLocaleString()}/${frequency} HOA</span>`;
                }
                if (listing.GarageSpaces && parseInt(listing.GarageSpaces) > 0) {
                    secondaryInfoHTML += `<span>${listing.GarageSpaces} Garage ${parseInt(listing.GarageSpaces) > 1 ? 'Spaces' : 'Space'}</span>`;
                }
                
                let cardControls = '';
                let imageHTML = `<img src="${photo}" alt="${fullAddress}" loading="lazy" onerror="this.onerror=null;this.src='https://placehold.co/420x280/eee/ccc?text=No+Image';">`;
                let detailsButtonHTML = '';

                if (context === 'sidebar') {
                    imageHTML = `<a href="/property/${listing.ListingId}" target="_blank">${imageHTML}</a>`;
                } else if (context === 'popup' || context === 'window') {
                    if (context === 'popup') {
                        const popoutIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M6 1h6v6h-1V2.707L5.354 9.354l-.708-.708L11.293 2H6V1z"/><path d="M2 3.5A1.5 1.5 0 0 1 3.5 2H5v1H3.5a.5.5 0 0 0-.5.5v10a.5.5 0 0 0 .5.5h10a.5.5 0 0 0 .5-.5V11h1v2.5a1.5 1.5 0 0 1-1.5 1.5h-10A1.5 1.5 0 0 1 2 13.5V3.5z"/></svg>';
                        cardControls = `<button class="bme-popout-btn" title="Pop out card">${popoutIcon}</button>`;
                    }
                    detailsButtonHTML = `<a href="/property/${listing.ListingId}" class="bme-view-details-btn" target="_blank">View Details</a>`;
                }

                return `
                    <div class="bme-listing-card" data-listing-id="${listing.ListingId}">
                        <div class="bme-card-image">
                            ${imageHTML}
                            <div class="bme-card-image-overlay">
                                <div class="bme-card-tags">${tagsHTML}</div>
                                 ${cardControls}
                            </div>
                        </div>
                        <div class="bme-card-details">
                            <div class="bme-card-header">
                                <div class="bme-card-price">${price}</div>
                            </div>
                            <div class="bme-card-specs">
                                <span><strong>${listing.BedroomsTotal||0}</strong> bds</span>
                                <span class="bme-spec-divider"></span>
                                <span><strong>${totalBaths}</strong> ba</span>
                                <span class="bme-spec-divider"></span>
                                <span><strong>${parseInt(listing.LivingArea||0).toLocaleString()}</strong> sqft</span>
                            </div>
                            <div class="bme-card-address">
                                <p>${addressLine1}${listing.UnitNumber ? ` #${listing.UnitNumber}` : ''}</p>
                                <p>${addressLine2}</p>
                            </div>
                            ${secondaryInfoHTML ? `<div class="bme-card-secondary-info">${secondaryInfoHTML}</div>` : ''}
                            ${detailsButtonHTML}
                        </div>
                    </div>`;
            }
            
            function formatPrice(price) {
                price = parseFloat(price);
                if (isNaN(price)) return '';
                if (price < 10000) return `$${parseInt(price).toLocaleString('en-US')}`;
                if (price < 1000000) return `$${Math.round(price / 1000)}k`;
                return `$${(price / 1000000).toFixed(price < 10000000 ? 2 : 1)}m`;
            }

            function initPriceSlider() {
                const slider = document.getElementById('bme-price-slider');
                const minHandle = document.getElementById('bme-price-slider-handle-min');
                const maxHandle = document.getElementById('bme-price-slider-handle-max');
                const minInput = document.getElementById('bme-filter-price-min');
                const maxInput = document.getElementById('bme-filter-price-max');
                let activeHandle = null;

                function startDrag(e) {
                    e.preventDefault();
                    activeHandle = e.target;
                    $('#bme-filters-modal-overlay').addClass('is-dragging');
                    document.addEventListener('mousemove', drag);
                    document.addEventListener('mouseup', stopDrag);
                    document.addEventListener('touchmove', drag, { passive: false });
                    document.addEventListener('touchend', stopDrag);
                }

                function drag(e) {
                    if (!activeHandle) return;
                    e.preventDefault();
                    const rect = slider.getBoundingClientRect();
                    const x = (e.touches ? e.touches[0].clientX : e.clientX) - rect.left;
                    let percent = Math.max(0, Math.min(100, (x / rect.width) * 100));
                    
                    const minPercent = parseFloat(minHandle.style.left) || 0;
                    const maxPercent = parseFloat(maxHandle.style.left) || 100;

                    if (activeHandle === minHandle) {
                        percent = Math.min(percent, maxPercent);
                    } else {
                        percent = Math.max(percent, minPercent);
                    }

                    activeHandle.style.left = percent + '%';
                    updatePriceFromSlider();
                }

                function stopDrag() {
                    activeHandle = null;
                    setTimeout(() => {
                        $('#bme-filters-modal-overlay').removeClass('is-dragging');
                    }, 50);
                    document.removeEventListener('mousemove', drag);
                    document.removeEventListener('mouseup', stopDrag);
                    document.removeEventListener('touchmove', drag);
                    document.removeEventListener('touchend', stopDrag);
                }

                minHandle.addEventListener('mousedown', startDrag);
                maxHandle.addEventListener('mousedown', startDrag);
                minHandle.addEventListener('touchstart', startDrag, { passive: false });
                maxHandle.addEventListener('touchstart', startDrag, { passive: false });

                function handleInputBlur(e) {
                    const input = e.target;
                    let rawValue = input.value.replace(/[^0-9]/g, '');
                    if (rawValue === '') {
                        $(input).data('raw-value', '');
                    } else {
                        rawValue = parseInt(rawValue, 10);
                        $(input).data('raw-value', rawValue);
                        input.value = formatCurrency(rawValue);
                    }
                    updateSliderFromInput();
                }
            
                $(minInput).on('blur', handleInputBlur);
                $(maxInput).on('blur', handleInputBlur);
                
                function handleInputFocus(e) {
                    const input = e.target;
                    const rawValue = $(input).data('raw-value');
                    if (rawValue !== '') {
                        input.value = rawValue;
                    }
                }
                
                $(minInput).on('focus', handleInputFocus);
                $(maxInput).on('focus', handleInputFocus);
            }

            function updatePriceFromSlider() {
                const minPercent = parseFloat(document.getElementById('bme-price-slider-handle-min').style.left) || 0;
                const maxPercent = parseFloat(document.getElementById('bme-price-slider-handle-max').style.left) || 100;
                
                const sliderRange = priceSliderData.display_max - priceSliderData.min;

                const currentMin = (sliderRange > 0) ? Math.round(priceSliderData.min + (minPercent / 100) * sliderRange) : priceSliderData.min;
                $('#bme-filter-price-min').val(formatCurrency(currentMin)).data('raw-value', currentMin);

                if (maxPercent >= 100) {
                    $('#bme-filter-price-max').val(formatCurrency(priceSliderData.display_max) + '+').data('raw-value', '');
                } else {
                    const currentMax = (sliderRange > 0) ? Math.round(priceSliderData.min + (maxPercent / 100) * sliderRange) : priceSliderData.display_max;
                    $('#bme-filter-price-max').val(formatCurrency(currentMax)).data('raw-value', currentMax);
                }
                
                updatePriceSliderRangeAndHistogram();
                
                clearTimeout(countUpdateTimer);
                countUpdateTimer = setTimeout(updateFilterCount, 400);
            }
            
            function updateSliderFromInput() {
                let minVal = parseFloat($('#bme-filter-price-min').data('raw-value'));
                let maxVal = parseFloat($('#bme-filter-price-max').data('raw-value'));

                const sliderMin = priceSliderData.min;
                const sliderMax = priceSliderData.display_max;
                const sliderRange = sliderMax - sliderMin;

                if (isNaN(minVal) && isNaN(maxVal)) {
                    document.getElementById('bme-price-slider-handle-min').style.left = '0%';
                    document.getElementById('bme-price-slider-handle-max').style.left = '100%';
                    updatePriceSliderRangeAndHistogram();
                    clearTimeout(countUpdateTimer);
                    countUpdateTimer = setTimeout(updateFilterCount, 400);
                    return;
                }

                if (isNaN(minVal)) minVal = sliderMin;
                if (isNaN(maxVal)) maxVal = sliderMax;

                let minPercent = 0;
                let maxPercent = 100;

                if (sliderRange > 0) {
                    minPercent = ((minVal - sliderMin) / sliderRange) * 100;
                    maxPercent = ((maxVal - sliderMin) / sliderRange) * 100;
                    
                    minPercent = Math.max(0, Math.min(100, minPercent));
                    maxPercent = Math.max(0, Math.min(100, maxPercent));
                }
                
                if (maxVal > sliderMax) {
                    maxPercent = 100;
                }

                document.getElementById('bme-price-slider-handle-min').style.left = minPercent + '%';
                document.getElementById('bme-price-slider-handle-max').style.left = maxPercent + '%';
                
                updatePriceSliderRangeAndHistogram();
                
                clearTimeout(countUpdateTimer);
                countUpdateTimer = setTimeout(updateFilterCount, 400);
            }

            function updatePriceSliderRangeAndHistogram() {
                const minPercent = parseFloat(document.getElementById('bme-price-slider-handle-min').style.left) || 0;
                const maxPercent = parseFloat(document.getElementById('bme-price-slider-handle-max').style.left) || 100;

                const rangeEl = document.getElementById('bme-price-slider-range');
                rangeEl.style.left = minPercent + '%';
                rangeEl.style.width = (maxPercent - minPercent) + '%';
                
                $('#bme-price-histogram .bme-histogram-bar').each(function(index) {
                    const barPercent = (index / (priceSliderData.distribution.length || 1)) * 100;
                    $(this).toggleClass('in-range', barPercent >= minPercent && barPercent < maxPercent);
                });
                const $outlierBar = $('.bme-histogram-bar-outlier');
                if ($outlierBar.length > 0) {
                    $outlierBar.toggleClass('in-range', maxPercent >= 100);
                }
            }

            function updatePriceSliderUI() {
                const { min, display_max, distribution, outlier_count } = priceSliderData;
                
                const currentMin = modalFilters.price_min !== '' ? modalFilters.price_min : min;
                const currentMax = modalFilters.price_max !== '' ? modalFilters.price_max : display_max;

                $('#bme-filter-price-min').val(formatCurrency(currentMin)).data('raw-value', currentMin);
                if (modalFilters.price_max === '' && currentMax >= display_max) {
                     $('#bme-filter-price-max').val(formatCurrency(display_max) + '+').data('raw-value', '');
                } else {
                     $('#bme-filter-price-max').val(formatCurrency(currentMax)).data('raw-value', currentMax);
                }

                const histogramContainer = $('#bme-price-histogram');
                histogramContainer.empty();

                if (!distribution || (distribution.length === 0 && outlier_count === 0) || display_max === 0) {
                    histogramContainer.html('<div class="bme-placeholder">No price data available.</div>');
                    $('#bme-price-slider').hide();
                    return;
                }
                $('#bme-price-slider').show();

                const maxCount = Math.max(...distribution, outlier_count);
                distribution.forEach(count => {
                    const height = maxCount > 0 ? (count / maxCount) * 100 : 0;
                    histogramContainer.append(`<div class="bme-histogram-bar" style="height: ${height}%"></div>`);
                });

                if (outlier_count > 0) {
                    const height = maxCount > 0 ? (outlier_count / maxCount) * 100 : 0;
                    const outlierLabel = `${outlier_count} listings above ${formatCurrency(display_max)}`;
                    const outlierBarHTML = `
                        <div class="bme-histogram-bar bme-histogram-bar-outlier" style="height: ${height}%">
                            <span class="bme-histogram-bar-label">${outlierLabel}</span>
                        </div>`;
                    histogramContainer.append(outlierBarHTML);
                }
                
                updateSliderFromInput();
            }

            // Start the application
            init();
        }
    };

    // Initial load
    $(document).ready(function() {
        MLD_Map_App.init();
    });

    // Handle AJAX navigation
    $(document).ajaxComplete(function() {
        setTimeout(function() {
            MLD_Map_App.init();
        }, 500);
    });

})(jQuery);
