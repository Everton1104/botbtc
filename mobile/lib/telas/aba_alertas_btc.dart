// ─────────────────────────────────────────────────────────────────────────────
// ABA ALERTAS DE PREÇO — "avisa quando o BTC chegar a X".
//
// O admin cria um alerta (valor + acima/abaixo); o ciclo do bot (≤1 min)
// confere os pendentes contra o ticker e manda UM push quando o preço
// cruza o alvo. Depois disso o alerta fica aqui como histórico, apagável.
// — o mesmo padrão das outras abas: carrega, trata 401, puxão atualiza.
// ─────────────────────────────────────────────────────────────────────────────

import 'package:firebase_messaging/firebase_messaging.dart'
    show AuthorizationStatus;
import 'package:flutter/material.dart';

import '../modelos/admin.dart';
import '../servicos/api.dart';
import '../servicos/notificacoes.dart' show pedirPermissaoPush, statusPermissaoPush;
import '../tema.dart';
import '../util/formatar.dart';
import 'tela_login.dart';

class AbaAlertasBtc extends StatefulWidget {
  const AbaAlertasBtc({super.key});

  @override
  State<AbaAlertasBtc> createState() => _AbaAlertasBtcState();
}

class _AbaAlertasBtcState extends State<AbaAlertasBtc> {
  AlertasBtc? _dados;
  bool _carregando = true;
  String? _erro;

  final _ctrAlvo = TextEditingController();
  bool _acima = true; // true = "acima de", false = "abaixo de"
  bool _criando = false;
  int? _excluindoId; // trash com spinner do alerta sendo apagado

  // Preferência "avisos de compra e venda" (vem no /api/me). null = ainda
  // não chegou → o card do toggle não renderiza (nada de switch mentiroso).
  bool? _pushTrades;
  bool _salvandoPush = false;

  // Toggle GERAL de notificações (vem no /api/me) + permissão do ANDROID
  // (o app não pede mais no boot: a permissão é pedida no ato do desejo,
  // quando o usuário liga este switch). null = ainda não carregou.
  bool? _pushAtivo;
  AuthorizationStatus? _permissaoAndroid; // firebase_messaging
  bool _salvandoGeral = false;

  @override
  void initState() {
    super.initState();
    _carregar();
    _carregarPreferenciaPush();
  }

  @override
  void dispose() {
    _ctrAlvo.dispose();
    super.dispose();
  }

  /// LIGA/DESLIGA o toggle geral. Ao LIGAR, se o Android ainda não deu
  /// permissão de notificação, pede AQUI — o desejo explícito do usuário é
  /// o momento certo do diálogo (nunca mais no boot).
  Future<void> _alternarPushAtivo(bool ativo) async {
    setState(() => _salvandoGeral = true);
    try {
      final msg = await ApiService.salvarPushAtivo(ativo);
      if (mounted) setState(() => _pushAtivo = ativo);
      _aviso(msg);

      // Desejo expresso + aparelho sem permissão = hora do diálogo do Android.
      if (ativo && _permissaoAndroid == AuthorizationStatus.denied) {
        final status = await pedirPermissaoPush();
        if (mounted) setState(() => _permissaoAndroid = status);
        if (status != AuthorizationStatus.authorized) {
          _aviso('Ative as notificações do BotBTC nas configurações do aparelho.');
        }
      }
    } on ApiException catch (e) {
      _aviso(e.mensagem, erro: true);
    } catch (_) {
      _aviso('Não foi possível conectar ao servidor.', erro: true);
    } finally {
      if (mounted) setState(() => _salvandoGeral = false);
    }
  }

  Future<void> _carregar({bool silencioso = false}) async {
    if (!silencioso && _dados == null) setState(() => _carregando = true);

    try {
      final dados = await ApiService.alertasBtc();
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

  /// Re-pede a permissão do Android (toque no aviso discreto do card geral).
  /// Enquanto não estiver "não perguntar de novo", o diálogo aparece; depois
  /// disso, só nas configurações do aparelho.
  Future<void> _alternarPermissaoAndroid() async {
    final status = await pedirPermissaoPush();
    if (mounted) setState(() => _permissaoAndroid = status);
    if (status == AuthorizationStatus.authorized) {
      _aviso('Notificações do aparelho ativadas.');
    } else {
      _aviso('Ative o BotBTC nas configurações de notificação do aparelho.');
    }
  }

  /// Busca o estado dos toggles de push (campos push_ativo/push_trades do
  /// /api/me) e a permissão de notificação deste aparelho. Silencioso de
  /// propósito: se falhar, a aba segue só com os alertas.
  Future<void> _carregarPreferenciaPush() async {
    try {
      final u = await ApiService.me();
      if (!mounted) return;
      setState(() {
        _pushTrades = u.pushTrades;
        _pushAtivo = u.pushAtivo;
      });
      _permissaoAndroid = await statusPermissaoPush();
      if (mounted) setState(() {});
    } catch (_) {}
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

  void _aviso(String mensagem, {bool erro = false}) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(mensagem),
        backgroundColor: erro ? Cores.vermelho : Cores.verde,
      ),
    );
  }

  // ── Criar alerta ───────────────────────────────────────────────────────────

  Future<void> _criar() async {
    // Aceita "600000", "600.000,50" e "600000.50" (teclado numérico + colar).
    final alvo = double.tryParse(
          _ctrAlvo.text.replaceAll('.', '').replaceAll(',', '.'),
        ) ??
        double.tryParse(_ctrAlvo.text);
    if (alvo == null || alvo < 1000) {
      _aviso('Digite um preço válido (ex.: 600000).', erro: true);
      return;
    }

    setState(() => _criando = true);
    try {
      final msg = await ApiService.criarAlertaBtc(alvo: alvo, acima: _acima);
      _ctrAlvo.clear();
      _aviso(msg);
      await _carregar(silencioso: true);
    } on ApiException catch (e) {
      _aviso(e.mensagem, erro: true);
    } catch (_) {
      _aviso('Não foi possível conectar ao servidor.', erro: true);
    } finally {
      if (mounted) setState(() => _criando = false);
    }
  }

  // ── Toggle de avisos de compra e venda ─────────────────────────────────────

  /// LIGA/DESLIGA o push de ordem executada. O servidor guarda a preferência
  /// e para de ENVIAR o push de trade — silêncio na origem (com o app fechado
  /// é o Android quem desenha a notificação, filtrar aqui não adiantaria).
  /// Erro → o switch volta pro estado de antes (nunca fica mentindo na tela).
  Future<void> _alternarPushTrades(bool ativo) async {
    setState(() => _salvandoPush = true);
    try {
      final msg = await ApiService.salvarPushTrades(ativo);
      if (mounted) setState(() => _pushTrades = ativo);
      _aviso(msg);
    } on ApiException catch (e) {
      _aviso(e.mensagem, erro: true);
    } catch (_) {
      _aviso('Não foi possível conectar ao servidor.', erro: true);
    } finally {
      if (mounted) setState(() => _salvandoPush = false);
    }
  }

  // ── Excluir alerta ─────────────────────────────────────────────────────────

  Future<void> _excluir(AlertaBtc alerta) async {
    setState(() => _excluindoId = alerta.id);
    try {
      final msg = await ApiService.excluirAlertaBtc(alerta.id);
      _aviso(msg);
      await _carregar(silencioso: true);
    } on ApiException catch (e) {
      _aviso(e.mensagem, erro: true);
    } catch (_) {
      _aviso('Não foi possível conectar ao servidor.', erro: true);
    } finally {
      if (mounted) setState(() => _excluindoId = null);
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

  Widget _lista(AlertasBtc dados) {
    final preco = dados.preco;

    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 24),
      children: [
        // ── Avisos por push: o controle mora aqui, no topo — é a primeira
        // coisa que quem chega incomodado (ou com fome de avisos) quer achar.
        if (_pushAtivo != null) ...[
          _titulo('Avisos por push', Icons.notifications_active_outlined),

          // Toggle GERAL: false = o servidor não envia NADA (silêncio na
          // origem — todos os tipos, inclusive saque).
          Card(
            margin: const EdgeInsets.only(bottom: 4),
            child: SwitchListTile(
              title: const Text('Notificações',
                  style: TextStyle(fontWeight: FontWeight.w700)),
              subtitle: Text(
                _pushAtivo!
                    ? 'Ligadas — o servidor envia tudo que pertence a você.'
                    : 'Desligadas — nada chega por push enquanto estiver aqui.',
                style: const TextStyle(fontSize: 11.5, height: 1.4),
              ),
              value: _pushAtivo!,
              activeThumbColor: Cores.dourado,
              onChanged: _salvandoGeral ? null : _alternarPushAtivo,
            ),
          ),

          // A permissão do ANDROID é opt-in (o app não pede no boot): se o
          // geral está ligado mas o aparelho bloqueia, um botão discreto
          // resolve — sem card chamando atenção na Home.
          if (_pushAtivo! &&
              _permissaoAndroid == AuthorizationStatus.denied)
            Padding(
              padding: const EdgeInsets.only(bottom: 4, left: 8, right: 8),
              child: Align(
                alignment: Alignment.centerLeft,
                child: TextButton.icon(
                  onPressed: _alternarPermissaoAndroid,
                  style: TextButton.styleFrom(
                    foregroundColor: Cores.textoSuave,
                    textStyle: const TextStyle(fontSize: 11.5),
                  ),
                  icon: const Icon(Icons.notifications_off_outlined, size: 14),
                  label: const Text('Este aparelho bloqueia notificações — ativar'),
                ),
              ),
            ),

          // Sub-toggle: só faz sentido com o geral ligado (desabilitado e
          // explicativo quando desligado — nunca um switch mentindo).
          if (_pushTrades != null)
            Card(
              margin: const EdgeInsets.only(bottom: 4),
              child: SwitchListTile(
                title: const Text('Compras e vendas do bot',
                    style: TextStyle(fontWeight: FontWeight.w700)),
                subtitle: Text(
                  !_pushAtivo!
                      ? 'Ligue as notificações primeiro.'
                      : _pushTrades!
                          ? 'Push a cada ordem executada — 🟢 compra, 🔴 venda.'
                          : 'Silêncio nas ordens. Saques, depósitos e alertas de '
                              'preço continuam chegando.',
                  style: const TextStyle(fontSize: 11.5, height: 1.4),
                ),
                value: _pushAtivo! && _pushTrades!,
                activeThumbColor: Cores.dourado,
                // Sem geral ligado, este switch não responde (visual puxa o estado).
                onChanged: _pushAtivo! && !_salvandoPush
                    ? (v) => _alternarPushTrades(v)
                    : null,
              ),
            ),
        ],

        _titulo('Preço do BTC', Icons.query_stats),

        // Preço atual — a referência pra decidir o alvo do alerta
        Card(
          margin: const EdgeInsets.only(bottom: 4),
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text('BTC agora', style: TextStyle(fontSize: 11, color: Cores.textoSuave)),
                const SizedBox(height: 4),
                Text(
                  preco == null ? 'indisponível agora' : moeda(preco),
                  style: TextStyle(
                    fontSize: 24,
                    fontWeight: FontWeight.w800,
                    color: preco == null ? Cores.textoSuave : Cores.dourado,
                  ),
                ),
                const SizedBox(height: 4),
                const Text(
                  'ticker BTCBRL com cache de 45s — puxe para atualizar',
                  style: TextStyle(fontSize: 10.5, color: Cores.textoSuave),
                ),
              ],
            ),
          ),
        ),

        _titulo('Novo alerta', Icons.notifications_active_outlined),

        Card(
          margin: const EdgeInsets.only(bottom: 4),
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'Avisar por push quando o BTC chegar a...',
                  style: TextStyle(fontSize: 11.5, color: Cores.textoSuave),
                ),
                const SizedBox(height: 10),
                SizedBox(
                  width: double.infinity,
                  child: SegmentedButton<bool>(
                    segments: const [
                      ButtonSegment(
                        value: true,
                        icon: Icon(Icons.trending_up, size: 16),
                        label: Text('Acima de'),
                      ),
                      ButtonSegment(
                        value: false,
                        icon: Icon(Icons.trending_down, size: 16),
                        label: Text('Abaixo de'),
                      ),
                    ],
                    selected: {_acima},
                    showSelectedIcon: false,
                    onSelectionChanged: (s) => setState(() => _acima = s.first),
                  ),
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: _ctrAlvo,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w700),
                  decoration: const InputDecoration(
                    isDense: true,
                    prefixText: 'R\$ ',
                    prefixStyle: TextStyle(fontSize: 14, color: Cores.textoSuave),
                    hintText: '600000',
                    counterText: '',
                    border: OutlineInputBorder(),
                  ),
                  maxLength: 12,
                  onSubmitted: (_) => _criando ? null : _criar(),
                ),
                const SizedBox(height: 10),
                SizedBox(
                  width: double.infinity,
                  child: FilledButton.icon(
                    style: FilledButton.styleFrom(backgroundColor: Cores.dourado),
                    onPressed: _criando ? null : _criar,
                    icon: _criando
                        ? const SizedBox(
                            width: 16,
                            height: 16,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(Icons.notifications_active_outlined, size: 18),
                    label: Text(_criando ? 'Criando...' : 'Criar alerta'),
                  ),
                ),
                const SizedBox(height: 6),
                const Text(
                  'O ciclo do bot confere a cada minuto e manda UM push quando '
                  'o preço cruzar o alvo. Alerta já vencido dispara no ciclo seguinte.',
                  style: TextStyle(fontSize: 10.5, color: Cores.textoSuave, height: 1.4),
                ),
              ],
            ),
          ),
        ),

        _titulo('Seus alertas', Icons.list_alt),

        if (dados.alertas.isEmpty)
          const Padding(
            padding: EdgeInsets.symmetric(vertical: 24),
            child: Text(
              'Nenhum alerta ainda — crie o primeiro acima.',
              textAlign: TextAlign.center,
              style: TextStyle(color: Cores.textoSuave, fontSize: 12.5),
            ),
          )
        else
          for (final a in dados.alertas) _cardAlerta(a, preco),
      ],
    );
  }

  /// Um alerta da lista: pendente (dourado, vivo) ou já disparado (suave).
  Widget _cardAlerta(AlertaBtc a, double? preco) {
    final pendente = !a.disparado;
    final cor = a.acima ? Cores.verde : Cores.vermelho;

    // Distância percentual até o alvo (só faz sentido em pendente com preço)
    String? faltam;
    if (pendente && preco != null && preco > 0) {
      final pct = (a.alvo / preco - 1) * 100;
      if (pct.abs() < 0.05) {
        faltam = 'no alvo — dispara no próximo ciclo';
      } else {
        final casas = pct.abs() < 10 ? 1 : 0;
        faltam = 'faltam ${pct.abs().toStringAsFixed(casas)}% '
            '(${a.acima ? 'subir' : 'cair'} até ${moeda(a.alvo)})';
      }
    }

    return Card(
      margin: const EdgeInsets.only(bottom: 6),
      child: ListTile(
        contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 2),
        leading: Icon(
          a.acima ? Icons.trending_up : Icons.trending_down,
          color: pendente ? cor : Cores.textoSuave,
          size: 26,
        ),
        title: Text(
          '${a.acima ? 'Acima de' : 'Abaixo de'} ${moeda(a.alvo)}',
          style: TextStyle(
            fontSize: 13.5,
            fontWeight: FontWeight.w700,
            color: pendente ? null : Cores.textoSuave,
          ),
        ),
        subtitle: Text(
          pendente
              ? (faltam ?? 'aguardando o preço cruzar o alvo')
              : 'disparado ${_dataHora(a.disparadoEm!)}'
                  '${a.precoDisparo != null ? ' · BTC em ${moeda(a.precoDisparo!)}' : ''}',
          style: const TextStyle(fontSize: 11, height: 1.4),
        ),
        trailing: _excluindoId == a.id
            ? const SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(strokeWidth: 2),
              )
            : IconButton(
                icon: const Icon(Icons.delete_outline, size: 20),
                color: Cores.textoSuave,
                onPressed: () => _excluir(a),
              ),
      ),
    );
  }

  /// Título de seção — o mesmo das outras abas.
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

  /// DateTime → "04/11 15:30" (sem depender do pacote intl).
  String _dataHora(DateTime d) =>
      '${d.day.toString().padLeft(2, '0')}/${d.month.toString().padLeft(2, '0')} '
      '${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';
}
