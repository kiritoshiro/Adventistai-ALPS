@php
  $theme_color = get_alps_option('theme_color');
  $alpsVersion = \App\Core\ALPSVersions::get();
@endphp
<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <meta http-equiv="x-ua-compatible" content="ie=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  @php wp_head() @endphp

  @php
    // Security: only emit schema markup as validated JSON-LD (never raw HTML).
    $schemamarkup = get_post_meta(get_the_ID(), 'schemamarkup', true);
    if (!empty($schemamarkup) && is_string($schemamarkup)) {
      $schema_json = trim($schemamarkup);
      if (preg_match('#<script[^>]*>(.*?)</script>#is', $schema_json, $schema_m)) {
        $schema_json = trim($schema_m[1]);
      }
      $schema_data = json_decode($schema_json);
      if ($schema_data !== null && json_last_error() === JSON_ERROR_NONE) {
        echo '<script type="application/ld+json">' . wp_json_encode($schema_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
      }
    }
  @endphp

  <link rel="shortcut icon" href="<?php bloginfo('template_directory'); ?>/assets/images/favicon<?php if ($theme_color): echo '--' . $theme_color; endif; ?>.png">
  {{-- The ALPS stylesheet, font preloads and Sabbath timer styles are enqueued from app/setup.php. --}}
  <script src="{{ $alpsVersion['scripts']['head'] }}{{ alps_asset_version($alpsVersion['scripts']['head']) }}" type="text/javascript" async></script>
</head>
