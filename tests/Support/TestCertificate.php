<?php

declare(strict_types=1);

namespace DiegoDrese\NfseNacional\Tests\Support;

/** Certificado e-CNPJ autoassinado para os testes (gerado na hora; nunca um certificado real). */
final class TestCertificate
{
    /** @return array{0: string, 1: string} PFX e senha */
    public static function pfx(string $cnpj = '11222333000181', int $days = 365): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => "EMPRESA DE TESTE LTDA:{$cnpj}", 'countryName' => 'BR'], $key);
        $cert = openssl_csr_sign($csr, null, $key, $days);
        $password = bin2hex(random_bytes(8));
        openssl_pkcs12_export($cert, $pfx, $key, $password);

        return [$pfx, $password];
    }
}
