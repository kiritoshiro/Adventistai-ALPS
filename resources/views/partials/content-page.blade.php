{{--
  Body of the "Page Builder Template" (template-custom.blade.php).

  This used to output the_content() bare, straight into <main class="l-main">.
  With no .l-main__content grid and no .text wrapper, none of the theme's
  layout, measure or typography rules could reach it — which is why this
  template drifted full-bleed while the default page template behaved.

  It now uses the same structure as the default template
  (patterns/02-organisms/content/content-page.blade.php), minus the breadcrumbs,
  sidebar and related-pages sections, which page-builder pages supply themselves.

  Blocks set to "Full width" in the editor still break out of the measure —
  see .alignfull / .alignwide in assets/css/site-overrides.css section 18.
--}}
<section id="top" class="l-main__content l-grid l-grid--7-col l-grid-wrap--6-of-7 u-spacing--double--until-xxlarge u-padding--zero--sides">
  <article @php post_class("c-article l-grid-item l-grid-item--l--5-col") @endphp>
    <div class="c-article__body">
      <div class="text u-spacing">
        @php(the_content())

        {!! wp_link_pages(['echo' => 0, 'before' => '<nav class="page-nav"><p>' . __('Pages:', 'alps'), 'after' => '</p></nav>']) !!}
        @include('patterns.02-organisms.sections.latest-post-sliders')
      </div>
    </div>
  </article>
</section>
