import fs from 'node:fs/promises';
import { SpreadsheetFile, Workbook } from '@oai/artifact-tool';

const outputDir = '/Applications/XAMPP/xamppfiles/htdocs/Phone_acc_imran/outputs/test-cases';
const outputPath = `${outputDir}/imran_pos_test_cases.xlsx`;

const workbook = Workbook.create();
const summary = workbook.worksheets.add('Summary');
const cases = workbook.worksheets.add('Test Cases');
const data = workbook.worksheets.add('Test Data');
const coverage = workbook.worksheets.add('Coverage Map');

const today = '2026-05-24';

const testCases = [
  ['TC-001', 'Authentication', 'Login as super admin', 'High', 'Active super_admin user exists.', '1. Open login page.\n2. Enter valid email or username.\n3. Enter valid password.\n4. Click login.', 'Role: super_admin\nis_active: true', 'User is redirected to dashboard and can access protected pages.', 'Existing: Auth/AuthenticationTest.php', 'Automated'],
  ['TC-002', 'Product', 'Add product with full product details and image', 'High', 'Category, brand, and unit exist.', '1. Go to Products > Add Product.\n2. Fill name, SKU, barcode, category, brand, unit.\n3. Enter cost, selling, wholesale price, stock, minimum stock.\n4. Add compatible models, color, warranty, and image.\n5. Save.', 'Name: Matte Shockproof Case\nSKU: CASE-001\nStock: 15', 'Product is saved, linked to category/brand/unit, and image is stored.', 'Existing: ProductManagementTest.php', 'Automated'],
  ['TC-003', 'Product', 'View product list', 'Medium', 'At least one product exists.', '1. Login.\n2. Open Products page.', 'Route: products.index', 'Products page loads successfully and product records are visible.', 'Existing: ProductManagementTest.php', 'Automated'],
  ['TC-004', 'Product', 'POS product search filters catalog', 'Medium', 'Sample products are seeded.', '1. Open POS terminal.\n2. Type product keyword in search.\n3. Review filtered results.', 'Search: AirPods', 'Matching product appears and unrelated products are hidden.', 'Existing: PosTerminalTest.php', 'Automated'],
  ['TC-005', 'Customer', 'Add customer with opening due balance', 'High', 'User is logged in.', '1. Open Customers page.\n2. Enter customer name, phone, email, address.\n3. Enter opening balance.\n4. Save customer.', 'Name: Step Customer\nOpening balance: 750', 'Customer is saved. Opening balance and due balance both equal 750.', 'New: FullWorkflowTest.php', 'Automated'],
  ['TC-006', 'Customer', 'Add quick customer during checkout', 'High', 'POS terminal is open.', '1. Search customer name in POS.\n2. Click add quick customer.\n3. Fill name, phone, email.\n4. Save customer.', 'Name: Checkout Customer\nPhone: 0771234003', 'Customer is created, selected on the checkout, and due balance starts at 0.', 'New: FullWorkflowTest.php', 'Automated'],
  ['TC-007', 'Customer Ledger', 'Customer ledger shows bill and payment timeline', 'Medium', 'Customer has one sale and one payment.', '1. Open Customers page.\n2. Click customer ledger.\n3. Review bills and payments timeline.', 'Bill: INV-LEDGER-1\nPayment ref: CASH-77', 'Ledger shows bill number, bill date, paid date, payment note, reference, and opening balance.', 'Existing: CustomerLedgerTest.php', 'Automated'],
  ['TC-008', 'POS Sale', 'Full cash sale', 'High', 'Customer and in-stock product exist.', '1. Open POS.\n2. Add product to cart.\n3. Set quantity to 2.\n4. Select customer.\n5. Select payment method cash.\n6. Enter full amount.\n7. Submit checkout.', 'Product price: 250\nQuantity: 2\nPaid: 500', 'Sale status is paid, due is 0, cash payment is recorded, customer due remains 0, stock decreases by 2.', 'New: FullWorkflowTest.php', 'Automated'],
  ['TC-009', 'POS Sale', 'Partial cash sale with due balance', 'High', 'Customer and in-stock product exist. Due sales enabled.', '1. Open POS.\n2. Add product.\n3. Select customer.\n4. Select cash.\n5. Enter less than total.\n6. Submit checkout.', 'Total: 1000\nPaid: 400\nDue: 600', 'Sale status is partial, paid is 400, due is 600, customer due increases by 600, stock decreases.', 'New: FullWorkflowTest.php', 'Automated'],
  ['TC-010', 'POS Sale', 'Customer and full sale in one checkout', 'High', 'Product exists with stock.', '1. Open POS.\n2. Add quick customer.\n3. Add product to cart.\n4. Select cash.\n5. Pay full amount.\n6. Submit checkout.', 'Customer: Checkout Customer\nPaid: 175', 'New customer is linked to sale. Sale status is paid and customer due remains 0.', 'New: FullWorkflowTest.php', 'Automated'],
  ['TC-011', 'POS Sale', 'Cart item quantity, price, and discount edit', 'Medium', 'Product exists in POS catalog.', '1. Add product to cart.\n2. Open cart item editor.\n3. Change quantity.\n4. Change unit price.\n5. Apply percentage discount.\n6. Save item.', 'Quantity: 2\nUnit price: 1200\nDiscount: 10%', 'Cart item subtotal recalculates to 2160, price type is custom, and checkout paid amount syncs.', 'Existing: PosTerminalTest.php', 'Automated'],
  ['TC-012', 'POS Sale', 'Fixed sale discount checkout', 'Medium', 'Customer and product exist.', '1. Add product to cart.\n2. Select customer.\n3. Select fixed discount.\n4. Enter discount.\n5. Pay discounted total.\n6. Submit.', 'Gross: 1500\nFixed discount: 200', 'Discount amount is 200 and grand total is 1300.', 'Existing: PosTerminalTest.php', 'Automated'],
  ['TC-013', 'POS Sale', 'Percentage sale discount checkout', 'Medium', 'Customer and product exist.', '1. Add product to cart.\n2. Select customer.\n3. Select percentage discount.\n4. Enter discount percent.\n5. Pay discounted total.\n6. Submit.', 'Gross: 1500\nDiscount: 10%', 'Discount amount is 150 and grand total is 1350.', 'Existing: PosTerminalTest.php', 'Automated'],
  ['TC-014', 'Cheque Sale', 'Cheque sale creates pending payment and due invoice', 'High', 'Registered customer exists. Product exists.', '1. Open POS.\n2. Add product.\n3. Select registered customer.\n4. Select payment method cheque.\n5. Enter cheque amount, bank, number, date.\n6. Submit checkout.', 'Bank: BOC\nCheque: CHQ-100\nAmount: 1500', 'Sale status is cheque_pending, paid is 0, due equals cheque amount, payment cheque_status is pending, customer due increases.', 'Existing: PosTerminalTest.php', 'Automated'],
  ['TC-015', 'Cheque Sale', 'Cheque sale blocked for walk-in customer', 'High', 'Walk-in Customer exists.', '1. Open POS.\n2. Add product.\n3. Select Walk-in Customer.\n4. Select cheque.\n5. Fill cheque fields.\n6. Submit.', 'Walk-in phone: 0000000000', 'Validation error appears on customer selection. Cheque sale is not saved.', 'Existing: PosTerminalTest.php', 'Automated'],
  ['TC-016', 'Cheque Processing', 'Passing pending cheque settles sale', 'High', 'Pending cheque payment exists.', '1. Open cheque follow-up or process cheque service.\n2. Mark cheque as passed.', 'Cheque status: pending\nAmount: 1000', 'Cheque status becomes passed. Sale becomes paid. Customer due decreases by cheque amount.', 'Existing: ChequePaymentTest.php', 'Automated'],
  ['TC-017', 'Cheque Processing', 'Returning pending cheque leaves invoice due', 'High', 'Pending cheque payment exists.', '1. Open cheque follow-up or process cheque service.\n2. Mark cheque as returned.', 'Cheque status: pending\nAmount: 1000', 'Cheque status becomes returned. Sale remains due and customer balance remains outstanding.', 'Existing: ChequePaymentTest.php', 'Automated'],
  ['TC-018', 'Cheque Processing', 'Old pending cheques pass automatically', 'Medium', 'Pending cheque older than auto-pass threshold exists.', '1. Trigger cheque auto-pass process.\n2. Review sale and payment.', 'Cheque date: older than 7 days', 'Eligible cheque is passed automatically and sale/customer balances are updated.', 'Existing: ChequePaymentTest.php', 'Automated'],
  ['TC-019', 'Sale Due', 'Collect full due payment from sales invoice', 'High', 'Partial sale exists with customer due.', '1. Open Sales page.\n2. View invoice.\n3. Open Pay Due modal.\n4. Enter full due amount.\n5. Select cash and reference.\n6. Submit.', 'Invoice: INV-DUE-100\nDue: 600\nRef: DUE-CASH-100', 'Sale becomes paid, due becomes 0, paid amount increases, customer due becomes 0, payment ledger record is created.', 'New: FullWorkflowTest.php', 'Automated'],
  ['TC-020', 'Purchase', 'Add purchase and restock product', 'High', 'Supplier and product exist.', '1. Open Purchases > Create.\n2. Select supplier.\n3. Select product.\n4. Set quantity, cost price, selling price.\n5. Enter discount and paid amount.\n6. Save purchase.', 'Quantity: 4\nCost: 90\nSell: 160\nPaid: 200\nDiscount: 20', 'Purchase is saved as partial, supplier due increases by 140, product stock increases by 4, product cost/selling prices update.', 'New: FullWorkflowTest.php', 'Automated'],
  ['TC-021', 'Purchase', 'Restock button opens purchase with product prefilled', 'Medium', 'Low-stock product exists.', '1. Open dashboard.\n2. Click restock button for low-stock product.\n3. Open purchase create page.', 'Product stock: 1\nMinimum: 5', 'Purchase form opens with selected product already in cart with cost and selling price populated.', 'Existing: PurchaseRestockIntegrationTest.php', 'Automated'],
  ['TC-022', 'Purchase', 'Purchase index links to supplier ledger and product detail', 'Low', 'Purchase with supplier and product exists.', '1. Open Purchases page.\n2. Open purchase invoice drawer.\n3. Check supplier and product links.', 'Invoice: PUR-TEST-123', 'Supplier link points to supplier ledger and product link points to product details.', 'Existing: PurchaseRestockIntegrationTest.php', 'Automated'],
  ['TC-023', 'Sales', 'Sales invoice drawer displays PDF share controls', 'Medium', 'Sale exists.', '1. Open Sales page.\n2. View an invoice.\n3. Check PDF share section.', 'Button: Share PDF', 'Invoice drawer displays Share PDF action and PDF ready/download controls after generation.', 'Manual + existing page assertions', 'Manual'],
  ['TC-024', 'POS Export', 'POS success modal can prepare/share PDF bill', 'Medium', 'Completed POS sale exists.', '1. Complete POS checkout.\n2. On success modal click Share PDF Bill.\n3. Wait for PDF generation.\n4. Download or share.', 'Invoice generated from checkout', 'A PDF file is generated with the invoice number as filename and can be downloaded/shared.', 'Manual browser test recommended', 'Manual'],
  ['TC-025', 'Customer Export', 'Customer ledger PDF statement generation', 'Medium', 'Customer has ledger entries.', '1. Open customer ledger.\n2. Click Share PDF.\n3. Wait for PDF statement.\n4. Download.', 'Customer: Ledger Customer', 'Ledger PDF statement is prepared and download filename includes customer name and Ledger.', 'Manual browser test recommended', 'Manual'],
  ['TC-026', 'Accounting Export', 'Accounting cash book PDF download', 'High', 'User can access accounting reports.', '1. Open Accounting > Cash Book.\n2. Click PDF / Print export.\n3. Download file.', 'Report: cash-book', 'Server-side PDF download is triggered as cash-book-report.pdf.', 'New: FullWorkflowTest.php', 'Automated'],
  ['TC-027', 'Reports', 'Visit every business report page', 'Medium', 'User is logged in.', '1. Open each Reports page.\n2. Confirm heading and signature/footer areas.\n3. Confirm PDF / Print action exists.', 'Sales, Purchases, Profit & Loss, Stock, Expenses, Receives, Debits, Due Bills, Customer Dues', 'Each report page loads with correct heading, PDF / Print action, Prepared By, and Authorized Signatory.', 'Existing: BusinessReportsTest.php', 'Automated'],
  ['TC-028', 'Reports', 'Business report filters return expected records', 'Medium', 'Report transactions exist.', '1. Open Sales report.\n2. Filter by partial.\n3. Search missing invoice.\n4. Open Receives and Debits reports.\n5. Filter payment methods.', 'Invoice: INV-REPORT-100\nMethod: bank_transfer', 'Filters show matching records and hide non-matching records.', 'Existing: BusinessReportsTest.php', 'Automated'],
  ['TC-029', 'Accounting', 'Accounting pages separate cash-in cash-out bank transfers and ledgers', 'High', 'Sales, purchase, customer payment, and expenses exist.', '1. Open Cash In.\n2. Open Cash Out.\n3. Open Bank Transfers.\n4. Open Payment Method Report.\n5. Open T Accounts.', 'Cash sale, bank due payment, purchase payment, card expense', 'Cash-in shows inflows only, cash-out shows outflows only, bank report shows transfers, T accounts group ledgers correctly.', 'Existing: AccountingReportsTest.php', 'Automated'],
  ['TC-030', 'Public Bill', 'Public bill page opens without login', 'Medium', 'Sale exists with invoice number.', '1. Copy public bill URL.\n2. Open URL in logged-out browser.\n3. Review bill details.', 'Route: bill/{invoice_no}', 'Public bill displays invoice, customer, items, and payment details without requiring authentication.', 'Manual + controller coverage recommended', 'Manual'],
  ['TC-031', 'SMS', 'Sale notification template can be parsed and sent when enabled', 'Low', 'SMS settings are enabled and customer has valid phone.', '1. Complete sale.\n2. Confirm SMS notification service runs.\n3. Review SMS log.', 'Ref prefix: SALE-', 'SMS log records successful or failed delivery with parsed sale template.', 'Existing: SmsGatewayTest.php', 'Automated'],
  ['TC-032', 'Expense', 'Expense page and categories are accessible', 'Low', 'User is logged in.', '1. Open Expenses.\n2. Open Add Expense.\n3. Open Expense Categories.', 'Routes: expenses.index, expenses.create, expenses.categories', 'Expense pages load without errors.', 'Existing: ExpensesPagesTest.php', 'Automated'],
];

const testDataRows = [
  ['Entity', 'Field', 'Value', 'Notes'],
  ['User', 'role', 'super_admin', 'Required to access POS, products, purchases, reports, and accounting.'],
  ['Customer', 'Step Customer opening balance', 750, 'Expected due_balance also 750.'],
  ['Customer', 'Checkout Customer phone', '0771234003', 'Quick customer created inside POS checkout.'],
  ['Product', 'Full Pay Case', 'Cost 100 / Sell 250 / Stock 8', 'Full cash sale quantity 2.'],
  ['Product', 'Due Pay Charger', 'Cost 400 / Sell 1000 / Stock 4', 'Partial sale due amount 600.'],
  ['Product', 'Restock Battery', 'Cost 80 / Sell 140 / Stock 5', 'Purchase restock updates cost to 90 and sell to 160.'],
  ['Supplier', 'Workflow Supplier opening due', 0, 'Expected due after purchase is 140.'],
  ['Sale', 'INV-DUE-100', 'Grand 1000 / Paid 400 / Due 600', 'Due payment collection test.'],
  ['Cheque', 'CHQ-100', 'Bank BOC / Amount 1500', 'Pending cheque sale test.'],
  ['PDF', 'cash-book-report.pdf', 'Server-side accounting export', 'Automated Livewire file download assertion.'],
];

const coverageRows = [
  ['Module', 'Automated Cases', 'Manual Cases', 'Key Risks Covered'],
  ['Product', 3, 0, 'Product creation, list loading, POS search.'],
  ['Customer', 3, 1, 'Customer creation, quick checkout customer, ledger timeline, public bill customer view.'],
  ['POS Sale', 8, 1, 'Full payment, due sale, customer checkout, cart edit, discounts, stock deduction, POS PDF share.'],
  ['Cheque', 5, 0, 'Pending cheque, walk-in blocking, passed/returned cheque, auto-pass aging.'],
  ['Purchase', 3, 0, 'Restock entry, supplier due, stock and pricing updates, restock deep link.'],
  ['Reports & Accounting', 4, 0, 'Business report filters, accounting ledgers, server-side PDF export.'],
  ['PDF / Export', 1, 4, 'Accounting PDF is automated; browser-generated jsPDF exports require manual/browser test.'],
  ['SMS / Expense', 2, 0, 'SMS gateway behavior and expense page smoke coverage.'],
];

function styleTitle(sheet, title, subtitle) {
  sheet.getRange('A1:J1').merge();
  sheet.getRange('A1').values = [[title]];
  sheet.getRange('A1').format.fill = '#1F4E79';
  sheet.getRange('A1').format.font = { color: '#FFFFFF', bold: true, size: 18 };
  sheet.getRange('A1').format.horizontalAlignment = 'center';
  sheet.getRange('A2:J2').merge();
  sheet.getRange('A2').values = [[subtitle]];
  sheet.getRange('A2').format.fill = '#D9EAF7';
  sheet.getRange('A2').format.font = { color: '#17365D', italic: true };
  sheet.getRange('A2').format.horizontalAlignment = 'center';
}

styleTitle(summary, 'IMRAN POS - Test Case Workbook', `Generated ${today} from current Laravel/Pest coverage`);

summary.getRange('A4:B10').values = [
  ['Metric', 'Value'],
  ['Total Test Cases', null],
  ['Automated Cases', null],
  ['Manual Cases', null],
  ['High Priority Cases', null],
  ['Medium Priority Cases', null],
  ['Low Priority Cases', null],
];
summary.getRange('B5:B10').formulas = [
  ['=COUNTA(\'Test Cases\'!A2:A200)'],
  ['=COUNTIF(\'Test Cases\'!J2:J200,"Automated")'],
  ['=COUNTIF(\'Test Cases\'!J2:J200,"Manual")'],
  ['=COUNTIF(\'Test Cases\'!D2:D200,"High")'],
  ['=COUNTIF(\'Test Cases\'!D2:D200,"Medium")'],
  ['=COUNTIF(\'Test Cases\'!D2:D200,"Low")'],
];
summary.getRange('A4:B4').format.fill = '#305496';
summary.getRange('A4:B4').format.font = { color: '#FFFFFF', bold: true };
summary.getRange('A4:B10').format.borders = { preset: 'all', style: 'thin', color: '#A6A6A6' };

summary.getRange('D4:H12').values = coverageRows;
summary.getRange('D4:H4').format.fill = '#305496';
summary.getRange('D4:H4').format.font = { color: '#FFFFFF', bold: true };
summary.getRange('D4:H12').format.borders = { preset: 'all', style: 'thin', color: '#A6A6A6' };
summary.getRange('D4:H12').format.wrapText = true;

summary.getRange('A13:J13').merge();
summary.getRange('A13').values = [['Notes']];
summary.getRange('A13').format.fill = '#F4B183';
summary.getRange('A13').format.font = { bold: true };
summary.getRange('A14:J16').merge();
summary.getRange('A14').values = [[
  'Automated cases are covered by Pest/Livewire tests in the repository. Manual cases mostly involve browser-generated jsPDF flows (POS bill, sales invoice, customer ledger) where a real browser should verify download/share behavior, filename, and visual PDF output.'
]];
summary.getRange('A14').format.wrapText = true;
summary.getRange('A14').format.verticalAlignment = 'top';

const headers = ['Test Case ID', 'Module', 'Scenario', 'Priority', 'Preconditions', 'Step-by-step Actions', 'Test Data', 'Expected Result', 'Automation Reference', 'Status'];
cases.getRange('A1:J1').values = [headers];
cases.getRange('A2').write(testCases);
cases.getRange('A1:J1').format.fill = '#1F4E79';
cases.getRange('A1:J1').format.font = { color: '#FFFFFF', bold: true };
cases.getRange(`A1:J${testCases.length + 1}`).format.borders = { preset: 'all', style: 'thin', color: '#D9E2F3' };
cases.getRange(`A1:J${testCases.length + 1}`).format.wrapText = true;
cases.getRange(`D2:D${testCases.length + 1}`).conditionalFormats.add('containsText', {
  text: 'High',
  format: { fill: '#F4CCCC', font: { color: '#990000', bold: true } },
});
cases.getRange(`J2:J${testCases.length + 1}`).conditionalFormats.add('containsText', {
  text: 'Automated',
  format: { fill: '#D9EAD3', font: { color: '#274E13', bold: true } },
});
cases.getRange(`J2:J${testCases.length + 1}`).conditionalFormats.add('containsText', {
  text: 'Manual',
  format: { fill: '#FFF2CC', font: { color: '#7F6000', bold: true } },
});

data.getRange('A1:D1').values = [testDataRows[0]];
data.getRange('A2').write(testDataRows.slice(1));
data.getRange('A1:D1').format.fill = '#1F4E79';
data.getRange('A1:D1').format.font = { color: '#FFFFFF', bold: true };
data.getRange(`A1:D${testDataRows.length}`).format.borders = { preset: 'all', style: 'thin', color: '#D9E2F3' };
data.getRange(`A1:D${testDataRows.length}`).format.wrapText = true;

coverage.getRange('A1:D1').values = [coverageRows[0]];
coverage.getRange('A2').write(coverageRows.slice(1));
coverage.getRange('A1:D1').format.fill = '#1F4E79';
coverage.getRange('A1:D1').format.font = { color: '#FFFFFF', bold: true };
coverage.getRange(`A1:D${coverageRows.length}`).format.borders = { preset: 'all', style: 'thin', color: '#D9E2F3' };
coverage.getRange(`A1:D${coverageRows.length}`).format.wrapText = true;

for (const sheet of [summary, cases, data, coverage]) {
  sheet.getRange('A1:J80').format.autofitColumns();
  sheet.getRange('A1:J80').format.autofitRows();
}

const inspectSummary = await workbook.inspect({
  kind: 'table',
  range: 'Summary!A1:J16',
  include: 'values,formulas',
  tableMaxRows: 20,
  tableMaxCols: 10,
});
console.log(inspectSummary.ndjson);

const inspectCases = await workbook.inspect({
  kind: 'table',
  range: 'Test Cases!A1:J12',
  include: 'values',
  tableMaxRows: 12,
  tableMaxCols: 10,
});
console.log(inspectCases.ndjson);

const errors = await workbook.inspect({
  kind: 'match',
  searchTerm: '#REF!|#DIV/0!|#VALUE!|#NAME\\?|#N/A',
  options: { useRegex: true, maxResults: 300 },
  summary: 'final formula error scan',
});
console.log(errors.ndjson);

for (const sheetName of ['Summary', 'Test Cases', 'Test Data', 'Coverage Map']) {
  await workbook.render({ sheetName, range: sheetName === 'Test Cases' ? 'A1:J18' : 'A1:J20', scale: 1 });
  console.log(`rendered:${sheetName}`);
}

await fs.mkdir(outputDir, { recursive: true });
const output = await SpreadsheetFile.exportXlsx(workbook);
await output.save(outputPath);
console.log(`saved:${outputPath}`);
