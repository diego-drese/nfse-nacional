<?php

declare(strict_types=1);

namespace DiegoDrese\NfseNacional\Dps;

/**
 * Prestador (quem emite). `simplesOption` = opSimpNac (1 não optante, 2 MEI, 3 ME/EPP); `simplesRegime` = regApTribSN (1:
 * tributos federais e municipal pelo Simples; 2: federais pelo Simples e ISS fora; 3: tudo fora), só para ME/EPP.
 */
final readonly class Provider
{
    public function __construct(
        public string $cnpj,
        public string $cityCode,
        public ?string $municipalRegistration = null,
        public int $simplesOption = 3,
        public int $simplesRegime = 1,
    ) {}
}
