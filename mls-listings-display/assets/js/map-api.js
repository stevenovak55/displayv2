/**
 * MLD Map API Module
 * v4.3.0
 * - FIX: Corrected context filter passing to restore dynamic price range functionality.
 * - FEAT: Handles new data structure for filter options with counts.
 */
const MLD_API = {
    
    fetchAllListingsInBatches: function(page = 1, filters = MLD_Filters.getCombinedFilters()) {
        const app = MLD_Map_App;
        if (app.isFetchingCache && page === 1) return;
        if (page === 1) {
            console.log("Starting background cache refresh...");
            app.isFetchingCache = true;
            app.allListingsCache = { data: [], timestamp: null, total: 0 };
        }

        const backgroundFilters = { ...filters };
        delete backgroundFilters.north;
        delete backgroundFilters.south;
        delete backgroundFilters.east;
        delete backgroundFilters.west;

        jQuery.post(bmeMapData.ajax_url, {
            action: 'get_all_listings_for_cache',
            security: bmeMapData.security,
            filters: JSON.stringify(backgroundFilters),
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

    fetchDynamicFilterOptions: function() {
        const allBooleanFilters = Object.keys(MLD_Filters.getModalDefaults()).filter(k => typeof MLD_Filters.getModalDefaults()[k] === 'boolean');
        const contextFilters = MLD_Filters.getCombinedFilters(MLD_Filters.getModalState(true), ['home_type', 'status', 'structure_type', 'architectural_style', ...allBooleanFilters]);
        
        jQuery.post(bmeMapData.ajax_url, {
            action: 'get_filter_options',
            security: bmeMapData.security,
            filters: JSON.stringify(contextFilters)
        })
        .done(function(response) {
            if (response.success && response.data) {
                MLD_Filters.populateHomeTypes(response.data.PropertySubType || []);
                MLD_Filters.populateStatusTypes(response.data.StandardStatus || []);
                MLD_Filters.populateDynamicCheckboxes('#bme-filter-structure-type', response.data.StructureType || []);
                MLD_Filters.populateDynamicCheckboxes('#bme-filter-architectural-style', response.data.ArchitecturalStyle || []);
                MLD_Filters.populateDynamicAmenityCheckboxes(response.data.amenities || {});
            }
        })
        .fail(function() {
            console.error("Failed to fetch dynamic filter options.");
        });
        
        // Fetch price distribution with its own context
        this.fetchPriceDistribution();
    },

    fetchPriceDistribution: function() {
        // Exclude only price fields to get a dynamic range based on all other active filters.
        const contextFilters = MLD_Filters.getCombinedFilters(MLD_Filters.getModalState(true), ['price_min', 'price_max']);
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

    refreshMapListings: function(forceRefresh = false) {
        const app = MLD_Map_App;
        if (app.isUnitFocusMode) return;
    
        const currentZoom = app.map.getZoom();
        const currentCenter = MLD_Core.getNormalizedCenter(app.map);
        const isInitial = app.isInitialLoad;
    
        if (!forceRefresh) {
            const centerChanged = Math.abs(currentCenter.lat - app.lastMapState.lat) > 0.00001 || Math.abs(currentCenter.lng - app.lastMapState.lng) > 0.00001;
            const zoomChanged = currentZoom !== app.lastMapState.zoom;
            const zoomCrossedThreshold = (app.lastMapState.zoom < 17 && currentZoom >= 17) || (app.lastMapState.zoom >= 17 && currentZoom < 17);

            if (!centerChanged && !zoomChanged && !zoomCrossedThreshold) {
                return;
            }
        }
    
        app.lastMapState = { lat: currentCenter.lat, lng: currentCenter.lng, zoom: currentZoom };
    
        const isCacheValid = app.allListingsCache.data.length > 0 && (new Date().getTime() - app.allListingsCache.timestamp) < app.CACHE_EXPIRATION;
    
        if (isCacheValid && !forceRefresh && !isInitial) {
            const bounds = MLD_Core.getMapBounds();
            if (!bounds) return;
    
            const listingsInView = app.allListingsCache.data.filter(l => {
                const lat = parseFloat(l.Latitude);
                const lng = parseFloat(l.Longitude);
                return lat >= bounds.south && lat <= bounds.north && lng >= bounds.west && lng <= bounds.east;
            });
            
            MLD_Markers.updateMarkersOnMap(listingsInView);
            MLD_Core.updateSidebarList(listingsInView.slice(0, 100));
            MLD_Core.updateListingCountIndicator(listingsInView.length, app.allListingsCache.total);
            return;
        }
    
        const combinedFilters = MLD_Filters.getCombinedFilters();
        const hasFilters = Object.keys(combinedFilters).length > 0;
        
        let requestData = { 
            action: 'get_map_listings', 
            security: bmeMapData.security, 
            is_new_filter: forceRefresh && hasFilters,
            is_initial_load: isInitial
        };
        
        if (!requestData.is_new_filter && !isInitial) {
            const bounds = MLD_Core.getMapBounds();
            if (bounds) {
                requestData = { ...requestData, ...bounds };
            }
        }

        if (hasFilters) requestData.filters = JSON.stringify(combinedFilters);
    
        jQuery.post(bmeMapData.ajax_url, requestData)
        .done(function(response) {
            if (response.success && response.data) {
                const listings = response.data.listings || [];
                const total = response.data.total || 0;

                MLD_Markers.renderNewMarkers(listings);
                MLD_Core.updateSidebarList(listings);
                MLD_Core.updateListingCountIndicator(listings.length, total);

                if (forceRefresh && listings.length > 0) {
                    MLD_Core.fitMapToBounds(listings);
                }
                
                if (forceRefresh || !isCacheValid) {
                    MLD_API.fetchAllListingsInBatches();
                }
            } else {
                console.error("Failed to get map listings:", response.data);
                MLD_Core.updateListingCountIndicator(0, 0);
            }
        })
        .fail(function() {
            console.error("AJAX request to get map listings failed.");
            MLD_Core.updateListingCountIndicator(0, 0);
        });
    },

    fetchAutocompleteSuggestions: function(term, suggestionsId) {
        const app = MLD_Map_App;
        if (app.autocompleteRequest) app.autocompleteRequest.abort(); 
        app.autocompleteRequest = jQuery.post(bmeMapData.ajax_url, { action: 'get_autocomplete_suggestions', security: bmeMapData.security, term: term })
        .done(function(response) { 
            if (response.success && response.data) {
                MLD_Filters.renderAutocompleteSuggestions(response.data, suggestionsId); 
            }
        })
        .fail(function(xhr, status, error) {
            if (status !== 'abort') {
                console.error("Autocomplete suggestion request failed:", error);
            }
        });
    }
};