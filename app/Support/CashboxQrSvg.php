<?php

namespace App\Support;

/**
 * Cashbox QR stickers as standalone SVGs (user units = mm): one sticker of 80 x 110 mm
 * (headline "Kein Konto? Bar bezahlt" / "… Ich habe getwintet", QR code with the secret cashbox URL,
 * which is not printed, short instruction and app name), or an A4 sheet with 2 x 2 stickers and
 * dashed cut lines. Same drawing helpers as the flyer.
 */
class CashboxQrSvg extends FlyerSvg
{
    public const STICKER_W = 80;

    public const STICKER_H = 110;

    /** Sheet variants: method of each of the 4 stickers (row by row). */
    public const SHEETS = [
        'cash' => ['cash', 'cash', 'cash', 'cash'],
        'twint' => ['twint', 'twint', 'twint', 'twint'],
        'mixed' => ['cash', 'cash', 'twint', 'twint'],
    ];

    public static function sticker(string $appName, string $method, string $url): string
    {
        $out = self::open($appName, __('guest.cashbox_sticker_'.$method), self::STICKER_W, self::STICKER_H);
        self::body($out, $appName, $method, $url);
        $out[] = '</svg>';

        return implode("\n", $out)."\n";
    }

    /**
     * A4 sheet (210 x 297 mm) with 4 stickers, 10 mm gap, centered (margins 20 mm left/right, 33.5 mm top/bottom).
     *
     * @param  array<string, string>  $urls  method => cashbox URL
     */
    public static function sheet(string $appName, string $variant, array $urls): string
    {
        $out = self::open($appName, __('guest.cashbox_sheet_'.$variant), 210, 297);
        $gap = 10;
        $x0 = (210 - 2 * self::STICKER_W - $gap) / 2;
        $y0 = (297 - 2 * self::STICKER_H - $gap) / 2;
        foreach (self::SHEETS[$variant] as $i => $method) {
            $x = $x0 + ($i % 2) * (self::STICKER_W + $gap);
            $y = $y0 + intdiv($i, 2) * (self::STICKER_H + $gap);
            // Cut line along the sticker edge.
            $out[] = '<rect x="'.self::n($x).'" y="'.self::n($y).'" width="'.self::STICKER_W.'" height="'.self::STICKER_H.'"'
                .' fill="none" stroke="#9ca3af" stroke-width="0.2" stroke-dasharray="2 1.5"/>';
            $out[] = '<g transform="translate('.self::n($x).' '.self::n($y).')">';
            self::body($out, $appName, $method, $urls[$method]);
            $out[] = '</g>';
        }
        $out[] = '</svg>';

        return implode("\n", $out)."\n";
    }

    /** @return list<string> */
    private static function open(string $appName, string $label, int $w, int $h): array
    {
        return [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<svg xmlns="http://www.w3.org/2000/svg" version="1.1" width="'.$w.'mm" height="'.$h.'mm" viewBox="0 0 '.$w.' '.$h.'"'
                .' font-family="'.self::FONT.'" role="img" aria-label="'.self::e($label).'">',
            '<title>'.self::e($appName.' – '.$label).'</title>',
            '<rect x="0" y="0" width="'.$w.'" height="'.$h.'" fill="#ffffff"/>',
        ];
    }

    /**
     * One sticker at 0/0 (80 x 110).
     *
     * @param  list<string>  $out
     */
    private static function body(array &$out, string $appName, string $method, string $url): void
    {
        $out[] = '<rect x="1" y="1" width="78" height="108" rx="4" fill="#ffffff" stroke="'.self::ACCENT.'" stroke-width="1.2"/>';

        // Orange band with "Kein Konto?".
        $out[] = '<path d="M1 5a4 4 0 0 1 4-4h70a4 4 0 0 1 4 4v12H1z" fill="'.self::ACCENT.'"/>';
        $out[] = '<text x="40" y="12.2" font-size="6.4" font-weight="800" fill="#ffffff" text-anchor="middle">'
            .self::e(__('guest.cashbox_sticker_question')).'</text>';

        $y = self::lines($out, __('guest.cashbox_sticker_'.$method), 20, 40, 27, 6.4, 7.4, 'font-weight="900" fill="'.self::TEXT.'"');
        $qrSize = 58;
        $out[] = self::qr($url, 40 - $qrSize / 2, $y - 3, $qrSize);
        self::lines($out, __('guest.cashbox_sticker_hint'), 34, 40, $y - 3 + $qrSize + 2.5, 3.6, 4.4, 'fill="'.self::MUTED.'"');
        self::lines($out, $appName, 34, 40, 105.5, 3.4, 4, 'font-weight="700" fill="'.self::ACCENT_STRONG.'"');
    }
}
