# Prototype → Laravel feature gap check

Compared `C:\xampp\htdocs\AstraTrack_system` (prototype) against `C:\xampp\htdocs\framework` (Laravel 13).

## Status (2026-11-08): all gaps found have been fixed

| # | Gap | Fix |
|---|---|---|
| 1 | Client shipment detail page crashed (missing `client.shipments.show` view) | New `resources/views/client/shipments/show.blade.php`: progress stepper, info / key dates / cargo / containers / customs status / documents / delivery cards, Shipment Progress timeline, Billing Summary, Download Documents |
| 2 | Shipment detail: no Hold / Cancel / Archive / Reopen | "⋯" menu with Copy reference + lifecycle actions, "Restore and reopen" on archived banner, lifecycle dialog → `shipment_lifecycle.php` |
| 3 | Shipment detail: no audit drawer | "View all" opens drawer with All / Status / Documents / Edits filters and Load more → `shipment_audit.php` |
| 4 | Shipment detail: basic single-file upload | Upload dialog with drag-and-drop, multi-file queue, progress; per-row View / Download / Delete (admin). Document download now supports `?view=1` inline view for PDF/JPG/PNG |
| 5 | Customs: free-text declaration type | Customs dialog with Declaration Type select incl. "Other / enter custom value" (`__other__` handled in `ShipmentDetailsController::saveCustoms`), lane radios; reopens with errors on validation failure |
| 6 | Reassign: plain select | Reassign dialog with staff search, open-shipment counts, "Reassign staff?" confirmation |
| 7 | Delivery: inline form | Schedule / Edit Delivery dialog with conditional Delivered date |
| 8 | Header: `shipments.js` not loaded; no Print Summary | All prototype scripts loaded in prototype order; Print Summary link added |
| 9 | Admin dashboard: no trend chart | "Shipments Created" 12-month Chart.js line chart |

### Prototype changes made after the original port (Nov 7) — also brought over

- **Billing**: bill list is now a card carousel; client stat cards ("Balance Due", "Unpaid Bills"); client-only labels and "Submit Payment Evidence" (`view=submit`); Payment Review empty state ("You're all caught up") and two-panel layout; new charge types (Orange lane, Red lane, DEA examination, BOC Task Force).
- **Reports**: bar rows replaced by Chart.js charts (doughnut / line / horizontal bar) with the same canvas ids as the prototype.
- **Shipment Updates** (`client_activity.php`): new title, copy and sidebar highlight.
- **Assets**: `admin.css`, `billing.css`, `reports.css`, `billing.js` re-synced; `public/assets` now matches the prototype `assets/` byte-for-byte.

## Verification

- PHPUnit 10/10 pass; `php artisan view:cache` compiles every Blade view.
- Rendered against the local MySQL `astratrack` data as admin, staff and client: 15 pages (new and legacy URLs) return 200 with the expected markup.
- Browser test (2026-11-08, admin and client): all 7 shipment dialogs open centred; audit drawer filters and Load more; customs "Other" toggle and validation round-trip; delivery status toggle; reassign search → confirm → back; lifecycle and upload endpoints reject invalid requests with the expected messages; document View / Download / Download all; dashboard and report charts; billing carousel, review empty state, charge types; client shipment detail, downloads and client billing / Submit Payment Evidence. No data was changed.
- Bugs found and fixed during the browser test:
  - Every single-document download returned HTTP 500 (malformed regex in `ShipmentDocumentController::download`, present since the original port).
  - Lifecycle, upload, reassign and customs dialogs showed in the top-left corner: `staff.css` resets all margins, removing the browser's modal centring. The prototype loads the same `staff.css`, so it has the same issue; fixed in the framework with a small override in `shipments/show.blade.php`.
- Not tested with real writes (to keep your data unchanged): actually holding/cancelling/archiving/reopening, uploading a real file, saving customs/delivery, reassigning, creating bills or submitting payments.

## Environment note

`composer.json` requires PHP ≥ 8.4.1, but XAMPP's PHP is 8.2.12, so the framework does not run under XAMPP's Apache.
Use `C:\Users\braga\AppData\Local\Programs\PHP\8.5.12\php.exe artisan serve`, or upgrade XAMPP's PHP.
