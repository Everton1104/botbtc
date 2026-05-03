@extends('layouts.app')

@section('content')
@if(auth()->user()->id == 1)
    <h1 class="text-center my-5">Preço BTC: R$ <span id="btc-price">CARREGANDO...</span></h1>

    <h1 class="text-center my-5">Saldo BTC: <span id="btc-saldo">CARREGANDO...</span> <span class="form-text fs-5"> <br> R$ <span id="btc-saldo-real">CARREGANDO...</span> - Bloqueado: R$ <span id="btc-saldo-bloqueado">CARREGANDO...</span></h1>

    <h1 class="text-center my-5">Saldo BRL: R$ <span id="brl-saldo">CARREGANDO...</span> <span class="form-text fs-5"> <br> BTC: <span id="brl-saldo-btc">CARREGANDO...</span> - Bloqueado: R$ <span id="brl-saldo-bloqueado">CARREGANDO...</span></h1>

    <h1 class="text-center my-5">
        TOTAL GERAL (BRL): R$ <span id="brl-saldo-geral-total">CARREGANDO...</span>

        <span class="form-text fs-5">
            <br> Saldo TOTAL BRL: R$ <span id="brl-saldo-total">CARREGANDO...</span>
            <br> TOTAL BTC (em BRL): <span id="brl-saldo-btc-total">CARREGANDO...</span>
            <br> TOTAL BNB (em BRL): <span id="brl-saldo-bnb-total">CARREGANDO...</span>
        </span>
    </h1>

    <h1 class="text-center my-5">
        Oscilação: R$ <span id="salto">CARREGANDO...</span>
    </h1>
@endif

<h2 class="text-center mt-4">
    INVESTIMENTO INICIAL: R$ <span id="investimento-inicial">CARREGANDO...</span>
</h2>

<h2 class="text-center mt-4">
    LUCRO TOTAL: <span id="lucro-total">CARREGANDO...</span>
    <p class="mt-4">Preço atual do BTC: R$ <span id="btc-price-2">CARREGANDO...</span>
    <p>Impacto do BTC no Seu Lucro: R$ <span id="impacto-no-lucro">CARREGANDO...</span></p>
    <p>Valor Atual do Seu Investimento: R$ <span id="valor-atual-investimento">CARREGANDO...</span></p>
</h2>


@if(auth()->user()->id == 1)



    <div class="card p-3 mt-4">
    <h4>Gerenciar Investimentos dos Usuários</h4>

    <table class="table table-bordered mt-3">
        <thead>
            <tr>
                <th>ID</th>
                <th>Nome</th>
                <th>Email</th>
                <th>Investimento Inicial</th>
                <th>Valor Atual</th>
                <th>Lucro</th>
                <th>Adicionar</th>
                <th>Remover</th>
            </tr>
        </thead>
        <tbody id="tabela-usuarios"></tbody>
    </table>
</div>


    <script>
        if ({{ auth()->user()->id }} === 1) {

            axios.get('/admin/usuarios-investimentos').then(res => {

                let html = '';

                res.data.forEach(u => {
                    html += `
                        <tr>
                            <td>${u.id}</td>
                            <td>${u.name}</td>
                            <td>${u.email}</td>
                            <td>R$ ${formatar(u.investimento_inicial)}</td>
                            <td>R$ ${formatar(u.valor_atual)}</td>
                            <td>R$ ${formatar(u.lucro)}</td>
                            <td>
                                <button class="btn btn-primary btn-sm" onclick="adicionar(${u.id})">
                                    Adicionar
                                </button>
                            </td>
                            <td>
                                <button class="btn btn-danger btn-sm" onclick="remover(${u.id})">
                                    Remover
                                </button>
                            </td>
                        </tr>
                    `;
                });

                document.getElementById('tabela-usuarios').innerHTML = html;
            });
        }


        function adicionar(userId) {
            const valor = prompt("Digite o valor a adicionar:");

            if (!valor || isNaN(valor)) {
                alert("Valor inválido");
                return;
            }

            axios.post('/bot/investir-manual', { valor, userId })
                .then(res => alert(res.data.mensagem))
                .catch(() => alert("Erro ao adicionar investimento."));
        }

        function remover(userId) {
            if (!confirm("Tem certeza que deseja remover o investimento deste usuário?")) {
                return;
            }

            axios.delete(`/bot/remover-investimento/${userId}`)
                .then(res => alert(res.data.mensagem))
                .catch(() => alert("Erro ao remover investimento."));
        }


    </script>



@endif






<script>
    const formatarBTC = (valor) => {
        return new Intl.NumberFormat('en-US', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 8
        }).format(Number(valor));
    };

    const formatar = (valor) => {
        if (valor === null || valor === undefined || isNaN(valor)) {
            return "0,00";
        }
        return new Intl.NumberFormat('pt-BR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(Number(valor));
    };


    @if(auth()->user()->id == 1)
        function atualizar() {
            axios.get("/binance/getSaldos")
                .then((res) => {

                    axios.get("https://api.binance.com/api/v3/ticker/price?symbol=BTCBRL")
                        .then((precoBTC) => {

                            axios.get("https://api.binance.com/api/v3/ticker/price?symbol=BNBBRL")
                                .then((precoBNB) => {

                                    const btcbrl = parseFloat(precoBTC.data.price);
                                    const bnbbrl = parseFloat(precoBNB.data.price);

                                    let btcFree = 0, btcLocked = 0;
                                    let brlFree = 0, brlLocked = 0;
                                    let bnbFree = 0, bnbLocked = 0;

                                    res.data.balances.forEach(moeda => {

                                        if (moeda.asset === 'BTC') {
                                            btcFree = parseFloat(moeda.free);
                                            btcLocked = parseFloat(moeda.locked);

                                            $('#btc-saldo').text(formatarBTC(btcFree));
                                            $('#btc-price').text(formatar(btcbrl));
                                            $('#btc-saldo-real').text(formatar(btcFree * btcbrl));
                                            $('#btc-saldo-bloqueado').text(formatar(btcLocked * btcbrl));
                                        }

                                        if (moeda.asset === 'BRL') {
                                            brlFree = parseFloat(moeda.free);
                                            brlLocked = parseFloat(moeda.locked);

                                            $('#brl-saldo').text(formatar(brlFree));
                                            $('#brl-saldo-btc').text(formatarBTC(brlFree / btcbrl));
                                            $('#brl-saldo-bloqueado').text(formatar(brlLocked));
                                        }

                                        if (moeda.asset === 'BNB') {
                                            bnbFree = parseFloat(moeda.free);
                                            bnbLocked = parseFloat(moeda.locked);

                                            $('#bnb-saldo').text(formatar(bnbFree));
                                            $('#bnb-saldo-real').text(formatar(bnbFree * bnbbrl));
                                        }
                                    });

                                    const totalBRL = brlFree + brlLocked;
                                    const totalBTCemBRL = (btcFree + btcLocked) * btcbrl;
                                    const totalBNBemBRL = (bnbFree + bnbLocked) * bnbbrl;

                                    const totalGeralBRL = totalBRL + totalBTCemBRL + totalBNBemBRL;

                                    $('#brl-saldo-total').text(formatar(totalBRL));
                                    $('#brl-saldo-btc-total').text(formatar(totalBTCemBRL));
                                    $('#brl-saldo-bnb-total').text(formatar(totalBNBemBRL));
                                    $('#brl-saldo-geral-total').text(formatar(totalGeralBRL));

                                });
                        });
                })
                .catch(err => console.log(err));
        }
        atualizar();
        setInterval(() => {
            atualizar();
        }, 10000);
    @endif

    // atualiza o salto
    axios.get("https://evtu.com.br/binance/getConf")
        .then((res) => {
            $('#salto').text(res.data.salto);
        })
        .catch(err => console.log(err));

    // atualiza o lucro
    axios.get("/bot/valor-atual").then((res) => {

        const safe = (v) => Number(v ?? 0);

        $('#investimento-inicial').text(formatar(safe(res.data.investimento_inicial)));
        $('#lucro-total').text(formatar(safe(res.data.lucro)));
        $('#impacto-no-lucro').text(formatar(safe(res.data.impacto_usuario)));
        $('#valor-atual-investimento').text(formatar(safe(res.data.valor_atual)));

        axios.get("https://api.binance.com/api/v3/ticker/price?symbol=BTCBRL")
            .then((precoBTC) => {
                $('#btc-price-2').text(formatar(parseFloat(precoBTC.data.price)));
            });
    });

</script>

@endsection