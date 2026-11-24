GAL Bridge - TEST-ONLY vmail fixtures
=====================================

Everything in this directory is TEST DATA. It protects nothing and must never be loaded into a
production database.

schema.mysql.sql, schema.pgsql.sql
    The upstream iRedMail 1.8.11 vmail schema (samples/iredmail/iredmail.mysql and iredmail.pgsql,
    GPL-3.0-or-later, (c) iRedMail), vendored unchanged so the suites run against the real columns,
    types and defaults of both editions.

schema.sqlite.sql
    A SQLite mirror of the same tables (booleans as INTEGER, DATETIME/DATE as ISO text, no
    case-insensitive collation - case-sensitive like PostgreSQL).

seed.sql
    Fictional domains, mailboxes, aliases and lists covering the edge cases (inactive / backup-MX /
    expired domains, disabled users, enabledeliver=0, IDN domain, LIKE wildcards in names, alias
    domains, every accesspolicy value). The values SECRET-* (password hashes), the recovery address,
    allow_nets and the private forwarding targets are CANARIES: the tests assert that they never
    appear in plugin output, SQL column lists or logs. Expected visibility results are listed at the
    end of the file.

load.sh
    (Re)creates a throw-away database (default galbridge_vmail_test) on MariaDB/MySQL or PostgreSQL,
    loads schema + seed and creates the read-only test role from the plugin's own SQL/grants.*.sql
    (renamed to galbridge_ro, password "testonly-ro" - a throw-away credential of the test databases;
    for MariaDB/MySQL the template takes the verifier of that password, computed with openssl).
    It DROPS the database and the role it is given, so it protects itself against a wrong target:
    the database name must contain "test", the role name must contain "_ro" or "test" and
    must not be a role of iRedMail, Roundcube or the database server, and it refuses a server that
    holds mail databases (vmail, roundcubemail, iredadmin, ...) unless
    GALBRIDGE_LOAD_ON_MAIL_SERVER=1 says that this is intended. Never run it on a mail server.
    The commented-out optional grants of the templates ('-- GRANT ...', e.g. telephone/mobile/birthday)
    are uncommented for the test role, so the suites can exercise every field, and the PostgreSQL
    connection limit is lifted (a PHPUnit process holds many connections until garbage collection).
    It prints the DSNs for GALBRIDGE_TEST_MYSQL_DSN / GALBRIDGE_TEST_PGSQL_DSN.
