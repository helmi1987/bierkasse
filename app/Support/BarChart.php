<?php

namespace App\Support;

/**
 * Small server-rendered bar chart as inline SVG (no JS, no style attributes: CSP friendly).
 * Colours come from CSS classes in public/css/app.css.
 */
class BarChart
{
    /**
     * @param  list<string>  $labels
     * @param  list<int|float>  $values
     */
    public static function render(array $labels, array $values, string $title, ?callable $formatValue = null): string
    {
        $count = count($values);
        if ($count === 0) {
            return '';
        }

        $formatValue ??= fn ($v) => (string) $v;
        $width = 640;
        $height = 220;
        $top = 22;
        $bottom = 28;
        $plot = $height - $top - $bottom;
        $max = max(1, (float) max($values));
        $slot = $width / $count;
        $bar = max(4, $slot * 0.62);

        $out = '<svg class="chart" viewBox="0 0 '.$width.' '.$height.'" role="img" aria-label="'.e($title).'" xmlns="http://www.w3.org/2000/svg">';
        $out .= '<title>'.e($title).'</title>';
        $out .= '<line class="chart-axis" x1="0" y1="'.($top + $plot).'" x2="'.$width.'" y2="'.($top + $plot).'"/>';

        foreach (array_values($values) as $i => $value) {
            $h = $value > 0 ? max(2, round($plot * $value / $max, 1)) : 0;
            $x = round($i * $slot + ($slot - $bar) / 2, 1);
            $y = $top + $plot - $h;
            $cx = round($i * $slot + $slot / 2, 1);
            $label = (string) ($labels[$i] ?? '');
            $out .= '<g class="chart-item">';
            $out .= '<rect class="chart-bar" x="'.$x.'" y="'.$y.'" width="'.round($bar, 1).'" height="'.$h.'" rx="3">'
                .'<title>'.e($label.': '.$formatValue($value)).'</title></rect>';
            if ($value > 0) {
                $out .= '<text class="chart-value" x="'.$cx.'" y="'.max(12, $y - 5).'" text-anchor="middle">'.e($formatValue($value)).'</text>';
            }
            $out .= '<text class="chart-label" x="'.$cx.'" y="'.($height - 8).'" text-anchor="middle">'.e($label).'</text>';
            $out .= '</g>';
        }

        return $out.'</svg>';
    }
}
