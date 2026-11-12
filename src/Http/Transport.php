<?php

declare(strict_types=1);

namespace DiegoDrese\NfseNacional\Http;

use DiegoDrese\NfseNacional\Certificate;

/**
 * Envio HTTP com autenticação mútua (TLS com o certificado A1). O padrão é o cURL (CurlTransport); troque para testar sem
 * rede, para passar por um proxy ou para registrar as chamadas do seu jeito.
 */
interface Transport
{
    /**
     * @param  list<string>  $headers
     * @return array{0: int, 1: string} status HTTP (0 = sem resposta) e corpo (ou a mensagem de erro de rede)
     */
    public function send(string $method, string $url, ?string $body, array $headers, Certificate $certificate, int $timeout): array;
}
