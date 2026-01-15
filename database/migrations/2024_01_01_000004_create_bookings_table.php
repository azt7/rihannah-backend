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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            // Inline customer info (for quick add without creating customer record)
            $table->string('customer_name', 150)->nullable();
            $table->string('customer_phone', 20)->nullable();

            // Dates
            $table->date('start_date');
            $table->date('end_date');

            // Payment info
            $table->decimal('price_total', 10, 2);
            $table->decimal('amount_paid', 10, 2)->default(0);
            $table->decimal('remaining_amount', 10, 2)->storedAs('price_total - amount_paid');

            $table->enum('payment_status', ['unpaid', 'partial', 'paid'])->default('unpaid');
            $table->enum('payment_method', ['cash', 'transfer', 'card'])->nullable();
            $table->decimal('deposit_amount', 10, 2)->nullable();
            $table->string('reference_number', 100)->nullable();

            // Booking status
            $table->enum('booking_status', [
                'tentative',
                'confirmed',
                'checked_in',
                'checked_out',
                'cancelled'
            ])->default('confirmed');

            // Notes
            $table->text('notes')->nullable();

            // Cancellation info
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();

            // Audit
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Indexes for calendar queries and conflict detection
            $table->index(['unit_id', 'start_date', 'end_date']);
            $table->index(['start_date', 'end_date']);
            $table->index('booking_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
