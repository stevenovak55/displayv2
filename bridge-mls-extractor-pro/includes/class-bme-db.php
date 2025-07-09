<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class BME_DB
 *
 * Handles database creation and schema management.
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

        // Added a new `Coordinates` column with a SPATIAL index for high-performance geographic queries.
        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            source_extraction_id BIGINT(20) UNSIGNED NOT NULL,
            ListingKey VARCHAR(128) NOT NULL,
            ListingId VARCHAR(50) NOT NULL,
            ModificationTimestamp DATETIME,
            CreationTimestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            StandardStatus VARCHAR(50),
            PropertyType VARCHAR(50),
            PropertySubType VARCHAR(50),
            ListPrice DECIMAL(20,2),
            ClosePrice DECIMAL(20,2),
            StreetNumber VARCHAR(50),
            StreetName VARCHAR(100),
            StreetNumberNumeric INT,
            UnitNumber VARCHAR(30),
            City VARCHAR(100),
            StateOrProvince VARCHAR(50),
            PostalCode VARCHAR(20),
            CountyOrParish VARCHAR(100),
            Country VARCHAR(5),
            BedroomsTotal INT,
            BathroomsTotalInteger INT,
            BathroomsFull INT,
            BathroomsHalf INT,
            LivingArea DECIMAL(14,2),
            LotSizeAcres DECIMAL(20,4),
            LotSizeSquareFeet DECIMAL(20,2),
            YearBuilt INT,
            Latitude DOUBLE,
            Longitude DOUBLE,
            Coordinates POINT,
            PublicRemarks LONGTEXT,
            ListAgentMlsId VARCHAR(50),
            BuyerAgentMlsId VARCHAR(50),
            ListOfficeMlsId VARCHAR(50),
            BuyerOfficeMlsId VARCHAR(50),
            CloseDate DATETIME,
            PurchaseContractDate DATETIME,
            StatusChangeTimestamp DATETIME,
            Media LONGTEXT,
            PhotosCount INT,
            AssociationFee DECIMAL(20,2),
            AssociationFeeFrequency VARCHAR(20),
            BuildingName VARCHAR(100),
            StructureType VARCHAR(100),
            ArchitecturalStyle VARCHAR(100),
            ConstructionMaterials LONGTEXT,
            GarageSpaces INT,
            ParkingTotal INT,
            ParkingFeatures LONGTEXT,
            MLSAreaMajor VARCHAR(100),
            MLSAreaMinor VARCHAR(100),
            TaxAnnualAmount DECIMAL(20,2),
            TaxYear INT,
            WaterfrontYN BOOLEAN,
            PoolFeatures LONGTEXT,
            Heating LONGTEXT,
            Cooling LONGTEXT,
            ElementarySchool VARCHAR(100),
            MiddleOrJuniorSchool VARCHAR(100),
            HighSchool VARCHAR(100),
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
