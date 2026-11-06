<?php

namespace App\Domain;

/**
 * The five onboarding stages. The order of the cases is the workflow order.
 */
enum Stage: string
{
    case ContractSent = 'CONTRACT_SENT';
    case ContractSigned = 'CONTRACT_SIGNED';
    case KycDocsReceived = 'KYC_DOCS_RECEIVED';
    case KycVerified = 'KYC_VERIFIED';
    case Active = 'ACTIVE';

    public function label(): string
    {
        return match ($this) {
            self::ContractSent => 'Contract Sent',
            self::ContractSigned => 'Contract Signed',
            self::KycDocsReceived => 'KYC Docs Received',
            self::KycVerified => 'KYC Verified',
            self::Active => 'Active',
        };
    }

    /** Position in the workflow, starting at 0. */
    public function index(): int
    {
        return array_search($this, self::cases(), true);
    }

    public function next(): ?self
    {
        return self::cases()[$this->index() + 1] ?? null;
    }

    /** The vendor has finished onboarding, so it can't be "stuck". */
    public function isTerminal(): bool
    {
        return $this === self::Active;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
