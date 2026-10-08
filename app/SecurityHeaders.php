<?php

namespace App;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Security headers for public pages (PageSpeed "Trust and safety").
 *
 * - Clickjacking: other sites may not show these pages in a frame
 *   (`X-Frame-Options: SAMEORIGIN` and CSP `frame-ancestors 'self'`). The
 *   site's own frames (Customizer preview, block editor) are same-origin.
 * - `Cross-Origin-Opener-Policy: same-origin-allow-popups`: a page opened from
 *   another site cannot script this window, while popups this site opens
 *   (sharing dialogs, YouTube links) keep working.
 *
 * Only the frame rule is sent as a Content-Security-Policy, so it cannot
 * block scripts or styles. A full CSP and Trusted Types are not set: the
 * site's inline scripts (WordPress, plugins, analytics) would need a
 * report-only rollout first. wp-admin and the login page keep WordPress' own
 * headers. Filter `adventistai_security_headers` to change or drop a header
 * (for example to allow framing by a partner site).
 */
class SecurityHeaders
{
    public static function register()
    {
        add_action('send_headers', [__CLASS__, 'send']);
    }

    /** @return array<string, string> */
    public static function headers()
    {
        $headers = [
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "frame-ancestors 'self'",
            'Cross-Origin-Opener-Policy' => 'same-origin-allow-popups',
        ];
        $headers = apply_filters('adventistai_security_headers', $headers);
        return is_array($headers) ? array_filter($headers, 'is_string') : [];
    }

    public static function send()
    {
        if (is_admin() || headers_sent()) {
            return;
        }
        foreach (self::headers() as $name => $value) {
            if (preg_match('/^[A-Za-z-]+$/', (string) $name) && '' !== $value && false === strpbrk($value, "\r\n")) {
                header($name . ': ' . $value);
            }
        }
    }
}
