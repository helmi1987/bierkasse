<?php

namespace App\Mail;

use App\Support\AppSettings;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Notice in the shared mail layout (deposit report, payout request, low stock, new registration,
 * feedback). HTML plus a plain-text alternative.
 */
class KassierNotice extends Mailable
{
    /**
     * @param  list<string>  $lines  Paragraphs before the facts table
     * @param  string|null  $url  Button target (default: backend dashboard)
     * @param  string|null  $buttonLabel  Button text (default: mail.button_backend)
     * @param  array<string, string>  $rows  Facts as label => value
     * @param  string|null  $title  Heading in the mail (default: the subject)
     * @param  list<string>  $outro  Paragraphs after the button
     * @param  string|null  $textBlock  Free text after the facts table (escaped, line breaks kept)
     */
    public function __construct(
        public string $noticeSubject,
        public array $lines = [],
        public ?string $url = null,
        public ?string $buttonLabel = null,
        public array $rows = [],
        public ?string $title = null,
        public array $outro = [],
        public ?string $textBlock = null,
    ) {
        // Public properties are passed to the views as well, so normalize them here.
        $this->rows = array_filter($rows, fn ($value) => $value !== null && $value !== '');
        $this->title ??= $noticeSubject;
        $this->buttonLabel ??= __('mail.button_backend');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: '['.AppSettings::appName().'] '.$this->noticeSubject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.notice',
            text: 'mail.notice-text',
            with: [
                'appName' => AppSettings::appName(),
                'appUrl' => url('/'),
                'title' => $this->title,
                'preheader' => $this->lines[0] ?? null,
                'lines' => $this->lines,
                'rows' => $this->rows,
                'buttonUrl' => $this->url ?? route('backend.dashboard'),
                'buttonLabel' => $this->buttonLabel,
                'outro' => $this->outro,
                'textBlock' => $this->textBlock,
            ],
        );
    }
}
