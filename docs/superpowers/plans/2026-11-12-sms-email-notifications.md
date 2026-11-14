# SMS and Email Notifications Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Semaphore SMS and SMTP email delivery on top of AstraTrack's existing in-app notifications, routed through the existing `NotificationService` hub.

**Architecture:** `NotificationService` keeps creating in-app rows exactly as today, then looks up a per-event rule in `config/notifications.php`, resolves recipients (the "mix" phone rule), writes `notification_deliveries` rows, and, **after the DB transaction commits**, runs a job that calls `SmsIntegration` / `EmailIntegration` and records sent/failed. Each channel lives in its own file for the capstone defense.

**Tech Stack:** Laravel 13, PHP 8.3+ (run with `C:\Users\braga\AppData\Local\Programs\PHP\8.5.12\php.exe` — XAMPP's PHP 8.2 fails composer's platform check), Laravel `Http` client, Laravel Mail, PHPUnit 12 on SQLite in-memory.

**Spec:** `docs/superpowers/specs/2026-11-12-sms-email-notifications-design.md` — read it first; this plan argues from it.

## Global Constraints

- Test command (referred to below as `PHPUNIT`): `/c/Users/braga/AppData/Local/Programs/PHP/8.5.12/php vendor/bin/phpunit`
- Baseline: 10 tests, 1 already failing (`LegacyCompatibilityTest::test_login_page_keeps_legacy_form_fields_and_url`). Do not count it as a regression; do not fix it here.
- The project is **not a git repository**. Skip "commit" steps unless the user has run `git init`; in that case commit after each task with the message given.
- SMS goes to **client users / client companies only**. Admin and staff: email only, never SMS.
- SMS text: prefix `AstraTrack: `, at most **160 characters**, GSM-safe ASCII only.
- Phones stored normalized as `639XXXXXXXXX`. Accepted input: `09XXXXXXXXX`, `+639XXXXXXXXX`, `639XXXXXXXXX` (spaces, dashes, dots, parentheses ignored). Anything else is "not a mobile".
- Nothing is sent from inside a DB transaction; a rolled-back action sends nothing.
- A failed or slow SMS/email never throws into, or changes the response of, the staff action.
- No secrets in code. `.env.example` gets variable **names** only. Never read or print `.env`.
- Follow existing table conventions: `*_id` primary keys, `$timestamps = false`, migrations guarded with `Schema::hasTable` / `hasColumn`. New migrations must also run on SQLite.
- In-app notification behaviour (rows, titles, recipients) stays exactly as today.

## Review Focus

1. **Landline or oddly formatted phones** (`(02) 8123-4567`, `0917 123 4567`, `+63 917-123-4567`) — landlines count as "no mobile" and are never sent to Semaphore; formatted mobiles normalize correctly. → tests in Task 1 and Task 4.
2. **Semaphore down, timing out, or answering an error** (HTTP 500, connection exception, HTTP 200 with an error body) — the delivery row becomes `failed` with a short reason; the staff action still succeeds. → tests in Task 2 and Task 5.
3. **Long or non-ASCII messages** (rejection reasons with `ñ`, `—`, curly quotes, 300-char remarks) — exactly one SMS: transliterated to ASCII and cut to 160 chars ending in `...`. → test in Task 2.
4. **SMS configured as `semaphore` with no API key** — nothing is sent over HTTP; row `failed` with `SMS not configured`. → test in Task 2.
5. **SMTP failure** (mailer throws) — email row `failed`, action unaffected. → test in Task 5.

---

## File Structure

| File | Responsibility |
|---|---|
| `database/migrations/2026_11_12_000001_add_sms_email_notifications.php` (create) | `users.phone`, `users.sms_enabled`, `notification_deliveries` table |
| `app/Support/PhoneNumber.php` (create) | Normalize/validate PH mobile numbers |
| `app/Models/NotificationDelivery.php` (create) | Eloquent model for `notification_deliveries` |
| `app/Services/SmsIntegration.php` (create) | **Only** code that talks to Semaphore (log/semaphore drivers) |
| `app/Services/EmailIntegration.php` (create) | **Only** code that sends notification email |
| `app/Mail/NotificationMail.php` + `resources/views/emails/notification.blade.php` (create) | Plain notification email |
| `config/notifications.php` (create) | Event → channel/target rules (spec §5.1) |
| `app/Services/NotificationService.php` (modify) | Hub: rules, recipient resolution, delivery rows, after-commit dispatch |
| `app/Jobs/SendNotificationDeliveries.php` (create) | Sends pending delivery rows, records results |
| Controllers listed in Task 6 (modify) | Pass notification keys |
| Profile + user management + client form (modify) | Phone / SMS on-off input |
| `tests/Support/CreatesNotificationTables.php` (create) | Minimal SQLite schema for these tests |

---

### Task 1: Schema, phone helper, test tables

**Files:**
- Create: `database/migrations/2026_11_12_000001_add_sms_email_notifications.php`
- Create: `app/Support/PhoneNumber.php`
- Create: `app/Models/NotificationDelivery.php`
- Modify: `app/Models/User.php` (add `phone`, `sms_enabled` to `#[Fillable]`; cast `sms_enabled` to `boolean`)
- Create: `tests/Support/CreatesNotificationTables.php`
- Test: `tests/Unit/PhoneNumberTest.php`, `tests/Feature/Notifications/MigrationTest.php`

**Interfaces:**
- Produces:
  - `App\Support\PhoneNumber::normalize(?string $raw): ?string` → `'639XXXXXXXXX'` or `null`
  - `App\Support\PhoneNumber::isMobile(?string $raw): bool`
  - `App\Models\NotificationDelivery` — table `notification_deliveries`, PK `delivery_id`, `$timestamps = false`, fillable: `notification_id, shipment_id, channel, recipient, subject, message, status, provider_message_id, error, created_at, sent_at`; constants `STATUS_PENDING='pending'`, `STATUS_SENT='sent'`, `STATUS_FAILED='failed'`, `STATUS_SKIPPED='skipped'`, `CHANNEL_SMS='sms'`, `CHANNEL_EMAIL='email'`.
  - Trait `Tests\Support\CreatesNotificationTables` with `createNotificationTables(): void` — creates minimal `clients` (client_id, client_name, email, phone, status), `users` (user_id, full_name, email, password_hash, role, client_id, status, created_at), `shipments` (shipment_id, client_id, assigned_staff_id, reference_no), `notifications` (as legacy schema), then runs the new migration's `up()` via `(require database_path('migrations/2026_11_12_000001_add_sms_email_notifications.php'))->up()`.

Migration columns: `users.phone` string(20) nullable; `users.sms_enabled` boolean default true; `notification_deliveries`: `delivery_id` bigIncrements, `notification_id` unsignedBigInteger nullable (FK → notifications, cascade delete), `shipment_id` unsignedInteger nullable (FK → shipments, cascade delete), `channel` string(10), `recipient` string(120), `subject` string(150) nullable, `message` string(500), `status` string(10) default `pending`, `provider_message_id` string(64) nullable, `error` string(255) nullable, `created_at` dateTime useCurrent, `sent_at` dateTime nullable; index on `status`. `down()` drops the table and the two columns.

- [ ] **Step 1: Write failing tests**

```php
// tests/Unit/PhoneNumberTest.php
public function test_normalizes_ph_mobile_formats(): void {
    $this->assertSame('639171234567', PhoneNumber::normalize('09171234567'));
    $this->assertSame('639171234567', PhoneNumber::normalize('+63 917-123-4567'));
    $this->assertSame('639171234567', PhoneNumber::normalize('0917 123 4567'));
    $this->assertSame('639171234567', PhoneNumber::normalize('639171234567'));
}
public function test_rejects_landlines_and_junk(): void {
    foreach (['(02) 8123-4567', '028123456', '', null, '0917123456', 'call me'] as $raw) {
        $this->assertNull(PhoneNumber::normalize($raw));
        $this->assertFalse(PhoneNumber::isMobile($raw));
    }
}
// tests/Feature/Notifications/MigrationTest.php (uses CreatesNotificationTables)
public function test_migration_adds_columns_and_table_on_sqlite(): void {
    $this->createNotificationTables();
    $this->assertTrue(Schema::hasColumns('users', ['phone', 'sms_enabled']));
    $this->assertTrue(Schema::hasColumns('notification_deliveries', ['delivery_id', 'channel', 'recipient', 'status', 'error', 'sent_at']));
}
```

- [ ] **Step 2: Run** `PHPUNIT --filter "PhoneNumberTest|MigrationTest"` — Expected: FAIL (class/migration not found).
- [ ] **Step 3: Implement** the migration, `PhoneNumber`, `NotificationDelivery`, `User` changes, and the test trait as specified above. `normalize`: strip `[\s\-\.\(\)]`, then match `^(?:\+?63|0)(9\d{9})$` and return `'63'.$m[1]`.
- [ ] **Step 4: Run** `PHPUNIT --filter "PhoneNumberTest|MigrationTest"` — Expected: PASS.
- [ ] **Step 5: Commit** — `feat: add phone fields and notification_deliveries table`

---

### Task 2: `SmsIntegration` (Semaphore)

**Files:**
- Create: `app/Services/SmsIntegration.php`, `app/Services/ChannelResult.php`
- Modify: `config/services.php` (add `semaphore` block), `.env.example` (names only)
- Test: `tests/Unit/SmsIntegrationTest.php`

**Interfaces:**
- Produces:
  - `App\Services\ChannelResult` — readonly: `bool $ok`, `?string $providerId`, `?string $error`; static `ok(?string $providerId = null)`, `fail(string $error)`.
  - `App\Services\SmsIntegration::send(string $phone, string $message): ChannelResult` — never throws.
  - `App\Services\SmsIntegration::format(string $message): string` — public static; prefix + ASCII + 160 cap.
- Config: `services.semaphore` = `driver` (`env('SMS_DRIVER','log')`), `api_key` (`SEMAPHORE_API_KEY`), `sender_name` (`SEMAPHORE_SENDER_NAME`), `url` (`env('SEMAPHORE_URL','https://api.semaphore.co/api/v4/messages')`), `timeout` (10).

Behaviour: `log` driver → `Log::info('SMS (log driver)', [...])`, return `ok('log')`. `semaphore` driver → if `api_key` empty return `fail('SMS not configured')` with no HTTP call; else `Http::asForm()->timeout(...)->post(url, ['apikey','number','message','sendername'?])`. Success = 2xx **and** body is a list whose first item has `message_id`; provider id = that `message_id`. Anything else (non-2xx, error body, `ConnectionException`/any `Throwable`) → `fail(<short reason, max 255 chars, never containing the API key>)`. Builder: confirm endpoint/fields against current Semaphore docs; omit `sendername` when not configured.

`format`: `'AstraTrack: '.$message`, transliterate to ASCII (`Str::ascii`), collapse whitespace, if over 160 cut to 157 + `...`.

- [ ] **Step 1: Write failing tests**

```php
public function test_log_driver_sends_nothing_over_http(): void {
    config(['services.semaphore.driver' => 'log']); Http::fake();
    $this->assertTrue((new SmsIntegration)->send('639171234567', 'Hi')->ok);
    Http::assertNothingSent();
}
public function test_semaphore_success_returns_message_id(): void {
    config(['services.semaphore' => ['driver'=>'semaphore','api_key'=>'k','sender_name'=>'ASTRA','url'=>'https://sms.test/messages','timeout'=>10]]);
    Http::fake(['sms.test/*' => Http::response([['message_id' => 123, 'status' => 'Pending']])]);
    $r = (new SmsIntegration)->send('639171234567', 'Hello');
    $this->assertTrue($r->ok); $this->assertSame('123', $r->providerId);
    Http::assertSent(fn ($req) => $req['number'] === '639171234567' && $req['apikey'] === 'k' && $req['sendername'] === 'ASTRA');
}
public function test_semaphore_error_body_server_error_and_exception_fail_without_throwing(): void {
    // api_key 'secret-key-xyz'; three cases: response(['number' => ['invalid']], 200), response('oops', 500),
    // Http::fake(fn () => throw new ConnectionException('timeout'))
    // each: assertFalse($r->ok); assertNotEmpty($r->error); assertStringNotContainsString('secret-key-xyz', $r->error)
}
public function test_missing_api_key_fails_without_http(): void {
    config(['services.semaphore.driver' => 'semaphore', 'services.semaphore.api_key' => null]); Http::fake();
    $r = (new SmsIntegration)->send('639171234567', 'Hi');
    $this->assertFalse($r->ok); $this->assertSame('SMS not configured', $r->error); Http::assertNothingSent();
}
public function test_format_is_ascii_prefixed_and_max_160(): void {
    $out = SmsIntegration::format('Document rejected — Señor’s B/L '.str_repeat('x', 300));
    $this->assertStringStartsWith('AstraTrack: ', $out);
    $this->assertLessThanOrEqual(160, strlen($out));
    $this->assertStringEndsWith('...', $out);
    $this->assertSame($out, Str::ascii($out));
}
```

- [ ] **Step 2: Run** `PHPUNIT --filter SmsIntegrationTest` — Expected: FAIL.
- [ ] **Step 3: Implement** `ChannelResult`, `SmsIntegration`, config and `.env.example` names (`SMS_DRIVER=log`, `SEMAPHORE_API_KEY=`, `SEMAPHORE_SENDER_NAME=`).
- [ ] **Step 4: Run** `PHPUNIT --filter SmsIntegrationTest` — Expected: PASS.
- [ ] **Step 5: Commit** — `feat: add Semaphore SmsIntegration`

---

### Task 3: `EmailIntegration`

**Files:**
- Create: `app/Services/EmailIntegration.php`, `app/Mail/NotificationMail.php`, `resources/views/emails/notification.blade.php`
- Modify: `.env.example` (add commented SMTP names: `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`; keep `MAIL_MAILER=log`)
- Test: `tests/Unit/EmailIntegrationTest.php`

**Interfaces:**
- Consumes: `ChannelResult` (Task 2).
- Produces: `App\Services\EmailIntegration::send(string $email, string $subject, string $message, ?string $link = null): ChannelResult` — never throws. `App\Mail\NotificationMail(string $subjectLine, string $body, ?string $link)` — subject = `'AstraTrack: '.$subjectLine`, view `emails.notification` (title, message, "View in AstraTrack" button when `$link`).

Uses `Mail::to($email)->send(new NotificationMail(...))` (sent synchronously — the job in Task 5 already runs after commit). Invalid email (`filter_var` fails) → `fail('Invalid email address')`. Any `Throwable` → `fail(<short message, max 255>)`.

- [ ] **Step 1: Write failing tests**

```php
public function test_sends_notification_mail(): void {
    Mail::fake();
    $r = (new EmailIntegration)->send('client@example.com', 'Shipment status updated', 'REF-1 is now Delivered', 'https://app.test/shipments/1');
    $this->assertTrue($r->ok);
    Mail::assertSent(NotificationMail::class, fn ($m) => $m->hasTo('client@example.com') && $m->subjectLine === 'Shipment status updated');
}
public function test_mailer_exception_returns_failure(): void {
    Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));
    $r = (new EmailIntegration)->send('client@example.com', 'S', 'M');
    $this->assertFalse($r->ok); $this->assertStringContainsString('SMTP down', $r->error);
}
public function test_invalid_address_fails(): void {
    Mail::fake();
    $this->assertFalse((new EmailIntegration)->send('not-an-email', 'S', 'M')->ok);
    Mail::assertNothingSent();
}
```

- [ ] **Step 2: Run** `PHPUNIT --filter EmailIntegrationTest` — Expected: FAIL.
- [ ] **Step 3: Implement** as specified.
- [ ] **Step 4: Run** `PHPUNIT --filter EmailIntegrationTest` — Expected: PASS.
- [ ] **Step 5: Commit** — `feat: add EmailIntegration and notification mail`

---

### Task 4: Rules + recipient resolution in `NotificationService`

**Files:**
- Create: `config/notifications.php`
- Modify: `app/Services/NotificationService.php`
- Test: `tests/Feature/Notifications/NotificationRoutingTest.php`

**Interfaces:**
- Consumes: `PhoneNumber`, `NotificationDelivery` (Task 1); `SmsIntegration::format` (Task 2).
- Produces (later tasks call these):
  - `forShipment(Shipment $shipment, ?User $actor, string $eventType, string $title, string $message, ?string $key = null): void`
  - `forShipmentRoles(Shipment $shipment, ?User $actor, array $roles, string $eventType, string $title, string $message, ?string $key = null): void`
  - `forUsers(iterable $userIds, ?User $actor, string $eventType, string $title, string $message, ?int $shipmentId = null, ?string $key = null, ?int $clientId = null): void`
  - Existing callers keep working unchanged (new params are optional, last).
  - After creating rows, the service calls `$this->queueDeliveries(array $deliveryIds)` which Task 5 implements; in this task it is a no-op stub.

`config/notifications.php`:

```php
return [
    'default' => ['email' => true, 'sms' => null],
    'rules' => [
        'status'                        => ['email' => true,  'sms' => 'user'],
        'request_accepted'              => ['email' => true,  'sms' => 'company'],
        'request_declined'              => ['email' => true,  'sms' => 'company'],
        'document_requested'            => ['email' => true,  'sms' => 'user'],
        'document_rejected'             => ['email' => true,  'sms' => 'user'],
        'shipment_created_from_request' => ['email' => true,  'email_roles' => ['admin', 'staff'], 'sms' => null],
        'archive'                       => ['email' => false, 'sms' => null],
    ],
];
```

Rule lookup: `rules[$key] ?? rules[$eventType] ?? default`.

Resolution (after the in-app rows are created; recipients = the same users who got in-app rows):
- **Email:** one `email` delivery per recipient whose role is in `email_roles` (all roles if absent), `recipient` = user email, `subject` = title, `message` = message. For `sms: 'company'` events, email goes to `clients.email` once if present (`notification_id` null), else to each client user.
- **SMS `user`:** client-role recipients only, skipping `sms_enabled = false`. Phone = `PhoneNumber::normalize(user.phone)` ?? `PhoneNumber::normalize(client.phone)`.
- **SMS `company`:** `PhoneNumber::normalize(client.phone)`; if null → each client user of that client with `sms_enabled` and a mobile phone.
- Client for SMS: `$shipment->client_id`, or `$clientId` for `forUsers`.
- **De-duplicate** SMS by normalized number within the call; `notification_id` = the first matching in-app row (null for company phone).
- No number resolved for an SMS-eligible client user → one `skipped` row per user with `error = 'No mobile number'` and `recipient = ''` (user opted out → no row).
- SMS `message` = `SmsIntegration::format($message)`; status `pending`.

- [ ] **Step 1: Write failing tests** (use `CreatesNotificationTables`; seed one admin, one staff, client company `phone = '09170000000'`, `email = 'co@example.com'`)

```php
public function test_status_event_texts_user_phone_and_emails_everyone_but_not_staff_sms(): void
// client user phone '09171111111' → one sms delivery recipient '639171111111'; email rows for admin, staff, client user; no sms row with an admin/staff email or phone
public function test_user_without_phone_falls_back_to_company_mobile_once(): void
// three client users without phone → exactly one sms row to '639170000000'
public function test_landline_company_phone_falls_back_to_user_mobiles_for_company_event(): void
// client phone '(02) 8123-4567', users with '09172222222' and '09173333333'; forUsers(..., key: 'request_declined', clientId: X) → sms rows to both user mobiles
public function test_landline_and_no_user_phone_records_skipped(): void
// status event, company landline, user without phone → one sms row status 'skipped', error 'No mobile number'
public function test_opted_out_user_gets_no_sms_and_no_fallback(): void
// user sms_enabled=false → no sms rows at all
public function test_minor_event_is_email_only(): void
// forShipment(..., 'customs', ...) → only email rows
public function test_archive_sends_nothing_external(): void
// forShipment(..., 'archive', ...) → zero delivery rows, in-app rows still created
public function test_created_from_request_emails_office_only(): void
// forShipment(..., 'created', ..., key: 'shipment_created_from_request') → email rows only for admin/staff
public function test_in_app_rows_unchanged(): void
// forShipment status → notifications rows identical in count/recipients to pre-change behaviour (actor excluded, inactive excluded)
```

- [ ] **Step 2: Run** `PHPUNIT --filter NotificationRoutingTest` — Expected: FAIL.
- [ ] **Step 3: Implement** config file and service changes as specified. Keep each step in a small private method (`ruleFor`, `emailDeliveries`, `smsDeliveries`, `resolvePhone`).
- [ ] **Step 4: Run** `PHPUNIT --filter NotificationRoutingTest` — Expected: PASS; full `PHPUNIT` shows only the known baseline failure.
- [ ] **Step 5: Commit** — `feat: route notifications to sms and email deliveries`

---

### Task 5: Send after commit and record results

**Files:**
- Create: `app/Jobs/SendNotificationDeliveries.php`
- Modify: `app/Services/NotificationService.php` (implement `queueDeliveries`)
- Test: `tests/Feature/Notifications/DeliverySendingTest.php`

**Interfaces:**
- Consumes: `SmsIntegration::send`, `EmailIntegration::send`, `ChannelResult`, `NotificationDelivery`.
- Produces: `App\Jobs\SendNotificationDeliveries(array $deliveryIds)` implementing `ShouldQueue`; `handle(SmsIntegration $sms, EmailIntegration $email): void`.

`queueDeliveries(array $ids)`: if empty return; else `DB::afterCommit(fn () => SendNotificationDeliveries::dispatch($ids))` (runs immediately when not in a transaction; dropped on rollback). `handle`: for each `pending` row → call channel → set `sent` + `provider_message_id` + `sent_at = now()`, or `failed` + `error`. Wrap each row in `try/catch (Throwable)` so one bad row never stops the rest or reaches the caller. `$tries = 1` (with the sync queue a retry would block the request; real retries are a later switch to the database queue).

Email link: `route('shipments.show', $shipment_id)` when `shipment_id` is set — builder confirms the route name exists for clients (client view may be `client.shipments.show`); pass the link through the delivery row or compute it in the job.

- [ ] **Step 1: Write failing tests**

```php
public function test_pending_deliveries_are_sent_and_marked(): void
// Http::fake semaphore success, Mail::fake; forShipment status → all rows 'sent', sms row provider_message_id set, sent_at not null
public function test_semaphore_failure_marks_failed_and_does_not_throw(): void
// Http::fake 500 → sms row 'failed', email rows 'sent', no exception from forShipment
public function test_smtp_failure_marks_email_failed(): void
// Mail::shouldReceive('to')->andThrow(...) → email rows 'failed', sms row 'sent'
public function test_rolled_back_transaction_sends_nothing(): void
// try { DB::transaction(function () { $service->forShipment(...status...); throw new RuntimeException; }); } catch (RuntimeException) {}
// Http::assertNothingSent(); Mail::assertNothingSent(); NotificationDelivery::count() === 0
public function test_skipped_rows_are_not_sent(): void
// skipped row stays 'skipped', Http::assertNothingSent()
```

Configure `services.semaphore` driver `semaphore` with a fake URL in these tests so HTTP is exercised.

- [ ] **Step 2: Run** `PHPUNIT --filter DeliverySendingTest` — Expected: FAIL.
- [ ] **Step 3: Implement** the job and `queueDeliveries`.
- [ ] **Step 4: Run** `PHPUNIT --filter "Notifications|SmsIntegration|EmailIntegration|PhoneNumber"` — Expected: PASS.
- [ ] **Step 5: Commit** — `feat: send notification deliveries after commit`

---

### Task 6: Pass notification keys at the call sites

**Files (modify):**
- `app/Http/Controllers/ShipmentCreationController.php:206` → `key: 'request_accepted'`; `:216` → `key: $shipmentRequest ? 'shipment_created_from_request' : null`
- `app/Http/Controllers/ShipmentRequestController.php:117` (declined) → `key: 'request_declined', clientId: (int) $shipmentRequest->client_id`
- `app/Http/Controllers/DocumentWorkflowController.php:165` → `key: $accepted ? 'document_accepted' : 'document_rejected'`; `:197` → `key: 'document_requested'`
- `ShipmentStatusController.php:141` and `ShipmentLifecycleController.php:114,160` — event type is already `status`; no change needed (verify).
- Test: `tests/Feature/Notifications/CallSiteKeysTest.php`

**Interfaces:**
- Consumes: the `?string $key` / `?int $clientId` params from Task 4 (use named arguments).

Note: the `request_accepted` notification goes through `forShipmentRoles(..., ['client'], ...)`, so its company SMS uses `$shipment->client_id`.

- [ ] **Step 1: Write failing test** — a source-level guard is enough here, since the controllers need the full legacy schema:

```php
public function test_sms_worthy_call_sites_pass_keys(): void {
    $this->assertStringContainsString("key: 'request_accepted'", file_get_contents(app_path('Http/Controllers/ShipmentCreationController.php')));
    $this->assertStringContainsString("'shipment_created_from_request'", file_get_contents(app_path('Http/Controllers/ShipmentCreationController.php')));
    $this->assertStringContainsString("key: 'request_declined'", file_get_contents(app_path('Http/Controllers/ShipmentRequestController.php')));
    $doc = file_get_contents(app_path('Http/Controllers/DocumentWorkflowController.php'));
    $this->assertStringContainsString("'document_rejected'", $doc);
    $this->assertStringContainsString("key: 'document_requested'", $doc);
}
```

- [ ] **Step 2: Run** `PHPUNIT --filter CallSiteKeysTest` — Expected: FAIL.
- [ ] **Step 3: Edit** the call sites listed above.
- [ ] **Step 4: Run** full `PHPUNIT` — Expected: only the known baseline failure.
- [ ] **Step 5: Commit** — `feat: tag sms-worthy notification events`

---

### Task 7: Phone and SMS on/off inputs

**Files (modify):**
- `app/Http/Controllers/Client/ProfileController.php` — add `updateContact(Request $request): RedirectResponse`
- `routes/web.php` — `Route::post('/profile/contact', [ClientProfileController::class, 'updateContact'])->name('profile.contact')` inside the existing client group (next to line 163)
- `resources/views/client/profile/show.blade.php` — small form: "Mobile number" input + "Send me SMS updates" checkbox
- `app/Http/Requests/Admin/SaveUserRequest.php` + `app/Http/Controllers/Admin/UserManagementController.php` + `resources/views/admin/users/index.blade.php` — optional `phone` on add/edit
- `resources/views/admin/clients/index.blade.php:87` — under the Phone input: hint "Use a mobile number (09XX…) to receive SMS updates."
- Test: `tests/Feature/Notifications/ContactSettingsTest.php`

**Interfaces:**
- Consumes: `PhoneNumber::normalize` (Task 1).

Validation (both places): `phone` nullable, max 20, and if filled `PhoneNumber::isMobile` must be true — message: "Enter a Philippine mobile number like 09171234567." Store `PhoneNumber::normalize(...)` (empty → null). Profile: `sms_enabled` = checkbox present. Activity-log the profile change via `ActivityLogger` like other user actions (`'Profile Update'`).

- [ ] **Step 1: Write failing tests** (use `CreatesNotificationTables`; act as an active client user)

```php
public function test_client_can_save_mobile_and_turn_sms_off(): void
// post route('client.profile.contact'), ['phone' => '0917 123 4567'] (no sms_enabled) → user phone '639171234567', sms_enabled false, redirect with success
public function test_landline_is_rejected(): void
// ['phone' => '(02) 8123-4567'] → session error on 'phone', phone unchanged
public function test_blank_phone_clears_it(): void
// ['phone' => '', 'sms_enabled' => '1'] → phone null, sms_enabled true
```

Builder: confirm the route name prefix of the client group (`client.`) in `routes/web.php`; if the profile view needs more tables than the trait creates, test only the POST.

- [ ] **Step 2: Run** `PHPUNIT --filter ContactSettingsTest` — Expected: FAIL.
- [ ] **Step 3: Implement** controller action, route, view form, admin user phone field, client form hint.
- [ ] **Step 4: Run** full `PHPUNIT` — Expected: only the known baseline failure. Then open `/client/profile` and `/admin/users` in the browser preview and check the new fields render and save.
- [ ] **Step 5: Commit** — `feat: let users manage their mobile number and sms preference`

---

### Task 8: Manual end-to-end check

- [ ] With `SMS_DRIVER=log` and `MAIL_MAILER=log` (user edits `.env`; do not read it), change a shipment status in the browser as staff → `storage/logs/laravel.log` shows one `SMS (log driver)` line for the client and the mail entries; `notification_deliveries` rows are `sent`.
- [ ] Ask the user before any real send. With their Semaphore key and SMTP settings in `.env`, send one status update to the user's own phone/email and confirm receipt.

---

## Follow-ups (not in this plan)

- Revise DFD/ERD images per spec §12.
- Register a Semaphore sender name before go-live.
- Optional admin screen listing `notification_deliveries` for the defense demo.
