/**
 * MLD Map Core Module
 * This is the main application object that initializes the map and manages state.
 */
const MLD_Map_App = {

	// --- State Properties ---
	isInitialized: false,
	map: null,
	markers: [],
	openPopupIds: new Set(),
	autocompleteRequest: null,
	debounceTimer: null,
	countUpdateTimer: null,
	isInitialLoad: true,
	lastMapState: { lat: 0, lng: 0, zoom: 0 },
	AdvancedMarkerElement: null,
	allListingsCache: { data: [], timestamp: null, total: 0 },
	isFetchingCache: false,
	selectedPropertyType: 'Residential',
	keywordFilters: {},
	modalFilters: {},
	isUnitFocusMode: false,
    isNearbySearchActive: false, // To track the state of the nearby search toggle
	focusedListings: [],
	openPopoutWindows: {},
	subtypeCustomizations: {},
	priceSliderData: { min: 0, display_max: 0, distribution: [], outlier_count: 0 },

	// --- Constants ---
	CACHE_EXPIRATION: 15 * 60 * 1000, // 15 minutes
	BATCH_SIZE: 200,

	/**
	 * Main entry point. Called on document ready and after AJAX calls.
	 */
	init: function() {
		const mapContainer = document.getElementById('bme-map-container');

		if (!mapContainer || this.isInitialized || mapContainer.classList.contains('mld-map-initialized')) {
			return;
		}

		if (typeof bmeMapData === 'undefined') {
			console.error("MLD Error: Map data (bmeMapData) is not available.");
			return;
		}

		if (bmeMapData.provider === 'google') {
			this.waitForGoogleMaps();
		} else {
			this.run();
		}
	},

	/**
	 * Polls to check if the Google Maps API and its libraries are loaded.
	 */
	waitForGoogleMaps: function() {
		const self = this;
		const interval = setInterval(function() {
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
	 * Contains the entire map application logic.
	 * Only called once the necessary APIs are ready.
	 */
	run: function() {
		const mapContainer = document.getElementById('bme-map-container');
		mapContainer.classList.add('mld-map-initialized');
		this.isInitialized = true;
		this.subtypeCustomizations = bmeMapData.subtype_customizations || {};
		this.modalFilters = MLD_Filters.getModalDefaults();

		document.body.classList.add('mld-map-active');

		this.initMap();
	},

	/**
	 * Initializes the map instance, defaulting to Boston.
	 */
	async initMap() {
        const mapContainer = document.getElementById('bme-map-container');
        const bostonCenterGoogle = { lat: 42.3601, lng: -71.0589 };
        const bostonCenterMapbox = [-71.0589, 42.3601];
        const initialZoom = 14;

        // Ensure toggle is off on initial load
        jQuery('#bme-nearby-toggle').prop('checked', false);
        this.isNearbySearchActive = false;

        if (bmeMapData.provider === 'google') {
            try {
                const { Map } = await google.maps.importLibrary("maps");
                const markerLibrary = await google.maps.importLibrary("marker");
                await google.maps.importLibrary("drawing");

                this.AdvancedMarkerElement = markerLibrary.AdvancedMarkerElement;

                this.map = new Map(mapContainer, {
                    center: bostonCenterGoogle,
                    zoom: initialZoom,
                    mapId: 'BME_MAP_ID',
                    gestureHandling: 'greedy',
                    fullscreenControl: false,
                    mapTypeControl: false,
                    streetViewControl: false,
                    zoomControlOptions: { position: google.maps.ControlPosition.LEFT_BOTTOM }
                });

                this.map.addListener('idle', () => MLD_API.refreshMapListings(this.isInitialLoad));
                this.map.addListener('dragstart', () => MLD_Core.exitUnitFocusView());
                this.map.addListener('click', () => MLD_Core.exitUnitFocusView());

            } catch (error) {
                console.error("Error loading Google Maps libraries:", error);
                mapContainer.innerHTML = '<p>Error: Could not load the map. Please check the API key and console for details.</p>';
                return;
            }
        } else { // Mapbox
            mapboxgl.accessToken = bmeMapData.mapbox_key;

            this.map = new mapboxgl.Map({
                container: 'bme-map-container',
                style: 'mapbox://styles/mapbox/streets-v11',
                center: bostonCenterMapbox,
                zoom: initialZoom
            });

            this.map.addControl(new mapboxgl.NavigationControl(), 'bottom-left');
            this.map.on('idle', () => MLD_API.refreshMapListings(this.isInitialLoad));
            this.map.on('dragstart', () => MLD_Core.exitUnitFocusView());
            this.map.on('click', () => MLD_Core.exitUnitFocusView());
        }

        // Final setup steps after map is created
        this.postInitSetup();
    },

	/**
	 * Runs setup tasks after the map has been initialized.
	 */
	postInitSetup: function() {
		MLD_Filters.initSearchAndFilters();
		MLD_Core.initEventDelegation();
		MLD_Filters.initPriceSlider();

		const savedType = localStorage.getItem('bmePropertyType');
		if (savedType) {
			this.selectedPropertyType = savedType;
		}
        
        // Clone the property type selector into the modal and set up syncing
        MLD_Core.setupPropertyTypeSelectors();

		jQuery('#bme-property-type-select').val(this.selectedPropertyType);
        // Manually trigger change on the original select to sync the clone
        jQuery('#bme-property-type-select').trigger('change');


		MLD_Core.updateModalVisibility();
		MLD_API.fetchDynamicFilterOptions();

		// This flag is checked in the 'idle' event listener
		if (this.isInitialLoad) {
			this.isInitialLoad = false;
		}
	}
};


/**
 * MLD Core Utility Module
 * Contains general helper functions and event handlers for the map application.
 */
const MLD_Core = {
    
    /**
     * Clones the property type selector into the modal and keeps them synced.
     * This ensures the selector is available in both the top bar and modal on desktop.
     */
    setupPropertyTypeSelectors: function() {
        const $ = jQuery;
        const originalSelect = $('#bme-property-type-select');
        const mobileContainer = $('#bme-property-type-mobile-container');

        if (!originalSelect.length || !mobileContainer.length) {
            return;
        }

        // Clone the select element for the modal
        const clonedSelect = originalSelect.clone()
            .attr('id', 'bme-property-type-select-modal')
            .removeClass('bme-control-select'); // Remove top-bar specific class

        // Append the clone to the modal container
        mobileContainer.append(clonedSelect);

        // --- Two-way Syncing ---

        // When the main (desktop) selector changes, update the modal clone
        originalSelect.on('change', function() {
            const newValue = $(this).val();
            clonedSelect.val(newValue);
            // The main app state update should be handled by a separate listener
        });

        // When the modal selector changes, update the main one and trigger its change event
        // so that any other listeners on the original select will fire.
        clonedSelect.on('change', function() {
            const newValue = $(this).val();
            originalSelect.val(newValue).trigger('change');
        });
    },

    /**
     * Attempts to center the map on the user's current location.
     */
    centerOnUserLocation: function() {
        const app = MLD_Map_App;

        if (!navigator.geolocation) {
            alert("Geolocation is not supported by your browser.");
            jQuery('#bme-nearby-toggle').prop('checked', false);
            return;
        }

        navigator.geolocation.getCurrentPosition(
            (position) => { // Success
                const isMapbox = (bmeMapData.provider === 'mapbox');
                const center = isMapbox ?
                    [position.coords.longitude, position.coords.latitude] :
                    { lat: position.coords.latitude, lng: position.coords.longitude };

                app.isNearbySearchActive = true;
                app.map.setZoom(14);
                app.map.setCenter(center);

                MLD_Markers.createUserLocationMarker(center, isMapbox);
            },
            () => { // Error
                app.isNearbySearchActive = false;
                alert("Unable to retrieve your location. Please ensure location services are enabled for your browser and this site.");
                jQuery('#bme-nearby-toggle').prop('checked', false);
                MLD_Markers.removeUserLocationMarker();
            }
        );
    },

	/**
	 * A utility function to delay execution of a function.
	 */
	debounce: function(func, delay) {
		let timeout;
		return function(...args) {
			const context = this;
			clearTimeout(timeout);
			timeout = setTimeout(() => func.apply(context, args), delay);
		};
	},

	/**
	 * Converts a string to a URL-friendly slug.
	 */
	slugify: function(text) {
		if (typeof text !== 'string') return '';
		return text.toLowerCase().replace(/[^a-z0-9_\-]/g, '');
	},

	/**
	 * Formats a number as a US currency string.
	 */
	formatCurrency: function(value) {
		const num = Number(value);
		if (isNaN(num)) return '';
		return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(num);
	},

	/**
	 * Formats a price for display on markers (e.g., $500k, $1.2m).
	 */
	formatPrice: function(price) {
		price = parseFloat(price);
		if (isNaN(price)) return '';
		if (price < 10000) return `$${parseInt(price).toLocaleString('en-US')}`;
		if (price < 1000000) return `$${Math.round(price / 1000)}k`;
		return `$${(price / 1000000).toFixed(price < 10000000 ? 2 : 1)}m`;
	},

	/**
	 * Gets the normalized center coordinates of the map.
	 */
	getNormalizedCenter: function(mapInstance) {
		const center = mapInstance.getCenter();
		if (typeof center.lat === 'function') { // Google Maps
			return { lat: center.lat(), lng: center.lng() };
		}
		return { lat: center.lat, lng: center.lng }; // Mapbox
	},

	/**
	 * Gets the geographic bounds of the current map view.
	 */
	getMapBounds: function() {
		const map = MLD_Map_App.map;
		if (!map || !map.getBounds()) return null;
		if (bmeMapData.provider === 'google') {
			const b = map.getBounds();
			const ne = b.getNorthEast();
			const sw = b.getSouthWest();
			return { north: ne.lat(), south: sw.lat(), east: ne.lng(), west: sw.lng() };
		}
		const b = map.getBounds();
		return { north: b.getNorth(), south: b.getSouth(), east: b.getEast(), west: b.getWest() };
	},

	/**
	 * Handles the window resize event to keep the map centered.
	 */
	handleResize: function() {
		const map = MLD_Map_App.map;
		if (!map) return;
		if (bmeMapData.provider === 'google') {
			const center = map.getCenter();
			google.maps.event.trigger(map, 'resize');
			map.setCenter(center);
		} else {
			map.resize();
		}
		MLD_API.refreshMapListings(false);
	},

	/**
	 * Initializes global event delegations.
	 */
	initEventDelegation: function() {
		const $ = jQuery;
		$('body').on('click', '.bme-card-image a, .bme-view-details-btn', function(e) {
			e.stopPropagation();
		});

		$('body').on('click', '.bme-popout-btn', function(e) {
			e.stopPropagation();
			const listingData = $(this).closest('.bme-popup-card-wrapper').data('listingData');
			if (listingData) {
				MLD_Core.openPropertyInNewWindow(listingData);
				MLD_Markers.closeListingPopup(listingData.ListingId);
			}
		});

		window.addEventListener('beforeunload', () => {
			for (const id in MLD_Map_App.openPopoutWindows) {
				if (MLD_Map_App.openPopoutWindows[id] && !MLD_Map_App.openPopoutWindows[id].closed) {
					MLD_Map_App.openPopoutWindows[id].close();
				}
			}
		});
        
        // Nearby Search Toggle Handler
        $('#bme-nearby-toggle').on('change', function() {
            if ($(this).is(':checked')) {
                MLD_Core.centerOnUserLocation();
            } else {
                MLD_Map_App.isNearbySearchActive = false;
                MLD_Markers.removeUserLocationMarker();
            }
        });

        // Add resize listener for map
        window.addEventListener('resize', MLD_Core.debounce(MLD_Core.handleResize, 250));
	},

	/**
	 * Updates the visibility of certain filter sections based on property type.
	 */
	updateModalVisibility: function() {
		const $ = jQuery;
		const rentalTypes = ['Residential Lease', 'Commercial Lease'];
		const saleTypes = ['Residential', 'Residential Income', 'Commercial Sale', 'Business Opportunity', 'Land'];

		if (rentalTypes.includes(MLD_Map_App.selectedPropertyType)) {
			$('#bme-rental-filters').show();
			$('#bme-status-filter-group').hide();
		} else if (saleTypes.includes(MLD_Map_App.selectedPropertyType)) {
			$('#bme-rental-filters').hide();
			$('#bme-status-filter-group').show();
		} else {
			$('#bme-rental-filters').hide();
			$('#bme-status-filter-group').hide();
		}
	},

	/**
	 * Restores the filter state from the URL hash on page load.
	 */
	restoreStateFromUrl: function() {
		const hash = window.location.hash.substring(1);
		if (!hash) return;
		const params = new URLSearchParams(hash);
		const newKeywordFilters = {};
		const newModalFilters = MLD_Filters.getModalDefaults();
		for (const [key, value] of params.entries()) {
			const values = value.split(',');
			if (key === 'PropertyType') {
				MLD_Map_App.selectedPropertyType = value;
			} else if (['City', 'Building Name', 'MLS Area Major', 'MLS Area Minor', 'Postal Code', 'Street Name', 'MLS Number', 'Address'].includes(key)) {
				newKeywordFilters[key] = new Set(values);
			} else {
				if (['home_type', 'status', 'beds'].includes(key)) newModalFilters[key] = values;
				else if (['waterfront_only', 'open_house_only', 'pool_only', 'garage_only', 'fireplace_only'].includes(key)) newModalFilters[key] = value === 'true';
				else if (MLD_Filters.getModalDefaults().hasOwnProperty(key)) newModalFilters[key] = value;
			}
		}
		MLD_Map_App.keywordFilters = newKeywordFilters;
		MLD_Map_App.modalFilters = newModalFilters;
		MLD_Filters.renderFilterTags();
		MLD_Filters.restoreModalUIToState();
	},

	/**
	 * Updates the URL hash to reflect the current filter state.
	 */
	updateUrlHash: function() {
		const params = new URLSearchParams();
		const combined = MLD_Filters.getCombinedFilters();
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
	},

	/**
	 * Enters the "unit focus" mode when a cluster is clicked.
	 */
	enterUnitFocusView: function(group, focusedMarkerId) {
		const app = MLD_Map_App;
		if (app.isUnitFocusMode) return;
		app.isUnitFocusMode = true;
		app.focusedListings = group;

		const focusedMarker = app.markers.find(m => m.id === focusedMarkerId);

		MLD_Markers.clearMarkers();

		if (focusedMarker) {
			if (bmeMapData.provider === 'google') {
				focusedMarker.marker.map = app.map;
			} else {
				focusedMarker.marker.addTo(app.map);
			}
			app.markers.push(focusedMarker);
		}

		this.updateSidebarList(group);

		const address = `${group[0].StreetNumber||''} ${group[0].StreetName||''}`.trim();
		const overlay = `<div id="bme-focus-overlay">Showing units at: <strong>${address}</strong><span id="bme-focus-exit">(Click map to exit)</span></div>`;
		jQuery('.bme-map-ui-wrapper').append(overlay);
	},

	/**
	 * Exits the "unit focus" mode.
	 */
	exitUnitFocusView: function() {
		const app = MLD_Map_App;
		if (!app.isUnitFocusMode) return;
		app.isUnitFocusMode = false;
		app.focusedListings = [];
		jQuery('#bme-focus-overlay').remove();

		const bounds = this.getMapBounds();
		if (!bounds) return;
		const listingsInView = app.allListingsCache.data.filter(l => {
			const lat = parseFloat(l.Latitude);
			const lng = parseFloat(l.Longitude);
			return lat >= bounds.south && lat <= bounds.north && lng >= bounds.west && lng <= bounds.east;
		});

		MLD_Markers.renderNewMarkers(listingsInView);
		this.updateSidebarList(listingsInView.slice(0, 100));
	},

	/**
	 * Updates the sidebar with a list of properties.
	 */
	updateSidebarList: function(listings) {
		const $ = jQuery;
		const container = $('#bme-listings-list-container .bme-listings-grid');
		if (container.length === 0) return;
		container.empty();
		if (!listings || listings.length === 0) {
			container.html('<p class="bme-list-placeholder">No listings found.</p>');
			return;
		}
		listings.forEach(listing => {
			const card = $(this.createCardHTML(listing, 'sidebar'));
			card.on('mouseenter', () => MLD_Markers.highlightMarker(listing.ListingId, 'hover')).on('mouseleave', () => { MLD_Markers.highlightMarker(listing.ListingId, 'none'); MLD_Markers.reapplyActiveHighlights(); });
			container.append(card);
		});
	},

	/**
	 * Pans the map to a specific listing.
	 */
	panTo: function(listing) {
		const pos = { lat: parseFloat(listing.Latitude), lng: parseFloat(listing.Longitude) };
		if (bmeMapData.provider === 'google') MLD_Map_App.map.panTo(pos);
		else MLD_Map_App.map.panTo([pos.lng, pos.lat]);
	},

	/**
	 * Zooms the map to fit a given set of listings.
	 */
	fitMapToBounds: function(listings) {
		const map = MLD_Map_App.map;
		if (bmeMapData.provider === 'google') {
			const bounds = new google.maps.LatLngBounds();
			listings.forEach(l => bounds.extend(new google.maps.LatLng(parseFloat(l.Latitude), parseFloat(l.Longitude))));
			if (!bounds.isEmpty()) map.fitBounds(bounds);
		} else {
			const bounds = new mapboxgl.LngLatBounds();
			listings.forEach(l => bounds.extend([parseFloat(l.Longitude), parseFloat(l.Latitude)]));
			if (!bounds.isEmpty()) map.fitBounds(bounds, { padding: 100 });
		}
	},

	/**
	 * Opens a property card in a new, small browser window.
	 */
	openPropertyInNewWindow: function(listing) {
		const app = MLD_Map_App;
		const listingId = listing.ListingId;
		if (app.openPopoutWindows[listingId] && !app.openPopoutWindows[listingId].closed) {
			app.openPopoutWindows[listingId].focus();
			return;
		}

		const features = 'width=450,height=480,menubar=no,toolbar=no,location=no,resizable=yes,scrollbars=yes';
		const newWindow = window.open('', listingId, features);

		if (!newWindow) {
			alert('Please allow pop-ups for this website.');
			return;
		}

		app.openPopoutWindows[listingId] = newWindow;

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

		const popoutHTML = this.createCardHTML(listing, 'window');

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
			MLD_Markers.highlightMarker(listingId, 'none');
			delete app.openPopoutWindows[listingId];
		});
	},

	/**
	 * Generates the HTML for a listing card.
	 */
	createCardHTML: function(listing, context = 'sidebar') {
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
	},

	/**
	 * Gets an appropriate SVG icon for a given property type.
	 */
	getIconForType: function(type) {
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
};

// --- Initializer ---
(function($) {
	// Initial load
	$(document).ready(function() {
		MLD_Map_App.init();
	});

	// Handle AJAX navigation in some themes
	$(document).ajaxComplete(function() {
		setTimeout(function() {
			MLD_Map_App.init();
		}, 500);
	});
})(jQuery);
