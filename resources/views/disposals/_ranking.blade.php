{{--
    Ranking of the booked disposals (1.32.0): /statistik (if public) and the backend page "Entsorgung".
    $ranking = App\Services\Disposals::ranking(); counts are always shown (stats_ranking_counts does not apply).
--}}
@if (empty($ranking))
    <p class="muted">{{ __('disposal.ranking_empty') }}</p>
@else
    <ol class="ranking" data-testid="disposal-ranking">
        @foreach ($ranking as $row)
            <li @class(['me' => $row['user_id'] === auth()->id(), 'rank-'.$row['rank'] => $row['rank'] <= 3])>
                <span class="rank">{{ $row['rank'] }}</span>
                <span class="name">{{ $row['name'] }}</span>
                <strong>{{ __('disposal.ranking_count', ['count' => $row['count']]) }}</strong>
                <small class="muted">{{ trans_choice('disposal.kpi_beers', $row['beers'], ['count' => $row['beers']]) }}</small>
            </li>
        @endforeach
    </ol>
@endif
