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

namespace GalBridge\Cli;

use GalBridge\Log;

/**
 * Stream filter that bin/galbridge.sh appends to STDERR while diag runs (stream_filter_append(STDERR,
 * self::NAME, STREAM_FILTER_WRITE)): whatever other code writes there - Roundcube's
 * rcube::raise_error() writes the text of a database driver, of a server's error packet or of a
 * failed query to STDERR in the command line, unchanged - reaches the terminal neutralised
 * (Terminal::clean()) and with DSN passwords masked (Log::maskCredentials()).
 *
 * A character split across two writes shows as the escapes of its bytes: harmless, and visible.
 */
final class StderrFilter extends \php_user_filter
{
    public const NAME = 'galbridge.terminal';

    /**
     * @param resource $in
     * @param resource $out
     * @param int      $consumed
     */
    #[\Override]
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while (($bucket = stream_bucket_make_writeable($in)) !== null) {
            $data = $bucket->data;
            if (is_string($data)) {
                $consumed += strlen($data);
                $bucket->data = Terminal::clean(Log::maskCredentials($data));
            }
            stream_bucket_append($out, $bucket);
        }
        return PSFS_PASS_ON;
    }
}
