<?php

namespace App\Mail;

use App\Support\AppSettings;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Plain-text notice to the kassiers (new deposit report, payout request, new registration).
 */
class KassierNotice extends Mailable
{
    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public string $noticeSubject,
        public array $lines,
        public ?string $url = null,
        public ?string $footer = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '['.AppSettings::appName().'] '.$this->noticeSubject);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.kassier-notice', with: [
            'lines' => $this->lines,
            'url' => $this->url ?? route('backend.dashboard'),
            'footer' => $this->footer ?? __('mail.footer'),
        ]);
    }
}
