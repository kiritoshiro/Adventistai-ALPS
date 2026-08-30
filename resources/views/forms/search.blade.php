<form role="search" method="get" class="search-form" action="{{ home_url('/') }}">
  <label>
    <span class="sr-only">Ieškoti:</span>

    <input
      type="search"
      placeholder="Ieškoti…"
      value="{{ get_search_query() }}"
      name="s"
    >
  </label>

  <button>Ieškoti</button>
</form>
