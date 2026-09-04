@extends('theme::layouts.master')

@section('content')
    <article class="entry entry--post">
        <h1 class="entry-title">{{ $title }}</h1>
        <div class="entry-content">
            {!! cms_html($body ?? '') !!}
        </div>
    </article>
@endsection
