<?php

declare(strict_types=1);

namespace DiegoDrese\NfseNacional\Dps;

use DateTimeImmutable;

/**
 * Valor de terceiro dentro da nota (gReeRepRes): repasse, reembolso ou ressarcimento "por conta e ordem de terceiro", já
 * tributado por quem forneceu. Sai da base do IBS/CBS e precisa do documento do fornecedor.
 *
 * Tipos (tpReeRepRes): 01 repasse a corretores de imóveis · 02 repasse a fornecedor por agência de turismo · 03 e 04
 * reembolso a agência de publicidade (produção externa / mídia) · 99 outros reembolsos ou ressarcimentos por conta e ordem
 * de terceiro (informe `typeDescription`).
 *
 * Documento (um deles): `dfeKey` (chave de 50 dígitos de NFS-e = 1, NF-e = 2, CT-e = 3, outro = 9 com `dfeDescription`);
 * `municipalDocument` (nota municipal fora do padrão nacional: município IBGE, número, descrição); ou `otherDocument`
 * (número e descrição, ex.: fatura do fornecedor).
 */
final readonly class Reimbursement
{
    /**
     * @param  array{type: int, key: string, description?: ?string}|null  $dfeKey
     * @param  array{city: string, number: string, description: string}|null  $municipalDocument
     * @param  array{number: string, description: string}|null  $otherDocument
     * @param  array{document: string, name: string}|null  $supplier  CPF/CNPJ e nome de quem forneceu
     */
    public function __construct(
        public string $type,
        public float $amount,
        public DateTimeImmutable $issuedOn,
        public DateTimeImmutable $competenceOn,
        public ?array $dfeKey = null,
        public ?array $municipalDocument = null,
        public ?array $otherDocument = null,
        public ?array $supplier = null,
        public ?string $typeDescription = null,
    ) {}
}
