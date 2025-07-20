# Quick Security Testing Checklist

## 🔍 Manual Testing Steps

### 1. **SQL Injection Test** (2 minutes)
```bash
# In Database Browser search box, try:
' OR 1=1 --
```
✅ **Expected:** No results or error, not all listings

### 2. **XSS Test** (2 minutes)
Create extraction profile with title:
```html
Test <script>alert('XSS')</script>
```
✅ **Expected:** Script tags shown as text, no popup

### 3. **Access Control Test** (1 minute)
Log out and try accessing:
```
/wp-admin/admin.php?page=bme-database-browser
```
✅ **Expected:** Login required message

### 4. **Encrypted Storage Test** (1 minute)
Check database `wp_options` table:
```sql
SELECT option_name, LENGTH(option_value) as size 
FROM wp_options 
WHERE option_name LIKE '%bme%credential%';
```
✅ **Expected:** See `bme_api_credentials_encrypted`, not plain `bme_pro_api_credentials`

### 5. **Security Headers Test** (1 minute)
1. Open Chrome DevTools → Network tab
2. Visit any plugin admin page
3. Click on the page request
4. Check Response Headers

✅ **Expected:** See these headers:
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: SAMEORIGIN`
- `X-XSS-Protection: 1; mode=block`

### 6. **Audit Log Test** (1 minute)
Check security log:
```sql
SELECT * FROM wp_bme_security_log 
ORDER BY timestamp DESC LIMIT 5;
```
✅ **Expected:** Recent security events logged

## 🚀 Quick Automated Test

1. Copy `security-test.php` to WordPress root
2. Run:
```bash
wp eval-file security-test.php
```
Or visit: `https://yoursite.com/security-test.php` (delete after!)

## 📊 Security Status Dashboard

| Security Feature | How to Verify | Status |
|-----------------|---------------|---------|
| SQL Injection Protection | Try `' OR 1=1 --` in search | ⬜ |
| XSS Prevention | Add `<script>` in form fields | ⬜ |
| Access Control | Access admin as non-admin user | ⬜ |
| Encrypted Storage | Check database for encrypted creds | ⬜ |
| Input Validation | Enter invalid cities `Boston@#$` | ⬜ |
| Security Headers | Check DevTools Network tab | ⬜ |
| Audit Logging | Query security_log table | ⬜ |
| CSRF Protection | Check for nonces in forms | ⬜ |

## 🔴 Red Flags to Watch For

1. **Plain text passwords in database**
2. **JavaScript alerts appearing** 
3. **Accessing admin pages without login**
4. **SQL errors in debug.log**
5. **Missing security headers**
6. **Empty security audit log**

## 🟢 Good Signs

1. **All tests show "PASS"**
2. **Security events being logged**
3. **API credentials encrypted**
4. **Invalid input rejected**
5. **Proper error messages (not raw SQL)**

## 📝 After Testing

- [ ] Delete test files
- [ ] Disable WP_DEBUG in production
- [ ] Review and clear old audit logs
- [ ] Document any issues found
- [ ] Update credentials if needed

## 🆘 If Issues Found

1. **Check error logs:** `/wp-content/debug.log`
2. **Review code changes** in security helper
3. **Verify plugin files** are updated
4. **Clear caches** if needed
5. **Re-run tests** after fixes