// ─────────────────────────────────────────────────────────────────────────────
// MODELOS DO ADMIN — o espelho Dart do JSON das rotas /api/admin/*.
//
// São os mesmos dados da aba "Mais Informações" do site:
//   • Controles: form da config do bot + estado (pausa manual, modo subida)
//   • Números: lucro 1/7/30d (decomposição grid×oscilação), performance M8,
//     transferências diretas da Binance e a tendência que o bot usa
//   • Simulação: backtest fiel ao BotExecutor (/simulacao do site)
//   • Relatório: P&L realizado mensal (FIFO sobre bot_trades)
//
// Campos DECIMAL do MySQL chegam como STRING — _num aceita os dois.
// ─────────────────────────────────────────────────────────────────────────────

/// Resposta de GET /api/admin/controles — o que abre instantâneo (só banco).
class ControlesAdmin {
  final ConfigBot config;
  final EstadoBot estado;

  const ControlesAdmin({required this.config, required this.estado});

  factory ControlesAdmin.fromJson(Map<String, dynamic> json) => ControlesAdmin(
        config: ConfigBot.fromJson(json['config'] as Map<String, dynamic>),
        estado: EstadoBot.fromJson(json['estado'] as Map<String, dynamic>),
      );
}

/// A linha de bot_config — todos os campos editáveis do form do site.
/// (salto não é editável: sempre dinâmico por ATR, o servidor força 0.)
class ConfigBot {
  final double nivel1, nivel2, nivel3, nivel4, nivel5, nivel6, nivel7, nivelFinal;
  final int allinThreshold;
  final double minNotional;
  final double pisoOrdemPct;
  final double targetBtcPct; // alvo de alocação (BRL é sempre 100−BTC)
  final double deltaAlertaPct, deltaBloqueioPct;
  final double pisoAltaDeltaPct, pisoBaixaDeltaPct;
  final double guardMetaPct;
  final bool modoSubidaAutoHabilitado;
  final double spreadMinimoPct, taxaTotalPct;
  final bool camadasAtrHabilitado;
  final double rsiMaximoCompra, rsiMinimoVenda;
  final double adaptHisteresePct;
  final int adaptCooldownMin, adaptPersistenciaMin;

  const ConfigBot({
    required this.nivel1,
    required this.nivel2,
    required this.nivel3,
    required this.nivel4,
    required this.nivel5,
    required this.nivel6,
    required this.nivel7,
    required this.nivelFinal,
    required this.allinThreshold,
    required this.minNotional,
    required this.pisoOrdemPct,
    required this.targetBtcPct,
    required this.deltaAlertaPct,
    required this.deltaBloqueioPct,
    required this.pisoAltaDeltaPct,
    required this.pisoBaixaDeltaPct,
    required this.guardMetaPct,
    required this.modoSubidaAutoHabilitado,
    required this.spreadMinimoPct,
    required this.taxaTotalPct,
    required this.camadasAtrHabilitado,
    required this.rsiMaximoCompra,
    required this.rsiMinimoVenda,
    required this.adaptHisteresePct,
    required this.adaptCooldownMin,
    required this.adaptPersistenciaMin,
  });

  factory ConfigBot.fromJson(Map<String, dynamic> j) => ConfigBot(
        nivel1: _num(j['nivel1']),
        nivel2: _num(j['nivel2']),
        nivel3: _num(j['nivel3']),
        nivel4: _num(j['nivel4']),
        nivel5: _num(j['nivel5']),
        nivel6: _num(j['nivel6']),
        nivel7: _num(j['nivel7']),
        nivelFinal: _num(j['nivel_final']),
        allinThreshold: (j['allin_threshold'] as num?)?.toInt() ?? 12,
        minNotional: _num(j['min_notional']),
        pisoOrdemPct: _num(j['piso_ordem_pct']),
        targetBtcPct: _num(j['target_btc_pct']),
        deltaAlertaPct: _num(j['delta_alerta_pct']),
        deltaBloqueioPct: _num(j['delta_bloqueio_pct']),
        pisoAltaDeltaPct: _num(j['piso_alta_delta_pct']),
        pisoBaixaDeltaPct: _num(j['piso_baixa_delta_pct']),
        guardMetaPct: _num(j['guard_meta_pct']),
        modoSubidaAutoHabilitado: j['modo_subida_auto_habilitado'] as bool? ?? false,
        spreadMinimoPct: _num(j['spread_minimo_pct']),
        taxaTotalPct: _num(j['taxa_total_pct']),
        camadasAtrHabilitado: j['camadas_atr_habilitado'] as bool? ?? false,
        rsiMaximoCompra: _num(j['rsi_maximo_compra']),
        rsiMinimoVenda: _num(j['rsi_minimo_venda']),
        adaptHisteresePct: _num(j['adapt_histerese_pct']),
        adaptCooldownMin: (j['adapt_cooldown_min'] as num?)?.toInt() ?? 0,
        adaptPersistenciaMin: (j['adapt_persistencia_min'] as num?)?.toInt() ?? 0,
      );

  /// O corpo de POST /api/admin/config — as chaves EXATAS que o servidor lê.
  Map<String, dynamic> paraBody() => {
        'nivel1': nivel1,
        'nivel2': nivel2,
        'nivel3': nivel3,
        'nivel4': nivel4,
        'nivel5': nivel5,
        'nivel6': nivel6,
        'nivel7': nivel7,
        'nivel_final': nivelFinal,
        'allin_threshold': allinThreshold,
        'min_notional': minNotional,
        'piso_ordem_pct': pisoOrdemPct,
        'target_btc_pct': targetBtcPct,
        'delta_alerta_pct': deltaAlertaPct,
        'delta_bloqueio_pct': deltaBloqueioPct,
        'piso_alta_delta_pct': pisoAltaDeltaPct,
        'piso_baixa_delta_pct': pisoBaixaDeltaPct,
        'guard_meta_pct': guardMetaPct,
        'modo_subida_auto_habilitado': modoSubidaAutoHabilitado,
        'spread_minimo_pct': spreadMinimoPct,
        'taxa_total_pct': taxaTotalPct,
        'camadas_atr_habilitado': camadasAtrHabilitado,
        'rsi_maximo_compra': rsiMaximoCompra,
        'rsi_minimo_venda': rsiMinimoVenda,
        'adapt_histerese_pct': adaptHisteresePct,
        'adapt_persistencia_min': adaptPersistenciaMin,
        'adapt_cooldown_min': adaptCooldownMin,
      };
}

/// Flags operacionais do bot (bot_state) + pausa automática dos saques.
class EstadoBot {
  final bool pausadoManual;
  final bool modoSubida;
  final bool modoSubidaAuto;
  final bool botAtivo;
  final bool pausaSaquesPausado;
  final int pausaSaquesSegundos;
  final String? pausaSaquesMotivo;

  const EstadoBot({
    required this.pausadoManual,
    required this.modoSubida,
    required this.modoSubidaAuto,
    required this.botAtivo,
    required this.pausaSaquesPausado,
    required this.pausaSaquesSegundos,
    this.pausaSaquesMotivo,
  });

  factory EstadoBot.fromJson(Map<String, dynamic> j) {
    final pausa = (j['pausa_saques'] as Map<String, dynamic>?) ?? {};
    return EstadoBot(
      pausadoManual: j['pausado_manual'] as bool? ?? false,
      modoSubida: j['modo_subida'] as bool? ?? false,
      modoSubidaAuto: j['modo_subida_auto'] as bool? ?? false,
      botAtivo: j['bot_ativo'] as bool? ?? false,
      pausaSaquesPausado: pausa['pausado'] as bool? ?? false,
      pausaSaquesSegundos: (pausa['segundos'] as num?)?.toInt() ?? 0,
      pausaSaquesMotivo: pausa['motivo'] as String?,
    );
  }
}

/// Resposta de GET /api/admin/numeros.
class NumerosAdmin {
  final PnlJanela pnlHoje, pnlD7, pnlD30;
  final PerformanceBot? performance; // null = Binance não respondeu agora
  final List<TransferenciaDireta> transferencias;
  final Tendencia? tendencia;

  const NumerosAdmin({
    required this.pnlHoje,
    required this.pnlD7,
    required this.pnlD30,
    this.performance,
    required this.transferencias,
    this.tendencia,
  });

  factory NumerosAdmin.fromJson(Map<String, dynamic> j) => NumerosAdmin(
        pnlHoje: PnlJanela.fromJson((j['pnl'] as Map<String, dynamic>)['hoje']),
        pnlD7: PnlJanela.fromJson((j['pnl'] as Map<String, dynamic>)['d7']),
        pnlD30: PnlJanela.fromJson((j['pnl'] as Map<String, dynamic>)['d30']),
        performance: j['performance'] == null
            ? null
            : PerformanceBot.fromJson(j['performance'] as Map<String, dynamic>),
        transferencias: (j['transferencias'] as List? ?? [])
            .map((t) => TransferenciaDireta.fromJson(t as Map<String, dynamic>))
            .toList(),
        tendencia: j['tendencia'] == null
            ? null
            : Tendencia.fromJson(j['tendencia'] as Map<String, dynamic>),
      );
}

/// Decomposição do P&L de uma janela (1/7/30 dias): lucro do grid (ciclos
/// compra→venda) vs oscilação do BTC (variação do estoque).
class PnlJanela {
  final bool vazio;
  final String? periodoInicio, periodoFim;
  final double grid, oscilacao, total, feesBrl, volume;
  final int compras, vendas;
  final double? retornoTotalPct; // % sobre o patrimônio no início da janela

  const PnlJanela({
    required this.vazio,
    this.periodoInicio,
    this.periodoFim,
    required this.grid,
    required this.oscilacao,
    required this.total,
    required this.feesBrl,
    required this.volume,
    required this.compras,
    required this.vendas,
    this.retornoTotalPct,
  });

  factory PnlJanela.fromJson(Map<String, dynamic> j) {
    final periodo = j['periodo'] as Map<String, dynamic>?;
    return PnlJanela(
      vazio: j['vazio'] as bool? ?? false,
      periodoInicio: periodo?['inicio'] as String?,
      periodoFim: periodo?['fim'] as String?,
      grid: _num(j['grid']),
      oscilacao: _num(j['oscilacao']),
      total: _num(j['total']),
      feesBrl: _num(j['fees_brl']),
      volume: _num(j['volume']),
      compras: (j['compras'] as num?)?.toInt() ?? 0,
      vendas: (j['vendas'] as num?)?.toInt() ?? 0,
      retornoTotalPct: j['retorno_total'] == null ? null : _num(j['retorno_total']),
    );
  }
}

/// Painel M8 de performance: base fotografada vs agora, série diária,
/// pico/drawdown, contagem de operações e P&L realizado acumulado.
class PerformanceBot {
  final double? basePatrimonioInicial;
  final String? baseEm;
  final double atualPatrimonio, atualPctBtc, atualPctBrl;
  final double? variacaoPatrimonioPct, variacaoBtcPct, variacaoBrlPct;
  final List<PontoPatrimonio> serie;
  final double picoValor;
  final String picoEm;
  final double drawdownMaxPct;
  final int tradesTotal, tradesCompras, tradesVendas;
  final String? tradesDesde;
  final double pnlRealizadoAcumulado, pnlFeesBrl;

  const PerformanceBot({
    this.basePatrimonioInicial,
    this.baseEm,
    required this.atualPatrimonio,
    required this.atualPctBtc,
    required this.atualPctBrl,
    this.variacaoPatrimonioPct,
    this.variacaoBtcPct,
    this.variacaoBrlPct,
    required this.serie,
    required this.picoValor,
    required this.picoEm,
    required this.drawdownMaxPct,
    required this.tradesTotal,
    required this.tradesCompras,
    required this.tradesVendas,
    this.tradesDesde,
    required this.pnlRealizadoAcumulado,
    required this.pnlFeesBrl,
  });

  factory PerformanceBot.fromJson(Map<String, dynamic> j) {
    final base = (j['base'] as Map<String, dynamic>?) ?? {};
    final atual = j['atual'] as Map<String, dynamic>;
    final pico = j['pico'] as Map<String, dynamic>;
    final trades = j['trades'] as Map<String, dynamic>;
    final pnl = j['pnl'] as Map<String, dynamic>;

    return PerformanceBot(
      basePatrimonioInicial: base['patrimonio_inicial'] == null
          ? null
          : _num(base['patrimonio_inicial']),
      baseEm: base['em'] as String?,
      atualPatrimonio: _num(atual['patrimonio']),
      atualPctBtc: _num(atual['pct_btc']),
      atualPctBrl: _num(atual['pct_brl']),
      variacaoPatrimonioPct: atual['variacao_patrimonio_pct'] == null
          ? null
          : _num(atual['variacao_patrimonio_pct']),
      variacaoBtcPct:
          atual['variacao_btc_pct'] == null ? null : _num(atual['variacao_btc_pct']),
      variacaoBrlPct:
          atual['variacao_brl_pct'] == null ? null : _num(atual['variacao_brl_pct']),
      serie: (j['serie'] as List? ?? [])
          .map((p) => PontoPatrimonio.fromJson(p as Map<String, dynamic>))
          .toList(),
      picoValor: _num(pico['valor']),
      picoEm: pico['em'] as String,
      drawdownMaxPct: _num(j['drawdown_max_pct']),
      tradesTotal: (trades['total'] as num?)?.toInt() ?? 0,
      tradesCompras: (trades['compras'] as num?)?.toInt() ?? 0,
      tradesVendas: (trades['vendas'] as num?)?.toInt() ?? 0,
      tradesDesde: trades['desde'] as String?,
      pnlRealizadoAcumulado: _num(pnl['realizado_acumulado']),
      pnlFeesBrl: _num(pnl['fees_brl']),
    );
  }
}

/// Um ponto da série diária de patrimônio (M8).
class PontoPatrimonio {
  final String dia; // "12/10/2026" ou "agora"
  final double patrimonio;

  const PontoPatrimonio({required this.dia, required this.patrimonio});

  factory PontoPatrimonio.fromJson(Map<String, dynamic> j) => PontoPatrimonio(
        dia: j['dia'] as String,
        patrimonio: _num(j['patrimonio']),
      );
}

/// Depósito/saque DIRETO na Binance (fora do fluxo de investidor), vindo do
/// /sapi/v1/capital pelo cron — a tabela "Transferências Diretas" do site.
class TransferenciaDireta {
  final String tipo; // deposit | withdraw
  final String coin; // BTC | BRL | BNB
  final double amount;
  final DateTime? aplicadaEm;

  const TransferenciaDireta({
    required this.tipo,
    required this.coin,
    required this.amount,
    this.aplicadaEm,
  });

  bool get ehDeposito => tipo == 'deposit';

  factory TransferenciaDireta.fromJson(Map<String, dynamic> j) =>
      TransferenciaDireta(
        tipo: j['transfer_type'] as String? ?? '',
        coin: j['coin'] as String? ?? '',
        amount: _num(j['amount']),
        aplicadaEm: DateTime.tryParse(j['applied_at'] as String? ?? ''),
      );
}

/// A análise de tendência que o PRÓPRIO bot usa pra operar (fonte única:
/// analisarTendencia) — o tile de tendência + indicadores do site.
class Tendencia {
  final String tendencia; // alta | baixa | neutra...
  final double rsi, rsi4h, atr, saltoDinamico;
  final double? macdHist, bollPctB, bollWidth;
  final double distanciaPct, fatorCompra, fatorVenda;
  final int? fearGreed;
  final double preco;

  const Tendencia({
    required this.tendencia,
    required this.rsi,
    required this.rsi4h,
    required this.atr,
    required this.saltoDinamico,
    this.macdHist,
    this.bollPctB,
    this.bollWidth,
    required this.distanciaPct,
    required this.fatorCompra,
    required this.fatorVenda,
    this.fearGreed,
    required this.preco,
  });

  factory Tendencia.fromJson(Map<String, dynamic> j) => Tendencia(
        tendencia: j['tendencia'] as String? ?? '—',
        rsi: _num(j['rsi']),
        rsi4h: _num(j['rsi_4h']),
        atr: _num(j['atr']),
        saltoDinamico: _num(j['salto_dinamico']),
        macdHist: j['macd_hist'] == null ? null : _num(j['macd_hist']),
        bollPctB: j['boll_pct_b'] == null ? null : _num(j['boll_pct_b']),
        bollWidth: j['boll_width'] == null ? null : _num(j['boll_width']),
        distanciaPct: _num(j['distancia_pct']),
        fatorCompra: _num(j['fator_compra']),
        fatorVenda: _num(j['fator_venda']),
        fearGreed: j['fear_greed'] == null ? null : (j['fear_greed'] as num).toInt(),
        preco: _num(j['preco']),
      );
}

/// Resposta de GET /api/admin/simulacao — o backtest (mesma engine do site).
class Simulacao {
  final List<PontoSimulacao> serie;
  final double roiPct, patrimonio, patrimonioInicial, drawdownMaxPct, saltoMedio;
  final int nTrades, nCompras, nVendas, nAllins, dias;
  final bool vazio;

  const Simulacao({
    required this.serie,
    required this.roiPct,
    required this.patrimonio,
    required this.patrimonioInicial,
    required this.drawdownMaxPct,
    required this.saltoMedio,
    required this.nTrades,
    required this.nCompras,
    required this.nVendas,
    required this.nAllins,
    required this.dias,
    required this.vazio,
  });

  factory Simulacao.fromJson(Map<String, dynamic> j) {
    final totals = j['totals'] as Map<String, dynamic>?;
    final config = j['config'] as Map<String, dynamic>?;
    final serie = (j['serie'] as List? ?? [])
        .map((p) => PontoSimulacao.fromJson(p as Map<String, dynamic>))
        .toList();

    if (totals == null || serie.isEmpty) {
      return Simulacao(
        serie: const [],
        roiPct: 0, patrimonio: 0,
        patrimonioInicial: _num(config?['patrimonio_inicial']),
        drawdownMaxPct: 0, saltoMedio: 0,
        nTrades: 0, nCompras: 0, nVendas: 0, nAllins: 0,
        dias: (config?['dias'] as num?)?.toInt() ?? 0,
        vazio: true,
      );
    }

    return Simulacao(
      serie: serie,
      roiPct: _num(totals['roi_pct']),
      patrimonio: _num(totals['patrimonio']),
      patrimonioInicial: _num(config?['patrimonio_inicial']),
      drawdownMaxPct: _num(totals['drawdown_max_pct']),
      saltoMedio: _num(totals['salto_medio']),
      nTrades: (totals['n_trades'] as num?)?.toInt() ?? 0,
      nCompras: (totals['n_compras'] as num?)?.toInt() ?? 0,
      nVendas: (totals['n_vendas'] as num?)?.toInt() ?? 0,
      nAllins: (totals['n_allins'] as num?)?.toInt() ?? 0,
      dias: (config?['dias'] as num?)?.toInt() ?? 0,
      vazio: false,
    );
  }
}

/// Um dia da simulação: fechamento, patrimônio acumulado e trades do dia.
class PontoSimulacao {
  final String date; // "12/10"
  final double close, patrimonio;
  final int tradesDia, salto, fng;

  const PontoSimulacao({
    required this.date,
    required this.close,
    required this.patrimonio,
    required this.tradesDia,
    required this.salto,
    required this.fng,
  });

  factory PontoSimulacao.fromJson(Map<String, dynamic> j) => PontoSimulacao(
        date: j['date'] as String,
        close: _num(j['close']),
        patrimonio: _num(j['patrimonio']),
        tradesDia: (j['trades_dia'] as num?)?.toInt() ?? 0,
        salto: (j['salto'] as num?)?.toInt() ?? 0,
        fng: (j['fng'] as num?)?.toInt() ?? 50,
      );
}

/// Resposta de GET /api/admin/relatorio — P&L realizado mensal (FIFO).
class RelatorioMensal {
  final bool vazio;
  final String mes; // "outubro/2026"
  final double pnl, volume, feesBrl, bruto, drawdownMax, mediaDiaOp;
  final int trades, diasOp, diasTotais;
  final DiaPnl? melhorDia, piorDia;
  final List<PontoRelatorio> serie;
  final double btcAberto, pmAberto;

  const RelatorioMensal({
    required this.vazio,
    required this.mes,
    required this.pnl,
    required this.volume,
    required this.feesBrl,
    required this.bruto,
    required this.drawdownMax,
    required this.mediaDiaOp,
    required this.trades,
    required this.diasOp,
    required this.diasTotais,
    this.melhorDia,
    this.piorDia,
    required this.serie,
    required this.btcAberto,
    required this.pmAberto,
  });

  factory RelatorioMensal.fromJson(Map<String, dynamic> j) {
    if (j['vazio'] as bool? ?? false) {
      return RelatorioMensal(
        vazio: true, mes: j['mes'] as String? ?? '—',
        pnl: 0, volume: 0, feesBrl: 0, bruto: 0, drawdownMax: 0, mediaDiaOp: 0,
        trades: 0, diasOp: 0, diasTotais: 0,
        serie: const [], btcAberto: 0, pmAberto: 0,
      );
    }

    final t = j['totais'] as Map<String, dynamic>;
    final pos = (j['posicao'] as Map<String, dynamic>?) ?? {};

    return RelatorioMensal(
      vazio: false,
      mes: j['mes'] as String? ?? '—',
      pnl: _num(t['pnl']),
      volume: _num(t['volume']),
      feesBrl: _num(t['fees_brl']),
      bruto: _num(t['bruto']),
      drawdownMax: _num(t['drawdown_max']),
      mediaDiaOp: _num(t['media_dia_op']),
      trades: (t['trades'] as num?)?.toInt() ?? 0,
      diasOp: (t['dias_op'] as num?)?.toInt() ?? 0,
      diasTotais: (t['dias_totais'] as num?)?.toInt() ?? 0,
      melhorDia: t['melhor_dia'] == null
          ? null
          : DiaPnl.fromJson(t['melhor_dia'] as Map<String, dynamic>),
      piorDia: t['pior_dia'] == null
          ? null
          : DiaPnl.fromJson(t['pior_dia'] as Map<String, dynamic>),
      serie: (j['serie'] as List? ?? [])
          .map((p) => PontoRelatorio.fromJson(p as Map<String, dynamic>))
          .toList(),
      btcAberto: _num(pos['btc_aberto']),
      pmAberto: _num(pos['pm_aberto']),
    );
  }
}

/// Melhor/pior dia do mês (P&L realizado).
class DiaPnl {
  final String dia; // "2026-10-02"
  final double pnl;

  const DiaPnl({required this.dia, required this.pnl});

  factory DiaPnl.fromJson(Map<String, dynamic> j) =>
      DiaPnl(dia: j['dia'] as String, pnl: _num(j['pnl']));
}

/// Um dia da série do relatório mensal.
class PontoRelatorio {
  final String date; // "02/10"
  final double pnl;
  final int trades;

  const PontoRelatorio({required this.date, required this.pnl, required this.trades});

  factory PontoRelatorio.fromJson(Map<String, dynamic> j) => PontoRelatorio(
        date: j['date'] as String,
        pnl: _num(j['pnl']),
        trades: (j['trades'] as num?)?.toInt() ?? 0,
      );
}

/// Aceita número OU string-numérica (DECIMAL do MySQL) e devolve double.
double _num(dynamic v) {
  if (v is num) return v.toDouble();
  if (v is String) return double.tryParse(v) ?? 0;
  return 0;
}
