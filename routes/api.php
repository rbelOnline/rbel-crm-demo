<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AutomationController;
use App\Http\Controllers\CalendarLabelController;
use App\Http\Controllers\BeneficiaryController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientLookupController;
use App\Http\Controllers\ClientDocumentController;
use App\Http\Controllers\DocumentTemplateController;
use App\Http\Controllers\CoverageDocumentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmailImageController;
use App\Http\Controllers\EmailLogController;
use App\Http\Controllers\EmailTemplateController;
use App\Http\Controllers\FundTypeController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\OwnerLookupController;
use App\Http\Controllers\MailSettingsController;
use App\Http\Controllers\MetaController;
use App\Http\Controllers\PolicyController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReminderController;
use App\Http\Controllers\ScheduleItemController;
use Illuminate\Support\Facades\Route;

/*
| RBEL-CRM API. The SPA authenticates with Sanctum's cookie-based session
| (statefulApi), so every state-changing request is CSRF-protected.
| `can:manage` = admin or advisor; `can:admin` = admin only.
*/

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/user', [AuthController::class, 'me'])->name('user');

    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'password'])->middleware('throttle:6,1')->name('profile.password');

    Route::get('/meta', MetaController::class)->name('meta');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/clients/lookup', ClientLookupController::class)->name('clients.lookup');
    Route::get('/owner-lookup', OwnerLookupController::class)->name('owner-lookup');

    // Excel exports; registered before the resources so "export" is not taken as an {id}.
    Route::get('/clients/export', [ClientController::class, 'export'])->name('clients.export');
    Route::get('/leads/export', [LeadController::class, 'export'])->name('leads.export');
    Route::get('/policies/export', [PolicyController::class, 'export'])->name('policies.export');

    // Products (plans): everyone can view; admins and advisors manage.
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');
    Route::middleware('can:manage')->group(function () {
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
        Route::post('/products/bulk-delete', [ProductController::class, 'bulkDestroy'])->name('products.bulk-destroy');
    });

    // Fund types (dropdown on client records): everyone can view; admins and advisors manage.
    Route::get('/fund-types', [FundTypeController::class, 'index'])->name('fund-types.index');
    Route::get('/fund-types/{fund_type}', [FundTypeController::class, 'show'])->name('fund-types.show');
    Route::middleware('can:manage')->group(function () {
        Route::post('/fund-types', [FundTypeController::class, 'store'])->name('fund-types.store');
        Route::put('/fund-types/{fund_type}', [FundTypeController::class, 'update'])->name('fund-types.update');
        Route::delete('/fund-types/{fund_type}', [FundTypeController::class, 'destroy'])->name('fund-types.destroy');
        Route::post('/fund-types/bulk-delete', [FundTypeController::class, 'bulkDestroy'])->name('fund-types.bulk-destroy');
    });

    // Mass delete: same rules as single delete, manage only.
    Route::middleware('can:manage')->group(function () {
        Route::post('/clients/bulk-delete', [ClientController::class, 'bulkDestroy'])->name('clients.bulk-destroy');
        Route::post('/leads/bulk-delete', [LeadController::class, 'bulkDestroy'])->name('leads.bulk-destroy');
        Route::post('/policies/bulk-delete', [PolicyController::class, 'bulkDestroy'])->name('policies.bulk-destroy');
        Route::post('/appointments/bulk-delete', [AppointmentController::class, 'bulkDestroy'])->name('appointments.bulk-destroy');
        Route::post('/email-templates/bulk-delete', [EmailTemplateController::class, 'bulkDestroy'])->name('email-templates.bulk-destroy');
    });

    Route::apiResource('clients', ClientController::class)
        ->parameters(['clients' => 'client'])
        ->except('destroy');
    Route::delete('/clients/{client}', [ClientController::class, 'destroy'])->middleware('can:manage')->name('clients.destroy');

    Route::apiResource('leads', LeadController::class)->except('destroy');
    Route::post('/leads/{lead}/convert', [LeadController::class, 'convert'])->name('leads.convert');
    Route::delete('/leads/{lead}', [LeadController::class, 'destroy'])->middleware('can:manage')->name('leads.destroy');

    Route::apiResource('policies', PolicyController::class)->except('destroy');
    Route::delete('/policies/{policy}', [PolicyController::class, 'destroy'])->middleware('can:manage')->name('policies.destroy');

    Route::scopeBindings()->group(function () {
        Route::get('/policies/{policy}/beneficiaries', [BeneficiaryController::class, 'index'])->name('policies.beneficiaries.index');
        Route::post('/policies/{policy}/beneficiaries', [BeneficiaryController::class, 'store'])->name('policies.beneficiaries.store');
        Route::put('/policies/{policy}/beneficiaries/{beneficiary}', [BeneficiaryController::class, 'update'])->name('policies.beneficiaries.update');
        Route::delete('/policies/{policy}/beneficiaries/{beneficiary}', [BeneficiaryController::class, 'destroy'])->middleware('can:manage')->name('policies.beneficiaries.destroy');
    });

    // Documents module (templates): everyone can view and download; admins and advisors manage.
    Route::get('/document-templates', [DocumentTemplateController::class, 'index'])->name('document-templates.index');
    Route::get('/document-templates/{document_template}', [DocumentTemplateController::class, 'show'])->name('document-templates.show');
    Route::get('/document-templates/{document_template}/download', [DocumentTemplateController::class, 'download'])->name('document-templates.download');
    Route::middleware('can:manage')->group(function () {
        Route::post('/document-templates', [DocumentTemplateController::class, 'store'])->middleware('throttle:uploads')->name('document-templates.store');
        // POST (multipart, with _method=PUT) so a replacement file can be sent.
        Route::put('/document-templates/{document_template}', [DocumentTemplateController::class, 'update'])->middleware('throttle:uploads')->name('document-templates.update');
        Route::put('/document-templates/{document_template}/pdf-fields', [DocumentTemplateController::class, 'savePdfFields'])->name('document-templates.pdf-fields');
        Route::delete('/document-templates/{document_template}', [DocumentTemplateController::class, 'destroy'])->name('document-templates.destroy');
        Route::post('/document-templates/bulk-delete', [DocumentTemplateController::class, 'bulkDestroy'])->name('document-templates.bulk-destroy');
    });

    // Documents on a client record.
    Route::scopeBindings()->group(function () {
        Route::get('/policies/{policy}/documents', [ClientDocumentController::class, 'index'])->name('policies.documents.index');
        Route::post('/policies/{policy}/documents', [ClientDocumentController::class, 'store'])->middleware('throttle:uploads')->name('policies.documents.store');
        Route::put('/policies/{policy}/documents/{document}', [ClientDocumentController::class, 'update'])->middleware('throttle:uploads')->name('policies.documents.update');
        Route::get('/policies/{policy}/documents/fields', [ClientDocumentController::class, 'fields'])->name('policies.documents.fields');
        Route::get('/policies/{policy}/documents/{document}/content', [ClientDocumentController::class, 'content'])->name('policies.documents.content');
        Route::put('/policies/{policy}/documents/{document}/content', [ClientDocumentController::class, 'saveContent'])->name('policies.documents.content.save');
        Route::get('/policies/{policy}/documents/{document}/download',[ClientDocumentController::class, 'download'])->name('policies.documents.download');
        Route::get('/policies/{policy}/documents/{document}/pdf-source', [ClientDocumentController::class, 'pdfSource'])->name('policies.documents.pdf-source');
        // POST (multipart, with _method=PUT): the stamped PDF is sent with the fields.
        Route::put('/policies/{policy}/documents/{document}/pdf', [ClientDocumentController::class, 'savePdf'])->middleware('throttle:uploads')->name('policies.documents.pdf.save');
        Route::delete('/policies/{policy}/documents/{document}', [ClientDocumentController::class, 'destroy'])->middleware('can:manage')->name('policies.documents.destroy');
    });

    Route::post('/policies/{policy}/document',[CoverageDocumentController::class, 'store'])->middleware('throttle:uploads')->name('policies.document.store');
    Route::get('/policies/{policy}/document', [CoverageDocumentController::class, 'show'])->name('policies.document.show');
    Route::get('/policies/{policy}/document/download', [CoverageDocumentController::class, 'download'])->name('policies.document.download');
    Route::delete('/policies/{policy}/document', [CoverageDocumentController::class, 'destroy'])->middleware('can:manage')->name('policies.document.destroy');

    // Personal schedule on the Calendar (each user's own).
    Route::post('/schedule-items/{scheduleItem}/skip', [ScheduleItemController::class, 'skip'])->name('schedule-items.skip');
    Route::apiResource('schedule-items', ScheduleItemController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['schedule-items' => 'scheduleItem']);

    // Calendar colour label names: everyone reads; admins and advisors rename.
    Route::get('/calendar-labels', [CalendarLabelController::class, 'index'])->name('calendar-labels.index');
    Route::put('/calendar-labels', [CalendarLabelController::class, 'update'])->middleware('can:manage')->name('calendar-labels.update');

    // Before the resource, so "calendar" is not taken as an {appointment}.
    Route::get('/appointments/calendar', [AppointmentController::class, 'calendar'])->name('appointments.calendar');
    Route::apiResource('appointments', AppointmentController::class)->except('destroy');
    Route::delete('/appointments/{appointment}', [AppointmentController::class, 'destroy'])->middleware('can:manage')->name('appointments.destroy');

    Route::apiResource('goals', GoalController::class)->except('destroy');
    Route::delete('/goals/{goal}', [GoalController::class, 'destroy'])->middleware('can:manage')->name('goals.destroy');

    Route::apiResource('reminders', ReminderController::class)->except(['show', 'destroy']);
    Route::patch('/reminders/{reminder}/complete', [ReminderController::class, 'complete'])->name('reminders.complete');
    Route::delete('/reminders/{reminder}', [ReminderController::class, 'destroy'])->middleware('can:manage')->name('reminders.destroy');

    Route::apiResource('email-templates', EmailTemplateController::class)
        ->parameters(['email-templates' => 'email_template'])
        ->except('destroy');
    Route::delete('/email-templates/{email_template}', [EmailTemplateController::class, 'destroy'])->middleware('can:manage')->name('email-templates.destroy');
    Route::post('/email-templates/{email_template}/duplicate', [EmailTemplateController::class, 'duplicate'])->name('email-templates.duplicate');
    Route::post('/email-templates/{email_template}/preview', [EmailTemplateController::class, 'preview'])->name('email-templates.preview');
    Route::post('/email-templates/{email_template}/send', [EmailTemplateController::class, 'send'])
        ->middleware(['can:manage', 'throttle:emails'])
        ->name('email-templates.send');
    Route::get('/email-logs', [EmailLogController::class, 'index'])->name('email-logs.index');

    // Template images: upload returns an {{image:ID}} token; show serves it to authenticated previews.
    Route::post('/email-images', [EmailImageController::class, 'store'])->middleware('throttle:uploads')->name('email-images.store');
    Route::get('/email-images/{emailImage}', [EmailImageController::class, 'show'])->name('email-images.show');

    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
    Route::get('/analytics/sales', [AnalyticsController::class, 'sales'])->name('analytics.sales');
    Route::get('/analytics/age-distribution', [AnalyticsController::class, 'ageDistribution'])->name('analytics.age');

    Route::middleware('can:admin')->group(function () {
        Route::get('/mail-settings', [MailSettingsController::class, 'show'])->name('mail-settings.show');
        Route::put('/mail-settings', [MailSettingsController::class, 'update'])->name('mail-settings.update');
        Route::post('/mail-settings/test', [MailSettingsController::class, 'test'])->middleware('throttle:emails')->name('mail-settings.test');

        Route::get('/automations', [AutomationController::class, 'index'])->name('automations.index');
        Route::put('/automations/{automation}', [AutomationController::class, 'update'])->name('automations.update');
        Route::get('/automations/{automation}/preview', [AutomationController::class, 'preview'])->name('automations.preview');
        Route::post('/automations/{automation}/run', [AutomationController::class, 'run'])->middleware('throttle:emails')->name('automations.run');

        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
        Route::get('/audit-logs/facets', [AuditLogController::class, 'facets'])->name('audit-logs.facets');
    });
});
