<?php

declare(strict_types=1);

namespace App\Service\SurgicalHub;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * MedVue's only two calls to SurgicalHub (docs/surgicalhub-integration.md
 * §5.2-§5.3, decision D11): reading one person's absences, and telling
 * SurgicalHub an association was ended from MedVue. Nothing else, and never
 * any MedVue business data — the requests carry a linkId and a date window.
 *
 * Every answer is validated against the v1 contract *before* the caller sees
 * it: an incomplete, truncated, inconsistent or unexpected answer is a
 * failure (SurgicalHubSyncFailedException), never an empty list. Only the
 * exact `{"apiVersion": 1, "error": "link_not_found" | "link_revoked"}`
 * bodies of a 404/410 mean the association is gone — a proxy's HTML 404
 * must never erase anything.
 */
final class SurgicalHubApiClient
{
    private const MAX_ABSENCES = 1000;
    private const ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'SURGICALHUB_API_BASE_URL')]
        private readonly string $baseUrl,
        #[Autowire(env: 'SURGICALHUB_API_TOKEN')]
        private readonly string $token,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->baseUrl) && '' !== trim($this->token);
    }

    /**
     * @throws SurgicalHubSyncFailedException nothing usable came back
     * @throws SurgicalHubLinkGoneException   SurgicalHub no longer knows this association
     */
    public function fetchAbsences(string $linkId, \DateTimeImmutable $from, \DateTimeImmutable $to, ?float $timeoutSeconds = null): SurgicalHubAbsenceSnapshot
    {
        if (!$this->isConfigured()) {
            throw new SurgicalHubSyncFailedException(SurgicalHubSyncError::NOT_CONFIGURED);
        }

        try {
            $response = $this->httpClient->request('GET', $this->url('/api/integrations/medvue/v1/links/'.rawurlencode($linkId).'/absences'), $this->options($timeoutSeconds) + [
                'query' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            ]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (ExceptionInterface) {
            throw new SurgicalHubSyncFailedException(SurgicalHubSyncError::UNREACHABLE);
        }

        if (200 !== $status) {
            throw $this->failureFor($status, $body);
        }

        return $this->parseSnapshot($body, $linkId, $from, $to);
    }

    /**
     * Best effort (D4): true when SurgicalHub confirms the association is
     * ended (204, or 404 because it never knew it). Never throws.
     */
    public function deleteLink(string $linkId): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        try {
            $response = $this->httpClient->request('DELETE', $this->url('/api/integrations/medvue/v1/links/'.rawurlencode($linkId)), $this->options());
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (ExceptionInterface) {
            return false;
        }

        return 204 === $status || (404 === $status && 'link_not_found' === self::contractError($body));
    }

    private function failureFor(int $status, string $body): \RuntimeException
    {
        $error = self::contractError($body);

        if (404 === $status && SurgicalHubLinkGoneException::NOT_FOUND === $error) {
            return new SurgicalHubLinkGoneException(SurgicalHubLinkGoneException::NOT_FOUND);
        }
        if (410 === $status && SurgicalHubLinkGoneException::REVOKED === $error) {
            return new SurgicalHubLinkGoneException(SurgicalHubLinkGoneException::REVOKED);
        }

        return new SurgicalHubSyncFailedException(match (true) {
            401 === $status => SurgicalHubSyncError::UNAUTHORIZED,
            429 === $status => SurgicalHubSyncError::RATE_LIMITED,
            422 === $status && 'window_too_large' === $error => SurgicalHubSyncError::WINDOW_TOO_LARGE,
            $status >= 500 => SurgicalHubSyncError::SERVER_ERROR,
            $status >= 300 && $status < 400 => SurgicalHubSyncError::UNREACHABLE,
            default => SurgicalHubSyncError::INVALID_RESPONSE,
        });
    }

    /** The `error` of a v1 error body, or null if the body is anything else. */
    private static function contractError(string $body): ?string
    {
        try {
            $data = json_decode($body, true, 4, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($data) || 1 !== ($data['apiVersion'] ?? null) || !\is_string($data['error'] ?? null)) {
            return null;
        }

        return $data['error'];
    }

    private function parseSnapshot(string $body, string $linkId, \DateTimeImmutable $from, \DateTimeImmutable $to): SurgicalHubAbsenceSnapshot
    {
        $invalid = new SurgicalHubSyncFailedException(SurgicalHubSyncError::INVALID_RESPONSE);

        try {
            $data = json_decode($body, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw $invalid;
        }

        $fromDay = $from->format('Y-m-d');
        $toDay = $to->format('Y-m-d');

        if (!\is_array($data)
            || 1 !== ($data['apiVersion'] ?? null)
            || $linkId !== ($data['linkId'] ?? null)
            || true !== ($data['complete'] ?? null)
            || !\is_array($data['window'] ?? null)
            || $fromDay !== ($data['window']['from'] ?? null)
            || $toDay !== ($data['window']['to'] ?? null)
            || !\is_array($data['absences'] ?? null)
            || !array_is_list($data['absences'])
            || \count($data['absences']) > self::MAX_ABSENCES
        ) {
            throw $invalid;
        }

        $absences = [];
        foreach ($data['absences'] as $item) {
            if (!\is_array($item)
                || !\is_string($item['id'] ?? null) || 1 !== preg_match(self::ID_PATTERN, $item['id'])
                || !\is_string($item['status'] ?? null)
                || isset($absences[$item['id']])
            ) {
                throw $invalid;
            }

            $start = self::date($item['startDate'] ?? null);
            $end = self::date($item['endDate'] ?? null);
            if (null === $start || null === $end || $end < $start
                // Every absence must intersect the window asked for.
                || $start->format('Y-m-d') > $toDay || $end->format('Y-m-d') < $fromDay
            ) {
                throw $invalid;
            }

            $absences[$item['id']] = new SurgicalHubAbsence($item['id'], $start, $end, $item['status']);
        }

        return new SurgicalHubAbsenceSnapshot($from, $to, $absences);
    }

    /** A strict YYYY-MM-DD calendar date (no time, no overflow like 2026-02-31). */
    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));

        return false !== $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function options(?float $timeoutSeconds = null): array
    {
        return [
            'headers' => ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'],
            'timeout' => $timeoutSeconds ?? 10,
            'max_duration' => null !== $timeoutSeconds ? $timeoutSeconds : 15,
            'max_redirects' => 0,
        ];
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }
}
