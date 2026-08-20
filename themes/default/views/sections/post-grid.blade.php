{{-- Post grid section. Heading + a responsive grid of article cards. --}}
@php($s = $section)
<section class="section section--post-grid section--bg-{{ $s->setting('background', 'none') }}"
         id="{{ $s->id }}"
         data-variant="{{ $s->setting('variant', 'standard') }}">
    <div class="section__inner">
        @include('theme::components.section-header', [
            'heading' => $s->field('heading'),
            'subheading' => $s->field('subheading'),
        ])

        @php($posts = $s->field('posts', []))
        @if (is_array($posts) && $posts !== [])
            <div class="post-grid post-grid--cols-{{ $s->setting('columns', 3) }}">
                @foreach ($posts as $post)
                    @include('theme::components.post-card', [
                        'post' => $post,
                        'showImage' => (bool) $s->setting('show_image', true),
                        'showExcerpt' => (bool) $s->setting('show_excerpt', true),
                        'showCategory' => (bool) $s->setting('show_category', $s->setting('show_meta', true)),
                        'showDate' => (bool) $s->setting('show_date', $s->setting('show_meta', true)),
                        'showViews' => (bool) $s->setting('show_views', false),
                        'showComments' => (bool) $s->setting('show_comments', false),
                        'showAuthor' => (bool) $s->setting('show_author', false),
                    ])
                @endforeach
            </div>
        @endif
    </div>
</section>
