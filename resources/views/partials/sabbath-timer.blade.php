@php
  $sabbathTimerData = \App\SabbathTimer::data();
  $sabbathDefaultCity = $sabbathTimerData['defaultCity'];
@endphp

<div class="adventistai-sabbath-timer" data-sabbath-timer hidden>
  <div class="adventistai-sabbath-timer__countdown-view" data-sabbath-countdown-view hidden>
    <div class="adventistai-sabbath-timer__main">
      <span class="adventistai-sabbath-timer__label">Šabas prasideda už:</span>
      <strong class="adventistai-sabbath-timer__countdown" data-sabbath-countdown>--:--:--</strong>

      <div class="adventistai-sabbath-timer__selector" data-sabbath-selector>
        <button
          type="button"
          class="adventistai-sabbath-timer__selector-button"
          data-sabbath-selector-button
          aria-haspopup="listbox"
          aria-expanded="false"
          aria-controls="adventistai-sabbath-city-list"
        >
          <span data-sabbath-selected-city>{{ $sabbathTimerData['cities'][$sabbathDefaultCity]['name'] }}</span>
        </button>

        <div class="adventistai-sabbath-timer__selector-popover" data-sabbath-selector-popover hidden>
          <div class="adventistai-sabbath-timer__selector-title">Pasirinkite miestą</div>
          <div
            id="adventistai-sabbath-city-list"
            class="adventistai-sabbath-timer__selector-list"
            role="listbox"
            aria-label="Lietuvos miestai"
          >
            @foreach ($sabbathTimerData['cities'] as $cityKey => $city)
              <button
                type="button"
                class="adventistai-sabbath-timer__selector-option{{ $cityKey === $sabbathDefaultCity ? ' is-selected' : '' }}"
                data-sabbath-city="{{ $cityKey }}"
                role="option"
                aria-selected="{{ $cityKey === $sabbathDefaultCity ? 'true' : 'false' }}"
              >
                <span>{{ $city['name'] }}</span>
                <time class="adventistai-sabbath-timer__selector-time" data-sabbath-city-time>—</time>
              </button>
            @endforeach
          </div>
        </div>
      </div>

      <span class="adventistai-sabbath-timer__meta" data-sabbath-start-meta></span>
    </div>
  </div>

  <div class="adventistai-sabbath-timer__sabbath-view" data-sabbath-active-view hidden>
    <blockquote class="adventistai-sabbath-timer__verse">
      <p class="adventistai-sabbath-timer__verse-text" data-sabbath-verse-text></p>
      <cite class="adventistai-sabbath-timer__verse-reference" data-sabbath-verse-reference></cite>
    </blockquote>

    <div class="adventistai-sabbath-timer__sabbath-meta">
      <div class="adventistai-sabbath-timer__selector" data-sabbath-selector-active>
        <button
          type="button"
          class="adventistai-sabbath-timer__selector-button"
          data-sabbath-selector-button-active
          aria-haspopup="listbox"
          aria-expanded="false"
          aria-controls="adventistai-sabbath-city-list-active"
        >
          <span data-sabbath-selected-city-active>{{ $sabbathTimerData['cities'][$sabbathDefaultCity]['name'] }}</span>
        </button>

        <div class="adventistai-sabbath-timer__selector-popover" data-sabbath-selector-popover-active hidden>
          <div class="adventistai-sabbath-timer__selector-title">Pasirinkite miestą</div>
          <div
            id="adventistai-sabbath-city-list-active"
            class="adventistai-sabbath-timer__selector-list"
            role="listbox"
            aria-label="Lietuvos miestai"
          >
            @foreach ($sabbathTimerData['cities'] as $cityKey => $city)
              <button
                type="button"
                class="adventistai-sabbath-timer__selector-option{{ $cityKey === $sabbathDefaultCity ? ' is-selected' : '' }}"
                data-sabbath-city-active="{{ $cityKey }}"
                role="option"
                aria-selected="{{ $cityKey === $sabbathDefaultCity ? 'true' : 'false' }}"
              >
                <span>{{ $city['name'] }}</span>
                <time class="adventistai-sabbath-timer__selector-time" data-sabbath-city-end-time>—</time>
              </button>
            @endforeach
          </div>
        </div>
      </div>

      <span class="adventistai-sabbath-timer__end-time" data-sabbath-end-meta></span>
    </div>
  </div>

  <script type="application/json" data-sabbath-data>{!! wp_json_encode($sabbathTimerData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
</div>
