<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\TicketTypeController;
use App\Http\Controllers\TicketPricePeriodController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\PlatformSettingsController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\AttendeeController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\SpecialtyController;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/avatar-upload', [AuthController::class, 'avatarUpload']);
    Route::post('/bootstrap-admin', [AuthController::class, 'bootstrapAdmin']);

    // Protected routes
    Route::middleware('auth:api')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::patch('/me', [AuthController::class, 'patchMe']);
        Route::patch('/me/password', [AuthController::class, 'patchPassword']);
        Route::post('/me/avatar-upload', [AuthController::class, 'meAvatarUpload']);
        Route::delete('/me/avatar', [AuthController::class, 'deleteAvatar']);
    });
});

Route::middleware('auth:api')->group(function () {
    Route::prefix('users')->group(function () {
        Route::get('/', [UserController::class, 'index'])->middleware('permission:users.manage');
        Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:roles.manage');
        Route::put('/roles/{roleCode}/permissions', [RoleController::class, 'updatePermissions'])->middleware('permission:roles.manage');
        Route::post('/avatar-upload', [UserController::class, 'avatarUpload'])->middleware('permission:users.manage');
        Route::get('/{id}', [UserController::class, 'show'])->middleware('permission:users.manage');
        Route::post('/', [UserController::class, 'store'])->middleware('permission:users.manage');
        Route::put('/{id}', [UserController::class, 'update'])->middleware('permission:users.manage');
        Route::patch('/{id}/status', [UserController::class, 'updateStatus'])->middleware('permission:users.manage');
        Route::patch('/{id}/password', [UserController::class, 'updatePassword'])->middleware('permission:users.manage');
        Route::post('/{id}/impersonate', [UserController::class, 'impersonate'])->middleware('permission:users.manage');
    });
});

Route::get('/specialties', [SpecialtyController::class, 'index']);
Route::middleware(['auth:api', 'permission:settings.manage'])->prefix('specialties')->group(function () {
    Route::post('/', [SpecialtyController::class, 'store']);
    Route::put('/{id}', [SpecialtyController::class, 'update']);
    Route::patch('/{id}/status', [SpecialtyController::class, 'updateStatus']);
    Route::delete('/{id}', [SpecialtyController::class, 'destroy']);
});

// Phase C Routes

// Public Event endpoints (Phase C & D)
Route::prefix('public/events')->group(function () {
    Route::get('/', [\App\Http\Controllers\PublicEventController::class, 'index']);
    Route::get('/{slug}', [\App\Http\Controllers\PublicEventController::class, 'show']);
    Route::get('/{slug}/reviews', [\App\Http\Controllers\PublicEventController::class, 'reviews']);

    // Phase C - Reviews write endpoints
    Route::get('/{slug}/review-eligibility', [\App\Http\Controllers\PublicEventController::class, 'reviewEligibility'])->middleware('auth:api');
    Route::post('/{slug}/review', [\App\Http\Controllers\PublicEventController::class, 'storeReview'])->middleware('auth:api');
    Route::patch('/{slug}/review', [\App\Http\Controllers\PublicEventController::class, 'updateReview'])->middleware('auth:api');

    // Phase D - Checkout
    Route::post('/{slug}/checkout', [\App\Http\Controllers\PublicCheckoutController::class, 'checkout']);
});

// Phase G - Booking (Event Brief Requests)
Route::post('/booking', [\App\Http\Controllers\BookingController::class, 'store']);

// Phase D - Registration lookup
Route::get('public/events/registrations/{reference}', [\App\Http\Controllers\PublicCheckoutController::class, 'getRegistration']);

// Admin & Public Events (Auth handled conditionally inside controller)
Route::get('/events', [EventController::class, 'index']);
Route::get('/events/{id}', [EventController::class, 'show']);

Route::middleware('auth:api')->group(function () {
    Route::post('/events', [EventController::class, 'store'])->middleware('permission:events.manage');
    Route::put('/events/{id}', [EventController::class, 'update'])->middleware('permission:events.manage');
    Route::patch('/events/{id}/status', [EventController::class, 'updateStatus'])->middleware('permission:events.manage');
    Route::delete('/events/{id}', [EventController::class, 'destroy'])->middleware('permission:events.manage');
    Route::post('/events/{id}/restore', [EventController::class, 'restore'])->middleware('permission:events.manage');
});

// Tickets & Pricing
Route::get('/tickets', [TicketTypeController::class, 'index']);
Route::middleware('auth:api')->group(function () {
    Route::post('/tickets', [TicketTypeController::class, 'store'])->middleware('permission:tickets.manage');
    Route::put('/tickets/{id}', [TicketTypeController::class, 'update'])->middleware('permission:tickets.manage');
    Route::patch('/tickets/{id}/status', [TicketTypeController::class, 'updateStatus'])->middleware('permission:tickets.manage');
    Route::delete('/tickets/{id}', [TicketTypeController::class, 'destroy'])->middleware('permission:tickets.manage');

    Route::post('/tickets/price-periods', [TicketPricePeriodController::class, 'store'])->middleware('permission:pricing.manage');
    Route::put('/tickets/price-periods/{id}', [TicketPricePeriodController::class, 'update'])->middleware('permission:pricing.manage');
    Route::patch('/tickets/price-periods/{id}/status', [TicketPricePeriodController::class, 'updateStatus'])->middleware('permission:pricing.manage');
    Route::delete('/tickets/price-periods/{id}', [TicketPricePeriodController::class, 'destroy'])->middleware('permission:pricing.manage');
});

// GET Price periods handles auth conditionally inside the controller (like node) so it's not grouped by auth:api
Route::get('/tickets/{ticketTypeId}/price-periods', [TicketPricePeriodController::class, 'index']);

// Reviews Admin
Route::middleware(['auth:api', 'any_permission:reviews.view,reviews.manage'])->group(function () {
    Route::get('/reviews', [ReviewController::class, 'index']);
    Route::get('/reviews/{id}', [ReviewController::class, 'show']);
});

Route::middleware(['auth:api', 'permission:reviews.manage'])->group(function () {
    Route::patch('/reviews/{id}/status', [ReviewController::class, 'updateStatus']);
    Route::delete('/reviews/{id}', [ReviewController::class, 'destroy']);
});

// Phase E: Public Registrations
Route::post('/registrations', [RegistrationController::class, 'store']);
Route::patch('/registrations/{id}/payment-proof', [RegistrationController::class, 'updatePaymentProof']);

// Phase E: Protected Registrations & Attendees
Route::middleware('auth:api')->group(function () {
    Route::get('/registrations', [RegistrationController::class, 'index']);
    Route::get('/registrations/{id}', [RegistrationController::class, 'show']);
    Route::post('/registrations/manual', [RegistrationController::class, 'storeManual']);
    Route::patch('/registrations/{id}/payment-review', [RegistrationController::class, 'reviewPayment']);
    Route::patch('/registrations/{id}/review', [RegistrationController::class, 'reviewRegistration']);
    Route::patch('/registrations/{id}/order-status', [RegistrationController::class, 'updateOrderStatus']);

    Route::get('/attendees', [AttendeeController::class, 'index']);
    Route::get('/attendees/{id}', [AttendeeController::class, 'show']);
    Route::post('/attendees', [AttendeeController::class, 'store']);
    Route::post('/attendees/checkin', [AttendeeController::class, 'checkin']);
    Route::patch('/attendees/{id}/qr-status', [AttendeeController::class, 'updateQrStatus']);

});

// Doctors are public in the legacy Node API. Authenticated staff still get event-scoped history.
Route::get('/doctors', [\App\Http\Controllers\DoctorController::class, 'index']);
Route::get('/doctors/lookup/profile', [\App\Http\Controllers\DoctorController::class, 'lookupProfile']);
Route::get('/doctors/{id}', [\App\Http\Controllers\DoctorController::class, 'show']);
Route::post('/doctors', [\App\Http\Controllers\DoctorController::class, 'store']);

// Phase G: Contact Inquiries
Route::post('/contact-inquiries', [\App\Http\Controllers\ContactInquiryController::class, 'store']);
Route::middleware(['auth:api', 'permission:contact_inquiries.manage'])->group(function () {
    Route::get('/contact-inquiries', [\App\Http\Controllers\ContactInquiryController::class, 'index']);
    Route::get('/contact-inquiries/{id}', [\App\Http\Controllers\ContactInquiryController::class, 'show']);
    Route::patch('/contact-inquiries/{id}', [\App\Http\Controllers\ContactInquiryController::class, 'update']);
});

// Phase G: Kiosk
Route::post('/kiosk/search', [\App\Http\Controllers\KioskController::class, 'search'])
    ->middleware(['auth:api', 'permission:kiosk.use']);

// Phase G: Reports
Route::middleware(['auth:api', 'permission:reports.view'])->group(function () {
    Route::get('/reports/summary', [\App\Http\Controllers\ReportController::class, 'summary']);
    Route::get('/reports/registrations', [\App\Http\Controllers\ReportController::class, 'registrations']);
    Route::get('/reports/nationalities', [\App\Http\Controllers\ReportController::class, 'nationalities']);
    Route::get('/reports/specialties', [\App\Http\Controllers\ReportController::class, 'specialties']);
    Route::get('/reports/ticket-performance', [\App\Http\Controllers\ReportController::class, 'ticketPerformance']);
});

// Phase G: Certificates
Route::middleware(['auth:api'])->group(function () {
    Route::get('/certificates/templates', [\App\Http\Controllers\CertificateController::class, 'getTemplates'])
        ->middleware('permission:certificates.view,certificates.manage');
    Route::get('/certificates/delivery', [\App\Http\Controllers\CertificateController::class, 'getDelivery'])
        ->middleware('permission:certificates.view,certificates.manage');
    Route::post('/certificates/templates', [\App\Http\Controllers\CertificateController::class, 'storeTemplate'])
        ->middleware('permission:certificates.manage');
    Route::patch('/certificates/templates/{id}/status', [\App\Http\Controllers\CertificateController::class, 'updateTemplateStatus'])
        ->middleware('permission:certificates.manage');
    Route::post('/certificates/issue', [\App\Http\Controllers\CertificateController::class, 'issue'])
        ->middleware('permission:certificates.manage');
    Route::post('/certificates/event-card', [\App\Http\Controllers\CertificateController::class, 'eventCard'])
        ->middleware('permission:certificates.manage');
    Route::post('/certificates/email/bulk', [\App\Http\Controllers\CertificateController::class, 'bulkEmail'])
        ->middleware('permission:certificates.manage');
});

// Platform Settings & Content
Route::get('platform/overview', [PlatformSettingsController::class, 'overview']);
Route::get('/platform/settings/theme', [PlatformSettingsController::class, 'getTheme']);
Route::get('/platform/settings/site-content', [PlatformSettingsController::class, 'getSiteContent']);
Route::get('/platform/settings/currency', [PlatformSettingsController::class, 'getCurrency']);
Route::get('/platform/settings/card-template', [PlatformSettingsController::class, 'getCardTemplate']);

Route::middleware('auth:api')->group(function () {
    Route::put('/platform/settings/theme', [PlatformSettingsController::class, 'updateTheme'])->middleware('permission:theme_identity.manage');
    Route::put('/platform/settings/site-content', [PlatformSettingsController::class, 'updateSiteContent'])->middleware('permission:website_content.manage');
    Route::put('/platform/settings/currency', [PlatformSettingsController::class, 'updateCurrency'])->middleware('permission:settings.manage');
    Route::put('/platform/settings/card-template', [PlatformSettingsController::class, 'updateCardTemplate'])->middleware('permission:certificates.manage');
    Route::post('/platform/assets/upload', [PlatformSettingsController::class, 'uploadAsset'])->middleware('any_permission:website_content.manage,certificates.manage');
});

// Phase F: Customer Dashboard
Route::middleware('auth:api')->prefix('me')->group(function () {
    Route::get('/dashboard', [MeController::class, 'dashboard']);
    Route::get('/events-for-you', [MeController::class, 'eventsForYou']);
    Route::get('/registrations', [MeController::class, 'registrations']);
    Route::get('/registrations/{id}', [MeController::class, 'showRegistration']);
    Route::get('/tickets', [MeController::class, 'tickets']);
    Route::get('/tickets/{id}', [MeController::class, 'showTicket']);
    Route::get('/tickets/{id}/qr', [MeController::class, 'ticketQr']);
    Route::get('/certificates', [MeController::class, 'certificates']);
    Route::get('/certificates/{id}', [MeController::class, 'showCertificate']);
    Route::get('/notifications', [MeController::class, 'notifications']);
    Route::patch('/notifications/{id}/read', [MeController::class, 'markNotificationRead']);
    Route::patch('/notifications/read-all', [MeController::class, 'markAllNotificationsRead']);
    Route::get('/reviews', [MeController::class, 'reviews']);
});
