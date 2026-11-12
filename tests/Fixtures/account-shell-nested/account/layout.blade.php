{{-- Fixture: a theme account layout that wraps Core's shell by extending the stable target
     itself. Core must render its own shell for the inner level (no recursion). --}}
@extends('cms::account.layout')

@section('title', 'nested-theme-title')
