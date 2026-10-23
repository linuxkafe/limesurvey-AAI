# AuthAAI — Operational Contract

## Project
LimeSurvey Shibboleth/AAI (eduGAIN) authentication plugin.

## Essential commands
- Tests: `php tests/run-tests.php`
- LS6 integration: `php tests/verify-ls6-auth.php` (run inside LS6)
- Sync shib.php: `./tests/sync-shib.sh /var/www/html/6`

## Critical files
- `AuthAAI.php` — plugin logic (beforeLogin, newUserSession)
- `tests/run-tests.php` — unit test harness
- `config.xml` — plugin metadata

## Never-do
- Never commit credentials or IdP attributes
- Never disable the auth_aai permission guard without review