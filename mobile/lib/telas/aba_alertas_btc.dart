// ─────────────────────────────────────────────────────────────────────────────
// ABA ALERTAS DE PREÇO — "avisa quando o BTC chegar a X".
//
// O admin cria um alerta (valor + acima/abaixo); o ciclo do bot (≤1 min)
// confere os pendentes contra o ticker e manda UM push quando o preço
// cruza o alvo. Depois disso o alerta fica aqui como histórico, apagável.
// — o mesmo padrão das outras abas: carrega, trata 401, puxão atualiza.
// ─────────────────────────────────────────────────────────────────────────────

import 'package:flutter/material.dart';

import '../modelos/admin.dart';
import '../servicos/api.dart';
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

  @override
  void initState() {
    super.initState();
    _carregar();
  }

  @override
  void dispose() {
    _ctrAlvo.dispose();
    super.dispose();
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
