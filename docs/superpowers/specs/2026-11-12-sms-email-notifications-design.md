# SMS and Email Notifications — Design

Date: 2026-11-12
Project: AstraTrack Shipment Management and Transaction Monitoring System (Laravel 13, capstone)

## 1. Intent

AstraTrack already creates **in-app notifications** (bell menu + notifications page) for shipment events.
Real users in the **Philippines** — mostly clients waiting on their shipments — don't watch the app all day.
We want the important events to also reach them by **SMS** and **email**.

The build must be easy to **defend in the capstone panel**: each external channel lives in its **own file**,
and every notification still goes through **one central hub** (the existing `NotificationService`),
matching the Level 1 DFD process "Record Activity and Notify".

## 2. Goals

- Send **SMS through Semaphore** (semaphore.co) and **email through SMTP** for selected events.
- Keep each channel in its own, self-explanatory file:
  - `app/Services/SmsIntegration.php` — the only code that talks to Semaphore.
  - `app/Services/EmailIntegration.php` — the only code that sends notification email.
  - `app/Services/NotificationService.php` — existing hub; decides who gets what on which channel and calls the two files above.
- Decide channels **automatically by event type** (staff do not pick per update).
- Pick the SMS recipient with the **"mix" rule** (section 5).
- **Record every SMS/email attempt** and its result (sent / failed / skipped) so the DFD "Delivery status" flows are real.
- Work in development **without spending money**: a `log` mode that writes SMS to the Laravel log instead of sending.

## 3. Non-goals

- Facebook Messenger, push notifications, WhatsApp (maybe later; the hub design allows adding a channel file).
- New notification events (e.g. billing/payment notices). We only add channels to the events that already exist.
- A notification-settings admin screen. Event → channel rules live in a config file.
- Two-way SMS (replies), OTP login.
- Revising the DFD/ERD images — the user will revise them later to match this design.

## 4. Anti-goals (would be a failure even if it "works")

- A slow or failing Semaphore/SMTP call **breaks or rolls back** a staff action (status update, document request…).
- SMS sent from **inside a database transaction**, so a rolled-back action still texts the client.
- The **same SMS sent several times** to one phone for one event (e.g. three client users without phones all falling back to the company phone).
- **Spending SMS credits** on minor events (customs details edited, dates changed, etc.).
- API keys or SMTP passwords **hard-coded** in PHP files.
- Staff/admin being texted (SMS budget is for clients).

## 5. Who receives what (the "mix" rule)

### 5.1 Channels per event (defaults — adjustable in config)

In-app notifications stay exactly as today for every event. SMS and email are added on top:

| Event (`event_type` / title) | Audience today | Email | SMS (clients only) |
|---|---|---|---|
| `status` — status changed / reopened / on hold / cancelled | admin, staff, client | ✅ all recipients | ✅ |
| `created` — "Shipment request accepted" | client | ✅ | ✅ |
| `shipment_request` — "Shipment request declined" | client | ✅ | ✅ |
| `shipment_request` — "New shipment request" | admin/staff | ✅ | ❌ |
| `document` — "Document requested" | client | ✅ | ✅ |
| `document` — "Document needs to be re-uploaded" (rejected) | client | ✅ | ✅ |
| `document` — accepted / cancelled / uploaded / removed / to review | mixed | ✅ | ❌ |
| `assignment` | admin, staff, client | ✅ | ❌ |
| `customs`, `delivery`, `date` | admin, staff, client | ✅ | ❌ |
| `created` — "Shipment created" | admin, staff, client | ✅ | ❌ |
| `archive` | admin, staff, client | ❌ | ❌ |

Because `event_type` alone is not specific enough (`document` and `shipment_request` cover several cases),
the callers that need SMS will pass a more specific **notification key** (e.g. `document_requested`,
`document_rejected`, `request_accepted`, `request_declined`). Callers that pass nothing fall back to the
`event_type` rule. Exact key names are the builder's call; the table above is the behaviour.

**Admin and staff get email only, never SMS.**

**One email per action for clients (my call):** when staff accept a shipment request, `ShipmentCreationController`
fires both "Shipment request accepted" (client) and "Shipment created" (everyone). Client users must not get the
"Shipment created" email in that case — the "accepted" message is the one they receive by email/SMS. The in-app
notifications stay as they are today.

### 5.2 Target: person or company

Each SMS-enabled event is marked as one of:

- **`user`** — meant for the person on the client account (status updates, document requests/rejections).
- **`company`** — meant for the client company as a whole (shipment request accepted/declined).

### 5.3 Picking the phone number

- **`user` target:** for each client user being notified, use the **user's own phone** if they have one;
  otherwise fall back to the **client company's phone** (`clients.phone`).
- **`company` target:** send once to the **client company's phone**. If the company phone is **not a valid PH mobile
  number** (e.g. a landline like `(02) 8xxx-xxxx`, or empty), send instead to the **client users' own mobile numbers**
  (users with `sms_enabled`, de-duplicated). *(User decision.)*
- The user-target fallback to the company phone also applies only when the company phone is a valid mobile number.
- `clients.phone` stays free text (existing data is not changed); the admin client form shows a hint that a mobile
  number is needed for SMS.
- **De-duplicate** phone numbers per event: one SMS per number per event, no matter how many users resolve to it.
- No valid phone at all → no SMS; record it as `skipped` with reason "no phone".
- A user who turned SMS off (section 6.3) gets no SMS and **does not** fall back to the company phone for that user.

### 5.4 Email address

- Every user already has a required `email`; email goes to each notified user.
- `company` target email goes to `clients.email` if present, else to the client's users.

## 6. Data changes (migration)

### 6.1 `users`
- Add `phone` VARCHAR(20) NULL — Philippine mobile number.
- Add `sms_enabled` BOOLEAN NOT NULL DEFAULT 1 — user's opt-out switch (my call: real users + Data Privacy Act; cheap to add).

### 6.2 New table `notification_deliveries`
One row per SMS/email attempt:

| Column | Notes |
|---|---|
| `delivery_id` PK | |
| `notification_id` FK NULL → notifications | the in-app record it belongs to (null for company-level sends) |
| `shipment_id` FK NULL → shipments | for filtering |
| `channel` | `sms` or `email` |
| `recipient` | phone number or email address |
| `message` | text actually sent |
| `status` | `pending`, `sent`, `failed`, `skipped` |
| `provider_message_id` NULL | Semaphore's message id |
| `error` NULL | short failure / skip reason |
| `created_at`, `sent_at` NULL | |

Follow the project's existing table conventions (`*_id` primary keys, no Laravel timestamps where the other tables don't use them).
Add the same changes to `database/legacy-schema` notes only if that folder is still treated as the source of truth (builder's call).

### 6.3 Phone input
- Users can edit their own **phone** and **SMS on/off** in the client profile page.
- Admin can set a user's phone in user management.
- Validate PH mobile format: accept `09XXXXXXXXX`, `+639XXXXXXXXX`, `639XXXXXXXXX`; store normalized as `639XXXXXXXXX`
  (or whatever format Semaphore accepts — builder confirms against Semaphore docs).

## 7. The integration files

### 7.1 `SmsIntegration.php`
- One public method, e.g. `send(string $phone, string $message): SmsResult` (ok/fail, provider id, error).
- Drivers via config: `log` (writes to the Laravel log, default for local) and `semaphore` (real).
- Semaphore: HTTP POST to its send-message endpoint with `apikey`, `number`, `message`, `sendername`
  using Laravel's `Http` client, with a short timeout. Builder confirms endpoint and fields against current Semaphore docs.
- Message trimmed to fit **one SMS (160 chars)** to control cost; prefix with the app name (e.g. `AstraTrack: ...`).
- Never throws to the caller; failures come back as a failed result.

### 7.2 `EmailIntegration.php`
- One public method, e.g. `send(string $email, string $subject, string $message, ?string $link): EmailResult`.
- Uses **Laravel Mail** (no PHPMailer) with one simple Mailable + Blade template (title, message, "View shipment" button).
- Mailer comes from `.env` (`MAIL_MAILER=smtp`, host/port/user/password). Local default stays `log`.

### 7.3 Config
- `config/services.php` → `semaphore` block: `driver`, `api_key`, `sender_name`, `enabled`.
- `.env.example` gets the new variable **names** (no values): `SMS_DRIVER`, `SEMAPHORE_API_KEY`, `SEMAPHORE_SENDER_NAME`, plus SMTP `MAIL_*`.
- A small config file (e.g. `config/notifications.php`) holds the event → channels/target table from section 5.1.

## 8. Flow (matches the corrected integration diagram)

1. Staff saves an update (e.g. status change) — inside the existing DB transaction.
2. Controller calls `NotificationService` as today → in-app `notifications` rows are created (unchanged).
3. `NotificationService` looks up the event's channel rules, resolves recipients' emails/phones (Users, Clients), de-duplicates phones, and writes `notification_deliveries` rows as `pending` (or `skipped`).
4. The actual sending is dispatched **after the transaction commits** (queued job with `afterCommit`, or `DB::afterCommit`).
   With `QUEUE_CONNECTION=sync` it runs right after commit in the same request; switching to `database` queue later needs no code change.
5. The job calls `SmsIntegration` / `EmailIntegration` and updates each delivery row to `sent` / `failed`.
6. A failure never shows an error to the staff member; it is visible in the delivery records (and the log).

## 9. Testing

- **Test level (my call):** test `NotificationService`, the delivery job, `SmsIntegration` and `EmailIntegration`
  directly — not through the full controllers. The baseline migration only runs on MySQL, so tests build just the
  few tables they need with `Schema::create` in a shared test helper (same approach as
  `tests/Feature/LegacyCompatibilityTest.php`). The new migration must also work on SQLite.
  "Rollback → nothing sent" is tested by calling the service inside `DB::transaction` that throws.
- Tests (SQLite in-memory, as configured in `phpunit.xml`) with `Http::fake()` and `Mail::fake()`:
  - status change → client user with phone gets SMS to their own number; staff gets email, no SMS.
  - client user without phone → company phone used; three such users → one SMS.
  - user with `sms_enabled = false` → no SMS.
  - minor event (`customs`) → email only.
  - Semaphore returns an error → delivery `failed`, staff action still succeeds.
  - action that rolls back → nothing sent.
- Manual: `SMS_DRIVER=log` locally; one real send to the developer's own phone before the defense.

## 10. Left to the builder

- Exact notification key names and how callers pass them.
- Job vs `DB::afterCommit` mechanics; retry count (suggest 1–2 retries for failed SMS).
- Email template look (keep it plain, match app name/colors).
- Whether to show delivery records in an admin screen (nice for the defense demo, not required).

## 11. Calls I made (not explicitly decided by the user)

- Channels are automatic per event type (user said "follow your plan").
- The specific SMS-worthy event list in 5.1.
- Admin/staff: email only.
- `sms_enabled` opt-out switch on users.
- New `notification_deliveries` table instead of extra columns on `notifications`.
- Laravel Mail instead of PHPMailer; class files in `app/Services` instead of a plain `sms_integration.php`.
- 160-character SMS limit.

## 12. Follow-up outside this build

- Revise diagrams to match: SMS integration diagram (arrow Gateway → Client, reuse Level 1 process names, send from
  "Record Activity and Notify", results return to the sender, recipient = user phone or client phone),
  Level 1 (email delivery status return flow, Users/Clients → notify contact-info flow, process/store numbering),
  ERD (`users.phone`, `users.sms_enabled`, `notification_deliveries`).
- Register a Semaphore sender name before go-live (takes a few days).
