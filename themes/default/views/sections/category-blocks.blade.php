{{-- Category blocks section. Several editorial category columns, each with a
     heading and a short list of headlines. --}}
@php($s = $section)
@php($blocks = $s->field('blocks', []))
@php($showDescription = (bool) $s->setting('show_description', true))
@php($showDate = (bool) $s->setting('show_date', true))
@php($showViewAll = (bool) $s->setting('show_view_all', true))
<section class="section section--category-blocks section--bg-{{ $s->setting('background', 'none') }}"
         id="{{ $s->id }}"
         data-variant="{{ $s->setting('variant', 'standard') }}">
    <div class="section__inner">
        @include('theme::components.section-header', [
            'heading' => $s->field('heading'),
            'subheading' => null,
        ])

        @if (is_array($blocks) && $blocks !== [])
            <div class="category-blocks category-blocks--cols-{{ $s->setting('columns', 3) }}">
                @foreach ($blocks as $block)
                    @php($block = is_array($block) ? $block : [])
                    @php($blockTitle = $block['title'] ?? '')
                    @if (is_string($blockTitle) && $blockTitle !== '')
                        @php($blockUrl = is_string($block['url'] ?? null) && $block['url'] !== '' ? $block['url'] : null)
                        <div class="category-block">
                            <h3 class="category-block__title">
                                @if ($blockUrl)
                                    <a href="{{ $blockUrl }}">{{ $blockTitle }}</a>
                                @else
                                    {{ $blockTitle }}
                                @endif
                            </h3>

                            @php($description = $block['description'] ?? '')
                            @if ($showDescription && is_string($description) && $description !== '')
                                <p class="category-block__description">{{ $description }}</p>
                            @endif

                            @php($posts = $block['posts'] ?? [])
                            @if (is_array($posts) && $posts !== [])
                                <ul class="category-block__posts">
                                    @foreach ($posts as $post)
                                        @php($post = is_array($post) ? $post : [])
                                        @php($postTitle = $post['title'] ?? '')
                                        @if (is_string($postTitle) && $postTitle !== '')
                                            @php($postUrl = is_string($post['url'] ?? null) && $post['url'] !== '' ? $post['url'] : null)
                                            <li class="category-block__post">
                                                @if ($postUrl)
                                                    <a href="{{ $postUrl }}">{{ $postTitle }}</a>
                                                @else
                                                    {{ $postTitle }}
                                                @endif
                                                @php($postDate = $post['date'] ?? '')
                                                @if ($showDate && is_string($postDate) && $postDate !== '')
                                                    <time class="category-block__date">{{ $postDate }}</time>
                                                @endif
                                            </li>
                                        @endif
                                    @endforeach
                                </ul>
                            @endif

                            {{-- "View all" deep-links to the category archive (the block's
                                 own resolved URL); shown when enabled and a URL exists. --}}
                            @if ($showViewAll && $blockUrl)
                                <a class="category-block__more" href="{{ $blockUrl }}">{{ theme_trans('View all') }}</a>
                            @endif
                        </div>
                    @endif
                @endforeach
            </div>
        @endif
    </div>
</section>
