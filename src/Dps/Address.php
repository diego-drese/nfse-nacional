<?php

declare(strict_types=1);

namespace DiegoDrese\NfseNacional\Dps;

/** Endereço nacional do tomador: município pelo código IBGE (7 dígitos). */
final readonly class Address
{
    public function __construct(
        public string $zip,
        public string $street,
        public string $number,
        public string $district,
        public string $cityCode,
        public ?string $complement = null,
    ) {}
}
