# Contributing

## Tests

The integration tests start a real SimpleSAMLphp (installed as a dev dependency) with PHP's
built-in web server. The same installation acts as test SAML IdP and SP, and the tests run
a full browser-style login against it:

- **Log writing:** a successful SAML login writes an `AUDIT-LOGIN` line (filter attached to the IdP
  and, in a second run, to the SP), a failed login does not, and the entries show up in the admin tab.
- **Log rotation:** the webcron endpoint archives the log, appends on a second run the same day,
  prunes archives older than `retention_days`, and rejects a wrong or unset token.

```bash
composer install
vendor/bin/phpunit
```

Requires PHP 8.3+ with the `bcmath`, `curl`, `intl`, `mbstring`, `openssl` and `xml` extensions.
The tests also run in GitHub Actions (`.github/workflows/ci.yml`) on PHP 8.3, 8.4 and 8.5.
