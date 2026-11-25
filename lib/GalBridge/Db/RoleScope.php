<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 TaKeN.PL Usługi Informatyczne Marek Królikowski
 * Original author: Marek Królikowski (TaKeN)
 * Original project: https://github.com/takenek/galbridge
 * SPDX-License-Identifier: GPL-3.0-or-later
 * See LICENSE and COPYRIGHT for the license and GPL section 7 attribution terms.
 *
 * GAL Bridge - Global Address List for Roundcube
 *
 * @license GPL-3.0-or-later
 */

namespace GalBridge\Db;

use GalBridge\Directory\Schema;
use GalBridge\Log;

/**
 * What the database role of the plugin can do beyond reading the columns of the directory, and
 * what the view galbridge_forwardings is - read from catalogs the role itself may read, in ONE
 * statement per engine (ViewerResolver::lookup() runs it once per renewal of the viewer cache,
 * diag once).
 *
 * The role of the grant templates has none of it: LOGIN and column-level SELECT (MariaDB/MySQL:
 * also SHOW VIEW on the view, to let diag compare its definition). A role with more - a secret
 * column of any table, a role attribute, a membership in another role (a NOINHERIT role reaches
 * its privileges with SET ROLE, which information_schema does not show), the ownership of the
 * database, a global privilege, a privilege outside the directory database (PostgreSQL: also
 * outside its schema - a relation or sequence of another schema, CREATE on another schema, and
 * every privilege granted to the role itself on an object of the database that is no relation:
 * another schema, a function, a type, a foreign server or wrapper, a large object, default
 * privileges; the USAGE of the grant set on its own schema is the one expected; and a privilege on
 * or the ownership of a shared object: another database, a tablespace, a configuration parameter
 * of PostgreSQL 15+), or a privilege to
 * change anything - is refused by the plugin (role_too_broad): whoever holds its password, which
 * lies in a file the web server reads, would hold all of that. What the role can CREATE in its own
 * schema or in the database, and objects it owns, only diag fails (the plugin logs a warning): a
 * PostgreSQL database of version 14 or older gives CREATE on schema public to every role
 * (PUBLIC), and refusing it would take the books away from every such installation until the
 * grant set is applied again. That CREATE of PUBLIC on schema public counts the same way when the
 * role's own schema is another one; CREATE on schema public granted to the role itself (or its
 * ownership) is then a privilege outside like CREATE on any other schema.
 *
 * The same reasoning applies to the server-wide test databases of MariaDB/MySQL: mariadb-install-db
 * (RHEL and its rebuilds, FreeBSD, the official tarballs - not Debian/Ubuntu or the Docker images)
 * creates the database "test" and grants EVERY account all privileges on "test" and "test_*"
 * (MariaDB 10.11 and later through PUBLIC, older servers through blank-user rows of mysql.db). A
 * database the role can see that is named "test" or starts with "test_", and on which the
 * account's own grants hold nothing, is such a server default: it is listed apart
 * ($serverTestDatabases) and is no reason to refuse the role - the plugin logs a warning, diag
 * fails until it is removed (mariadb-secure-installation). Any other database the role can see,
 * and a test database the account holds a privilege on itself, stays a refusal ($outside).
 *
 * Not visible to the role, so not checked here: privileges granted to PUBLIC on MariaDB/MySQL on
 * the tables of the directory database (diag asks the server instead: UPDATE / DELETE that can
 * change nothing) and the definition of the MariaDB/MySQL view (diag reads it with SHOW CREATE
 * VIEW, which the grant set allows).
 */
final class RoleScope
{
    /** relation kinds of pg_class that carry table privileges (tables, views, materialised views, foreign and partitioned tables) */
    private const PG_RELATIONS = "('r', 'v', 'm', 'f', 'p')";

    /**
     * @param list<string>      $attributes      role attributes beyond LOGIN (PostgreSQL: SUPERUSER, CREATEROLE, CREATEDB, REPLICATION, BYPASSRLS)
     * @param null|list<string> $memberships     roles the role is a member of / roles granted to the account (null: the server cannot tell - MySQL before 8.0.19)
     * @param bool              $databaseOwner   it owns the directory database (PostgreSQL)
     * @param list<string>      $global          global privileges other than USAGE (MariaDB/MySQL)
     * @param list<string>      $outside         databases, schemas, relations or other objects outside the directory database (PostgreSQL: and schema) it holds a privilege on
     * @param list<string>      $changes         privileges to change or administer objects of the directory database ("relation:PRIVILEGE")
     * @param bool              $canCreate       it may create objects in its schema or the database, or in schema public through PUBLIC only (PostgreSQL; MariaDB/MySQL: part of $changes)
     * @param int               $owned           objects it owns (PostgreSQL, every database)
     * @param list<string>      $viewProblems    how the view galbridge_forwardings differs from the one of the grant set (as far as the catalogs tell)
     * @param list<string>      $connectable     other databases the role may connect to - through PUBLIC as a rule (PostgreSQL; pg_hba.conf decides)
     * @param list<string>      $serverTestDatabases server-wide test databases the role can see ("test", "test_*") on which the account's own grants hold nothing (MariaDB/MySQL; not in $outside)
     * @param list<string>      $createSchemas   other schemas of the directory database it may create objects in, as "schema <name>" (PostgreSQL; also in $outside)
     */
    public function __construct(
        public readonly array $attributes = [],
        public readonly ?array $memberships = [],
        public readonly bool $databaseOwner = false,
        public readonly array $global = [],
        public readonly array $outside = [],
        public readonly array $changes = [],
        public readonly bool $canCreate = false,
        public readonly int $owned = 0,
        public readonly array $viewProblems = [],
        public readonly array $connectable = [],
        public readonly array $serverTestDatabases = [],
        public readonly array $createSchemas = [],
    ) {
    }

    /**
     * The catalogs of the connected server, as the role sees them. SQLite (tests) has no
     * privileges: nothing to report.
     *
     * @throws \GalBridge\Exception\GalBridgeException 'db_down' | 'db_error'
     */
    public static function read(Connection $conn): self
    {
        return match ($conn->driver()) {
            'pgsql' => self::postgres($conn),
            'mysql' => self::mysql($conn),
            default => new self(),
        };
    }

    /**
     * Why the plugin refuses the role, given the columns it can see in EVERY relation of the
     * directory schema / database (Connection::visibleColumnsEverywhere()) - empty when it is
     * the role of the grant templates.
     *
     * @param array<string, list<string>> $columns
     *
     * @return list<string>
     */
    public function refusals(array $columns): array
    {
        $out = [];
        $secret = [];
        foreach ($columns as $table => $cols) {
            foreach (array_intersect(Connection::SECRET_COLUMNS, $cols) as $col) {
                $secret[] = $table . '.' . $col;
            }
        }
        if ($secret !== []) {
            $out[] = 'it can read secret columns (' . self::names($secret) . ')';
        }
        foreach (Connection::FORBIDDEN_TABLES as $table) {
            if (($columns[$table] ?? []) !== []) {
                $out[] = 'it can read the table "' . $table . '" (private forwarding targets of every mailbox) instead of the view ' . Schema::FORWARDINGS;
            }
        }
        if ($this->attributes !== []) {
            $out[] = 'it has the role attribute(s) ' . self::names($this->attributes);
        }
        if ($this->memberships !== null && $this->memberships !== []) {
            $out[] = 'it is a member of the role(s) ' . self::names($this->memberships) . ' (SET ROLE reaches their privileges)';
        }
        if ($this->databaseOwner) {
            $out[] = 'it owns the database';
        }
        if ($this->global !== []) {
            $out[] = 'it holds global privileges (' . self::names($this->global) . ')';
        }
        if ($this->outside !== []) {
            $out[] = 'it holds privileges outside the directory (' . self::names($this->outside) . ')';
        }
        if ($this->changes !== []) {
            $out[] = 'it can change or administer objects (' . self::names($this->changes) . ')';
        }
        return $out;
    }

    /**
     * At most eight sanitised names (catalog names can carry anything) and the number of the others.
     *
     * @param list<string> $names
     */
    public static function names(array $names): string
    {
        $shown = array_map(static fn (string $n): string => Log::sanitize($n), array_slice($names, 0, 8));
        return implode(', ', $shown) . (count($names) > 8 ? ', ... (' . (count($names) - 8) . ' more)' : '');
    }

    /**
     * @throws \GalBridge\Exception\GalBridgeException
     */
    private static function postgres(Connection $conn): self
    {
        $rel = self::PG_RELATIONS;
        $view = "'" . Schema::FORWARDINGS . "'";
        $table = "'" . Schema::FORWARDINGS_TABLE . "'";
        // a schema of this database other than the role's own (n = pg_namespace)
        $otherSchema = "n.nspname <> current_schema() AND n.nspname <> 'information_schema' AND left(n.nspname, 3) <> 'pg_'";
        // CREATE on schema public that only PUBLIC holds - the default of a database of PostgreSQL 14
        // or older - not a grant that names the role (nor its ownership): treated like CREATE on its
        // own schema (can_create), see the class docblock
        $publicDefault = "n.nspname = 'public' AND n.nspowner <> me.oid"
            . " AND EXISTS (SELECT 1 FROM aclexplode(n.nspacl) a WHERE a.grantee = 0 AND a.privilege_type = 'CREATE')"
            . " AND NOT EXISTS (SELECT 1 FROM aclexplode(n.nspacl) a WHERE a.grantee = me.oid AND a.privilege_type = 'CREATE')";
        $createElsewhere = "{$otherSchema} AND has_schema_privilege(current_user, n.oid, 'CREATE') AND NOT ({$publicDefault})";
        // the lists below are joined with the unit separator: an object name may contain a comma
        // (a function with two arguments always does)
        $rows = $conn->select(
            'WITH me AS (SELECT r.oid, r.rolsuper, r.rolcreaterole, r.rolcreatedb, r.rolreplication, r.rolbypassrls FROM pg_roles r WHERE r.rolname = current_user),'
            . ' db AS (SELECT d.oid, d.datdba FROM pg_database d WHERE d.datname = current_database()),'
            . " v AS (SELECT c.oid, c.relkind, c.relowner, c.reloptions FROM pg_class c WHERE c.relnamespace = current_schema()::regnamespace AND c.relname = {$view}),"
            . " t AS (SELECT c.oid, c.relowner FROM pg_class c WHERE c.relnamespace = current_schema()::regnamespace AND c.relname = {$table})"
            . ' SELECT'
            . " concat_ws(',', CASE WHEN me.rolsuper THEN 'SUPERUSER' END, CASE WHEN me.rolcreaterole THEN 'CREATEROLE' END,"
            . " CASE WHEN me.rolcreatedb THEN 'CREATEDB' END, CASE WHEN me.rolreplication THEN 'REPLICATION' END, CASE WHEN me.rolbypassrls THEN 'BYPASSRLS' END) AS attributes,"
            . " (SELECT string_agg(g.rolname, ',' ORDER BY g.rolname) FROM pg_auth_members m JOIN pg_roles g ON g.oid = m.roleid WHERE m.member = me.oid) AS memberships,"
            . ' (SELECT db.datdba = me.oid FROM db) AS database_owner,'
            . " has_schema_privilege(current_user, current_schema(), 'CREATE') OR has_database_privilege(current_user, current_database(), 'CREATE')"
            . " OR EXISTS (SELECT 1 FROM pg_namespace n WHERE {$otherSchema} AND has_schema_privilege(current_user, n.oid, 'CREATE') AND {$publicDefault}) AS can_create,"
            . " (SELECT string_agg('schema ' || quote_ident(n.nspname), chr(31) ORDER BY n.nspname) FROM pg_namespace n WHERE {$createElsewhere}) AS create_schemas,"
            . " (SELECT count(*) FROM pg_shdepend s WHERE s.refclassid = 'pg_authid'::regclass AND s.refobjid = me.oid AND s.deptype = 'o') AS owned,"
            . ' (SELECT string_agg(DISTINCT o.x, chr(31) ORDER BY o.x) FROM ('
            // privileges on objects of another database, and on another database itself
            . " SELECT d.datname AS x FROM pg_shdepend s JOIN pg_database d ON d.oid = s.dbid WHERE s.refclassid = 'pg_authid'::regclass AND s.refobjid = me.oid AND s.deptype IN ('a', 'o') AND s.dbid <> (SELECT oid FROM db)"
            . " UNION SELECT d.datname FROM pg_shdepend s JOIN pg_database d ON d.oid = s.objid WHERE s.refclassid = 'pg_authid'::regclass AND s.refobjid = me.oid AND s.classid = 'pg_database'::regclass AND s.dbid = 0 AND d.oid <> (SELECT oid FROM db)"
            // relations of another schema of this database (also through PUBLIC)
            . " UNION SELECT n.nspname || '.' || c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace"
            . " WHERE n.nspname <> current_schema() AND n.nspname <> 'information_schema' AND left(n.nspname, 3) <> 'pg_'"
            . " AND CASE WHEN c.relkind IN {$rel} THEN has_table_privilege(current_user, c.oid, 'SELECT,INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER')"
            . " OR has_any_column_privilege(current_user, c.oid, 'SELECT,INSERT,UPDATE,REFERENCES')"
            . " WHEN c.relkind = 'S' THEN has_sequence_privilege(current_user, c.oid, 'USAGE,SELECT,UPDATE') ELSE false END"
            // CREATE on another schema of this database (also through PUBLIC; rendered like the
            // schema entries of pg_identify_object() below, so one schema is named once)
            . " UNION SELECT 'schema ' || quote_ident(n.nspname) FROM pg_namespace n WHERE {$createElsewhere}"
            // every other privilege granted to the role itself on an object of this database that is
            // no relation (relations: above): another schema - the USAGE of the grant set on its own
            // schema is the one expected entry -, a function, a type, a foreign server or wrapper, a
            // large object, default privileges; named by pg_identify_object(), which - unlike
            // pg_describe_object() - does not depend on the server's lc_messages
            . " UNION SELECT i.type || ' ' || i.identity FROM pg_shdepend s CROSS JOIN LATERAL pg_identify_object(s.classid, s.objid, s.objsubid) i"
            . " WHERE s.refclassid = 'pg_authid'::regclass AND s.refobjid = me.oid AND s.deptype = 'a' AND s.dbid = (SELECT oid FROM db)"
            . " AND s.classid <> 'pg_class'::regclass AND NOT (s.classid = 'pg_namespace'::regclass AND s.objid = current_schema()::regnamespace)"
            // a privilege on (or the ownership of) a shared object other than a database (those:
            // above): a tablespace, a configuration parameter (PostgreSQL 15+: GRANT SET / ALTER
            // SYSTEM ON PARAMETER - ALTER SYSTEM can change how the server runs programs)
            . " UNION SELECT i.type || ' ' || i.identity FROM pg_shdepend s CROSS JOIN LATERAL pg_identify_object(s.classid, s.objid, s.objsubid) i"
            . " WHERE s.refclassid = 'pg_authid'::regclass AND s.refobjid = me.oid AND s.deptype IN ('a', 'o') AND s.dbid = 0 AND s.classid <> 'pg_database'::regclass"
            . ' ) o) AS outside,'
            // what it may change in its own schema (also through PUBLIC); sequences: nextval / setval
            . " (SELECT string_agg(c.relname, ',' ORDER BY c.relname) FROM pg_class c WHERE c.relnamespace = current_schema()::regnamespace"
            . " AND CASE WHEN c.relkind IN {$rel} THEN has_table_privilege(current_user, c.oid, 'INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER')"
            . " OR has_any_column_privilege(current_user, c.oid, 'INSERT,UPDATE,REFERENCES')"
            . " WHEN c.relkind = 'S' THEN has_sequence_privilege(current_user, c.oid, 'USAGE,UPDATE') ELSE false END) AS changes,"
            . " (SELECT string_agg(d.datname, ',' ORDER BY d.datname) FROM pg_database d WHERE d.oid <> (SELECT oid FROM db) AND d.datallowconn AND NOT d.datistemplate"
            . " AND has_database_privilege(current_user, d.oid, 'CONNECT')) AS connectable,"
            // the view: kind, owner (a superuser or the owner of the table), options, fence, what its rule uses
            . ' (SELECT v.relkind FROM v) AS view_kind,'
            . ' (SELECT (SELECT r.rolsuper FROM pg_roles r WHERE r.oid = v.relowner) OR v.relowner = (SELECT t.relowner FROM t) FROM v) AS view_owner_ok,'
            . ' (SELECT pg_get_userbyid(v.relowner) FROM v) AS view_owner,'
            . " (SELECT coalesce(array_to_string(v.reloptions, ','), '') FROM v) AS view_options,"
            . " (SELECT CASE WHEN v.relkind = 'v' THEN pg_get_viewdef(v.oid) END FROM v) AS view_definition,"
            . " (SELECT count(*) FROM v, pg_rewrite w, pg_depend d WHERE w.ev_class = v.oid AND d.classid = 'pg_rewrite'::regclass AND d.objid = w.oid"
            . " AND d.refobjid <> v.oid AND NOT (d.refclassid = 'pg_class'::regclass AND d.refobjid = (SELECT t.oid FROM t))) AS view_foreign"
            . ' FROM me',
        );
        $row = $rows[0] ?? [];
        $problems = [];
        $kind = $row['view_kind'] ?? null;
        if ($kind !== null) {
            if ($kind !== 'v') {
                $problems[] = 'it is not a view (relkind ' . Log::sanitize($kind) . ')';
            } else {
                if (!self::truthy($row['view_owner_ok'] ?? null)) {
                    $problems[] = 'it is owned by ' . Log::sanitize((string) ($row['view_owner'] ?? '?')) . ', neither a superuser nor the owner of the table ' . Schema::FORWARDINGS_TABLE . ': that role can change the view at any time';
                }
                $options = explode(',', strtolower((string) ($row['view_options'] ?? '')));
                if (!in_array('security_barrier=true', $options, true)) {
                    $problems[] = 'it is no security barrier (security_barrier)';
                }
                if (in_array('security_invoker=true', $options, true)) {
                    $problems[] = 'it runs with the rights of the caller (security_invoker)';
                }
                if (!str_contains(strtoupper((string) ($row['view_definition'] ?? '')), 'OFFSET 0')) {
                    $problems[] = 'it is not fenced: the planner may move conditions of a query into it (no OFFSET 0)';
                }
                if ((int) ($row['view_foreign'] ?? 0) > 0) {
                    $problems[] = 'its definition uses ' . (int) $row['view_foreign'] . ' object(s) other than the table ' . Schema::FORWARDINGS_TABLE;
                }
            }
        }
        return new self(
            attributes: self::split($row['attributes'] ?? null),
            memberships: self::split($row['memberships'] ?? null),
            databaseOwner: self::truthy($row['database_owner'] ?? null),
            outside: self::split($row['outside'] ?? null, "\x1F"),
            changes: self::split($row['changes'] ?? null),
            canCreate: self::truthy($row['can_create'] ?? null),
            owned: (int) ($row['owned'] ?? 0),
            viewProblems: $problems,
            connectable: self::split($row['connectable'] ?? null),
            createSchemas: self::split($row['create_schemas'] ?? null, "\x1F"),
        );
    }

    /**
     * @throws \GalBridge\Exception\GalBridgeException
     */
    private static function mysql(Connection $conn): self
    {
        $mariadb = $conn->flavour() === 'mariadb';
        // APPLICABLE_ROLES: MariaDB, and MySQL from 8.0.19 on; older MySQL cannot tell
        $roles = $mariadb || version_compare((string) preg_replace('/[^0-9.].*$/s', '', $conn->serverVersion()), '8.0.19', '>=');
        $g = "CONCAT('''', SUBSTRING_INDEX(CURRENT_USER(), '@', 1), '''@''', SUBSTRING_INDEX(CURRENT_USER(), '@', -1), '''')";
        $view = "'" . Schema::FORWARDINGS . "'";
        // a visible database that is a server-wide test database: named exactly "test" or "test_*"
        // (the names the server's own grants cover; compared as bytes, as the server compares
        // database names on a case-sensitive file system), with no grant of the account's own on it
        // - a pattern of its own (SCHEMA_PRIVILEGES, compared with LIKE) or a table or column grant
        $serverTest = "s.SCHEMA_NAME NOT IN (DATABASE(), 'information_schema', 'performance_schema')"
            . " AND (CAST(s.SCHEMA_NAME AS BINARY) = 'test' OR LEFT(CAST(s.SCHEMA_NAME AS BINARY), 5) = 'test_')"
            . " AND NOT EXISTS (SELECT 1 FROM information_schema.SCHEMA_PRIVILEGES p WHERE p.GRANTEE = {$g} AND s.SCHEMA_NAME LIKE p.TABLE_SCHEMA)"
            . " AND NOT EXISTS (SELECT 1 FROM information_schema.TABLE_PRIVILEGES p WHERE p.GRANTEE = {$g} AND p.TABLE_SCHEMA = s.SCHEMA_NAME)"
            . " AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMN_PRIVILEGES p WHERE p.GRANTEE = {$g} AND p.TABLE_SCHEMA = s.SCHEMA_NAME)";
        $rows = $conn->select(
            'SELECT'
            . " (SELECT GROUP_CONCAT(DISTINCT PRIVILEGE_TYPE ORDER BY PRIVILEGE_TYPE SEPARATOR ',') FROM information_schema.USER_PRIVILEGES WHERE GRANTEE = {$g} AND PRIVILEGE_TYPE <> 'USAGE') AS global_privileges,"
            . ($roles ? " (SELECT GROUP_CONCAT(DISTINCT ROLE_NAME ORDER BY ROLE_NAME SEPARATOR ',') FROM information_schema.APPLICABLE_ROLES) AS memberships," : ' NULL AS memberships,')
            . " (SELECT GROUP_CONCAT(DISTINCT o.s ORDER BY o.s SEPARATOR ',') FROM ("
            // a grant pattern (vmail%) that matches the directory database counts as one of it
            . " SELECT TABLE_SCHEMA AS s FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE = {$g} AND NOT (DATABASE() LIKE TABLE_SCHEMA)"
            . " UNION SELECT TABLE_SCHEMA FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE = {$g} AND TABLE_SCHEMA <> DATABASE()"
            . " UNION SELECT TABLE_SCHEMA FROM information_schema.COLUMN_PRIVILEGES WHERE GRANTEE = {$g} AND TABLE_SCHEMA <> DATABASE()"
            // whatever reaches the account otherwise (a role, PUBLIC): the databases it can see -
            // except the server-wide test databases (listed apart below)
            . " UNION SELECT s.SCHEMA_NAME FROM information_schema.SCHEMATA s WHERE s.SCHEMA_NAME NOT IN (DATABASE(), 'information_schema', 'performance_schema') AND NOT ({$serverTest})"
            . ' ) o) AS outside,'
            . " (SELECT GROUP_CONCAT(s.SCHEMA_NAME ORDER BY s.SCHEMA_NAME SEPARATOR ',') FROM information_schema.SCHEMATA s WHERE {$serverTest}) AS server_test,"
            . " (SELECT GROUP_CONCAT(DISTINCT w.p ORDER BY w.p SEPARATOR ',') FROM ("
            . " SELECT CONCAT('*:', PRIVILEGE_TYPE) AS p FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE = {$g} AND DATABASE() LIKE TABLE_SCHEMA AND PRIVILEGE_TYPE <> 'SELECT'"
            . " UNION SELECT CONCAT(TABLE_NAME, ':', PRIVILEGE_TYPE) FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE = {$g} AND TABLE_SCHEMA = DATABASE() AND PRIVILEGE_TYPE <> 'SELECT'"
            . " AND NOT (TABLE_NAME = {$view} AND PRIVILEGE_TYPE = 'SHOW VIEW')"
            . " UNION SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME, ':', PRIVILEGE_TYPE) FROM information_schema.COLUMN_PRIVILEGES WHERE GRANTEE = {$g} AND TABLE_SCHEMA = DATABASE() AND PRIVILEGE_TYPE <> 'SELECT'"
            . ' ) w) AS changes,'
            . " (SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = {$view}) AS view_type,"
            . " (SELECT SECURITY_TYPE FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = {$view}) AS view_security,"
            . " (SELECT IS_UPDATABLE FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = {$view}) AS view_updatable,"
            // the algorithm is a column of MariaDB's VIEWS only
            . ($mariadb ? " (SELECT ALGORITHM FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = {$view}) AS view_algorithm" : ' NULL AS view_algorithm'),
        );
        $row = $rows[0] ?? [];
        $problems = [];
        $type = $row['view_type'] ?? null;
        if ($type !== null) {
            if (strtoupper($type) !== 'VIEW') {
                $problems[] = 'it is not a view (' . Log::sanitize($type) . ')';
            } else {
                if (strtoupper((string) ($row['view_security'] ?? '')) !== 'DEFINER') {
                    $problems[] = 'it does not run with the rights of its creator (SQL SECURITY ' . Log::sanitize((string) ($row['view_security'] ?? '?')) . ')';
                }
                $algorithm = $row['view_algorithm'] ?? null;
                if (strtoupper((string) ($row['view_updatable'] ?? '')) !== 'NO' || ($algorithm !== null && strtoupper($algorithm) !== 'TEMPTABLE')) {
                    $problems[] = 'it is merged into the queries of the role (' . ($algorithm !== null ? 'ALGORITHM=' . Log::sanitize($algorithm) . ', ' : '') . 'updatable: ' . Log::sanitize((string) ($row['view_updatable'] ?? '?')) . '), so their conditions run on private forwarding rows';
                }
            }
        }
        return new self(
            memberships: $roles ? self::split($row['memberships'] ?? null) : null,
            global: self::split($row['global_privileges'] ?? null),
            outside: self::split($row['outside'] ?? null),
            changes: self::split($row['changes'] ?? null),
            viewProblems: $problems,
            serverTestDatabases: self::split($row['server_test'] ?? null),
        );
    }

    /**
     * @param non-empty-string $separator
     *
     * @return list<string>
     */
    private static function split(?string $list, string $separator = ','): array
    {
        return $list === null || $list === '' ? [] : array_values(array_filter(explode($separator, $list), static fn (string $s): bool => $s !== ''));
    }

    private static function truthy(?string $value): bool
    {
        return in_array(strtolower((string) $value), ['t', 'true', '1', 'on', 'yes'], true);
    }
}
