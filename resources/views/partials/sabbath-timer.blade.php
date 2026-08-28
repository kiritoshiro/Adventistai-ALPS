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
      <span class="adventistai-sabbath-timer__meta" data-sabbath-meta>
        {{ $sabbathTimerData['cities'][$sabbathDefaultCity]['name'] }} · {{ $sabbathInitialEvent['time'] }}
      </span>
    </div>

    <div class="adventistai-sabbath-timer__cities" aria-label="Pasirinkite miestą">
      <span class="adventistai-sabbath-timer__cities-label">Saulėlydis pagal miestą</span>
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
          class="adventistai-sabbath-timer__city{{ $cityKey === $sabbathDefaultCity ? ' is-selected' : '' }}"
          data-sabbath-city="{{ $cityKey }}"
          aria-pressed="{{ $cityKey === $sabbathDefaultCity ? 'true' : 'false' }}"
        >
          <span>{{ $city['name'] }}</span>
          @if ($cityInitialEvent)
            <time
              class="adventistai-sabbath-timer__city-time"
              data-sabbath-city-time
              datetime="{{ $cityInitialEvent['iso'] }}"
            >{{ $cityInitialEvent['time'] }}</time>
          @else
            <time class="adventistai-sabbath-timer__city-time" data-sabbath-city-time>—</time>
          @endif
        </button>
      @endforeach
    </div>

    <script type="application/json" data-sabbath-data>{!! wp_json_encode($sabbathTimerData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
  </div>
@endif
