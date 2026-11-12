# Desenvolvimento

Guia para quem vai mexer no código (inclusive uma IA). O pacote é pequeno de propósito: cada arquivo tem uma
responsabilidade e nenhum depende de framework.

> Projeto escrito por IA (Claude, Anthropic). Use por sua conta e risco; para mudar, faça um fork.

## Estrutura

```
src/
  Environment.php          Produção × homologação (tpAmb e endereços da Sefin/ADN)
  Certificate.php          Lê o PFX (com fallback "openssl -legacy"), CNPJ, validade, assina
  Dps/                     Dados de entrada (imutáveis): Dps, Provider, Taker, Address
  Xml/DpsXml.php           Monta o XML da DPS e do pedido de cancelamento (leiaute 1.01)
  Xml/XmlSigner.php        Assinatura XMLDSig envelopada (C14N, RSA-SHA1)
  Xml/SchemaValidator.php  Valida contra os .xsd oficiais e devolve as mensagens
  SefinClient.php          Emitir, consultar pela DPS, cancelar, DANFSe; monta o Result
  Result.php               Resultado com status, erros, XML enviado, HTTP e resposta crua
  Http/Transport.php       Contrato do envio HTTP com TLS mútuo
  Http/CurlTransport.php   Implementação com cURL (padrão)
  Exceptions/              CertificateException
resources/schemas/         .xsd oficiais (leiaute 1.01) + LEIA-ME com o ajuste local
tests/                     PHPUnit; Support/ gera certificado de teste e simula a Sefin
```

## Fluxo de uma emissão

1. **Dados** (`Dps`): quem usa monta a DPS com o número sequencial que ele mesmo controla.
2. **XML** (`DpsXml::dps`): elementos na ordem exigida pelo schema, no namespace `http://www.sped.fazenda.gov.br/nfse`.
3. **Assinatura** (`XmlSigner::sign($xml, 'infDPS')`): resumo SHA-1 do `infDPS` canonizado, `SignedInfo` assinado com a
   chave do A1, certificado no `KeyInfo`. A `Signature` fica irmã do `infDPS`, dentro de `DPS`.
4. **Validação** (`SchemaValidator`): se o XML assinado não passa no `DPS_v1.01.xsd`, o `SefinClient` devolve `rejected`
   com código `XSD` e **não chama a Sefin**.
5. **Envio** (`SefinClient::issue`): `POST {sefin}/nfse` com `{"dpsXmlGZipB64": base64(gzip(xml))}`, TLS mútuo com o A1.
6. **Resposta**: sucesso traz `chaveAcesso` e `nfseXmlGZipB64` (o número da nota sai do `<nNFSe>`); erro traz `erros`
   (`Codigo`, `Descricao`, `Complemento`). 0/429/5xx = `unavailable` (repetir é seguro com `findByDps` antes).

Cancelamento: mesmo caminho com `DpsXml::cancellation` (Id = `PRE` + chave + `101101`), assinando `infPedReg` e enviando
para `POST {sefin}/nfse/{chave}/eventos` em `pedidoRegistroEventoXmlGZipB64`.

## Rodando os testes

Com PHP 8.2+ local:

```bash
composer install
composer test
```

Sem PHP local, com Docker:

```bash
docker run --rm -v "$PWD":/w -w /w composer:2 sh -c "composer install -q && vendor/bin/phpunit"
```

O CI (`.github/workflows/tests.yml`) roda em PHP 8.2, 8.3 e 8.4. Atenção a funções novas do PHP: `mb_str_pad`, por
exemplo, só existe no 8.3 (já quebrou o CI uma vez).

Os testes não acessam a internet: `tests/Support/TestCertificate` gera um e-CNPJ autoassinado e `FakeTransport` responde no
lugar da Sefin. Use CNPJs fictícios nos testes e nos exemplos.

## Como incluir um campo da DPS

1. Procure o campo em `resources/schemas/tiposComplexos_v1.01.xsd` (tipo, se é opcional e **a posição**: a ordem dos
   elementos importa).
2. Inclua a propriedade no DTO certo em `src/Dps/` (opcional, com valor padrão, para não quebrar quem já usa).
3. Escreva o elemento em `src/Xml/DpsXml.php`, na posição da sequência do schema.
4. Teste em `tests/DpsTest.php`: o XML gerado tem que continuar passando no `SchemaValidator` e conter o campo.

## Atualizando o leiaute

1. Baixe os schemas novos da documentação técnica da NFS-e Nacional (gov.br/nfse) e troque os `.xsd` em
   `resources/schemas/`. Reaplique o ajuste do `TSSerieDPS` se o defeito do `^...$` continuar (veja o LEIA-ME).
2. Atualize `DpsXml::VERSION` e as constantes `SchemaValidator::DPS` e `::EVENT` com os nomes dos arquivos novos.
3. Rode os testes: o que mudou de obrigatório ou de formato aparece como erro de schema, com o campo.
4. Teste uma emissão em homologação com um certificado real antes de publicar a versão.

## Testando contra a Sefin (homologação)

Não há teste automático contra a Sefin (exige certificado real). Para conferir à mão:

```php
$sefin = new SefinClient(Environment::Homologacao, Certificate::fromPfx(file_get_contents('empresa.pfx'), $senha),
    log: fn ($method, $url, $status, $body) => fwrite(STDERR, "{$method} {$url} → {$status}\n{$body}\n"));
$result = $sefin->issue($dps);
var_dump($result->status, $result->message(), $result->httpStatus);
file_put_contents('enviado.xml', $result->sentXml);
```

Erros comuns da Sefin: série/número de DPS repetido (o número tem de ser sequencial e único por série e ambiente),
município do prestador não conveniado ao padrão nacional, inscrição municipal divergente do cadastro, código de tributação
não permitido para o município, certificado de outro CNPJ.

## Versões

SemVer. Mudança de leiaute que altera a forma de montar a DPS = versão menor nova; quebra de API = versão maior.
Para publicar: atualize o `CHANGELOG.md`, faça o commit e crie a tag (`git tag vX.Y.Z && git push origin vX.Y.Z`).
