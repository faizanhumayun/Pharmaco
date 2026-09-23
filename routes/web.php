<?php

use App\Http\Controllers\Admin\BusinessController;
use App\Http\Controllers\Admin\BusinessMemberController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\OpeningBalanceController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Business\AuditController;
use App\Http\Controllers\Business\CompanyController;
use App\Http\Controllers\Business\DailyClosingController;
use App\Http\Controllers\Business\DailyEntryController;
use App\Http\Controllers\Business\DashboardController as BusinessDashboardController;
use App\Http\Controllers\Business\ExpenseCategoryController;
use App\Http\Controllers\Business\HistoryController;
use App\Http\Controllers\Business\PosController;
use App\Http\Controllers\Business\TeamController;
use App\Http\Controllers\Business\LedgerController;
use App\Http\Controllers\Business\OrderController;
use App\Http\Controllers\Business\PharmacyController;
use App\Http\Controllers\Business\PriceListImportController;
use App\Http\Controllers\Business\ProductController;
use App\Http\Controllers\Business\StockVerificationController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'));

Route::get('/dashboard', fn () => redirect(auth()->user()->homeRoute()))
    ->middleware('auth')
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::view('/no-business', 'no-business')->name('no-business');
});

/*
 * Back office — platform scope.
 *
 * The App Owner operates the platform and belongs to no business, so these
 * routes carry no business context and are guarded by the platform check alone.
 */
Route::middleware(['auth', 'platform.admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');

        // destroy is allowed only while a business is still setup data; the policy
        // refuses it the moment anything has been posted.
        Route::resource('businesses', BusinessController::class);
        Route::patch('businesses/{business}/status', [BusinessController::class, 'changeStatus'])
            ->name('businesses.status');

        Route::post('businesses/{business}/members', [BusinessMemberController::class, 'store'])
            ->name('businesses.members.store');
        Route::delete('businesses/{business}/members/{user}', [BusinessMemberController::class, 'destroy'])
            ->name('businesses.members.destroy');

        // Opening balance — the business's starting financial position.
        Route::prefix('businesses/{business}/opening-balance')
            ->name('businesses.opening.')
            ->controller(OpeningBalanceController::class)
            ->group(function () {
                Route::get('/', 'show')->name('show');
                Route::get('edit', 'edit')->name('edit');
                Route::put('/', 'update')->name('update');
                Route::get('review', 'review')->name('review');
                Route::post('finalize', 'finalize')->name('finalize');
                Route::post('correct', 'correct')->name('correct');
            });

        Route::resource('users', UserController::class)->except(['show', 'destroy']);
        Route::patch('users/{user}/toggle-active', [UserController::class, 'toggleActive'])
            ->name('users.toggle-active');
    });

/*
 * Business workspace — tenant scope.
 *
 * scopeBindings() is the second isolation layer: it resolves nested models only
 * within the business in the URL, so /b/acme/entries/{entry} cannot load an
 * entry belonging to another tenant even if the id is guessed.
 */
Route::middleware(['auth', 'business'])
    ->prefix('b/{business}')
    ->name('businesses.')
    ->scopeBindings()
    ->group(function () {
        Route::get('/', BusinessDashboardController::class)->name('show');

        Route::get('history', HistoryController::class)->name('history');

        Route::get('audit', AuditController::class)->name('audit');

        // The counter. A bill here writes itself onto the day's entry, so the
        // day stays the one financial document.
        Route::get('pos', [PosController::class, 'index'])->name('pos.index');
        Route::post('pos/bills', [PosController::class, 'store'])->name('pos.store');
        Route::get('pos/bills/{posBill}', [PosController::class, 'receipt'])->name('pos.receipt');

        // Who works here and the logins they use. The owner's page; {member}
        // resolves through Business::members(), so it is always someone in
        // this business.
        Route::get('team', [TeamController::class, 'index'])->name('team.index');
        Route::post('team', [TeamController::class, 'store'])->name('team.store');
        Route::patch('team/{member}', [TeamController::class, 'update'])->name('team.update');

        // Read-only window on the ledger: inspect and drill into the figures
        // every other screen is derived from.
        Route::get('ledger', [LedgerController::class, 'index'])->name('ledger');
        Route::get('ledger/{code}', [LedgerController::class, 'show'])->name('ledger.account');

        Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');
        Route::post('companies', [CompanyController::class, 'store'])->name('companies.store');
        Route::get('companies/{company}', [CompanyController::class, 'show'])->name('companies.show');

        /*
         * Price-list import. A company sends a PDF of its catalogue; it is read
         * into a staging table, reviewed line by line, and only then applied.
         * Every step before the last one leaves the catalogue untouched.
         */
        Route::prefix('companies/{company}/price-lists')
            ->name('companies.imports.')
            ->controller(PriceListImportController::class)
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('new', 'create')->name('create');
                Route::post('/', 'store')->name('store');
                Route::get('{productImport}', 'show')->name('show');
                Route::get('{productImport}/file', 'file')->name('file');
                Route::patch('{productImport}/columns', 'remap')->name('remap');
                Route::patch('{productImport}/rows/{row}', 'updateRow')->name('rows.update');
                Route::post('{productImport}/apply', 'commit')->name('commit');
                Route::post('{productImport}/discard', 'discard')->name('discard');
            });

        /*
         * The same upload screen without a company in the URL, for arriving at
         * it from the catalogue rather than from a company. The form asks which
         * company sent the list; the answer is checked against this business's
         * own companies before anything is read.
         */
        Route::get('price-lists/new', [PriceListImportController::class, 'create'])
            ->name('imports.create');
        Route::post('price-lists', [PriceListImportController::class, 'store'])
            ->name('imports.store');

        /*
         * Order forms. A request sent to a company, never a posting: no route
         * in this group touches the ledger, and a sent order is frozen so the
         * company's copy and ours cannot drift apart.
         */
        Route::prefix('orders')->name('orders.')->controller(OrderController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('new', 'create')->name('create');
            Route::get('product-search', 'productSearch')->name('product-search');
            Route::post('/', 'store')->name('store');
            Route::get('{order}', 'show')->name('show');
            Route::get('{order}/edit', 'edit')->name('edit');
            Route::put('{order}', 'update')->name('update');
            Route::post('{order}/send', 'send')->name('send');
            Route::get('{order}/receive', 'receive')->name('receive');
            Route::post('{order}/receive', 'storeReceipt')->name('receive.store');
            Route::get('{order}/pdf', 'pdf')->name('pdf');
        });

        // The catalogue itself — read-only, and derived wholly from those imports.
        Route::get('products', [ProductController::class, 'index'])->name('products.index');
        Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');
        Route::post('products/{product}/stock', [ProductController::class, 'adjustStock'])
            ->name('products.adjust-stock');

        Route::get('pharmacies', [PharmacyController::class, 'index'])->name('pharmacies.index');
        Route::post('pharmacies', [PharmacyController::class, 'store'])->name('pharmacies.store');
        Route::get('pharmacies/{pharmacy}', [PharmacyController::class, 'show'])->name('pharmacies.show');

        Route::get('expenses', [ExpenseCategoryController::class, 'index'])->name('expenses.index');
        Route::post('expenses', [ExpenseCategoryController::class, 'store'])->name('expenses.store');
        // An expense added from the Expenses page lands on its day's entry.
        Route::post('expenses/add', [ExpenseCategoryController::class, 'storeExpense'])->name('expenses.add');
        Route::get('expenses/{expense}', [ExpenseCategoryController::class, 'show'])->name('expenses.show');

        Route::get('stock', [StockVerificationController::class, 'index'])->name('stock.index');
        Route::post('stock', [StockVerificationController::class, 'store'])->name('stock.store');

        Route::prefix('daily')->name('daily.')->controller(DailyEntryController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('new', 'create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::get('{entry}', 'show')->name('show');
            Route::post('{entry}/post', 'post')->name('post');
            Route::post('{entry}/reverse', 'reverse')->name('reverse');
        });

        Route::prefix('closing')->name('closing.')->controller(DailyClosingController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('day/{date?}', 'show')->name('show');
            Route::post('day/{date}/reconcile', 'reconcile')->name('reconcile');
            Route::post('day/{date}/finalize', 'finalize')->name('finalize');
            Route::post('day/{date}/reopen', 'reopen')->name('reopen');
        });
    });

require __DIR__.'/auth.php';
