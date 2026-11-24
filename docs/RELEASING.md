# GAL Bridge – Releasing

Releases are signed annotated Git tags of a commit with a green CI run and recorded release checks,
distributed through GitHub and Packagist, and installed by version constraint. `main` / `dev-main`
is for development only.

## 1. Checklist

> **Public Packagist precondition (first publication only):** `packagist.org` indexes public
> packages from a public VCS URL. Before the initial submission, make the GitHub repository public.
> If the repository must remain private, use Private Packagist instead. After initial setup, confirm
> that Packagist reports the package as **auto-updated** so later tags are synchronized automatically.

1. **CI is green** on `main` for the commit to be released (`.github/workflows/ci.yml`: PHPUnit on
   PHP 8.1 – 8.5 against Roundcube 1.7.7 with the SQLite mirror, MariaDB 11.8 and PostgreSQL 17,
   `php -l`, `composer validate --strict`, `composer audit`, PHPStan, PHP-CS-Fixer).
2. **Local verification on the exact release commit**, recorded in `docs/TESTING.md` (versions,
   commands, results). Do not tag while any of them is recorded as not run:
   * the full PHPUnit suite on **all three engines** – reload the throw-away databases first, then
     check that no test was skipped for a missing engine:

     ```sh
     cd tests/fixtures/vmail && ./load.sh mysql && PSQL_ADMIN="runuser -u postgres -- psql" ./load.sh pgsql && cd -
     GALBRIDGE_RC=/opt/rcrun/1.7.7 \
     GALBRIDGE_TEST_MYSQL_DSN=mysql://galbridge_ro:testonly-ro@127.0.0.1/galbridge_vmail_test \
     GALBRIDGE_TEST_PGSQL_DSN=pgsql://galbridge_ro:testonly-ro@127.0.0.1/galbridge_vmail_test \
     vendor/bin/phpunit
     ```

     The role the tests connect with is built from `SQL/grants.*.sql`, so a passing run also proves
     that the shipped grants are sufficient;
   * PHPStan against a Roundcube **1.7.7** tree (`/opt/rcrun/1.7.7`, see `phpstan.neon.dist`):
     `php -d memory_limit=2G vendor/bin/phpstan analyse -c phpstan.neon.dist`;
   * coding style: `vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php`;
   * `composer validate --strict`;
   * the end-to-end suite against a real Roundcube 1.7.7 for **both** iRedMail SQL editions, e.g.
     `python3 tests/e2e/test_e2e.py --rc /opt/rcrun/1.7.7 --php php8.4 --db mysql --port 8181` and
     the same with `--db pgsql` (requirements and the Dovecot test server: `docs/TESTING.md` and the
     header of `tests/e2e/test_e2e.py`);
   * the browser tests of Roundcube's own UI with the plugin (headless Chromium):
     `python3 tests/ui/test_ui.py --rc /opt/rcrun/1.7.7 --php php8.4 --db mysql` (and `--db pgsql`);
   * `bin/galbridge.sh diag` on a test installation: no FAIL;
   * `bin/galbridge.sh grants --driver=mysql` and `--driver=pgsql`: the output applies cleanly to a
     fresh iRedMail `vmail` database.
3. **Platform check.** Look for a newer Roundcube 1.7.x release (<https://roundcube.net/news/>), a
   new iRedMail release and its `update/<version>/vmail.{mysql,pgsql}` scripts (vmail schema
   changes), and the PHP supported-versions table. A new supported Roundcube release needs the full
   verification above before these are changed together: `Tool::SUPPORTED_ROUNDCUBE`
   (`lib/GalBridge/Cli/Tool.php`), `composer.json` `extra.roundcube.min-version` / `max-version`,
   the Roundcube pin in `.github/workflows/ci.yml`, README section 1, SECURITY.md "Supported
   versions". Update `Tool::PHP_SECURITY_EOL` when PHP publishes new dates.
4. **Version bump** in one commit:
   * `galbridge::info()` in `galbridge.php` – `'version' => 'X.Y.Z'`;
   * `CHANGELOG.md`: rename `## [Unreleased]` to `## [X.Y.Z] – YYYY-MM-DD` (semantic versioning; any
     security fix is at least a patch release) and add a new empty `## [Unreleased]` above it.
   `composer.json` carries no version (Packagist takes it from the tag).
5. **Release archive contents.** Build the archive the way GitHub and Packagist will and inspect it
   (`export-ignore` in `.gitattributes`):

   ```sh
   git archive --format=tar HEAD | tar -t | sort > /tmp/galbridge-archive.txt
   grep -E '^(tests/|\.github/|\.gitattributes|\.gitignore|\.gitguardian\.yaml|\.php-cs-fixer\.dist\.php|phpstan\.neon\.dist|phpunit\.xml\.dist|config\.inc\.php$|composer\.lock|vendor/|tmp/)' /tmp/galbridge-archive.txt && echo 'UNEXPECTED FILES'
   ```

   The archive must contain `galbridge.php`, `lib/`, `bin/galbridge.sh`, `SQL/grants.mysql.sql`,
   `SQL/grants.postgres.sql`, `localization/`, `config.inc.php.dist`, `.htaccess`, `composer.json`,
   `LICENSE`, `COPYRIGHT`, `README.md`, `SECURITY.md`, `CHANGELOG.md` and `docs/`, and nothing
   from the test suite (including the TEST-ONLY vmail fixtures and harness credentials), CI
   configuration, developer tooling or security-audit working documents.
6. **Signed annotated tag** on the version-bump commit:

   ```sh
   # GPG key
   git tag -s vX.Y.Z -m "GAL Bridge X.Y.Z"
   # or an SSH signing key (Git >= 2.34)
   git config gpg.format ssh
   git config user.signingkey ~/.ssh/id_ed25519.pub
   git tag -s vX.Y.Z -m "GAL Bridge X.Y.Z"
   ```

   Never use a lightweight or unsigned tag for a release.
7. **Verify** before publishing: `git tag -v vX.Y.Z` (for SSH signatures configure
   `gpg.ssh.allowedSignersFile`, see section 3), and repeat step 5 on the tag:
   `git archive vX.Y.Z | tar -t`.
8. **Push the tag**: `git push origin vX.Y.Z` (the commit must already be on `main`).
9. **GitHub release** from the tag (`gh release create vX.Y.Z --verify-tag --notes-file <notes>`),
   notes = the CHANGELOG section of the version.
10. **Packagist**:
    - **first publication only**: after the repository is public, submit
      `https://github.com/takenek/galbridge` once at packagist.org and configure/confirm GitHub
      synchronization until the package page reports **"This package is auto-updated"**;
    - **every release**: check that version `X.Y.Z` appears at
      `packagist.org/packages/takenek/galbridge` and resolves to the exact tag commit.
    Stable versions are immutable on Packagist, so never move or recreate a published release tag.

## 2. Installation by version

Administrators install a tagged release, never `dev-main`:

```sh
composer require "takenek/galbridge:^X.Y"
```

`composer update` then stays within the major version; review CHANGELOG.md before upgrading and run
`bin/galbridge.sh diag` afterwards. A git installation checks out the tag
(`git -C plugins/galbridge checkout vX.Y.Z`) after verifying it (section 3).

## 3. Verifying a tag signature

```sh
git clone https://github.com/takenek/galbridge && cd galbridge
git tag -v vX.Y.Z          # GPG: import the maintainer's public key first
# SSH-signed tags: list the maintainer's public signing key in an allowed-signers file
echo "<maintainer e-mail> ssh-ed25519 AAAA..." > allowed_signers
git -c gpg.ssh.allowedSignersFile=allowed_signers tag -v vX.Y.Z
```

Obtain the maintainer's key from a channel independent of the repository (e.g. the key published in
the maintainer's GitHub profile, `https://github.com/<user>.gpg` / `.keys`), and compare the tagged
commit with the one Composer installed (`composer show takenek/galbridge`, field "source").

## 4. Release archive contents

The archive GitHub and Packagist serve (`git archive`) honours `export-ignore` in `.gitattributes`:
the test suite (including the TEST-ONLY vmail fixtures and harness credentials), CI configuration
(`.github/`), developer tooling configuration and security-audit working documents are not part of
a release. `config.inc.php` (the local configuration with the database password), `vendor/` and
`composer.lock` are git-ignored and therefore never in a tag. Check with
`git archive vX.Y.Z | tar -t` before publishing (step 5).
