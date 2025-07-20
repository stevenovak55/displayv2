# Security Testing Guide for Bridge MLS Extractor Pro

This guide provides step-by-step instructions to verify that all security implementations are working correctly.

## Prerequisites

- WordPress installation with the plugin activated
- Admin access to WordPress
- Browser developer tools
- (Optional) Database access tool like phpMyAdmin

## 1. SQL Injection Testing

### Test 1.1: API Filter Injection
1. Go to **MLS Extractions** → **Add New**
2. In browser dev tools, monitor Network tab
3. Try entering malicious city names:
   - `Boston'; DROP TABLE wp_users; --`
   - `Cambridge' OR 1=1 --`
4. Save the extraction profile

**Expected Result:** 
- Cities with invalid characters should be filtered out
- Check database - only valid city names should be saved
- No SQL errors in WordPress debug log

### Test 1.2: Search Injection
1. Go to **Database Browser**
2. Try searching with SQL injection attempts:
   - `' OR 1=1 --`
   - `"; DELETE FROM wp_bme_listings WHERE 1=1; --`
3. Monitor the AJAX requests in Network tab

**Expected Result:**
- Search should return no results or safe results
- No database errors
- Prepared statements visible in query logs

## 2. XSS Prevention Testing

### Test 2.1: Admin Interface XSS
1. Create extraction profile with XSS attempts in fields:
   - Profile Title: `<script>alert('XSS')</script>`
   - Cities: `Boston<img src=x onerror=alert('XSS')>`
   - Agent ID: `<iframe src="javascript:alert('XSS')"></iframe>`
2. Save and view the extraction list

**Expected Result:**
- All HTML/JavaScript should be displayed as plain text
- No alerts or script execution
- Check page source - all tags should be escaped

### Test 2.2: AJAX Response XSS
1. Open browser console
2. Navigate to extraction statistics
3. Check the rendered HTML in developer tools

**Expected Result:**
- All dynamic content properly escaped
- No inline JavaScript execution

## 3. Access Control Testing

### Test 3.1: Capability Checks
1. Create a new WordPress user with "Author" role
2. Log in as this user
3. Try to access plugin admin pages directly:
   - `/wp-admin/edit.php?post_type=bme_extraction`
   - `/wp-admin/admin.php?page=bme-database-browser`

**Expected Result:**
- Should see "Sorry, you are not allowed to access this page"
- Check `wp_bme_security_log` table for unauthorized access attempts

### Test 3.2: AJAX Endpoint Protection
1. Open browser console
2. Try to call AJAX endpoints without proper permissions:
```javascript
jQuery.post(ajaxurl, {
    action: 'bme_get_extraction_stats',
    extraction_id: 1
});
```

**Expected Result:**
- Should receive "Permission denied" error
- Security log should record the attempt

## 4. Encrypted Storage Testing

### Test 4.1: API Credentials Encryption
1. Go to **Settings** → **BME Pro Settings**
2. Enter API credentials and save
3. Check database `wp_options` table

**Expected Result:**
- Look for `bme_api_credentials_encrypted` option
- Token should be encrypted (base64 encoded string)
- Old `bme_pro_api_credentials` option should be removed
- `bme_encryption_key` should exist

### Test 4.2: Credential Migration
1. If you have existing credentials, check they still work
2. Run an extraction to verify API connection

**Expected Result:**
- Extraction should work with encrypted credentials
- No plain text credentials in database

## 5. Input Validation Testing

### Test 5.1: City Name Validation
1. Add extraction profile with invalid cities:
   - `Boston123` (numbers)
   - `Cambridge@#$` (special chars)
   - `Salem-Village` (valid with hyphen)
   - `O'Fallon` (valid with apostrophe)

**Expected Result:**
- Invalid cities should be filtered out
- Valid cities with hyphens/apostrophes should be saved

### Test 5.2: Numeric Input Validation
1. Try entering invalid lookback months:
   - `-5` (negative)
   - `999` (too large)
   - `abc` (non-numeric)

**Expected Result:**
- Negative values converted to 0
- Values over 120 capped at 120
- Non-numeric values converted to 0

## 6. Security Headers Testing

### Test 6.1: Admin Page Headers
1. Navigate to any plugin admin page
2. Open Network tab in dev tools
3. Check response headers for any plugin page

**Expected Result:**
Should see these headers:
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: SAMEORIGIN`
- `X-XSS-Protection: 1; mode=block`
- `Referrer-Policy: strict-origin-when-cross-origin`

### Test 6.2: Header Logging
1. Check `wp_bme_security_log` table
2. Look for `security_headers_applied` events

**Expected Result:**
- Should see log entries when accessing plugin pages
- Screen ID and user agent recorded

## 7. Security Audit Log Testing

### Test 7.1: Log Creation
1. Check if `wp_bme_security_log` table exists
2. Perform various actions:
   - Update API credentials
   - Save extraction profile
   - Attempt unauthorized access

**Expected Result:**
- All actions should create log entries
- Timestamps, user IDs, and IP addresses recorded

### Test 7.2: Log Query
Run this SQL query to view recent security events:
```sql
SELECT * FROM wp_bme_security_log 
ORDER BY timestamp DESC 
LIMIT 20;
```

## 8. CSRF Protection Testing

### Test 8.1: Nonce Verification
1. Try to submit forms without valid nonce
2. Use browser console to remove nonce field:
```javascript
jQuery('[name="bme_extraction_nonce"]').remove();
```
3. Try to save extraction profile

**Expected Result:**
- Form submission should fail
- "Security check failed" message

## Testing Checklist

- [ ] SQL injection attempts blocked
- [ ] XSS attempts escaped properly
- [ ] Unauthorized access denied and logged
- [ ] API credentials encrypted in database
- [ ] Invalid input filtered/sanitized
- [ ] Security headers present on admin pages
- [ ] Audit log recording all security events
- [ ] CSRF tokens validated on all forms

## Automated Testing Script

Create this test file as `test-security.php` in plugin directory:

```php
<?php
// Security Test Suite for BME Pro
// Run from command line: wp eval-file path/to/test-security.php

echo "Starting BME Pro Security Tests...\n\n";

// Test 1: Check encrypted credentials
$creds = get_option('bme_api_credentials_encrypted');
$plain_creds = get_option('bme_pro_api_credentials');
echo "1. Encrypted Credentials: " . (!empty($creds) ? "✓ PASS" : "✗ FAIL") . "\n";
echo "   Plain Credentials Removed: " . (empty($plain_creds) ? "✓ PASS" : "✗ FAIL") . "\n";

// Test 2: Check security tables
global $wpdb;
$security_log_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}bme_security_log'");
echo "2. Security Log Table: " . ($security_log_exists ? "✓ PASS" : "✗ FAIL") . "\n";

// Test 3: Test input validation
$validator = new BME_Security_Helper();
$test_data = [
    'cities' => 'Boston, Cambridge@#$, Salem-Village',
    'states' => 'MA, XXX, CT',
    'lookback_months' => '999'
];
$sanitized = BME_Security_Helper::sanitize_extraction_profile($test_data);
echo "3. Input Validation:\n";
echo "   Cities filtered: " . (strpos($sanitized['cities'], '@#$') === false ? "✓ PASS" : "✗ FAIL") . "\n";
echo "   Invalid state removed: " . (strpos($sanitized['states'], 'XXX') === false ? "✓ PASS" : "✗ FAIL") . "\n";
echo "   Lookback capped: " . ($sanitized['lookback_months'] <= 120 ? "✓ PASS" : "✗ FAIL") . "\n";

// Test 4: Check SQL injection protection
$test_injection = "' OR 1=1 --";
$safe_query = $wpdb->prepare("SELECT * FROM {$wpdb->prefix}bme_listings WHERE city = %s", $test_injection);
echo "4. SQL Injection Protection: " . (strpos($safe_query, "' OR 1=1 --") === false ? "✓ PASS" : "✗ FAIL") . "\n";

echo "\nSecurity tests completed.\n";
```

## Common Issues and Solutions

### Issue: Credentials not working after update
**Solution:** Re-enter credentials in settings to trigger encryption

### Issue: Security headers not appearing
**Solution:** Check if you're on a plugin-specific admin page

### Issue: Validation too strict
**Solution:** Adjust regex patterns in `BME_Security_Helper::sanitize_extraction_profile()`

### Issue: Audit log growing too large
**Solution:** Implement log rotation or cleanup old entries:
```sql
DELETE FROM wp_bme_security_log 
WHERE timestamp < DATE_SUB(NOW(), INTERVAL 30 DAY);
```

## Security Monitoring

Set up these WordPress debug constants in `wp-config.php` for testing:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

Monitor the debug log at `/wp-content/debug.log` for any security-related errors.

## Reporting Security Issues

If you discover any security vulnerabilities during testing:
1. Document the issue with steps to reproduce
2. Check the security audit log for related entries
3. Review the specific security implementation in the code
4. Apply additional fixes as needed

Remember to test in a staging environment first before applying to production!