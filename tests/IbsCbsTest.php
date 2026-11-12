<?php

declare(strict_types=1);

namespace DiegoDrese\NfseNacional\Tests;

use DateTimeImmutable;
use DiegoDrese\NfseNacional\Certificate;
use DiegoDrese\NfseNacional\Dps\Dps;
use DiegoDrese\NfseNacional\Dps\IbsCbs;
use DiegoDrese\NfseNacional\Dps\Provider;
use DiegoDrese\NfseNacional\Dps\Reimbursement;
use DiegoDrese\NfseNacional\Environment;
use DiegoDrese\NfseNacional\SefinClient;
use DiegoDrese\NfseNacional\Tests\Support\FakeTransport;
use DiegoDrese\NfseNacional\Tests\Support\TestCertificate;
use DiegoDrese\NfseNacional\Xml\DpsRules;
use DiegoDrese\NfseNacional\Xml\DpsXml;
use DiegoDrese\NfseNacional\Xml\SchemaValidator;
use DiegoDrese\NfseNacional\Xml\XmlSigner;
use PHPUnit\Framework\TestCase;

/** Reforma tributária: grupo IBS/CBS (classificação) e repasse de terceiro na DPS; valores calculados lidos da NFS-e. */
final class IbsCbsTest extends TestCase
{
    /** Serviço de R$ 799,00 em que R$ 120,00 são repasse de um fornecedor (ex.: monitoramento de terceiro). */
    public static function dps(?IbsCbs $ibsCbs = null, ?string $nbs = '112011000'): Dps
    {
        $d = DpsTest::dps();
        $ibsCbs ??= new IbsCbs('010101', '000', '000001', reimbursements: [
            new Reimbursement('99', 120.00, new DateTimeImmutable('2026-11-05'), new DateTimeImmutable('2026-11-01'),
                dfeKey: ['type' => 1, 'key' => str_repeat('3', 50)], supplier: ['document' => '55.666.777/0001-88', 'name' => 'Fornecedor de Rastreamento Ltda'],
                typeDescription: 'Repasse do serviço de rastreamento contratado por conta e ordem do cliente'),
            new Reimbursement('99', 30.00, new DateTimeImmutable('2026-11-06'), new DateTimeImmutable('2026-11-01'),
                otherDocument: ['number' => 'FAT-123', 'description' => 'Fatura do fornecedor'], typeDescription: 'Taxa de ativação repassada'),
        ]);

        return new Dps($d->environment, $d->series, $d->number, $d->issuedAt, $d->competence, new Provider('11222333000181', '4314902', '123456-7', 1),
            $d->taker, '010301', 'Serviço com repasse de terceiro.', 799.00, 2.0, null, $nbs, $ibsCbs);
    }

    public function test_the_ibs_cbs_group_with_reimbursements_passes_the_official_schema(): void
    {
        [$pfx, $password] = TestCertificate::pfx();
        $signed = (new XmlSigner(Certificate::fromPfx($pfx, $password)))->sign(DpsXml::dps(self::dps()), 'infDPS');

        $this->assertSame([], SchemaValidator::errors($signed, SchemaValidator::DPS));
        $this->assertSame([], DpsRules::errors(self::dps()));
        foreach (['<cIndOp>010101</cIndOp>', '<CST>000</CST>', '<cClassTrib>000001</cClassTrib>', '<tpReeRepRes>99</tpReeRepRes>', '<vlrReeRepRes>120.00</vlrReeRepRes>',
            '<tipoChaveDFe>1</tipoChaveDFe>', '<nDoc>FAT-123</nDoc>', '<cNBS>112011000</cNBS>', '<CNPJ>55666777000188</CNPJ>'] as $piece) {
            $this->assertStringContainsString($piece, $signed);
        }
        $this->assertLessThan(strpos($signed, '<IBSCBS>'), strpos($signed, '</valores>'), 'O grupo IBSCBS vem depois de valores.');
    }

    public function test_rules_the_schema_does_not_check_are_explained_before_sending(): void
    {
        $this->assertContains('Com IBS/CBS, o código NBS do serviço é obrigatório.', DpsRules::errors(self::dps(nbs: null)));
        $tooMuch = new IbsCbs('010101', '000', '000001', reimbursements: [new Reimbursement('99', 900.0, new DateTimeImmutable, new DateTimeImmutable)]);
        $errors = DpsRules::errors(self::dps($tooMuch));
        $this->assertContains('Os repasses somam mais que o valor do serviço.', $errors);
        $this->assertContains('Repasse 1: informe o documento do fornecedor (chave, nota municipal ou outro documento).', $errors);
        $this->assertContains('Repasse 1: no tipo 99 (outros), descreva o reembolso.', $errors);

        [$pfx, $password] = TestCertificate::pfx();
        $transport = new FakeTransport([]);
        $result = (new SefinClient(Environment::Homologacao, Certificate::fromPfx($pfx, $password), transport: $transport))->issue(self::dps(nbs: null));
        $this->assertSame(['rejected', 'REGRA'], [$result->status, $result->errors[0]['code']]);
        $this->assertSame([], $transport->calls, 'Não chama a Sefin.');
    }

    public function test_the_ibs_cbs_values_calculated_by_the_national_system_come_back_in_the_result(): void
    {
        $key = str_repeat('6', 50);
        $nfse = '<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse"><infNFSe><nNFSe>10</nNFSe><IBSCBS><cLocalidadeIncid>4314902</cLocalidadeIncid><xLocalidadeIncid>Porto Alegre</xLocalidadeIncid>'
            .'<valores><vBC>649.00</vBC><uf><pIBSUF>0.10</pIBSUF><pAliqEfetUF>0.10</pAliqEfetUF></uf><mun><pIBSMun>0.00</pIBSMun><pAliqEfetMun>0.00</pAliqEfetMun></mun>'
            .'<fed><pCBS>0.90</pCBS><pAliqEfetCBS>0.90</pAliqEfetCBS></fed></valores><totCIBS><vTotNF>799.00</vTotNF><gIBS><vIBSTot>0.65</vIBSTot></gIBS><gCBS><vCBS>5.84</vCBS></gCBS></totCIBS></IBSCBS></infNFSe></NFSe>';
        [$pfx, $password] = TestCertificate::pfx();
        $transport = new FakeTransport([[201, (string) json_encode(['chaveAcesso' => $key, 'nfseXmlGZipB64' => base64_encode((string) gzencode($nfse))])]]);
        $result = (new SefinClient(Environment::Homologacao, Certificate::fromPfx($pfx, $password), transport: $transport))->issue(self::dps());

        $this->assertTrue($result->ok());
        $this->assertSame(['base' => 649.0, 'ibs' => 0.65, 'cbs' => 5.84, 'total' => 799.0, 'cbs_rate' => 0.9, 'ibs_state_rate' => 0.1, 'ibs_city_rate' => 0.0, 'city' => 'Porto Alegre'], $result->ibsCbs);
    }
}
