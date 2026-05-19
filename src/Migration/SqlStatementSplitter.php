<?php
/**
 * Split a .sql file into executable statements for PDO::exec (one at a time).
 *
 * Strips block comments and line comments. Line comments are recognised as:
 *   - whole lines whose first non-whitespace characters are `--`
 *   - trailing ` -- …` where `--` is preceded by whitespace (MySQL line comment)
 *
 * Assumption: the consolidated schema does not put `--` inside a quoted string
 * on the same line as other SQL in a way that would be mistaken for a comment.
 * If that changes, use a proper SQL lexer or split migrations manually.
 */

declare(strict_types=1);

namespace Seismo\Migration;

final class SqlStatementSplitter
{
    /**
     * @return list<string>
     */
    public static function statements(string $sql): array
    {
        $sql = self::stripBlockComments($sql);
        $sql = self::stripLineComments($sql);
        $chunks = explode(';', $sql);
        $out = [];
        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk !== '') {
                $out[] = $chunk;
            }
        }
        return $out;
    }

    private static function stripBlockComments(string $sql): string
    {
        return (string)preg_replace('/\/\*[\s\S]*?\*\//', '', $sql);
    }

    private static function stripLineComments(string $sql): string
    {
        $lines = explode("\n", $sql);
        foreach ($lines as $i => $line) {
            if (str_starts_with(ltrim($line), '--')) {
                $lines[$i] = '';
                continue;
            }
            $lines[$i] = (string)preg_replace('/\s--[^\r\n]*$/', '', $line);
        }
        return implode("\n", $lines);
    }
}
