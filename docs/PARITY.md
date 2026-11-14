# AstraTrack to Laravel Parity Inventory

Source: `C:\xampp\htdocs\AstraTrack_system` (read-only). Target: `C:\xampp\htdocs\framework`.

Status values: **INVENTORIED** means source behavior/location recorded; no Laravel conversion or parity verification has been performed. Legacy URLs below must remain available, including their `.php` names and query strings.

## Conversion progress (2026-11-05)

- **Inventory:** 35 source form rows are now listed below, in addition to one row per source PHP page and helper/include. AJAX contracts are recorded below. Markup and role behavior still require old/new response comparison.
- **Foundation:** Laravel 13.39.0 scaffold and 16 Eloquent models were placed in the requested target. `.env`/`.env.example` use `DB_DATABASE=astratrack`, blank credentials, and a blank Gemini key in the example. The target `.env` has a locally generated app key. No source secrets were copied. The baseline migration runs only on an empty MySQL schema, does nothing if any legacy table exists, does not seed sample records, and has an empty rollback. Session/cache/queue use file/sync drivers so Laravel does not need new tables in `astratrack`.
- **Compatibility work:** nested legacy routes are explicitly registered; the catch-all PHP executor and its copied PHP pages were removed from the target. The landing markup is a Blade view. Shipment audit JSON, lifecycle actions, printable summary, legacy notification actions, and upload/delete response handlers have Laravel controllers. Original `assets/` and `uploads/` content were hash-compared; 46 asset files and 4 upload files match the source byte-for-byte.
- **Shared sidebar correction:** replaced the extra Laravel-added close button and cube/“Astra Track” treatment with the source Astra Cargo logo and “Impex Solutions OPC” brand for admin, staff, and client menus. Role-specific legacy links remain in place and are covered by the sidebar feature test; old/new screenshots for each role remain pending.
- **Dashboard layout correction:** admin, staff, and client dashboards now use the legacy `.main` / `.topbar` structure, role-specific source header text, search/profile controls, and dashboard CSS. The breadcrumb row and duplicate dashboard heading were removed from these screens. Content-level visual parity is still pending.
- **Cross-page layout audit:** corrected the same header/wrapper mismatch on User Management and Add Shipment, removed duplicate staff dashboard heading, restored the client profile's source profile header/base CSS, added the missing base layout CSS to Notifications, and restored Search's standalone source layout. Shared page CSS now loads before the common sidebar skin, matching the source cascade. Other screens with a dedicated modern `.fig-main` / `.topnav` stylesheet keep that layout.
- **Client shipment/activity rendering fixes:** the client shipment view now loads the base `staff.css` required by its source stylesheet (restoring Inter typography, topbar/profile sizing, links, and compact icons). Replaced default Laravel/Tailwind pagination SVGs with the source-style pager controls on client shipment/dashboard, activity, shipment updates, reports, billing, transactions, and notifications pages.
- **Parity status:** auth/client/staff/admin/notices/billing/Gemini screens have not been compared page-by-page with the source. The existing Laravel starter's views are not yet proven to have the identical HTML output, and some old form/response contracts remain unmatched. Do not treat any page as DONE until comparison is recorded.
- **Verification:** with PHP 8.5.12 and SQLite enabled, the PHPUnit suite passes against its in-memory SQLite configuration (10 tests, 58 assertions). Blade view caching succeeds. The login page was smoke-tested after replacing its direct `$_SESSION['csrf']` access with Laravel's `csrf_token()` while preserving the legacy `csrf` field name. Admin, staff, and client sidebar branding/links plus legacy dashboard, profile, modern topbar, and compact pager structures are covered. No MySQL migration or live database query was run. Old/new screenshots and full behavior coverage are pending.

## Pages, forms, endpoints, and role checks

| Old file / URL, form, or endpoint | Role / access check | What it does / preserved behavior | Laravel target | Status |
|---|---|---|---|---|
| `index.php` | Guest and signed-in role redirect | Landing page, tracking form and role-dependent login/dashboard destination | `HomeController@index`; tracking endpoint; Blade | INVENTORIED |
| `index.php#trackForm` | Public | Tracking lookup submitted from landing page; preserve input/query behavior and response messages | `TrackingController@lookup` | INVENTORIED |
| `login.php` GET/POST | Guest; authenticate active users with `password_hash` credentials | Login form, validation/errors, session/auth setup, role-based redirects | `Auth\LoginController`; Blade; `POST /login.php` | INVENTORIED |
| `logout.php` | Signed-in user | End session and redirect as before | `Auth\LogoutController`; `GET /logout.php` | INVENTORIED |
| `process.php` | Source behavior to verify | Legacy process entry point / dispatch | `LegacyProcessController`; `GET,POST /process.php` | INVENTORIED—VERIFY |
| `dashboard.php` | Signed-in user; role dispatch | Legacy dashboard redirect/dispatch | `DashboardController`; `GET /dashboard.php` | INVENTORIED |
| `account-management/admin.php` | Admin only | Admin dashboard counts, shipment stages, transaction status, outstanding balance and activity preview | `Admin\DashboardController`; dashboard wrapper/header converted; content parity pending | HEADER CONVERTED—PARITY PENDING |
| `account-management/staff.php` | Staff only | Staff dashboard and assigned-work summary | `Staff\DashboardController`; dashboard wrapper/header converted; content parity pending | HEADER CONVERTED—PARITY PENDING |
| `account-management/user_management.php` GET | Admin only | User listing, role/status filters, client selector and account summary | `Admin\UserController@index`; original topbar restored; content parity pending | HEADER CONVERTED—PARITY PENDING |
| `account-management/user_management.php` POST `userForm` | Admin only | Create/edit user, role/client/status validation, prevent self-removal/last active admin, hash password, log activity; preserve exact messages | `Admin\UserController@store/update`; service; Blade | INVENTORIED |
| `account-management/user_management.php` POST account actions | Admin only | Toggle active status and delete account with existing safeguards and activity audit | `Admin\UserController@action` | INVENTORIED |
| `account-management/staff.php` POST forms/actions | Staff/admin rules in source | Staff dashboard actions and any embedded updates | `Staff\DashboardController` action methods | INVENTORIED—VERIFY |
| `client-management/client_dashbroad.php` | Client only | Client dashboard and client-scoped counts/shipments | `Client\DashboardController`; dashboard wrapper/header converted; content parity pending | HEADER CONVERTED—PARITY PENDING |
| `client-management/clint_shipment.php` GET | Client only; shipment query constrained by authenticated `client_id` | Client shipment list, tabs/filters/pagination, detail view, documents/delivery/container/customs/billing/status history; no client write controls | `Client\ShipmentController@index/show`; source base CSS and compact pager restored; content parity pending | LAYOUT CONVERTED—PARITY PENDING |
| `client-management/client_profile.php` GET/POST forms | Client only; self profile | Profile display and allowed profile updates, existing validation/messages | `Client\ProfileController`; original profile header/base CSS restored; content parity pending | HEADER CONVERTED—PARITY PENDING |
| `client-management/client_billing.php` GET/POST forms | Client only; own client billing | Billing list/details, payment submission and proof upload according to existing rules | `Client\BillingController`; upload service; Blade | INVENTORIED |
| `client-management/client_transactions.php` GET/POST forms | Client only; own client transactions | Transaction listing/details and existing payment/transaction actions | `Client\TransactionController`; Blade | INVENTORIED |
| `client-management/client_activity.php` | Client only; own activity scope | Client activity history | `Client\ActivityController`; Blade | INVENTORIED |
| `client-management/clients.php` GET/POST forms | Staff/admin checks per source | Client directory, search and client create/edit/status operations | `Client\DirectoryController`; Blade | INVENTORIED |
| `shipment-management/shipments.php` GET/POST/forms | Staff/admin and assignment scoping per source | Shipment listing, filters, tabs, search and actions | `ShipmentController@index/actions`; Blade | INVENTORIED |
| `shipment-management/shipment_add.php` GET/POST form | Staff/admin per source | Create shipment, reference number `AST-YYYY-NNNN`, related records, validation, history/audit/notifications | `ShipmentController@create/store`; source topbar restored; content parity pending | HEADER CONVERTED—PARITY PENDING |
| `shipment-management/shipment_edit.php` GET/POST form | Staff/admin; assigned scope per source | Edit shipment and related fields with existing validation, audit/history | `ShipmentController@edit/update`; services | INVENTORIED |
| `shipment-management/shipment_view.php?id=…` | Staff/admin; assignment/access scope per source | Shipment detail view, tabs, related records and actions | `ShipmentController@show`; Blade | INVENTORIED |
| `shipment-management/shipment_details.php?id=…` | Staff/admin per source | Shipment detail/summary content | `ShipmentController@details`; Blade | INVENTORIED |
| `shipment-management/shipment_summary.php` | Staff/admin per source | Shipment summary view | `ShipmentController@summary`; Blade | INVENTORIED |
| `shipment-management/staff_shipment.php` | Staff; staff assignment scope | Staff shipment work queue and related actions | `Staff\ShipmentController@index/actions`; Blade | INVENTORIED |
| `shipment-management/shipment_assignment.php` GET/POST | Admin/staff rule per source | Assign/reassign shipment and preserve activity/notification behavior | `ShipmentAssignmentController`; service | INVENTORIED |
| `shipment-management/shipment_status.php` GET/POST | Staff/admin; scope per source | Update status/stage, remarks, status history, audit and notifications | `ShipmentStatusController`; service | INVENTORIED |
| `shipment-management/shipment_lifecycle.php` GET/POST | Staff/admin per source | Lifecycle stage/tab updates and related business logic | `ShipmentLifecycleController`; service | INVENTORIED |
| `shipment-management/shipment_audit.php?id=…` | Staff/admin per source | Shipment audit/status history view | `ShipmentAuditController`; Blade | INVENTORIED |
| `shipment-management/shipment_document.php` GET/POST | Staff/admin; preserve upload/access rules | Document listing and document actions | `ShipmentDocumentController`; upload service | INVENTORIED |
| `shipment-management/shipment_documents_upload.php` POST | Staff/admin per source; allowed file validation | Upload shipment document, preserve existing storage paths and messages | `ShipmentDocumentController@upload` | INVENTORIED |
| `shipment-management/shipment_documents_download.php?id=…` | Authenticated role plus shipment/client scope per source | Download existing shipment document with access checks | `ShipmentDocumentController@download` | INVENTORIED |
| `shipment-management/shipment_documents_delete.php` POST | Staff/admin per source | Delete document according to existing rules | `ShipmentDocumentController@destroy` | INVENTORIED |
| `billing-management/billing.php` GET/POST/forms | Staff/admin; client data scope per source | Billing list/detail, create/update, totals and balance display | `BillingController`; billing service; compact source-style pager restored | LAYOUT CONVERTED—PARITY PENDING |
| `billing-management/transactions.php` GET/POST/forms | Staff/admin; client data scope per source | Transactions list/detail and transaction actions/status | `TransactionController`; billing service; compact source-style pager restored | LAYOUT CONVERTED—PARITY PENDING |
| `operations/activity.php` | Admin/staff scope per source | Activity log list, filters and records | `ActivityController@index`; source-style text pager restored | LAYOUT CONVERTED—PARITY PENDING |
| `operations/notifications.php` GET/POST | Signed-in client/staff/admin; own notifications only | Notification list/read state; mark-all form | `NotificationController@index/markAll`; source layout CSS and compact pager restored | LAYOUT CONVERTED—PARITY PENDING |
| `operations/notifications_action.php` POST/AJAX | Signed-in user; own notification only | Mark one/all notifications read; return existing response format and unread count | `NotificationController@action`; `POST /operations/notifications_action.php` | INVENTORIED |
| `operations/search.php` GET/AJAX | Signed-in role; role-scoped results per source | Global search and existing result/empty behavior | `SearchController`; standalone source wrapper restored; content parity pending | LAYOUT CONVERTED—PARITY PENDING |
| `operations/reports.php` GET/query filters | Admin/staff per source; staff assignment scope | Reports (overview, shipments, clients, transactions, billing, activity), date/client/status/search filters, tables, pagination | `ReportController`; source-style text pager restored | LAYOUT CONVERTED—PARITY PENDING |
| `login.php` form `#loginForm` | Guest | Email/password POST; exact validation and login messages | `POST /login.php` | INVENTORIED |
| `account-management/user_management.php` form `#userForm` and row actions | Admin | Create/edit/toggle/delete user actions; exact field names and validation | `POST /account-management/user_management.php` | INVENTORIED |
| Client profile/billing/transactions forms | Client | Preserve every source form field, method, upload and response; exact names pending source audit | Corresponding client controller routes | INVENTORIED—FIELD AUDIT |
| Shipment create/edit/assignment/status/lifecycle/document forms | Staff/admin per source checks | Preserve each form's field names, action, validation, upload and response; exact names pending source audit | Corresponding shipment controller routes | INVENTORIED—FIELD AUDIT |
| Billing/transactions/client-directory forms | Role checks in source | Preserve each form's field names, action, validation and response; exact names pending source audit | Corresponding controllers | INVENTORIED—FIELD AUDIT |
| Sidebar/top-nav search and notification interactions | Signed-in; role-scoped | Preserve links, search request, notification badge and actions | Blade components; search/notification routes | INVENTORIED |
| `index.php` tracking interaction | Public | Tracking lookup request/response | `POST /tracking` (plus legacy URL compatibility) | INVENTORIED |

## Required helper and include mapping

| Old file | Responsibilities to preserve | Laravel target | Status |
|---|---|---|---|
| `function/log_activity.php` | Activity log helper, actor identity and audit writes | `app/Services/ActivityLogService.php` | INVENTORIED |
| `function/notifications.php` | Notification creation/count/role scoped helpers | `app/Services/NotificationService.php` | INVENTORIED |
| `function/shipment_common.php` | Shared shipment/access/data helpers | `app/Services/ShipmentService.php` and policies | INVENTORIED |
| `function/shipment_documents.php` | Upload, validation, storage and document access helpers | `app/Services/ShipmentDocumentService.php` | INVENTORIED |
| `function/shipment_stages.php` | Stage/tab/status mapping and transition logic | `app/Services/ShipmentStageService.php` | INVENTORIED |
| `function/sidebar.php` | Role-specific navigation, menus and URLs | `resources/views/components/sidebar.blade.php`; role links tested, visual parity pending | CONVERTED—VISUAL PARITY PENDING |
| `includes/header.php` | Shared head markup, CSS/JS includes and metadata | Blade layout partial (markup/assets unchanged) | INVENTORIED |
| `includes/navbar.php` | Top navigation, breadcrumbs, search and notification UI | Blade component (markup unchanged) | INVENTORIED |
| `includes/footer.php` | Shared footer and script markup | Blade layout partial (markup/assets unchanged) | INVENTORIED |
| `class/user.php` | User-related class behavior/auth helpers | `App\Models\User` plus auth service as appropriate | INVENTORIED |

## Configuration and assets

| Old file/path | Purpose | Laravel target | Status |
|---|---|---|---|
| `config/database.php`, `config/database.local.sample.php` | Existing DB connection settings; secrets/config must not enter source control | `.env` plus Laravel database config | INVENTORIED |
| `config/gemini.php`, `config/gemini.sample.php` | Gemini integration and key configuration | `.env` key plus `config/services.php` and Gemini service | INVENTORIED |
| `assets/` | Existing CSS, JavaScript, icons and images; preserve byte-for-byte where possible | `public/assets/` | INVENTORIED |
| `uploads/` | Existing user/shipment files and access rules | Laravel storage/public mapping preserving existing paths | INVENTORIED |

## Database objects

Source queries refer to at least these tables/views; the final schema inventory must be confirmed against the live `astratrack` schema and source SQL before the baseline migration is written: `users`, `clients`, `shipments`, `shipment_stages`, `shipment_status_updates`, `shipment_documents`, `deliveries`, `containers`, `customs_processing`, `transactions`, `billings`, `payments`, `notifications`, `activity_log`, and view `v_billing_balance`. Preserve existing column names and types. The baseline migration is intended as schema documentation/copy and must not be run against or alter live data.

## Inventory caveats / open questions

- Exact per-field inventories for all forms, complete AJAX request/response contracts, every included/required file relationship, all database columns/indexes, and every role guard remain to be checked directly against the source and live schema before conversion.
- `process.php` has a generic legacy dispatcher name; its exact behavior and whether any external caller depends on it must be confirmed.
- Source contains `config/database.php` and `config/gemini.php`; do not copy their secret values. Populate only environment variable names/configuration in the Laravel application.
- Some URLs may be reached by relative links and form actions. Route coverage must preserve the legacy path, HTTP method and query string exactly.


## Enumerated source form inventory

Field names below are extracted from each source `<form>`; dynamically generated controls and JavaScript request payloads require browser-level confirmation. Role scope is summarized from each page and must follow its individual source guard.

| Old form | Role | Method, action, fields | Laravel target | Status |
|---|---|---|---|---|
| `account-management/admin.php` · `search` | Admin | `GET` → `../operations/search.php`; fields: q | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `account-management/staff.php` · `search` | Staff | `GET` → `staff.php#shipments`; fields: q | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `account-management/staff.php` · `trackForm` | Staff | `GET` → `staff.php`; fields: No named fields parsed (JS-built or button-only form) | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `account-management/user_management.php` · `userForm` | Admin | `POST` → `current PHP URL`; fields: csrf, action, user_id, full_name, email, password, role, status, client_id | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `account-management/user_management.php` · `confirmForm` | Admin | `POST` → `current PHP URL`; fields: csrf, action, user_id, confirm_text | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `billing-management/billing.php` · `bill-filters` | Admin/Staff; client wrapper uses Client | `GET` → `current PHP URL`; fields: q, client_id, bill_status, payment_status, start, end | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `billing-management/billing.php` · `createBillForm` | Admin/Staff; client wrapper uses Client | `POST` → `current PHP URL`; fields: csrf, action, client_id, shipment_id, billing_date, due_date, items[0][charge_type], items[0][description], items[0][amount] | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `billing-management/billing.php` · `bill-review-actions` | Admin/Staff; client wrapper uses Client | `POST` → `current PHP URL`; fields: csrf, action, payment_id | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `billing-management/billing.php` · `fcard bill-form` | Admin/Staff; client wrapper uses Client | `POST` → `current PHP URL`; fields: csrf, action, billing_id, amount, payment_date, payment_method, reference_no, evidence | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `billing-management/transactions.php` · `txn-filters` | Admin/Staff; client wrapper uses Client | `GET` → `current PHP URL`; fields: q, client_id, status, start, end | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `billing-management/transactions.php` · `fcard txn-form` | Admin/Staff; client wrapper uses Client | `POST` → `current PHP URL`; fields: csrf, action, shipment_id, billing_id | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `billing-management/transactions.php` · `fcard txn-form` | Admin/Staff; client wrapper uses Client | `POST` → `current PHP URL`; fields: csrf, action, transaction_id, reason, confirm | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `client-management/client_activity.php` · `activity-search` | Client | `GET` → `client_activity.php`; fields: q | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `client-management/client_dashbroad.php` · `trackForm` | Client | `GET` → `client_dashbroad.php`; fields: q | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `client-management/clients.php` · `cm-filters` | Admin/Staff | `GET` → `clients.php`; fields: q, status | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `client-management/clients.php` · `form #2` | Admin/Staff | `POST` → `current PHP URL`; fields: csrf, action, client_id, return_tab, status | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `client-management/clients.php` · `cm-filters cm-shipment-filter` | Admin/Staff | `GET` → `current PHP URL`; fields: id, tab, sq, ss | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `client-management/clients.php` · `cm-dialog-form` | Admin/Staff | `POST` → `current PHP URL`; fields: csrf, action, client_id, return_tab, client_name, contact_person, email, phone, address, tin, status | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `client-management/clint_shipment.php` · `toolbar` | Client | `GET` → `clint_shipment.php`; fields: status, q, type | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `index.php` · `trackForm` | Public | `GET (default)` → `current PHP URL`; fields: bl_number | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `login.php` · `loginForm` | Public | `POST` → ``; fields: csrf, login, password, remember | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `operations/activity.php` · `activity-search` | Signed-in role; exact guard in source | `GET` → `activity.php`; fields: q | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `operations/notifications.php` · `form #1` | Signed-in role; exact guard in source | `POST` → `current PHP URL`; fields: csrf, action | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `operations/reports.php` · `fcard report-filters <?= $report===` | Signed-in role; exact guard in source | `GET (default)` → `current PHP URL`; fields: report, from, to, client_id, filter, q | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `operations/search.php` · `search` | Signed-in role; exact guard in source | `GET` → `current PHP URL`; fields: q | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `shipment-management/shipment_add.php` · `shipForm` | Admin/Staff; source scope varies | `GET (default)` → `current PHP URL`; fields: client_id, transaction_type, mode, remarks, vessel_or_flight, bl_awb_no, shipper_name, consignee_name, port_of_loading, port_of_discharge, hs_code, total_packages, total_gross_weight, measurement_cbm, incoterm, invoice_no, currency, invoice_value, freight_amount, fob_value | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `shipment-management/shipment_edit.php` · `shipmentEditForm` | Admin/Staff; source scope varies | `POST` → `shipment_edit.php?id=<?= $id ?`; fields: csrf, shipment_id, client_id, remarks, currency, containers[<?= $i ?>][container_no], containers[<?= $i ?>][seal_no], containers[<?= $i ?>][size_type] | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `shipment-management/shipment_view.php` · `statusForm` | Admin/Staff; source scope varies | `GET (default)` → `current PHP URL`; fields: shipment_id, correction_toggle, stage, occurred_at, actual_arrival_date, reason, remarks, notify | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `shipment-management/shipment_view.php` · `form #2` | Admin/Staff; source scope varies | `POST` → `shipment_view.php?id=<?= (int)$ship[`; fields: csrf, action, staff_id, staff_choice | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `shipment-management/shipment_view.php` · `documentsUploadForm` | Admin/Staff; source scope varies | `GET (default)` → `current PHP URL`; fields: No named fields parsed (JS-built or button-only form) | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `shipment-management/shipment_view.php` · `shipmentActionForm` | Admin/Staff; source scope varies | `GET (default)` → `current PHP URL`; fields: action, shipment_id, reason, remarks | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `shipment-management/shipment_view.php` · `form #5` | Admin/Staff; source scope varies | `POST` → `shipment_view.php?id=<?= (int)$ship[`; fields: csrf, action, customs_id, entry_no, declaration_type, declaration_type_custom, lodgement_date, assessment_status, customs_value, duties_taxes_amount, duties_paid_date, clearance_date, examination_lane, customs_remarks | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `shipment-management/shipment_view.php` · `form #6` | Admin/Staff; source scope varies | `POST` → `shipment_view.php?id=<?= (int)$ship[`; fields: csrf, action, delivery_id, trucker, driver_name, plate_no, scheduled_date, scheduled_time, delivery_address, contact_name, contact_phone, status, delivered_date | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `shipment-management/shipments.php` · `toolbar` | Admin/Staff; source scope varies | `GET` → `shipments.php`; fields: status, scope, q, staff, date, arrival | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |
| `shipment-management/staff_shipment.php` · `toolbar` | Admin/Staff; source scope varies | `GET` → `staff_shipment.php`; fields: status, q, date, arrival | Laravel route/controller listed above; preserve source names | INVENTORIED—PARITY PENDING |


## AJAX endpoint inventory

| Old endpoint / request | Role / scope | Request and response behavior to preserve | Laravel target | Status |
|---|---|---|---|---|
| `client-management/client_dashbroad.php?ajax=track&q=…` GET | Client; only own shipment | JSON track result/error; keep `q` and JSON fields | `Client\DashboardController@track` on same URL | ROUTED—PARITY PENDING |
| `account-management/staff.php?ajax=track&ref=…` GET | Staff; assigned/unassigned scope from source | JSON tracking result/error | `Staff\DashboardController@track` on same URL | ROUTED—PARITY PENDING |
| `shipment-management/shipment_add.php?ajax=check-bl` POST | Admin/staff | BL/AWB duplicate status JSON | `ShipmentCreationController@checkBl` | ROUTED—PARITY PENDING |
| `shipment-management/shipment_add.php?ajax=extract` POST | Admin/staff | PDF validation, Gemini extraction payload/errors | `ShipmentCreationController@extract`; `GeminiExtractor` | ROUTED—PARITY PENDING |
| `shipment-management/shipment_add.php` POST | Admin/staff | Shipment draft payload, reference number, related records; JSON success/error | `ShipmentCreationController@store` | ROUTED—PARITY PENDING |
| `shipment-management/shipment_status.php` POST | Admin/staff; assignment guard; admin-only correction | Stage/history/audit/transaction/notification mutation; JSON response | `ShipmentStatusController@update` | ROUTED—PARITY PENDING |
| `shipment-management/shipment_lifecycle.php` POST | Admin/staff; staff assignment guard; cancel/archive/reopen admin rules | Hold/cancel/archive/reopen with exact JSON messages and related history | `ShipmentLifecycleController@update` | ROUTED—PARITY PENDING |
| `shipment-management/shipment_assignment.php` POST | Admin only | Batch assign unassigned shipments; JSON result | `Admin\ShipmentManagementController@assign` | ROUTED—PARITY PENDING |
| `shipment-management/shipment_documents_upload.php` POST | Admin/staff; assigned scope; 10 MB/MIME/extension checks | Upload and JSON `{ok,message/error}`; existing path convention | `ShipmentDocumentController@legacyStore` | ROUTED—PARITY PENDING |
| `shipment-management/shipment_documents_delete.php` POST | Admin only | Delete matching row/file and return JSON result | `ShipmentDocumentController@legacyDestroy` | ROUTED—PARITY PENDING |
| `shipment-management/shipment_documents_download.php?shipment_id=…` GET | Admin/staff/client; scope by assignment/ownership | Download one file or package the shipment documents | `ShipmentDocumentController@downloadAll` | ROUTED—PARITY PENDING |
| `shipment-management/shipment_document.php?id=…` GET | Admin/staff/client; scope by assignment/ownership | Stream one document, optional inline view | `ShipmentDocumentController@download` | ROUTED—PARITY PENDING |
| `shipment-management/shipment_audit.php?shipment_id=…&filter=…&offset=…` GET | Admin/staff; assigned shipment scope | Paginated event JSON, status/document/activity deduplication | `ShipmentAuditController@show` | ROUTED—PARITY PENDING |
| `operations/notifications_action.php` POST | Signed-in user; own notification IDs only | Mark read/unread/all; `{ok,unread}` JSON and exact errors | `NotificationController@legacyAction` | ROUTED—PARITY PENDING |
| `assets/js/landing.js` tracking submit → `login.php?bl=…` | Public | Current behavior redirects to login with `bl` query; no public lookup | `/login.php` | ROUTED—PARITY PENDING |

## Observed source oddities

- The legacy URL spellings `clint_shipment.php` and `client_dashbroad.php` are intentional compatibility paths and remain registered as written.
- `process.php` only loads database configuration and initializes null variables; its replacement returns an empty `200` response.
- The public landing tracking interaction redirects to `login.php?bl=…`; it does not perform a public shipment lookup.
- `client-management/client_activity.php` is administrator-only despite its filename.
- `shipment-management/shipment_document.php` streams one document; `shipment_documents_download.php` handles all documents for a shipment.
- `config/gemini.sample.php` in the source contains a nonempty credential despite its sample name. It was not copied; target Gemini configuration reads only `.env`.
- Legacy uploads are under `uploads/<reference>/`; the target stores those existing DB paths under private storage while serving them only through scoped Laravel routes.
