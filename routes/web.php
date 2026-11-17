<?php

/*
 * FILE PURPOSE
 * File   : routes/web.php
 * Feature: Core
 * Purpose: All website URLs: which URL goes to which controller, for every feature and role.
 */

use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\ClientManagementController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\IdentityVerificationController as AdminIdentityVerificationController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SearchController;
use App\Http\Controllers\Admin\ShipmentManagementController as AdminShipmentManagementController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\IdAnalyzerWebhookController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\Client\DashboardController as ClientDashboardController;
use App\Http\Controllers\Client\IdentityVerificationController as ClientIdentityVerificationController;
use App\Http\Controllers\Client\ProfileController as ClientProfileController;
use App\Http\Controllers\Client\ShipmentController as ClientShipmentController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ShipmentAuditController;
use App\Http\Controllers\ShipmentCreationController;
use App\Http\Controllers\ShipmentDetailsController;
use App\Http\Controllers\ShipmentDocumentController;
use App\Http\Controllers\ShipmentEditController;
use App\Http\Controllers\ShipmentLifecycleController;
use App\Http\Controllers\ShipmentStatusController;
use App\Http\Controllers\ShipmentSummaryController;
use App\Http\Controllers\Staff\DashboardController as StaffDashboardController;
use App\Http\Controllers\Staff\ShipmentManagementController;
use App\Http\Controllers\TransactionController;
use App\Services\ActivityLogger;
use App\Services\GeminiExtractor;
use App\Services\NotificationService;
use App\Services\ShipmentDocumentService;
use App\Services\ShipmentStageService;
use App\Http\Controllers\DocumentWorkflowController;
use App\Http\Controllers\ShipmentRequestController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', [LandingController::class, 'index'])->name('home');
Route::get('/index.php', fn () => redirect('/', 301))->name('legacy.index');

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login.store');
Route::get('/login.php', [AuthController::class, 'showLogin'])->name('legacy.login');
Route::post('/login.php', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::post('/logout.php', [AuthController::class, 'logout'])->middleware('auth');

Route::get('/dashboard', [AuthController::class, 'dashboard'])->middleware('auth')->name('dashboard');
Route::get('/dashboard.php', fn () => redirect()->route('dashboard'))->middleware('auth')->name('legacy.dashboard');

Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');
    Route::get('/users', [UserManagementController::class, 'index'])->name('users');
    Route::post('/users', [UserManagementController::class, 'save'])->name('users.save');
    Route::post('/clients', [ClientManagementController::class, 'save'])->name('clients.save');
    Route::get('/shipments', [AdminShipmentManagementController::class, 'index'])->name('shipments');
    Route::post('/shipments/assign', [AdminShipmentManagementController::class, 'assign'])->name('shipments.assign');
    Route::get('/reports', [ReportController::class, 'index'])->name('reports');
    Route::get('/reports/forecasting',[\App\Http\Controllers\Admin\ForecastingController::class, 'index'])->name('forecasting');
    Route::get('/activity', [ActivityController::class, 'index'])->name('activity');
    Route::get('/activity/shipments', [ActivityController::class, 'shipmentUpdates'])->name('activity.shipments');
    Route::get('/search', [SearchController::class, 'index'])->name('search');
});
Route::get('/admin/clients', [ClientManagementController::class, 'index'])->middleware(['auth', 'role:admin,staff'])->name('admin.clients');

Route::get('/admin.php', fn () => redirect()->route('admin.dashboard'))->middleware(['auth', 'role:admin'])->name('legacy.admin');
Route::get('/user_management.php', fn () => redirect()->route('admin.users'))->middleware(['auth', 'role:admin'])->name('legacy.user-management');
Route::post('/user_management.php', [UserManagementController::class, 'save'])->middleware(['auth', 'role:admin']);
Route::get('/clients.php', fn () => redirect()->route('admin.clients', request()->query()))->middleware(['auth', 'role:admin,staff'])->name('legacy.clients');
Route::post('/clients.php', [ClientManagementController::class, 'save'])->middleware(['auth', 'role:admin']);

Route::middleware(['auth', 'role:staff'])->prefix('staff')->name('staff.')->group(function (): void {
    Route::get('/dashboard', [StaffDashboardController::class, 'index'])->name('dashboard');
    Route::get('/track', [StaffDashboardController::class, 'track'])->name('track');
    Route::get('/shipments', [ShipmentManagementController::class, 'index'])->name('shipments');
});

Route::get('/staff.php', function () {
    if (request()->query('ajax') === 'track') {
        return redirect()->route('staff.track', ['ref' => request()->query('ref')]);
    }

    return redirect()->route('staff.dashboard', request()->query());
})->middleware(['auth', 'role:staff'])->name('legacy.staff');

Route::get('/staff_shipment.php', function () {
    if (request()->user()->role === 'admin') {
        return redirect('/admin/shipments');
    }

    return redirect()->route('staff.shipments', request()->query());
})->middleware(['auth', 'role:staff,admin'])->name('legacy.staff-shipments');
Route::get('/shipments.php', function () {
    $target = request()->user()->role === 'admin' ? 'admin.shipments' : 'staff.shipments';

    return redirect()->route($target, request()->query());
})->middleware(['auth', 'role:admin,staff'])->name('legacy.shipments');
Route::post('/shipment_assignment.php', [AdminShipmentManagementController::class, 'assign'])->middleware(['auth', 'role:admin']);

Route::middleware(['auth', 'role:admin,staff'])->group(function (): void {
    Route::post('/billing', [BillingController::class, 'storeBill'])->name('billing.store');
    Route::post('/billing/payments/review', [BillingController::class, 'reviewPayment'])->middleware('role:admin')->name('billing.payments.review');
    Route::get('/shipments/create', [ShipmentCreationController::class, 'create'])->name('shipments.add');
    Route::post('/shipments/add/check-bl', [ShipmentCreationController::class, 'checkBl'])->middleware('throttle:60,1')->name('shipments.add.check');
    Route::post('/shipments/add/extract', [ShipmentCreationController::class, 'extract'])->middleware('throttle:20,1')->name('shipments.add.extract');
    Route::post('/shipments/add', [ShipmentCreationController::class, 'store'])->middleware('throttle:30,1')->name('shipments.add.store');
    Route::get('/shipments/{shipmentId}/edit', [ShipmentEditController::class, 'edit'])->whereNumber('shipmentId')->name('shipments.edit');
    Route::put('/shipments/{shipmentId}', [ShipmentEditController::class, 'update'])->whereNumber('shipmentId')->name('shipments.update');
    Route::post('/shipments/{shipmentId}/customs', [ShipmentDetailsController::class, 'saveCustoms'])->whereNumber('shipmentId')->name('shipments.customs.save');
    Route::post('/shipments/{shipmentId}/delivery', [ShipmentDetailsController::class, 'saveDelivery'])->whereNumber('shipmentId')->name('shipments.delivery.save');
    Route::post('/shipments/{shipmentId}/status', [ShipmentStatusController::class, 'update'])->whereNumber('shipmentId')->middleware('throttle:30,1')->name('shipments.status.update');
    Route::post('/shipments/{shipmentId}/reassign', [ShipmentDetailsController::class, 'reassign'])->whereNumber('shipmentId')->middleware('role:admin')->name('shipments.reassign');
    Route::get('/shipments/{shipmentId}', [ShipmentDetailsController::class, 'show'])->whereNumber('shipmentId')->name('shipments.show');
    Route::post('/shipments/{shipmentId}/documents', [ShipmentDocumentController::class, 'store'])->whereNumber('shipmentId')->middleware('throttle:30,1')->name('shipments.documents.store');
    Route::delete('/shipment-documents/{documentId}', [ShipmentDocumentController::class, 'destroy'])->whereNumber('documentId')->name('shipments.documents.destroy');
});
Route::middleware(['auth', 'role:admin,staff,client'])->group(function (): void {
    Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
    Route::post('/billing/payments', [BillingController::class, 'recordPayment'])->middleware('throttle:20,1')->name('billing.payments.store');
    Route::get('/billing/payments/{paymentId}/evidence', [BillingController::class, 'evidence'])->whereNumber('paymentId')->name('billing.evidence');
});
Route::middleware(['auth', 'role:admin,staff,client'])->group(function (): void {
    Route::get('/shipments/{shipmentId}/documents/download', [ShipmentDocumentController::class, 'downloadAll'])->whereNumber('shipmentId')->name('shipments.documents.download-all');
    Route::get('/shipment-documents/{documentId}/download', [ShipmentDocumentController::class, 'download'])->whereNumber('documentId')->name('shipments.documents.download');
});
Route::get('/billing.php', fn () => redirect()->route('billing.index', request()->query()))->middleware(['auth', 'role:admin,staff'])->name('legacy.billing');
Route::middleware(['auth', 'role:admin,staff,client'])->group(function (): void {
    Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::post('/transactions', [TransactionController::class, 'store'])->name('transactions.store');
    Route::post('/transactions/correct', [TransactionController::class, 'correct'])->middleware('role:admin')->name('transactions.correct');
});
Route::get('/client/billing', fn () => redirect()->route('billing.index', request()->query()))->middleware(['auth', 'role:client'])->name('client.billing');
Route::get('/client/transactions', fn () => redirect()->route('transactions.index', request()->query()))->middleware(['auth', 'role:client'])->name('client.transactions');
Route::get('/transactions.php', fn () => redirect()->route('transactions.index', request()->query()))->middleware(['auth', 'role:admin,staff'])->name('legacy.transactions');
Route::get('/reports.php', fn () => redirect()->route('admin.reports', request()->query()))->middleware(['auth', 'role:admin'])->name('legacy.reports');
Route::get('/activity.php', fn () => redirect()->route('admin.activity', request()->query()))->middleware(['auth', 'role:admin'])->name('legacy.activity');
Route::get('/search.php', fn () => redirect()->route('admin.search', request()->query()))->middleware(['auth', 'role:admin'])->name('legacy.search');
Route::middleware('auth')->group(function (): void {
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllRead'])->name('notifications.mark-all-read');
    Route::post('/notifications/{notificationId}/read', [NotificationController::class, 'markRead'])->whereNumber('notificationId')->name('notifications.read');
});
Route::get('/notifications.php', fn () => redirect()->route('notifications.index', request()->query()))->middleware('auth')->name('legacy.notifications');
Route::get('/shipment_add.php', fn () => redirect()->route('shipments.add'))->middleware(['auth', 'role:admin,staff'])->name('legacy.shipment-add');
Route::get('/shipment_edit.php', function () {
    $shipmentId = request()->integer('id');
    abort_if($shipmentId < 1, 404, 'Shipment not found.');

    return redirect()->route('shipments.edit', ['shipmentId' => $shipmentId]);
})->middleware(['auth', 'role:admin,staff'])->name('legacy.shipment-edit');
Route::get('/shipment_view.php', function () {
    $shipmentId = request()->integer('id');
    abort_if($shipmentId < 1, 404, 'Shipment not found.');

    return redirect()->route('shipments.show', ['shipmentId' => $shipmentId]);
})->middleware(['auth', 'role:admin,staff'])->name('legacy.shipment-view');

Route::middleware(['auth', 'role:client'])->prefix('client')->name('client.')->group(function (): void {
    Route::get('/dashboard', [ClientDashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/track', [ClientDashboardController::class, 'track'])->name('dashboard.track');
    Route::get('/shipments', [ClientShipmentController::class, 'index'])->name('shipments');
    Route::get('/shipments/{shipmentId}', [ClientShipmentController::class, 'show'])->whereNumber('shipmentId')->name('shipments.show');
    Route::get('/profile', [ClientProfileController::class, 'show'])->name('profile');
    Route::post('/profile/contact', [ClientProfileController::class, 'updateContact'])->name('profile.contact');
    // Client document uploads and shipment requests
    Route::post('/shipments/{shipmentId}/documents', [DocumentWorkflowController::class, 'clientUpload'])->whereNumber('shipmentId')->middleware('throttle:30,1')->name('shipments.documents.upload');
    Route::delete('/documents/{documentId}', [DocumentWorkflowController::class, 'clientWithdraw'])->whereNumber('documentId')->name('documents.withdraw');
    Route::get('/shipment-requests', [ShipmentRequestController::class, 'clientIndex'])->name('shipment-requests');
    Route::post('/shipment-requests', [ShipmentRequestController::class, 'clientStore'])->middleware('throttle:10,1')->name('shipment-requests.store');
    Route::post('/shipment-requests/{requestId}/cancel', [ShipmentRequestController::class, 'clientCancel'])->whereNumber('requestId')->name('shipment-requests.cancel');
    // ID recognition (client onboarding)
    Route::get('/identity', [ClientIdentityVerificationController::class, 'show'])->name('identity');
    Route::post('/identity', [ClientIdentityVerificationController::class, 'store'])->middleware('throttle:5,1')->name('identity.store');
    Route::get('/identity/status', [ClientIdentityVerificationController::class, 'status'])->middleware('throttle:60,1')->name('identity.status');
    // Live ID + selfie check on ID Analyzer DocuPass
    Route::post('/identity/live', [ClientIdentityVerificationController::class, 'startLive'])->middleware('throttle:5,1')->name('identity.live');
    Route::get('/identity/live/return', [ClientIdentityVerificationController::class, 'returnFromLive'])->middleware('throttle:20,1')->name('identity.live.return');
    Route::post('/identity/live/check', [ClientIdentityVerificationController::class, 'checkLive'])->middleware('throttle:10,1')->name('identity.live.check');
});

// ID Analyzer webhook (signed with IDANALYZER_WEBHOOK_SECRET; no session or CSRF token)
Route::post('/webhooks/idanalyzer', IdAnalyzerWebhookController::class)
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
    ->middleware('throttle:120,1')->name('webhooks.idanalyzer');

// Admin review of client IDs
Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/identity', [AdminIdentityVerificationController::class, 'index'])->name('identity.index');
    Route::get('/identity/{verificationId}', [AdminIdentityVerificationController::class, 'show'])->whereNumber('verificationId')->name('identity.show');
    Route::get('/identity/{verificationId}/image', [AdminIdentityVerificationController::class, 'image'])->whereNumber('verificationId')->name('identity.image');
    Route::post('/identity/{verificationId}/decision', [AdminIdentityVerificationController::class, 'decide'])->whereNumber('verificationId')->name('identity.decide');
});

// Staff side of client documents and shipment requests
Route::middleware(['auth', 'role:admin,staff'])->group(function (): void {
    Route::post('/shipment-documents/{documentId}/review', [DocumentWorkflowController::class, 'review'])->whereNumber('documentId')->name('documents.review');
    Route::post('/shipments/{shipmentId}/document-requests', [DocumentWorkflowController::class, 'requestDocument'])->whereNumber('shipmentId')->name('documents.request');
    Route::post('/document-requests/{requestId}/cancel', [DocumentWorkflowController::class, 'cancelRequest'])->whereNumber('requestId')->name('documents.request.cancel');
    Route::get('/shipment-requests', [ShipmentRequestController::class, 'index'])->name('shipment-requests.index');
    Route::post('/shipment-requests/{requestId}/decline', [ShipmentRequestController::class, 'decline'])->whereNumber('requestId')->name('shipment-requests.decline');
});
Route::middleware(['auth', 'role:admin,staff,client'])->group(function (): void {
    Route::post('/shipment-documents/{documentId}/ai-check', [DocumentWorkflowController::class, 'aiCheck'])->whereNumber('documentId')->middleware('throttle:20,1')->name('documents.ai-check');
    Route::post('/shipment-requests/{requestId}/extract', [ShipmentRequestController::class, 'extract'])->whereNumber('requestId')->middleware('throttle:10,1')->name('shipment-requests.extract');
    Route::get('/shipment-requests/{requestId}/file', [ShipmentRequestController::class, 'file'])->whereNumber('requestId')->name('shipment-requests.file');
});
Route::get('/client_dashbroad.php', function () {
    if (request()->query('ajax') === 'track') {
        return redirect()->route('client.dashboard.track', ['q' => request()->query('q')]);
    }

    return redirect()->route('client.dashboard', request()->except('ajax'));
})->middleware(['auth', 'role:client'])->name('legacy.client-dashboard');
Route::get('/clint_shipment.php', function () {
    $shipmentId = request()->integer('id');
    if ($shipmentId > 0) {
        return redirect()->route('client.shipments.show', ['shipmentId' => $shipmentId]);
    }

    return redirect()->route('client.shipments', request()->except(['id', 'page']));
})->middleware(['auth', 'role:client'])->name('legacy.client-shipments');
Route::get('/client_profile.php', fn () => redirect()->route('client.profile'))
    ->middleware(['auth', 'role:client'])->name('legacy.client-profile');
Route::get('/client_activity.php', fn () => redirect()->route('admin.activity.shipments'))
    ->middleware(['auth', 'role:admin'])->name('legacy.client-activity-admin');

/* Direct compatibility routes retain the original nested .php paths. */
Route::get('/account-management/admin.php', [AdminDashboardController::class, 'index'])->middleware('legacy.role:admin');
Route::get('/account-management/staff.php', function (Request $request, StaffDashboardController $controller) {
    return $request->query('ajax') === 'track'
        ? $controller->track($request)
        : $controller->index($request);
})->middleware('legacy.role:staff');
Route::get('/account-management/user_management.php', [UserManagementController::class, 'index'])->middleware('legacy.role:admin');
Route::post('/account-management/user_management.php', [UserManagementController::class, 'save'])->middleware('legacy.role:admin');

Route::get('/client-management/client_dashbroad.php', function (Request $request, ClientDashboardController $controller) {
    return $request->query('ajax') === 'track'
        ? $controller->track($request)
        : $controller->index($request);
})->middleware('legacy.role:client');
Route::get('/client-management/clint_shipment.php', function (Request $request, ClientShipmentController $controller) {
    return $request->integer('id') > 0
        ? $controller->show($request, $request->integer('id'), app(ShipmentStageService::class), app(ShipmentDocumentService::class))
        : $controller->index($request, app(ShipmentStageService::class));
})->middleware('legacy.role:client');
Route::get('/client-management/client_profile.php', [ClientProfileController::class, 'show'])->middleware('legacy.role:client');
Route::get('/client-management/client_billing.php', [BillingController::class, 'index'])->middleware('legacy.role:client');
Route::post('/client-management/client_billing.php', [BillingController::class, 'recordPayment'])->middleware('legacy.role:client');
Route::get('/client-management/client_transactions.php', [TransactionController::class, 'index'])->middleware('legacy.role:client');
Route::post('/client-management/client_transactions.php', [TransactionController::class, 'store'])->middleware('legacy.role:client');
Route::get('/client-management/client_activity.php', [ActivityController::class, 'shipmentUpdates'])->middleware(['auth', 'role:admin']);
Route::get('/client-management/clients.php', [ClientManagementController::class, 'index'])->middleware('legacy.role:admin,staff');
Route::post('/client-management/clients.php', [ClientManagementController::class, 'save'])->middleware('legacy.role:admin');

Route::get('/billing-management/billing.php', [BillingController::class, 'index'])->middleware('legacy.role:admin,staff');
Route::post('/billing-management/billing.php', [BillingController::class, 'storeBill'])->middleware('legacy.role:admin,staff');
Route::get('/billing-management/transactions.php', [TransactionController::class, 'index'])->middleware('legacy.role:admin,staff');
Route::post('/billing-management/transactions.php', [TransactionController::class, 'store'])->middleware('legacy.role:admin,staff');

Route::get('/shipment-management/shipments.php', function (Request $request) {
    if ($request->user()->role === 'staff') {
        return redirect('/shipment-management/staff_shipment.php');
    }

    return app(AdminShipmentManagementController::class)->index($request);
})->middleware('legacy.role:admin,staff');
Route::get('/shipment-management/staff_shipment.php', [ShipmentManagementController::class, 'index'])->middleware('legacy.role:staff');
Route::get('/shipment-management/shipment_add.php', [ShipmentCreationController::class, 'create'])->middleware('legacy.role:admin,staff');
Route::post('/shipment-management/shipment_add.php', function (Request $request, ShipmentCreationController $controller, GeminiExtractor $extractor) {
    return match ((string) $request->query('ajax')) {
        'check-bl' => $controller->checkBl($request),
        'extract' => $controller->extract($request, $extractor),
        default => $controller->store($request),
    };
})->middleware('legacy.role:admin,staff');
Route::get('/shipment-management/shipment_edit.php', function (Request $request, ShipmentEditController $controller) {
    abort_if($request->integer('id') < 1, 404, 'Shipment not found.');

    return $controller->edit($request, $request->integer('id'));
})->middleware('legacy.role:admin,staff');
Route::post('/shipment-management/shipment_edit.php', function (Request $request, ShipmentEditController $controller) {
    abort_if($request->integer('id') < 1, 404, 'Shipment not found.');

    return $controller->update($request, $request->integer('id'));
})->middleware('legacy.role:admin,staff');
Route::get('/shipment-management/shipment_view.php', function (Request $request, ShipmentDetailsController $controller) {
    abort_if($request->integer('id') < 1, 404, 'Shipment not found.');

    return $controller->show($request, $request->integer('id'), app(ShipmentDocumentService::class));
})->middleware('legacy.role:admin,staff');
Route::post('/shipment-management/shipment_view.php', function (Request $request, ShipmentDetailsController $details, ShipmentStatusController $status) {
    abort_if($request->integer('id') < 1, 404, 'Shipment not found.');

    return match ((string) $request->input('action')) {
        'reassign' => $details->reassign($request, $request->integer('id')),
        'save_customs' => $details->saveCustoms($request, $request->integer('id')),
        'save_delivery' => $details->saveDelivery($request, $request->integer('id')),
        default => $status->update($request, $request->integer('id')),
    };
})->middleware('legacy.role:admin,staff');
Route::post('/shipment-management/shipment_assignment.php', [AdminShipmentManagementController::class, 'assign'])->middleware(['auth', 'role:admin']);
Route::get('/shipment-management/shipment_audit.php', [ShipmentAuditController::class, 'show'])->middleware(['auth', 'role:admin,staff']);
Route::get('/shipment-management/shipment_details.php', function (Request $request) {
    $id = $request->integer('id');
    if ($request->user()->role === 'client') {
        return redirect($id > 0 ? '/client-management/clint_shipment.php?id='.$id : '/client-management/clint_shipment.php', 303);
    }
    if ($id < 1) {
        $list = $request->user()->role === 'admin' ? '/shipment-management/shipments.php' : '/shipment-management/staff_shipment.php';

        return redirect($list, 303);
    }

    return redirect('/shipment-management/shipment_view.php?id='.$id, 303);
})->middleware('legacy.role:admin,staff,client');
Route::get('/shipment-management/shipment_summary.php', [ShipmentSummaryController::class, 'show'])->middleware('legacy.role:admin,staff');
Route::post('/shipment-management/shipment_status.php', function (Request $request, ShipmentStatusController $controller) {
    return $controller->update($request, $request->integer('shipment_id'));
})->middleware(['auth', 'role:admin,staff']);
Route::post('/shipment-management/shipment_lifecycle.php', [ShipmentLifecycleController::class, 'update'])->middleware(['auth', 'role:admin,staff']);
Route::post('/shipment-management/shipment_documents_upload.php', function (Request $request, ShipmentDocumentController $controller) {
    return $controller->legacyStore($request, $request->integer('shipment_id'), app(ShipmentDocumentService::class), app(ActivityLogger::class), app(NotificationService::class));
})->middleware(['auth', 'role:admin,staff']);
Route::get('/shipment-management/shipment_documents_download.php', function (Request $request, ShipmentDocumentController $controller) {
    abort_if($request->integer('shipment_id') < 1, 404, 'Shipment not found.');

    return $controller->downloadAll($request, $request->integer('shipment_id'), app(ShipmentDocumentService::class));
})->middleware('legacy.role:admin,staff,client');
Route::post('/shipment-management/shipment_documents_delete.php', function (Request $request, ShipmentDocumentController $controller) {
    $documentId = $request->integer('document_id', $request->integer('id'));
    abort_if($documentId < 1, 404, 'Document not found.');

    return $controller->legacyDestroy($request, $documentId, app(ShipmentDocumentService::class), app(ActivityLogger::class), app(NotificationService::class));
})->middleware(['auth', 'role:admin']);
Route::get('/shipment-management/shipment_document.php', function (Request $request, ShipmentDocumentController $controller) {
    abort_if($request->integer('id') < 1, 404, 'Document not found.');

    return $controller->download($request, $request->integer('id'), app(ShipmentDocumentService::class));
})->middleware('legacy.role:admin,staff,client');

Route::get('/operations/activity.php', [ActivityController::class, 'index'])->middleware(['auth', 'role:admin']);
Route::get('/operations/notifications.php', [NotificationController::class, 'index'])->middleware('legacy.role:admin,staff,client');
Route::post('/operations/notifications.php', [NotificationController::class, 'markAllRead'])->middleware('legacy.role:admin,staff,client');
Route::post('/operations/notifications_action.php', [NotificationController::class, 'legacyAction'])->middleware('auth');
Route::get('/operations/reports.php', [ReportController::class, 'index'])->middleware(['auth', 'role:admin']);
Route::get('/operations/search.php', [SearchController::class, 'index'])->middleware(['auth', 'role:admin']);
Route::get('/dashboard.php', [AuthController::class, 'dashboard'])->middleware('auth');
// Old bookmarks/links: no longer signs out (a GET logout can be triggered by any site). Logout is POST /logout.
Route::get('/logout.php', fn () => redirect(auth()->check() ? '/dashboard.php' : '/login'));
Route::get('/process.php', fn () => response('', 200));
