<?php

declare(strict_types=1);

namespace Seismo\Config;

/**
 * Surgical upsert of a single `define('CONST', …);` line in `config.local.php`.
 *
 * Used from Settings → General for migrate key and admin password hash — secrets
 * stay in the file, not in `system_config`.
 */
final class ConfigLocalDefinePatcher
{
    /**
     * @return array{ok: bool, error: ?string}
     */
    public static function upsertStringDefine(string $absolutePath, string $constantName, string $value): array
    {
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $constantName)) {
            return ['ok' => false, 'error' => 'Invalid constant name.'];
        }
        if (!is_readable($absolutePath)) {
            return ['ok' => false, 'error' => 'config.local.php is not readable.'];
        }
        $raw = file_get_contents($absolutePath);
        if ($raw === false) {
            return ['ok' => false, 'error' => 'Could not read config.local.php.'];
        }

        $newLine = 'define(\'' . $constantName . '\', ' . var_export($value, true) . ');';
        $lines    = preg_split("/\r\n|\n|\r/", $raw) ?: [];
        $out      = [];
        $replaced = false;
        $pattern  = '/^define\s*\(\s*[\'"]' . preg_quote($constantName, '/') . '[\'"]\s*,/';

        foreach ($lines as $line) {
            if (!$replaced && preg_match($pattern, $line) === 1) {
                $out[]    = $newLine;
                $replaced = true;
            } else {
                $out[] = $line;
            }
        }
        if (!$replaced) {
            $out[] = $newLine;
        }

        $newBody = implode("\n", $out);
        if ($newBody !== '' && !str_ends_with($newBody, "\n")) {
            $newBody .= "\n";
        }

        if (!is_writable($absolutePath)) {
            return ['ok' => false, 'error' => 'not_writable'];
        }
        if (file_put_contents($absolutePath, $newBody, LOCK_EX) === false) {
            return ['ok' => false, 'error' => 'file_put_contents failed.'];
        }

        return ['ok' => true, 'error' => null];
    }
}
