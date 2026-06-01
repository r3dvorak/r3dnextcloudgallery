<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Fields.r3dnextcloudgallery
 *
 * @copyright   (C) 2026 Richard Dvorak / R3D
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace R3d\Plugin\Fields\R3dnextcloudgallery\Service;

defined('_JEXEC') or die;

use RuntimeException;
use SimpleXMLElement;

final class PublicWebDavClient
{
    private const MAX_SHARE_TITLE_BODY_BYTES = 262144; // 256 KB

    /**
     * @param string[] $allowedShareHosts
     */
    public function __construct(private array $allowedShareHosts = [])
    {
    }

    public function fetchShareTitle(string $shareUrl): string
    {
        $this->validateUrlAllowed($shareUrl);

        $ch = curl_init($shareUrl);
        if ($ch === false) {
            return '';
        }

        $body = '';
        $bytesRead = 0;
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_USERAGENT, 'r3dnextcloudgallery/1.0');
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($ch, string $chunk) use (&$body, &$bytesRead): int {
            $len = strlen($chunk);
            $bytesRead += $len;
            if ($bytesRead > self::MAX_SHARE_TITLE_BODY_BYTES) {
                return 0;
            }
            $body .= $chunk;
            return $len;
        });

        $result = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === '' || $statusCode < 200 || $statusCode > 299 || $result === false) {
            return '';
        }

        if (preg_match('/<meta[^>]+property="og:title"[^>]+content="([^"]+)"/i', $body, $m)) {
            return $this->sanitizeTitleValue($m[1]);
        }

        if (preg_match('/<input[^>]*class="[^"]*sharingTabDetailsView__label[^"]*"[^>]*value="([^"]*)"/i', $body, $m)) {
            return $this->sanitizeTitleValue($m[1]);
        }

        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $m)) {
            $title = $this->sanitizeTitleValue(strip_tags($m[1]));
            $title = preg_replace('/\s*[-|]\s*(Dateien|Files)\s*[-|].*$/iu', '', $title) ?: $title;
            return trim($title);
        }

        return '';
    }

    public function testAccess(string $baseUrl, string $token, string $password = ''): void
    {
        $this->propfind($baseUrl, $token, $password, '/', 0);
    }

    public function listFiles(string $baseUrl, string $token, string $password = ''): array
    {
        $response = $this->propfind($baseUrl, $token, $password, '/', 1);

        return $this->parsePropfindResponse($response['body']);
    }

    public function download(string $baseUrl, string $token, string $password, string $remotePath, string $targetPath, int $maxBytes = 0): void
    {
        $url = $this->buildFileUrl($baseUrl, $token, $remotePath);
        $this->request('GET', $url, $token, $password, [], null, $targetPath, $maxBytes);
    }

    private function propfind(string $baseUrl, string $token, string $password, string $remotePath, int $depth): array
    {
        $url = $this->buildFileUrl($baseUrl, $token, $remotePath);
        $body = <<<XML
<?xml version="1.0"?>
<d:propfind xmlns:d="DAV:">
  <d:prop>
    <d:getcontenttype />
    <d:getcontentlength />
    <d:getlastmodified />
    <d:resourcetype />
  </d:prop>
</d:propfind>
XML;

        return $this->request('PROPFIND', $url, $token, $password, ['Depth: ' . $depth, 'Content-Type: application/xml; charset=utf-8'], $body);
    }

    private function parsePropfindResponse(string $xml): array
    {
        $xmlObj = @simplexml_load_string($xml);

        if (!$xmlObj instanceof SimpleXMLElement) {
            throw new RuntimeException('Invalid PROPFIND response XML.');
        }

        $xmlObj->registerXPathNamespace('d', 'DAV:');
        $responses = $xmlObj->xpath('/d:multistatus/d:response');

        if (!is_array($responses)) {
            return [];
        }

        $result = [];

        foreach ($responses as $response) {
            $response->registerXPathNamespace('d', 'DAV:');
            $href = (string) ($response->xpath('./d:href')[0] ?? '');
            $prop = $response->xpath('./d:propstat/d:prop')[0] ?? null;

            if (!$prop instanceof SimpleXMLElement) {
                continue;
            }

            $resourceType = $prop->xpath('./d:resourcetype/d:collection');
            $isCollection = is_array($resourceType) && count($resourceType) > 0;
            $contentType = trim((string) ($prop->xpath('./d:getcontenttype')[0] ?? ''));
            $contentLength = (int) ($prop->xpath('./d:getcontentlength')[0] ?? 0);
            $lastModified = trim((string) ($prop->xpath('./d:getlastmodified')[0] ?? ''));

            $result[] = [
                'href' => $href,
                'is_collection' => $isCollection,
                'content_type' => $contentType,
                'content_length' => $contentLength,
                'last_modified' => $lastModified,
            ];
        }

        return $result;
    }

    private function buildFileUrl(string $baseUrl, string $token, string $remotePath): string
    {
        $trimmed = trim($remotePath);
        $trimmed = $trimmed === '' ? '/' : $trimmed;
        $normalized = str_replace('\\', '/', $trimmed);
        $segments = array_values(array_filter(explode('/', trim($normalized, '/')), static fn(string $part): bool => $part !== ''));
        $encodedPath = implode('/', array_map('rawurlencode', $segments));
        $suffix = $encodedPath !== '' ? '/' . $encodedPath : '';

        return rtrim($baseUrl, '/') . '/public.php/dav/files/' . rawurlencode($token) . $suffix;
    }

    private function request(
        string $method,
        string $url,
        string $token,
        string $password,
        array $headers = [],
        ?string $body = null,
        ?string $targetFile = null,
        int $maxBytes = 0
    ): array {
        $this->validateUrlAllowed($url);

        $ch = curl_init($url);

        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL.');
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_USERPWD, $token . ':' . $password);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, $targetFile === null);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 0);
        // Keep redirects disabled. If redirects are enabled in future, each redirect target
        // must be revalidated against the same host/IP SSRF checks before following it.
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $fp = null;
        $bytesWritten = 0;

        if ($targetFile !== null) {
            $fp = fopen($targetFile, 'wb');

            if ($fp === false) {
                curl_close($ch);
                throw new RuntimeException('Unable to open target file for writing.');
            }

            curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($ch, string $chunk) use ($fp, $maxBytes, &$bytesWritten): int {
                $length = strlen($chunk);
                $bytesWritten += $length;
                if ($maxBytes > 0 && $bytesWritten > $maxBytes) {
                    return 0;
                }
                $written = fwrite($fp, $chunk);
                if ($written === false) {
                    return 0;
                }
                return $written;
            });
        }

        $responseBody = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($fp !== null) {
            fclose($fp);
        }

        curl_close($ch);

        if ($targetFile !== null && $maxBytes > 0 && $bytesWritten > $maxBytes) {
            if (file_exists($targetFile)) {
                @unlink($targetFile);
            }
            throw new RuntimeException('Download exceeded configured file size limit.');
        }

        if ($responseBody === false && $targetFile === null) {
            throw new RuntimeException('WebDAV request failed: ' . $curlError);
        }

        if ($statusCode < 200 || $statusCode > 299) {
            if ($targetFile !== null && file_exists($targetFile)) {
                @unlink($targetFile);
            }
            throw new RuntimeException('WebDAV request failed with HTTP ' . $statusCode . '.');
        }

        return [
            'status' => $statusCode,
            'body' => is_string($responseBody) ? $responseBody : '',
        ];
    }

    private function sanitizeTitleValue(string $title): string
    {
        $decoded = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = strip_tags($decoded);
        $decoded = preg_replace('/\s+/u', ' ', $decoded) ?: $decoded;
        $decoded = trim($decoded);
        if (mb_strlen($decoded) > 200) {
            $decoded = mb_substr($decoded, 0, 200);
        }
        return $decoded;
    }

    private function validateUrlAllowed(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new RuntimeException('Invalid URL.');
        }
        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'https') {
            throw new RuntimeException('Only HTTPS URLs are allowed.');
        }

        $host = strtolower(trim((string) $parts['host']));
        // Enforce strict host validation (allowlist + DNS/public IP checks) before any network call.
        $parser = new ShareLinkParser();
        $parser->validateSafeShareHost($host, $this->allowedShareHosts);
    }
}

