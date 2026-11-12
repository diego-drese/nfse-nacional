<?php

declare(strict_types=1);

namespace DiegoDrese\NfseNacional\Http;

use DiegoDrese\NfseNacional\Certificate;

/** Transporte padrão: cURL com o certificado e a chave em arquivos temporários (0600) que só existem durante a chamada. */
final class CurlTransport implements Transport
{
    public function send(string $method, string $url, ?string $body, array $headers, Certificate $certificate, int $timeout): array
    {
        $cert = (string) tempnam(sys_get_temp_dir(), 'nfse-cert-');
        $key = (string) tempnam(sys_get_temp_dir(), 'nfse-key-');
        try {
            file_put_contents($cert, $certificate->certPem);
            file_put_contents($key, $certificate->keyPem);
            chmod($key, 0600);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_SSLCERT => $cert, CURLOPT_SSLKEY => $key,
                CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $timeout, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, CURLOPT_HTTPHEADER => $headers,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $response = curl_exec($ch);
            if ($response === false) {
                return [0, curl_error($ch)];
            }

            return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) $response];
        } finally {
            @unlink($cert);
            @unlink($key);
        }
    }
}
