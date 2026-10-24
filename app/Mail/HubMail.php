<?php

namespace App\Mail;

use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A Notification Hub email: the rule / campaign text as a plain paragraph
 * inside the shared mail layout. Not queued itself; it is already sent from
 * the hub's delivery job.
 */
class HubMail extends Mailable
{
    use Queueable;

    public function __construct(
        public string $mailSubject,
        public string $text,
        public ?string $link = null,
    ) {}

    public function envelope(): Envelope
    {
        $this->applySiteName();

        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        $this->applySiteName();

        $html = nl2br(e($this->text));
        if ($this->link) {
            $html .= '<br><br><a href="' . e($this->link) . '">' . e($this->link) . '</a>';
        }

        return new Content(markdown: 'emails.template', with: ['renderedBody' => $html]);
    }

    // Same as TemplatedMail: workers froze config('app.name') at boot.
    protected function applySiteName(): void
    {
        $siteName = Setting::get('site_name');

        if (filled($siteName)) {
            config(['app.name' => $siteName]);
        }
    }
}
