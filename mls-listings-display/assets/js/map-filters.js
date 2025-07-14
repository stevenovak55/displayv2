/**
 * MLD Map Filters Module
 * Manages all filter-related UI and state.
 */
const MLD_Filters = {

    /**
     * Initializes all event listeners for the filter controls.
     */
    initSearchAndFilters: function() {
        const $ = jQuery;

        // This function sets up the keyup listener for a search input
        const setupSearchListener = (inputId, suggestionsId) => {
            $(inputId).on('keyup', e => {
                clearTimeout(MLD_Map_App.debounceTimer);
                MLD_Map_App.debounceTimer = setTimeout(() => {
                    const term = $(e.target).val();
                    if (term.length >= 2) {
                        // Pass the suggestions container ID to the API layer
                        MLD_API.fetchAutocompleteSuggestions(term, suggestionsId);
                    } else {
                        $(suggestionsId).hide().empty();
                    }
                }, 250);
            });
        };

        // Set up listeners for both search inputs
        setupSearchListener('#bme-search-input', '#bme-autocomplete-suggestions');
        setupSearchListener('#bme-search-input-modal', '#bme-autocomplete-suggestions-modal');

        // Two-way sync for the inputs
        $('#bme-search-input').on('input', function() {
            $('#bme-search-input-modal').val($(this).val());
        });
        $('#bme-search-input-modal').on('input', function() {
            $('#bme-search-input').val($(this).val());
        });

        $('#bme-property-type-select').on('change', function() {
            MLD_Map_App.selectedPropertyType = $(this).val();
            localStorage.setItem('bmePropertyType', MLD_Map_App.selectedPropertyType);
            MLD_Map_App.modalFilters = MLD_Filters.getModalDefaults(); // Reset modal filters on type change
            MLD_Filters.restoreModalUIToState();
            MLD_Core.updateModalVisibility();
            MLD_API.fetchDynamicFilterOptions();
            MLD_Core.updateUrlHash();
            MLD_API.refreshMapListings(true);
            MLD_Filters.renderFilterTags(); // Re-render tags to show the new property type
        });
        
        $(document).on('click', e => {
            if (!$(e.target).closest('#bme-search-bar-wrapper, #bme-search-bar-wrapper-modal').length) {
                $('#bme-autocomplete-suggestions').hide();
                $('#bme-autocomplete-suggestions-modal').hide();
            }
        });

        const $filtersModal = $('#bme-filters-modal-overlay');
        $('#bme-filters-button').on('click', () => {
            $filtersModal.css('display', 'flex');
            MLD_API.updateFilterCount();
            MLD_API.fetchDynamicFilterOptions();
        });
        $('#bme-filters-modal-close').on('click', () => $filtersModal.hide());
        $filtersModal.on('click', e => {
            if ($(e.target).is($filtersModal) && !$filtersModal.hasClass('is-dragging')) {
                $filtersModal.hide();
            }
        });

        $('#bme-apply-filters-btn').on('click', this.applyModalFilters);
        $('#bme-clear-filters-btn').on('click', this.clearAllFilters);
        
        $('body').on('click', '.bme-home-type-btn', function() { $(this).toggleClass('active'); });
        
        $('#bme-filter-beds').on('click', 'button', this.handleBedsSelection);
        $('#bme-filter-baths').on('click', 'button', this.handleBathsSelection);

        $('#bme-filters-modal-body').on('change keyup', 'input, select', () => {
            clearTimeout(MLD_Map_App.countUpdateTimer);
            MLD_Map_App.countUpdateTimer = setTimeout(MLD_API.updateFilterCount, 400);
        });
        $('#bme-filters-modal-body').on('click', 'button, input[type="checkbox"]', () => {
            clearTimeout(MLD_Map_App.countUpdateTimer);
            MLD_Map_App.countUpdateTimer = setTimeout(MLD_API.updateFilterCount, 100);
        });
    },

    /**
     * Initializes the price slider in the filter modal.
     */
    initPriceSlider: function() {
        const $ = jQuery;
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
            MLD_Filters.updatePriceFromSlider();
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
                input.value = MLD_Core.formatCurrency(rawValue);
            }
            MLD_Filters.updateSliderFromInput();
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
    },

    /**
     * Gets the default state for the filter modal.
     */
    getModalDefaults: function() {
        return {
            price_min: '', price_max: '', beds: [], baths_min: 0,
            home_type: [], status: ['Active'], sqft_min: '', sqft_max: '',
            year_built_min: '', year_built_max: '',
            keywords: '', stories: '', available_by: '',
            waterfront_only: false, open_house_only: false, pool_only: false, garage_only: false, fireplace_only: false
        };
    },

    /**
     * Reads the current state of the filter modal UI.
     */
    getModalState: function(isForCountOrOptions = false) {
        const $ = jQuery;
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
        MLD_Map_App.modalFilters = state;
        return state;
    },

    /**
     * Applies the filters from the modal and refreshes the map.
     */
    applyModalFilters: function() {
        MLD_Filters.getModalState();
        jQuery('#bme-filters-modal-overlay').hide();
        MLD_Filters.renderFilterTags(); // Render tags after applying
        MLD_Core.updateUrlHash();
        MLD_API.refreshMapListings(true);
    },

    /**
     * Clears all active filters and refreshes the map.
     */
    clearAllFilters: function() {
        MLD_Map_App.keywordFilters = {};
        MLD_Map_App.modalFilters = MLD_Filters.getModalDefaults();
        MLD_Filters.renderFilterTags();
        MLD_Filters.restoreModalUIToState();
        jQuery('#bme-filters-modal-overlay').hide();
        MLD_Core.updateUrlHash();
        MLD_API.refreshMapListings(true);
    },

    /**
     * Sets the filter modal UI to match the current filter state.
     */
    restoreModalUIToState: function() {
        const $ = jQuery;
        const modalFilters = MLD_Map_App.modalFilters;
        this.updatePriceSliderUI();
        
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
    },

    /**
     * Combines keyword filters and modal filters into a single object for API requests.
     */
    getCombinedFilters: function(currentModalState = MLD_Map_App.modalFilters, excludeHomeTypeAndStatus = false) {
        const combined = {};
        for (const type in MLD_Map_App.keywordFilters) {
            if (MLD_Map_App.keywordFilters[type].size > 0) combined[type] = Array.from(MLD_Map_App.keywordFilters[type]);
        }
    
        const tempCombined = { ...combined, ...currentModalState };
        const finalFilters = {};
    
        for (const key in tempCombined) {
            if (excludeHomeTypeAndStatus && (key === 'home_type' || key === 'status' || key === 'price_min' || key === 'price_max')) continue;
    
            const value = tempCombined[key];
            const defaultValue = this.getModalDefaults()[key];
    
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
    
        finalFilters.PropertyType = MLD_Map_App.selectedPropertyType;
    
        const rentalTypes = ['Residential Lease', 'Commercial Lease'];
        if (rentalTypes.includes(MLD_Map_App.selectedPropertyType)) {
            delete finalFilters.status;
        } else {
            delete finalFilters.available_by;
            if (!finalFilters.status || finalFilters.status.length === 0) {
                finalFilters.status = ['Active'];
            }
        }
    
        return finalFilters;
    },

    /**
     * Populates the home type filter buttons.
     */
    populateHomeTypes: function(subtypes) {
        const $ = jQuery;
        const container = $('#bme-filter-home-type');
        container.empty();
        if (!subtypes || subtypes.length === 0) {
            container.html(`<p class="bme-placeholder">No specific home types available for this selection.</p>`);
            return;
        }

        let html = subtypes.map(type => {
            const subtypeSlug = MLD_Core.slugify(type);
            const custom = MLD_Map_App.subtypeCustomizations[subtypeSlug] || {};
            
            const label = custom.label || type;
            const iconHTML = custom.icon
                ? `<img src="${custom.icon}" alt="${label}" class="bme-custom-icon">`
                : MLD_Core.getIconForType(type);

            return `<button class="bme-home-type-btn" data-value="${type}">${iconHTML}<span>${label}</span></button>`;
        }).join('');

        container.html(html);
        this.restoreModalUIToState();
    },

    /**
     * Populates the status filter checkboxes.
     */
    populateStatusTypes: function(statuses) {
        const container = jQuery('#bme-filter-status');
        container.empty();
        if (!statuses || statuses.length === 0) {
            container.html(`<p class="bme-placeholder">No statuses available for the current selection.</p>`);
            return;
        }

        let html = statuses.map(status => `
            <label><input type="checkbox" value="${status}"> ${status}</label>
        `).join('');

        container.html(html);
        this.restoreModalUIToState();
    },

    /**
     * Handles selection logic for the beds filter.
     */
    handleBedsSelection: function(e) {
        const $ = jQuery;
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
    },

    /**
     * Handles selection logic for the baths filter.
     */
    handleBathsSelection: function(e) {
        const $ = jQuery;
        const $button = $(e.currentTarget);
        const $group = $button.closest('.bme-button-group');
        $group.find('button').removeClass('active');
        $button.addClass('active');
    },

    /**
     * Renders the autocomplete suggestions dropdown.
     * @param {Array} suggestions - The suggestion data from the API.
     * @param {string} suggestionsId - The ID of the container to render into.
     */
    renderAutocompleteSuggestions: function(suggestions, suggestionsId) {
        const $ = jQuery;
        const $container = $(suggestionsId);
        if (!$container.length) return;

        if (!suggestions || suggestions.length === 0) {
            $container.hide().empty();
            return;
        }
        let html = suggestions.map(s => `<div class="bme-suggestion-item" data-type="${s.type}" data-value="${s.value}"><span>${s.value}</span><span class="bme-suggestion-type">${s.type}</span></div>`).join('');
        $container.html(html).show();
        
        $container.find('.bme-suggestion-item').on('click', function() {
            MLD_Filters.addKeywordFilter($(this).data('type'), $(this).data('value'));
        });
    },

    /**
     * Adds a keyword filter tag and refreshes the map.
     */
    addKeywordFilter: function(type, value) {
        const app = MLD_Map_App;
        if (!app.keywordFilters[type]) app.keywordFilters[type] = new Set();
        app.keywordFilters[type].add(value);
        
        // Clear both inputs and hide both suggestions
        jQuery('#bme-search-input, #bme-search-input-modal').val('');
        jQuery('#bme-autocomplete-suggestions, #bme-autocomplete-suggestions-modal').hide().empty();

        this.renderFilterTags();
        MLD_Core.updateUrlHash();
        MLD_API.refreshMapListings(true);
    },
    
    /**
     * NEW: Removes any type of filter and updates the UI and map.
     */
    removeFilter: function(type, value) {
        const app = MLD_Map_App;
        const defaults = this.getModalDefaults();

        // Handle keyword filters (stored in a Set)
        if (app.keywordFilters[type]) {
            app.keywordFilters[type].delete(value);
            if (app.keywordFilters[type].size === 0) {
                delete app.keywordFilters[type];
            }
        } 
        // Handle modal filters
        else {
            switch(type) {
                case 'price':
                    app.modalFilters.price_min = defaults.price_min;
                    app.modalFilters.price_max = defaults.price_max;
                    break;
                case 'beds':
                    app.modalFilters.beds = app.modalFilters.beds.filter(item => item !== value);
                    break;
                case 'baths_min':
                    app.modalFilters.baths_min = defaults.baths_min;
                    break;
                case 'home_type':
                    app.modalFilters.home_type = app.modalFilters.home_type.filter(item => item !== value);
                    break;
                case 'status':
                    app.modalFilters.status = app.modalFilters.status.filter(item => item !== value);
                    break;
                default:
                    // Handle boolean amenities
                    if (defaults.hasOwnProperty(type)) {
                        app.modalFilters[type] = defaults[type];
                    }
                    break;
            }
        }

        this.restoreModalUIToState(); // Update the UI inside the modal
        this.renderFilterTags();      // Re-render the tags
        MLD_Core.updateUrlHash();
        MLD_API.refreshMapListings(true);
    },

    /**
     * Renders the active filter tags below the search bar.
     */
    renderFilterTags: function() {
        const $ = jQuery;
        const $container = $('#bme-filter-tags-container');
        $container.empty();
        const modalFilters = MLD_Map_App.modalFilters;
        const defaults = this.getModalDefaults();

        const createTag = (type, value, label) => {
            const $tag = $(`<div class="bme-filter-tag" data-type="${type}" data-value="${value}">${label} <span class="bme-filter-tag-remove">&times;</span></div>`);
            $tag.find('.bme-filter-tag-remove').on('click', () => this.removeFilter(type, value));
            $container.append($tag);
        };

        // Render keyword filters
        for (const type in MLD_Map_App.keywordFilters) {
            MLD_Map_App.keywordFilters[type].forEach(value => createTag(type, value, value));
        }
        
        // Render modal filters
        if (modalFilters.price_min || modalFilters.price_max) {
            const min = MLD_Core.formatCurrency(modalFilters.price_min || 0);
            const max = modalFilters.price_max ? MLD_Core.formatCurrency(modalFilters.price_max) : 'Any';
            createTag('price', 'all', `Price: ${min} - ${max}`);
        }
        modalFilters.beds.forEach(bed => createTag('beds', bed, `Beds: ${bed}`));
        if (modalFilters.baths_min != defaults.baths_min) {
            createTag('baths_min', modalFilters.baths_min, `Baths: ${modalFilters.baths_min}+`);
        }
        modalFilters.home_type.forEach(ht => createTag('home_type', ht, ht));
        modalFilters.status.forEach(s => createTag('status', s, s));

        // Render boolean amenities
        ['waterfront_only', 'open_house_only', 'pool_only', 'garage_only', 'fireplace_only'].forEach(key => {
            if (modalFilters[key]) {
                const label = key.replace('_only', '').replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                createTag(key, true, label);
            }
        });
        
        // Removed the placeholder changing logic here
        $('#bme-search-input').attr('placeholder', 'City, Address, School, ZIP, Agent, ID');
    },

    /**
     * Updates the price input fields based on the slider position.
     */
    updatePriceFromSlider: function() {
        const $ = jQuery;
        const priceSliderData = MLD_Map_App.priceSliderData;
        const minPercent = parseFloat(document.getElementById('bme-price-slider-handle-min').style.left) || 0;
        const maxPercent = parseFloat(document.getElementById('bme-price-slider-handle-max').style.left) || 100;
        
        const sliderRange = priceSliderData.display_max - priceSliderData.min;

        const currentMin = (sliderRange > 0) ? Math.round(priceSliderData.min + (minPercent / 100) * sliderRange) : priceSliderData.min;
        $('#bme-filter-price-min').val(MLD_Core.formatCurrency(currentMin)).data('raw-value', currentMin);

        if (maxPercent >= 100) {
            $('#bme-filter-price-max').val(MLD_Core.formatCurrency(priceSliderData.display_max) + '+').data('raw-value', '');
        } else {
            const currentMax = (sliderRange > 0) ? Math.round(priceSliderData.min + (maxPercent / 100) * sliderRange) : priceSliderData.display_max;
            $('#bme-filter-price-max').val(MLD_Core.formatCurrency(currentMax)).data('raw-value', currentMax);
        }
        
        this.updatePriceSliderRangeAndHistogram();
        
        clearTimeout(MLD_Map_App.countUpdateTimer);
        MLD_Map_App.countUpdateTimer = setTimeout(MLD_API.updateFilterCount, 400);
    },

    /**
     * Updates the slider position based on the price input fields.
     */
    updateSliderFromInput: function() {
        const $ = jQuery;
        let minVal = parseFloat($('#bme-filter-price-min').data('raw-value'));
        let maxVal = parseFloat($('#bme-filter-price-max').data('raw-value'));

        const priceSliderData = MLD_Map_App.priceSliderData;
        const sliderMin = priceSliderData.min;
        const sliderMax = priceSliderData.display_max;
        const sliderRange = sliderMax - sliderMin;

        if (isNaN(minVal) && isNaN(maxVal)) {
            document.getElementById('bme-price-slider-handle-min').style.left = '0%';
            document.getElementById('bme-price-slider-handle-max').style.left = '100%';
            this.updatePriceSliderRangeAndHistogram();
            clearTimeout(MLD_Map_App.countUpdateTimer);
            MLD_Map_App.countUpdateTimer = setTimeout(MLD_API.updateFilterCount, 400);
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
        
        this.updatePriceSliderRangeAndHistogram();
        
        clearTimeout(MLD_Map_App.countUpdateTimer);
        MLD_Map_App.countUpdateTimer = setTimeout(MLD_API.updateFilterCount, 400);
    },

    /**
     * Updates the visual state of the price slider range and histogram bars.
     */
    updatePriceSliderRangeAndHistogram: function() {
        const $ = jQuery;
        const minPercent = parseFloat(document.getElementById('bme-price-slider-handle-min').style.left) || 0;
        const maxPercent = parseFloat(document.getElementById('bme-price-slider-handle-max').style.left) || 100;

        const rangeEl = document.getElementById('bme-price-slider-range');
        rangeEl.style.left = minPercent + '%';
        rangeEl.style.width = (maxPercent - minPercent) + '%';
        
        $('#bme-price-histogram .bme-histogram-bar').each(function(index) {
            const barPercent = (index / (MLD_Map_App.priceSliderData.distribution.length || 1)) * 100;
            $(this).toggleClass('in-range', barPercent >= minPercent && barPercent < maxPercent);
        });
        const $outlierBar = $('.bme-histogram-bar-outlier');
        if ($outlierBar.length > 0) {
            $outlierBar.toggleClass('in-range', maxPercent >= 100);
        }
    },

    /**
     * Updates the entire price slider UI with new data from the API.
     */
    updatePriceSliderUI: function() {
        const $ = jQuery;
        const { min, display_max, distribution, outlier_count } = MLD_Map_App.priceSliderData;
        const modalFilters = MLD_Map_App.modalFilters;
        
        const currentMin = modalFilters.price_min !== '' ? modalFilters.price_min : min;
        const currentMax = modalFilters.price_max !== '' ? modalFilters.price_max : display_max;

        $('#bme-filter-price-min').val(MLD_Core.formatCurrency(currentMin)).data('raw-value', currentMin);
        if (modalFilters.price_max === '' && currentMax >= display_max) {
             $('#bme-filter-price-max').val(MLD_Core.formatCurrency(display_max) + '+').data('raw-value', '');
        } else {
             $('#bme-filter-price-max').val(MLD_Core.formatCurrency(currentMax)).data('raw-value', currentMax);
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
            const outlierLabel = `${outlier_count} listings above ${MLD_Core.formatCurrency(display_max)}`;
            const outlierBarHTML = `
                <div class="bme-histogram-bar bme-histogram-bar-outlier" style="height: ${height}%">
                    <span class="bme-histogram-bar-label">${outlierLabel}</span>
                </div>`;
            histogramContainer.append(outlierBarHTML);
        }
        
        this.updateSliderFromInput();
    }
};
