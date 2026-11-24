<?php

use App\Http\Controllers\Admin\PainelController as AdminPainel;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegistroController;
use App\Http\Controllers\Auth\SenhaController;
use App\Http\Controllers\Empresa\CertificadoController as EmpresaCertificado;
use App\Http\Controllers\Empresa\CfopController as EmpresaCfop;
use App\Http\Controllers\Empresa\DadosController as EmpresaDados;
use App\Http\Controllers\Empresa\NcmController as EmpresaNcm;
use App\Http\Controllers\Empresa\NotaController as EmpresaNota;
use App\Http\Controllers\Empresa\PessoaController as EmpresaPessoa;
use App\Http\Controllers\Empresa\ProdutoController as EmpresaProduto;
use App\Http\Controllers\Empresa\PainelController as EmpresaPainel;
use App\Http\Controllers\Empresa\Nfse\ClienteMensalController as NfseClientes;
use App\Http\Controllers\Empresa\Nfse\LoteController as NfseLote;
use App\Http\Controllers\Empresa\Nfse\NfseController as EmpresaNfse;
use App\Http\Controllers\Empresa\Nfse\ServicoController as EmpresaServico;
use App\Http\Controllers\Escritorio\EmpresaController as EscritorioEmpresa;
use App\Http\Controllers\Escritorio\PainelController as EscritorioPainel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Páginas públicas
Route::view('/', 'welcome')->name('inicio');
Route::view('/termos-de-uso', 'paginas.termos')->name('termos');
Route::view('/politica-de-privacidade', 'paginas.privacidade')->name('privacidade');

// Visitantes (não logados)
Route::middleware('guest')->group(function () {
    Route::get('/entrar', [LoginController::class, 'create'])->name('login');
    Route::post('/entrar', [LoginController::class, 'store']);

    Route::get('/cadastro', [RegistroController::class, 'create'])->name('registro');
    Route::post('/cadastro', [RegistroController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/cadastro/cnpj/{cnpj}', [RegistroController::class, 'cnpj'])->where('cnpj', '[0-9]{14}')->middleware('throttle:15,1')->name('registro.cnpj');

    Route::get('/esqueci-a-senha', [SenhaController::class, 'solicitar'])->name('password.request');
    Route::post('/esqueci-a-senha', [SenhaController::class, 'enviarLink'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/redefinir-senha/{token}', [SenhaController::class, 'redefinir'])->name('password.reset');
    Route::post('/redefinir-senha', [SenhaController::class, 'salvar'])->name('password.update');
});

// Usuários logados
Route::middleware(['auth', 'ativo'])->group(function () {
    Route::post('/sair', [LoginController::class, 'destroy'])->name('logout');

    // Leva cada perfil ao seu painel
    Route::get('/painel', fn (Request $request) => redirect()->route($request->user()->perfil->rotaPainel()))
        ->name('painel');

    Route::prefix('admin')->name('admin.')->middleware('perfil:admin')->group(function () {
        Route::get('/', AdminPainel::class)->name('painel');
    });

    Route::prefix('escritorio')->name('escritorio.')->middleware('perfil:escritorio')->group(function () {
        Route::get('/', EscritorioPainel::class)->name('painel');

        Route::get('/empresas/nova', [EscritorioEmpresa::class, 'create'])->name('empresas.create');
        Route::post('/empresas', [EscritorioEmpresa::class, 'store'])->middleware('throttle:20,1')->name('empresas.store');
        Route::get('/cnpj/{cnpj}', [EscritorioEmpresa::class, 'cnpj'])->where('cnpj', '[0-9]{14}')->middleware('throttle:30,1')->name('cnpj');

        Route::post('/empresas/{empresa}/acessar', [EscritorioEmpresa::class, 'acessar'])->whereNumber('empresa')->name('empresas.acessar');
        Route::post('/sair-da-empresa', [EscritorioEmpresa::class, 'sair'])->name('empresas.sair');
        Route::post('/empresas/{empresa}/aceitar', [EscritorioEmpresa::class, 'aceitar'])->whereNumber('empresa')->name('empresas.aceitar');
        Route::delete('/empresas/{empresa}', [EscritorioEmpresa::class, 'desvincular'])->whereNumber('empresa')->name('empresas.desvincular');
        Route::post('/empresas/{empresa}/creditos', [EscritorioEmpresa::class, 'transferir'])->whereNumber('empresa')->name('empresas.creditos');
        Route::post('/empresas/{empresa}/saldo-compartilhado', [EscritorioEmpresa::class, 'saldoCompartilhado'])->whereNumber('empresa')->name('empresas.saldo');

        Route::get('/arquivos', [EscritorioEmpresa::class, 'arquivosDoMes'])->name('arquivos');
    });

    // Área da empresa: usada pela própria empresa e pelo escritório que a acessa
    Route::prefix('empresa')->name('empresa.')->middleware(['perfil:empresa,escritorio', 'empresa.atual'])->group(function () {
        Route::get('/', EmpresaPainel::class)->name('painel');

        Route::get('/contador', [\App\Http\Controllers\Empresa\ContadorController::class, 'show'])->name('contador');
        Route::post('/contador', [\App\Http\Controllers\Empresa\ContadorController::class, 'store'])->middleware('throttle:10,1')->name('contador.store');
        Route::delete('/contador', [\App\Http\Controllers\Empresa\ContadorController::class, 'destroy'])->name('contador.destroy');
        Route::get('/notas/arquivos', [EmpresaNota::class, 'arquivosDoMes'])->name('notas.arquivos');

        // NFC-e (cupom fiscal)
        Route::get('/nfce', [\App\Http\Controllers\Empresa\NfceController::class, 'index'])->name('nfce.index');
        Route::get('/nfce/venda', [\App\Http\Controllers\Empresa\NfceController::class, 'create'])->name('nfce.create');
        Route::post('/nfce', [\App\Http\Controllers\Empresa\NfceController::class, 'store'])->middleware('throttle:60,1')->name('nfce.store');
        Route::get('/nfce/{nota}', [\App\Http\Controllers\Empresa\NfceController::class, 'show'])->whereNumber('nota')->name('nfce.show');
        Route::get('/nfce/{nota}/editar', [\App\Http\Controllers\Empresa\NfceController::class, 'edit'])->whereNumber('nota')->name('nfce.edit');
        Route::put('/nfce/{nota}', [\App\Http\Controllers\Empresa\NfceController::class, 'update'])->whereNumber('nota')->middleware('throttle:60,1')->name('nfce.update');

        // Serviços (NFS-e Nacional)
        Route::get('/servicos/codigos', [EmpresaServico::class, 'buscarCodigo'])->middleware('throttle:120,1')->name('servicos.codigos.buscar');
        Route::get('/servicos/codigos/{codigo}', [EmpresaServico::class, 'mostrarCodigo'])->whereNumber('codigo')->name('servicos.codigos.mostrar');
        Route::resource('servicos', EmpresaServico::class)->except('show')->whereNumber('servico');

        Route::prefix('nfse')->name('nfse.')->group(function () {
            Route::get('/', [EmpresaNfse::class, 'index'])->name('index');
            Route::get('/emitir', [EmpresaNfse::class, 'create'])->name('create');
            Route::post('/', [EmpresaNfse::class, 'store'])->middleware('throttle:30,1')->name('store');
            Route::get('/lote', [NfseLote::class, 'index'])->name('lote');
            Route::post('/lote', [NfseLote::class, 'emitir'])->middleware('throttle:10,1')->name('lote.emitir');
            Route::post('/lote/reajuste', [NfseLote::class, 'reajustar'])->name('lote.reajustar');
            Route::post('/lote/desfazer-reajuste', [NfseLote::class, 'desfazerReajuste'])->name('lote.desfazer');
            Route::resource('clientes', NfseClientes::class)->except('show')->whereNumber('cliente');

            Route::get('/{nfse}', [EmpresaNfse::class, 'show'])->whereNumber('nfse')->name('show');
            Route::get('/{nfse}/editar', [EmpresaNfse::class, 'edit'])->whereNumber('nfse')->name('edit');
            Route::put('/{nfse}', [EmpresaNfse::class, 'update'])->whereNumber('nfse')->middleware('throttle:30,1')->name('update');
            Route::post('/{nfse}/consultar', [EmpresaNfse::class, 'consultar'])->whereNumber('nfse')->middleware('throttle:20,1')->name('consultar');
            Route::post('/{nfse}/cancelar', [EmpresaNfse::class, 'cancelar'])->whereNumber('nfse')->middleware('throttle:10,1')->name('cancelar');
            Route::post('/{nfse}/email', [EmpresaNfse::class, 'email'])->whereNumber('nfse')->middleware('throttle:10,1')->name('email');
            Route::get('/{nfse}/xml', [EmpresaNfse::class, 'xml'])->whereNumber('nfse')->name('xml');
            Route::get('/{nfse}/pdf', [EmpresaNfse::class, 'pdf'])->whereNumber('nfse')->name('pdf');
        });

        Route::get('/dados', [EmpresaDados::class, 'edit'])->name('dados');
        Route::put('/dados', [EmpresaDados::class, 'update'])->name('dados.update');

        Route::get('/certificado', [EmpresaCertificado::class, 'show'])->name('certificado');
        Route::post('/certificado', [EmpresaCertificado::class, 'store'])->middleware('throttle:10,1')->name('certificado.store');
        Route::delete('/certificado', [EmpresaCertificado::class, 'destroy'])->name('certificado.destroy');

        Route::resource('pessoas', EmpresaPessoa::class)->except('show')
            ->parameters(['pessoas' => 'pessoa'])->whereNumber('pessoa');
        Route::resource('produtos', EmpresaProduto::class)->except('show')->whereNumber('produto');

        Route::post('/notas/{nota}/cancelar', [EmpresaNota::class, 'cancelar'])->whereNumber('nota')->middleware('throttle:10,1')->name('notas.cancelar');
        Route::post('/notas/{nota}/correcao', [EmpresaNota::class, 'corrigir'])->whereNumber('nota')->middleware('throttle:10,1')->name('notas.corrigir');
        Route::get('/notas/{nota}/eventos/{evento}/xml', [EmpresaNota::class, 'eventoXml'])->whereNumber(['nota', 'evento'])->name('notas.evento.xml');
        Route::get('/notas/{nota}/eventos/{evento}/pdf', [EmpresaNota::class, 'eventoPdf'])->whereNumber(['nota', 'evento'])->name('notas.evento.pdf');
        Route::post('/notas/importar-compra', [EmpresaNota::class, 'importarCompra'])->middleware('throttle:20,1')->name('notas.importar-compra');
        Route::resource('notas', EmpresaNota::class)->whereNumber('nota');
        Route::post('/notas/{nota}/transmitir', [EmpresaNota::class, 'transmitir'])->whereNumber('nota')
            ->middleware('throttle:20,1')->name('notas.transmitir');
        Route::post('/notas/{nota}/consultar', [EmpresaNota::class, 'consultar'])->whereNumber('nota')
            ->middleware('throttle:20,1')->name('notas.consultar');
        Route::get('/notas/{nota}/xml', [EmpresaNota::class, 'xml'])->whereNumber('nota')->name('notas.xml');
        Route::get('/notas/{nota}/danfe', [EmpresaNota::class, 'danfe'])->whereNumber('nota')->name('notas.danfe');

        Route::get('/cnpj/{cnpj}', [\App\Http\Controllers\Empresa\CnpjController::class, 'mostrar'])->where('cnpj', '[0-9]{14}')->middleware('throttle:30,1')->name('cnpj.mostrar');
        Route::get('/classtrib', [\App\Http\Controllers\Empresa\ClassTribController::class, 'buscar'])->middleware('throttle:120,1')->name('classtrib.buscar');
        Route::get('/classtrib/{codigo}', [\App\Http\Controllers\Empresa\ClassTribController::class, 'mostrar'])->whereNumber('codigo')->name('classtrib.mostrar');
        Route::get('/ncm', [EmpresaNcm::class, 'buscar'])->middleware('throttle:120,1')->name('ncm.buscar');
        Route::get('/ncm/{codigo}', [EmpresaNcm::class, 'mostrar'])->whereNumber('codigo')->name('ncm.mostrar');

        Route::get('/cfop', [EmpresaCfop::class, 'buscar'])->middleware('throttle:120,1')->name('cfop.buscar');
        Route::get('/cfop/{codigo}', [EmpresaCfop::class, 'mostrar'])->whereNumber('codigo')->name('cfop.mostrar');
    });
});
