<?php
use App\Http\Controllers\ApiController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\FiscalController;
use App\Http\Controllers\FiscalTaxRuleController;
use App\Http\Controllers\FiscalTaxGroupController;
use App\Http\Controllers\FiscalFcpRuleController;
use App\Http\Controllers\NFCeDanfeController;
use App\Http\Controllers\NfeDraftController;
use App\Http\Controllers\OperationNatureController;
use App\Http\Controllers\FinancialReconciliationController;
use App\Http\Controllers\FinancialReceiptController;
use App\Http\Controllers\FinancialRecurrenceController;
use App\Http\Controllers\FinancialTransferController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PdvController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SaleReturnController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if(!auth()->check()) return redirect()->route('login');
    return redirect()->route(auth()->user()->homeRouteName());
});
Route::prefix('api/nextor')->middleware(['nextor.api','throttle:120,1'])->group(function () {
    Route::get('/products',[ApiController::class,'products']);
    Route::get('/customers',[ApiController::class,'customers']);
    Route::get('/sales',[ApiController::class,'sales']);
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class,'loginForm'])->name('login');
    Route::post('/login', [AuthController::class,'login'])->middleware('throttle:5,1');
});
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class,'logout'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard')->middleware('permission:dashboard');
    Route::get('/products',[ProductController::class,'index'])->name('products.index')->middleware('permission:products');
    Route::get('/products/create',[ProductController::class,'create'])->name('products.create')->middleware('permission:products');
    Route::post('/products',[ProductController::class,'store'])->name('products.store')->middleware('permission:products');
    Route::get('/products/{product}/edit',[ProductController::class,'edit'])->name('products.edit')->middleware('permission:products');
    Route::put('/products/{product}',[ProductController::class,'update'])->name('products.update')->middleware('permission:products');
    Route::post('/products/bulk-duplicate',[ProductController::class,'bulkDuplicate'])->name('products.bulk-duplicate')->middleware('permission:products');
    Route::post('/products/bulk-status',[ProductController::class,'bulkStatus'])->name('products.bulk-status')->middleware('permission:products');
    Route::delete('/products/bulk-delete',[ProductController::class,'bulkDelete'])->name('products.bulk-delete')->middleware('permission:products');
    Route::get('/services',[ServiceController::class,'index'])->name('services.index')->middleware('permission:services');
    Route::get('/services/create',[ServiceController::class,'create'])->name('services.create')->middleware('permission:services');
    Route::post('/services',[ServiceController::class,'store'])->name('services.store')->middleware('permission:services');
    Route::get('/services/{service}/edit',[ServiceController::class,'edit'])->name('services.edit')->middleware('permission:services');
    Route::put('/services/{service}',[ServiceController::class,'update'])->name('services.update')->middleware('permission:services');
    Route::post('/services/bulk-duplicate',[ServiceController::class,'bulkDuplicate'])->name('services.bulk-duplicate')->middleware('permission:services');
    Route::post('/services/bulk-status',[ServiceController::class,'bulkStatus'])->name('services.bulk-status')->middleware('permission:services');
    Route::delete('/services/bulk-delete',[ServiceController::class,'bulkDelete'])->name('services.bulk-delete')->middleware('permission:services');
    Route::get('/customers',[CustomerController::class,'index'])->name('customers.index')->middleware('permission:customers');
    Route::get('/customers/cnpj/{cnpj}',[CustomerController::class,'lookupCnpj'])->where('cnpj','[0-9A-Za-z.\\/-]+')->name('customers.lookup-cnpj')->middleware('permission:customers,settings');
    Route::get('/customers/create',[CustomerController::class,'create'])->name('customers.create')->middleware('permission:customers');
    Route::post('/customers',[CustomerController::class,'store'])->name('customers.store')->middleware('permission:customers');
    Route::get('/customers/{customer}/edit',[CustomerController::class,'edit'])->name('customers.edit')->middleware('permission:customers');
    Route::put('/customers/{customer}',[CustomerController::class,'update'])->name('customers.update')->middleware('permission:customers');
    Route::post('/customers/bulk-duplicate',[CustomerController::class,'bulkDuplicate'])->name('customers.bulk-duplicate')->middleware('permission:customers');
    Route::delete('/customers/bulk-delete',[CustomerController::class,'bulkDelete'])->name('customers.bulk-delete')->middleware('permission:customers');
    Route::get('/stock',[StockController::class,'index'])->name('stock.index')->middleware('permission:stock');
    Route::post('/stock',[StockController::class,'store'])->name('stock.store')->middleware('permission:stock');
    Route::get('/pdv',[PdvController::class,'index'])->name('pdv.index')->middleware('permission:pdv');
    Route::get('/pdv/search',[PdvController::class,'search'])->name('pdv.search')->middleware('permission:pdv');
    Route::post('/pdv/cash/open',[PdvController::class,'openCash'])->name('pdv.cash.open')->middleware('permission:pdv');
    Route::post('/pdv/cash/close',[PdvController::class,'closeCash'])->name('pdv.cash.close')->middleware('permission:pdv');
    Route::post('/pdv/cash/movement',[PdvController::class,'cashMovement'])->name('pdv.cash.movement')->middleware('permission:pdv');
    Route::post('/pdv/suspended',[PdvController::class,'suspendSale'])->name('pdv.suspended.store')->middleware('permission:pdv');
    Route::post('/pdv/suspended/{suspendedSale}/resume',[PdvController::class,'resumeSale'])->name('pdv.suspended.resume')->middleware('permission:pdv');
    Route::delete('/pdv/suspended/{suspendedSale}',[PdvController::class,'discardSuspendedSale'])->name('pdv.suspended.destroy')->middleware('permission:pdv');
    Route::post('/pdv/nfce/contingency',[PdvController::class,'setNfceContingency'])->name('pdv.nfce.contingency')->middleware('permission:pdv');
    Route::post('/pdv/nfce/{fiscalJob}/cancel',[PdvController::class,'requestNfceCancellation'])->name('pdv.nfce.cancel')->middleware('permission:pdv');
    Route::get('/pdv/receipt/{sale}',[PdvController::class,'receipt'])->name('pdv.receipt')->middleware('permission:pdv');
    Route::post('/pdv/receipt/{sale}/prepare-nfce',[PdvController::class,'prepareNfce'])->name('pdv.receipt.nfce.prepare')->middleware('permission:pdv');
    Route::get('/pdv/nfce/{fiscalDocumentJob}/danfe',NFCeDanfeController::class)->name('pdv.nfce.danfe')->middleware('permission:pdv');
    Route::post('/pdv',[PdvController::class,'store'])->name('pdv.store')->middleware('permission:pdv');
    Route::get('/sales',[SaleController::class,'index'])->name('sales.index')->middleware('permission:sales');
    Route::get('/sales/create',[SaleController::class,'create'])->name('sales.create')->middleware('permission:sales');
    Route::post('/sales',[SaleController::class,'store'])->name('sales.store')->middleware('permission:sales');

    Route::get('/sales/returns',[SaleReturnController::class,'index'])->name('sales.returns.index')->middleware('permission:returns');
    Route::get('/sales/returns/create',[SaleReturnController::class,'create'])->name('sales.returns.create')->middleware('permission:returns');
    Route::post('/sales/returns',[SaleReturnController::class,'store'])->name('sales.returns.store')->middleware('permission:returns');
    Route::get('/sales/returns/{saleReturn}',[SaleReturnController::class,'show'])->name('sales.returns.show')->middleware('permission:returns');
    Route::post('/sales/returns/{saleReturn}/cancel',[SaleReturnController::class,'cancel'])->name('sales.returns.cancel')->middleware('permission:returns');

    Route::get('/sales/{sale}',[SaleController::class,'show'])->name('sales.show')->middleware('permission:sales');
    Route::post('/sales/{sale}/cancel',[SaleController::class,'cancel'])->name('sales.cancel')->middleware('permission:sales');

    Route::get('/fiscal',[FiscalController::class,'index'])->name('fiscal.index')->middleware('permission:fiscal');
    Route::get('/fiscal/fcp',[FiscalFcpRuleController::class,'index'])->name('fiscal.fcp.index')->middleware('permission:settings');
    Route::post('/fiscal/fcp',[FiscalFcpRuleController::class,'store'])->name('fiscal.fcp.store')->middleware('permission:settings');
    Route::put('/fiscal/fcp/{fiscalFcpRule}',[FiscalFcpRuleController::class,'update'])->name('fiscal.fcp.update')->middleware('permission:settings');
    Route::get('/fiscal/tax-groups',[FiscalTaxGroupController::class,'index'])->name('fiscal.tax-groups.index')->middleware('permission:settings');
    Route::get('/fiscal/tax-groups/create',[FiscalTaxGroupController::class,'create'])->name('fiscal.tax-groups.create')->middleware('permission:settings');
    Route::post('/fiscal/tax-groups',[FiscalTaxGroupController::class,'store'])->name('fiscal.tax-groups.store')->middleware('permission:settings');
    Route::get('/fiscal/tax-groups/{fiscalTaxGroup}/edit',[FiscalTaxGroupController::class,'edit'])->name('fiscal.tax-groups.edit')->middleware('permission:settings');
    Route::put('/fiscal/tax-groups/{fiscalTaxGroup}',[FiscalTaxGroupController::class,'update'])->name('fiscal.tax-groups.update')->middleware('permission:settings');
    Route::get('/fiscal/rules',[FiscalTaxRuleController::class,'index'])->name('fiscal.tax-rules.index')->middleware('permission:settings');
    Route::post('/fiscal/rules',[FiscalTaxRuleController::class,'store'])->name('fiscal.tax-rules.store')->middleware('permission:settings');
    Route::put('/fiscal/rules/{fiscalTaxRule}',[FiscalTaxRuleController::class,'update'])->name('fiscal.tax-rules.update')->middleware('permission:settings');
    Route::post('/fiscal/rules/mode',[FiscalTaxRuleController::class,'mode'])->name('fiscal.tax-rules.mode')->middleware('permission:settings');
    Route::post('/fiscal/{fiscalDocumentJob}/apply-tax-rules',[FiscalTaxRuleController::class,'apply'])->name('fiscal.tax-rules.apply')->middleware('permission:fiscal');

    Route::get('/fiscal/nfe/create',[NfeDraftController::class,'create'])->name('fiscal.nfe.create')->middleware('permission:fiscal');
    Route::get('/fiscal/nfe/drafts',[NfeDraftController::class,'index'])->name('fiscal.nfe.drafts.index')->middleware('permission:fiscal');
    Route::post('/fiscal/nfe/drafts',[NfeDraftController::class,'store'])->name('fiscal.nfe.store')->middleware('permission:fiscal');
    Route::get('/fiscal/nfe/drafts/{nfeDraft}/edit',[NfeDraftController::class,'edit'])->name('fiscal.nfe.edit')->middleware('permission:fiscal');
    Route::put('/fiscal/nfe/drafts/{nfeDraft}',[NfeDraftController::class,'update'])->name('fiscal.nfe.update')->middleware('permission:fiscal');
    Route::post('/fiscal/nfe/drafts/{nfeDraft}/validate',[NfeDraftController::class,'validateDraft'])->name('fiscal.nfe.validate')->middleware('permission:fiscal');
    Route::delete('/fiscal/nfe/drafts/{nfeDraft}',[NfeDraftController::class,'destroy'])->name('fiscal.nfe.destroy')->middleware('permission:fiscal');

    Route::get('/fiscal/nfe/natures',[OperationNatureController::class,'index'])->name('fiscal.nfe.natures.index')->middleware('permission:fiscal');
    Route::post('/fiscal/nfe/natures',[OperationNatureController::class,'store'])->name('fiscal.nfe.natures.store')->middleware('permission:fiscal');
    Route::put('/fiscal/nfe/natures/{operationNature}',[OperationNatureController::class,'update'])->name('fiscal.nfe.natures.update')->middleware('permission:fiscal');

    Route::post('/fiscal/{fiscalDocumentJob}/refresh-nfce-fiscal-data',[FiscalController::class,'refreshNfceFiscalData'])->name('fiscal.nfce.refresh-data')->middleware('permission:fiscal');
    Route::post('/fiscal/{fiscalDocumentJob}/emit-nfce',[FiscalController::class,'issueNfce'])->name('fiscal.nfce.emit')->middleware('permission:fiscal');
    Route::post('/fiscal/{fiscalDocumentJob}/consult-nfce',[FiscalController::class,'consultNfce'])->name('fiscal.nfce.consult')->middleware('permission:fiscal');
    Route::get('/fiscal/{fiscalDocumentJob}/xml',[FiscalController::class,'downloadNfceXml'])->name('fiscal.nfce.xml')->middleware('permission:fiscal');
    Route::get('/fiscal/{fiscalDocumentJob}/danfe',NFCeDanfeController::class)->name('fiscal.nfce.danfe')->middleware('permission:fiscal');
    Route::post('/fiscal/{fiscalDocumentJob}/recover-nfce-xml',[FiscalController::class,'recoverNfceXml'])->name('fiscal.nfce.recover-xml')->middleware('permission:fiscal');

    Route::get('/fiscal/{fiscalDocumentJob}',[FiscalController::class,'show'])->name('fiscal.show')->middleware('permission:fiscal');

    Route::get('/finance',[FinanceController::class,'dashboard'])->name('finance.dashboard')->middleware('permission:finance');
    Route::get('/finance/entries',[FinanceController::class,'entries'])->name('finance.entries')->middleware('permission:finance');
    Route::get('/finance/entries/create',[FinanceController::class,'create'])->name('finance.entries.create')->middleware('permission:finance');
    Route::post('/finance/entries',[FinanceController::class,'store'])->name('finance.entries.store')->middleware('permission:finance');
    Route::post('/finance/entries/bulk-action',[FinanceController::class,'bulkAction'])->name('finance.entries.bulk-action')->middleware('permission:finance');
    Route::get('/finance/entries/{entry}',[FinanceController::class,'show'])->name('finance.entries.show')->middleware('permission:finance');
    Route::get('/finance/entries/{entry}/edit',[FinanceController::class,'edit'])->name('finance.entries.edit')->middleware('permission:finance');
    Route::put('/finance/entries/{entry}',[FinanceController::class,'update'])->name('finance.entries.update')->middleware('permission:finance');
    Route::post('/finance/entries/{entry}/settle',[FinanceController::class,'settle'])->name('finance.entries.settle')->middleware('permission:finance');
    Route::post('/finance/entries/{entry}/cancel',[FinanceController::class,'cancel'])->name('finance.entries.cancel')->middleware('permission:finance');
    Route::post('/finance/settlements/{settlement}/reverse',[FinanceController::class,'reverseSettlement'])->name('finance.settlements.reverse')->middleware('permission:finance');

    Route::get('/finance/transfers',[FinancialTransferController::class,'index'])->name('finance.transfers.index')->middleware('permission:finance');
    Route::get('/finance/transfers/create',[FinancialTransferController::class,'create'])->name('finance.transfers.create')->middleware('permission:finance');
    Route::post('/finance/transfers',[FinancialTransferController::class,'store'])->name('finance.transfers.store')->middleware('permission:finance');
    Route::post('/finance/transfers/{transfer}/cancel',[FinancialTransferController::class,'cancel'])->name('finance.transfers.cancel')->middleware('permission:finance');

    Route::get('/finance/recurrences',[FinancialRecurrenceController::class,'index'])->name('finance.recurrences.index')->middleware('permission:finance');
    Route::get('/finance/recurrences/create',[FinancialRecurrenceController::class,'create'])->name('finance.recurrences.create')->middleware('permission:finance');
    Route::post('/finance/recurrences',[FinancialRecurrenceController::class,'store'])->name('finance.recurrences.store')->middleware('permission:finance');
    Route::post('/finance/recurrences/generate',[FinancialRecurrenceController::class,'generate'])->name('finance.recurrences.generate')->middleware('permission:finance');
    Route::post('/finance/recurrences/{recurrence}/toggle',[FinancialRecurrenceController::class,'toggle'])->name('finance.recurrences.toggle')->middleware('permission:finance');

    Route::get('/finance/receipts',[FinancialReceiptController::class,'index'])->name('finance.receipts.index')->middleware('permission:finance');
    Route::get('/finance/receipts/create',[FinancialReceiptController::class,'create'])->name('finance.receipts.create')->middleware('permission:finance');
    Route::post('/finance/receipts',[FinancialReceiptController::class,'store'])->name('finance.receipts.store')->middleware('permission:finance');
    Route::get('/finance/receipts/{receipt}/print',[FinancialReceiptController::class,'printReceipt'])->name('finance.receipts.print')->middleware('permission:finance');
    Route::get('/finance/receipts/{receipt}/attachment',[FinancialReceiptController::class,'attachment'])->name('finance.receipts.attachment')->middleware('permission:finance');

    Route::get('/finance/reconciliation',[FinancialReconciliationController::class,'index'])->name('finance.reconciliation.index')->middleware('permission:finance');
    Route::get('/finance/reconciliation/import',[FinancialReconciliationController::class,'createImport'])->name('finance.reconciliation.import')->middleware('permission:finance');
    Route::post('/finance/reconciliation/import',[FinancialReconciliationController::class,'storeImport'])->name('finance.reconciliation.store-import')->middleware('permission:finance');
    Route::post('/finance/reconciliation/transactions/{transaction}/match',[FinancialReconciliationController::class,'match'])->name('finance.reconciliation.match')->middleware('permission:finance');
    Route::post('/finance/reconciliation/transactions/{transaction}/unmatch',[FinancialReconciliationController::class,'unmatch'])->name('finance.reconciliation.unmatch')->middleware('permission:finance');

    Route::get('/finance/entries/{entry}/attachment',[FinanceController::class,'attachment'])->name('finance.entries.attachment')->middleware('permission:finance');

    Route::get('/company/logo',[SettingsController::class,'companyLogo'])->name('company.logo');
    Route::get('/settings',[SettingsController::class,'index'])->name('settings.index')->middleware('permission:settings');
    Route::post('/settings/company',[SettingsController::class,'updateCompany'])->name('settings.company.update')->middleware('permission:settings');
    Route::post('/settings/printing',[SettingsController::class,'updatePrinting'])->name('settings.printing.update')->middleware('permission:settings');
    Route::post('/settings/group/{group}',[SettingsController::class,'updateGroup'])->name('settings.group.update')->middleware('permission:settings');
    Route::post('/settings/accounting/export',[SettingsController::class,'exportAccounting'])->name('settings.accounting.export')->middleware('permission:settings');
    Route::post('/settings/api-token/regenerate',[SettingsController::class,'regenerateApiToken'])->name('settings.api-token.regenerate')->middleware('permission:settings');
    Route::post('/settings/certificate',[SettingsController::class,'uploadCertificate'])->name('settings.certificate.upload')->middleware('permission:settings');
    Route::delete('/settings/certificate',[SettingsController::class,'removeCertificate'])->name('settings.certificate.remove')->middleware('permission:settings');
    Route::post('/settings/payment-methods',[SettingsController::class,'storePaymentMethod'])->name('settings.payment-methods.store')->middleware('permission:settings');
    Route::put('/settings/payment-methods/{paymentMethod}',[SettingsController::class,'updatePaymentMethod'])->name('settings.payment-methods.update')->middleware('permission:settings');
    Route::post('/settings/users',[SettingsController::class,'storeUser'])->name('settings.users.store')->middleware('permission:settings');
    Route::put('/settings/users/{user}',[SettingsController::class,'updateUser'])->name('settings.users.update')->middleware('permission:settings');

    Route::get('/finance/settings',[FinanceController::class,'settings'])->name('finance.settings')->middleware('permission:settings');
    Route::post('/finance/categories',[FinanceController::class,'storeCategory'])->name('finance.categories.store')->middleware('permission:settings');
    Route::post('/finance/categories/{category}/toggle',[FinanceController::class,'toggleCategory'])->name('finance.categories.toggle')->middleware('permission:settings');
    Route::post('/finance/accounts',[FinanceController::class,'storeAccount'])->name('finance.accounts.store')->middleware('permission:settings');
    Route::post('/finance/accounts/{account}/toggle',[FinanceController::class,'toggleAccount'])->name('finance.accounts.toggle')->middleware('permission:settings');
});
