@php
  $sabbathTimerData = \App\SabbathTimer::data();
  $sabbathDefaultCity = $sabbathTimerData['defaultCity'];
  $sabbathNow = time();
  $sabbathInitialEvent = null;

  if (isset($sabbathTimerData['cities'][$sabbathDefaultCity]['events'])) {
    foreach ($sabbathTimerData['cities'][$sabbathDefaultCity]['events'] as $event) {
      if ($event['timestamp'] > $sabbathNow) {
        $sabbathInitialEvent = $event;
        break;
      }
    }
  }
@endphp

@if ($sabbathInitialEvent)
  <div class="adventistai-sabbath-timer" data-sabbath-timer>
    <div class="adventistai-sabbath-timer__main">
      <span class="adventistai-sabbath-timer__label" data-sabbath-label>
        {{ $sabbathInitialEvent['type'] === 'end' ? 'Sabata baigiasi už:' : 'Sabata prasideda už:' }}
      </span>
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

        <div
          class="adventistai-sabbath-timer__selector-popover"
          data-sabbath-selector-popover
          hidden
        >
          <div class="adventistai-sabbath-timer__selector-title">Pasirinkite miestą</div>
          <div
            id="adventistai-sabbath-city-list"
            class="adventistai-sabbath-timer__selector-list"
            role="listbox"
            aria-label="Lietuvos miestai"
          >
            @foreach ($sabbathTimerData['cities'] as $cityKey => $city)
              @php
                $cityInitialEvent = null;
                foreach ($city['events'] as $event) {
                  if ($event['timestamp'] > $sabbathNow) {
                    $cityInitialEvent = $event;
                    break;
                  }
                }
              @endphp
              <button
                type="button"
                class="adventistai-sabbath-timer__selector-option{{ $cityKey === $sabbathDefaultCity ? ' is-selected' : '' }}"
                data-sabbath-city="{{ $cityKey }}"
                role="option"
                aria-selected="{{ $cityKey === $sabbathDefaultCity ? 'true' : 'false' }}"
              >
                <span>{{ $city['name'] }}</span>
                <time
                  class="adventistai-sabbath-timer__selector-time"
                  data-sabbath-city-time
                  @if ($cityInitialEvent) datetime="{{ $cityInitialEvent['iso'] }}" @endif
                >{{ $cityInitialEvent ? $cityInitialEvent['time'] : '—' }}</time>
              </button>
            @endforeach
          </div>
        </div>
      </div>

      <span class="adventistai-sabbath-timer__meta" data-sabbath-meta>{{ $sabbathInitialEvent['time'] }}</span>
    </div>

    <script type="application/json" data-sabbath-data>{!! wp_json_encode($sabbathTimerData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
  </div>
@endif
