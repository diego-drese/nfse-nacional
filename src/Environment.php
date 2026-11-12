<?php

declare(strict_types=1);

namespace DiegoDrese\NfseNacional;

/** Ambiente da NFS-e Nacional: produção ou homologação (produção restrita). */
enum Environment: string
{
    case Producao = 'producao';
    case Homologacao = 'homologacao';

    /** tpAmb da DPS: 1 produção, 2 homologação. */
    public function code(): string
    {
        return $this === self::Producao ? '1' : '2';
    }

    public function sefinUrl(): string
    {
        return $this === self::Producao ? 'https://sefin.nfse.gov.br/SefinNacional' : 'https://sefin.producaorestrita.nfse.gov.br/SefinNacional';
    }

    public function adnUrl(): string
    {
        return $this === self::Producao ? 'https://adn.nfse.gov.br' : 'https://adn.producaorestrita.nfse.gov.br';
    }
}
