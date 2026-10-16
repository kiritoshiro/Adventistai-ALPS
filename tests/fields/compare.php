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

if (! function_exists('carbon_get_post_meta')) {
    echo "ERROR Carbon Fields is not loaded: run composer install with dev dependencies (see tests/fields/legacy/boot.php)\n";
    return;
}

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
    if ($field instanceof Association_Field) {
        $d['types'] = array_map(function ($type) {
            return array_intersect_key($type, ['type' => 1, 'post_type' => 1, 'taxonomy' => 1]);
        }, $field->get_types());
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
    if (isset($d['types'])) {
        $out['types'] = $d['types'];
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
        // A legacy ID of a post (not a page): read as the field's first type.
        add_post_meta($id, '_related_custom_value|||6|value', (string) $post_target);
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

// 4. Store::setOption() writes the rows carbon_set_theme_option() writes.
$option_snapshot = function ($name) {
    global $wpdb;
    $rows = [];
    foreach ($wpdb->get_results($wpdb->prepare("SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name = %s OR option_name LIKE %s ORDER BY option_name", "_$name", $wpdb->esc_like("_$name|") . '%')) as $row) {
        $rows[$row->option_name] = [$row->option_value, $row->autoload];
    }
    return $rows;
};
$write_cases = function ($name, $field) use ($value_for, $images) {
    switch ($field['type']) {
        case 'checkbox':
            return ['true' => true, 'false' => false, 'value' => $field['value'], 'empty' => ''];
        case 'radio':
        case 'select':
            return ['last' => end($field['options']), 'first' => $field['options'][0], 'empty' => '', 'invalid' => 'bogus'];
        case 'image':
            return ['int' => $images[1], 'string' => (string) $images[2], 'empty' => ''];
        case 'complex':
            $group = (string) array_key_first($field['groups']);
            $subs = array_keys($field['groups'][$group]);
            $full = $value_for($name, $field);
            $typed = $group === '_' ? [] : ['_type' => $group];
            return [
                'none' => [],
                'one' => [$full[0]],
                'two' => $full,
                'partial' => [$typed + [$subs[0] => 'Only the first']],
                'unknown-group' => [['_type' => 'nope', $subs[0] => 'x']],
            ];
        default:
            return ['text' => "Line one $name\nLine <b>two</b>", 'empty' => ''];
    }
};
foreach (Registry::options() as $name => $field) {
    foreach ($write_cases($name, $field) as $case => $value) {
        foreach ([false, true] as $stray) {
            $results = [];
            foreach (['carbon', 'store'] as $writer) {
                // Start from a saved value (plus a stray piped row), then overwrite it.
                carbon_set_theme_option($name, $value_for($name, $field));
                if ($stray) {
                    add_option("_$name|||7|value", 'stray', '', false);
                }
                if ($writer === 'carbon') {
                    carbon_set_theme_option($name, $value);
                } else {
                    Store::setOption($name, $value);
                }
                $results[$writer] = $option_snapshot($name);
                foreach (array_keys($results[$writer]) as $option) {
                    delete_option($option);
                }
            }
            $same("setOption[$name:$case" . ($stray ? '+stray' : '') . ']', $results['carbon'], $results['store']);
        }
    }
}
$same('setOption unknown field', false, Store::setOption('no_such_option', 'x'));

// An older theme version stored footer_description as one plain option (as on
// the live site). The field ignores it and the footer falls back to it.
// Saving the field: Carbon Fields leaves it, Store::setOption() deletes it
// (one known difference: a row neither reads), so removing the entry later
// shows the default, not the old text again.
$same('legacy footer description: none', '', Store::legacyFooterDescription());
$results_by_writer = [];
foreach (['carbon', 'store'] as $writer) {
    add_option('_footer_description', " Older footer text \n", '', false);
    $same("legacy footer description: field ignores it [$writer]", [], $writer === 'carbon' ? carbon_get_theme_option('footer_description') : Store::option('footer_description'));
    $same("legacy footer description: read [$writer]", 'Older footer text', Store::legacyFooterDescription());
    $rows = [['_type' => 'translatable_footer_description', 'footer_description_en' => 'Older footer text']];
    if ($writer === 'carbon') {
        carbon_set_theme_option('footer_description', $rows);
    } else {
        Store::setOption('footer_description', $rows);
    }
    $snapshot = $option_snapshot('footer_description');
    $same("legacy footer description: plain option after saving [$writer]", $writer === 'carbon', isset($snapshot['_footer_description']));
    $same("legacy footer description: saved as the English entry [$writer]", 'Older footer text', Store::option('footer_description')[0]['footer_description_en'] ?? null);
    unset($snapshot['_footer_description']);
    $results_by_writer[$writer] = $snapshot;
    foreach (array_keys($option_snapshot('footer_description')) as $option) {
        delete_option($option);
    }
}
$same('legacy footer description: same rows otherwise', $results_by_writer['carbon'], $results_by_writer['store']);

// 5. The settings page turns submitted values into what Store::setOption() takes.
$page_cases = [
    'dark_theme' => [['true', 'true'], ['', ''], [null, ''], ['on', '']],
    'theme_color' => [['forest', 'forest'], ['bogus', ''], [['x'], '']],
    'logo' => [[(string) $images[0], (string) $images[0]], ['12abc', '12'], ['', ''], ['0', '']],
    'footer_copyright' => [['Text <b>bold</b>', 'Text <b>bold</b>']],
    // Ticked by default; the unticked box submits '' and is stored as off.
    'alps_image_keep_originals' => [['true', 'true'], ['', ''], [null, '']],
    'alps_image_original_days' => [['10', '10'], ['', '']],
];
$same('original retention reads the stored keep setting', true, \App\OriginalImageRetention::keeps() === Store::option('alps_image_keep_originals'));
foreach ($page_cases as $name => $cases) {
    foreach ($cases as $i => $case) {
        $same("SettingsPage::sanitize($name #$i)", $case[1], \App\Fields\SettingsPage::sanitize(Registry::field('option', $name), $case[0]));
    }
}
$same('SettingsPage::sanitize(footer_description)', [
    ['_type' => 'translatable_footer_description', 'footer_description_en' => 'EN', 'footer_description_de' => '', 'footer_description_fr' => '', 'footer_description_pt-pt' => '', 'footer_description_es' => '', 'footer_description_ko' => '', 'footer_description_ru' => '', 'footer_description_zh-hant' => ''],
], \App\Fields\SettingsPage::sanitize(Registry::field('option', 'footer_description'), [
    5 => ['_type' => 'translatable_footer_description', 'footer_description_en' => 'EN', 'junk' => 'x'],
    6 => ['_type' => 'unknown', 'footer_description_en' => 'dropped'],
    7 => 'not a row',
]));

// 6. Store::setPost() writes the rows carbon_set_post_meta() writes.
$meta_snapshot = function ($id, $name = null) {
    global $wpdb;
    $rows = [];
    foreach ($wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_key, meta_id", $id)) as $row) {
        if ($name === null ? strpos($row->meta_key, '_edit_') !== 0 && strpos($row->meta_key, '_wp_') !== 0 : ($row->meta_key === "_$name" || strpos($row->meta_key, "_$name|") === 0)) {
            $rows[] = [$row->meta_key, $row->meta_value];
        }
    }
    return $rows;
};
$post_write_cases = function ($name, $field) use ($write_cases, $value_for, $page_target, $post_target) {
    if ($field['type'] === 'association') {
        return [
            'none' => [],
            'two' => $value_for($name, $field),
            'strings' => ["post:page:$page_target", "post:post:$post_target"],
            'legacy-id' => [(string) $post_target],
            'blank' => ['  '],
        ];
    }
    return $write_cases($name, $field);
};
$writer_page = $new_post('page', 'Writer page');
foreach ($registry_post as $name => $field) {
    foreach ($post_write_cases($name, $field) as $case => $value) {
        foreach ([false, true] as $stray) {
            $results = [];
            foreach (['carbon', 'store'] as $writer) {
                carbon_set_post_meta($writer_page, $name, $value_for($name, $field));
                if ($stray) {
                    add_post_meta($writer_page, "_$name|||7|value", 'stray');
                }
                if ($writer === 'carbon') {
                    carbon_set_post_meta($writer_page, $name, $value);
                } else {
                    \App\Fields\Store::setPost($writer_page, $name, $value);
                }
                $results[$writer] = $meta_snapshot($writer_page, $name);
                foreach ($results[$writer] as $row) {
                    delete_post_meta($writer_page, $row[0]);
                }
            }
            $same("setPost[$name:$case" . ($stray ? '+stray' : '') . ']', $results['carbon'], $results['store']);
        }
    }
}
$same('setPost unknown field', false, \App\Fields\Store::setPost($writer_page, 'no_such_field', 'x'));
$same('setPost post 0', false, \App\Fields\Store::setPost(0, 'kicker', 'x'));

// 7. The block editor's REST field.
use App\Fields\PostPanels;

$admin = get_users(['role' => 'administrator', 'number' => 1])[0];
$rest = function ($method, $route, $body = null, $user = null) use ($admin) {
    wp_set_current_user($user ? $user : $admin->ID);
    $request = new WP_REST_Request($method, $route);
    if ($method === 'GET') {
        $request->set_param('context', 'edit');
    }
    if ($body !== null) {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($body));
    }
    $response = rest_do_request($request);
    wp_set_current_user(0);
    return $response;
};
// The field as the editor receives it (JSON).
$alps = function ($response) {
    return json_decode(wp_json_encode($response->get_data()['alps_fields']), true);
};
$same('panel post types include post and page', true, in_array('post', PostPanels::blockEditorTypes(), true) && in_array('page', PostPanels::blockEditorTypes(), true));
$same('attachments have no panels', false, in_array('attachment', PostPanels::blockEditorTypes(), true));

$filled = $new_post('page', 'REST filled page');
foreach ($registry_post as $name => $field) {
    carbon_set_post_meta($filled, $name, $value_for($name, $field));
}
$got = $rest('GET', "/wp/v2/pages/$filled");
$same('REST GET status', 200, $got->get_status());
$fields = $alps($got);
$same('REST GET = PostPanels::values', PostPanels::values(get_post($filled), true), $fields);
$same('REST GET hero_carousel rows', 2, count($fields['hero_carousel'] ?? []));
$same('REST view context hides the field', false, array_key_exists('alps_fields', rest_do_request(new WP_REST_Request('GET', "/wp/v2/pages/$filled"))->get_data()));

// Sending everything back unchanged writes nothing; one change writes that field only.
$before = $meta_snapshot($filled);
$rest('POST', "/wp/v2/pages/$filled", ['alps_fields' => $fields]);
$same('REST unchanged save writes nothing', $before, $meta_snapshot($filled));
$changed = $fields;
$changed['hero_kicker'] = 'Kicker from REST';
$changed['hero_carousel'][0]['slide_heading'] = 'Heading from REST';
$changed['related_custom_value'] = array_reverse($fields['related_custom_value']);
$rest('POST', "/wp/v2/pages/$filled", ['alps_fields' => $changed]);
$after = $meta_snapshot($filled);
$expected = $filled_expected = $new_post('page', 'Expected rows');
foreach ($registry_post as $name => $field) {
    carbon_set_post_meta($expected, $name, $value_for($name, $field));
}
carbon_set_post_meta($expected, 'hero_kicker', 'Kicker from REST');
$carousel = carbon_get_post_meta($expected, 'hero_carousel');
$carousel[0]['slide_heading'] = 'Heading from REST';
carbon_set_post_meta($expected, 'hero_carousel', $carousel);
carbon_set_post_meta($expected, 'related_custom_value', array_reverse($value_for('related_custom_value', $registry_post['related_custom_value'])));
$same('REST save writes what Carbon Fields would', $meta_snapshot($expected), $after);
$same('REST save read back', 'Kicker from REST', \App\Fields\Store::post($filled, 'hero_kicker'));

// Cleaning: unknown keys, bad choices, other post types, extra rows, script for authors.
$author = wp_insert_user(['user_login' => 'fields-author', 'user_pass' => wp_generate_password(), 'role' => 'author', 'user_email' => 'fields-author@example.test']);
$author_post = wp_insert_post(['post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Author post', 'post_author' => $author]);
$rest('POST', "/wp/v2/posts/$author_post", ['alps_fields' => [
    'kicker' => 'Safe <b>bold</b><script>alert(1)</script>',
    'head_type' => 'bogus',
    'related' => 'related_all',
    'no_such_field' => 'x',
    'hero_carousel' => array_fill(0, 12, ['slide_heading' => 'Slide']),
]], $author);
$same('author text is cleaned', 'Safe <b>bold</b>alert(1)', \App\Fields\Store::post($author_post, 'kicker'));
$same('bad choice saves the first choice', 'default', \App\Fields\Store::post($author_post, 'head_type'));
$same('page-only field ignored on posts', [], $meta_snapshot($author_post, 'related'));
$same('carousel capped at 9 rows', 9, count(\App\Fields\Store::post($author_post, 'hero_carousel')));
$same('unknown key ignored', [], $meta_snapshot($author_post, 'no_such_field'));
$assoc = PostPanels::sanitize('related_custom_value', $registry_post['related_custom_value'], [
    ['id' => $page_target], ['id' => $images[0]], ['id' => 999999], ['id' => $post_target, 'subtype' => 'page'], 'junk',
]);
$same('association keeps only existing pages and posts', [['type' => 'post', 'subtype' => 'page', 'id' => $page_target], ['type' => 'post', 'subtype' => 'post', 'id' => $post_target]], $assoc);

// Someone who cannot edit the post changes nothing.
$subscriber = wp_insert_user(['user_login' => 'fields-subscriber', 'user_pass' => wp_generate_password(), 'role' => 'subscriber', 'user_email' => 'fields-subscriber@example.test']);
$denied = $rest('POST', "/wp/v2/pages/$filled", ['alps_fields' => ['hero_kicker' => 'From subscriber']], $subscriber);
$same('subscriber update refused', true, $denied->get_status() >= 400);
$same('subscriber changed nothing', 'Kicker from REST', \App\Fields\Store::post($filled, 'hero_kicker'));

// The edit-screen defaults: shown for new entries, stored on the first save.
foreach ([['page', 'page-header'], ['post', 'header-block-featured']] as $case) {
    list($type, $default) = $case;
    $draft = wp_insert_post(['post_type' => $type, 'post_status' => 'auto-draft', 'post_title' => "New $type"]);
    $shown = $alps($rest('GET', '/wp/v2/' . ($type === 'page' ? 'pages' : 'posts') . "/$draft"))['featured_image_hero_layout'];
    $same("new $type shows the $default banner", $default, $shown);
    $rest('POST', '/wp/v2/' . ($type === 'page' ? 'pages' : 'posts') . "/$draft", ['title' => "Saved $type", 'status' => 'draft']);
    $same("first save of a new $type stores it", [['_featured_image_hero_layout', $default]], $meta_snapshot($draft, 'featured_image_hero_layout'));
}
$old_page = $new_post('page', 'Old page');
$rest('POST', "/wp/v2/pages/$old_page", ['title' => 'Old page saved']);
$same('saving an existing page stores no banner default', [], $meta_snapshot($old_page, 'featured_image_hero_layout'));
$grid_page = $new_post('page', 'Grid page');
add_post_meta($grid_page, '_related_grid', 'true');
$same('grid page shows the landscape crop', 'landscape', $alps($rest('GET', "/wp/v2/pages/$grid_page"))['related_image_crop']);
$rest('POST', "/wp/v2/pages/$grid_page", ['title' => 'Grid page saved']);
$same('saving a grid page stores the landscape crop', [['_related_image_crop', 'landscape']], $meta_snapshot($grid_page, 'related_image_crop'));
$legacy_post = $new_post('post', 'Legacy layout post');
add_post_meta($legacy_post, '_featured_image_hero_layout', 'hero_layout_1_3');
// A stored old layout value is a stored value: no default, read as 'false' (as in Carbon Fields).
$same('old hero layout reads as no banner', 'false', $alps($rest('GET', "/wp/v2/posts/$legacy_post"))['featured_image_hero_layout']);
$rest('POST', "/wp/v2/posts/$legacy_post", ['alps_fields' => $alps($rest('GET', "/wp/v2/posts/$legacy_post"))]);
$same('saving the panels keeps it', 'false', \App\Fields\Store::post($legacy_post, 'featured_image_hero_layout'));
$same('Carbon Fields reads it the same', @carbon_get_post_meta($legacy_post, 'featured_image_hero_layout'), \App\Fields\Store::post($legacy_post, 'featured_image_hero_layout'));

// 8. Native widgets = the Carbon Fields widgets they replaced (frozen in legacy/cf-widget.php).
require_once get_template_directory() . '/tests/fields/legacy/cf-widget.php';
// Capture what each template would get instead of rendering it (the
// templates are include_once'd, so they can only run once per request).
class Compare_CF_Author extends ALPS_Author_Box_Widget { public $captured; public function front_end($args, $settings) { $this->captured = $settings; } }
class Compare_CF_Text extends ALPS_Text_With_Link_Widget { public $captured; public function front_end($args, $settings) { $this->captured = $settings; } }
class Compare_CF_Feed extends ALPS_Post_Feed_Widget { public $captured; public function front_end($args, $settings) { $this->captured = $settings; } }
class Compare_CF_Related extends ALPS_Related_Stories_Widget { public $captured; public function front_end($args, $settings) { $this->captured = $settings; } }
class Compare_Native_Author extends \App\Widgets\AuthorBox { public $captured; protected function render($args, $settings) { $this->captured = $settings; } }
class Compare_Native_Text extends \App\Widgets\TextWithLink { public $captured; protected function render($args, $settings) { $this->captured = $settings; } }
class Compare_Native_Feed extends \App\Widgets\PostFeed { public $captured; protected function render($args, $settings) { $this->captured = $settings; } }
class Compare_Native_Related extends \App\Widgets\RelatedStories { public $captured; protected function render($args, $settings) { $this->captured = $settings; } }

foreach (['Widget cat A', 'Widget cat B'] as $cat_name) {
    wp_insert_term($cat_name, 'category');
}
$cat_ids = array_map('strval', get_categories(['orderby' => 'name', 'hide_empty' => false, 'fields' => 'ids']));
$widget_args = ['before_widget' => '<section class="%2$s">', 'after_widget' => '</section>', 'before_title' => '', 'after_title' => ''];
wp_set_current_user($admin->ID);
foreach ([
    'author' => [new Compare_CF_Author(), new Compare_Native_Author()],
    'text' => [new Compare_CF_Text(), new Compare_Native_Text()],
    'feed' => [new Compare_CF_Feed(), new Compare_Native_Feed()],
    'related' => [new Compare_CF_Related(), new Compare_Native_Related()],
] as $kind => $pair) {
    list($cf, $native) = $pair;
    $same("widget[$kind] id_base", $cf->id_base, $native->id_base);
    $same("widget[$kind] option name", $cf->option_name, $native->option_name);
    $same("widget[$kind] classname", $cf->widget_options['classname'], $native->widget_options['classname']);
    $same("widget[$kind] title", $cf->name, $native->name);
    $definitions = $native->definitions();
    $filled = $blank = $odd = $input = $odd_input = [];
    foreach ($definitions as $name => $field) {
        $filled["_$name"] = $field['type'] === 'checkbox' ? 'true' : ($field['type'] === 'select' ? end($cat_ids) : "Text $name\nline <b>2</b>");
        $blank["_$name"] = '';
        $odd["_$name"] = $field['type'] === 'checkbox' ? 'TRUE' : ($field['type'] === 'select' ? 'bogus' : ' spaced ');
        $input[$name] = $field['type'] === 'checkbox' ? 'true' : ($field['type'] === 'select' ? reset($cat_ids) : "Input $name <i>x</i>");
        $odd_input[$name] = $field['type'] === 'checkbox' ? 'on' : ($field['type'] === 'select' ? 'bogus' : '');
    }
    $instances = [
        'filled' => $filled,
        'empty' => [],
        'blank' => $blank,
        'odd' => $odd,
        'extra' => $filled + ['title' => 'x', '_unrelated' => 'y', '_' . array_key_first($definitions) . '|||0|value' => 'piped'],
    ];
    foreach ($instances as $case => $instance) {
        $outputs = [];
        foreach (['cf' => $cf, 'native' => $native] as $which => $widget) {
            $widget->captured = null;
            ob_start();
            $widget->widget($widget_args, $instance);
            $outputs[$which] = [ob_get_clean(), $widget->captured];
        }
        $same("widget[$kind:$case] template values and wrapper", $outputs['cf'], $outputs['native']);
    }
    $inputs = ['full' => $input, 'odd' => $odd_input, 'missing' => [], 'one' => [array_key_first($definitions) => 'Only one']];
    foreach ($inputs as $in_case => $new) {
        foreach (['empty' => [], 'filled' => $instances['extra']] as $old_case => $old) {
            $same("widget[$kind] update($in_case, $old_case)", $cf->update($new, $old), $native->update($new, $old));
        }
    }
    ob_start();
    $native->form($filled);
    $form = ob_get_clean();
    $same("widget[$kind] form shows the stored values", true, strpos($form, 'name="' . $native->get_field_name(array_key_first($definitions)) . '"') !== false
        && (strpos($form, esc_attr("Text " . array_key_first($definitions))) !== false || strpos($form, 'selected') !== false || strpos($form, 'checked') !== false));
}
wp_set_current_user(0);
global $wp_widget_factory;
$registered = array_map(function ($widget) {
    return get_class($widget) . '=' . $widget->id_base;
}, array_values(array_filter($wp_widget_factory->widgets, function ($widget) {
    return strpos($widget->id_base, 'carbon_fields_') === 0;
})));
sort($registered);
$same('registered theme widgets', [
    'App\\Widgets\\AuthorBox=carbon_fields_alps_widget_author',
    'App\\Widgets\\PostFeed=carbon_fields_alps_widget_post_feed',
    'App\\Widgets\\RelatedStories=carbon_fields_alps_widget_related_storied',
    'App\\Widgets\\TextWithLink=carbon_fields_alps_widget_text_with_link',
], $registered);

echo 'PHP ' . PHP_VERSION . ', WordPress ' . get_bloginfo('version') . ', Carbon Fields ' . \Carbon_Fields\VERSION . "\n";
echo "compare: $checks checks, " . count($failures) . " failures\n";
foreach ($failures as $failure) {
    echo "FAIL $failure\n";
}
