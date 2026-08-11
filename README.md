# World of Beauty OpenCart

OpenCart 3.0.3.8 project for `worldofbeauty.hr`.

## Project layout

- `upload/` is the public web root.
- `storagedijana/storagedijana/` is `DIR_STORAGE`.
- `upload/image/` is intentionally not tracked and is transferred separately.
- `upload/config.php`, `upload/admin/config.php`, and `upload/env.php` are environment-specific and are intentionally not tracked.

## Local setup

The local Herd site uses `https://wob.test`, PHP 7.4, and the local `wob` database. Because the local image directory is absent, local configuration defines `REMOTE_IMAGE_URL` and image URLs are served from `https://www.worldofbeauty.hr/image/`.

Production must not define `REMOTE_IMAGE_URL`; with the normal `image` directory present, OpenCart continues using local production images.

## Deployment

Clone or pull the repository into the directory that contains `upload/` and `storagedijana/`. Keep the production config files and `upload/image/` on the server; Git ignores them and will not overwrite them.
