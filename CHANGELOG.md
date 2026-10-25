# NeorClearCache Changelog

All notable changes to this project will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [1.0.0] — 2026-10-25

### Added

- Initial release of **NeorClearCache**.
- Added web interface button under **Administration → System → Clear cache** to purge Matomo caches directly from the browser without CLI/SSH access.
- Integrated with Matomo core cache flushing mechanism (`Filesystem::deleteAllCacheOnUpdate()`), properly triggering the `Filesystem.allCachesCleared` event.
- Superuser-only access enforcement on both menu registration (`Menu.php`) and controller execution (`Controller.php`).
- CSRF mitigation utilizing single-use Matomo Nonces validated strictly from the POST body, with Origin and Referer checks.
- Multilingual support: translations for English (`en`), German (`de`), French (`fr`), Spanish (`es`), Portuguese (`pt`, `pt-br`), Italian (`it`), Russian (`ru`).
- Standalone automated test suite (`tests/security.php`) verifying authorization, HTTP methods, Nonce validation, token expiration, replay prevention, and Twig template rendering.
