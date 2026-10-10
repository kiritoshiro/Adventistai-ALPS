@php
  $postHeaderCategory = is_singular('post')
    ? \App\ContentHelpers::categoryName((int) get_queried_object_id())
    : '';
@endphp
@if ($postHeaderCategory !== '')
  <span class="o-kicker alps-hero__category {{ $postHeaderCategoryClass ?? '' }}">{{ $postHeaderCategory }}</span>
@endif
