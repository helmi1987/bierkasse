<?php

namespace App\Support;

/**
 * Cashbox QR sticker as a standalone SVG (80 x 110 mm, user units = mm): headline
 * ("Kein Konto? Bar bezahlt" / "… Ich habe getwintet"), QR code with the secret cashbox URL
 * (the URL itself is not printed), short instruction and app name. Same drawing helpers as the flyer.
 */
class CashboxQrSvg extends FlyerSvg
{
    public static function sticker(string $appName, string $method, string $url): string
    {
        $out = [];
        $out[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $out[] = '<svg xmlns="http://www.w3.org/2000/svg" version="1.1" width="80mm" height="110mm" viewBox="0 0 80 110"'
            .' font-family="'.self::FONT.'" role="img" aria-label="'.self::e(__('guest.cashbox_sticker_'.$method)).'">';
        $out[] = '<title>'.self::e($appName.' – '.__('guest.cashbox_sticker_'.$method)).'</title>';
        $out[] = '<rect x="0" y="0" width="80" height="110" fill="#ffffff"/>';
        $out[] = '<rect x="1" y="1" width="78" height="108" rx="4" fill="#ffffff" stroke="'.self::ACCENT.'" stroke-width="1.2"/>';

        // Orange band with "Kein Konto?".
        $out[] = '<path d="M1 5a4 4 0 0 1 4-4h70a4 4 0 0 1 4 4v12H1z" fill="'.self::ACCENT.'"/>';
        $out[] = '<text x="40" y="12.2" font-size="6.4" font-weight="800" fill="#ffffff" text-anchor="middle">'
            .self::e(__('guest.cashbox_sticker_question')).'</text>';

        $y = self::lines($out, __('guest.cashbox_sticker_'.$method), 20, 40, 27, 6.4, 7.4, 'font-weight="900" fill="'.self::TEXT.'"');
        $qrSize = 58;
        $out[] = self::qr($url, 40 - $qrSize / 2, $y - 3, $qrSize);
        $y = self::lines($out, __('guest.cashbox_sticker_hint'), 34, 40, $y - 3 + $qrSize + 2.5, 3.6, 4.4, 'fill="'.self::MUTED.'"');
        self::lines($out, $appName, 34, 40, 105.5, 3.4, 4, 'font-weight="700" fill="'.self::ACCENT_STRONG.'"');

        $out[] = '</svg>';

        return implode("\n", $out)."\n";
    }
}
