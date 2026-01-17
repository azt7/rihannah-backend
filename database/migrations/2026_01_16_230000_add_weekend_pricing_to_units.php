<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->decimal('price_thursday', 10, 2)->nullable()->after('default_price');
            $table->decimal('price_friday', 10, 2)->nullable()->after('price_thursday');
            $table->decimal('price_saturday', 10, 2)->nullable()->after('price_friday');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn(['price_thursday', 'price_friday', 'price_saturday']);
        });
    }
};
