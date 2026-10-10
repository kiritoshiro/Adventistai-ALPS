@php
  // Shared by the regular banner and the older featured-image layout choices.
  $imageHeaderTone = \App\HeaderTone::forAttachment($imageHeader['id']);
  $imageHeaderClass = 'alps-hero alps-hero--' . $imageHeader['fit']['mode'] . (!empty($imageHeader['tall']) ? ' alps-hero--tall' : '');
  $imageHeaderStyle = '--alps-hero-ratio:' . $imageHeader['fit']['ratio'] . ';--alps-hero-ink:' . $imageHeaderTone['ink'] . ';--alps-hero-scrim:' . $imageHeaderTone['scrim'];
@endphp
{!! \App\HeaderTone::stylesheet() !!}
<style type="text/css">{!! \App\ImageDelivery::backgroundCss('.alps-hero__media,.alps-hero__blur', $imageHeader['id']) !!}</style>
<header class="c-page-header c-page-header__long u-theme--background-color--dark u-space--zero--top {{ $imageHeaderClass }}" style="{{ $imageHeaderStyle }}">
  <div class="alps-hero__blur" aria-hidden="true"></div>
  <div class="alps-hero__row">
    <div class="alps-hero__media" aria-hidden="true"></div>
    <div class="alps-hero__text">
      @if (!empty($imageHeader['kicker']))
        <span class="o-kicker">{{ $imageHeader['kicker'] }}</span>
      @endif
      <h1 class="u-font--primary--xl u-font-weight--bold">{!! wp_kses_post($imageHeader['title']) !!}</h1>
      @include('partials.post-header-category')
      @if (!empty($imageHeader['subtitle']))
        <span class="o-kicker">{{ $imageHeader['subtitle'] }}</span>
      @endif
      @if (!empty($imageHeader['description']))
        <p class="alps-hero__description">{{ $imageHeader['description'] }}</p>
      @endif
      @if (!empty($imageHeader['date']))
        <span class="alps-hero__date">{{ $imageHeader['date'] }}</span>
      @endif
    </div>
  </div>
  @if (!empty($imageHeader['caption']))
    <div class="alps-hero__caption">{{ $imageHeader['caption'] }}</div>
  @endif
</header>
