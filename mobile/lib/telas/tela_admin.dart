// ─────────────────────────────────────────────────────────────────────────────
// TELA ADMIN DO BOT — os controles da aba "Mais Informações" do site, no app.
//
// Quatro abas autocontidas (cada uma carrega seus dados, trata 401 e tem
// puxão pra atualizar — o padrão da TelaAcoes):
//   1. Controles  — pausa manual, modo subida e o form da config do bot
//   2. Números    — lucro 1/7/30d, performance, transferências, tendência
//   3. Simulação  — o backtest fiel ao bot (página /simulacao do site)
//   4. Relatório  — P&L realizado mensal (FIFO), mês a mês
//
// Só o admin (id 1) chega aqui: o botão fica escondido na Home de cliente
// e TODOS os endpoints guardam admin no servidor de qualquer forma.
// ─────────────────────────────────────────────────────────────────────────────

import 'package:flutter/material.dart';

import '../tema.dart';
import 'aba_controles_bot.dart';
import 'aba_numeros_bot.dart';
import 'aba_relatorio.dart';
import 'aba_simulacao.dart';

class TelaAdmin extends StatelessWidget {
  const TelaAdmin({super.key});

  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      length: 4,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Admin do Bot'),
          bottom: const TabBar(
            labelColor: Cores.dourado,
            unselectedLabelColor: Cores.textoSuave,
            indicatorColor: Cores.dourado,
            isScrollable: true,
            tabAlignment: TabAlignment.start,
            tabs: [
              Tab(icon: Icon(Icons.tune), text: 'Controles'),
              Tab(icon: Icon(Icons.insights_outlined), text: 'Números'),
              Tab(icon: Icon(Icons.science_outlined), text: 'Simulação'),
              Tab(icon: Icon(Icons.calendar_month_outlined), text: 'Relatório'),
            ],
          ),
        ),
        body: const TabBarView(
          children: [
            AbaControlesBot(),
            AbaNumerosBot(),
            AbaSimulacao(),
            AbaRelatorio(),
          ],
        ),
      ),
    );
  }
}
