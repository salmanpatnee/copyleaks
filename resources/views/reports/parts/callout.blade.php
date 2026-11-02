<div class="abs callout" style="left: {{ $callout->x }}pt; top: {{ $callout->y }}pt; width: {{ $callout->width }}pt; height: {{ $callout->height }}pt;">&nbsp;</div>
<div class="abs callout-text" style="left: {{ $callout->x + 12 }}pt; top: {{ $callout->y + 7.6 }}pt; width: {{ $callout->width - 24 }}pt;">
@if (filled($callout->title))
<div class="semi small">{{ $callout->title }}</div>
@endif
@foreach ($callout->cleanParagraphs() as $paragraph)
<div class="small">{{ $paragraph }}</div>
@if (! $loop->last)
<div class="small">&nbsp;</div>
@endif
@endforeach
</div>
