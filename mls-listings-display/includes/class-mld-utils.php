<?php
/**
 * Utility functions for the MLS Listings Display plugin.
 * v6.0.0
 * - REFACTOR: Replaced the entire label system with a new categorized structure based on the complete field list.
 * - FEAT: Added get_all_fields_by_category() to drive the dynamic display on the single property page.
 */
class MLD_Utils {

    /**
     * Safely decodes a JSON string from the database.
     */
    public static function decode_json($json) {
        if (empty($json) || !is_string($json)) return null;
        $decoded = json_decode($json, true);
        return (json_last_error() === JSON_ERROR_NONE) ? $decoded : null;
    }

    /**
     * Formats a value for display, handling arrays, booleans, and empty values.
     */
    public static function format_display_value($value, $na_string = 'N/A') {
        if (is_string($value) && (strpos(trim($value), '[') === 0 || strpos(trim($value), '{') === 0)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }

        if (is_array($value)) {
            $filtered = array_filter($value, fn($item) => $item !== null && trim((string)$item) !== '');
            return empty($filtered) ? $na_string : esc_html(implode(', ', $filtered));
        }

        if (is_bool($value) || (is_numeric($value) && ($value == 1 || $value == 0))) {
            return $value ? 'Yes' : 'No';
        }
        if ($value === null || trim((string)$value) === '' || trim((string)$value) === '[]') {
            return $na_string;
        }
        if (is_string($value)) {
            $lower_value = strtolower(trim($value));
            if ($lower_value === 'yes') return 'Yes';
            if ($lower_value === 'no') return 'No';
        }

        return esc_html(trim((string)$value));
    }

    /**
     * Renders a grid item using the centralized field label.
     */
    public static function render_grid_item($field_id, $value) {
        $label = self::get_field_label($field_id);
        $formatted_value = self::format_display_value($value);

        if ($formatted_value !== 'N/A' && $formatted_value !== '') {
            echo '<div class="mld-grid-item"><strong>' . esc_html($label) . '</strong><span>' . $formatted_value . '</span></div>';
        }
    }

    /**
     * Gets the full categorized array of all field labels.
     * This is the new single source of truth for the property page structure.
     * @return array The categorized fields array.
     */
    public static function get_all_fields_by_category() {
        static $categorized_fields = null;
        if ($categorized_fields === null) {
            $categorized_fields = self::get_fields_array();
        }
        return $categorized_fields;
    }

    /**
     * Gets the display label for a single field ID.
     */
    public static function get_field_label($field_id) {
        $all_fields = self::get_all_fields_by_category();
        foreach ($all_fields as $category) {
            if (isset($category['fields'][$field_id])) {
                return $category['fields'][$field_id];
            }
        }
        // Fallback for any fields not in the main list
        return ucwords(str_replace(['_', 'YN'], [' ', ''], preg_replace('/(?<!^)[A-Z]/', ' $0', $field_id)));
    }
    
    /**
     * Centralized private function to define the categorized fields array.
     * @return array The categorized fields array.
     */
    private static function get_fields_array() {
        return [
            'Core Identifiers & Timestamps' => [
                'title' => 'Core Identifiers & Timestamps',
                'fields' => [
                    'ListingKey' => 'Unique listing key',
                    'ListingId' => 'MLS Listing ID',
                    'ModificationTimestamp' => 'Last modified',
                    'CreationTimestamp' => 'Record creation timestamp',
                    'StatusChangeTimestamp' => 'Status change date/time',
                    'CloseDate' => 'Date listing closed',
                    'PurchaseContractDate' => 'Date under contract',
                    'ListingContractDate' => 'Listing agreement date',
                    'OriginalEntryTimestamp' => 'First entry to MLS',
                    'OffMarketDate' => 'Date taken off market',
                ]
            ],
            'Core Listing Details' => [
                'title' => 'Core Listing Details',
                'fields' => [
                    'StandardStatus' => 'Standardized status',
                    'MlsStatus' => 'MLS-provided status',
                    'PropertyType' => 'Main property type',
                    'PropertySubType' => 'Subtype',
                    'BusinessType' => 'If Business Opportunity, its type',
                    'ListPrice' => 'Current list price',
                    'OriginalListPrice' => 'Original list price',
                    'ClosePrice' => 'Closing price',
                    'PublicRemarks' => 'Consumer-facing remarks',
                ]
            ],
            'Location Details' => [
                'title' => 'Location Details',
                'fields' => [
                    'UnparsedAddress' => 'Full raw address',
                    'StreetNumber' => 'Street number',
                    'StreetDirPrefix' => 'Street prefix',
                    'StreetName' => 'Street name',
                    'StreetDirSuffix' => 'Street suffix',
                    'StreetNumberNumeric' => 'Numeric street #',
                    'UnitNumber' => 'Unit or Apt #',
                    'EntryLevel' => 'Entry floor/level',
                    'EntryLocation' => 'Unit location',
                    'City' => 'City',
                    'StateOrProvince' => 'State or province',
                    'PostalCode' => 'Postal code',
                    'PostalCodePlus4' => 'ZIP+4',
                    'CountyOrParish' => 'County',
                    'Country' => 'Country code',
                    'MLSAreaMajor' => 'Major area',
                    'MLSAreaMinor' => 'Minor area',
                    'SubdivisionName' => 'Subdivision',
                    'Latitude' => 'Latitude',
                    'Longitude' => 'Longitude',
                    'Coordinates' => 'Geo point',
                ]
            ],
            'Property Characteristics' => [
                'title' => 'Property Characteristics',
                'fields' => [
                    'BedroomsTotal' => 'Total bedrooms',
                    'BathroomsTotalInteger' => 'Total baths',
                    'BathroomsFull' => 'Full baths',
                    'BathroomsHalf' => 'Half baths',
                    'LivingArea' => 'Living area',
                    'AboveGradeFinishedArea' => 'Above grade area',
                    'BelowGradeFinishedArea' => 'Below grade area',
                    'LivingAreaUnits' => 'Unit of measurement',
                    'BuildingAreaTotal' => 'Total building area',
                    'LotSizeAcres' => 'Lot size (acres)',
                    'LotSizeSquareFeet' => 'Lot size (sq ft)',
                    'LotSizeArea' => 'Lot size',
                    'YearBuilt' => 'Year built',
                    'YearBuiltEffective' => 'Effective year built',
                    'YearBuiltDetails' => 'Notes on year built',
                    'StructureType' => 'Structure type',
                    'ArchitecturalStyle' => 'Style',
                    'StoriesTotal' => 'Number of stories',
                    'Levels' => 'Levels description',
                    'PropertyAttachedYN' => 'Attached?',
                    'AttachedGarageYN' => 'Attached garage?',
                    'Basement' => 'Basement details',
                    'MLSPIN_MARKET_TIME_PROPERTY' => 'Days on market',
                    'PropertyCondition' => 'Condition',
                    'MLSPIN_COMPLEX_COMPLETE' => 'Complex complete?',
                    'MLSPIN_UNIT_BUILDING' => 'Unit building ID',
                    'MLSPIN_COLOR' => 'Exterior color',
                    'HomeWarrantyYN' => 'Home warranty?',
                ]
            ],
            'Construction & Utilities' => [
                'title' => 'Construction & Utilities',
                'fields' => [
                    'ConstructionMaterials' => 'Materials used',
                    'FoundationDetails' => 'Foundation type',
                    'FoundationArea' => 'Foundation area',
                    'Roof' => 'Roof details',
                    'Heating' => 'Heating system',
                    'Cooling' => 'Cooling system',
                    'Utilities' => 'Utilities',
                    'Sewer' => 'Sewer type',
                    'WaterSource' => 'Water source',
                    'Electric' => 'Electric system',
                    'ElectricOnPropertyYN' => 'Electricity on property?',
                    'MLSPIN_COOLING_UNITS' => 'Number of cooling units',
                    'MLSPIN_COOLING_ZONES' => 'Cooling zones',
                    'MLSPIN_HEAT_ZONES' => 'Heat zones',
                    'MLSPIN_HEAT_UNITS' => 'Heating units',
                    'MLSPIN_HOT_WATER' => 'Hot water type',
                    'MLSPIN_INSULATION_FEATURE' => 'Insulation details',
                    'WaterSewerExpense' => 'Water/sewer expense',
                    'ElectricExpense' => 'Electric expense',
                    'InsuranceExpense' => 'Insurance expense',
                ]
            ],
            'Interior Features' => [
                'title' => 'Interior Features',
                'fields' => [
                    'InteriorFeatures' => 'Interior notes',
                    'Flooring' => 'Flooring types',
                    'Appliances' => 'Appliances',
                    'FireplaceFeatures' => 'Fireplace features',
                    'FireplacesTotal' => 'Fireplace count',
                    'FireplaceYN' => 'Fireplace present?',
                    'RoomsTotal' => 'Number of rooms',
                    'WindowFeatures' => 'Window details',
                    'DoorFeatures' => 'Door details',
                    'LaundryFeatures' => 'Laundry notes',
                    'SecurityFeatures' => 'Security systems',
                    'SpaYN' => 'Spa present?',
                    'SpaFeatures' => 'Spa features',
                ]
            ],
            'Exterior & Lot Features' => [
                'title' => 'Exterior & Lot Features',
                'fields' => [
                    'ExteriorFeatures' => 'Exterior details',
                    'PatioAndPorchFeatures' => 'Patio/porch details',
                    'LotFeatures' => 'Lot details',
                    'RoadSurfaceType' => 'Road surface type',
                    'RoadFrontageType' => 'Road frontage type',
                    'RoadResponsibility' => 'Who maintains road',
                    'FrontageLength' => 'Frontage length',
                    'FrontageType' => 'Frontage type',
                    'Fencing' => 'Fencing details',
                    'OtherStructures' => 'Other structures',
                    'OtherEquipment' => 'Other equipment',
                    'PastureArea' => 'Pasture area',
                    'CultivatedArea' => 'Cultivated area',
                    'WaterfrontYN' => 'Waterfront?',
                    'WaterfrontFeatures' => 'Waterfront features',
                    'View' => 'View description',
                    'ViewYN' => 'View present?',
                    'CommunityFeatures' => 'Community features',
                    'MLSPIN_WATERVIEW_FLAG' => 'Water view?',
                    'MLSPIN_WATERVIEW_FEATURES' => 'Water view features',
                    'GreenIndoorAirQuality' => 'Green air quality',
                    'GreenEnergyGeneration' => 'Green energy generation',
                    'HorseYN' => 'Horse property?',
                    'HorseAmenities' => 'Horse amenities',
                ]
            ],
            'Parking' => [
                'title' => 'Parking',
                'fields' => [
                    'GarageSpaces' => 'Garage spaces',
                    'GarageYN' => 'Garage present?',
                    'CoveredSpaces' => 'Covered parking spaces',
                    'ParkingTotal' => 'Non-garage parking spaces',
                    'ParkingFeatures' => 'Parking features',
                    'CarportYN' => 'Carport present?',
                ]
            ],
            'HOA & Financial' => [
                'title' => 'HOA & Financial',
                'fields' => [
                    'AssociationYN' => 'HOA present?',
                    'AssociationFee' => 'HOA fee',
                    'AssociationFeeFrequency' => 'HOA fee frequency',
                    'AssociationName' => 'HOA name',
                    'AssociationAmenities' => 'HOA amenities',
                    'AssociationFeeIncludes' => 'HOA fee includes',
                    'MLSPIN_OPTIONAL_FEE' => 'Optional HOA fee',
                    'MLSPIN_OPT_FEE_INCLUDES' => 'Optional HOA fee includes',
                    'MLSPIN_REQD_OWN_ASSOCIATION' => 'Ownership required?',
                    'MLSPIN_NO_UNITS_OWNER_OCC' => 'Owner-occupied units',
                    'MLSPIN_DPR_Flag' => 'Down payment resource eligible?',
                    'MLSPIN_LENDER_OWNED' => 'Foreclosure?',
                    'GrossIncome' => 'Gross income',
                    'GrossScheduledIncome' => 'Scheduled income',
                    'NetOperatingIncome' => 'Net operating income',
                    'OperatingExpense' => 'Operating expenses',
                    'TotalActualRent' => 'Actual rent',
                    'MLSPIN_SELLER_DISCOUNT_PTS' => 'Seller discount points',
                    'FinancialDataSource' => 'Financial data source',
                    'CurrentFinancing' => 'Current financing',
                    'DevelopmentStatus' => 'Development status',
                    'ExistingLeaseType' => 'Lease type',
                ]
            ],
            'Rental Specific' => [
                'title' => 'Rental Specific',
                'fields' => [
                    'AvailabilityDate' => 'Availability date',
                    'MLSPIN_AvailableNow' => 'Available now?',
                    'LeaseTerm' => 'Lease term',
                    'RentIncludes' => 'Rent includes',
                    'MLSPIN_SEC_DEPOSIT' => 'Security deposit',
                    'MLSPIN_DEPOSIT_REQD' => 'Deposit required?',
                    'MLSPIN_INSURANCE_REQD' => 'Insurance required?',
                    'MLSPIN_LAST_MON_REQD' => 'Last month required?',
                    'MLSPIN_FIRST_MON_REQD' => 'First month required?',
                    'MLSPIN_REFERENCES_REQD' => 'References required?',
                ]
            ],
            'School Information' => [
                'title' => 'School Information',
                'fields' => [
                    'ElementarySchool' => 'Elementary school',
                    'MiddleOrJuniorSchool' => 'Middle/junior school',
                    'HighSchool' => 'High school',
                    'SchoolDistrict' => 'School district',
                ]
            ],
            'Media' => [
                'title' => 'Media',
                'fields' => [
                    'Media' => 'Media assets',
                    'PhotosCount' => 'Photo count',
                    'VirtualTourURLUnbranded' => 'Unbranded tour URL',
                    'VirtualTourURLBranded' => 'Branded tour URL',
                ]
            ],
            'Agent & Office' => [
                'title' => 'Agent & Office',
                'fields' => [
                    'ListAgentMlsId' => 'Listing agent ID',
                    'BuyerAgentMlsId' => 'Buyer agent ID',
                    'ListOfficeMlsId' => 'Listing office ID',
                    'BuyerOfficeMlsId' => 'Buyer office ID',
                    'MLSPIN_MAIN_SO' => 'Selling office ID',
                    'MLSPIN_MAIN_LO' => 'Listing office ID',
                    'MLSPIN_MSE' => 'Selling agent ID',
                    'MLSPIN_MGF' => 'Buyer office ID',
                    'MLSPIN_DEQE' => 'Buyer agent ID',
                    'MLSPIN_SOLD_VS_RENT' => 'Sold or rented',
                    'MLSPIN_TEAM_MEMBER' => 'Team member IDs',
                ]
            ],
            'Hidden/Admin' => [
                'title' => 'Hidden/Admin',
                'fields' => [
                    'PrivateOfficeRemarks' => 'Admin-only remarks',
                    'BuyerAgencyCompensation' => 'Buyer compensation',
                    'MLSPIN_BUYER_COMP_OFFERED' => 'Buyer comp offered?',
                    'MLSPIN_SHOWINGS_DEFERRAL_DATE' => 'Showings deferral date',
                    'MLSPIN_ALERT_COMMENTS' => 'Alert comments',
                    'MLSPIN_DISCLOSURE' => 'Disclosure info',
                    'MLSPIN_COMP_BASED_ON' => 'Comp based on',
                    'ExpirationDate' => 'Listing expiration',
                    'Disclosures' => 'Disclosures text',
                    'ShowingInstructions' => 'How to show the property',
                    'PrivateRemarks' => 'Private Remarks',
                ]
            ],
            'Municipal/Legal' => [
                'title' => 'Municipal/Legal',
                'fields' => [
                    'TaxMapNumber' => 'Tax map number',
                    'TaxBookNumber' => 'Tax book',
                    'TaxBlock' => 'Tax block',
                    'TaxLot' => 'Tax lot',
                    'ParcelNumber' => 'Parcel number',
                    'Zoning' => 'Zoning code',
                    'ZoningDescription' => 'Zoning description',
                    'MLSPIN_MASTER_PAGE' => 'Master deed page',
                    'MLSPIN_MASTER_BOOK' => 'Master deed book',
                    'MLSPIN_PAGE' => 'Deed page',
                    'MLSPIN_SEWAGE_DISTRICT' => 'Sewage district',
                ]
            ],
            'JSON / Miscellaneous' => [
                'title' => 'JSON / Miscellaneous',
                'fields' => [
                    'ListAgentData' => 'Listing agent JSON',
                    'ListOfficeData' => 'Listing office JSON',
                    'BuyerAgentData' => 'Buyer agent JSON',
                    'BuyerOfficeData' => 'Buyer office JSON',
                    'OpenHouseData' => 'Open house JSON',
                    'AdditionalData' => 'Extra data',
                ]
            ]
        ];
    }
}
