# Login audit for SimpleSAMLphp

Keep a record of who logged in, when and from where. This module logs every successful
login, shows the history in the SimpleSAMLphp admin interface, and takes care of rotating
and deleting old logs.

![The Login audit tab in the SimpleSAMLphp admin interface](docs/admin-audit.png)

## Features

- **Login log:** one line per successful login with user ID, name, email, identity provider and IP address.
- **Admin tab:** browse all logins, newest first, with search across every field and pagination.
- **IP locations:** city and country flag per IP address. Private and internal addresses are labelled as such.
- **Log rotation:** daily rotation and automatic deletion of old logs through a URL you call from a cron service.

## Requirements

- SimpleSAMLphp 2.x
- The `admin` module, only if you want the **Login audit** tab. Logging and log rotation work without it.
- PHP 8.3 or newer
- Optional: the `pdo_sqlite` PHP extension, used to cache IP locations

## Installation

1. Install the module:

   ```bash
   composer require eiclu/simplesamlphp-module-loginaudit
   ```

   Without Composer, copy the module into `modules/loginaudit/` instead.

2. Enable it in `config/config.php`:

   ```php
   'module.enable' => ['loginaudit' => true],
   ```

3. Copy `config-templates/module_loginaudit.php` to your `config/` folder and adjust it
   (see [Configuration](#configuration)).

4. Add the filter that writes the log lines (see [Logging logins](#logging-logins)).

5. If the new tab or the rotation URL returns "not found", delete the cached route files in
   SimpleSAMLphp's `tempdir`.

The tab appears as **Login audit** in the admin menu, next to **Log out**.

## Logging logins

Add the `loginaudit:Audit` filter to the authentication processing chain. To log every login
through your IdP, put it in `authproc.idp` in `config/config.php`:

```php
'authproc.idp' => [
    90 => [
        'class' => 'loginaudit:Audit',
        'user_attribute' => 'uid',          // attribute used as user ID (default: uid)
        'name_attribute' => 'displayName',  // default: displayName
        'email_attribute' => 'mail',        // default: mail
    ],
],
```

When SimpleSAMLphp acts as a service provider, add the same entry to the `authproc` of your
`saml:SP` source in `config/authsources.php`. Use one place only, or each login is logged twice.

- Filters run in ascending order. Give the filter a **larger number** than the filters that set the attributes it reads (in the example above, `90`).
- `logging.level` in `config/config.php` must be `NOTICE` (the default) or more verbose.
- The IP address is the one PHP sees as `REMOTE_ADDR`. Behind a reverse proxy, configure your web server to pass on the client address.

Each login produces a line like this:

```
Nov 10 12:00:00 host NOTICE [track] AUDIT-LOGIN: user="mmustermann" name="Maria Mustermann" email="maria.mustermann@example.org" idp="https://idp.example.org/saml2/idp/metadata.php" ip="203.0.113.24"
```

## Configuration

All options go in `config/module_loginaudit.php` and are optional.

| Option           | Default                                    | Description |
|------------------|--------------------------------------------|-------------|
| `logdir`         | `loggingdir` from `config.php`             | Folder that contains the log files. |
| `logfile`        | `logging.logfile` from `config.php`        | Name of the active log file. Rotated files are named `<name>-YYYY-MM-DD.log`. |
| `cache_db`       | `<tempdir>/loginaudit_ipcache.sqlite`      | SQLite file where IP locations are cached. |
| `geo_lookup`     | `true`                                     | Look up unknown IP addresses online. `false` uses only the cache. |
| `rotate_token`   | empty (rotation disabled)                  | Secret that authorizes the rotation URL. |
| `retention_days` | `120`                                      | Days to keep rotated logs. |
| `page_size`      | `50`                                       | Logins per page in the admin tab. |

## Log rotation

Without rotation, the log file grows forever. To rotate it automatically:

1. Set a long random `rotate_token`, for example the output of
   `php -r 'echo bin2hex(random_bytes(24));'`.
2. Call this URL once a day from a cron or webcron service:

   ```
   https://<your-host>/<base-path>/module.php/loginaudit/rotate?token=<rotate_token>
   ```

Each call copies the active log to `simplesamlphp-YYYY-MM-DD.log`, empties the active log, and
deletes archives older than `retention_days` (default: 120). Rotated logs stay visible in the admin
tab until they are deleted.

## Privacy

Locations are looked up at [ip-api.com](https://ip-api.com), which receives the IP addresses
shown in the tab. Results are cached locally. Set `'geo_lookup' => false` to keep all addresses
on your server. The tab is only accessible to SimpleSAMLphp administrators.

## License

[LGPL-2.1-or-later](LICENSE)
