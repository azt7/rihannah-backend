<?php

use Illuminate\Support\Facades\Broadcast;

// Public channel for bookings - all authenticated users can listen
Broadcast::channel('bookings', function ($user) {
    return $user !== null;
});
