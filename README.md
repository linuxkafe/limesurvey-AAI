LimeSurvey-ShibbolethAuth
=========================

Shibboleth (AAI / eduGAIN) authentication plugin for LimeSurvey.

LimeSurvey: http://www.limesurvey.org/en/

Supported LimeSurvey versions: 4.x and 6.x (see `config.xml`).


How it works
------------

The plugin trusts the Shibboleth Service Provider to have authenticated the
request, and maps the released attributes onto a LimeSurvey user account:

| Attribute | Used for                                                     |
|-----------|--------------------------------------------------------------|
| `eppn`    | Presence check: marks the request as AAI-authenticated       |
| `nmec`    | The LimeSurvey username (staff/student number)                |
| `cn`      | The user's full name                                          |
| `mail`    | The user's e-mail address                                     |

`nmec` is the preferred username. When it is not released, the plugin falls
back to `eppn`. When `mail` is not released, it falls back to `eppn` as well,
because the e-mail column is mandatory on insert.

On the first AAI login the user does not exist yet, so the plugin creates it
and, if the `permission_create_survey` setting is enabled, grants the
`surveys` global permission so the user can create questionnaires.

The plugin generates a random password for the account. It is never shown and
the account is expected to be used through AAI only.


Deployment notes
----------------

### Attributes must reach PHP

The attributes arrive as environment variables exported by the Shibboleth SP
(typically `SetEnv` in the Apache location that the SP protects). Depending on
the SAPI in use, PHP exposes them differently:

| Setup                        | `$_SERVER` | `getenv()` |
|------------------------------|-----------|------------|
| Apache + mod_php             | yes       | yes        |
| Apache + php-fpm (proxy_fcgi)| yes       | yes        |
| nginx + php-fpm              | no        | no         |

The plugin reads `$_SERVER` first and falls back to `getenv()`, so it works
under the first two setups out of the box.

Under **nginx + php-fpm** the attributes are absent from both sources
unless they are mapped explicitly, because php-fpm's default `clear_env=yes`
strips the environment. Either map them in the nginx location:

```nginx
fastcgi_param eppn $eppn;
fastcgi_param nmec $nmec;
fastcgi_param cn   $cn;
fastcgi_param mail $mail;
```

or set `clear_env = no` in the FPM pool and use `env[eppn] = $eppn`.

To confirm what reaches PHP, deploy `tests/probe-aai.php` to the web root and
request it through the SP. It prints which channel carries the attributes,
without exposing their values. **Delete it afterwards.**

### The shib.php front controller

The SP protects a specific URL path, so the front controller has to be
reachable under that path. The usual approach is to keep a copy of `index.php`
named `shib.php` next to it.

That copy goes stale whenever LimeSurvey updates `index.php`, and a stale copy
breaks AAI login in a way that looks like a server misconfiguration. After
every LimeSurvey upgrade, regenerate it:

```bash
./tests/sync-shib.sh /var/www/html/6
```

The script is idempotent and keeps the previous copy as `shib.php.bak`.


Tests
-----

```bash
# Unit tests, no LimeSurvey installation required.
php tests/run-tests.php

# Integration tests, run from inside a LimeSurvey 6 installation:
php tests/verify-ls6-auth.php    # drives the real LS6 event flow
php tests/e2e-web-login.php      # drives a real HTTP login
```

`tests/verify-ls6-auth.php` and `tests/e2e-web-login.php` must be placed in
the LimeSurvey root (they bootstrap the application). `tests/ls6-bootstrap.php`
is the shared bootstrap helper.
