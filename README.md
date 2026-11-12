# nfse-nacional

Emissão de **NFS-e no padrão nacional** (Sefin Nacional / ADN) em **PHP puro**: monta a DPS no leiaute oficial, valida contra os
schemas da Receita, assina com o certificado A1, emite, consulta, cancela e baixa o DANFSe (PDF).

- **Sem framework e sem Guzzle.** Só extensões do PHP (`curl`, `dom`, `openssl`, `zlib`, `mbstring`). Funciona em Laravel,
  Symfony ou PHP solto, sem conflito de versão com o resto do projeto.
- **Diagnóstico simples.** Erro de leiaute aparece **antes** de enviar, com o campo e a regra do schema. Erro da Sefin volta com
  o código (`E0014 - ...`). Toda resposta traz o XML enviado, o status HTTP e o corpo cru da resposta.
- **Sem regra de negócio.** O pacote só fala com a NFS-e Nacional. Quando emitir, numeração da DPS, alíquota do mês, retenção
  de nota etc. ficam no seu sistema.

> ⚠️ **Projeto desenvolvido inteiramente por IA** (Claude, da Anthropic), sob a orientação de um desenvolvedor humano.
> **Use por sua conta e risco**: não há garantia de nenhum tipo, e nota fiscal tem efeito fiscal real. Teste em homologação
> antes de produção. Não há suporte: se quiser mudar algo, faça seu fork e siga feliz.

> Leiaute **1.01** da DPS. Testado contra os schemas oficiais; a homologação na Sefin depende de um certificado A1 real da
> sua empresa (veja [Homologação](#homologação-produção-restrita)).

## Instalação

```bash
composer require diego-drese/nfse-nacional
```

Requisitos: PHP 8.2+, e para certificados A1 antigos (cifra RC2-40) o comando `openssl` disponível no servidor (veja
[Certificado A1](#certificado-a1)).

## Uso rápido

```php
use DiegoDrese\NfseNacional\Certificate;
use DiegoDrese\NfseNacional\Dps\{Address, Dps, Provider, Taker};
use DiegoDrese\NfseNacional\Environment;
use DiegoDrese\NfseNacional\SefinClient;

$certificate = Certificate::fromPfx(file_get_contents('/caminho/empresa.pfx'), 'senha-do-certificado');
$sefin = new SefinClient(Environment::Homologacao, $certificate);

$now = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
$dps = new Dps(
    environment: Environment::Homologacao,
    series: '1',
    number: 42,                       // sequencial por série: guarde e incremente no seu sistema
    issuedAt: $now,
    competence: $now,
    provider: new Provider(cnpj: '11222333000181', cityCode: '4314902', municipalRegistration: '123456-7', simplesOption: 3, simplesRegime: 1),
    taker: new Taker(
        document: '99.888.777/0001-66',
        name: 'Cliente Exemplo Ltda',
        address: new Address(zip: '90010-000', street: 'Rua dos Andradas', number: '100', district: 'Centro', cityCode: '4314902'),
        email: 'financeiro@cliente.com.br',
    ),
    tribNac: '010301',                // código de tributação nacional (LC 116 01.03.01 → 010301)
    description: 'Processamento de dados. Mensalidade 11/2026.',
    amount: 648.40,
    issRate: 2.01,                    // alíquota de ISS (no Simples Nacional, a do mês)
    simplesTotalRate: 6.00,           // opcional: % total do Simples para o "valor aproximado dos tributos"
);

$result = $sefin->issue($dps);

if ($result->ok()) {
    $result->accessKey;   // chave de acesso (50 dígitos)
    $result->number;      // número da NFS-e
    $result->nfseXml;     // XML autorizado: guarde
    $pdf = $sefin->danfse($result->accessKey);   // PDF (pode ser null se o ADN ainda não gerou: tente depois)
} else {
    $result->status;      // 'rejected' (corrigir o dado) ou 'unavailable' (tentar de novo)
    $result->message();   // "E0014 - Conjunto de Série e Número já existe (...)"
}
```

### Reenvio sem duplicar

Se a emissão ficou sem resposta (`unavailable`), a nota pode ter sido gerada mesmo assim. Antes de reenviar, pergunte:

```php
$existing = $sefin->findByDps($dps->id());   // Result com a chave e o XML, ou null se a DPS não virou nota
$result = $existing ?? $sefin->issue($dps);  // mesmo número de DPS: a Sefin nunca gera duas notas
```

### Cancelamento

```php
// Motivo: 1 erro na emissão, 2 serviço não prestado, 9 outros. Texto com 15+ caracteres.
$result = $sefin->cancel($accessKey, cnpj: '11222333000181', code: 1, reason: 'Valor informado errado na emissão');
```

## Prestador do Simples Nacional

| Campo | DPS | Valores |
|---|---|---|
| `simplesOption` | `opSimpNac` | 1 não optante · 2 MEI · 3 ME/EPP |
| `simplesRegime` | `regApTribSN` | só ME/EPP: 1 tudo pelo Simples · 2 federais pelo Simples, ISS fora · 3 tudo fora |
| `issRate` | `pAliq` | alíquota de ISS (no Simples muda conforme a faixa de faturamento) |
| `simplesTotalRate` | `pTotTribSN` | % total do Simples; sem ele a DPS informa `indTotTrib = 0` (não declara) |

O ISS sai como tributável no município do prestador, sem retenção (`tribISSQN = 1`, `tpRetISSQN = 1`). Para uma operação
fora do ISS, como a locação de bem móvel (STF Súmula Vinculante 31), use `issTaxation: Dps::ISS_NON_INCIDENCE`
(`tribISSQN = 4`, sem alíquota). Outros cenários (retenção, exportação, imunidade, deduções) ainda não estão no `DpsXml`: veja
[Limitações](#limitações).

## Reforma tributária: IBS e CBS

O leiaute 1.01 tem o grupo opcional **IBSCBS**. Na DPS vai só a **classificação** da operação; **alíquotas e valores quem
calcula é o sistema nacional**, e a nota volta com eles. Então o seu sistema não precisa manter tabela de alíquota de
IBS/CBS para emitir, só classificar cada serviço.

```php
use DiegoDrese\NfseNacional\Dps\{IbsCbs, Reimbursement};

$dps = new Dps(
    // ... os mesmos campos de antes ...
    nbs: '112011000',                 // com IBS/CBS, o NBS do serviço é obrigatório
    ibsCbs: new IbsCbs(
        operationIndicator: '010101', // cIndOp: tabela "código indicador de operação"
        cst: '000',                   // situação tributária do IBS/CBS
        classification: '000001',     // cClassTrib: classificação tributária
        reimbursements: [             // opcional: valores de terceiros, fora da base do IBS/CBS
            new Reimbursement(
                type: '99',           // 99 = outros reembolsos por conta e ordem de terceiro
                amount: 120.00,
                issuedOn: new DateTimeImmutable('2026-11-05'),
                competenceOn: new DateTimeImmutable('2026-11-01'),
                dfeKey: ['type' => 1, 'key' => '<chave de 50 dígitos da NFS-e do fornecedor>'],
                supplier: ['document' => '55.666.777/0001-88', 'name' => 'Fornecedor Ltda'],
                typeDescription: 'Repasse do serviço contratado por conta e ordem do cliente',
            ),
        ],
    ),
);

$result = $sefin->issue($dps);
$result->ibsCbs;   // ['base' => 649.0, 'ibs' => 0.65, 'cbs' => 5.84, 'total' => 799.0, 'cbs_rate' => 0.9, ...]
```

- **Quem precisa informar e quando** depende do regime da empresa e do calendário da reforma (2026 é o ano de teste).
  Confirme com o seu contador.
- **Os códigos** (`cIndOp`, `CST`, `cClassTrib`) vêm das tabelas oficiais da reforma e dependem do serviço. Guarde-os no
  cadastro do **serviço/produto** do seu sistema, com data de vigência, em vez de fixar na empresa.
- **Repasse/reembolso** precisa do documento do fornecedor: chave de NFS-e/NF-e/CT-e, nota municipal (`municipalDocument`) ou
  outro documento (`otherDocument`). A soma dos repasses não pode passar do valor do serviço.
- **A NFS-e não tem itens**: é um serviço por nota. Serviços com classificação diferente viram notas separadas.

Antes de enviar, o pacote confere regras que o schema não pega (`DpsRules`: NBS obrigatório com IBS/CBS, documento do repasse,
soma dos repasses...) e devolve `rejected` com código `REGRA` e a explicação, sem chamar a Sefin.

## Certificado A1

```php
$cert = Certificate::fromPfx($pfxBinario, $senha);
$cert->cnpj();          // CNPJ do titular (do e-CNPJ)
$cert->validUntil();    // DateTimeImmutable
$cert->expired();       // a emissão recusa certificado vencido sem chamar a Sefin
```

Muitos A1 brasileiros usam a cifra antiga RC2-40, que o OpenSSL 3 não lê por padrão. Se a leitura normal falhar, o pacote
converte pela linha de comando (`openssl pkcs12 -legacy`) num diretório temporário apagado em seguida. Senha errada ou
arquivo inválido: `CertificateException` com "confira o arquivo .pfx e a senha".

Guarde o PFX e a senha **cifrados** no seu sistema. O pacote só os usa em memória e em arquivos temporários (0600) durante
cada chamada.

## Diagnóstico

Quando algo dá errado, olhe nesta ordem:

1. **`$result->errors` com código `REGRA`**: alguma regra da DPS que o schema não confere (ex.: NBS com IBS/CBS); nada foi
   enviado. **Código `XSD`**: a DPS não passou no schema oficial; nada foi enviado. A mensagem diz o campo e a
   regra (ex.: `Element 'cTribNac': [facet 'pattern'] The value '01030' is not accepted by the pattern '[0-9]{6}'`).
2. **`CERT`**: certificado vencido.
3. **Código da Sefin** (`E0xxx`): o dado está no formato certo, mas a Sefin recusou (série/número repetido, município não
   conveniado, inscrição municipal, alíquota...). A mensagem e o complemento vêm como a Sefin mandou.
4. **`unavailable`**: sem resposta (status 0) ou erro do servidor (5xx, 429). Tentar de novo é seguro (use `findByDps` antes).

Todo `Result` traz `sentXml` (a DPS assinada), `httpStatus` e `response` (corpo cru). Guarde junto da nota no seu sistema e
você sempre vai saber o que foi enviado e o que voltou. Para registrar cada chamada:

```php
$sefin = new SefinClient(Environment::Producao, $certificate, log: function (string $method, string $url, int $status, string $body) use ($logger) {
    $logger->info("NFS-e {$method} {$url} → {$status}", ['body' => $body]);
});
```

O transporte HTTP é trocável (`DiegoDrese\NfseNacional\Http\Transport`): use isso para testes sem rede, proxy ou outro cliente HTTP.

## Ambientes e endereços

| | Produção | Homologação (produção restrita) |
|---|---|---|
| Sefin Nacional | `https://sefin.nfse.gov.br/SefinNacional` | `https://sefin.producaorestrita.nfse.gov.br/SefinNacional` |
| ADN (DANFSe) | `https://adn.nfse.gov.br` | `https://adn.producaorestrita.nfse.gov.br` |

Municípios com endpoint próprio: informe `sefinUrl` e `adnUrl` no construtor do `SefinClient`.

### Homologação (produção restrita)

Use `Environment::Homologacao` com o certificado A1 real da empresa. A nota de homologação não tem valor fiscal. Comece por
lá, confira o PDF e o XML, e só então troque para `Environment::Producao`.

## Quando a Receita mudar o leiaute

1. Troque os `.xsd` em `resources/schemas/` pelos da nova versão.
2. Ajuste `src/Xml/DpsXml.php` (`VERSION` e os campos novos ou alterados).
3. Rode os testes: eles validam o XML gerado contra os schemas. Se passar, a DPS está no formato novo.

## Limitações

- Prestador pessoa jurídica (CNPJ). Tomador com endereço nacional (sem exterior).
- ISS tributável no município do prestador, sem retenção; sem deduções, descontos, obra, evento ou intermediário.
- IBS/CBS: classificação, consumo final, compras governamentais e repasses; ainda sem diferimento, crédito presumido,
  tributação regular (`gTribRegular`), destinatário diferente do tomador e imóvel. Sem substituição de nota (só cancelamento).

Pull requests podem ser olhados, mas não há compromisso de manutenção: o caminho certo para o seu caso é o fork.

## Por que este pacote existe

Ele nasceu dentro de um sistema de gestão para escritórios de radiodifusão que precisava emitir a NFS-e das mensalidades, e
foi separado para ser usado em outros projetos que também emitem nota: o que muda entre eles são as regras **antes** de
emitir; a conversa com a NFS-e Nacional é sempre a mesma.

A referência foi o **[nfse-nacional/nfse-php](https://github.com/nfse-nacional/nfse-php)** (MIT), um SDK bem mais completo
(contribuinte e município, distribuição de DF-e, parâmetros municipais, IBS/CBS). Ele não serviu diretamente porque:

- exige o **Guzzle 7**, e projetos em Laravel recentes já usam o Guzzle 8: instalar obrigaria rebaixar o cliente HTTP do
  projeto inteiro;
- ainda está em versão **beta** e depende do `spatie/data-transfer-object`, que foi **abandonado**;
- o SeadV2 precisava de pouca coisa (emitir, consultar, cancelar, PDF) e de **diagnóstico fácil** quando algo der errado.

Daqui vieram os schemas oficiais (XSD), a lógica da assinatura XML e os endereços da Sefin/ADN. O resto foi escrito do zero,
sem dependências além das extensões do PHP. Se você precisa de algo que este pacote não cobre, o `nfse-php` provavelmente
cobre.

## Desenvolvimento

Arquitetura, fluxo de uma emissão, como rodar os testes, como incluir um campo da DPS e como atualizar o leiaute:
[docs/DESENVOLVIMENTO.md](docs/DESENVOLVIMENTO.md).

## Testes

```bash
composer install
composer test
```

Os testes geram um certificado autoassinado na hora e simulam a Sefin pelo `Transport`. Nenhuma chamada sai para a internet.

## Licença e aviso

MIT. A assinatura XML foi adaptada do [nfse-nacional/nfse-php](https://github.com/nfse-nacional/nfse-php) (MIT).

Código escrito por IA (Claude, Anthropic). O software é fornecido "como está", sem garantia; quem usa assume a
responsabilidade pelas notas emitidas. Confira sempre em homologação e com o seu contador.
