// ─────────────────────────────────────────────────────────────────────────────
// PONTO DE ENTRADA DO APP
//
// O que acontece quando você toca no ícone:
//   1. main() roda (é o "begin" do programa).
//   2. Antes de criar qualquer widget, perguntamos ao armazenamento local
//      se existe um token salvo de uma sessão anterior.
//   3. Com token → abre direto na Home. Sem token → abre no Login.
//
// WidgetsFlutterBinding.ensureInitialized() é obrigatório quando usamos
// plugins (como o shared_preferences) ANTES do runApp — sem isso, o plugin
// não encontra a "ponte" com o Android e o app quebra na largada.
// ─────────────────────────────────────────────────────────────────────────────

import 'package:flutter/material.dart';

import 'servicos/api.dart';
import 'telas/tela_home.dart';
import 'telas/tela_login.dart';

Future<void> main() async {
  // Liga a infraestrutura de plugins antes de qualquer uso.
  WidgetsFlutterBinding.ensureInitialized();

  // Já logou outra vez? O token fica salvo no aparelho mesmo com o app
  // fechado — é por isso que você não digita a senha toda vez.
  final bool temToken = await ApiService.temTokenSalvo();

  runApp(BotbtcApp(iniciaLogado: temToken));
}

/// O widget raiz. StatelessWidget porque a escolha da primeira tela é
/// feita UMA vez, na construção — depois disso ele nunca muda.
class BotbtcApp extends StatelessWidget {
  final bool iniciaLogado;

  const BotbtcApp({super.key, required this.iniciaLogado});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'BotBTC',
      debugShowCheckedModeBanner: false, // tira a faixa "debug" do canto

      // Material 3 é o visual atual do Flutter. A cor semente (laranja
      // bitcoin #F7931A) gera automaticamente todo o esquema de cores —
      // botões, campos e realces herdam tons dela.
      theme: ThemeData(
        useMaterial3: true,
        colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xFFF7931A)),
      ),

      // A porta de entrada do app: Home se já tem token, Login se não.
      home: iniciaLogado ? const TelaHome() : const TelaLogin(),
    );
  }
}
