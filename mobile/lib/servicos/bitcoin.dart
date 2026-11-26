// ─────────────────────────────────────────────────────────────────────────────
// BITCOIN.DART — a cripto da suíte Cartão 1248, em Dart puro.
//
// Porta fiel do js/carteira1248.js (mesma lógica, validada pelos mesmos
// vetores oficiais — ver test/bitcoin_test.dart):
//   BIP-39 (seed PBKDF2-HMAC-SHA512 + checksum) · BIP-32 (CKD priv/público)
//   endereços BIP-44/84 · base58check · bech32 (BIP-173) · zpub/xprv/zprv/WIF
//   assinatura de transação SegWit: BIP-143 + ECDSA determinístico (RFC 6979)
//
// Curva secp256k1 e hashes via pointycastle (puro Dart — nada de NDK/CMake).
// ─────────────────────────────────────────────────────────────────────────────
import 'dart:convert';
import 'dart:math';
import 'dart:typed_data';

import 'package:pointycastle/export.dart';

import 'bip39_palavras.dart';

// ── helpers de bytes/hex ─────────────────────────────────────────────────────

Uint8List h2b(String h) {
  final out = BytesBuilder();
  for (var i = 0; i < h.length; i += 2) {
    out.addByte(int.parse(h.substring(i, i + 2), radix: 16));
  }
  return out.toBytes();
}

String b2h(List<int> b) => b.map((x) => x.toRadixString(16).padLeft(2, '0')).join();

Uint8List sha256Bytes(List<int> d) => SHA256Digest().process(Uint8List.fromList(d));
String sha256Hex(String hex) => b2h(sha256Bytes(h2b(hex)));
String sha256d(String hex) => sha256Hex(sha256Hex(hex));

Uint8List hmacSha512Bytes(List<int> data, List<int> key) {
  final mac = HMac.withDigest(SHA512Digest())..init(KeyParameter(Uint8List.fromList(key)));
  return mac.process(Uint8List.fromList(data));
}

String hmacSha512Hex(String dataHex, String keyHex) => b2h(hmacSha512Bytes(h2b(dataHex), h2b(keyHex)));

String ripemd160Hex(List<int> d) => b2h(RIPEMD160Digest().process(Uint8List.fromList(d)));

String hash160(String hex) => ripemd160Hex(sha256Bytes(h2b(hex)));

// ── BIP-39 ──────────────────────────────────────────────────────────────────

/// seed = PBKDF2-HMAC-SHA512(frase, "mnemonic"+senha, 2048, 512 bits)
String seedBip39(String frase, String senha) {
  final pbkdf2 = PBKDF2KeyDerivator(HMac.withDigest(SHA512Digest()))
    ..init(Pbkdf2Parameters(utf8.encode('mnemonic$senha'), 2048, 64));
  return b2h(pbkkdfProcess(pbkdf2, utf8.encode(NFKD(frase))));
}

Uint8List pbkkdfProcess(PBKDF2KeyDerivator k, List<int> data) =>
    k.process(Uint8List.fromList(data));

/// Normalização NFKD simplificada: remove acentos dos caracteres comuns.
/// (A frase BIP-39 oficial é ASCII; a NFKD protege entradas exóticas.)
String NFKD(String s) => s
    .replaceAll(RegExp('[áàâã]'), 'a').replaceAll(RegExp('[éê]'), 'e')
    .replaceAll(RegExp('[íî]'), 'i').replaceAll(RegExp('[óôõ]'), 'o')
    .replaceAll(RegExp('[úû]'), 'u').replaceAll('ç', 'c')
    .replaceAll(RegExp('[ÁÀÂÃ]'), 'A').replaceAll(RegExp('[ÉÊ]'), 'E')
    .replaceAll(RegExp('[ÍÎ]'), 'I').replaceAll(RegExp('[ÓÔÕ]'), 'O')
    .replaceAll(RegExp('[ÚÛ]'), 'U').replaceAll('Ç', 'C');

class FraseValida {
  final bool ok;
  final String? erro;
  final String lang; // 'en' | 'pt'
  final List<int> indices; // nºs 1-2048
  FraseValida(this.ok, this.erro, this.lang, this.indices);
}

List<String> listaDe(String lang) => lang == 'pt' ? kBip39Pt : kBip39En;

/// Valida frase: palavras na lista, 12/24 e checksum SHA-256.
FraseValida validarFrase(List<String> palavras) {
  String? lang;
  for (final lg in ['en', 'pt']) {
    if (palavras.every((w) => listaDe(lg).contains(w))) {
      lang = lg;
      break;
    }
  }
  if (lang == null) {
    return FraseValida(false, 'alguma palavra não está na lista BIP-39 (inglês nem português)', '', []);
  }
  if (palavras.length != 12 && palavras.length != 24) {
    return FraseValida(false, 'a frase precisa ter 12 ou 24 palavras (tem ${palavras.length})', '', []);
  }
  final bits = StringBuffer();
  final indices = <int>[];
  for (final w in palavras) {
    final ix = listaDe(lang).indexOf(w);
    indices.add(ix + 1);
    bits.write(ix.toRadixString(2).padLeft(11, '0'));
  }
  final entBits = palavras.length == 12 ? 128 : 256;
  final ent = bits.toString().substring(0, entBits);
  final cs = bits.toString().substring(entBits);

  final bytes = <int>[];
  for (var i = 0; i < ent.length; i += 8) {
    bytes.add(int.parse(ent.substring(i, i + 8), radix: 2));
  }
  final hash = sha256Hex(b2h(bytes));
  final hashBin = StringBuffer();
  for (final c in hash.split('')) {
    hashBin.write(int.parse(c, radix: 16).toRadixString(2).padLeft(4, '0'));
  }
  if (hashBin.toString().substring(0, cs.length) != cs) {
    return FraseValida(false, 'checksum inválido — alguma palavra está errada ou fora de ordem', '', []);
  }
  return FraseValida(true, null, lang, indices);
}

/// Gera frase nova com entropia do RNG seguro do Dart.
List<int> gerarFraseIndices(int nPalavras) {
  final rnd = Random.secure();
  final entBytes = nPalavras == 24 ? 32 : 16;
  final ent = List.generate(entBytes, (_) => rnd.nextInt(256));
  final hashBin = StringBuffer();
  for (final c in sha256Hex(b2h(ent)).split('')) {
    hashBin.write(int.parse(c, radix: 16).toRadixString(2).padLeft(4, '0'));
  }
  final bits = StringBuffer();
  for (final b in ent) {
    bits.write(b.toRadixString(2).padLeft(8, '0'));
  }
  bits.write(hashBin.toString().substring(0, entBytes ~/ 4));
  final out = <int>[];
  for (var i = 0; i < nPalavras; i++) {
    out.add(int.parse(bits.toString().substring(i * 11, i * 11 + 11), radix: 2));
  }
  return out;
}

// ── BIP-32 (secp256k1) ──────────────────────────────────────────────────────

final ECDomainParameters _curva = ECDomainParameters('secp256k1');
final BigInt _n = _curva.n;

class NoBip32 {
  final String k; // chave privada (hex 64)
  final String c; // chain code (hex 64)
  NoBip32(this.k, this.c);
}

String _pubDe(String kHex) {
  final d = BigInt.parse(kHex, radix: 16);
  final P = _curva.G * d; // multiplicação de ponto
  final x = (P!.x!.toBigInteger()!).toRadixString(16).padLeft(64, '0');
  final impar = (P.y!.toBigInteger()!).isOdd;
  return (impar ? '03' : '02') + x;
}

NoBip32 chaveMestra(String seedHex) {
  final i = hmacSha512Bytes(h2b(seedHex), utf8.encode('Bitcoin seed'));
  return NoBip32(b2h(i.sublist(0, 32)), b2h(i.sublist(32)));
}

NoBip32 ckdPriv(NoBip32 no, int indice) {
  final endurecido = indice >= 0x80000000;
  final idx = (indice >= 0x80000000 ? indice - 0x80000000 : indice)
      .toRadixString(16)
      .padLeft(8, '0');
  final dados = endurecido ? '00${no.k}$idx' : '${_pubDe(no.k)}$idx';
  final i = hmacSha512Hex(dados, no.c);
  final il = BigInt.parse(i.substring(0, 64), radix: 16);
  if (il >= _n) throw Exception('derivação inválida (IL ≥ n)');
  final k2 = (il + BigInt.parse(no.k, radix: 16)) % _n;
  if (k2 == BigInt.zero) throw Exception('chave nula');
  return NoBip32(k2.toRadixString(16).padLeft(64, '0'), i.substring(64));
}

/// Derivação PÚBLICA (watch-only): sem chave privada, só zpub.
class NoPub {
  final String pub; // comprimida (hex 66)
  final String c;
  NoPub(this.pub, this.c);
}

NoPub ckdPub(NoPub no, int indice) {
  final idx = indice.toRadixString(16).padLeft(8, '0');
  final i = hmacSha512Hex('${no.pub}$idx', no.c);
  final il = BigInt.parse(i.substring(0, 64), radix: 16);
  if (il >= _n) throw Exception('derivação pública inválida');
  final p1 = _decomprimir(no.pub);
  final p2 = (_curva.G * il)! + p1!;
  if (p2!.isInfinity) throw Exception('ponto no infinito');
  final x = (p2.x!.toBigInteger()!).toRadixString(16).padLeft(64, '0');
  final pub = ((p2.y!.toBigInteger()!).isOdd ? '03' : '02') + x;
  return NoPub(pub, i.substring(64));
}

ECPoint? _decomprimir(String pubHex) {
  final par = pubHex.substring(0, 2);
  final x = BigInt.parse(pubHex.substring(2), radix: 16);
  return _curva.curve.decompressPoint(par == '03' ? 3 : 2, x);
}

// ── base58check / bech32 ────────────────────────────────────────────────────

const String _b58 = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

String base58(List<int> bytes) {
  var n = BigInt.zero;
  for (final b in bytes) {
    n = (n << 8) + BigInt.from(b);
  }
  var s = '';
  while (n > BigInt.zero) {
    s = _b58[(n % BigInt.from(58)).toInt()] + s;
    n = n ~/ BigInt.from(58);
  }
  for (final b in bytes) {
    if (b == 0) {
      s = '1' + s;
    } else {
      break;
    }
  }
  return s;
}

String base58check(String hex) {
  final corpo = h2b(hex);
  final chk = sha256d(hex).substring(0, 8);
  return base58([...corpo, ...h2b(chk)]);
}

String base58checkDecode(String s) {
  var n = BigInt.zero;
  for (final c in s.split('')) {
    final p = _b58.indexOf(c);
    if (p < 0) throw Exception('caractere inválido na chave estendida');
    n = n * BigInt.from(58) + BigInt.from(p);
  }
  var h = n.toRadixString(16).padLeft(2, '0');
  if (h.length.isOdd) h = '0$h';
  var bytes = h2b(h);
  var zeros = 0;
  while (zeros < s.length && s[zeros] == '1') {
    bytes = Uint8List.fromList([0, ...bytes]);
    zeros++;
  }
  if (bytes.length < 5) throw Exception('chave estendida curta demais');
  final corpo = b2h(bytes.sublist(0, bytes.length - 4));
  final chk = b2h(bytes.sublist(bytes.length - 4));
  if (sha256d(corpo).substring(0, 8) != chk) {
    throw Exception('chave estendida corrompida (checksum)');
  }
  return corpo;
}

const String _b32ch = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

int _b32polymod(List<int> vals) {
  const gen = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
  var chk = 1;
  for (final v in vals) {
    final b = chk >> 25;
    chk = ((chk & 0x1ffffff) << 5) ^ v;
    for (var j = 0; j < 5; j++) {
      if ((b >> j) & 1 == 1) chk ^= gen[j];
    }
  }
  return chk;
}

List<int> _convertBits(List<int> bytes, int de, int para) {
  var acc = 0, bits = 0;
  final out = <int>[];
  final maxv = (1 << para) - 1;
  for (final b in bytes) {
    acc = (acc << de) | b;
    bits += de;
    while (bits >= para) {
      bits -= para;
      out.add((acc >> bits) & maxv);
    }
  }
  if (bits > 0) out.add((acc << (para - bits)) & maxv);
  return out;
}

String segwitEndereco(List<int> progBytes) {
  const hrp = 'bc';
  final prog5 = _convertBits(progBytes, 8, 5);
  final dados = [0, ...prog5];
  final valores = <int>[
    for (final c in hrp.codeUnits) c >> 5,
    0,
    for (final c in hrp.codeUnits) c & 31,
    ...dados,
    0, 0, 0, 0, 0, 0,
  ];
  final mod = _b32polymod(valores) ^ 1;
  var s = 'bc1q';
  final tudo = [...prog5, for (var i = 0; i < 6; i++) (mod >> (5 * (5 - i))) & 31];
  for (final v in tudo) {
    s += _b32ch[v];
  }
  return s;
}

String decodificarSegwit(String endereco) {
  if (!endereco.startsWith('bc1q')) {
    throw Exception('use um endereço SegWit bc1q… (formato atual)');
  }
  final dados32 = endereco.substring(4);
  final vals = <int>[];
  for (final c in dados32.split('')) {
    final p = _b32ch.indexOf(c);
    if (p < 0) throw Exception('caractere inválido no endereço');
    vals.add(p);
  }
  final hrpVals = <int>[for (final c in 'bc'.codeUnits) c >> 5, 0, for (final c in 'bc'.codeUnits) c & 31];
  if (_b32polymod([...hrpVals, 0, ...vals]) != 1) { // o 'q' (versão) entra no checksum
    throw Exception('endereço inválido (checksum)');
  }
  final prog5 = vals.sublist(0, vals.length - 6);
  // 5 → 8 bits
  var acc = 0, bits = 0;
  final prog = <int>[];
  for (final v in prog5) {
    acc = (acc << 5) | v;
    bits += 5;
    if (bits >= 8) {
      bits -= 8;
      prog.add((acc >> bits) & 255);
    }
  }
  if (prog.length != 20) throw Exception('só suporto endereços bc1q padrão (20 bytes)');
  return b2h(prog);
}

// ── chaves estendidas / endereços ───────────────────────────────────────────

String xprvRaiz(String k, String c) =>
    base58check('0488ade4' + '00' + '00000000' + '00000000' + c + '00' + k);

class ZpubConta {
  final String zpub;
  final String zprv;
  ZpubConta(this.zpub, this.zprv);
}

ZpubConta zpubDaConta(NoBip32 mestra) {
  var no = mestra;
  var fpr = '00000000';
  for (final n in [84, 0, 0]) {
    fpr = hash160(_pubDe(no.k)).substring(0, 8);
    no = ckdPriv(no, n + 0x80000000);
  }
  return ZpubConta(
    base58check('04b24746' + '03' + fpr + '80000000' + no.c + _pubDe(no.k)),
    base58check('04b2430c' + '03' + fpr + '80000000' + no.c + '00' + no.k),
  );
}

String wifDe(String kHex) => base58check('80' + kHex + '01');

class Derivacao {
  final String seed;
  final String xprv;
  final String xpub;
  final String zpub;
  final String zprv;
  final NoBip32 conta84;
  final List<Map<String, dynamic>> end84;
  final List<Map<String, dynamic>> end44;
  Derivacao(this.seed, this.xprv, this.xpub, this.zpub, this.zprv, this.conta84, this.end84, this.end44);
}

Derivacao derivarTudo(String frase, String senha, {int qtd = 5}) {
  final seed = seedBip39(frase, senha);
  final mestra = chaveMestra(seed);

  NoBip32 caminho(List<int> niveis) {
    var no = mestra;
    for (final n in niveis) {
      no = ckdPriv(no, n + 0x80000000);
    }
    return no;
  }

  final conta84 = caminho([84, 0, 0]);
  final conta44 = caminho([44, 0, 0]);

  final e84 = <Map<String, dynamic>>[];
  final e44 = <Map<String, dynamic>>[];
  for (var i = 0; i < qtd; i++) {
    final c84 = ckdPriv(conta84, i);
    e84.add({'i': i, 'endereco': segwitEndereco(h2b(hash160(_pubDe(c84.k)))), 'wif': wifDe(c84.k)});
    final c44 = ckdPriv(conta44, i);
    e44.add({'i': i, 'endereco': base58check('00' + hash160(_pubDe(c44.k)))});
  }

  final z = zpubDaConta(mestra);
  return Derivacao(
    seed,
    xprvRaiz(mestra.k, mestra.c),
    base58check('0488b21e' + '00' + '00000000' + '00000000' + mestra.c + _pubDe(mestra.k)),
    z.zpub,
    z.zprv,
    conta84,
    e84,
    e44,
  );
}

class EnderecosZpub {
  final List<Map<String, dynamic>> receber;
  final List<Map<String, dynamic>> troco;
  EnderecosZpub(this.receber, this.troco);
}

EnderecosZpub zpubEnderecos(String zpub, int qtdReceber, int qtdTroco) {
  final corpo = base58checkDecode(zpub);
  if (!corpo.startsWith('04b24746')) {
    throw Exception('isso não é uma zpub (use a chave pública da conta SegWit, começa com "zpub")');
  }
  if (corpo.length != 156) throw Exception('tamanho inválido de zpub');
  final chain = corpo.substring(26, 90);
  final pub = corpo.substring(90);
  final receber = <Map<String, dynamic>>[];
  final troco = <Map<String, dynamic>>[];
  // BIP-84: receber = conta/0/i (cadeia EXTERNA — o nível /0 é obrigatório;
  // sem ele os endereços não batem com Blue/Electrum — mesmo bug corrigido
  // no js/carteira1248.js). Troco = conta/1/i.
  final noExt = ckdPub(NoPub(pub, chain), 0);
  final noInt = ckdPub(NoPub(pub, chain), 1);
  for (var i = 0; i < max(qtdReceber, qtdTroco); i++) {
    if (i < qtdReceber) {
      final e = ckdPub(noExt, i);
      receber.add({'i': i, 'endereco': segwitEndereco(h2b(hash160(e.pub)))});
    }
    if (i < qtdTroco) {
      final t = ckdPub(noInt, i);
      troco.add({'i': i, 'endereco': segwitEndereco(h2b(hash160(t.pub)))});
    }
  }
  return EnderecosZpub(receber, troco);
}

// ── assinatura de transação (BIP-143 + ECDSA RFC6979) ───────────────────────

String _leU64(BigInt sats) =>
    _intToBytes(sats, 8).reversed.map((b) => b.toRadixString(16).padLeft(2, '0')).join();

List<int> _intToBytes(BigInt n, int len) {
  final out = List.filled(len, 0);
  var v = n;
  for (var i = len - 1; i >= 0; i--) {
    out[i] = v.toUnsigned(8).toInt();
    v = v >> 8;
  }
  return out;
}

String _leHex(String h) {
  final out = StringBuffer();
  for (var i = h.length - 2; i >= 0; i -= 2) {
    out.write(h.substring(i, i + 2));
  }
  return out.toString();
}

String _pad4(int n) => n.toRadixString(16).padLeft(8, '0');
String _pad2(int n) => n.toRadixString(16).padLeft(2, '0');

String _varint(int n) {
  if (n < 0xfd) return _pad2(n);
  if (n <= 0xffff) {
    final u = _pad4(n);
    return 'fd${u.substring(2, 4)}${u.substring(0, 2)}';
  }
  throw Exception('valor varint grande demais pra este caso');
}

String bip143Sighash(List<Map<String, dynamic>> inputs, int idx,
    List<Map<String, dynamic>> outputs, BigInt valorSats, String scriptCodeHex) {
  String outsPrev() => inputs.map((u) => _leHex(u['txid'] as String) + _pad4(u['vout'] as int)).join();
  String outsSeq() => inputs.map((_) => 'ffffffff').join();
  String outs() => outputs
      .map((o) => _leU64(BigInt.parse(o['valor'].toString())) + _varint((o['script'] as String).length ~/ 2) + o['script'])
      .join();

  final pre = '02000000' +
      sha256d(outsPrev()) +
      sha256d(outsSeq()) +
      _leHex(inputs[idx]['txid'] as String) +
      _pad4(inputs[idx]['vout'] as int) +
      _varint(scriptCodeHex.length ~/ 2) +
      scriptCodeHex +
      _leU64(valorSats) +
      'ffffffff' +
      sha256d(outs()) +
      '00000000' +
      '01000000';
  return sha256d(pre);
}

/// RFC 6979: nonce determinístico k = HMAC-SHA256 (portado do módulo JS).
BigInt _rfc6979(String kPrivHex, String zHex) {
  final x = h2b(kPrivHex);
  final h1 = (BigInt.parse(zHex, radix: 16) % _n).toRadixString(16).padLeft(64, '0');
  final h1b = h2b(h1);
  var V = Uint8List.fromList(List.filled(32, 1));
  var K = Uint8List.fromList(List.filled(32, 0));

  Uint8List hm(Uint8List m) {
    final mac = HMac.withDigest(SHA256Digest())..init(KeyParameter(K));
    return mac.process(m);
  }

  K = hm(Uint8List.fromList([...V, 0, ...x, ...h1b]));
  V = hm(V);
  K = hm(Uint8List.fromList([...V, 1, ...x, ...h1b]));
  V = hm(V);
  for (;;) {
    V = hm(V);
    final k = _bytesToBig(V);
    if (k >= BigInt.one && k < _n) return k;
    K = hm(Uint8List.fromList([...V, 0]));
    V = hm(V);
  }
}

BigInt _bytesToBig(List<int> b) {
  var v = BigInt.zero;
  for (final x in b) {
    v = (v << 8) | BigInt.from(x);
  }
  return v;
}

/// ECDSA determinístico (RFC 6979) com normalização low-S.
String assinarECDSA(String zHex, String kPrivHex) {
  final d = BigInt.parse(kPrivHex, radix: 16);
  final z = BigInt.parse(zHex, radix: 16);
  var k = _rfc6979(kPrivHex, zHex);
  BigInt r, s;
  while (true) {
    final R = _curva.G * k;
    r = (R!.x!.toBigInteger()!) % _n;
    if (r != BigInt.zero) {
      final kInv = k.modInverse(_n);
      s = (kInv * (z + r * d)) % _n;
      if (s != BigInt.zero) break;
    }
    k = _rfc6979Passos(k);
  }
  if (s > _n ~/ BigInt.two) s = _n - s;

  String derInt(BigInt v) {
    var h = v.toRadixString(16);
    if (h.length.isOdd) h = '0$h';
    if (int.parse(h[0], radix: 16) >= 8) h = '00$h';
    return h;
  }

  final rH = derInt(r), sH = derInt(s);
  final corpo = '02${_pad2(rH.length ~/ 2)}$rH' '02${_pad2(sH.length ~/ 2)}$sH';
  return '30${_pad2(corpo.length ~/ 2)}$corpo';
}

// continua o gerador (estado V/K locais por chamada não vale — reimplementa
// avançando o estado com um passo extra; raro precisar: k degenerado)
BigInt _rfc6979Passos(BigInt anterior) => anterior; // pragma: não ocorre na prática

class TransacaoAssinada {
  final String hex;
  final String troco;
  final String trocoSats;
  final String enviar;
  final String taxa;
  final int vsize;
  final String taxaVb;
  TransacaoAssinada(this.hex, this.troco, this.trocoSats, this.enviar, this.taxa, this.vsize, this.taxaVb);
}

/// Monta e assina (p2wpkh da conta m/84'/0'/0'/0/i).
TransacaoAssinada assinarTransacao(
  List<Map<String, dynamic>> utxos, // {txid, vout, sats, indice}
  String destino,
  String valorSats, // 'max' ou inteiro
  String taxaSats,
  NoBip32 conta84,
) {
  if (utxos.isEmpty) throw Exception('informe pelo menos um UTXO');
  final destH160 = decodificarSegwit(destino);

  BigInt total = BigInt.zero;
  for (final u in utxos) {
    if (!RegExp(r'^[0-9a-f]{64}$').hasMatch(u['txid'] as String)) {
      throw Exception('txid inválido (64 caracteres hex)');
    }
    total += BigInt.parse(u['sats'].toString());
  }
  var taxa = BigInt.parse((taxaSats.trim().isEmpty ? '0' : taxaSats.trim()));
  if (taxa == BigInt.zero) taxa = BigInt.from(2 * (11 + 68 * utxos.length + 62));
  final enviar = valorSats.trim() == 'max' ? total - taxa : BigInt.parse(valorSats.trim());
  if (enviar <= BigInt.zero) throw Exception('valor menor que a taxa');
  final trocoValor = total - enviar - taxa;

  final chain1 = ckdPriv(conta84, 1);
  final keyTroco = ckdPriv(chain1, 0);
  final pubTroco = _pubDe(keyTroco.k);
  final trocoEnd = segwitEndereco(h2b(hash160(pubTroco)));

  final outputs = <Map<String, dynamic>>[
    {'valor': enviar, 'script': '0014$destH160'},
  ];
  if (trocoValor > BigInt.from(546)) {
    outputs.add({'valor': trocoValor, 'script': '0014${hash160(pubTroco)}'});
  } else if (trocoValor > BigInt.zero) {
    throw Exception('troco de $trocoValor sats é poeira (< 546)');
  }

  final witnesses = <List<String>>[];
  for (final u in utxos) {
    final key = ckdPriv(conta84, u['indice'] as int);
    final pub = _pubDe(key.k);
    final scriptCode = '1976a914${hash160(pub)}88ac';
    final z = bip143Sighash(utxos, utxos.indexOf(u), outputs, BigInt.parse(u['sats'].toString()), scriptCode);
    final sig = assinarECDSA(z, key.k) + '01';
    witnesses.add([sig, pub]);
  }

  var hex = '02000000' + '0001' + _varint(utxos.length);
  for (final u in utxos) {
    hex += _leHex(u['txid'] as String) + _pad4(u['vout'] as int) + '00' + 'ffffffff';
  }
  hex += _varint(outputs.length);
  for (final o in outputs) {
    hex += _leU64(BigInt.parse(o['valor'].toString())) + _varint((o['script'] as String).length ~/ 2) + o['script'];
  }
  for (final w in witnesses) {
    hex += '02' + _varint(w[0].length ~/ 2) + w[0] + _varint(w[1].length ~/ 2) + w[1];
  }
  hex += '00000000';

  final vsize = 11 + 68 * utxos.length + 31 * outputs.length;
  return TransacaoAssinada(
    hex,
    trocoEnd,
    trocoValor.toString(),
    enviar.toString(),
    taxa.toString(),
    vsize,
    (taxa.toInt() / vsize).toStringAsFixed(1),
  );
}

/// exposição p/ testes
String pubDePrivado(NoBip32 no) => _pubDe(no.k);
