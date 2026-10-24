<?php

namespace App\Support;

/**
 * Registration flyer as a standalone A4 SVG (210 x 297 mm, user units = mm).
 * An image prints the same in every browser ("fit to page"), unlike the former HTML/CSS flyer.
 * Only presentation attributes (no <style>), so the CSP of the app does not matter when the file
 * is opened directly. Text is wrapped by character count because SVG has no automatic line breaks;
 * the limits are chosen with generous room for wider system fonts.
 */
class FlyerSvg
{
    private const W = 210;

    private const H = 297;

    protected const ACCENT = '#d97706';

    protected const ACCENT_STRONG = '#b45309';

    protected const ACCENT_SOFT = '#fef3c7';

    protected const TEXT = '#1f1a14';

    protected const MUTED = '#6b6152';

    private const BG = '#fdf8ef';

    private const BORDER = '#eadfcb';

    protected const FONT = 'system-ui, -apple-system, &quot;Segoe UI&quot;, Roboto, Arial, sans-serif';

    /**
     * @param  string  $appName  shown in the orange band
     * @param  string  $qrText  content of the QR code (registration URL, optionally with flyer code)
     * @param  string  $url  plain registration URL printed below the QR code
     * @param  string  $step2  text of step 2 (depends on flyer code / registration mode)
     * @param  bool  $passwordNote  show "Ohne QR-Code: …" (only when registration with password is open)
     */
    public static function render(string $appName, string $qrText, string $url, string $step2, bool $passwordNote): string
    {
        $out = [];
        $out[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $out[] = '<svg xmlns="http://www.w3.org/2000/svg" version="1.1" width="210mm" height="297mm" viewBox="0 0 210 297"'
            .' font-family="'.self::FONT.'" role="img" aria-label="'.self::e($appName).'">';
        $out[] = '<title>'.self::e($appName.' – '.__('settings.qr_tab_flyer')).'</title>';
        $out[] = '<defs><linearGradient id="body-bg" gradientUnits="userSpaceOnUse" x1="0" y1="26" x2="0" y2="121">'
            .'<stop offset="0" stop-color="'.self::ACCENT_SOFT.'"/><stop offset="1" stop-color="#ffffff"/></linearGradient></defs>';

        // Page and body background.
        $out[] = '<rect x="0" y="0" width="210" height="297" fill="#ffffff"/>';
        $out[] = '<rect x="0" y="26" width="210" height="250" fill="url(#body-bg)"/>';

        // Orange band: logo (white circle) + app name, centered as a group.
        $out[] = '<rect x="0" y="0" width="210" height="26" fill="'.self::ACCENT.'"/>';
        $nameSize = 8.8;
        // Assumption: average glyph width of a bold sans-serif is about 0.6 em.
        $nameWidth = mb_strlen($appName) * 0.6 * $nameSize;
        $maxName = self::W - 24 - 20 - 5;
        $fit = '';
        if ($nameWidth > $maxName) {
            $nameWidth = $maxName;
            $fit = ' textLength="'.$maxName.'" lengthAdjust="spacingAndGlyphs"';
        }
        $groupX = max(12, (self::W - (20 + 5 + $nameWidth)) / 2);
        $out[] = '<circle cx="'.self::n($groupX + 10).'" cy="13" r="10" fill="#ffffff"/>';
        $out[] = '<g transform="translate('.self::n($groupX + 1.6).' 4.6) scale(0.2625)">'.self::logo().'</g>';
        $out[] = '<text x="'.self::n($groupX + 25).'" y="16.2" font-size="'.$nameSize.'" font-weight="800" fill="#ffffff"'.$fit.'>'
            .self::e($appName).'</text>';

        // Headline and subline.
        $y = 44;
        $y = self::lines($out, __('settings.flyer_headline'), 24, 105, $y, 12.5, 13.5, 'font-weight="900" fill="'.self::TEXT.'"');
        $y = self::lines($out, __('settings.flyer_subline'), 46, 105, $y + 0.5, 6.6, 7.8, 'font-weight="600" fill="'.self::ACCENT_STRONG.'"');

        // Three numbered steps.
        $steps = [
            [__('settings.flyer_step1_title'), __('settings.flyer_step1_text')],
            [__('settings.flyer_step2_title'), $step2],
            [__('settings.flyer_step3_title'), __('settings.flyer_step3_text')],
        ];
        $stepTop = $y + 4;
        $stepBottom = $stepTop;
        foreach ($steps as $i => [$title, $text]) {
            $cx = 44 + $i * 61;
            $cy = $stepTop + 8;
            $out[] = '<circle cx="'.$cx.'" cy="'.self::n($cy).'" r="8" fill="'.self::ACCENT.'"/>';
            $out[] = '<text x="'.$cx.'" y="'.self::n($cy + 2.8).'" font-size="8" font-weight="800" fill="#ffffff" text-anchor="middle">'.($i + 1).'</text>';
            $ty = self::lines($out, $title, 20, $cx, $cy + 15, 5.4, 6.2, 'font-weight="700" fill="'.self::TEXT.'"');
            $ty = self::lines($out, $text, 24, $cx, $ty + 0.4, 4.1, 5.1, 'fill="'.self::MUTED.'"');
            $stepBottom = max($stepBottom, $ty);
        }

        // QR box: "HIER SCANNEN", QR code, URL and note.
        $boxTop = $stepBottom + 4;
        $boxX = 43;
        $boxW = 124;
        $inner = [];
        $iy = $boxTop + 9;
        $inner[] = '<text x="105" y="'.self::n($iy).'" font-size="5.8" font-weight="800" fill="'.self::ACCENT_STRONG.'" text-anchor="middle" letter-spacing="0.7">'
            .self::e(mb_strtoupper(__('settings.flyer_scan'))).'</text>';
        $qrSize = 78;
        $qrY = $iy + 3;
        $inner[] = self::qr($qrText, 105 - $qrSize / 2, $qrY, $qrSize);
        $iy = $qrY + $qrSize + 5;
        $iy = self::lines($inner, __('settings.flyer_or_open'), 52, 105, $iy, 4, 4.9, 'fill="'.self::MUTED.'"');
        $iy = self::lines($inner, $url, 40, 105, $iy + 0.6, 4.4, 5.2, 'font-weight="600" fill="'.self::TEXT.'"', true);
        if ($passwordNote) {
            $iy = self::lines($inner, __('settings.flyer_url_password_note'), 52, 105, $iy + 0.6, 4, 4.9, 'fill="'.self::MUTED.'"');
        }
        $boxH = $iy - $boxTop + 1.5;
        $out[] = '<rect x="'.$boxX.'" y="'.self::n($boxTop).'" width="'.$boxW.'" height="'.self::n($boxH).'" rx="6.3"'
            .' fill="#ffffff" stroke="'.self::ACCENT.'" stroke-width="1.3"/>';
        array_push($out, ...$inner);

        // Joke line, just above the footer.
        self::lines($out, __('settings.flyer_joke'), 60, 105, 268, 4.6, 5.4, 'font-style="italic" fill="'.self::MUTED.'"');

        // Footer: install hint (left) and questions (right).
        $out[] = '<rect x="0" y="276" width="210" height="21" fill="'.self::BG.'"/>';
        $out[] = '<line x1="0" y1="276.15" x2="210" y2="276.15" stroke="'.self::BORDER.'" stroke-width="0.3"/>';
        self::lines($out, __('settings.flyer_install'), 30, 11, 284.5, 3.9, 4.8, 'font-weight="600" fill="'.self::TEXT.'"', false, 'start');
        self::lines($out, __('settings.flyer_questions'), 30, 199, 284.5, 3.9, 4.8, 'fill="'.self::MUTED.'"', false, 'end');

        $out[] = '</svg>';

        return implode("\n", $out)."\n";
    }

    /**
     * Wraps text into lines of at most $maxChars characters (at word boundaries; words that are
     * longer are split). With $anywhere the text is split into fixed chunks (URLs).
     *
     * @return list<string>
     */
    public static function wrap(string $text, int $maxChars, bool $anywhere = false): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') {
            return [];
        }
        if ($anywhere) {
            return mb_str_split($text, $maxChars);
        }
        $lines = [];
        $line = '';
        foreach (explode(' ', $text) as $word) {
            while (mb_strlen($word) > $maxChars) {
                if ($line !== '') {
                    $lines[] = $line;
                    $line = '';
                }
                $lines[] = mb_substr($word, 0, $maxChars);
                $word = mb_substr($word, $maxChars);
            }
            if ($line === '') {
                $line = $word;
            } elseif (mb_strlen($line) + 1 + mb_strlen($word) <= $maxChars) {
                $line .= ' '.$word;
            } else {
                $lines[] = $line;
                $line = $word;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Appends a wrapped <text> with one <tspan> per line; $y is the first baseline.
     * Returns the baseline position after the last line (y of a following line).
     *
     * @param  list<string>  $out
     */
    protected static function lines(array &$out, string $text, int $maxChars, float $x, float $y, float $size, float $lineHeight,
        string $attrs, bool $anywhere = false, string $anchor = 'middle'): float
    {
        $lines = self::wrap($text, $maxChars, $anywhere);
        if ($lines === []) {
            return $y;
        }
        $svg = '<text x="'.self::n($x).'" y="'.self::n($y).'" font-size="'.self::n($size).'" text-anchor="'.$anchor.'" '.$attrs.'>';
        foreach ($lines as $i => $line) {
            $svg .= '<tspan x="'.self::n($x).'"'.($i > 0 ? ' dy="'.self::n($lineHeight).'"' : '').'>'.self::e($line).'</tspan>';
        }
        $out[] = $svg.'</text>';

        return $y + count($lines) * $lineHeight;
    }

    /** QR code as nested <svg> (without the XML declaration of the generator). */
    protected static function qr(string $text, float $x, float $y, float $size): string
    {
        $svg = preg_replace('/^\s*<\?xml[^>]*\?>\s*/', '', Qr::svg($text, 480)) ?? '';

        return preg_replace(
            '/<svg\b[^>]*?viewBox="([^"]*)"[^>]*>/',
            '<svg x="'.self::n($x).'" y="'.self::n($y).'" width="'.self::n($size).'" height="'.self::n($size).'" viewBox="$1">',
            $svg,
            1
        ) ?? '';
    }

    /** Paths of public/logo.svg (viewBox 0 0 64 64). */
    protected static function logo(): string
    {
        return Qr::LOGO_PATHS;
    }

    protected static function e(string $text): string
    {
        // Drop characters that are not allowed in XML 1.0 (would break the document).
        $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';

        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    protected static function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
