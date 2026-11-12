<?php

declare(strict_types=1);

namespace DiegoDrese\NfseNacional;

use DateTimeImmutable;
use DiegoDrese\NfseNacional\Dps\Dps;
use DiegoDrese\NfseNacional\Http\CurlTransport;
use DiegoDrese\NfseNacional\Http\Transport;
use DiegoDrese\NfseNacional\Xml\DpsRules;
use DiegoDrese\NfseNacional\Xml\DpsXml;
use DiegoDrese\NfseNacional\Xml\SchemaValidator;
use DiegoDrese\NfseNacional\Xml\XmlSigner;

/**
 * Cliente da NFS-e Nacional (Sefin Nacional + ADN), com autenticação mútua pelo certificado A1 (só cURL, sem framework):
 *  - POST {sefin}/nfse {dpsXmlGZipB64}                          emitir
 *  - GET  {sefin}/dps/{idDps} e {sefin}/nfse/{chave}            a DPS já virou nota? (reenvio sem duplicar)
 *  - POST {sefin}/nfse/{chave}/eventos {pedidoRegistroEventoXmlGZipB64}  cancelar (evento 101101)
 *  - GET  {adn}/danfse/{chave}                                   PDF
 * A DPS é validada contra o schema oficial antes de sair. `$log` (opcional) recebe cada chamada para diagnóstico; o
 * `Transport` (cURL por padrão) pode ser trocado em testes.
 */
final class SefinClient
{
    /** @param (callable(string $method, string $url, int $status, string $body): void)|null $log */
    public function __construct(
        private readonly Environment $environment,
        private readonly Certificate $certificate,
        private readonly ?string $sefinUrl = null,
        private readonly ?string $adnUrl = null,
        private readonly mixed $log = null,
        private readonly int $timeout = 60,
        private readonly ?Transport $transport = null,
    ) {}

    public function issue(Dps $dps): Result
    {
        if ($this->certificate->expired()) {
            return new Result('rejected', errors: [['code' => 'CERT', 'message' => 'O certificado A1 venceu em '.$this->certificate->validUntil()->format('d/m/Y').'.']]);
        }
        if (($broken = DpsRules::errors($dps)) !== []) {
            return new Result('rejected', errors: array_map(fn ($m) => ['code' => 'REGRA', 'message' => $m], $broken));
        }
        $signed = (new XmlSigner($this->certificate))->sign(DpsXml::dps($dps), 'infDPS');
        if (($invalid = SchemaValidator::errors($signed, SchemaValidator::DPS)) !== []) {
            return new Result('rejected', errors: array_map(fn ($m) => ['code' => 'XSD', 'message' => $m], $invalid), sentXml: $signed);
        }
        [$status, $body] = $this->call('POST', $this->sefin('/nfse'), ['dpsXmlGZipB64' => base64_encode((string) gzencode($signed))]);

        return $this->issued($status, $body, $signed);
    }

    public function findByDps(string $dpsId): ?Result
    {
        [$status, $body] = $this->call('GET', $this->sefin('/dps/'.$dpsId));
        $key = self::json($body)['chaveAcesso'] ?? null;
        if ($status !== 200 || $key === null) {
            return null;
        }
        [$status, $body] = $this->call('GET', $this->sefin('/nfse/'.$key));

        return $status === 200 ? $this->issued($status, $body, null) : new Result('issued', accessKey: (string) $key, httpStatus: $status, response: $body);
    }

    /** Cancelamento: `$code` 1 erro na emissão, 2 serviço não prestado, 9 outros; motivo com 15+ caracteres. */
    public function cancel(string $accessKey, string $cnpj, int $code, string $reason): Result
    {
        $signed = (new XmlSigner($this->certificate))->sign(DpsXml::cancellation($this->environment, $cnpj, $accessKey, $code, $reason, new DateTimeImmutable), 'infPedReg');
        if (($invalid = SchemaValidator::errors($signed, SchemaValidator::EVENT)) !== []) {
            return new Result('rejected', errors: array_map(fn ($m) => ['code' => 'XSD', 'message' => $m], $invalid), sentXml: $signed);
        }
        [$status, $body] = $this->call('POST', $this->sefin("/nfse/{$accessKey}/eventos"), ['pedidoRegistroEventoXmlGZipB64' => base64_encode((string) gzencode($signed))]);
        $errors = self::errors(self::json($body));
        if ($status >= 200 && $status < 300 && $errors === []) {
            return new Result('issued', accessKey: $accessKey, sentXml: $signed, httpStatus: $status, response: $body);
        }

        return new Result($status === 0 || $status === 429 || $status >= 500 ? 'unavailable' : 'rejected', errors: $errors ?: [['code' => (string) $status, 'message' => 'Resposta inesperada da Sefin.']],
            sentXml: $signed, httpStatus: $status, response: $body);
    }

    public function danfse(string $accessKey): ?string
    {
        [$status, $body] = $this->call('GET', rtrim($this->adnUrl ?? $this->environment->adnUrl(), '/').'/danfse/'.$accessKey, accept: 'application/pdf');

        return $status === 200 && str_starts_with($body, '%PDF') ? $body : null;
    }

    private function issued(int $status, string $body, ?string $sent): Result
    {
        $json = self::json($body);
        $errors = self::errors($json);
        if (isset($json['chaveAcesso']) && $errors === []) {
            $xml = isset($json['nfseXmlGZipB64']) ? (string) gzdecode((string) base64_decode((string) $json['nfseXmlGZipB64'])) : null;

            return new Result('issued', accessKey: (string) $json['chaveAcesso'], number: $xml !== null && preg_match('/<nNFSe>(\d+)<\/nNFSe>/', $xml, $m) ? $m[1] : null,
                nfseXml: $xml, sentXml: $sent, httpStatus: $status, response: $body, ibsCbs: $xml !== null ? self::ibsCbs($xml) : null);
        }
        if ($status === 0 || $status === 429 || $status >= 500) {
            return new Result('unavailable', errors: $errors ?: [['code' => (string) $status, 'message' => $status === 0 ? 'Sem resposta da Sefin Nacional.' : "A Sefin Nacional respondeu {$status}."]],
                sentXml: $sent, httpStatus: $status, response: $body);
        }

        return new Result('rejected', errors: $errors ?: [['code' => (string) $status, 'message' => 'A Sefin Nacional recusou sem detalhar o motivo.']], sentXml: $sent, httpStatus: $status, response: $body);
    }

    /**
     * Valores de IBS/CBS calculados pelo sistema nacional e devolvidos na NFS-e (só quando a DPS levou o grupo IBSCBS).
     *
     * @return array{base: float, ibs: float, cbs: float, total: float, cbs_rate: ?float, ibs_state_rate: ?float, ibs_city_rate: ?float, city: ?string}|null
     */
    public static function ibsCbs(string $nfseXml): ?array
    {
        $dom = new \DOMDocument;
        if (! @$dom->loadXML($nfseXml)) {
            return null;
        }
        $xp = new \DOMXPath($dom);
        $v = fn (string $name) => ($n = $xp->query("//*[local-name()='IBSCBS']//*[local-name()='{$name}']")->item(0)) !== null ? $n->textContent : null;
        if ($v('vBC') === null) {
            return null;
        }

        return ['base' => (float) $v('vBC'), 'ibs' => (float) $v('vIBSTot'), 'cbs' => (float) $v('vCBS'), 'total' => (float) $v('vTotNF'),
            'cbs_rate' => ($r = $v('pAliqEfetCBS')) !== null ? (float) $r : null, 'ibs_state_rate' => ($r = $v('pAliqEfetUF')) !== null ? (float) $r : null,
            'ibs_city_rate' => ($r = $v('pAliqEfetMun')) !== null ? (float) $r : null, 'city' => $v('xLocalidadeIncid')];
    }

    /** @return array{0: int, 1: string} status HTTP (0 = sem resposta) e corpo */
    private function call(string $method, string $url, ?array $json = null, string $accept = 'application/json'): array
    {
        [$status, $body] = ($this->transport ?? new CurlTransport)->send($method, $url, $json === null ? null : (string) json_encode($json),
            array_values(array_filter(['Accept: '.$accept, $json !== null ? 'Content-Type: application/json' : null])), $this->certificate, $this->timeout);
        if (is_callable($this->log)) {
            ($this->log)($method, $url, $status, $accept === 'application/pdf' && $status === 200 ? '[PDF]' : mb_substr($body, 0, 20000));
        }

        return [$status, $body];
    }

    private function sefin(string $path): string
    {
        return rtrim($this->sefinUrl ?? $this->environment->sefinUrl(), '/').$path;
    }

    /** @return array<string, mixed> */
    private static function json(string $body): array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $json
     * @return list<array{code: string, message: string}>
     */
    private static function errors(array $json): array
    {
        return array_values(array_map(fn ($e) => [
            'code' => (string) ($e['codigo'] ?? $e['Codigo'] ?? ''),
            'message' => trim((string) ($e['descricao'] ?? $e['Descricao'] ?? $e['mensagem'] ?? $e['Mensagem'] ?? '').(($c = $e['complemento'] ?? $e['Complemento'] ?? null) ? " ({$c})" : '')),
        ], (array) ($json['erros'] ?? $json['Erros'] ?? [])));
    }
}
