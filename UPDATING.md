# Theme updates from private GitHub

This repository builds an installable `adventistai.zip` whenever a new version is pushed to `main`. The ZIP includes Composer dependencies and keeps the installed WordPress theme directory named `adventistai`.

## First installation

1. Download `adventistai.zip` from the latest GitHub release.
2. In WordPress, open **Appearance > Themes > Add New > Upload Theme**.
3. Upload the ZIP and choose **Replace current with uploaded** when WordPress detects the existing theme.

## Enable automatic update notices

Create a fine-grained GitHub personal access token that can read the private `kiritoshiro/Adventistai-ALPS` repository. Give it read-only access to **Contents** and add it above the `/* That's all, stop editing! */` line in `wp-config.php`:

```php
define( 'ADVENTISTAI_ALPS_GITHUB_TOKEN', 'github_pat_REPLACE_WITH_YOUR_TOKEN' );
```

The older `ADVENTISTAI_THEME_GITHUB_TOKEN` constant is also accepted, so sites configured for the previous Adventistai theme do not need an immediate `wp-config.php` change.

WordPress checks the repository's latest release and shows newer versions under **Dashboard > Updates** and **Appearance > Themes**.

## Publish an update

1. Increase `Version` in `style.css`.
2. Commit and push the change to `main`.

The release workflow creates the matching tag (for example `v3.19.6`), installs Composer dependencies, creates the installable ZIP, and publishes it as a GitHub release asset. If that version already has a release, the workflow leaves it unchanged.
