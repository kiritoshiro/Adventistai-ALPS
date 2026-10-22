@php
  // The post's authors linked to their pages, when "Show the authors under
  // post titles" is on (App\Authors\Output::byline()).
  $authorByline = is_singular() ? \App\Authors\Output::byline() : '';
@endphp
@if ($authorByline !== '')
  <span class="o-kicker alps-hero__byline {{ $postHeaderCategoryClass ?? '' }}" style="display:block">{!! $authorByline !!}</span>
@endif
