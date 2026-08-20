{{-- Rich text section. A measure-controlled prose block of sanitized HTML. --}}
@php($s = $section)
@php($body = $s->field('body'))
@if (is_string($body) && trim($body) !== '')
    <section class="section section--rich-text section--bg-{{ $s->setting('background', 'none') }} align--{{ $s->setting('align', 'left') }}"
             id="{{ $s->id }}">
        <div class="section__inner">
            <div class="rich-text__body">{!! cms_html($body) !!}</div>
        </div>
    </section>
@endif
