/**
 * MLD Map Markers Module
 * Handles the creation, rendering, and interaction of map markers and popups.
 */
const MLD_Markers = {

    /**
     * Renders a completely new set of markers on the map.
     * @param {Array} listings - The listings to render.
     */
    renderNewMarkers: function(listings) {
        this.clearMarkers();
        const markerData = this.getMarkerDataForListings(listings);
        markerData.forEach(data => {
             if (data.type === 'price') {
                this.createPriceMarker(data.listing, data.lng, data.lat);
            } else if (data.type === 'dot') {
                this.createDotMarker(data.listing, data.lng, data.lat, data.group);
            } else if (data.type === 'cluster') {
                this.createUnitClusterMarker(data.group, data.lng, data.lat);
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
        const requiredMarkerData = this.getMarkerDataForListings(listingsInView);
        const requiredMarkerIds = new Set(requiredMarkerData.map(m => m.id));
        const currentMarkerIdsOnMap = new Set(app.markers.map(m => m.id));

        const markersToRemove = app.markers.filter(m => !requiredMarkerIds.has(m.id));
        markersToRemove.forEach(({ marker }) => {
            if (bmeMapData.provider === 'google' && marker.map) marker.map = null;
            else if (marker.remove) marker.remove();
        });

        const markersToAdd = requiredMarkerData.filter(m => !currentMarkerIdsOnMap.has(m.id));
        markersToAdd.forEach(data => {
            if (data.type === 'price') {
                this.createPriceMarker(data.listing, data.lng, data.lat);
            } else if (data.type === 'dot') {
                this.createDotMarker(data.listing, data.lng, data.lat, data.group);
            } else if (data.type === 'cluster') {
                this.createUnitClusterMarker(data.group, data.lng, data.lat);
            }
        });

        app.markers = app.markers.filter(m => requiredMarkerIds.has(m.id));
        
        this.reapplyActiveHighlights();
    },

    /**
     * Determines which type of marker to show based on zoom level and density.
     * @param {Array} listings - The listings to analyze.
     * @returns {Array} An array of marker data objects.
     */
    getMarkerDataForListings: function(listings) {
        const app = MLD_Map_App;
        const MAX_PINS = 75;
        const CLUSTER_ZOOM_THRESHOLD = 16;
        const currentZoom = app.map.getZoom();
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
    },

    /**
     * Creates a small dot marker, used at high density.
     */
    createDotMarker: function(listing, lng, lat, group = null) {
        const container = document.createElement('div');
        container.className = 'bme-marker-container';
    
        const dot = document.createElement('div');
        dot.className = 'bme-dot-marker';
    
        const pricePin = document.createElement('div');
        pricePin.className = 'bme-price-marker bme-marker-hover-reveal';
        pricePin.textContent = MLD_Core.formatPrice(listing.ListPrice);
        
        container.appendChild(dot);
        container.appendChild(pricePin);
    
        const markerId = group ? `dot-cluster-${lat}-${lng}` : `dot-${listing.ListingId}`;
        const data = group || listing;

        if (group) {
            container.onclick = () => MLD_Core.enterUnitFocusView(group, `cluster-${lat}-${lng}`);
        } else {
            container.onclick = () => this.handleMarkerClick(listing);
        }
    
        this.createMarkerElement(container, lng, lat, markerId, data);
    },

    /**
     * Creates a marker showing the listing price.
     */
    createPriceMarker: function(listing, lng, lat) { 
        const el = document.createElement('div'); 
        el.className = 'bme-price-marker'; 
        el.textContent = MLD_Core.formatPrice(listing.ListPrice); 
        el.onclick = (e) => { e.stopPropagation(); this.handleMarkerClick(listing); }; 
        this.createMarkerElement(el, lng, lat, `price-${listing.ListingId}`, listing); 
    },

    /**
     * Creates a cluster marker for multiple units at the same location.
     */
    createUnitClusterMarker: function(group, lng, lat) {
        const el = document.createElement('div');
        const clusterId = `cluster-${lat}-${lng}`;
        el.className = 'bme-unit-cluster-marker';
        el.textContent = `${group.length} Units`;
        el.onclick = (e) => {
            e.stopPropagation();
            MLD_Core.enterUnitFocusView(group, clusterId);
        };
        this.createMarkerElement(el, lng, lat, clusterId, group);
    },

    /**
     * Generic function to create a marker element for either Google Maps or Mapbox.
     */
    createMarkerElement: function(element, lng, lat, id, data) {
        const app = MLD_Map_App;
        let marker;
        let rawListingId = null;
        if (data && !Array.isArray(data)) {
            rawListingId = data.ListingId;
        }

        if (bmeMapData.provider === 'google' && app.AdvancedMarkerElement) {
            marker = new app.AdvancedMarkerElement({ position: { lat, lng }, map: app.map, content: element, zIndex: 1 });
        } else if (bmeMapData.provider === 'mapbox') {
            marker = new mapboxgl.Marker({ element }).setLngLat([lng, lat]).addTo(app.map);
        }
        if (marker) {
            app.markers.push({ marker, id, element, data, rawListingId });
        }
    },

    /**
     * Clears all markers from the map.
     */
    clearMarkers: function() {
        const app = MLD_Map_App;
        app.markers.forEach(({ marker }) => { 
            if (bmeMapData.provider === 'google' && marker.map) marker.map = null; 
            else if (marker.remove) marker.remove(); 
        }); 
        app.markers = []; 
    },

    /**
     * Handles a click on a marker.
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
        const $closeButton = jQuery('<button class="bme-popup-close" aria-label="Close">&times;</button>').on('click', e => { e.stopPropagation(); this.closeListingPopup(listing.ListingId); });
        $popupWrapper.append($closeButton);
        const stagger = (app.openPopupIds.size - 1) * 15;
        $popupWrapper.css({ bottom: `${20 + stagger}px`, left: `calc(50% - ${stagger}px)`, transform: 'translateX(-50%)' });
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
        const markerData = MLD_Map_App.markers.find(m => m.rawListingId === listingId);
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
        let p1=0, p2=0, p3=0, p4=0; 
        const handle = $element.find('.bme-listing-card'); 
        handle.on('mousedown', e => { 
            e.preventDefault(); 
            p3 = e.clientX; p4 = e.clientY; 
            jQuery('.bme-popup-card-wrapper').css('z-index', 1001); 
            $element.css('z-index', 1002); 
            handle.addClass('is-dragging'); 
            jQuery(document).on('mouseup', closeDrag).on('mousemove', drag); 
        }); 
        const drag = e => { 
            p1 = p3 - e.clientX; p2 = p4 - e.clientY; p3 = e.clientX; p4 = e.clientY; 
            if ($element.css('bottom') !== 'auto') $element.css({ top: $element.offset().top + 'px', left: $element.offset().left + 'px', bottom: 'auto', transform: 'none' }); 
            $element.css({ top: ($element.get(0).offsetTop - p2) + "px", left: ($element.get(0).offsetLeft - p1) + "px" }); 
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
