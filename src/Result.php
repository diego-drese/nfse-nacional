<?php

declare(strict_types=1);

namespace DiegoDrese\NfseNacional;

/**
 * Resultado de uma chamada à NFS-e Nacional, com tudo o que serve para diagnóstico: o XML enviado, o status HTTP e a resposta
 * crua (e, com a reforma, os valores de IBS/CBS que a Sefin calculou). `status`: `issued` (feito; na emissão traz a chave e o XML da nota), `rejected` (erro de dado: corrigir antes de
 * reenviar; `errors` diz o quê) ou `unavailable` (sem resposta ou erro do servidor: tentar de novo é seguro).
 */
final readonly class Result
{
    /** @param list<array{code: string, message: string}> $errors */
    public function __construct(
        public string $status,
        public ?string $accessKey = null,
        public ?string $number = null,
        public ?string $nfseXml = null,
        public array $errors = [],
        public ?string $sentXml = null,
        public int $httpStatus = 0,
        public ?string $response = null,
        /** IBS/CBS calculados pelo sistema nacional (base, ibs, cbs, total e alíquotas efetivas), quando a DPS levou o grupo. */
        public ?array $ibsCbs = null,
    ) {}

    public function ok(): bool
    {
        return $this->status === 'issued';
    }

    /** Mensagem para mostrar: "E0014 - descrição (complemento); …". */
    public function message(): string
    {
        return implode('; ', array_map(fn ($e) => trim($e['code'].' - '.$e['message'], ' -'), $this->errors));
    }
}
