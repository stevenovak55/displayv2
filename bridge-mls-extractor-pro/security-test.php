<?php
/**
 * Security Test Script for Bridge MLS Extractor Pro
 * 
 * Usage: 
 * 1. Place this file in your WordPress root directory
 * 2. Run: wp eval-file security-test.php
 * Or access via browser (not recommended for production)
 */

// Ensure WordPress is loaded
if (!defined('ABSPATH')) {
    require_once('wp-load.php');
}

// Only allow admins to run this
if (!current_user_can('manage_options')) {
    die('Access denied. Admin privileges required.');
}

echo "<pre>";
echo "===========================================\n";
echo "Bridge MLS Extractor Pro Security Test Suite\n";
echo "===========================================\n\n";

$tests_passed = 0;
$tests_failed = 0;

// Test 1: Check if security helper exists
echo "TEST 1: Security Helper Class\n";
if (class_exists('BME_Security_Helper')) {
    echo "✅ PASS: Security helper class loaded\n";
    $tests_passed++;
} else {
    echo "❌ FAIL: Security helper class not found\n";
    $tests_failed++;
}
echo "\n";

// Test 2: Check encrypted credentials
echo "TEST 2: Encrypted API Credentials\n";
$encrypted_creds = get_option('bme_api_credentials_encrypted');
$plain_creds = get_option('bme_pro_api_credentials');

if (!empty($encrypted_creds)) {
    echo "✅ PASS: Encrypted credentials found\n";
    $tests_passed++;
} else {
    echo "⚠️  WARN: No encrypted credentials found (may not be configured yet)\n";
}

if (empty($plain_creds)) {
    echo "✅ PASS: Plain text credentials removed\n";
    $tests_passed++;
} else {
    echo "❌ FAIL: Plain text credentials still exist - security risk!\n";
    $tests_failed++;
}

if (get_option('bme_encryption_key')) {
    echo "✅ PASS: Encryption key exists\n";
    $tests_passed++;
} else {
    echo "❌ FAIL: No encryption key found\n";
    $tests_failed++;
}
echo "\n";

// Test 3: Database security tables
echo "TEST 3: Security Database Tables\n";
global $wpdb;
$security_log_table = $wpdb->prefix . 'bme_security_log';
$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$security_log_table'");

if ($table_exists) {
    echo "✅ PASS: Security log table exists\n";
    $tests_passed++;
    
    // Check if logging works
    BME_Security_Logger::log('security_test', ['test' => 'automated_security_check']);
    $log_count = $wpdb->get_var("SELECT COUNT(*) FROM $security_log_table WHERE event_type = 'security_test'");
    
    if ($log_count > 0) {
        echo "✅ PASS: Security logging functional\n";
        $tests_passed++;
    } else {
        echo "❌ FAIL: Security logging not working\n";
        $tests_failed++;
    }
} else {
    echo "❌ FAIL: Security log table missing\n";
    $tests_failed++;
}
echo "\n";

// Test 4: Input validation
echo "TEST 4: Input Validation & Sanitization\n";
$test_data = [
    'cities' => 'Boston, <script>alert("XSS")</script>, Salem-Village, Cambridge@#$%',
    'states' => 'MA, XX, CT, 123',
    'lookback_months' => '999',
    'statuses' => ['Active', 'Closed', '<script>alert("XSS")</script>']
];

$sanitized = BME_Security_Helper::sanitize_extraction_profile($test_data);

// Check cities
if (strpos($sanitized['cities'], '<script>') === false && 
    strpos($sanitized['cities'], '@#$%') === false &&
    strpos($sanitized['cities'], 'Salem-Village') !== false) {
    echo "✅ PASS: City validation working correctly\n";
    $tests_passed++;
} else {
    echo "❌ FAIL: City validation issues\n";
    $tests_failed++;
}

// Check states
if ($sanitized['states'] === 'MA,CT' && strpos($sanitized['states'], 'XX') === false) {
    echo "✅ PASS: State validation working correctly\n";
    $tests_passed++;
} else {
    echo "❌ FAIL: State validation issues\n";
    $tests_failed++;
}

// Check lookback months
if ($sanitized['lookback_months'] === 120) {
    echo "✅ PASS: Numeric validation capped correctly\n";
    $tests_passed++;
} else {
    echo "❌ FAIL: Numeric validation not working\n";
    $tests_failed++;
}

// Check status validation
if (count($sanitized['statuses']) === 2 && !in_array('<script>alert("XSS")</script>', $sanitized['statuses'])) {
    echo "✅ PASS: Status validation working\n";
    $tests_passed++;
} else {
    echo "❌ FAIL: Status validation issues\n";
    $tests_failed++;
}
echo "\n";

// Test 5: SQL Injection Protection
echo "TEST 5: SQL Injection Protection\n";
$injection_attempts = [
    "' OR 1=1 --",
    "'; DROP TABLE users; --",
    '" OR ""="',
    "' UNION SELECT * FROM wp_users --"
];

$all_safe = true;
foreach ($injection_attempts as $attempt) {
    $prepared = $wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE post_title = %s", $attempt);
    if (strpos($prepared, 'OR 1=1') !== false || 
        strpos($prepared, 'DROP TABLE') !== false ||
        strpos($prepared, 'UNION SELECT') !== false) {
        $all_safe = false;
        break;
    }
}

if ($all_safe) {
    echo "✅ PASS: SQL injection attempts properly escaped\n";
    $tests_passed++;
} else {
    echo "❌ FAIL: SQL injection vulnerability detected\n";
    $tests_failed++;
}
echo "\n";

// Test 6: XSS Protection
echo "TEST 6: XSS Protection\n";
$xss_attempts = [
    '<script>alert("XSS")</script>',
    '<img src=x onerror=alert("XSS")>',
    'javascript:alert("XSS")',
    '<iframe src="javascript:alert(\'XSS\')"></iframe>'
];

$all_escaped = true;
foreach ($xss_attempts as $xss) {
    $escaped = esc_html($xss);
    if (strpos($escaped, '<script>') !== false || 
        strpos($escaped, 'onerror=') !== false ||
        strpos($escaped, 'javascript:') !== false && strpos($escaped, 'javascript:') === 0) {
        $all_escaped = false;
        break;
    }
}

if ($all_escaped) {
    echo "✅ PASS: XSS attempts properly escaped\n";
    $tests_passed++;
} else {
    echo "❌ FAIL: XSS vulnerability detected\n";
    $tests_failed++;
}
echo "\n";

// Test 7: Check for secure random token generation
echo "TEST 7: Secure Token Generation\n";
if (method_exists('BME_Security_Helper', 'generate_secure_token')) {
    $token1 = BME_Security_Helper::generate_secure_token();
    $token2 = BME_Security_Helper::generate_secure_token();
    
    if (strlen($token1) === 32 && $token1 !== $token2) {
        echo "✅ PASS: Secure token generation working\n";
        $tests_passed++;
    } else {
        echo "❌ FAIL: Token generation issues\n";
        $tests_failed++;
    }
} else {
    echo "⚠️  SKIP: Token generation method not found\n";
}
echo "\n";

// Test 8: Capability checks
echo "TEST 8: Access Control\n";
if (function_exists('BME_Security_Helper::check_capability')) {
    // This should pass for admin
    if (BME_Security_Helper::check_capability('manage_options')) {
        echo "✅ PASS: Capability check working\n";
        $tests_passed++;
    } else {
        echo "❌ FAIL: Capability check failed for admin\n";
        $tests_failed++;
    }
} else {
    echo "⚠️  SKIP: Capability check method not accessible\n";
}
echo "\n";

// Summary
echo "===========================================\n";
echo "TEST SUMMARY\n";
echo "===========================================\n";
echo "Tests Passed: $tests_passed\n";
echo "Tests Failed: $tests_failed\n";
echo "Total Tests: " . ($tests_passed + $tests_failed) . "\n\n";

if ($tests_failed === 0) {
    echo "✅ ALL SECURITY TESTS PASSED! 🎉\n";
} else {
    echo "❌ SECURITY ISSUES DETECTED! Please review failed tests.\n";
}

echo "\nRECOMMENDATIONS:\n";
echo "1. Delete this test file after running\n";
echo "2. Review security audit log regularly\n";
echo "3. Keep WordPress and plugins updated\n";
echo "4. Use strong API credentials\n";
echo "5. Regularly backup your database\n";

echo "</pre>";

// Clean up test log entry
$wpdb->delete($security_log_table, ['event_type' => 'security_test']);