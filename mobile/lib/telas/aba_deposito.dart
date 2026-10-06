// ─────────────────────────────────────────────────────────────────────────────
// ABA DE DEPÓSITOS (dentro da tela "Ações") — o fluxo PIX do site, no app.
//
// Para o investidor:
//   • Gerar uma cobrança PIX (valor → QR code + copia-e-cola, 30 min de vida)
//   • Acompanhar o pagamento: polling de 5s no status + countdown na tela
//     (o webhook do gateway confirma lá e o push "💰 Depósito confirmado"
//     chega junto); reabrir o app retoma a cobrança em aberto (pendente_atual)
//   • Histórico de depósitos pagos (com selo "aguardando registro")
//
// Para o admin (id 1):
//   • "Depósito manual" — aporte sem gateway: credita as cotas na hora e
//     PAUSA o bot até o admin transferir o valor pra Binance e liberar
//     (mesma pausa da confirmação de saque — banner com botão Retomar)
//   • "Contas" — lista dos usuários; contas sem cotas podem ser removidas
//   • "Depósitos confirmados" de todos os investidores
//   • Registrar no Bot — credita as cotas e marca o registro numa ÚNICA
//     transaction (no site são dois passos separados)
//   • Estornar — devolve via MercadoPago (diálogo pesado: taxa não volta)
//
// O dinheiro só entra no saldo depois do registro manual do admin — o
// pagamento confirmado apenas tira o depósito da fila.
// ─────────────────────────────────────────────────────────────────────────────

import 'dart:async'; // Timer (countdown + polling)
import 'dart:convert'; // base64Decode (o QR vem como PNG em base64)

import 'package:flutter/material.dart';
import 'package:flutter/services.dart'; // Clipboard (copia-e-cola)
import 'package:url_launcher/url_launcher.dart'; // abrir link no navegador

import '../modelos/deposito.dart';
import '../servicos/api.dart';
import '../tema.dart';
import '../util/formatar.dart';
import 'banner_pausa_bot.dart';
import 'tela_login.dart';

/// Situação da cobrança em exibição (o polling decide as transições).
enum _EstadoPix { aguardando, pago, expirado }

/// Aba da tela "Ações" — sem Scaffold próprio (AppBar/TabBar na TelaAcoes).
class AbaDeposito extends StatefulWidget {
  const AbaDeposito({super.key});

  @override
  State<AbaDeposito> createState() => _AbaDepositoState();
}

class _AbaDepositoState extends State<AbaDeposito> with WidgetsBindingObserver {
  // ── Estado ────────────────────────────────────────────────────────────────
  DadosDeposito? _dados; // null = primeira carga ainda não terminou
  bool _carregando = true;
  String? _erro;

  final _ctrlValor = TextEditingController();

  // Depósito manual (admin): investidor escolhido + valor do aporte.
  Investidor? _investidorSel;
  final _ctrlValorManual = TextEditingController();
  bool _fazendoManual = false;

  bool _criando = false; // gerando cobrança
  int? _processandoId; // depósito admin com ação em curso (registrar/estornar)
  int? _removendoId; // conta com remoção em curso (seção "Contas")
  bool _retomando = false; // liberando o bot da pausa do depósito manual
  bool _copiado = false; // alterna o ícone do botão copiar (2s)

  // A cobrança em exibição (do /criar ou retomada do pendente_atual).
  DepositoPendente? _cobranca;
  _EstadoPix _estado = _EstadoPix.aguardando;

  // UM timer para tudo: tique de 1s para o countdown, e a cada 5 tiques
  // (5s) dispara a consulta de status — mesma cadência do site.
  Timer? _timerPix;
  int _tic = 0;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _carregar();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _timerPix?.cancel();
    _ctrlValor.dispose();
    _ctrlValorManual.dispose();
    super.dispose();
  }

  /// App em segundo plano: sem polling (bateria/dados). Voltando: religa o
  /// timer e consulta na hora — o pagamento pode ter acontecido lá fora.
  @override
  void didChangeAppLifecycleState(AppLifecycleState estado) {
    if (estado == AppLifecycleState.resumed) {
      if (_cobranca != null && _estado == _EstadoPix.aguardando) {
        _iniciarTimerPix();
        _consultar();
      }
    } else if (estado != AppLifecycleState.inactive) {
      _timerPix?.cancel();
      _timerPix = null;
    }
  }

  /// Busca a aba inteira no servidor. Aproveita para RETOMAR uma cobrança
  /// pendente que ficou em aberto (o usuário fechou o app no meio).
  Future<void> _carregar() async {
    if (_dados == null) setState(() => _carregando = true);

    try {
      final dados = await ApiService.telaDeposito();
      if (!mounted) return;
      setState(() {
        _dados = dados;
        _erro = null;
        _carregando = false;
        if (_cobranca == null &&
            _estado == _EstadoPix.aguardando &&
            dados.pendenteAtual != null) {
          _cobranca = dados.pendenteAtual;
          _iniciarTimerPix();
        }
      });
    } on ApiException catch (e) {
      if (e.status == 401) {
        _tokenInvalido();
        return;
      }
      _falhou(e.mensagem);
    } catch (_) {
      _falhou('Não foi possível conectar ao servidor.');
    }
  }

  void _falhou(String mensagem) {
    if (!mounted) return;
    if (_dados != null) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(mensagem), backgroundColor: Cores.vermelho),
      );
      setState(() => _carregando = false);
      return;
    }
    setState(() {
      _erro = mensagem;
      _carregando = false;
    });
  }

  /// Ritual das ações de admin (igual ao da AbaSaque): desliga o botão,
  /// chama a API, SnackBar com a mensagem do servidor e recarrega.
  Future<void> _acao({
    required Future<String> Function() chamada,
    required bool Function() bloqueado,
    required VoidCallback ligar,
    required VoidCallback desligar,
  }) async {
    if (bloqueado()) return;

    desligar();
    try {
      final mensagem = await chamada();
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(mensagem), backgroundColor: Cores.verde),
      );
      await _carregar();
    } on ApiException catch (e) {
      if (e.status == 401) {
        _tokenInvalido();
        return;
      }
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(e.mensagem), backgroundColor: Cores.vermelho),
        );
      }
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
              content: Text('Não foi possível conectar ao servidor.'),
              backgroundColor: Cores.vermelho),
        );
      }
    } finally {
      if (mounted) ligar();
    }
  }

  /// Campo de texto → double (vírgula pt-BR vira ponto). null = válido
  /// vazio ou texto que não vira número (quem chama decide o que é erro).
  double? _numeroDe(TextEditingController c) {
    final texto = c.text.trim().replaceAll(',', '.');
    if (texto.isEmpty) return null;
    return double.tryParse(texto);
  }

  /// Valor do PIX a gerar.
  double? get _valorDigitado => _numeroDe(_ctrlValor);

  /// GERAR cobrança PIX — com diálogo de confirmação (dinheiro entrando).
  Future<void> _gerarPix() async {
    final valor = _valorDigitado;

    if (valor == null || valor < 1) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
            content: Text('Informe um valor de pelo menos R\$ 1,00.'),
            backgroundColor: Cores.vermelho),
      );
      return;
    }

    final prosseguir = await _confirmar(
      titulo: 'Gerar PIX de depósito',
      mensagem: 'Gerar cobrança PIX de ${moeda(valor)}?\n\n'
          'Você paga escaneando o QR ou colando o código no app do banco. '
          'A cobrança expira em 30 minutos.',
      botao: 'Gerar PIX',
    );
    if (prosseguir != true) return;

    setState(() => _criando = true);
    try {
      final cobranca = await ApiService.criarDeposito(valor: valor);
      if (!mounted) return;
      setState(() {
        _cobranca = cobranca;
        _estado = _EstadoPix.aguardando;
        _ctrlValor.clear();
      });
      _iniciarTimerPix();
    } on ApiException catch (e) {
      if (e.status == 401) {
        _tokenInvalido();
        return;
      }
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(e.mensagem), backgroundColor: Cores.vermelho),
        );
      }
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
              content: Text('Não foi possível gerar o PIX. Tente novamente.'),
              backgroundColor: Cores.vermelho),
        );
      }
    } finally {
      if (mounted) setState(() => _criando = false);
    }
  }

  /// Liga o timer único (countdown de 1s + status a cada 5s).
  void _iniciarTimerPix() {
    _timerPix?.cancel();
    _tic = 0;
    _timerPix = Timer.periodic(const Duration(seconds: 1), (_) {
      _tic++;

      // Countdown venceu localmente — nem espera o servidor confirmar.
      final exp = _cobranca?.expiracao;
      final venceu = exp != null && !exp.isAfter(DateTime.now());

      if (venceu && _estado == _EstadoPix.aguardando) {
        _expirar();
        return;
      }

      if (_tic % 5 == 0) _consultar(); // mesma cadência do site (5s)
      if (mounted) setState(() {}); // re-render do countdown
    });
  }

  void _pararTimerPix() {
    _timerPix?.cancel();
    _timerPix = null;
  }

  /// Consulta o status da cobrança no servidor. Erros de rede são engolidos
  /// (o próximo tique tenta de novo — o site faz o mesmo); 401 sai pro
  /// login; 404 descarta a cobrança (sumiu do servidor).
  Future<void> _consultar() async {
    if (_cobranca == null || _estado != _EstadoPix.aguardando) return;

    try {
      final st = await ApiService.statusDeposito(_cobranca!.txid);
      if (!mounted) return;

      if (st.status == 'pago') {
        _pararTimerPix();
        setState(() => _estado = _EstadoPix.pago);
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
              content: Text(
                  'Depósito confirmado! Aguardando registro do administrador.'),
              backgroundColor: Cores.verde),
        );
        await _carregar(); // histórico e fila do admin atualizam
      } else if (st.status == 'expirado' || st.status == 'cancelado') {
        _expirar();
      }
    } on ApiException catch (e) {
      if (e.status == 401) {
        _pararTimerPix();
        _tokenInvalido();
      } else if (e.status == 404) {
        _pararTimerPix();
        if (mounted) setState(() => _cobranca = null);
      }
      // demais erros: silêncio — o próximo tique tenta de novo
    } catch (_) {
      // rede fora do ar no meio do polling: idem, silêncio
    }
  }

  void _expirar() {
    _pararTimerPix();
    if (!mounted) return;
    setState(() => _estado = _EstadoPix.expirado);
  }

  /// Botão "Gerar novo PIX" do card de expirado.
  void _novoPix() {
    setState(() {
      _cobranca = null;
      _estado = _EstadoPix.aguardando;
    });
  }

  /// Copia o código PIX (ou o link de pagamento) e acena com o ícone.
  Future<void> _copiar(String texto) async {
    await Clipboard.setData(ClipboardData(text: texto));
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(
          content: Text('Código PIX copiado — cole no app do banco.'),
          backgroundColor: Cores.verde),
    );
    setState(() => _copiado = true);
    Future.delayed(const Duration(seconds: 2), () {
      if (mounted) setState(() => _copiado = false);
    });
  }

  /// Abre o link de pagamento (InfinitePay) no navegador PADRÃO do
  /// aparelho. O link é um checkout de navegador — não um código PIX pra
  /// colar no app do banco; o 3DS do cartão pode precisar abrir outros
  /// apps, e o navegador resolve tudo isso. Enquanto isso, o polling da
  /// cobrança continua rodando: quando o pagamento cair, o app avisa.
  Future<void> _abrirLink(String url) async {
    try {
      final abriu = await launchUrl(
        Uri.parse(url),
        mode: LaunchMode.externalApplication,
      );
      if (!abriu && mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
              content: Text('Não foi possível abrir o navegador.'),
              backgroundColor: Cores.vermelho),
        );
      }
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
              content: Text('Não foi possível abrir o navegador.'),
              backgroundColor: Cores.vermelho),
        );
      }
    }
  }

  /// DEPÓSITO MANUAL (admin) — aporte direto sem gateway, com confirmação
  /// (credita cotas de verdade, igual registrar).
  Future<void> _depositoManual() async {
    final inv = _investidorSel;
    final valor = _numeroDe(_ctrlValorManual);

    if (inv == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
            content: Text('Escolha o investidor.'),
            backgroundColor: Cores.vermelho),
      );
      return;
    }
    if (valor == null || valor <= 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
            content: Text('Informe um valor válido — use só números (ex.: 1500,50).'),
            backgroundColor: Cores.vermelho),
      );
      return;
    }

    final prosseguir = await _confirmar(
      titulo: 'Depósito manual',
      mensagem: 'Registrar aporte de ${moeda(valor)} para ${inv.nome}?\n\n'
          'Sem gateway e sem taxa: as cotas são creditadas direto pelo preço '
          'atual e o depósito já nasce registrado.\n\nO bot fica PAUSADO '
          'até você transferir o valor para a Binance e liberar.',
      botao: 'Registrar',
    );
    if (prosseguir != true) return;

    await _acao(
      chamada: () => ApiService.depositoManual(userId: inv.id, valor: valor),
      bloqueado: () => _fazendoManual,
      ligar: () => setState(() => _fazendoManual = false),
      desligar: () => setState(() => _fazendoManual = true),
    );

    // Limpa o valor — o aporte já foi; o nome pode ficar (próximos aportes
    // costumam ser pro mesmo investidor).
    if (mounted) setState(() => _ctrlValorManual.clear());
  }

  /// LIBERAR o bot da pausa do depósito manual (a transferência caiu).
  Future<void> _retomarBot() async {
    await _acao(
      chamada: ApiService.retomarBot,
      bloqueado: () => _retomando,
      ligar: () => setState(() => _retomando = false),
      desligar: () => setState(() => _retomando = true),
    );
  }

  /// REMOVER CONTA sem cotas (admin) — confirmação pesada: é irreversível.
  /// O servidor é a autoridade (recusa se houver qualquer dinheiro).
  Future<void> _removerConta(Investidor i) async {
    final prosseguir = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Remover conta'),
        content: Text(
          'Remover a conta de ${i.nome} (${i.email})?\n\n'
          'Esta ação é irreversível. A conta não tem cotas nem depósitos.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: const Text('Voltar'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Cores.vermelho),
            onPressed: () => Navigator.of(ctx).pop(true),
            child: const Text('Remover'),
          ),
        ],
      ),
    );
    if (prosseguir != true) return;

    await _acao(
      chamada: () => ApiService.removerUsuario(i.id),
      bloqueado: () => _removendoId != null,
      ligar: () => setState(() => _removendoId = null),
      desligar: () => setState(() => _removendoId = i.id),
    );

    // O investidor pode ter sido removido — solta a seleção do manual.
    if (mounted && _investidorSel?.id == i.id) {
      setState(() => _investidorSel = null);
    }
  }

  /// REGISTRAR depósito no bot (admin) — crédito de cotas.
  Future<void> _registrar(DepositoAdmin d) async {
    final prosseguir = await _confirmar(
      titulo: 'Registrar depósito no Bot',
      mensagem: 'Registrar ${moeda(d.liquido)} de ${d.userName}?\n\n'
          'As cotas serão creditadas proporcionalmente ao patrimônio atual.',
      botao: 'Registrar',
    );
    if (prosseguir != true) return;

    await _acao(
      chamada: () => ApiService.registrarDeposito(d.id),
      bloqueado: () => _processandoId != null,
      ligar: () => setState(() => _processandoId = null),
      desligar: () => setState(() => _processandoId = d.id),
    );
  }

  /// ESTORNAR depósito (admin) — diálogo pesado: o cliente recebe 100%,
  /// mas a taxa do Mercado Pago não volta.
  Future<void> _estornar(DepositoAdmin d) async {
    final prosseguir = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Estornar pagamento'),
        content: Text(
          'Estornar ${moeda(d.valor)} de ${d.userName}?\n\n'
          'O cliente recebe 100% de volta, mas a taxa de 1% do Mercado '
          'Pago NÃO será recuperada.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: const Text('Voltar'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Cores.vermelho),
            onPressed: () => Navigator.of(ctx).pop(true),
            child: const Text('Estornar'),
          ),
        ],
      ),
    );
    if (prosseguir != true) return;

    await _acao(
      chamada: () => ApiService.estornarDeposito(d.id),
      bloqueado: () => _processandoId != null,
      ligar: () => setState(() => _processandoId = null),
      desligar: () => setState(() => _processandoId = d.id),
    );
  }

  /// Diálogo sim/não genérico (mesmo da AbaSaque).
  Future<bool?> _confirmar({
    required String titulo,
    required String mensagem,
    required String botao,
  }) {
    return showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(titulo),
        content: Text(mensagem),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: const Text('Voltar'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(ctx).pop(true),
            child: Text(botao),
          ),
        ],
      ),
    );
  }

  /// Token inválido: limpa e volta pro login (mesmo ritual da Home).
  Future<void> _tokenInvalido() async {
    await ApiService.limparToken();
    if (!mounted) return;
    Navigator.of(context).pushReplacement(
      MaterialPageRoute(builder: (_) => const TelaLogin()),
    );
  }

  /// "mm:ss" até a expiração (nunca negativo).
  String get _restoStr {
    final exp = _cobranca?.expiracao;
    if (exp == null) return '--:--';
    var resto = exp.difference(DateTime.now());
    if (resto.isNegative) resto = Duration.zero;
    final m = resto.inMinutes.remainder(60).toString().padLeft(2, '0');
    final s = resto.inSeconds.remainder(60).toString().padLeft(2, '0');
    return '$m:$s';
  }

  @override
  Widget build(BuildContext context) {
    final Widget corpo;

    if (_carregando && _dados == null) {
      corpo = const Center(child: CircularProgressIndicator());
    } else if (_erro != null && _dados == null) {
      corpo = ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        children: [
          const SizedBox(height: 80),
          const Icon(Icons.wifi_off, size: 48, color: Cores.textoSuave),
          const SizedBox(height: 8),
          Text(_erro!, textAlign: TextAlign.center),
        ],
      );
    } else {
      corpo = _lista(_dados!);
    }

    // Sem Scaffold: a AppBar e a TabBar pertencem à TelaAcoes.
    return RefreshIndicator(onRefresh: _carregar, child: corpo);
  }

  /// A lista completa da aba de depósitos.
  Widget _lista(DadosDeposito d) {
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 20),
      children: [
        // ── Banner do bot pausado p/ depósito manual (só admin, pausa ativa)
        if (d.ehAdmin && (d.pausa?.pausado ?? false))
          BannerPausaBot(
            pausa: d.pausa!,
            onRetomar: _retomarBot,
            ocupado: _retomando,
          ),

        if (_cobranca == null) ...[
          _tituloSecao('Depositar via PIX', Icons.add_card_outlined),
          _cardDepositar(),
        ] else ...[
          _tituloSecao('Pagamento PIX', Icons.qr_code_2),
          _cardPix(),
        ],

        _tituloSecao('Histórico de depósitos', Icons.history),
        if (d.historico.isEmpty)
          const _CardVazio(texto: 'Nenhum depósito ainda.')
        else
          ...d.historico.map(_cardHistorico),

        // ── Seções do admin — o servidor nem envia os dados pros demais ──
        if (d.ehAdmin) ...[
          _tituloSecao('Depósito manual', Icons.person_add_alt_outlined),
          _cardManual(d),

          _tituloSecao('Contas', Icons.manage_accounts_outlined),
          ...d.investidores.map(_cardConta),

          _tituloSecao('Depósitos confirmados', Icons.verified_outlined),
          if (d.adminDepositos.isEmpty)
            const _CardVazio(texto: 'Nenhum depósito confirmado ainda.')
          else
            ...d.adminDepositos.map(_cardAdmin),
        ],

        const SizedBox(height: 48),
      ],
    );
  }

  /// Linha da seção "Contas": nome, e-mail e cotas; a lixeira só acende
  /// pra quem não tem cotas (o servidor continua sendo a autoridade).
  Widget _cardConta(Investidor i) {
    final semCotas = i.cotas <= 0;

    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        child: Row(
          children: [
            Icon(
              semCotas ? Icons.person_outline : Icons.person,
              size: 20,
              color: semCotas ? Cores.textoSuave : Cores.dourado,
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(i.nome, style: const TextStyle(fontWeight: FontWeight.w600)),
                  Text(
                    i.cotas > 0 ? '${i.cotas.toStringAsFixed(2)} cotas' : i.email,
                    style:
                        const TextStyle(fontSize: 11, color: Cores.textoSuave),
                    overflow: TextOverflow.ellipsis,
                  ),
                ],
              ),
            ),
            if (semCotas && i.id != 1)
              _removendoId == i.id
                  ? const SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : IconButton(
                      tooltip: 'Remover conta',
                      onPressed: _removendoId != null ? null : () => _removerConta(i),
                      icon: const Icon(Icons.delete_outline, size: 20),
                      color: Cores.vermelho,
                    ),
          ],
        ),
      ),
    );
  }

  /// Card de gerar cobrança: campo de valor + botão.
  Widget _cardDepositar() {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            TextField(
              controller: _ctrlValor,
              keyboardType:
                  const TextInputType.numberWithOptions(decimal: true),
              decoration: const InputDecoration(
                labelText: 'Valor (R\$)',
                prefixIcon: Icon(Icons.payments_outlined),
                hintText: 'Quanto você quer depositar',
              ),
              onSubmitted: (_) => _criando ? null : _gerarPix(),
            ),
            const SizedBox(height: 6),
            const Text(
              'O valor entra no bot após o registro do administrador.',
              style: TextStyle(fontSize: 11, color: Cores.textoSuave),
            ),
            const SizedBox(height: 10),
            SizedBox(
              width: double.infinity,
              child: FilledButton.icon(
                onPressed: _criando ? null : _gerarPix,
                icon: _criando
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.qr_code_2),
                label: Text(_criando ? 'Gerando...' : 'Gerar PIX'),
              ),
            ),
          ],
        ),
      ),
    );
  }

  /// O card da cobrança — muda conforme o estado (aguardando/pago/expirado).
  Widget _cardPix() {
    final c = _cobranca!;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text('Valor a pagar',
                      style:
                          const TextStyle(fontSize: 12, color: Cores.textoSuave)),
                ),
                if (_estado == _EstadoPix.aguardando)
                  Text('Expira em $_restoStr',
                      style: const TextStyle(
                          fontSize: 13,
                          fontWeight: FontWeight.w700,
                          color: Cores.dourado))
                else
                  Text(moeda(c.valor),
                      style: const TextStyle(
                          fontSize: 15, fontWeight: FontWeight.w700)),
              ],
            ),
            if (_estado == _EstadoPix.aguardando) ...[
              const SizedBox(height: 4),
              Text(moeda(c.valor),
                  style: const TextStyle(
                      fontSize: 15,
                      fontWeight: FontWeight.w700,
                      color: Cores.verde)),
              const SizedBox(height: 12),
              if (c.qrCode != null && c.qrCode!.isNotEmpty)
                Center(
                  child: Container(
                    padding: const EdgeInsets.all(8),
                    decoration: BoxDecoration(
                      // Fundo BRANCO: leitor de QR não lê dourado em escuro.
                      color: Colors.white,
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: Cores.verde, width: 3),
                    ),
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(6),
                      child: Image.memory(
                        base64Decode(c.qrCode!),
                        width: 210,
                        height: 210,
                        fit: BoxFit.contain,
                        gaplessPlayback: true, // não pisca no rebuild do countdown
                      ),
                    ),
                  ),
                )
              else ...[
                // InfinitePay: sem QR — o pagamento é um link.
                const SizedBox(height: 4),
                const Text(
                  'Este link abre o checkout no navegador — não é um código '
                  'PIX para colar no app do banco.',
                  style: TextStyle(fontSize: 12, color: Cores.textoSuave),
                ),
              ],
              const SizedBox(height: 12),
              if (c.ehLink) ...[
                // Ação principal: abrir o navegador padrão do aparelho.
                // Copiar fica secundário (o link só é útil aberto).
                SizedBox(
                  width: double.infinity,
                  child: FilledButton.tonalIcon(
                    onPressed:
                        c.paymentUrl != null ? () => _abrirLink(c.paymentUrl!) : null,
                    icon: const Icon(Icons.open_in_new, size: 18),
                    label: const Text('Abrir no navegador'),
                  ),
                ),
                const SizedBox(height: 6),
                SizedBox(
                  width: double.infinity,
                  child: TextButton.icon(
                    onPressed:
                        c.paymentUrl != null ? () => _copiar(c.paymentUrl!) : null,
                    icon: Icon(_copiado ? Icons.check : Icons.copy, size: 16),
                    label: Text(_copiado ? 'Link copiado' : 'Copiar link'),
                  ),
                ),
              ]
              else if (c.copiaECola != null && c.copiaECola!.isNotEmpty) ...[
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                    color: Cores.superficie2,
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Text(
                    c.copiaECola!,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                        fontSize: 11, color: Cores.textoSuave),
                  ),
                ),
                const SizedBox(height: 8),
                SizedBox(
                  width: double.infinity,
                  child: FilledButton.tonalIcon(
                    onPressed: () => _copiar(c.copiaECola!),
                    icon: Icon(_copiado ? Icons.check : Icons.copy, size: 18),
                    label: Text(_copiado ? 'Código copiado' : 'Copiar código PIX'),
                  ),
                ),
              ],
              const SizedBox(height: 10),
              // Quem processa o pagamento (nome vem do env do servidor —
              // trocar de operadora não exige update do app).
              Text(
                'Pagamento processado pela ${_dados?.gatewayNome ?? 'PIX'} '
                'em um site externo seguro de pagamentos. O administrador '
                'será avisado do depósito e em breve o valor será adicionado '
                'à sua conta.',
                style: const TextStyle(fontSize: 11, color: Cores.textoSuave),
              ),
              // A taxa de 1% é do Mercado Pago — só faz sentido citá-la
              // quando a cobrança em tela é dele.
              if (c.gateway == 'mercadopago') ...[
                const SizedBox(height: 6),
                const Text(
                  'Em caso de estorno, o valor integral é devolvido; a taxa '
                  'de 1% do Mercado Pago não é recuperada.',
                  style: TextStyle(fontSize: 10.5, color: Cores.textoSuave),
                ),
              ],
            ] else if (_estado == _EstadoPix.pago) ...[
              const SizedBox(height: 12),
              Row(
                children: [
                  const Icon(Icons.check_circle, color: Cores.verde, size: 22),
                  const SizedBox(width: 10),
                  Text('Pagamento recebido!',
                      style: TextStyle(
                          fontSize: 15,
                          fontWeight: FontWeight.w700,
                          color: Cores.verde)),
                ],
              ),
              const SizedBox(height: 8),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: Cores.dourado.withValues(alpha: 0.10),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: const Text(
                  'Aguardando registro do administrador — o valor entra no '
                  'seu saldo após a aprovação manual.',
                  style: TextStyle(fontSize: 12, color: Cores.dourado),
                ),
              ),
            ] else ...[
              const SizedBox(height: 12),
              Row(
                children: [
                  const Icon(Icons.hourglass_bottom,
                      color: Cores.vermelho, size: 22),
                  const SizedBox(width: 10),
                  Text('Cobrança expirada',
                      style: TextStyle(
                          fontSize: 15,
                          fontWeight: FontWeight.w700,
                          color: Cores.vermelho)),
                ],
              ),
              const SizedBox(height: 4),
              const Text(
                'O código não vale mais. Gere uma nova cobrança para depositar.',
                style: TextStyle(fontSize: 12, color: Cores.textoSuave),
              ),
              const SizedBox(height: 12),
              SizedBox(
                width: double.infinity,
                child: FilledButton.icon(
                  onPressed: _novoPix,
                  icon: const Icon(Icons.refresh),
                  label: const Text('Gerar novo PIX'),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  /// Linha do histórico (depósito pago; estornado fica esmaecido).
  Widget _cardHistorico(DepositoHistorico d) {
    final esmaecido = d.estornado;
    return Opacity(
      opacity: esmaecido ? 0.55 : 1,
      child: Card(
        margin: const EdgeInsets.only(bottom: 8),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
          child: Row(
            children: [
              if (d.estornado)
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                  decoration: BoxDecoration(
                    color: Cores.vermelho.withValues(alpha: 0.14),
                    borderRadius: BorderRadius.circular(6),
                  ),
                  child: const Text('Estornado',
                      style: TextStyle(
                          fontSize: 11.5,
                          fontWeight: FontWeight.w700,
                          color: Cores.vermelho)),
                )
              else
                const Icon(Icons.check_circle_outline,
                    size: 18, color: Cores.verde),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(d.estornado ? 'Estornado em ${d.pagoEm}' : 'Pago em ${d.pagoEm}',
                        style: const TextStyle(color: Cores.textoSuave)),
                    if (!d.estornado && !d.registrado)
                      const Text('Aguardando registro no bot',
                          style:
                              TextStyle(fontSize: 10.5, color: Cores.dourado)),
                  ],
                ),
              ),
              Text(moeda(d.valor),
                  style: const TextStyle(fontWeight: FontWeight.w600)),
            ],
          ),
        ),
      ),
    );
  }

  /// Card do admin: aporte direto sem gateway — seletor de investidor +
  /// valor. Credita cotas na hora (o registro nasce pago + registrado).
  Widget _cardManual(DadosDeposito d) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            DropdownButtonFormField<int>(
              initialValue: _investidorSel?.id,
              decoration: const InputDecoration(
                labelText: 'Investidor',
                prefixIcon: Icon(Icons.person_outline),
              ),
              items: [
                for (final i in d.investidores)
                  DropdownMenuItem(
                    value: i.id,
                    child: Text(i.nome, overflow: TextOverflow.ellipsis),
                  ),
              ],
              onChanged: (id) => setState(() {
                _investidorSel =
                    d.investidores.firstWhere((i) => i.id == id);
              }),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _ctrlValorManual,
              keyboardType:
                  const TextInputType.numberWithOptions(decimal: true),
              decoration: const InputDecoration(
                labelText: 'Valor (R\$)',
                prefixIcon: Icon(Icons.payments_outlined),
                hintText: 'Aporte sem gateway',
              ),
            ),
            const SizedBox(height: 6),
            const Text(
              'Sem gateway, sem taxa e sem aprovação: registre aqui, transfira '
              'o valor para a Binance e libere o bot no banner acima.',
              style: TextStyle(fontSize: 11, color: Cores.textoSuave),
            ),
            const SizedBox(height: 10),
            SizedBox(
              width: double.infinity,
              child: FilledButton.icon(
                onPressed: _fazendoManual ? null : _depositoManual,
                icon: _fazendoManual
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.bolt_outlined),
                label: Text(_fazendoManual
                    ? 'Registrando...'
                    : 'Registrar depósito manual'),
              ),
            ),
          ],
        ),
      ),
    );
  }

  /// Card do admin: quem depositou, quanto, e as ações Registrar/Estornar.
  /// Registrados e estornados viram linhas esmaecidas (sem ação).
  Widget _cardAdmin(DepositoAdmin d) {
    if (d.registrado || d.estornado) {
      return Opacity(
        opacity: 0.55,
        child: Card(
          margin: const EdgeInsets.only(bottom: 8),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
            child: Row(
              children: [
                Icon(
                  d.estornado ? Icons.undo : Icons.check_circle_outline,
                  size: 18,
                  color: d.estornado ? Cores.vermelho : Cores.verde,
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    '${d.userName} · ${d.pagoEm}',
                    style: const TextStyle(color: Cores.textoSuave),
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                Text(moeda(d.valor),
                    style: const TextStyle(fontWeight: FontWeight.w600)),
                const SizedBox(width: 8),
                Text(
                  d.estornado ? 'estornado' : 'registrado',
                  style: const TextStyle(
                      fontSize: 10.5, color: Cores.textoSuave),
                ),
              ],
            ),
          ),
        ),
      );
    }

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
                      Text(d.userName,
                          style: const TextStyle(fontWeight: FontWeight.w700)),
                      Text(d.userEmail,
                          style: const TextStyle(
                              fontSize: 11, color: Cores.textoSuave)),
                    ],
                  ),
                ),
                Text(d.pagoEm,
                    style:
                        const TextStyle(fontSize: 11, color: Cores.textoSuave)),
              ],
            ),
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(child: _miniInfo('Bruto', moeda(d.valor))),
                Expanded(child: _miniInfo('Líquido (cotas)', moeda(d.liquido))),
                Expanded(child: _miniInfo('Método', d.metodo)),
              ],
            ),
            const SizedBox(height: 10),
            Row(
              children: [
                Expanded(
                  child: FilledButton.icon(
                    onPressed: _processandoId != null ? null : () => _registrar(d),
                    icon: _processandoId == d.id
                        ? const SizedBox(
                            width: 18,
                            height: 18,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(Icons.task_alt_outlined),
                    label: Text(
                        _processandoId == d.id ? 'Registrando...' : 'Registrar no Bot'),
                  ),
                ),
                const SizedBox(width: 8),
                _processandoId == d.id
                    ? const SizedBox(width: 20, height: 20)
                    : TextButton(
                        onPressed:
                            _processandoId != null ? null : () => _estornar(d),
                        style: TextButton.styleFrom(
                            foregroundColor: Cores.vermelho),
                        child: const Text('Estornar'),
                      ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  /// Par rótulo/valor (mesmo do card de investidor da Home).
  Widget _miniInfo(String rotulo, String valor) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(rotulo,
            style: const TextStyle(fontSize: 10, color: Cores.textoSuave)),
        Text(valor,
            style:
                const TextStyle(fontSize: 12, fontWeight: FontWeight.w600)),
      ],
    );
  }

  /// Título de seção com ícone — igual ao da Home.
  Widget _tituloSecao(String texto, IconData icone) {
    return Padding(
      padding: const EdgeInsets.only(top: 20, bottom: 8, left: 4),
      child: Row(
        children: [
          Icon(icone, size: 18, color: Cores.dourado),
          const SizedBox(width: 8),
          Text(texto,
              style: const TextStyle(
                  fontSize: 15, fontWeight: FontWeight.w700)),
        ],
      ),
    );
  }
}

/// Card de lista vazia — mesma cara do da Home.
class _CardVazio extends StatelessWidget {
  final String texto;

  const _CardVazio({required this.texto});

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 14),
        child: Text(texto,
            textAlign: TextAlign.center,
            style: const TextStyle(color: Cores.textoSuave)),
      ),
    );
  }
}
