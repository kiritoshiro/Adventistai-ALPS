<?php

namespace App\Widgets;

use App\Fields\Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The theme's classic widgets, formerly Carbon Fields widgets. They keep the
 * Carbon Fields widget IDs ("carbon_fields_<id>"), CSS class and instance
 * format (each field stored as "_<name>"), so widgets already placed in
 * sidebars keep their settings, and older theme versions read what these
 * save. The templates get the same $args and $settings as before
 * (tests/fields/compare.php compares both with Carbon Fields).
 *
 * The Carbon Fields widgets loaded their templates with include_once, so only
 * the first widget of a kind on a page showed; these show every one.
 */
abstract class AlpsWidget extends \WP_Widget
{
    const PREFIX = 'carbon_fields_';

    /** Widget ID without the prefix, title, description. */
    abstract protected function info();

    /** name => ['type' => text|textarea|checkbox|select, 'label' => …, 'help' => …, 'width' => 50]. */
    abstract protected function fields();

    /** Template file in app/Widgets/templates/. */
    abstract protected function template();

    public static function registerAll()
    {
        foreach ([AuthorBox::class, TextWithLink::class, PostFeed::class, RelatedStories::class] as $class) {
            register_widget($class);
        }
    }

    public function __construct()
    {
        list($id, $title, $description) = $this->info();
        $id = self::PREFIX . $id;
        parent::__construct($id, $title, [
            'description' => $description,
            'classname' => $id,
            'widget_ID' => $id,
        ], ['width' => 295]);
    }

    /** Field definitions in the format App\Fields\Store reads. */
    public function definitions()
    {
        $definitions = [];
        foreach ($this->fields() as $name => $field) {
            $definition = ['type' => $field['type']];
            if ($field['type'] === 'checkbox') {
                $definition['value'] = 'true';
                $definition['default'] = false;
            }
            if ($field['type'] === 'select') {
                $definition['options'] = array_keys($field['choices']);
            }
            $definitions[$name] = $definition;
        }
        return $definitions;
    }

    public function widget($args, $instance)
    {
        echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sidebar markup from register_sidebar().
        $this->render($args, Store::fromInstance($this->definitions(), $instance));
        echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sidebar markup from register_sidebar().
    }

    /** Only $args and $settings are in the template's scope, as with Carbon Fields. */
    protected function render($args, $settings)
    {
        include __DIR__ . '/templates/' . $this->template();
    }

    /**
     * The instance Carbon Fields saved for the same form input: the old
     * instance with each field's "_<name>" replaced. A field missing from the
     * input is stored as ''; a checkbox as 'true' or ''; a select as the
     * matching choice key (category IDs as integers), anything else as the
     * first choice.
     */
    public function update($new_instance, $old_instance)
    {
        $instance = $old_instance;
        foreach ($this->fields() as $name => $field) {
            foreach (array_keys($instance) as $key) {
                if ($key === '_' . $name || strpos((string) $key, '_' . $name . '|') === 0) {
                    unset($instance[$key]);
                }
            }
            $value = isset($new_instance[$name]) && is_scalar($new_instance[$name]) ? (string) $new_instance[$name] : null;
            if ($field['type'] === 'checkbox') {
                $instance['_' . $name] = $value === 'true' ? 'true' : '';
            } elseif ($field['type'] === 'select') {
                $keys = array_keys($field['choices']);
                $index = array_search((string) $value, array_map('strval', $keys), true);
                $instance['_' . $name] = $index !== false ? $keys[$index] : ($keys ? $keys[0] : '');
            } else {
                $value = $value === null ? '' : $value;
                $instance['_' . $name] = current_user_can('unfiltered_html') ? $value : wp_kses_post($value);
            }
        }
        return $instance;
    }

    public function form($instance)
    {
        $values = Store::fromInstance($this->definitions(), $instance);
        foreach ($this->fields() as $name => $field) {
            $id = $this->get_field_id($name);
            $input_name = $this->get_field_name($name);
            $value = $values[$name];
            echo '<p class="alps-widget-field">';
            if ($field['type'] === 'checkbox') {
                printf('<input type="checkbox" class="checkbox" id="%s" name="%s" value="true"%s> <label for="%s">%s</label>', esc_attr($id), esc_attr($input_name), checked($value, true, false), esc_attr($id), esc_html($field['label']));
            } else {
                printf('<label for="%s">%s</label>', esc_attr($id), esc_html($field['label']));
                if ($field['type'] === 'textarea') {
                    printf('<textarea class="widefat" rows="4" id="%s" name="%s">%s</textarea>', esc_attr($id), esc_attr($input_name), esc_textarea((string) $value));
                } elseif ($field['type'] === 'select') {
                    printf('<select class="widefat" id="%s" name="%s">', esc_attr($id), esc_attr($input_name));
                    foreach ($field['choices'] as $key => $text) {
                        printf('<option value="%s"%s>%s</option>', esc_attr((string) $key), selected((string) $value, (string) $key, false), esc_html($text));
                    }
                    echo '</select>';
                } else {
                    printf('<input type="text" class="widefat" id="%s" name="%s" value="%s">', esc_attr($id), esc_attr($input_name), esc_attr((string) $value));
                }
            }
            if (! empty($field['help'])) {
                echo '<br><small class="description">' . esc_html($field['help']) . '</small>';
            }
            echo '</p>';
        }
        return '';
    }
}
