<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * SurgicalHub's machine API as MedVue sees it in tests (docs/surgicalhub-integration.md
 * §5.2-§5.3): the test environment's http_client answers through this
 * (config/packages/framework.yaml, when@test), so no test ever leaves the
 * machine. State is static — it must survive the kernel reboots between a
 * test's requests — and reset() by each test that uses it.
 *
 * Every request MedVue makes is recorded, so tests can prove what MedVue
 * sends (and that it never writes anything but the D4 DELETE).
 */
final class FakeSurgicalHub
{
    public const BASE_URL = 'https://surgicalhub.test';
    public const TOKEN = 'test-medvue-to-surgicalhub-secret';

    /** @var array<string, list<array{id: string, startDate: string, endDate: string, status?: string}>> */
    private static array $absences = [];
    /** @var array<string, string> linkId => 'link_not_found' | 'link_revoked' */
    private static array $gone = [];
    /** @var (callable(string, string, array<string, mixed>): ?MockResponse)|null */
    private static $override;
    /** @var list<array{method: string, url: string, headers: list<string>, body: string, timeout: ?float, maxDuration: ?float}> */
    private static array $requests = [];

    public static function reset(): void
    {
        self::$absences = [];
        self::$gone = [];
        self::$override = null;
        self::$requests = [];
    }

    /**
     * @param list<array{id: string, startDate: string, endDate: string, status?: string}> $absences
     */
    public static function setAbsences(string $linkId, array $absences): void
    {
        self::$absences[$linkId] = $absences;
    }

    public static function markGone(string $linkId, string $error): void
    {
        self::$gone[$linkId] = $error;
    }

    /**
     * Answers instead of the normal behaviour while set (returning null falls back to it).
     *
     * @param (callable(string, string, array<string, mixed>): ?MockResponse)|null $override
     */
    public static function override(?callable $override): void
    {
        self::$override = $override;
    }

    /**
     * @return list<array{method: string, url: string, headers: list<string>, body: string, timeout: ?float, maxDuration: ?float}>
     */
    public static function requests(): array
    {
        return self::$requests;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        self::$requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $options['headers'] ?? [],
            'body' => (string) ($options['body'] ?? ''),
            'timeout' => isset($options['timeout']) ? (float) $options['timeout'] : null,
            'maxDuration' => isset($options['max_duration']) ? (float) $options['max_duration'] : null,
        ];

        if (null !== self::$override && null !== $response = (self::$override)($method, $url, $options)) {
            return $response;
        }

        if (!\in_array('Authorization: Bearer '.self::TOKEN, $options['headers'] ?? [], true)) {
            return self::json(['apiVersion' => 1, 'error' => 'unauthorized'], 401);
        }

        $path = (string) parse_url($url, \PHP_URL_PATH);
        if (1 === preg_match('#^/api/integrations/medvue/v1/links/([^/]+)/absences$#', $path, $m) && 'GET' === $method) {
            return $this->absences(rawurldecode($m[1]), $url);
        }
        if (1 === preg_match('#^/api/integrations/medvue/v1/links/([^/]+)$#', $path, $m) && 'DELETE' === $method) {
            $linkId = rawurldecode($m[1]);
            if (!isset(self::$absences[$linkId]) && !isset(self::$gone[$linkId])) {
                return self::json(['apiVersion' => 1, 'error' => 'link_not_found'], 404);
            }
            self::$gone[$linkId] = 'link_revoked';

            return new MockResponse('', ['http_code' => 204]);
        }

        return new MockResponse('<html>Not Found</html>', ['http_code' => 404, 'response_headers' => ['content-type: text/html']]);
    }

    private function absences(string $linkId, string $url): MockResponse
    {
        if (isset(self::$gone[$linkId])) {
            return self::json(['apiVersion' => 1, 'error' => self::$gone[$linkId]], 'link_revoked' === self::$gone[$linkId] ? 410 : 404);
        }
        if (!isset(self::$absences[$linkId])) {
            return self::json(['apiVersion' => 1, 'error' => 'link_not_found'], 404);
        }

        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        $from = (string) ($query['from'] ?? '');
        $to = (string) ($query['to'] ?? '');

        $items = [];
        foreach (self::$absences[$linkId] as $absence) {
            if ($absence['startDate'] <= $to && $absence['endDate'] >= $from) {
                $items[] = $absence + ['status' => 'CONFIRMED', 'updatedAt' => null];
            }
        }

        return self::json([
            'apiVersion' => 1,
            'linkId' => $linkId,
            'window' => ['from' => $from, 'to' => $to],
            'generatedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'complete' => true,
            'absences' => $items,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function json(array $data, int $status = 200): MockResponse
    {
        return new MockResponse((string) json_encode($data), ['http_code' => $status, 'response_headers' => ['content-type: application/json']]);
    }
}
