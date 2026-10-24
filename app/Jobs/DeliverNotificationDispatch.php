<?php

namespace App\Jobs;

use App\Models\NotificationDispatch;
use App\NotificationHub\NotificationHub;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one Notification Hub dispatch. Only an id is queued: the row is the
 * source of truth, and NotificationHub::deliver() claims it, so this job and
 * notifications:flush can never both send it.
 */
class DeliverNotificationDispatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $dispatchId) {}

    public function handle(NotificationHub $hub): void
    {
        $dispatch = NotificationDispatch::find($this->dispatchId);

        if ($dispatch) {
            $hub->deliver($dispatch);
        }
    }
}
