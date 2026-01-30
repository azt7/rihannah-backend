<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Unit;
use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create admin user
        User::create([
            'name' => 'Admin',
            'email' => 'admin@chalet.local',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        // Create 8 chalet units
        for ($i = 1; $i <= 8; $i++) {
            Unit::create([
                'name' => "Unit $i",
                'status' => 'active',
                'sort_order' => $i,
            ]);
        }

        // Create default WhatsApp message template (Arabic) - now includes booking reference
        Setting::create([
            'key' => 'whatsapp_template',
            'value' => "حجز الشاليه ✅\nرقم الحجز: {reference}\nالاسم: {customer_name}\nرقم الجوال: {phone}\nالوحدة: {unit_name}\nالتاريخ: {start_date} إلى {end_date}\nالسعر: {total_price} ر.س\nالمدفوع: {paid} ر.س\nالمتبقي: {remaining} ر.س",
            'type' => 'text',
            'group' => 'whatsapp',
        ]);

        // English template alternative - now includes booking reference
        Setting::create([
            'key' => 'whatsapp_template_en',
            'value' => "Chalet Booking ✅\nRef: {reference}\nName: {customer_name}\nPhone: {phone}\nUnit: {unit_name}\nDates: {start_date} to {end_date}\nTotal: {total_price} SAR\nPaid: {paid} SAR\nRemaining: {remaining} SAR",
            'type' => 'text',
            'group' => 'whatsapp',
        ]);

        // Tentative booking expiry hours (US-RA-03, US-SYS-02)
        // Tentative booking expiry hours (US-RA-03, US-SYS-02)
        Setting::firstOrCreate(
            ['key' => 'tentative_expiry_hours'],
            [
                'value' => '4',
                'type' => 'integer',
                'group' => 'booking',
            ]
        );

        // App settings
        Setting::create([
            'key' => 'app_name',
            'value' => 'Chalet Booking System',
            'type' => 'string',
            'group' => 'general',
        ]);

        Setting::create([
            'key' => 'timezone',
            'value' => 'Asia/Riyadh',
            'type' => 'string',
            'group' => 'general',
        ]);

        Setting::create([
            'key' => 'currency',
            'value' => 'SAR',
            'type' => 'string',
            'group' => 'general',
        ]);

        Setting::create([
            'key' => 'default_language',
            'value' => 'ar',
            'type' => 'string',
            'group' => 'general',
        ]);
    }
}
