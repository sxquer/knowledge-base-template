<section class="call-widget public-call" data-call-player>
    <div class="call-heading"><strong>{{ $call['title'] ?? 'Звонок' }}</strong><span class="call-status">Запись звонка</span></div>
    @if(!empty($call['asset_id']))
        <audio controls preload="metadata" src="/media/{{ $call['asset_id'] }}?publication={{ $publication }}"></audio>
    @else
        <p class="muted">Запись ещё не была готова в этой редакции.</p>
    @endif
    @if(($call['status'] ?? '') === 'ready')
        <details class="call-transcript"><summary>Расшифровка звонка</summary><p class="muted">Автоматическая расшифровка — возможны неточности.</p>
            @if(count($call['words'] ?? []))
                <div class="call-words">
                    @foreach($call['words'] as $word)
                        @if(isset($word['speaker']) && ($loop->first || ($call['words'][$loop->index - 1]['speaker'] ?? null) !== $word['speaker']))
                            <span class="call-speaker">Спикер {{ $word['speaker'] + 1 }}</span>
                        @endif
                        <button type="button" class="call-word" data-start="{{ $word['start'] }}" data-end="{{ $word['end'] }}">{{ $word['word'] }}</button>
                    @endforeach
                </div>
            @elseif(count($call['segments'] ?? []))
                <div class="call-segments">@foreach($call['segments'] as $segment)<button type="button" class="call-segment" data-start="{{ $segment['start'] }}" data-end="{{ $segment['end'] }}">{{ $segment['text'] }}</button>@endforeach</div>
            @else
                <p class="call-plain">{{ $call['transcript'] ?? '' }}</p>
            @endif
        </details>
    @else
        <p class="muted">Расшифровка не включена в эту опубликованную редакцию.</p>
    @endif
</section>
