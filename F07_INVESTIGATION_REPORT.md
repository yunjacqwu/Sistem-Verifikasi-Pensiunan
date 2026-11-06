# F-07: PDO Error Disclosure Investigation Report

**Date:** 2026-11-06  
**Project:** Absensi Pensiunan  
**Target Files:** `users/dashboard.php`, `config/koneksi.php`, `config/bootstrap.php`, related error handling paths  
**Investigation Type:** READ-ONLY Static Analysis + Log Review  

---

## Executive Summary

The application implements **defensive error handling for PDO exceptions** at critical boundaries (database connection, dashboard data load, login flows, document upload). Sensitive details are logged server-side; user-facing responses are sanitized. However, **several risk factors identified**:

1. **Inconsistent catch block specificity** — Some files catch generic `Exception` instead of `PDOException`
2. **Log file web accessibility** — `logs/` directory has `.htaccess` protection, but logs contain error stack traces with file paths
3. **Error log may retain sensitive patterns** — Historical log shows variable names, PIDs, query contexts (old app versions)
4. **Unencrypted log storage** — Error logs written to plaintext files without encryption
5. **Limited audit trail for PDO failures** — No structured logging to audit_logs table for database errors

**Overall Status:** **PARTIAL** — Error handling strategy is sound; implementation gaps and logging posture need hardening.

---

## Detailed Findings

### 1. Configuration & Bootstrap (Core Layer)

**File:** `config/bootstrap.php` (Lines 1–56)

**Error Handling Configuration:**
```php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/error.log');
```

**Assessment:**
- ✅ `display_errors` disabled → errors not exposed to browser
- ✅ `error_reporting(E_ALL)` → catches all error types
- ✅ `log_errors` enabled → errors written to file
- ⚠️ Log file written to plaintext, unencrypted
- ⚠️ No log rotation configured

**Risk:** Server errors (PHP warnings, deprecated calls, fatals) logged with full stack traces, file paths, and function names. Historical logs (error.log sampled lines 1–50) confirm this contains:
- Full file paths (`C:\xampp\htdocs\absensi_pensiunan_dev\...`)
- Line numbers
- Function names
- Variable names

---

### 2. Database Connection (Entry Point)

**File:** `config/koneksi.php` (Lines 1–31)

**PDO Configuration & Error Handling:**
```php
try {
    $db = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    die('Terjadi kesalahan sistem. Silakan coba beberapa saat lagi.');
}
```

**Assessment:**
- ✅ `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION` → converts errors to exceptions
- ✅ `PDO::ATTR_EMULATE_PREPARES => false` → uses native prepared statements (prevents SQL injection)
- ✅ `catch (PDOException $e)` → specific exception type
- ✅ `error_log()` → exception message logged server-side
- ✅ User-facing response is generic (no SQL/schema disclosure)
- ⚠️ Full exception message logged; may include:
  - Database server hostname
  - Connection parameters (if error reveals them)
  - Driver specifics

**Risk Level:** LOW — Exception caught, logged, and sanitized response sent. However, log itself is readable if compromised.

---

### 3. User Dashboard (Target File)

**File:** `users/dashboard.php` (Lines 98–103)

**PDO Exception Handling:**
```php
try {
    // Data retrieval queries (lines 16–96)
    // Prepared statements with parameter binding
    $stmt = $db->prepare("SELECT * FROM pensiunan WHERE npk = :npk LIMIT 1");
    $stmt->execute(['npk' => $npk]);
    // ... more queries ...
} catch (PDOException $e) {
    // Detail exception tetap di server log; jangan tampilkan SQL/schema ke pengguna.
    error_log('Dashboard data load failed: ' . $e->getMessage());
    http_response_code(500);
    die('Gagal memuat data. Silakan coba kembali beberapa saat lagi.');
}
```

**Assessment:**
- ✅ `catch (PDOException $e)` → specific type
- ✅ `error_log()` → logged server-side only
- ✅ http_response_code(500) → indicates server error
- ✅ User-facing message is generic and non-descriptive
- ✅ Comment explicitly states policy: "Detail exception tetap di server log; jangan tampilkan SQL/schema ke pengguna"
- ✅ No query details, table names, or schema information leaked to HTML
- ⚠️ Exception message may contain query context or MySQL-specific error codes (logged)

**Risk Level:** LOW — Implementation matches stated policy.

---

### 4. User Login & Authentication

**File:** `users/proses_login_user.php` (Lines 40–82)

**PDO Exception Handling:**
```php
try {
    $stmt = $db->prepare("SELECT npk, pin_hash, status, is_first_login FROM pensiunan WHERE npk = :npk LIMIT 1");
    $stmt->execute(['npk' => $npk]);
    $user = $stmt->fetch();
    
    if (!$user) {
        rate_limit_hit($db, 'user', 5, 600);
        header("Location: login.php?error=NPK atau PIN yang Anda masukkan salah.");
        exit;
    }
    // ... authentication logic ...
} catch (PDOException $e) {
    header("Location: login.php?error=Terjadi kesalahan sistem. Silakan coba lagi.");
    exit;
}
```

**Assessment:**
- ✅ `catch (PDOException $e)` → specific exception type
- ✅ No exception details logged in visible response
- ✅ Generic error message to user
- ✅ No error_log() call in catch block
- ⚠️ **Exception is silently swallowed** — not logged to error log; may hide real database issues
- ⚠️ User is redirected without feedback about what failed

**Risk Level:** MEDIUM — Silent failures may mask database connection issues in production.

**Recommendation:** Add `error_log('Login PDO error: ' . $e->getMessage());` before redirect to maintain audit trail.

---

### 5. Admin Login & Authentication

**File:** `admin/proses_login_admin.php` (Lines 32–55)

**PDO Exception Handling:**
```php
try {
    $stmt = $db->prepare("SELECT id, username, pass_hash, nama FROM admin_sistem WHERE username = :username LIMIT 1");
    $stmt->execute(['username' => $username]);
    $admin = $stmt->fetch();
    
    if (!$admin || !password_verify($password, $admin['pass_hash'])) {
        rate_limit_hit($db, 'admin', 5, 600);
        header("Location: login.php?error=Username atau password salah.");
        exit;
    }
    // ... session setup ...
} catch (PDOException $e) {
    header("Location: login.php?error=Terjadi kesalahan sistem. Silakan coba lagi.");
    exit;
}
```

**Assessment:**
- ✅ `catch (PDOException $e)` → specific exception type
- ✅ Generic error message
- ⚠️ **Exception not logged** — same as user login (silent failure)

**Risk Level:** MEDIUM — Same as user login.

---

### 6. Change PIN Endpoint (JSON Response)

**File:** `users/proses_ganti_pin.php` (Lines 45–70)

**PDO Exception Handling:**
```php
try {
    $pin_hash = password_hash($pin_baru, PASSWORD_BCRYPT);
    $stmt = $db->prepare("UPDATE pensiunan SET pin_hash = :pin_hash, is_first_login = 0 WHERE npk = :npk");
    $stmt->execute(['pin_hash' => $pin_hash, 'npk' => $npk]);
    // ... audit log ...
    echo json_encode(['sukses' => true]);
    exit;
} catch (PDOException $e) {
    echo json_encode(['sukses' => false, 'pesan' => 'Terjadi kesalahan sistem.']);
    exit;
}
```

**Assessment:**
- ✅ `catch (PDOException $e)` → specific exception type
- ✅ JSON response is generic
- ✅ Exception details not exposed in JSON
- ⚠️ **Exception not logged** — silent failure, no audit trail

**Risk Level:** MEDIUM — No server-side logging of database errors.

---

### 7. Document Upload

**File:** `users/upload_dokumen.php` (Lines 32–91)

**PDO Exception Handling (Two Blocks):**

**Block 1 (Upload processing, lines 32–76):**
```php
try {
    // File reading, encryption, and DB operations
    $cek = $db->prepare("SELECT id, file FROM dokumen WHERE npk = :npk AND jns_dok = :jns_dok LIMIT 1");
    $cek->execute(['npk' => $npk, 'jns_dok' => $jns_dok]);
    // ... INSERT or UPDATE ...
    // ... audit log ...
} catch (Exception $e) {
    $pesan_error = "Gagal memproses dokumen. Silakan coba lagi.";
}
```

**Assessment:**
- ⚠️ **Catches generic `Exception` instead of `PDOException`** — may mask non-PDO errors but will catch PDO exceptions
- ✅ User-facing error is generic
- ⚠️ No error_log() call
- ⚠️ Scope includes file I/O and encryption; mixing concerns

**Risk Level:** MEDIUM — Overly broad exception handling masks error source.

**Block 2 (Status retrieval, lines 82–91):**
```php
try {
    $stmt = $db->prepare("SELECT jns_dok, status FROM dokumen WHERE npk = :npk");
    $stmt->execute(['npk' => $npk]);
    $list_dok = [];
    foreach ($stmt->fetchAll() as $row) {
        $list_dok[$row['jns_dok']] = $row['status'];
    }
} catch (PDOException $e) {
    $list_dok = [];
}
```

**Assessment:**
- ✅ `catch (PDOException $e)` → specific type
- ✅ Graceful fallback: `$list_dok = []` (empty array rendered as "Belum ada")
- ✅ Exception not logged (acceptable for read-only operation with fallback)

**Risk Level:** LOW — Graceful degradation implemented.

---

### 8. Admin Dashboard

**File:** `admin/dashboard.php` (Lines 15–98)

**PDO Exception Handling:**
```php
try {
    // Multiple aggregate queries (COUNT, JOIN)
    $total = $db->query("SELECT COUNT(*) FROM pensiunan WHERE status = 'Aktif'")->fetchColumn();
    $menunggu = $db->query("...")->fetchColumn();
    // ... more queries ...
} catch (PDOException $e) {
    die("Gagal memuat data dashboard.");
}
```

**Assessment:**
- ✅ `catch (PDOException $e)` → specific type
- ⚠️ Generic error message: "Gagal memuat data dashboard."
- ⚠️ **No error_log() call** — silent failure, no server-side audit trail
- ⚠️ Uses `$db->query()` (unprepared) instead of prepared statements for some queries → SQL injection risk (though queries appear parameterless)

**Risk Level:** MEDIUM — Silently fails without logging.

---

### 9. Data Diri Page

**File:** `users/data_diri.php` (Lines 44–46)

**PDO Exception Handling:**
```php
} catch (PDOException $e) {
    die("Gagal memuat data.");
}
```

**Assessment:**
- ✅ `catch (PDOException $e)` → specific type
- ✅ Generic error message
- ⚠️ No error_log() call

**Risk Level:** LOW — Page is read-only; silent failure acceptable. However, no logging means diagnosability issue.

---

### 10. Log File Security

**File:** `logs/.htaccess`

**Content:**
```apache
# Deny direct web access to logs (Apache 2.4)
Require all denied
```

**Assessment:**
- ✅ `.htaccess` blocks direct HTTP access to logs
- ✅ Applies to all files in `logs/` directory
- ✅ Compatible with Apache 2.4+
- ⚠️ **Depends on Apache module loading and configuration**; could be bypassed if:
  - `.htaccess` processing disabled in `httpd.conf`
  - Server runs Nginx (not Apache)
  - Misconfigured virtual host
- ⚠️ **Logs stored as plaintext on filesystem**; accessible via:
  - Server command line / SSH access
  - Directory traversal vulnerabilities
  - Server compromise
  - Backup files

**Risk Level:** MEDIUM — Web access blocked; filesystem access remains a risk.

---

### 11. Error Log Contents (Sample Review)

**File:** `logs/error.log` (First 100 lines)

**Observations:**

**Old entries (July 2026):**
- Lines 1–50: Old application (`absensi_pensiunan`, not `absensi_pensiunan_dev`)
- Patterns: "PHP Warning", "PHP Deprecated", "PHP Fatal error"
- Exposed data:
  - Full file paths: `C:\xampp\htdocs\absensi_pensiunan\test_error.php`
  - Line numbers: `line 5`, `line 6`
  - Variable names: `$variabel_yang_tidak_ada`
  - Deprecated function names
  - Stack traces with function names

**Recent entries (Nov 2026):**
- Lines 67–100: Current development (`proses_absen.php` lines 61, 64)
- Error: "Call to undefined function imagecreatefromstring()"
- Exposed:
  - Full file path: `C:\xampp\htdocs\absensi_pensiunan_dev\users\proses_absen.php`
  - Line numbers (line 61, 64)
  - Function name that failed
  - Stack trace

**Assessment:**
- ✅ No SQL queries visible (prepared statements protect logs)
- ✅ No credentials visible
- ✅ No encrypted data contents
- ⚠️ Full paths expose directory structure and application layout
- ⚠️ Line numbers enable targeted exploitation
- ⚠️ Function names and variable names aid reverse engineering

**Risk Level:** MEDIUM — Information disclosure via server-side logs.

---

## Security Gaps Summary

| # | Category | Finding | Severity | Status |
|---|----------|---------|----------|--------|
| 1 | Catch Specificity | Some files catch generic `Exception` instead of `PDOException` | MEDIUM | upload_dokumen.php (line 74) |
| 2 | Silent Failures | Login endpoints don't log PDO exceptions | MEDIUM | proses_login_user.php, proses_login_admin.php, proses_ganti_pin.php |
| 3 | Dashboard Failures | Admin dashboard doesn't log PDO exceptions | MEDIUM | admin/dashboard.php (line 96) |
| 4 | Log Encryption | Error logs stored as plaintext | MEDIUM | logs/error.log |
| 5 | Log Rotation | No log rotation configured | LOW | bootstrap.php |
| 6 | Path Disclosure | File paths exposed in error logs | MEDIUM | logs/error.log (all entries) |
| 7 | Audit Trail | No structured audit logging for database errors | LOW | All catch blocks |
| 8 | Log Access | Depends on `.htaccess` (Apache-specific, can be bypassed) | MEDIUM | logs/.htaccess |

---

## Positive Findings (Security Controls In Place)

| # | Control | Status |
|---|---------|--------|
| 1 | User-facing error messages are generic, non-descriptive | ✅ PASS |
| 2 | PDO error mode set to EXCEPTION (not SILENT/WARNING) | ✅ PASS |
| 3 | Prepared statements with parameter binding used throughout | ✅ PASS |
| 4 | Sensitive exception details logged server-side (not sent to client) | ✅ PASS |
| 5 | Display errors disabled (`display_errors = 0`) | ✅ PASS |
| 6 | Error reporting enabled (`error_reporting = E_ALL`) | ✅ PASS |
| 7 | Logs directory protected with `.htaccess` | ✅ PASS (Apache-specific) |
| 8 | No SQL queries or schema details in user responses | ✅ PASS |

---

## Data Sensitivity in Logs

**Sensitive data that COULD be exposed if logging is compromised:**

- **Via error messages:** None currently (exception messages are logged, not displayed)
- **Via stack traces:** Function names, file paths, line numbers, parameter variable names
- **Via application context:** Database structure hints (table names in SQL errors—not yet observed, but possible)
- **Historical/indirect:** Old application logs contain internal structure hints

**Data NOT at risk:**
- ✅ User credentials (hashed with bcrypt, never logged)
- ✅ PIN codes (never logged)
- ✅ Encrypted user data (stored encrypted)
- ✅ SQL queries (parameterized queries don't expose data in logs)

---

## Testing Limitations (READ-ONLY Investigation)

The following cannot be verified without runtime testing:

1. **Database Connection Failure Response**  
   - Cannot trigger actual DB connection failure (requires DB shutdown or network disruption)
   - Only static review of catch block possible

2. **Query Execution Failures**  
   - Cannot simulate SQL errors (e.g., missing column, constraint violation) without modifying DB
   - Only review of exception handling code possible

3. **Log File Access via Web**  
   - Cannot verify `.htaccess` effectiveness without HTTP requests
   - `.htaccess` processing depends on Apache configuration (not inspected)

4. **Log Rotation & Cleanup**  
   - Cannot verify log lifecycle without running application over time
   - Only static INI config review possible (no rotation configured)

5. **Encrypted Log Content**  
   - Cannot verify if error messages are logged before or after encryption
   - Code review shows exception messages are logged (unencrypted)

6. **Session/Request Isolation**  
   - Cannot verify that PDO errors don't leak between requests
   - Static analysis shows each request has its own exception handling

---

## Recommendations (Minimal Implementation)

### Priority 1: Add Missing Server-Side Logging (HIGH IMPACT, LOW EFFORT)

**File:** `users/proses_login_user.php`  
**Action:** Add `error_log()` in PDO catch block.
```php
} catch (PDOException $e) {
    error_log('User login PDO error: ' . $e->getMessage());
    header("Location: login.php?error=Terjadi kesalahan sistem. Silakan coba lagi.");
    exit;
}
```

**Files to update:**
- `users/proses_login_user.php` (line 80)
- `admin/proses_login_admin.php` (line 53)
- `users/proses_ganti_pin.php` (line 68)
- `admin/dashboard.php` (add before line 97)
- `users/data_diri.php` (add before line 45)

**Benefit:** Enables diagnosis of database failures in production logs.

---

### Priority 2: Replace Generic Exception Catch (MEDIUM IMPACT, MEDIUM EFFORT)

**File:** `users/upload_dokumen.php` (line 74)  
**Action:** Separate concerns; catch `PDOException` and file I/O exceptions separately.
```php
} catch (PDOException $e) {
    error_log('Upload document PDO error: ' . $e->getMessage());
    $pesan_error = "Gagal menyimpan dokumen. Silakan coba lagi.";
} catch (RuntimeException $e) {
    // File I/O errors
    error_log('Upload document file error: ' . $e->getMessage());
    $pesan_error = "Gagal memproses berkas. Silakan coba lagi.";
}
```

**Benefit:** Enables precise error diagnosis.

---

### Priority 3: Audit Log Integration (MEDIUM IMPACT, MEDIUM EFFORT)

**Action:** For critical operations (login, PIN change, data modification), log failed database attempts to `audit_logs` table with status='ERROR' after successful DB connection recovery.

**Note:** Cannot do this for connection failures (table not accessible). Use error.log for those.

**Benefit:** Creates persistent audit trail of database issues.

---

### Priority 4: Log Encryption & Rotation (LOW-MEDIUM IMPACT, MEDIUM-HIGH EFFORT)

**Action:** Configure log rotation in `bootstrap.php` (use `ini_set()` or external tool like `logrotate`).

**Note:** Error log encryption requires middleware (not standard PHP); consider:
- Rotating logs weekly
- Compressing old logs
- Retaining only 4 weeks of logs
- Storing backups offline

**Benefit:** Limits window of exposure if logs are compromised.

---

## Conclusion

**Overall Status: PARTIAL** — Implemented baseline error handling is sound (user-facing responses are sanitized). Key weaknesses are:

1. **Inconsistent logging** — Many PDO failures silently swallowed (proses_login_user.php, admin dashboards)
2. **Log file confidentiality** — Path disclosure and plaintext storage increase risk if filesystem is compromised
3. **Log rotation absent** — error.log grows unbounded

**No evidence of active PDO error disclosure to end users** via current codebase; however, **server-side logs are information source** if compromised (e.g., via SSH, backup restoration, or server compromise).

**Recommended next steps:** Implement Priority 1 & 2 recommendations above to close logging gaps.

---

## Files Reviewed

- `config/bootstrap.php` — Error reporting config
- `config/koneksi.php` — PDO instantiation and catch
- `users/dashboard.php` — Primary target; read data + catch
- `users/proses_login_user.php` — Authentication; catch
- `admin/proses_login_admin.php` — Admin auth; catch
- `users/proses_ganti_pin.php` — PIN update; catch + JSON response
- `users/upload_dokumen.php` — Document upload; dual catch blocks
- `users/data_diri.php` — Data read; catch
- `admin/dashboard.php` — Admin stats; catch
- `includes/header.php` — Notification count; catch
- `logs/.htaccess` — Web access protection
- `logs/error.log` — Sample log file (first 100 entries)

---

## Verification Checklist

- [x] Read-only investigation completed
- [x] No code modifications made
- [x] No database modifications made
- [x] No configuration changes made
- [x] Log file reviewed (sample only)
- [x] All catch blocks inventoried
- [x] No secrets disclosed in report
- [x] No user PII included in report

