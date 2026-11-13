// ─────────────────────────────────────────────────────────────────────────────
// CARTEIRA 1248 — derivação de chaves/endereços no navegador (página /1248).
//
// Pipeline BIP-39 → BIP-32 → BIP-44/84, tudo local:
//   frase (+ passphrase) → seed 512 bits (PBKDF2-HMAC-SHA512, 2048 iterações)
//   seed → chave mestra (HMAC-SHA512 com "Bitcoin seed")
//   m/84'/0'/0'/0/i → endereços SegWit nativo (bc1…) · m/44'/0'/0'/0/i → legado (1…)
//
// Hashes via crypto-js (o layout do site já carrega); curva secp256k1 em
// BigInt puro (coordenadas jacobianas); base58check e bech32 (BIP-173)
// implementados aqui. O mesmo arquivo roda no Node para os testes
// (module.exports no fim).
// ─────────────────────────────────────────────────────────────────────────────
(function (raiz) {
  'use strict';

  // ── helpers hex/bytes ──────────────────────────────────────────────────────
  function hexToBytes(h) {
    var out = [];
    for (var i = 0; i < h.length; i += 2) out.push(parseInt(h.substr(i, 2), 16));
    return out;
  }
  function bytesToHex(b) {
    var s = '';
    for (var i = 0; i < b.length; i++) s += (b[i] + 0x100).toString(16).slice(1);
    return s;
  }
  function wa(hex) { return CryptoJS.enc.Hex.parse(hex); }
  function sha256(hex) { return CryptoJS.SHA256(wa(hex)).toString(); }
  function hmacSha512(dadosHex, chaveHex) {
    return CryptoJS.HmacSHA512(wa(dadosHex), wa(chaveHex)).toString();
  }
  function hash160(hex) {
    return CryptoJS.RIPEMD160(CryptoJS.SHA256(wa(hex))).toString();
  }
  function seedBip39(frase, passphrase) {
    return CryptoJS.PBKDF2(frase, 'mnemonic' + passphrase, {
      keySize: 512 / 32, iterations: 2048, hasher: CryptoJS.algo.SHA512
    }).toString();
  }

  // ── secp256k1 (jacobiana, BigInt) ─────────────────────────────────────────
  var P  = 0xFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEFFFFFC2Fn;
  var N  = 0xFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141n;
  var GX = 0x79BE667EF9DCBBAC55A06295CE870B07029BFCDB2DCE28D959F2815B16F81798n;
  var GY = 0x483ADA7726A3C4655DA4FBFC0E1108A8FD17B448A68554199C47D08FFB10D4B8n;

  function modP(a) { a %= P; return a >= 0n ? a : a + P; }
  function invP(a) {
    var b = modP(a), r = 1n, e = P - 2n;
    while (e > 0n) { if (e & 1n) r = r * b % P; b = b * b % P; e >>= 1n; }
    return r;
  }
  function ptDup(p1) {
    if (p1[1] === 0n) return [0n, 0n, 0n];
    var Y2 = p1[1] * p1[1] % P;
    var S = 4n * p1[0] * Y2 % P;
    var M = 3n * p1[0] % P * p1[0] % P;
    var X3 = modP(M * M % P - 2n * S);
    var Y3 = modP(M * modP(S - X3) - 8n * Y2 * Y2 % P);
    var Z3 = 2n * p1[1] * p1[2] % P;
    return [X3, Y3, Z3];
  }
  function ptAdd(p, q) {
    if (p[2] === 0n) return q;
    if (q[2] === 0n) return p;
    var Z1Z1 = p[2] * p[2] % P, Z2Z2 = q[2] * q[2] % P;
    var U1 = p[0] * Z2Z2 % P, U2 = q[0] * Z1Z1 % P;
    var S1 = p[1] * q[2] % P * Z2Z2 % P, S2 = q[1] * p[2] % P * Z1Z1 % P;
    if (U1 === U2) return (S1 === S2) ? ptDup(p) : [0n, 0n, 0n];
    var H = modP(U2 - U1);
    var HH = H * H % P, I = 4n * HH % P, J = H * I % P;
    var r = 2n * modP(S2 - S1) % P;
    var V = U1 * I % P;
    var X3 = modP(r * r % P - J - 2n * V);
    var Y3 = modP(r * modP(V - X3) - 2n * S1 * J % P);
    var Z3 = modP(modP(modP(p[2] + q[2]) * modP(p[2] + q[2]) - Z1Z1 - Z2Z2) * H);
    return [X3, Y3, Z3];
  }
  function ptMul(k, p1) {
    var kk = k % N; if (kk < 0n) kk += N;
    var r = [0n, 0n, 0n], a = p1;
    while (kk > 0n) { if (kk & 1n) r = ptAdd(r, a); a = ptDup(a); kk >>= 1n; }
    return r;
  }
  var _pubCache = {};
  function pubDe(kHex) {
    if (_pubCache[kHex]) return _pubCache[kHex];
    var K = ptMul(BigInt('0x' + kHex), [GX, GY, 1n]);
    var zi = invP(K[2]), zi2 = zi * zi % P, zi3 = zi2 * zi % P;
    var x = K[0] * zi2 % P, y = K[1] * zi3 % P;
    var pub = (y % 2n === 0n ? '02' : '03') + x.toString(16).padStart(64, '0');
    _pubCache[kHex] = pub;
    return pub;
  }

  // ── BIP-32 ────────────────────────────────────────────────────────────────
  function chaveMestra(seedHex) {
    var I = hmacSha512(seedHex, '426974636f696e2073656564'); // "Bitcoin seed"
    return { k: I.slice(0, 64), c: I.slice(64) };
  }
  function ckdPriv(kHex, cHex, indice) {
    var endurecido = indice >= 0x80000000;
    var idx = (endurecido ? indice - 0x80000000 : indice).toString(16).padStart(8, '0');
    var dados = endurecido ? '00' + kHex + idx : pubDe(kHex) + idx;
    var I = hmacSha512(dados, cHex);
    var IL = I.slice(0, 64), IR = I.slice(64);
    var ilBig = BigInt('0x' + IL);
    if (ilBig >= N) throw new Error('derivação inválida (IL ≥ n) — caso raríssimo, gere outra frase');
    var k = (ilBig + BigInt('0x' + kHex)) % N;
    if (k === 0n) throw new Error('chave nula — caso raríssimo, gere outra frase');
    return { k: k.toString(16).padStart(64, '0'), c: IR };
  }

  // ── base58check / bech32 ──────────────────────────────────────────────────
  var B58 = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
  function base58(bytes) {
    var n = 0n;
    for (var i = 0; i < bytes.length; i++) n = (n << 8n) + BigInt(bytes[i]);
    var s = '';
    while (n > 0n) { s = B58[Number(n % 58n)] + s; n /= 58n; }
    for (var j = 0; j < bytes.length && bytes[j] === 0; j++) s = '1' + s;
    return s;
  }
  function base58checkDecode(str) { // → hex (sem verificar versão), lança se checksum bater não
    var n = 0n;
    for (var i = 0; i < str.length; i++) {
      var p = B58.indexOf(str[i]);
      if (p < 0) throw new Error('caractere inválido na chave estendida');
      n = n * 58n + BigInt(p);
    }
    var h = n.toString(16).padStart(2, '0');
    if (h.length % 2) h = '0' + h;
    var bytes = hexToBytes(h);
    // zeros à esquerda codificados como '1'
    var zeros = 0;
    while (zeros < str.length && str[zeros] === '1') { bytes.unshift(0); zeros++; }
    if (bytes.length < 5) throw new Error('chave estendida curta demais');
    var corpo = bytes.slice(0, bytes.length - 4), chk = bytesToHex(bytes.slice(bytes.length - 4));
    if (sha256(sha256(bytesToHex(corpo))).slice(0, 8) !== chk) {
      throw new Error('chave estendida corrompida (checksum)');
    }
    return bytesToHex(corpo);
  }
  function base58check(hex) {
    return base58(hexToBytes(hex).concat(hexToBytes(sha256(sha256(hex)).slice(0, 8))));
  }
  function xprvRaiz(kHex, cHex) {
    // versão xprv | depth 0 | fingerprint 00000000 | child 00000000 | chain | 00+chave
    return base58check('0488ade4' + '00' + '00000000' + '00000000' + cHex + '00' + kHex);
  }

  var B32CH = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
  function b32polymod(vals) {
    var GEN = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
    var chk = 1;
    for (var i = 0; i < vals.length; i++) {
      var b = chk >> 25;
      chk = ((chk & 0x1ffffff) << 5) ^ vals[i];
      for (var j = 0; j < 5; j++) if ((b >> j) & 1) chk ^= GEN[j];
    }
    return chk;
  }
  function convertBits(bytes, de, para) {
    var acc = 0, bits = 0, ret = [], maxv = (1 << para) - 1;
    for (var i = 0; i < bytes.length; i++) {
      acc = (acc << de) | bytes[i]; bits += de;
      while (bits >= para) { bits -= para; ret.push((acc >> bits) & maxv); }
    }
    if (bits) ret.push((acc << (para - bits)) & maxv);
    return ret;
  }
  function segwitEndereco(progBytes) { // witness v0 → bc1q…
    var hrp = 'bc';
    var prog5 = convertBits(progBytes, 8, 5);
    var dados = [0].concat(prog5); // versão + programa — é isto que entra no checksum
    var valores = [], i;
    for (i = 0; i < hrp.length; i++) valores.push(hrp.charCodeAt(i) >> 5);
    valores.push(0);
    for (i = 0; i < hrp.length; i++) valores.push(hrp.charCodeAt(i) & 31);
    valores = valores.concat(dados, [0, 0, 0, 0, 0, 0]);
    var mod = b32polymod(valores) ^ 1;
    var s = 'bc1q'; // hrp + separador + versão witness 0 (= "q")
    var tudo = prog5.slice();
    for (i = 0; i < 6; i++) tudo.push((mod >> (5 * (5 - i))) & 31);
    tudo.forEach(function (v) { s += B32CH[v]; });
    return s;
  }

  // ── pipeline completo ─────────────────────────────────────────────────────
  function zpubDaConta(mestra) {
    // m/84'/0'/0' com serialização fiel: depth 3, fingerprint do pai e índice endurecido
    var no = mestra, fpr = '00000000';
    [84, 0, 0].forEach(function (n) {
      fpr = hash160(pubDe(no.k)).slice(0, 8);
      no = ckdPriv(no.k, no.c, n + 0x80000000);
    });
    return {
      pub: base58check('04b24746' + '03' + fpr + '80000000' + no.c + pubDe(no.k)),
      prvp: base58check('04b2430c' + '03' + fpr + '80000000' + no.c + '00' + no.k)
    };
  }

  function derivarTudo(frase, passphrase, qtd) {
    qtd = qtd || 5;
    var seed = seedBip39(frase, passphrase || '');
    var mestra = chaveMestra(seed);

    function caminho(niveis) {
      var no = mestra;
      for (var i = 0; i < niveis.length; i++) no = ckdPriv(no.k, no.c, niveis[i] + 0x80000000);
      return no;
    }
    var conta84 = caminho([84, 0, 0]);
    var conta44 = caminho([44, 0, 0]);

    var end84 = [], end44 = [];
    for (var i = 0; i < qtd; i++) {
      var c84 = ckdPriv(conta84.k, conta84.c, i);
      var h84 = hash160(pubDe(c84.k));
      end84.push({ i: i, endereco: segwitEndereco(hexToBytes(h84)), wif: wifDe(c84.k) });

      var c44 = ckdPriv(conta44.k, conta44.c, i);
      var h44 = hash160(pubDe(c44.k));
      end44.push({ i: i, endereco: base58check('00' + h44) });
    }
    var z = zpubDaConta(mestra);
    return {
      seed: seed,
      xprv: xprvRaiz(mestra.k, mestra.c),
      xpub: base58check('0488b21e' + '00' + '00000000' + '00000000' + mestra.c + pubDe(mestra.k)),
      zpub: z.pub,
      zprv: z.prvp,
      conta84: conta84,
      end84: end84,
      end44: end44
    };
  }
  function wifDe(kHex) { // WIF comprimido (mainnet)
    return base58check('80' + kHex + '01');
  }

  // ── derivação PÚBLICA (watch-only, zpub) ─────────────────────────────────
  function ckdPub(pubHex, cHex, indice) {
    var idx = indice.toString(16).padStart(8, '0');
    var I = hmacSha512(pubHex + idx, cHex);
    var IL = I.slice(0, 64), IR = I.slice(64);
    var ilBig = BigInt('0x' + IL);
    if (ilBig >= N) throw new Error('derivação pública inválida (raro) — tente o próximo índice');
    var Ki = ptAdd(pubPonto(pubHex), ptMul(ilBig, [GX, GY, 1n]));
    if (Ki[2] === 0n) throw new Error('ponto no infinito (raro)');
    var zi = invP(Ki[2]), zi2 = zi * zi % P, zi3 = zi2 * zi % P;
    var x = Ki[0] * zi2 % P, y = Ki[1] * zi3 % P;
    var pub = (y % 2n === 0n ? '02' : '03') + x.toString(16).padStart(64, '0');
    return { pub: pub, c: IR };
  }
  function pubPonto(pubHex) {
    return descompactarPub(pubHex).slice(0, 2).concat([1n]); // [x, y, z=1]
  }
  // zpub (conta BIP-84) → endereços das cadeias 0 (receber) e 1 (troco)
  function zpubEnderecos(zpub, qtdReceber, qtdTroco) {
    var corpo = base58checkDecode(zpub);
    if (corpo.substr(0, 8) !== '04b24746') throw new Error('isso não é uma zpub (use a chave pública da conta SegWit, começa com "zpub")');
    if (corpo.length !== 78 * 2) throw new Error('tamanho inválido de zpub');
    var chain = corpo.substr(26, 64);   // versão(4) depth(1) fpr(4) child(4) → chaincode(32)
    var pub = corpo.substr(90, 66);     // chave pública comprimida (33)
    var receber = [], troco = [];
    var noInt = ckdPub(pub, chain, 1);  // cadeia interna (troco) = conta/1 — um nível só
    for (var i = 0; i < Math.max(qtdReceber, qtdTroco); i++) {
      if (i < qtdReceber) {
        var e = ckdPub(pub, chain, i);  // cada índice deriva DIRETO da conta
        receber.push({ i: i, endereco: segwitEndereco(hexToBytes(hash160(e.pub))) });
      }
      if (i < qtdTroco) {
        var t = ckdPub(noInt.pub, noInt.c, i);
        troco.push({ i: i, endereco: segwitEndereco(hexToBytes(hash160(t.pub))) });
      }
    }
    return { receber: receber, troco: troco };
  }
  function pad2(n) { return (n + 0x100).toString(16).slice(1); }
  function pad4(n) { return (n + 0x10000).toString(16).slice(1); }
  function leU64(sats) { // inteiro decimal → 8 bytes little-endian
    var h = BigInt(sats).toString(16).padStart(16, '0'), out = '';
    for (var i = h.length - 2; i >= 0; i -= 2) out += h.substr(i, 2);
    return out;
  }
  function leHex(h) { // inverte os bytes (txid etc.)
    var out = '';
    for (var i = h.length - 2; i >= 0; i -= 2) out += h.substr(i, 2);
    return out;
  }
  function varint(n) {
    if (n < 0xfd) return pad2(n);
    if (n <= 0xffff) { var u = pad4(n); return 'fd' + u.substr(2, 2) + u.substr(0, 2); }
    throw new Error('valor varint grande demais pra este caso');
  }
  function sha256d(hex) { return sha256(sha256(hex)); }
  function invMod(a, m) {
    var lm = 1n, hm = 0n, low = ((a % m) + m) % m, high = m;
    while (low > 1n) {
      var r = high / low;
      var nm = hm - lm * r, nw = high - low * r;
      hm = lm; lm = nm; high = low; low = nw;
    }
    return ((lm % m) + m) % m;
  }

  // nonce determinístico RFC6979 (HMAC-SHA256)
  function rfc6979(privHex, z32Hex) {
    var x = CryptoJS.enc.Hex.parse(privHex);
    var h1 = CryptoJS.enc.Hex.parse((BigInt('0x' + z32Hex) % N).toString(16).padStart(64, '0'));
    var V = CryptoJS.enc.Hex.parse(''.concat(Array(33).join('01')));
    var K = CryptoJS.enc.Hex.parse(''.concat(Array(33).join('00')));
    function hm(v, data) { return CryptoJS.HmacSHA256(CryptoJS.lib.WordArray.create(v.concat(data)), K); }
    // WordArray → byte array
    function w2b(wa2) {
      var words = wa2.words, out = [];
      for (var i = 0; i < words.length; i++) {
        out.push((words[i] >>> 24) & 255, (words[i] >>> 16) & 255, (words[i] >>> 8) & 255, words[i] & 255);
      }
      return out;
    }
    var vb = w2b(V);
    K = CryptoJS.HmacSHA256(CryptoJS.lib.WordArray.create(vb.concat([0].concat(w2b(x), w2b(h1)))), K);
    V = CryptoJS.HmacSHA256(V, K);
    vb = w2b(V);
    K = CryptoJS.HmacSHA256(CryptoJS.lib.WordArray.create(vb.concat([1].concat(w2b(x), w2b(h1)))), K);
    V = CryptoJS.HmacSHA256(V, K);
    for (;;) {
      V = CryptoJS.HmacSHA256(V, K);
      var k = BigInt('0x' + V.toString());
      if (k >= 1n && k < N) return k;
      vb = w2b(V);
      K = CryptoJS.HmacSHA256(CryptoJS.lib.WordArray.create(vb.concat([0])), K);
      V = CryptoJS.HmacSHA256(V, K);
    }
  }

  function derInt(nBig) {
    var h = nBig.toString(16);
    if (h.length % 2) h = '0' + h;
    if (parseInt(h[0], 16) >= 8) h = '00' + h; // byte de sinal
    return h;
  }
  function assinarECDSA(zHex, kPrivHex) {
    var d = BigInt('0x' + kPrivHex);
    var z = BigInt('0x' + zHex);
    var k = rfc6979(kPrivHex, zHex);
    var K = ptMul(k, [GX, GY, 1n]);
    var zi = invP(K[2]), zi2 = zi * zi % P;
    var x = K[0] * zi2 % P; // coordenada x afim
    var r = x % N;
    if (r === 0n) throw new Error('nonce degenerado, tente de novo');
    var s = invMod(k, N) * (z + r * d) % N;
    if (s === 0n) throw new Error('assinatura degenerada, tente de novo');
    if (s > N / 2n) s = N - s; // low-S (BIP-62)
    var rH = derInt(r), sH = derInt(s);
    var body = '02' + pad2(rH.length / 2) + rH + '02' + pad2(sH.length / 2) + sH;
    return '30' + pad2(body.length / 2) + body;
  }
  function verificarECDSA(zHex, sigDerHex, pubCompactHex) { // autochecagem
    function parse() {
      var i = 4; // pula "30 <len>" — os 2 primeiros bytes do DER
      if (sigDerHex.substr(i, 2) !== '02') throw new Error('DER');
      i += 2;
      var lr = parseInt(sigDerHex.substr(i, 2), 16); i += 2;
      var r = BigInt('0x' + sigDerHex.substr(i, lr * 2)); i += lr * 2;
      if (sigDerHex.substr(i, 2) !== '02') throw new Error('DER');
      i += 2;
      var ls = parseInt(sigDerHex.substr(i, 2), 16); i += 2;
      var s = BigInt('0x' + sigDerHex.substr(i, ls * 2));
      return [r, s];
    }
    var rs = parse(), r = rs[0], s = rs[1];
    var w = invMod(s, N);
    var u1 = BigInt('0x' + zHex) * w % N, u2 = r * w % N;
    var pub = descompactarPub(pubCompactHex); // [x,y]
    var R = ptAdd(ptMul(u1, [GX, GY, 1n]), ptMul(u2, [pub[0], pub[1], 1n]));
    if (R[2] === 0n) return false;
    var zi = invP(R[2]), zi2 = zi * zi % P, xr = R[0] * zi2 % P;
    return xr % N === r;
  }
  function descompactarPub(compactHex) {
    var par = compactHex.substr(0, 2);
    var x = BigInt('0x' + compactHex.substr(2));
    // y² = x³ + 7
    var y2 = (x * x % P * x + 7n) % P;
    var y = powModP(y2, (P + 1n) / 4n);
    if (y * y % P !== y2) throw new Error('ponto inválido');
    var impar = par === '03';
    if ((y % 2n === 1n) !== impar) y = P - y;
    return [x, y];
  }
  function powModP(b, e) {
    var r = 1n, bb = ((b % P) + P) % P;
    while (e > 0n) { if (e & 1n) r = r * bb % P; bb = bb * bb % P; e >>= 1n; }
    return r;
  }

  // BIP-143 sighash (SegWit v0, p2wpkh, SIGHASH_ALL)
  function bip143Sighash(inputs, idx, outputs, valorSats, scriptCodeHex, pubKeyHex) {
    function outsPrev() {
      var h = '';
      inputs.forEach(function (u) { h += leHex(u.txid) + pad4(u.vout); });
      return h;
    }
    function outsSeq() {
      var h = '';
      inputs.forEach(function () { h += 'ffffffff'; });
      return h;
    }
    function outs() {
      var h = '';
      outputs.forEach(function (o) { h += leU64(o.valor) + varint(o.script.length / 2) + o.script; });
      return h;
    }
    var pre = '02000000'                                    // versão
      + sha256d(outsPrev()) + sha256d(outsSeq())
      + leHex(inputs[idx].txid) + pad4(inputs[idx].vout)
      + varint(scriptCodeHex.length / 2) + scriptCodeHex
      + leU64(valorSats) + 'ffffffff'
      + sha256d(outs())
      + '00000000'                                          // locktime
      + '01000000';                                         // SIGHASH_ALL
    return sha256d(pre);
  }

  // decodifica endereço bc1q… → hash160 (20 bytes) pra montar o script de saída
  function decodificarSegwit(endereco) {
    var HRP = 'bc';
    if (endereco.slice(0, 4) !== 'bc1q') throw new Error('use um endereço SegWit bc1q… (formato atual)');
    var dados32 = endereco.slice(4);
    var vals = [];
    for (var i = 0; i < dados32.length; i++) {
      var p = B32CH.indexOf(dados32[i]);
      if (p < 0) throw new Error('caractere inválido no endereço');
      vals.push(p);
    }
    var hrpVals = [], j;
    for (j = 0; j < HRP.length; j++) hrpVals.push(HRP.charCodeAt(j) >> 5);
    hrpVals.push(0);
    for (j = 0; j < HRP.length; j++) hrpVals.push(HRP.charCodeAt(j) & 31);
    var tudo = hrpVals.concat([0], vals); // o "q" (versão witness 0) entra no checksum
    var mod = b32polymod(tudo);
    if (mod !== 1) throw new Error('endereço inválido (checksum)');
    var prog5 = vals.slice(0, vals.length - 6);
    // 5→8 bits
    var acc = 0, bits = 0, prog = [];
    for (var k2 = 0; k2 < prog5.length; k2++) {
      acc = (acc << 5) | prog5[k2]; bits += 5;
      if (bits >= 8) { bits -= 8; prog.push((acc >> bits) & 255); }
    }
    if (bits >= 5 || ((acc << (8 - bits)) & 255)) throw new Error('padding inválido');
    if (prog.length !== 20) throw new Error('só suporto endereços bc1q padrão (20 bytes)');
    return bytesToHex(prog);
  }

  // ── montar + assinar a transação inteira ──────────────────────────────────
  // utxos: [{txid, vout, sats, indice}] — indice = nº do endereço na carteira
  // (m/84'/0'/0'/0/indice). valorSats 'max' envia tudo menos a taxa.
  function assinarTransacao(utxos, destino, valorSats, taxaSats, conta84) {
    if (!utxos.length) throw new Error('informe pelo menos um UTXO');
    var destH160 = decodificarSegwit(destino);

    var total = 0n;
    utxos.forEach(function (u) {
      if (!/^[0-9a-f]{64}$/.test(u.txid)) throw new Error('txid inválido (64 caracteres hex)');
      total += BigInt(u.sats);
    });
    taxaSats = BigInt(String(taxaSats).trim() === '' ? '0' : taxaSats);
    if (taxaSats === 0n) taxaSats = BigInt(2 * (11 + 68 * utxos.length + 62)); // ~2 sat/vB se o pacote não trouxer
    var enviar = (valorSats === 'max') ? (total - taxaSats) : BigInt(valorSats);
    if (enviar <= 0n) throw new Error('valor menor que a taxa');

    var trocoValor = total - enviar - taxaSats;

    // troco: cadeia INTERNA do BIP-84 → m/84'/0'/0'/1/0 (não endurecido)
    var chain1 = ckdPriv(conta84.k, conta84.c, 1);
    var keyTroco = ckdPriv(chain1.k, chain1.c, 0);
    var pubTroco = pubDe(keyTroco.k);
    var trocoEndereco = segwitEndereco(hexToBytes(hash160(pubTroco)));

    // saídas
    var outputs = [{ valor: enviar, script: '0014' + destH160 }];
    if (trocoValor > 546n) outputs.push({ valor: trocoValor, script: '0014' + hash160(pubTroco) });
    else if (trocoValor > 0n) throw new Error('troco de ' + trocoValor + ' sats é poeira (< 546) — aumente o valor enviado');

    // assina cada entrada (p2wpkh da nossa carteira)
    var witnesses = [];
    utxos.forEach(function (u, i) {
      var key = ckdPriv(conta84.k, conta84.c, u.indice);
      var pub = pubDe(key.k);
      var scriptCode = '1976a914' + hash160(pub) + '88ac';
      var z = bip143Sighash(utxos, i, outputs, u.sats, scriptCode, pub);
      var sigDer = assinarECDSA(z, key.k) + '01'; // + SIGHASH_ALL
      witnesses.push({ sig: sigDer, pub: pub });
    });

    // serializa a transação (SegWit)
    var hex = '02000000' + '0001' + varint(utxos.length);
    utxos.forEach(function (u) {
      hex += leHex(u.txid) + pad4(u.vout) + '00' + 'ffffffff';
    });
    hex += varint(outputs.length);
    outputs.forEach(function (o) {
      hex += leU64(o.valor.toString()) + varint(o.script.length / 2) + o.script;
    });
    witnesses.forEach(function (w) {
      hex += '02' + varint(w.sig.length / 2) + w.sig + varint(w.pub.length / 2) + w.pub;
    });
    hex += '00000000';

    var vsize = 11 + 68 * utxos.length + 31 * outputs.length;
    return {
      hex: hex,
      troco: trocoEndereco,
      trocoSats: trocoValor > 0n ? trocoValor.toString() : '0',
      enviar: enviar.toString(),
      taxa: taxaSats.toString(),
      vsize: vsize,
      taxaVb: taxaSats > 0n ? (Number(taxaSats) / vsize).toFixed(1) : '0'
    };
  }

  function validarFrase(palavras, listas) {
    // listas: { en: [...], pt: [...] }
    var linguaOk = null;
    ['en', 'pt'].forEach(function (lg) {
      if (linguaOk) return;
      if (palavras.every(function (w) { return listas[lg].indexOf(w) >= 0; })) linguaOk = lg;
    });
    if (!linguaOk) return { ok: false, erro: 'alguma palavra não está na lista BIP-39 (inglês nem português)' };
    if (palavras.length !== 12 && palavras.length !== 24) {
      return { ok: false, erro: 'a frase precisa ter 12 ou 24 palavras (tem ' + palavras.length + ')' };
    }
    var bits = '';
    var indices = palavras.map(function (w) { return listas[linguaOk].indexOf(w); });
    indices.forEach(function (ix) { bits += ix.toString(2).padStart(11, '0'); });
    var entBits = palavras.length === 12 ? 128 : 256;
    var ent = bits.slice(0, entBits);
    var cs = bits.slice(entBits);
    // entropia → bytes → sha256
    var bytes = [];
    for (var i = 0; i < ent.length; i += 8) bytes.push(parseInt(ent.substr(i, 8), 2));
    var hash = sha256(bytesToHex(bytes));
    var hashBin = '';
    for (var j = 0; j < hash.length; j++) hashBin += parseInt(hash[j], 16).toString(2).padStart(4, '0');
    if (hashBin.slice(0, cs.length) !== cs) {
      return { ok: false, erro: 'checksum inválido — alguma palavra está errada ou fora de ordem' };
    }
    return { ok: true, lang: linguaOk, indices: indices.map(function (ix) { return ix + 1; }) };
  }

  // ── export (testes no Node) ───────────────────────────────────────────────
  var api = {
    sha256: sha256, hmacSha512: hmacSha512, hash160: hash160, seedBip39: seedBip39,
    pubDe: pubDe, ckdPriv: ckdPriv, chaveMestra: chaveMestra,
    base58check: base58check, xprvRaiz: xprvRaiz, segwitEndereco: segwitEndereco,
    derivarTudo: derivarTudo, validarFrase: validarFrase,
    assinarTransacao: assinarTransacao, verificarECDSA: verificarECDSA,
    assinarECDSA: assinarECDSA, decodificarSegwit: decodificarSegwit, bip143Sighash: bip143Sighash,
    base58checkDecode: base58checkDecode, ckdPub: ckdPub, zpubEnderecos: zpubEnderecos,
    xprvSerial: function (kHex, cHex, versao) { return base58check((versao || '0488ade4') + '00' + '00000000' + '00000000' + cHex + '00' + kHex); },
    zpubSerial: function (pubHex, cHex) { return base58check('04b24746' + '03' + '00000000' + '00000000' + cHex + pubHex); }
  };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  raiz.C1248Carteira = api;

  // ── integração com a página ───────────────────────────────────────────────
  if (typeof document === 'undefined' || !document.getElementById('cartoesFrase')) return;

  var CASAS = [[1, 2], [1, 4], [2, 8], [1, 4], [2, 8], [1, 4], [2, 8]];
  var COLINI = [0, 1, 3, 5], VALORES = [[1, 2], [1, 4, 2, 8], [1, 4, 2, 8], [1, 4, 2, 8]];

  function furosDe(n) {
    var s = String(n).padStart(4, '0'), out = [];
    for (var g = 0; g < 4; g++) {
      var t = +s[g];
      VALORES[g].forEach(function (v, i) {
        if (t & v) out.push({ casa: COLINI[g] + Math.floor(i / 2), pos: i % 2 });
      });
    }
    return out;
  }

  function cartaoHTML(pos, palavra, n) {
    var furos = {};
    furosDe(n).forEach(function (fu) { furos[fu.casa * 2 + fu.pos] = 1; });
    var tam = palavra.length <= 4 ? 15 : palavra.length <= 6 ? 12 : palavra.length <= 8 ? 10 : 8;
    var h = '<div class="c1248x"><div class="num"><div class="pos">' + pos + 'º</div>'
      + '<div class="pal" style="font-size:' + tam + 'px">' + palavra + '</div>'
      + '<div class="nidx">nº ' + n + '</div></div><div class="casas">';
    CASAS.forEach(function (par, i) {
      var gcls = i === 1 ? ' g1' : i === 3 ? ' g2' : i === 5 ? ' g3' : '';
      h += '<div class="casa' + gcls + '">'
        + '<span class="dgt' + (furos[i * 2] ? ' furado' : '') + '">' + par[0] + '</span>'
        + '<span class="dgt' + (furos[i * 2 + 1] ? ' furado' : '') + '">' + par[1] + '</span></div>';
    });
    return h + '</div></div>';
  }

  function renderCartoes(fraseArr) {
    var box = document.getElementById('cartoesFrase');
    if (!fraseArr || !fraseArr.length) {
      box.innerHTML = '';
      return;
    }
    var h = '';
    fraseArr.forEach(function (p) { h += cartaoHTML(p.pos, p.palavra, p.n); });
    box.innerHTML = h;
    // frase mudou → zpub/endereços também (derivação automática, sem botão)
    agendarDerivacao();
    atualizarCardTransacao(); // offline: abre o card de criar/assinar transação
    var bz = document.getElementById('blocoZpub');
    if (bz) bz.style.display = '';
  }
  renderCartoes(null);
  // hook: o gerador da página chama isto a cada mudança de frase
  window.__c1248Mudou = renderCartoes;

  // assinatura de transação SÓ no modo offline (arquivo baixado): no site
  // fica apenas o aviso apontando pro download. Detecta file:// ou a flag
  // C1248_OFFLINE injetada no build da versão offline.
  var modoOffline = window.C1248_OFFLINE === true || location.protocol === 'file:';

  // Offline: Importar (e Criar/assinar transação) sobem pro TOPO, acima do Gerar
  if (modoOffline) {
    var cardGerar = document.getElementById('cardGerar');
    var cardImp = document.getElementById('cardImportar');
    var cardTx = document.getElementById('cardTransacao');
    if (cardGerar && cardImp && cardTx && cardGerar.parentNode) {
      cardGerar.parentNode.insertBefore(cardImp, cardGerar);
      cardGerar.parentNode.insertBefore(cardTx, cardGerar);
    }
  }

  // Card "Criar e assinar transação": só no offline, e só com carteira ativa
  function atualizarCardTransacao() {
    var card = document.getElementById('cardTransacao');
    if (!card) return;
    var ativa = window.C1248Ger && window.C1248Ger.frase() && window.C1248Ger.frase().length;
    card.style.display = (modoOffline && ativa) ? '' : 'none';
  }

  // Importar (Card 1): frase OU números das posições do Cartão 1248
  document.getElementById('btnUsarColada').addEventListener('click', function () {
    var erro = document.getElementById('colarErro');
    erro.style.color = 'var(--red)';
    erro.textContent = '';
    var bruto = document.getElementById('colarFrase').value.trim().toLowerCase().replace(/[\s,;]+/g, ' ');
    if (!bruto) { erro.textContent = 'cole a frase ou os números.'; return; }
    var tokens = bruto.split(' ').filter(Boolean);

    try {
      var arr, lingua;
      var soNumeros = tokens.every(function (t) { return /^\d{1,4}$/.test(t); });

      if (soNumeros) {
        if (tokens.length !== 12 && tokens.length !== 24) throw 'precisam ser 12 ou 24 números (veio ' + tokens.length + ')';
        var sel = document.querySelector('.ger-lang.selected');
        lingua = sel ? sel.dataset.lang : 'en';
        var lista = BIP39[lingua];
        arr = tokens.map(function (n, i) {
          var ix = +n;
          if (ix < 1 || ix > 2048) throw 'número fora da faixa (1-2048): ' + n;
          return { pos: i + 1, n: ix, palavra: lista[ix - 1] };
        });
        var vN = validarFrase(arr.map(function (p) { return p.palavra; }), BIP39);
        if (!vN.ok) throw 'checksum inválido — confira os números (e o idioma: lista ' + (lingua === 'pt' ? 'portuguesa' : 'inglesa') + ' selecionada)';
      } else {
        var v = validarFrase(tokens, BIP39);
        if (!v.ok) throw v.erro;
        lingua = v.lang;
        arr = tokens.map(function (w, i) { return { pos: i + 1, n: v.indices[i], palavra: w }; });
      }

      window.C1248Ger.definir(arr, lingua);
      erro.style.color = 'var(--green)';
      erro.textContent = '✓ carteira importada (' + tokens.length + ' ' + (soNumeros ? 'números' : 'palavras') + ', lista ' + (lingua === 'pt' ? 'portuguesa' : 'inglesa') + ')';
      setTimeout(function () { erro.textContent = ''; erro.style.color = 'var(--red)'; }, 5000);
      document.getElementById('colarFrase').value = '';

      // offline: opção de criar transação após importar com sucesso
      if (modoOffline && !document.getElementById('btnIrTransacao')) {
        var ir = document.createElement('button');
        ir.id = 'btnIrTransacao';
        ir.className = 'btn btn-gold btn-sm fw-bold mt-2';
        ir.innerHTML = '<i class="fa-solid fa-signature me-1"></i>Criar transação';
        ir.addEventListener('click', function () {
          var c = document.getElementById('cardTransacao');
          if (c) c.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
        erro.parentNode.appendChild(ir);
      }
    } catch (e) {
      erro.textContent = '✗ ' + (e.message || e);
    }
  });

  // Preencher campos com pacote do site (upload)
  var pacArquivo = document.getElementById('pacArquivo');
  if (pacArquivo) pacArquivo.addEventListener('change', function () {
    var arq = this.files && this.files[0];
    if (!arq) return;
    var leitor = new FileReader();
    leitor.onload = function () {
      try {
        var pacote = parsePacote(String(leitor.result || ''));
        document.getElementById('assUtxos').value = pacote.utxos.map(function (u) {
          return u.txid + ',' + u.vout + ',' + u.sats + ',' + u.indice;
        }).join('\n');
        document.getElementById('assDestino').value = pacote.destino;
        document.getElementById('assValor').value = pacote.valor;
        document.getElementById('assTaxa').value = pacote.taxa;
      } catch (e) {
        document.getElementById('assErro').textContent = e.message || e;
      }
    };
    leitor.readAsText(arq);
  });

  // chaves e endereços — derivação AUTOMÁTICA ao gerar/colar frase ou mudar a senha
  var tDerivar = null;
  function agendarDerivacao() {
    clearTimeout(tDerivar);
    tDerivar = setTimeout(derivarAgora, 400);
  }
  function derivarAgora() {
    var fraseArr = window.C1248Ger ? window.C1248Ger.frase() : null;
    var box = document.getElementById('derivResultado');
    if (!fraseArr || !fraseArr.length) {
      box.classList.add('d-none');
      box.style.display = 'none';
      return;
    }
    box.classList.remove('d-none');
    box.style.display = 'flex';
    box.innerHTML = '<span class="text-muted" style="font-size:.85rem;"><i class="fa-solid fa-circle-notch fa-spin me-2"></i>Derivando zpub e endereços…</span>';
    setTimeout(function () {
      try {
        var texto = fraseArr.map(function (p) { return p.palavra; }).join(' ');
        var r = derivarTudo(texto, document.getElementById('passFrase').value);
        box.classList.remove('d-none');
        box.style.display = 'flex';
        box.innerHTML =
            '<div><div class="form-label mb-1 d-flex align-items-center gap-2">zpub (conta m/84\'/0\'/0\')'
          + '<button class="btn btn-outline-muted btn-sm py-0" id="btnCopiarZpub"><i class="fa-regular fa-copy me-1"></i>copiar</button></div>'
          + '<div id="dvZpub" class="zpub-destaque"></div>'
          + '<div class="text-muted mt-1" style="font-size:.76rem;">pode ser usado na aba <a href="/carteiras" class="text-gold">Carteiras</a> pra acompanhar a cold wallet em tempo real.</div></div>'
          + '<div><button class="btn btn-outline-muted btn-sm" id="btnAvancado"><i class="fa-solid fa-gears me-1"></i>Avançado — todas as chaves da carteira</button>'
          + '<div id="dvAvancado" class="d-none flex-column gap-2 mt-2">'
          +   '<div><div class="form-label mb-1">Seed (512 bits) <span class="text-muted" style="font-weight:400; font-size:.76rem;">— o número mestre que a carteira usa internamente</span></div>'
          +   '<div id="dvSeed" class="dado-tecnico"></div></div>'
          +   '<div><div class="form-label mb-1">Chave privada estendida raiz (xprv, BIP-32) <span class="text-muted" style="font-weight:400; font-size:.76rem;">— com ela se reconstrói toda a carteira; quem tem isso TEM as moedas</span></div>'
          +   '<div id="dvXprv" class="dado-tecnico"></div></div>'
          +   '<div><div class="form-label mb-1">zprv da conta <span class="text-muted" style="font-weight:400; font-size:.76rem;">— a chave privada desta conta SegWit (equivalente privado da zpub)</span></div>'
          +   '<div id="dvZprv" class="dado-tecnico"></div></div>'
          +   '<div><div class="form-label mb-1">xpub raiz <span class="text-muted" style="font-weight:400; font-size:.76rem;">— a pública da raiz; deriva tudo sem mover moedas</span></div>'
          +   '<div id="dvXpub" class="dado-tecnico"></div></div>'
          +   '<div><div class="form-label mb-1">Endereços e chaves privadas (WIF) <span class="text-muted" style="font-weight:400; font-size:.76rem;">— os primeiros da carteira</span></div>'
          +   '<div id="dvEnderecos" class="d-flex flex-column gap-1"></div></div>'
          +   '<div id="blocoBip44"><div class="form-label mb-1">Endereços legado (BIP-44, 1…) <span class="text-muted" style="font-weight:400; font-size:.76rem;">— formato antigo; caminho m/44\'/0\'/0\'/0/i</span></div>'
          +   '<div id="dvBip44" class="d-flex flex-column gap-1"></div></div>'
          + '</div></div>';
        document.getElementById('dvZpub').textContent = r.zpub;
        document.getElementById('btnCopiarZpub').addEventListener('click', function () {
          navigator.clipboard.writeText(r.zpub);
          this.innerHTML = '<i class="fa-solid fa-check me-1"></i>copiada!';
        });
        document.getElementById('btnAvancado').addEventListener('click', function () {
          var av = document.getElementById('dvAvancado');
          av.classList.toggle('d-none');
          av.style.display = av.classList.contains('d-none') ? 'none' : 'flex';
        });
        document.getElementById('dvSeed').textContent = r.seed;
        document.getElementById('dvXprv').textContent = r.xprv;
        document.getElementById('dvZprv').textContent = r.zprv;
        document.getElementById('dvXpub').textContent = r.xpub;
        document.getElementById('dvEnderecos').innerHTML = r.end84.map(function (e) {
          return '<div class="endereco-item"><span class="caminho">m/84\'/0\'/0\'/0/' + e.i + '</span><code>' + e.endereco + '</code>'
            + '<span class="text-muted" style="font-size:.66rem; word-break:break-all;">WIF ' + e.wif + '</span></div>';
        }).join('');
      } catch (e2) {
        box.classList.remove('d-none');
        box.style.display = 'flex';
        box.innerHTML = '<span style="color:var(--red); font-size:.85rem;">' + e2.message + '</span>';
      }
    }, 30);
  }
  // senha mudou → deriva de novo
  var inpPass = document.getElementById('passFrase');
  if (inpPass) inpPass.addEventListener('input', function () {
    if (window.C1248Ger && window.C1248Ger.frase() && window.C1248Ger.frase().length) agendarDerivacao();
  });

  // assinar transação — cole/upload do PACOTE (dados extraídos dele)
  function parsePacote(txt) {
    txt = String(txt || '').trim();
    if (!txt) throw 'cole o pacote de assinatura gerado na aba Carteiras';
    // JSON (formato atual da aba Carteiras)
    try {
      var j = JSON.parse(txt);
      if (j && j.destino && j.utxos && j.utxos.length) {
        return {
          destino: String(j.destino).trim(),
          valor: String(j.valor || 'max'),
          taxa: String(j.taxa || ''),
          utxos: j.utxos.map(function (u) {
            return { txid: String(u.txid).toLowerCase(), vout: +u.vout, sats: String(u.sats), indice: +u.indice };
          })
        };
      }
    } catch (e) {}
    // formato texto (legado): linhas "txid,vout,sats,indice" + Destino/Valor/Taxa
    var utxos = [], destino = '', valor = '', taxa = '';
    txt.split(/\n/).forEach(function (linha) {
      linha = linha.trim();
      if (!linha) return;
      var m = linha.match(/^(destino|valor|taxa)\s*:\s*(.+)$/i);
      if (m) {
        var k = m[1].toLowerCase(), v = m[2].replace(/,.*$/, '').replace(/\s*sats.*$/i, '').trim();
        if (k === 'destino') destino = v;
        if (k === 'valor') valor = v;
        if (k === 'taxa') taxa = v;
        return;
      }
      var p = linha.split(',');
      if (p.length === 4 && /^[0-9a-f]{64}$/i.test(p[0].trim())) {
        utxos.push({ txid: p[0].trim().toLowerCase(), vout: +p[1], sats: p[2].trim(), indice: +p[3] });
      }
    });
    if (utxos.length && destino) return { destino: destino, valor: valor || 'max', taxa: taxa, utxos: utxos };
    throw 'pacote não reconhecido — gere-o na aba Carteiras e cole inteiro';
  }

  // assinar a transação dos CAMPOS (preenchidos à mão ou pelo pacote do site)
  document.getElementById('btnAssinar').addEventListener('click', function () {
    var erro = document.getElementById('assErro');
    var res = document.getElementById('assResultado');
    erro.textContent = '';
    res.classList.add('d-none');
    res.style.display = 'none';

    var fraseArr = window.C1248Ger ? window.C1248Ger.frase() : null;
    if (!fraseArr || !fraseArr.length) {
      erro.textContent = 'Gere ou importe a carteira primeiro.';
      return;
    }

    var pacote = { destino: '', valor: '', taxa: '', utxos: [] };
    try {
      document.getElementById('assUtxos').value.split('\n').forEach(function (linha) {
        linha = linha.trim();
        if (!linha) return;
        var p = linha.split(',');
        if (p.length !== 4) throw 'linha de UTXO precisa de 4 campos: txid,vout,sats,índice';
        pacote.utxos.push({ txid: p[0].trim().toLowerCase(), vout: +p[1], sats: p[2].trim(), indice: +p[3] });
      });
      if (!pacote.utxos.length) throw 'informe ao menos um UTXO (ou preencha com o pacote do site).';
      pacote.destino = document.getElementById('assDestino').value.trim();
      if (!pacote.destino) throw 'informe o endereço de destino.';
      pacote.valor = document.getElementById('assValor').value.trim() || 'max';
      pacote.taxa = document.getElementById('assTaxa').value.trim();
    } catch (msg) {
      erro.textContent = msg.message || msg;
      return;
    }

    var btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-hourglass-half me-1"></i>Assinando…';
    setTimeout(function () {
      try {
        var texto = fraseArr.map(function (p) { return p.palavra; }).join(' ');
        var seed = seedBip39(texto, document.getElementById('passFrase').value);
        var mestra = chaveMestra(seed);
        var conta = mestra;
        [84, 0, 0].forEach(function (n) { conta = ckdPriv(conta.k, conta.c, n + 0x80000000); });

        var r = assinarTransacao(pacote.utxos, pacote.destino, pacote.valor, pacote.taxa, conta);

        document.getElementById('assHex').textContent = r.hex;
        document.getElementById('assDetalhes').innerHTML =
            'Envia <strong class="text-light">' + r.enviar + '</strong> sats · taxa <strong class="text-light">' + r.taxa
          + '</strong> sats (~' + r.taxaVb + ' sat/vB em ' + r.vsize + ' vB) · troco de <strong class="text-light">' + r.trocoSats
          + '</strong> sats volta pra <code style="color:var(--gold)">' + r.troco + '</code>'
          + ' · <button class="btn btn-outline-muted btn-sm py-0" id="btnBaixarTx"><i class="fa-solid fa-download me-1"></i>Baixar arquivo .tx</button>';
        document.getElementById('btnBaixarTx').addEventListener('click', function () {
          var blob = new Blob([r.hex], { type: 'text/plain' });
          var a = document.createElement('a');
          a.href = URL.createObjectURL(blob);
          a.download = 'assinada-' + Date.now() + '.tx';
          a.click();
          URL.revokeObjectURL(a.href);
        });
        res.classList.remove('d-none');
        res.style.display = 'flex';
      } catch (e2) {
        erro.textContent = e2.message;
      } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-signature me-1"></i>Assinar transação';
      }
    }, 30);
  });
})(typeof window !== 'undefined' ? window : globalThis);
