// ─────────────────────────────────────────────────────────────────────────────
// SUÍTE BITCOIN — Cartão 1248 · Gerar Carteira · Carteiras, em 3 abas.
//
// A mesma suíte do site, nativa:
//   • Cartão 1248: o que é, simulador de furos e PDF pra imprimir
//   • Gerar Carteira: frase nova/importada (palavras ou NÚMEROS das
//     posições), cartões por palavra, zpub e Avançado com todas as chaves
//   • Carteiras: watch-only ao vivo (saldo pendente, confirmações, UTXOs),
//     taxas sugeridas, pacote de assinatura e broadcast
//
// A cripto é a de servicos/bitcoin.dart — testada contra os vetores oficiais.
// A ASSINATURA de transações fica só no arquivo offline (Tails), por design.
// ─────────────────────────────────────────────────────────────────────────────
import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:http/http.dart' as http2;
import 'package:url_launcher/url_launcher.dart';

import '../servicos/api.dart';
import '../servicos/bitcoin.dart';
import '../servicos/bip39_palavras.dart';
import '../tema.dart';
import 'tela_cartao1248.dart';

class TelaSuite extends StatelessWidget {
  const TelaSuite({super.key});

  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      length: 3,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Suíte Bitcoin'),
          bottom: const TabBar(
            labelColor: Cores.dourado,
            unselectedLabelColor: Cores.textoSuave,
            indicatorColor: Cores.dourado,
            tabs: [
              Tab(icon: Icon(Icons.confirmation_number), text: 'Cartão 1248'),
              Tab(icon: Icon(Icons.key), text: 'Gerar'),
              Tab(icon: Icon(Icons.wallet), text: 'Carteiras'),
            ],
          ),
        ),
        body: const TabBarView(children: [
          TelaCartao1248(),
          AbaGerarCarteira(),
          AbaCarteiras(),
        ]),
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════════════════
// ABA GERAR CARTEIRA
// ═══════════════════════════════════════════════════════════════════════════

class AbaGerarCarteira extends StatefulWidget {
  const AbaGerarCarteira({super.key});

  @override
  State<AbaGerarCarteira> createState() => _AbaGerarCarteiraState();
}

class _AbaGerarCarteiraState extends State<AbaGerarCarteira> {
  int _nPalavras = 24;
  String _lang = 'en';
  final _senhaCtrl = TextEditingController();
  final _importarCtrl = TextEditingController();

  /// frase ativa: [{pos, n, palavra}]
  List<Map<String, dynamic>>? _frase;
  String _origem = '';
  Derivacao? _deriv;
  String? _derivandoErro;
  bool _carregando = false;
  bool _avisoConversao = false;

  @override
  void dispose() {
    _senhaCtrl.dispose();
    _importarCtrl.dispose();
    super.dispose();
  }

  Future<void> _gerar() async {
    final indices = gerarFraseIndices(_nPalavras);
    final palavras = indices.map((i) => listaDe(_lang)[i]).toList();
    _definir(palavras.map((w) => {'palavra': w, 'n': listaDe(_lang).indexOf(w) + 1}).toList(), 'gerada');
  }

  Future<void> _importar() async {
    final bruto = _importarCtrl.text.trim().toLowerCase().replaceAll(RegExp(r'[\s,;]+'), ' ');
    if (bruto.isEmpty) return;
    final tokens = bruto.split(' ').where((t) => t.isNotEmpty).toList();
    try {
      final soNumeros = tokens.every((t) => RegExp(r'^\d{1,4}$').hasMatch(t));
      if (soNumeros) {
        if (tokens.length != 12 && tokens.length != 24) throw 'precisam ser 12 ou 24 números';
        final lista = listaDe(_lang);
        final arr = <Map<String, dynamic>>[];
        for (var i = 0; i < tokens.length; i++) {
          final ix = int.parse(tokens[i]);
          if (ix < 1 || ix > 2048) throw 'número fora da faixa (1-2048): ${tokens[i]}';
          arr.add({'palavra': lista[ix - 1], 'n': ix});
        }
        final v = validarFrase(arr.map((e) => e['palavra'] as String).toList());
        if (!v.ok) throw 'checksum inválido — confira os números (e o idioma $_lang)';
        _definir(arr, 'importada (números)');
      } else {
        final v = validarFrase(tokens);
        if (!v.ok) throw v.erro!;
        _definir(
          tokens.asMap().entries.map((e) => {'palavra': e.value, 'n': v.indices[e.key]}).toList(),
          'importada',
        );
      }
      _importarCtrl.clear();
    } catch (e) {
      _msg(e.toString().replaceFirst('Exception: ', ''));
    }
  }

  void _definir(List<Map<String, dynamic>> frase, String origem) {
    setState(() {
      for (var i = 0; i < frase.length; i++) {
        frase[i]['pos'] = i + 1;
      }
      _frase = frase;
      _origem = origem;
      _deriv = null;
      _carregando = true;
    });
    _derivar();
  }

  Future<void> _derivar() async {
    if (_frase == null) return;
    try {
      final texto = (_frase!).map((p) => p['palavra']).join(' ');
      final d = await Future.sync(() => derivarTudo(texto, _senhaCtrl.text, qtd: 5));
      setState(() {
        _deriv = d;
        _derivandoErro = null;
        _carregando = false;
      });
    } catch (e) {
      setState(() {
        _derivandoErro = e.toString().replaceFirst('Exception: ', '');
        _carregando = false;
      });
    }
  }

  void _msg(String t) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(t), backgroundColor: Cores.vermelho));
  }

  /// Converte as palavras da frase pro OUTRO idioma, preservando o nº de
  /// cada posição. Aviso BIP-39: a seed vem das PALAVRAS — a carteira muda.
  void _converterIdioma() {
    if (_frase == null) return;
    final alvo = _lang == 'en' ? 'pt' : 'en';
    final lista = listaDe(alvo);
    final convertida = _frase!
        .map((p) => {'pos': p['pos'], 'n': p['n'], 'palavra': lista[(p['n'] as int) - 1]})
        .toList();
    setState(() {
      _lang = alvo;
      _avisoConversao = true;
    });
    _definir(convertida, 'convertida');
  }

  void _copiar(String t, String rotulo) {
    Clipboard.setData(ClipboardData(text: t));
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$rotulo copiad$rotulo!'), backgroundColor: Cores.verde));
  }

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(14),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        _avisoTeste(),
        const SizedBox(height: 12),

        // ── CARTÃO 1248 (cartões por palavra) ──
        if (_frase != null) ...[
          _titulo('CARTÃO 1248', Icons.confirmation_number),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: _frase!.map(_cartaozinho).toList(),
          ),
          const SizedBox(height: 16),
        ],

        // ── controles ──
        _card(child: Column(children: [
          Wrap(spacing: 10, runSpacing: 8, children: [
            _seletor(['12', '24'], _nPalavras == 12 ? '12' : '24', (v) => setState(() => _nPalavras = int.parse(v))),
            _seletor(['Inglês', 'Português'], _lang == 'en' ? 'Inglês' : 'Português', (v) => setState(() => _lang = v == 'Inglês' ? 'en' : 'pt')),
          ]),
          const SizedBox(height: 10),
          Row(children: [
            Expanded(child: TextField(
              controller: _senhaCtrl,
              decoration: const InputDecoration(labelText: 'Passphrase', isDense: true, hintText: 'opcional'),
              onChanged: (_) { if (_frase != null) _derivar(); },
            )),
            const SizedBox(width: 10),
            FilledButton(
              style: FilledButton.styleFrom(backgroundColor: Cores.dourado, foregroundColor: Colors.black),
              onPressed: _gerar,
              child: const Text('Gerar frase', style: TextStyle(fontWeight: FontWeight.w700)),
            ),
          ]),
          const SizedBox(height: 4),
          const Text('A "25ª palavra": mesma frase + senha diferente = carteira diferente.',
              style: TextStyle(fontSize: 10.5, color: Cores.textoSuave)),
        ])),
        const SizedBox(height: 12),

        // ── importar ──
        _card(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Text('Importar carteira', style: TextStyle(fontWeight: FontWeight.w700)),
          const SizedBox(height: 4),
          const Text('Cole a frase (12/24 palavras) ou os números das posições do Cartão 1248.',
              style: TextStyle(fontSize: 11.5, color: Cores.textoSuave)),
          const SizedBox(height: 8),
          TextField(
            controller: _importarCtrl,
            maxLines: 2,
            style: const TextStyle(fontFamily: 'monospace', fontSize: 12),
            decoration: const InputDecoration(isDense: true, hintText: 'palavras…  ou  1723 45 987 …'),
          ),
          const SizedBox(height: 8),
          Row(children: [
            OutlinedButton(onPressed: _importar, child: const Text('Importar')),
            const SizedBox(width: 8),
            OutlinedButton.icon(
              icon: const Icon(Icons.language, size: 16),
              label: Text(_frase == null ? 'Converter' : (_lang == 'en' ? 'p/ Português' : 'p/ Inglês')),
              onPressed: _frase == null ? null : _converterIdioma,
            ),
          ]),
          if (_avisoConversao)
            const Padding(
              padding: EdgeInsets.only(top: 6),
              child: Text(
                'Convertido: os números das posições são os mesmos, mas a seed nasce das palavras escritas — trocar o idioma gera uma carteira DIFERENTE (a zpub já é a nova).',
                style: TextStyle(fontSize: 10.5, color: Color(0xFFe6b84c), height: 1.4),
              ),
            ),
        ])),
        const SizedBox(height: 16),

        // ── zpub + avançado ──
        if (_frase != null && _carregando)
          const Padding(padding: EdgeInsets.all(20), child: Center(child: CircularProgressIndicator(color: Cores.dourado))),
        if (_derivandoErro != null)
          Text('✗ $_derivandoErro', style: const TextStyle(color: Cores.vermelho, fontSize: 12)),
        if (_deriv != null) ...[
          _titulo('zpub', Icons.visibility),
          _card(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('A chave pública da carteira: vigia saldo/histórico e monta transações — mas nunca executa. Quem assina é a cold wallet offline.',
                style: const TextStyle(fontSize: 11.5, color: Cores.textoSuave)),
            const SizedBox(height: 8),
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: Cores.dourado.withValues(alpha: 0.12),
                border: Border.all(color: Cores.dourado),
                borderRadius: BorderRadius.circular(8),
              ),
              child: SelectableText(_deriv!.zpub, style: const TextStyle(fontSize: 11, color: Cores.dourado)),
            ),
            const SizedBox(height: 6),
            Row(children: [
              OutlinedButton.icon(
                onPressed: () => _copiar(_deriv!.zpub, 'a zpub'),
                icon: const Icon(Icons.copy, size: 16),
                label: const Text('Copiar zpub'),
              ),
              const SizedBox(width: 8),
              const Text('use na aba Carteiras', style: TextStyle(fontSize: 11, color: Cores.textoSuave)),
            ]),
          ])),
          const SizedBox(height: 12),
          _avancado(),
        ],
      ]),
    );
  }

  Widget _avisoTeste() => Container(
        padding: const EdgeInsets.all(10),
        decoration: BoxDecoration(
          color: const Color(0x33e6b84c),
          borderRadius: BorderRadius.circular(8),
          border: Border.all(color: const Color(0x66e6b84c)),
        ),
        child: const Row(children: [
          Icon(Icons.science_outlined, color: Color(0xFFe6b84c), size: 18),
          SizedBox(width: 8),
          Expanded(child: Text(
            'Esta geração é apenas para teste. Pra criar uma carteira de verdade, use o arquivo offline no Tails OS (veja a aba Cartão 1248).',
            style: TextStyle(fontSize: 11.5, color: Color(0xFFe6b84c)),
          )),
        ]),
      );

  Widget _cartaozinho(Map<String, dynamic> p) {
    const casas = [
      [1, 2], [1, 4], [2, 8], [1, 4], [2, 8], [1, 4], [2, 8]
    ];
    const colIni = [0, 1, 3, 5];
    const valores = [
      [1, 2], [1, 4, 2, 8], [1, 4, 2, 8], [1, 4, 2, 8]
    ];
    String s = p['n'].toString().padLeft(4, '0');
    final furos = <int>{};
    for (var g = 0; g < 4; g++) {
      final t = int.parse(s[g]);
      for (var i = 0; i < valores[g].length; i++) {
        if (t & valores[g][i] != 0) {
          furos.add(colIni[g] * 2 + (i < 2 ? i : 1));
        }
      }
    }
    final tamPal = (p['palavra'] as String).length;
    return Container(
      width: 225,
      decoration: BoxDecoration(
        color: Colors.white,
        border: Border.all(color: Colors.black, width: 2),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(children: [
        Container(
          width: 66,
          padding: const EdgeInsets.symmetric(vertical: 8),
          decoration: const BoxDecoration(border: Border(right: BorderSide(color: Colors.black, width: 2))),
          child: Column(children: [
            Text('${p['pos']}º', style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w900, color: Colors.black)),
            const SizedBox(height: 3),
            Text(p['palavra'],
                textAlign: TextAlign.center,
                style: TextStyle(
                  fontSize: tamPal <= 4 ? 13.0 : tamPal <= 6 ? 10.5 : 8.5,
                  fontWeight: FontWeight.w900,
                  color: Colors.black,
                )),
            const SizedBox(height: 3),
            Text('nº ${p['n']}', style: const TextStyle(fontSize: 8.5, color: Colors.black54, fontWeight: FontWeight.w700)),
          ]),
        ),
        Expanded(
          child: Row(children: [
            for (var i = 0; i < 7; i++)
              Expanded(
                child: Container(
                  decoration: BoxDecoration(
                    border: Border(
                      left: (i == 1 || i == 3 || i == 5)
                          ? const BorderSide(color: Colors.black, width: 3)
                          : const BorderSide(color: Colors.black, width: 1.4),
                    ),
                  ),
                  child: Column(children: [
                    for (var j = 0; j < 2; j++)
                      Container(
                        height: 46,
                        decoration: BoxDecoration(border: Border(top: j == 1 ? const BorderSide(color: Colors.black, width: 1.4) : BorderSide.none)),
                        child: Center(
                          child: Container(
                            width: 20,
                            height: 20,
                            decoration: BoxDecoration(
                              shape: BoxShape.circle,
                              color: furos.contains(i * 2 + j) ? Colors.black : null,
                            ),
                            child: Center(
                              child: Text(
                                '${casas[i][j]}',
                                style: TextStyle(
                                  fontSize: 10,
                                  fontWeight: FontWeight.w900,
                                  color: furos.contains(i * 2 + j) ? Colors.white : Colors.black,
                                ),
                              ),
                            ),
                          ),
                        ),
                      ),
                  ]),
                ),
              ),
          ]),
        ),
      ]),
    );
  }

  Widget _avancado() => Theme(
        data: Theme.of(context).copyWith(dividerColor: Colors.transparent),
        child: ExpansionTile(
          tilePadding: EdgeInsets.zero,
          iconColor: Cores.dourado,
          title: const Text('Avançado — todas as chaves da carteira', style: TextStyle(fontSize: 13)),
          children: [
            _dado('Seed (512 bits) — o número mestre', _deriv!.seed),
            _dado('xprv raiz — com ela se reconstrói tudo', _deriv!.xprv),
            _dado('zprv da conta — o privado da zpub', _deriv!.zprv),
            _dado('xpub raiz — deriva tudo sem mover moedas', _deriv!.xpub),
            const Padding(
              padding: EdgeInsets.only(top: 6, bottom: 4),
              child: Text('Endereços e WIF (m/84\'/0\'/0\'/0/i)', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 12.5)),
            ),
            for (final e in _deriv!.end84)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 2),
                child: SelectableText(
                  "m/84'/0'/0'/0/${e['i']}  ${e['endereco']}\nWIF ${e['wif']}",
                  style: const TextStyle(fontSize: 10.5, color: Cores.dourado),
                ),
              ),
          ],
        ),
      );

  Widget _dado(String rotulo, String valor) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 3),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(rotulo, style: const TextStyle(fontSize: 11, color: Cores.textoSuave)),
          const SizedBox(height: 2),
          SelectableText(valor, style: const TextStyle(fontSize: 10.5, color: Cores.texto)),
        ]),
      );

  Widget _seletor(List<String> opcoes, String atual, void Function(String) aoMudar) {
    return Wrap(spacing: 0, children: [
      for (final op in opcoes)
        InkWell(
          onTap: () => aoMudar(op),
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
            decoration: BoxDecoration(
              border: Border.all(color: op == atual ? Cores.dourado : Cores.borda),
              color: op == atual ? Cores.dourado.withValues(alpha: 0.14) : null,
              borderRadius: BorderRadius.horizontal(
                left: op == opcoes.first ? const Radius.circular(6) : Radius.zero,
                right: op == opcoes.last ? const Radius.circular(6) : Radius.zero,
              ),
            ),
            child: Text(op,
                style: TextStyle(
                  fontSize: 12,
                  fontWeight: op == atual ? FontWeight.w700 : FontWeight.w400,
                  color: op == atual ? Cores.dourado : Cores.textoSuave,
                )),
          ),
        ),
    ]);
  }

  Widget _titulo(String t, IconData i) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Row(children: [
          Icon(i, color: Cores.dourado, size: 17),
          const SizedBox(width: 6),
          Text(t, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 14.5)),
        ]),
      );

  Widget _card({required Widget child}) => Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: Cores.cartao,
          border: Border.all(color: Cores.borda),
          borderRadius: BorderRadius.circular(12),
        ),
        child: child,
      );
}

// ═══════════════════════════════════════════════════════════════════════════
// ABA CARTEIRAS (watch-only ao vivo)
// ═══════════════════════════════════════════════════════════════════════════

class AbaCarteiras extends StatefulWidget {
  const AbaCarteiras({super.key});

  @override
  State<AbaCarteiras> createState() => _AbaCarteirasState();
}

class _AbaCarteirasState extends State<AbaCarteiras> {
  List<Map<String, dynamic>>? _carteiras;
  Map<String, int>? _taxas;
  double _cotacao = 0; // BTC/BRL pro saldo em reais
  String? _erro;
  Timer? _timer;

  final _rotuloCtrl = TextEditingController();
  final _zpubCtrl = TextEditingController();
  final _hexCtrl = TextEditingController();

  /// Controladores do formulário de transação POR CARTEIRA — criados no
  /// build seriam refeitos a cada polling de 30s, apagando o que o usuário
  /// digitou. Aqui eles vivem no State e só são descartados no dispose.
  final Map<int, (TextEditingController, TextEditingController, TextEditingController)> _pacoteCtrls = {};

  @override
  void initState() {
    super.initState();
    _carregar();
    _timer = Timer.periodic(const Duration(seconds: 30), (_) => _carregar());
  }

  @override
  void dispose() {
    _timer?.cancel();
    _rotuloCtrl.dispose();
    _zpubCtrl.dispose();
    _hexCtrl.dispose();
    for (final t in _pacoteCtrls.values) {
      t.$1.dispose();
      t.$2.dispose();
      t.$3.dispose();
    }
    super.dispose();
  }

  Future<void> _carregar() async {
    try {
      final dados = await ApiService.carteirasDados();
      if (!mounted) return;
      setState(() {
        _carteiras = dados;
        _erro = null;
      });
      ApiService.carteirasTaxas().then((t) {
        if (mounted) setState(() => _taxas = t);
      }).catchError((_) {});
      ApiService.carteirasCotacao().then((c) {
        if (mounted && c > 0) setState(() => _cotacao = c);
      }).catchError((_) {});
    } catch (e) {
      if (mounted) setState(() => _erro = e.toString().replaceFirst('Exception: ', ''));
    }
  }

  Future<void> _importar() async {
    final zpub = _zpubCtrl.text.trim();
    if (zpub.isEmpty) return;
    try {
      final enderecos = zpubEnderecos(zpub, 20, 20);
      await ApiService.carteirasImportar(
        _rotuloCtrl.text.trim().isEmpty ? 'Carteira' : _rotuloCtrl.text.trim(),
        zpub,
        {'receber': enderecos.receber, 'troco': enderecos.troco},
      );
      _zpubCtrl.clear();
      _rotuloCtrl.clear();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Carteira importada ✓'), backgroundColor: Cores.verde));
      }
      _carregar();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(e.toString().replaceFirst('Exception: ', '')), backgroundColor: Cores.vermelho));
      }
    }
  }

  Future<void> _remover(int id) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: Cores.cartao,
        title: const Text('Remover carteira?'),
        content: const Text('Só para de vigiar — as moedas não são afetadas.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Voltar')),
          FilledButton(
              style: FilledButton.styleFrom(backgroundColor: Cores.vermelho),
              onPressed: () => Navigator.pop(ctx, true),
              child: const Text('Remover')),
        ],
      ),
    );
    if (ok == true) {
      await ApiService.carteirasRemover(id);
      _carregar();
    }
  }

  Future<void> _enviar() async {
    final hex = _hexCtrl.text.trim();
    if (hex.isEmpty) return;
    try {
      final txid = await ApiService.carteirasEnviar(hex);
      _hexCtrl.clear();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text('Transmitida ✓ txid: $txid'), backgroundColor: Cores.verde));
      }
      _carregar();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(e.toString().replaceFirst('Exception: ', '')), backgroundColor: Cores.vermelho));
      }
    }
  }

  String _fmtSats(dynamic n) {
    final v = (n is num) ? n : int.tryParse(n.toString()) ?? 0;
    final btc = v / 100000000;
    var txt = '${btc.toStringAsFixed(8).replaceAll(RegExp(r'0+$'), '').replaceAll('.', ',')} BTC';
    if (_cotacao > 0) {
      final brl = btc * _cotacao;
      txt += '\nR\$ ${brl.toStringAsFixed(2).replaceAll('.', ',')}';
    }
    txt += '\n${v.toString().replaceAllMapped(RegExp(r'\B(?=(\d{3})+(?!\d))'), (m) => '.')} sats';
    return txt;
  }

  int _vsizeDe(int nUtxos) => 11 + 68 * nUtxos + 62;

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      color: Cores.dourado,
      onRefresh: _carregar,
      child: ListView(padding: const EdgeInsets.all(14), children: [
        if (_erro != null)
          Padding(padding: const EdgeInsets.only(bottom: 10), child: Text('✗ $_erro', style: const TextStyle(color: Cores.vermelho, fontSize: 12))),
        if (_carteiras == null)
          const Padding(padding: EdgeInsets.all(30), child: Center(child: CircularProgressIndicator(color: Cores.dourado))),

        // ── carteiras ──
        if (_carteiras != null)
          for (final c in _carteiras!) _carteiraWidget(c),

        const SizedBox(height: 14),

        // ── importar ──
        _card(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Text('Importar watch-only', style: TextStyle(fontWeight: FontWeight.w700)),
          const SizedBox(height: 8),
          TextField(controller: _rotuloCtrl, decoration: const InputDecoration(labelText: 'Nome', isDense: true)),
          const SizedBox(height: 6),
          TextField(
            controller: _zpubCtrl,
            style: const TextStyle(fontFamily: 'monospace', fontSize: 11),
            decoration: const InputDecoration(labelText: 'zpub (chave pública — nunca a frase)', isDense: true),
          ),
          const SizedBox(height: 8),
          OutlinedButton(onPressed: _importar, child: const Text('Importar')),
        ])),
        const SizedBox(height: 14),

        // ── transmitir ──
        _card(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Text('Transmitir transação assinada', style: TextStyle(fontWeight: FontWeight.w700)),
          const SizedBox(height: 8),
          TextField(
            controller: _hexCtrl,
            maxLines: 3,
            style: const TextStyle(fontFamily: 'monospace', fontSize: 10),
            decoration: const InputDecoration(hintText: 'cole o hex assinado no Tails…', isDense: true),
          ),
          const SizedBox(height: 8),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Cores.dourado, foregroundColor: Colors.black),
            onPressed: _enviar,
            child: const Text('Enviar pra mempool', style: TextStyle(fontWeight: FontWeight.w700)),
          ),
        ])),
      ]),
    );
  }

  Widget _carteiraWidget(Map<String, dynamic> c) {
    final utxosUsaveis = (c['utxos'] as List).where((u) => u['confirmado'] == true).toList();
    final pendente = (c['saldo_pendente'] ?? 0) is int ? c['saldo_pendente'] as int : int.tryParse('${c['saldo_pendente']}') ?? 0;
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      decoration: BoxDecoration(
        color: Cores.cartao,
        border: Border.all(color: Cores.borda),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(children: [
        Container(
          padding: const EdgeInsets.all(12),
          decoration: const BoxDecoration(
            border: Border(bottom: BorderSide(color: Cores.borda)),
          ),
          child: Row(children: [
            const Icon(Icons.visibility, color: Cores.dourado, size: 18),
            const SizedBox(width: 8),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(c['rotulo'], style: const TextStyle(fontWeight: FontWeight.w800)),
                SelectableText(c['zpub'],
                    style: const TextStyle(fontSize: 9, fontFamily: 'monospace', color: Cores.textoSuave)),
              ]),
            ),
            Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
              Text(_fmtSats(c['saldo']), textAlign: TextAlign.right, style: const TextStyle(color: Cores.dourado, fontWeight: FontWeight.w800, fontSize: 12.5, height: 1.3)),
              if (pendente != 0)
                Text('+$pendente sats a confirmar', style: const TextStyle(color: Cores.verde, fontSize: 10)),
            ]),
            IconButton(
              icon: const Icon(Icons.delete_outline, size: 18),
              onPressed: () => _remover(c['id'] as int),
            ),
          ]),
        ),
        Padding(
          padding: const EdgeInsets.all(12),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            // histórico
            const Text('Movimento — ao vivo', style: TextStyle(fontSize: 11.5, fontWeight: FontWeight.w700)),
            if ((c['txs'] as List).isEmpty)
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 6),
                child: Text('Nenhuma transação ainda.', style: TextStyle(fontSize: 11, color: Cores.textoSuave)),
              )
            else
              for (final t in (c['txs'] as List).take(8))
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 2),
                  child: Row(children: [
                    Expanded(
                      child: Text(
                        '${t['quando']} · ${t['conf'] ? "✓ ${t['confirmacoes']} confirmações" : "na mempool"}',
                        style: TextStyle(fontSize: 10.5, color: t['conf'] ? Cores.verde : Cores.dourado),
                      ),
                    ),
                    Text(
                      '${(t['delta'] as int) >= 0 ? '+' : ''}${t['delta']} sats',
                      style: TextStyle(
                        fontSize: 10.5,
                        fontWeight: FontWeight.w700,
                        color: (t['delta'] as int) >= 0 ? Cores.verde : Cores.vermelho,
                      ),
                    ),
                  ]),
                ),
            const SizedBox(height: 10),

            // UTXOs
            Text('UTXOs disponíveis (${utxosUsaveis.length})', style: const TextStyle(fontSize: 11.5, fontWeight: FontWeight.w700)),
            for (final u in utxosUsaveis.take(5))
              Text(
                '${(u['txid'] as String).substring(0, 16)}…:${u['vout']} · ${u['sats']} sats · end. nº ${u['i']}',
                style: const TextStyle(fontSize: 10, fontFamily: 'monospace', color: Cores.textoSuave),
              ),
            const SizedBox(height: 10),

            _enderecosWidget(c),

            // criar transação não assinada
            _pacoteWidget(c, utxosUsaveis),
          ]),
        ),
      ]),
    );
  }

  Widget _pacoteWidget(Map<String, dynamic> c, List utxosUsaveis) {
    final id = c['id'] as int;
    if (!_pacoteCtrls.containsKey(id)) {
      _pacoteCtrls[id] = (
        TextEditingController(),
        TextEditingController(),
        TextEditingController(),
      );
    }
    final (destCtrl, valorCtrl, taxaCtrl) = _pacoteCtrls[id]!;

    // taxa padrão: mínimo recomendado × tamanho
    if (_taxas != null && utxosUsaveis.isNotEmpty && taxaCtrl.text.isEmpty) {
      taxaCtrl.text = '${_taxas!['economica']! * _vsizeDe(utxosUsaveis.length)}';
    }

    return Theme(
      data: Theme.of(context).copyWith(dividerColor: Colors.transparent),
      child: ExpansionTile(
        tilePadding: EdgeInsets.zero,
        iconColor: Cores.dourado,
        title: const Text('Criar transação não assinada', style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700)),
        children: [
          TextField(controller: destCtrl, decoration: const InputDecoration(labelText: 'Destino bc1q…', isDense: true)),
          const SizedBox(height: 6),
          Row(children: [
            Expanded(child: TextField(controller: valorCtrl, decoration: const InputDecoration(labelText: 'Valor (sats ou max)', isDense: true))),
            const SizedBox(width: 8),
            Expanded(child: TextField(controller: taxaCtrl, decoration: const InputDecoration(labelText: 'Taxa (sats)', isDense: true))),
          ]),
          if (_taxas != null)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Wrap(spacing: 6, children: [
                for (final e in {'mínima': 'economica', '~1h': 'hora', '~30min': 'meiaHora', 'rápida': 'rapida'}.entries)
                  ActionChip(
                    label: Text(e.key, style: const TextStyle(fontSize: 11)),
                    onPressed: () => taxaCtrl.text = '${_taxas![e.value]! * _vsizeDe(utxosUsaveis.length)}',
                  ),
              ]),
            ),
          const SizedBox(height: 8),
          FilledButton.icon(
            style: FilledButton.styleFrom(backgroundColor: Cores.dourado, foregroundColor: Colors.black),
            icon: const Icon(Icons.description, size: 16),
            label: const Text('Gerar pacote', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 12.5)),
            onPressed: () {
              if (utxosUsaveis.isEmpty) {
                ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('Sem UTXO confirmado pra gastar.'), backgroundColor: Cores.vermelho));
                return;
              }
              final pacote = {
                'v': 1,
                'tipo': 'pacote-1248',
                'destino': destCtrl.text.trim(),
                'valor': valorCtrl.text.trim().isEmpty ? 'max' : valorCtrl.text.trim(),
                'taxa': taxaCtrl.text.trim(),
                'utxos': utxosUsaveis
                    .map((u) => {'txid': u['txid'], 'vout': u['vout'], 'sats': u['sats'], 'indice': u['i']})
                    .toList(),
              };
              final texto = const JsonEncoder.withIndent(' ').convert(pacote);
              Clipboard.setData(ClipboardData(text: texto));
              ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
                  content: Text('Pacote copiado ✓ — leve pro assinador offline (Tails)'),
                  backgroundColor: Cores.verde));
            },
          ),
        ],
      ),
    );
  }

  Widget _card({required Widget child}) => Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: Cores.cartao,
          border: Border.all(color: Cores.borda),
          borderRadius: BorderRadius.circular(12),
        ),
        child: child,
      );

  // ── Endereços de recebimento (lista + histórico por endereço + criar mais) ──
  final Map<String, bool> _histAberto = {};
  final Map<String, Widget> _histCache = {};

  Widget _enderecosWidget(Map<String, dynamic> c) {
    final enderecos = (c['receber'] as List?) ?? [];
    return Theme(
      data: Theme.of(context).copyWith(dividerColor: Colors.transparent),
      child: ExpansionTile(
        tilePadding: EdgeInsets.zero,
        iconColor: Cores.dourado,
        title: Text('Endereços de recebimento (' + enderecos.length.toString() + ')',
            style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700)),
        children: [
          for (final e in enderecos)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 3),
              child: Column(children: [
                Row(children: [
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
                    decoration: BoxDecoration(color: Cores.dourado.withValues(alpha: 0.14), borderRadius: BorderRadius.circular(4)),
                    child: Text('nº ' + e['i'].toString(), style: const TextStyle(fontSize: 10, color: Cores.dourado, fontWeight: FontWeight.w700)),
                  ),
                  const SizedBox(width: 6),
                  Expanded(
                    child: SelectableText(e['endereco'].toString(),
                        style: const TextStyle(fontSize: 10.5, fontFamily: 'monospace', color: Cores.texto)),
                  ),
                  IconButton(
                    icon: const Icon(Icons.copy, size: 14),
                    padding: EdgeInsets.zero,
                    constraints: const BoxConstraints(),
                    onPressed: () {
                      Clipboard.setData(ClipboardData(text: e['endereco'].toString()));
                      ScaffoldMessenger.of(context).showSnackBar(
                          const SnackBar(content: Text('Endereço copiado'), backgroundColor: Cores.verde));
                    },
                  ),
                  IconButton(
                    icon: const Icon(Icons.history, size: 15),
                    padding: EdgeInsets.zero,
                    constraints: const BoxConstraints(),
                    onPressed: () => _toggleHistorico(c['id'].toString() + '-' + e['i'].toString(), e['endereco'].toString()),
                  ),
                ]),
                if (_histAberto[c['id'].toString() + '-' + e['i'].toString()] == true)
                  (_histCache[c['id'].toString() + '-' + e['i'].toString()] ??
                      const Padding(padding: EdgeInsets.all(6), child: SizedBox(height: 14, width: 14, child: CircularProgressIndicator(strokeWidth: 2, color: Cores.dourado)))),
              ]),
            ),
          const SizedBox(height: 6),
          OutlinedButton.icon(
            icon: const Icon(Icons.add, size: 15),
            label: const Text('Criar mais 10 endereços', style: TextStyle(fontSize: 12)),
            onPressed: () async {
              try {
                final end = zpubEnderecos(c['zpub'].toString(), enderecos.length + 10, 20);
                await ApiService.carteirasEnderecos(c['id'] as int, end.receber.cast<Map<String, dynamic>>());
                _carregar();
              } catch (e2) {
                if (mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(content: Text(e2.toString().replaceFirst('Exception: ', '')), backgroundColor: Cores.vermelho));
                }
              }
            },
          ),
        ],
      ),
    );
  }

  void _toggleHistorico(String chave, String endereco) {
    setState(() {
      _histAberto[chave] = !(_histAberto[chave] ?? false);
    });
    if (_histAberto[chave] == true && !_histCache.containsKey(chave)) {
      http2
          .get(Uri.parse('https://mempool.space/api/address/' + endereco + '/txs'))
          .timeout(const Duration(seconds: 15))
          .then((r) {
        final txs = jsonDecode(r.body) as List;
        Widget w;
        if (txs.isEmpty) {
          w = const Padding(padding: EdgeInsets.only(left: 30, bottom: 4),
              child: Text('sem transações neste endereço.', style: TextStyle(fontSize: 10.5, color: Cores.textoSuave)));
        } else {
          w = Padding(
            padding: const EdgeInsets.only(left: 30, bottom: 4),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              for (final tx in txs.take(6))
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 1),
                  child: Row(children: [
                    Expanded(
                      child: Text(
                        (tx['status'] != null && tx['status']['confirmed'] == true)
                            ? DateTime.fromMillisecondsSinceEpoch((tx['status']['block_time'] as int) * 1000).toString().substring(0, 16)
                            : 'na mempool',
                        style: TextStyle(
                            fontSize: 10,
                            color: (tx['status'] != null && tx['status']['confirmed'] == true) ? Cores.verde : Cores.dourado),
                      ),
                    ),
                    Text(tx['txid'].toString().substring(0, 12) + '…',
                        style: const TextStyle(fontSize: 9.5, fontFamily: 'monospace', color: Cores.textoSuave)),
                  ]),
                ),
            ]),
          );
        }
        if (mounted) setState(() => _histCache[chave] = w);
      }).catchError((_) {
        if (mounted) {
          setState(() => _histCache[chave] = const Padding(
              padding: EdgeInsets.only(left: 30, bottom: 4),
              child: Text('não deu pra consultar agora.', style: TextStyle(fontSize: 10.5, color: Cores.vermelho))));
        }
      });
    }
  }
}

