{{--
    Button component. Input: $link = { label, url, variant?, target? } (a
    pagebuilder/06 link field). Null-safe: renders nothing without a url+label.
--}}
@php($link = $link ?? null)
@if (is_array($link) && ! empty($link['url']) && ! empty($link['label']))
    <a class="btn btn--{{ $link['variant'] ?? 'solid' }}"
       href="{{ $link['url'] }}"
       @if (! empty($link['target'])) target="{{ $link['target'] }}" rel="noopener" @endif>
        {{ $link['label'] }}
    </a>
@endif
