<?php

use App\Http\Controllers\Admin\BusinessController;
use App\Http\Controllers\Admin\BusinessMemberController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\OpeningBalanceController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Business\AttentionController;
use App\Http\Controllers\Business\AuditController;
use App\Http\Controllers\Business\CollectionController;
use App\Http\Controllers\Business\CompanyController;
use App\Http\Controllers\Business\DailyClosingController;
use App\Http\Controllers\Business\DailyEntryController;
use App\Http\Controllers\Business\DashboardController as BusinessDashboardController;
use App\Http\Controllers\Business\DirectDeliveryController;
use App\Http\Controllers\Business\ExpenseCategoryController;
use App\Http\Controllers\Business\ExpiringStockController;
use App\Http\Controllers\Business\HistoryController;
use App\Http\Controllers\Business\PosController;
use App\Http\Controllers\Business\TeamController;
use App\Http\Controllers\Business\LedgerController;
use App\Http\Controllers\Business\OrderController;
use App\Http\Controllers\Business\PharmacyController;
use App\Http\Controllers\Business\SettingsController;
use App\Http\Controllers\Business\PriceListImportController;
use App\Http\Controllers\Business\ProductController;
use App\Http\Controllers\Business\StockAuditController;
use App\Http\Controllers\Business\StockVerificationController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

// The public landing page. Its feature list is config/landing.php — add a
// feature there when it ships.
Route::view('/', 'landing')->name('home');

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

        // Moving a business in from its old POS: upload its database, preview
        // what would come across, then import it. The delete route discards an
        // upload that has not been imported; it removes no business data.
        Route::prefix('businesses/{business}/import')
            ->name('businesses.import.')
            ->controller(\App\Http\Controllers\Admin\BusinessImportController::class)
            ->group(function () {
                Route::get('/', 'create')->name('create');
                Route::post('/', 'store')->name('store');
                // The file in pieces, for servers that take only small uploads.
                Route::post('pieces', 'chunk')->name('chunk');
                Route::get('{import}', 'show')->whereNumber('import')->name('show');
                Route::post('{import}', 'commit')->whereNumber('import')->name('commit');
                // Balances kept from an import, posted once the opening balance is finalised.
                Route::post('{import}/balances', 'balances')->whereNumber('import')->name('balances');
                Route::delete('{import}', 'destroy')->name('destroy');
            });

        // The wallets and banks any business may be paid through. No delete
        // route: a method that has taken money is switched off, never removed.
        Route::get('payment-methods', [\App\Http\Controllers\Admin\PaymentMethodController::class, 'index'])->name('payment-methods.index');
        Route::post('payment-methods', [\App\Http\Controllers\Admin\PaymentMethodController::class, 'store'])->name('payment-methods.store');
        Route::patch('payment-methods/{paymentMethod}', [\App\Http\Controllers\Admin\PaymentMethodController::class, 'update'])->name('payment-methods.update');

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

        // What needs acting on. Read as often as the dashboard, so it sits
        // beside it rather than inside a group.
        Route::get('attention', AttentionController::class)->name('attention');

        Route::get('history', HistoryController::class)->name('history');

        Route::get('audit', AuditController::class)->name('audit');

        // Sales, purchases, stock and returns, each as a table that can be
        // filtered by date and taken away as a spreadsheet.
        Route::get('reports', [\App\Http\Controllers\Business\ReportController::class, 'index'])->name('reports.index');
        Route::get('reports/{report}', [\App\Http\Controllers\Business\ReportController::class, 'show'])->name('reports.show');

        // The counter. A bill here writes itself onto the day's entry, so the
        // day stays the one financial document.
        Route::get('pos', [PosController::class, 'index'])->name('pos.index');
        Route::post('pos/bills', [PosController::class, 'store'])->name('pos.store');
        Route::get('pos/bills/{posBill}', [PosController::class, 'receipt'])->name('pos.receipt');

        // Correcting a saved bill. The page is also the bill's history: every
        // edit and every return against it, for anyone who can sell.
        // The bill to read. Its old address ended in /edit, from when it was
        // corrected here; that still lands on the same page.
        Route::get('pos/bills/{posBill}/view', [\App\Http\Controllers\Business\PosBillController::class, 'edit'])->name('pos.bills.edit');
        Route::get('pos/bills/{posBill}/edit', fn (\App\Models\Business $business, \App\Models\PosBill $posBill) => redirect()->route('businesses.pos.bills.edit', [$business, $posBill]));
        Route::put('pos/bills/{posBill}', [\App\Http\Controllers\Business\PosBillController::class, 'update'])->name('pos.bills.update');

        // Goods brought back against a bill.
        Route::get('pos/bills/{posBill}/return', [\App\Http\Controllers\Business\PosBillController::class, 'returnForm'])->name('pos.bills.return');
        Route::post('pos/bills/{posBill}/return', [\App\Http\Controllers\Business\PosBillController::class, 'storeReturn'])->name('pos.bills.return.store');

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
        Route::get('products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
        Route::put('products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::post('products/{product}/stock', [ProductController::class, 'adjustStock'])
            ->name('products.adjust-stock');

        // What a business looks after itself: its details, and its counter.
        Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::put('settings/payment-methods', [SettingsController::class, 'paymentMethods'])->name('settings.payment-methods');

        // Counting the shelves a few lines at a time.
        Route::get('stock/audit', [StockAuditController::class, 'index'])->name('stock.audit');
        Route::post('stock/audit', [StockAuditController::class, 'store'])->name('stock.audit.store');
        Route::post('stock/audit/{stockCount}', [StockAuditController::class, 'resolve'])->name('stock.audit.resolve');

        // The shelf sweep: what is running out of time.
        Route::get('stock/expiring', [ExpiringStockController::class, 'index'])->name('stock.expiring');

        // A delivery that arrived without an order form having been raised.
        Route::get('stock/receive', [DirectDeliveryController::class, 'create'])->name('stock.receive');
        Route::post('stock/receive', [DirectDeliveryController::class, 'store'])->name('stock.receive.store');
        Route::post('stock/receive/product', [DirectDeliveryController::class, 'storeProduct'])->name('stock.receive.product');

        // What the market owes, and cash brought back against it.
        Route::get('collections', [CollectionController::class, 'index'])->name('collections.index');
        Route::post('collections', [CollectionController::class, 'store'])->name('collections.store');

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
