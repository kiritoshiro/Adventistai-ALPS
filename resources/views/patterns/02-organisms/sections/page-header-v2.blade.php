@if (\App\CampaignBand::shown())
  {{-- "Visa darau nauja" (App\CampaignBand, off by default): on the front page it takes the place of the usual header, whichever this would be. --}}
  @include('partials.campaign-band')
@else
@if ($headerType === \App\TemplateHelpers::POST_HEADER_TYPE_HERO)
  @include('patterns.01-molecules.blocks.header-block-hero')
@elseif ($headerType === \App\TemplateHelpers::POST_HEADER_TYPE_FEATURED)
  @include('patterns.01-molecules.blocks.header-block-featured')
@else
  @include('patterns.01-molecules.blocks.header-block')
@endif
@endif
