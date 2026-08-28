@extends('layouts.app')

@section('content')
  @if (class_exists('Adv_Search') && Adv_Search::enabled())
    {!! Adv_Search_Renderer::render_page() !!}
  @else
    @include('patterns.02-organisms.content.content-search')
  @endif
@endsection
