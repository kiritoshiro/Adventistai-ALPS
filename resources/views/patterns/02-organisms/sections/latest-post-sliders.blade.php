@php
  $sectionAttributes = $sectionAttributes ?? '';
  $configuredSliderModules = $configuredSliderModules ?? [];
  $sliderColumns = $sliderColumns ?? 1;
  $renderableSliderModules = [];

  foreach ($configuredSliderModules as $configuredSliderModule) {
    $sliderPosts = \App\LatestPostSlider::posts($configuredSliderModule);

    if (!empty($sliderPosts)) {
      $renderableSliderModules[] = [
        'module' => $configuredSliderModule,
        'posts' => $sliderPosts,
      ];
    }
  }
@endphp

@if (!empty($renderableSliderModules))
  <section {!! $sectionAttributes ?: 'class="alps-latest-sliders u-space--double--top" style="--alps-latest-slider-columns: ' . esc_attr($sliderColumns) . ';"' !!} data-alps-latest-sliders>
    @foreach ($renderableSliderModules as $renderableSliderModule)
      @php
        $module = $renderableSliderModule['module'];
        $sliderPosts = $renderableSliderModule['posts'];
        $source = sanitize_key((string) ($module['alps_latest_slider_source'] ?? 'latest'));
        $moduleTitle = trim(wp_strip_all_tags((string) ($module['alps_latest_slider_title'] ?? '')));
        $moduleUrl = esc_url($module['alps_latest_slider_title_url'] ?? '', ['http', 'https']);

        if (!$moduleUrl && $source === 'category') {
          $categoryIds = \App\LatestPostSlider::associationIds($module['alps_latest_slider_category'] ?? []);
          $categoryUrl = $categoryIds ? get_category_link(reset($categoryIds)) : '';
          $moduleUrl = is_wp_error($categoryUrl) ? '' : esc_url($categoryUrl);
        }

        if (!$moduleTitle && $source === 'category') {
          $categoryIds = \App\LatestPostSlider::associationIds($module['alps_latest_slider_category'] ?? []);
          $category = !empty($categoryIds) ? get_term(reset($categoryIds), 'category') : null;
          $moduleTitle = ($category && !is_wp_error($category)) ? $category->name : __('Kategorijos įrašai', 'alps');
        } elseif (!$moduleTitle && $source === 'custom') {
          $moduleTitle = __('Pasirinkti įrašai ir puslapiai', 'alps');
        } elseif (!$moduleTitle) {
          $moduleTitle = __('Naujausi įrašai', 'alps');
        }

        $interval = min(30, max(2, absint($module['alps_latest_slider_interval'] ?? 5)));
        $autoplay = !array_key_exists('alps_latest_slider_autoplay', $module)
          || in_array((string) $module['alps_latest_slider_autoplay'], ['1', 'true'], true);
        $sliderId = wp_unique_id('alps-latest-slider-');
        $slideCount = count($sliderPosts);
      @endphp

      <article
        class="alps-latest-slider"
        data-alps-latest-slider
        data-alps-slider-interval="{{ $interval }}"
        data-alps-slider-autoplay="{{ $autoplay ? 'true' : 'false' }}"
      >
        <div class="alps-latest-slider__heading">
          <h2 class="alps-latest-slider__title">
            @if ($moduleUrl)
              <a href="{{ $moduleUrl }}">{{ $moduleTitle }}</a>
            @else
              {{ $moduleTitle }}
            @endif
          </h2>
        </div>

        <div
          class="alps-latest-slider__viewport"
          data-alps-slider-viewport
          aria-live="polite"
        >
          @foreach ($sliderPosts as $slideIndex => $sliderPost)
            @php
              $postId = $sliderPost->ID;
              $postTitle = get_the_title($postId);
              $postLink = get_permalink($postId);
              $postExcerpt = get_the_excerpt($sliderPost);

              if (!$postExcerpt) {
                $postExcerpt = get_post_field('post_content', $postId);
              }

              $postExcerpt = wp_trim_words(wp_strip_all_tags((string) $postExcerpt), 32, '…');
              $thumbnailId = get_post_thumbnail_id($postId);
              $thumbnailAlt = $thumbnailId ? get_post_meta($thumbnailId, '_wp_attachment_image_alt', true) : '';
              $thumbnailAlt = $thumbnailAlt ?: $postTitle;
              $categories = get_the_category($postId);
              $categoryName = !empty($categories) ? $categories[0]->name : '';
              $isActive = $slideIndex === 0;
            @endphp

            <article
              id="{{ $sliderId }}-slide-{{ $slideIndex }}"
              class="alps-latest-slider__slide{{ $isActive ? ' is-active' : '' }}"
              data-alps-slider-slide
              aria-hidden="{{ $isActive ? 'false' : 'true' }}"
              aria-roledescription="{{ __('skaidrė', 'alps') }}"
              aria-label="{{ sprintf(__('%1$d iš %2$d', 'alps'), $slideIndex + 1, $slideCount) }}"
            >
              <a class="alps-latest-slider__link" href="{{ esc_url($postLink) }}">
                @if ($thumbnailId)
                  {!! get_the_post_thumbnail($postId, 'horiz__16x9--m', [
                    'class' => 'alps-latest-slider__image',
                    'alt' => $thumbnailAlt,
                    'loading' => $isActive ? 'eager' : 'lazy',
                    'decoding' => 'async',
                  ]) !!}
                @endif

                <div class="alps-latest-slider__body">
                  @if ($categoryName)
                    <span class="alps-latest-slider__meta">{{ $categoryName }}</span>
                  @endif
                  <h3 class="alps-latest-slider__post-title">{{ $postTitle }}</h3>
                  @if ($postExcerpt)
                    <div class="alps-latest-slider__excerpt-wrap">
                      <p class="alps-latest-slider__excerpt">{{ $postExcerpt }}</p>
                    </div>
                  @endif
                  <span class="alps-latest-slider__read-more">
                    {{ __('Skaityti daugiau', 'alps') }}
                    <span aria-hidden="true">&rarr;</span>
                  </span>
                </div>
              </a>
            </article>
          @endforeach
        </div>

        @if ($slideCount > 1)
          <div
            class="alps-latest-slider__dots"
            data-alps-slider-dots
            role="tablist"
            aria-label="{{ sprintf(__('%s skaidrės', 'alps'), $moduleTitle) }}"
          >
            @foreach ($sliderPosts as $dotIndex => $sliderPost)
              @php($dotActive = $dotIndex === 0)
              <button
                type="button"
                class="alps-latest-slider__dot{{ $dotActive ? ' is-active' : '' }}"
                data-alps-slider-dot
                role="tab"
                aria-controls="{{ $sliderId }}-slide-{{ $dotIndex }}"
                aria-label="{{ sprintf(__('Rodyti skaidrę %d', 'alps'), $dotIndex + 1) }}"
                aria-selected="{{ $dotActive ? 'true' : 'false' }}"
                tabindex="{{ $dotActive ? '0' : '-1' }}"
              ></button>
            @endforeach
          </div>
        @endif
      </article>
    @endforeach
  </section>
@endif
