<?php
/**
 * Template for displaying a single property listing.
 * v7.1.0
 * - REFACTOR: Page layout completely restructured to move the gallery to the top.
 * - FEAT: Gallery HTML updated to support a new touch-friendly, swipeable slider.
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

$photos = MLD_Utils::decode_json($listing['Media'] ?? '[]') ?: [];
$address_full = $listing['UnparsedAddress'] ?: trim(sprintf('%s %s, %s, %s %s', $listing['StreetNumber'], $listing['StreetName'], $listing['City'], $listing['StateOrProvince'], $listing['PostalCode']));
$price = '$' . number_format((float)($listing['ListPrice'] ?? 0));
$total_baths = ($listing['BathroomsFull'] ?? 0) + (($listing['BathroomsHalf'] ?? 0) * 0.5);

$list_agent = MLD_Utils::decode_json($listing['ListAgentData'] ?? '[]');
$list_office = MLD_Utils::decode_json($listing['ListOfficeData'] ?? '[]');
$additional_data = MLD_Utils::decode_json($listing['AdditionalData'] ?? '[]');
$team_members_str = $additional_data['MLSPIN_TEAM_MEMBER'] ?? '';
$team_members = !empty($team_members_str) ? explode(',', $team_members_str) : [];

$site_agents = get_option('mld_contact_settings', []);

$open_houses = MLD_Utils::decode_json($listing['OpenHouseData'] ?? '[]');
if (!empty($open_houses)) {
    $now = new DateTime('now', new DateTimeZone('America/New_York'));
    $upcoming_open_houses = array_filter($open_houses, function($oh) use ($now) {
        $oh_date = new DateTime($oh['OpenHouseStartTime'] ?? 'now -1 day');
        return $oh_date >= $now;
    });
    usort($upcoming_open_houses, fn($a, $b) => strtotime($a['OpenHouseStartTime']) - strtotime($b['OpenHouseStartTime']));
} else {
    $upcoming_open_houses = [];
}

$all_categorized_fields = MLD_Utils::get_all_fields_by_category();
$admin_categories = ['Core Identifiers & Timestamps', 'Hidden/Admin', 'JSON / Miscellaneous', 'Agent & Office', 'Media'];

get_header(); ?>

<div id="mld-single-property-page">
    
    <!-- Gallery -->
    <div class="mld-gallery">
        <div class="mld-gallery-main-image">
            <div class="mld-gallery-slider">
                <?php if (!empty($photos)): ?>
                    <?php foreach ($photos as $photo): ?>
                        <div class="mld-gallery-slide">
                            <img src="<?php echo esc_url($photo['MediaURL']); ?>" alt="<?php echo esc_attr($address_full); ?>" loading="lazy">
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="mld-gallery-slide">
                        <img src="https://placehold.co/1200x800/eee/ccc?text=No+Image" alt="No Image Available">
                    </div>
                <?php endif; ?>
            </div>
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

    <div class="mld-container">
        <div class="mld-page-content-wrapper">
            <!-- Admin-Only Banners -->
            <?php if ($is_admin): ?>
                <div class="mld-admin-banners-container">
                    <?php if (!empty($listing['Disclosures'])): ?>
                        <div class="mld-admin-banner">
                            <strong>Disclosures:</strong> <?php echo esc_html($listing['Disclosures']); ?>
                            <button class="mld-admin-banner-close" aria-label="Close">&times;</button>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($listing['ShowingInstructions'])): ?>
                        <div class="mld-admin-banner">
                            <strong>Showing Instructions:</strong> <?php echo esc_html($listing['ShowingInstructions']); ?>
                            <button class="mld-admin-banner-close" aria-label="Close">&times;</button>
                        </div>
                    <?php endif; ?>
                     <?php if (!empty($listing['PrivateOfficeRemarks'])): ?>
                        <div class="mld-admin-banner">
                            <strong>Office Remarks:</strong> <?php echo esc_html($listing['PrivateOfficeRemarks']); ?>
                            <button class="mld-admin-banner-close" aria-label="Close">&times;</button>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Header: Status, Address, Price -->
            <header class="mld-page-header">
                <div>
                    <div class="mld-status-tags">
                        <span class="mld-status-tag primary"><?php echo esc_html($listing['StandardStatus'] ?? 'N/A'); ?></span>
                        <?php if (!empty($listing['OriginalListPrice']) && !empty($listing['ListPrice']) && $listing['ListPrice'] < $listing['OriginalListPrice']): ?>
                            <span class="mld-status-tag price-drop">Price Drop</span>
                        <?php endif; ?>
                    </div>
                    <h1 class="mld-address-main"><?php echo esc_html($address_full); ?></h1>
                    <div class="mld-core-specs-header">
                        <span><strong><?php echo (int)($listing['BedroomsTotal'] ?? 0); ?></strong> bds</span>
                        <span class="mld-spec-divider">|</span>
                        <span><strong><?php echo $total_baths; ?></strong> ba</span>
                        <span class="mld-spec-divider">|</span>
                        <span><strong><?php echo number_format((int)($listing['LivingArea'] ?? 0)); ?></strong> sqft</span>
                    </div>
                </div>
                <div class="mld-price-container">
                    <div class="mld-price"><?php echo esc_html($price); ?></div>
                </div>
            </header>

            <!-- Main Content (2-column) -->
            <div class="mld-main-content-wrapper">
                <main class="mld-listing-details">
                    
                    <!-- Description -->
                    <section class="mld-section">
                        <h2><?php echo esc_html(MLD_Utils::get_field_label('PublicRemarks')); ?></h2>
                        <p class="mld-description"><?php echo nl2br(esc_html($listing['PublicRemarks'] ?? 'No description available.')); ?></p>
                    </section>
                    
                    <!-- Open House Section -->
                    <?php if (!empty($upcoming_open_houses)): ?>
                    <section class="mld-section">
                        <h2>Open Houses</h2>
                        <div class="mld-open-house-list">
                            <?php foreach ($upcoming_open_houses as $oh): 
                                $start = new DateTime($oh['OpenHouseStartTime']);
                                $end = new DateTime($oh['OpenHouseEndTime']);
                            ?>
                            <div class="mld-oh-item">
                                <div class="mld-oh-date">
                                    <span class="mld-oh-month"><?php echo $start->format('M'); ?></span>
                                    <span class="mld-oh-day"><?php echo $start->format('d'); ?></span>
                                </div>
                                <div class="mld-oh-details">
                                    <span class="mld-oh-day-full"><?php echo $start->format('l'); ?></span>
                                    <span class="mld-oh-time"><?php echo $start->format('g:i A'); ?> - <?php echo $end->format('g:i A'); ?></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <?php endif; ?>

                    <!-- Dynamic Detail Sections -->
                    <?php
                    foreach ($all_categorized_fields as $category_key => $category_data) {
                        if (in_array($category_key, $admin_categories, true)) continue;

                        ob_start();
                        echo '<div class="mld-details-grid">';
                        foreach (array_keys($category_data['fields']) as $field_id) {
                            if (isset($listing[$field_id])) {
                                MLD_Utils::render_grid_item($field_id, $listing[$field_id]);
                            }
                        }
                        echo '</div>';
                        $section_html = ob_get_clean();

                        if (strpos($section_html, 'mld-grid-item') !== false) {
                            echo '<section class="mld-section">';
                            echo '<h2>' . esc_html($category_data['title']) . '</h2>';
                            echo $section_html;
                            echo '</section>';
                        }
                    }
                    ?>

                    <!-- Listing Agent/Office Info -->
                    <section class="mld-section mld-listing-agent-section">
                        <h2>Listing Agent & Office Information</h2>
                         <?php if (!empty($list_agent) || !empty($list_office)): ?>
                            <div class="mld-sidebar-card">
                                <p class="mld-sidebar-card-header">Listing Presented By</p>
                                <?php if (!empty($list_agent)): ?>
                                <div class="mld-agent-info">
                                    <div class="mld-agent-details">
                                        <strong><?php echo esc_html($list_agent['MemberFullName'] ?? 'N/A'); ?></strong>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($list_office)): ?>
                                <div class="mld-office-info">
                                    <strong><?php echo esc_html($list_office['OfficeName'] ?? 'N/A'); ?></strong>
                                </div>
                                <?php endif; ?>
                                 <?php if (!empty($team_members)): ?>
                                    <div class="mld-team-info">
                                        <strong>Team Members:</strong>
                                        <ul>
                                            <?php foreach($team_members as $member): ?>
                                                <li><?php echo esc_html(trim($member)); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <p>Listing agent information is not available.</p>
                        <?php endif; ?>
                    </section>

                </main>

                <!-- Sidebar -->
                <aside class="mld-sidebar">
                    <div class="mld-sidebar-sticky-content">
                        <div class="mld-sidebar-card">
                            <button class="mld-sidebar-btn primary">Request a Tour</button>
                            <button class="mld-sidebar-btn secondary">Contact Agent</button>
                        </div>

                        <?php if (!empty($site_agents)): ?>
                            <div class="mld-sidebar-card">
                                <p class="mld-sidebar-card-header">For Questions or Showings</p>
                                <?php foreach ($site_agents as $agent): ?>
                                    <div class="mld-agent-info">
                                        <?php if (!empty($agent['photo'])): ?>
                                            <img src="<?php echo esc_url($agent['photo']); ?>" alt="<?php echo esc_attr($agent['name']); ?>" class="mld-agent-avatar photo">
                                        <?php else: ?>
                                            <div class="mld-agent-avatar initial">
                                                <?php echo esc_html(strtoupper(substr($agent['name'] ?? 'A', 0, 1))); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="mld-agent-details">
                                            <strong><?php echo esc_html($agent['name'] ?? 'N/A'); ?></strong>
                                            <?php if (!empty($agent['email'])): ?>
                                                <a href="mailto:<?php echo esc_attr($agent['email']); ?>"><?php echo esc_html($agent['email']); ?></a>
                                            <?php endif; ?>
                                             <?php if (!empty($agent['phone'])): ?>
                                                <a href="tel:<?php echo esc_attr($agent['phone']); ?>"><?php echo esc_html($agent['phone']); ?></a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </aside>
            </div>

            <!-- Admin Only Section -->
            <?php if ($is_admin): ?>
                <section class="mld-section">
                    <div class="mld-admin-section">
                        <button class="mld-admin-toggle">
                            Admin Information
                            <span class="mld-toggle-icon">+</span>
                        </button>
                        <div class="mld-admin-content">
                            <?php
                            foreach ($all_categorized_fields as $category_key => $category_data) {
                                if (!in_array($category_key, $admin_categories, true)) continue;

                                $section_has_content = false;
                                ob_start();
                                foreach (array_keys($category_data['fields']) as $field_id) {
                                    if (isset($listing[$field_id]) && MLD_Utils::format_display_value($listing[$field_id], '') !== '') {
                                        MLD_Utils::render_grid_item($field_id, $listing[$field_id]);
                                        $section_has_content = true;
                                    }
                                }
                                $section_html = ob_get_clean();

                                if ($section_has_content) {
                                    echo '<h3>' . esc_html($category_data['title']) . '</h3>';
                                    echo '<div class="mld-details-grid">' . $section_html . '</div>';
                                }
                            }
                            ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php get_footer(); ?>
