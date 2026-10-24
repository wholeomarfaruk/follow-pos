<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * The Notification Hub's in-app channel: a row in `notifications`, read by the
 * topbar bell (data.message, optional data.url to open on click).
 */
class HubNotification extends Notification
{
    public function __construct(
        public string $eventKey,
        public string $title,
        public string $body,
        public ?string $url = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event_key' => $this->eventKey,
            'title' => $this->title,
            'body' => $this->body,
            'message' => trim($this->title . ($this->body !== '' ? ' — ' . $this->body : '')),
            'url' => $this->url,
        ];
    }
}
