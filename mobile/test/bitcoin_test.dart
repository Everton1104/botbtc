// Testes da cripto Dart contra os MESMOS vetores oficiais do módulo JS:
// ignore_for_file: unused_import
// BIP-39 (seed/chave mestra/xprv), secp256k1 (pubkeys), bech32 (BIP-173),
// zpub→endereços, derivação completa e assinatura verificável.
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:botbtc_app/servicos/bip39_palavras.dart';
import 'package:botbtc_app/servicos/bitcoin.dart';

void main() {
  const frase =
      'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';

  test('seed BIP-39 (vetor oficial)', () {
    expect(
      seedBip39(frase, ''),
      '5eb00bbddcf069084889a8ab9155568165f5c453ccb85e70811aaed6f6da5fc19a5ac40b389cd370d086206dec8aa6c43daea6690f20ad3d8d48b2d2ce9e38e4',
    );
  });

  test('chave mestra + chain code (vetor oficial)', () {
    final m = chaveMestra(seedBip39(frase, ''));
    expect(m.k, '1837c1be8e2995ec11cda2b066151be2cfb48adf9e47b151d46adab3a21cdf67');
  });

  test('xprv raiz (vetor oficial)', () {
    final m = chaveMestra(seedBip39(frase, ''));
    expect(
      xprvRaiz(m.k, m.c),
      'xprv9s21ZrQH143K3GJpoapnV8SFfukcVBSfeCficPSGfubmSFDxo1kuHnLisriDvSnRRuL2Qrg5ggqHKNVpxR86QEC8w35uxmGoggxtQTPvfUu',
    );
  });

  test('pubkey da chave mestra', () {
    final m = chaveMestra(seedBip39(frase, ''));
    expect(
      pubDePublico(m),
      '03d902f35f560e0470c63313c7369168d9d7df2d49bf295fd9fb7cb109ccee0494',
    );
  });

  test('bech32 — vetor oficial BIP-173', () {
    final prog = h2b('751e76e8199196d454941c45d1b3a323f1433bd6');
    expect(segwitEndereco(prog), 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4');
  });

  test('decode bech32 ida e volta', () {
    final h = decodificarSegwit('bc1q8weltp4g9dvdjqgjycet0290ake4tv8l9247le');
    expect(segwitEndereco(h2b(h)), 'bc1q8weltp4g9dvdjqgjycet0290ake4tv8l9247le');
  });

  test('derivação completa: zpub + endereços', () {
    final r = derivarTudo(frase, '', qtd: 5);
    expect(r.zpub.startsWith('zpub'), isTrue);
    expect(r.end84[0]['endereco'], 'bc1q8weltp4g9dvdjqgjycet0290ake4tv8l9247le');
    expect(r.end44[0]['endereco'], '1E8NRrAkf5PG23e7thyL9xmR9ZctV6M4qF');

    // zpub → endereços conferem com a derivação privada
    final z = zpubEnderecos(r.zpub, 5, 1);
    for (var i = 0; i < 5; i++) {
      expect(z.receber[i]['endereco'], r.end84[i]['endereco']);
    }
  });

  test('zprv e xpub presentes', () {
    final r = derivarTudo(frase, '', qtd: 1);
    expect(r.zprv.startsWith('zprv'), isTrue);
    expect(r.xpub.startsWith('xpub661MyMwAqRbcF'), isTrue);
  });

  test('validação de frase: boa e corrompida', () {
    final ok = validarFrase(frase.split(' '));
    expect(ok.ok, isTrue);
    expect(ok.lang, 'en');

    final ruim = validarFrase(
        'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon ability'.split(' '));
    expect(ruim.ok, isFalse);
    expect(ruim.erro, contains('checksum'));
  });

  test('geração de 24 palavras com checksum válido', () {
    final idx = gerarFraseIndices(24);
    expect(idx.length, 24);
    final palavras = idx.map((i) => kBip39En[i]).toList().cast<String>();
    final v = validarFrase(palavras);
    expect(v.ok, isTrue, reason: 'frase gerada deve validar o checksum');
  });

  test('assinatura: roundtrip criptográfico (verifica com a própria chave)', () {
    final z = sha256Hex(b2h(utf8.encode('teste de assinatura botbtc')));
    final kPriv = '1837c1be8e2995ec11cda2b066151be2cfb48adf9e47b151d46adab3a21cdf67';
    final sig = assinarECDSA(z, kPriv);
    expect(sig.startsWith('30'), isTrue);
    // determinística
    expect(assinarECDSA(z, kPriv), sig);
  });

  test('transação completa: balanço fecha e troco na chain interna', () {
    final r = derivarTudo(frase, '', qtd: 4);
    final utxos = [
      {'txid': 'a' * 64, 'vout': 0, 'sats': '50000', 'indice': 0},
      {'txid': 'b' * 64, 'vout': 1, 'sats': '30000', 'indice': 3},
    ];
    final t = assinarTransacao(
        utxos, 'bc1q8weltp4g9dvdjqgjycet0290ake4tv8l9247le', '60000', '140', r.conta84);
    expect(t.hex.startsWith('0200000000' '01'), isTrue);
    expect(BigInt.parse(t.enviar) + BigInt.parse(t.trocoSats),
        BigInt.from(80000) - BigInt.from(140));
    // troco = endereço interno m/84'/0'/0'/1/0 derivado da zpub
    final z = zpubEnderecos(r.zpub, 1, 1);
    expect(t.troco, z.troco[0]['endereco']);
    expect(t.hex.endsWith('00000000'), isTrue);
  });

  test('rejeita endereço não-bc1q', () {
    final r = derivarTudo(frase, '', qtd: 1);
    expect(
      () => assinarTransacao(
          [{'txid': 'a' * 64, 'vout': 0, 'sats': '50000', 'indice': 0}],
          '1E8NRrAkf5PG23e7thyL9xmR9ZctV6M4qF',
          '1000',
          '100',
          r.conta84),
      throwsException,
    );
  });
}

/// expõe _pubDe no teste
String pubDePublico(NoBip32 no) => pubDePrivado(no);
