<?php

declare(strict_types=1);

namespace GalBridge\Tests\Support;

/**
 * Reads .github/workflows/ci.yml as text - the jobs and steps the tests about CI and release
 * assert on (second verification pass, F-21 / F-22 / F-24 / F-25 / F-34). No YAML library: the file
 * keeps the layout these helpers rely on (two-space job keys under "jobs:", steps starting with
 * "      - "), and the tests fail when it does not.
 *
 * @license GPL-3.0-or-later
 */
final class Workflow
{
    public static function path(): string
    {
        return dirname(__DIR__, 2) . '/.github/workflows/ci.yml';
    }

    public static function text(): string
    {
        return (string) file_get_contents(self::path());
    }

    /**
     * The text without comment lines (a "#" as the first non-blank character): what runs.
     */
    public static function code(string $text): string
    {
        return implode("\n", array_filter(explode("\n", $text), static fn (string $l): bool => !str_starts_with(ltrim($l), '#')));
    }

    /**
     * The job blocks: job id => its text (everything up to the next job).
     *
     * @return array<string, string>
     */
    public static function jobs(): array
    {
        $lines = explode("\n", self::text());
        $start = array_search('jobs:', $lines, true);
        if ($start === false) {
            return [];
        }
        $jobs = [];
        $current = null;
        foreach (array_slice($lines, (int) $start + 1) as $line) {
            if (preg_match('/^  ([a-z][a-z0-9-]*):$/D', $line, $m) === 1) {
                $current = $m[1];
                $jobs[$current] = '';
                continue;
            }
            if ($current !== null) {
                $jobs[$current] .= $line . "\n";
            }
        }
        return $jobs;
    }

    /**
     * The steps of a job, in order: each step's text (from its "      - " line to the next).
     *
     * @return list<string>
     */
    public static function steps(string $job): array
    {
        $block = self::jobs()[$job] ?? '';
        $steps = [];
        $in = false;
        foreach (explode("\n", $block) as $line) {
            if (preg_match('/^    steps:$/D', $line) === 1) {
                $in = true;
                continue;
            }
            if (!$in) {
                continue;
            }
            if (str_starts_with($line, '      - ')) {
                $steps[] = $line . "\n";
            } elseif ($steps !== []) {
                $steps[count($steps) - 1] .= $line . "\n";
            }
        }
        return $steps;
    }

    /**
     * The index of the first step of a job whose code contains the text, or null.
     */
    public static function stepIndex(string $job, string $needle): ?int
    {
        foreach (self::steps($job) as $i => $step) {
            if (str_contains(self::code($step), $needle)) {
                return $i;
            }
        }
        return null;
    }
}
