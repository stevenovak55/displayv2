/**
 * MLD Map API Module
 * Handles all AJAX communications with the WordPress backend.
 */
const MLD_API = {
    
    /**
     * Fetches listings for the map cache in the background.
     * @param {number} page - The page number to fetch.
     * @param {object} filters - The current filter state.
     */
    fetchAllListingsInBatches: function(page = 1, filters = MLD_Filters.getCombinedFilters()) {
        const app = MLD_Map_App;
        if (app.isFetchingCache && page === 1) return;
        if (page === 1) {
            console.log("Starting background cache refresh...");
            app.isFetchingCache = true;
            app.allListingsCache = { data: [], timestamp: null, total: 0 };
        }

        // For background cache, we explicitly do NOT send map bounds,
        // so it fetches ALL listings matching the other filters.
        const backgroundFilters = { ...filters };
        delete backgroundFilters.north;
        delete backgroundFilters.south;
        delete backgroundFilters.east;
        delete backgroundFilters.west;

        jQuery.post(bmeMapData.ajax_url, {
            action: 'get_all_listings_for_cache',
            security: bmeMapData.security,
            filters: JSON.stringify(backgroundFilters), // Use backgroundFilters
            page: page,
            limit: app.BATCH_SIZE
        }).done(response => {
            if (response.success && response.data) {
                app.allListingsCache.data.push(...response.data.listings);
                app.allListingsCache.total = response.data.total;

                if (app.allListingsCache.data.length < app.allListingsCache.total) {
                    setTimeout(() => this.fetchAllListingsInBatches(page + 1, filters), 1500);
                } else {
                    app.isFetchingCache = false;
                    app.allListingsCache.timestamp = new Date().getTime();
                    console.log(`Background cache fully loaded with ${app.allListingsCache.total} listings.`);
                }
            } else {
                app.isFetchingCache = false;
                console.error("Failed to fetch a batch of listings for cache.");
            }
        }).fail(() => {
            app.isFetchingCache = false;
            console.error("AJAX error while fetching listings for cache.");
        });
    },

    /**
     * Fetches dynamic options for the filter modal (e.g., home types, statuses).
     */
    fetchDynamicFilterOptions: function() {
        const contextFilters = MLD_Filters.getCombinedFilters(MLD_Filters.getModalState(true), true);
        
        jQuery.post(bmeMapData.ajax_url, {
            action: 'get_filter_options',
            security: bmeMapData.security,
            filters: JSON.stringify(contextFilters)
        })
        .done(function(response) {
            if (response.success && response.data) {
                MLD_Filters.populateHomeTypes(response.data.PropertySubType || []);
                MLD_Filters.populateStatusTypes(response.data.StandardStatus || []);
            }
        })
        .fail(function() {
            console.error("Failed to fetch dynamic filter options.");
        });
        this.fetchPriceDistribution();
    },

    /**
     * Fetches the price distribution data for the histogram in the filter modal.
     */
    fetchPriceDistribution: function() {
        const contextFilters = MLD_Filters.getCombinedFilters(MLD_Filters.getModalState(true), true);
        jQuery.post(bmeMapData.ajax_url, {
            action: 'get_price_distribution',
            security: bmeMapData.security,
            filters: JSON.stringify(contextFilters)
        })
        .done(function(response) {
            if (response.success && response.data) {
                MLD_Map_App.priceSliderData = response.data;
                MLD_Filters.updatePriceSliderUI();
            }
        })
        .fail(function() {
            console.error("Failed to fetch price distribution data.");
        });
    },

    /**
     * Fetches the count of listings that match the current filter selection.
     */
    updateFilterCount: function() {
        const tempFilters = MLD_Filters.getModalState(true);
        const combined = MLD_Filters.getCombinedFilters(tempFilters);

        jQuery.post(bmeMapData.ajax_url, {
            action: 'get_filtered_count',
            security: bmeMapData.security,
            filters: JSON.stringify(combined)
        })
        .done(function(response) {
            if (response.success) {
                jQuery('#bme-apply-filters-btn').text(`See ${response.data} Listings`);
            }
        })
        .fail(function() {
            console.error("Failed to update filter count.");
            jQuery('#bme-apply-filters-btn').text(`See Listings`);
        });
    },

    /**
     * Fetches listings based on the current map view and filters.
     * @param {boolean} forceRefresh - If true, fetches for the entire filtered set, not just the map bounds.
     */
    refreshMapListings: function(forceRefresh = false) {
        const app = MLD_Map_App;
        if (app.isUnitFocusMode) return;
    
        const currentZoom = app.map.getZoom();
        const currentCenter = MLD_Core.getNormalizedCenter(app.map);
    
        if (!forceRefresh) {
            const centerChanged = Math.abs(currentCenter.lat - app.lastMapState.lat) > 0.00001 || Math.abs(currentCenter.lng - app.lastMapState.lng) > 0.00001;
            const zoomChanged = currentZoom !== app.lastMapState.zoom;
            if (!centerChanged && !zoomChanged) {
                return;
            }
        }
    
        app.lastMapState = { lat: currentCenter.lat, lng: currentCenter.lng, zoom: currentZoom };
    
        const isCacheValid = app.allListingsCache.data.length > 0 && (new Date().getTime() - app.allListingsCache.timestamp) < app.CACHE_EXPIRATION;
    
        if (isCacheValid && !forceRefresh) {
            const bounds = MLD_Core.getMapBounds();
            if (!bounds) return;
    
            const listingsInView = app.allListingsCache.data.filter(l => {
                const lat = parseFloat(l.Latitude);
                const lng = parseFloat(l.Longitude);
                return lat >= bounds.south && lat <= bounds.north && lng >= bounds.west && lng <= bounds.east;
            });
            
            MLD_Markers.updateMarkersOnMap(listingsInView);
            MLD_Core.updateSidebarList(listingsInView.slice(0, 100));
            return;
        }
    
        const combinedFilters = MLD_Filters.getCombinedFilters();
        const hasFilters = Object.keys(combinedFilters).length > 0;
        
        // Always include bounds in the request data, even on initial load (forceRefresh = true)
        // This ensures the initial query respects the viewport.
        const bounds = MLD_Core.getMapBounds();
        let requestData = { 
            action: 'get_map_listings', 
            security: bmeMapData.security, 
            is_new_filter: forceRefresh && hasFilters // Keep this flag for PHP to adjust limit
        };

        if (bounds) {
            requestData = { ...requestData, ...bounds };
        }
        
        if (hasFilters) {
            requestData.filters = JSON.stringify(combinedFilters);
        }
    
        jQuery.post(bmeMapData.ajax_url, requestData)
        .done(function(response) {
            if (response.success && response.data) {
                MLD_Markers.renderNewMarkers(response.data || []);
                MLD_Core.updateSidebarList(response.data || []);
                // Only fit map to bounds if it's a new filter AND there are listings to fit
                if (forceRefresh && (response.data || []).length > 0) {
                    MLD_Core.fitMapToBounds(response.data);
                }
                // Always fetch all listings for cache after initial load, regardless of bounds
                // The fetchAllListingsInBatches function will handle filtering by other criteria.
                MLD_API.fetchAllListingsInBatches(1, combinedFilters); 
            } else {
                console.error("Failed to get map listings:", response.data);
            }
        })
        .fail(function() {
            console.error("AJAX request to get map listings failed.");
        });
    },

    /**
     * Fetches autocomplete suggestions for the search bar.
     * @param {string} term - The search term.
     */
    fetchAutocompleteSuggestions: function(term) {
        const app = MLD_Map_App;
        if (app.autocompleteRequest) app.autocompleteRequest.abort(); 
        app.autocompleteRequest = jQuery.post(bmeMapData.ajax_url, { action: 'get_autocomplete_suggestions', security: bmeMapData.security, term: term })
        .done(function(response) { 
            if (response.success && response.data) {
                MLD_Filters.renderAutocompleteSuggestions(response.data); 
            }
        })
        .fail(function(xhr, status, error) {
            if (status !== 'abort') {
                console.error("Autocomplete suggestion request failed:", error);
            }
        });
    }
};
