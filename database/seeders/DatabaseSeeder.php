<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\SmsNotificationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Seed Core Users
        $users = [
            [
                'name' => 'Super Admin',
                'email' => 'admin@example.com',
                'username' => 'admin',
                'role' => 'super_admin',
            ],
            [
                'name' => 'Cashier Imran',
                'email' => 'cashier@example.com',
                'username' => 'cashier',
                'role' => 'cashier',
            ],
            [
                'name' => 'Accountant Sam',
                'email' => 'accountant@example.com',
                'username' => 'accountant',
                'role' => 'accountant',
            ],
            [
                'name' => 'Inventory Manager',
                'email' => 'inventory@example.com',
                'username' => 'inventory',
                'role' => 'inventory_manager',
            ],
        ];

        foreach ($users as $user) {
            User::query()->updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'username' => $user['username'],
                    'password' => Hash::make('password'),
                    'role' => $user['role'],
                    'is_active' => true,
                ],
            );
        }

        // 2. Seed Default Settings
        $settings = [
            'business_name' => 'Imran Phone Accessories',
            'business_logo' => null,
            'business_phone' => '+94 77 123 4567',
            'business_email' => 'info@imranaccessories.com',
            'business_address' => 'No. 45, Mobile Plaza, Colombo 11, Sri Lanka',
            'currency_symbol' => 'Rs',
            'currency_name' => 'LKR',
            'date_format' => 'Y-m-d',
            'time_zone' => 'Asia/Colombo',
            'tax_enabled' => '0',
            'tax_percentage' => '0',
            'pos_allow_due_sale' => '1',
            'pos_allow_negative_stock' => '0',
            'pos_show_product_image' => '1',
            'pos_enable_hold_order' => '1',
            'pos_enable_barcode_scanner' => '1',
            'pos_enable_multiple_price' => '1',
            'pos_enable_stock_by_price' => '0',
            'invoice_prefix' => 'INV-',
            'invoice_starting_no' => '1001',
            'invoice_paper_size' => 'thermal_80mm', // thermal_58mm, thermal_80mm, A4
            'invoice_footer_note' => 'Thank you for shopping with us! No cash refunds. Exchange valid within 7 days with invoice.',
            'invoice_terms' => 'Warranty claims subject to physical inspection. Tempered glass and back covers carry no warranty.',
            'sms_enabled' => '1',
            'sms_textit_id' => '94758822269',
            'sms_textit_password' => '6886',
            'sms_textit_base_url' => 'https://textit.biz/sendmsg',
            'sms_notify_sale_enabled' => '1',
            'sms_notify_payment_enabled' => '1',
            'sms_notify_cheque_passed_enabled' => '1',
            'sms_notify_cheque_reminder_enabled' => '1',
            'sms_template_sale' => SmsNotificationService::DEFAULT_TEMPLATES['sms_template_sale'],
            'sms_template_due' => 'Dear {cus_name}, a friendly reminder of your outstanding due balance of Rs {due} at {business_name}. Invoice Ref: {invoice_no}. Thank you.',
            'sms_template_payment' => SmsNotificationService::DEFAULT_TEMPLATES['sms_template_payment'],
            'sms_template_cheque_passed' => SmsNotificationService::DEFAULT_TEMPLATES['sms_template_cheque_passed'],
            'sms_template_cheque_reminder' => SmsNotificationService::DEFAULT_TEMPLATES['sms_template_cheque_reminder'],
            'sms_template_return' => 'Hi {customer_name}, your return request for invoice {invoice_no} has been processed at {business_name}. Refund: Rs {total}. Thank you.',
        ];

        foreach ($settings as $key => $value) {
            Setting::set($key, $value, 'general');
        }

        // 3. Seed Default Walk-in Customer
        $walkIn = Customer::query()->updateOrCreate(
            ['phone' => '0000000000'],
            [
                'name' => 'Walk-in Customer',
                'email' => 'walkin@example.com',
                'address' => 'Colombo, Sri Lanka',
                'opening_balance' => 0.00,
                'due_balance' => 0.00,
            ],
        );

        // Seed an extra test customer
        Customer::query()->updateOrCreate(
            ['phone' => '0771234567'],
            [
                'name' => 'Mohamed Nasran',
                'email' => 'nasran@example.com',
                'address' => 'Galle Road, Colombo 03',
                'opening_balance' => 0.00,
                'due_balance' => 1500.00, // starting test due
            ],
        );

        // 4. Seed Default Supplier
        $suppliers = [
            [
                'name' => 'Imran Wholesale Distributors',
                'phone' => '0777999888',
                'email' => 'wholesale@imran.com',
                'company_name' => 'Imran Accessories Ltd',
                'address' => 'Pettah Wholesale Market, Colombo 11',
            ],
            [
                'name' => 'Colombo Mobile Hub',
                'phone' => '0777001100',
                'email' => 'sales@cmbmobilehub.test',
                'company_name' => 'Colombo Mobile Hub Pvt Ltd',
                'address' => '1st Cross Street, Colombo 11',
            ],
            [
                'name' => 'Galaxy Accessories Lanka',
                'phone' => '0777002200',
                'email' => 'orders@galaxyaccessories.test',
                'company_name' => 'Galaxy Accessories Lanka',
                'address' => 'Main Street, Kandy',
            ],
            [
                'name' => 'Quick Charge Traders',
                'phone' => '0777003300',
                'email' => 'accounts@quickcharge.test',
                'company_name' => 'Quick Charge Traders',
                'address' => 'Galle Road, Dehiwala',
            ],
            [
                'name' => 'Smart Cover Wholesale',
                'phone' => '0777004400',
                'email' => 'hello@smartcover.test',
                'company_name' => 'Smart Cover Wholesale',
                'address' => 'Negombo Road, Wattala',
            ],
        ];

        foreach ($suppliers as $supplier) {
            Supplier::query()->updateOrCreate(
                ['phone' => $supplier['phone']],
                [
                    'name' => $supplier['name'],
                    'email' => $supplier['email'],
                    'company_name' => $supplier['company_name'],
                    'address' => $supplier['address'],
                    'opening_balance' => 0.00,
                    'due_balance' => 0.00,
                ],
            );
        }

        // 5. Seed Units
        $pcs = Unit::query()->updateOrCreate(['short_name' => 'pcs'], ['name' => 'Pieces', 'is_active' => true]);
        $box = Unit::query()->updateOrCreate(['short_name' => 'box'], ['name' => 'Box', 'is_active' => true]);
        $set = Unit::query()->updateOrCreate(['short_name' => 'set'], ['name' => 'Set', 'is_active' => true]);

        // 6. Seed Categories
        $cats = [
            'Covers & Cases',
            'Tempered Glasses',
            'Chargers & Adapters',
            'Cables & Hubs',
            'Earphones & Speakers',
            'Powerbanks',
            'Smart Watch Straps',
            'Memory Cards & USBs',
            'Car Holders',
            'Repair Spare Parts',
        ];
        foreach ($cats as $cat) {
            Category::query()->updateOrCreate(['name' => $cat], ['is_active' => true]);
        }

        // 7. Seed Brands
        $brands = [
            'Spigen',
            'Anker',
            'Joyroom',
            'Baseus',
            'Samsung',
            'Apple',
            'Xiaomi',
            'Remax',
            'Ugreen',
            'LDNIO',
        ];
        foreach ($brands as $brand) {
            Brand::query()->updateOrCreate(['name' => $brand], ['is_active' => true]);
        }

        $this->call(SampleProductsSeeder::class);
    }
}
