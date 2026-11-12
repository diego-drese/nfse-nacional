<?php

declare(strict_types=1);

namespace DiegoDrese\NfseNacional\Xml;

use DiegoDrese\NfseNacional\Dps\Dps;

/**
 * Regras da DPS que o schema não confere (vêm dos manuais da NFS-e Nacional), checadas antes de enviar, em português.
 * Cada regra que a Sefin recusar e que der para prever entra aqui, com o teste.
 */
final class DpsRules
{
    /** @return list<string> problemas (vazio = ok) */
    public static function errors(Dps $dps): array
    {
        $errors = [];
        if ($dps->amount <= 0) {
            $errors[] = 'O valor do serviço tem de ser maior que zero.';
        }
        if (! in_array($dps->issTaxation, [Dps::ISS_TAXABLE, Dps::ISS_NON_INCIDENCE], true)) {
            $errors[] = 'Tributação do ISS não suportada: use 1 (tributável) ou 4 (não incidência).';
        }
        $t = $dps->ibsCbs;
        if ($t !== null) {
            if (empty($dps->nbs)) {
                $errors[] = 'Com IBS/CBS, o código NBS do serviço é obrigatório.';
            }
            if (($t->governmentEntity === null) !== ($t->operationType === null)) {
                $errors[] = 'Compra governamental: informe o tipo de ente (tpEnteGov) e o tipo de operação (tpOper) juntos.';
            }
            $sum = 0.0;
            foreach ($t->reimbursements as $i => $r) {
                $n = $i + 1;
                if ($r->amount <= 0) {
                    $errors[] = "Repasse {$n}: o valor tem de ser maior que zero.";
                }
                if ($r->dfeKey === null && $r->municipalDocument === null && $r->otherDocument === null) {
                    $errors[] = "Repasse {$n}: informe o documento do fornecedor (chave, nota municipal ou outro documento).";
                }
                if ($r->type === '99' && empty($r->typeDescription)) {
                    $errors[] = "Repasse {$n}: no tipo 99 (outros), descreva o reembolso.";
                }
                if ($r->dfeKey !== null && (int) $r->dfeKey['type'] === 9 && empty($r->dfeKey['description'])) {
                    $errors[] = "Repasse {$n}: chave de documento do tipo 9 (outro) precisa da descrição do documento.";
                }
                $sum += $r->amount;
            }
            if ($sum > $dps->amount + 0.004) {
                $errors[] = 'Os repasses somam mais que o valor do serviço.';
            }
        }

        return $errors;
    }
}
