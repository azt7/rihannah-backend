<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add tentative expiry hours setting if it doesn't exist
        if (!Setting::where('key', 'tentative_expiry_hours')->exists()) {
            Setting::create([
                'key' => 'tentative_expiry_hours',
                'value' => '4',
                'type' => 'integer',
                'group' => 'booking',
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Setting::where('key', 'tentative_expiry_hours')->delete();
    }
};
