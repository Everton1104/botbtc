// ─────────────────────────────────────────────────────────────────────────────
// ABA NÚMEROS DO BOT — o que a aba "Mais Informações" do site mostra:
//   • Lucro do Bot: cards hoje / 7d / 30d (decomposição grid × oscilação)
//   • Performance (M8): base fotografada vs agora, pico, drawdown, trades
//   • Transferências Diretas (Binance)
//   • Tendência que o bot usa pra operar (RSI, ATR, F&G, MACD, Bollinger)
// ─────────────────────────────────────────────────────────────────────────────

import 'package:flutter/material.dart';

import '../modelos/admin.dart';
import '../servicos/api.dart';
import '../tema.dart';
import '../util/formatar.dart';
import 'tela_login.dart';

class AbaNumerosBot extends StatefulWidget {
  const AbaNumerosBot({super.key});

  @override
  State<AbaNumerosBot> createState() => _AbaNumerosBotState();
}

class _AbaNumerosBotState extends State<AbaNumerosBot> {
  NumerosAdmin? _dados;
  bool _carregando = true;
  String? _erro;

  @override
  void initState() {
    super.initState();
    _carregar();
  }

  Future<void> _carregar({bool silencioso = false}) async {
    if (!silencioso && _dados == null) setState(() => _carregando = true);

    try {
      final dados = await ApiService.numerosAdmin();
      if (!mounted) return;
      setState(() {
        _dados = dados;
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
    if (silencioso && _dados != null) return;
    if (_dados != null) {
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

  @override
  Widget build(BuildContext context) {
    final corpo = (_carregando && _dados == null)
        ? const Center(child: CircularProgressIndicator())
        : (_erro != null && _dados == null)
            ? ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                children: [
                  const SizedBox(height: 80),
                  const Icon(Icons.wifi_off, size: 48, color: Cores.textoSuave),
                  const SizedBox(height: 8),
                  Text(_erro!, textAlign: TextAlign.center),
                ],
              )
            : _lista(_dados!);

    return RefreshIndicator(onRefresh: () => _carregar(), child: corpo);
  }

  Widget _lista(NumerosAdmin d) {
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 24),
      children: [
        _titulo('Lucro do Bot', Icons.show_chart_outlined),
        _cardPnl('Hoje', d.pnlHoje),
        _cardPnl('Últimos 7 dias', d.pnlD7),
        _cardPnl('Últimos 30 dias', d.pnlD30),

        _titulo('Performance do Bot', Icons.speed),
        if (d.performance == null)
          const Card(
            child: Padding(
              padding: EdgeInsets.all(12),
              child: Text(
                'Saldos da Binance indisponíveis agora — puxe de novo em instantes.',
                style: TextStyle(color: Cores.textoSuave, fontSize: 12),
              ),
            ),
          )
        else
          _cardPerformance(d.performance!),

        _titulo('Tendência', Icons.explore_outlined),
        if (d.tendencia == null)
          const Card(
            child: Padding(
              padding: EdgeInsets.all(12),
              child: Text(
                'Indisponível agora — puxe de novo em instantes.',
                style: TextStyle(color: Cores.textoSuave, fontSize: 12),
              ),
            ),
          )
        else
          _cardTendencia(d.tendencia!),

        _titulo('Transferências Diretas (Binance)', Icons.swap_horiz),
        if (d.transferencias.isEmpty)
          _cardVazio('Nenhuma transferência direta registrada.')
        else
          ...d.transferencias.take(15).map(_cardTransferencia),
        if (d.transferencias.length > 15)
          const Padding(
            padding: EdgeInsets.only(top: 6),
            child: Text(
              'Mostrando as 15 mais recentes (o site lista 100).',
              style: TextStyle(fontSize: 10.5, color: Cores.textoSuave),
            ),
          ),
      ],
    );
  }

  Widget _titulo(String texto, IconData icone) {
    return Padding(
      padding: const EdgeInsets.only(top: 14, bottom: 8, left: 4),
      child: Row(
        children: [
          Icon(icone, size: 18, color: Cores.dourado),
          const SizedBox(width: 8),
          Text(texto, style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w700)),
        ],
      ),
    );
  }

  Widget _cardVazio(String texto) => Card(
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 14),
          child: Text(texto, textAlign: TextAlign.center, style: const TextStyle(color: Cores.textoSuave)),
        ),
      );

  /// Card de uma janela de P&L: total grande colorido + grid/oscilação/retorno.
  Widget _cardPnl(String rotulo, PnlJanela j) {
    if (j.vazio) {
      return Card(
        margin: const EdgeInsets.only(bottom: 8),
        child: ListTile(
          title: Text(rotulo, style: const TextStyle(fontWeight: FontWeight.w700)),
          subtitle: const Text('Sem operações no período.', style: TextStyle(fontSize: 12)),
        ),
      );
    }

    final (seta, positivo) = setaLucro(j.total);
    final cor = positivo ? Cores.verde : Cores.vermelho;
    final retorno = j.retornoTotalPct == null
        ? '—'
        : '${j.retornoTotalPct! >= 0 ? '+' : ''}${j.retornoTotalPct!.toStringAsFixed(2)}%';

    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(child: Text(rotulo, style: const TextStyle(fontSize: 12, color: Cores.textoSuave))),
                Text('${j.periodoInicio ?? ''} → ${j.periodoFim ?? ''}',
                    style: const TextStyle(fontSize: 10.5, color: Cores.textoSuave)),
              ],
            ),
            const SizedBox(height: 4),
            FittedBox(
              fit: BoxFit.scaleDown,
              child: Text(
                '$seta ${moeda(j.total.abs())}  ·  $retorno',
                style: TextStyle(fontSize: 19, fontWeight: FontWeight.w800, color: cor),
              ),
            ),
            const Divider(height: 14),
            Row(
              children: [
                Expanded(child: _mini('Grid (ciclos)', j.grid)),
                Expanded(child: _mini('Oscilação BTC', j.oscilacao)),
                Expanded(child: _mini('Fees', j.feesBrl)),
              ],
            ),
            const SizedBox(height: 6),
            Text(
              '${j.compras} compras · ${j.vendas} vendas · volume ${moeda(j.volume)}',
              style: const TextStyle(fontSize: 10.5, color: Cores.textoSuave),
            ),
          ],
        ),
      ),
    );
  }

  /// Par rótulo/valor colorido pelo sinal.
  Widget _mini(String rotulo, double valor) {
    final (_, positivo) = setaLucro(valor);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(rotulo, style: const TextStyle(fontSize: 10, color: Cores.textoSuave)),
        Text(
          moeda(valor),
          style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: valor == 0 ? Cores.texto : (positivo ? Cores.verde : Cores.vermelho)),
        ),
      ],
    );
  }

  /// Como _mini, mas valor em % (variações da performance M8).
  Widget _miniPct(String rotulo, double? valor) {
    final v = valor ?? 0;
    final (_, positivo) = setaLucro(v);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(rotulo, style: const TextStyle(fontSize: 10, color: Cores.textoSuave)),
        Text(
          valor == null ? '—' : '${v >= 0 ? '+' : ''}${v.toStringAsFixed(2)}%',
          style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: v == 0 ? Cores.texto : (positivo ? Cores.verde : Cores.vermelho)),
        ),
      ],
    );
  }

  /// O painel M8 completo: base → agora, pico/drawdown, trades e realizado.
  Widget _cardPerformance(PerformanceBot p) {
    String varPct(double? v) =>
        v == null ? '—' : '${v >= 0 ? '+' : ''}${v.toStringAsFixed(2)}%';

    Color corVar(double? v) => (v ?? 0) >= 0 ? Cores.verde : Cores.vermelho;

    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text('Patrimônio agora', style: TextStyle(fontSize: 10.5, color: Cores.textoSuave)),
                      FittedBox(
                        fit: BoxFit.scaleDown,
                        child: Text(moeda(p.atualPatrimonio),
                            style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: Cores.verde)),
                      ),
                    ],
                  ),
                ),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    const Text('Desde a base', style: TextStyle(fontSize: 10.5, color: Cores.textoSuave)),
                    Text(varPct(p.variacaoPatrimonioPct),
                        style: TextStyle(fontSize: 14, fontWeight: FontWeight.w700, color: corVar(p.variacaoPatrimonioPct))),
                  ],
                ),
              ],
            ),
            const SizedBox(height: 6),
            Text(
              p.baseEm == null
                  ? 'Base ainda não fotografada (o próximo ciclo do bot cria).'
                  : 'Base: ${moeda(p.basePatrimonioInicial ?? 0)} em ${p.baseEm}',
              style: const TextStyle(fontSize: 10.5, color: Cores.textoSuave),
            ),
            const Divider(height: 14),
            Row(
              children: [
                Expanded(child: _miniPct('Variação BTC', p.variacaoBtcPct)),
                Expanded(child: _miniPct('Variação BRL', p.variacaoBrlPct)),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text('Alocação', style: TextStyle(fontSize: 10, color: Cores.textoSuave)),
                      Text(
                        '${p.atualPctBtc.toStringAsFixed(1)}% BTC',
                        style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: Cores.dourado),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const Divider(height: 14),
            Row(
              children: [
                Expanded(child: _mini('Pico', p.picoValor)),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text('Drawdown máx.', style: TextStyle(fontSize: 10, color: Cores.textoSuave)),
                      Text('${p.drawdownMaxPct.toStringAsFixed(2)}%',
                          style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: Cores.vermelho)),
                    ],
                  ),
                ),
                Expanded(child: _mini('PnL realizado', p.pnlRealizadoAcumulado)),
              ],
            ),
            if (p.picoEm.isNotEmpty) ...[
              const SizedBox(height: 4),
              Text('Pico em ${p.picoEm}', style: const TextStyle(fontSize: 10.5, color: Cores.textoSuave)),
            ],
            const Divider(height: 14),
            Text(
              '${p.tradesTotal} operações (${p.tradesCompras} compras · ${p.tradesVendas} vendas)'
              '${p.tradesDesde != null ? ' desde ${p.tradesDesde}' : ''}',
              style: const TextStyle(fontSize: 11, color: Cores.textoSuave),
            ),
            const SizedBox(height: 8),
            const Text('Patrimônio dia a dia', style: TextStyle(fontSize: 11, fontWeight: FontWeight.w700)),
            const SizedBox(height: 6),
            ..._seriePatrimonio(p.serie),
          ],
        ),
      ),
    );
  }

  /// A série diária compacta: últimos 10 pontos, do mais antigo pro agora.
  List<Widget> _seriePatrimonio(List<PontoPatrimonio> serie) {
    if (serie.length <= 1) {
      return const [Text('—', style: TextStyle(fontSize: 11, color: Cores.textoSuave))];
    }
    final base = serie.first.patrimonio;
    return serie
        .skip(serie.length > 12 ? serie.length - 12 : 1)
        .toList()
        .map((pt) {
      final varPct = base > 0 ? (pt.patrimonio - base) / base * 100 : 0.0;
      final (_, positivo) = setaLucro(varPct);
      return Padding(
        padding: const EdgeInsets.only(bottom: 4),
        child: Row(
          children: [
            SizedBox(
              width: 86,
              child: Text(pt.dia, style: const TextStyle(fontSize: 11, color: Cores.textoSuave)),
            ),
            Expanded(child: Text(moeda(pt.patrimonio), style: const TextStyle(fontSize: 11.5, fontWeight: FontWeight.w600))),
            Text(
              '${positivo ? '+' : ''}${varPct.toStringAsFixed(2)}%',
              style: TextStyle(fontSize: 11.5, fontWeight: FontWeight.w600, color: positivo ? Cores.verde : Cores.vermelho),
            ),
          ],
        ),
      );
    }).toList();
  }

  /// O card de tendência: rótulo grande + indicadores que o bot usa.
  Widget _cardTendencia(Tendencia t) {
    final (rotulo, cor) = switch (t.tendencia) {
      'alta' => ('ALTA', Cores.verde),
      'baixa' => ('BAIXA', Cores.vermelho),
      'neutra' => ('NEUTRA', Cores.dourado),
      _ => (t.tendencia.toUpperCase(), Cores.textoSuave),
    };

    Widget chip(String rotulo, String valor, {Color? cor}) => Container(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
          decoration: BoxDecoration(
            color: Cores.superficie2,
            borderRadius: BorderRadius.circular(8),
          ),
          child: Column(
            children: [
              Text(rotulo, style: const TextStyle(fontSize: 9.5, color: Cores.textoSuave)),
              const SizedBox(height: 2),
              Text(valor, style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: cor ?? Cores.texto)),
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
            Row(
              children: [
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(
                    color: cor.withValues(alpha: 0.14),
                    borderRadius: BorderRadius.circular(6),
                  ),
                  child: Text(rotulo, style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w800, color: cor)),
                ),
                const Spacer(),
                Text('BTC ${moeda(t.preco)}', style: const TextStyle(fontSize: 11.5, color: Cores.textoSuave)),
              ],
            ),
            const SizedBox(height: 10),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                SizedBox(width: 96, child: chip('RSI 1h', t.rsi.toStringAsFixed(0))),
                SizedBox(width: 96, child: chip('RSI 4h', t.rsi4h.toStringAsFixed(0))),
                SizedBox(width: 96, child: chip('Medo/Ganância', t.fearGreed?.toString() ?? '—')),
                SizedBox(width: 96, child: chip('ATR', moeda(t.atr))),
                SizedBox(width: 96, child: chip('Salto (ATR)', moeda(t.saltoDinamico))),
                SizedBox(
                  width: 96,
                  child: chip(
                    'MACD hist',
                    t.macdHist == null ? '—' : t.macdHist!.toStringAsFixed(0),
                    cor: (t.macdHist ?? 0) >= 0 ? Cores.verde : Cores.vermelho,
                  ),
                ),
                SizedBox(
                  width: 96,
                  child: chip('%B', t.bollPctB == null ? '—' : t.bollPctB!.toStringAsFixed(2)),
                ),
                SizedBox(
                  width: 96,
                  child: chip('Fator C/V', '${t.fatorCompra.toStringAsFixed(1)}/${t.fatorVenda.toStringAsFixed(1)}'),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Text(
              'A mesma análise que o bot usa pra operar (EMA9/MA21, RSI, MACD, '
              'Bollinger e tendência 4h) — atualizada a cada 60s.',
              style: const TextStyle(fontSize: 10.5, color: Cores.textoSuave, height: 1.4),
            ),
          ],
        ),
      ),
    );
  }

  /// Uma transferência direta (depósito/saque fora do fluxo de investidores).
  Widget _cardTransferencia(TransferenciaDireta t) {
    final dep = t.ehDeposito;
    final cor = dep ? Cores.verde : Cores.vermelho;
    final data = t.aplicadaEm?.toLocal();
    final dataFmt = data == null
        ? '—'
        : '${data.day.toString().padLeft(2, '0')}/${data.month.toString().padLeft(2, '0')}/${data.year} '
            '${data.hour.toString().padLeft(2, '0')}:${data.minute.toString().padLeft(2, '0')}';

    return Card(
      margin: const EdgeInsets.only(bottom: 6),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        child: Row(
          children: [
            Icon(dep ? Icons.south_west : Icons.north_east, size: 18, color: cor),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(dep ? 'Depósito direto' : 'Saque direto',
                      style: const TextStyle(fontWeight: FontWeight.w600)),
                  Text(dataFmt, style: const TextStyle(fontSize: 11, color: Cores.textoSuave)),
                ],
              ),
            ),
            Text(
              '${dep ? '+' : '−'} ${t.amount.toStringAsFixed(5)} ${t.coin}',
              style: TextStyle(fontWeight: FontWeight.w600, color: cor),
            ),
          ],
        ),
      ),
    );
  }
}
