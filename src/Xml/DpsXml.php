<?php

declare(strict_types=1);

namespace DiegoDrese\NfseNacional\Xml;

use DateTimeImmutable;
use DateTimeZone;
use DiegoDrese\NfseNacional\Dps\Dps;
use DiegoDrese\NfseNacional\Dps\IbsCbs;
use DiegoDrese\NfseNacional\Dps\Reimbursement;
use DiegoDrese\NfseNacional\Environment;
use DOMDocument;
use DOMElement;

/**
 * XML da DPS (leiaute 1.01 da NFS-e Nacional; schemas em resources/schemas) e do pedido de cancelamento. Prestador do
 * Simples Nacional (opSimpNac/regApTribSN), tomador com CPF/CNPJ e endereço, ISS tributável não retido no município do
 * prestador, alíquota do mês e, quando informado, o percentual total do Simples (pTotTribSN).
 */
final class DpsXml
{
    public const NS = 'http://www.sped.fazenda.gov.br/nfse';

    public const VERSION = '1.01';

    public const APP = 'nfse-nacional-php';

    public static function dps(Dps $r): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $root = $dom->appendChild($dom->createElementNS(self::NS, 'DPS'));
        $root->setAttribute('versao', self::VERSION);
        $inf = self::add($dom, $root, 'infDPS');
        $inf->setAttribute('Id', $r->id());
        self::add($dom, $inf, 'tpAmb', $r->environment->code());
        self::add($dom, $inf, 'dhEmi', self::dateTime($r->issuedAt));
        self::add($dom, $inf, 'verAplic', self::APP);
        self::add($dom, $inf, 'serie', str_pad($r->series, 5, '0', STR_PAD_LEFT));
        self::add($dom, $inf, 'nDPS', (string) $r->number);
        self::add($dom, $inf, 'dCompet', $r->competence->format('Y-m-d'));
        self::add($dom, $inf, 'tpEmit', '1');
        self::add($dom, $inf, 'cLocEmi', $r->provider->cityCode);

        $prest = self::add($dom, $inf, 'prest');
        self::add($dom, $prest, 'CNPJ', $r->provider->cnpj);
        if (($im = preg_replace('/\D/', '', (string) $r->provider->municipalRegistration)) !== '') {
            self::add($dom, $prest, 'IM', $im);
        }
        $reg = self::add($dom, $prest, 'regTrib');
        self::add($dom, $reg, 'opSimpNac', (string) $r->provider->simplesOption);
        if ($r->provider->simplesOption === 3) {
            self::add($dom, $reg, 'regApTribSN', (string) $r->provider->simplesRegime);
        }
        self::add($dom, $reg, 'regEspTrib', '0');

        $toma = self::add($dom, $inf, 'toma');
        $doc = preg_replace('/\D/', '', $r->taker->document);
        self::add($dom, $toma, strlen((string) $doc) === 11 ? 'CPF' : 'CNPJ', (string) $doc);
        self::add($dom, $toma, 'xNome', self::text($r->taker->name, 300));
        $a = $r->taker->address;
        $end = self::add($dom, $toma, 'end');
        $nac = self::add($dom, $end, 'endNac');
        self::add($dom, $nac, 'cMun', $a->cityCode);
        self::add($dom, $nac, 'CEP', (string) preg_replace('/\D/', '', $a->zip));
        self::add($dom, $end, 'xLgr', self::text($a->street, 255));
        self::add($dom, $end, 'nro', self::text($a->number, 60));
        if (! empty($a->complement)) {
            self::add($dom, $end, 'xCpl', self::text((string) $a->complement, 156));
        }
        self::add($dom, $end, 'xBairro', self::text($a->district, 60));
        if (($phone = preg_replace('/\D/', '', (string) $r->taker->phone)) !== '' && strlen((string) $phone) >= 6 && strlen((string) $phone) <= 20) {
            self::add($dom, $toma, 'fone', (string) $phone);
        }
        if (! empty($r->taker->email) && filter_var($r->taker->email, FILTER_VALIDATE_EMAIL)) {
            self::add($dom, $toma, 'email', self::text((string) $r->taker->email, 80));
        }

        $serv = self::add($dom, $inf, 'serv');
        self::add($dom, self::add($dom, $serv, 'locPrest'), 'cLocPrestacao', $r->provider->cityCode);
        $cServ = self::add($dom, $serv, 'cServ');
        self::add($dom, $cServ, 'cTribNac', $r->tribNac);
        self::add($dom, $cServ, 'xDescServ', self::text($r->description, 2000));
        if (! empty($r->nbs)) {
            self::add($dom, $cServ, 'cNBS', (string) preg_replace('/\D/', '', $r->nbs));
        }

        $val = self::add($dom, $inf, 'valores');
        self::add($dom, self::add($dom, $val, 'vServPrest'), 'vServ', number_format($r->amount, 2, '.', ''));
        $trib = self::add($dom, $val, 'trib');
        $mun = self::add($dom, $trib, 'tribMun');
        self::add($dom, $mun, 'tribISSQN', (string) $r->issTaxation);
        self::add($dom, $mun, 'tpRetISSQN', '1');
        if ($r->issRate !== null && $r->issTaxation === Dps::ISS_TAXABLE) {
            self::add($dom, $mun, 'pAliq', number_format($r->issRate, 2, '.', ''));
        }
        $tot = self::add($dom, $trib, 'totTrib');
        $r->simplesTotalRate !== null && $r->provider->simplesOption === 3
            ? self::add($dom, $tot, 'pTotTribSN', number_format($r->simplesTotalRate, 2, '.', ''))
            : self::add($dom, $tot, 'indTotTrib', '0');

        if ($r->ibsCbs !== null) {
            self::ibsCbs($dom, $inf, $r->ibsCbs);
        }

        return (string) $dom->saveXML();
    }

    /** Grupo IBS/CBS (reforma tributária), na ordem do TCRTCInfoIBSCBS. */
    private static function ibsCbs(DOMDocument $dom, DOMElement $inf, IbsCbs $t): void
    {
        $g = self::add($dom, $inf, 'IBSCBS');
        self::add($dom, $g, 'finNFSe', '0');
        if ($t->finalConsumer) {
            self::add($dom, $g, 'indFinal', '1');
        }
        self::add($dom, $g, 'cIndOp', $t->operationIndicator);
        if ($t->operationType !== null) {
            self::add($dom, $g, 'tpOper', (string) $t->operationType);
        }
        if ($t->governmentEntity !== null) {
            self::add($dom, $g, 'tpEnteGov', (string) $t->governmentEntity);
        }
        self::add($dom, $g, 'indDest', '0');
        $values = self::add($dom, $g, 'valores');
        if ($t->reimbursements !== []) {
            $list = self::add($dom, $values, 'gReeRepRes');
            foreach ($t->reimbursements as $item) {
                self::reimbursement($dom, self::add($dom, $list, 'documentos'), $item);
            }
        }
        $cls = self::add($dom, self::add($dom, $values, 'trib'), 'gIBSCBS');
        self::add($dom, $cls, 'CST', $t->cst);
        self::add($dom, $cls, 'cClassTrib', $t->classification);
    }

    private static function reimbursement(DOMDocument $dom, DOMElement $doc, Reimbursement $r): void
    {
        if ($r->dfeKey !== null) {
            $dfe = self::add($dom, $doc, 'dFeNacional');
            self::add($dom, $dfe, 'tipoChaveDFe', (string) $r->dfeKey['type']);
            if (! empty($r->dfeKey['description'])) {
                self::add($dom, $dfe, 'xTipoChaveDFe', self::text((string) $r->dfeKey['description'], 255));
            }
            self::add($dom, $dfe, 'chaveDFe', (string) preg_replace('/\D/', '', $r->dfeKey['key']));
        } elseif ($r->municipalDocument !== null) {
            $m = self::add($dom, $doc, 'docFiscalOutro');
            self::add($dom, $m, 'cMunDocFiscal', $r->municipalDocument['city']);
            self::add($dom, $m, 'nDocFiscal', self::text($r->municipalDocument['number'], 255));
            self::add($dom, $m, 'xDocFiscal', self::text($r->municipalDocument['description'], 255));
        } else {
            $o = self::add($dom, $doc, 'docOutro');
            self::add($dom, $o, 'nDoc', self::text((string) ($r->otherDocument['number'] ?? ''), 255));
            self::add($dom, $o, 'xDoc', self::text((string) ($r->otherDocument['description'] ?? ''), 255));
        }
        if ($r->supplier !== null) {
            $f = self::add($dom, $doc, 'fornec');
            $d = (string) preg_replace('/\D/', '', $r->supplier['document']);
            self::add($dom, $f, strlen($d) === 11 ? 'CPF' : 'CNPJ', $d);
            self::add($dom, $f, 'xNome', self::text($r->supplier['name'], 150));
        }
        self::add($dom, $doc, 'dtEmiDoc', $r->issuedOn->format('Y-m-d'));
        self::add($dom, $doc, 'dtCompDoc', $r->competenceOn->format('Y-m-d'));
        self::add($dom, $doc, 'tpReeRepRes', $r->type);
        if (! empty($r->typeDescription)) {
            self::add($dom, $doc, 'xTpReeRepRes', self::text($r->typeDescription, 150));
        }
        self::add($dom, $doc, 'vlrReeRepRes', number_format($r->amount, 2, '.', ''));
    }

    /** Pedido de registro do evento de cancelamento (101101). */
    public static function cancellation(Environment $environment, string $cnpj, string $accessKey, int $code, string $reason, DateTimeImmutable $at): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $root = $dom->appendChild($dom->createElementNS(self::NS, 'pedRegEvento'));
        $root->setAttribute('versao', self::VERSION);
        $inf = self::add($dom, $root, 'infPedReg');
        // "PRE" + chave de acesso (50) + tipo do evento (6): o XSD fixa 56 dígitos.
        $inf->setAttribute('Id', 'PRE'.$accessKey.'101101');
        self::add($dom, $inf, 'tpAmb', $environment->code());
        self::add($dom, $inf, 'verAplic', self::APP);
        self::add($dom, $inf, 'dhEvento', self::dateTime($at));
        self::add($dom, $inf, 'CNPJAutor', $cnpj);
        self::add($dom, $inf, 'chNFSe', $accessKey);
        $e = self::add($dom, $inf, 'e101101');
        self::add($dom, $e, 'xDesc', 'Cancelamento de NFS-e');
        self::add($dom, $e, 'cMotivo', (string) $code);
        // O motivo exige 15+ caracteres: completa com pontos (mb_str_pad só existe no PHP 8.3).
        $reason = trim($reason);
        self::add($dom, $e, 'xMotivo', self::text($reason.str_repeat('.', max(0, 15 - mb_strlen($reason))), 255));

        return (string) $dom->saveXML();
    }

    private static function add(DOMDocument $dom, \DOMNode $parent, string $name, ?string $value = null): DOMElement
    {
        $el = $dom->createElementNS(self::NS, $name);
        if ($value !== null) {
            $el->appendChild($dom->createTextNode($value));
        }
        $parent->appendChild($el);

        return $el;
    }

    /** Data e hora no fuso de Brasília, sem frações (AAAA-MM-DDThh:mm:ss-03:00). */
    private static function dateTime(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('Y-m-d\TH:i:sP');
    }

    private static function text(string $value, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/', ' ', $value)), 0, $max);
    }
}
