<?php

declare(strict_types=1);

namespace DiegoDrese\NfseNacional\Tests;

use DateTimeImmutable;
use DateTimeZone;
use DiegoDrese\NfseNacional\Certificate;
use DiegoDrese\NfseNacional\Dps\Address;
use DiegoDrese\NfseNacional\Dps\Dps;
use DiegoDrese\NfseNacional\Dps\Provider;
use DiegoDrese\NfseNacional\Dps\Taker;
use DiegoDrese\NfseNacional\Environment;
use DiegoDrese\NfseNacional\Tests\Support\TestCertificate;
use DiegoDrese\NfseNacional\Xml\DpsXml;
use DiegoDrese\NfseNacional\Xml\SchemaValidator;
use DiegoDrese\NfseNacional\Xml\XmlSigner;
use DOMDocument;
use PHPUnit\Framework\TestCase;

final class DpsTest extends TestCase
{
    public static function dps(string $tribNac = '010301'): Dps
    {
        $at = new DateTimeImmutable('2026-11-15 08:30', new DateTimeZone('America/Sao_Paulo'));

        return new Dps(Environment::Homologacao, '1', 42, $at, $at, new Provider('11222333000181', '4314902', '123456-7', 3, 1),
            new Taker('99.888.777/0001-66', 'Cliente & Filhos', new Address('90010-000', 'Rua dos Andradas', '100', 'Centro', '4314902', 'sala 2'), 'financeiro@cliente.test', '(51) 3333-4444'),
            $tribNac, 'Processamento de dados. Mensalidade 11/2026.', 648.40, 2.01, 6.0);
    }

    public function test_the_id_follows_the_official_rule(): void
    {
        $this->assertSame('DPS431490221122233300018100001000000000000042', self::dps()->id());
    }

    public function test_the_signed_dps_passes_the_official_schema_and_the_signature_checks(): void
    {
        [$pfx, $password] = TestCertificate::pfx();
        $cert = Certificate::fromPfx($pfx, $password);
        $signed = (new XmlSigner($cert))->sign(DpsXml::dps(self::dps()), 'infDPS');

        $this->assertSame([], SchemaValidator::errors($signed, SchemaValidator::DPS));
        foreach (['<serie>00001</serie>', '<nDPS>42</nDPS>', '<tpAmb>2</tpAmb>', '<pAliq>2.01</pAliq>', '<pTotTribSN>6.00</pTotTribSN>', '<regApTribSN>1</regApTribSN>',
            '<CNPJ>99888777000166</CNPJ>', '<CEP>90010000</CEP>', '<dhEmi>2026-11-15T08:30:00-03:00</dhEmi>', '<vServ>648.40</vServ>'] as $piece) {
            $this->assertStringContainsString($piece, $signed);
        }

        $dom = new DOMDocument;
        $dom->loadXML($signed);
        $signature = $dom->getElementsByTagName('Signature')->item(0);
        $signedInfo = $dom->getElementsByTagName('SignedInfo')->item(0)->C14N(false, false);
        $value = $dom->getElementsByTagName('SignatureValue')->item(0)->textContent;
        $digest = $dom->getElementsByTagName('DigestValue')->item(0)->textContent;
        $signature->parentNode->removeChild($signature);
        $this->assertSame(base64_encode(hash('sha1', $dom->getElementsByTagName('infDPS')->item(0)->C14N(false, false), true)), $digest);
        $this->assertSame(1, openssl_verify($signedInfo, base64_decode($value), $cert->certPem, OPENSSL_ALGO_SHA1));
    }

    public function test_without_the_simples_total_the_dps_says_it_does_not_declare(): void
    {
        $d = self::dps();
        $plain = new Dps($d->environment, $d->series, $d->number, $d->issuedAt, $d->competence, new Provider('11222333000181', '4314902', null, 1), $d->taker, $d->tribNac, $d->description, 100.0);
        $xml = DpsXml::dps($plain);
        $this->assertStringContainsString('<indTotTrib>0</indTotTrib>', $xml);
        $this->assertStringNotContainsString('regApTribSN', $xml, 'Só ME/EPP informa o regime de apuração.');
    }

    public function test_the_cancellation_request_passes_the_official_schema(): void
    {
        [$pfx, $password] = TestCertificate::pfx();
        $key = str_repeat('4', 50);
        $signed = (new XmlSigner(Certificate::fromPfx($pfx, $password)))->sign(DpsXml::cancellation(Environment::Producao, '11222333000181', $key, 2, 'Curto', new DateTimeImmutable), 'infPedReg');

        $this->assertSame([], SchemaValidator::errors($signed, SchemaValidator::EVENT));
        $this->assertStringContainsString('Id="PRE'.$key.'101101"', $signed);
        $this->assertStringContainsString('<tpAmb>1</tpAmb>', $signed);
    }

    public function test_a_layout_error_is_explained_with_the_field(): void
    {
        [$pfx, $password] = TestCertificate::pfx();
        $signed = (new XmlSigner(Certificate::fromPfx($pfx, $password)))->sign(DpsXml::dps(self::dps('01030')), 'infDPS');
        $errors = SchemaValidator::errors($signed, SchemaValidator::DPS);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('cTribNac', implode(' ', $errors));
    }
}
