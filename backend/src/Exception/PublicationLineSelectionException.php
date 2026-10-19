<?php

declare(strict_types=1);

namespace App\Exception;

use App\Entity\PlanningLine;

/**
 * A refused choice of lines to publish (docs/decisions.md D196): never a
 * guess — the lines concerned are named in `details`.
 */
final class PublicationLineSelectionException extends \RuntimeException implements ApiProblemWithDetails
{
    /**
     * @param list<array<string, mixed>> $details
     */
    private function __construct(
        private readonly string $errorCode,
        private readonly int $statusCode,
        string $message,
        private readonly array $details,
    ) {
        parent::__construct($message);
    }

    public static function empty(): self
    {
        return new self('lines_required', 422, 'Choose at least one line to publish.', []);
    }

    /**
     * @param list<string> $stableIds
     */
    public static function unknown(array $stableIds): self
    {
        return new self('unknown_line', 422, 'These lines take no part in this period.', array_map(static fn (string $id): array => ['lineStableId' => $id], $stableIds));
    }

    /**
     * @param list<PlanningLine> $lines
     */
    public static function alreadyPublished(array $lines): self
    {
        return new self('line_already_published', 409, 'These lines are already published in this period: republish their changes instead.', self::named($lines));
    }

    /**
     * @param list<array{line: PlanningLine, blockers: list<string>}> $lines
     */
    public static function notPublishable(array $lines): self
    {
        return new self('line_not_publishable', 409, 'These lines cannot be published as they are.', array_map(
            static fn (array $item): array => [...self::named([$item['line']])[0], 'blockers' => $item['blockers']],
            $lines,
        ));
    }

    /**
     * @param list<array{line: PlanningLine, source: PlanningLine}> $lines
     */
    public static function sourceNotPublished(array $lines): self
    {
        return new self('source_line_not_published', 409, 'A reinforcement line is published with, or after, the line it reinforces — never before.', array_map(
            static fn (array $item): array => [...self::named([$item['line']])[0], 'sourceLineStableId' => (string) $item['source']->getStableId(), 'sourceLineName' => $item['source']->getName()],
            $lines,
        ));
    }

    /**
     * @param list<PlanningLine> $lines
     */
    public static function republishRequired(array $lines): self
    {
        return new self('republish_required', 409, 'The published lines have unpublished changes: republish them, choosing the new lines to publish with them.', self::named($lines));
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function details(): array
    {
        return $this->details;
    }

    /**
     * @param list<PlanningLine> $lines
     *
     * @return list<array{lineStableId: string, lineName: string}>
     */
    private static function named(array $lines): array
    {
        return array_map(static fn (PlanningLine $line): array => ['lineStableId' => (string) $line->getStableId(), 'lineName' => $line->getName()], $lines);
    }
}
