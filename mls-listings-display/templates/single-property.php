<?php
/**
 * Template for displaying a single property listing.
 * v2.3.0
 * - FEAT: Dynamically displays all non-standard fields from the new `AdditionalData` JSON column, ensuring all 307+ custom fields are visible.
 */

// --- Helper Functions ---

/**
 * Safely decodes a JSON string from the database.
 */
function mld_decode_json($json) {
    if (empty($json) || !is_string($json)) return null;
    return json_decode($json, true);
}

/**
 * Formats a value for display, handling arrays, booleans, and empty values.
 */
function mld_format_display_value($value, $na_string = 'N/A') {
    if (is_bool($value)) {
        return $value ? 'Yes' : 'No';
    }
    if (is_array($value)) {
        $filtered = array_filter($value, fn($item) => !empty(trim($item)));
        return !empty($filtered) ? esc_html(implode(', ', $filtered)) : $na_string;
    }
    if ($value === null || trim((string)$value) === '') {
        return $na_string;
    }
    return esc_html($value);
}

/**
 * Renders a grid item if the value is not empty.
 */
function mld_render_grid_item($label, $value) {
    $formatted_value = mld_format_display_value($value);
    if ($formatted_value !== 'N/A' && $formatted_value !== 'No' && $formatted_value !== '') {
        echo '<div class="grid-item"><strong>' . esc_html($label) . '</strong><span>' . $formatted_value . '</span></div>';
    }
}

// --- Main Template Logic ---

$mls_number = get_query_var('mls_number');
if (!$mls_number) {
    global $wp_query; $wp_query->set_404(); status_header(404); get_template_part(404); exit();
}

$listing = MLD_BME_Query::get_listing_details($mls_number);

if (!$listing) {
    global $wp_query; $wp_query->set_404(); status_header(404); get_template_part(404); exit();
}

// Prepare data for display
$address_parts = [
    $listing['StreetNumber'],
    $listing['StreetDirPrefix'],
    $listing['StreetName'],
    $listing['StreetDirSuffix'],
];
$address_line_1 = trim(implode(' ', array_filter($address_parts)));
$address_full = trim(sprintf('%s, %s, %s %s', $address_line_1, $listing['City'], $listing['StateOrProvince'], $listing['PostalCode']));

$price = '$' . number_format((float)$listing['ListPrice']);
$photos = mld_decode_json($listing['Media']) ?: [];
$main_photo = !empty($photos) ? $photos[0]['MediaURL'] : 'https://placehold.co/1200x800/eee/ccc?text=No+Image';

$list_agent = mld_decode_json($listing['ListAgentData']);
$list_office = mld_decode_json($listing['ListOfficeData']);
$open_houses = mld_decode_json($listing['OpenHouseData']);
$additional_data = mld_decode_json($listing['AdditionalData']); // Decode the new catch-all field

$js_data = [
    'photos' => array_column($photos, 'MediaURL')
];

get_header(); ?>

<div id="mld-single-property-page">
    <div class="mld-container">

        <!-- Gallery -->
        <div class="mld-gallery">
            <div class="mld-gallery-main-image">
                <img src="<?php echo esc_url($main_photo); ?>" alt="<?php echo esc_attr($address_full); ?>" id="mld-main-photo">
                <?php if (count($photos) > 1): ?>
                <button class="mld-slider-nav prev" aria-label="Previous image">&#10094;</button>
                <button class="mld-slider-nav next" aria-label="Next image">&#10095;</button>
                <?php endif; ?>
            </div>
            <?php if (count($photos) > 1): ?>
            <div class="mld-gallery-thumbnails">
                <?php foreach ($photos as $index => $photo): ?>
                    <img src="<?php echo esc_url($photo['MediaURL']); ?>" alt="Thumbnail <?php echo $index + 1; ?>" class="mld-thumb <?php echo $index === 0 ? 'active' : ''; ?>" data-index="<?php echo $index; ?>">
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="mld-main-content">
            <div class="mld-listing-details">
                <!-- Header -->
                <div class="mld-details-header">
                    <div>
                        <h1 class="mld-address"><?php echo esc_html($address_line_1); ?></h1>
                        <p class="mld-city-state"><?php echo esc_html(sprintf('%s, %s %s', $listing['City'], $listing['StateOrProvince'], $listing['PostalCode'])); ?></p>
                    </div>
                    <div>
                        <div class="mld-price"><?php echo esc_html($price); ?></div>
                        <div class="mld-status"><?php echo esc_html($listing['StandardStatus']); ?></div>
                    </div>
                </div>

                <!-- Core Specs -->
                <div class="mld-core-specs">
                    <div class="spec-item"><strong><?php echo (int)$listing['BedroomsTotal']; ?></strong><span>Beds</span></div>
                    <div class="spec-item"><strong><?php echo (float)$listing['BathroomsTotalInteger']; ?></strong><span>Baths</span></div>
                    <div class="spec-item"><strong><?php echo number_format((float)$listing['LivingArea']); ?></strong><span>Sq. Ft.</span></div>
                    <?php if (!empty($listing['LotSizeAcres']) && (float)$listing['LotSizeAcres'] > 0): ?>
                    <div class="spec-item"><strong><?php echo (float)$listing['LotSizeAcres']; ?></strong><span>Acres</span></div>
                    <?php endif; ?>
                </div>

                <!-- Open House Section -->
                <?php if (!empty($open_houses)): ?>
                <div class="mld-section mld-open-house-section">
                    <h2>Upcoming Open Houses</h2>
                    <div class="mld-open-house-list">
                    <?php 
                    $eastern_tz = new DateTimeZone('America/New_York');
                    $utc_tz = new DateTimeZone('UTC');

                    foreach ($open_houses as $oh): 
                        $oh_start_str = $oh['OpenHouseStartTime'] . 'Z';
                        $oh_end_str = $oh['OpenHouseEndTime'] . 'Z';
                        
                        $oh_start = new DateTime($oh_start_str, $utc_tz);
                        $oh_start->setTimezone($eastern_tz);

                        $oh_end = new DateTime($oh_end_str, $utc_tz);
                        $oh_end->setTimezone($eastern_tz);
                    ?>
                        <div class="oh-item">
                            <div class="oh-date">
                                <span class="oh-month"><?php echo $oh_start->format('M'); ?></span>
                                <span class="oh-day"><?php echo $oh_start->format('d'); ?></span>
                            </div>
                            <div class="oh-details">
                                <span class="oh-day-full"><?php echo $oh_start->format('l'); ?></span>
                                <span class="oh-time"><?php echo $oh_start->format('g:i A'); ?> - <?php echo $oh_end->format('g:i A'); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Description -->
                <div class="mld-section">
                    <h2>About this Property</h2>
                    <p><?php echo nl2br(esc_html($listing['PublicRemarks'])); ?></p>
                </div>

                <!-- Details Grids -->
                <?php 
                // Sections are now built from the main listing data
                $sections = [
                    'Property Information' => ['PropertyType', 'PropertySubType', 'YearBuilt', 'ArchitecturalStyle', 'StructureType', 'StoriesTotal', 'LivingArea', 'BuildingAreaTotal', 'Basement'],
                    'Interior Features' => ['BedroomsTotal', 'BathroomsFull', 'BathroomsHalf', 'RoomsTotal', 'InteriorFeatures', 'Appliances', 'Flooring', 'FireplacesTotal', 'FireplaceFeatures'],
                    'Exterior & Lot' => ['LotSizeAcres', 'LotSizeSquareFeet', 'ExteriorFeatures', 'PatioAndPorchFeatures', 'LotFeatures', 'ConstructionMaterials', 'FoundationDetails', 'Roof', 'CommunityFeatures', 'View'],
                    'Parking & Utilities' => ['GarageSpaces', 'GarageYN', 'ParkingTotal', 'ParkingFeatures', 'Heating', 'Cooling', 'WaterSource', 'Sewer', 'Utilities', 'Electric'],
                    'Financial & Association' => ['TaxAnnualAmount', 'TaxYear', 'TaxAssessedValue', 'AssociationYN', 'AssociationFee', 'AssociationFeeFrequency'],
                    'School Information' => ['ElementarySchool', 'MiddleOrJuniorSchool', 'HighSchool'],
                ];

                foreach ($sections as $title => $fields) {
                    $section_html = '';
                    foreach ($fields as $field) {
                        if (isset($listing[$field])) {
                            $label = ucwords(str_replace('_', ' ', preg_replace('/(?<!^)[A-Z]/', ' $0', $field)));
                            $formatted_value = mld_format_display_value($listing[$field]);
                             if ($formatted_value !== 'N/A' && $formatted_value !== 'No' && $formatted_value !== '') {
                                $section_html .= '<div class="grid-item"><strong>' . esc_html($label) . '</strong><span>' . $formatted_value . '</span></div>';
                            }
                        }
                    }

                    if (!empty($section_html)) {
                        echo '<div class="mld-section">';
                        echo '<h2>' . esc_html($title) . '</h2>';
                        echo '<div class="mld-details-grid">' . $section_html . '</div></div>';
                    }
                }

                // New section for all additional data
                if (!empty($additional_data) && is_array($additional_data)) {
                    echo '<div class="mld-section">';
                    echo '<h2>Additional Information</h2>';
                    echo '<div class="mld-details-grid">';
                    foreach ($additional_data as $label => $value) {
                         mld_render_grid_item($label, $value);
                    }
                    echo '</div></div>';
                }
                ?>
            </div>

            <!-- Sidebar -->
            <div class="mld-sidebar">
                <div class="mld-sidebar-card">
                    <?php if (!empty($listing['VirtualTourURLBranded'])): ?>
                        <a href="<?php echo esc_url($listing['VirtualTourURLBranded']); ?>" class="mld-sidebar-btn" target="_blank">View Virtual Tour</a>
                    <?php endif; ?>

                    <?php if (!empty($listing['ShowingContactName']) || !empty($listing['ShowingContactPhone'])): ?>
                        <div class="mld-showing-info">
                            <h3>Schedule a Showing</h3>
                            <?php if (!empty($listing['ShowingContactName'])): ?>
                                <p><strong>Contact:</strong> <?php echo esc_html($listing['ShowingContactName']); ?></p>
                            <?php endif; ?>
                             <?php if (!empty($listing['ShowingContactPhone'])): ?>
                                <p><strong>Phone:</strong> <?php echo esc_html($listing['ShowingContactPhone']); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($list_agent || $list_office): ?>
                <div class="mld-sidebar-card">
                    <h3>Listing Provided By</h3>
                    <?php if ($list_agent): ?>
                    <div class="mld-agent-info">
                        <div class="mld-agent-avatar">
                            <?php echo strtoupper(substr($list_agent['MemberFirstName'], 0, 1) . substr($list_agent['MemberLastName'], 0, 1)); ?>
                        </div>
                        <div class="mld-agent-details">
                            <strong><?php echo esc_html($list_agent['MemberFullName']); ?></strong>
                            <span><?php echo esc_html($list_agent['MemberType']); ?></span>
                            <?php if (!empty($list_agent['MemberEmail'])): ?>
                            <a href="mailto:<?php echo esc_attr($list_agent['MemberEmail']); ?>">Email Agent</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($list_office): ?>
                    <div class="mld-office-info">
                        <strong><?php echo esc_html($list_office['OfficeName']); ?></strong>
                        <p>
                            <?php echo esc_html($list_office['OfficeAddress1']); ?><br>
                            <?php echo esc_html(sprintf('%s, %s %s', $list_office['OfficeCity'], $list_office['OfficeStateOrProvince'], $list_office['OfficePostalCode'])); ?>
                        </p>
                        <?php if (!empty($list_office['OfficePhone'])): ?>
                        <p>Office: <?php echo esc_html($list_office['OfficePhone']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($listing['ListOfficeURL'])): ?>
                        <p><a href="<?php echo esc_url($listing['ListOfficeURL']); ?>" target="_blank">Visit Office Website</a></p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const propertyData = <?php echo json_encode($js_data); ?>;
    const photos = propertyData.photos;
    if (!photos || photos.length <= 1) return;

    let currentIndex = 0;
    const mainPhoto = document.getElementById('mld-main-photo');
    const thumbnailsContainer = document.querySelector('.mld-gallery-thumbnails');
    const allThumbs = document.querySelectorAll('.mld-thumb');

    function updateMainPhoto(index) {
        if (!photos[index]) return;
        mainPhoto.style.opacity = 0;
        setTimeout(() => {
            mainPhoto.src = photos[index];
            mainPhoto.style.opacity = 1;
        }, 200);
        
        currentIndex = index;
        allThumbs.forEach(thumb => thumb.classList.remove('active'));
        const activeThumb = document.querySelector(`.mld-thumb[data-index="${index}"]`);
        if(activeThumb) {
            activeThumb.classList.add('active');
            activeThumb.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
        }
    }

    document.querySelector('.mld-slider-nav.next').addEventListener('click', () => updateMainPhoto((currentIndex + 1) % photos.length));
    document.querySelector('.mld-slider-nav.prev').addEventListener('click', () => updateMainPhoto((currentIndex - 1 + photos.length) % photos.length));

    if (thumbnailsContainer) {
        thumbnailsContainer.addEventListener('click', e => {
            if (e.target.classList.contains('mld-thumb')) {
                updateMainPhoto(parseInt(e.target.dataset.index, 10));
            }
        });
    }
});
</script>

<?php get_footer(); ?>
