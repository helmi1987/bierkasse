{{--
    Print view of the offline emergency plan (1.40.0, admins; App\Services\EmergencyPlan::sheet()). Own page without
    the app layout: A4, @page margin 10 mm, printed with the browser (or saved as PDF). Page 1 = envelope window
    (frame visible in the test print only) and guide; pages 2+ = tally sheet with fixed row heights (the page
    split is computed in EmergencyPlan::pages() with the same mm values). Colour, readable in black and white:
    rows alternate white / light orange, the boxes are always white. All texts are escaped (EmergencyPlan::html()).
--}}
@php
    $nonce = \Illuminate\Support\Facades\Vite::cspNonce();
    $E = \App\Services\EmergencyPlan::class;
    $h = fn (string $text) => $E::html($text, $vars);
    $boxes = function () use ($E) {
        $html = '';
        for ($i = 1; $i <= $E::BOXES; $i++) {
            $html .= '<i></i>'.($i === $E::BOXES_GROUP ? '<b></b>' : '');
        }

        return new \Illuminate\Support\HtmlString('<div class="bx">'.$html.'</div>');
    };
@endphp
<!DOCTYPE html>
<html lang="de-CH">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('emergency.sheet_title') }}@if ($test) – {{ __('emergency.test_page') }}@endif – {{ $appName }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <style nonce="{{ $nonce }}">
        @page { size: A4; margin: {{ \App\Support\AppSettings::EMERGENCY_PRINT_MARGIN }}mm; }
        html, body { margin: 0; padding: 0; background: #fff; }
        body { font: 9pt system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #000; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .sheet { position: relative; width: 190mm; height: {{ $E::PAGE_MM }}mm; overflow: hidden; break-after: page; page-break-after: always; background: #fff; }
        .sheet:last-of-type { break-after: auto; page-break-after: auto; }

        /* Page 1: envelope window and guide. */
        .win { position: absolute; top: {{ $window['top'] }}mm; {{ $window['side'] }}: {{ $window['edge'] }}mm; width: {{ $window['width'] }}mm; height: {{ $window['height'] }}mm;
            box-sizing: border-box; padding: 4mm 6mm; display: flex; flex-direction: column; justify-content: center; overflow: hidden; border: 1pt dashed transparent; }
        .test .win { border-color: #c8792a; }
        .wt { font-size: 18pt; font-weight: 800; letter-spacing: .5pt; color: #b5560b; line-height: 1.15; }
        .ws { font-size: 11pt; }
        .wn { font-size: 9.5pt; font-weight: 700; margin-top: 2mm; }
        .wd { font-size: 9pt; margin-top: 2mm; }
        .cover .body { position: absolute; top: {{ $window['body'] }}mm; left: {{ $window['body_x'] }}mm; right: {{ $window['body_x'] }}mm; font-size: 10.5pt; }
        .cover h2 { font-size: 14pt; margin: 0 0 3mm; color: #8a4b0f; }
        .contact { border: 1.2pt solid #b5560b; padding: 2mm 3mm; border-radius: 2mm; margin: 0 0 3mm; }
        .contact b, .info b { color: #b5560b; }
        .cover ol { padding-left: 6mm; margin: 0; }
        .cover li { margin-bottom: 1.5mm; }
        .kv { border-collapse: collapse; margin-top: 4mm; }
        .kv td { padding: 1mm 4mm 1mm 0; font-size: 10pt; }
        .kv td:first-child { color: #8a4b0f; font-weight: 600; }
        .pg { position: absolute; bottom: 0; right: 0; font-size: 8pt; }

        /* Pages 2+: tally sheet. */
        .head { height: {{ $E::HEAD_MM }}mm; overflow: hidden; }
        h1 { font-size: 13pt; margin: 0; color: #8a4b0f; }
        .meta { display: flex; justify-content: space-between; font-size: 8pt; margin: 1mm 0 2mm; }
        .info { font-size: 8pt; }
        .frame { border: 1.5pt solid #8a4b0f; }
        .r { display: grid; grid-template-columns: repeat({{ $E::COLUMNS }}, 1fr); height: {{ $E::ROW_MM }}mm; }
        .r.g .c { background: #f6dfb8; }
        .c { box-sizing: border-box; height: {{ $E::ROW_MM }}mm; overflow: hidden; padding: .7mm 1.5mm; border-right: .6pt solid #c9a77a; border-bottom: .6pt solid #c9a77a; background: #fff; }
        .c:last-child { border-right: none; }
        .nm { font-weight: 700; font-size: 8.5pt; line-height: 3.6mm; height: 3.6mm; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .un { font-size: 7pt; line-height: 3mm; height: 3mm; color: #333; margin-bottom: .4mm; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .nm.blank { font-weight: 400; color: #666; font-size: 7pt; border-bottom: .5pt dotted #666; }
        .blank + .un { color: #666; }
        .bx { display: flex; gap: .8mm; align-items: center; height: 4.6mm; }
        .bx i { display: block; width: 4.2mm; height: 4.2mm; box-sizing: border-box; border: .7pt solid #5a3410; background: #fff; }
        .bx b { display: block; width: .6pt; height: 4.6mm; background: #000; margin: 0 .6mm; }
        .sep { box-sizing: border-box; height: {{ $E::SEP_MM }}mm; font-weight: 700; font-size: 8.5pt; padding: 1mm 1.5mm; background: #c8792a; color: #fff; border-top: 1.2pt solid #8a4b0f; border-bottom: 1.2pt solid #8a4b0f; }
        .foot { position: absolute; left: 0; right: 0; bottom: 0; height: {{ $E::FOOT_MM }}mm; font-size: 7.5pt; display: flex; justify-content: space-between; align-items: flex-end; gap: 4mm; }

        /* Screen only: toolbar and sheets on a grey desk. */
        .toolbar { font: 14px/1.4 system-ui, sans-serif; padding: 12px 16px; background: #fdf8ef; border-bottom: 1px solid #eadfcb; display: flex; flex-wrap: wrap; gap: 8px 16px; align-items: center; }
        .toolbar button, .toolbar a { font: inherit; padding: 8px 14px; border-radius: 10px; border: 1px solid #d97706; background: #d97706; color: #fff; text-decoration: none; cursor: pointer; }
        .toolbar a { background: #fff; color: #b45309; }
        .toolbar span { color: #6b6152; }
        @media screen {
            body { background: #e7e2d9; }
            .sheet { margin: 16px auto; padding: 10mm; box-sizing: content-box; box-shadow: 0 2px 10px rgba(0, 0, 0, .15); }
        }
        @media print {
            .toolbar { display: none !important; }
        }
    </style>
</head>
<body @class(['test' => $test])>
<div class="toolbar">
    <button type="button" data-print>{{ __('emergency.sheet_print') }}</button>
    <a href="{{ route('backend.emergency') }}">{{ __('emergency.sheet_back') }}</a>
    <span>{{ $test ? __('emergency.sheet_test_hint') : __('emergency.sheet_hint') }}</span>
</div>

<section class="sheet cover" data-testid="emergency-cover">
    <div class="win" data-testid="emergency-window">
        <div class="wt">{{ $h($texts['window_title']) }}</div>
        <div class="ws">{{ $h($texts['window_subtitle']) }}</div>
        <div class="wn">{{ $h($texts['window_notice']) }}</div>
        <div class="wd">{{ __('emergency.window_stand', ['date' => $date]) }}</div>
    </div>
    <div class="body">
        <h2>{{ $h($texts['heading']) }}</h2>
        <p class="contact" data-testid="emergency-contact">{{ $h($texts['contact']) }}</p>
        <ol>
            @foreach ($steps as $step)
                <li>{{ $h($step) }}</li>
            @endforeach
        </ol>
        <table class="kv">
            <tr><td>{{ __('emergency.printed_row') }}</td><td>{{ $printedAt }} · {{ $person }}</td></tr>
            <tr><td>{{ __('emergency.count_row') }}</td><td>{{ $users->count() }}</td></tr>
            <tr><td>{{ __('emergency.opened_row') }}</td><td>______________________</td></tr>
        </table>
    </div>
    <div class="pg">{{ $test ? __('emergency.test_page') : __('emergency.page', ['current' => 1, 'total' => $totalPages]) }}</div>
</section>

@foreach ($pages as $p => $rows)
    <section class="sheet list" data-testid="emergency-list-page">
        <div class="head">
            <h1>{{ __('emergency.list_title', ['app' => $appName]) }}</h1>
            <div class="meta"><span>{{ __('emergency.list_printed', ['date' => $printedAt, 'person' => $person]) }}</span><span>{{ __('emergency.list_price', ['price' => $price]) }} · {{ __('emergency.page', ['current' => $p + 2, 'total' => $totalPages]) }}</span></div>
            <div class="info">{{ $h($texts['short_guide']) }}</div>
        </div>
        <div class="frame">
            @php($zebra = 0)
            @foreach ($rows as $row)
                @if ($row['type'] === 'sep')
                    <div class="sep">{{ __('emergency.new_users_bar') }}</div>
                @else
                    <div @class(['r', 'g' => $zebra++ % 2 === 1])>
                        @if ($row['type'] === 'users')
                            @foreach ($row['cells'] as $u)
                                <div class="c">
                                    @if ($u)
                                        <div class="nm" data-testid="emergency-user">{{ $u->displayName() }}</div>
                                        <div class="un">{{ $u->username }}</div>
                                        {{ $boxes() }}
                                    @endif
                                </div>
                            @endforeach
                        @else
                            @for ($i = 0; $i < $E::COLUMNS; $i++)
                                <div class="c"><div class="nm blank">{{ __('emergency.blank_name') }}</div><div class="un">{{ __('emergency.blank_username') }}</div>{{ $boxes() }}</div>
                            @endfor
                        @endif
                    </div>
                @endif
            @endforeach
        </div>
        <div class="foot">
            @foreach ($footer as $part)
                <span>{{ $h($part) }}</span>
            @endforeach
        </div>
    </section>
@endforeach

<script nonce="{{ $nonce }}">
    document.querySelectorAll('[data-print]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); });
    @if ($autoprint)
    window.addEventListener('load', function () { window.print(); });
    @endif
</script>
</body>
</html>
