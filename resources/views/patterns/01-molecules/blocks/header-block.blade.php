@php
  /**
   * Simple Header Block
   *
   * @var string $headerTitle
   * @var string $headerKicker
   * @var string $headerSubtitle
   * @var string $headerBackgroundImage
   */

  $headerClasses = [
    'c-page-header',
    'c-page-header__long',
    'u-theme--background-color--dark',
    'u-space--zero--top',
  ];
  $headerInnerClasses = [
    'c-page-header__long--inner',
    'l-grid',
    'l-grid--7-col',
  ];
  $headerContentClasses = [
    'c-page-header__content',
    'c-page-header__long__content',
    'l-grid-wrap',
    'l-grid-wrap--5-of-7',
    'u-shift--left--1-col--at-xxlarge'
  ];

  $alps_header_fit = null;

  if (@isset($headerBackgroundImage)) {
    $headerClasses[] = 'o-background-image';
    $headerClasses[] = 'u-background--cover';
    $headerClasses[] = 'has-background';
    $headerInnerClasses[] = 'u-gradient--bottom';
    $headerContentClasses[] = 'u-border-left--white--at-large';
    // Size the header to the image instead of cutting it to a thin band (App\ImageDelivery).
    $alps_header_fit = \App\ImageDelivery::headerFit((int) $headerBackgroundImage);
    if ($alps_header_fit) {
      $headerClasses[] = 'alps-fit-header';
      $headerClasses[] = 'alps-fit-header--' . $alps_header_fit['mode'];
    }
  }
@endphp

@if ($alps_header_fit && !is_front_page())
  @include('partials.image-header', ['imageHeader' => [
    'id' => (int) $headerBackgroundImage,
    'fit' => $alps_header_fit,
    'title' => $headerTitle,
    'kicker' => $headerKicker ?? '',
  ]])
@else

@if (@isset($headerBackgroundImage))
  <style type="text/css">{!! \App\ImageDelivery::backgroundCss('.o-background-image', (int) $headerBackgroundImage) !!}</style>
@endif

<header class="{{ join(' ', $headerClasses) }}"@if (!empty($alps_header_fit)) style="--alps-header-ratio:{{ $alps_header_fit['ratio'] }}"@endif>
  <div class="{{ join(' ', $headerInnerClasses) }}">
    <div class="{{ join(' ', $headerContentClasses) }}">
      @if (@isset($headerKicker))
        <span class="o-kicker u-color--white">{{ $headerKicker }}</span>
      @endif
      <h1 class="u-font--primary--xl u-color--white u-font-weight--bold">
        {!! wp_kses_post($headerTitle) !!}
      </h1>
      @include('partials.post-header-category', ['postHeaderCategoryClass' => 'u-color--white'])
    </div>
  </div>
</header>
@endif

@if (@isset($GLOBALS["headerSubtitle"]))
  <div class="c-page-header__subtitle c-page-header__long__subtitle l-grid l-grid--7-col u-space--top--zero">
    <div class="l-grid-wrap l-grid-wrap--5-of-7 u-shift--left--1-col--at-medium u-border--left u-font--secondary--m">
      {{ $headerSubtitle }}
    </div>
  </div>
@endif
