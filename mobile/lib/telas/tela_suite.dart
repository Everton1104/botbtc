// ─────────────────────────────────────────────────────────────────────────────
// SUÍTE BITCOIN — Carteiras (watch-only).
//
// A suíte em uma aba só: carteiras registradas, endereços, UTXOs, criação de
// pacote de assinatura (salva ARQUIVO via seletor do Android — pendrive ou
// armazenamento interno) e broadcast. O gerar/1248 agora é o arquivo offline:
// um botão baixa o gerador-bip39.html do site e abre o seletor pra salvar.
//
// A cripto é a de servicos/bitcoin.dart — testada contra os vetores oficiais.
// A ASSINATURA de transações fica só no arquivo offline (Tails), por design.
// ─────────────────────────────────────────────────────────────────────────────
import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:http/http.dart' as http2;

import '../servicos/api.dart';
import '../servicos/arquivos.dart';
import '../servicos/bitcoin.dart';
import '../tema.dart';

class TelaSuite extends StatelessWidget {
  const TelaSuite({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Carteiras'),
        actions: const [_BtnBaixarGerador()],
      ),
      body: const AbaCarteiras(),
    );
  }
}

/// Baixa o gerador offline (gerador-bip39.html — gerar/1248/assinar) do site
/// e abre o SELETOR DE ARQUIVOS do Android pra salvar onde o usuário quiser
/// (pendrive OTG ou armazenamento interno). O ?v acompanha a versão publicada
/// do gerador — bumpar junto quando o site mudar.
class _BtnBaixarGerador extends StatefulWidget {
  const _BtnBaixarGerador();

  @override
  State<_BtnBaixarGerador> createState() => _BtnBaixarGeradorState();
}

class _BtnBaixarGeradorState extends State<_BtnBaixarGerador> {
  bool _baixando = false;

  Future<void> _baixar() async {
    if (_baixando) return;
    setState(() => _baixando = true);
    try {
      final resp = await http2.get(Uri.parse('$kBaseUrl/gerador-bip39.html?v=24'));
      if (resp.statusCode != 200) throw Exception('site respondeu ${resp.statusCode}');
      final ok = await Arquivos.salvarComo(
        nome: 'gerador-bitcoin-offline.html',
        mime: 'text/html',
        conteudo: resp.body,
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(ok
            ? 'Gerador salvo ✓ — abra no Tails/máquina limpa pra gerar ou assinar'
            : 'Download cancelado.'),
        backgroundColor: ok ? Cores.verde : Cores.textoSuave,
      ));
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text('Falha ao baixar: $e'), backgroundColor: Cores.vermelho));
      }
    } finally {
      if (mounted) setState(() => _baixando = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return IconButton(
      icon: _baixando
          ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Cores.dourado))
          : const Icon(Icons.download),
      tooltip: 'Baixar gerador offline (HTML)',
      onPressed: _baixando ? null : _baixar,
    );
  }
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

  /// Última taxa que o APP escreveu no campo (auto/chips). Se o texto atual
  /// é diferente disso, o usuário digitou manualmente — aí a gente não mexe.
  final Map<int, int> _taxaAuto = {};

  @override
  void initState() {
    super.initState();
    _carregar();
    _timer = Timer.periodic(const Duration(seconds: 30), (_) => _carregar(silencioso: true));
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

  /// Busca as carteiras no servidor.
  ///
  /// `silencioso: true` (o relógio de 30s) engole falha de rede quando já há
  /// dados na tela: o aparelho em standby/fora de cobertura derruba o DNS e a
  /// exception crua ("ClientException … host lookup") vinha parar no topo da
  /// lista sem quebrar nada — daqui a 30s ele tenta de novo sozinho. Sem
  /// dados ainda (primeira carga/puxão manual), mostra mensagem amigável.
  Future<void> _carregar({bool silencioso = false}) async {
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
    } on ApiException catch (e) {
      _falhou(e.mensagem, silencioso: silencioso);
    } catch (_) {
      _falhou('Não foi possível conectar ao servidor.', silencioso: silencioso);
    }
  }

  /// Tratamento comum das falhas de _carregar (o mesmo padrão da Home).
  void _falhou(String mensagem, {required bool silencioso}) {
    if (!mounted) return;
    // Polling com dados de pé: nada muda na tela, o próximo tic tenta de novo.
    if (silencioso && _carteiras != null) return;
    if (_carteiras != null) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(mensagem), backgroundColor: Cores.vermelho),
      );
      return;
    }
    setState(() => _erro = mensagem);
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

  /// Formato do saldo no card da carteira: clique no VALOR alterna
  /// BTC → sats → R$. static: a escolha vale pro resto da sessão — voltar
  /// na tela mantém o último formato escolhido.
  static String _fmtSaldo = 'btc';

  /// O valor da carteira no formato escolhido (uma linha).
  String _fmtSaldoFmt(dynamic n) {
    final v = ((n is num) ? n : int.tryParse(n.toString()) ?? 0).toInt();
    final btc = v / 100000000;
    switch (_fmtSaldo) {
      case 'sats':
        return '${_fmtMilhar(v)} sats';
      case 'brl':
        return _cotacao > 0
            ? 'R\$ ${(btc * _cotacao).toStringAsFixed(2).replaceAll('.', ',')}'
            : 'sem cotação agora';
      default:
        return '${btc.toStringAsFixed(8).replaceAll(RegExp(r'\.?0+$'), '').replaceAll('.', ',')} BTC';
    }
  }

  int _vsizeDe(int nUtxos) => 11 + 68 * nUtxos + 62;

  /// "≈ 0,00060000 BTC · R$ 651,23" — a linha de conversão do campo de valor
  /// (R$ só quando a cotação já chegou).
  String _fmtConv(int sats) {
    final btc = sats / 100000000;
    var t = '≈ ${btc.toStringAsFixed(8).replaceAll('.', ',')} BTC';
    if (_cotacao > 0) {
      t += ' · R\$ ${(btc * _cotacao).toStringAsFixed(2).replaceAll('.', ',')}';
    }
    return t;
  }

  /// O delta de cada linha do histórico segue o MESMO formato do total
  /// (o toque no valor da carteira comanda os dois): "+0,0005 BTC",
  /// "+50.000 sats" ou "+R$ 543,21".
  String _fmtDelta(dynamic n) {
    final v = ((n is num) ? n : int.tryParse(n.toString()) ?? 0).toInt();
    final s = v >= 0 ? '+' : '−';
    final abs = v.abs();
    switch (_fmtSaldo) {
      case 'sats':
        return '$s${_fmtMilhar(abs)} sats';
      case 'brl':
        return _cotacao > 0
            ? '$s${'R\$ ${(abs / 100000000 * _cotacao).toStringAsFixed(2).replaceAll('.', ',')}'}'
            : '$s${_fmtMilhar(abs)} sats'; // sem cotação ainda
      default:
        return '$s${(abs / 100000000).toStringAsFixed(8).replaceAll(RegExp(r'\.?0+$'), '').replaceAll('.', ',')} BTC';
    }
  }

  /// 87432 → "87.432" (separador de milhar pt-BR).
  String _fmtMilhar(int v) =>
      v.toString().replaceAllMapped(RegExp(r'\B(?=(\d{3})+(?!\d))'), (m) => '.');

  /// 5 → "05" (pro nome do arquivo do pacote).
  String _dois(int n) => n.toString().padLeft(2, '0');

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
              // O VALOR é botão: cada toque alterna BTC → sats → R$.
              GestureDetector(
                onTap: () => setState(() => _fmtSaldo = switch (_fmtSaldo) {
                      'btc' => 'sats',
                      'sats' => 'brl',
                      _ => 'btc',
                    }),
                child: Tooltip(
                  message: 'alternar: BTC / sats / R\$',
                  child: Text(_fmtSaldoFmt(c['saldo']),
                      textAlign: TextAlign.right,
                      style: const TextStyle(color: Cores.dourado, fontWeight: FontWeight.w800, fontSize: 12.5)),
                ),
              ),
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
                      _fmtDelta(t['delta']),
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
                '${(u['txid'] as String).substring(0, 16)}…:${u['vout']} · ${u['sats']} sats · end. nº ${(u['i'] as num).toInt() + 1}',
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
      final valor = TextEditingController();
      // Digitar no valor reconstrói na hora: a linha de conversão
      // sats → BTC → R$ embaixo do campo é calculada no build.
      valor.addListener(() {
        if (mounted) setState(() {});
      });
      _pacoteCtrls[id] = (
        TextEditingController(),
        valor,
        TextEditingController(),
      );
    }
    final (destCtrl, valorCtrl, taxaCtrl) = _pacoteCtrls[id]!;

    // Taxa AUTOMÁTICA acompanha o nº de UTXOs (cada uso da carteira engorda
    // a transação: vsize = 11 + 68×n + 62). Recalcula sozinha quando a lista
    // de UTXOs muda — SÓ enquanto o usuário não digitar um valor manualmente
    // (o flag guarda o último número que a gente mesmo escreveu no campo).
    final taxaCalc = (_taxas != null && utxosUsaveis.isNotEmpty)
        ? _taxas!['economica']! * _vsizeDe(utxosUsaveis.length)
        : null;
    if (taxaCalc != null &&
        (taxaCtrl.text.isEmpty || int.tryParse(taxaCtrl.text.trim()) == (_taxaAuto[id] ?? -1))) {
      _taxaAuto[id] = taxaCalc;
      taxaCtrl.text = '$taxaCalc';
    }

    // Conversão ao vivo do valor digitado: "≈ 0,00060000 BTC · R$ 651,23".
    // "max" mostra o que sobra dos UTXOs confirmados menos a taxa.
    final vTxt = valorCtrl.text.trim().toLowerCase();
    String? conv;
    if (vTxt == 'max' && utxosUsaveis.isNotEmpty) {
      final total = utxosUsaveis.fold<int>(0, (s, u) => s + (u['sats'] as num).toInt());
      final liquido = total - (int.tryParse(taxaCtrl.text.trim()) ?? 0);
      conv = 'max ≈ ${_fmtMilhar(liquido)} sats · ${_fmtConv(liquido)}';
    } else if (vTxt.isNotEmpty && RegExp(r'^\d+$').hasMatch(vTxt)) {
      conv = _fmtConv(int.parse(vTxt));
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
          if (conv != null)
            Padding(
              padding: const EdgeInsets.only(top: 4, bottom: 2),
              child: Text(conv, style: const TextStyle(fontSize: 11, color: Cores.dourado)),
            ),
          if (_taxas != null)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Wrap(spacing: 6, children: [
                for (final e in {'mínima': 'economica', '~1h': 'hora', '~30min': 'meiaHora', 'rápida': 'rapida'}.entries)
                  ActionChip(
                    label: Text(e.key, style: const TextStyle(fontSize: 11)),
                    // setState: com valor "max" a conversão depende da taxa —
                    // precisa reconstruir quando o chip troca a taxa.
                    onPressed: () => setState(() {
                          _taxaAuto[id] = _taxas![e.value]! * _vsizeDe(utxosUsaveis.length);
                          taxaCtrl.text = '${_taxaAuto[id]}';
                        }),
                  ),
              ]),
            ),
          const SizedBox(height: 8),
          FilledButton.icon(
            style: FilledButton.styleFrom(backgroundColor: Cores.dourado, foregroundColor: Colors.black),
            icon: const Icon(Icons.save_alt, size: 16),
            label: const Text('Gerar pacote', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 12.5)),
            onPressed: () async {
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
              // O pacote vira ARQUIVO na hora: o seletor do Android abre pra
              // escolher pendrive/armazenamento interno — de lá vai pro
              // assinador offline (Tails).
              final agora = DateTime.now();
              final nome = 'pacote-${agora.year}${_dois(agora.month)}${_dois(agora.day)}-${_dois(agora.hour)}${_dois(agora.minute)}.json';
              final ok = await Arquivos.salvarComo(nome: nome, mime: 'application/json', conteudo: texto);
              if (!mounted) return;
              ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                  content: Text(ok
                      ? 'Pacote salvo ✓ — leve o arquivo pro assinador offline (Tails)'
                      : 'Pacote não salvo (seletor cancelado).'),
                  backgroundColor: ok ? Cores.verde : Cores.textoSuave));
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
                    child: Text('nº ${(e['i'] as num).toInt() + 1}', style: const TextStyle(fontSize: 10, color: Cores.dourado, fontWeight: FontWeight.w700)),
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

