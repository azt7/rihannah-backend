<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Unit;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Test Case: TC-BK-DUP-01 and variations
 * Title: System must prevent duplicate or overlapping bookings for the same unit
 */
class BookingDuplicateTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Unit $unit1;
    protected Unit $unit2;
    protected BookingService $bookingService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test user
        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'role' => 'agent',
        ]);

        // Create test units
        $this->unit1 = Unit::create([
            'name' => 'Unit 1',
            'default_price' => 500,
            'status' => 'active',
        ]);

        $this->unit2 = Unit::create([
            'name' => 'Unit 2',
            'default_price' => 600,
            'status' => 'active',
        ]);

        $this->bookingService = app(BookingService::class);
    }

    /**
     * Helper to create a booking via service
     */
    protected function createBooking(array $data): Booking
    {
        $this->actingAs($this->user);
        return $this->bookingService->create(array_merge([
            'unit_id' => $this->unit1->id,
            'customer_name' => 'Test Customer',
            'customer_phone' => '+966500000000',
            'price_total' => 1000,
            'amount_paid' => 0,
            'booking_status' => 'confirmed',
        ], $data));
    }

    /**
     * TC-BK-DUP-01: Overlapping dates should be blocked
     * Existing: 2026-01-20 to 2026-01-22
     * New attempt: 2026-01-21 to 2026-01-23
     * Expected: BLOCKED
     */
    public function test_overlapping_dates_are_blocked(): void
    {
        // Create existing booking
        $this->createBooking([
            'start_date' => '2026-01-20',
            'end_date' => '2026-01-22',
        ]);

        // Attempt overlapping booking - should throw ValidationException
        $this->expectException(ValidationException::class);

        $this->createBooking([
            'start_date' => '2026-01-21',
            'end_date' => '2026-01-23',
            'customer_name' => 'Another Customer',
            'customer_phone' => '+966500000001',
        ]);
    }

    /**
     * TC-BK-DUP-02: Exact same dates should be blocked
     * Existing: 2026-01-20 to 2026-01-22
     * New attempt: 2026-01-20 to 2026-01-22
     * Expected: BLOCKED
     */
    public function test_exact_same_dates_are_blocked(): void
    {
        $this->createBooking([
            'start_date' => '2026-01-20',
            'end_date' => '2026-01-22',
        ]);

        $this->expectException(ValidationException::class);

        $this->createBooking([
            'start_date' => '2026-01-20',
            'end_date' => '2026-01-22',
            'customer_name' => 'Another Customer',
            'customer_phone' => '+966500000001',
        ]);
    }

    /**
     * TC-BK-DUP-03: Same start date should be blocked
     * Existing: 2026-01-20 to 2026-01-22
     * New attempt: 2026-01-20 to 2026-01-21
     * Expected: BLOCKED
     */
    public function test_same_start_date_is_blocked(): void
    {
        $this->createBooking([
            'start_date' => '2026-01-20',
            'end_date' => '2026-01-22',
        ]);

        $this->expectException(ValidationException::class);

        $this->createBooking([
            'start_date' => '2026-01-20',
            'end_date' => '2026-01-21',
            'customer_name' => 'Another Customer',
            'customer_phone' => '+966500000001',
        ]);
    }

    /**
     * TC-BK-DUP-04: Same end date should be blocked
     * Existing: 2026-01-20 to 2026-01-22
     * New attempt: 2026-01-21 to 2026-01-22
     * Expected: BLOCKED
     */
    public function test_same_end_date_is_blocked(): void
    {
        $this->createBooking([
            'start_date' => '2026-01-20',
            'end_date' => '2026-01-22',
        ]);

        $this->expectException(ValidationException::class);

        $this->createBooking([
            'start_date' => '2026-01-21',
            'end_date' => '2026-01-22',
            'customer_name' => 'Another Customer',
            'customer_phone' => '+966500000001',
        ]);
    }

    /**
     * TC-BK-DUP-05: Adjacent dates should be ALLOWED
     * Existing: 2026-01-20 to 2026-01-22
     * New attempt: 2026-01-23 to 2026-01-24
     * Expected: ALLOWED (checkout day = next check-in allowed)
     */
    public function test_adjacent_dates_are_allowed(): void
    {
        $this->createBooking([
            'start_date' => '2026-01-20',
            'end_date' => '2026-01-22',
        ]);

        // This should NOT throw an exception
        $newBooking = $this->createBooking([
            'start_date' => '2026-01-23',
            'end_date' => '2026-01-24',
            'customer_name' => 'Another Customer',
            'customer_phone' => '+966500000001',
        ]);

        $this->assertNotNull($newBooking->id);
        $this->assertEquals('2026-01-23', $newBooking->start_date->format('Y-m-d'));
    }

    /**
     * TC-BK-DUP-05b: Check-in on checkout day should be ALLOWED
     * Existing: 2026-01-20 to 2026-01-22
     * New attempt: 2026-01-22 to 2026-01-24
     * Expected: ALLOWED (checkout day = check-in day is allowed)
     */
    public function test_checkin_on_checkout_day_is_allowed(): void
    {
        $this->createBooking([
            'start_date' => '2026-01-20',
            'end_date' => '2026-01-22',
        ]);

        // Check-in on the same day as checkout should be allowed
        $newBooking = $this->createBooking([
            'start_date' => '2026-01-22',
            'end_date' => '2026-01-24',
            'customer_name' => 'Another Customer',
            'customer_phone' => '+966500000001',
        ]);

        $this->assertNotNull($newBooking->id);
        $this->assertEquals('2026-01-22', $newBooking->start_date->format('Y-m-d'));
    }

    /**
     * TC-BK-DUP-06: Cancelled booking should NOT block new booking
     * Existing booking status: Cancelled
     * New booking on same dates
     * Expected: ALLOWED
     */
    public function test_cancelled_booking_does_not_block(): void
    {
        // Create and cancel a booking
        $existingBooking = $this->createBooking([
            'start_date' => '2026-01-20',
            'end_date' => '2026-01-22',
        ]);

        $this->bookingService->cancel($existingBooking, 'Test cancellation');

        // New booking on same dates should be allowed
        $newBooking = $this->createBooking([
            'start_date' => '2026-01-20',
            'end_date' => '2026-01-22',
            'customer_name' => 'Another Customer',
            'customer_phone' => '+966500000001',
        ]);

        $this->assertNotNull($newBooking->id);
        $this->assertEquals('confirmed', $newBooking->booking_status);
    }

    /**
     * TC-BK-DUP-07: Different unit same dates should be ALLOWED
     * Existing: Unit 1, 2026-01-20 to 2026-01-22
     * New: Unit 2, 2026-01-20 to 2026-01-22
     * Expected: ALLOWED
     */
    public function test_different_unit_same_dates_is_allowed(): void
    {
        // Create booking on Unit 1
        $this->createBooking([
            'unit_id' => $this->unit1->id,
            'start_date' => '2026-01-20',
            'end_date' => '2026-01-22',
        ]);

        // Booking on Unit 2 with same dates should be allowed
        $newBooking = $this->createBooking([
            'unit_id' => $this->unit2->id,
            'start_date' => '2026-01-20',
            'end_date' => '2026-01-22',
            'customer_name' => 'Another Customer',
            'customer_phone' => '+966500000001',
        ]);

        $this->assertNotNull($newBooking->id);
        $this->assertEquals($this->unit2->id, $newBooking->unit_id);
    }

    /**
     * Test that updating a booking to conflict is also blocked
     */
    public function test_update_to_conflicting_dates_is_blocked(): void
    {
        // Create two non-overlapping bookings
        $booking1 = $this->createBooking([
            'start_date' => '2026-01-20',
            'end_date' => '2026-01-22',
        ]);

        $booking2 = $this->createBooking([
            'start_date' => '2026-01-25',
            'end_date' => '2026-01-27',
            'customer_name' => 'Another Customer',
            'customer_phone' => '+966500000001',
        ]);

        // Try to update booking2 to overlap with booking1
        $this->expectException(ValidationException::class);

        $this->bookingService->update($booking2, [
            'start_date' => '2026-01-21',
            'end_date' => '2026-01-23',
        ]);
    }

    /**
     * Test that updating a booking's own dates is allowed (excludeId works)
     */
    public function test_update_own_booking_dates_is_allowed(): void
    {
        $booking = $this->createBooking([
            'start_date' => '2026-01-20',
            'end_date' => '2026-01-22',
        ]);

        // Extending the same booking should work
        $updatedBooking = $this->bookingService->update($booking, [
            'start_date' => '2026-01-19',
            'end_date' => '2026-01-23',
        ]);

        $this->assertEquals('2026-01-19', $updatedBooking->start_date->format('Y-m-d'));
        $this->assertEquals('2026-01-23', $updatedBooking->end_date->format('Y-m-d'));
    }

    /**
     * Test via API endpoint that validation error message is clear
     */
    public function test_api_returns_clear_validation_message(): void
    {
        // Create existing booking directly
        Booking::create([
            'unit_id' => $this->unit1->id,
            'customer_name' => 'Test Customer',
            'customer_phone' => '+966500000000',
            'start_date' => '2026-01-20',
            'end_date' => '2026-01-22',
            'price_total' => 1000,
            'amount_paid' => 0,
            'booking_status' => 'confirmed',
            'created_by' => $this->user->id,
        ]);

        // Try to create overlapping booking via API
        $response = $this->actingAs($this->user)
            ->postJson('/api/bookings', [
                'unit_id' => $this->unit1->id,
                'customer_name' => 'Another Customer',
                'customer_phone' => '+966500000001',
                'start_date' => '2026-01-21',
                'end_date' => '2026-01-23',
                'price_total' => 1000,
                'amount_paid' => 0,
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'errors' => ['dates']]);
        $response->assertJsonFragment(['dates' => ['This unit is already booked from Jan 20, 2026 to Jan 22, 2026.']]);
    }

    /**
     * Test that no reference number is generated when validation fails
     */
    public function test_no_reference_generated_on_validation_failure(): void
    {
        $this->createBooking([
            'start_date' => '2026-01-20',
            'end_date' => '2026-01-22',
        ]);

        $initialCount = Booking::count();

        try {
            $this->createBooking([
                'start_date' => '2026-01-21',
                'end_date' => '2026-01-23',
                'customer_name' => 'Another Customer',
                'customer_phone' => '+966500000001',
            ]);
        } catch (ValidationException $e) {
            // Expected
        }

        // No new booking should be created
        $this->assertEquals($initialCount, Booking::count());
    }
}
