<?php
  $offset = empty($settings['post_feed_offset']) ? '' : $settings['post_feed_offset'];
  $featured = empty($settings['post_feed_featured']) ? false : $settings['post_feed_featured'];
  $category = empty($settings['post_feed_category']) ? 'news' : $settings['post_feed_category'];
  $title = empty($settings['post_feed_title']) ? get_cat_name($category) : $settings['post_feed_title'];
  $url = empty($settings['post_feed_url']) ? '' : $settings['post_feed_url'];
  $count = empty($settings['post_feed_count']) ? '-1' : $settings['post_feed_count'];
  $layout_grid = empty($settings['post_feed_layout']) ? false : $settings['post_feed_layout'];

  $args = array(
    'cat' => $category,
    'posts_per_page' => $count,
    'offset' => $offset,
  );
  $the_query = new WP_Query($args);
?>

<div class="c-block-wrap u-spacing <?php if ($layout_grid == true): echo 'u-space--right--negative'; endif; ?>">
  <div class="c-block__heading u-theme--border-color--darker">
    <h3 class="c-block__heading-title u-theme--color--darker">
      <?php echo esc_html($title); ?>
    </h3>
    <?php if ($url): ?>
      <a href="<?php echo esc_url($url); ?>" class="c-block__heading-link u-theme--color--base u-theme--link-hover--dark"><?php esc_html_e('See All', 'alps'); ?></a>
    <?php endif; ?>
  </div>
  <div class="c-block-wrap__content u-spacing">
    <?php if ($the_query->have_posts()): ?>
      <?php while ($the_query->have_posts()) : $the_query->the_post(); ?>
        <?php
          $id = get_the_ID();
          $title = get_the_title($id);
          $link = get_permalink($id);
          $date = null;
          $image = null;
          $thumb_id = null;
          $excerpt = '';
          $body = '';

          $categories = get_the_category();
          $category = '';
          if (!empty($categories)) {
            if (class_exists('WPSEO_Primary_Term')) {
              $wpseo_primary_term = new WPSEO_Primary_Term('category', get_the_id());
              $wpseo_primary_term = $wpseo_primary_term->get_primary_term();
              $term = get_term($wpseo_primary_term);

              if (is_wp_error($term)) {
                $category = $categories[0]->name;
              } else {
                $category = $term->name;
              }
            } else {
              $category = $categories[0]->name;
            }
          }

          if ($featured == true) {
            $date = get_the_date('F j, Y');
            $excerpt = get_the_excerpt($id);
            $body = get_the_content(null, false, $id);
            $thumb_id = get_post_thumbnail_id($id);
            $thumb_size = 'horiz__4x3';
            $image_data = $thumb_id ? wp_get_attachment_image_src($thumb_id, $thumb_size . '--s') : false;
            $image = $image_data ? $image_data[0] : '';
            $alt = $thumb_id ? get_post_meta($thumb_id, '_wp_attachment_image_alt', true) : '';
            $block_meta_class = 'u-theme--color--dark u-font--secondary--xs';

            if ($layout_grid == true) {
              $excerpt_length = 100;
              $block_class = 'c-block--reversed c-media-block--reversed l-grid--7-col';
              $block_img_class = 'l-grid-item--2-col l-grid-item--m--1-col l-grid-item--l--1-col u-padding--right';
              $block_content_class = 'l-grid-item--4-col l-grid-item--m--3-col l-grid-item--l--1-col u-border--left u-theme--border-color--darker--left u-color--gray u-spacing--half';
              $block_title_class = 'u-theme--color--darker u-font--primary--s';
              $block_group_class = 'u-flex--justify-start';
            } else {
              $excerpt_length = 200;
              $block_class = 'c-block__stacked c-media-block__stacked';
              $block_content_class = 'l-grid-item u-border--left u-color--gray u-theme--border-color--darker--left u-spacing--half';
              $block_title_class = 'u-theme--color--darker u-font--primary--m';
              $block_img_class = '';
              $block_group_class = '';
            }
          } else {
            $excerpt_length = 35;
            $excerpt = get_the_excerpt($id);
            $body = get_the_content(null, false, $id);
            $block_class = 'c-block__text u-theme--border-color--darker u-border--left u-padding--bottom u-spacing--half';
            $block_title_class = 'u-theme--color--darker u-font--primary--s';
          }
        ?>
        <?php if ($featured == true): ?>
          <div class="c-media-block c-block <?php echo esc_attr($block_class ?? ''); ?>">
          <?php if (!empty($image) || isset($picture)): ?>
            <div class="c-media-block__image c-block__image <?php echo esc_attr($block_img_class ?? ''); ?><?php if (isset($block_type)): ?> c-block__icon c-block__icon--<?php echo esc_attr($block_type); ?><?php endif; ?>">
              <div class="c-block__image-wrap <?php echo esc_attr($block_img_wrap_class ?? ''); ?>">
                <?php if (isset($picture)): ?>
                  <picture class="picture">
                    <!--[if IE 9]><video style="display: none;"><![endif]-->
                    <?php if (isset($image_break_xl, $image_xl)): ?>
                      <source srcset="<?php echo esc_url($image_xl); ?>" media="(min-width: <?php echo absint($image_break_xl); ?>px)">
                    <?php endif; ?>
                    <?php if (isset($image_break_l, $image_l)): ?>
                      <source srcset="<?php echo esc_url($image_l); ?>" media="(min-width: <?php echo absint($image_break_l); ?>px)">
                    <?php endif; ?>
                    <?php if (isset($image_m, $image_break_m)): ?>
                      <source srcset="<?php echo esc_url($image_m); ?>" media="(min-width: <?php echo absint($image_break_m); ?>px)">
                    <?php endif; ?>
                    <!--[if IE 9]></video><![endif]-->
                    <?php if (isset($image_s)): ?><img itemprop="image" srcset="<?php echo esc_url($image_s); ?>" alt="<?php echo esc_attr($alt ?? ''); ?>"><?php endif; ?>
                  </picture>
                <?php elseif (!empty($image)): ?>
                  <img src="<?php echo esc_url($image); ?>" itemprop="image" alt="<?php echo esc_attr($alt ?? ''); ?>" />
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
          <div class="c-media-block__content c-block__content u-spacing <?php echo esc_attr($block_content_class ?? ''); ?>">
            <div class="u-spacing c-block__group c-media-block__group <?php echo esc_attr($block_group_class ?? ''); ?>">
              <div class="u-spacing u-width--100p">
                <?php if (isset($kicker)): ?>
                  <h4 class="c-media-block__kicker c-block__kicker <?php echo esc_attr($block_kicker_class ?? ''); ?>"><?php echo esc_html($kicker); ?></h4>
                <?php endif; ?>
                <h3 class="c-media-block__title c-block__title <?php echo esc_attr($block_title_class ?? ''); ?><?php if (isset($kicker)): ?> u-space--zero<?php endif; ?>">
                  <?php if ($link): ?><a href="<?php echo esc_url($link); ?>" class="c-block__title-link u-theme--link-hover--dark"><?php endif; ?>
                  <?php echo esc_html($title); ?>
                  <?php if ($link): ?></a><?php endif; ?>
                </h3>
                <?php if (!empty($excerpt) || !empty($body)): ?>
                  <p class="c-media-block__description c-block__description">
                    <?php
                      $source_text = !empty($excerpt) ? $excerpt : $body;
                      $display_text = strlen($source_text) > $excerpt_length
                        ? wp_trim_words($body, $excerpt_length)
                        : $source_text;
                      echo esc_html(wp_strip_all_tags(strip_shortcodes($display_text)));
                    ?>
                  </p>
                <?php endif; ?>
              </div>
              <?php if ($category !== '' || $date): ?>
                <div class="c-media-block__meta c-block__meta <?php echo esc_attr($block_meta_class ?? ''); ?>">
                  <?php if ($category !== ''): ?><span class="c-block__category u-text-transform--upper"><?php echo esc_html($category); ?></span><?php endif; ?>
                  <?php if ($date): ?><time class="c-block__date u-text-transform--upper"><?php echo esc_html($date); ?></time><?php endif; ?>
                </div>
              <?php endif; ?>
              <?php if (isset($cta)): ?>
                <a href="<?php echo esc_url($link); ?>" class="c-block__button o-button o-button--outline"><?php echo esc_html($cta); ?><span class="u-icon u-icon--m u-path-fill--base u-space--half--left"><?php include locate_template('patterns/00-atoms/icons/icon-arrow-long-right.blade.php'); ?></span></a>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php else: ?>
          <div class="c-block c-block__text <?php if ($thumb_id): ?>has-image<?php endif; ?> u-theme--border-color--darker u-border--left <?php echo esc_attr($block_class ?? ''); ?>">
            <?php if ($thumb_id): ?>
              <?php $thumb = wp_get_attachment_image_src($thumb_id, 'featured__hero--m'); ?>
              <?php if ($thumb): ?><img class="c-block__image" src="<?php echo esc_url($thumb[0]); ?>" alt="" /><?php endif; ?>
            <?php endif; ?>
            <h3 class="u-theme--color--darker <?php echo esc_attr($block_title_class ?? ''); ?>">
              <?php if ($link): ?><a href="<?php echo esc_url($link); ?>" class="c-block__title-link u-theme--link-hover--dark"><?php endif; ?>
              <strong><?php echo esc_html($title); ?></strong>
              <?php if ($link): ?></a><?php endif; ?>
            </h3>
            <?php if (!empty($excerpt) || !empty($body)): ?>
              <p class="c-block__body text">
                <?php
                  $source_text = !empty($excerpt) ? $excerpt : $body;
                  $display_text = str_word_count(wp_strip_all_tags($source_text)) > $excerpt_length
                    ? wp_trim_words($body, $excerpt_length)
                    : $source_text;
                  echo wp_kses_post(strip_shortcodes($display_text));
                ?>
              </p>
            <?php endif; ?>
            <?php if ($category !== '' || $date): ?>
              <span class="c-block__meta u-theme--color--dark u-font--secondary--xs">
                <?php if ($category !== ''): ?><span class="c-block__category u-text-transform--upper"><?php echo esc_html($category); ?></span><?php endif; ?>
                <?php if ($date): ?><time class="c-block__date u-text-transform--upper"><?php echo esc_html($date); ?></time><?php endif; ?>
              </span>
            <?php endif; ?>
            <?php if (isset($expand_body)): ?>
              <div class="c-block__content"><p><?php echo wp_kses_post($expand_body); ?></p></div>
            <?php endif; ?>
            <?php if (isset($expand)): ?>
              <a href="" class="o-button o-button--outline o-button--expand js-toggle-parent"></a>
            <?php elseif (isset($cta)): ?>
              <a href="<?php echo esc_url($link); ?>" class="c-block__button o-button o-button--outline"><?php echo esc_html($cta); ?><span class="u-icon u-icon--m u-path-fill--base u-space--half--left"><?php include locate_template('patterns/00-atoms/icons/icon-arrow-long-right.blade.php'); ?></span></a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      <?php endwhile; ?>
      <?php wp_reset_postdata(); ?>
    <?php else: ?>
      <?php esc_html_e('There are no posts at this time.', 'alps'); ?>
    <?php endif; ?>
  </div>
</div>
