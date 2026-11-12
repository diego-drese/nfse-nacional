<?php

declare(strict_types=1);

namespace DiegoDrese\NfseNacional\Dps;

use DateTimeImmutable;
use DiegoDrese\NfseNacional\Environment;

/**
 * Declaração de Prestação de Serviço (o pedido da NFS-e). `tribNac` é o código de tributação nacional (6 dígitos, LC 116:
 * 01.03.01 → 010301). `issRate` é a alíquota de ISS (Simples: muda todo mês); `simplesTotalRate`, o percentual total do
 * Simples para o "valor aproximado dos tributos" (sem ele, a DPS informa que não declara).
 */
final readonly class Dps
{
    public function __construct(
        public Environment $environment,
        public string $series,
        public int $number,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $competence,
        public Provider $provider,
        public Taker $taker,
        public string $tribNac,
        public string $description,
        public float $amount,
        public ?float $issRate = null,
        public ?float $simplesTotalRate = null,
        public ?string $nbs = null,
    ) {}

    /** Id: "DPS" + município (7) + tipo de inscrição (2 = CNPJ) + CNPJ (14) + série (5) + número (15). */
    public function id(): string
    {
        return 'DPS'.$this->provider->cityCode.'2'.str_pad($this->provider->cnpj, 14, '0', STR_PAD_LEFT)
            .str_pad($this->series, 5, '0', STR_PAD_LEFT).str_pad((string) $this->number, 15, '0', STR_PAD_LEFT);
    }
}
