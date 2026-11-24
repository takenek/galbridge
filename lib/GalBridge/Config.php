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
 *  - '*' is honoured only as the sole element of a list.
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

    private const ADMIN_ONLY_PREFIX = 'galbridge_';

    /** @var null|array<string, mixed> */
    private ?array $adminValues = null;

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
            return array_key_exists($name, $admin) ? $admin[$name] : (self::DEFAULTS[$name] ?? null);
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

    public function bool(string $name): bool
    {
        return (bool) $this->get($name);
    }

    public function int(string $name, int $min, int $max): int
    {
        $v = $this->get($name);
        $v = is_numeric($v) ? (int) $v : (int) (self::DEFAULTS[$name] ?? $min);
        return max($min, min($max, $v));
    }

    public function string(string $name): string
    {
        $v = $this->get($name);
        return is_scalar($v) ? trim((string) $v) : '';
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
     * @return array<string, list<string>>
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
                foreach ($list as $d) {
                    $seen[$d] = ($seen[$d] ?? 0) + 1;
                }
                $orgs[$label] = $list;
            }
            $dupes = array_keys(array_filter($seen, static fn (int $n): bool => $n > 1));
            if ($dupes !== []) {
                $this->problem('fail', 'galbridge_organizations', 'domains listed in more than one organization fall back to their own domain: ' . implode(', ', $dupes));
                foreach ($orgs as $label => $list) {
                    $orgs[$label] = array_values(array_diff($list, $dupes));
                }
            }
            $this->memo['organizations'] = array_filter($orgs, static fn (array $l): bool => $l !== []);
        }
        /** @var array<string, list<string>> */
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
     * Returns null when the configured name is invalid (fail closed).
     */
    public function visibilityColumn(): ?string
    {
        $c = $this->string('galbridge_visibility_column');
        if ($c === '') {
            return '';
        }
        if (!preg_match('/^[a-z_][a-z0-9_]{0,63}$/', $c)) {
            $this->problem('fail', 'galbridge_visibility_column', 'invalid column name: all books are disabled');
            return null;
        }
        return $c;
    }

    /**
     * Allowed IMAP hosts (bare host names / IP literals, lower case). Empty = derive from imap_host.
     * null = malformed (fail closed).
     *
     * @return null|list<string>
     */
    public function imapHosts(): ?array
    {
        if (!array_key_exists('imap_hosts', $this->memo)) {
            $normalise = static function (mixed $v): ?string {
                if (!is_string($v) || $v === '' || preg_match('~[%/\s]|://~', $v)) {
                    return null;
                }
                $v = strtolower(trim($v));
                if (filter_var(trim($v, '[]'), FILTER_VALIDATE_IP) !== false) {
                    return trim($v, '[]');
                }
                return str_contains($v, ':') ? null : Normalizer::domain($v);
            };
            $invalid = [];
            $list = Normalizer::list($this->get('galbridge_imap_hosts'), $normalise, true, $invalid);
            if ($list === null || $list === ['*']) {
                $this->problem('fail', 'galbridge_imap_hosts', 'entries must be bare host names or IP addresses (no scheme, port or %placeholders): the plugin is disabled');
                $list = null;
            }
            $this->memo['imap_hosts'] = $list;
        }
        return $this->memo['imap_hosts'];
    }

    /**
     * Refused database roles (lower case).
     *
     * @return list<string>
     */
    public function refusedUsers(): array
    {
        $raw = $this->get('galbridge_db_refused_users');
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $u) {
            if (is_string($u) && trim($u) !== '') {
                $out[] = strtolower(trim($u));
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Hash of the effective administrator configuration of this plugin. Binds the cached Viewer and
     * stored search specs, so a configuration change takes effect immediately.
     */
    public function fingerprint(): string
    {
        $values = [];
        foreach (array_keys(self::DEFAULTS) as $name) {
            $values[$name] = $this->get($name);
        }
        $values['imap_host'] = $this->adminCore('imap_host');
        return substr(hash('sha256', serialize($values)), 0, 32);
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
            $fresh = new \rcube_config();
            $dir = defined('RCUBE_PLUGINS_DIR') ? RCUBE_PLUGINS_DIR : (defined('INSTALL_PATH') ? INSTALL_PATH . 'plugins/' : '');
            if ($dir !== '' && is_file($dir . 'galbridge/config.inc.php')) {
                $fresh->load_from_file($dir . 'galbridge/config.inc.php');
            } else {
                $fresh->load_from_file('galbridge.inc.php');
            }
            $this->adminValues = $fresh->all();
        }
        return $this->adminValues;
    }
}
