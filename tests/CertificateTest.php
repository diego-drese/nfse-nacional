<?php

declare(strict_types=1);

namespace DiegoDrese\NfseNacional\Tests;

use DiegoDrese\NfseNacional\Certificate;
use DiegoDrese\NfseNacional\Exceptions\CertificateException;
use DiegoDrese\NfseNacional\Tests\Support\TestCertificate;
use PHPUnit\Framework\TestCase;

final class CertificateTest extends TestCase
{
    public function test_it_reads_the_holder_cnpj_and_validity(): void
    {
        [$pfx, $password] = TestCertificate::pfx('11222333000181');
        $cert = Certificate::fromPfx($pfx, $password);

        $this->assertSame('11222333000181', $cert->cnpj());
        $this->assertSame('EMPRESA DE TESTE LTDA:11222333000181', $cert->subject());
        $this->assertFalse($cert->expired());
        $this->assertStringNotContainsString('BEGIN', $cert->base64());
    }

    public function test_a_wrong_password_says_what_to_check(): void
    {
        [$pfx] = TestCertificate::pfx();
        $this->expectException(CertificateException::class);
        $this->expectExceptionMessage('confira o arquivo .pfx e a senha');
        Certificate::fromPfx($pfx, 'senha-errada');
    }
}
