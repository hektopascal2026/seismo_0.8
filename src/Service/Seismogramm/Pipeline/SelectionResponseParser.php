<?php

declare(strict_types=1);

namespace Seismo\Service\Seismogramm\Pipeline;

use Seismo\Util\LenientJsonParser;

final class SelectionResponseParser
{
    /**
     * Parses the response payload from Pass 1 selection.
     * Reuses lenient JSON parsing and falls back to regex search if needed.
     *
     * @param string $rawText The raw text output from the model candidates.
     * @param list<array<string, mixed>> $entries Eligible entries context.
     * @param int $maxKeys Maximum items allowed to return.
     * @return list<string> list of parsed or inferred keys
     */
    public function parseSelectionResponse(string $rawText, array $entries, int $maxKeys): array
    {
        $rawText = trim($rawText);
        if ($rawText === '') {
            return [];
        }

        $valid = $this->validKeysForEntries($entries);

        $parsed = LenientJsonParser::parseObject($rawText);
        if (is_array($parsed) && isset($parsed['used_entry_keys']) && is_array($parsed['used_entry_keys'])) {
            $keys = $this->filterValidKeys($parsed['used_entry_keys'], $valid, $maxKeys);
            if ($keys !== []) {
                return $keys;
            }
        }

        return $this->inferUsedEntryKeys($rawText, $valid, $maxKeys);
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return array<string, true>
     */
    private function validKeysForEntries(array $entries): array
    {
        $valid = [];
        foreach ($entries as $e) {
            $type = (string)($e['entry_type'] ?? '');
            $id   = (string)($e['entry_id'] ?? '');
            if ($type !== '' && $id !== '' && ctype_digit($id)) {
                $valid[strtolower($type . ':' . $id)] = true;
            }
        }

        return $valid;
    }

    /**
     * @param list<mixed> $rawKeys
     * @param array<string, true> $valid
     * @return list<string>
     */
    private function filterValidKeys(array $rawKeys, array $valid, int $maxKeys): array
    {
        if ($valid === [] || $maxKeys < 1) {
            return [];
        }

        $found = [];
        foreach ($rawKeys as $k) {
            $key = strtolower(trim((string)$k));
            if ($key === '' || !isset($valid[$key])) {
                continue;
            }
            if (!in_array($key, $found, true)) {
                $found[] = $key;
            }
            if (count($found) >= $maxKeys) {
                break;
            }
        }

        return $found;
    }

    /**
     * @param array<string, true> $valid
     * @return list<string>
     */
    private function inferUsedEntryKeys(string $text, array $valid, int $maxKeys): array
    {
        if ($text === '' || $valid === [] || $maxKeys < 1) {
            return [];
        }

        $found = [];
        $patterns = [
            '/\[ID:\s*([a-z][a-z0-9_]*:\d+)\s*\]/i',
            '/\b([a-z][a-z0-9_]*:\d+)\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $text, $matches)) {
                continue;
            }
            foreach ($matches[1] as $raw) {
                $key = strtolower(trim((string)$raw));
                if (!isset($valid[$key])) {
                    continue;
                }
                if (!in_array($key, $found, true)) {
                    $found[] = $key;
                }
                if (count($found) >= $maxKeys) {
                    return $found;
                }
            }
        }

        return $found;
    }
}
