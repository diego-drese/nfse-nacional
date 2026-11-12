<?php

declare(strict_types=1);

namespace DiegoDrese\NfseNacional\Dps;

/**
 * Grupo IBS/CBS da DPS (reforma tributária, leiaute 1.01). A DPS leva só a **classificação** da operação; alíquotas e valores
 * quem calcula é o sistema nacional, e a NFS-e volta com eles (Result::$ibsCbs).
 *
 * - `operationIndicator` (cIndOp, 6 dígitos): tabela "código indicador de operação" (define o local de incidência).
 * - `cst` (3 dígitos) e `classification` (cClassTrib, 6 dígitos): tabelas de situação e classificação tributária do IBS/CBS.
 * - `finalConsumer` (indFinal): operação de uso ou consumo pessoal.
 * - `governmentEntity` (tpEnteGov 1 União, 2 Estado, 3 DF, 4 Município) e `operationType` (tpOper 1–5): compras governamentais.
 * - `reimbursements`: valores de terceiros (repasse, reembolso, ressarcimento) que saem da base do IBS/CBS, com o documento.
 *
 * Com o grupo IBS/CBS, o código NBS do serviço passa a ser obrigatório (Dps::$nbs).
 */
final readonly class IbsCbs
{
    /** @param list<Reimbursement> $reimbursements */
    public function __construct(
        public string $operationIndicator,
        public string $cst,
        public string $classification,
        public bool $finalConsumer = false,
        public array $reimbursements = [],
        public ?int $governmentEntity = null,
        public ?int $operationType = null,
    ) {}
}
