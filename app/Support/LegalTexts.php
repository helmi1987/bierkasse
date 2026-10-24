<?php

namespace App\Support;

use App\Models\LegalRevision;
use App\Models\User;
use App\Services\LegalArchive;
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

    /** How a change of a text is announced (settings form). AUTO = update notice if the text changed, else silent. */
    public const MODE_AUTO = 'auto';

    public const MODE_SILENT = 'silent';

    public const MODE_UPDATE = 'update';

    public const MODE_REACCEPT = 'reaccept';

    public const MODES = [self::MODE_AUTO, self::MODE_SILENT, self::MODE_UPDATE, self::MODE_REACCEPT];

    /** Placeholders that come from app settings (a change of the setting changes the text for users). */
    public const SETTING_PLACEHOLDERS = ['{bierpreis}', '{limit}', '{undo_minuten}'];

    private const CHANGED_KEYS = [self::PRIVACY => AppSettings::PRIVACY_CHANGED, self::TERMS => AppSettings::TERMS_CHANGED];

    private const NOTICE_KEYS = [self::PRIVACY => AppSettings::PRIVACY_NOTICE, self::TERMS => AppSettings::TERMS_NOTICE];

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

    public static function changedKey(string $kind): string
    {
        return self::CHANGED_KEYS[$kind];
    }

    public static function noticeKey(string $kind): string
    {
        return self::NOTICE_KEYS[$kind];
    }

    /** Users column with the time the notice of a text was dismissed. */
    public static function seenColumn(string $kind): string
    {
        return $kind.'_notice_seen_at';
    }

    /** "Last changed" (UTC string) or null. */
    public static function changedAt(string $kind): ?string
    {
        $v = AppSettings::get(self::CHANGED_KEYS[$kind]);

        return is_string($v) && $v !== '' ? $v : null;
    }

    /** Time (UTC string) an update notice was published or null. */
    public static function noticeAt(string $kind): ?string
    {
        $v = AppSettings::get(self::NOTICE_KEYS[$kind]);

        return is_string($v) && $v !== '' ? $v : null;
    }

    /** The text uses a placeholder filled from app settings (price, limit, undo minutes). */
    public static function usesSettingPlaceholders(string $kind): bool
    {
        $text = self::text($kind);
        foreach (self::SETTING_PLACEHOLDERS as $placeholder) {
            if (str_contains($text, $placeholder)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Settings to store when a setting used in the texts changed (price, limit, undo minutes):
     * "last changed" and update notice of every text that uses such a placeholder.
     *
     * @return array<string, string>
     */
    public static function settingChangeValues(): array
    {
        $now = self::newVersion();
        $values = [];
        foreach (self::KINDS as $kind) {
            if (self::usesSettingPlaceholders($kind)) {
                $values[self::CHANGED_KEYS[$kind]] = $now;
                $values[self::NOTICE_KEYS[$kind]] = $now;
            }
        }

        return $values;
    }

    /**
     * Kinds with an update notice this user has not dismissed yet (with the notice date).
     *
     * @return array<string, string> kind => "d.m.Y"
     */
    public static function pendingNotices(User $user): array
    {
        $pending = [];
        foreach (self::KINDS as $kind) {
            $notice = self::noticeAt($kind);
            if ($notice === null) {
                continue;
            }
            try {
                $at = Carbon::parse($notice, 'UTC');
            } catch (Throwable) {
                continue;
            }
            $seen = $user->{self::seenColumn($kind)};
            if ($seen === null || $seen->getTimestamp() < $at->getTimestamp()) {
                $pending[$kind] = self::versionDate($notice);
            }
        }

        return $pending;
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
     * Text with placeholders (stored or default text) rendered.
     *
     * @return array{title: ?string, html: HtmlString}
     */
    public static function renderText(string $text): array
    {
        return self::renderSource(self::substitute($text));
    }

    /**
     * Current text of a kind as plain text with the placeholders replaced (what users see, archived
     * as LegalRevision body).
     */
    public static function source(string $kind): string
    {
        return self::substitute(self::text($kind));
    }

    /** Line endings normalised, placeholders replaced, trimmed. */
    public static function substitute(string $text): string
    {
        return trim(strtr(str_replace(["\r\n", "\r"], "\n", $text), self::placeholders()));
    }

    /**
     * Renders plain text whose placeholders are already replaced (e.g. an archived revision).
     *
     * @return array{title: ?string, html: HtmlString}
     */
    public static function renderSource(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
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
     * Also marks both update notices as seen (the user just read the texts) and records the accepted
     * revisions (default: the current ones, created when missing).
     *
     * @param  array<string, LegalRevision>|null  $revisions  kind => revision the user saw
     * @return array<string, mixed>
     */
    public static function consentAttributes(?array $revisions = null): array
    {
        $revisions ??= LegalArchive::currentAll();

        return [
            'privacy_accepted_version' => $revisions[self::PRIVACY]->version,
            'terms_accepted_version' => $revisions[self::TERMS]->version,
            'privacy_revision_id' => $revisions[self::PRIVACY]->id,
            'terms_revision_id' => $revisions[self::TERMS]->id,
            'consent_accepted_at' => now(),
            self::seenColumn(self::PRIVACY) => now(),
            self::seenColumn(self::TERMS) => now(),
        ];
    }

    private static function isHeading(string $line): bool
    {
        return (bool) preg_match('/^\d{1,2}\.\s+\S/u', $line);
    }
}
