# GAL Bridge – Releasing

Releases are annotated Git tags, signed with the SSH release key listed in `.github/release-signers`
(fingerprint `SHA256:Dh488r7YcvACwpsZov0/TASb3v3FRjz4//bwoQCyPwU`, SECURITY.md "Release signing
key"), of a commit of `main` with a green CI run and recorded release checks; they are distributed
through GitHub and Packagist and installed by version constraint. `main` / `dev-main` is for
development only. The tag and its archive are checked with the same scripts twice: by the
maintainer before the push (`tests/release/verify-tag.sh`, `tests/release/check-archive.php`) and by
the CI job `release` afterwards - Packagist publishes a tag as soon as it is pushed, so the first
check is the one that counts.

## 0. First publication

The repository is private until its first release. Branch and tag rulesets and private
vulnerability reporting need a public repository on GitHub's free plan, so the order matters: no
instruction may become public before the tag it names exists, and the package name must be
registered on Packagist right after the repository becomes public.

**While the repository is still private:**

1. CI is green on `main` (every job, the job `e2e` and the root step of the PHPUnit job included).
2. `.github/release-signers` on `main` lists the release key; the same key is registered as an SSH
   **signing** key of the maintainer's GitHub account (Settings → SSH and GPG keys → "Signing key";
   it appears at <https://api.github.com/users/takenek/ssh_signing_keys>), and its fingerprint is
   published on a channel that does not depend on this repository (for example the maintainer's own
   web site), then named in SECURITY.md "Release signing key".
3. The version bump of the first release is done (step 4 below).
4. The signed tag is created (step 6), `tests/release/verify-tag.sh` and
   `tests/release/check-archive.php` pass on it (step 7), and it is pushed (step 8) while the
   repository is private; the CI job `release` is green for it. Its checks need no credentials of
   their own, so they work on the private repository.

**Publication, in one sitting:**

1. Make the repository public.
2. **Immediately** submit `https://github.com/takenek/galbridge` at packagist.org and confirm that the
   package page reports **"This package is auto-updated"**. Check first that the vendor name
   `takenek` already belongs to the maintainer on Packagist (for example through `takenek/mimeshield`):
   if it does not, the minutes between "public" and the submission are the window in which somebody
   else could take the package name, so keep them short.
3. Check that version `X.Y.Z` appears at `packagist.org/packages/takenek/galbridge` and resolves to
   the commit of the tag.
4. Settings → Code security: enable **private vulnerability reporting** (SECURITY.md names
   <https://github.com/takenek/galbridge/security/advisories/new> as the only private channel),
   **secret scanning with push protection**, Dependabot alerts and security updates.
5. A ruleset for `main`: no deletion, no force push, required status checks (every PHPUnit leg,
   PHPStan, PHP-CS-Fixer, the secret scan and the four `e2e` legs), signed commits.
6. A ruleset for `refs/tags/v*`: only the maintainer creates them; update and deletion blocked.
7. Settings → Actions: allow only `actions/checkout`, `actions/upload-artifact` and
   `shivammathur/setup-php` (and require full-length commit SHAs where GitHub offers that policy),
   require approval for workflows of all outside collaborators, keep the default token read-only.
8. Immutable releases, where GitHub offers them; two-factor authentication on the account.
9. The GitHub release of the tag (step 9).

**Afterwards:** the schedule check of step 3 with every release and monthly.

## 1. Checklist (every release)

1. **CI is green** on `main` for the commit to be released (`.github/workflows/ci.yml`): PHPUnit on
   PHP 8.1 – 8.5 against the newest Roundcube 1.7.x and on PHP 8.1 and 8.4 against 1.7.0, each with
   the SQLite mirror, MariaDB 11.8 and PostgreSQL 17 and failing on any skipped test, plus the root
   step of the PHP 8.4 legs (the refusal of `bin/galbridge.sh` to run as root and the checks of
   `--allow-root`, from a root-owned copy); the job `databases` (the same suite on PHP 8.4 and the
   newest 1.7.x against the seven server pairs of INSTALLATION.md section 1, each leg with a MariaDB or MySQL
   server and a PostgreSQL server, failing on any skipped test); `php -l`, `composer validate
   --strict`, `composer audit` of every resolved dependency set, PHPStan, PHP-CS-Fixer; the job `e2e` (end-to-end and browser
   suites against Roundcube 1.7.0 and the newest known 1.7.x, MariaDB and PostgreSQL, as an
   unprivileged user); the secret scan of history and tree with its controls. Every job that
   resolves dependencies records them in its summary and keeps its `composer.lock` as an artifact
   (`composer-lock-tests-php<version>-newest|1.7.0`, `composer-lock-databases-<leg>`,
   `composer-lock-phpstan`, `composer-lock-coding-style`, 90 days): keep the run of the release
   commit (its URL goes into the release notes).
2. **Local verification of the exact release commit**, recorded in `docs/TESTING.md` (versions,
   commands, results). Do not tag while any of it is recorded as not run. It runs in a **disposable
   container or virtual machine** that holds no signing key, no SSH agent and no GitHub or Packagist
   credentials - never on the machine that signs: the development dependencies execute there.
   A Debian 13 container with the packages of `docs/TESTING.md` section 2 ("Running the E2E and browser suites") is enough.
   * Development dependencies come from the CI run of the release commit, not from a new
     resolution: download the locks outside the container
     (`gh run download <run-id> -n composer-lock-tests-php8.4-1.7.0 -D lock-1.7.0` and
     `-n composer-lock-tests-php8.4-newest -D lock-newest`) and copy the `composer.lock` files in. In
     the container, make the runner-local edit of that leg -
     `composer require --dev --no-update "roundcube/roundcubemail:~1.7.0"` (newest), or `:1.7.0` plus
     the `composer config --json --merge policy.advisories.ignore ...` line of the workflow (1.7.0) -
     put the lock next to `composer.json` and run `composer install --no-interaction` (never
     `update`), then `composer audit`.
   * The full PHPUnit suite on **all three engines**, as an **unprivileged user**, failing on skips -
     reload the throw-away databases first (load.sh and dovecot.sh are the administrator's steps, run
     as root):

     ```sh
     cd tests/fixtures/vmail && ./load.sh mysql && PSQL_ADMIN="runuser -u postgres -- psql" ./load.sh pgsql && cd -
     GALBRIDGE_RC=/opt/rcrun/1.7.7 \
     GALBRIDGE_TEST_MYSQL_DSN=mysql://galbridge_ro:testonly-ro@127.0.0.1/galbridge_vmail_test \
     GALBRIDGE_TEST_PGSQL_DSN=pgsql://galbridge_ro:testonly-ro@127.0.0.1/galbridge_vmail_test \
     vendor/bin/phpunit --fail-on-skipped --exclude-group root
     ```

     with the administrator access the role and fixture tests need (`MYSQL_ADMIN` / `PSQL_ADMIN`,
     `docs/TESTING.md` section 2). Run it against the **newest** Roundcube 1.7.x tree (newest lock)
     and against the **oldest** one (`GALBRIDGE_RC=/opt/rcrun/1.7.0`, the 1.7.0 lock): the whole
     branch is supported. The root-only tests (`--group root-aware` as root, from a copy and a
     Roundcube tree that root owns) run in CI's root step; repeat them in the container only when
     that step did not run for the commit. The role the tests connect with is built from
     `SQL/grants.*.sql` (with the commented-out personal-data grant enabled), so a passing run also
     proves that the shipped grants are sufficient; `tests/Integration/CliToolTest.php` applies the
     output of `bin/galbridge.sh grants` to both servers and runs `diag` against good, too broad and
     too narrow roles;
   * PHPStan against the newest Roundcube **1.7.x** tree (`/opt/rcrun/1.7.7`, see `phpstan.neon.dist`):
     `php -d memory_limit=2G vendor/bin/phpstan analyse -c phpstan.neon.dist`;
   * coding style: `vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php`;
   * `composer validate --strict`;
   * the end-to-end suite against a real Roundcube for **both** iRedMail SQL editions and the
     supported PHP branches, as the unprivileged user: `tests/e2e/matrix.sh` runs the oldest release
     (1.7.0; PHP 8.1 and 8.4) and the newest one (PHP 8.1, 8.4 and 8.5), each × MariaDB and
     PostgreSQL, and fails when a combination is missing or a case failed or was skipped
     (requirements and the Dovecot test server: `docs/TESTING.md` and the header of
     `tests/e2e/test_e2e.py`; a single run:
     `python3 tests/e2e/test_e2e.py --rc /opt/rcrun/1.7.7 --php php8.4 --db mysql --port 8181`);
   * the browser tests of Roundcube's own UI with the plugin (headless Chromium, with its sandbox):
     `python3 tests/ui/test_ui.py --rc /opt/rcrun/1.7.7 --php php8.4 --db mysql` (and `--db pgsql`);
     a skipped case fails both suites;
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
   * **The scheduled CI run is still active.** GitHub disables the schedule of a workflow after 60
     days without activity in the repository, and the daily check of the newest Roundcube 1.7.x
     (SECURITY.md "Supported versions") depends on it:
     `gh api repos/takenek/galbridge/actions/workflows/ci.yml --jq .state` must print `active`;
     otherwise re-enable it with `gh workflow enable ci.yml`. Check it with every release and once a
     month.
   * **A new Roundcube 1.7.x release** is supported as soon as it is published – users are told to
     install it without waiting. CI's scheduled run tests it on the day Packagist has it (while the
     schedule is active); a failure there is a bug to fix in a patch release. Within 14 days of the
     release, run the local verification above against its tree and raise
     `Tool::LATEST_KNOWN_ROUNDCUBE` (`lib/GalBridge/Cli/Tool.php`; `diag` warns on older cores), the
     newest entries of `tests/e2e/matrix.sh` and the newest core of the job `e2e` with the SHA-256
     of its `roundcubemail-<version>-complete.tar.gz` (the digest of the GitHub release asset,
     checked against the GPG-signed tarball) in the next GAL Bridge release.
   * **A new minor branch** (1.8) needs the full verification before these are changed together:
     `Tool::SUPPORTED_BRANCH`, `composer.json` `extra.roundcube.min-version` / `max-version`, the
     Roundcube constraints in `.github/workflows/ci.yml`, INSTALLATION.md section 1, SECURITY.md "Supported
     versions".
   * Update `Tool::PHP_SECURITY_EOL` when PHP publishes new dates.
   * **Pins that nothing updates automatically** (Dependabot updates the actions and their tag
     comments): the digests of **all** images in `.github/workflows/ci.yml` – the service images of
     the job `tests` (`mariadb:11.8`, `postgres:17`), the server pairs of the job `databases` (its
     matrix: `mariadb:10.6`, `10.11`, `11.4`, `12.3`, `mysql:8.0`, `8.4`, `9.7`, `postgres:13` –
     `postgres:19`; its PostgreSQL 17 leg uses the `postgres:17` digest of the job `tests`) and the
     `debian:13-slim` image of the job `e2e`. Refresh every one of them, tag and digest together – one
     tag has one digest in the whole file (`Remediation2CiTest`):

     ```sh
     for ref in $(grep -oE '(mariadb|mysql|postgres|debian):[0-9][0-9a-z.-]*@sha256:[0-9a-f]{64}' .github/workflows/ci.yml | cut -d@ -f1 | sort -u); do
       printf '%s@%s\n' "$ref" "$(docker buildx imagetools inspect "$ref" | awk '$1 == "Digest:" { print $2; exit }')"
     done
     ```

     and replace each digest that changed. A server release outside the tested set (a new MariaDB
     LTS or MySQL release, a new PostgreSQL major version) is added as a new leg of the job `databases`
     after a local run of the database matrix against it (`docs/TESTING.md` section 2), together with
     INSTALLATION.md section 1. Further pins: the gitleaks
     release with its SHA-256 (from the checksums file of the release), `COMPOSER_VERSION` with
     `COMPOSER_SHA256` (from `https://getcomposer.org/download/<version>/composer.phar.sha256sum`; take
     a release that is at least 14 days old) and the SHA-256 of each Roundcube tarball of the job
     `e2e`. Refresh them at least with every release.
   * Review the entries of the register in SECURITY.md whose review date has passed, and the
     advisories of the development dependencies (`composer audit` in CI; raise the lower bounds in
     `composer.json` when a release with an advisory is still allowed).
4. **Version bump** in one commit:
   * `galbridge::info()` in `galbridge.php` – `'version' => 'X.Y.Z'`;
   * the `v=X.Y.Z` lines of README section 2.1 and of docs/INSTALLATION.md section 2.1 – the same
     command in both (`Remediation2ReleaseTest` compares them with `galbridge.php`);
   * the version badge of the README, a file of the repository whose name carries the version:
     `curl -fsS -o .github/badges/packagist-vX.Y.Z.svg https://img.shields.io/badge/packagist-vX.Y.Z-007ec6.svg
     && git add .github/badges`, and the file name in the badge URL of README line 4 (the image
     shields.io draws for Packagist; the README links the committed file because GitHub's image
     proxy does not reliably fetch shields.io, and a new name per release because packagist.org
     shows README images through rawcdn.githack.com, which caches a URL for a year). The badges of
     earlier versions stay: the READMEs of earlier releases link them through `main`.
     `Remediation2ReleaseTest` checks the name and version of the current badge and that every
     version badge names its own version;
   * `CHANGELOG.md`: rename `## [Unreleased]` to `## [X.Y.Z] – YYYY-MM-DD` (semantic versioning; any
     security fix is at least a patch release; the date of the tag – correct it if the tag is made
     later) and add a new empty `## [Unreleased]` above it.
   `composer.json` carries no version (Packagist takes it from the tag).
5. **Release archive contents.** Check the archive the way GitHub and Packagist will build it
   (`git archive`, which honours `export-ignore` in `.gitattributes`) against the allow-list of full
   paths that CI applies to the tag:

   ```sh
   php tests/release/check-archive.php HEAD
   ```

   It fails for any path that is not part of a release (the plugin, `lib/`, `bin/galbridge.sh`, the
   two SQL templates, `localization/*.inc`, `docs/*.md`, `config.inc.php.dist`, `.htaccess`,
   `composer.json`, `LICENSE`, `COPYRIGHT`, `README.md`, `SECURITY.md`, `CHANGELOG.md`), for anything
   that is not a regular file or directory, and for a required file that is missing - so nothing from
   the test suite (including the TEST-ONLY vmail fixtures and harness credentials), CI configuration,
   developer tooling or security-audit working documents (those are git-ignored: they are kept
   outside the repository and never committed) can be released.
6. **Signed annotated tag** on the version-bump commit, made on the maintainer's machine from a clean
   clone in which Composer and PHPUnit are never run, with the SSH key of `.github/release-signers`
   (Git 2.34 or later):

   ```sh
   git config gpg.format ssh
   git config user.signingkey /path/to/the-release-key.pub   # the key whose fingerprint SECURITY.md names
   git config user.email '<id>+<login>@users.noreply.github.com'   # an address of the maintainer's GitHub account
   git tag -s vX.Y.Z -m "GAL Bridge X.Y.Z"
   ```

   GitHub verifies an SSH signature only when the tagger's address belongs to the account that
   registered the key (a verified address or the account's `noreply` address, which publishes no
   personal address); otherwise it reports `no_user` and the CI job `release` refuses the tag.

   Never use a lightweight, unsigned or GPG-signed tag for a release: CI refuses all three. The key
   must be listed in `.github/release-signers` **on `main`** and registered as an SSH signing key of
   the maintainer's GitHub account (CI also requires GitHub's own verification).
7. **Verify before pushing** – in the same clean clone, with the scripts CI runs on the tag:

   ```sh
   tests/release/verify-tag.sh vX.Y.Z --commit "$(git rev-parse HEAD)"
   php tests/release/check-archive.php vX.Y.Z
   ```

   The first prints `Good "git" signature for galbridge-release with RSA key SHA256:...` and the
   commit; it refuses an unsigned, lightweight or GPG-signed tag, a tag object made for another name,
   a key that is not in the file and another commit.
8. **Push the tag**: `git push origin vX.Y.Z` (the commit must already be on `main`). Packagist
   publishes the version on push; the CI job `release` then checks again, with no credentials of its
   own (it also works while the repository is private): the tag object (rebuilt from GitHub's API if
   the checkout stored only a reference) and GitHub's signature verification,
   `tests/release/verify-tag.sh --require-main` with the key file of `main`, the version in
   `galbridge.php` and `CHANGELOG.md`, and `tests/release/check-archive.php` on the tag. A red job
   means: delete the GitHub release if there is one, fix, and release the next patch version - a
   published tag is never moved.
9. **GitHub release** from the tag (`gh release create vX.Y.Z --verify-tag --notes-file <notes>`),
   notes = the CHANGELOG section of the version and the URL of the CI run of step 1.
10. **Packagist** (every release): check that version `X.Y.Z` appears at
    `packagist.org/packages/takenek/galbridge` and resolves to the exact tag commit. Stable versions
    are immutable on Packagist, so never move or recreate a published release tag. (The first
    submission is section 0.)

## 2. Installation by version

Administrators install a tagged release, never `dev-main`, from its archive, with the
configuration created **before** in `config/galbridge.inc.php` (owner root, group of the PHP user,
mode `0640`):

```sh
composer require --prefer-dist "takenek/galbridge:^X.Y"
```

(iRedMail's MariaDB/MySQL and PostgreSQL editions lack the PHP `ldap` extension that Roundcube's
own lock file declares: add `--ignore-platform-req=ext-ldap` there, INSTALLATION.md 2.1.)
`composer update` then stays within the major version; review CHANGELOG.md before upgrading and run
`sudo -u www-data plugins/galbridge/bin/galbridge.sh diag` from the Roundcube directory (INSTALLATION.md 2.7)
after **every** update. Composer does not verify tag signatures, and Packagist publishes no checksum
of its archive: INSTALLATION.md 2.1 compares the installed files with `git archive` of a verified tag and
the commit Composer names (`composer show takenek/galbridge`, source reference) with the tag's. Roundcube's plugin installer re-creates `plugins/galbridge/config.inc.php` at every
update as a new file with the owner and umask of the user running Composer, and keeps a full copy of
the plugin directory in `plugins/galbridge-<20 digits>` until the update succeeds (a failed update
leaves it): a configuration in `config/galbridge.inc.php` is not touched; `diag` fails for a leftover
copy and warns for a configuration inside the plugin directory of a Composer installation
(INSTALLATION.md 2.1). The release itself needs no `extra.roundcube.persistent-files` entry: the installer cannot be
told to keep the owner and mode of a restored file. Without Composer, administrators unpack the
archive of the verified tag into `plugins/galbridge` (INSTALLATION.md 2.1: `verify-tag` with the release key,
then `git archive | tar -x`); a git clone inside `plugins/` is not an installation method – it would
bring the test suite onto the mail server.

## 3. Verifying a tag signature

The release key: RSA 8192 bits, fingerprint `SHA256:Dh488r7YcvACwpsZov0/TASb3v3FRjz4//bwoQCyPwU`,
principal `galbridge-release` (SECURITY.md "Release signing key").

```sh
git clone https://github.com/takenek/galbridge && cd galbridge
git -c gpg.ssh.allowedSignersFile=.github/release-signers verify-tag vX.Y.Z
# Good "git" signature for galbridge-release with RSA key SHA256:Dh488r7YcvACwpsZov0/TASb3v3FRjz4//bwoQCyPwU
```

Without `gpg.ssh.allowedSignersFile` git cannot verify an SSH signature at all. The file comes from
the same clone as the tag: compare the printed fingerprint with SECURITY.md as GitHub shows it on
`main`, with the maintainer's GitHub **signing** keys (<https://api.github.com/users/takenek/ssh_signing_keys>:
pipe the `key` values into `ssh-keygen -lf -`, INSTALLATION.md 2.1 gives the command) and with the channel
outside GitHub named in section 0. The files Composer installed are compared with `git archive` of
such a verified clone, and its commit with `git rev-parse "vX.Y.Z^{commit}"` (`composer show
takenek/galbridge`, field "source": `[git] https://github.com/takenek/galbridge.git <commit>`).

**Key rotation**: add the new key's line to `.github/release-signers` on `main` (principal
`galbridge-release`, namespace `git`, no comment) before it signs a tag, register it as a GitHub
signing key, give the old line `namespaces="git",valid-before="YYYYMMDD"` (tags signed before that
day keep verifying), and replace the fingerprint in SECURITY.md, README 2.1, INSTALLATION.md 2.1, this section and the
exact-value exceptions of `.gitleaks.toml` and `.gitguardian.yaml` (`Remediation2ReleaseTest`
checks all of them).

## 4. Release archive contents

The archive GitHub and Packagist serve (`git archive`) honours `export-ignore` in `.gitattributes`:
the test suite (including the TEST-ONLY vmail fixtures and harness credentials), CI configuration
(`.github/`), developer tooling configuration and security-audit working documents are not part of
a release. `config.inc.php` (the local configuration with the database password), its copies,
`vendor/` and `composer.lock` are git-ignored and therefore never in a tag.
`tests/release/check-archive.php` checks the archive of a ref against the allow-list of full paths
(step 5, step 7 and the CI job `release`; `Remediation2ReleaseTest` runs it on every push).
