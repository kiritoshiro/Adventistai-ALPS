@extends('layouts.app')

@section('content')
  @if (class_exists('Adv_Search') && Adv_Search::enabled())
    @php
      $searchHtml = Adv_Search_Renderer::render_page();

      // The highlighted snippet already makes the match location obvious.
      $searchHtml = str_replace(
        [' · Rasta pavadinime', ' · Rasta turinyje', 'Rasta pavadinime · ', 'Rasta turinyje · ', 'Rasta pavadinime', 'Rasta turinyje'],
        '',
        $searchHtml
      );

    @endphp
    {!! $searchHtml !!}
  @else
    @include('patterns.02-organisms.content.content-search')
  @endif
@endsection
