<?php

namespace App;

use App\Fields\SettingsPage;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Appearance → ALPS Theme Settings → Images → kept originals
 * (themes.php?page=alps-theme-options&view=originals): the uploaded originals
 * OriginalImageRetention still keeps, with buttons to delete them before they
 * are due. Deleting goes through the same checks as the scheduled clean-up.
 */
class RetainedOriginalsPage
{
    const VIEW = 'originals';
    const ACTION = 'alps_delete_originals';
    const PER_PAGE = 50;
    /** "Delete all now" batches per click (redirects stay under the browsers' limit of about 20). */
    const MAX_ROUNDS = 15;
    /** Refused rows named in the notice; the rest are counted. */
    const LISTED = 20;

    public static function register()
    {
        add_action('admin_post_' . self::ACTION, [__CLASS__, 'handle']);
    }

    public static function url($args = [])
    {
        return SettingsPage::url(array_merge(['view' => self::VIEW], $args));
    }

    /** Content above the Images settings: how many originals are kept, and a link to them. */
    public static function settingsHtml()
    {
        $count = OriginalImageRetention::count();
        /* translators: %d: number of kept original images */
        $text = sprintf(_n('%d uploaded original is kept for now.', '%d uploaded originals are kept for now.', $count, 'alps'), $count);
        return '<h3>' . esc_html__('Uploaded originals', 'alps') . '</h3>'
            . '<p>' . esc_html__('When a large image is uploaded, WordPress keeps the uploaded file next to the smaller copies the site shows, so the image can be edited or converted again from it. The site never shows it. The theme deletes these originals after the days set below.', 'alps') . '</p>'
            . '<p>' . esc_html($text) . ' <a href="' . esc_url(self::url()) . '">' . esc_html__('Show the kept originals', 'alps') . '</a></p>';
    }

    /** Size of the kept original on disk, or null when it is missing. */
    public static function fileSize($id)
    {
        $metadata = wp_get_attachment_metadata($id);
        $original = is_array($metadata) ? (string) ($metadata['original_image'] ?? '') : '';
        $file = get_attached_file($id, true);
        if ($original === '' || basename($original) !== $original || ! $file) {
            return null;
        }
        $path = dirname($file) . '/' . $original;
        return is_file($path) && ! is_link($path) ? (int) filesize($path) : null;
    }

    public static function render()
    {
        if (! current_user_can(SettingsPage::CAPABILITY)) {
            return;
        }
        // Dates as the current settings give them, even if they were changed elsewhere.
        OriginalImageRetention::apply();
        $ids = OriginalImageRetention::query();
        $total = count($ids);
        $bytes = 0;
        update_meta_cache('post', $ids);
        foreach ($ids as $id) {
            $bytes += (int) self::fileSize($id);
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks the page to show.
        $paged = isset($_GET['paged']) ? max(1, absint(wp_unslash($_GET['paged']))) : 1;
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $paged = min($paged, $pages);
        $rows = array_slice($ids, ($paged - 1) * self::PER_PAGE, self::PER_PAGE);

        echo '<div class="wrap alps-settings alps-originals">';
        echo '<h1>' . esc_html__('Kept uploaded originals', 'alps') . '</h1>';
        echo '<p><a href="' . esc_url(SettingsPage::url(['tab' => 'images'])) . '">' . esc_html__('← Back to the Images settings', 'alps') . '</a></p>';
        self::notice();
        if (OriginalImageRetention::keeps()) {
            /* translators: %d: number of days */
            $policy = sprintf(_n('Originals are kept for %d day after the upload, then deleted by the next scheduled clean-up.', 'Originals are kept for %d days after the upload, then deleted by the next scheduled clean-up.', OriginalImageRetention::days(), 'alps'), OriginalImageRetention::days());
        } else {
            $policy = __('Originals are not kept: each is deleted by the next scheduled clean-up.', 'alps');
        }
        echo '<p>' . esc_html($policy) . '</p>';
        /* translators: 1: number of originals, 2: their total size, e.g. "12 MB" */
        $summary = sprintf(_n('%1$d original, %2$s in total.', '%1$d originals, %2$s in total.', $total, 'alps'), $total, size_format($bytes, 1));
        echo '<p><strong>' . esc_html($summary) . '</strong></p>';
        if (! $total) {
            echo '</div>';
            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
        echo '<input type="hidden" name="paged" value="' . esc_attr((string) $paged) . '">';
        wp_nonce_field(self::ACTION);
        printf(
            '<p><button type="submit" class="button button-secondary" name="all" value="1" data-confirm="%s">%s</button></p>',
            esc_attr__('Delete all kept originals now? The images stay in the library, but they can no longer be edited or converted again from the uploaded file.', 'alps'),
            esc_html__('Delete all now', 'alps')
        );
        echo '<table class="widefat striped"><thead><tr>';
        foreach ([__('Image', 'alps'), __('Title', 'alps'), __('Original file', 'alps'), __('Size', 'alps'), __('Kept since', 'alps'), __('Deletes on', 'alps'), ''] as $heading) {
            echo '<th scope="col">' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        $format = get_option('date_format') . ' ' . get_option('time_format');
        $confirm = __('Delete this original now? The image stays in the library, but it can no longer be edited or converted again from the uploaded file.', 'alps');
        foreach ($rows as $id) {
            $metadata = wp_get_attachment_metadata($id);
            $size = self::fileSize($id);
            $kept = OriginalImageRetention::keptAt($id);
            $due = OriginalImageRetention::dueAt($id);
            $link = get_edit_post_link($id);
            $title = self::title($id);
            echo '<tr>';
            // nosemgrep: php.lang.security.injection.echoed-request.echoed-request -- escaped by wp_kses_post().
            echo '<td>' . wp_kses_post(wp_get_attachment_image($id, [60, 60], false, ['style' => 'max-width:60px;height:auto'])) . '</td>';
            // nosemgrep: php.lang.security.injection.echoed-request.echoed-request -- escaped by esc_url()/esc_html().
            echo '<td>' . ($link ? '<a href="' . esc_url($link) . '">' . esc_html($title) . '</a>' : esc_html($title)) . '</td>';
            echo '<td><code>' . esc_html(is_array($metadata) ? (string) ($metadata['original_image'] ?? '') : '') . '</code></td>';
            echo '<td>' . esc_html($size === null ? __('missing', 'alps') : size_format($size, 1)) . '</td>';
            echo '<td>' . esc_html($kept ? wp_date($format, $kept) : '—') . '</td>';
            echo '<td>' . esc_html($due <= time() ? __('at the next clean-up', 'alps') : wp_date($format, $due)) . '</td>';
            printf('<td><button type="submit" class="button-link button-link-delete" name="attachment" value="%d" data-confirm="%s">%s</button></td>', (int) $id, esc_attr($confirm), esc_html__('Delete original now', 'alps'));
            echo '</tr>';
        }
        echo '</tbody></table></form>';
        if ($pages > 1) {
            // nosemgrep: php.lang.security.injection.echoed-request.echoed-request -- escaped by wp_kses_post().
            echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(paginate_links([
                'base' => add_query_arg('paged', '%#%', self::url()),
                'format' => '',
                'current' => $paged,
                'total' => $pages,
            ])) . '</div></div>';
        }
        echo '</div>';
    }

    /** The attachment's title as typed (escaped where it is printed). */
    private static function title($id)
    {
        $title = trim((string) get_post_field('post_title', $id, 'raw'));
        /* translators: %d: attachment ID */
        return $title !== '' ? $title : sprintf(__('(no title, #%d)', 'alps'), $id);
    }

    /** The result of the last deletion, after the redirect. */
    private static function notice()
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only: counts and IDs after the redirect.
        if (! isset($_GET['deleted'])) {
            return;
        }
        $deleted = absint(wp_unslash($_GET['deleted']));
        $refused = isset($_GET['refused']) ? absint(wp_unslash($_GET['refused'])) : 0;
        $listed = isset($_GET['refused_ids']) ? array_filter(array_map('absint', explode(',', sanitize_text_field(wp_unslash($_GET['refused_ids']))))) : [];
        // phpcs:enable
        if ($deleted) {
            /* translators: %d: number of originals deleted */
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(_n('%d original deleted.', '%d originals deleted.', $deleted, 'alps'), $deleted)) . '</p></div>';
        }
        if (! empty($_GET['more'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
            echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__('More originals remain. Use "Delete all now" again to continue.', 'alps') . '</p></div>';
        }
        if (! $refused) {
            return;
        }
        $names = [];
        foreach (array_slice($listed, 0, self::LISTED) as $id) {
            $names[] = self::title($id);
        }
        /* translators: %d: number of originals not deleted */
        $message = sprintf(_n('%d original was not deleted: a safety check failed (for example, a size of the image is missing, or another attachment uses the file). It stays until the check passes.', '%d originals were not deleted: a safety check failed (for example, a size of the image is missing, or another attachment uses the file). They stay until the check passes.', $refused, 'alps'), $refused);
        echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html($message) . '</p>';
        if ($names) {
            echo '<p>' . esc_html(implode(', ', $names)) . ($refused > count($names) ? ' …' : '') . '</p>';
        }
        echo '</div>';
    }

    public static function handle()
    {
        if (! current_user_can(SettingsPage::CAPABILITY)) {
            wp_die(esc_html__('Sorry, you are not allowed to manage these options.', 'alps'), 403);
        }
        check_admin_referer(self::ACTION);
        // "Delete all now" posts the form, then continues by GET (continueUrl()) with the totals so far.
        $all = ! empty($_REQUEST['all']);
        $deleted = $all && isset($_REQUEST['deleted']) ? absint(wp_unslash($_REQUEST['deleted'])) : 0;
        $refused = $all && isset($_REQUEST['refused']) ? absint(wp_unslash($_REQUEST['refused'])) : 0;
        $listed = $all && isset($_REQUEST['refused_ids']) ? array_values(array_filter(array_map('absint', explode(',', sanitize_text_field(wp_unslash($_REQUEST['refused_ids'])))))) : [];
        if ($all) {
            // One batch per request. Refused rows stay first in the queue, so they are skipped.
            $ids = OriginalImageRetention::query(['posts_per_page' => self::PER_PAGE, 'offset' => $refused]);
        } else {
            $ids = isset($_POST['attachment']) ? [absint(wp_unslash($_POST['attachment']))] : [];
        }
        foreach (array_filter($ids) as $id) {
            if (OriginalImageRetention::deleteNow($id)) {
                $deleted++;
            } else {
                $refused++;
                if (count($listed) < self::LISTED) {
                    $listed[] = $id;
                }
            }
        }
        $round = $all && isset($_REQUEST['round']) ? absint(wp_unslash($_REQUEST['round'])) + 1 : 1;
        $args = [
            'paged' => isset($_REQUEST['paged']) ? max(1, absint(wp_unslash($_REQUEST['paged']))) : 1,
            'deleted' => $deleted,
            'refused' => $refused,
            'refused_ids' => implode(',', $listed),
        ];
        if ($all && count($ids) === self::PER_PAGE
            && OriginalImageRetention::query(['posts_per_page' => 1, 'offset' => $refused])) {
            // Browsers stop following redirects after about 20: end the chain
            // before that and say that more remain.
            if ($round < self::MAX_ROUNDS) {
                wp_safe_redirect(self::continueUrl($args + ['round' => $round]));
                exit;
            }
            $args['more'] = 1;
        }
        wp_safe_redirect(self::url($args));
        exit;
    }

    /** The next batch of "Delete all now", carrying the totals so far. */
    public static function continueUrl($args)
    {
        // Not wp_nonce_url(): it escapes "&" for HTML, and this is a redirect.
        return add_query_arg(array_merge(['action' => self::ACTION, 'all' => 1], $args, ['_wpnonce' => wp_create_nonce(self::ACTION)]), admin_url('admin-post.php'));
    }
}
