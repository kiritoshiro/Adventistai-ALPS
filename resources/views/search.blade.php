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

      // Add the post category to result metadata without changing the search index.
      $searchHtml = preg_replace_callback(
        '/(<article class="adv-search-result[^"]*">\s*<h2 class="adv-search-result__title"><a href="([^"]+)">.*?<\/a><\/h2>)(<p class="adv-search-result__meta">)(.*?)(<\/p>)/su',
        static function ($matches) {
          $postId = url_to_postid(html_entity_decode($matches[2], ENT_QUOTES, 'UTF-8'));
          if (!$postId || 'post' !== get_post_type($postId)) {
            return $matches[0];
          }

          $categories = get_the_category($postId);
          if (!$categories) {
            return $matches[0];
          }

          $categoryNames = array_map(
            static function ($category) {
              return $category->name;
            },
            $categories
          );

          return $matches[1] . $matches[3] . $matches[4] . ' · ' . esc_html(implode(', ', $categoryNames)) . $matches[5];
        },
        $searchHtml
      );
    @endphp
    {!! $searchHtml !!}
  @else
    @include('patterns.02-organisms.content.content-search')
  @endif
@endsection
