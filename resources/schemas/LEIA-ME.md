# Schemas oficiais da NFS-e Nacional (leiaute 1.01)

Usados pelo `SchemaValidator` para conferir a DPS e o pedido de cancelamento **antes** de enviar à Sefin.

Origem: documentação técnica da NFS-e Nacional (gov.br/nfse), na cópia distribuída pelo
[nfse-nacional/nfse-php](https://github.com/nfse-nacional/nfse-php).

## Ajuste local
Em `tiposSimples_v1.01.xsd`, o padrão da série da DPS (`TSSerieDPS`) vem como `^0{0,4}\d{1,5}$`. Em XML Schema `^` e `$`
são caracteres literais (não âncoras), então nenhum valor real passaria. Ficou `0{0,4}\d{1,5}`.

## Leiaute novo
Quando a Receita publicar outro leiaute: troque os `.xsd` aqui, ajuste `src/Xml/DpsXml.php` (`VERSION` e os campos) e rode os
testes, que validam o XML gerado contra estes schemas.
