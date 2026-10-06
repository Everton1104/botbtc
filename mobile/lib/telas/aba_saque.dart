// ─────────────────────────────────────────────────────────────────────────────
// ABA DE SAQUES (dentro da tela "Ações") — o fluxo completo do site, no app.
//
// Para o investidor:
//   • Cadastrar a chave PIX de recebimento (exigida — o admin paga nela)
//   • Solicitar saque (com valor, ou vazio = sacar tudo)
//   • Cancelar um pendente (o servidor devolve as cotas)
//   • Ver o histórico de saques confirmados
//
// Para o admin (id 1 — o servidor manda 'eh_admin': true e as seções
// extras; para os demais usuários elas simplesmente não existem):
//   • Aprovar o saque de qualquer investidor — o botão faz TUDO que o
//     site faz: vende BTC se faltar BRL, cancela as ordens abertas e
//     pausa o bot por 3 min (tempo do PIX na Binance), com a chave PIX
//     do pedido em destaque para pagar;
//   • Banner "bot pausado" com botão de retomar quando a transferência
//     terminar antes do prazo.
//
// Confirmar saque mexe com dinheiro de verdade, então os botões mais
// perigosos pedem uma confirmação em diálogo antes de chamar a API.
// ─────────────────────────────────────────────────────────────────────────────

import 'package:flutter/material.dart';
import 'package:flutter/services.dart'; // Clipboard (copiar a chave PIX)

import '../modelos/saque.dart';
import '../servicos/api.dart';
import '../tema.dart';
import '../util/formatar.dart';
import 'banner_pausa_bot.dart';
import 'tela_login.dart';

/// Aba da tela "Ações" — sem Scaffold próprio (a AppBar/TabBar ficam
/// na TelaAcoes); é um corpo com RefreshIndicator, como as outras abas.
class AbaSaque extends StatefulWidget {
  const AbaSaque({super.key});

  @override
  State<AbaSaque> createState() => _AbaSaqueState();
}

class _AbaSaqueState extends State<AbaSaque> {
  // ── Estado ────────────────────────────────────────────────────────────────
  DadosSaque? _dados; // null = primeira carga ainda não terminou
  bool _carregando = true;
  String? _erro;

  // Campo do valor do saque (vazio = sacar tudo).
  final _ctrlValor = TextEditingController();

  // Campo da chave PIX de recebimento (prefill com a salva no servidor).
  final _ctrlChave = TextEditingController();

  // Botões de ação se desligam enquanto a requisição corre (sem envio duplo).
  bool _solicitando = false;
  bool _salvandoChave = false;
  int? _processandoId; // id do saque com ação em curso (cancelar/confirmar)

  @override
  void initState() {
    super.initState();
    _carregar();
  }

  @override
  void dispose() {
    _ctrlValor.dispose();
    _ctrlChave.dispose();
    super.dispose();
  }

  /// Busca a aba inteira no servidor (mesmo padrão da Home: loader só na
  /// primeira carga, SnackBar nos erros seguintes, puxão pra atualizar).
  Future<void> _carregar() async {
    if (_dados == null) setState(() => _carregando = true);

    try {
      final dados = await ApiService.telaSaque();
      if (!mounted) return;
      setState(() {
        _dados = dados;
        _erro = null;
        _carregando = false;
        // Prefill da chave só quando o campo está vazio — não atropela
        // quem já está digitando (mesma regra do site).
        if (_ctrlChave.text.isEmpty && (dados.chavePix ?? '').isNotEmpty) {
          _ctrlChave.text = dados.chavePix!;
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

  /// Executa uma ação de saque (solicitar/cancelar/confirmar/retomar) com o
  /// ritual completo: desliga o botão, chama a API, mostra a mensagem do
  /// servidor num SnackBar e recarrega a aba (os números mudaram).
  Future<void> _acao({
    required Future<String> Function() chamada,
    required bool Function() bloqueado,
    required VoidCallback ligar,
    required VoidCallback desligar,
  }) async {
    if (bloqueado()) return; // já tem uma ação correndo

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
      // finally: religa o botão tenha dado certo ou não.
      if (mounted) ligar();
    }
  }

  /// Valor digitado → double. Brasileiro digita "1500,50": trocamos a
  /// vírgula por ponto antes de tentar converter (padrão do Dart é o ponto).
  double? get _valorDigitado {
    final texto = _ctrlValor.text.trim().replaceAll(',', '.');
    if (texto.isEmpty) return null; // vazio = sacar tudo
    return double.tryParse(texto);
  }

  /// SALVAR a chave PIX de recebimento.
  Future<void> _salvarChave() async {
    final chave = _ctrlChave.text.trim();

    if (chave.length < 5) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
            content: Text('A chave PIX deve ter pelo menos 5 caracteres.'),
            backgroundColor: Cores.vermelho),
      );
      return;
    }

    await _acao(
      chamada: () => ApiService.salvarChavePix(chave),
      bloqueado: () => _salvandoChave,
      ligar: () => setState(() => _salvandoChave = false),
      desligar: () => setState(() => _salvandoChave = true),
    );
  }

  /// SOLICITAR saque — com diálogo de confirmação (é dinheiro saindo).
  Future<void> _solicitar() async {
    final valor = _valorDigitado;

    // Sem chave cadastrada o servidor recusa (422) — o aviso local chega
    // antes, já apontando o campo que falta preencher.
    if (_ctrlChave.text.trim().isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
            content:
                Text('Cadastre sua chave PIX antes de sacar — o saque é pago nela.'),
            backgroundColor: Cores.vermelho),
      );
      return;
    }

    // Digitou algo que não vira número? Avisa antes de chamar o servidor.
    if (_ctrlValor.text.trim().isNotEmpty && valor == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
            content: Text('Valor inválido — use só números (ex.: 1500,50).'),
            backgroundColor: Cores.vermelho),
      );
      return;
    }

    // Confirmação: mostra o que vai ser solicitado.
    final prosseguir = await _confirmar(
      titulo: 'Solicitar saque',
      mensagem: valor == null
          ? 'Sacar TODO o seu saldo disponível (${moeda(_dados?.disponivel ?? 0)})?'
          : 'Solicitar ${moeda(valor)}?',
      botao: 'Solicitar',
    );
    if (prosseguir != true) return;

    await _acao(
      chamada: () => ApiService.solicitarSaque(valor: valor),
      bloqueado: () => _solicitando,
      ligar: () => setState(() => _solicitando = false),
      desligar: () => setState(() => _solicitando = true),
    );

    // Limpa o campo depois — o saque já foi pedido, o número não serve mais.
    if (mounted) setState(() => _ctrlValor.clear());
  }

  /// CANCELAR um saque pendente meu (o servidor devolve as cotas).
  Future<void> _cancelar(SaqueMeu s) async {
    await _acao(
      chamada: () => ApiService.cancelarSaque(s.id),
      bloqueado: () => _processandoId != null,
      ligar: () => setState(() => _processandoId = null),
      desligar: () => setState(() => _processandoId = s.id),
    );
  }

  /// CONFIRMAR um saque como admin — o diálogo avisa o que vai acontecer,
  /// porque esse botão pode vender BTC e pausar o bot.
  Future<void> _confirmarSaqueAdmin(SaqueAprovacao s) async {
    final prosseguir = await _confirmar(
      titulo: 'Confirmar saque',
      mensagem: 'Confirmar o PIX de ${moeda(s.valorLiquido)} para ${s.nome}?\n\n'
          'Chave PIX: ${s.chavePix}\n\n'
          'Se faltar BRL, o bot vende BTC a mercado, cancela as ordens '
          'abertas e pausa por 3 minutos para a transferência.',
      botao: 'Confirmar PIX',
    );
    if (prosseguir != true) return;

    await _acao(
      chamada: () => ApiService.confirmarSaque(s.id),
      bloqueado: () => _processandoId != null,
      ligar: () => setState(() => _processandoId = null),
      desligar: () => setState(() => _processandoId = s.id),
    );
  }

  /// RETOMAR o bot da pausa (transferência terminou antes dos 3 min).
  Future<void> _retomarBot() async {
    await _acao(
      chamada: ApiService.retomarBot,
      bloqueado: () => _processandoId != null,
      ligar: () => setState(() => _processandoId = null),
      desligar: () => setState(() => _processandoId = -1), // -1 = banner
    );
  }

  /// Copia a chave PIX de um pedido (o admin paga colando no banco).
  void _copiarChave(String chave) {
    Clipboard.setData(ClipboardData(text: chave));
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(
          content: Text('Chave PIX copiada.'), backgroundColor: Cores.verde),
    );
  }

  /// Diálogo sim/não genérico. Devolve true (confirmou), false (negou) ou
  /// null (fechou tocando fora) — por isso o `bool?`.
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

  /// A lista completa da aba de saques.
  Widget _lista(DadosSaque d) {
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 20),
      children: [
        // ── Banner do bot pausado (só aparece pro admin com pausa ativa) ──
        if (d.ehAdmin && (d.pausa?.pausado ?? false))
          BannerPausaBot(
            pausa: d.pausa!,
            onRetomar: _retomarBot,
            ocupado: _processandoId != null,
          ),

        _tituloSecao('Minha chave PIX', Icons.vpn_key_outlined),
        _cardChave(),

        _tituloSecao('Solicitar saque', Icons.outbox_outlined),
        _cardSolicitar(d),

        _tituloSecao('Meus saques pendentes', Icons.schedule),
        if (d.pendentes.isEmpty)
          const _CardVazio(texto: 'Nenhum saque pendente.')
        else
          ...d.pendentes.map(_cardPendente),

        _tituloSecao('Histórico', Icons.history),
        if (d.historico.isEmpty)
          const _CardVazio(texto: 'Nenhum saque confirmado ainda.')
        else
          ...d.historico.map(_cardHistorico),

        // ── Seções do admin — o servidor nem envia os dados pros demais ──
        if (d.ehAdmin) ...[
          _tituloSecao('Aguardando sua aprovação', Icons.verified_outlined),
          if (d.aprovacoes.isEmpty)
            const _CardVazio(texto: 'Nenhum saque aguardando aprovação.')
          else
            ...d.aprovacoes.map(_cardAprovacao),
        ],

        const SizedBox(height: 48),
      ],
    );
  }

  /// Card da chave PIX de recebimento — onde o admin paga os saques.
  Widget _cardChave() {
    final preenchida = _ctrlChave.text.trim().isNotEmpty;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            TextField(
              controller: _ctrlChave,
              keyboardType: TextInputType.text,
              decoration: const InputDecoration(
                labelText: 'Chave PIX de recebimento',
                prefixIcon: Icon(Icons.vpn_key_outlined),
                hintText: 'CPF, e-mail, telefone ou chave aleatória',
              ),
            ),
            const SizedBox(height: 6),
            Text(
              preenchida
                  ? 'Os saques são pagos pelo administrador nesta chave.'
                  : 'Cadastre sua chave PIX — ela é exigida para solicitar saques.',
              style: const TextStyle(fontSize: 11, color: Cores.textoSuave),
            ),
            const SizedBox(height: 10),
            SizedBox(
              width: double.infinity,
              child: FilledButton.icon(
                onPressed: _salvandoChave ? null : _salvarChave,
                icon: _salvandoChave
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.save_outlined),
                label:
                    Text(_salvandoChave ? 'Salvando...' : 'Salvar chave PIX'),
              ),
            ),
          ],
        ),
      ),
    );
  }

  /// Card de solicitação: saldo disponível + campo de valor + botão.
  Widget _cardSolicitar(DadosSaque d) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Expanded(
                  child: Text('Disponível para saque',
                      style: TextStyle(fontSize: 12, color: Cores.textoSuave)),
                ),
                // Verde como o "Total Geral" da Home: é o seu dinheiro.
                Text(moeda(d.disponivel),
                    style: const TextStyle(
                        fontSize: 15, fontWeight: FontWeight.w700, color: Cores.verde)),
              ],
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _ctrlValor,
              // Teclado com vírgula decimal (padrão brasileiro).
              keyboardType:
                  const TextInputType.numberWithOptions(decimal: true),
              decoration: const InputDecoration(
                labelText: 'Valor (R\$)', // \$: escapar o cifrão (senão o Dart acha que é interpolação)
                prefixIcon: Icon(Icons.payments_outlined),
                // Deixar vazio é uma escolha válida: saca tudo.
                hintText: 'Deixe vazio para sacar tudo',
              ),
              onSubmitted: (_) => _solicitando ? null : _solicitar(),
            ),
            const SizedBox(height: 12),
            SizedBox(
              width: double.infinity, // botão de largura total, como no site
              child: FilledButton.icon(
                onPressed: _solicitando ? null : _solicitar,
                icon: _solicitando
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.outbox_outlined),
                label: Text(_solicitando ? 'Solicitando...' : 'Solicitar saque'),
              ),
            ),
          ],
        ),
      ),
    );
  }

  /// Card de um saque MEU pendente, com botão de cancelar.
  Widget _cardPendente(SaqueMeu s) {
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        child: Row(
          children: [
            // Badge dourado "Pendente" — aguardando o admin.
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
              decoration: BoxDecoration(
                color: Cores.dourado.withValues(alpha: 0.14),
                borderRadius: BorderRadius.circular(6),
              ),
              child: const Text('Pendente',
                  style: TextStyle(
                      fontSize: 11.5,
                      fontWeight: FontWeight.w700,
                      color: Cores.dourado)),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(moeda(s.valorBruto),
                      style: const TextStyle(fontWeight: FontWeight.w600)),
                  Text(
                    'Líquido: ${moeda(s.valorLiquido)} · ${s.criadoEm}',
                    style:
                        const TextStyle(fontSize: 11, color: Cores.textoSuave),
                  ),
                ],
              ),
            ),
            // Cancelar é recuperável (devolve as cotas): sem diálogo.
            _processandoId == s.id
                ? const SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : TextButton(
                    onPressed: _processandoId != null ? null : () => _cancelar(s),
                    style: TextButton.styleFrom(foregroundColor: Cores.vermelho),
                    child: const Text('Cancelar'),
                  ),
          ],
        ),
      ),
    );
  }

  /// Linha do histórico (saque já confirmado — dinheiro já saiu).
  Widget _cardHistorico(SaqueHistorico s) {
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        child: Row(
          children: [
            const Icon(Icons.check_circle_outline,
                size: 18, color: Cores.verde),
            const SizedBox(width: 10),
            Expanded(
              child: Text('Recebido em ${s.confirmadoEm}',
                  style: const TextStyle(color: Cores.textoSuave)),
            ),
            Text(moeda(s.valorLiquido),
                style: const TextStyle(fontWeight: FontWeight.w600)),
          ],
        ),
      ),
    );
  }

  /// Card de aprovação (admin): quem pediu, quanto, a chave PIX (pra pagar
  /// direto do app do banco, colando) e o botão que decide.
  Widget _cardAprovacao(SaqueAprovacao s) {
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
                      Text(s.nome,
                          style: const TextStyle(fontWeight: FontWeight.w700)),
                      Text(s.email,
                          style: const TextStyle(
                              fontSize: 11, color: Cores.textoSuave)),
                    ],
                  ),
                ),
                Text(s.criadoEm,
                    style:
                        const TextStyle(fontSize: 11, color: Cores.textoSuave)),
              ],
            ),
            const SizedBox(height: 8),
            // A chave PIX em destaque: é onde o admin paga o saque.
            InkWell(
              onTap: s.chavePix == '—' ? null : () => _copiarChave(s.chavePix),
              borderRadius: BorderRadius.circular(8),
              child: Container(
                width: double.infinity,
                padding:
                    const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                decoration: BoxDecoration(
                  color: Cores.dourado.withValues(alpha: 0.10),
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: Cores.dourado.withValues(alpha: 0.35)),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.vpn_key_outlined,
                        size: 16, color: Cores.dourado),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        'PIX: ${s.chavePix}',
                        style: const TextStyle(
                            fontSize: 12,
                            fontWeight: FontWeight.w600,
                            color: Cores.dourado),
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    if (s.chavePix != '—') ...[
                      const SizedBox(width: 8),
                      const Icon(Icons.copy, size: 16, color: Cores.dourado),
                    ],
                  ],
                ),
              ),
            ),
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(
                  child: _miniInfo('Bruto', moeda(s.valorBruto)),
                ),
                Expanded(
                  child: _miniInfo('Líquido (PIX)', moeda(s.valorLiquido)),
                ),
                Expanded(
                  child: _miniInfo('Cotas', s.cotas.toStringAsFixed(2)),
                ),
              ],
            ),
            const SizedBox(height: 10),
            SizedBox(
              width: double.infinity,
              child: FilledButton.icon(
                onPressed: _processandoId != null
                    ? null
                    : () => _confirmarSaqueAdmin(s),
                icon: _processandoId == s.id
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.task_alt_outlined),
                label: Text(
                    _processandoId == s.id ? 'Confirmando...' : 'Confirmar PIX'),
              ),
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
