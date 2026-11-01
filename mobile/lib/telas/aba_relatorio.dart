// ─────────────────────────────────────────────────────────────────────────────
// ABA RELATÓRIO — o P&L realizado mensal (FIFO sobre bot_trades), a página
// /relatorio do site: totais do mês, melhor/pior dia, drawdown e a série
// diária. Navegação mês a mês pelos botões ‹ ›.
// ─────────────────────────────────────────────────────────────────────────────

import 'package:flutter/material.dart';

import '../modelos/admin.dart';
import '../servicos/api.dart';
import '../tema.dart';
import '../util/formatar.dart';
import 'tela_login.dart';

class AbaRelatorio extends StatefulWidget {
  const AbaRelatorio({super.key});

  @override
  State<AbaRelatorio> createState() => _AbaRelatorioState();
}

class _AbaRelatorioState extends State<AbaRelatorio> {
  RelatorioMensal? _relatorio;
  DateTime _mes = DateTime.now(); // mês em exibição (dia ignorado)
  bool _carregando = true;
  String? _erro;

  @override
  void initState() {
    super.initState();
    _carregar();
  }

  Future<void> _carregar({bool silencioso = false}) async {
    if (!silencioso && _relatorio == null) setState(() => _carregando = true);

    try {
      final r = await ApiService.relatorioMensal(ano: _mes.year, mes: _mes.month);
      if (!mounted) return;
      setState(() {
        _relatorio = r;
        _erro = null;
        _carregando = false;
      });
    } on ApiException catch (e) {
      if (e.status == 401) return _tokenInvalido();
      _falhou(e.mensagem, silencioso: silencioso);
    } catch (_) {
      _falhou('Não foi possível conectar ao servidor.', silencioso: silencioso);
    }
  }

  void _falhou(String mensagem, {required bool silencioso}) {
    if (!mounted) return;
    if (silencioso && _relatorio != null) return;
    if (_relatorio != null) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(mensagem), backgroundColor: Cores.vermelho),
      );
      return;
    }
    setState(() {
      _erro = mensagem;
      _carregando = false;
    });
  }

  Future<void> _tokenInvalido() async {
    await ApiService.limparToken();
    if (!mounted) return;
    Navigator.of(context).pushReplacement(MaterialPageRoute(builder: (_) => const TelaLogin()));
  }

  void _mudarMes(int delta) {
    setState(() {
      _mes = DateTime(_mes.year, _mes.month + delta, 1);
      _carregando = true;
    });
    _carregar(silencioso: _relatorio != null);
  }

  String get _rotuloMes {
    const meses = [
      'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
      'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'
    ];
    return '${meses[_mes.month - 1]}/${_mes.year}';
  }

  @override
  Widget build(BuildContext context) {
    final agora = DateTime.now();
    final mesCorrente = _mes.year == agora.year && _mes.month == agora.month;

    return RefreshIndicator(
      onRefresh: () => _carregar(),
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(12, 12, 12, 24),
        children: [
          // Navegação de mês
          Card(
            margin: const EdgeInsets.only(bottom: 8),
            child: Row(
              children: [
                IconButton(
                  onPressed: () => _mudarMes(-1),
                  icon: const Icon(Icons.chevron_left),
                  tooltip: 'Mês anterior',
                ),
                Expanded(
                  child: Center(
                    child: Text(
                      _rotuloMes[0].toUpperCase() + _rotuloMes.substring(1),
                      style: const TextStyle(fontSize: 14.5, fontWeight: FontWeight.w700, color: Cores.dourado),
                    ),
                  ),
                ),
                IconButton(
                  onPressed: mesCorrente ? null : () => _mudarMes(1),
                  icon: const Icon(Icons.chevron_right),
                  tooltip: 'Próximo mês',
                ),
              ],
            ),
          ),

          if (_carregando && _relatorio == null)
            const Padding(
              padding: EdgeInsets.only(top: 60),
              child: Center(child: CircularProgressIndicator()),
            )
          else if (_erro != null && _relatorio == null)
            Padding(
              padding: const EdgeInsets.only(top: 60),
              child: Column(
                children: [
                  const Icon(Icons.wifi_off, size: 48, color: Cores.textoSuave),
                  const SizedBox(height: 8),
                  Text(_erro!, textAlign: TextAlign.center),
                ],
              ),
            )
          else if (_relatorio != null)
            _relatorioWidget(_relatorio!),
        ],
      ),
    );
  }

  Widget _relatorioWidget(RelatorioMensal r) {
    if (r.vazio) {
      return const Card(
        child: Padding(
          padding: EdgeInsets.all(14),
          child: Text('Nenhuma operação neste mês.', style: TextStyle(color: Cores.textoSuave)),
        ),
      );
    }

    final (_, positivo) = setaLucro(r.pnl);
    final cor = positivo ? Cores.verde : Cores.vermelho;

    Widget linha(String rotulo, String valor, {Color? cor}) => Padding(
          padding: const EdgeInsets.only(bottom: 5),
          child: Row(
            children: [
              Expanded(child: Text(rotulo, style: const TextStyle(fontSize: 12, color: Cores.textoSuave))),
              Text(valor, style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600, color: cor ?? Cores.texto)),
            ],
          ),
        );

    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            FittedBox(
              fit: BoxFit.scaleDown,
              child: Row(
                children: [
                  Text('P&L realizado: ', style: const TextStyle(fontSize: 13, color: Cores.textoSuave)),
                  Text(
                    '${positivo ? '▲' : '▼'} ${moeda(r.pnl.abs())}',
                    style: TextStyle(fontSize: 19, fontWeight: FontWeight.w800, color: cor),
                  ),
                ],
              ),
            ),
            const Divider(height: 16),
            linha('Trades', '${r.trades}'),
            linha('Volume', moeda(r.volume)),
            linha('Taxas (fees)', moeda(r.feesBrl)),
            linha('Bruto (antes dos fees)', moeda(r.bruto)),
            linha('Melhor dia',
                r.melhorDia == null ? '—' : '${_dataCurta(r.melhorDia!.dia)} · ${moeda(r.melhorDia!.pnl)}',
                cor: Cores.verde),
            linha('Pior dia',
                r.piorDia == null ? '—' : '${_dataCurta(r.piorDia!.dia)} · ${moeda(r.piorDia!.pnl)}',
                cor: Cores.vermelho),
            linha('Drawdown máximo', moeda(r.drawdownMax), cor: Cores.vermelho),
            linha('Dias operados', '${r.diasOp} de ${r.diasTotais}'),
            linha('Média em dia operado', moeda(r.mediaDiaOp)),
            const Divider(height: 16),
            const Text('Série diária', style: TextStyle(fontSize: 11, fontWeight: FontWeight.w700)),
            const SizedBox(height: 6),
            ...r.serie.where((p) => p.trades > 0 || p.pnl != 0).map((p) {
              final (_, pos) = setaLucro(p.pnl);
              return Padding(
                padding: const EdgeInsets.only(bottom: 4),
                child: Row(
                  children: [
                    SizedBox(
                      width: 52,
                      child: Text(p.date, style: const TextStyle(fontSize: 11, color: Cores.textoSuave)),
                    ),
                    Expanded(
                      child: Text(
                        moeda(p.pnl),
                        style: TextStyle(
                          fontSize: 11.5,
                          fontWeight: FontWeight.w600,
                          color: p.pnl == 0 ? Cores.textoSuave : (pos ? Cores.verde : Cores.vermelho),
                        ),
                      ),
                    ),
                    Text('${p.trades} op.', style: const TextStyle(fontSize: 10.5, color: Cores.textoSuave)),
                  ],
                ),
              );
            }),
            if (r.btcAberto > 0) ...[
              const Divider(height: 16),
              Text(
                'Posição aberta: ${r.btcAberto.toStringAsFixed(5)} BTC · PM ${moeda(r.pmAberto)}',
                style: const TextStyle(fontSize: 10.5, color: Cores.textoSuave),
              ),
            ],
          ],
        ),
      ),
    );
  }

  /// "2026-10-02" → "02/10".
  String _dataCurta(String iso) {
    final d = DateTime.tryParse(iso);
    if (d == null) return iso;
    return '${d.day.toString().padLeft(2, '0')}/${d.month.toString().padLeft(2, '0')}';
  }
}
