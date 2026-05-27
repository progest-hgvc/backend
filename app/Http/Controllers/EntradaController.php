<?php

namespace App\Http\Controllers;

use App\Models\Entrada;
use App\Models\Estoque;
use App\Models\EstoqueLote;
use App\Models\ItensEntrada;
use App\Models\Produto;
use App\Models\Setores;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class EntradaController extends Controller
{
    /**
     * Registrar uma nova entrada de produtos no estoque do setor.
     */
    public function add(Request $request)
    {
        $data = $request->all();

        $validator = Validator::make($data, [
            'nota_fiscal' => 'required|string|max:255',
            'setor_id' => 'required|exists:setores,id',
            'fornecedor_id' => 'required|exists:fornecedores,id',
            'itens' => 'required|array|min:1',
            'itens.*.produto_id' => 'required|exists:produtos,id',
            'itens.*.quantidade' => 'required|integer|min:1',
            'itens.*.lote' => 'required|string|max:50',
            'itens.*.data_vencimento' => 'required|date|after:today',
            'itens.*.data_fabricacao' => 'nullable|date|before_or_equal:today',
        ], [
            'nota_fiscal.required' => 'A nota fiscal é obrigatória.',
            'setor_id.required' => 'O setor é obrigatório.',
            'setor_id.exists' => 'Setor não encontrado.',
            'fornecedor_id.required' => 'O fornecedor é obrigatório.',
            'fornecedor_id.exists' => 'Fornecedor não encontrado.',
            'itens.required' => 'Informe ao menos um item para a entrada.',
            'itens.array' => 'A lista de itens deve ser um array.',
            'itens.min' => 'Informe ao menos um item para a entrada.',
            'itens.*.produto_id.required' => 'Produto é obrigatório em todos os itens.',
            'itens.*.produto_id.exists' => 'Produto informado não foi encontrado.',
            'itens.*.quantidade.required' => 'Quantidade é obrigatória em todos os itens.',
            'itens.*.quantidade.integer' => 'Quantidade deve ser um número inteiro.',
            'itens.*.quantidade.min' => 'Quantidade deve ser ao menos 1.',
            'itens.*.lote.required' => 'O lote é obrigatório em todos os itens.',
            'itens.*.lote.max' => 'O lote deve ter no máximo 50 caracteres.',
            'itens.*.data_vencimento.required' => 'A data de vencimento é obrigatória.',
            'itens.*.data_vencimento.date' => 'A data de vencimento deve ser uma data válida.',
            'itens.*.data_vencimento.after' => 'A data de vencimento deve ser posterior à data atual.',
            'itens.*.data_fabricacao.date' => 'A data de fabricação deve ser uma data válida.',
            'itens.*.data_fabricacao.before_or_equal' => 'A data de fabricação não pode ser futura.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'validacao' => true,
                'erros' => $validator->errors()
            ], 422);
        }

        $setor = Setores::find($data['setor_id']);

        if (!$setor->estoque) {
            return response()->json([
                'status' => false,
                'message' => 'O setor selecionado não possui controle de estoque.'
            ], 400);
        }

        try {
            $entrada = DB::transaction(function () use ($data, $setor) {
                $entrada = Entrada::create([
                    'nota_fiscal' => mb_strtoupper(trim($data['nota_fiscal'])),
                    'setor_id' => $setor->id,
                    'fornecedor_id' => $data['fornecedor_id'],
                ]);

                foreach ($data['itens'] as $item) {
                    $produto = Produto::with('grupoProduto')->find($item['produto_id']);

                    if (!$produto) {
                        throw new \RuntimeException('Produto não encontrado.');
                    }

                    if (!$produto->grupoProduto || $produto->grupoProduto->tipo !== $setor->tipo) {
                        throw new \RuntimeException('Produto "' . $produto->nome . '" não é compatível com o tipo do setor.');
                    }

                    $itemEntrada = ItensEntrada::create([
                        'entrada_id' => $entrada->id,
                        'produto_id' => $produto->id,
                        'quantidade' => $item['quantidade'],
                        'lote' => mb_strtoupper(trim($item['lote'])),
                        'data_vencimento' => $item['data_vencimento'],
                        'data_fabricacao' => $item['data_fabricacao'] ?? null,
                    ]);

                    // Evitando Lost Update na Entrada
                    $estoqueBase = Estoque::firstOrCreate(
                        ['produto_id' => $produto->id, 'setor_id' => $setor->id],
                        ['quantidade_atual' => 0, 'quantidade_minima' => 0, 'status_disponibilidade' => 'D']
                    );

                    // Tranca a linha para ninguém ler enquanto somamos
                    $estoqueTravado = Estoque::where('id', $estoqueBase->id)->lockForUpdate()->first();
                    $estoqueTravado->quantidade_atual += $itemEntrada->quantidade;
                    $estoqueTravado->status_disponibilidade = 'D';
                    $estoqueTravado->save();    

                    // Atualizar ou criar registro de estoque por lote
                    $estoqueLote = EstoqueLote::firstOrCreate(
                        [
                            'setor_id' => $setor->id,
                            'produto_id' => $produto->id,
                            'lote' => mb_strtoupper(trim($item['lote'])),
                        ],
                        [
                            'quantidade_disponivel' => 0,
                            'data_vencimento' => $item['data_vencimento'],
                            'data_fabricacao' => $item['data_fabricacao'] ?? null,
                        ]
                    );

                    $estoqueLote->quantidade_disponivel += $itemEntrada->quantidade;
                    $estoqueLote->save();
                }

                return $entrada;
            });

            $entrada->load(['setor', 'fornecedor', 'itens.produto']);

            return response()->json([
                'status' => true,
                'message' => 'Entrada registrada com sucesso.',
                'data' => $entrada,
            ], 201);
        } catch (\RuntimeException $e) {
            Log::warning('Falha de validação na criação de entrada: ' . $e->getMessage(), [
                'payload' => $data,
            ]);

            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('Erro ao registrar entrada: ' . $e->getMessage(), [
                'payload' => $data,
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Erro interno ao registrar entrada.'
            ], 500);
        }
    }

    /**
     * Listar entradas com seus itens e detalhes dos produtos
     */
    public function list(Request $request)
    {
        try {
            $data = $request->all();
            $filters = $data['filters'] ?? [];
            $perPage = $data['per_page'] ?? 15;

            $query = Entrada::with([
                // 'codigo_unidade' não existe na tabela 'Setores' (migration), removido para evitar SQL error
                'setor:id,nome,tipo',
                'fornecedor:id,razao_social_nome,tipo_pessoa,status',
                'itens.produto:id,nome,marca,grupo_produto_id,unidade_medida_id,status',
                'itens.produto.grupoProduto:id,nome,tipo',
                'itens.produto.unidadeMedida:id,nome',
            ])->orderByDesc('created_at');

            if (!empty($filters)) {
                foreach ($filters as $key => $value) {
                    if ($value === null || $value === '') {
                        continue;
                    }

                    switch ($key) {
                        case 'nota_fiscal':
                            $query->where('nota_fiscal', 'like', '%' . trim($value) . '%');
                            break;
                        case 'setor_id':
                            $query->where('setor_id', $value);
                            break;
                        case 'fornecedor_id':
                            $query->where('fornecedor_id', $value);
                            break;
                    }
                }
            }

            /** @var LengthAwarePaginator $entradas */
            $entradas = $query->paginate($perPage);

            $entradas->getCollection()->transform(function (Entrada $entrada) {
                return [
                    'id' => $entrada->id,
                    'nota_fiscal' => $entrada->nota_fiscal,
                    'created_at' => $entrada->created_at,
                    'setor' => $entrada->setor,
                    'fornecedor' => $entrada->fornecedor,
                    'itens' => $entrada->itens->map(function (ItensEntrada $item) {
                        return [
                            'id' => $item->id,
                            'quantidade' => $item->quantidade,
                            'lote' => $item->lote,
                            'data_vencimento' => $item->data_vencimento,
                            'data_fabricacao' => $item->data_fabricacao,
                            'produto' => [
                                'id' => $item->produto->id,
                                'nome' => $item->produto->nome,
                                'marca' => $item->produto->marca,
                                'status' => $item->produto->status,
                                'grupo_produto' => $item->produto->grupoProduto,
                                'unidade_medida' => $item->produto->unidadeMedida,
                            ],
                        ];
                    })->values(),
                ];
            });

            return response()->json([
                'status' => true,
                'data' => $entradas,
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao listar entradas: ' . $e->getMessage(), [
                'payload' => $request->all(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Erro interno ao listar entradas.'
            ], 500);
        }
    }

    /**
     * Atualizar uma entrada existente e seus itens ajustando o estoque
     */
    public function update(Request $request)
    {
        $data = $request->all();

        $validator = Validator::make($data, [
            'id' => 'required|exists:entrada,id',
            'nota_fiscal' => 'required|string|max:255',
            'setor_id' => 'required|exists:setores,id',
            'fornecedor_id' => 'required|exists:fornecedores,id',
            'itens' => 'required|array|min:1',
            'itens.*.produto_id' => 'required|exists:produtos,id',
            'itens.*.quantidade' => 'required|integer|min:1',
            'itens.*.lote' => 'required|string|max:50',
            'itens.*.data_vencimento' => 'required|date|after:today',
            'itens.*.data_fabricacao' => 'nullable|date|before_or_equal:today',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'validacao' => true,
                'erros' => $validator->errors()
            ], 422);
        }

        $entrada = Entrada::with(['itens'])->find($data['id']);
        $setor = Setores::find($data['setor_id']);

        if (!$setor->estoque) {
            return response()->json([
                'status' => false,
                'message' => 'O setor selecionado não possui controle de estoque.'
            ], 400);
        }

        try {
            $entradaAtualizada = DB::transaction(function () use ($data, $entrada, $setor) {
                // Reverter estoque dos itens atuais (tanto estoque geral quanto lotes)
                foreach ($entrada->itens as $itemExistente) {
                    // Reverter estoque geral
                    $estoque = Estoque::where('produto_id', $itemExistente->produto_id)
                        ->where('setor_id', $entrada->setor_id)
                        ->first();

                    if ($estoque) {
                        $estoque->quantidade_atual -= $itemExistente->quantidade;
                        if ($estoque->quantidade_atual < 0) {
                            $estoque->quantidade_atual = 0;
                        }
                        $estoque->status_disponibilidade = $estoque->quantidade_atual > 0 ? 'D' : 'I';
                        $estoque->save();
                    }

                    // Reverter estoque de lote
                    if ($itemExistente->lote) {
                        $estoqueLote = EstoqueLote::where('setor_id', $entrada->setor_id)
                            ->where('produto_id', $itemExistente->produto_id)
                            ->where('lote', $itemExistente->lote)
                            ->first();

                        if ($estoqueLote) {
                            $estoqueLote->quantidade_disponivel -= $itemExistente->quantidade;
                            if ($estoqueLote->quantidade_disponivel < 0) {
                                $estoqueLote->quantidade_disponivel = 0;
                            }
                            $estoqueLote->save();
                        }
                    }
                }

                // Atualiza dados da entrada
                $entrada->update([
                    'nota_fiscal' => mb_strtoupper(trim($data['nota_fiscal'])),
                    'setor_id' => $setor->id,
                    'fornecedor_id' => $data['fornecedor_id'],
                ]);

                // Remove itens antigos
                ItensEntrada::where('entrada_id', $entrada->id)->delete();

                // Cadastra novos itens e atualiza estoque
                foreach ($data['itens'] as $item) {
                    $produto = Produto::with('grupoProduto')->find($item['produto_id']);

                    if (!$produto || !$produto->grupoProduto || $produto->grupoProduto->tipo !== $setor->tipo) {
                        throw new \RuntimeException('Produto "' . $produto->nome . '" não é compatível com o tipo do setor.');
                    }

                    $itemEntrada = ItensEntrada::create([
                        'entrada_id' => $entrada->id,
                        'produto_id' => $produto->id,
                        'quantidade' => $item['quantidade'],
                        'lote' => mb_strtoupper(trim($item['lote'])),
                        'data_vencimento' => $item['data_vencimento'],
                        'data_fabricacao' => $item['data_fabricacao'] ?? null,
                    ]);

                    // Atualizar estoque geral
                    $estoque = Estoque::firstOrCreate(
                        [
                            'produto_id' => $produto->id,
                            'setor_id' => $setor->id,
                        ],
                        [
                            'quantidade_atual' => 0,
                            'quantidade_minima' => 0,
                            'status_disponibilidade' => 'D',
                        ]
                    );

                    $estoque->quantidade_atual += $itemEntrada->quantidade;
                    $estoque->status_disponibilidade = $estoque->quantidade_atual > 0 ? 'D' : 'I';
                    $estoque->save();

                    // Atualizar ou criar estoque de lote
                    $estoqueLote = EstoqueLote::firstOrCreate(
                        [
                            'setor_id' => $setor->id,
                            'produto_id' => $produto->id,
                            'lote' => mb_strtoupper(trim($item['lote'])),
                        ],
                        [
                            'quantidade_disponivel' => 0,
                            'data_vencimento' => $item['data_vencimento'],
                            'data_fabricacao' => $item['data_fabricacao'] ?? null,
                        ]
                    );

                    $estoqueLote->quantidade_disponivel += $itemEntrada->quantidade;
                    $estoqueLote->save();
                }

                return $entrada;
            });

            $entradaAtualizada->load(['setor', 'fornecedor', 'itens.produto']);

            return response()->json([
                'status' => true,
                'message' => 'Entrada atualizada com sucesso.',
                'data' => $entradaAtualizada,
            ]);
        } catch (\RuntimeException $e) {
            Log::warning('Falha de validação na atualização de entrada: ' . $e->getMessage(), [
                'payload' => $data,
            ]);

            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('Erro ao atualizar entrada: ' . $e->getMessage(), [
                'payload' => $data,
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Erro interno ao atualizar entrada.'
            ], 500);
        }
    }

    /**
     * Remover uma entrada e reverter o estoque relacionado
     */
    public function delete(Request $request)
    {
        $data = $request->all();

        $validator = Validator::make($data, [
            'id' => 'required|exists:entrada,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'validacao' => true,
                'erros' => $validator->errors()
            ], 422);
        }

        try {
            DB::transaction(function () use ($data) {
                $entrada = Entrada::with('itens')->find($data['id']);

                foreach ($entrada->itens as $item) {
                    $estoque = Estoque::where('produto_id', $item->produto_id)
                        ->where('setor_id', $entrada->setor_id)
                        ->first();

                    if ($estoque) {
                        $estoque->quantidade_atual -= $item->quantidade;
                        if ($estoque->quantidade_atual < 0) {
                            $estoque->quantidade_atual = 0;
                        }
                        $estoque->status_disponibilidade = $estoque->quantidade_atual > 0 ? 'D' : 'I';
                        $estoque->save();
                    }
                }

                ItensEntrada::where('entrada_id', $entrada->id)->delete();
                $entrada->delete();
            });

            return response()->json([
                'status' => true,
                'message' => 'Entrada removida com sucesso.'
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao remover entrada: ' . $e->getMessage(), [
                'payload' => $data,
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Erro interno ao remover entrada.'
            ], 500);
        }
    }
}
