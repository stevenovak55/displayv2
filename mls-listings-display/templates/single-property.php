<?php
/**
 * Template for displaying a single property listing.
 * v3.5.0
 * - REFACTOR: Removed helper functions to new MLD_Utils class.
 * - REFACTOR: Moved all JavaScript to assets/js/single-property.js.
 */

// --- Main Template Logic ---

$mls_number = get_query_var('mls_number');
if (!$mls_number) {
    global $wp_query; $wp_query->set_404(); status_header(404); get_template_part(404); exit();
}

$listing = MLD_Query::get_listing_details($mls_number);

if (!$listing) {
    global $wp_query; $wp_query->set_404(); status_header(404); get_template_part(404); exit();
}

// --- Prepare Data for Display ---
$is_admin = is_user_logged_in() && current_user_can('manage_options');
$is_rental = isset($listing['PropertyType']) && $listing['PropertyType'] === 'Residential Lease';

$address_parts = [$listing['StreetNumber'], $listing['StreetDirPrefix'], $listing['StreetName'], $listing['StreetDirSuffix']];
$address_line_1 = trim(implode(' ', array_filter($address_parts)));
if (!empty($listing['UnitNumber'])) {
    $address_line_1 .= ' #' . $listing['UnitNumber'];
}
$address_full = $listing['UnparsedAddress'] ?: trim(sprintf('%s, %s, %s %s', $address_line_1, $listing['City'], $listing['StateOrProvince'], $listing['PostalCode']));

$price = '$' . number_format((float)$listing['ListPrice']);
$is_price_drop = isset($listing['OriginalListPrice']) && (float)$listing['OriginalListPrice'] > (float)$listing['ListPrice'];

$photos = MLD_Utils::decode_json($listing['Media']) ?: [];
$main_photo = !empty($photos) ? $photos[0]['MediaURL'] : 'https://placehold.co/1200x800/eee/ccc?text=No+Image';

$list_agent = MLD_Utils::decode_json($listing['ListAgentData']);
$list_office = MLD_Utils::decode_json($listing['ListOfficeData']);
$open_houses = MLD_Utils::decode_json($listing['OpenHouseData']);
$additional_data = MLD_Utils::decode_json($listing['AdditionalData']);

$total_baths = (isset($listing['BathroomsFull']) ? $listing['BathroomsFull'] : 0) + (isset($listing['BathroomsHalf']) ? $listing['BathroomsHalf'] * 0.5 : 0);

// --- Group fields for organized display ---
$key_details_fields = ['PropertyType', 'PropertySubType', 'YearBuilt', 'EntryLevel', 'LotSizeAcres', 'LotSizeSquareFeet', 'MLSPIN_MARKET_TIME_PROPERTY'];
$interior_fields = ['InteriorFeatures', 'Appliances', 'Flooring', 'FireplacesTotal', 'FireplaceFeatures', 'FireplaceYN', 'RoomsTotal'];
$exterior_fields = ['ExteriorFeatures', 'PatioAndPorchFeatures', 'LotFeatures', 'ConstructionMaterials', 'FoundationDetails', 'Roof', 'View', 'WaterfrontYN', 'WaterfrontFeatures'];
$amenity_fields = ['CommunityFeatures', 'PoolPrivateYN', 'PoolFeatures'];
$parking_fields = ['GarageSpaces', 'GarageYN', 'ParkingTotal', 'ParkingFeatures'];
$utility_fields = ['Heating', 'Cooling', 'WaterSource', 'Sewer', 'Utilities', 'Electric'];
$financial_fields = ['TaxAnnualAmount', 'TaxYear', 'TaxAssessedValue', 'AssociationYN', 'AssociationFee', 'AssociationFeeFrequency'];
$school_fields = ['ElementarySchool', 'MiddleOrJuniorSchool', 'HighSchool', 'SchoolDistrict'];
$rental_fields = ['AvailabilityDate', 'MLSPIN_AvailableNow', 'LeaseTerm', 'RentIncludes', 'MLSPIN_SEC_DEPOSIT'];

$all_defined_fields = array_merge(
    $key_details_fields, $interior_fields, $exterior_fields, $amenity_fields, $parking_fields, 
    $utility_fields, $financial_fields, $school_fields, $rental_fields,
    ['ListingKey', 'ListingId', 'PublicRemarks', 'ListAgentData', 'ListOfficeData', 'AdditionalData', 'Media', 'OpenHouseData', 'Disclosures', 'ShowingInstructions']
);

$other_features = is_array($additional_data) ? array_filter($additional_data, fn($key) => !in_array($key, $all_defined_fields), ARRAY_FILTER_USE_KEY) : [];

get_header(); ?>

<div id="mld-single-property-page">
    <div class="mld-container">

        <!-- Admin-Only Info Boxes -->
        <?php if ($is_admin): ?>
            <?php if (!empty($listing['ShowingInstructions'])): ?>
            <div class="mld-admin-box info">
                <strong>Showing Instructions:</strong> <?php echo esc_html($listing['ShowingInstructions']); ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($listing['Disclosures'])): ?>
            <div class="mld-admin-box warning">
                <strong>Disclosures:</strong> <?php echo nl2br(esc_html($listing['Disclosures'])); ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Header: Status, Address, Price -->
        <header class="mld-page-header">
            <div>
                <div class="mld-status-tags">
                    <span class="mld-status-tag primary"><?php echo esc_html($listing['StandardStatus']); ?></span>
                    <?php if (!empty($listing['MlsStatus'])): ?>
                        <span class="mld-status-tag secondary"><?php echo esc_html($listing['MlsStatus']); ?></span>
                    <?php endif; ?>
                    <?php if ($is_price_drop): ?>
                        <span class="mld-status-tag price-drop">Price Drop</span>
                    <?php endif; ?>
                </div>
                <h1 class="mld-address-main"><?php echo esc_html($address_line_1); ?></h1>
                <p class="mld-address-secondary"><?php echo esc_html(sprintf('%s, %s %s', $listing['City'], $listing['StateOrProvince'], $listing['PostalCode'])); ?></p>
            </div>
            <div class="mld-price-container">
                <div class="mld-price"><?php echo esc_html($price); ?></div>
                <div class="mld-core-specs-header">
                    <span><?php echo (int)$listing['BedroomsTotal']; ?> Beds</span>
                    <span class="mld-spec-divider">|</span>
                    <span><?php echo $total_baths; ?> Baths</span>
                    <span class="mld-spec-divider">|</span>
                    <span><?php echo number_format((float)$listing['LivingArea']); ?> Sq. Ft.</span>
                </div>
            </div>
        </header>

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
                    <img src="<?php echo esc_url($photo['MediaURL']); ?>" alt="Thumbnail <?php echo $index + 1; ?>" class="mld-thumb <?php echo $index === 0 ? 'active' : ''; ?>" data-index="<?php echo $index; ?>" loading="lazy">
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Main Content (2-column) -->
        <div class="mld-main-content-wrapper">
            <main class="mld-listing-details">
                
                <!-- Description -->
                <section class="mld-section">
                    <h2>About This Home</h2>
                    <p class="mld-description"><?php echo nl2br(esc_html($listing['PublicRemarks'])); ?></p>
                </section>

                <!-- Living Area Breakdown -->
                <?php if (!empty($listing['AboveGradeFinishedArea']) || !empty($listing['BelowGradeFinishedArea'])): ?>
                <section class="mld-section">
                    <h2>Living Area Details</h2>
                    <div class="mld-details-grid">
                        <?php MLD_Utils::render_grid_item('Total Living Area', number_format((float)$listing['LivingArea']) . ' Sq. Ft.'); ?>
                        <?php MLD_Utils::render_grid_item('Above Grade Finished Area', number_format((float)$listing['AboveGradeFinishedArea']) . ' Sq. Ft.'); ?>
                        <?php MLD_Utils::render_grid_item('Below Grade Finished Area', number_format((float)$listing['BelowGradeFinishedArea']) . ' Sq. Ft.'); ?>
                    </div>
                </section>
                <?php endif; ?>

                <!-- Open House Section -->
                <?php if (!empty($open_houses) && is_array($open_houses)): ?>
                <section class="mld-section">
                    <h2>Open House Schedule</h2>
                    <div class="mld-open-house-list">
                    <?php 
                    $eastern_tz = new DateTimeZone('America/New_York');
                    $utc_tz = new DateTimeZone('UTC');
                    foreach ($open_houses as $oh): 
                        try {
                            $oh_start = new DateTime($oh['OpenHouseStartTime'], $utc_tz);
                            $oh_start->setTimezone($eastern_tz);
                            $oh_end = new DateTime($oh['OpenHouseEndTime'], $utc_tz);
                            $oh_end->setTimezone($eastern_tz);
                    ?>
                        <div class="mld-oh-item">
                            <div class="mld-oh-date">
                                <span class="mld-oh-month"><?php echo $oh_start->format('M'); ?></span>
                                <span class="mld-oh-day"><?php echo $oh_start->format('d'); ?></span>
                            </div>
                            <div class="mld-oh-details">
                                <span class="mld-oh-day-full"><?php echo $oh_start->format('l'); ?></span>
                                <span class="mld-oh-time"><?php echo $oh_start->format('g:i A'); ?> - <?php echo $oh_end->format('g:i A'); ?></span>
                            </div>
                        </div>
                    <?php } catch (Exception $e) { /* Skip invalid date */ } endforeach; ?>
                    </div>
                </section>
                <?php endif; ?>

                <!-- Dynamic Detail Sections -->
                <?php
                $sections = [
                    'Key Details' => $key_details_fields,
                    'Interior' => $interior_fields,
                    'Exterior & Lot' => $exterior_fields,
                    'Amenities' => $amenity_fields,
                    'Parking' => $parking_fields,
                    'Utilities' => $utility_fields,
                    'Financial Details' => $financial_fields,
                    'School Information' => $school_fields,
                ];
                
                if ($is_rental) {
                    $sections['Rental Information'] = $rental_fields;
                }
                
                $sections['Additional Features'] = array_keys($other_features);

                foreach ($sections as $title => $fields) {
                    ob_start();
                    echo '<div class="mld-details-grid">';
                    foreach ($fields as $field) {
                        $source_array = ($title === 'Additional Features') ? $other_features : $listing;
                        if (isset($source_array[$field])) {
                            MLD_Utils::render_grid_item($field, $source_array[$field]);
                        }
                    }
                    echo '</div>';
                    $section_html = ob_get_clean();

                    if (strpos($section_html, 'mld-grid-item') !== false) {
                        echo '<section class="mld-section">';
                        echo '<h2>' . esc_html($title) . '</h2>';
                        echo $section_html;
                        echo '</section>';
                    }
                }
                ?>
            </main>

            <!-- Sidebar -->
            <aside class="mld-sidebar">
                <div class="mld-sidebar-sticky-content">
                    <div class="mld-sidebar-card">
                        <button class="mld-sidebar-btn primary">Request a Tour</button>
                        <button class="mld-sidebar-btn secondary">Contact Agent</button>
                    </div>

                    <?php if ($list_agent || $list_office): ?>
                    <div class="mld-sidebar-card">
                        <p class="mld-sidebar-card-header">Listing Presented By</p>
                        <?php if ($list_agent): ?>
                        <div class="mld-agent-info">
                            <div class="mld-agent-avatar">
                                <?php echo esc_html(strtoupper(substr($list_agent['MemberFirstName'], 0, 1) . substr($list_agent['MemberLastName'], 0, 1))); ?>
                            </div>
                            <div class="mld-agent-details">
                                <strong><?php echo esc_html($list_agent['MemberFullName']); ?></strong>
                                <?php if (!empty($list_agent['MemberEmail'])): ?>
                                <a href="mailto:<?php echo esc_attr($list_agent['MemberEmail']); ?>">Email Agent</a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($list_office): ?>
                        <div class="mld-office-info">
                            <strong><?php echo esc_html($list_office['OfficeName']); ?></strong>
                            <?php if (!empty($list_office['OfficePhone'])): ?>
                            <p>Office: <?php echo esc_html($list_office['OfficePhone']); ?></p>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </aside>
        </div>
    </div>
</div>

<?php get_footer(); ?>
