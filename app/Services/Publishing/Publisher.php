<?php

namespace App\Services\Publishing;

use App\Exceptions\PublishingException;
use App\Models\PostTarget;

interface Publisher
{
    /**
     * Publish (or continue publishing) the target's post to its social account.
     *
     * @throws PublishingException for permanent failures
     */
    public function publish(PostTarget $target): PublishResult;
}
