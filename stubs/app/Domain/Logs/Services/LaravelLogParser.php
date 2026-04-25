<?php

namespace App\Domain\Logs\Services;

use App\Domain\Logs\DTOs\LogEntryDTO;
use Carbon\Carbon;

/**
 * Stateless parser for Laravel log lines.
 *
 * Format recognised:
 *   [YYYY-MM-DD HH:MM:SS(.uuuuuu)?] env.LEVEL: message {optional-json-context}
 *
 * Multiline stacks (anything that doesn't match the header regex) are
 * appended to the previous entry's `stack` field by the caller. This
 * service only knows how to interpret a single line and assemble a DTO.
 *
 * Lines that appear before the file's first header line are bundled into
 * a raw entry by the caller (no real timestamp/level) so they remain
 * visible instead of being silently dropped.
 */
class LaravelLogParser
{
    public const RAW_LEVEL = 'raw';

    private const ENTRY_REGEX = '/^\[(?<ts>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.\d+)?\] (?<env>\w+)\.(?<level>\w+): (?<msg>.*)$/';

    /**
     * Try to parse a line as a new entry header.
     * Returns the header parts on match, null if the line is a continuation.
     *
     * @return array{timestamp: string, env: string, level: string, message: string}|null
     */
    public function parseLine(string $line): ?array
    {
        if (! preg_match(self::ENTRY_REGEX, $line, $matches)) {
            return null;
        }

        return [
            'timestamp' => $matches['ts'],
            'env' => $matches['env'],
            'level' => strtolower($matches['level']),
            'message' => $matches['msg'],
        ];
    }

    /**
     * Build a LogEntryDTO from a parsed header + accumulated continuation lines.
     *
     * @param  array{timestamp: string, env: string, level: string, message: string}  $header
     * @param  list<string>  $stackLines
     */
    public function buildEntry(array $header, array $stackLines): LogEntryDTO
    {
        $message = $header['message'];
        $context = null;

        // Try to peel off a trailing JSON context block: " {...}" at end of message.
        if (preg_match('/^(?<msg>.*?)\s+(?<json>\{.*\})$/', $message, $m)) {
            $decoded = json_decode($m['json'], true);
            if (is_array($decoded)) {
                $message = $m['msg'];
                $context = $decoded;
            }
        }

        $stack = empty($stackLines) ? null : implode("\n", $stackLines);

        return new LogEntryDTO(
            timestamp: Carbon::parse($header['timestamp']),
            level: $header['level'],
            env: $header['env'],
            message: $message,
            context: $context,
            stack: $stack,
            isRaw: false,
        );
    }

    /**
     * Build a raw LogEntryDTO from one or more lines that could not be
     * parsed as a Laravel-format header. Used for content that appears
     * before the first header in a file (or for files written by tools
     * that don't follow the Laravel single/daily formatter).
     *
     * Sentinel timestamp (epoch 0) signals "no real time" — the UI hides
     * the timestamp for raw entries.
     *
     * @param  list<string>  $lines
     */
    public function buildRawEntry(array $lines): LogEntryDTO
    {
        return new LogEntryDTO(
            timestamp: Carbon::createFromTimestamp(0),
            level: self::RAW_LEVEL,
            env: '-',
            message: implode("\n", $lines),
            context: null,
            stack: null,
            isRaw: true,
        );
    }
}
