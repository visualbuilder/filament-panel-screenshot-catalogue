@php
    $deviceClass = match ($viewport['name']) {
        'mobile' => 'device device-mobile',
        'tablet' => 'device device-tablet',
        default => '',
    };
@endphp

@if ($shot)
    <div class="variant {{ $deviceClass }}">
        <div class="label">
            <span>{{ $viewport['name'] }} · {{ $viewport['width'] }}px</span>
            <span class="mode">{{ $mode }}</span>
        </div>
        <a href="{{ $shot['url'] }}" target="_blank" rel="noopener" class="variant-link" data-full="{{ $shot['url'] }}" data-caption="{{ $slug }} · {{ $viewport['name'] }} · {{ $mode }}">
            <img src="{{ $shot['url'] }}" alt="{{ $slug }} {{ $viewport['name'] }} {{ $mode }}" loading="lazy">
        </a>
    </div>
@else
    <div class="variant missing {{ $deviceClass }}">
        <div>
            <span>{{ $viewport['name'] }} · {{ $mode }}</span><br>
            not captured
        </div>
    </div>
@endif
