<?php

declare(strict_types=1);

namespace Seismo\Core\Lex;

use Seismo\Service\Http\BaseClient;
use Seismo\Service\Http\HttpClientException;

/**
 * Fetch BGBl Regelungstext corpus from recht.bund.de PDFs.
 *
 * RSS items are metadata-only; full law text lives in {@code regelungstext.pdf}.
 * Requires {@code pdftotext} (poppler-utils) on the host.
 */
final class LexRechtBundContentFetcher
{
    public const MAX_CONTENT_BYTES = 1_048_576;

    private const PDF_SUFFIX = 'regelungstext.pdf?__blob=publicationFile&v=1';

    public function __construct(
        private BaseClient $http = new BaseClient(45),
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function attachContentToRows(array $rows, int $limit): array
    {
        $limit = max(0, $limit);
        if ($limit === 0 || $rows === []) {
            return $rows;
        }

        $fetched = 0;
        foreach ($rows as &$row) {
            if ($fetched >= $limit) {
                break;
            }
            if (trim((string)($row['content'] ?? '')) !== '') {
                continue;
            }
            $url = self::publicationUrlFromRow($row);
            if ($url === null) {
                continue;
            }
            $content = $this->fetchPlainTextFromPublicationUrl($url);
            if ($content === null || $content === '') {
                continue;
            }
            $row['content'] = $content;
            $fetched++;
        }
        unset($row);

        return $rows;
    }

    public function fetchPlainTextFromPublicationUrl(string $publicationUrl): ?string
    {
        $pdfUrl = self::regelungstextPdfUrl($publicationUrl);
        if ($pdfUrl === null) {
            return null;
        }

        return $this->fetchPlainTextFromPdfUrl($pdfUrl);
    }

    public static function publicationUrlFromRow(array $row): ?string
    {
        foreach (['work_uri', 'eurlex_url'] as $key) {
            $url = trim((string)($row[$key] ?? ''));
            if ($url !== '' && preg_match('#^https://www\.recht\.bund\.de/#i', $url)) {
                return $url;
            }
        }

        return null;
    }

    public static function regelungstextPdfUrl(string $publicationUrl): ?string
    {
        $publicationUrl = trim($publicationUrl);
        if ($publicationUrl === '') {
            return null;
        }

        if (preg_match('#^https://www\.recht\.bund\.de/eli/bund/bgbl-([12])/(\d{4})/(\d+)#i', $publicationUrl, $m)) {
            return 'https://www.recht.bund.de/bgbl/' . $m[1] . '/' . $m[2] . '/' . $m[3] . '/' . self::PDF_SUFFIX;
        }

        if (preg_match('#^https://www\.recht\.bund\.de/bgbl/([12])/(\d{4})/(\d+)#i', $publicationUrl, $m)) {
            return 'https://www.recht.bund.de/bgbl/' . $m[1] . '/' . $m[2] . '/' . $m[3] . '/' . self::PDF_SUFFIX;
        }

        return null;
    }

    public static function isPdftotextAvailable(): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }

        $cmd = PHP_OS_FAMILY === 'Windows' ? 'where pdftotext 2>NUL' : 'command -v pdftotext 2>/dev/null';
        $proc = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($proc)) {
            return false;
        }
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return trim((string)$out) !== '';
    }

    public function fetchPlainTextFromPdfUrl(string $pdfUrl): ?string
    {
        try {
            $resp = $this->http->get($pdfUrl, [
                'Accept' => 'application/pdf,application/octet-stream;q=0.9,*/*;q=0.8',
            ]);
        } catch (HttpClientException) {
            return null;
        }

        if (!$resp->isOk() || $resp->body === '' || !str_starts_with($resp->body, '%PDF')) {
            return null;
        }

        return $this->plainTextFromPdfBytes($resp->body);
    }

    public function plainTextFromPdfBytes(string $pdfBytes): ?string
    {
        if (!self::isPdftotextAvailable()) {
            return null;
        }

        $tmpBase = tempnam(sys_get_temp_dir(), 'seismo_de_');
        if ($tmpBase === false) {
            return null;
        }

        $pdfPath = $tmpBase . '.pdf';
        $txtPath = $tmpBase . '.txt';
        @unlink($tmpBase);

        try {
            if (file_put_contents($pdfPath, $pdfBytes) === false) {
                return null;
            }

            $cmd = 'pdftotext -enc UTF-8 -nopgbrk ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($txtPath);
            $proc = proc_open(
                $cmd,
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            if (!is_resource($proc)) {
                return null;
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($proc);
            if ($exitCode !== 0 || !is_file($txtPath)) {
                return null;
            }

            $plain = LexPlainText::normalize((string)file_get_contents($txtPath));
            if ($plain === '') {
                return null;
            }
            if (strlen($plain) > self::MAX_CONTENT_BYTES) {
                $plain = \Seismo\Util\Utf8ByteCap::truncate($plain, self::MAX_CONTENT_BYTES, "\n\n[truncated]");
            }

            return $plain;
        } finally {
            @unlink($pdfPath);
            @unlink($txtPath);
        }
    }
}
