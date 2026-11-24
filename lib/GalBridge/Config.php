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

namespace GalBridge;

/**
 * Typed access to the plugin configuration (config.inc.php) with safe defaults.
 *
 * Every option documented in config.inc.php.dist has its default here. Values are validated,
 * normalised and clamped, so a broken configuration cannot widen what users see:
 *  - an invalid entry in an ALLOW-list is dropped (ERROR, reported by diag),
 *  - an invalid DENY-list (or an overlap of organizations) disables what it protects (fail closed),
 *  - '*' is honoured only as the sole element of a list,
 *  - a switch must be the boolean true or false (or the integer 1 or 0); anything else - a string
 *    such as 'false', an array - is never cast: it is reported and disables every book (fail closed),
 *  - the visibility column must be a string: another type never means "no column".
 *
 * Roundcube applies environment overrides (ROUNDCUBE_<OPTION>) on every read and turns the text
 * "false" into the boolean true. For switches this class therefore reads the variable itself and
 * accepts only 1, 0, true and false (case-insensitive); any other spelling fails closed as well.
 *
 * Roundcube merges stored user preferences over the configuration (rcube_config::merge). No
 * galbridge_* option is a user preference: a stored preference with such a name is ignored and the
 * administrator's value is read from a fresh rcube_config (the MIME Shield pattern).
 */
final class Config
{
    public const DEFAULTS = [
        // vmail database
        'galbridge_db_dsn' => '',
        'galbridge_db_connect_timeout' => 2,
        'galbridge_db_timeout' => 3,
        'galbridge_db_allow_plaintext' => false,
        'galbridge_db_allow_verify_ca' => false,
        'galbridge_db_refused_users' => ['vmail', 'vmailadmin', 'roundcube', 'sogo', 'iredadmin', 'iredapd', 'amavisd', 'fail2ban', 'root', 'postgres'],
        // who is who
        'galbridge_imap_hosts' => [],
        'galbridge_scope_ttl' => 300,
        // domain book
        'galbridge_domain_book' => true,
        'galbridge_domain_book_name' => '',
        'galbridge_domain_viewer_domains' => ['*'],
        'galbridge_domain_viewer_domains_exclude' => [],
        'galbridge_domain_groups' => 'auto',
        'galbridge_domain_searchonly' => false,
        'galbridge_domain_autocomplete' => true,
        'galbridge_domain_export' => true,
        'galbridge_organizations' => [],
        // global book
        'galbridge_global_book' => false,
        'galbridge_global_book_name' => '',
        'galbridge_global_viewer_domains' => [],
        'galbridge_global_viewer_domains_exclude' => [],
        'galbridge_global_data_domains' => [],
        'galbridge_global_data_domains_exclude' => [],
        'galbridge_global_allow_wildcard' => false,
        'galbridge_global_include_home' => false,
        'galbridge_global_groups' => 'none',
        'galbridge_global_searchonly' => true,
        'galbridge_global_autocomplete' => true,
        'galbridge_global_export' => false,
        'galbridge_searchonly_threshold' => 2000,
        // content
        'galbridge_fields' => ['name', 'firstname', 'surname', 'email', 'department'],
        'galbridge_user_aliases' => false,
        'galbridge_include_aliases' => true,
        'galbridge_include_mailing_lists' => true,
        'galbridge_list_policy' => 'strict',
        'galbridge_hide_expired' => true,
        'galbridge_hidden_addresses' => [],
        'galbridge_hidden_localparts' => [],
        'galbridge_visibility_column' => '',
        'galbridge_mark_foreign_domains' => true,
        // limits
        'galbridge_min_search_length' => 2,
        'galbridge_max_search_length' => 128,
        'galbridge_max_results' => 500,
        'galbridge_global_max_results' => 100,
        'galbridge_max_ids' => 200,
        'galbridge_max_group_members' => 500,
        'galbridge_max_groups' => 50,
        'galbridge_max_export' => 2000,
        'galbridge_max_count' => 10000,
        // logging
        'galbridge_debug' => false,
    ];

    /** fields known to FieldMap (galbridge_fields allowlist) */
    public const FIELDS = ['name', 'firstname', 'surname', 'email', 'department', 'phone', 'mobile', 'organization', 'birthday'];

    public const GROUPINGS = ['auto', 'department', 'domain', 'none'];

    public const LIST_POLICIES = ['strict', 'public_only', 'all'];

    /** switches: the boolean true or false only (or the integers 1 / 0) */
    public const BOOLEANS = [
        'galbridge_db_allow_plaintext', 'galbridge_db_allow_verify_ca',
        'galbridge_domain_book', 'galbridge_domain_autocomplete', 'galbridge_domain_export',
        'galbridge_global_book', 'galbridge_global_allow_wildcard', 'galbridge_global_include_home',
        'galbridge_global_autocomplete', 'galbridge_global_export',
        'galbridge_user_aliases', 'galbridge_include_aliases', 'galbridge_include_mailing_lists',
        'galbridge_hide_expired', 'galbridge_mark_foreign_domains', 'galbridge_debug',
    ];

    /** switches that also accept the string 'auto' */
    public const TRISTATES = ['galbridge_domain_searchonly', 'galbridge_global_searchonly'];

    private const ADMIN_ONLY_PREFIX = 'galbridge_';

    /** @var null|array<string, mixed> */
    private ?array $adminValues = null;

    /** a configuration without user preferences, for options that no configuration file sets */
    private ?\rcube_config $adminConfig = null;

    /** @var list<array{level: string, option: string, message: string}> */
    private array $problems = [];

    /** @var array<string, mixed> memo of normalised values */
    private array $memo = [];

    /**
     * @param array<string, mixed>      $userPrefs the logged-in user's stored preferences
     * @param null|array<string, mixed> $admin     administrator values (tests); null = load lazily
     */
    public function __construct(private readonly \rcube_config $config, private readonly array $userPrefs = [], ?array $admin = null)
    {
        $this->adminValues = $admin;
    }

    public function get(string $name): mixed
    {
        // a (stale or injected) user preference must never change an administrator option
        if (str_starts_with($name, self::ADMIN_ONLY_PREFIX) && array_key_exists($name, $this->userPrefs)) {
            $admin = $this->adminValues();
            if (array_key_exists($name, $admin)) {
                return $admin[$name];
            }
            // In no configuration file: the built-in default - or the ROUNDCUBE_<OPTION> environment
            // variable. rcube_config::all() applies those variables to the options of the files only,
            // so an option that is set through the environment alone is read like below: the same
            // call, on a configuration that never saw the user's preferences.
            return $this->adminConfig()->get($name, self::DEFAULTS[$name] ?? null);
        }
        return $this->config->get($name, self::DEFAULTS[$name] ?? null);
    }

    /**
     * A core option as written by the administrator (never a user preference), e.g. imap_host.
     */
    public function adminCore(string $name, mixed $default = null): mixed
    {
        $admin = $this->adminValues();
        return array_key_exists($name, $admin) ? $admin[$name] : $default;
    }

    /**
     * A switch. An ambiguous value (see the class comment) is reported, disables every book
     * (booksDisabled()) and reads as the built-in default - the conservative value of every switch.
     */
    public function bool(string $name): bool
    {
        $v = $this->switchValue($name, false);
        return is_bool($v) ? $v : (bool) (self::DEFAULTS[$name] ?? false);
    }

    /**
     * galbridge_*_searchonly: true, false or null for 'auto'. An ambiguous value reads as true (the
     * restrictive value) and disables every book.
     */
    public function searchonly(string $name): ?bool
    {
        $v = $this->switchValue($name, true);
        if (is_bool($v)) {
            return $v;
        }
        return $v === 'auto' ? null : true;
    }

    /**
     * A switch as it is in effect: true, false, 'auto' (galbridge_*_searchonly only) - or null when
     * its value is ambiguous (reported; every book is disabled).
     */
    public function effectiveSwitch(string $name): bool|string|null
    {
        return $this->switchValue($name, in_array($name, self::TRISTATES, true));
    }

    /**
     * Is every switch unambiguous? false = a configuration error that disables every book.
     */
    public function switchesValid(): bool
    {
        $ok = true;
        foreach (self::BOOLEANS as $name) {
            $ok = $this->switchValue($name, false) !== null && $ok;
        }
        foreach (self::TRISTATES as $name) {
            $ok = $this->switchValue($name, true) !== null && $ok;
        }
        return $ok;
    }

    /**
     * Configuration errors that must disable EVERY book, never mean "no restriction": an ambiguous
     * switch, a malformed hidden list or an invalid visibility column.
     */
    public function booksDisabled(): bool
    {
        return !$this->switchesValid()
            || $this->hiddenAddresses() === null
            || $this->hiddenLocalparts() === null
            || $this->visibilityColumn() === null;
    }

    /**
     * galbridge_* options currently overridden by a ROUNDCUBE_<OPTION> environment variable (names
     * only - a value may be the DSN).
     *
     * @return list<string>
     */
    public function envOverrides(): array
    {
        $out = [];
        foreach (array_keys(self::DEFAULTS) as $name) {
            if (getenv('ROUNDCUBE_' . strtoupper($name)) !== false) {
                $out[] = $name;
            }
        }
        return $out;
    }

    public function int(string $name, int $min, int $max): int
    {
        $v = $this->get($name);
        if (!is_int($v) && !is_float($v) && !(is_string($v) && is_numeric($v))) {
            if ($v !== null) {
                $this->problem('error', $name, 'not a number, using the default');
            }
            $v = self::DEFAULTS[$name] ?? $min;
        }
        return max($min, min($max, (int) $v));
    }

    public function string(string $name): string
    {
        $v = $this->get($name);
        if ($v !== null && !is_string($v)) {
            $this->problem('error', $name, 'must be a string (' . get_debug_type($v) . ' given), ignored');
            return '';
        }
        return is_string($v) ? trim($v) : '';
    }

    /**
     * true / false, 'auto' (only with $allowAuto), or null when the value is ambiguous.
     */
    private function switchValue(string $name, bool $allowAuto): bool|string|null
    {
        $key = 'switch:' . $name;
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }
        $expected = $allowAuto ? "must be true, false or 'auto'" : 'must be the boolean true or false';
        $env = getenv('ROUNDCUBE_' . strtoupper($name));
        if (is_string($env)) {
            // the raw text: Roundcube's own conversion turns "false" into true
            $text = strtolower(trim($env));
            $value = match (true) {
                $text === '1', $text === 'true' => true,
                $text === '0', $text === 'false' => false,
                $allowAuto && $text === 'auto' => 'auto',
                default => null,
            };
            if ($value === null) {
                $this->problem('fail', $name, 'environment variable ROUNDCUBE_' . strtoupper($name) . ' '
                    . ($allowAuto ? 'must be 1, 0, true, false or auto' : 'must be 1, 0, true or false') . ': all books are disabled');
            }
            return $this->memo[$key] = $value;
        }
        $raw = $this->get($name);
        $value = match (true) {
            is_bool($raw) => $raw,
            $raw === 1 => true,
            $raw === 0 => false,
            $allowAuto && is_string($raw) && strtolower(trim($raw)) === 'auto' => 'auto',
            default => null,
        };
        if ($value === null) {
            $this->problem('fail', $name, $expected . ' (' . get_debug_type($raw) . ' given): all books are disabled');
        }
        return $this->memo[$key] = $value;
    }

    /**
     * One of $allowed (case-insensitive), else the default.
     *
     * @param list<string> $allowed
     */
    public function choice(string $name, array $allowed): string
    {
        $v = strtolower($this->string($name));
        if (in_array($v, $allowed, true)) {
            return $v;
        }
        $this->problem('error', $name, 'invalid value, using the default');
        $default = self::DEFAULTS[$name] ?? '';
        return is_string($default) ? $default : '';
    }

    /**
     * Normalised domain allow-list (invalid entries dropped). ['*'] = wildcard.
     *
     * @return list<string>
     */
    public function domainAllowList(string $name): array
    {
        return $this->memo[$name] ??= (function () use ($name): array {
            $invalid = [];
            $list = Normalizer::list($this->get($name), [Normalizer::class, 'domain'], false, $invalid);
            if ($list === null) {
                $this->problem('error', $name, 'must be an array of domains, or [\'*\'] alone; treated as empty');
                return [];
            }
            foreach ($invalid as $bad) {
                $this->problem('error', $name, 'entry dropped (cannot be normalised): ' . Log::sanitize($bad));
            }
            return $list;
        })();
    }

    /**
     * Normalised domain deny-list. null = malformed: the caller must fail closed.
     *
     * @return null|list<string>
     */
    public function domainDenyList(string $name): ?array
    {
        if (!array_key_exists($name, $this->memo)) {
            $invalid = [];
            $list = Normalizer::list($this->get($name), [Normalizer::class, 'domain'], true, $invalid);
            if ($list === null) {
                $this->problem('fail', $name, 'malformed deny-list (' . Log::sanitize(implode(', ', $invalid)) . '): the protected book is disabled');
            } elseif ($list === ['*']) {
                $list = null;
                $this->problem('fail', $name, '\'*\' is not allowed in a deny-list: the protected book is disabled');
            }
            $this->memo[$name] = $list;
        }
        return $this->memo[$name];
    }

    /**
     * galbridge_hidden_addresses (deny-list). null = malformed: every book fails closed.
     *
     * @return null|list<string>
     */
    public function hiddenAddresses(): ?array
    {
        if (!array_key_exists('hidden_addresses', $this->memo)) {
            $invalid = [];
            $list = Normalizer::list($this->get('galbridge_hidden_addresses'), [Normalizer::class, 'address'], true, $invalid);
            if ($list === null || $list === ['*']) {
                $this->problem('fail', 'galbridge_hidden_addresses', 'malformed deny-list (' . Log::sanitize(implode(', ', $invalid)) . '): all books are disabled');
                $list = null;
            }
            $this->memo['hidden_addresses'] = $list;
        }
        return $this->memo['hidden_addresses'];
    }

    /**
     * galbridge_hidden_localparts (deny-list of local parts, e.g. 'postmaster'). null = malformed.
     *
     * @return null|list<string>
     */
    public function hiddenLocalparts(): ?array
    {
        if (!array_key_exists('hidden_localparts', $this->memo)) {
            $normalise = static function (mixed $v): ?string {
                if (!is_string($v)) {
                    return null;
                }
                $v = mb_strtolower(trim(Normalizer::nfc($v)));
                return Normalizer::localPart($v) ? $v : null;
            };
            $invalid = [];
            $list = Normalizer::list($this->get('galbridge_hidden_localparts'), $normalise, true, $invalid);
            if ($list === null || $list === ['*']) {
                $this->problem('fail', 'galbridge_hidden_localparts', 'malformed deny-list (' . Log::sanitize(implode(', ', $invalid)) . '): all books are disabled');
                $list = null;
            }
            $this->memo['hidden_localparts'] = $list;
        }
        return $this->memo['hidden_localparts'];
    }

    /**
     * galbridge_organizations as label => domains. Domains listed in more than one organization are
     * removed from all of them (they fall back to their own domain: fail closed), and reported.
     *
     * @return array<array-key, list<string>> label => domains (cast the key to string)
     */
    public function organizations(): array
    {
        if (!array_key_exists('organizations', $this->memo)) {
            $raw = $this->get('galbridge_organizations');
            $orgs = [];
            if (!is_array($raw)) {
                $this->problem('error', 'galbridge_organizations', 'must be an array of label => list of domains');
                $raw = [];
            }
            $seen = [];
            foreach ($raw as $label => $domains) {
                $label = Normalizer::displayText(is_int($label) ? 'Organization ' . ($label + 1) : $label, 64);
                $invalid = [];
                $list = Normalizer::list($domains, [Normalizer::class, 'domain'], false, $invalid);
                if ($list === null || $list === ['*'] || $label === '') {
                    $this->problem('error', 'galbridge_organizations', 'organization ignored (needs a label and a list of domains)');
                    continue;
                }
                foreach ($invalid as $bad) {
                    $this->problem('error', 'galbridge_organizations', 'entry dropped (cannot be normalised): ' . Log::sanitize($bad));
                }
                if (array_key_exists($label, $orgs)) {
                    // two keys that are the same label once cleaned: the later one is not merged
                    $this->problem('error', 'galbridge_organizations', 'organization ignored (duplicate label)');
                    continue;
                }
                foreach ($list as $d) {
                    $seen[$d] = ($seen[$d] ?? 0) + 1;
                }
                $orgs[$label] = $list;
            }
            $dupes = array_map('strval', array_keys(array_filter($seen, static fn (int $n): bool => $n > 1)));
            if ($dupes !== []) {
                $this->problem('fail', 'galbridge_organizations', 'domains listed in more than one organization fall back to their own domain: ' . implode(', ', $dupes));
                foreach ($orgs as $label => $list) {
                    $orgs[$label] = array_values(array_diff($list, $dupes));
                }
            }
            $this->memo['organizations'] = array_filter($orgs, static fn (array $l): bool => $l !== []);
        }
        // NOTE: PHP turns a key such as '2024' into an int: consumers cast the label to string
        /** @var array<array-key, list<string>> */
        return $this->memo['organizations'];
    }

    /**
     * Effective field allowlist ('name' and 'email' are always included).
     *
     * @return list<string>
     */
    public function fields(): array
    {
        return $this->memo['fields'] ??= (function (): array {
            $raw = $this->get('galbridge_fields');
            $out = ['name' => true, 'email' => true];
            if (!is_array($raw)) {
                // fewer fields, never more - but said aloud, like every other option of the wrong type
                $this->problem('error', 'galbridge_fields', 'must be an array of field names (' . get_debug_type($raw) . ' given): only name and email are shown');
            }
            foreach (is_array($raw) ? $raw : [] as $f) {
                $f = is_string($f) ? strtolower(trim($f)) : '';
                if (in_array($f, self::FIELDS, true)) {
                    $out[$f] = true;
                } else {
                    $this->problem('error', 'galbridge_fields', 'unknown field dropped: ' . Log::sanitize($f));
                }
            }
            return array_values(array_intersect(self::FIELDS, array_keys($out)));
        })();
    }

    /**
     * Optional admin-created boolean visibility column of vmail.mailbox ('' = none).
     * Returns null when the option is not a string or not a valid column name (fail closed).
     */
    public function visibilityColumn(): ?string
    {
        if (!array_key_exists('visibility_column', $this->memo)) {
            // the raw value: a wrong type (an array, a number, a boolean) must never read as "no column"
            $raw = $this->get('galbridge_visibility_column');
            if ($raw === null || $raw === '') {
                $column = '';
            } elseif (!is_string($raw)) {
                $this->problem('fail', 'galbridge_visibility_column', 'must be a column name (a string, ' . get_debug_type($raw) . " given; '' = no column): all books are disabled");
                $column = null;
            } elseif (!preg_match('/^[a-z_][a-z0-9_]{0,63}$/D', trim($raw))) {
                $this->problem('fail', 'galbridge_visibility_column', 'invalid column name: all books are disabled');
                $column = null;
            } else {
                $column = trim($raw);
            }
            $this->memo['visibility_column'] = $column;
        }
        return $this->memo['visibility_column'];
    }

    /**
     * Allowed IMAP endpoints: "host" (any port of that host) or "host:port" (IPv6 with a port:
     * "[::1]:993"), lower case - or a local socket, "unix:///run/dovecot/imap.sock" (the path as
     * written). Empty = derive from imap_host. null = malformed (fail closed).
     *
     * @return null|list<string>
     */
    public function imapHosts(): ?array
    {
        if (!array_key_exists('imap_hosts', $this->memo)) {
            $invalid = [];
            $list = Normalizer::list($this->get('galbridge_imap_hosts'), [self::class, 'imapEndpoint'], true, $invalid);
            if ($list === null || $list === ['*']) {
                $this->problem('fail', 'galbridge_imap_hosts', 'entries must be host names or IP addresses, optionally with a :port, or unix:///path for a local socket (no other scheme, no %placeholders): the plugin is disabled');
                $list = null;
            }
            $this->memo['imap_hosts'] = $list;
        }
        return $this->memo['imap_hosts'];
    }

    /**
     * Normalise one galbridge_imap_hosts entry: "host", "host:port" ("[v6]:port") or a local socket
     * ("unix:///path"); null = invalid.
     */
    public static function imapEndpoint(mixed $v): ?string
    {
        if (is_string($v) && ($unix = self::unixEndpoint($v)) !== null) {
            return $unix;
        }
        if (!is_string($v) || $v === '' || preg_match('~[%/\s]|://~', $v)) {
            return null;
        }
        $v = strtolower(trim($v));
        $port = null;
        if (preg_match('/^\[([0-9a-f:.]+)\](?::([0-9]{1,5}))?$/D', $v, $m)) {
            $host = filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? $m[1] : null;
            $port = $m[2] ?? null;
        } elseif (filter_var($v, FILTER_VALIDATE_IP) !== false) {
            $host = $v; // a bare IPv4 or IPv6 literal
        } elseif (preg_match('/^([^:]+):([0-9]{1,5})$/D', $v, $m)) {
            $host = filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $m[1] : Normalizer::domain($m[1]);
            $port = $m[2];
        } else {
            $host = str_contains($v, ':') ? null : Normalizer::domain($v);
        }
        if ($host === null) {
            return null;
        }
        if ($port === null) {
            return $host;
        }
        $p = (int) $port;
        if ($p < 1 || $p > 65535) {
            return null;
        }
        return (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $p;
    }

    /**
     * An IMAP server on a local socket in Roundcube's notation, "unix:///absolute/path": the scheme
     * in lower case, the path exactly as written (file names are case-sensitive; no whitespace, no
     * %placeholder). null = not such a URI. A socket has no port and no host name: it is matched as
     * a whole or not at all.
     */
    public static function unixEndpoint(string $v): ?string
    {
        return preg_match('~^unix://(/[^\s%\x00-\x1F\x7F]+)$~Di', $v, $m) === 1 ? 'unix://' . $m[1] : null;
    }

    /**
     * Refused database roles (lower case).
     *
     * @return list<string>
     */
    public function refusedUsers(): array
    {
        $raw = $this->get('galbridge_db_refused_users');
        if (!is_array($raw)) {
            // a safety net must not vanish because of a typo: keep refusing the built-in names
            $this->problem('fail', 'galbridge_db_refused_users', 'must be an array of role names (' . get_debug_type($raw) . ' given): the built-in list is used');
            $raw = self::DEFAULTS['galbridge_db_refused_users'];
        }
        $out = [];
        foreach ($raw as $u) {
            if (is_string($u) && trim($u) !== '') {
                $out[] = strtolower(trim($u));
            } else {
                $this->problem('error', 'galbridge_db_refused_users', 'entry ignored (not a role name)');
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Run every validating accessor once, so problems() is complete (diag calls this first; at
     * runtime each accessor validates lazily when it is first needed).
     */
    public function validate(): void
    {
        $this->organizations();
        $this->fields();
        $this->hiddenAddresses();
        $this->hiddenLocalparts();
        $this->visibilityColumn();
        $this->imapHosts();
        $this->refusedUsers();
        $this->switchesValid();
        foreach (self::DEFAULTS as $name => $default) {
            if (is_int($default)) {
                $this->int($name, PHP_INT_MIN, PHP_INT_MAX);
            }
        }
        foreach (['galbridge_db_dsn', 'galbridge_domain_book_name', 'galbridge_global_book_name'] as $name) {
            $this->string($name);
        }
        foreach (['galbridge_domain_viewer_domains', 'galbridge_global_viewer_domains', 'galbridge_global_data_domains'] as $allow) {
            $this->domainAllowList($allow);
        }
        foreach (['galbridge_domain_viewer_domains_exclude', 'galbridge_global_viewer_domains_exclude', 'galbridge_global_data_domains_exclude'] as $deny) {
            $this->domainDenyList($deny);
        }
        $this->choice('galbridge_domain_groups', self::GROUPINGS);
        $this->choice('galbridge_global_groups', self::GROUPINGS);
        $this->choice('galbridge_list_policy', self::LIST_POLICIES);
    }

    /**
     * The DSN of Roundcube's password plugin (its user is refused for GAL Bridge). iRedMail writes
     * it into plugins/password/config.inc.php, which the main configuration does not include.
     */
    public function passwordPluginDsn(): ?string
    {
        $dsn = $this->adminCore('password_db_dsn');
        if (is_string($dsn) && $dsn !== '') {
            return $dsn;
        }
        $fresh = new \rcube_config();
        $dir = defined('RCUBE_PLUGINS_DIR') ? RCUBE_PLUGINS_DIR : (defined('INSTALL_PATH') ? INSTALL_PATH . 'plugins/' : '');
        if ($dir !== '' && is_readable($dir . 'password/config.inc.php')) {
            $fresh->load_from_file($dir . 'password/config.inc.php');
        } else {
            $fresh->load_from_file('password.inc.php');
        }
        $dsn = $fresh->get('password_db_dsn');
        return is_string($dsn) && $dsn !== '' ? $dsn : null;
    }

    /**
     * Keyed hash (HMAC with des_key) of the effective administrator configuration of this plugin.
     * Binds the cached Viewer and stored search specs, so a configuration change takes effect
     * immediately.
     */
    public function fingerprint(): string
    {
        if (!isset($this->memo['fingerprint'])) {
            $values = [];
            foreach (array_keys(self::DEFAULTS) as $name) {
                $values[$name] = $this->get($name);
            }
            // Switches as they are in EFFECT: for one that is set through the environment Roundcube
            // hands out its own conversion of the text ("false" becomes true), which is the same for
            // different effective values - a change of the variable would not change the fingerprint.
            foreach ([...self::BOOLEANS, ...self::TRISTATES] as $name) {
                $values[$name] = $this->effectiveSwitch($name);
            }
            $values['imap_host'] = $this->adminCore('imap_host');
            $values['include_host_config'] = $this->adminCore('include_host_config');
            // Keyed with Roundcube's server secret: the value is stored in the session (Viewer cache,
            // search specs) and covers the DSN, so without a key it would let whoever reads the session
            // store test candidates for the database password offline.
            $key = $this->adminCore('des_key', $this->config->get('des_key'));
            $this->memo['fingerprint'] = substr(hash_hmac('sha256', serialize($values), 'galbridge-fingerprint:' . (is_string($key) ? $key : '')), 0, 32);
        }
        return $this->memo['fingerprint'];
    }

    /**
     * Problems found while reading the configuration (for diag and the log).
     *
     * @return list<array{level: string, option: string, message: string}>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    private function problem(string $level, string $option, string $message): void
    {
        foreach ($this->problems as $p) {
            if ($p['option'] === $option && $p['message'] === $message) {
                return;
            }
        }
        $this->problems[] = ['level' => $level, 'option' => $option, 'message' => $message];
        Log::error('config', 'config_invalid: ' . $message, ['option' => $option]);
    }

    /**
     * Configuration as written by the administrator (fresh load without user preferences).
     *
     * @return array<string, mixed>
     */
    private function adminValues(): array
    {
        if ($this->adminValues === null) {
            $this->adminValues = $this->adminConfig()->all();
        }
        return $this->adminValues;
    }

    /**
     * The configuration files loaded afresh (core files and the plugin's own), without the stored
     * preferences of any user.
     */
    private function adminConfig(): \rcube_config
    {
        if ($this->adminConfig === null) {
            $fresh = new \rcube_config();
            $dir = defined('RCUBE_PLUGINS_DIR') ? RCUBE_PLUGINS_DIR : (defined('INSTALL_PATH') ? INSTALL_PATH . 'plugins/' : '');
            if ($dir !== '' && is_file($dir . 'galbridge/config.inc.php')) {
                $fresh->load_from_file($dir . 'galbridge/config.inc.php');
            } else {
                $fresh->load_from_file('galbridge.inc.php');
            }
            $this->adminConfig = $fresh;
        }
        return $this->adminConfig;
    }
}
