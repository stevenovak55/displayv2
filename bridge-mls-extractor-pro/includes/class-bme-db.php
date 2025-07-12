<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class BME_DB
 *
 * Handles database creation and schema management.
 *
 * v3.7.1
 * - FIX: Added `NOT NULL` to the `Coordinates` column to resolve the "SPATIAL index must be NOT NULL" error during table creation on some MySQL/MariaDB versions.
 * v3.7.0
 * - REFACTOR: Removed the index from the `MlsStatus` column as it is not used for filtering, only for display. `StandardStatus` is the primary field for all status-based queries.
 * - FEAT: Added `MLSPIN_AvailableNow` column for rental availability filtering.
 */
class BME_DB {

    /**
     * Create or update the necessary database tables on plugin activation.
     */
    public static function create_tables() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bme_listings';
        $charset_collate = $wpdb->get_charset_collate();
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            source_extraction_id BIGINT(20) UNSIGNED NOT NULL,
            
            -- Core Identifiers & Timestamps
            ListingKey VARCHAR(128) NOT NULL,
            ListingId VARCHAR(50) NOT NULL,
            ModificationTimestamp DATETIME,
            CreationTimestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            StatusChangeTimestamp DATETIME,
            CloseDate DATETIME,
            PurchaseContractDate DATETIME,
            ListingContractDate DATE,
            OriginalEntryTimestamp DATETIME,
            OffMarketDate DATETIME,

            -- Core Listing Details
            StandardStatus VARCHAR(50),
            MlsStatus VARCHAR(50),
            PropertyType VARCHAR(50),
            PropertySubType VARCHAR(50),
            ListPrice DECIMAL(20,2),
            OriginalListPrice DECIMAL(20,2),
            ClosePrice DECIMAL(20,2),
            PublicRemarks LONGTEXT,
            PrivateRemarks LONGTEXT,
            Disclosures LONGTEXT,
            ShowingInstructions TEXT,
            
            -- Location Details
            UnparsedAddress VARCHAR(255),
            StreetNumber VARCHAR(50),
            StreetDirPrefix VARCHAR(20),
            StreetName VARCHAR(100),
            StreetDirSuffix VARCHAR(20),
            StreetNumberNumeric INT,
            UnitNumber VARCHAR(30),
            EntryLevel VARCHAR(100),
            City VARCHAR(100),
            StateOrProvince VARCHAR(50),
            PostalCode VARCHAR(20),
            CountyOrParish VARCHAR(100),
            Country VARCHAR(5),
            MLSAreaMajor VARCHAR(100),
            MLSAreaMinor VARCHAR(100),
            SubdivisionName VARCHAR(100),
            Latitude DOUBLE,
            Longitude DOUBLE,
            Coordinates POINT NOT NULL,

            -- Property Characteristics
            BedroomsTotal INT,
            BathroomsTotalInteger INT,
            BathroomsFull INT,
            BathroomsHalf INT,
            LivingArea DECIMAL(14,2),
            AboveGradeFinishedArea DECIMAL(14,2),
            BelowGradeFinishedArea DECIMAL(14,2),
            LivingAreaUnits VARCHAR(20),
            BuildingAreaTotal DECIMAL(14,2),
            LotSizeAcres DECIMAL(20,4),
            LotSizeSquareFeet DECIMAL(20,2),
            YearBuilt INT,
            StructureType VARCHAR(100),
            ArchitecturalStyle VARCHAR(100),
            StoriesTotal INT,
            Levels LONGTEXT,
            BuildingName VARCHAR(100),
            Basement LONGTEXT,
            MLSPIN_MARKET_TIME_PROPERTY INT,

            -- Construction & Utilities
            ConstructionMaterials LONGTEXT,
            FoundationDetails LONGTEXT,
            Roof LONGTEXT,
            Heating LONGTEXT,
            Cooling LONGTEXT,
            Utilities LONGTEXT,
            Sewer LONGTEXT,
            WaterSource LONGTEXT,
            Electric LONGTEXT,

            -- Interior Features
            InteriorFeatures LONGTEXT,
            Flooring LONGTEXT,
            Appliances LONGTEXT,
            FireplaceFeatures LONGTEXT,
            FireplacesTotal INT,
            FireplaceYN BOOLEAN,
            RoomsTotal INT,

            -- Exterior & Lot Features
            ExteriorFeatures LONGTEXT,
            PatioAndPorchFeatures LONGTEXT,
            LotFeatures LONGTEXT,
            WaterfrontYN BOOLEAN,
            WaterfrontFeatures LONGTEXT,
            PoolFeatures LONGTEXT,
            PoolYN BOOLEAN,
            View LONGTEXT,
            ViewYN BOOLEAN,
            CommunityFeatures LONGTEXT,

            -- Parking
            GarageSpaces INT,
            GarageYN BOOLEAN,
            ParkingTotal INT,
            ParkingFeatures LONGTEXT,
            
            -- HOA & Financial
            AssociationFee DECIMAL(20,2),
            AssociationFeeFrequency VARCHAR(20),
            AssociationYN BOOLEAN,
            AssociationName VARCHAR(100),
            TaxAnnualAmount DECIMAL(20,2),
            TaxYear INT,
            TaxAssessedValue DECIMAL(20,2),

            -- Rental Specific
            AvailabilityDate DATE,
            MLSPIN_AvailableNow BOOLEAN,
            LeaseTerm VARCHAR(100),
            RentIncludes TEXT,
            MLSPIN_SEC_DEPOSIT DECIMAL(20,2),

            -- School Information
            ElementarySchool VARCHAR(100),
            MiddleOrJuniorSchool VARCHAR(100),
            HighSchool VARCHAR(100),
            SchoolDistrict VARCHAR(100),

            -- Media
            Media LONGTEXT,
            PhotosCount INT,
            VirtualTourURLUnbranded VARCHAR(255),
            VirtualTourURLBranded VARCHAR(255),

            -- Open House
            OpenHouseYN BOOLEAN,

            -- Agent & Office IDs (used for lookups)
            ListAgentMlsId VARCHAR(50),
            BuyerAgentMlsId VARCHAR(50),
            ListOfficeMlsId VARCHAR(50),
            BuyerOfficeMlsId VARCHAR(50),
            ListAgentDirectWorkPhone VARCHAR(50),
            ListAgentOfficePhone VARCHAR(50),
            ListOfficeName VARCHAR(100),
            ListOfficeURL VARCHAR(255),

            -- Showing
            ShowingContactName VARCHAR(100),
            ShowingContactPhone VARCHAR(50),

            -- Stored JSON data for related entities
            ListAgentData LONGTEXT,
            ListOfficeData LONGTEXT,
            BuyerAgentData LONGTEXT,
            BuyerOfficeData LONGTEXT,
            OpenHouseData LONGTEXT,

            -- Catch-all for non-standard data
            AdditionalData LONGTEXT,

            PRIMARY KEY  (id),
            UNIQUE KEY `ListingKey` (`ListingKey`),
            INDEX `source_extraction_id` (`source_extraction_id`),
            INDEX `ListingId` (`ListingId`),
            INDEX `StandardStatus` (`StandardStatus`),
            INDEX `City` (`City`),
            INDEX `YearBuilt` (`YearBuilt`),
            INDEX `ListPrice` (`ListPrice`),
            INDEX `PropertyType` (`PropertyType`),
            INDEX `BuildingName` (`BuildingName`),
            INDEX `PropertySubType` (`PropertySubType`),
            INDEX `PostalCode` (`PostalCode`),
            INDEX `BuyerAgentMlsId` (`BuyerAgentMlsId`),
            INDEX `ListOfficeMlsId` (`ListOfficeMlsId`),
            INDEX `BuyerOfficeMlsId` (`BuyerOfficeMlsId`),
            INDEX `StreetName` (`StreetName`),
            INDEX `MLSAreaMajor` (`MLSAreaMajor`),
            INDEX `MLSAreaMinor` (`MLSAreaMinor`),
            INDEX `StructureType` (`StructureType`),
            INDEX `CreationTimestamp` (`CreationTimestamp`),
            INDEX `ModificationTimestamp` (`ModificationTimestamp`),
            INDEX `OpenHouseYN` (`OpenHouseYN`),
            INDEX `FireplaceYN` (`FireplaceYN`),
            INDEX `MLSPIN_AvailableNow` (`MLSPIN_AvailableNow`),
            INDEX `ListingContractDate` (`ListingContractDate`),
            
            -- COMPOSITE INDEXES FOR PERFORMANCE --
            INDEX `status_city` (`StandardStatus`, `City`),
            INDEX `type_city` (`PropertyType`, `City`),
            INDEX `status_type_city` (`StandardStatus`, `PropertyType`, `City`),
            INDEX `status_price` (`StandardStatus`, `ListPrice`),

            -- SPATIAL INDEX FOR MAPS --
            SPATIAL KEY `location` (`Coordinates`)
        ) $charset_collate;";

        dbDelta($sql);

        if (!empty($wpdb->last_error)) {
            error_log('BME DB Error: ' . $wpdb->last_error);
        }
    }

    /**
     * Get all columns from the listings table.
     */
    public static function get_table_columns() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bme_listings';
        static $columns = null;
        if ($columns === null) {
            $columns = $wpdb->get_col("DESC $table_name", 0);
        }
        return $columns;
    }
}
