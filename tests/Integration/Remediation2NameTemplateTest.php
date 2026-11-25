<?php

declare(strict_types=1);

namespace GalBridge\Tests\Integration;

use GalBridge\AddressBook;
use GalBridge\Directory\BookDefinition;
use GalBridge\Directory\IdCodec;
use GalBridge\Log;
use GalBridge\Normalizer;
use GalBridge\Tests\Support\Env;
use GalBridge\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

/**
 * Second verification pass, F-19 (completed): every text field of an entry under every name
 * template core may compose recipient names with stays inert - whatever the text holds: the
 * placeholders of the template, an at-sign and its look-alikes, the delimiters of an RFC 2047
 * encoded-word, the replacement patterns of JavaScript's String.replace() and the highlight markers
 * of Roundcube's suggestion list. Organization labels follow the at-sign rule as well.
 *
 * @license GPL-3.0-or-later
 */
final class Remediation2NameTemplateTest extends TestCase
{
    private const JAN = 'jan.kowalski@example.com';

    /** a payload of each class, written into EVERY text field of a throw-away entry */
    private const PAYLOADS = [
        '{name}{email}', '{firstname}{surname}', '{department}{phone}', '{mobile}{organization}',
        'ceo@bank.example', "ceo\u{FF20}bank.example", "ceo\u{FE6B}bank.example",
        '=?UTF-8?B?Y2VvQGJhbmsuZXhhbXBsZQ==?=', '?UTF-8?Q?x?', '=x=', '?x?',
        '$&$`', '$1$2', '$<x>', '$$', "\$'x",
        '%%', '##x%%', '%x%', '50%%%',
    ];

    /** templates with every placeholder, fields next to each other and text before the name */
    private const TEMPLATES = [
        '{name}', '{name} {department}', '{name}{department}{phone}', '{department} {name}',
        '{firstname} {surname} ({department})', '{name} <{email}> {organization} {mobile}', '{surname}{firstname}{phone}{mobile}',
        '{name} ({organization})',
    ];

    protected function setUp(): void
    {
        if (!GALBRIDGE_RC_LOADED) {
            self::markTestSkipped('needs Roundcube 1.7 (GALBRIDGE_RC)');
        }
        $_SESSION = [];
        $GLOBALS['galbridge_test_log'] = [];
        Log::resetOnce();
        (new \ReflectionProperty(AddressBook::class, 'unavailableShown'))->setValue(null, false);
    }

    protected function tearDown(): void
    {
        Fixture::restore();
        $_SESSION = [];
    }

    #[DataProviderExternal(Env::class, 'engines')]
    public function testEveryFieldUnderEveryTemplateStaysInert(string $dsn): void
    {
        $rows = [];
        foreach (self::PAYLOADS as $i => $payload) {
            $p = str_replace("'", "''", $payload);
            $rows[] = sprintf("('inert%02d@example.com', '%s', '%s', '%s', 'example.com', '%s', '%s', '%s', 'example.com/i/n/e/inert%02d')", $i, $p, $p, $p, $p, $p, $p, $i);
        }
        Fixture::alter(
            $dsn,
            ['INSERT INTO mailbox (username, name, first_name, last_name, domain, department, telephone, mobile, maildir) VALUES ' . implode(', ', $rows)],
            ["DELETE FROM mailbox WHERE username LIKE 'inert__@example.com'"],
        );
        $options = [
            'galbridge_fields' => ['name', 'firstname', 'surname', 'email', 'department', 'phone', 'mobile', 'organization'],
            'galbridge_organizations' => ['Org {name} $& %%' => ['example.com']],
        ];
        $book = Env::factory(Env::config($dsn, $options), self::JAN)->create(BookDefinition::DOMAIN);
        self::assertInstanceOf(AddressBook::class, $book);
        foreach (self::PAYLOADS as $i => $payload) {
            $email = sprintf('inert%02d@example.com', $i);
            $record = $book->get_record(IdCodec::contactId(IdCodec::PERSON, $email), true);
            self::assertIsArray($record, $email);
            foreach (self::TEMPLATES as $template) {
                foreach ([
                    'list name' => \rcube_addressbook::compose_search_name($record, null, (string) $record['name'], $template),
                    'search name' => \rcube_addressbook::compose_search_name($record, $email, null, $template),
                ] as $kind => $composed) {
                    // the entry's own address may appear through {email} and is what it is
                    $text = str_replace($email, '', $composed);
                    $label = json_encode($payload) . " / {$template} / {$kind}: {$composed}";
                    self::assertDoesNotMatchRegularExpression('/[{}]/', $text, $label . ': no placeholder of the directory text');
                    self::assertFalse(Normalizer::containsAt($text), $label . ': no at-sign outside the own address');
                    self::assertStringNotContainsString('=?', $text, $label);
                    self::assertStringNotContainsString('?=', $text, $label);
                    self::assertSame($text, \rcube_mime::decode_header($text, 'UTF-8'), $label . ' (decoded as a header)');
                    self::assertStringNotContainsString('$', $text, $label . ': no replacement pattern for String.replace()');
                    self::assertStringNotContainsString('%%', $text, $label . ': no highlight marker');
                    self::assertSame(0, substr_count(str_replace($email, '', format_email_recipient($email, $composed)), '@'), $label . ' (the recipient)');
                }
            }
        }
    }

    /**
     * An organization label is text of the administrator that a template with {organization} puts
     * into recipient names: it is cut at the at-sign (and the configuration reports it).
     */
    #[DataProviderExternal(Env::class, 'engines')]
    public function testOrganizationLabelIsCutAtTheAtSign(string $dsn): void
    {
        $config = Env::config($dsn, [
            'galbridge_fields' => ['name', 'email', 'organization'],
            'galbridge_organizations' => ["Acme ceo\u{FF20}bank.example" => ['example.com'], 'Org $1 50%%' => ['example.org']],
        ]);
        $book = Env::factory($config, self::JAN)->create(BookDefinition::DOMAIN);
        self::assertInstanceOf(AddressBook::class, $book);
        $record = $book->get_record(IdCodec::contactId(IdCodec::PERSON, self::JAN), true);
        self::assertIsArray($record);
        self::assertSame('Acme ceo', $record['organization'] ?? null);
        self::assertContains('organization 1: label cut at the at-sign (a label must not look like an e-mail address)', array_column($config->problems(), 'message'));
        // the other notations as in every display text: '$' full-width, no "%%", no '%' at the end
        self::assertSame(['Acme ceo', "Org \u{FF04}1 50% \u{FF05}"], array_map('strval', array_keys($config->organizations())));
    }
}
