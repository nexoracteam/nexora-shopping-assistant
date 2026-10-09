# Contributing

Thanks for taking an interest in Nexora Shopping Assistant for WooCommerce.

## Before opening a change

- Keep changes focused and backward-compatible where possible.
- Preserve the legacy ConvoCart data identifiers, constants, shortcodes, JavaScript API and REST routes unless a migration is explicitly designed and documented.
- Never commit API keys, credentials, customer data, provider request logs, or production database dumps.
- Keep the WordPress `readme.txt` in sync with user-facing behavior, requirements, external services, privacy details, and version changes.

## Verification

At minimum, run PHP syntax checks on all PHP files and review the diff for unintended provider endpoint, permissions, privacy, database, or lifecycle changes. For releases, also run the current official WordPress Plugin Check and staging WordPress/WooCommerce regression tests.

Please describe the tested WordPress, WooCommerce, and PHP versions when opening a pull request.
