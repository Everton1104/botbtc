// Teste de widget — versão "smoke test" do app real.
//
// Smoke test: verifica o mínimo para o app estar "respirando" — abre sem
// quebrar e a tela de login aparece com os campos esperados.
//
// Nota: passamos `iniciaLogado: false` para forçar a tela de login sem
// depender do armazenamento (que não existe direito num ambiente de teste).
// O plugin shared_preferences, por isso, é substituído por uma versão falsa
// (mock) antes de construir o widget.

import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:botbtc_app/main.dart';

void main() {
  testWidgets('App abre na tela de login', (WidgetTester tester) async {
    // O shared_preferences real conversa com o Android; nos testes,
    // inicializamos com valores fake para simular o "caderninho" vazio.
    SharedPreferences.setMockInitialValues({});

    // Monta o app inteiro.
    await tester.pumpWidget(const BotbtcApp(iniciaLogado: false));

    // Um frame normal; o segundo com duração extra dá tempo de animações
    // iniciais terminarem (pumpAndSettle esperaria a rede — não queremos).
    await tester.pump();

    // A tela de login precisa mostrar título e o botão de entrar.
    expect(find.text('Acesse sua conta'), findsOneWidget);
    expect(find.text('Entrar'), findsOneWidget);
    expect(find.text('E-mail'), findsOneWidget);
    expect(find.text('Senha'), findsOneWidget);
  });
}
