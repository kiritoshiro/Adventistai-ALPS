<?php

/**
 * Search form override. The renderer preserves stock behaviour while the
 * advanced-search kill switch is off.
 *
 * @package Adventistai
 */

defined( 'ABSPATH' ) || exit;

echo Adv_Search_Renderer::render_search_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
