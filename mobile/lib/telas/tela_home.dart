// ─────────────────────────────────────────────────────────────────────────────
// TELA HOME — o painel do bot, espelhando a aba "Início" do site.
//
// Conteúdo (por decisão do usuário: só o essencial, sem simulações e sem
// "Mais Informações"):
//   1. Painel do Bot: 4 tiles — Preço BTC, Saldo BRL, Saldo BTC, Total Geral
//   2. Ordens Abertas (badge Compra/Venda, preço, quantidade, total)
//   3. Investidores (só admin): aportado, cotas, participação, valor atual, lucro
//   4. Saques Pendentes (só admin)
//
// ATUALIZAÇÃO: o site recarrega a cada 30s. No app, duas formas:
//   • AUTOMÁTICA — a cada 1 minuto, em SILÊNCIO (a tela não pisca: os dados
//     antigos ficam de pé até os novos chegarem de verdade);
//   • MANUAL — puxar pra baixo (RefreshIndicator), quando você quiser já.
//
// Bateria: o relógio de 1 minuto DESLIGA quando o app vai pro fundo (nada de
// acordar o celular sem ninguém olhando) e religa — com uma atualização
// imediata — quando você volta pro app.
// ─────────────────────────────────────────────────────────────────────────────

import 'dart:async'; // Timer: o "relógio" que dispara a atualização de 1 em 1 min

import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';

import '../modelos/painel.dart';
import '../servicos/api.dart';
import '../servicos/notificacoes.dart';
import '../tema.dart';
import '../util/formatar.dart';
import 'tela_acoes.dart';
import 'tela_admin.dart';
import 'tela_login.dart';

class TelaHome extends StatefulWidget {
  const TelaHome({super.key});

  @override
  State<TelaHome> createState() => _TelaHomeState();
}

class _TelaHomeState extends State<TelaHome> with WidgetsBindingObserver {
  // ── Estado dos dados ─────────────────────────────────────────────────────
  // Painel em tela (null = ainda não carregou nada nesta sessão).
  Painel? _painel;
  // Quem está logado — usado só pro nome no topo (AppBar). null enquanto
  // não chega (o título mostra "BotBTC" nesse meio-tempo).
  Usuario? _usuario;
  // true apenas na PRIMEIRA carga (tela ainda vazia → loader central no lugar).
  bool _carregando = true;
  // Último erro (só vira tela de erro se NÃO houver dados antigos para mostrar).
  String? _erro;

  // ── Relógio da atualização automática ────────────────────────────────────
  Timer? _timerAuto;

  // Permissão de push: null = ainda não consultou. Se o usuário NEGOU na
  // instalação, a Home mostra o lembrete (sem ele, quem nega fica pra
  // sempre sem os avisos de depósito/saque/liberação).
  AuthorizationStatus? _permissaoPush;

  /// Intervalo entre atualizações automáticas (o site usa 30s; 1 min basta
  /// pra um painel e pesa menos no servidor e na bateria).
  static const Duration kIntervaloAuto = Duration(minutes: 1);

  @override
  void initState() {
    super.initState();
    // WidgetsBindingObserver: nos avisa quando o app vai pro fundo/volta.
    // É com isso que o relógio pausa (bateria) e retoma (dados frescos).
    WidgetsBinding.instance.addObserver(this);
    _carregar(); // primeira carga (com loader)
    _carregarUsuario(); // nome do topo (sem await — não segura o painel)
    _checarPermissaoPush(); // lembrete pra quem negou as notificações
    _iniciarTimer(); // liga o relógio de 1 minuto
  }

  /// Consulta a permissão de push UMA vez por sessão — só pra decidir se
  /// o lembrete aparece. Silencioso por natureza.
  Future<void> _checarPermissaoPush() async {
    final status = await statusPermissaoPush();
    if (mounted) setState(() => _permissaoPush = status);
  }

  /// Toque em "Ativar agora": re-pede a permissão (o Android mostra o
  /// diálogo de novo enquanto não estiver "não perguntar de novo").
  Future<void> _pedirPermissaoPush() async {
    final status = await pedirPermissaoPush();
    if (mounted) setState(() => _permissaoPush = status);
  }

  @override
  void dispose() {
    // Boa criação de hábito: quem registra observer/timer no initState
    // DEVOLVE no dispose — a tela nunca é destruída sem limpar a bagunça.
    WidgetsBinding.instance.removeObserver(this);
    _pararTimer();
    super.dispose();
  }

  /// O Android avisou que o ciclo de vida do app mudou.
  @override
  void didChangeAppLifecycleState(AppLifecycleState estado) {
    if (estado == AppLifecycleState.resumed) {
      // Voltou a olhar pro app: atualiza JÁ (não espera o próximo minuto)
      // e garante o relógio ligado.
      _carregar(silencioso: true);
      _iniciarTimer();
    } else if (estado == AppLifecycleState.paused ||
        estado == AppLifecycleState.inactive ||
        estado == AppLifecycleState.hidden) {
      // App saiu de vista: desliga o relógio — nada de rede/bateria
      // gastando sem plateia. O push do Firebase é quem avisa das novidades.
      _pararTimer();
    }
  }

  void _iniciarTimer() {
    // ??= liga só se ainda não estiver ligado (evita dois relógios).
    _timerAuto ??= Timer.periodic(
      kIntervaloAuto,
      (_) => _carregar(silencioso: true),
    );
  }

  void _pararTimer() {
    _timerAuto?.cancel();
    _timerAuto = null;
  }

  /// Busca o usuário logado UMA vez por sessão — só pra saudar no topo.
  /// Se falhar (rede etc.), o título segue "BotBTC": nada crítico aqui.
  Future<void> _carregarUsuario() async {
    try {
      final u = await ApiService.me();
      if (mounted) setState(() => _usuario = u);
    } catch (_) {}
  }

  /// Busca o painel no servidor.
  ///
  /// `silencioso: true` (o relógio de 1 min) NÃO mexe na tela enquanto
  /// espera: os dados atuais continuam de pé e são trocados de uma vez
  /// quando a resposta chega. Erro em modo silencioso com dados na tela
  /// também é engolido — daqui a 1 minuto ele tenta de novo sozinho.
  /// `silencioso: false` (abertura da tela e puxão manual) mostra loader
  /// na primeira vez e SnackBar se falhar com dados na tela.
  Future<void> _carregar({bool silencioso = false}) async {
    if (!silencioso && _painel == null) {
      setState(() => _carregando = true);
    }

    try {
      final painel = await ApiService.painel();
      if (!mounted) return;
      setState(() {
        _painel = painel;
        _erro = null;
        _carregando = false;
      });
    } on ApiException catch (e) {
      // 401 = token revogado: volta pro login nas duas modalidades.
      if (e.status == 401) {
        _tokenInvalido();
        return;
      }
      _falhou(e.mensagem, silencioso: silencioso);
    } catch (_) {
      _falhou('Não foi possível conectar ao servidor.', silencioso: silencioso);
    }
  }

  /// Tratamento comum das falhas de _carregar.
  void _falhou(String mensagem, {required bool silencioso}) {
    if (!mounted) return;

    // Com dados na tela + falha silenciosa: nada muda na tela (os dados de
    // 1 minuto atrás continuam valendo; o próximo tic tenta de novo).
    if (silencioso && _painel != null) return;

    if (_painel != null) {
      // Puxão manual com dados na tela: a lista fica, avisamos num SnackBar
      // (aquela tarja que sobe de baixo e some sozinha).
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(mensagem), backgroundColor: Cores.vermelho),
      );
      return;
    }

    // Primeira carga falhou: aqui sim, tela de erro inteira (com o gesto
    // de puxar pra baixo disponível pra tentar de novo).
    setState(() {
      _erro = mensagem;
      _carregando = false;
    });
  }

  /// Faz logout e volta para a tela de login.
  Future<void> _sair() async {
    await ApiService.logout();

    if (!mounted) return;
    // pushReplacement: troca a Home pela Login — o botão de voltar não pode
    // devolver o usuário para trás do logout.
    Navigator.of(context).pushReplacement(
      MaterialPageRoute(builder: (_) => const TelaLogin()),
    );
  }

  /// Abre a tela "Ações" (saques e depósitos em abas). O `await` espera
  /// ela fechar para então atualizar a Home em silêncio — solicitar saque,
  /// registrar depósito, aprovar... tudo isso muda os números daqui embaixo.
  /// [aba] leva direto à pendência (0 = saques, 1 = depósitos).
  Future<void> _abrirAcoes({int aba = 0}) async {
    await Navigator.of(context).push(
      MaterialPageRoute(builder: (_) => TelaAcoes(abaInicial: aba)),
    );
    if (mounted) _carregar(silencioso: true);
  }

  /// Abre a tela "Admin do Bot" (config, pausa, simulação, relatório) —
  /// só o admin vê o botão. Ao voltar, atualiza: lixeira de cotas e
  /// exclusão de conta mudam a lista de investidores daqui de baixo.
  Future<void> _abrirAdmin() async {
    await Navigator.of(context).push(
      MaterialPageRoute(builder: (_) => const TelaAdmin()),
    );
    if (mounted) _carregar(silencioso: true);
  }

  /// LIXEIRA do investidor (admin): transfere as cotas dele 1:1 pro admin
  /// e remove o registro — a mesma ação do site na tabela de investidores.
  Future<void> _esvaziarCotas(Investidor i) async {
    final prosseguir = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Esvaziar cotas'),
        content: Text(
          i.cotas > 0
              ? 'Transferir ${i.cotas.toStringAsFixed(2)} cotas de ${i.nome} '
                  '(${moeda(i.valorAtual)}) para a SUA conta e remover o registro?\n\n'
                  'A fatia dele no bot passa a ser sua. O aporte histórico '
                  'dele não soma no seu — aparece direto no seu lucro.'
              : 'Remover o registro de investimento de ${i.nome}?\n\n'
                  'Ele não possui cotas (registro zerado).',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: const Text('Voltar')),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Cores.vermelho),
            onPressed: () => Navigator.of(ctx).pop(true),
            child: const Text('Esvaziar'),
          ),
        ],
      ),
    );
    if (prosseguir != true) return;

    try {
      final msg = await ApiService.esvaziarCotas(i.id);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(msg), backgroundColor: Cores.verde),
      );
      _carregar(silencioso: true);
    } on ApiException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.mensagem), backgroundColor: Cores.vermelho),
      );
    }
  }

  /// EXCLUIR a conta de um investidor sem cotas/histórico (admin) — o
  /// servidor é a autoridade e recusa se houver qualquer dinheiro.
  Future<void> _excluirConta(Investidor i) async {
    final prosseguir = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Excluir conta'),
        content: Text(
          'Excluir a CONTA de ${i.nome} (${i.email})?\n\n'
          'Esta ação é irreversível. Só é possível sem cotas, sem depósitos '
          'no histórico e sem saque pendente.',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: const Text('Voltar')),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Cores.vermelho),
            onPressed: () => Navigator.of(ctx).pop(true),
            child: const Text('Excluir'),
          ),
        ],
      ),
    );
    if (prosseguir != true) return;

    try {
      final msg = await ApiService.removerUsuario(i.id);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(msg), backgroundColor: Cores.verde),
      );
      _carregar(silencioso: true);
    } on ApiException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.mensagem), backgroundColor: Cores.vermelho),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    // O corpo muda conforme o estado — em vez de FutureBuilder (que pisca
    // loader a cada future novo), decidimos nós mesmos o que mostrar:
    //   1ª carga        → loader central
    //   erro sem dados  → tela de erro (com puxão pra tentar de novo)
    //   com dados       → a lista (que só é trocada quando dados NOVOS chegam)
    final Widget corpo;

    if (_carregando && _painel == null) {
      corpo = const Center(child: CircularProgressIndicator());
    } else if (_erro != null && _painel == null) {
      corpo = ListView(
        // ListView (e não Column) para o RefreshIndicator ter onde
        // rolar mesmo numa tela de erro — assim dá pra tentar de novo
        // puxando pra baixo.
        physics: const AlwaysScrollableScrollPhysics(),
        children: [
          const SizedBox(height: 80),
          const Icon(Icons.wifi_off, size: 48, color: Cores.textoSuave),
          const SizedBox(height: 8),
          Text(_erro!, textAlign: TextAlign.center),
        ],
      );
    } else {
      corpo = _listaPainel(_painel!);
    }

    return Scaffold(
      appBar: AppBar(
        // Saudação com o primeiro nome de quem logou ("Olá, Everton");
        // enquanto o me() não responde, o topo segue com a marca.
        title: Text(_usuario == null
            ? 'BotBTC'
            : 'Olá, ${_usuario!.nome.split(' ').first}'),
        actions: [
          // Ações de dinheiro: saques (solicitar/cancelar/aprovar) e
          // depósitos PIX moram na TelaAcoes, em duas abas.
          IconButton(
            icon: const Icon(Icons.currency_exchange),
            tooltip: 'Ações',
            onPressed: _abrirAcoes,
          ),
          // Admin do bot: config, pausa, modo subida, simulação, relatório
          // (a engrenagem só aparece pro admin — o servidor barra o resto).
          if (_painel?.ehAdmin ?? false)
            IconButton(
              icon: const Icon(Icons.settings),
              tooltip: 'Admin do bot',
              onPressed: _abrirAdmin,
            ),
          IconButton(icon: const Icon(Icons.logout), tooltip: 'Sair', onPressed: _sair),
        ],
      ),
      body: RefreshIndicator(
        // Puxão manual: mesmo _carregar, mas SEM silêncio (o RefreshIndicator
        // mostra o giro dele no topo; a lista continua visível embaixo).
        onRefresh: () => _carregar(),
        child: corpo,
      ),
    );
  }

  /// A lista completa do painel (tiles, ordens, investidores, saques).
  Widget _listaPainel(Painel painel) {
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      // padding lateral/de-cima 12; embaixo maior pra última seção
      // não encostar na borda/gesture bar do aparelho.
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 20),
      children: [
        // ── Push desligado: aviso de depósito/saque/liberação não chega.
        // O pedido original acontece na instalação; este lembrete é o
        // resgate pra quem negou (ou pro usuário novo do Android 13+).
        if (_permissaoPush == AuthorizationStatus.denied)
          Card(
            margin: const EdgeInsets.only(bottom: 10),
            color: Cores.dourado.withValues(alpha: 0.10),
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      const Icon(Icons.notifications_off_outlined,
                          color: Cores.dourado, size: 20),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          'Notificações desligadas',
                          style: TextStyle(
                              fontSize: 13.5,
                              fontWeight: FontWeight.w700,
                              color: Cores.dourado),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 6),
                  const Text(
                    'Ative pra receber avisos de depósitos, saques e liberação '
                    'de ativos.',
                    style: TextStyle(
                        fontSize: 12, color: Cores.textoSuave, height: 1.4),
                  ),
                  const SizedBox(height: 8),
                  SizedBox(
                    width: double.infinity,
                    child: FilledButton.tonalIcon(
                      onPressed: _pedirPermissaoPush,
                      style: FilledButton.styleFrom(
                        backgroundColor: Cores.superficie2,
                      ),
                      icon: const Icon(Icons.notifications_active_outlined,
                          size: 16),
                      label: const Text('Ativar agora', style: TextStyle(fontSize: 12.5)),
                    ),
                  ),
                ],
              ),
            ),
          ),

        // ── ADMIN: pendências no TOPO — só o admin dá continuidade a
        // saques e depósitos, então eles ficam grudados aqui até resolver.
        if (painel.ehAdmin &&
            (painel.saques.isNotEmpty || painel.depositosPendentes.isNotEmpty))
          _cardAguardandoVoce(painel),

        // Aviso quando a Binance não respondeu (tiles zerados).
        if (!painel.tiles.binanceOk)
          const Card(
            child: Padding(
              padding: EdgeInsets.all(12),
              child: Row(
                children: [
                  Icon(Icons.warning_amber_rounded, color: Cores.dourado),
                  SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      'Sem resposta da Binance agora — valores podem estar zerados.',
                      style: TextStyle(color: Cores.textoSuave),
                    ),
                  ),
                ],
              ),
            ),
          ),

        _tituloSecao(painel.ehAdmin ? 'Painel do Bot' : 'Meu Investimento', Icons.speed),
        _gridTiles(painel),

        // ── CLIENTE: card do disponível via PIX (valor atual menos a taxa
        // de 1% do saque — o mesmo "Disponível via Pix" do site) ─────────
        if (!painel.ehAdmin)
          Padding(
            padding: const EdgeInsets.only(top: 10),
            child: Card(
              margin: EdgeInsets.zero,
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Row(
                  children: [
                    const Icon(Icons.currency_exchange, size: 22, color: Cores.verde),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Text('Disponível via PIX',
                              style: TextStyle(fontSize: 11.5, color: Cores.textoSuave)),
                          const SizedBox(height: 4),
                          FittedBox(
                            fit: BoxFit.scaleDown,
                            child: Text(
                              moeda((painel.meu?.valorAtual ?? 0) * 0.99),
                              style: const TextStyle(
                                  fontSize: 17,
                                  fontWeight: FontWeight.w700,
                                  color: Cores.verde),
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: 6),
                    const Text(
                      'Taxa de 1%\njá descontada',
                      textAlign: TextAlign.right,
                      style: TextStyle(fontSize: 10.5, color: Cores.textoSuave, height: 1.35),
                    ),
                  ],
                ),
              ),
            ),
          ),

        // ── CLIENTE: histórico dos próprios saques e depósitos (até 30,
        // a mesma lista do site via /bot/historico) ──────────────────────
        if (!painel.ehAdmin) ...[
          _tituloSecao('Histórico', Icons.history),
          if (painel.historico.isEmpty)
            const _CardVazio(texto: 'Nenhum saque ou depósito ainda.')
          else
            ...painel.historico.map(_cardLancamento),
        ],

        // Oscilação do grid — o mesmo "salto · ATR dinâmico" do site,
        // em badge dourado logo abaixo dos tiles (parâmetro do bot: admin).
        if (painel.ehAdmin)
        Padding(
          padding: const EdgeInsets.only(top: 2),
          child: Align(
            alignment: Alignment.centerLeft,
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
              decoration: BoxDecoration(
                color: Cores.dourado.withValues(alpha: 0.14),
                borderRadius: BorderRadius.circular(8),
              ),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Icon(Icons.swap_vert, size: 15, color: Cores.dourado),
                  const SizedBox(width: 5),
                  Text(
                    'Salto (ATR): ${moeda(painel.tiles.saltoAtr)}',
                    style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700, color: Cores.dourado),
                  ),
                ],
              ),
            ),
          ),
        ),

        // Ordens abertas, investidores e saques pendentes são visão do
        // ADMIN (o servidor já manda as listas vazias pro cliente — o if
        // aqui só evita os títulos/sections "Nenhum..." na tela dele).
        if (painel.ehAdmin) ...[
          _tituloSecao('Ordens Abertas', Icons.checklist),
          if (painel.ordens.isEmpty)
            const _CardVazio(texto: 'Nenhuma ordem aberta.')
          else
            ...painel.ordens.map(_cardOrdem),

          _tituloSecao('Investidores', Icons.group),
          if (painel.investidores.isEmpty)
            const _CardVazio(texto: 'Nenhum investidor cadastrado.')
          else
            ...painel.investidores.map(_cardInvestidor),

          _tituloSecao('Saques Pendentes', Icons.currency_exchange),
          if (painel.saques.isEmpty)
            const _CardVazio(texto: 'Nenhum saque pendente.')
          else
            ...painel.saques.map(_cardSaque),
        ],

        const SizedBox(height: 48),
      ],
    );
  }

  /// O bloco "Aguardando você" — pendências que só o admin resolve, no
  /// topo da Home: saques aguardando aprovação e depósitos pagos esperando
  /// transferência pra Binance + registro. Cada linha já leva à aba certa.
  Widget _cardAguardandoVoce(Painel painel) {
    final saques = painel.saques;
    final depositos = painel.depositosPendentes;
    final totalSaques =
        saques.fold(0.0, (s, x) => s + x.valorBruto);
    final totalDepositos =
        depositos.fold(0.0, (s, x) => s + x.liquido);

    return Card(
      margin: EdgeInsets.zero,
      color: Cores.vermelho.withValues(alpha: 0.08),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Icon(Icons.notification_important,
                    color: Cores.vermelho, size: 20),
                const SizedBox(width: 8),
                Text(
                  'Aguardando você',
                  style: TextStyle(
                      fontSize: 14,
                      fontWeight: FontWeight.w700,
                      color: Cores.vermelho),
                ),
              ],
            ),
            const SizedBox(height: 8),
            if (saques.isNotEmpty)
              _linhaPendencia(
                icone: Icons.currency_exchange,
                texto: saques.length == 1
                    ? '1 saque aguardando aprovação — ${moeda(totalSaques)}'
                    : '${saques.length} saques aguardando aprovação — ${moeda(totalSaques)}',
                aoTocar: () => _abrirAcoes(aba: 0),
              ),
            if (depositos.isNotEmpty) ...[
              if (saques.isNotEmpty) const SizedBox(height: 6),
              _linhaPendencia(
                icone: Icons.south_west,
                texto: depositos.length == 1
                    ? '1 depósito p/ registrar — ${moeda(totalDepositos)}'
                    : '${depositos.length} depósitos p/ registrar — ${moeda(totalDepositos)}',
                aoTocar: () => _abrirAcoes(aba: 1),
              ),
            ],
            const SizedBox(height: 4),
            const Text(
              'Só você dá continuidade: depósito = transferir p/ Binance e '
              'registrar; saque = pagar o PIX e concluir.',
              style: TextStyle(fontSize: 10.5, color: Cores.textoSuave, height: 1.4),
            ),
          ],
        ),
      ),
    );
  }

  /// Uma pendência do bloco "Aguardando você" — botão integral que abre
  /// a aba correspondente na tela Ações.
  Widget _linhaPendencia({
    required IconData icone,
    required String texto,
    required VoidCallback aoTocar,
  }) {
    return SizedBox(
      width: double.infinity,
      child: FilledButton.tonalIcon(
        onPressed: aoTocar,
        style: FilledButton.styleFrom(
          alignment: Alignment.centerLeft,
          backgroundColor: Cores.superficie2,
        ),
        icon: Icon(icone, size: 16, color: Cores.dourado),
        label: Text(texto, style: const TextStyle(fontSize: 12.5)),
      ),
    );
  }

  /// Título de seção com ícone — o "section-title" do site.
  Widget _tituloSecao(String texto, IconData icone) {
    return Padding(
      padding: const EdgeInsets.only(top: 20, bottom: 8, left: 4),
      child: Row(
        children: [
          Icon(icone, size: 18, color: Cores.dourado),
          const SizedBox(width: 8),
          Text(texto, style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w700)),
        ],
      ),
    );
  }

  /// Os tiles do topo.
  ///
  /// ADMIN: grade 2×2 — Preço BTC, Saldo BRL, Saldo BTC, Total Geral.
  /// CLIENTE: também 2×2, mas só com o que é DELE — cotação, valor
  /// investido, total aportado e lucro. Os saldos do bot nem chegam no
  /// JSON de não-admin (zerados no controller).
  ///
  /// GridView dentro de ListView precisa de shrinkWrap (calcula a altura
  /// própria em vez de tentar ser infinito) e NeverScrollableScrollPhysics
  /// (o gesto de rolar pertence à lista de fora).
  Widget _gridTiles(Painel painel) {
    final t = painel.tiles;

    if (!painel.ehAdmin) {
      final meu = painel.meu;
      final (seta, positivo) = setaLucro(meu?.lucro ?? 0);

      return GridView.count(
        crossAxisCount: 2,
        shrinkWrap: true,
        physics: const NeverScrollableScrollPhysics(),
        mainAxisSpacing: 10,
        crossAxisSpacing: 10,
        childAspectRatio: 1.45,
        children: [
          _tile(
            rotulo: 'Preço BTC',
            valor: moeda(t.precoBtc),
            valorCor: Cores.dourado,
            icone: Icons.currency_bitcoin,
            iconeCor: Cores.dourado,
          ),
          _tile(
            rotulo: 'Valor Investido',
            valor: moeda(meu?.valorAtual ?? 0),
            valorCor: Cores.verde,
            icone: Icons.savings_outlined,
            iconeCor: Cores.verde,
          ),
          _tile(
            rotulo: 'Total Aportado',
            valor: moeda(meu?.investimentoInicial ?? 0),
            icone: Icons.payments_outlined,
          ),
          _tile(
            rotulo: 'Lucro',
            valor: '$seta ${moeda((meu?.lucro ?? 0).abs())}',
            valorCor: positivo ? Cores.verde : Cores.vermelho,
            icone: positivo ? Icons.trending_up : Icons.trending_down,
            iconeCor: positivo ? Cores.verde : Cores.vermelho,
          ),
        ],
      );
    }

    return GridView.count(
      crossAxisCount: 2,
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      mainAxisSpacing: 10,
      crossAxisSpacing: 10,
      childAspectRatio: 1.45, // retangular, como os stat-tile do site
      children: [
        _tile(
          rotulo: 'Preço BTC',
          valor: moeda(t.precoBtc),
          valorCor: Cores.dourado,
          icone: Icons.currency_bitcoin,
          iconeCor: Cores.dourado,
        ),
        _tile(
          rotulo: 'Saldo BRL',
          valor: moeda(t.brlTotal),
          sub: 'Bloqueado: ${moeda(t.brlBloqueado)}\nLivre: ${moeda(t.brlLivre)}',
          icone: Icons.payments_outlined,
        ),
        _tile(
          rotulo: 'Saldo BTC',
          valor: moeda(t.btcTotalBrl),
          sub: 'Bloqueado: ${moeda(t.btcBloqueadoBrl)}\nLivre: ${moeda(t.btcLivreBrl)}',
          icone: Icons.currency_bitcoin_outlined,
          iconeCor: Cores.dourado,
        ),
        _tile(
          rotulo: 'Total Geral',
          valor: moeda(t.totalGeralBrl),
          valorCor: Cores.verde,
          sub: 'BNB: ${moeda(t.bnbBrl)}',
          icone: Icons.pie_chart_outline,
          iconeCor: Cores.verde,
        ),
      ],
    );
  }

  /// Um stat tile: rótulo pequeno, valor grande, subs discretos, ícone à direita.
  Widget _tile({
    required String rotulo,
    required String valor,
    required IconData icone,
    Color? valorCor,
    Color? iconeCor,
    String? sub,
  }) {
    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(rotulo, style: const TextStyle(fontSize: 11.5, color: Cores.textoSuave)),
                  const SizedBox(height: 4),
                  FittedBox(
                    // FittedBox encolhe o valor pra caber sem quebrar linha
                    // (números longos tipo R$ 390.197,00).
                    fit: BoxFit.scaleDown,
                    child: Text(
                      valor,
                      style: TextStyle(
                        fontSize: 17,
                        fontWeight: FontWeight.w700,
                        color: valorCor ?? Cores.texto,
                      ),
                    ),
                  ),
                  if (sub != null) ...[
                    const SizedBox(height: 2),
                    Text(sub, style: const TextStyle(fontSize: 10.5, color: Cores.textoSuave, height: 1.35)),
                  ],
                ],
              ),
            ),
            const SizedBox(width: 6),
            Icon(icone, size: 22, color: iconeCor ?? Cores.textoSuave),
          ],
        ),
      ),
    );
  }

  /// Linha de uma ordem aberta.
  Widget _cardOrdem(Ordem o) {
    final compra = o.isCompra;
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        child: Row(
          children: [
            // Badge Compra/Venda com as cores do site (verde/vermelho).
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
              decoration: BoxDecoration(
                color: compra ? Cores.verde.withValues(alpha: 0.14) : Cores.vermelho.withValues(alpha: 0.14),
                borderRadius: BorderRadius.circular(6),
              ),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(compra ? Icons.south_west : Icons.north_east, size: 13, color: compra ? Cores.verde : Cores.vermelho),
                  const SizedBox(width: 3),
                  Text(compra ? 'Compra' : 'Venda', style: TextStyle(fontSize: 11.5, fontWeight: FontWeight.w700, color: compra ? Cores.verde : Cores.vermelho)),
                ],
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(moeda(o.preco), style: const TextStyle(fontWeight: FontWeight.w600)),
                  Text('${qtdBtc(o.quantidade)} BTC · ${o.status}',
                      style: const TextStyle(fontSize: 11, color: Cores.textoSuave)),
                ],
              ),
            ),
            Text(moeda(o.total), style: const TextStyle(fontWeight: FontWeight.w600)),
          ],
        ),
      ),
    );
  }

  /// Card de um investidor: identidade em cima, números embaixo — e o menu
  /// do admin (lixeira de cotas e exclusão de conta) na ponta direita.
  Widget _cardInvestidor(Investidor i) {
    final (seta, positivo) = setaLucro(i.lucro);
    final corLucro = positivo ? Cores.verde : Cores.vermelho;
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
                      Text(i.nome, style: const TextStyle(fontWeight: FontWeight.w700)),
                      Text(i.email, style: const TextStyle(fontSize: 11, color: Cores.textoSuave)),
                    ],
                  ),
                ),
                // Participação no bolo (Part. do site).
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                  decoration: BoxDecoration(
                    color: Cores.dourado.withValues(alpha: 0.14),
                    borderRadius: BorderRadius.circular(6),
                  ),
                  child: Text('${i.percentual.toStringAsFixed(2)}%',
                      style: const TextStyle(fontSize: 11.5, fontWeight: FontWeight.w700, color: Cores.dourado)),
                ),
                // Menu do admin: as ações da tabela de investidores do site.
                PopupMenuButton<String>(
                  icon: const Icon(Icons.more_vert, size: 20, color: Cores.textoSuave),
                  tooltip: 'Ações do investidor',
                  onSelected: (acao) {
                    if (acao == 'esvaziar') _esvaziarCotas(i);
                    if (acao == 'excluir') _excluirConta(i);
                  },
                  itemBuilder: (_) => [
                    const PopupMenuItem(
                      value: 'esvaziar',
                      child: ListTile(
                        dense: true,
                        contentPadding: EdgeInsets.zero,
                        leading: Icon(Icons.delete_outline, size: 20, color: Cores.vermelho),
                        title: Text('Esvaziar cotas', style: TextStyle(fontSize: 13)),
                        subtitle: Text('Transfere p/ você e remove o registro',
                            style: TextStyle(fontSize: 10.5)),
                      ),
                    ),
                    const PopupMenuItem(
                      value: 'excluir',
                      child: ListTile(
                        dense: true,
                        contentPadding: EdgeInsets.zero,
                        leading: Icon(Icons.person_remove_outlined, size: 20, color: Cores.vermelho),
                        title: Text('Excluir conta', style: TextStyle(fontSize: 13)),
                        subtitle: Text('Só sem cotas nem histórico', style: TextStyle(fontSize: 10.5)),
                      ),
                    ),
                  ],
                ),
              ],
            ),
            const Divider(height: 16),
            Row(
              children: [
                Expanded(child: _miniInfo('Aportado', moeda(i.investimentoInicial))),
                Expanded(child: _miniInfo('Cotas', i.cotas.toStringAsFixed(2))),
                Expanded(child: _miniInfo('Valor Atual', moeda(i.valorAtual))),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      const Text('Lucro', style: TextStyle(fontSize: 10, color: Cores.textoSuave)),
                      Text(
                        '$seta ${moeda(i.lucro.abs())}',
                        textAlign: TextAlign.right,
                        style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: corLucro),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  /// Par rótulo/valor usado dentro do card de investidor.
  Widget _miniInfo(String rotulo, String valor) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(rotulo, style: const TextStyle(fontSize: 10, color: Cores.textoSuave)),
        Text(valor, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600)),
      ],
    );
  }

  /// Card de um saque pendente.
  Widget _cardSaque(SaquePendente s) {
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
                  child: Row(
                    children: [
                      Flexible(
                        child: Text(s.nome, style: const TextStyle(fontWeight: FontWeight.w700)),
                      ),
                      if (s.emAndamento) ...[
                        const SizedBox(width: 6),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                          decoration: BoxDecoration(
                            color: Cores.verde.withValues(alpha: 0.14),
                            borderRadius: BorderRadius.circular(6),
                          ),
                          child: const Text('EM ANDAMENTO',
                              style: TextStyle(
                                  fontSize: 9.5,
                                  fontWeight: FontWeight.w700,
                                  color: Cores.verde)),
                        ),
                      ],
                    ],
                  ),
                ),
                Text(s.criadoEm, style: const TextStyle(fontSize: 11, color: Cores.textoSuave)),
              ],
            ),
            const SizedBox(height: 6),
            Row(
              children: [
                Expanded(child: _miniInfo('Bruto', moeda(s.valorBruto))),
                Expanded(child: _miniInfo('Líquido (Pix)', moeda(s.valorLiquido))),
                Expanded(child: _miniInfo('Cotas', s.cotas.toStringAsFixed(2))),
              ],
            ),
          ],
        ),
      ),
    );
  }

  /// Linha do histórico do cliente: depósito (verde, entra) ou saque
  /// (vermelho, sai), com a data e o status andando (Pendente → Em
  /// andamento → Pago) — a mesma tabela do site.
  Widget _cardLancamento(Lancamento h) {
    final dep = h.ehDeposito;
    final cor = dep ? Cores.verde : Cores.vermelho;

    return Card(
      margin: const EdgeInsets.only(bottom: 8),
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
                  Text(dep ? 'Depósito' : 'Saque',
                      style: const TextStyle(fontWeight: FontWeight.w600)),
                  Text('${h.data} · ${h.statusLabel}',
                      style: const TextStyle(fontSize: 11, color: Cores.textoSuave)),
                ],
              ),
            ),
            Text(
              '${dep ? '+' : '−'} ${moeda(h.valor)}',
              style: TextStyle(fontWeight: FontWeight.w600, color: cor),
            ),
          ],
        ),
      ),
    );
  }

  /// Token inválido: limpa o que está salvo e volta pro login.
  Future<void> _tokenInvalido() async {
    await ApiService.limparToken();
    if (!mounted) return;
    Navigator.of(context).pushReplacement(
      MaterialPageRoute(builder: (_) => const TelaLogin()),
    );
  }
}

/// Card de lista vazia — o "Nenhuma ordem aberta." do site.
class _CardVazio extends StatelessWidget {
  final String texto;

  const _CardVazio({required this.texto});

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 14),
        child: Text(texto, textAlign: TextAlign.center, style: const TextStyle(color: Cores.textoSuave)),
      ),
    );
  }
}
