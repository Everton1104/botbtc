// ─────────────────────────────────────────────────────────────────────────────
// TELA "AÇÕES" — saques e depósitos PIX na mesma página, em abas.
//
// Substituiu a antiga TelaSaque: o ícone da AppBar da Home continua o
// mesmo (currency_exchange), agora abrindo as duas ações de dinheiro.
// Cada aba é autocontida (carrega seus dados, trata 401, tem puxão pra
// atualizar) — a TabBarView preserva o estado de ambas, então o polling
// do PIX continua rodando enquanto o usuário olha a aba de saques.
// ─────────────────────────────────────────────────────────────────────────────

import 'package:flutter/material.dart';

import '../tema.dart';
import 'aba_deposito.dart';
import 'aba_saque.dart';

class TelaAcoes extends StatelessWidget {
  const TelaAcoes({super.key});

  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Ações'),
          bottom: const TabBar(
            labelColor: Cores.dourado,
            unselectedLabelColor: Cores.textoSuave,
            indicatorColor: Cores.dourado,
            tabs: [
              Tab(icon: Icon(Icons.currency_exchange), text: 'Saques'),
              Tab(icon: Icon(Icons.qr_code_2), text: 'Depósitos'),
            ],
          ),
        ),
        body: const TabBarView(
          children: [
            AbaSaque(),
            AbaDeposito(),
          ],
        ),
      ),
    );
  }
}
