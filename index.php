<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Blade templates perform context-specific escaping before the complete document is rendered.
echo view(app('sage.view'), app('sage.data'))->render();
