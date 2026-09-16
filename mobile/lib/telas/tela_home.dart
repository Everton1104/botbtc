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

import 'package:flutter/material.dart';

import '../modelos/painel.dart';
import '../servicos/api.dart';
import '../tema.dart';
import '../util/formatar.dart';
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
  // true apenas na PRIMEIRA carga (tela ainda vazia → loader central no lugar).
  bool _carregando = true;
  // Último erro (só vira tela de erro se NÃO houver dados antigos para mostrar).
  String? _erro;

  // ── Relógio da atualização automática ────────────────────────────────────
  Timer? _timerAuto;

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
    _iniciarTimer(); // liga o relógio de 1 minuto
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
        title: const Text('BotBTC'),
        actions: [
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

        _tituloSecao('Painel do Bot', Icons.speed),
        _gridTiles(painel.tiles),

        // Oscilação do grid — o mesmo "salto · ATR dinâmico" do site,
        // em badge dourado logo abaixo dos tiles.
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

        const SizedBox(height: 48),
      ],
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

  /// Os 4 tiles em grade 2×2.
  ///
  /// GridView dentro de ListView precisa de shrinkWrap (calcula a altura
  /// própria em vez de tentar ser infinito) e NeverScrollableScrollPhysics
  /// (o gesto de rolar pertence à lista de fora).
  Widget _gridTiles(Tiles t) {
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

  /// Card de um investidor: identidade em cima, números embaixo.
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
                  child: Text(s.nome, style: const TextStyle(fontWeight: FontWeight.w700)),
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
