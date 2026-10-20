<?php

namespace App\Mail;

use App\Support\AppSettings;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Plain-text notice to the kassiers (new deposit report, payout request).
 */
class KassierNotice extends Mailable
{
    /**
     * @param  list<string>  $lines
     */
    public function __construct(public string $noticeSubject, public array $lines) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '['.AppSettings::appName().'] '.$this->noticeSubject);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.kassier-notice', with: [
            'lines' => $this->lines,
            'url' => route('backend.dashboard'),
        ]);
    }
}
