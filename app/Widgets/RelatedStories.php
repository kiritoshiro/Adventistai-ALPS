<?php

namespace App\Widgets;

if (! defined('ABSPATH')) {
    exit;
}

class RelatedStories extends AlpsWidget
{
    protected function info()
    {
        // "storied" is the ID the widgets were saved under.
        return ['alps_widget_related_storied', __('ALPS - Related Stories', 'alps'), __('Related Stories block for Post Page sidebar.', 'alps')];
    }

    protected function fields()
    {
        return [
            'related_stories_title' => ['type' => 'text', 'label' => __('Title', 'alps')],
        ];
    }

    protected function template()
    {
        return 'alps-related-stories.php';
    }
}
