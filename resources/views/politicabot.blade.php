@extends('layouts.app')

@section('content')
<div class="container" style="max-width:760px;">

    <div class="mb-4 pt-2">
        <h1 class="fw-bold" style="font-size:1.5rem; color:var(--gold);">
            <i class="fa-solid fa-shield-halved me-2"></i>Política de Uso do Assistente Virtual
        </h1>
        <p class="text-muted" style="font-size:.82rem;">Última atualização: {{ \Carbon\Carbon::now()->format('d/m/Y') }}</p>
    </div>

    {{-- 1 --}}
    <div class="card mb-4">
        <div class="card-header">
            <i class="fa-solid fa-circle-info me-2 text-gold"></i>1. O que é este Assistente
        </div>
        <div class="card-body" style="line-height:1.8; color:var(--muted); font-size:.9rem;">
            <p>Este assistente virtual é um sistema automatizado baseado em inteligência artificial que responde perguntas, executa tarefas e fornece informações dentro do escopo definido pelo operador do serviço. As respostas são geradas de forma automatizada e não representam, em nenhuma hipótese, opinião, parecer ou orientação humana.</p>
            <p class="mb-0">Ao interagir com o assistente, o usuário declara ter lido, compreendido e concordado integralmente com os termos desta política.</p>
        </div>
    </div>

    {{-- 2 --}}
    <div class="card mb-4">
        <div class="card-header">
            <i class="fa-solid fa-triangle-exclamation me-2" style="color:var(--red);"></i>2. Limitações e Isenção de Responsabilidade
        </div>
        <div class="card-body" style="line-height:1.8; color:var(--muted); font-size:.9rem;">
            <p>O assistente pode cometer erros, apresentar informações desatualizadas ou responder de forma imprecisa. As respostas <strong class="text-light">não substituem</strong> consultas a profissionais habilitados nas respectivas áreas (médica, jurídica, financeira, técnica, etc.).</p>
            <ul class="mb-0 ps-3">
                <li>O operador não se responsabiliza por decisões tomadas com base nas respostas do assistente.</li>
                <li>O serviço pode ser interrompido temporariamente para manutenção sem aviso prévio.</li>
                <li>A disponibilidade do assistente não é garantida em 100% do tempo.</li>
                <li>O operador não se responsabiliza por danos diretos ou indiretos decorrentes do uso do serviço.</li>
            </ul>
        </div>
    </div>

    {{-- 3 --}}
    <div class="card mb-4">
        <div class="card-header">
            <i class="fa-solid fa-user-check me-2 text-gold"></i>3. Elegibilidade
        </div>
        <div class="card-body" style="line-height:1.8; color:var(--muted); font-size:.9rem;">
            <ul class="mb-0 ps-3">
                <li>O uso é permitido a qualquer pessoa com capacidade legal para tanto, respeitadas as leis do país do usuário.</li>
                <li>Menores de 18 anos devem ter autorização e supervisão de um responsável legal.</li>
                <li>O operador pode restringir o acesso a determinadas regiões ou perfis de usuário a qualquer momento.</li>
            </ul>
        </div>
    </div>

    {{-- 4 --}}
    <div class="card mb-4">
        <div class="card-header">
            <i class="fa-solid fa-circle-check me-2" style="color:var(--green);"></i>4. Uso Permitido
        </div>
        <div class="card-body" style="line-height:1.8; color:var(--muted); font-size:.9rem;">
            <p>O assistente pode ser utilizado para:</p>
            <ul class="mb-0 ps-3">
                <li>Obter informações gerais dentro do tema do serviço.</li>
                <li>Tirar dúvidas, explorar tópicos e receber orientações básicas.</li>
                <li>Automatizar interações de suporte, atendimento ou consulta.</li>
                <li>Fins educacionais, de pesquisa ou entretenimento, desde que lícitos.</li>
            </ul>
        </div>
    </div>

    {{-- 5 --}}
    <div class="card mb-4">
        <div class="card-header">
            <i class="fa-solid fa-ban me-2" style="color:var(--red);"></i>5. Uso Proibido
        </div>
        <div class="card-body" style="line-height:1.8; color:var(--muted); font-size:.9rem;">
            <p>É expressamente proibido utilizar o assistente para:</p>
            <ul class="mb-0 ps-3">
                <li>Gerar, disseminar ou amplificar conteúdo falso, enganoso ou de desinformação.</li>
                <li>Produzir conteúdo ofensivo, discriminatório, de ódio, assédio ou de natureza ilegal.</li>
                <li>Realizar atividades fraudulentas, golpes ou qualquer forma de enganação a terceiros.</li>
                <li>Tentar contornar filtros, explorar falhas ou manipular o sistema de forma não autorizada.</li>
                <li>Coletar dados de outros usuários ou realizar scraping automatizado sem autorização.</li>
                <li>Qualquer finalidade que viole leis locais, nacionais ou internacionais aplicáveis.</li>
            </ul>
        </div>
    </div>

    {{-- 6 --}}
    <div class="card mb-4">
        <div class="card-header">
            <i class="fa-solid fa-lock me-2 text-gold"></i>6. Privacidade e Dados
        </div>
        <div class="card-body" style="line-height:1.8; color:var(--muted); font-size:.9rem;">
            <p>As interações com o assistente podem ser registradas para fins de:</p>
            <ul class="mb-3 ps-3">
                <li>Melhoria contínua da qualidade das respostas.</li>
                <li>Segurança, moderação e prevenção de abusos.</li>
                <li>Cumprimento de obrigações legais.</li>
            </ul>
            <p>Nenhuma informação pessoal sensível deve ser compartilhada nas conversas — como senhas, números de documentos, dados bancários ou informações de saúde que permitam identificação.</p>
            <p class="mb-0">Os dados coletados não são vendidos a terceiros. O tratamento segue as disposições da Lei Geral de Proteção de Dados (LGPD — Lei nº 13.709/2018) e demais normas aplicáveis.</p>
        </div>
    </div>

    {{-- 7 --}}
    <div class="card mb-4">
        <div class="card-header">
            <i class="fa-solid fa-copyright me-2 text-gold"></i>7. Propriedade Intelectual
        </div>
        <div class="card-body" style="line-height:1.8; color:var(--muted); font-size:.9rem;">
            <p class="mb-0">O sistema, a interface e os modelos que compõem este assistente são de propriedade do operador ou de seus fornecedores de tecnologia. O conteúdo gerado pelo assistente pode ser utilizado pelo usuário para fins pessoais e não comerciais, salvo autorização expressa em contrário. A reprodução total ou parcial do sistema sem autorização é proibida.</p>
        </div>
    </div>

    {{-- 8 --}}
    <div class="card mb-4">
        <div class="card-header">
            <i class="fa-solid fa-rotate me-2 text-gold"></i>8. Alterações nesta Política
        </div>
        <div class="card-body" style="line-height:1.8; color:var(--muted); font-size:.9rem;">
            <p class="mb-0">Esta política pode ser revisada a qualquer momento pelo operador. A versão vigente é sempre a publicada nesta página, com a data de atualização indicada no topo. O uso continuado do assistente após alterações implica aceitação dos novos termos.</p>
        </div>
    </div>

    {{-- 9 --}}
    <div class="card mb-4">
        <div class="card-header">
            <i class="fa-solid fa-envelope me-2 text-gold"></i>9. Contato
        </div>
        <div class="card-body" style="line-height:1.8; color:var(--muted); font-size:.9rem;">
            <p class="mb-0">Dúvidas, solicitações de exclusão de dados ou reclamações relacionadas a esta política devem ser encaminhadas ao operador responsável pelo canal de contato disponibilizado no site em que este assistente está integrado.</p>
        </div>
    </div>

    <div class="text-center mb-5">
        <a href="{{ url('/') }}" class="btn btn-outline-muted btn-sm">
            <i class="fa-solid fa-arrow-left me-1"></i>Voltar
        </a>
    </div>

</div>
@endsection
