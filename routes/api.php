<?php

use App\Http\Controllers\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;

use App\Http\Controllers\Cadastros\SetoresController;
use App\Http\Controllers\Cadastros\FornecedorController;
use App\Http\Controllers\Cadastros\ProdutoController;
use App\Http\Controllers\Cadastros\UnidadeMedidaController;
use App\Http\Controllers\Cadastros\EstoqueController as CadastrosEstoqueController;
use App\Http\Controllers\Cadastros\TipoVinculoController;
use App\Http\Controllers\Cadastros\GrupoProdutoController;
use App\Http\Controllers\Cadastros\PoloController;
use App\Http\Controllers\EstoqueController;
use App\Http\Controllers\EstoqueLoteController;
use App\Http\Controllers\EntradaController;
use App\Http\Controllers\UsuarioSetorController;
use App\Http\Controllers\RelatoriosController;
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Health check - rota pública para testar se a API está funcionando
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'API is running',
        'timestamp' => now()->toIso8601String(),
    ]);
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});


Route::group(['middleware' => 'auth:sanctum'], function () {
    Route::get('countUsers', [UserController::class, 'countUsers']);

    // Cadastro de Usuários
    // Rotas para gerenciamento de vínculo usuário <-> setor
    Route::post('/usuarioSetor/create', [UsuarioSetorController::class, 'create']);
    Route::post('/usuarioSetor/update', [UsuarioSetorController::class, 'update']);
    Route::post('/usuarioSetor/delete', [UsuarioSetorController::class, 'delete']);
    Route::post('/usuarioSetor/listBySetor', [UsuarioSetorController::class, 'listBySetor']);
    Route::post('/usuarioSetor/listByUsuario', [UsuarioSetorController::class, 'listByUsuario']);
});


Route::post("login", [AuthController::class, 'login']);
Route::post("register", [AuthController::class, 'register']);
Route::post("logout", [AuthController::class, 'logout']);

Route::prefix('user')->group(function () {
    Route::post('/add', [UserController::class, 'add']);
    Route::post('/update', [UserController::class, 'update']);
    Route::post('/list', [UserController::class, 'listAll']);
    Route::post('/listData', [UserController::class, 'listData']);
    Route::post('/delete/{id}', [UserController::class, 'delete']);
});

Route::post('/tipoVinculo/add', [TipoVinculoController::class, 'add']);
Route::post('/tipoVinculo/update', [TipoVinculoController::class, 'update']);
Route::post('/tipoVinculo/list', [TipoVinculoController::class, 'listAll']);
Route::post('/tipoVinculo/listData', [TipoVinculoController::class, 'listData']);
Route::post('/tipoVinculo/delete/{id}', [TipoVinculoController::class, 'delete']);

// Rotas alternativas com hífen para compatibilidade
Route::post('/tipo-vinculo/add', [TipoVinculoController::class, 'add']);
Route::post('/tipo-vinculo/update', [TipoVinculoController::class, 'update']);
Route::post('/tipo-vinculo/list', [TipoVinculoController::class, 'listAll']);
Route::post('/tipo-vinculo/listData', [TipoVinculoController::class, 'listData']);
Route::post('/tipo-vinculo/delete/{id}', [TipoVinculoController::class, 'delete']);

// Rotas para unidade (mantém compatibilidade com /polo)
Route::post('/unidade/add', [PoloController::class, 'add']);
Route::post('/unidade/update', [PoloController::class, 'update']);
Route::post('/unidade/list', [PoloController::class, 'listAll']);
Route::post('/unidade/listData', [PoloController::class, 'listData']);
Route::post('/unidade/delete/{id}', [PoloController::class, 'delete']);
Route::post('/unidade/toggleStatus', [PoloController::class, 'toggleStatus']);

// Rotas antigas /polo para compatibilidade apontando para o mesmo controller
Route::post('/polo/add', [PoloController::class, 'add']);
Route::post('/polo/update', [PoloController::class, 'update']);
Route::post('/polo/list', [PoloController::class, 'listAll']);
Route::post('/polo/listData', [PoloController::class, 'listData']);
Route::post('/polo/delete/{id}', [PoloController::class, 'delete']);
Route::post('/polo/toggleStatus', [PoloController::class, 'toggleStatus']);

// Rotas antigas de unidades removidas - usar /setores

Route::post('/setores/add', [SetoresController::class, 'add']);
Route::post('/setores/update', [SetoresController::class, 'update']);
Route::post('/setores/list', [SetoresController::class, 'listAll']);
Route::post('/setores/listData', [SetoresController::class, 'listData']);
Route::post('/setores/delete/{id}', [SetoresController::class, 'delete']);
Route::post('/setores/toggleStatus', [SetoresController::class, 'toggleStatus']);
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/setores/addDistribuidor', [SetoresController::class, 'addDistribuidor']);
    Route::post('/setores/removeDistribuidor', [SetoresController::class, 'removeDistribuidor']);
});
Route::middleware('auth:sanctum')->post('/setores/listWithAccess', [SetoresController::class, 'listWithAccess']);
Route::middleware('auth:sanctum')->post('/setores/getDetail', [SetoresController::class, 'getDetail']);
Route::middleware('auth:sanctum')->post('/setores/listConsumers', [SetoresController::class, 'listConsumers']);
Route::middleware('auth:sanctum')->post('/setores/listDistribuidoresParaSetor', [SetoresController::class, 'listDistribuidoresParaSetor']);

// Rotas dos módulos produtos, categoriasProdutos e unidadesMedida foram removidas
// Use os novos módulos: Produto, GrupoProduto e UnidadeMedida

// Rotas para tabela nova `unidade_medida` (singular)
Route::post('/unidadeMedida/add', [UnidadeMedidaController::class, 'add']);
Route::post('/unidadeMedida/update', [UnidadeMedidaController::class, 'update']);
Route::post('/unidadeMedida/list', [UnidadeMedidaController::class, 'listAll']);
Route::post('/unidadeMedida/listData', [UnidadeMedidaController::class, 'listData']);
Route::post('/unidadeMedida/delete/{id}', [UnidadeMedidaController::class, 'delete']);

// Rotas para fornecedores
Route::post('/fornecedores/add', [FornecedorController::class, 'add']);
Route::post('/fornecedores/update', [FornecedorController::class, 'update']);
Route::post('/fornecedores/list', [FornecedorController::class, 'listAll']);
Route::post('/fornecedores/listData', [FornecedorController::class, 'listData']);
Route::post('/fornecedores/delete/{id}', [FornecedorController::class, 'delete']);
Route::post('/fornecedores/toggleStatus', [FornecedorController::class, 'toggleStatus']);

// Rotas para produtos
Route::post('/produtos/add', [ProdutoController::class, 'add']);
Route::post('/produtos/update', [ProdutoController::class, 'update']);
Route::post('/produtos/list', [ProdutoController::class, 'listAll']);
Route::post('/produtos/listData', [ProdutoController::class, 'listData']);
Route::post('/produtos/delete/{id}', [ProdutoController::class, 'delete']);
Route::post('/produtos/toggleStatus', [ProdutoController::class, 'toggleStatus']);
Route::post('/produtos/dadosAuxiliares', [ProdutoController::class, 'getDadosAuxiliares']);
Route::post('/produtos/listByTipo', [ProdutoController::class, 'listByTipo']);

Route::post('/entrada/add', [EntradaController::class, 'add']);
Route::post('/entrada/list', [EntradaController::class, 'list']);
Route::post('/entrada/update', [EntradaController::class, 'update']);
Route::post('/entrada/delete', [EntradaController::class, 'delete']);

// Rotas para movimentações
use App\Http\Controllers\MovimentacaoController;

Route::post('/movimentacao/add', [MovimentacaoController::class, 'store']);
Route::match(['get', 'post'], '/movimentacao/listBySetor', [MovimentacaoController::class, 'listBySetor']);
// Rota legado para compatibilidade com front que ainda chama "listByUnidade"
Route::match(['get', 'post'], '/movimentacao/listByUnidade', [MovimentacaoController::class, 'listBySetor']);
Route::get('/movimentacao/{id}', [MovimentacaoController::class, 'show']);
Route::get('/movimentacao/{id}/preview-lotes', [MovimentacaoController::class, 'previewLotes']);
Route::post('/movimentacao/{id}/process', [MovimentacaoController::class, 'process']);
Route::post('/movimentacao/{id}/delete', [MovimentacaoController::class, 'destroy']);
Route::post('/movimentacoes/{id}/status', [MovimentacaoController::class, 'updateStatus']);

// Rotas antigas do estoque (para manter compatibilidade)
Route::post('/estoque/add', [CadastrosEstoqueController::class, 'add']);
Route::post('/estoque/update', [CadastrosEstoqueController::class, 'update']);
Route::post('/estoque/list', [CadastrosEstoqueController::class, 'listAll']);
Route::post('/estoque/listData', [CadastrosEstoqueController::class, 'listData']);
Route::post('/estoque/delete/{id}', [CadastrosEstoqueController::class, 'delete']);

// Novas rotas do módulo de estoque
Route::get('/estoque/setor/{setorId}', [EstoqueController::class, 'listarPorSetor']);
Route::get('/estoque/{id}', [EstoqueController::class, 'show']);
Route::put('/estoque/{id}/quantidade-minima', [EstoqueController::class, 'atualizarQuantidadeMinima']);
Route::put('/estoque/{id}/status', [EstoqueController::class, 'atualizarStatus']);

Route::post('/grupoProduto/add', [GrupoProdutoController::class, 'add']);
Route::post('/grupoProduto/update', [GrupoProdutoController::class, 'update']);
Route::post('/grupoProduto/list', [GrupoProdutoController::class, 'listAll']);
Route::post('/grupoProduto/listData', [GrupoProdutoController::class, 'listData']);
Route::post('/grupoProduto/delete/{id}', [GrupoProdutoController::class, 'delete']);

// Rotas para controle de lotes no estoque
Route::post('/estoqueLote/list', [EstoqueLoteController::class, 'list']);
Route::post('/estoqueLote/updateQuantidade', [EstoqueLoteController::class, 'updateQuantidade']);

// Rotas para relatórios — exige autenticação via Sanctum
// (controle de perfil é feito no próprio controller via usuario_setor)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/relatorios/entradas/list', [RelatoriosController::class, 'listEntradasReport']);
    Route::post('/relatorios/movimentacoes/list', [RelatoriosController::class, 'listMovimentacoesReport']);
    Route::post('/relatorios/saidas/list', [RelatoriosController::class, 'listSaidasReport']);
    Route::post('/relatorios/saidas-por-data/list', [RelatoriosController::class, 'listSaidasPorData']);
    Route::post('/relatorios/entradas-por-data/list', [RelatoriosController::class, 'listEntradasPorData']);
    Route::post('/relatorios/estoque/list', [RelatoriosController::class, 'listEstoqueReport']);
    Route::post('/relatorios/usuarios/list', [RelatoriosController::class, 'listUsuariosReport']);
});

