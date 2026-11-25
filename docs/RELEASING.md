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
   PHP 8.1 – 8.5 against the newest Roundcube 1.7.x and on PHP 8.1 and 8.4 against 1.7.0, each with
   the SQLite mirror, MariaDB 11.8 and PostgreSQL 17; `php -l`, `composer validate --strict`,
   `composer audit`, PHPStan, PHP-CS-Fixer; the secret scan of history and tree). Each PHPUnit job
   records the dependency versions it resolved in its job summary: keep the run of the release
   commit (its URL goes into the release notes).
2. **Local verification on the exact release commit**, recorded in `docs/TESTING.md` (versions,
   commands, results). Do not tag while any of them is recorded as not run. Run the PHPUnit suite
   **as root** as well: the refusal of `bin/galbridge.sh` to run as root and the checks of
   `--allow-root` are tested only then (CI runs as an unprivileged user and skips those tests):
   * the full PHPUnit suite on **all three engines** – reload the throw-away databases first, then
     check that no test was skipped for a missing engine:

     ```sh
     cd tests/fixtures/vmail && ./load.sh mysql && PSQL_ADMIN="runuser -u postgres -- psql" ./load.sh pgsql && cd -
     GALBRIDGE_RC=/opt/rcrun/1.7.7 \
     GALBRIDGE_TEST_MYSQL_DSN=mysql://galbridge_ro:testonly-ro@127.0.0.1/galbridge_vmail_test \
     GALBRIDGE_TEST_PGSQL_DSN=pgsql://galbridge_ro:testonly-ro@127.0.0.1/galbridge_vmail_test \
     vendor/bin/phpunit
     ```

     Run it against the **newest** Roundcube 1.7.x tree and against the **oldest** one
     (`GALBRIDGE_RC=/opt/rcrun/1.7.0`): the whole branch is supported.

     The role the tests connect with is built from `SQL/grants.*.sql` (with the commented-out
     personal-data grant enabled), so a passing run also proves that the shipped grants are
     sufficient. The run includes `tests/Integration/CliToolTest.php`, which applies the output of
     `bin/galbridge.sh grants` to both servers and runs `diag` against good, too broad and too narrow
     roles (with the administrator access of `load.sh`: `MYSQL_ADMIN` / `PSQL_ADMIN` or their
     defaults);
   * PHPStan against the newest Roundcube **1.7.x** tree (`/opt/rcrun/1.7.7`, see `phpstan.neon.dist`):
     `php -d memory_limit=2G vendor/bin/phpstan analyse -c phpstan.neon.dist`;
   * coding style: `vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php`;
   * `composer validate --strict`;
   * the end-to-end suite against a real Roundcube for **both** iRedMail SQL editions and the
     supported PHP branches: `tests/e2e/matrix.sh` runs the oldest release (1.7.0; PHP 8.1 and 8.4)
     and the newest one (PHP 8.1, 8.4 and 8.5), each × MariaDB and PostgreSQL, and fails when a
     combination is missing (requirements and the Dovecot test server:
     `docs/TESTING.md` and the header of `tests/e2e/test_e2e.py`; a single run:
     `python3 tests/e2e/test_e2e.py --rc /opt/rcrun/1.7.7 --php php8.4 --db mysql --port 8181`);
   * the browser tests of Roundcube's own UI with the plugin (headless Chromium):
     `python3 tests/ui/test_ui.py --rc /opt/rcrun/1.7.7 --php php8.4 --db mysql` (and `--db pgsql`);
   * `bin/galbridge.sh diag --user=<login>` on a test installation of **each** edition (MariaDB and
     PostgreSQL 16 or later): no FAIL, and every section runs to its end;
   * `bin/galbridge.sh grants --driver=mysql` and `--driver=pgsql` (also with `--role`, `--db`,
     `--host`, `--account-host` and `--connection-limit`): the output applies cleanly to a fresh
     iRedMail `vmail` database and the printed DSN connects; applied a second time it stops at the
     existing role and changes nothing; the `--reset-password` output changes the password (its DSN
     connects, the old one does not); the `--grants-only` output applies without changing it. No
     output contains the password outside its DSN line. (`CliToolTest` does all of this on both
     servers; repeat it by hand on a real iRedMail database.)
3. **Platform check.** Look for a newer Roundcube 1.7.x release (<https://roundcube.net/news/>), a
   new iRedMail release and its `update/<version>/vmail.{mysql,pgsql}` scripts (vmail schema
   changes – in particular of the `forwardings` table, which the view `galbridge_forwardings` reads),
   and the PHP supported-versions table.
   * **A new Roundcube 1.7.x release** is supported as soon as it is published – users are told to
     install it without waiting. CI's daily run tests it on the day Packagist has it; a failure
     there is a bug to fix in a patch release. Within 14 days of the release, run the local
     verification above against its tree and raise `Tool::LATEST_KNOWN_ROUNDCUBE`
     (`lib/GalBridge/Cli/Tool.php`; `diag` warns on older cores) and the newest entries of
     `tests/e2e/matrix.sh` in the next GAL Bridge release.
   * **A new minor branch** (1.8) needs the full verification before these are changed together:
     `Tool::SUPPORTED_BRANCH`, `composer.json` `extra.roundcube.min-version` / `max-version`, the
     Roundcube constraints in `.github/workflows/ci.yml`, README section 1, SECURITY.md "Supported
     versions".
   * Update `Tool::PHP_SECURITY_EOL` when PHP publishes new dates.
   * **Pins that nothing updates automatically**: the digests of the service images in
     `.github/workflows/ci.yml` (`docker buildx imagetools inspect mariadb:11.8`, the same for
     `postgres:17`; change tag comment and digest together) and the gitleaks release with its
     SHA-256 (from the checksums file of the release). Refresh them at least with every release.
   * Review the entries of the register in SECURITY.md whose review date has passed, and the
     advisories of the development dependencies (`composer audit` in CI; raise the lower bounds in
     `composer.json` when a release with an advisory is still allowed).
4. **Version bump** in one commit:
   * `galbridge::info()` in `galbridge.php` – `'version' => 'X.Y.Z'`;
   * `CHANGELOG.md`: rename `## [Unreleased]` to `## [X.Y.Z] – YYYY-MM-DD` (semantic versioning; any
     security fix is at least a patch release) and add a new empty `## [Unreleased]` above it.
   `composer.json` carries no version (Packagist takes it from the tag).
5. **Release archive contents.** Build the archive the way GitHub and Packagist will and inspect it
   (`export-ignore` in `.gitattributes`):

   ```sh
   list="$(mktemp)"     # a private file, never a fixed name in /tmp
   git archive --format=tar HEAD | tar -t | sort > "$list"
   grep -E '^(tests/|\.github/|\.gitattributes|\.gitignore|\.gitguardian\.yaml|\.gitleaks\.toml|\.php-cs-fixer\.dist\.php|phpstan\.neon\.dist|phpunit\.xml\.dist|config\.inc\.php$|composer\.lock|vendor/|tmp/|STAGE_|FINAL_SECURITY_REPORT|DOSTARCZONE)' "$list" && echo 'UNEXPECTED FILES'
   ```

   The archive must contain `galbridge.php`, `lib/`, `bin/galbridge.sh`, `SQL/grants.mysql.sql`,
   `SQL/grants.postgres.sql`, `localization/`, `config.inc.php.dist`, `.htaccess`, `composer.json`,
   `LICENSE`, `COPYRIGHT`, `README.md`, `SECURITY.md`, `CHANGELOG.md` and `docs/`, and nothing
   from the test suite (including the TEST-ONLY vmail fixtures and harness credentials), CI
   configuration, developer tooling or security-audit working documents (those are git-ignored:
   they are kept outside the repository and never committed).
6. **Signed annotated tag** on the version-bump commit:

   ```sh
   # GPG key
   git tag -s vX.Y.Z -m "GAL Bridge X.Y.Z"
   # or an SSH signing key (Git >= 2.34)
   git config gpg.format ssh
   git config user.signingkey ~/.ssh/id_ed25519.pub
   git tag -s vX.Y.Z -m "GAL Bridge X.Y.Z"
   ```

   Never use a lightweight or unsigned tag for a release. The public part of the signing key
   must be registered in the maintainer's GitHub account: the CI job `release` runs for every
   pushed `v*` tag and fails unless the tag is annotated, GitHub reports its signature as verified,
   the tagged commit is already part of `main`, its version equals `galbridge::info()` and a
   CHANGELOG section, and the archive of the tag holds the plugin without development or audit
   files. "Verified" means that SOME GitHub account's key signed the tag: the job does not know
   which keys may sign releases - check `git tag -v` against the maintainers' published keys
   (step 7, section 3), and pin the release key in the job once the maintainers have published it. README section 2.1 tells administrators to
   install only from a tag whose signature they verified – publish the installation instructions
   of a version only once its tag exists.
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
`bin/galbridge.sh diag` afterwards. Without Composer, administrators unpack the archive of the
verified tag into `plugins/galbridge` (README 2.1: `git verify-tag`, then `git archive | tar -x`);
a git clone inside `plugins/` is not an installation method – it would bring the test suite onto
the mail server.

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
