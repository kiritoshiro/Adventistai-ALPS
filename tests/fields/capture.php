<?php
/**
 * Records how Carbon Fields stores and returns every theme field, as the
 * reference for replacing it with native WordPress code.
 *
 * Runs inside a throwaway WordPress with this theme active (WordPress
 * Playground, see tests/fields/README.md), never on a real site: it creates
 * posts, images, terms and widgets and overwrites theme options.
 *
 * For every container and field it records the definition (type, default,
 * options, post types it shows on), saves a test value through Carbon Fields'
 * own API, then dumps the raw rows written (post meta, options, widget
 * instances) and what carbon_get_*(), get_alps_field(), get_alps_option() and
 * crb_get_i18n_theme_option() return, both for saved values and for an empty
 * post (defaults). Output: tests/fields/fixtures/carbon-fields-<version>.json.
 */

if (! defined('ABSPATH')) {
    exit;
}

use Carbon_Fields\Carbon_Fields;
use Carbon_Fields\Field\Association_Field;
use Carbon_Fields\Field\Checkbox_Field;
use Carbon_Fields\Field\Complex_Field;

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/post.php';

$capture = new class {
    /** Placeholder names for the IDs created here, so runs can be compared. */
    public $ids = [];

    public function image($name, $rgb)
    {
        $upload = wp_upload_dir();
        $file = $upload['basedir'] . '/cf-capture-' . $name . '.jpg';
        wp_mkdir_p(dirname($file));
        $gd = imagecreatetruecolor(800, 500);
        imagefill($gd, 0, 0, imagecolorallocate($gd, $rgb[0], $rgb[1], $rgb[2]));
        imagejpeg($gd, $file, 80);
        // Only the attachment ID matters here. No sub-sizes: the theme converts
        // uploads to AVIF, which Playground's GD cannot encode.
        $id = wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => 'Capture ' . $name, 'post_status' => 'inherit'], $file);
        wp_update_attachment_metadata($id, ['width' => 800, 'height' => 500, 'file' => basename($file), 'sizes' => []]);
        $this->ids['image_' . $name] = $id;
        return $id;
    }

    public function post($name, $type, $title)
    {
        $id = wp_insert_post(['post_type' => $type, 'post_status' => 'publish', 'post_title' => $title, 'post_content' => '<!-- wp:paragraph --><p>' . $title . '</p><!-- /wp:paragraph -->']);
        if (! $id || is_wp_error($id)) {
            throw new Exception("could not create $name");
        }
        $this->ids[$name] = $id;
        return $id;
    }

    /** JSON-safe copy of a value, with known IDs replaced by their placeholder names. */
    public function plain($value)
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->plain($v);
            }
            return $out;
        }
        if (is_object($value)) {
            return ['__object' => get_class($value)] + $this->plain(get_object_vars($value));
        }
        return $value;
    }

    public function label($text)
    {
        $map = array_flip(array_map('strval', $this->ids));
        $text = (string) $text;
        return preg_replace_callback('/(?<=[:|=])(\d+)\b|^(\d+)$/', function ($m) use ($map) {
            $n = $m[1] !== '' ? $m[1] : $m[2];
            return isset($map[$n]) ? '{' . $map[$n] . '}' : $n;
        }, $text);
    }
};

global $wpdb, $wp_widget_factory;

$out = [
    'carbon_fields' => defined('Carbon_Fields\\VERSION') ? \Carbon_Fields\VERSION : '?',
    'wordpress' => get_bloginfo('version'),
    'php' => PHP_VERSION,
    'theme_version' => wp_get_theme()->get('Version'),
];

// Objects the test values point at.
$capture->image('a', [200, 40, 40]);
$capture->image('b', [40, 160, 60]);
$capture->image('c', [40, 60, 200]);
$capture->post('page_target', 'page', 'Association target page');
$capture->post('post_target', 'post', 'Association target post');
$term = wp_insert_term('Capture category', 'category');
$capture->ids['term_capture'] = is_wp_error($term) ? 0 : (int) $term['term_id'];
$empty_page = $capture->post('page_empty', 'page', 'Empty page (defaults)');
$empty_post = $capture->post('post_empty', 'post', 'Empty post (defaults)');
$page = $capture->post('page_filled', 'page', 'Filled page');
$post = $capture->post('post_filled', 'post', 'Filled post');
$images = [$capture->ids['image_a'], $capture->ids['image_b'], $capture->ids['image_c']];

/** A test value for a field, as Carbon Fields' own setters accept it. */
$value_for = function ($field, $row = 0) use (&$value_for, $images, $capture) {
    $name = $field->get_base_name();
    switch ($field->get_type()) {
        case 'checkbox':
            return $field->get_option_value();
        case 'image':
        case 'file':
            return $images[$row % 3];
        case 'radio':
        case 'select':
            $keys = array_map('strval', array_keys((array) $field->get_options()));
            $default = (string) $field->get_default_value();
            $other = array_values(array_diff($keys, [$default]));
            return $other ? end($other) : ($keys ? $keys[0] : '');
        case 'association':
            $picked = [];
            foreach ((array) $field->get_types() as $type) {
                if ($type['type'] === 'post') {
                    $target = $type['post_type'] === 'page' ? 'page_target' : 'post_target';
                    $picked[] = ['type' => 'post', 'subtype' => $type['post_type'], 'id' => $capture->ids[$target]];
                } elseif ($type['type'] === 'term') {
                    $picked[] = ['type' => 'term', 'subtype' => $type['taxonomy'], 'id' => $capture->ids['term_capture']];
                }
            }
            return $picked;
        case 'complex':
            $rows = [];
            $max = method_exists($field, 'get_max') ? (int) $field->get_max() : -1;
            $count = $max > 0 ? min(2, $max) : 2;
            // Rows name their group; '_' is the unnamed one. footer_description
            // uses 'translatable_footer_description' and is empty without it.
            $group = $field->get_group_names()[0] ?? '_';
            for ($i = 0; $i < $count; $i++) {
                $values = $group === '_' ? [] : ['_type' => $group];
                foreach ($field->get_group_by_name($group)->get_fields() as $sub) {
                    if (! in_array($sub->get_type(), ['separator', 'html'], true)) {
                        $values[$sub->get_base_name()] = $value_for($sub, $i);
                    }
                }
                $rows[] = $values;
            }
            return $rows;
        case 'color':
            return '#123456';
        case 'rich_text':
            return "<p>Rich {$name} <strong>bold</strong></p>";
        case 'textarea':
            return "Line one {$name}\nLine two";
        default:
            return "Text {$name}" . ($row ? " row {$row}" : '');
    }
};

/** Definition of one field, recursively for complex fields. */
$describe = function ($field) use (&$describe) {
    $d = ['name' => $field->get_base_name(), 'type' => $field->get_type()];
    $default = $field->get_default_value();
    if ($default !== '' && $default !== null && $default !== []) {
        $d['default'] = $default;
    }
    if ($field instanceof Checkbox_Field) {
        $d['option_value'] = $field->get_option_value();
    }
    if (method_exists($field, 'get_options') && in_array($field->get_type(), ['radio', 'select'], true)) {
        $d['options'] = array_map('strval', array_keys((array) $field->get_options()));
    }
    if ($field instanceof Association_Field) {
        $d['types'] = $field->get_types();
        $d['max'] = $field->get_max();
    }
    if ($field instanceof Complex_Field) {
        $d['max'] = $field->get_max();
        $d['groups'] = [];
        foreach ($field->get_group_names() as $group) {
            $d['groups'][$group] = array_map($describe, $field->get_group_by_name($group)->get_fields());
        }
    }
    if (method_exists($field, 'get_conditional_logic') && $field->get_conditional_logic()) {
        $d['conditional_logic'] = $field->get_conditional_logic();
    }
    if (method_exists($field, 'get_value_type') && $field->get_type() === 'image') {
        $d['value_type'] = $field->get_value_type();
    }
    return $d;
};

$repository = Carbon_Fields::resolve('container_repository');
$containers = [];
$theme_fields = [];
$post_fields = [];
foreach ($repository->get_containers() as $container) {
    $entry = ['id' => $container->get_id(), 'type' => $container->type, 'title' => $container->title];
    if ($container->type === 'post_meta') {
        $entry['post_types'] = array_values($container->get_post_type_visibility());
    }
    $entry['fields'] = [];
    foreach ($container->get_fields() as $field) {
        if (in_array($field->get_type(), ['separator', 'html'], true)) {
            continue;
        }
        $entry['fields'][] = $describe($field);
        if ($container->type === 'post_meta') {
            $post_fields[$field->get_base_name()] = $field;
        } elseif ($container->type === 'theme_options') {
            $theme_fields[$field->get_base_name()] = $field;
        }
    }
    $containers[] = $entry;
}
$out['containers'] = $containers;

$meta_rows = function ($id) use ($wpdb, $capture) {
    $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key NOT IN ('_edit_lock', '_edit_last') ORDER BY meta_id", $id), ARRAY_A);
    $out = [];
    foreach ($rows as $row) {
        $out[$row['meta_key']] = $capture->label($row['meta_value']);
    }
    return $out;
};
$option_rows = function () use ($wpdb, $capture) {
    $rows = $wpdb->get_results("SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE '\\_%' AND option_name NOT LIKE '\\_transient%' AND option_name NOT LIKE '\\_site\\_transient%' ORDER BY option_name", ARRAY_A);
    $out = [];
    foreach ($rows as $row) {
        $out[$row['option_name']] = ['value' => $capture->label($row['option_value']), 'autoload' => $row['autoload']];
    }
    return $out;
};
$read_post = function ($id) use ($post_fields, $capture) {
    $values = [];
    foreach ($post_fields as $name => $field) {
        $values[$name] = [
            'carbon_get_post_meta' => $capture->plain(carbon_get_post_meta($id, $name)),
            'get_alps_field' => $capture->plain(get_alps_field($name, $id)),
        ];
    }
    return $values;
};
$read_options = function () use ($theme_fields, $capture) {
    $values = [];
    foreach ($theme_fields as $name => $field) {
        $values[$name] = [
            'carbon_get_theme_option' => $capture->plain(carbon_get_theme_option($name)),
            'get_alps_option' => $capture->plain(get_alps_option($name)),
        ];
    }
    $values['crb_get_i18n_theme_option(footer_description)'] = crb_get_i18n_theme_option('footer_description');
    return $values;
};

// Before anything is saved: defaults, and the options the theme's own setup wrote.
$out['options_before'] = $option_rows();
$out['defaults'] = [
    'page' => $read_post($empty_page),
    'post' => $read_post($empty_post),
    'options' => $read_options(),
];

// Save a value in every field through Carbon Fields' own API.
$saved = ['page' => [], 'post' => [], 'options' => []];
foreach ($post_fields as $name => $field) {
    $value = $value_for($field);
    carbon_set_post_meta($page, $name, $value);
    carbon_set_post_meta($post, $name, $value);
    $saved['page'][$name] = $value;
}
foreach ($theme_fields as $name => $field) {
    $value = $value_for($field);
    carbon_set_theme_option($name, $value);
    $saved['options'][$name] = $value;
}
$out['saved_input'] = $capture->plain($saved);

$out['post_meta_rows'] = ['page_filled' => $meta_rows($page), 'post_filled' => $meta_rows($post), 'page_empty' => $meta_rows($empty_page)];
$after = $option_rows();
$out['option_rows_written'] = array_diff_key($after, $out['options_before']) + array_filter($after, function ($row, $key) use ($out) {
    return isset($out['options_before'][$key]) && $out['options_before'][$key] != $row;
}, ARRAY_FILTER_USE_BOTH);
$out['read_back'] = ['page' => $read_post($page), 'post' => $read_post($post), 'options' => $read_options()];

// Widgets: save one instance of each through the widget's own update().
$out['widgets'] = [];
foreach ($wp_widget_factory->widgets as $class => $widget) {
    if (! $widget instanceof \Carbon_Fields\Widget) {
        continue;
    }
    $input = [];
    $definitions = [];
    $fields = (new ReflectionProperty(\Carbon_Fields\Widget::class, 'custom_fields'))->getValue($widget);
    foreach ($fields as $field) {
        $definitions[] = $describe($field);
        $value = $value_for($field);
        if ($field->get_type() === 'select' && $value === '') {
            $value = (string) $capture->ids['term_capture'];
        }
        $input[$field->get_name()] = $value;
    }
    $instance = $widget->update($input, []);
    $error = '';
    ob_start();
    try {
        $widget->widget(['before_widget' => '', 'after_widget' => '', 'before_title' => '', 'after_title' => '', 'widget_id' => $widget->id_base . '-1'], $instance);
    } catch (Throwable $t) {
        // With WP_DEBUG, Acorn turns PHP warnings into exceptions.
        $error = $t->getMessage() . ' (' . basename($t->getFile()) . ':' . $t->getLine() . ')';
    }
    $html = ob_get_clean();
    $out['widgets'][$class] = [
        'id_base' => $widget->id_base,
        'option_name' => $widget->option_name,
        'fields' => $definitions,
        'input' => $capture->plain($input),
        'stored_instance' => $capture->plain($instance),
        'render_error' => $error,
        'rendered_html_length' => strlen($html),
        'rendered_html' => $capture->label(trim(preg_replace('/\s+/', ' ', $html))),
    ];
}

$out['ids'] = array_flip(array_map('strval', $capture->ids));
ksort($out['ids']);

$dir = get_template_directory() . '/tests/fields/fixtures';
wp_mkdir_p($dir);
$file = $dir . '/carbon-fields-' . $out['carbon_fields'] . '.json';
file_put_contents($file, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo "Wrote $file\n";
