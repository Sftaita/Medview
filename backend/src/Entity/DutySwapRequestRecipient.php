<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One colleague a SELECTED swap request is addressed to (docs/duty-swaps.md
 * §5) — the only people besides the requester who may see it and answer
 * it. Written with the request, never changed afterwards.
 */
#[ORM\Entity]
#[ORM\Table(name: 'duty_swap_request_recipients')]
#[ORM\UniqueConstraint(name: 'uniq_duty_swap_request_recipients', columns: ['request_id', 'user_id'])]
#[ORM\Index(columns: ['user_id'], name: 'idx_duty_swap_request_recipients_user')]
class DutySwapRequestRecipient
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DutySwapRequest::class, inversedBy: 'recipients')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private DutySwapRequest $request;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    public function __construct(DutySwapRequest $request, User $user)
    {
        $this->request = $request;
        $this->user = $user;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequest(): DutySwapRequest
    {
        return $this->request;
    }

    public function getUser(): User
    {
        return $this->user;
    }
}
