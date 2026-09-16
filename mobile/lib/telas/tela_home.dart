// ─────────────────────────────────────────────────────────────────────────────
// TELA HOME — primeira tela depois do login.
//
// Por enquanto mostra os dados da conta (buscados de /api/me) e o botão de
// sair. É o esqueleto onde as próximas funcionalidades vão morar (saldos,
// ordens, painel do bot...), espelhando o site.
//
// Conceito novo: FutureBuilder — widget que recebe um Future (a promessa de
// um Usuario) e desenha uma coisa enquanto espera (loader), outra se deu
// erro, e a certa quando os dados chegam. Sem setState manual.
// ─────────────────────────────────────────────────────────────────────────────

import 'package:flutter/material.dart';

import '../servicos/api.dart';
import '../tema.dart';
import 'tela_login.dart';

class TelaHome extends StatefulWidget {
  const TelaHome({super.key});

  @override
  State<TelaHome> createState() => _TelaHomeState();
}

class _TelaHomeState extends State<TelaHome> {
  // O Future que o FutureBuilder observa. É um campo (e não uma variável
  // local no build) por um motivo importante: se estivesse dentro do build,
  // CADA redesenho dispararia uma nova requisição. Como campo, a chamada
  // acontece uma vez, quando a tela é criada.
  late final Future<Usuario> _futureUsuario = ApiService.me();

  /// Faz logout e volta para a tela de login.
  Future<void> _sair() async {
    await ApiService.logout();

    if (!mounted) return;
    // pushReplacement: empilha a Login SUBSTITUINDO a Home — o botão de
    // voltar do Android não pode devolver o usuário para trás do logout.
    Navigator.of(context).pushReplacement(
      MaterialPageRoute(builder: (_) => const TelaLogin()),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('BotBTC'),
        // Ações da barra: ficam à direita, junto ao título.
        actions: [
          IconButton(
            icon: const Icon(Icons.logout),
            tooltip: 'Sair',
            onPressed: _sair,
          ),
        ],
      ),
      body: FutureBuilder<Usuario>(
        future: _futureUsuario,
        builder: (context, snapshot) {
          // snapshot.connectionState diz em que ponto a promessa está.
          if (snapshot.connectionState == ConnectionState.waiting) {
            // Ainda esperando a resposta: loader centralizado.
            return const Center(child: CircularProgressIndicator());
          }

          // Erro inclui ApiException 401 (token revogado no servidor) —
          // nesse caso o token salvo não presta mais; limpar e voltar pro
          // login é o comportamento correto.
          if (snapshot.hasError) {
            final erro = snapshot.error;
            if (erro is ApiException && erro.status == 401) {
              // WidgetsBinding: agenda a troca de tela após o build atual.
              WidgetsBinding.instance.addPostFrameCallback((_) => _tokenInvalido());
              return const Center(child: Text('Sessão expirada.'));
            }
            return Center(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Icon(Icons.wifi_off, size: 48, color: Cores.textoSuave),
                  const SizedBox(height: 8),
                  Text('$erro', textAlign: TextAlign.center),
                ],
              ),
            );
          }

          // Dados chegaram: snapshot.data é o Usuario.
          final usuario = snapshot.data!;
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          CircleAvatar(
                            // Iniciais do nome: "Everton" → "E". Fundo
                            // dourado com letra escura, na linha do site.
                            backgroundColor: Cores.dourado,
                            foregroundColor: Cores.fundo,
                            child: Text(usuario.nome.isNotEmpty ? usuario.nome[0] : '?'),
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  usuario.nome,
                                  style: Theme.of(context).textTheme.titleMedium,
                                ),
                                Text(usuario.email, style: const TextStyle(color: Cores.textoSuave)),
                              ],
                            ),
                          ),
                        ],
                      ),
                      const Divider(height: 24),
                      _linha('WhatsApp', usuario.whatsapp ?? 'não cadastrado'),
                      const SizedBox(height: 4),
                      // A linha do status de verificação é um widget próprio
                      // porque mistura texto + ícone colorido. Verde/vermelho
                      // são os mesmos --green e --red do site.
                      Row(
                        children: [
                          const Text('Status:  '),
                          Icon(
                            usuario.whatsappVerificado
                                ? Icons.verified
                                : Icons.warning_amber_rounded,
                            size: 18,
                            color: usuario.whatsappVerificado ? Cores.verde : Cores.dourado,
                          ),
                          const SizedBox(width: 4),
                          Text(
                            usuario.whatsappVerificado ? 'verificado' : 'verificação pendente',
                            style: TextStyle(
                              color: usuario.whatsappVerificado ? Cores.verde : Cores.dourado,
                              fontWeight: FontWeight.w500,
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 16),
              // Placeholder do que vem a seguir — o app vai espelhar o site.
              const Card(
                child: Padding(
                  padding: EdgeInsets.all(16),
                  child: Text(
                    'Em breve: saldos, ordens e o painel do bot — '
                    'espelhando o site pela API.',
                    style: TextStyle(color: Cores.textoSuave),
                  ),
                ),
              ),
            ],
          );
        },
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

  /// Linha simples "rótulo: valor" para o card de dados.
  Widget _linha(String rotulo, String valor) {
    return Row(
      children: [
        Text('$rotulo:  '),
        Expanded(child: Text(valor)),
      ],
    );
  }
}
