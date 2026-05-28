<?php

namespace App\Http\Controllers\Cadastros;

use App\Models\Produto;
use App\Models\GrupoProduto;
use App\Models\UnidadeMedida;
use App\Http\Requests\ProdutoRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProdutoController
{
    /**
     * Listar todos os produtos
     */
    public function listAll(Request $request)
    {
        try {
            $query = Produto::with(['grupoProduto', 'unidadeMedida']);

            // Ignorar produtos sem nome (registros incompletos/importados sem dados)
            $query->whereNotNull('produtos.nome')->where('produtos.nome', '!=', '');

            // Busca textual por nome, marca ou grupo
            $search = $request->input('search');
            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('produtos.nome', 'LIKE', '%' . $search . '%')
                      ->orWhere('produtos.marca', 'LIKE', '%' . $search . '%')
                      ->orWhereHas('grupoProduto', function ($gq) use ($search) {
                          $gq->where('nome', 'LIKE', '%' . $search . '%');
                      });
                });
            }

            // Filtro por grupo_produto_id (direto)
            $grupoProdutoId = $request->input('grupo_produto_id');
            if (!empty($grupoProdutoId)) {
                $query->where('grupo_produto_id', $grupoProdutoId);
            }

            // Filtro por marca (direto)
            $marca = $request->input('marca');
            if (!empty($marca)) {
                $query->where('produtos.marca', $marca);
            }

            // Filtros via objeto ou array (suporta ambos os formatos do frontend)
            $filters = $request->input('filters', []);
            if (!empty($filters) && is_array($filters)) {
                // Formato objeto: {"tipo_produto": "Medicamento", "status": "A"}
                if (array_keys($filters) !== range(0, count($filters) - 1)) {
                    // Filtro por tipo do grupo_produto (ex: Medicamento / Material)
                    if (!empty($filters['tipo_produto'])) {
                        $query->whereHas('grupoProduto', function ($gq) use ($filters) {
                            $gq->where('tipo', $filters['tipo_produto']);
                        });
                    }
                    if (!empty($filters['status'])) {
                        $query->where('produtos.status', $filters['status']);
                    }
                    if (!empty($filters['grupo_produto_id'])) {
                        $query->where('grupo_produto_id', $filters['grupo_produto_id']);
                    }
                    if (!empty($filters['nome'])) {
                        $query->where('produtos.nome', 'LIKE', '%' . $filters['nome'] . '%');
                    }
                } else {
                    // Formato array legado: [{"status": "A"}, {"nome": "dipirona"}]
                    foreach ($filters as $condition) {
                        if (is_array($condition)) {
                            foreach ($condition as $column => $value) {
                                if ($value !== null && $value !== '') {
                                    $allowedColumns = ['nome', 'marca', 'grupo_produto_id', 'unidade_medida_id', 'status'];
                                    if (in_array($column, $allowedColumns)) {
                                        if (in_array($column, ['grupo_produto_id', 'unidade_medida_id', 'status'])) {
                                            $query->where($column, $value);
                                        } else {
                                            $query->where('produtos.' . $column, 'LIKE', '%' . $value . '%');
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // Ordenação dinâmica
            $sortBy  = $request->input('sort_by', 'nome');
            $sortDir = strtolower($request->input('sort_dir', 'asc'));
            if (!in_array($sortDir, ['asc', 'desc'])) $sortDir = 'asc';

            $allowedSortColumns = ['id', 'nome', 'marca', 'status'];
            if (in_array($sortBy, $allowedSortColumns)) {
                $query->orderBy('produtos.' . $sortBy, $sortDir);
            } elseif ($sortBy === 'grupo_produto') {
                $query->join('grupo_produto', 'produtos.grupo_produto_id', '=', 'grupo_produto.id')
                      ->orderBy('grupo_produto.nome', $sortDir);
            } elseif ($sortBy === 'unidade_medida') {
                $query->join('unidade_medida', 'produtos.unidade_medida_id', '=', 'unidade_medida.id')
                      ->orderBy('unidade_medida.nome', $sortDir);
            } else {
                $query->orderBy('produtos.nome', 'asc');
            }

            $query->select(
                'produtos.id', 'produtos.nome', 'produtos.marca',
                'produtos.codigo_simpas', 'produtos.codigo_barras',
                'produtos.grupo_produto_id', 'produtos.unidade_medida_id', 'produtos.status'
            );

            // Paginação: per_page padrão 1000; se per_page=0 retorna tudo (sem paginação)
            $perPage = (int) $request->input('per_page', 1000);
            $page    = (int) $request->input('page', 1);

            if ($perPage > 0) {
                $paginator = $query->paginate($perPage, ['*'], 'page', $page);
                return response()->json([
                    'status' => true,
                    'data'   => $paginator->items(),
                    'meta'   => [
                        'total'        => $paginator->total(),
                        'per_page'     => $paginator->perPage(),
                        'current_page' => $paginator->currentPage(),
                        'last_page'    => $paginator->lastPage(),
                    ],
                ]);
            }

            return response()->json(['status' => true, 'data' => $query->get()]);
        } catch (\Throwable $e) {
            Log::error('Erro ao listar produtos: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Obter dados de um produto específico
     */
    public function listData(Request $request)
    {
        try {
            $id = $request->input('id');

            if (!$id) return response()->json(['status' => false, 'message' => 'ID do produto é obrigatório'], 400);

            $produto = Produto::with(['grupoProduto', 'unidadeMedida'])->find($id);

            if (!$produto) return response()->json(['status' => false, 'message' => 'Produto não encontrado'], 404);

            return response()->json(['status' => true, 'data' => $produto]);
        } catch (\Throwable $e) {
            Log::error('Erro ao buscar produto: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Criar novo produto
     */
    public function add(ProdutoRequest $request)
    {
        try {
            $data = $request->validated()['produto'];

            $produto = new Produto();
            $produto->nome = trim($data['nome']);
            $produto->marca = !empty($data['marca']) ? trim($data['marca']) : null;
            $produto->codigo_simpas = !empty($data['codigo_simpas']) ? trim($data['codigo_simpas']) : null;
            $produto->codigo_barras = !empty($data['codigo_barras']) ? trim($data['codigo_barras']) : null;
            $produto->grupo_produto_id = $data['grupo_produto_id'];
            $produto->unidade_medida_id = $data['unidade_medida_id'];
            $produto->status = $data['status'] ?? 'A';

            $produto->save();
            $produto->load(['grupoProduto', 'unidadeMedida']);

            return response()->json(['status' => true, 'data' => $produto, 'message' => 'Produto cadastrado com sucesso'], 201);
        } catch (\Throwable $e) {
            Log::error('Erro ao criar produto: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Erro interno do servidor: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Atualizar produto existente
     */
    public function update(ProdutoRequest $request)
    {
        try {
            $data = $request->validated()['produto'];

            $produto = Produto::find($data['id']);
            if (!$produto) return response()->json(['status' => false, 'message' => 'Produto não encontrado'], 404);

            $produto->nome = trim($data['nome']);
            $produto->marca = !empty($data['marca']) ? trim($data['marca']) : null;
            $produto->codigo_simpas = !empty($data['codigo_simpas']) ? trim($data['codigo_simpas']) : null;
            $produto->codigo_barras = !empty($data['codigo_barras']) ? trim($data['codigo_barras']) : null;
            $produto->grupo_produto_id = $data['grupo_produto_id'];
            $produto->unidade_medida_id = $data['unidade_medida_id'];
            $produto->status = $data['status'] ?? $produto->status;

            $produto->save();
            $produto->load(['grupoProduto', 'unidadeMedida']);

            return response()->json(['status' => true, 'data' => $produto, 'message' => 'Produto atualizado com sucesso']);
        } catch (\Throwable $e) {
            Log::error('Erro ao atualizar produto: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Erro interno do servidor: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Excluir produto
     */
    public function delete($id)
    {
        try {
            $produto = Produto::find($id);
            if (!$produto) return response()->json(['status' => false, 'message' => 'Produto não encontrado'], 404);

            // Ao inativar, verificar referências
            if ($produto->status === 'A') {
                $references = [];
                if ($produto->itensEntrada()->count() > 0) $references[] = 'Entradas';
                if ($produto->itensMovimentacao()->count() > 0) $references[] = 'Movimentações';

                if (!empty($references)) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Não é possível inativar: existem registros vinculados (' . implode(', ', $references) . ').',
                    ], 422);
                }
            }

            $produto->status = $produto->status === 'A' ? 'I' : 'A';
            $produto->save();
            $produto->load(['grupoProduto', 'unidadeMedida']);

            $action = $produto->status === 'A' ? 'ativado' : 'inativado';
            return response()->json(['status' => true, 'message' => "Produto {$action} com sucesso.", 'data' => $produto]);
        } catch (\Throwable $e) {
            Log::error('Erro ao alterar status do produto: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Alterar status do produto (ativar/inativar)
     */
    public function toggleStatus(Request $request)
    {
        try {
            $id = $request->input('id');

            if (!$id) return response()->json(['status' => false, 'message' => 'ID do produto é obrigatório'], 400);

            $produto = Produto::find($id);
            if (!$produto) return response()->json(['status' => false, 'message' => 'Produto não encontrado'], 404);

            $produto->status = $produto->status === 'A' ? 'I' : 'A';
            $produto->save();

            // Recarregar com relacionamentos
            $produto->load(['grupoProduto', 'unidadeMedida']);

            $statusText = $produto->status === 'A' ? 'ativado' : 'inativado';

            return response()->json(['status' => true, 'data' => $produto, 'message' => "Produto {$statusText} com sucesso"]);
        } catch (\Throwable $e) {
            Log::error('Erro ao alterar status do produto: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Obter dados auxiliares para o formulário (grupos e unidades)
     */
    public function getDadosAuxiliares(Request $request)
    {
        try {
            $grupos = GrupoProduto::where('status', 'A')->select('id', 'nome')->orderBy('nome')->get();
            $unidades = UnidadeMedida::where('status', 'A')->select('id', 'nome', 'sigla')->orderBy('nome')->get();

            return response()->json(['status' => true, 'data' => ['grupos' => $grupos, 'unidades' => $unidades]]);
        } catch (\Throwable $e) {
            Log::error('Erro ao buscar dados auxiliares: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Listar produtos filtrados por tipo (Medicamento/Material).
     * Usado no formulário de movimentações para filtrar produtos pelo tipo do setor fornecedor.
     */
    public function listByTipo(Request $request)
    {
        try {
            $tipo = $request->input('tipo');

            if (!$tipo) return response()->json(['status' => false, 'message' => 'Tipo é obrigatório'], 400);

            $produtos = Produto::with(['grupoProduto', 'unidadeMedida'])
                ->whereHas('grupoProduto', function ($query) use ($tipo) {
                    $query->where('tipo', $tipo);
                })
                ->where('status', 'A')
                ->select('id', 'nome', 'marca', 'codigo_simpas', 'grupo_produto_id', 'unidade_medida_id')
                ->orderBy('nome')
                ->get();

            return response()->json(['status' => true, 'data' => $produtos]);
        } catch (\Throwable $e) {
            Log::error('Erro ao listar produtos por tipo: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }
}
