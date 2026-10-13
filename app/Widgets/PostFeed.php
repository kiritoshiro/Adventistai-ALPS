<?php

namespace App\Widgets;

if (! defined('ABSPATH')) {
    exit;
}

class PostFeed extends AlpsWidget
{
    protected function info()
    {
        return ['alps_widget_post_feed', __('ALPS - Post Feed', 'alps'), __('Feed of posts in the selected category.', 'alps')];
    }

    protected function fields()
    {
        $categories = [];
        foreach (get_categories(['orderby' => 'name']) as $category) {
            $categories[$category->term_id] = $category->name;
        }
        return [
            'post_feed_category' => ['type' => 'select', 'label' => __('Feed Category', 'alps'), 'choices' => $categories],
            'post_feed_title' => ['type' => 'text', 'label' => __('Feed Title', 'alps')],
            'post_feed_url' => ['type' => 'text', 'label' => __('See All Link', 'alps'), 'help' => __('Enter the url to view all of the post from the selected category.', 'alps')],
            'post_feed_count' => ['type' => 'text', 'label' => __('Number of Posts to Display', 'alps')],
            'post_feed_offset' => ['type' => 'text', 'label' => __('Post Offset', 'alps'), 'help' => __('Enter the number of posts to offset. Only works if the number of posts is set.', 'alps')],
            'post_feed_featured' => ['type' => 'checkbox', 'label' => __('Post Image/Description', 'alps'), 'help' => __('Check to show the image and description for each post.', 'alps')],
            'post_feed_layout' => ['type' => 'checkbox', 'label' => __('Grid Layout', 'alps'), 'help' => __('Check to show the image and description side-by-side.', 'alps')],
        ];
    }

    protected function template()
    {
        return 'alps-post-feed.php';
    }
}
