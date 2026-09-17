@if (!is_front_page() && !is_home())
  @php
    $has_yoast_breadcrumbs = function_exists('yoast_breadcrumb');
    $native_breadcrumbs = $has_yoast_breadcrumbs ? '' : \App\Breadcrumbs::render();
  @endphp
  @if ($has_yoast_breadcrumbs || $native_breadcrumbs !== '')
    <nav class="c-breadcrumbs" aria-label="{{ __('Breadcrumbs', 'alps') }}">
      @if ($has_yoast_breadcrumbs)
      @php yoast_breadcrumb('<ul class="c-breadcrumbs__list">','</ul>') @endphp
      @else
        {!! $native_breadcrumbs !!}
      @endif
    </nav>
  @endif
@endif
