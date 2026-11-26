// ─────────────────────────────────────────────────────────────────────────────
// ABA CONTROLES DO BOT — pausa manual, modo "preparar subida" e o form
// "Configuração do Bot" do site (níveis, alvo BTC/BRL, pisos, RSI, adaptação
// do grid), com os mesmos clamps aplicados no servidor.
// ─────────────────────────────────────────────────────────────────────────────

import 'package:flutter/material.dart';

import '../modelos/admin.dart';
import '../servicos/api.dart';
import '../tema.dart';
import 'tela_login.dart';

class AbaControlesBot extends StatefulWidget {
  const AbaControlesBot({super.key});

  @override
  State<AbaControlesBot> createState() => _AbaControlesBotState();
}

class _AbaControlesBotState extends State<AbaControlesBot> {
  ControlesAdmin? _dados;
  bool _carregando = true;
  String? _erro;
  bool _salvando = false;
  bool _alterando = false; // pausa / modo subida em andamento

  // Controllers do form — preenchidos quando a config chega.
  final _ctrAllin = TextEditingController();
  final _ctrNiveis = List.generate(8, (_) => TextEditingController()); // 1-7 + final
  final _ctrDeltaAlerta = TextEditingController();
  final _ctrDeltaBloqueio = TextEditingController();
  final _ctrPisoAlta = TextEditingController();
  final _ctrPisoBaixa = TextEditingController();
  final _ctrPisoOrdem = TextEditingController();
  final _ctrGuardMeta = TextEditingController();
  final _ctrBoost = TextEditingController();
  final _ctrSpread = TextEditingController();
  final _ctrTaxa = TextEditingController();
  final _ctrRsiMax = TextEditingController();
  final _ctrRsiMin = TextEditingController();
  final _ctrHisterese = TextEditingController();
  final _ctrPersistencia = TextEditingController();
  final _ctrCooldown = TextEditingController();
  final _ctrFixoGatilho = TextEditingController();
  final _ctrFixoValor = TextEditingController();

  double _alvoBtc = 50; // slider 5-95
  bool _subidaAuto = false;
  bool _camadasAtr = false;

  /// Estratégia de grid ativa na config carregada ('fixo' vs 'atr').
  bool get _ehFixo => _dados?.config.estrategiaGrid == 'fixo';

  @override
  void initState() {
    super.initState();
    _carregar();
  }

  @override
  void dispose() {
    for (final c in _ctrNiveis) {
      c.dispose();
    }
    _ctrAllin.dispose();
    _ctrDeltaAlerta.dispose();
    _ctrDeltaBloqueio.dispose();
    _ctrPisoAlta.dispose();
    _ctrPisoBaixa.dispose();
    _ctrPisoOrdem.dispose();
    _ctrGuardMeta.dispose();
    _ctrBoost.dispose();
    _ctrSpread.dispose();
    _ctrTaxa.dispose();
    _ctrRsiMax.dispose();
    _ctrRsiMin.dispose();
    _ctrHisterese.dispose();
    _ctrPersistencia.dispose();
    _ctrCooldown.dispose();
    _ctrFixoGatilho.dispose();
    _ctrFixoValor.dispose();
    super.dispose();
  }

  Future<void> _carregar({bool silencioso = false}) async {
    if (!silencioso && _dados == null) setState(() => _carregando = true);

    try {
      final dados = await ApiService.controlesAdmin();
      if (!mounted) return;
      setState(() {
        _dados = dados;
        _erro = null;
        _carregando = false;
        _preencherForm(dados.config);
      });
    } on ApiException catch (e) {
      if (e.status == 401) return _tokenInvalido();
      _falhou(e.mensagem, silencioso: silencioso);
    } catch (_) {
      _falhou('Não foi possível conectar ao servidor.', silencioso: silencioso);
    }
  }

  /// Preenche os controllers com a config atual (uma única vez por carga).
  void _preencherForm(ConfigBot c) {
    _ctrAllin.text = c.allinThreshold.toString();
    for (var i = 0; i < 7; i++) {
      _ctrNiveis[i].text = _semZeros([c.nivel1, c.nivel2, c.nivel3, c.nivel4, c.nivel5, c.nivel6, c.nivel7][i]);
    }
    _ctrNiveis[7].text = _semZeros(c.nivelFinal);
    _ctrDeltaAlerta.text = _semZeros(c.deltaAlertaPct);
    _ctrDeltaBloqueio.text = _semZeros(c.deltaBloqueioPct);
    _ctrPisoAlta.text = _semZeros(c.pisoAltaDeltaPct);
    _ctrPisoBaixa.text = _semZeros(c.pisoBaixaDeltaPct);
    _ctrPisoOrdem.text = _semZeros(c.pisoOrdemPct);
    _ctrBoost.text = _semZeros(c.boostPct);
    _ctrGuardMeta.text = _semZeros(c.guardMetaPct);
    _ctrSpread.text = _semZeros(c.spreadMinimoPct);
    _ctrTaxa.text = _semZeros(c.taxaTotalPct);
    _ctrRsiMax.text = _semZeros(c.rsiMaximoCompra);
    _ctrRsiMin.text = _semZeros(c.rsiMinimoVenda);
    _ctrHisterese.text = _semZeros(c.adaptHisteresePct);
    _ctrPersistencia.text = c.adaptPersistenciaMin.toString();
    _ctrCooldown.text = c.adaptCooldownMin.toString();
    _alvoBtc = c.targetBtcPct.roundToDouble();
    _subidaAuto = c.modoSubidaAutoHabilitado;
    _camadasAtr = c.camadasAtrHabilitado;
    _ctrFixoGatilho.text = _semZeros(c.fixoGatilhoPct);
    _ctrFixoValor.text = c.fixoValorOrdem.round().toString();
  }

  /// 0.2 → "0.2" (o toString do Dart já limpa; garantia contra "0.20000").
  String _semZeros(double v) {
    final s = v.toString();
    return s.endsWith('.0') ? s.substring(0, s.length - 2) : s;
  }

  double _lerDouble(TextEditingController c, double padrao) =>
      double.tryParse(c.text.replaceAll(',', '.')) ?? padrao;

  int _lerInt(TextEditingController c, int padrao) =>
      int.tryParse(c.text.trim()) ?? padrao;

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

  void _aviso(String mensagem, {bool erro = false}) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(mensagem),
        backgroundColor: erro ? Cores.vermelho : Cores.verde,
      ),
    );
  }

  // ── Ações de estado (pausa / modo subida) ─────────────────────────────────

  /// PAUSAR/DESPAUSAR com confirmação — pausar cancela TODAS as ordens na hora.
  Future<void> _alternarPausa(bool ativo) async {
    final estado = _dados?.estado;
    if (estado == null) return;

    // Pausar pede confirmação (cancela ordens de verdade); despausar não.
    if (ativo) {
      final ok = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('Pausar o bot'),
          content: const Text(
            'Pausar o bot agora?\n\nTODAS as ordens abertas serão canceladas '
            'na hora e o bot fica congelado — não recria o par até você '
            'despausar.',
          ),
          actions: [
            TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: const Text('Voltar')),
            FilledButton(
              style: FilledButton.styleFrom(backgroundColor: Cores.vermelho),
              onPressed: () => Navigator.of(ctx).pop(true),
              child: const Text('Pausar'),
            ),
          ],
        ),
      );
      if (ok != true) return;
    }

    setState(() => _alterando = true);
    try {
      final msg = await ApiService.pausarBot(ativo);
      _aviso(msg);
      await _carregar(silencioso: true);
    } on ApiException catch (e) {
      _aviso(e.mensagem, erro: true);
    } finally {
      if (mounted) setState(() => _alterando = false);
    }
  }

  /// MODO "PREPARAR SUBIDA": inibe vendas (só compra) — o gatilho manual do
  /// painel do site.
  Future<void> _alternarSubida(bool ativo) async {
    setState(() => _alterando = true);
    try {
      final msg = await ApiService.alternarModoSubida(ativo);
      _aviso(msg);
      await _carregar(silencioso: true);
    } on ApiException catch (e) {
      _aviso(e.mensagem, erro: true);
    } finally {
      if (mounted) setState(() => _alterando = false);
    }
  }

  // ── Salvar config ─────────────────────────────────────────────────────────

  Future<void> _salvar() async {
    final atual = _dados?.config;
    if (atual == null) return;

    setState(() => _salvando = true);
    try {
      // Monta a config nova a partir do form; campos não editáveis no app
      // (min_notional) vêm do que já estava salvo.
      final nova = ConfigBot(
        nivel1: _lerDouble(_ctrNiveis[0], atual.nivel1),
        nivel2: _lerDouble(_ctrNiveis[1], atual.nivel2),
        nivel3: _lerDouble(_ctrNiveis[2], atual.nivel3),
        nivel4: _lerDouble(_ctrNiveis[3], atual.nivel4),
        nivel5: _lerDouble(_ctrNiveis[4], atual.nivel5),
        nivel6: _lerDouble(_ctrNiveis[5], atual.nivel6),
        nivel7: _lerDouble(_ctrNiveis[6], atual.nivel7),
        nivelFinal: _lerDouble(_ctrNiveis[7], atual.nivelFinal),
        allinThreshold: _lerInt(_ctrAllin, atual.allinThreshold),
        minNotional: atual.minNotional,
        pisoOrdemPct: _lerDouble(_ctrPisoOrdem, atual.pisoOrdemPct),
        boostPct: _lerDouble(_ctrBoost, atual.boostPct),
        targetBtcPct: _alvoBtc,
        deltaAlertaPct: _lerDouble(_ctrDeltaAlerta, atual.deltaAlertaPct),
        deltaBloqueioPct: _lerDouble(_ctrDeltaBloqueio, atual.deltaBloqueioPct),
        pisoAltaDeltaPct: _lerDouble(_ctrPisoAlta, atual.pisoAltaDeltaPct),
        pisoBaixaDeltaPct: _lerDouble(_ctrPisoBaixa, atual.pisoBaixaDeltaPct),
        guardMetaPct: _lerDouble(_ctrGuardMeta, atual.guardMetaPct),
        modoSubidaAutoHabilitado: _subidaAuto,
        spreadMinimoPct: _lerDouble(_ctrSpread, atual.spreadMinimoPct),
        taxaTotalPct: _lerDouble(_ctrTaxa, atual.taxaTotalPct),
        camadasAtrHabilitado: _camadasAtr,
        rsiMaximoCompra: _lerDouble(_ctrRsiMax, atual.rsiMaximoCompra),
        rsiMinimoVenda: _lerDouble(_ctrRsiMin, atual.rsiMinimoVenda),
        adaptHisteresePct: _lerDouble(_ctrHisterese, atual.adaptHisteresePct),
        adaptCooldownMin: _lerInt(_ctrCooldown, atual.adaptCooldownMin),
        adaptPersistenciaMin: _lerInt(_ctrPersistencia, atual.adaptPersistenciaMin),
        // O form geral NÃO troca a estratégia — viaja o estado atual; os
        // parâmetros do fixo saem dos inputs do card (se o admin editou lá).
        estrategiaGrid: atual.estrategiaGrid,
        fixoGatilhoPct: _lerDouble(_ctrFixoGatilho, atual.fixoGatilhoPct),
        fixoValorOrdem: _lerDouble(_ctrFixoValor, atual.fixoValorOrdem),
        fixoEsperaMin: atual.fixoEsperaMin,
      );

      final msg = await ApiService.salvarConfigBot(nova);
      _aviso(msg);
      await _carregar(silencioso: true);
    } on ApiException catch (e) {
      _aviso(e.mensagem, erro: true);
    } finally {
      if (mounted) setState(() => _salvando = false);
    }
  }

  // ── Estratégia do grid (ATR × Spread fixo) ────────────────────────────────

  /// Alterna ATR ↔ Spread fixo com confirmação — a troca cancela as ordens
  /// abertas na hora; o próximo ciclo (≤1 min) cria o par no formato novo.
  Future<void> _alternarEstrategia(bool ativo) async {
    final atual = _dados?.config;
    if (atual == null) return;

    final indoFixo = ativo; // switch ligado = spread fixo
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(indoFixo ? 'Ativar spread fixo' : 'Reativar ATR dinâmico'),
        content: Text(
          indoFixo
              ? 'DESATIVAR O ATR e ligar o SPREAD FIXO?\n\n'
                  'As ordens abertas serão canceladas e o próximo ciclo (≤1 min) '
                  'cria o par fixo (gatilho e valor atuais).\n'
                  'Proteções de tendência, níveis progressivos e modo subida '
                  'deixam de valer enquanto o fixo estiver ativo.'
              : 'REATIVAR O ATR DINÂMICO?\n\n'
                  'As ordens do spread fixo serão canceladas e o próximo ciclo '
                  '(≤1 min) recria o grid com salto por volatilidade, níveis e '
                  'proteções de sempre.',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: const Text('Voltar')),
          FilledButton(
            onPressed: () => Navigator.of(ctx).pop(true),
            child: const Text('Confirmar'),
          ),
        ],
      ),
    );
    if (ok != true) return;

    setState(() => _alterando = true);
    try {
      final msg = await ApiService.salvarConfigBot(
        atual.comFixo(estrategia: indoFixo ? 'fixo' : 'atr'),
      );
      _aviso(msg);
      await _carregar(silencioso: true);
    } on ApiException catch (e) {
      _aviso(e.mensagem, erro: true);
    } catch (_) {
      _aviso('Não foi possível alternar a estratégia.', erro: true);
    } finally {
      if (mounted) setState(() => _alterando = false);
    }
  }

  /// Aplica gatilho + valor da ordem do spread fixo. Com o fixo ATIVO, o
  /// servidor recria o par na hora (ritual do boost); inativo, valem pra
  /// próxima ativação.
  Future<void> _aplicarFixo() async {
    final atual = _dados?.config;
    if (atual == null) return;

    final gatilho = _lerDouble(_ctrFixoGatilho, atual.fixoGatilhoPct);
    final valor = _lerDouble(_ctrFixoValor, atual.fixoValorOrdem);
    if (gatilho < 0.15 || valor < 50) {
      _aviso('Faixa: gatilho ≥ 0,15% · ordem ≥ R\$ 50.', erro: true);
      return;
    }

    setState(() => _salvando = true);
    try {
      final msg = await ApiService.salvarConfigBot(
        atual.comFixo(gatilho: gatilho, valor: valor),
      );
      _aviso(msg);
      await _carregar(silencioso: true);
    } on ApiException catch (e) {
      _aviso(e.mensagem, erro: true);
    } finally {
      if (mounted) setState(() => _salvando = false);
    }
  }

  // ── Reset da base de performance ──────────────────────────────────────────

  Future<void> _resetarBase() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Resetar base de performance'),
        content: const Text(
          'A variação "desde a base" volta a zero e o próximo ciclo do bot '
          '(≤1 min) fotografia a base nova com os saldos atuais.\n\n'
          'Útil após saque/depósito grande que distorce a leitura.',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: const Text('Voltar')),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Cores.vermelho),
            onPressed: () => Navigator.of(ctx).pop(true),
            child: const Text('Resetar'),
          ),
        ],
      ),
    );
    if (ok != true) return;

    try {
      _aviso(await ApiService.resetarPerformance());
    } on ApiException catch (e) {
      _aviso(e.mensagem, erro: true);
    }
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

    return RefreshIndicator(
      onRefresh: () => _carregar(),
      child: corpo,
    );
  }

  Widget _lista(ControlesAdmin dados) {
    final e = dados.estado;

    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 24),
      children: [
        _titulo('Estado do bot', Icons.power_settings_new),

        // Pausa manual
        Card(
          margin: const EdgeInsets.only(bottom: 8),
          child: SwitchListTile(
            title: const Text('Pausa manual', style: TextStyle(fontWeight: FontWeight.w700)),
            subtitle: Text(
              e.pausadoManual
                  ? 'Bot CONGELADO e ordens canceladas — não opera até despausar.'
                  : 'Pausar cancela TODAS as ordens na hora e congela o bot.',
              style: const TextStyle(fontSize: 11.5, height: 1.4),
            ),
            value: e.pausadoManual,
            activeThumbColor: Cores.dourado,
            onChanged: _alterando ? null : _alternarPausa,
          ),
        ),

        // Modo preparar subida
        Card(
          margin: const EdgeInsets.only(bottom: 8),
          child: SwitchListTile(
            title: const Text('Modo "preparar subida"', style: TextStyle(fontWeight: FontWeight.w700)),
            subtitle: Text(
              e.modoSubida
                  ? 'ATIVO — vendas inibidas (só compra) pra segurar alta.'
                  : 'Inibe as vendas (só compra) até você desligar.',
              style: const TextStyle(fontSize: 11.5, height: 1.4),
            ),
            value: e.modoSubida,
            activeThumbColor: Cores.dourado,
            onChanged: _alterando ? null : _alternarSubida,
          ),
        ),

        // Reset da base de performance
        Card(
          margin: EdgeInsets.zero,
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Row(
                  children: [
                    Icon(Icons.restart_alt, size: 18, color: Cores.textoSuave),
                    SizedBox(width: 8),
                    Expanded(
                      child: Text('Base de performance',
                          style: TextStyle(fontWeight: FontWeight.w700)),
                    ),
                  ],
                ),
                const SizedBox(height: 4),
                const Text(
                  'Zerar a base faz o próximo ciclo do bot fotografar os saldos '
                  'atuais como ponto zero da variação.',
                  style: TextStyle(fontSize: 11.5, color: Cores.textoSuave, height: 1.4),
                ),
                const SizedBox(height: 8),
                Align(
                  alignment: Alignment.centerLeft,
                  child: OutlinedButton.icon(
                    style: OutlinedButton.styleFrom(
                      foregroundColor: Cores.vermelho,
                      side: const BorderSide(color: Cores.vermelho),
                    ),
                    onPressed: _resetarBase,
                    icon: const Icon(Icons.restart_alt, size: 16),
                    label: const Text('Resetar base', style: TextStyle(fontSize: 12.5)),
                  ),
                ),
              ],
            ),
          ),
        ),

        // Estratégia do grid: ATR × Spread fixo (reversível)
        Card(
          margin: const EdgeInsets.only(bottom: 8),
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    const Icon(Icons.swap_horiz, size: 18, color: Cores.textoSuave),
                    const SizedBox(width: 8),
                    const Expanded(
                      child: Text('Estratégia do grid', style: TextStyle(fontWeight: FontWeight.w700)),
                    ),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                      decoration: BoxDecoration(
                        color: _ehFixo ? Cores.dourado : Cores.verde,
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: Text(
                        _ehFixo ? 'SPREAD FIXO' : 'ATR DINÂMICO',
                        style: const TextStyle(fontSize: 10, fontWeight: FontWeight.w700, color: Colors.black),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 4),
                const Text(
                  'Spread fixo: ordens de valor fixo a ±gatilho% da base, sem ATR '
                  'nem níveis. A troca cancela as ordens abertas na hora; o próximo '
                  'ciclo (≤1 min) cria o par no formato novo.',
                  style: TextStyle(fontSize: 11.5, color: Cores.textoSuave, height: 1.4),
                ),
                SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  dense: true,
                  title: Text(
                    _ehFixo ? 'Spread fixo ativo' : 'ATR dinâmico ativo',
                    style: const TextStyle(fontSize: 13),
                  ),
                  subtitle: Text(
                    _ehFixo
                        ? 'Gatilho ±${_semZeros(_dados!.config.fixoGatilhoPct)}% · ordens de R\$ ${_dados!.config.fixoValorOrdem.round()}'
                        : 'Grid com salto por volatilidade e proteções.',
                    style: const TextStyle(fontSize: 11, color: Cores.textoSuave),
                  ),
                  value: _ehFixo,
                  activeThumbColor: Cores.dourado,
                  onChanged: _alterando ? null : _alternarEstrategia,
                ),
                const Divider(height: 18),
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(child: _campo('Gatilho por perna (%)', _ctrFixoGatilho, '%')),
                    const SizedBox(width: 10),
                    Expanded(child: _campo('Valor da ordem (R\$)', _ctrFixoValor, 'R\$')),
                  ],
                ),
                const SizedBox(height: 4),
                const Text(
                  'Faixa do gatilho: 0,15–5% · faixa da ordem: R\$ 50–50.000 '
                  '(limitada ao saldo de cada perna). Mudou com o fixo ativo: o par '
                  'é recriado em ≤1 min.',
                  style: TextStyle(fontSize: 10.5, color: Cores.textoSuave, height: 1.4),
                ),
                const SizedBox(height: 8),
                Align(
                  alignment: Alignment.centerLeft,
                  child: OutlinedButton.icon(
                    onPressed: _salvando ? null : _aplicarFixo,
                    icon: const Icon(Icons.check, size: 16),
                    label: const Text('Aplicar parâmetros', style: TextStyle(fontSize: 12.5)),
                  ),
                ),
              ],
            ),
          ),
        ),

        _titulo('Configuração do Bot', Icons.tune),

        // All-in
        _cardSecao(
          descricao: 'All-in a partir do nível (5–50) — acima do nível 7 usa o '
              'Nível 8+ até atingir o all-in · all-in cap: 95%.',
          children: [
            _campo('All-in a partir do nível', _ctrAllin, 'nív.'),
          ],
        ),

        // Níveis
        _cardSecao(
          titulo: 'Percentual por nível (0.01 – 1.00)',
          children: [
            for (var n = 0; n < 8; n++)
              _campo(n < 7 ? 'Nível ${n + 1}' : 'Nível 8+ (final)', _ctrNiveis[n], '%×'),
          ],
        ),

        // Alvo de alocação
        _cardSecao(
          titulo: 'Alocação patrimonial — alvo BTC/BRL',
          descricao: 'Mais BTC = mais agressivo (50/50 equilibrado · 95/5 '
              'exposição máxima ao preço). BRL é sempre 100−BTC.',
          children: [
            Row(
              children: [
                Expanded(
                  child: Slider(
                    value: _alvoBtc.clamp(5, 95),
                    min: 5,
                    max: 95,
                    divisions: 18,
                    activeColor: Cores.dourado,
                    label: '${_alvoBtc.round()}% BTC',
                    onChanged: (v) => setState(() => _alvoBtc = v),
                  ),
                ),
                SizedBox(
                  width: 108,
                  child: Text(
                    '${_alvoBtc.round()}% BTC · ${(100 - _alvoBtc.round())}% BRL',
                    textAlign: TextAlign.center,
                    style: const TextStyle(fontSize: 11.5, fontWeight: FontWeight.w700, color: Cores.dourado),
                  ),
                ),
              ],
            ),
            _campo('Alerta a ±X pts do alvo', _ctrDeltaAlerta, 'pts'),
            _campo('Bloqueio a ±Y pts do alvo', _ctrDeltaBloqueio, 'pts'),
          ],
        ),

        // Pisos de tendência forte
        _cardSecao(
          titulo: 'Proteção de tendência forte — pisos (pts abaixo do alvo)',
          descricao: 'Tendência forte confirmada bloqueia venda abaixo do piso '
              'de BTC na alta e compra abaixo do piso de BRL na baixa (piso '
              'efetivo nunca desce de 5%).',
          children: [
            _campo('Alta forte: piso BTC = alvo −', _ctrPisoAlta, 'pts'),
            _campo('Baixa forte: piso BRL = alvo −', _ctrPisoBaixa, 'pts'),
          ],
        ),

        // Dimensionamento de ordens
        _cardSecao(
          titulo: 'Dimensionamento de ordens',
          descricao: 'Piso: ordem que entra nunca é menor que X% do saldo '
              '(0 desliga). Guard: com o lado de risco abaixo de X% do alvo, o '
              'freio da tendência forte é suavizado de ×0.5 para ×0.8. Boost: '
              'multiplica o valor FINAL das ordens nas duas pernas, depois de '
              'todos os ajustes do bot (ex.: 30 → ordens 30% maiores, inclusive '
              'pisos; 0 = desligado) — trocar o valor cancela as ordens abertas '
              'e o ciclo recria o par no novo tamanho. Limites por cima: 99,5% '
              'do saldo e 95% do estoque.',
          children: [
            _campo('Piso por ordem (% do saldo)', _ctrPisoOrdem, '%'),
            _campo('Guard de meta (% do alvo)', _ctrGuardMeta, '%'),
            _campo('Boost de ordens (%)', _ctrBoost, '%'),
          ],
        ),

        // Spread & extremos
        _cardSecao(
          titulo: 'Spread do grid & extremos',
          children: [
            _campo('Spread mínimo (%)', _ctrSpread, '%'),
            _campo('Taxa total estimada (%)', _ctrTaxa, '%'),
            _campo('RSI máx. p/ comprar', _ctrRsiMax, ''),
            _campo('RSI mín. p/ vender', _ctrRsiMin, ''),
          ],
        ),

        // Adaptação do grid
        _cardSecao(
          titulo: 'Adaptação do grid ao regime (reposiciona mantendo o centro)',
          children: [
            _campo('Divergência mínima (%)', _ctrHisterese, '%'),
            _campo('Persistência (min)', _ctrPersistencia, 'min'),
            _campo('Cooldown do par (min)', _ctrCooldown, 'min'),
          ],
        ),

        // Switches M4/M6
        Card(
          margin: const EdgeInsets.only(bottom: 8),
          child: Column(
            children: [
              SwitchListTile(
                title: const Text('Modo subida automático (M4)', style: TextStyle(fontSize: 13.5)),
                value: _subidaAuto,
                activeThumbColor: Cores.dourado,
                onChanged: (v) => setState(() => _subidaAuto = v),
              ),
              const Divider(height: 1),
              SwitchListTile(
                title: const Text('Camadas de salto por ATR (M6)', style: TextStyle(fontSize: 13.5)),
                value: _camadasAtr,
                activeThumbColor: Cores.dourado,
                onChanged: (v) => setState(() => _camadasAtr = v),
              ),
            ],
          ),
        ),

        const SizedBox(height: 4),
        SizedBox(
          width: double.infinity,
          child: FilledButton.icon(
            onPressed: _salvando ? null : _salvar,
            icon: _salvando
                ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
                : const Icon(Icons.save_outlined, size: 18),
            label: Text(_salvando ? 'Salvando...' : 'Salvar configuração'),
          ),
        ),
        const SizedBox(height: 6),
        const Text(
          'Salto sempre dinâmico (ATR) · valores fora das faixas são ajustados '
          'pelo servidor (os mesmos clamps do site).',
          textAlign: TextAlign.center,
          style: TextStyle(fontSize: 10.5, color: Cores.textoSuave, height: 1.4),
        ),
      ],
    );
  }

  /// Título de seção — o "section-title" do site.
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

  /// Card com campos em grade 2 colunas (como as rows do form do site).
  Widget _cardSecao({String? titulo, String? descricao, required List<Widget> children}) {
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (titulo != null) ...[
              Text(titulo, style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700, color: Cores.texto)),
              const SizedBox(height: 8),
            ],
            Wrap(
              spacing: 10,
              runSpacing: 10,
              children: [
                for (final campo in children)
                  SizedBox(width: (MediaQuery.of(context).size.width - 68) / 2, child: campo),
              ],
            ),
            if (descricao != null) ...[
              const SizedBox(height: 8),
              Text(descricao, style: const TextStyle(fontSize: 10.5, color: Cores.textoSuave, height: 1.45)),
            ],
          ],
        ),
      ),
    );
  }

  /// Um campo numérico do form (label em cima, sufixo à direita).
  Widget _campo(String rotulo, TextEditingController controller, String sufixo) {
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
            decoration: InputDecoration(
              isDense: true,
              contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 10),
              suffixText: sufixo.isEmpty ? null : sufixo,
              suffixStyle: const TextStyle(fontSize: 11, color: Cores.textoSuave),
            ),
          ),
        ),
      ],
    );
  }
}
