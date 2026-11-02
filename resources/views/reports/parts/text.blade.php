@if (filled($text))
<div class="abs {{ $class }}" style="left: {{ $x }}pt; top: {{ $y }}pt;@if ($w !== null) width: {{ $w }}pt;@endif @if ($color !== null) color: {{ $color }};@endif text-align: {{ $align }};">{{ $text }}</div>
@endif
