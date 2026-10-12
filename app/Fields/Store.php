<?php

namespace App\Fields;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Reads the theme's fields without Carbon Fields, returning exactly what
 * carbon_get_post_meta() / carbon_get_theme_option() 3.6.11 return for the
 * same rows (tests/fields/run.php compares the two):
 *
 * - a field with no stored row returns its default;
 * - checkboxes return true when the stored value equals the ticked value;
 * - radios and selects return the stored choice, or the first choice when the
 *   stored value is not one of them;
 * - images return the attachment ID as stored (a string), other simple fields
 *   the stored string;
 * - complex fields return a list of rows, each `_type` (group name) first and
 *   then its sub-fields in definition order;
 * - associations return [{value, type, subtype, id}];
 * - unknown fields, and any field before `init`, return null.
 *
 * Storage (Carbon Fields' own): a simple field is the row `_<name>`; complex
 * and association values are rows `_<name>|<sub>|<row>|<index>|<property>`.
 * Post values come from the post's meta cache, options from one query per
 * request (reset whenever an `_`-prefixed option changes).
 */
class Store
{
    const KEEPALIVE = '_empty';

    /** Option rows of the theme's fields, by blog ID. */
    private static $optionRows = [];

    /** A post field of post $id. */
    public static function post($id, $name)
    {
        $field = did_action('init') ? Registry::field('post', $name) : null;
        if (! $field) {
            return null;
        }
        return self::read($field, $name, self::postRows((int) $id, $name));
    }

    /** A post field of the current post in the loop (carbon_get_the_post_meta). */
    public static function thePost($name)
    {
        return self::post(get_the_ID(), $name);
    }

    /** A theme option. */
    public static function option($name)
    {
        $field = did_action('init') ? Registry::field('option', $name) : null;
        if (! $field) {
            return null;
        }
        $rows = [];
        foreach (self::optionRows() as $key => $value) {
            if (self::belongsTo($key, $name)) {
                $rows[$key] = $value;
            }
        }
        return self::read($field, $name, $rows);
    }

    /**
     * Save a theme option, writing the same rows carbon_set_theme_option()
     * writes (tests/fields/compare.php checks this). See rows(). Rows are not
     * autoloaded. Returns false for unknown fields.
     */
    public static function setOption($name, $value)
    {
        $field = Registry::field('option', $name);
        $rows = $field ? self::rows($field, $name, $value) : null;
        if ($rows === null) {
            return false;
        }
        foreach (array_keys(self::storedOptionRows($name)) as $option) {
            delete_option($option);
        }
        foreach ($rows as $option => $stored) {
            add_option($option, $stored, '', false);
        }
        return true;
    }

    /**
     * Save a post field, writing the same rows carbon_set_post_meta() writes
     * (tests/fields/compare.php checks this). See rows(). Returns false for
     * unknown fields.
     */
    public static function setPost($id, $name, $value)
    {
        $id = (int) $id;
        $field = Registry::field('post', $name);
        $rows = $field && $id > 0 ? self::rows($field, $name, $value) : null;
        if ($rows === null) {
            return false;
        }
        foreach (array_keys(self::postRows($id, $name)) as $key) {
            delete_post_meta($id, $key);
        }
        foreach ($rows as $key => $stored) {
            add_post_meta($id, $key, wp_slash($stored));
        }
        return true;
    }

    /**
     * The rows Carbon Fields stores for a value: a simple field is the row
     * `_<name>`; a complex field one `_<name>|||<row>|value` group row per row
     * plus one `_<name>|<sub>|<row>|0|value` row per sub-field (missing
     * sub-fields get their default); an association `_<name>|||<n>|value`,
     * `|type`, `|subtype` and `|id` per entry ([type, subtype, id] arrays, or
     * "type:subtype:id" strings). An empty list is a single
     * `_<name>|||0|_empty` row. Checkboxes accept true/false or the stored
     * strings.
     */
    private static function rows(array $field, $name, $value)
    {
        $rows = [];
        if ($field['type'] === 'complex' || $field['type'] === 'association') {
            $value = is_array($value) ? array_values($value) : [];
            if (! $value) {
                $rows["_{$name}|||0|" . self::KEEPALIVE] = '';
            }
        }
        if ($field['type'] === 'complex') {
            foreach ($value as $index => $row) {
                $row = is_array($row) ? $row : [];
                $group = isset($row['_type']) ? (string) $row['_type'] : '_';
                $rows["_{$name}|||{$index}|value"] = $group;
                if (isset($field['groups'][$group])) {
                    foreach ($field['groups'][$group] as $sub => $sub_field) {
                        $sub_value = array_key_exists($sub, $row) ? $row[$sub] : self::fieldDefault($sub_field);
                        $rows["_{$name}|{$sub}|{$index}|0|value"] = self::storable($sub_field, $sub_value);
                    }
                }
            }
        } elseif ($field['type'] === 'association') {
            $index = 0;
            foreach ($value as $entry) {
                if (! is_array($entry) || ! isset($entry['type'])) {
                    $string = trim((string) (is_array($entry) ? ($entry['value'] ?? '') : $entry));
                    if (empty($string)) {
                        continue;
                    }
                    if (is_numeric($string)) {
                        $string = self::legacyAssociation($field, $string);
                    }
                    $pieces = explode(':', $string);
                    $entry = ['value' => $string, 'type' => $pieces[0], 'subtype' => $pieces[1] ?? 'post', 'id' => intval($pieces[2] ?? 0)];
                }
                $entry += ['value' => '', 'subtype' => '', 'id' => 0];
                foreach (['value', 'type', 'subtype', 'id'] as $property) {
                    $rows["_{$name}|||{$index}|{$property}"] = $entry[$property];
                }
                $index++;
            }
            if (! $index && $value) {
                $rows["_{$name}|||0|" . self::KEEPALIVE] = '';
            }
        } else {
            $rows["_{$name}"] = self::storable($field, $value);
        }
        return $rows;
    }

    /** The value as stored for a single-value field. */
    private static function storable(array $field, $value)
    {
        if ($field['type'] === 'checkbox' && is_bool($value)) {
            return $value ? $field['value'] : '';
        }
        return $value === null ? '' : $value;
    }

    /** The `_<name>` / `_<name>|…` rows of an option, straight from the table. */
    private static function storedOptionRows($name)
    {
        global $wpdb;
        $rows = [];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Prefix match over option names; no WordPress API for it.
        foreach ($wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name = %s OR option_name LIKE %s", '_' . $name, $wpdb->esc_like('_' . $name . '|') . '%')) as $row) {
            $rows[$row->option_name] = $row->option_value;
        }
        return $rows;
    }

    /** Forget the cached option rows (after options change). */
    public static function flush($option = null)
    {
        if ($option === null || (is_string($option) && strpos($option, '_') === 0)) {
            self::$optionRows = [];
        }
    }

    public static function register()
    {
        foreach (['added_option', 'updated_option', 'deleted_option'] as $hook) {
            add_action($hook, [__CLASS__, 'flush']);
        }
    }

    private static function belongsTo($key, $name)
    {
        return $key === '_' . $name || strpos($key, '_' . $name . '|') === 0;
    }

    private static function postRows($id, $name)
    {
        $meta = $id > 0 ? get_post_meta($id) : [];
        $rows = [];
        if (is_array($meta)) {
            foreach ($meta as $key => $values) {
                if (self::belongsTo((string) $key, $name) && $values) {
                    // Carbon Fields lets the last of duplicate rows win.
                    $rows[(string) $key] = end($values);
                }
            }
        }
        return $rows;
    }

    private static function optionRows()
    {
        global $wpdb;
        $blog = get_current_blog_id();
        if (! isset(self::$optionRows[$blog])) {
            $where = [];
            foreach (array_keys(Registry::options()) as $name) {
                $where[] = $wpdb->prepare('option_name = %s OR option_name LIKE %s', '_' . $name, $wpdb->esc_like('_' . $name . '|') . '%');
            }
            $rows = [];
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- Each condition is prepared above; cached per request.
            foreach ($wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE " . implode(' OR ', $where)) as $row) {
                $rows[$row->option_name] = $row->option_value;
            }
            self::$optionRows[$blog] = $rows;
        }
        return self::$optionRows[$blog];
    }

    /**
     * The stored rows of one field as Carbon Fields' value tree: row/value
     * index => [property or sub-field => ...]. Null when nothing is stored.
     */
    private static function tree($name, array $rows)
    {
        $tree = [];
        $keepalive = false;
        foreach ($rows as $key => $value) {
            $segments = explode('|', substr($key, 1));
            $hierarchy = [];
            $hierarchyIndex = [];
            $valueIndex = 0;
            $property = 'value';
            if (count($segments) === 5) {
                $hierarchy = array_filter(explode(':', $segments[1]));
                if (! empty($segments[2])) {
                    $hierarchyIndex = array_map('intval', explode(':', $segments[2]));
                }
                $valueIndex = intval($segments[3]);
                $property = $segments[4];
            }
            if ($property === self::KEEPALIVE) {
                $keepalive = true;
                continue;
            }
            $path = array_merge([$segments[0]], $hierarchy);
            $level = &$tree;
            foreach ($path as $i => $part) {
                if (! isset($level[$part])) {
                    $level[$part] = [];
                }
                $level = &$level[$part];
                $index = $i < count($path) - 1 ? ($hierarchyIndex[$i] ?? 0) : $valueIndex;
                if (! isset($level[$index])) {
                    $level[$index] = [];
                }
                $level = &$level[$index];
            }
            $level[$property] = $value;
            unset($level);
        }
        self::ksortRecursive($tree);
        if (! $tree && ! $keepalive) {
            return null;
        }
        return isset($tree[$name]) ? $tree[$name] : $tree;
    }

    private static function ksortRecursive(&$array)
    {
        if (is_array($array)) {
            ksort($array);
            foreach ($array as &$value) {
                self::ksortRecursive($value);
            }
        }
    }

    private static function read(array $field, $name, array $rows)
    {
        $tree = self::tree($name, $rows);
        switch ($field['type']) {
            case 'complex':
                return self::complex($field, $tree === null ? [] : $tree);
            case 'association':
                return self::association($field, $tree === null ? [] : $tree);
            default:
                return self::format($field, $tree === null ? self::fieldDefault($field) : self::single($tree));
        }
    }

    /** The stored value of a single-value field: the first entry's value. */
    private static function single($entries)
    {
        if (! is_array($entries)) {
            return $entries;
        }
        foreach ($entries as $entry) {
            return is_array($entry) && isset($entry['value']) ? $entry['value'] : '';
        }
        return '';
    }

    private static function fieldDefault(array $field)
    {
        $default = array_key_exists('default', $field) ? $field['default'] : '';
        if ($field['type'] === 'checkbox' && is_bool($default)) {
            $default = $default ? $field['value'] : '';
        }
        return $default;
    }

    private static function format(array $field, $value)
    {
        switch ($field['type']) {
            case 'checkbox':
                return $value === $field['value'];
            case 'radio':
            case 'select':
                $options = $field['options'];
                $index = array_search(strval($value), array_map('strval', $options), true);
                if ($index !== false) {
                    return $options[$index];
                }
                return $options ? $options[0] : '';
            default:
                return $value;
        }
    }

    private static function complex(array $field, array $tree)
    {
        $groups = [];
        foreach ($tree as $row) {
            $groups[] = is_array($row) && isset($row['value']) ? $row['value'] : '_';
        }
        $out = [];
        foreach ($tree as $index => $row) {
            $group = isset($groups[$index]) ? $groups[$index] : null;
            if (! isset($field['groups'][$group])) {
                continue;
            }
            $values = ['_type' => $group];
            foreach ($field['groups'][$group] as $sub => $subField) {
                $values[$sub] = self::format($subField, isset($row[$sub]) ? self::single($row[$sub]) : self::fieldDefault($subField));
            }
            $out[$index] = $values;
        }
        return $out;
    }

    /**
     * Legacy association entries hold only a post ID; Carbon Fields reads them
     * as the field's first post type, whatever the post really is.
     */
    private static function legacyAssociation(array $field, $id)
    {
        $subtype = 'post';
        foreach (isset($field['types']) ? $field['types'] : [] as $type) {
            if ($type['type'] === 'post') {
                $subtype = $type['post_type'];
                break;
            }
        }
        return 'post:' . $subtype . ':' . $id;
    }

    private static function association(array $field, array $tree)
    {
        $out = [];
        foreach ($tree as $entry) {
            if (is_array($entry) && isset($entry['type'])) {
                $out[] = [
                    'value' => isset($entry['value']) ? $entry['value'] : '',
                    'type' => $entry['type'],
                    'subtype' => isset($entry['subtype']) ? $entry['subtype'] : '',
                    'id' => isset($entry['id']) ? $entry['id'] : 0,
                ];
                continue;
            }
            $string = trim((string) (is_array($entry) ? ($entry['value'] ?? '') : $entry));
            if (empty($string)) {
                continue;
            }
            if (is_numeric($string)) {
                $string = self::legacyAssociation($field, $string);
            }
            $pieces = explode(':', $string);
            $out[] = [
                'value' => $string,
                'type' => $pieces[0],
                'subtype' => isset($pieces[1]) ? $pieces[1] : 'post',
                'id' => intval(isset($pieces[2]) ? $pieces[2] : 0),
            ];
        }
        return $out;
    }
}
