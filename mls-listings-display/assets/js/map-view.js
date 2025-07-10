/**
 * MLS Listings Display Map View v8.5.0
 * - FEAT: Replaced all home type filter icons with a new, professional set matching the user's reference image for a cleaner UI.
 */
(function($) {
    $(document).ready(function() {
        // --- SETUP & INITIALIZATION ---
        const mapContainer = document.getElementById('bme-map-container');
        if (!mapContainer || typeof bmeMapData === 'undefined') return;

        document.body.classList.add('mld-map-active');

        let map, markers = [], allListingsInView = [], openPopupIds = new Set();
        let autocompleteRequest, debounceTimer, countUpdateTimer;
        let isInitialLoad = true;
        let dynamicFilterOptions = {};

        // --- STATE MANAGEMENT ---
        let listingMode = 'For Sale';
        let keywordFilters = {}; 
        let modalFilters = getModalDefaults();
        let isNewFilterAction = false; 
        let isUnitFocusMode = false;
        let focusedListings = [];

        // --- ICONS (New Set) ---
        const icons = {
            'Single Family': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>',
            'Condominium': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22v-5"></path><path d="M20 17v-5"></path><path d="M4 17v-5"></path><path d="M12 12V2l-7 5v5l7-5z"></path><path d="M20 12V2l-7 5v5l7-5z"></path><path d="M4 12V2l7 5v5l-7-5z"></path></svg>',
            'Townhouse': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 22V8.2c0-.4.2-.8.5-1L10 3l6.5 4.2c.3.2.5.6.5 1V22"/><path d="M14 14v-3.1c0-.4.2-.8.5-1L20 6l-6.5-4.2c-.3-.2-.5-.6-.5-1V-3"/><path d="M10 22v-2.5a.5.5 0 0 1 .5-.5h3a.5.5 0 0 1 .5.5V22"/><path d="M10 14H4v8h6Z"/></svg>',
            'Apartment': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M7 3v18"/><path d="M17 3v18"/><path d="M3 7h18"/><path d="M3 12h18"/><path d="M3 17h18"/></svg>',
            'Stock Cooperative': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M20 8v6m-3-3h6"/></svg>',
            'Parking': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V6h6.5a4.5 4.5 0 1 1 0 9H9z"/></svg>',
            'Default': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/><path d="M12 8v4l2 2"/></svg>'
        };

        // --- INITIALIZE ---
        initMap();
        initSearchAndFilters();
        initEventDelegation();

        async function initMap() {
            if (bmeMapData.provider === 'google') {
                const { Map } = await google.maps.importLibrary("maps");
                map = new Map(mapContainer, { center: { lat: 42.3601, lng: -71.0589 }, zoom: 11, mapId: 'BME_MAP_ID', gestureHandling: 'greedy', fullscreenControl: false, mapTypeControl: false, streetViewControl: false, zoomControlOptions: { position: google.maps.ControlPosition.LEFT_BOTTOM } });
                map.addListener('idle', handleMapIdle);
                map.addListener('dragstart', exitUnitFocusView);
                map.addListener('click', exitUnitFocusView);
            } else {
                mapboxgl.accessToken = bmeMapData.mapbox_key;
                map = new mapboxgl.Map({ container: 'bme-map-container', style: 'mapbox://styles/mapbox/streets-v11', center: [-71.0589, 42.3601], zoom: 10 });
                map.addControl(new mapboxgl.NavigationControl(), 'bottom-left');
                map.on('idle', handleMapIdle);
                map.on('dragstart', exitUnitFocusView);
                map.on('click', exitUnitFocusView);
            }
            loadInitialMode();
            toggleRentalFilters();
            restoreStateFromUrl();
            initDynamicFilters();
        }
        
        function handleMapIdle() {
            if (isUnitFocusMode) return; // Don't refresh if we are focused on a unit
            if (isInitialLoad) {
                isInitialLoad = false;
                refreshMapListings(true); 
            } else {
                refreshMapListings(false);
            }
        }
        
        function loadInitialMode() {
            const savedMode = localStorage.getItem('bmeListingMode');
            if (savedMode === 'For Rent') {
                listingMode = savedMode;
                $('#bme-listing-mode-select').val(savedMode);
            }
        }

        function toggleRentalFilters() {
            if (listingMode === 'For Rent') {
                $('#bme-rental-filters').show();
                $('#bme-status-filter-group').hide();
            } else {
                $('#bme-rental-filters').hide();
                $('#bme-status-filter-group').show();
            }
        }

        function initSearchAndFilters() {
            $('#bme-listing-mode-select').on('change', function() {
                listingMode = $(this).val();
                localStorage.setItem('bmeListingMode', listingMode);
                modalFilters = getModalDefaults();
                restoreModalUIToState();
                toggleRentalFilters();
                initDynamicFilters();
                refreshMapListings(true);
            });

            $('#bme-search-input').on('keyup', e => { clearTimeout(debounceTimer); debounceTimer = setTimeout(() => { const term = $(e.target).val(); if (term.length >= 2) fetchAutocompleteSuggestions(term); else $('#bme-autocomplete-suggestions').hide().empty(); }, 250); });
            $(document).on('click', e => { if (!$(e.target).closest('#bme-search-bar-wrapper').length) $('#bme-autocomplete-suggestions').hide(); });

            const $filtersModal = $('#bme-filters-modal-overlay');
            $('#bme-filters-button').on('click', () => { $filtersModal.css('display', 'flex'); updateFilterCount(); });
            $('#bme-filters-modal-close').on('click', () => $filtersModal.hide());
            $filtersModal.on('click', e => { if ($(e.target).is($filtersModal)) $filtersModal.hide(); });

            $('#bme-apply-filters-btn').on('click', applyModalFilters);
            $('#bme-clear-filters-btn').on('click', clearAllFilters);
            
            $('body').on('click', '.bme-home-type-btn', function() { $(this).toggleClass('active'); });
            
            $('#bme-filter-beds, #bme-filter-baths').on('click', 'button', handleRangeSelection);

            const priceInputs = $('#bme-filter-price-min, #bme-filter-price-max');
            priceInputs.on('focus', function() { $(this).val($(this).data('raw-value')); });
            priceInputs.on('blur', function() {
                const rawValue = $(this).val().replace(/,/g, '');
                if ($.isNumeric(rawValue)) {
                    $(this).data('raw-value', rawValue);
                    $(this).val(parseInt(rawValue).toLocaleString('en-US'));
                } else {
                    $(this).data('raw-value', '');
                    $(this).val('');
                }
            });

            $('#bme-filters-modal-body').on('change keyup', 'input, select', () => { clearTimeout(countUpdateTimer); countUpdateTimer = setTimeout(updateFilterCount, 400); });
            $('#bme-filters-modal-body').on('click', 'button, input[type="checkbox"]', () => { clearTimeout(countUpdateTimer); countUpdateTimer = setTimeout(updateFilterCount, 100); });
        }

        function initEventDelegation() {
            $('body').on('click', '.bme-quick-details-btn', function(e) {
                e.stopPropagation();
                const $button = $(this);
                const $card = $button.closest('.bme-listing-card');
                const listingId = $card.data('listing-id');
                const $detailsContainer = $card.find('.bme-card-quick-details');

                if ($card.hasClass('quick-view-open')) {
                    $card.removeClass('quick-view-open');
                    $detailsContainer.slideUp();
                    $button.text('Quick Details');
                } else {
                    if ($detailsContainer.data('loaded')) {
                        $card.addClass('quick-view-open');
                        $detailsContainer.slideDown();
                        $button.text('Hide Details');
                    } else {
                        fetchAndShowQuickDetails(listingId, $card, $button);
                    }
                }
            });
        }
        
        function initDynamicFilters() {
            $.post(bmeMapData.ajax_url, { 
                action: 'get_filter_options', 
                security: bmeMapData.security,
                listing_mode: listingMode 
            }, function(response) {
                if (response.success && response.data) {
                    dynamicFilterOptions = response.data;
                    populateHomeTypes(dynamicFilterOptions.PropertySubType);
                }
            });
        }

        function populateHomeTypes(subtypes) {
            const container = $('#bme-filter-home-type');
            if (!subtypes || subtypes.length === 0) {
                container.html('<p>No specific home types available for this category.</p>');
                return;
            }

            const typeMap = {
                'Apartment': { label: 'Apartment', icon: icons['Apartment'] },
                'Attached': { label: 'Townhouse', icon: icons['Townhouse'] },
                'Condominium': { label: 'Condo', icon: icons['Condominium'] },
                'Single Family': { label: 'House', icon: icons['Single Family'] },
                'Parking': { label: 'Parking', icon: icons['Parking'] },
                'Stock Cooperative': { label: 'Co-op', icon: icons['Stock Cooperative'] }
            };

            let html = subtypes.map(type => {
                let mappedType = { label: type, icon: icons['Default'] };
                for (const key in typeMap) {
                    if (type.toLowerCase().includes(key.toLowerCase())) {
                        mappedType = typeMap[key];
                        break;
                    }
                }
                return `<button class="bme-home-type-btn" data-value="${type}">${mappedType.icon}<span>${mappedType.label}</span></button>`;
            }).join('');

            container.html(html);
            restoreModalUIToState();
        }


        function handleRangeSelection(e) {
            const $button = $(e.currentTarget);
            const $group = $button.closest('.bme-button-group');
            const isAnyButton = $button.data('value') == 0;

            if (isAnyButton) {
                $group.find('button').removeClass('range-start range-end in-range active');
                $button.addClass('active');
                updateRangeVisuals($group);
                return;
            }

            $group.find('button[data-value="0"]').removeClass('active');
            const $start = $group.find('.range-start');
            const $end = $group.find('.range-end');

            if ($start.length && !$end.length && $start[0] !== $button[0]) {
                $button.addClass('range-end');
            } else {
                $group.find('button').removeClass('range-start range-end in-range');
                $button.addClass('range-start');
            }
            updateRangeVisuals($group);
        }

        function updateRangeVisuals($group) {
            const $buttons = $group.find('button:not([data-value="0"])');
            const $start = $group.find('.range-start');
            const $end = $group.find('.range-end');
            let label = '';

            $buttons.removeClass('in-range');

            if ($start.length) {
                let startIndex = $buttons.index($start);
                let endIndex = $end.length ? $buttons.index($end) : startIndex;

                if (startIndex > endIndex) {
                    [startIndex, endIndex] = [endIndex, startIndex];
                }

                for (let i = startIndex + 1; i < endIndex; i++) {
                    $buttons.eq(i).addClass('in-range');
                }
                
                const startVal = $group.find('.range-start').text();
                const endVal = $group.find('.range-end').length ? $group.find('.range-end').text() : '';
                label = endVal && startVal !== endVal ? `${startVal} - ${endVal}` : `${startVal}`;
            }
            $group.siblings('label').find('.bme-range-label').text(label);
        }

        function updateFilterCount() {
            const tempFilters = getModalState(true);
            const combined = getCombinedFilters(tempFilters);

            $.post(bmeMapData.ajax_url, {
                action: 'get_filtered_count',
                security: bmeMapData.security,
                filters: JSON.stringify(combined)
            }, function(response) {
                if (response.success) {
                    $('#bme-apply-filters-btn').text(`See ${response.data} Listings`);
                }
            });
        }

        const refreshMapListings = (isNewAction = false) => {
            if (isUnitFocusMode) exitUnitFocusView();
            isNewFilterAction = isNewAction;
            const combinedFilters = getCombinedFilters();
            const hasFilters = Object.keys(combinedFilters).length > 0;
            let requestData = { action: 'get_map_listings', security: bmeMapData.security, is_new_filter: isNewFilterAction && hasFilters };
            if (!requestData.is_new_filter) {
                const bounds = getMapBounds();
                if (!bounds) return;
                requestData = { ...requestData, ...bounds };
            }
            if (hasFilters) requestData.filters = JSON.stringify(combinedFilters);
            $.post(bmeMapData.ajax_url, requestData, function(response) {
                if (response.success) {
                    allListingsInView = response.data || [];
                    groupAndDisplayMarkers(allListingsInView);
                    updateSidebarList(allListingsInView);
                    if (isNewFilterAction && hasFilters && allListingsInView.length > 0) fitMapToBounds(allListingsInView);
                }
            }).always(updateUrlHash);
        };

        function getModalDefaults() {
            return {
                price_min: '', price_max: '', beds_min: 0, beds_max: 0, baths_min: 0, baths_max: 0,
                home_type: [], status: ['Active'], sqft_min: '', sqft_max: '',
                year_built_min: '', year_built_max: '',
                keywords: '', stories: '', available_by: '',
                waterfront_only: false, open_house_only: false, pool_only: false, garage_only: false, fireplace_only: false
            };
        }
        
        function getModalState(isForCount = false) {
            const state = {};
            state.price_min = $('#bme-filter-price-min').data('raw-value') || '';
            state.price_max = $('#bme-filter-price-max').data('raw-value') || '';
            
            const $bedStart = $('#bme-filter-beds .range-start');
            const $bedEnd = $('#bme-filter-beds .range-end');
            state.beds_min = $bedStart.length ? $bedStart.data('value') : ($('#bme-filter-beds .active').data('value') || 0);
            state.beds_max = $bedEnd.length ? $bedEnd.data('value') : 0;
            if ($bedStart.length && !$bedEnd.length) state.beds_max = 0;
            if (state.beds_min == 0) state.beds_max = 0;

            const $bathStart = $('#bme-filter-baths .range-start');
            const $bathEnd = $('#bme-filter-baths .range-end');
            state.baths_min = $bathStart.length ? $bathStart.data('value') : ($('#bme-filter-baths .active').data('value') || 0);
            state.baths_max = $bathEnd.length ? $bathEnd.data('value') : 0;
            if ($bathStart.length && !$bathEnd.length) state.baths_max = 0;
            if (state.baths_min == 0) state.baths_max = 0;

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
            state.pool_only = $('#bme-filter-amenities input[value="PoolYN"]').is(':checked');
            state.garage_only = $('#bme-filter-amenities input[value="GarageYN"]').is(':checked');
            state.fireplace_only = $('#bme-filter-amenities input[value="FireplaceYN"]').is(':checked');

            if (isForCount) return state;
            modalFilters = state;
        }

        function applyModalFilters() { getModalState(); $('#bme-filters-modal-overlay').hide(); refreshMapListings(true); }
        function clearAllFilters() { keywordFilters = {}; modalFilters = getModalDefaults(); renderFilterTags(); restoreModalUIToState(); $('#bme-filters-modal-overlay').hide(); refreshMapListings(true); }
        
        function restoreModalUIToState() {
            $('#bme-filter-price-min').data('raw-value', modalFilters.price_min).val(modalFilters.price_min ? parseInt(modalFilters.price_min).toLocaleString('en-US') : '');
            $('#bme-filter-price-max').data('raw-value', modalFilters.price_max).val(modalFilters.price_max ? parseInt(modalFilters.price_max).toLocaleString('en-US') : '');
            
            $('#bme-filter-beds button').removeClass('range-start range-end in-range active');
            if (modalFilters.beds_min > 0) { $(`button[data-value="${modalFilters.beds_min}"]`, '#bme-filter-beds').addClass('range-start'); if (modalFilters.beds_max > 0) $(`button[data-value="${modalFilters.beds_max}"]`, '#bme-filter-beds').addClass('range-end'); } else { $('button[data-value="0"]', '#bme-filter-beds').addClass('active'); }
            updateRangeVisuals($('#bme-filter-beds'));

            $('#bme-filter-baths button').removeClass('range-start range-end in-range active');
            if (modalFilters.baths_min > 0) { $(`button[data-value="${modalFilters.baths_min}"]`, '#bme-filter-baths').addClass('range-start'); if (modalFilters.baths_max > 0) $(`button[data-value="${modalFilters.baths_max}"]`, '#bme-filter-baths').addClass('range-end'); } else { $('button[data-value="0"]', '#bme-filter-baths').addClass('active'); }
            updateRangeVisuals($('#bme-filter-baths'));

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
            $('#bme-filter-amenities input[value="PoolYN"]').prop('checked', modalFilters.pool_only);
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
                if (key === 'listingMode') { 
                    listingMode = value; $('#bme-listing-mode-select').val(listingMode); 
                } else if (['City', 'Building Name', 'MLS Area Major', 'MLS Area Minor', 'Postal Code', 'Street Name', 'MLS Number', 'Address'].includes(key)) { 
                    newKeywordFilters[key] = new Set(values); 
                } else { 
                    if (['home_type', 'status'].includes(key)) newModalFilters[key] = values; 
                    else if (['waterfront_only', 'open_house_only', 'pool_only', 'garage_only', 'fireplace_only'].includes(key)) newModalFilters[key] = value === 'true'; 
                    else if (getModalDefaults().hasOwnProperty(key)) newModalFilters[key] = value; 
                } 
            } 
            keywordFilters = newKeywordFilters; 
            modalFilters = newModalFilters; 
            renderFilterTags(); 
            restoreModalUIToState(); 
            toggleRentalFilters();
        }

        function getCombinedFilters(currentModalState = modalFilters) { 
            const combined = {}; 
            for (const type in keywordFilters) { 
                if (keywordFilters[type].size > 0) combined[type] = Array.from(keywordFilters[type]); 
            } 
            const tempCombined = { ...combined, ...currentModalState }; 
            const finalFilters = {}; 
            for (const key in tempCombined) { 
                const value = tempCombined[key]; 
                const defaultValue = getModalDefaults()[key]; 
                if (JSON.stringify(value) !== JSON.stringify(defaultValue)) { 
                    if ((Array.isArray(value) && value.length > 0) || (!Array.isArray(value) && value && value != 0)) { 
                        finalFilters[key] = value; 
                    } 
                } 
            } 
            if (listingMode === 'For Rent') {
                finalFilters.PropertyType = ['Residential Lease'];
                delete finalFilters.status;
            } else {
                finalFilters.PropertyType = ['Residential'];
                delete finalFilters.available_by;
            }
            return finalFilters; 
        }

        function updateUrlHash() { 
            const params = new URLSearchParams(); 
            const combined = getCombinedFilters(); 
            if (listingMode !== 'For Sale') params.set('listingMode', listingMode); 
            for (const key in combined) { 
                if (key === 'PropertyType') continue; 
                const value = combined[key]; 
                if (Array.isArray(value) || value instanceof Set) { 
                    if (Array.from(value).length > 0) params.set(key, Array.from(value).join(',')); 
                } else if (value) { 
                    params.set(key, value.toString()); 
                } 
            } 
            const newHash = '#' + params.toString(); 
            if (window.location.hash !== newHash) history.replaceState(null, '', newHash); 
        }

        function groupAndDisplayMarkers(listings) { 
            clearMarkers(); 
            const MAX_PINS = 25; 
            const listingsByLocation = {}; 
            listings.forEach(listing => { 
                const key = `${parseFloat(listing.Latitude).toFixed(6)},${parseFloat(listing.Longitude).toFixed(6)}`; 
                if (!listingsByLocation[key]) listingsByLocation[key] = []; 
                listingsByLocation[key].push(listing); 
            }); 
            const singleListings = []; 
            for (const key in listingsByLocation) { 
                const group = listingsByLocation[key]; 
                if (group.length > 1) { 
                    const [lat, lng] = key.split(','); 
                    createUnitClusterMarker(group, parseFloat(lng), parseFloat(lat)); 
                } else { 
                    singleListings.push(group[0]); 
                } 
            } 
            let listingsForPins = singleListings.slice(0, MAX_PINS); 
            let listingsForDots = singleListings.slice(MAX_PINS); 
            listingsForDots.forEach(l => createDotMarker(l, parseFloat(l.Longitude), parseFloat(l.Latitude))); 
            listingsForPins.forEach(l => createPriceMarker(l, parseFloat(l.Longitude), parseFloat(l.Latitude))); 
            reapplyActiveHighlights(); 
        }

        function enterUnitFocusView(group, focusedMarkerId) {
            if (isUnitFocusMode) return;
            isUnitFocusMode = true;
            focusedListings = group;

            markers.forEach(({ marker, listingId }) => {
                if (listingId !== focusedMarkerId) {
                    if (bmeMapData.provider === 'google') {
                        marker.map = null;
                    } else {
                        marker.remove();
                    }
                }
            });

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
            groupAndDisplayMarkers(allListingsInView);
            updateSidebarList(allListingsInView);
        }

        function createDotMarker(listing, lng, lat) { const dot = document.createElement('div'); dot.className = 'bme-dot-marker'; let pricePin = null; $(dot).on('mouseenter', function() { if (pricePin) return; pricePin = document.createElement('div'); pricePin.className = 'bme-price-marker'; pricePin.textContent = formatPrice(listing.ListPrice); pricePin.onclick = () => handleMarkerClick(listing); const markerData = markers.find(m => m.listingId === `dot-${listing.ListingId}`); if (markerData && markerData.marker) { if (bmeMapData.provider === 'google') markerData.marker.content = pricePin; else markerData.marker.getElement().replaceWith(pricePin); } }).on('mouseleave', function() { if (!pricePin) return; const markerData = markers.find(m => m.listingId === `dot-${listing.ListingId}`); if (markerData && markerData.marker) { if (bmeMapData.provider === 'google') markerData.marker.content = dot; else markerData.marker.getElement().replaceWith(dot); } pricePin = null; }); createMarkerElement(dot, lng, lat, `dot-${listing.ListingId}`); }
        function fetchAutocompleteSuggestions(term) { if (autocompleteRequest) autocompleteRequest.abort(); autocompleteRequest = $.post(bmeMapData.ajax_url, { action: 'get_autocomplete_suggestions', security: bmeMapData.security, term: term }, function(response) { if (response.success && response.data) renderAutocompleteSuggestions(response.data); }); }
        function renderAutocompleteSuggestions(suggestions) { const $container = $('#bme-autocomplete-suggestions'); if (!suggestions || suggestions.length === 0) { $container.hide().empty(); return; } let html = suggestions.map(s => `<div class="bme-suggestion-item" data-type="${s.type}" data-value="${s.value}"><span>${s.value}</span><span class="bme-suggestion-type">${s.type}</span></div>`).join(''); $container.html(html).show(); $('.bme-suggestion-item').on('click', function() { addKeywordFilter($(this).data('type'), $(this).data('value')); $container.hide().empty(); $('#bme-search-input').val('').focus(); }); }
        function addKeywordFilter(type, value) { if (!keywordFilters[type]) keywordFilters[type] = new Set(); keywordFilters[type].add(value); renderFilterTags(); refreshMapListings(true); }
        function removeKeywordFilter(type, value) { if (keywordFilters[type]) { keywordFilters[type].delete(value); if (keywordFilters[type].size === 0) delete keywordFilters[type]; } renderFilterTags(); refreshMapListings(true); }
        function renderFilterTags() { const $container = $('#bme-filter-tags-container'); $container.empty(); for (const type in keywordFilters) { keywordFilters[type].forEach(value => { const $tag = $(`<div class="bme-filter-tag" data-type="${type}" data-value="${value}">${value} <span class="bme-filter-tag-remove">&times;</span></div>`); $tag.find('.bme-filter-tag-remove').on('click', () => removeKeywordFilter(type, value)); $container.append($tag); }); } }
        function fitMapToBounds(listings) { if (bmeMapData.provider === 'google') { const bounds = new google.maps.LatLngBounds(); listings.forEach(l => bounds.extend(new google.maps.LatLng(parseFloat(l.Latitude), parseFloat(l.Longitude)))); if (!bounds.isEmpty()) map.fitBounds(bounds); } else { const bounds = new mapboxgl.LngLatBounds(); listings.forEach(l => bounds.extend([parseFloat(l.Longitude), parseFloat(l.Latitude)])); if (!bounds.isEmpty()) map.fitBounds(bounds, { padding: 100 }); } }
        function createPriceMarker(listing, lng, lat) { const el = document.createElement('div'); el.className = 'bme-price-marker'; el.textContent = formatPrice(listing.ListPrice); el.onclick = (e) => { e.stopPropagation(); handleMarkerClick(listing); }; createMarkerElement(el, lng, lat, listing.ListingId); }
        
        function createUnitClusterMarker(group, lng, lat) {
            const el = document.createElement('div');
            const clusterId = `cluster-${lat}-${lng}`;
            el.className = 'bme-unit-cluster-marker';
            el.textContent = `${group.length} Units`;
            el.onclick = (e) => {
                e.stopPropagation();
                enterUnitFocusView(group, clusterId);
            };
            createMarkerElement(el, lng, lat, clusterId);
        }

        function createMarkerElement(element, lng, lat, listingId = null) { let marker; if (bmeMapData.provider === 'google') { marker = new google.maps.marker.AdvancedMarkerElement({ position: { lat, lng }, map, content: element, zIndex: 1 }); } else { marker = new mapboxgl.Marker({ element }).setLngLat([lng, lat]).addTo(map); } markers.push({ marker, listingId, element }); }
        function getMapBounds() { if (!map || !map.getBounds()) return null; if (bmeMapData.provider === 'google') { const b = map.getBounds(); const ne = b.getNorthEast(); const sw = b.getSouthWest(); return { north: ne.lat(), south: sw.lat(), east: ne.lng(), west: sw.lng() }; } const b = map.getBounds(); return { north: b.getNorth(), south: b.getSouth(), east: b.getEast(), west: b.getWest() }; }
        function clearMarkers() { markers.forEach(({ marker }) => { if (bmeMapData.provider === 'google' && marker.map) marker.map = null; else if (marker.remove) marker.remove(); }); markers = []; }
        function updateSidebarList(listings) { const container = $('#bme-listings-list-container .bme-listings-grid'); if (container.length === 0) return; container.empty(); if (!listings || listings.length === 0) { container.html('<p class="bme-list-placeholder">No listings found.</p>'); return; } listings.forEach(listing => { const card = $(createCardHTML(listing, 'sidebar')); card.on('click', () => handleMarkerClick(listing)); container.append(card); }); }
        function highlightMarker(listingId, state) { const markerData = markers.find(m => m.listingId === listingId); if (!markerData || (markerData.listingId && markerData.listingId.startsWith('dot-'))) return; const { element, marker } = markerData; element.classList.remove('highlighted-active', 'highlighted-hover'); if(bmeMapData.provider === 'google') marker.zIndex = 1; if (state === 'active') { element.classList.add('highlighted-active'); if(bmeMapData.provider === 'google') marker.zIndex = 3; } else if (state === 'hover' && !element.classList.contains('highlighted-active')) { element.classList.add('highlighted-hover'); if(bmeMapData.provider === 'google') marker.zIndex = 2; } }
        function reapplyActiveHighlights() { openPopupIds.forEach(id => highlightMarker(id, 'active')); }
        $('body').on('mouseenter', '.bme-listing-card', function() { highlightMarker($(this).data('listing-id'), 'hover'); }).on('mouseleave', '.bme-listing-card', function() { highlightMarker($(this).data('listing-id'), 'none'); reapplyActiveHighlights(); });
        function handleMarkerClick(listing) { if (openPopupIds.has(listing.ListingId)) closeListingPopup(listing.ListingId); else { panTo(listing); showListingPopup(listing); } }
        function panTo(listing) { const pos = { lat: parseFloat(listing.Latitude), lng: parseFloat(listing.Longitude) }; if (bmeMapData.provider === 'google') map.panTo(pos); else map.panTo([pos.lng, pos.lat]); }
        function showListingPopup(listing) { if (openPopupIds.has(listing.ListingId)) return; openPopupIds.add(listing.ListingId); highlightMarker(listing.ListingId, 'active'); const $popupWrapper = $(`<div class="bme-popup-card-wrapper" data-listing-id="${listing.ListingId}"></div>`).html(createCardHTML(listing, 'popup')); const $closeButton = $('<button class="bme-popup-close" aria-label="Close">&times;</button>').on('click', e => { e.stopPropagation(); closeListingPopup(listing.ListingId); }); $popupWrapper.append($closeButton); const stagger = (openPopupIds.size - 1) * 15; $popupWrapper.css({ bottom: `${20 + stagger}px`, left: `calc(50% - ${stagger}px)`, transform: 'translateX(-50%)' }); $('#bme-popup-container').append($popupWrapper).show(); makeDraggable($popupWrapper); updateCloseAllButton(); }
        function closeListingPopup(listingId) { $(`.bme-popup-card-wrapper[data-listing-id="${listingId}"]`).remove(); openPopupIds.delete(listingId); highlightMarker(listingId, 'none'); if (openPopupIds.size === 0) $('#bme-popup-container').hide(); updateCloseAllButton(); }
        function makeDraggable($element) { let p1=0, p2=0, p3=0, p4=0; const handle = $element.find('.bme-listing-card'); handle.on('mousedown', e => { e.preventDefault(); p3 = e.clientX; p4 = e.clientY; $('.bme-popup-card-wrapper').css('z-index', 1001); $element.css('z-index', 1002); handle.addClass('is-dragging'); $(document).on('mouseup', closeDrag).on('mousemove', drag); }); const drag = e => { p1 = p3 - e.clientX; p2 = p4 - e.clientY; p3 = e.clientX; p4 = e.clientY; if ($element.css('bottom') !== 'auto') $element.css({ top: $element.offset().top + 'px', left: $element.offset().left + 'px', bottom: 'auto', transform: 'none' }); $element.css({ top: ($element.get(0).offsetTop - p2) + "px", left: ($element.get(0).offsetLeft - p1) + "px" }); }; const closeDrag = () => { handle.removeClass('is-dragging'); $(document).off('mouseup', closeDrag).off('mousemove', drag); }; }
        function updateCloseAllButton() { let btn = $('#bme-close-all-btn'); if (openPopupIds.size > 1) { if (btn.length === 0) $('<button id="bme-close-all-btn">Close All</button>').on('click', () => new Set(openPopupIds).forEach(id => closeListingPopup(id))).appendTo('body'); } else { btn.remove(); } }
        
        function createCardHTML(listing, context = 'popup') {
            const photo = (JSON.parse(listing.Media || '[]')[0] || {}).MediaURL || 'https://placehold.co/400x300/eee/ccc?text=No+Image';
            const address = `${listing.StreetNumber||''} ${listing.StreetName||''} ${listing.UnitNumber||''}`.trim();
            const price = `$${parseInt(listing.ListPrice).toLocaleString('en-US')}`;
            const totalBaths = (parseInt(listing.BathroomsFull) || 0) + ( (parseInt(listing.BathroomsHalf) || 0) * 0.5);

            let detailsButtonHTML = '';
            if (context === 'popup') {
                detailsButtonHTML = '<button class="bme-quick-details-btn">Quick Details</button>';
            } else { // sidebar
                detailsButtonHTML = `<a href="/property/${listing.ListingId}" class="bme-view-details-link" target="_blank">View Details</a>`;
            }

            let openHouseTag = '';
            const openHouseData = listing.OpenHouseData ? JSON.parse(listing.OpenHouseData) : null;
            if (openHouseData && Array.isArray(openHouseData) && openHouseData.length > 0) {
                const now = new Date();
                const upcoming = openHouseData
                    .map(oh => {
                        if (!oh.OpenHouseStartTime) return null;
                        const startTimeString = oh.OpenHouseStartTime.endsWith('Z') ? oh.OpenHouseStartTime : oh.OpenHouseStartTime + 'Z';
                        const dateTime = new Date(startTimeString);
                        if (isNaN(dateTime.getTime())) return null;
                        return {...oh, dateTime};
                    })
                    .filter(oh => oh && oh.dateTime >= now)
                    .sort((a, b) => a.dateTime - b.dateTime);

                if (upcoming.length > 0) {
                    const nextOpenHouse = upcoming[0];
                    const ohStart = nextOpenHouse.dateTime;
                    const endTimeString = nextOpenHouse.OpenHouseEndTime.endsWith('Z') ? nextOpenHouse.OpenHouseEndTime : nextOpenHouse.OpenHouseEndTime + 'Z';
                    const ohEnd = new Date(endTimeString);

                    const timeZone = 'America/New_York';
                    const day = ohStart.toLocaleDateString('en-US', { weekday: 'short', timeZone }).toUpperCase();
                    const startTime = ohStart.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', timeZone, hour12: true }).replace(' ', '');
                    const endTime = ohEnd.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', timeZone, hour12: true }).replace(' ', '');

                    openHouseTag = `<div class="bme-card-tag bme-open-house-tag">OPEN ${day}, ${startTime} TO ${endTime}</div>`;
                }
            }

            return `<div class="bme-listing-card" data-listing-id="${listing.ListingId}">
                        <div class="bme-card-image">
                            <img src="${photo}" alt="${address}" loading="lazy" onerror="this.onerror=null;this.src='https://placehold.co/400x300/eee/ccc?text=No+Image';">
                            ${openHouseTag}
                        </div>
                        <div class="bme-card-details">
                            <div class="bme-card-price">${price}</div>
                            <div class="bme-card-specs">
                                <span><strong>${listing.BedroomsTotal||0}</strong> bds</span><span class="bme-spec-divider">|</span>
                                <span><strong>${totalBaths}</strong> ba</span><span class="bme-spec-divider">|</span>
                                <span><strong>${parseInt(listing.LivingArea||0).toLocaleString()}</strong> sqft</span>
                            </div>
                            <p class="bme-card-address">${address}</p>
                            <p class="bme-card-city">${listing.City}, ${listing.StateOrProvince} ${listing.PostalCode}</p>
                            ${detailsButtonHTML}
                        </div>
                        <div class="bme-card-quick-details"></div>
                    </div>`;
        }

        function fetchAndShowQuickDetails(listingId, $card, $button) {
            $button.text('Loading...').prop('disabled', true);
            $.post(bmeMapData.ajax_url, {
                action: 'get_listing_details',
                security: bmeMapData.security,
                listing_id: listingId
            }, function(response) {
                if (response.success) {
                    const details = response.data;
                    const $detailsContainer = $card.find('.bme-card-quick-details');
                    $detailsContainer.html(formatQuickDetails(details)).data('loaded', true);
                    $card.addClass('quick-view-open');
                    $detailsContainer.slideDown();
                    $button.text('Hide Details').prop('disabled', false);
                } else {
                    $button.text('Error').prop('disabled', false);
                }
            });
        }
        
        function formatDetailValue(value) {
            if (typeof value !== 'string') return value;
            if (value.startsWith('[') && value.endsWith(']')) {
                try {
                    const arr = JSON.parse(value);
                    if (Array.isArray(arr)) {
                        const cleanedArr = arr.filter(item => item && typeof item === 'string' && item.trim() !== '');
                        return cleanedArr.length > 0 ? cleanedArr.join(', ') : 'N/A';
                    }
                } catch (e) { /* Not valid JSON, fall through */ }
            }
            return value;
        }

        function formatQuickDetails(details) {
            let html = '<div class="bme-details-grid">';
            const fieldMap = {
                'Property Subtype': details.PropertySubType, 'Year Built': details.YearBuilt,
                'County': details.CountyOrParish, 'Structure Type': formatDetailValue(details.StructureType),
                'Parking': formatDetailValue(details.ParkingFeatures), 'Waterfront': details.WaterfrontYN == 1 ? 'Yes' : 'No',
                'Pool': details.PoolYN == 1 ? 'Yes' : 'No', 'Heating': formatDetailValue(details.Heating), 'Cooling': formatDetailValue(details.Cooling),
                'Association Fee': details.AssociationFee ? `$${parseInt(details.AssociationFee).toLocaleString()} / ${details.AssociationFeeFrequency}` : 'N/A',
                'Taxes': details.TaxAnnualAmount ? `$${parseInt(details.TaxAnnualAmount).toLocaleString()} (${details.TaxYear})` : 'N/A'
            };

            for (const label in fieldMap) {
                const value = fieldMap[label];
                if (value && value !== 'N/A' && value !== 'No') {
                    html += `<div class="bme-detail-item"><strong>${label}:</strong> <span>${value}</span></div>`;
                }
            }
            html += '</div>';
            html += `<a href="/property/${details.ListingId}" class="bme-view-details-link" target="_blank" style="margin-top: 15px;">View Full Details</a>`;
            return html;
        }

        function formatPrice(price) {
            price = parseFloat(price);
            if (isNaN(price)) return '';
            if (price < 10000) return `$${parseInt(price).toLocaleString('en-US')}`;
            if (price < 1000000) return `$${Math.round(price / 1000)}k`;
            return `$${(price / 1000000).toFixed(price < 10000000 ? 2 : 1)}m`;
        }
    });
})(jQuery);
