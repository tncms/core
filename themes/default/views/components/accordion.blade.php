{{--
    Accordion (molecule). Inputs:
      $items        = [{ question, answer(html) }]
      $allowMultiple (bool) — allow several panels open at once
      $sectionId    (string) — used to build unique ids

    Accessible by construction: each question is a native <button> with
    aria-expanded + aria-controls; each answer is a labelled region. Works
    without JS (panels visible); accordion.js collapses + wires toggling.
    Answer HTML is sanitized via cms_html().
--}}
@php($items = is_array($items ?? null) ? $items : [])
@php($allowMultiple = (bool) ($allowMultiple ?? false))
@php($base = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($sectionId ?? 'faq')))
@if ($items !== [])
    <div class="accordion" data-accordion @if ($allowMultiple) data-allow-multiple="true" @endif>
        @foreach ($items as $i => $item)
            @php($panelId = $base . '-acc-' . $i)
            @php($buttonId = $panelId . '-btn')
            <div class="accordion__item">
                <h3 class="accordion__heading">
                    <button class="accordion__trigger" type="button"
                            id="{{ $buttonId }}"
                            aria-controls="{{ $panelId }}"
                            aria-expanded="false">
                        <span class="accordion__question">{{ $item['question'] ?? '' }}</span>
                        <span class="accordion__icon" aria-hidden="true"></span>
                    </button>
                </h3>
                <div class="accordion__panel" id="{{ $panelId }}" role="region" aria-labelledby="{{ $buttonId }}">
                    <div class="accordion__answer">{!! cms_html($item['answer'] ?? '') !!}</div>
                </div>
            </div>
        @endforeach
    </div>
@endif
