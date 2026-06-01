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

use InvalidArgumentException;

final class ShareLinkParser
{
    /**
     * @param string[] $allowedShareHosts
     */
    public function parse(string $shareUrl, array $allowedShareHosts = []): array
    {
        $shareUrl = trim($shareUrl);

        if ($shareUrl === '') {
            throw new InvalidArgumentException('Empty share URL.');
        }

        $parts = parse_url($shareUrl);

        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || empty($parts['path'])) {
            throw new InvalidArgumentException('Invalid share URL.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'https') {
            throw new InvalidArgumentException('Only HTTPS share URLs are allowed.');
        }

        $host = $this->normalizeHost((string) $parts['host']);
        $this->validateSafeShareHost($host, $allowedShareHosts);

        $path = trim((string) $parts['path']);
        $matches = [];

        if (!preg_match('#/s/([A-Za-z0-9]+)#', $path, $matches)) {
            throw new InvalidArgumentException('No share token found in URL.');
        }

        $token = $matches[1];
        $portPart = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $baseUrl = $scheme . '://' . $host . $portPart;

        return [
            'share_url' => $shareUrl,
            'token' => $token,
            'base_url' => $baseUrl,
            'host' => $host,
        ];
    }

    /**
     * @param string[] $allowedShareHosts
     */
    public function validateSafeShareHost(string $host, array $allowedShareHosts = []): void
    {
        $host = $this->normalizeHost($host);

        if ($host === '') {
            throw new InvalidArgumentException('Invalid hostname.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw new InvalidArgumentException('IP-based share hosts are not allowed.');
        }

        if ($host === 'localhost' || !str_contains($host, '.')) {
            throw new InvalidArgumentException('Local hostname not allowed.');
        }

        foreach (['.local', '.internal', '.lan', '.home', '.localdomain'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                throw new InvalidArgumentException('Internal hostname not allowed.');
            }
        }

        $allowed = [];
        foreach ($allowedShareHosts as $candidate) {
            $normalized = $this->normalizeHost((string) $candidate);
            if ($normalized === '' || str_contains($normalized, '*')) {
                continue;
            }
            if (filter_var($normalized, FILTER_VALIDATE_IP) !== false) {
                continue;
            }
            $allowed[] = $normalized;
        }
        $allowed = array_values(array_unique($allowed));

        if ($allowed !== [] && !in_array($host, $allowed, true)) {
            throw new InvalidArgumentException('Share host not allowed.');
        }

        $this->validateResolvedHostIps($host);
    }

    private function validateResolvedHostIps(string $host): void
    {
        // Resolve both A/AAAA records and ensure each target remains public-routable.
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records) || $records === []) {
            throw new InvalidArgumentException('Share host DNS resolution failed.');
        }

        $resolvedCount = 0;
        foreach ($records as $record) {
            $ip = '';
            if (isset($record['ip'])) {
                $ip = (string) $record['ip'];
            } elseif (isset($record['ipv6'])) {
                $ip = (string) $record['ipv6'];
            }
            if ($ip === '') {
                continue;
            }
            $resolvedCount++;
            if (!$this->isPublicRoutableIp($ip)) {
                throw new InvalidArgumentException('Share host resolved to disallowed IP range.');
            }
        }

        if ($resolvedCount === 0) {
            throw new InvalidArgumentException('Share host DNS resolution failed.');
        }
    }

    private function isPublicRoutableIp(string $ip): bool
    {
        $isPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;

        if (!$isPublic) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $lower = strtolower($ip);
            if ($lower === '::1'
                || str_starts_with($lower, 'fe80:')
                || str_starts_with($lower, 'fc')
                || str_starts_with($lower, 'fd')
                || str_starts_with($lower, 'ff')
                || str_starts_with($lower, '::ffff:127.')
            ) {
                return false;
            }
        }

        return true;
    }

    private function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = rtrim($host, '.');
        if ($host === '') {
            return '';
        }

        if (function_exists('idn_to_ascii') && preg_match('/[^[:ascii:]]/', $host)) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $host = strtolower(trim($ascii));
                $host = rtrim($host, '.');
            }
        }

        return $host;
    }
}

