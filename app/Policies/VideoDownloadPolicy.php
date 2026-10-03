<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VideoDownload;

class VideoDownloadPolicy
{
    public function view(User $user, VideoDownload $videoDownload): bool
    {
        return $videoDownload->user_id === $user->id;
    }

    public function delete(User $user, VideoDownload $videoDownload): bool
    {
        return $videoDownload->user_id === $user->id;
    }
}
