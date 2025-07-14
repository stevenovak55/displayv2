/**
 * MLD Map Markers Module
 * Handles the creation, rendering, and interaction of map markers and popups.
 */
const MLD_Markers = {

    userLocationMarker: null, // To hold the user's location marker

    /**
     * Creates a special marker for the user's current location.
     * This marker is separate from the property markers.
     * @param {object} position - The lat/lng coordinates for the marker.
     * @param {boolean} isMapbox - Flag to determine which map provider is used.
     */
    createUserLocationMarker: function(position, isMapbox = false) {
        const app = MLD_Map_App;

        // If a user marker already exists, remove it before creating a new one.
        if (this.userLocationMarker) {
            this.removeUserLocationMarker();
        }

        // Create the custom HTML element for the marker (blue dot)
        const userMarkerPin = document.createElement('div');
        userMarkerPin.style.cssText = 'width: 20px; height: 20px; border-radius: 50%; background-color: #4285F4; border: 2px solid white; box-shadow: 0 0 5px rgba(0,0,0,0.5); cursor: pointer;';
        userMarkerPin.title = 'Your Location';

        if (isMapbox) {
            this.userLocationMarker = new mapboxgl.Marker(userMarkerPin)
                .setLngLat(position)
                .addTo(app.map);
        } else {
            // Ensure AdvancedMarkerElement is available
            if (app.AdvancedMarkerElement) {
                this.userLocationMarker = new app.AdvancedMarkerElement({
                    map: app.map,
                    position: position,
                    content: userMarkerPin,
                    title: 'Your Location',
                    zIndex: 9999 // Ensure it's on top of other markers
                });
            }
        }
    },

    /**
     * Removes the user location marker from the map.
     */
    removeUserLocationMarker: function() {
        if (this.userLocationMarker) {
            if (typeof this.userLocationMarker.map !== 'undefined') { // Google Maps AdvancedMarkerElement
                this.userLocationMarker.map = null;
            } else if (this.userLocationMarker.remove) { // Mapbox GL JS Marker
                this.userLocationMarker.remove();
            }
            this.userLocationMarker = null;
        }
    },

    /**
     * Renders a completely new set of markers on the map.
     * @param {Array} listings - The listings to render.
     */
    renderNewMarkers: function(listings) {
        this.clearMarkers();
        const markerData = this.getMarkerDataForListings(listings);
        markerData.forEach(data => {
            if (data.type === 'price') {
                this.createPriceMarker(data.listing, data.lng, data.lat, data.id);
            } else if (data.type === 'dot') {
                this.createDotMarker(data.listing, data.lng, data.lat, data.group, data.id);
            } else if (data.type === 'cluster') {
                this.createUnitClusterMarker(data.group, data.lng, data.lat, data.id);
            }
        });
        this.reapplyActiveHighlights();
    },

    /**
     * Efficiently updates markers on the map based on the current view.
     * @param {Array} listingsInView - The listings currently visible.
     */
    updateMarkersOnMap: function(listingsInView) {
        const app = MLD_Map_App;
        const requiredMarkerData = this.getMarkerDataForListings(listingsInView); // This generates the desired state
        const requiredMarkerMap = new Map(requiredMarkerData.map(m => [m.id, m])); // Map for quick lookup

        const markersToKeep = [];
        const markersToRecreate = []; // Markers whose type or content needs to change

        // First pass: Identify markers to remove or update
        app.markers.forEach(({ marker, id, element, data, rawListingId }) => {
            const desiredData = requiredMarkerMap.get(id);

            if (!desiredData) {
                // Marker exists on map but is no longer required (out of view or filtered out)
                if (bmeMapData.provider === 'google' && marker.map) marker.map = null;
                else if (marker.remove) marker.remove();
            } else {
                // Marker is required. Check if its type or content needs updating.
                let needsUpdate = false;

                // Determine current marker type from its class list
                const currentMarkerType = element.classList.contains('bme-price-marker') ? 'price' :
                                          (element.classList.contains('bme-dot-marker') ? 'dot' : 'cluster');

                if (desiredData.type !== currentMarkerType) {
                    needsUpdate = true; // Type has changed (e.g., price to dot, or vice-versa)
                } else if (desiredData.type === 'cluster') {
                    // For clusters, check if the text content needs to change due to zoom
                    const firstListing = desiredData.group[0];
                    const streetNumber = firstListing.StreetNumber || '';
                    const streetName = firstListing.StreetName || '';
                    const expectedText = `${desiredData.group.length} Units` + (app.map.getZoom() >= 17 ? ` at ${streetNumber} ${streetName}` : '');
                    if (element.textContent.trim() !== expectedText.trim()) {
                        needsUpdate = true;
                    }
                }

                if (needsUpdate) {
                    if (bmeMapData.provider === 'google' && marker.map) marker.map = null;
                    else if (marker.remove) marker.remove();
                    markersToRecreate.push(desiredData); // Mark for recreation with new content/type
                } else {
                    markersToKeep.push({ marker, id, element, data, rawListingId }); // Keep existing marker
                }
            }
        });

        app.markers = markersToKeep; // Update app.markers to only contain those we're keeping

        // Second pass: Add new markers and recreate updated ones
        requiredMarkerData.forEach(data => {
            // If it's not already in markersToKeep and not already processed for recreation
            if (!app.markers.some(m => m.id === data.id) && !markersToRecreate.some(m => m.id === data.id)) {
                // This is a truly new marker that wasn't on the map before
                if (data.type === 'price') {
                    this.createPriceMarker(data.listing, data.lng, data.lat, data.id);
                } else if (data.type === 'dot') {
                    this.createDotMarker(data.listing, data.lng, data.lat, data.group, data.id);
                } else if (data.type === 'cluster') {
                    this.createUnitClusterMarker(data.group, data.lng, data.lat, data.id);
                }
            }
        });

        // Recreate markers that needed content/type updates
        markersToRecreate.forEach(data => {
            if (data.type === 'price') {
                this.createPriceMarker(data.listing, data.lng, data.lat, data.id);
            } else if (data.type === 'dot') {
                this.createDotMarker(data.listing, data.lng, data.lat, data.group, data.id);
            } else if (data.type === 'cluster') {
                this.createUnitClusterMarker(data.group, data.lng, data.lat, data.id);
            }
        });

        this.reapplyActiveHighlights();
    },

    /**
     * Normalizes street names by replacing common abbreviations and optionally
     * removing common street postfixes for more robust grouping.
     * @param {string} streetName - The street name to normalize.
     * @returns {string} The normalized street name.
     */
    normalizeStreetName: function(streetName) {
        if (typeof streetName !== 'string') {
            return '';
        }
        let normalized = streetName.trim().toLowerCase();

        // Define common abbreviations and their full forms
        const abbreviations = {
            'st': 'street',
            'ave': 'avenue',
            'blvd': 'boulevard',
            'dr': 'drive',
            'ln': 'lane',
            'rd': 'road',
            'sq': 'square',
            'ter': 'terrace',
            'ct': 'court',
            'cir': 'circle',
            'pl': 'place',
            'pkwy': 'parkway',
            'hwy': 'highway',
            'fwy': 'freeway',
            'trl': 'trail',
            'way': 'way',
            'aly': 'alley',
            'anx': 'annex',
            'arc': 'arcade',
            'bch': 'beach',
            'bnd': 'bend',
            'brg': 'bridge',
            'byp': 'bypass',
            'cmn': 'common',
            'cor': 'corner',
            'crs': 'crossing',
            'curv': 'curve',
            'est': 'estate',
            'expy': 'expressway',
            'ext': 'extension',
            'frry': 'ferry',
            'gln': 'glen',
            'grn': 'green',
            'grv': 'grove',
            'hbr': 'harbor',
            'htg': 'heights',
            'holw': 'hollow',
            'jct': 'junction',
            'ldg': 'lodging',
            'mdws': 'meadows',
            'mnt': 'mount',
            'mtn': 'mountain',
            'pk': 'park',
            'pt': 'point',
            'prt': 'port',
            'ranch': 'ranch',
            'rdg': 'ridge',
            'riv': 'river',
            'shrs': 'shores',
            'spg': 'spring',
            'sumt': 'summit',
            'tunl': 'tunnel',
            'vly': 'valley',
            'vis': 'vista',
            'wkway': 'walkway',
            'xng': 'crossing'
        };

        // First, replace abbreviations at the end of the string
        for (const abbr in abbreviations) {
            const regex = new RegExp(`\\b${abbr}\\.?$`); // Matches abbreviation at word boundary, optional dot, at end of string
            if (regex.test(normalized)) {
                normalized = normalized.replace(regex, abbreviations[abbr]);
                break; // Assuming one abbreviation per street name
            }
        }

        // Second, remove common full street type postfixes if present at the end
        const streetTypes = [
            'street', 'avenue', 'boulevard', 'drive', 'lane', 'road', 'square',
            'terrace', 'court', 'circle', 'place', 'parkway', 'highway', 'freeway',
            'trail', 'way', 'alley', 'annex', 'arcade', 'beach', 'bend', 'bridge',
            'bypass', 'common', 'corner', 'crossing', 'curve', 'estate', 'expressway',
            'extension', 'ferry', 'glen', 'green', 'grove', 'harbor', 'heights',
            'hollow', 'junction', 'lodging', 'meadows', 'mount', 'mountain', 'park',
            'point', 'port', 'ranch', 'ridge', 'river', 'shores', 'spring', 'summit',
            'tunnel', 'valley', 'vista', 'walkway'
        ];

        for (const type of streetTypes) {
            const regex = new RegExp(`\\b${type}$`); // Matches full street type at word boundary, at end of string
            if (regex.test(normalized)) {
                normalized = normalized.replace(regex, '').trim();
                break; // Assuming one street type per street name
            }
        }

        return normalized;
    },

    /**
     * Determines which type of marker to show based on zoom level and density.
     * Now groups listings by normalized Street Number, Street Name, and City,
     * using the most common GPS coordinate for the group's pin location.
     * @param {Array} listings - The listings to analyze.
     * @returns {Array} An array of marker data objects.
     */
    getMarkerDataForListings: function(listings) {
        const app = MLD_Map_App;
        const MAX_PINS = 75; // Max 'detailed' pins (price or cluster) before defaulting to dots
        const CLUSTER_ZOOM_THRESHOLD = 16;
        const currentZoom = app.map.getZoom();
        const markerData = [];

        if (!listings || listings.length === 0) {
            return markerData;
        }

        const addressGroups = {}; // Key: "StreetNumber-NormalizedStreetName-City", Value: { group: [listing, ...], latLngCounts: {}, mostCommonLatLng: {lat,lng} }
        listings.forEach(listing => {
            const streetNumber = listing.StreetNumber ? String(listing.StreetNumber).trim() : '';
            const normalizedStreetName = this.normalizeStreetName(listing.StreetName);
            const city = listing.City ? String(listing.City).trim().toLowerCase() : '';
            const addressKey = `${streetNumber}-${normalizedStreetName}-${city}`;

            if (!addressGroups[addressKey]) {
                addressGroups[addressKey] = {
                    group: [],
                    latLngCounts: {},
                    mostCommonLatLng: null
                };
            }
            addressGroups[addressKey].group.push(listing);

            // Only consider valid coordinates for the most common calculation
            const lat = parseFloat(listing.Latitude);
            const lng = parseFloat(listing.Longitude);
            if (!isNaN(lat) && !isNaN(lng)) {
                const latLngKey = `${lat.toFixed(6)},${lng.toFixed(6)}`;
                addressGroups[addressKey].latLngCounts[latLngKey] = (addressGroups[addressKey].latLngCounts[latLngKey] || 0) + 1;
            }
        });

        const processedAddressGroups = [];
        for (const key in addressGroups) {
            const { group, latLngCounts } = addressGroups[key];
            let mostCommonLatLng = null;
            let maxCount = 0;

            // Find the most common Lat/Lng within this address group
            for (const llKey in latLngCounts) {
                if (latLngCounts[llKey] > maxCount) {
                    maxCount = latLngCounts[llKey];
                    const [latStr, lngStr] = llKey.split(',');
                    mostCommonLatLng = { lat: parseFloat(latStr), lng: parseFloat(lngStr) };
                }
            }

            // Fallback: If no common coordinate found (e.g., all invalid or only one listing),
            // use the coordinate of the first valid listing in the group.
            if (!mostCommonLatLng && group.length > 0) {
                for (const listing of group) {
                    const lat = parseFloat(listing.Latitude);
                    const lng = parseFloat(listing.Longitude);
                    if (!isNaN(lat) && !isNaN(lng)) {
                        mostCommonLatLng = { lat, lng };
                        break;
                    }
                }
            }

            // Ensure mostCommonLatLng is valid before proceeding
            if (!mostCommonLatLng || isNaN(mostCommonLatLng.lat) || isNaN(mostCommonLatLng.lng)) {
                console.warn(`Skipping group with invalid or missing coordinates after fallback: ${key}`, group);
                continue;
            }

            processedAddressGroups.push({
                group: group,
                markerPosition: mostCommonLatLng,
                isMultiUnit: group.length > 1,
                // Unique ID for the group marker, combining slugified address and most common lat/lng
                id: `group-${MLD_Core.slugify(key)}-${mostCommonLatLng.lat.toFixed(6)}-${mostCommonLatLng.lng.toFixed(6)}`
            });
        }

        // Separate groups into candidates for 'detailed' pins (price, cluster) and 'dot' pins based on *initial* preference
        const candidatesForDetailedPins = [];
        const candidatesForDotPins = [];

        processedAddressGroups.forEach(processedGroup => {
            const { group, markerPosition, isMultiUnit, id } = processedGroup;
            const listingForPriceDisplay = group[0]; // Use the first listing in the group for price/general data

            if (isMultiUnit) {
                if (currentZoom >= CLUSTER_ZOOM_THRESHOLD) {
                    // Ideal: Cluster marker at high zoom
                    candidatesForDetailedPins.push({
                        type: 'cluster',
                        id: id,
                        group: group,
                        lng: markerPosition.lng,
                        lat: markerPosition.lat
                    });
                } else {
                    // Ideal: Dot marker for multi-unit at low zoom
                    candidatesForDotPins.push({
                        type: 'dot',
                        id: id,
                        listing: listingForPriceDisplay,
                        group: group,
                        lng: markerPosition.lng,
                        lat: markerPosition.lat
                    });
                }
            } else {
                // Single-unit always ideally a price marker
                candidatesForDetailedPins.push({
                    type: 'price',
                    id: `price-${listingForPriceDisplay.ListingId}`,
                    listing: listingForPriceDisplay,
                    lng: markerPosition.lng,
                    lat: markerPosition.lat
                });
            }
        });

        // Apply MAX_PINS limit to the 'detailed' candidates
        let detailedPinsCount = 0;
        for (let i = 0; i < candidatesForDetailedPins.length; i++) {
            if (detailedPinsCount < MAX_PINS) {
                markerData.push(candidatesForDetailedPins[i]);
                detailedPinsCount++;
            } else {
                // If budget exceeded, convert to a dot marker
                const convertedToDot = { ...candidatesForDetailedPins[i] };
                convertedToDot.type = 'dot';
                // Adjust ID for single listings converted to dot to maintain connection to ListingId
                if (convertedToDot.listing && convertedToDot.listing.ListingId) {
                    convertedToDot.id = `dot-${convertedToDot.listing.ListingId}`;
                }
                markerData.push(convertedToDot);
            }
        }
        // Add all markers that were originally determined to be 'dot' types
        markerData.push(...candidatesForDotPins);

        return markerData;
    },

    /**
     * Creates a small dot marker, used at high density or for grouped listings at low zoom.
     * @param {object} listing - The listing data (used for price display on hover if single unit).
     * @param {number} lng - Longitude.
     * @param {number} lat - Latitude.
     * @param {Array} [group=null] - The array of listings if this is a multi-unit group marker.
     * @param {string} id - The unique ID for this marker.
     */
    createDotMarker: function(listing, lng, lat, group = null, id) {
        const container = document.createElement('div');
        container.className = 'bme-marker-container';

        const dot = document.createElement('div');
        dot.className = 'bme-dot-marker';

        const pricePin = document.createElement('div');
        pricePin.className = 'bme-price-marker bme-marker-hover-reveal';
        pricePin.textContent = MLD_Core.formatPrice(listing.ListPrice);

        container.appendChild(dot);
        container.appendChild(pricePin);

        if (group) {
            container.onclick = () => MLD_Core.enterUnitFocusView(group, id);
        } else {
            container.onclick = () => this.handleMarkerClick(listing);
        }

        this.createMarkerElement(container, lng, lat, id, group || listing);
    },

    /**
     * Creates a marker showing the listing price.
     * @param {object} listing - The listing data.
     * @param {number} lng - Longitude.
     * @param {number} lat - Latitude.
     * @param {string} id - The unique ID for this marker.
     */
    createPriceMarker: function(listing, lng, lat, id) {
        const el = document.createElement('div');
        el.className = 'bme-price-marker';
        el.textContent = MLD_Core.formatPrice(listing.ListPrice);
        el.onclick = (e) => {
            e.stopPropagation();
            this.handleMarkerClick(listing);
        };
        this.createMarkerElement(el, lng, lat, id, listing);
    },

    /**
     * Creates a cluster marker for multiple units at the same location.
     * @param {Array} group - The array of listings in the cluster.
     * @param {number} lng - Longitude.
     * @param {number} lat - Latitude.
     * @param {string} id - The unique ID for this marker.
     */
    createUnitClusterMarker: function(group, lng, lat, id) {
        const el = document.createElement('div');
        el.className = 'bme-unit-cluster-marker';

        const currentZoom = MLD_Map_App.map.getZoom();
        let markerText = `${group.length} Units`;

        if (currentZoom >= 17) {
            const firstListing = group[0];
            const streetNumber = firstListing.StreetNumber || '';
            const streetName = firstListing.StreetName || '';
            markerText = `${group.length} Units at ${streetNumber} ${streetName}`;
        }

        el.textContent = markerText;
        el.onclick = (e) => {
            e.stopPropagation();
            MLD_Core.enterUnitFocusView(group, id);
        };
        this.createMarkerElement(el, lng, lat, id, group);
    },

    /**
     * Generic function to create a marker element for either Google Maps or Mapbox.
     * @param {HTMLElement} element - The custom HTML element for the marker.
     * @param {number} lng - Longitude.
     * @param {number} lat - Latitude.
     * @param {string} id - The unique ID for the marker (from markerData.id).
     * @param {object|Array} data - The raw listing object or the group array associated with the marker.
     */
    createMarkerElement: function(element, lng, lat, id, data) {
        const app = MLD_Map_App;
        let marker;
        let rawListingId = null;

        // Determine rawListingId only if it's a single listing marker (price or dot for single listing)
        if (!Array.isArray(data) && data.ListingId) {
            rawListingId = data.ListingId;
        }

        if (bmeMapData.provider === 'google' && app.AdvancedMarkerElement) {
            marker = new app.AdvancedMarkerElement({
                position: {
                    lat,
                    lng
                },
                map: app.map,
                content: element,
                zIndex: 1
            });
        } else if (bmeMapData.provider === 'mapbox') {
            marker = new mapboxgl.Marker({
                element
            }).setLngLat([lng, lat]).addTo(app.map);
        }
        if (marker) {
            app.markers.push({
                marker,
                id, // Use the passed-in ID (which is the effective unique ID for rendering/tracking)
                element,
                data,
                rawListingId // Will be null for group markers, ListingId for single markers
            });
        }
    },

    /**
     * Clears all markers from the map.
     */
    clearMarkers: function() {
        const app = MLD_Map_App;
        app.markers.forEach(({
            marker
        }) => {
            if (bmeMapData.provider === 'google' && marker.map) marker.map = null;
            else if (marker.remove) marker.remove();
        });
        app.markers = [];
    },

    /**
     * Handles a click on a marker.
     * For group markers, this function is not directly used; enterUnitFocusView is called.
     */
    handleMarkerClick: function(listing) {
        if (MLD_Map_App.openPopupIds.has(listing.ListingId)) {
            this.closeListingPopup(listing.ListingId);
        } else {
            MLD_Core.panTo(listing);
            this.showListingPopup(listing);
        }
    },

    /**
     * Shows a listing popup card on the map.
     */
    showListingPopup: function(listing) {
        const app = MLD_Map_App;
        if (app.openPopupIds.has(listing.ListingId)) return;
        app.openPopupIds.add(listing.ListingId);
        this.highlightMarker(listing.ListingId, 'active');
        const $popupWrapper = jQuery(`<div class="bme-popup-card-wrapper" data-listing-id="${listing.ListingId}"></div>`)
            .data('listingData', listing)
            .html(MLD_Core.createCardHTML(listing, 'popup'));
        const $closeButton = jQuery('<button class="bme-popup-close" aria-label="Close">&times;</button>').on('click', e => {
            e.stopPropagation();
            this.closeListingPopup(listing.ListingId);
        });
        $popupWrapper.append($closeButton);
        const stagger = (app.openPopupIds.size - 1) * 15;
        $popupWrapper.css({
            bottom: `${20 + stagger}px`,
            left: `calc(50% - ${stagger}px)`,
            transform: 'translateX(-50%)'
        });
        jQuery('#bme-popup-container').append($popupWrapper).show();
        this.makeDraggable($popupWrapper);
        this.updateCloseAllButton();
    },

    /**
     * Closes a specific listing popup.
     */
    closeListingPopup: function(listingId) {
        jQuery(`.bme-popup-card-wrapper[data-listing-id="${listingId}"]`).remove();
        MLD_Map_App.openPopupIds.delete(listingId);
        this.highlightMarker(listingId, 'none');
        if (MLD_Map_App.openPopupIds.size === 0) jQuery('#bme-popup-container').hide();
        this.updateCloseAllButton();
    },

    /**
     * Highlights a marker on the map (e.g., on hover or when active).
     */
    highlightMarker: function(listingId, state) {
        const markerData = MLD_Map_App.markers.find(m => m.rawListingId === listingId); // Only works for single listings
        if (!markerData) return;

        const {
            element,
            marker
        } = markerData;
        element.classList.remove('highlighted-active', 'highlighted-hover');
        if (bmeMapData.provider === 'google') marker.zIndex = 1;

        if (state === 'active') {
            element.classList.add('highlighted-active');
            if (bmeMapData.provider === 'google') marker.zIndex = 3;
        } else if (state === 'hover' && !element.classList.contains('highlighted-active')) {
            element.classList.add('highlighted-hover');
            if (bmeMapData.provider === 'google') marker.zIndex = 2;
        }
    },

    /**
     * Reapplies the 'active' highlight to markers whose popups are open.
     */
    reapplyActiveHighlights: function() {
        MLD_Map_App.openPopupIds.forEach(id => this.highlightMarker(id, 'active'));
    },

    /**
     * Makes a popup draggable.
     */
    makeDraggable: function($element) {
        let p1 = 0,
            p2 = 0,
            p3 = 0,
            p4 = 0;
        const handle = $element.find('.bme-listing-card');
        handle.on('mousedown', e => {
            e.preventDefault();
            p3 = e.clientX;
            p4 = e.clientY;
            jQuery('.bme-popup-card-wrapper').css('z-index', 1001);
            $element.css('z-index', 1002);
            handle.addClass('is-dragging');
            jQuery(document).on('mouseup', closeDrag).on('mousemove', drag);
        });
        const drag = e => {
            p1 = p3 - e.clientX;
            p2 = p4 - e.clientY;
            p3 = e.clientX;
            p4 = e.clientY;
            if ($element.css('bottom') !== 'auto') $element.css({
                top: $element.offset().top + 'px',
                left: $element.offset().left + 'px',
                bottom: 'auto',
                transform: 'none'
            });
            $element.css({
                top: ($element.get(0).offsetTop - p2) + "px",
                left: ($element.get(0).offsetLeft - p1) + "px"
            });
        };
        const closeDrag = () => {
            handle.removeClass('is-dragging');
            jQuery(document).off('mouseup', closeDrag).off('mousemove', drag);
        };
    },

    /**
     * Shows or hides the "Close All" button for popups.
     */
    updateCloseAllButton: function() {
        let btn = jQuery('#bme-close-all-btn');
        if (MLD_Map_App.openPopupIds.size > 1) {
            if (btn.length === 0) jQuery('<button id="bme-close-all-btn">Close All</button>').on('click', () => new Set(MLD_Map_App.openPopupIds).forEach(id => this.closeListingPopup(id))).appendTo('body');
        } else {
            btn.remove();
        }
    }
};
