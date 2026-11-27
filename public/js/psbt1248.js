// ─────────────────────────────────────────────────────────────────────────────
// psbt1248.js — monta um PSBT (BIP-174) de carteira watch-only (zpub, P2WPKH).
//
// Quem gera é o NAVEGADOR (a zpub é pública — mesma arquitetura do resto do
// site: o servidor nunca faz criptografia). O PSBT sai daqui COMPLETO pra
// assinar no Sparrow Wallet do Ambiente Offline (ISO WinPE): File ▸ Import
// PSBT, assinar, Export Transaction, e o .txn volta pro site transmitir.
//
// Escopo deliberadamente estreito (é o que o site vigia):
//   • SegWit v0 nativo P2WPKH (bc1q…, BIP-84)
//   • SIGHASH_ALL, sem RBF (sequence 0xffffffff) — igual ao fluxo do .pac
//   • witness_utxo por entrada (não precisamos da tx anterior inteira)
//
// Depende de C1248Carteira (carteira1248.js): ckdPub, hash160,
// decodificarSegwit, base58checkDecode, segwitEndereco.
// ─────────────────────────────────────────────────────────────────────────────
(function (raiz) {
    'use strict';
    var C = raiz.C1248Carteira;

    // ── utilidades binárias ────────────────────────────────────────────────
    function hexToBytes(h) {
        var out = [];
        for (var i = 0; i < h.length; i += 2) out.push(parseInt(h.substr(i, 2), 16));
        return out;
    }
    function bytesToB64(bytes) {
        var s = '';
        for (var i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
        return btoa(s);
    }
    // inteiro → n bytes little-endian (hex)
    function le(n, bytes) {
        var h = BigInt(n).toString(16).padStart(bytes * 2, '0'), out = '';
        for (var i = h.length - 2; i >= 0; i -= 2) out += h.substr(i, 2);
        return out;
    }
    // varint (CompactSize)
    function varint(n) {
        if (n < 0xfd) return n.toString(16).padStart(2, '0');
        if (n <= 0xffff) return 'fd' + le(n, 2);
        return 'fe' + le(n, 4);
    }
    // txid (hex big-endian como o mempool devolve) → bytes na ordem da tx
    function leHex(txid) {
        var out = '';
        for (var i = txid.length - 2; i >= 0; i -= 2) out += txid.substr(i, 2);
        return out;
    }
    // <len><dados> de uma chave/valor do PSBT
    function tlv(tipo, keyHex, valHex) {
        return varint(1 + keyHex.length / 2) + tipo + keyHex + varint(valHex.length / 2) + valHex;
    }
    var SEP = '00'; // separador de mapas do BIP-174

    // fingerprint da CONTA (primeiros 4 bytes do hash160 da chave da conta) —
    // usado nos campos BIP-32 junto com caminho RELATIVO à conta ([cadeia, i]),
    // que é o par consistente quando só temos a zpub (nível conta).
    function fingerprintDe(pubHex) { return C.hash160(pubHex).substr(0, 8); }

    // ── derivações públicas a partir da zpub ───────────────────────────────
    // devolve { nos: {0: nó cadeia receber, 1: nó cadeia troco},
    //            pub: função(cadeia, i) → pubHex, fingerprint }
    function arvoreDaZpub(zpub) {
        var corpo = C.base58checkDecode(zpub);
        if (corpo.substr(0, 8) !== '04b24746') throw new Error('isso não é uma zpub BIP-84 (conta SegWit nativa)');
        if (corpo.length !== 156) throw new Error('tamanho inválido de zpub');
        var chain = corpo.substr(26, 64), pub = corpo.substr(90, 66);
        var nos = { 0: C.ckdPub(pub, chain, 0), 1: C.ckdPub(pub, chain, 1) };
        return {
            nos: nos,
            pub: function (cadeia, i) { return C.ckdPub(nos[cadeia].pub, nos[cadeia].c, i).pub; },
            fingerprint: fingerprintDe(pub),
            corpo: corpo // 78 bytes serializados (pra global_xpub)
        };
    }

    // scriptPubKey P2WPKH: OP_0 <push20> hash160(pub)
    function scriptP2wpkh(pubHex) { return '0014' + C.hash160(pubHex); }

    // caminho de derivação da conta como uint32 LE endurecidos: 84'/0'/0'
    function pathConta() {
        function endurecido(n) { return le(n + 0x80000000, 4); }
        return endurecido(84) + endurecido(0) + endurecido(0);
    }

    /**
     * Constrói o PSBT.
     *
     * opts = {
     *   zpub:    'zpub…'                       — conta BIP-84 da carteira
     *   utxos:   [{txid, vout, sats, i, cadeia}] — 'receber'|'troco' (i = índice do endereço)
     *   destino: 'bc1q…'
     *   valor:   sats (número) ou 'max'        — 'max' envia tudo menos a taxa
     *   taxa:    sats (número)                 — vazio/0 usa a estimada (~2 sat/vB)
     * }
     */
    function construir(opts) {
        if (!C) throw new Error('carteira1248.js não carregado');
        if (!opts.utxos || !opts.utxos.length) throw new Error('sem UTXOs pra gastar');
        var arv = arvoreDaZpub(opts.zpub);

        // ── confere os UTXOs e deriva as chaves públicas de cada entrada ──
        var entradas = opts.utxos.map(function (u) {
            if (!/^[0-9a-f]{64}$/.test(u.txid || '')) throw new Error('txid inválido no UTXO');
            var cadeia = (u.cadeia === 'troco') ? 1 : 0;
            var pub = arv.pub(cadeia, u.i);
            return { txid: u.txid, vout: u.vout | 0, sats: u.sats, cadeia: cadeia, i: u.i, pub: pub, script: scriptP2wpkh(pub) };
        });

        var total = entradas.reduce(function (s, e) { return s + BigInt(e.sats); }, 0n);

        // ── taxa / valor / troco (mesma política do fluxo do .pac) ──
        var vsize = 11 + 68 * entradas.length + 31 * 2; // com troco; sem troco ajusta abaixo
        var taxa = BigInt(String(opts.taxa).trim() === '' || String(opts.taxa) === '0' ? 0 : opts.taxa);
        if (taxa === 0n) taxa = BigInt(2 * vsize); // ~2 sat/vB
        if (taxa < 141n) taxa = BigInt(2 * vsize);
        var enviar = (opts.valor === 'max') ? (total - taxa) : BigInt(String(opts.valor).trim() === '' ? 0 : opts.valor);
        if (enviar <= 0n) throw new Error('valor menor que a taxa');
        var trocoValor = total - enviar - taxa;
        if (trocoValor < 0n) throw new Error('valor + taxa (' + (enviar + taxa) + ' sats) excede o total dos UTXOs (' + total + ' sats) — use valor "max"');

        var destH160;
        try { destH160 = C.decodificarSegwit(opts.destino); }
        catch (e) { throw new Error('destino inválido: ' + e.message); }

        // troco: PRÓXIMO índice livre da cadeia interna — maior índice de troco
        // ainda com UTXO + 1 (padrão seria reusar sempre o 0; o próximo evita
        // reutilizar endereço). Limitado aos 20 vigiados (0..19).
        var trocoIdx = 0;
        opts.utxos.forEach(function (u) {
            if (u.cadeia === 'troco' && (u.i + 1) > trocoIdx) trocoIdx = u.i + 1;
        });
        if (trocoIdx > 19) throw new Error('todos os 20 endereços de troco vigiados já foram usados — derive mais endereços antes');

        var outputs = [{ valor: enviar, script: '0014' + destH160, troco: false }];
        var troco = null;
        if (trocoValor > 546n) {
            var pubTroco = arv.pub(1, trocoIdx);
            troco = { i: trocoIdx, endereco: C.segwitEndereco(hexToBytes(C.hash160(pubTroco))), pub: pubTroco, sats: trocoValor };
            outputs.push({ valor: trocoValor, script: scriptP2wpkh(pubTroco), troco: true });
        } else if (trocoValor > 0n) {
            throw new Error('troco de ' + trocoValor + ' sats é poeira (< 546) — aumente o valor enviado');
        }
        vsize = 11 + 68 * entradas.length + 31 * outputs.length;

        // ── transação NÃO assinada (serialização tradicional, sem marker/flag
        //    — é o que o BIP-174 exige no campo global) ──
        var tx = '02000000' + varint(entradas.length);
        entradas.forEach(function (e) {
            tx += leHex(e.txid) + le(e.vout, 4) + '00' + 'ffffffff';
        });
        tx += varint(outputs.length);
        outputs.forEach(function (o) {
            tx += le(o.valor, 8) + varint(o.script.length / 2) + o.script;
        });
        tx += '00000000'; // locktime

        // ── monta o PSBT (maps BIP-174) ──
        var psbt = '70736274ff'; // magic "psbt" + 0xff (obrigatório)
        // global: 0x00 unsigned_tx + 0x04 global_xpub (a própria zpub, com o
        // caminho da conta — o Sparrow usa pra exibir a derivação bonita)
        psbt += tlv('00', '', tx);
        psbt += tlv('04', arv.corpo, arv.fingerprint + pathConta());
        psbt += SEP;
        // inputs: witness_utxo (valor + scriptPubKey) + bip32_derivation
        // (fingerprint da conta + caminho relativo [cadeia, índice])
        entradas.forEach(function (e) {
            psbt += tlv('01', '', le(e.sats, 8) + varint(e.script.length / 2) + e.script);
            psbt += tlv('06', e.pub, arv.fingerprint + le(e.cadeia, 4) + le(e.i, 4));
            psbt += SEP;
        });
        // outputs: bip32_derivation só no troco (o destino não é nosso).
        // ATENÇÃO: a numeração de types é POR MAP — nos outputs, derivação
        // BIP-32 é 0x02 (0x06 seria TAP_TREE); nos inputs é 0x06 mesmo.
        outputs.forEach(function (o) {
            if (o.troco) psbt += tlv('02', troco.pub, arv.fingerprint + le(1, 4) + le(troco.i, 4));
            psbt += SEP;
        });

        return {
            base64: bytesToB64(hexToBytes(psbt)),
            semAssinar: tx,
            enviar: enviar,
            taxa: taxa,
            vsize: vsize,
            troco: troco // null quando 'max'
        };
    }

    raiz.C1248Psbt = { construir: construir, arvoreDaZpub: arvoreDaZpub, scriptP2wpkh: scriptP2wpkh };
    if (typeof module !== 'undefined' && module.exports) module.exports = raiz.C1248Psbt;
})(typeof window !== 'undefined' ? window : globalThis);
