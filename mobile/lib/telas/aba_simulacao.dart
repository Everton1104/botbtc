// ─────────────────────────────────────────────────────────────────────────────
// ABA SIMULAÇÃO — o backtest fiel ao BotExecutor (a página /simulacao do
// site): roda a MESMA engine PHP do servidor sobre candles reais (1h+4h,
// Fear & Greed), partindo de um patrimônio inicial em BRL.
//
// Pode levar alguns segundos (klines públicas em série) — o botão vira
// loader e a resposta traz totais + série dia a dia.
// ─────────────────────────────────────────────────────────────────────────────

import 'package:flutter/material.dart';

import '../modelos/admin.dart';
import '../servicos/api.dart';
import '../tema.dart';
import '../util/formatar.dart';
import 'tela_login.dart';

class AbaSimulacao extends StatefulWidget {
  const AbaSimulacao({super.key});

  @override
  State<AbaSimulacao> createState() => _AbaSimulacaoState();
}

class _AbaSimulacaoState extends State<AbaSimulacao> {
  final _ctrDias = TextEditingController(text: '30');
  final _ctrPatrimonio = TextEditingController(text: '1000');

  Simulacao? _resultado;
  bool _rodando = false;
  String? _erroAcao;

  @override
  void dispose() {
    _ctrDias.dispose();
    _ctrPatrimonio.dispose();
    super.dispose();
  }

  Future<void> _rodar() async {
    final dias = int.tryParse(_ctrDias.text.trim()) ?? 30;
    final patrimonio = double.tryParse(_ctrPatrimonio.text.replaceAll(',', '.')) ?? 1000;

    setState(() {
      _rodando = true;
      _erroAcao = null;
    });

    try {
      final r = await ApiService.simulacao(dias: dias, patrimonio: patrimonio);
      if (!mounted) return;
      setState(() => _resultado = r);
    } on ApiException catch (e) {
      if (e.status == 401) return _tokenInvalido();
      if (!mounted) return;
      setState(() => _erroAcao = e.mensagem);
    } catch (_) {
      if (!mounted) return;
      setState(() => _erroAcao = 'Não foi possível conectar ao servidor.');
    } finally {
      if (mounted) setState(() => _rodando = false);
    }
  }

  Future<void> _tokenInvalido() async {
    await ApiService.limparToken();
    if (!mounted) return;
    Navigator.of(context).pushReplacement(MaterialPageRoute(builder: (_) => const TelaLogin()));
  }

  @override
  Widget build(BuildContext context) {
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 24),
      children: [
        Card(
          margin: const EdgeInsets.only(bottom: 8),
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(child: _campo('Dias (15–120)', _ctrDias)),
                    const SizedBox(width: 10),
                    Expanded(child: _campo('Patrimônio inicial (R\$)', _ctrPatrimonio)),
                  ],
                ),
                const SizedBox(height: 10),
                SizedBox(
                  width: double.infinity,
                  child: FilledButton.icon(
                    onPressed: _rodando ? null : _rodar,
                    icon: _rodando
                        ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
                        : const Icon(Icons.play_arrow, size: 18),
                    label: Text(_rodando ? 'Simulando...' : 'Rodar simulação'),
                  ),
                ),
                const SizedBox(height: 6),
                const Text(
                  'Backtest fiel ao bot (engine PHP no servidor, candles 1h/4h '
                  '+ Fear & Greed). Usa a configuração ATUAL salva em Controles.',
                  style: TextStyle(fontSize: 10.5, color: Cores.textoSuave, height: 1.4),
                ),
              ],
            ),
          ),
        ),

        if (_erroAcao != null)
          Card(
            margin: const EdgeInsets.only(bottom: 8),
            color: Cores.vermelho.withValues(alpha: 0.08),
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Text(_erroAcao!, style: const TextStyle(color: Cores.vermelho, fontSize: 12)),
            ),
          ),

        if (_resultado != null) _resultadoWidget(_resultado!),
      ],
    );
  }

  Widget _campo(String rotulo, TextEditingController controller) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(rotulo, style: const TextStyle(fontSize: 10.5, color: Cores.textoSuave)),
        const SizedBox(height: 4),
        SizedBox(
          height: 42,
          child: TextField(
            controller: controller,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            style: const TextStyle(fontSize: 13.5),
            decoration: const InputDecoration(
              isDense: true,
              contentPadding: EdgeInsets.symmetric(horizontal: 10, vertical: 10),
            ),
          ),
        ),
      ],
    );
  }

  Widget _resultadoWidget(Simulacao r) {
    if (r.vazio) {
      return const Card(
        child: Padding(
          padding: EdgeInsets.all(14),
          child: Text('Sem dados suficientes para simular esse período.',
              style: TextStyle(color: Cores.textoSuave)),
        ),
      );
    }

    final (_, lucro) = setaLucro(r.roiPct);
    final cor = lucro && r.roiPct >= 0 ? Cores.verde : Cores.vermelho;
    final variacao = r.patrimonio - r.patrimonioInicial;

    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('${r.dias} dias · ${moeda(r.patrimonioInicial)} iniciais',
                style: const TextStyle(fontSize: 11, color: Cores.textoSuave)),
            const SizedBox(height: 4),
            FittedBox(
              fit: BoxFit.scaleDown,
              child: Text(
                'ROI ${r.roiPct >= 0 ? '+' : ''}${r.roiPct.toStringAsFixed(2)}%',
                style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: cor),
              ),
            ),
            Text(
              '${variacao >= 0 ? '+' : '−'} ${moeda(variacao.abs())} → ${moeda(r.patrimonio)}',
              style: const TextStyle(fontSize: 12, color: Cores.textoSuave),
            ),
            const Divider(height: 16),
            Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text('Trades', style: TextStyle(fontSize: 10, color: Cores.textoSuave)),
                      Text('${r.nTrades}', style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600)),
                      Text('${r.nCompras}C · ${r.nVendas}V · ${r.nAllins} all-in',
                          style: const TextStyle(fontSize: 10, color: Cores.textoSuave)),
                    ],
                  ),
                ),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text('Drawdown máx.', style: TextStyle(fontSize: 10, color: Cores.textoSuave)),
                      Text('${r.drawdownMaxPct.toStringAsFixed(2)}%',
                          style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600, color: Cores.vermelho)),
                    ],
                  ),
                ),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text('Salto médio', style: TextStyle(fontSize: 10, color: Cores.textoSuave)),
                      Text(moeda(r.saltoMedio), style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600)),
                    ],
                  ),
                ),
              ],
            ),
            const Divider(height: 16),
            const Text('Patrimônio dia a dia', style: TextStyle(fontSize: 11, fontWeight: FontWeight.w700)),
            const SizedBox(height: 6),
            ...r.serie.map((p) {
              final (_, positivo) = setaLucro(p.patrimonio - r.patrimonioInicial);
              return Padding(
                padding: const EdgeInsets.only(bottom: 4),
                child: Row(
                  children: [
                    SizedBox(
                      width: 52,
                      child: Text(p.date, style: const TextStyle(fontSize: 11, color: Cores.textoSuave)),
                    ),
                    Expanded(child: Text(moeda(p.patrimonio), style: const TextStyle(fontSize: 11.5, fontWeight: FontWeight.w600))),
                    if (p.fng > 0)
                      Padding(
                        padding: const EdgeInsets.only(right: 8),
                        child: Tooltip(
                          message: 'Fear & Greed do dia',
                          child: Text('F&G ${p.fng}',
                              style: const TextStyle(fontSize: 10, color: Cores.textoSuave)),
                        ),
                      ),
                    Text(
                      '${p.tradesDia} op.',
                      style: TextStyle(fontSize: 10.5, color: positivo ? Cores.verde : Cores.vermelho),
                    ),
                  ],
                ),
              );
            }),
          ],
        ),
      ),
    );
  }
}
