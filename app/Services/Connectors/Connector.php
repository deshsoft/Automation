<?php

namespace App\Services\Connectors;

use App\Exceptions\PublishingException;
use App\Models\User;

interface Connector
{
    /**
     * The URL the user is sent to for granting access.
     */
    public function authorizationUrl(string $state): string;

    /**
     * Exchange the OAuth code for tokens and save every account it grants access to.
     *
     * @return int The number of accounts connected or refreshed.
     *
     * @throws PublishingException
     */
    public function connect(string $code, User $user): int;
}
