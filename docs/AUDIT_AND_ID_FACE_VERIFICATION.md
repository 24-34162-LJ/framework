# AstraTrack — System Audit & ID Recognition / Face Verification Integration Study

Date: 2026-11-14 · Scope: `main` @ `95375d0` (Laravel shipment-tracking app for a PH customs brokerage)

---

## Part 1 — System assessment

### 1.1 What the system is

| Area | Finding |
|---|---|
| Stack | Laravel 13, PHP 8.5 (vendor needs ≥ 8.4.1), MySQL (XAMPP), Blade + plain JS/CSS ported from a PHP prototype, Vite/Tailwind 4 |
| Roles | `admin`, `staff`, `client` (`users.role`); client users belong to a `clients` row (importer company, has `tin`) |
| Accounts | Created by admin only — no self-registration, no password reset |
| Modules | Shipments (create/edit/lifecycle/status/audit), documents + client document requests, customs, deliveries, billing + payment proof, transactions, notifications (in-app/SMS via Semaphore/email), reports, activity log, forecasting |
| AI already in use | **Gemini** (`app/Services/GeminiClient.php`) reads uploaded PDFs/images and returns JSON — used for B/L extraction (`GeminiExtractor`) and document checks (`DocumentChecker`) |
| Sidecar pattern | **Local Python FastAPI service** for forecasting (`forecasting/chronos_service.py` on `127.0.0.1:8001`, called via `Http::post` from `ForecastingController`) |
| Routes | 139 routes; legacy `*.php` URLs kept for compatibility alongside new named routes |
| Tests | 55 tests, **53 pass, 2 fail** (see 1.3) |

### 1.2 What is done well

- Every non-public route sits behind `auth` + `role:` / `legacy.role:` middleware; inactive accounts are logged out on the next request.
- Object-level access checks exist: clients are scoped by `client_id`, staff by `assigned_staff_id` (e.g. `ShipmentDocumentController::assertShipmentAccess`, `BillingController`, `Client\ShipmentController`).
- Login is throttled (`throttle:5,1`), passwords are bcrypt-hashed, session ID is regenerated on login and invalidated on logout.
- Uploads go to the **private** disk (`storage/app/private`); PDFs are checked by MIME *and* `%PDF-` magic bytes; payment evidence is checked with `finfo`; files are hashed to detect duplicates.
- Raw SQL fragments are parameterised; the one string-built `selectRaw` (`ReportController::groupCounts`) only receives hard-coded column names.
- Gemini key is sent as a header (not in the URL) and TLS verification is kept on.
- Blade output is escaped; no `{!! !!}` on user data.

### 1.3 Findings (ranked)

| # | Severity | Finding | Where | Recommendation |
|---|---|---|---|---|
| 1 | **High (ops)** | XAMPP's PHP is **8.2.12**; `vendor/` requires **≥ 8.4.1**. `php artisan …` on the default PATH and Apache `mod_php` will fatal with a Composer platform error. Only `artisan serve` via the PHP 8.5 binary in `.claude/launch.json` works. `composer.json` also says `^8.3`, which disagrees with the lock file. | `composer.json`, `vendor/composer/platform_check.php` | Upgrade XAMPP's PHP (or run via PHP 8.5 + PHP-FPM/`artisan serve`), set `"php": "^8.4"` in `composer.json`. |
| 2 | Medium | Login accepts **`full_name` as the username**. Names are not unique, so `->first()` can pick the wrong account when two users share a name. | `AuthController.php:42` | Add a unique `username` column, or log in by email only. |
| 3 | Medium | No second factor for admin/staff accounts that can approve payments, edit users and download every client's documents. | auth flow | Add TOTP (or email OTP) for admin/staff. Face verification (Part 2) is **not** a substitute for MFA. |
| 4 | Medium | **2 failing tests**: `MailConfigTest::test_env_example_has_a_sender_address` (regex breaks on CRLF line endings in `.env.example`) and `LegacyCompatibilityTest::test_login_page_keeps_legacy_form_fields_and_url` (login markup changed). | `tests/` | Normalise `.env.example` to LF (`.gitattributes`) or make the regex `\r?`-aware; update the login test to the new markup. |
| 5 | Medium | Thin test coverage for **authorization**: no tests prove a client cannot open another client's shipment/bill/document, or that staff cannot open another staff member's assigned shipment. | `tests/Feature` | Add IDOR regression tests per role before adding identity data (Part 2 raises the stakes). |
| 6 | Low | "Remember me" re-issues the session cookie for 30 days, but the server-side session still expires after `SESSION_LIFETIME` (120 min), so it does not actually remember; the session cookie is also excluded from encryption. | `AuthController.php:58-70`, `bootstrap/app.php:19` | Use `Auth::login($user, $request->boolean('remember'))` (Laravel's remember-token). |
| 7 | Low | `GET /logout.php` has no `auth` middleware and, as a GET, no CSRF — any page can log a user out (`<img src=…/logout.php>`). | `routes/web.php:329` | Make it a redirect to a POST form, or accept the nuisance consciously. |
| 8 | Low | Staff can open **unassigned** shipments (`assigned_staff_id IS NULL`). Probably intended; confirm it is. | `ShipmentDocumentController.php:241` etc. | Document as a rule or restrict to admin. |
| 9 | Low (prod) | `.env` has `APP_DEBUG=true`, `QUEUE_CONNECTION=sync` (SMS/email sent inline during requests), `SESSION_SECURE_COOKIE` unset. Fine for dev. | `.env` | Production: `APP_DEBUG=false`, HTTPS + `SESSION_SECURE_COOKIE=true`, `QUEUE_CONNECTION=database` + a worker. |
| 10 | Info (privacy) | Shipping documents (which contain names, addresses, TINs) are already sent to Google Gemini. Under the **Data Privacy Act (RA 10173)** this should be disclosed in the privacy notice, and a paid Gemini tier should be used (free tier data may be used to improve Google products). | `GeminiClient` | Matters even more for ID images — see 2.6. |

---

## Part 2 — Integrating ID recognition and face verification

### 2.1 Where it makes sense in AstraTrack

| Use case | Who | Why it matters | Priority |
|---|---|---|---|
| **A. Client onboarding / KYC** | Authorized representative of an importer (`clients.contact_person`) | Brokers must know who they act for (BOC accreditation, anti-fraud). Today an admin creates the account with no identity proof. | ★★★ Start here |
| **B. Cargo release / delivery hand-over** | Person receiving the goods (`deliveries.receiving_contact`) | Proves the right person received the cargo; strong proof-of-delivery. | ★★ |
| **C. Step-up check on sensitive actions** | Client user submitting payment proof, changing contact info / phone for SMS | Stops a stolen password from redirecting money or notifications. | ★ Optional |
| ~~D. Face login for staff~~ | — | Biometric login adds privacy liability for little gain; use TOTP instead. | Not recommended |

### 2.2 The two technologies (they are different problems)

1. **ID recognition (document side)** — read the ID: classify the document type (PhilSys/PhilID, passport, driver's license, UMID, PRC, etc.), extract fields (name, birth date, ID number, expiry), and run sanity checks (expired? name matches the client record? MRZ check digits valid on passports?). An LLM/OCR is fine for this.
2. **Face verification (biometric side)** — 1:1 match: is the live selfie the same person as the photo on the ID? Plus **liveness / anti-spoofing** (not a printed photo or a phone screen). This needs a **dedicated face-recognition model**, not Gemini — general LLMs are not reliable or calibrated biometric matchers.

### 2.3 Integration options

| Option | ID recognition | Face match + liveness | Cost | Data leaves server? | Effort | Fit |
|---|---|---|---|---|---|---|
| **1. Hybrid (recommended)** | **Gemini** via existing `GeminiClient` (new prompt) | **Local Python sidecar** like the Chronos one: face detector + embedding model (e.g. InsightFace/ArcFace, or DeepFace/FaceNet) via ONNX + a passive anti-spoof model | Low (Gemini tokens only) | ID image → Google; **face data stays local** | Medium | Reuses both patterns already in the codebase |
| 2. Fully local | PaddleOCR / Tesseract + MRZ parser (e.g. `passporteye`) in the sidecar | Same sidecar | Free | Nothing leaves | Medium-High (OCR tuning for PH IDs) | Best privacy; weaker extraction quality than Gemini |
| 3. Managed cloud API | AWS Textract `AnalyzeID` / Google Document AI | AWS Rekognition `CompareFaces` + Face Liveness | Pay per check | Yes (to the cloud vendor) | Low-Medium | Good accuracy, vendor lock-in, needs a cloud account |
| 4. Full KYC SaaS (Veriff, Onfido, Sumsub, Jumio, Regula, etc.) | Included | Included, certified liveness | Highest per check | Yes | Lowest (hosted flow + webhook) | Production-grade; check PH ID support and pricing |

**Recommendation:** Build Option 1, behind a driver interface (the same way `SmsIntegration` swaps drivers), so you can switch to Option 3/4 later without touching controllers.

> ⚠ Licensing: InsightFace's *pretrained models* are licensed for non-commercial research only. For a commercial deployment pick a model whose weights allow commercial use, or use Option 3/4. Check each model's licence before shipping.

### 2.4 Proposed architecture (Option 1)

```
Browser (client/staff)                    Laravel (XAMPP)                         Local services
──────────────────────                    ───────────────                         ──────────────
1. Consent screen  ──────────────►  POST /verification            (create row, consent_at)
2. Upload ID front/back ─────────►  POST /verification/{id}/document
                                        └─ IdDocumentReader ──────────────►  Gemini (existing GeminiClient)
                                           extract + validate fields ◄────   JSON: type, name, dob, id_no, expiry
3. Live selfie (getUserMedia) ───►  POST /verification/{id}/selfie
                                        └─ FaceVerifier ──────────────────►  face_service.py :8002  POST /verify
                                                                              detect face on ID + selfie,
                                                                              embed, cosine similarity,
                                                                              liveness score
                                           decision engine ◄──────────────   {match_score, liveness, faces_found}
4. Result / "under review"  ◄────   auto_pass | needs_review | rejected
                                    Admin review queue (/admin/verifications)
                                    ActivityLogger + NotificationService
```

New pieces (all follow existing conventions):

| Layer | New item | Modelled on |
|---|---|---|
| Python | `face/face_service.py` (FastAPI, `127.0.0.1:8002`): `POST /verify` (id_image, selfie) → `{faces_on_id, faces_on_selfie, similarity, liveness, quality}` | `forecasting/chronos_service.py` |
| Config | `services.face.url`, `services.face.threshold`, `services.identity.driver` | `services.chronos` |
| Service | `App\Services\Identity\IdDocumentReader` (Gemini prompt for IDs) | `DocumentChecker` |
| Service | `App\Services\Identity\FaceVerifier` interface + `LocalFaceVerifier` / `RekognitionFaceVerifier` drivers | `SmsIntegration` drivers |
| Service | `App\Services\Identity\VerificationDecision` (thresholds → status) | — |
| Controller | `IdentityVerificationController` (client/receiver flow) and `Admin\VerificationReviewController` | `DocumentWorkflowController` |
| Model + migration | `identity_verifications` (below) | `2026_11_12_…` migration |
| Views/JS | consent + capture page (webcam), admin review page with side-by-side images and scores | existing Blade + `public/astratrack/js` |

### 2.5 Data model

```sql
identity_verifications
  id                    BIGINT PK
  subject_type          ENUM('client_user','delivery_receiver')
  user_id               INT NULL      -- FK users
  client_id             INT NULL      -- FK clients
  delivery_id           INT NULL      -- FK deliveries (use case B)
  purpose               ENUM('onboarding','release','step_up')
  id_type               VARCHAR(40)   -- philsys, passport, drivers_license, umid, prc, ...
  id_number_hash        CHAR(64)      -- HMAC-SHA256, never the raw number
  id_number_last4       CHAR(4)
  extracted             JSON          -- name, birth_date, expiry (encrypted cast)
  id_front_path         VARCHAR(255)  -- private disk, encrypted file contents
  id_back_path          VARCHAR(255) NULL
  selfie_path           VARCHAR(255)
  match_score           DECIMAL(5,4) NULL
  liveness_score        DECIMAL(5,4) NULL
  checks                JSON          -- {expired:false, name_matches:true, mrz_valid:null, ...}
  status                ENUM('pending','auto_passed','needs_review','approved','rejected','expired')
  reviewed_by           INT NULL, reviewed_at DATETIME NULL, review_note VARCHAR(255) NULL
  consent_version       VARCHAR(20), consent_at DATETIME, consent_ip VARCHAR(45)
  purge_after           DATETIME      -- retention deadline
  created_at / updated_at
```

Add `clients.identity_verified_at` (and optionally `users.identity_verified_at`) so other modules can gate actions with a cheap check, e.g. a `verified` middleware.

### 2.6 Decision rules (starting point — tune on your own test set)

| Condition | Result |
|---|---|
| No face / multiple faces on ID or selfie, unreadable ID, ID expired | `rejected` (user can retry, max 3 per day) |
| Liveness below threshold | `rejected` |
| Similarity ≥ high threshold **and** name on ID matches `contact_person` / `receiving_contact` (fuzzy) **and** all checks pass | `auto_passed` → admin can still audit |
| Anything in between | `needs_review` → admin queue, notification to admins |

Thresholds depend on the model (ArcFace cosine similarity is commonly in the 0.3–0.5 range for "same person"); measure false accept / false reject on test images before choosing values. Always keep a human review path — never let a borderline score auto-reject without a second look.

### 2.7 Privacy, legal and security requirements (do not skip)

Biometric and government-ID data are **sensitive personal information** under the Philippine **Data Privacy Act (RA 10173)**.

- **Explicit consent** screen with purpose, retention and the fact that the ID image is processed by Google (Option 1). Store consent version and timestamp.
- **Privacy Impact Assessment** and update of the privacy notice; check NPC registration obligations for the company.
- **Data minimisation:** store the ID number as an HMAC + last 4; do not keep face embeddings after the decision; encrypt image files at rest (`Crypt::encrypt` before `Storage::put`, or an encrypted disk); `extracted` column with Laravel's `encrypted:array` cast.
- **Retention:** a scheduled command purges images after the retention period (e.g. 90 days after decision), keeping only the decision record.
- **Access:** only admins can view ID images; every view is written to `ActivityLogger`.
- **Gemini:** use a paid API tier for ID images; keep the face comparison local (Option 1) so biometrics never leave the server.
- **Sidecar hardening:** bind to `127.0.0.1` only, require a shared secret header, cap image size, reject non-JPEG/PNG.
- **Camera access** (`getUserMedia`) only works on **HTTPS or `localhost`** — staff phones on the LAN need TLS on the XAMPP host.
- **Upload validation:** reuse the `finfo` + size checks already used for payment evidence; strip EXIF.

### 2.8 Rollout plan

| Phase | Deliverable | Notes |
|---|---|---|
| 0. Prerequisites | Fix audit items 1, 2, 4, 5; HTTPS on the host | Authorization tests protect the new sensitive data |
| 1. ID recognition only | Migration, `IdDocumentReader`, upload page, admin review queue (manual face check by admin) | Delivers value with no biometrics yet |
| 2. Face verification | `face_service.py`, `LocalFaceVerifier`, webcam capture, decision engine | Collect a small consented test set to tune thresholds |
| 3. Liveness | Passive anti-spoof model in the sidecar (or switch driver to a vendor with certified liveness) | Biggest fraud risk is a photo of the ID photo |
| 4. Use case B | Release/delivery hand-over flow on staff phones, linked to `deliveries` | Needs the receiver's identity captured at booking |
| 5. Hardening | Retention purge job, encryption, audit logging, privacy notice | Required before real client data |

### 2.9 Open decisions for the team

1. Which use case first — onboarding (A) or release (B)?
2. Is sending ID images to Google acceptable, or must everything stay on-premises (Option 2)?
3. Is this a school/prototype deployment (non-commercial models OK) or commercial (licence-clean models or a vendor)?
4. Which Philippine IDs must be accepted?
5. Retention period for ID images and selfies.
