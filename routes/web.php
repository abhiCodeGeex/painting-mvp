<?php

use App\Http\Controllers\Portal\DashboardController;
use App\Http\Controllers\Portal\EnquiryController;
use App\Http\Controllers\Portal\EnquiryLineController;
use App\Http\Controllers\Portal\EnquiryMessageController;
use App\Http\Controllers\Portal\EstimateController;
use App\Http\Controllers\Portal\QuoteController;
use App\Http\Controllers\PrivateMediaController;
use App\Http\Controllers\Settings\AddressController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

// Customer uploads: access-checked (staff, or the customer who owns the enquiry).
Route::get('files/{media}/{conversion?}', PrivateMediaController::class)
    ->middleware('auth')
    ->name('media.show');

Route::get('admin-files/quotes/{quote}/pdf', [QuoteController::class, 'adminPdf'])
    ->middleware('auth')
    ->name('admin.quotes.pdf');

// Customer portal (staff are redirected to /admin by the "customer" middleware, except on the
// enquiry editor routes they use to raise and edit enquiries for a customer)
Route::middleware(['auth', 'verified', 'customer'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::name('portal.')->group(function () {
        Route::get('enquiries', [EnquiryController::class, 'index'])->name('enquiries.index');
        Route::post('enquiries', [EnquiryController::class, 'store'])->name('enquiries.store');
        Route::get('enquiries/{enquiry}', [EnquiryController::class, 'show'])->name('enquiries.show');
        Route::get('enquiries/{enquiry}/edit', [EnquiryController::class, 'edit'])->name('enquiries.edit');
        Route::put('enquiries/{enquiry}', [EnquiryController::class, 'update'])->name('enquiries.update');
        Route::delete('enquiries/{enquiry}', [EnquiryController::class, 'destroy'])->name('enquiries.destroy');
        Route::post('enquiries/{enquiry}/submit', [EnquiryController::class, 'submit'])->name('enquiries.submit');
        Route::post('enquiries/{enquiry}/cancel', [EnquiryController::class, 'cancel'])->name('enquiries.cancel');
        Route::post('enquiries/{enquiry}/estimate', EstimateController::class)->middleware('throttle:120,1')->name('enquiries.estimate');
        Route::get('enquiries/{enquiry}/quotes/{quote}/pdf', [QuoteController::class, 'pdf'])->name('enquiries.quotes.pdf');
        Route::post('enquiries/{enquiry}/quotes/{quote}/approve', [QuoteController::class, 'approve'])->name('enquiries.quotes.approve');
        Route::post('enquiries/{enquiry}/quotes/{quote}/changes', [QuoteController::class, 'requestChanges'])->name('enquiries.quotes.changes');
        Route::post('enquiries/{enquiry}/quotes/{quote}/reject', [QuoteController::class, 'reject'])->name('enquiries.quotes.reject');
        Route::post('enquiries/{enquiry}/quotes/{quote}/requote', [QuoteController::class, 'requote'])->name('enquiries.quotes.requote');

        // Lines use POST for updates too: they carry file uploads (multipart).
        Route::post('enquiries/{enquiry}/lines', [EnquiryLineController::class, 'store'])->name('enquiries.lines.store');
        Route::post('enquiries/{enquiry}/lines/{line}', [EnquiryLineController::class, 'update'])->name('enquiries.lines.update');
        Route::post('enquiries/{enquiry}/lines/{line}/duplicate', [EnquiryLineController::class, 'duplicate'])->name('enquiries.lines.duplicate');
        Route::delete('enquiries/{enquiry}/lines/{line}', [EnquiryLineController::class, 'destroy'])->name('enquiries.lines.destroy');

        Route::post('enquiries/{enquiry}/addresses', [AddressController::class, 'storeForEnquiry'])->name('enquiries.addresses.store');

        Route::post('enquiries/{enquiry}/messages', [EnquiryMessageController::class, 'store'])->name('enquiries.messages.store');
    });

    Route::get('settings/addresses', [AddressController::class, 'index'])->name('addresses.index');
    Route::post('settings/addresses', [AddressController::class, 'store'])->name('addresses.store');
    Route::put('settings/addresses/{address}', [AddressController::class, 'update'])->name('addresses.update');
    Route::delete('settings/addresses/{address}', [AddressController::class, 'destroy'])->name('addresses.destroy');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

if (app()->environment('local')) {
    require __DIR__.'/dev.php';
}
