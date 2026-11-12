<?php

declare(strict_types=1);

namespace DiegoDrese\NfseNacional\Dps;

/** Tomador (cliente): CPF (11 dígitos) ou CNPJ (14), nome e endereço. */
final readonly class Taker
{
    public function __construct(
        public string $document,
        public string $name,
        public Address $address,
        public ?string $email = null,
        public ?string $phone = null,
    ) {}
}
