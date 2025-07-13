MLS Listings Display Plugin
Version: 2.0.2
Author: Your Name

1. Overview
The MLS Listings Display plugin provides a powerful and interactive way to display real estate listings on your WordPress site. It is designed to work with data imported by the Bridge MLS Extractor Pro plugin.

This plugin allows you to render your listings on a dynamic, filterable map, in various layouts, and on dedicated, SEO-friendly single property pages. It is highly configurable, allowing you to customize map providers, branding, and the appearance of property type filters to match your brand.

2. Key Features
Interactive Map Interface: A fast, modern map for users to visually search for properties.

Multiple Map Providers: Choose between Google Maps and Mapbox.

Advanced Filtering: A comprehensive modal with filters for price, beds, baths, home type, status, square footage, amenities, and more.

Dynamic Price Histogram: A visual price distribution chart helps users understand the market and set realistic price ranges.

Autocomplete Search: Users can quickly search by City, Address, MLS Number, Agent, and more.

Flexible Layouts: Use shortcodes to display a full-screen map or a split-view with a map and a listing grid.

Dedicated Property Pages: Automatically generates clean, detailed pages for each listing at a URL like /property/12345/.

Customizable UI: An "Icon & Label Manager" lets you customize the appearance of property types in the filters.

Client-Side Caching: Intelligently caches listing data in the background to provide a fast and smooth user experience when panning and zooming the map.

3. Installation & Setup
Prerequisites
A working WordPress installation.

The Bridge MLS Extractor Pro plugin must be installed, activated, and have successfully imported listing data into the WordPress database.

Installation Steps
Upload the entire mls-listings-display plugin folder to the /wp-content/plugins/ directory on your server.

Navigate to the Plugins page in your WordPress admin dashboard.

Locate "MLS Listings Display" in the list and click Activate.

Upon activation, the plugin automatically flushes your site's rewrite rules to enable the /property/{mls-number}/ URL structure.

4. Configuration
After activating the plugin, navigate to the MLS Display menu item in your WordPress admin dashboard to configure the plugin.

4.1. Main Settings
Go to MLS Display -> MLS Display Settings.

Display Logo: Upload or provide a URL for a logo. This logo will appear in the top-left corner of the map interface, next to the search bar.

Map Provider:

Google Maps: The industry standard. Requires a Google Cloud project with an API key.

Mapbox: A popular and highly customizable alternative. Requires a Mapbox account and API key.

Google Maps API Key:

Required if you select Google Maps.

You must enable the following APIs in your Google Cloud Console for the key to work correctly:

Maps JavaScript API (for displaying the map)

Places API (for potential future search enhancements)

Drawing Library (for map drawing tools)

Mapbox API Key:

Required if you select Mapbox. You can get this from your Mapbox account dashboard.

4.2. Icon & Label Manager
Go to MLS Display -> Icon & Label Manager.

This powerful tool allows you to customize the "Home Type" filter in the map's filter modal. The page automatically scans your database and lists every unique PropertySubType found in your listings.

For each subtype, you can:

Set a Custom Display Label: This changes how the property type appears to the user. For example, you can change "Condominium" to "Condo/Townhome". If you leave this field blank, the original name from the MLS will be used.

Provide a Custom Icon: Upload or paste a URL for a custom icon (a 32x32px transparent PNG or SVG is recommended). This icon will appear next to the label in the filter, providing a more visual and user-friendly experience. If no icon is provided, the plugin will attempt to show a default SVG icon based on keywords in the subtype name (e.g., 'condo', 'farm', 'commercial').

5. How The Map Works
The map interface is a single-page application built with JavaScript that communicates with your WordPress backend via AJAX.

Initial Load: When a user first loads a page with a map shortcode, the map initializes and makes an AJAX call to fetch a default set of the most recent listings.

Panning & Zooming: As the user moves or zooms the map, the JavaScript detects the new map boundaries and sends an AJAX request to get only the listings within the current view. This is highly efficient and keeps the map feeling fast.

Filtering: When a user applies filters, a new AJAX request is sent with the filter criteria. The backend returns a new set of listings that match, and the map updates accordingly. If the results are widespread, the map will automatically zoom to fit all the matching properties.

Client-Side Caching: To improve performance, the plugin fetches a large set of listings in the background and caches them in the user's browser. When the user makes small pans or zooms, the map can often display listings from this cache instantly without needing to contact the server, resulting in a very smooth experience.

6. Shortcodes
To display the listings map, place one of the following shortcodes on any WordPress page or post.

[bme_listings_map_view]
This shortcode displays a map that fills the entire browser window. It's the best option for creating a dedicated, immersive "Property Search" page on your site.

[bme_listings_half_map_view]
This shortcode creates a split-screen layout with the interactive map on one side (typically the left) and a scrollable grid of property cards on the other. This is ideal for users who prefer to see results in both a visual map format and a traditional list format simultaneously.

7. Single Property Page
The plugin automatically handles the display of individual property detail pages, so you do not need to create pages for each listing.

URL Structure: The plugin creates clean, SEO-friendly URLs for each property: yourwebsite.com/property/{MLS_NUMBER}/. For example: yourwebsite.com/property/73123456/.

Template File: The layout and content of this page are controlled by the templates/single-property.php file within the plugin folder.

Customization: To customize the appearance of the property details page, it is recommended to copy single-property.php into your active theme's folder. WordPress will then use your theme's version instead of the plugin's default, ensuring your changes are not overwritten during plugin updates.

Displayed Information: The template is designed to display all key information for a listing, including:

A full-width photo gallery with thumbnails.

Price, address, and core stats (beds, baths, sqft).

Status tags (e.g., "Active", "Price Drop").

The full property description (Public Remarks).

Detailed sections for interior, exterior, financial, and utility information.

Open house schedules.

Listing agent and office information.

Admin-only boxes for "Showing Instructions" and "Disclosures" (visible only to logged-in administrators).