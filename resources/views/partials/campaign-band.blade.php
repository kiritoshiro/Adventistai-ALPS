@php
  // The "Visa darau nauja" band (App\CampaignBand, ALPS Theme Settings →
  // VISA DARAU NAUJA), in place of the front page's header band. The
  // wordmark is the page's h1; the split between its two lines is only
  // visual. The photos are decorative and above the fold: no lazy loading,
  // high priority. Styles: assets/css/campaign-band.css.
  // Button URLs come checked and decoded from CampaignBand::safeUrl(); they
  // get WordPress's URL cleaning without its HTML encoding (esc_url_raw) and
  // then Blade's ordinary escaping, so nothing in them is decoded twice.
  $campaignBand = \App\CampaignBand::data();
@endphp
<header class="adventistai-vdn">
  <div class="adventistai-vdn__media" aria-hidden="true">
    @foreach (['old', 'new'] as $campaignSide)
      @php($campaignImage = $campaignBand['images'][$campaignSide])
      <picture class="adventistai-vdn__picture adventistai-vdn__picture--{{ $campaignSide }}{{ $campaignImage['custom'] ? ' adventistai-vdn__picture--custom' : '' }}">
        @if ($campaignImage['phone'] !== '')
          <source media="(max-width: 699px)" srcset="{{ $campaignImage['phone'] }}" sizes="100vw">
        @endif
        <img src="{{ $campaignImage['src'] }}"@if ($campaignImage['srcset'] !== '') srcset="{{ $campaignImage['srcset'] }}" sizes="{{ $campaignImage['sizes'] }}"@endif width="{{ $campaignImage['width'] }}" height="{{ $campaignImage['height'] }}" alt="" fetchpriority="high" decoding="async">
      </picture>
    @endforeach
  </div>
  <div class="adventistai-vdn__text">
    <h1 class="adventistai-vdn__wordmark"><span class="adventistai-vdn__line adventistai-vdn__line--old">{{ $campaignBand['line_old'] }}</span> <span class="adventistai-vdn__line adventistai-vdn__line--new">{{ $campaignBand['line_new'] }}</span></h1>
    @if ($campaignBand['tagline'] !== '' || $campaignBand['verse'] !== '' || $campaignBand['buttons'])
      <div class="adventistai-vdn__body">
        @if ($campaignBand['tagline'] !== '')
          <p class="adventistai-vdn__tagline">{{ $campaignBand['tagline'] }}</p>
        @endif
        @if ($campaignBand['verse'] !== '')
          <p class="adventistai-vdn__verse">{{ $campaignBand['verse'] }}@if ($campaignBand['reference'] !== '') <span class="adventistai-vdn__reference">({{ $campaignBand['reference'] }})</span>@endif</p>
        @endif
        @if ($campaignBand['buttons'])
          <p class="adventistai-vdn__buttons">
            @foreach ($campaignBand['buttons'] as $campaignButton)
              <a class="adventistai-vdn__button adventistai-vdn__button--{{ $campaignButton['primary'] ? 'primary' : 'secondary' }}" href="{{ esc_url_raw($campaignButton['url'], ['http', 'https']) }}">{{ $campaignButton['label'] }}@if ($campaignButton['primary'])<span class="adventistai-vdn__arrow" aria-hidden="true">→</span>@endif</a>
            @endforeach
          </p>
        @endif
      </div>
    @endif
  </div>
  @if (\App\SabbathTimer::enabled())
    @include('partials.sabbath-timer')
  @endif
</header>
