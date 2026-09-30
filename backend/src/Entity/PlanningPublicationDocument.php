<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlanningPublicationDocumentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The PDF of one PlanningPublication, exactly as it was generated when the
 * publication was recorded (docs/decisions.md D173) — its bytes, not the
 * data to render it again. Rendering later would read the planning's
 * current period, name, line names and people's names, all of which can
 * change after the publication (an extension, a rename); these bytes
 * cannot.
 *
 * Written in the publication's own transaction, append-only (database
 * trigger), one per publication. The email attachments (first
 * publication, republication, every retry) and "Télécharger le PDF" all
 * serve these same bytes. A publication recorded before D173 has none:
 * its PDF is still rendered from its frozen entries, as before — nothing
 * is reconstructed.
 */
#[ORM\Entity(repositoryClass: PlanningPublicationDocumentRepository::class)]
#[ORM\Table(name: 'planning_publication_documents')]
#[ORM\UniqueConstraint(name: 'uniq_planning_publication_documents_publication', columns: ['publication_id'])]
class PlanningPublicationDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PlanningPublication::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private PlanningPublication $publication;

    /** @var string|resource */
    #[ORM\Column(type: Types::BLOB)]
    private mixed $content;

    /** Not mapped: the stream above, read once. */
    private ?string $contentCache = null;

    #[ORM\Column(length: 255)]
    private string $filename;

    /** SHA-256 of the content, hex — what was attached can be proven identical later. */
    #[ORM\Column(length: 64)]
    private string $sha256;

    /** The planning's name when it was published — the emails of this publication say this one. */
    #[ORM\Column(length: 255)]
    private string $planningName;

    /** The published period, first and last day (inclusive), as the PDF shows it. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $periodFirstDay;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $periodLastDay;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(PlanningPublication $publication, string $content, string $filename, string $planningName, \DateTimeImmutable $periodFirstDay, \DateTimeImmutable $periodLastDay, \DateTimeImmutable $createdAt)
    {
        $this->publication = $publication;
        $this->content = $content;
        $this->filename = $filename;
        $this->sha256 = hash('sha256', $content);
        $this->planningName = $planningName;
        $this->periodFirstDay = $periodFirstDay;
        $this->periodLastDay = $periodLastDay;
        $this->createdAt = $createdAt;
    }

    public function getPlanningName(): string
    {
        return $this->planningName;
    }

    public function getPeriodFirstDay(): \DateTimeImmutable
    {
        return $this->periodFirstDay;
    }

    public function getPeriodLastDay(): \DateTimeImmutable
    {
        return $this->periodLastDay;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublication(): PlanningPublication
    {
        return $this->publication;
    }

    public function getContent(): string
    {
        if (!\is_resource($this->content)) {
            return $this->content;
        }

        // A BLOB column is hydrated as a stream: read it once into an UNMAPPED cache — writing the string back
        // into $content would look like a change to Doctrine, and the row is append-only.
        if (null === $this->contentCache) {
            rewind($this->content);
            $this->contentCache = (string) stream_get_contents($this->content);
        }

        return $this->contentCache;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getSha256(): string
    {
        return $this->sha256;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
