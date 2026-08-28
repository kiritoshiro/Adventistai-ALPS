<form role="search" method="get" class="search-form" action="{{ home_url('/') }}">
  <label>
    <span class="sr-only">
      {{ _x('Search for:', 'label', 'alps') }}
    </span>

    <input
      type="search"
      placeholder="{!! esc_attr_x('Search &hellip;', 'placeholder', 'alps') !!}"
      value="{{ get_search_query() }}"
      name="s"
    >
  </label>

  <button>{{ _x('Search', 'submit button', 'alps') }}</button>
</form>
