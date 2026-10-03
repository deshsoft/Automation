<?php

namespace App\Policies;

use App\Models\SocialAccount;
use App\Models\User;

class SocialAccountPolicy
{
    public function update(User $user, SocialAccount $socialAccount): bool
    {
        return $socialAccount->user_id === $user->id;
    }

    public function delete(User $user, SocialAccount $socialAccount): bool
    {
        return $socialAccount->user_id === $user->id;
    }
}
