<?php

namespace App\Policies;

use App\Models\LiveStream;
use App\Models\User;

class LiveStreamPolicy
{
    public function view(User $user, LiveStream $liveStream): bool
    {
        return $liveStream->user_id === $user->id;
    }

    public function update(User $user, LiveStream $liveStream): bool
    {
        return $liveStream->user_id === $user->id;
    }
}
