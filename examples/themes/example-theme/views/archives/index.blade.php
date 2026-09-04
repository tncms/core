@extends('theme::layouts.master')

@section('content')
    <section class="archive">
        <h1 class="entry-title">{{ $title ?? '' }}</h1>
        @foreach ($posts ?? [] as $post)
            <article class="archive-item">
                <h2><a href="{{ $post->url ?? '#' }}">{{ $post->title ?? '' }}</a></h2>
            </article>
        @endforeach
    </section>
@endsection
