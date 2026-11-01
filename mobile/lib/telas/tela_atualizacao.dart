// ─────────────────────────────────────────────────────────────────────────────
// TELA DE ATUALIZAÇÃO OBRIGATÓRIA — bloqueia o app quando a versão instalada
// é menor que a versão mínima que o servidor aceita (GET /api/versao-app).
//
// O fluxo para quem cai aqui é todo DENTRO do app (sem site, sem navegador):
//   1. "Baixar atualização" → o APK novo é baixado com barra de progresso
//      (servicos/atualizacao.dart) — o APK direto é público no servidor.
//   2. "Instalar atualização" → dispara o instalador do sistema. Na PRIMEIRA
//      vez o Android pede "permitir instalar apps desta fonte" (uma vez só);
//      depois é só o "Atualizar app?" normal — instalação POR CIMA, que
//      mantém login, token e dados (mesma assinatura/keystore).
//   3. Depois de atualizado, o app reabre já liberado — ou o botão
//      "Já atualizei" re-verifica na hora.
//
// Plano B: "Baixar pelo site" abre a página /download no navegador (login
// com WhatsApp verificado lá) — pra quem preferir, ou se algo no fluxo
// interno falhar (permissão negada, rede instável...).
//
// Não há como "pular" esta tela: ela é a home do app enquanto a versão
// estiver abaixo da mínima — sem ela, não se chega ao login nem à Home.
// ─────────────────────────────────────────────────────────────────────────────

import 'dart:io' show HttpException;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart'
    show MissingPluginException, PlatformException;
import 'package:url_launcher/url_launcher.dart';

import '../servicos/api.dart';
import '../servicos/atualizacao.dart';
import '../tema.dart';
import 'tela_home.dart';
import 'tela_login.dart';

class TelaAtualizacao extends StatefulWidget {
  final String versaoMinima; // a versão que o servidor está exigindo
  final String urlApk; // APK direto (do /api/versao-app, com fallback)

  const TelaAtualizacao({
    super.key,
    required this.versaoMinima,
    required this.urlApk,
  });

  @override
  State<TelaAtualizacao> createState() => _TelaAtualizacaoState();
}

class _TelaAtualizacaoState extends State<TelaAtualizacao> {
  bool _verificando = false; // true enquanto o "Já atualizei" re-checa
  bool _baixando = false; // download em andamento
  int _recebidos = 0; // bytes baixados até agora
  int _total = 0; // tamanho total em bytes (0 = desconhecido)
  String? _apkPath; // baixado e pronto pra instalar

  /// Página de download do SITE (plano B): exige login com WhatsApp
  /// verificado — sem sessão no navegador, o cliente loga ali uma vez.
  String get _urlDownload => '$kBaseUrl/download';

  /// BAIXA o APK novo de dentro do app (com progresso) e deixa pronto o
  /// botão de instalar. Erros voltam como SnackBar — o plano B (site)
  /// segue disponível embaixo.
  Future<void> _baixarAtualizacao() async {
    if (_baixando) return;

    setState(() {
      _baixando = true;
      _apkPath = null;
      _recebidos = 0;
      _total = 0;
    });

    try {
      final path = await baixarApk(widget.urlApk, (recebidos, total) {
        if (mounted) {
          setState(() {
            _recebidos = recebidos;
            _total = total;
          });
        }
      });
      if (!mounted) return;
      setState(() => _apkPath = path);
    } catch (e) {
      if (!mounted) return;
      _avisar(e is HttpException
          ? '${e.message} — tente de novo ou baixe pelo site.'
          : 'Não foi possível baixar. Verifique a internet ou baixe pelo site.');
    } finally {
      if (mounted) setState(() => _baixando = false);
    }
  }

  /// DISPARA o instalador do sistema sobre o APK baixado (canal nativo no
  /// MainActivity). O "Instalar?" em si é o diálogo do Android; se for a
  /// primeira vez, ele pede a permissão "instalar apps desta fonte".
  Future<void> _instalarAtualizacao() async {
    final path = _apkPath;
    if (path == null) return;

    try {
      await instalarApk(path);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
              'Instalador aberto — confirme a atualização na tela do Android.'),
          backgroundColor: Cores.verde,
        ),
      );
    } on PlatformException catch (e) {
      if (!mounted) return;
      _avisar('Não foi possível abrir o instalador: ${e.message}');
    } on MissingPluginException {
      // Canal não registrado (build sem o código nativo) — plano B.
      if (!mounted) return;
      _avisar('Instalador indisponível nesta versão — baixe pelo site.');
    }
  }

  /// Plano B: a página de download do site no navegador.
  Future<void> _baixarPeloSite() async {
    try {
      final abriu = await launchUrl(
        Uri.parse(_urlDownload),
        mode: LaunchMode.externalApplication,
      );
      if (!abriu && mounted) _avisar('Não foi possível abrir o navegador.');
    } catch (_) {
      if (mounted) _avisar('Não foi possível abrir o navegador.');
    }
  }

  void _avisar(String msg) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(msg), backgroundColor: Cores.vermelho),
    );
  }

  /// Re-pergunta o servidor — cobre o caso de a mínima ter baixado, ou de
  /// o usuário ter atualizado sem fechar o app por completo.
  Future<void> _verificarDeNovo() async {
    setState(() => _verificando = true);
    final resp = await ApiService.verificarVersaoApp();
    if (!mounted) return;

    if (resp == null) {
      setState(() => _verificando = false);
      _avisar('Sem resposta do servidor — conecte-se à internet e tente de novo.');
      return;
    }

    final minima = (resp['versao_minima'] ?? '') as String;
    if (versaoMaiorOuIgual(kAppVersao, minima)) {
      // Liberado: segue para a tela certa, como se o app tivesse acabado
      // de abrir (token salvo → Home; sem token → Login).
      final temToken = await ApiService.temTokenSalvo();
      if (!mounted) return;
      Navigator.of(context).pushReplacement(MaterialPageRoute(
        builder: (_) => temToken ? const TelaHome() : const TelaLogin(),
      ));
      return;
    }

    setState(() => _verificando = false);
    _avisar('Ainda consta a versão $minima como obrigatória — baixe e instale.');
  }

  /// "Baixando... 62% (11,8 de 19,1 MB)" sob a barra; tamanho
  /// desconhecido → só os MB recebidos (barra indeterminada).
  String get _textoProgresso {
    final mbRecebidos = (_recebidos / 1048576).toStringAsFixed(1);
    if (_total <= 0) return 'Baixando... $mbRecebidos MB';
    final mbTotal = (_total / 1048576).toStringAsFixed(1);
    final pct = ((_recebidos / _total) * 100).toStringAsFixed(0);
    return 'Baixando... $pct% ($mbRecebidos de $mbTotal MB)';
  }

  /// Fração 0–1 pra barra (null = indeterminada, tamanho desconhecido).
  double? get _fracao => _total > 0 ? (_recebidos / _total).clamp(0.0, 1.0) : null;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      // PopScope canPop:false — está tela não pode ser "voltada" (ela é a
      // home do app bloqueado; voltar = fechar, decisão do próprio Android).
      body: PopScope(
        canPop: false,
        child: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(28),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Icon(Icons.system_update_alt,
                      size: 76, color: Cores.dourado),
                  const SizedBox(height: 16),
                  Text(
                    'Atualização necessária',
                    textAlign: TextAlign.center,
                    style: Theme.of(context)
                        .textTheme
                        .headlineSmall
                        ?.copyWith(fontWeight: FontWeight.bold, color: Cores.texto),
                  ),
                  const SizedBox(height: 10),
                  Text(
                    'Saiu a versão ${widget.versaoMinima} do BotBTC e esta '
                    'versão do app ($kAppVersao) não abre mais o painel.',
                    textAlign: TextAlign.center,
                    style: const TextStyle(color: Cores.textoSuave, fontSize: 14),
                  ),
                  const SizedBox(height: 28),

                  // Ação principal muda com o estado do download.
                  if (_baixando) ...[
                    LinearProgressIndicator(
                      value: _fracao,
                      minHeight: 8,
                      borderRadius: BorderRadius.circular(4),
                    ),
                    const SizedBox(height: 10),
                    Text(
                      _textoProgresso,
                      textAlign: TextAlign.center,
                      style: const TextStyle(
                          fontSize: 12.5, color: Cores.textoSuave),
                    ),
                  ] else if (_apkPath != null) ...[
                    FilledButton.icon(
                      onPressed: _instalarAtualizacao,
                      icon: const Icon(Icons.install_mobile),
                      label: const Text('Instalar atualização'),
                    ),
                    const SizedBox(height: 6),
                    Text(
                      'Na primeira vez o Android pede permissão para instalar '
                      'apps desta fonte — permita e a instalação segue. Seu '
                      'login e seus dados são mantidos.',
                      textAlign: TextAlign.center,
                      style: const TextStyle(
                          fontSize: 11, color: Cores.textoSuave, height: 1.5),
                    ),
                    TextButton.icon(
                      onPressed: _baixarAtualizacao,
                      icon: const Icon(Icons.refresh, size: 18),
                      label: const Text('Baixar de novo'),
                    ),
                  ] else
                    FilledButton.icon(
                      onPressed: _baixarAtualizacao,
                      icon: const Icon(Icons.download),
                      label: const Text('Baixar atualização'),
                    ),
                  const SizedBox(height: 6),

                  TextButton.icon(
                    onPressed: _verificando ? null : _verificarDeNovo,
                    icon: _verificando
                        ? const SizedBox(
                            width: 16,
                            height: 16,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(Icons.refresh, size: 18),
                    label: Text(_verificando
                        ? 'Verificando...'
                        : 'Já atualizei — verificar de novo'),
                  ),

                  // Plano B: site no navegador (um por baixo do outro, sem
                  // competir com a ação principal).
                  const SizedBox(height: 12),
                  Text(
                    'Prefere baixar pelo site?',
                    textAlign: TextAlign.center,
                    style: const TextStyle(fontSize: 11, color: Cores.textoSuave),
                  ),
                  TextButton.icon(
                    onPressed: _baixarPeloSite,
                    icon: const Icon(Icons.language, size: 16),
                    label: const Text('Abrir página de download'),
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
