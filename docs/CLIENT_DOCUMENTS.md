# Client document uploads (2026-11-08)

## What clients can do
- **Upload documents** from their shipment page (*My Shipments → open a shipment → Documents*): an Upload button per missing checklist item, or "Upload document". PDF, JPG, PNG, XLS, XLSX up to 10 MB. Only while the shipment is open (not delivered, cancelled or archived).
- **Take photos** (phone camera or image files): the photos are turned into one PDF in the browser before upload.
- **See review status** of their uploads (Waiting for review / Accepted / Rejected with reason), **Replace** or **Upload again**, and **Withdraw** an upload that hasn't been reviewed yet.
- **Action needed**: document requests from staff, with due date and note, on the shipment page and on the dashboard ("Documents needed" card).
- **Request a new shipment** (*Shipment Requests* in the sidebar, or "Request a new shipment" on My Shipments): upload the document packet as one PDF (up to 25 MB) with notes. Gemini reads it right away.

## What staff/admin can do
- **Review client uploads** on the shipment page: Accept, or Reject with a reason. Only accepted files count toward the "required received" checklist and "Download accepted documents".
- **Request from client** next to any missing checklist item (due date and note optional); **Cancel request**.
- **Auto-check** (Gemini) on each client upload: detected document type, whether its B/L, invoice or container numbers match this shipment, and any issues. "Re-check" re-runs it.
- **Version history**: replaced files stay listed under "Version history".
- **Shipment Requests** page (sidebar, with pending count): open the client's PDF, see the auto-read summary, **Create shipment** (opens Add Shipment pre-filled; the client is fixed and the PDF is attached on save), or **Decline** with a reason.
- **Duplicate detection** (same file contents) for both client and staff uploads, and for shipment requests.

## Notifications
| Event | Who is notified |
|---|---|
| Client uploads or replaces a document | Admins and the assigned staff |
| Document accepted or rejected, document requested or cancelled | The client's users |
| New shipment request | All active admins and staff |
| Request declined, or converted to a shipment | The client's users |

## Database
Migration `2026_11_08_000001_add_client_document_features` (already applied to the local `astratrack` DB):
- `shipment_documents`: `source`, `review_status`, `reviewed_by`, `reviewed_at`, `review_note`, `file_hash`, `original_name`, `replaces_document_id`, `superseded_at`, `ai_check`. Existing rows became accepted staff documents.
- New tables `document_requests` and `shipment_requests`.

On another copy of the database run:
```
php artisan migrate --path=database/migrations/2026_11_08_000001_add_client_document_features.php
```

## Fixed along the way
- Saving a shipment with a blank Freight Amount, FOB Value or another blank numeric field failed with a database error; blanks are now saved as empty values (NULL).
- The baseline migration had a `use RuntimeException;` line that stopped every `php artisan migrate`.
