// ─────────────────────────────────────────────────────────────────────────────
// TELA DE LOGIN — email + senha, com validação e tratamento de erros.
//
// Conceitos de Dart/Flutter usados aqui:
//   • StatefulWidget: tela que MUDA de estado (campo digitado, carregando,
//     mensagem de erro). Telas paradas poderiam ser StatelessWidget.
//   • TextEditingController: "controla" o texto de um campo; lemos
//     controller.text para saber o que foi digitado.
//   • Form + GlobalKey<FormState>: ligam os validadores dos campos ao botão
//     (formKey.currentState!.validate() roda todos os validators de uma vez).
//   • dispose(): devolve os recursos dos controllers quando a tela morre —
//     obrigatório para não vazar memória.
// ─────────────────────────────────────────────────────────────────────────────

import 'package:flutter/material.dart';

import '../servicos/api.dart';
import 'tela_home.dart';

class TelaLogin extends StatefulWidget {
  const TelaLogin({super.key});

  @override
  State<TelaLogin> createState() => _TelaLoginState();
}

class _TelaLoginState extends State<TelaLogin> {
  // A chave que conecta o botão "Entrar" aos validadores do Form.
  final _formKey = GlobalKey<FormState>();

  // Um controller por campo de texto.
  final _emailController = TextEditingController();
  final _senhaController = TextEditingController();

  // ── Estado da tela (o que pode mudar enquanto ela está aberta) ──────────
  bool _carregando = false; // true enquanto a requisição de login roda
  bool _mostrarSenha = false; // alterna o olhinho 👁 do campo de senha
  String? _erro; // mensagem de erro da API, exibida em vermelho

  /// Roda quando a tela é destruída: libera os controllers.
  /// Se esquecer isso, o Dart mantém os objetos na memória (vazamento).
  @override
  void dispose() {
    _emailController.dispose();
    _senhaController.dispose();
    super.dispose();
  }

  /// Envia o formulário: valida os campos e chama a API.
  Future<void> _entrar() async {
    // Primeiro os validadores locais (campo vazio, email malformado...).
    // Se algum reclamar, validate() retorna false e nem chamamos a API.
    if (!_formKey.currentState!.validate()) return;

    // setState() avisa o Flutter: "o estado mudou, redesenhe a tela".
    // É assim que o botão vira um loader e a mensagem de erro aparece/some.
    setState(() {
      _carregando = true;
      _erro = null;
    });

    try {
      // Chama a API. Se email/senha estiverem errados, ApiService joga
      // ApiException — que capturamos logo abaixo.
      await ApiService.login(
        email: _emailController.text.trim(), // trim() tira espaços das pontas
        senha: _senhaController.text,
      );

      // Se chegou aqui, logou! Troca a tela de login pela Home.
      //
      // O `if (!mounted) return;` é uma proteção: enquanto a requisição
      // corria, o usuário pode ter fechado esta tela — usar um contexto
      // de tela morta causaria erro. "mounted" pergunta: ainda estou viva?
      if (!mounted) return;
      Navigator.of(context).pushReplacement(
        MaterialPageRoute(builder: (_) => const TelaHome()),
      );
    } on ApiException catch (e) {
      // Erro de negócio (senha errada, muitas tentativas...): mostra a
      // mensagem que o servidor mandou.
      setState(() => _erro = e.toString());
    } catch (_) {
      // Qualquer outro erro (servidor fora do ar, rede caiu...): mensagem
      // genérica, pois não há o que o usuário corrija no formulário.
      setState(() => _erro = 'Não foi possível conectar ao servidor.');
    } finally {
      // finally: sempre desliga o carregamento, deu certo ou não.
      if (mounted) setState(() => _carregando = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      // SafeArea respeita o notch/status bar do aparelho.
      body: SafeArea(
        // Center + SingleChildScrollView: centraliza o formulário e permite
        // rolar quando o teclado abre (senão o teclado cobre os campos).
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: Form(
              key: _formKey,
              child: Column(
                // mainAxisSize + spacing manual mantêm tudo centrado.
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  // ── Logo/título ────────────────────────────────────────
                  const Icon(Icons.currency_bitcoin, size: 72, color: Color(0xFFF7931A)),
                  const SizedBox(height: 8),
                  Text(
                    'BotBTC',
                    textAlign: TextAlign.center,
                    style: Theme.of(context).textTheme.headlineMedium?.copyWith(
                          fontWeight: FontWeight.bold,
                        ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    'Acesse sua conta',
                    textAlign: TextAlign.center,
                    style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                          color: Colors.grey,
                        ),
                  ),
                  const SizedBox(height: 32),

                  // ── Campo: e-mail ──────────────────────────────────────
                  TextFormField(
                    controller: _emailController,
                    keyboardType: TextInputType.emailAddress, // teclado com @
                    autofillHints: const [AutofillHints.email], // oferece salvar
                    decoration: const InputDecoration(
                      labelText: 'E-mail',
                      prefixIcon: Icon(Icons.email_outlined),
                      border: OutlineInputBorder(),
                    ),
                    // validator devolve uma String (a reclamação) ou null (ok).
                    validator: (valor) {
                      if (valor == null || valor.trim().isEmpty) {
                        return 'Informe o e-mail.';
                      }
                      if (!valor.contains('@') || !valor.contains('.')) {
                        return 'E-mail inválido.';
                      }
                      return null;
                    },
                  ),
                  const SizedBox(height: 16),

                  // ── Campo: senha (com olhinho para mostrar/ocultar) ────
                  TextFormField(
                    controller: _senhaController,
                    obscureText: !_mostrarSenha, // esconde com bolinhas ●●●
                    autofillHints: const [AutofillHints.password],
                    decoration: InputDecoration(
                      labelText: 'Senha',
                      prefixIcon: const Icon(Icons.lock_outline),
                      border: const OutlineInputBorder(),
                      // O suffix é o botão do olhinho, à direita do campo.
                      suffixIcon: IconButton(
                        icon: Icon(
                          _mostrarSenha ? Icons.visibility_off : Icons.visibility,
                        ),
                        tooltip: _mostrarSenha ? 'Ocultar senha' : 'Mostrar senha',
                        onPressed: () => setState(() => _mostrarSenha = !_mostrarSenha),
                      ),
                    ),
                    validator: (valor) {
                      if (valor == null || valor.isEmpty) {
                        return 'Informe a senha.';
                      }
                      return null;
                    },
                    // Enter no teclado já envia o formulário.
                    onFieldSubmitted: (_) => _carregando ? null : _entrar(),
                  ),
                  const SizedBox(height: 8),

                  // ── Mensagem de erro (só existe quando _erro != null) ──
                  if (_erro != null) ...[
                    const SizedBox(height: 8),
                    Text(
                      _erro!,
                      style: const TextStyle(color: Colors.red),
                      textAlign: TextAlign.center,
                    ),
                  ],
                  const SizedBox(height: 24),

                  // ── Botão Entrar / loader enquanto carrega ─────────────
                  // Enquanto _carregando é true, o botão vira uma animação
                  // e fica desabilitado (evita envio duplo).
                  FilledButton.icon(
                    onPressed: _carregando ? null : _entrar,
                    icon: _carregando
                        ? const SizedBox(
                            width: 18,
                            height: 18,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(Icons.login),
                    label: Text(_carregando ? 'Entrando...' : 'Entrar'),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
