<?php

namespace App\Services\Activity;

use App\Models\ActivityEvent;
use App\Models\Shop;

class ActivityLogger
{
    public function record(Shop $shop, string $type, string $subject, string $summary): ActivityEvent
    {
        return $shop->activityEvents()->create([
            'type' => $type,
            'subject' => $subject,
            'summary' => $summary,
            'occurred_at' => now(),
        ]);
    }
}
