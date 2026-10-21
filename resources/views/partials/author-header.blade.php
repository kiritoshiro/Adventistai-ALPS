@php
  /**
   * An author's photo, bio and links at the top of their page (App\Authors).
   *
   * @var \WP_Term $author
   */
  $authorImage = (int) get_term_meta($author->term_id, \App\Authors\Taxonomy::IMAGE, true);
  $authorWebsite = (string) get_term_meta($author->term_id, \App\Authors\Taxonomy::URL, true);
  $authorFacebook = (string) get_term_meta($author->term_id, \App\Authors\Taxonomy::FACEBOOK, true);
  $authorBio = trim((string) $author->description);
@endphp
@if ($authorImage || $authorBio !== '' || $authorWebsite !== '' || $authorFacebook !== '')
  <div class="alps-author-header u-spacing--half" style="display:flex;gap:1.25rem;align-items:flex-start;flex-wrap:wrap">
    @if ($authorImage)
      {!! wp_get_attachment_image($authorImage, 'thumbnail', false, [
        'class' => 'alps-author-header__photo',
        'style' => 'width:120px;height:120px;object-fit:cover;border-radius:50%;flex:none',
        'alt' => $author->name,
      ]) !!}
    @endif
    <div class="alps-author-header__text u-spacing--half" style="flex:1 1 16rem">
      @if ($authorBio !== '')
        <div class="alps-author-header__bio">{!! wpautop(wp_kses_post($authorBio)) !!}</div>
      @endif
      @if ($authorWebsite !== '' || $authorFacebook !== '')
        <ul class="o-inline-list alps-author-header__links">
          @if ($authorWebsite !== '')
            <li><a href="{{ esc_url($authorWebsite) }}" rel="noopener me" target="_blank">{{ __('Website', 'alps') }}</a></li>
          @endif
          @if ($authorFacebook !== '')
            <li><a href="{{ esc_url($authorFacebook) }}" rel="noopener me" target="_blank">{{ __('Facebook', 'alps') }}</a></li>
          @endif
        </ul>
      @endif
    </div>
  </div>
@endif
