<?php
/**
 * Template for displaying a single property listing.
 * v1.1.0: Added a helper function to format array data cleanly.
 */

// Helper function to format values that might be JSON arrays
function mld_format_display_value($value) {
    if (empty($value)) return 'N/A';
    // Check if it's a string that looks like a JSON array
    if (is_string($value) && substr($value, 0, 1) === '[' && substr($value, -1) === ']') {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            // Filter out any empty items from the array
            $filtered = array_filter($decoded, function($item) {
                return !empty(trim($item));
            });
            return !empty($filtered) ? implode(', ', $filtered) : 'N/A';
        }
    }
    // If it's not a JSON string or decoding fails, return the original value
    return esc_html($value);
}


// Get the MLS Number from the URL query variable
$mls_number = get_query_var('mls_number');
if ( ! $mls_number ) {
    global $wp_query;
    $wp_query->set_404();
    status_header(404);
    get_template_part(404);
    exit();
}

// Fetch the listing data from our local database
$listing = MLD_BME_Query::get_listing_details( $mls_number );

if ( ! $listing ) {
    global $wp_query;
    $wp_query->set_404();
    status_header(404);
    get_template_part(404);
    exit();
}

// Prepare data for display
$address_full = trim(sprintf('%s %s, %s, %s %s', $listing['StreetNumber'], $listing['StreetName'], $listing['City'], $listing['StateOrProvince'], $listing['PostalCode']));
$price = '$' . number_format((float)$listing['ListPrice']);
$photos = !empty($listing['Media']) ? json_decode($listing['Media'], true) : [];
$main_photo = !empty($photos) ? $photos[0]['MediaURL'] : 'https://placehold.co/1200x800/eee/ccc?text=No+Image';

// We need to pass some data to our JavaScript
$js_data = [
    'ajax_url'   => admin_url('admin-ajax.php'),
    'security'   => wp_create_nonce('bme_map_nonce'),
    'listing_id' => $listing['ListingId'],
    'photos'     => array_column($photos, 'MediaURL')
];

get_header(); ?>

<div id="mld-single-property-page" data-listing-id="<?php echo esc_attr($listing['ListingId']); ?>">
    <div class="mld-container">

        <!-- Image Slider Section -->
        <div class="mld-gallery">
            <div class="mld-gallery-main-image">
                <img src="<?php echo esc_url($main_photo); ?>" alt="<?php echo esc_attr($address_full); ?>" id="mld-main-photo">
                <button class="mld-slider-nav prev" aria-label="Previous image">&#10094;</button>
                <button class="mld-slider-nav next" aria-label="Next image">&#10095;</button>
            </div>
            <?php if (count($photos) > 1): ?>
            <div class="mld-gallery-thumbnails">
                <?php foreach ($photos as $index => $photo): ?>
                    <img src="<?php echo esc_url($photo['MediaURL']); ?>" alt="Thumbnail <?php echo $index + 1; ?>" class="mld-thumb <?php echo $index === 0 ? 'active' : ''; ?>" data-index="<?php echo $index; ?>">
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Main Details Section -->
        <div class="mld-main-content">
            <div class="mld-details-header">
                <h1 class="mld-address"><?php echo esc_html($address_full); ?></h1>
                <div class="mld-price"><?php echo esc_html($price); ?></div>
                <div class="mld-status"><?php echo esc_html($listing['StandardStatus']); ?></div>
            </div>

            <div class="mld-core-specs">
                <div class="spec-item"><strong><?php echo (int)$listing['BedroomsTotal']; ?></strong><span>Beds</span></div>
                <div class="spec-item"><strong><?php echo (float)$listing['BathroomsTotalInteger']; ?></strong><span>Baths</span></div>
                <div class="spec-item"><strong><?php echo number_format((float)$listing['LivingArea']); ?></strong><span>Sq. Ft.</span></div>
                <div class="spec-item"><strong><?php echo (float)$listing['LotSizeAcres']; ?></strong><span>Acres</span></div>
            </div>

            <!-- Public Remarks -->
            <div class="mld-description-section">
                <h2>About this property</h2>
                <p><?php echo nl2br(esc_html($listing['PublicRemarks'])); ?></p>
            </div>

            <!-- All Other Details -->
            <div class="mld-details-grid-section">
                <h2>Property Details</h2>
                <div class="mld-details-grid">
                    <?php
                    $details_map = [
                        'Property Type' => $listing['PropertyType'],
                        'Sub-Type' => $listing['PropertySubType'],
                        'Year Built' => $listing['YearBuilt'],
                        'County' => $listing['CountyOrParish'],
                        'MLS Area Major' => $listing['MLSAreaMajor'],
                        'Structure Type' => $listing['StructureType'],
                        'Architectural Style' => $listing['ArchitecturalStyle'],
                        'Construction Materials' => $listing['ConstructionMaterials'],
                        'Garage Spaces' => $listing['GarageSpaces'],
                        'Parking Features' => $listing['ParkingFeatures'],
                        'Waterfront' => $listing['WaterfrontYN'] ? 'Yes' : 'No',
                        'Pool Features' => $listing['PoolFeatures'],
                        'Heating' => $listing['Heating'],
                        'Cooling' => $listing['Cooling'],
                        'Association Fee' => $listing['AssociationFee'] ? '$' . number_format((float)$listing['AssociationFee']) . ' / ' . $listing['AssociationFeeFrequency'] : 'None',
                        'Annual Taxes' => $listing['TaxAnnualAmount'] ? '$' . number_format((float)$listing['TaxAnnualAmount']) . ' (' . $listing['TaxYear'] . ')' : 'N/A',
                        'Elementary School' => $listing['ElementarySchool'],
                        'Middle School' => $listing['MiddleOrJuniorSchool'],
                        'High School' => $listing['HighSchool'],
                    ];

                    foreach ($details_map as $label => $value) {
                        $formatted_value = mld_format_display_value($value);
                        if ($formatted_value !== 'N/A') {
                            echo '<div class="grid-item"><strong>' . esc_html($label) . '</strong><span>' . $formatted_value . '</span></div>';
                        }
                    }
                    ?>
                </div>
            </div>

            <!-- Section for Live API Data -->
            <div id="mld-live-data-section" class="mld-details-grid-section">
                <h2>Listing Information</h2>
                <div class="mld-details-grid" id="mld-live-data-grid">
                    <div class="grid-item loading"><strong>Listing Agent:</strong> <span>Loading...</span></div>
                    <div class="grid-item loading"><strong>Listing Office:</strong> <span>Loading...</span></div>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const singlePropertyData = <?php echo json_encode($js_data); ?>;
    const photos = singlePropertyData.photos;
    let currentIndex = 0;

    const mainPhoto = document.getElementById('mld-main-photo');
    const thumbnailsContainer = document.querySelector('.mld-gallery-thumbnails');
    const allThumbs = document.querySelectorAll('.mld-thumb');

    function updateMainPhoto(index) {
        if (!photos[index]) return;
        mainPhoto.src = photos[index];
        currentIndex = index;
        allThumbs.forEach(thumb => thumb.classList.remove('active'));
        const activeThumb = document.querySelector(`.mld-thumb[data-index="${index}"]`);
        if(activeThumb) {
            activeThumb.classList.add('active');
            activeThumb.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
        }
    }

    if (photos.length > 1) {
        document.querySelector('.mld-slider-nav.next').addEventListener('click', function() {
            const newIndex = (currentIndex + 1) % photos.length;
            updateMainPhoto(newIndex);
        });

        document.querySelector('.mld-slider-nav.prev').addEventListener('click', function() {
            const newIndex = (currentIndex - 1 + photos.length) % photos.length;
            updateMainPhoto(newIndex);
        });
    }


    if (thumbnailsContainer) {
        thumbnailsContainer.addEventListener('click', function(e) {
            if (e.target.classList.contains('mld-thumb')) {
                const index = parseInt(e.target.dataset.index, 10);
                updateMainPhoto(index);
            }
        });
    }

    function fetchLiveDetails() {
        const xhr = new XMLHttpRequest();
        const params = `action=get_live_listing_details&security=${singlePropertyData.security}&listing_id=${singlePropertyData.listing_id}`;
        xhr.open('POST', singlePropertyData.ajax_url, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

        xhr.onload = function() {
            const container = document.getElementById('mld-live-data-grid');
            container.innerHTML = ''; 

            if (xhr.status >= 200 && xhr.status < 400) {
                const response = JSON.parse(xhr.responseText);
                if (response.success && response.data) {
                    const data = response.data;
                    let html = '';

                    if (data.ListAgent) html += `<div class="grid-item"><strong>Listing Agent:</strong> <span>${data.ListAgent.MemberFullName || 'N/A'}</span></div>`;
                    if (data.ListOffice) html += `<div class="grid-item"><strong>Listing Office:</strong> <span>${data.ListOffice.OfficeName || 'N/A'}</span></div>`;
                    if (data.BuyerAgent) html += `<div class="grid-item"><strong>Buyer's Agent:</strong> <span>${data.BuyerAgent.MemberFullName || 'N/A'}</span></div>`;
                    if (data.BuyerOffice) html += `<div class="grid-item"><strong>Buyer's Office:</strong> <span>${data.BuyerOffice.OfficeName || 'N/A'}</span></div>`;

                    container.innerHTML = html;

                    if (data.OpenHouse && data.OpenHouse.length > 0) {
                        const openHouseSection = document.createElement('div');
                        openHouseSection.className = 'mld-details-grid-section';
                        let oh_html = '<h2>Open Houses</h2><div class="mld-details-grid">';
                        data.OpenHouse.forEach(oh => {
                            const oh_date = new Date(oh.OpenHouseDate).toLocaleDateString();
                            oh_html += `<div class="grid-item"><strong>${oh_date}:</strong> <span>${oh.OpenHouseStartTime} - ${oh.OpenHouseEndTime}</span></div>`;
                        });
                        oh_html += '</div>';
                        openHouseSection.innerHTML = oh_html;
                        document.getElementById('mld-live-data-section').after(openHouseSection);
                    }

                } else {
                    container.innerHTML = `<div class="grid-item">Could not load live listing details. ${response.data || ''}</div>`;
                }
            } else {
                 container.innerHTML = '<div class="grid-item">Error loading live details.</div>';
            }
        };
        xhr.send(params);
    }

    fetchLiveDetails();
});
</script>

<?php get_footer(); ?>
