@extends('layouts.app')

@section('content')
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


<script>
    const formatarBTC = (valor) => {
        return new Intl.NumberFormat('en-US', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 8
        }).format(Number(valor));
    };

    const formatar = (valor) => {
        return new Intl.NumberFormat('pt-BR').format(valor);
    }


    function atualizar() {
        axios.get("https://evtu.com.br/binance/getConf")
            .then((res) => {
                $('#salto').text(res.data.salto);
            })
            .catch(err => console.log(err));

        axios.get("https://evtu.com.br/binance/getSaldos")
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



    



</script>

@endsection