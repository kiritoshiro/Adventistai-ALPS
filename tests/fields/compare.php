<?php
/**
 * Checks that App\Fields\Store reads every theme field exactly as Carbon
 * Fields does, and that App\Fields\Registry matches Carbon Fields' own field
 * definitions.
 *
 * Runs inside a throwaway WordPress with this theme active (WordPress
 * Playground: `python3 tests/fields/run.py compare`), never on a real site:
 * it creates posts and overwrites theme options. For each stored state below
 * (saved through Carbon Fields, empty, blank, invalid, hand-written edge
 * rows) every field is read both ways and compared with ===, as are the old
 * and new get_alps_field(), get_alps_option() and crb_get_i18n_theme_option().
 * Prints one line per difference and "compare: N checks, M failures".
 */

if (! defined('ABSPATH')) {
    exit;
}

use App\Fields\Registry;
use App\Fields\Store;
use Carbon_Fields\Carbon_Fields;
use Carbon_Fields\Field\Association_Field;
use Carbon_Fields\Field\Checkbox_Field;
use Carbon_Fields\Field\Complex_Field;

$checks = 0;
$failures = [];
$same = function ($label, $expected, $actual) use (&$checks, &$failures) {
    $checks++;
    if ($expected !== $actual) {
        $failures[] = $label . "\n    carbon: " . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n    store:  " . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
};

// The helpers as they were before Store (functions.php at 7044340).
$old_field = function ($field, $id) {
    $field_data = @carbon_get_post_meta($id, $field);
    if (! empty($field_data)) {
        if (is_array($field_data)) {
            return count($field_data) === 1 ? $field_data[0] : $field_data;
        }
    } else {
        return $field_data;
    }
};
$old_option = function ($field) {
    $option = @carbon_get_theme_option($field);
    if (is_array($option)) {
        return count($option) == 1 ? $option[0] : $option;
    }
    return $option;
};
$old_i18n = function ($name) use ($old_option) {
    $suffix = defined('ICL_LANGUAGE_CODE') ? '_' . ICL_LANGUAGE_CODE : '';
    return isset($old_option($name)[$name . $suffix]) ? $old_option($name)[$name . $suffix] : '';
};

// 1. Registry = Carbon Fields' definitions.
$describe = function ($field) use (&$describe) {
    $d = ['type' => $field->get_type()];
    $default = $field->get_default_value();
    if ($field instanceof Checkbox_Field) {
        $d['value'] = $field->get_option_value();
        if (is_bool($default)) {
            $default = $default ? $field->get_option_value() : '';
        }
    }
    if (in_array($field->get_type(), ['radio', 'select'], true)) {
        $d['options'] = array_map('strval', array_keys((array) $field->get_options()));
    }
    if (! $field instanceof Complex_Field && ! $field instanceof Association_Field) {
        $d['default'] = $default;
    }
    if ($field instanceof Complex_Field) {
        $d['groups'] = [];
        foreach ($field->get_group_names() as $group) {
            foreach ($field->get_group_by_name($group)->get_fields() as $sub) {
                $d['groups'][$group][$sub->get_base_name()] = $describe($sub);
            }
        }
    }
    return $d;
};
$normalise = function ($d) use (&$normalise) {
    $out = ['type' => $d['type']];
    if ($d['type'] === 'checkbox') {
        $out['value'] = $d['value'];
    }
    if (isset($d['options'])) {
        $out['options'] = $d['options'];
    }
    if (! in_array($d['type'], ['complex', 'association'], true)) {
        $default = array_key_exists('default', $d) ? $d['default'] : '';
        if ($d['type'] === 'checkbox' && is_bool($default)) {
            $default = $default ? $d['value'] : '';
        }
        $out['default'] = $default;
    }
    if (isset($d['groups'])) {
        foreach ($d['groups'] as $group => $subs) {
            foreach ($subs as $name => $sub) {
                $out['groups'][$group][$name] = $normalise($sub);
            }
        }
    }
    return $out;
};
$cf_post = [];
$cf_options = [];
$registry_post = Registry::post();
foreach (Carbon_Fields::resolve('container_repository')->get_containers() as $container) {
    $fields = array_values(array_filter($container->get_fields(), function ($f) {
        return ! in_array($f->get_type(), ['separator', 'html'], true);
    }));
    if ($container->type === 'theme_options') {
        foreach ($fields as $f) {
            $cf_options[$f->get_base_name()] = $describe($f);
        }
    } elseif ($container->type === 'post_meta') {
        $names = array_map(function ($f) {
            return $f->get_base_name();
        }, $fields);
        // A box is either fully in the registry (live) or not at all (dead).
        $listed = array_intersect($names, array_keys($registry_post));
        $same("registry lists all or none of box '{$container->title}'", $listed ? $names : [], array_values($listed));
        if ($listed) {
            foreach ($fields as $f) {
                $cf_post[$f->get_base_name()] = $describe($f);
            }
        }
    }
}
$same('registry post field names', array_keys($cf_post), array_keys($registry_post));
$same('registry option field names', array_keys($cf_options), array_keys(Registry::options()));
foreach ($cf_post as $name => $d) {
    $same("definition post:$name", $normalise($d), isset($registry_post[$name]) ? $normalise($registry_post[$name]) : null);
}
foreach ($cf_options as $name => $d) {
    $option_field = Registry::field('option', $name);
    $same("definition option:$name", $normalise($d), $option_field ? $normalise($option_field) : null);
}

// 2. Objects the values point at.
require_once ABSPATH . 'wp-admin/includes/image.php';
$new_post = function ($type, $title) {
    return wp_insert_post(['post_type' => $type, 'post_status' => 'publish', 'post_title' => $title]);
};
$images = [];
foreach ([[200, 40, 40], [40, 160, 60], [40, 60, 200]] as $i => $rgb) {
    $file = wp_upload_dir()['basedir'] . "/fields-compare-$i.jpg";
    wp_mkdir_p(dirname($file));
    $gd = imagecreatetruecolor(80, 50);
    imagefill($gd, 0, 0, imagecolorallocate($gd, $rgb[0], $rgb[1], $rgb[2]));
    imagejpeg($gd, $file, 80);
    $images[] = wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => "Compare $i", 'post_status' => 'inherit'], $file);
}
$page_target = $new_post('page', 'Target page');
$post_target = $new_post('post', 'Target post');

$value_for = function ($name, $field, $row = 0) use (&$value_for, $images, $page_target, $post_target) {
    switch ($field['type']) {
        case 'checkbox':
            return $field['value'];
        case 'image':
            return $images[$row % 3];
        case 'radio':
        case 'select':
            return end($field['options']);
        case 'association':
            return [['type' => 'post', 'subtype' => 'page', 'id' => $page_target], ['type' => 'post', 'subtype' => 'post', 'id' => $post_target]];
        case 'complex':
            $rows = [];
            foreach ($field['groups'] as $group => $subs) {
                for ($i = 0; $i < 2; $i++) {
                    $values = $group === '_' ? [] : ['_type' => $group];
                    foreach ($subs as $sub => $sub_field) {
                        $values[$sub] = $value_for($sub, $sub_field, $i);
                    }
                    $rows[] = $values;
                }
            }
            return $rows;
        case 'textarea':
            return "Line one $name\nLine two";
        default:
            return "Text $name" . ($row ? " row $row" : '');
    }
};

// Rows written by hand: edge cases Carbon Fields' own setters never produce.
$raw_post_states = [
    'blank' => function ($id) use ($registry_post) {
        foreach ($registry_post as $name => $field) {
            if (! in_array($field['type'], ['complex', 'association'], true)) {
                add_post_meta($id, "_$name", '');
            }
        }
        add_post_meta($id, '_hero_column|||0|_empty', '');
        add_post_meta($id, '_related_custom_value|||0|_empty', '');
    },
    'invalid' => function ($id) use ($registry_post) {
        $odd = ['false', '1', 'TRUE', ' true', 'on', 'yes', '0'];
        $i = 0;
        foreach ($registry_post as $name => $field) {
            if ($field['type'] === 'checkbox') {
                add_post_meta($id, "_$name", $odd[$i++ % count($odd)]);
            } elseif (in_array($field['type'], ['radio', 'select'], true)) {
                add_post_meta($id, "_$name", 'bogus');
            }
        }
        add_post_meta($id, '_hero_overlay', 'OFF');
        add_post_meta($id, '_kicker', 'first');
        add_post_meta($id, '_kicker', 'second');
    },
    'complex-edge' => function ($id) use ($images) {
        // Gap (rows 0, 2), rows 10 and 11 (sort order), a row without sub-fields.
        foreach ([0, 2, 10, 11, 3] as $row) {
            add_post_meta($id, "_hero_carousel|||$row|value", '_');
        }
        foreach ([0, 2, 10, 11] as $row) {
            add_post_meta($id, "_hero_carousel|slide_image|$row|0|value", (string) $images[$row % 3]);
            add_post_meta($id, "_hero_carousel|slide_heading|$row|0|value", "Heading $row");
        }
        // A sub-field row without its group row, and an unknown group.
        add_post_meta($id, '_hero_column|hero_title_column|0|0|value', 'Orphan title');
        add_post_meta($id, '_hero_column|||1|value', 'unknown_group');
        add_post_meta($id, '_hero_column|hero_title_column|1|0|value', 'Unknown group title');
        // Malformed and multi-value keys on simple fields.
        add_post_meta($id, '_hero_title|||1|value', 'Second value');
        add_post_meta($id, '_kicker|x', 'Malformed');
        add_post_meta($id, '_page_sub_title|||0|value', 'Only a piped row');
    },
    'association-edge' => function ($id) use ($page_target, $post_target) {
        add_post_meta($id, '_related_custom_value|||0|type', 'post');
        add_post_meta($id, '_related_custom_value|||0|id', (string) $page_target);
        add_post_meta($id, '_related_custom_value|||1|value', "post:post:$post_target");
        add_post_meta($id, '_related_custom_value|||2|value', (string) $page_target);
        add_post_meta($id, '_related_custom_value|||3|value', '  ');
        add_post_meta($id, '_related_custom_value|||10|type', 'term');
        add_post_meta($id, '_related_custom_value|||10|subtype', 'category');
        add_post_meta($id, '_related_custom_value|||10|id', '1');
        add_post_meta($id, '_related_custom_value|||10|value', '');
        // Rows 5 then 4: the result follows the row numbers, not the table order.
        foreach ([5 => $post_target, 4 => $page_target] as $row => $target) {
            add_post_meta($id, "_related_custom_value|||$row|type", 'post');
            add_post_meta($id, "_related_custom_value|||$row|subtype", get_post_type($target));
            add_post_meta($id, "_related_custom_value|||$row|id", (string) $target);
        }
    },
    'complex-order' => function ($id) {
        foreach ([1, 0] as $row) {
            add_post_meta($id, "_hero_column|||$row|value", '_');
            add_post_meta($id, "_hero_column|hero_title_column|$row|0|value", "Column $row");
        }
    },
];

$compare_post = function ($state, $id) use ($same, $old_field, $registry_post) {
    foreach (array_merge(array_keys($registry_post), ['no_such_field']) as $name) {
        $same("post[$state]:$name", @carbon_get_post_meta($id, $name), Store::post($id, $name));
        $same("get_alps_field[$state]:$name", $old_field($name, $id), get_alps_field($name, $id));
    }
    // Fields of the dead boxes are unknown to Store (no template reads them).
    $same("post[$state]:post_feed_list_title (dead box)", null, Store::post($id, 'post_feed_list_title'));
};

foreach (['page', 'post'] as $type) {
    $compare_post("$type-empty", $new_post($type, "Empty $type"));

    $id = $new_post($type, "Filled $type");
    foreach ($registry_post as $name => $field) {
        carbon_set_post_meta($id, $name, $value_for($name, $field));
    }
    $compare_post("$type-filled", $id);

    foreach ($raw_post_states as $state => $write) {
        $id = $new_post($type, "$state $type");
        $write($id);
        $compare_post("$type-$state", $id);
    }
}
// No post at all (get_the_ID() false outside the loop).
$same('thePost outside the loop', carbon_get_the_post_meta('hero_overlay'), Store::thePost('hero_overlay'));
$same('post 0', carbon_get_post_meta(0, 'head_type'), Store::post(0, 'head_type'));

// 3. Theme options, one state after another.
$compare_options = function ($state) use ($same, $old_option, $old_i18n) {
    foreach (array_merge(array_keys(Registry::options()), ['no_such_option', 'logo_lt', 'project_alps_languages_hide_selector']) as $name) {
        $same("option[$state]:$name", @carbon_get_theme_option($name), Store::option($name));
        $same("get_alps_option[$state]:$name", $old_option($name), get_alps_option($name));
    }
    $same("crb_get_i18n_theme_option[$state]", $old_i18n('footer_description'), crb_get_i18n_theme_option('footer_description'));
};
$delete_all = function () {
    global $wpdb;
    foreach (array_keys(Registry::options()) as $name) {
        foreach ($wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name = %s OR option_name LIKE %s", "_$name", $wpdb->esc_like("_$name|") . '%')) as $option) {
            delete_option($option);
        }
    }
};

$compare_options('theme-setup');

foreach (Registry::options() as $name => $field) {
    carbon_set_theme_option($name, $value_for($name, $field));
}
$compare_options('filled');

$delete_all();
$compare_options('nothing-stored');

foreach (Registry::options() as $name => $field) {
    if (! in_array($field['type'], ['complex', 'association'], true)) {
        update_option("_$name", '', false);
    }
}
add_option('_footer_address|||0|_empty', '', '', false);
$compare_options('blank');

$delete_all();
foreach (Registry::options() as $name => $field) {
    if ($field['type'] === 'checkbox') {
        update_option("_$name", 'yes', false);
    } elseif (in_array($field['type'], ['radio', 'select'], true)) {
        update_option("_$name", 'Campfire', false);
    }
}
// footer_description rows 1 and 0 (written out of order), row 1 partly
// filled so its other languages fall back to their defaults.
add_option('_footer_description|||1|value', 'translatable_footer_description', '', false);
add_option('_footer_description|footer_description_en|1|0|value', 'Second row EN', '', false);
add_option('_footer_description|||0|value', 'translatable_footer_description', '', false);
add_option('_footer_description|footer_description_de|0|0|value', 'Erste Zeile', '', false);
add_option('_footer_address|||0|value', '_', '', false);
add_option('_footer_address|footer_address_city|0|0|value', 'Kaunas', '', false);
$compare_options('invalid-and-partial');

// Written straight to the table: Store must be flushed like any cache.
global $wpdb;
$wpdb->insert($wpdb->options, ['option_name' => '_footer_copyright', 'option_value' => 'Direct insert', 'autoload' => 'off']);
Store::flush();
$compare_options('direct-insert');

echo 'PHP ' . PHP_VERSION . ', WordPress ' . get_bloginfo('version') . ', Carbon Fields ' . \Carbon_Fields\VERSION . "\n";
echo "compare: $checks checks, " . count($failures) . " failures\n";
foreach ($failures as $failure) {
    echo "FAIL $failure\n";
}
