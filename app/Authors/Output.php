<?php

namespace App\Authors;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * What visitors see when author profiles are on: every name, link and
 * structured-data author comes from the post's authors, never from the
 * account that wrote it.
 *
 * - Author pages are the taxonomy archive /<base>/<slug>/
 *   (taxonomy-alps_author.blade.php). Old /author/<login>/ and ?author=N
 *   links go to the linked author's page (301), otherwise 404; the core
 *   redirect that reveals the login never runs.
 * - the_author (and RSS dc:creator), the post author's display name, author
 *   links, oEmbed and Yoast (meta author, Slack data, schema, sitemaps,
 *   article:author, twitter:creator) use the authors.
 * - With "Hide user accounts" (on by default): /wp/v2/users answers 401 to
 *   visitors who aren't logged in, the core users sitemap is off, and comment
 *   classes don't name accounts.
 *
 * Off: nothing here runs.
 */
class Output
{
    public static function register()
    {
        add_action('wp', [__CLASS__, 'redirectUserArchives'], 0);
        add_filter('the_author', [__CLASS__, 'theAuthor'], 20);
        add_filter('get_the_author_display_name', [__CLASS__, 'displayName'], 20, 2);
        add_filter('author_link', [__CLASS__, 'authorLink'], 20, 2);
        add_filter('oembed_response_data', [__CLASS__, 'oembed'], 20, 2);
        add_filter('wpseo_meta_author', [__CLASS__, 'yoastMetaAuthor'], 20, 2);
        add_filter('wpseo_enhanced_slack_data', [__CLASS__, 'yoastSlack'], 20, 2);
        add_filter('wpseo_schema_graph', [__CLASS__, 'yoastSchema'], 20, 2);
        add_filter('wpseo_sitemap_exclude_author', [__CLASS__, 'yoastExcludeAuthors'], 20);
        add_filter('wpseo_opengraph_author_facebook', [__CLASS__, 'yoastFacebook'], 20, 2);
        add_filter('wpseo_twitter_creator_account', [__CLASS__, 'yoastTwitter'], 20);
        add_filter('rest_pre_dispatch', [__CLASS__, 'restUsers'], 10, 3);
        add_filter('wp_sitemaps_add_provider', [__CLASS__, 'coreSitemapUsers'], 10, 2);
        add_filter('comment_class', [__CLASS__, 'commentClass'], 20);
    }

    private static function active()
    {
        return Taxonomy::enabled() && ! is_admin();
    }

    /** The authors of a post of a post type with authors. */
    public static function authors($post)
    {
        $post = get_post($post);
        if (! $post || ! in_array($post->post_type, Taxonomy::postTypes(), true)) {
            return [];
        }
        return Taxonomy::forPost($post);
    }

    public static function names($post, $all = true)
    {
        $names = wp_list_pluck(self::authors($post), 'name');
        return $all ? implode(', ', $names) : (string) reset($names);
    }

    public static function link(\WP_Term $term)
    {
        $link = get_term_link($term);
        return is_wp_error($link) ? '' : $link;
    }

    /** The author shown for the current post, for the Author Box widget (null when profiles are off). */
    public static function widgetAuthor()
    {
        if (! Taxonomy::enabled()) {
            return null;
        }
        $authors = is_singular() ? self::authors(get_queried_object_id()) : [];
        if (! $authors) {
            return false;
        }
        $term = $authors[0];
        return [
            'name' => $term->name,
            'url' => self::link($term),
            'bio' => $term->description,
            'image' => (int) get_term_meta($term->term_id, Taxonomy::IMAGE, true),
            'website' => (string) get_term_meta($term->term_id, Taxonomy::URL, true),
            'facebook' => (string) get_term_meta($term->term_id, Taxonomy::FACEBOOK, true),
        ];
    }

    /** "Name, Name" linked to the author pages, for the byline. */
    public static function byline($post = null)
    {
        if (! Taxonomy::bylineEnabled()) {
            return '';
        }
        $links = [];
        foreach (self::authors($post ?: get_queried_object_id()) as $term) {
            $url = self::link($term);
            $links[] = $url ? '<a href="' . esc_url($url) . '" rel="author">' . esc_html($term->name) . '</a>' : esc_html($term->name);
        }
        return implode(', ', $links);
    }

    /** /author/<login>/ and ?author=N: to the linked author's page, else 404. */
    public static function redirectUserArchives()
    {
        if (! self::active() || ! is_author()) {
            return;
        }
        $user = get_queried_object();
        $term = $user instanceof \WP_User ? Taxonomy::linkedTo($user->ID) : null;
        if ($term) {
            $url = is_feed() ? get_term_feed_link($term->term_id, Taxonomy::NAME) : self::link($term);
            if ($url) {
                wp_safe_redirect($url, 301);
                exit;
            }
        }
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
    }

    public static function theAuthor($name)
    {
        if (! self::active()) {
            return $name;
        }
        $names = self::names(get_post(), is_feed());
        return $names !== '' ? $names : $name;
    }

    public static function displayName($value, $user_id = 0)
    {
        if (! self::active()) {
            return $value;
        }
        $post = get_post();
        if ($post && (int) $post->post_author === (int) $user_id) {
            $names = self::names($post, false);
            return $names !== '' ? $names : $value;
        }
        return $value;
    }

    /** No /author/<login>/ links: the post's first author, the account's author, or the home page. */
    public static function authorLink($link, $user_id = 0)
    {
        if (! self::active()) {
            return $link;
        }
        $post = get_post();
        if ($post && (int) $post->post_author === (int) $user_id) {
            $authors = self::authors($post);
            if ($authors) {
                return self::link($authors[0]) ?: home_url('/');
            }
        }
        $term = Taxonomy::linkedTo($user_id);
        return $term ? (self::link($term) ?: home_url('/')) : home_url('/');
    }

    public static function oembed($data, $post)
    {
        if (! Taxonomy::enabled() || ! is_array($data)) {
            return $data;
        }
        $authors = self::authors($post);
        if ($authors) {
            $data['author_name'] = self::names($post);
            $data['author_url'] = self::link($authors[0]) ?: home_url('/');
        } elseif (isset($data['author_url'])) {
            $data['author_url'] = home_url('/');
        }
        return $data;
    }

    /** The post a Yoast presentation is about, if it is a post. */
    private static function yoastPost($presentation)
    {
        if (is_object($presentation) && isset($presentation->model->object_type) && $presentation->model->object_type === 'post') {
            return get_post((int) $presentation->model->object_id);
        }
        return null;
    }

    public static function yoastMetaAuthor($name, $presentation = null)
    {
        if (! self::active()) {
            return $name;
        }
        $names = self::names(self::yoastPost($presentation) ?: get_post());
        return $names !== '' ? $names : $name;
    }

    public static function yoastSlack($data, $presentation = null)
    {
        if (! self::active() || ! is_array($data)) {
            return $data;
        }
        // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Yoast's own label, to find its entry.
        $key = __('Written by', 'wordpress-seo');
        $names = self::names(self::yoastPost($presentation) ?: get_post());
        if (isset($data[$key]) && $names !== '') {
            $data[$key] = $names;
        }
        return $data;
    }

    public static function yoastFacebook($facebook, $presentation = null)
    {
        if (! self::active()) {
            return $facebook;
        }
        $authors = self::authors(self::yoastPost($presentation) ?: get_post());
        return $authors ? (string) get_term_meta($authors[0]->term_id, Taxonomy::FACEBOOK, true) : '';
    }

    public static function yoastTwitter($account)
    {
        // Authors have no Twitter account; the account's handle is not theirs.
        return self::active() ? '' : $account;
    }

    public static function yoastExcludeAuthors($users)
    {
        return Taxonomy::enabled() ? [] : $users;
    }

    /**
     * Yoast's schema: the Article's author is the post's authors (Person
     * pieces built from the authors), and the Person piece Yoast made from the
     * account (login-based @id, /author/<login>/ URL, Gravatar) is removed.
     */
    public static function yoastSchema($graph, $context = null)
    {
        if (! self::active() || ! is_array($graph)) {
            return $graph;
        }
        $post = is_object($context) && isset($context->post) && $context->post instanceof \WP_Post ? $context->post : (is_singular() ? get_queried_object() : null);
        $authors = $post instanceof \WP_Post ? self::authors($post) : [];
        if (! $authors) {
            return $graph;
        }
        $site = is_object($context) && ! empty($context->site_url) ? $context->site_url : trailingslashit(home_url());
        $people = [];
        $refs = [];
        foreach ($authors as $term) {
            $id = $site . '#/schema/person/alps-author-' . $term->term_id;
            $refs[] = ['@id' => $id, 'name' => $term->name];
            $person = ['@type' => 'Person', '@id' => $id, 'name' => $term->name];
            $url = self::link($term);
            if ($url) {
                $person['url'] = $url;
            }
            if (trim($term->description) !== '') {
                $person['description'] = wp_strip_all_tags($term->description);
            }
            $image = (int) get_term_meta($term->term_id, Taxonomy::IMAGE, true);
            $src = $image ? wp_get_attachment_image_url($image, 'medium') : '';
            if ($src) {
                $person['image'] = ['@type' => 'ImageObject', '@id' => $id . '/image', 'url' => $src, 'contentUrl' => $src, 'caption' => $term->name];
            }
            $same = array_values(array_filter([(string) get_term_meta($term->term_id, Taxonomy::URL, true), (string) get_term_meta($term->term_id, Taxonomy::FACEBOOK, true)]));
            if ($same) {
                $person['sameAs'] = $same;
            }
            $people[] = $person;
        }

        // The account's Person @id(s) that pieces point to as their author.
        $old = [];
        foreach ($graph as $i => $piece) {
            if (is_array($piece) && isset($piece['author']['@id'])) {
                $old[] = $piece['author']['@id'];
                $graph[$i]['author'] = count($refs) === 1 ? $refs[0] : $refs;
            }
        }
        $keep = is_object($context) && isset($context->site_represents, $context->site_user_id) && $context->site_represents === 'person' && (int) $context->site_user_id === (int) $post->post_author;
        if (! $keep && $old) {
            $graph = array_values(array_filter($graph, function ($piece) use ($old) {
                return ! (is_array($piece) && isset($piece['@id']) && in_array($piece['@id'], $old, true));
            }));
        }
        return array_merge($graph, $people);
    }

    /** /wp/v2/users answers 401 to visitors who are not logged in. */
    public static function restUsers($result, $server, $request)
    {
        if ($result !== null || ! Taxonomy::hideUsers() || is_user_logged_in()) {
            return $result;
        }
        $route = $request instanceof \WP_REST_Request ? $request->get_route() : '';
        if ($route === '/wp/v2/users' || strpos($route, '/wp/v2/users/') === 0) {
            return new \WP_Error('rest_user_cannot_view', __('Sorry, you are not allowed to list users.', 'alps'), ['status' => 401]);
        }
        return $result;
    }

    public static function coreSitemapUsers($provider, $name)
    {
        return $name === 'users' && Taxonomy::hideUsers() ? false : $provider;
    }

    /** Comment classes without account names (comment-author-<login>). */
    public static function commentClass($classes)
    {
        if (! Taxonomy::hideUsers() || ! is_array($classes)) {
            return $classes;
        }
        return array_values(array_filter($classes, function ($class) {
            return strpos($class, 'comment-author-') !== 0;
        }));
    }
}
