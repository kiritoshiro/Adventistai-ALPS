@php
  /**
   * Header: Featured
   *
   * @var string $headerTitle
   * @var string $headerTitleLink
   * @var string $headerDesc
   * @var string $headerKicker
   * @var string $headerImageCaption
   * @var string $headerDate
   * @var string $headerCategory
   * @var string $headerImages
   */
    $hasHeaderImage = !empty($headerImages['s'][0]);

    if ($hasHeaderImage) {
      $mediaBlockTitle = $headerTitle;
      $mediaBlockTitleLink = '';
      $mediaBlockDesc = $headerDesc;
      $mediaBlockDate = $headerDate;
      $mediaBlockCategory = $headerCategory;
      $mediaBlockImageCaption = $headerImageCaption;
      $mediaBlockImages = $headerImages;
      $mediaBlockImageId = $headerImageId ?? 0;
      $mediaBlockKicker = $headerKicker;
    }
@endphp

@php
  $featuredHeaderFit = !is_front_page() && !empty($headerImageId)
    ? \App\ImageDelivery::headerFit((int) $headerImageId) : null;
@endphp

@if ($hasHeaderImage && $featuredHeaderFit)
  @include('partials.image-header', ['imageHeader' => [
    'id' => (int) $headerImageId,
    'fit' => $featuredHeaderFit,
    'title' => $headerTitle,
    'kicker' => $headerKicker,
    'description' => $headerDesc,
    'date' => $headerDate,
    'caption' => $headerImageCaption,
  ]])
@elseif ($hasHeaderImage)
  <header class="c-page-header c-page-header__feature">
    <div class="c-page-header__content">
      @include('patterns.01-molecules.blocks.media-block-v2')
    </div>
  </header>
@else
  @include('patterns.01-molecules.blocks.header-block')
@endif
