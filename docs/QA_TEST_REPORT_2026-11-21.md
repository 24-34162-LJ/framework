# AstraTrack — QA Testing Report

| | |
|---|---|
| **System** | AstraTrack (Laravel, PHP 8.5 FastCGI, MySQL `astratrack`) at `http://127.0.0.1:8080` |
| **Test date** | 2026-11-21 |
| **Build** | `main` @ `92ca638` + uncommitted working-tree changes |
| **Environment** | Local XAMPP, `APP_ENV=local`, `APP_DEBUG=true`, mail = real SMTP, SMS = log driver |
| **Method** | Scripted HTTP testing (Python `requests`, real sessions + CSRF tokens) for every route and role, direct DB checks for every write, targeted code review to confirm root causes, browser testing (desktop + 375 px mobile) in the in-app browser |
| **Source code changed?** | No. Test data was created through the application itself (see Appendix A). |

---

## A. Executive Summary

AstraTrack is in **much better shape than a typical system at this stage**. The core security model is solid:

- **Role-based access control held on every one of ~70 pages × 4 identities (anonymous, admin, staff, client)** and on ~40 write endpoints probed with forged requests. No privilege escalation was possible.
- **Tenant isolation held**: a second client could not read, download, upload, withdraw or cancel anything belonging to the first client, and vice-versa. Staff could not change shipments assigned to another staff member.
- **No SQL injection** (8 payloads × 18 parameters) and **no stored or reflected XSS** (payloads planted in client name, address, contact, shipper, bill item description, remarks; all escaped in Blade and written with `textContent` in JS).
- **CSRF, login throttling (5/min), session regeneration on login, session invalidation on logout, and immediate lock-out of deactivated users all work.**
- **Business rules are well enforced**: bill/payment amounts, over-payment prevention, staff two-person rule, payment reversal, stage-transition rules, customs date ordering, file-type sniffing, duplicate-file detection.

The issues found are mainly **deployment hardening and account-security gaps**, not broken features:

| Severity | Count |
|---|---|
| Critical | 0 |
| High | 1 |
| Medium | 5 |
| Low | 7 |

The one High issue (debug mode exposing stack traces and file paths) is a configuration change, but it **must** be fixed before any public deployment. The Medium issues (no password reset, weak password policy, admin can approve own payment, other sessions survive a password change, missing security headers) should be fixed before go-live.

**Verdict: READY WITH MINOR FIXES**, on the condition that BUG-01 (debug mode) is fixed before deployment. Details are in section I.

---

## B. Testing Coverage

| Module | Tested |
|---|---|
| Authentication | Login (email + full name), invalid/empty/SQLi credentials, CSRF, throttling, remember-me field, session fixation, logout, reuse of old session cookie, deactivated account mid-session, password change, other-device sessions |
| Role-based access | 69 GET routes × 4 identities; ~40 POST/PUT/DELETE routes forged by staff/client; legacy `*.php` aliases |
| User Management (admin) | Create user, toggle status, validation (duplicate email, bad email, unreal mail domain, short password, invalid role, client without company, 5 000-char name, bad phone, empty name) |
| Client Management | Create client company, XSS payloads in fields, staff read access |
| Shipments | Create (admin), duplicate B/L, staff list/scope, details, edit, update, claim, reassign, delete confirmation, archive/reopen rules |
| Shipment Status workflow | Invalid stage, skipping, backwards, cancel by staff, On Hold reason, future/past timestamps, format, 255-char remarks, staff correction, prerequisites (customs/arrival), unpaid-balance release |
| Customs Details | Future lodgement, clearance/duties-paid before lodgement, assessment status consistency, negative value, invalid lane |
| Documents | Client upload (EXE/HTML/SVG/PHP/double extension/empty/oversized remarks/invalid type/path traversal name), duplicate file, cross-tenant upload/download/withdraw, staff accept/reject/re-review, download headers |
| Shipment Requests | Client submit (no file, non-PDF, valid, duplicate), cross-tenant file/cancel, staff decline with/without note, cancel after decline |
| Billing & Payments | Bill validation (negative, zero, 3 decimals, due < bill date, wrong client, text, overflow), create, payment evidence sniffing, future/ancient date, over-balance, cross-tenant bill, partial/paid status, staff/unassigned-staff/admin review, two-person rule, re-review, reversal (short reason, no confirm, valid, repeat) |
| Transactions | Auto-creation with shipment, bill linkage, staff/client access, admin-only correction |
| Notifications | Per-role list, mark-read of another user's notification (IDOR), legacy action endpoint |
| Account / Profile | Staff details & password, mass-assignment of role/email/client_id, client contact update |
| Search & filters | SQLi/XSS/overlong input on admin search, shipment/user/client/activity filters, reports, staff/client tracking, locations search, billing/transactions filters |
| Dashboards & reports | Admin, staff, client dashboards load and show scoped data; reports & forecasting load |
| UI / Responsive | Desktop + 375 px mobile for 9 client pages (horizontal overflow, broken images), mobile navigation drawer, shipment details stepper |

**Not tested (and why):**

| Area | Reason |
|---|---|
| ID verification (DocuPass live check, admin ID review decisions) | Calls a paid external service (ID Analyzer) and needs a real ID image; no verifications exist in the DB to review |
| Gemini AI extraction / AI document check | Calls an external paid API |
| Email & SMS content | Mail goes through real SMTP; I checked only that notifications are created, not delivered mail |
| Hard delete of a shipment | Destructive; only the "wrong confirmation" refusal was tested |
| Admin self-delete / self-demote / self-deactivate guards | Blocked from running against the real admin account by my safety settings; verified by code review only (`UserManagementController.php:80,95,171`) |
| Tablet breakpoint, staff/admin pages on mobile | Time-boxed; client pages were prioritised as the public-facing side |
| Load/performance, concurrency races | Out of scope for a functional QA pass |

---

## C. Test Case Results

### C1. Authentication & Session

| ID | Module | Test Scenario | Expected | Actual | Status |
|---|---|---|---|---|---|
| TC-AUTH-01 | Login | Admin logs in with valid credentials | Redirect to admin dashboard | → `/account-management/admin.php` → Admin Dashboard | PASS |
| TC-AUTH-02 | Login | Staff logs in | Staff dashboard | → `/account-management/staff.php` | PASS |
| TC-AUTH-03 | Login | Client logs in | Client dashboard | → `/client-management/client_dashbroad.php` | PASS |
| TC-AUTH-04 | Login | Empty email and password | Error, no login | "Enter your email or username and password." | PASS |
| TC-AUTH-05 | Login | Wrong password | Generic error | "Incorrect email/username or password." | PASS |
| TC-AUTH-06 | Login | SQLi `' OR '1'='1` in both fields | Rejected | Rejected with generic error | PASS |
| TC-AUTH-07 | Login | POST without CSRF token | 419 | 419 | PASS |
| TC-AUTH-08 | Login | 6th–9th attempt within 1 min | Throttled | 429 on all | PASS |
| TC-AUTH-09 | Session | Session ID changes on login (fixation) | New ID | Changed | PASS |
| TC-AUTH-10 | Session | Reuse old session cookie after logout | Redirect to login | → `/login` | PASS |
| TC-AUTH-11 | Session | Deactivate a logged-in user | Next request logged out | → `/login`; login then refused | PASS |
| TC-AUTH-12 | Session | Change password, then use the same account's session on another device | Other session ends | Other session still works | **FAIL** (BUG-02) |
| TC-AUTH-13 | Password | Set password `11111111` | Rejected as weak | Accepted | **FAIL** (BUG-03) |
| TC-AUTH-14 | Password | Wrong current password / mismatch / same as old / < 8 chars | Rejected with message | All rejected with clear messages | PASS |
| TC-AUTH-15 | Password | Forgot-password / reset flow exists | Link on login page | None exists for any role | **FAIL** (BUG-04) |
| TC-AUTH-16 | Password | Client can change own password | Option on profile | None | **FAIL** (BUG-04) |
| TC-AUTH-17 | Login | Deactivated user tries to log in | Clear "account inactive" message | Shown "Incorrect email/username or password." | **FAIL** (BUG-11, Low) |
| TC-AUTH-18 | Login | Log in using full name instead of email | Defined behaviour | Works (name is not unique) | PASS with note (BUG-12) |
| TC-AUTH-19 | Session | Cookie flags | HttpOnly, SameSite, Secure in prod | HttpOnly + SameSite=Lax; `Secure` unset (HTTP only locally) | PASS with note (BUG-06) |

### C2. Role-Based Access Control (direct URL)

69 GET routes were requested as anonymous, admin, staff and client. Summary of results (full matrix in Appendix B):

| ID | Test Scenario | Expected | Actual | Status |
|---|---|---|---|---|
| TC-RBAC-01 | Anonymous opens any protected page | Redirect to login | All 69 → login | PASS |
| TC-RBAC-02 | Staff opens admin pages (`/admin/*`, users, reports, activity, search, identity) | 403/redirect | 403 or → login on every one | PASS |
| TC-RBAC-03 | Client opens admin or staff pages | 403/redirect | 403 or → login on every one | PASS |
| TC-RBAC-04 | Admin/staff open client-only pages | 403 | 403 | PASS |
| TC-RBAC-05 | Staff opens Client Management (read) | Allowed | 200 | PASS |
| TC-RBAC-06 | Staff/client forge admin writes: user toggle, client status, assignment, reassign, delete, transaction correction, payment reversal, lifecycle archive | 403 | 403 on every one, DB unchanged | PASS |
| TC-RBAC-07 | Client forges staff writes: shipment update, status, customs, delivery, upload, document request, claim, bill create, transaction create | 403 | 403 on every one, DB unchanged | PASS |
| TC-RBAC-08 | Legacy `.php` URLs follow the same rules | Same rules | Same rules (but redirect to login instead of 403) | PASS with note (BUG-10) |

### C3. Data Isolation (IDOR)

| ID | Test Scenario | Expected | Actual | Status |
|---|---|---|---|---|
| TC-IDOR-01 | Client B opens Client A's shipment `/client/shipments/1` | 404 | 404 | PASS |
| TC-IDOR-02 | Client B downloads Client A's documents (single, zip, legacy) | 404 | 404 on all 4 routes | PASS |
| TC-IDOR-03 | Client B opens Client A's payment evidence | 404 | 404 | PASS |
| TC-IDOR-04 | Client B opens Client A's shipment-request file | 404 | 404 | PASS |
| TC-IDOR-05 | Client B tracks Client A's reference | Not found | 404 | PASS |
| TC-IDOR-06 | Client B filters billing/transactions by Client A's IDs | No A data | No A data | PASS |
| TC-IDOR-07 | Client A uploads to / withdraws from / cancels Client B's records | 404 | 404, DB unchanged | PASS |
| TC-IDOR-08 | User marks another user's notification as read | 404 | 404, unchanged | PASS |
| TC-IDOR-09 | Unassigned staff opens shipment assigned to another staff | 404 | 404 (details, summary, docs, evidence) | PASS |
| TC-IDOR-10 | Unassigned staff edits/updates status/customs/delivery/uploads/requests/bills on another staff's shipment | 403/404 | Blocked on all, DB unchanged | PASS |
| TC-IDOR-11 | Unassigned staff claims an already-assigned shipment | Refused | Not reassigned | PASS |
| TC-IDOR-12 | Staff shipment list shows only shipments the staff may open | Consistent | Lists other staff's shipments that then 404 | **FAIL** (BUG-09, Low) |

### C4. User & Client Management

| ID | Test Scenario | Expected | Actual | Status |
|---|---|---|---|---|
| TC-USR-01 | Create client user linked to company | Saved | Saved (user 13) | PASS |
| TC-USR-02 | Create staff user | Saved | Saved (user 14) | PASS |
| TC-USR-03 | Duplicate email | Rejected | "That email address is already in use." | PASS |
| TC-USR-04 | Invalid email / domain without mail server | Rejected | Both rejected | PASS |
| TC-USR-05 | Password < 8 | Rejected | Rejected | PASS |
| TC-USR-06 | Role `superadmin` | Rejected | Rejected | PASS |
| TC-USR-07 | Client role without company | Rejected | Rejected | PASS |
| TC-USR-08 | 5 000-char name / empty name / bad phone | Rejected | All rejected | PASS |
| TC-USR-09 | Toggle status on/off | Status changes; session ended | Works | PASS |
| TC-USR-10 | Admin cannot deactivate/delete self; last admin protected | Guards exist | Present in code (`abort_if($target->is($actor))`, `ensureNotLastActiveAdmin`) | NOT TESTED (code review only) |
| TC-CLI-01 | Create client company with HTML/JS in name, contact, address | Saved and shown escaped | Escaped on all 4 pages checked | PASS |
| TC-ACC-01 | Mass-assign `role=admin`, `email`, `client_id` via profile/account forms | Ignored | Ignored | PASS |

### C5. Shipments & Status Workflow

| ID | Test Scenario | Expected | Actual | Status |
|---|---|---|---|---|
| TC-SHP-01 | Admin creates shipment with container, assigns staff | Created, reference generated, transaction opened | AST-2026-0002, transaction #2 created | PASS |
| TC-SHP-02 | Duplicate B/L | Rejected | 422 "already used" | PASS |
| TC-SHP-03 | Invalid stage name | Rejected | 422 | PASS |
| TC-SHP-04 | Staff cancels shipment | Rejected | "Only an administrator can cancel" | PASS |
| TC-SHP-05 | On Hold without reason | Rejected | Rejected | PASS |
| TC-SHP-06 | Future timestamp | Rejected | Rejected | PASS |
| TC-SHP-07 | Timestamp before the last update | Rejected | Rejected at minute level, but same-minute earlier time accepted | **FAIL** (BUG-08, Low) |
| TC-SHP-08 | Wrong date format / remarks > 255 | Rejected | Rejected | PASS |
| TC-SHP-09 | Staff uses "correction" | 403 | 403 | PASS |
| TC-SHP-10 | Advance stage without customs details / arrival date | Rejected with prerequisites | Rejected, lists missing fields | PASS |
| TC-SHP-11 | Staff moves backwards | Rejected | Rejected | PASS |
| TC-SHP-12 | Forward to Released/cleared with ₱1,000 unpaid | Defined policy | Allowed with warning (config `release_requires_payment=false`) | PASS (policy note, section F) |
| TC-SHP-13 | Archive an active (not delivered) shipment | Rejected | "Only a delivered or cancelled shipment can be archived." | PASS |
| TC-SHP-14 | Invalid lifecycle action | Rejected | Rejected | PASS |
| TC-SHP-15 | Delete with wrong reference confirmation | Refused | Refused, shipment kept | PASS |
| TC-CUS-01 | Lodgement date in future | Rejected | Rejected | PASS |
| TC-CUS-02 | Clearance / duties-paid before lodgement | Rejected | Rejected | PASS |
| TC-CUS-03 | Duties paid date with status "assessed" | Rejected | Rejected | PASS |
| TC-CUS-04 | Negative value / invalid lane | Rejected | Rejected | PASS |
| TC-CUS-05 | Valid customs details | Saved | Saved; status auto-advanced to Duties paid | PASS (note, section F) |

### C6. Documents & Shipment Requests

| ID | Test Scenario | Expected | Actual | Status |
|---|---|---|---|---|
| TC-DOC-01 | Upload EXE renamed `.pdf` | Rejected | "contents do not match" | PASS |
| TC-DOC-02 | Upload HTML / SVG / PHP | Rejected | Rejected | PASS |
| TC-DOC-03 | Upload `x.php.pdf` with PHP content | Rejected | Rejected | PASS |
| TC-DOC-04 | Empty file / remarks > 255 / invalid doc type | Rejected | Rejected | PASS |
| TC-DOC-05 | Filename `../../../../public/pwn.pdf` | Sanitised | Stored under `uploads/AST-2026-0002/…`, nothing written to `public/` | PASS |
| TC-DOC-06 | Same file twice | Duplicate refused | 409 duplicate | PASS |
| TC-DOC-07 | Download headers | nosniff, no-store | `nosniff`, `private, no-store`, sanitised filename | PASS |
| TC-DOC-08 | Reject without reason | Rejected | 422 | PASS |
| TC-DOC-09 | Accept, then review again | Second review refused | 409 | PASS |
| TC-DOC-10 | Client withdraws an accepted document | Refused | Refused (404, unclear message) | PASS |
| TC-REQ-01 | Submit request without file / non-PDF | Rejected | Rejected | PASS |
| TC-REQ-02 | Valid request, then same file again | Created; duplicate refused | REQ-2026-0002; 409 duplicate | PASS |
| TC-REQ-03 | Decline without note | Rejected | 422 | PASS |
| TC-REQ-04 | Decline with note; client tries to cancel afterwards | Declined; cancel refused | Works | PASS |

### C7. Billing, Payments & Transactions

| ID | Test Scenario | Expected | Actual | Status |
|---|---|---|---|---|
| TC-BIL-01 | Bill with negative / zero / 3-decimal / text amount | Rejected | Rejected, no bill created | PASS |
| TC-BIL-02 | Due date before bill date | Rejected | Rejected | PASS |
| TC-BIL-03 | Client ID that doesn't own the shipment | Rejected | Rejected | PASS |
| TC-BIL-04 | Total over DB limit | Rejected | Rejected | PASS |
| TC-BIL-05 | Valid bill 1,500.50 + 499.50 | Total 2,000.00, unpaid, linked to transaction | Correct | PASS |
| TC-BIL-06 | Unassigned staff creates a bill on another staff's shipment | 403 | 403 | PASS |
| TC-PAY-01 | Evidence is text renamed `.png` / PHP renamed `.png` | Rejected | Rejected | PASS |
| TC-PAY-02 | Future payment date | Rejected | Rejected | PASS |
| TC-PAY-03 | Payment date 1900-01-01 | Rejected | **Accepted** | **FAIL** (BUG-07, Low) |
| TC-PAY-04 | Negative amount | Rejected | Rejected | PASS |
| TC-PAY-05 | Amount above balance (counting pending) | Rejected | Rejected | PASS |
| TC-PAY-06 | Pay another client's bill | 404 | 404 | PASS |
| TC-PAY-07 | Unassigned staff verifies payment | Refused | Stayed pending | PASS |
| TC-PAY-08 | Assigned staff verifies client payment | Verified, bill partial | Verified; bill "partial" | PASS |
| TC-PAY-09 | Verify an already verified payment | Refused | No change | PASS |
| TC-PAY-10 | Admin records a payment then verifies it alone | Second person needed | **Admin verified own payment, bill → paid** | **FAIL** (BUG-05) |
| TC-PAY-11 | Reversal with short reason / without confirm | Refused | Refused | PASS |
| TC-PAY-12 | Valid reversal | Payment rejected, bill recalculated | Bill paid → partial, reason stored | PASS |
| TC-PAY-13 | Reverse the same payment twice | Refused | No change | PASS |
| TC-TRX-01 | Transaction auto-created with shipment and linked to first bill | Yes | Yes | PASS |
| TC-TRX-02 | Non-admin uses transaction correction | 403 | 403 | PASS |

### C8. Security Probes

| ID | Test Scenario | Expected | Actual | Status |
|---|---|---|---|---|
| TC-SEC-01 | SQLi payloads on 18 search/filter params | No SQL error | All 200/404, no `SQLSTATE` | PASS |
| TC-SEC-02 | Reflected XSS on the same 18 params | Escaped | Escaped | PASS |
| TC-SEC-03 | Stored XSS rendered in 17 views across 3 roles | Escaped | Escaped (JSON tracking output rendered via `textContent`) | PASS |
| TC-SEC-04 | Error responses hide internals | Generic message | Exception class, file path, stack trace, model names exposed | **FAIL** (BUG-01) |
| TC-SEC-05 | Clickjacking / MIME-sniff / HSTS headers on pages | Present | Missing | **FAIL** (BUG-06) |
| TC-SEC-06 | Server version disclosure | Hidden | `Server: Apache/2.4.58 … PHP/8.2.12`, `X-Powered-By: PHP/8.5.12` | **FAIL** (BUG-06) |
| TC-SEC-07 | Authenticated pages cacheable after logout | `no-store` | `no-cache, private` (no `no-store`) | **FAIL** (BUG-13, Low) |

### C9. UI / Responsive

| ID | Test Scenario | Expected | Actual | Status |
|---|---|---|---|---|
| TC-UI-01 | 9 client pages at 375 px: no horizontal page scroll | No overflow | `scrollWidth` 372 on all; wide tabs/tables scroll inside their containers | PASS |
| TC-UI-02 | Broken images on those pages | None | None | PASS |
| TC-UI-03 | Mobile navigation drawer | Opens fully on screen | Opens at 0–264 px, all links visible | PASS |
| TC-UI-04 | Shipment details stepper on mobile | Stages and dates readable | Readable, all 4 groups checked for delivered shipment | PASS |
| TC-UI-05 | Validation and success messages | Clear and specific | Clear, specific messages throughout | PASS |
| TC-UI-06 | URL naming | Consistent | Typos `client_dashbroad.php`, `clint_shipment.php` | **FAIL** (BUG-14, cosmetic) |
| TC-UI-07 | Tablet layout, staff/admin pages on mobile | — | — | NOT TESTED |

---

## D. Bugs and Issues Found

### BUG-01 — Debug mode exposes stack traces, file paths and internal names
- **Module:** Configuration / all modules
- **Severity:** High · **Priority:** P1 (must fix before deployment)
- **Description:** `APP_DEBUG=true` and `APP_ENV=local`. Every error response returns the exception class, server file paths and stack trace. Examples seen during testing: `"exception": "Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException"`, `"file": "C:\\xampp\\…"`, `"No query results for model [App\\Models\\Notification] 3"`.
- **Steps:** Log in as any client → `POST /notifications/3/read` with `Accept: application/json` (a notification belonging to someone else).
- **Expected:** `{"message":"Not found."}` with no internals.
- **Actual:** Full exception payload with class names, paths and trace.
- **Impact:** Gives attackers a map of the code, file system and data model. With debug on, some Laravel error pages can also show environment details.
- **Fix:** In production `.env` set `APP_ENV=production`, `APP_DEBUG=false`, then `php artisan config:cache`.

### BUG-02 — Changing a password does not sign out other sessions
- **Module:** Account / Authentication
- **Severity:** Medium · **Priority:** P2
- **Steps:** Log in as the same staff user in two browsers (A and B) → in A, change the password at `/account` → in B, refresh `/account`.
- **Expected:** B is logged out (a stolen session or remembered device stops working).
- **Actual:** B stays logged in (200 OK).
- **Fix:** In `AccountController::updatePassword` call `Auth::logoutOtherDevices($request->password)` (enable the `AuthenticateSession` middleware) and rotate `remember_token`. Do the same when an admin resets a user's password.

### BUG-03 — Weak password policy
- **Module:** Authentication / User Management / Account
- **Severity:** Medium · **Priority:** P2
- **Steps:** `/account` → change password to `11111111`.
- **Expected:** Rejected as too weak.
- **Actual:** Accepted. Rule is only `min:8|max:72`. The supplied accounts use `12345678` and `admin123`.
- **Fix:** Use `Illuminate\Validation\Rules\Password::min(10)->letters()->mixedCase()->numbers()->uncompromised()` in `SaveUserRequest` and `AccountController`. Change the seeded/admin passwords before deployment.

### BUG-04 — No password reset, and clients cannot change their own password
- **Module:** Authentication / Client Profile
- **Severity:** Medium · **Priority:** P2
- **Steps:** Open `/login`: there is no "Forgot password" link, and no reset routes exist. Log in as a client → `/client/profile`: there is no password section (`/account` is admin/staff only).
- **Expected:** Users can recover access and change their own password.
- **Actual:** Only an admin can set a client's password. A client who forgets it, or suspects it was leaked, cannot fix it themselves.
- **Fix:** Add Laravel's password-reset flow (the mail transport already works), and a "Change password" card on the client profile that reuses the account password logic.

### BUG-05 — Admin can record and approve their own payment (no segregation of duties)
- **Module:** Billing & Payments
- **Severity:** Medium · **Priority:** P2
- **Steps:** As admin, record a ₱1,000 payment on bill BL-2026-000002 → as the same admin, verify payment #4.
- **Expected:** Like staff (`BillingController.php:311`), the person who entered a payment cannot approve it.
- **Actual:** Payment verified by its own recorder; bill became **paid**.
- **Impact:** One person can mark a bill paid without anyone else checking, which is a financial-control gap.
- **Fix:** Apply the `recorded_by !== actor` check to admins too, or require a second admin, and allow a logged override only when there is just one admin.

### BUG-06 — Missing security headers and version disclosure
- **Module:** Web server / middleware
- **Severity:** Medium · **Priority:** P2
- **Actual:** HTML pages have no `X-Frame-Options`/CSP `frame-ancestors` (pages can be framed for clickjacking), no `X-Content-Type-Options` on pages, no `Referrer-Policy`, no HSTS. `Server: Apache/2.4.58 (Win64) OpenSSL/3.1.3 PHP/8.2.12` and `X-Powered-By: PHP/8.5.12` are sent. Session cookie has no `Secure` flag.
- **Fix:** Add a small middleware (or Apache `Header set`) for `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, a basic CSP, and HSTS once on HTTPS. Set `ServerTokens Prod`, `expose_php=Off`, and `SESSION_SECURE_COOKIE=true` in production.

### BUG-07 — Payment date accepts unrealistic past dates
- **Module:** Billing & Payments
- **Severity:** Low · **Priority:** P3
- **Steps:** As client, submit payment evidence with payment date `1900-01-01`.
- **Expected:** Rejected (for example not before the bill date, or not more than N days old).
- **Actual:** Accepted (payment #2).
- **Fix:** Add `after_or_equal:<bill billing_date>` (or a sensible minimum) to `payment_date` in `recordPayment`.

### BUG-08 — Status timeline can be saved out of order within the same minute
- **Module:** Shipment Status
- **Severity:** Low · **Priority:** P3
- **Steps:** Save Customs Details at 11:44:53 (auto-creates "Duties paid") → immediately move to Released/cleared with time 11:44.
- **Expected:** Event time not earlier than the last update.
- **Actual:** Saved at 11:44:00, which is **earlier** than the previous entry (11:44:53). The check compares `Y-m-d H:i` strings, so seconds are ignored (`ShipmentStatusController.php`, `$lastAt` check). Any timeline sorted by time shows Released before Duties paid.
- **Fix:** Compare full timestamps, or clamp the new entry to `max(occurred_at, lastAt)`, and order the timeline by `update_id` as a tiebreaker.

### BUG-09 — Staff shipment list shows shipments the staff member cannot open
- **Module:** Staff Shipment Management
- **Severity:** Low · **Priority:** P3
- **Steps:** Log in as a staff member with no assignments → `/staff/shipments`.
- **Expected:** Either only own/unassigned shipments, or other staff's shipments are readable.
- **Actual:** The list shows every shipment, including client names and references of shipments assigned to others. Opening one returns **404**. Data exposure is limited to list columns, but the behaviour is inconsistent and looks broken to staff.
- **Fix:** Decide on a policy. Either scope the list to `assigned_staff_id IN (me, NULL)`, or allow read-only details for all staff.

### BUG-10 — Inconsistent "access denied" behaviour between new and legacy URLs
- **Module:** Routing / RBAC UX
- **Severity:** Low · **Priority:** P4
- **Steps:** As logged-in staff open `/account-management/admin.php` → redirected to `/login.php` even though already logged in. The same page via `/admin/dashboard` returns 403.
- **Fix:** Make `LegacyRoleMiddleware` return 403 (or redirect to the user's own dashboard) for logged-in users with the wrong role.

### BUG-11 — Deactivated users see "Incorrect email or password"
- **Module:** Authentication
- **Severity:** Low · **Priority:** P4
- **Actual:** The login query filters `status = active`, so a correct password on a deactivated account shows a misleading error and users may think they forgot their password.
- **Fix:** After a successful password check on an inactive account, show "Your account is inactive. Contact the administrator." Keep the generic message for wrong passwords.

### BUG-12 — Login accepts a non-unique full name as username
- **Module:** Authentication
- **Severity:** Low · **Priority:** P4
- **Actual:** `AuthController::login` matches `email` **or** `full_name` and tries the password against up to 20 accounts with the same name. Users with identical names can sign in to whichever account matches the password. Names are also easier to guess than emails.
- **Fix:** Log in by email only, or add a unique `username` column.

### BUG-13 — Authenticated pages are not marked `no-store`
- **Module:** Session / caching
- **Severity:** Low · **Priority:** P4
- **Actual:** `Cache-Control: no-cache, private`. On a shared computer, pressing Back after logout may show cached dashboard pages from the browser cache. (Header verified; Back-button behaviour not verified in a real browser.)
- **Fix:** Add `Cache-Control: no-store` for authenticated responses.

### BUG-14 — URL typos
- **Module:** Routing (cosmetic)
- **Severity:** Low · **Priority:** P5
- **Actual:** `client_dashbroad.php`, `clint_shipment.php` are visible in the address bar after client login.
- **Fix:** Make the correct spellings the main URLs and keep the typo'd ones as redirects.

---

## E. Role-Based Access Report

### Admin
- **Can:** all admin modules (dashboard, users, clients, shipments, assignment, reports, forecasting, activity, search, ID review list), billing review/reversal, transaction correction, lifecycle actions, delete with typed confirmation.
- **Cannot:** open client-only pages (403). Correct.
- **Issue:** can approve a payment they recorded themselves (BUG-05).

### Staff
- **Can:** staff dashboard, own shipments, create shipments (auto-assigned to self), client management (read), billing/transactions scoped to assigned shipments, shipment-request queue, account page.
- **Cannot (verified with forged requests):** any `/admin/*` page or action, user/client changes, reassign, delete, cancel a shipment, stage corrections, payment reversal, transaction correction, or any change to shipments assigned to another staff member. Two-person rule on payments works. All correct.
- **Issue:** the shipment list shows other staff's shipments that then 404 (BUG-09).

### Client
- **Can:** own dashboard, own shipments, documents (upload/withdraw pending), shipment requests, own bills (submit payment evidence), own transactions, notifications, profile contact.
- **Cannot (verified):** see or touch another client's shipments, documents, bills, evidence, requests or notifications; reach any admin/staff page or endpoint. Correct.
- **Issue:** cannot change own password or reset a forgotten one (BUG-04).

---

## F. Business Logic Assessment

**Calculations — correct.** Bill totals are computed in integer cents (no float drift). Partial, paid and unpaid statuses were recalculated correctly after verify (₱1,000 of ₱2,000 → partial), admin verify (→ paid) and reversal (→ partial). Available balance subtracts pending payments, which prevents double submission.

**Workflows — mostly correct, with these points to review:**

1. **Release with unpaid balance is allowed.** `config/billing.php` → `release_requires_payment=false` by default, so a shipment can move to Released/cleared or Trucking with unpaid bills (only a warning). If the business rule is "no release before payment", set `BILLING_RELEASE_REQUIRES_PAYMENT=true`.
2. **Saving Customs Details auto-advances the stage.** Saving complete customs data moved the shipment straight from *Documents received* to *Duties paid*, with no timeline entries for *Declaration filed* or *Assessment*. This matches the code's intent (`furthestSupportedStage`), but clients see stages jump. Consider writing the skipped intermediate stages to the timeline.
3. **Earlier stages stay "In progress" in history.** In `shipment_status_updates`, past stages keep status *In progress* after the shipment moves on (shipments 1 and 2). The UI stepper shows them as completed, so the display is right, but reports that read this column directly would be wrong.
4. **Timeline ordering:** see BUG-08.
5. **Segregation of duties:** see BUG-05.

**Data integrity — good.** Every write was cross-checked in MySQL. Rejected requests left no partial rows. Duplicate B/L numbers, duplicate files and duplicate requests are blocked. Uploaded files that fail validation are removed. Tables use `utf8mb4_unicode_ci` throughout, and special characters (em dash, peso sign) are stored correctly.

---

## G. Security Assessment

| Concern | Status | Impact |
|---|---|---|
| Debug output (BUG-01) | **Open: High** | Discloses code structure, paths, model names; helps an attacker plan further attacks |
| Session not revoked on password change (BUG-02) | Open: Medium | A stolen session survives the victim's password change |
| Weak passwords allowed (BUG-03) | Open: Medium | Easier credential guessing (throttling helps) |
| No self-service reset / client password change (BUG-04) | Open: Medium | Users can't fix a compromised password themselves |
| Admin self-approval of payments (BUG-05) | Open: Medium | Internal fraud or error goes unchecked |
| Missing headers / version disclosure (BUG-06) | Open: Medium | Clickjacking; easier vulnerability targeting |
| Login by non-unique name (BUG-12) | Open: Low | Easier guessing; account ambiguity |
| SQL injection | **Not found** | Eloquent/bound parameters throughout |
| XSS (stored/reflected/DOM) | **Not found** | Blade escaping; JS uses `textContent` |
| CSRF | **Protected** | 419 without token |
| Brute force | **Protected** | 5 attempts/min/IP on login |
| IDOR / tenant isolation | **Protected** | Every record lookup scoped by client/staff |
| File upload | **Protected** | Content sniffing, whitelist, random stored names, private disk, `nosniff` downloads |
| Mass assignment | **Protected** | Validated fields only |
| Session fixation | **Protected** | ID regenerated on login |

---

## H. Recommendations

**Before deployment (required):**
1. Turn off debug mode and set production environment (BUG-01).
2. Change the admin/staff/client passwords that were shared for testing (`admin123`, `12345678`), and enforce a stronger password rule (BUG-03).
3. Serve over HTTPS with `SESSION_SECURE_COOKIE=true`, add security headers, hide server versions (BUG-06).

**Soon after:**
4. Add password reset and a client "change password" option (BUG-04).
5. Log out other devices on password change (BUG-02).
6. Apply the two-person rule to admin payment approval (BUG-05).
7. Decide the "release before payment" policy and set `BILLING_RELEASE_REQUIRES_PAYMENT` accordingly (section F-1).

**Quality improvements:**
8. Fix the timeline timestamp comparison (BUG-08) and the payment date lower bound (BUG-07).
9. Align the staff list with detail-page access (BUG-09) and legacy-route 403 behaviour (BUG-10).
10. Clearer inactive-account message (BUG-11); email-only login (BUG-12); `no-store` on authenticated pages (BUG-13); fix URL typos (BUG-14).
11. Add automated feature tests for the RBAC matrix and billing rules. They are currently correct, and tests would keep them that way as the code changes.

---

## I. Overall QA Verdict

### ✅ READY WITH MINOR FIXES

**Reasoning:** No Critical defects were found. Every security control that protects data (authentication, role checks, tenant isolation, CSRF, injection and XSS defences, upload validation) worked under deliberate attack. The core business workflows (shipment lifecycle, customs prerequisites, documents, billing and payment verification) calculate and enforce correctly, and failed operations don't leave half-written data.

The remaining issues are configuration hardening and account-security features. They are not broken functionality, and each fix is small and well understood. **The single condition is BUG-01: the system must not be deployed with `APP_DEBUG=true`.** With BUG-01 and the three "before deployment" items in section H done, the system is fit for production use. The Medium items should follow in the next release.

---

## Appendix A — Test data created during QA

Everything was created through the application UI/API as an admin, staff or client would. Nothing was inserted directly into the database. Nothing pre-existing was modified except as noted.

| Record | Details |
|---|---|
| Client company #3 | `QA Test Co <b>bold</b>` (contains XSS test payloads in contact/address) |
| User #13 | `QA Client` (client of company #3), email `trackhidden9+qaclient@gmail.com` |
| User #14 | `QA Staff`, email `trackhidden9+qastaff@gmail.com` |
| Shipment #2 | `AST-2026-0002`, B/L `QA-BL-0001`, now *Released/cleared*, with customs record, container `QAAU1234567` |
| Transaction #2 | Auto-created for shipment #2 |
| Bill #2 | `BL-2026-000002`, ₱2,000.00 (partial) |
| Payments #2–#4 | #2 rejected (1900 date), #3 verified ₱1,000, #4 admin-recorded then reversed |
| Document #8 | Packing List on shipment #2 (accepted) |
| Shipment request #2 | `REQ-2026-0002` (declined) |
| Notifications / activity log | Generated by the actions above (some were sent to the QA client and to the existing staff account) |

QA account passwords are random and stored only in the tester's temporary scratch folder, not in this repository. Delete or deactivate users #13 and #14 and archive the QA records before go-live.

## Appendix B — RBAC matrix (GET, summary)

`L` = redirect to login, `→x` = redirect, numbers = HTTP status.

| Route group | Anon | Admin | Staff | Client |
|---|---|---|---|---|
| `/admin/*` (dashboard, users, shipments, reports, forecasting, activity, search, identity) | L | 200 | 403 | 403 |
| `/admin/clients` | L | 200 | 200 | 403 |
| `/staff/*` | L | 403 | 200 | 403 |
| `/account`, `/shipments/create`, `/shipments/{id}`, `/shipments/{id}/edit`, `/shipment-requests`, `/locations/search` | L | 200 | 200 | 403 |
| `/billing`, `/transactions`, `/notifications`, own documents/evidence | L | 200 | 200 | 200 (own data only) |
| `/client/*` | L | 403 | 403 | 200 |
| `/account-management/admin.php`, `user_management.php` | L | 200 | L | L |
| `/account-management/staff.php`, `staff_shipment.php` | L | L / → | 200 | L |
| `/client-management/client_*.php` | L | L | L | 200 |
| `/billing-management/*.php`, `/client-management/clients.php`, shipment add/edit/view/summary `.php` | L | 200 | 200 | L |
| `/operations/activity.php`, `reports.php`, `search.php` | L | 200 | 403 | 403 |

---

## Appendix C — Fix status (2026-11-21, same day)

| Bug | Status | What changed | Verified by |
|---|---|---|---|
| BUG-01 Debug mode | **Needs your action** | `.env` must set `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true` on the production server. `.env.example` now says so. (Kept `true` locally for development.) | — |
| BUG-02 Other sessions survive password change | **Fixed** | `AuthenticateSession` middleware enabled (`bootstrap/app.php`); remember token rotated | Live: second session redirected to login after change; feature test |
| BUG-03 Weak passwords | **Fixed** | New `App\Rules\StrongPassword` (10–72 chars, upper + lower case + digit, common passwords refused) used by add/edit user, My Account, client profile and reset; admin form hints and JS check updated | Live: `11111111`/`12345678` refused on My Account, Add User and Reset; feature tests |
| BUG-04 No reset / no client password change | **Fixed** | `/forgot-password` + `/reset-password/{token}` (60-min single-use token, same answer for unknown/inactive emails, throttled), "Forgot password?" link on login, "Change password" card on client My Profile; new `password_reset_tokens` table | Live in browser: forgot page, reset with weak then strong password, link refused on reuse, client change + restore; 5 feature tests |
| BUG-05 Admin self-approval | **Fixed** | Admin can't verify a payment they recorded unless another person (staff) checked it first; can still reject own entry; Verify button replaced by a note in both billing views | Live: admin self-verify refused, assigned staff then verified; 3 feature tests |
| BUG-06 Headers | **Fixed in app** / server part needs your action | New `SecurityHeaders` middleware: `X-Frame-Options`, CSP `frame-ancestors 'self'`, `nosniff`, `Referrer-Policy`, HSTS on HTTPS, `X-Powered-By` removed. Apache `Server` banner: set `ServerTokens Prod` and `ServerSignature Off` in `httpd.conf` | Live: headers present on `/login` |
| BUG-07 … BUG-14 (Low) | Open | Not in this round | — |

Test suite: 218 tests, all passing (11 new).
Existing accounts keep their current passwords until changed. The new rule applies when a password is set, so change `admin123` / `12345678` before go-live.
