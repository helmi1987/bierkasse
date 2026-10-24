<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Privacy policy and terms of use: plain text (admin settings, default from resources/legal/*.txt),
 * placeholders replaced at render, simple and safe rendering (everything escaped, no raw HTML):
 *  - blank line = new block; lines in a block are joined with <br>,
 *  - lines starting with "- " = list items,
 *  - lines like "1. Titel" = headings,
 *  - a single plain first line = page title.
 *
 * Each text has its own version ("Stand", UTC "Y-m-d H:i:s"). Users must accept both current
 * versions (see User::needsConsent()).
 */
class LegalTexts
{
    public const PRIVACY = 'privacy';

    public const TERMS = 'terms';

    public const KINDS = [self::PRIVACY, self::TERMS];

    /** Version of both texts until an admin forces a new one. */
    public const DEFAULT_VERSION = '2026-10-24 00:00:00';

    public const TEXT_MAX = 20000;

    private const TEXT_KEYS = [self::PRIVACY => AppSettings::PRIVACY_TEXT, self::TERMS => AppSettings::TERMS_TEXT];

    private const VERSION_KEYS = [self::PRIVACY => AppSettings::PRIVACY_VERSION, self::TERMS => AppSettings::TERMS_VERSION];

    public static function textKey(string $kind): string
    {
        return self::TEXT_KEYS[$kind];
    }

    public static function versionKey(string $kind): string
    {
        return self::VERSION_KEYS[$kind];
    }

    /** Stored text (with placeholders) or the default. */
    public static function text(string $kind): string
    {
        $stored = AppSettings::get(self::TEXT_KEYS[$kind]);

        return is_string($stored) && trim($stored) !== '' ? $stored : self::defaultText($kind);
    }

    public static function defaultText(string $kind): string
    {
        $path = resource_path('legal/'.$kind.'.txt');

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    public static function version(string $kind): string
    {
        $stored = AppSettings::get(self::VERSION_KEYS[$kind]);

        return is_string($stored) && $stored !== '' ? $stored : self::DEFAULT_VERSION;
    }

    /** New version for a forced re-acceptance (now, UTC). */
    public static function newVersion(): string
    {
        return now()->utc()->format('Y-m-d H:i:s');
    }

    /** Version as local date "24.10.2026" ("Stand"). */
    public static function versionDate(?string $version): string
    {
        if ($version === null || $version === '') {
            return '–';
        }
        try {
            return local_time(Carbon::parse($version, 'UTC'), 'd.m.Y');
        } catch (Throwable) {
            return $version;
        }
    }

    /**
     * Placeholder values (current settings).
     *
     * @return array<string, string>
     */
    public static function placeholders(): array
    {
        return [
            '{name}' => AppSettings::legalName(),
            '{email}' => AppSettings::legalEmail(),
            '{standort}' => AppSettings::legalLocation(),
            '{bierpreis}' => chf(AppSettings::beerPrice()),
            '{limit}' => chf(AppSettings::defaultLimit()),
            '{undo_minuten}' => (string) AppSettings::undoMinutes('consume'),
        ];
    }

    /**
     * Rendered text: title (first line, if it is a plain single line block) and escaped HTML body.
     *
     * @return array{title: ?string, html: HtmlString}
     */
    public static function render(string $kind): array
    {
        return self::renderText(self::text($kind));
    }

    /**
     * @return array{title: ?string, html: HtmlString}
     */
    public static function renderText(string $text): array
    {
        $text = strtr(str_replace(["\r\n", "\r"], "\n", $text), self::placeholders());
        $blocks = preg_split('/\n\s*\n/', trim($text)) ?: [];
        $title = null;
        if ($blocks !== []) {
            $first = trim($blocks[0]);
            if ($first !== '' && ! str_contains($first, "\n") && ! self::isHeading($first) && ! str_starts_with($first, '- ')) {
                $title = $first;
                array_shift($blocks);
            }
        }

        $html = '';
        foreach ($blocks as $block) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), fn (string $l) => $l !== ''));
            $paragraph = [];
            $list = [];
            $flushParagraph = function () use (&$paragraph, &$html) {
                if ($paragraph !== []) {
                    $html .= '<p>'.implode('<br>', array_map('e', $paragraph)).'</p>';
                    $paragraph = [];
                }
            };
            $flushList = function () use (&$list, &$html) {
                if ($list !== []) {
                    $html .= '<ul>'.implode('', array_map(fn (string $i) => '<li>'.e($i).'</li>', $list)).'</ul>';
                    $list = [];
                }
            };
            foreach ($lines as $line) {
                if (self::isHeading($line)) {
                    $flushParagraph();
                    $flushList();
                    $html .= '<h2>'.e($line).'</h2>';
                } elseif (str_starts_with($line, '- ')) {
                    $flushParagraph();
                    $list[] = trim(substr($line, 2));
                } else {
                    $flushList();
                    $paragraph[] = $line;
                }
            }
            $flushParagraph();
            $flushList();
        }

        return ['title' => $title, 'html' => new HtmlString($html)];
    }

    /** User accepted both current versions. */
    public static function accepted(User $user): bool
    {
        return $user->privacy_accepted_version === self::version(self::PRIVACY)
            && $user->terms_accepted_version === self::version(self::TERMS);
    }

    /**
     * Attributes that record the consent to both current versions now.
     *
     * @return array{privacy_accepted_version: string, terms_accepted_version: string, consent_accepted_at: Carbon}
     */
    public static function consentAttributes(): array
    {
        return [
            'privacy_accepted_version' => self::version(self::PRIVACY),
            'terms_accepted_version' => self::version(self::TERMS),
            'consent_accepted_at' => now(),
        ];
    }

    private static function isHeading(string $line): bool
    {
        return (bool) preg_match('/^\d{1,2}\.\s+\S/u', $line);
    }
}
