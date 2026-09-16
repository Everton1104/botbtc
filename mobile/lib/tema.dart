// ─────────────────────────────────────────────────────────────────────────────
// TEMA DO APP — as mesmas cores da versão web.
//
// O site define o design dele em variáveis CSS (no layouts/app.blade.php).
// Copiamos os mesmos valores hexadecimais para cá, com os mesmos nomes
// traduzidos, para o app ter a identidade visual do BotBTC:
//
//   Site (CSS)          →  App (Dart)
//   --bg:       #0d1117 →  fundo        (azul-escuro quase preto)
//   --surface:  #161b27 →  cartao       (fundo dos cards)
//   --surface2: #1e2537 →  superficie2  (campos, elevação)
//   --border:   #2a3148 →  borda
//   --gold:     #f0b90b →  dourado      (cor principal, amarelo Binance)
//   --green:    #00d68f →  verde        (alta / compra / sucesso)
//   --red:      #ff4757 →  vermelho     (baixa / venda / erro)
//   --text:     #e2e8f4 →  texto        (texto principal)
//   --muted:    #8892a8 →  textoSuave   (texto secundário)
// ─────────────────────────────────────────────────────────────────────────────

import 'package:flutter/material.dart';

/// Paleta do BotBTC — uma constante por variável do site.
/// `const` + `static` = valor fixo guardado uma única vez na memória.
abstract final class Cores {
  static const Color fundo = Color(0xFF0d1117);
  static const Color cartao = Color(0xFF161b27);
  static const Color superficie2 = Color(0xFF1e2537);
  static const Color borda = Color(0xFF2a3148);
  static const Color dourado = Color(0xFFf0b90b);
  static const Color verde = Color(0xFF00d68f);
  static const Color vermelho = Color(0xFFff4757);
  static const Color texto = Color(0xFFe2e8f4);
  static const Color textoSuave = Color(0xFF8892a8);
}

/// Monta o ThemeData (o "tema" que o MaterialApp aplica em tudo).
///
/// Em vez de ColorScheme.fromSeed (que INVENTA tons a partir de uma semente),
/// construímos o ColorScheme na mão com as cores do site — o resultado é
/// fiel ao que já existe na web.
ThemeData temaBotbtc() {
  const scheme = ColorScheme.dark(
    primary: Cores.dourado,
    // Texto sobre o dourado: escuro, porque amarelo + branco não lê.
    onPrimary: Cores.fundo,
    secondary: Cores.verde,
    onSecondary: Cores.fundo,
    surface: Cores.cartao,
    onSurface: Cores.texto,
    error: Cores.vermelho,
    onError: Colors.white,
  );

  return ThemeData(
    useMaterial3: true,
    colorScheme: scheme,

    // Fundo geral de cada tela (o "body" do site).
    scaffoldBackgroundColor: Cores.fundo,

    // Barra superior com a mesma cara do site: escura, título claro.
    appBarTheme: const AppBarTheme(
      backgroundColor: Cores.cartao,
      foregroundColor: Cores.texto,
      elevation: 0,
      centerTitle: false,
    ),

    // Cards usando --surface com a borda sutil --border.
    cardTheme: const CardThemeData(
      color: Cores.cartao,
      surfaceTintColor: Colors.transparent, // evita tint do Material 3
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.all(Radius.circular(12)),
        side: BorderSide(color: Cores.borda),
      ),
    ),

    // Campos de texto: fundo --surface2, borda --border; ao focar, dourado.
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: Cores.superficie2,
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: const BorderSide(color: Cores.borda),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: const BorderSide(color: Cores.borda),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: const BorderSide(color: Cores.dourado, width: 1.6),
      ),
      errorBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: const BorderSide(color: Cores.vermelho),
      ),
      focusedErrorBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: const BorderSide(color: Cores.vermelho, width: 1.6),
      ),
      labelStyle: const TextStyle(color: Cores.textoSuave),
      prefixIconColor: Cores.textoSuave,
      suffixIconColor: Cores.textoSuave,
    ),

    // Botões cheios (FilledButton): dourados, como os CTAs do site.
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        backgroundColor: Cores.dourado,
        foregroundColor: Cores.fundo,
        textStyle: const TextStyle(fontWeight: FontWeight.w600),
        padding: const EdgeInsets.symmetric(vertical: 14),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
      ),
    ),

    // Botões de ícone (o "Sair" da AppBar) discretos.
    iconButtonTheme: IconButtonThemeData(
      style: IconButton.styleFrom(foregroundColor: Cores.textoSuave),
    ),

    // Loader na cor principal.
    progressIndicatorTheme: const ProgressIndicatorThemeData(color: Cores.dourado),

    // Divisores com a cor de borda do site.
    dividerTheme: const DividerThemeData(color: Cores.borda, thickness: 1),
  );
}
