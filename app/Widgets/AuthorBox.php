<?php

namespace App\Widgets;

if (! defined('ABSPATH')) {
    exit;
}

class AuthorBox extends AlpsWidget
{
    protected function info()
    {
        return ['alps_widget_author', __('ALPS - Author Box', 'alps'), __('This will display a block of the post author\'s profile information.', 'alps')];
    }

    protected function fields()
    {
        return [
            'text_link_author' => ['type' => 'text', 'label' => __('Author', 'alps')],
        ];
    }

    protected function template()
    {
        return 'alps-author-box.php';
    }
}
