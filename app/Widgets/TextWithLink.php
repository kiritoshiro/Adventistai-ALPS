<?php

namespace App\Widgets;

if (! defined('ABSPATH')) {
    exit;
}

class TextWithLink extends AlpsWidget
{
    protected function info()
    {
        return ['alps_widget_text_with_link', __('ALPS - Text with Link', 'alps'), __('Text block with formatted link.', 'alps')];
    }

    protected function fields()
    {
        return [
            'text_link_title' => ['type' => 'text', 'label' => __('Title', 'alps')],
            'text_link_content' => ['type' => 'textarea', 'label' => __('Content', 'alps')],
            'text_link_url' => ['type' => 'text', 'label' => __('URL', 'alps')],
            'text_link_url_text' => ['type' => 'text', 'label' => __('URL Text', 'alps')],
        ];
    }

    protected function template()
    {
        return 'alps-text-with-link.php';
    }
}
