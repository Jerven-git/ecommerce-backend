<?php

use Illuminate\Support\Facades\Broadcast;

// Public channel - no auth needed
Broadcast::channel('ssu.updates', function () {
    return true;
});

// Private admin channel - requires authenticated admin
Broadcast::channel('ssu.admin', function ($user) {
    return $user->is_admin === true;
});
