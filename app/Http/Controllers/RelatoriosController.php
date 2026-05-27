<?php

namespace App\Http\Controllers;

use App\Models\Entrada;
use App\Models\Movimentacao;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use \Illuminate\Support\Facades\DB;

class RelatoriosController extends Controller
{
    /**
     * Relatório de Entradas
     * POST /api/relatorios/entradas/list
     * 
     * Retorna lista paginada de entradas com filtros por:
     * - Data inicial/final
     * - Setor
     * - Fornecedor
     * - Nota fiscal
     */
    public function listEntradasReport(Request $request)
    {
        try {
            $data = $request->all();
            
            // Validação dos filtros
            $validator = Validator::make($data, [
                'filters.date_from' => 'nullable|date',
                'filters.date_to' => 'nullable|date|after_or_equal:filters.date_from',
                'filters.polo_id' => 'nullable|exists:polos,id',
                'filters.setor_id' => 'nullable|exists:setores,id',
                'filters.fornecedor_id' => 'nullable|exists:fornecedores,id',
                'filters.nota_fiscal' => 'nullable|string|max:255',
            ], [
                'filters.date_to.after_or_equal' => 'A data final deve ser posterior ou igual à data inicial.',
                'filters.polo_id.exists' => 'Polo não encontrado.',
                'filters.setor_id.exists' => 'Setor não encontrado.',
                'filters.fornecedor_id.exists' => 'Fornecedor não encontrado.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'validacao' => true,
                    'erros' => $validator->errors()
                ], 422);
            }

            // Query base com eager loading para evitar N+1
            $query = Entrada::with([
                'fornecedor:id,razao_social_nome,cnpj',
                'setor:id,nome,tipo,polo_id',
                'setor.polo:id,nome',
                'itens.produto:id,nome,codigo_simpras,codigo_barras,grupo_produto_id,unidade_medida_id',
                'itens.produto.unidadeMedida:id,nome',
                'itens.produto.grupoProduto:id,nome,tipo'
            ]);

            // Restringir aos setores que o usuário autenticado tem acesso
            $user = auth()->user();
            $setoresPermitidos = \Illuminate\Support\Facades\DB::table('usuario_setor')
                ->where('usuario_id', $user->id)
                ->pluck('setor_id');
            $query->whereIn('setor_id', $setoresPermitidos);

            // Aplicar filtros se fornecidos
            $filters = $data['filters'] ?? [];

            if (!empty($filters['date_from'])) {
                $query->whereDate('created_at', '>=', $filters['date_from']);
            }

            if (!empty($filters['date_to'])) {
                $query->whereDate('created_at', '<=', $filters['date_to']);
            }

            if (!empty($filters['polo_id'])) {
                $query->whereHas('setor', function ($q) use ($filters) {
                    $q->where('polo_id', $filters['polo_id']);
                });
            }

            if (!empty($filters['setor_id'])) {
                $query->where('setor_id', $filters['setor_id']);
            }

            if (!empty($filters['fornecedor_id'])) {
                $query->where('fornecedor_id', $filters['fornecedor_id']);
            }

            if (!empty($filters['nota_fiscal'])) {
                $query->where('nota_fiscal', 'like', '%' . mb_strtoupper($filters['nota_fiscal']) . '%');
            }

            // Ordenação: mais recentes primeiro
            $query->orderByDesc('created_at');

            // Buscar todos os resultados
            $results = $query->get();

            return response()->json([
                'status' => true,
                'message' => 'Relatório de entradas recuperado com sucesso',
                'data' => $results,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao gerar relatório de entradas: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => false,
                'message' => 'Erro ao gerar relatório de entradas'
            ], 500);
        }
    }

    /**
     * Relatório de Movimentações
     * POST /api/relatorios/movimentacoes/list
     * 
     * Retorna lista paginada de movimentações com filtros por:
     * - Data inicial/final
     * - Tipo (transferencia, saida, devolucao)
     * - Setor origem
     * - Setor destino
     * - Status
     */
    public function listMovimentacoesReport(Request $request)
    {
        try {
            $data = $request->all();
            
            // Validação dos filtros
            $validator = Validator::make($data, [
                'filters.date_from' => 'nullable|date',
                'filters.date_to' => 'nullable|date|after_or_equal:filters.date_from',
                'filters.polo_id' => 'nullable|exists:polos,id',
                'filters.setor_id' => 'nullable|exists:setores,id',
                'filters.tipo' => 'nullable|string|in:T,S,D',
                'filters.setor_origem_id' => 'nullable|exists:setores,id',
                'filters.setor_destino_id' => 'nullable|exists:setores,id',
                'filters.status' => 'nullable|string|in:A,I',
            ], [
                'filters.date_to.after_or_equal' => 'A data final deve ser posterior ou igual à data inicial.',
                'filters.polo_id.exists' => 'Polo não encontrado.',
                'filters.setor_id.exists' => 'Setor não encontrado.',
                'filters.tipo.in' => 'Tipo de movimentação inválido. Use: T (Transferência), S (Saída) ou D (Devolução).',
                'filters.setor_origem_id.exists' => 'Setor de origem não encontrado.',
                'filters.setor_destino_id.exists' => 'Setor de destino não encontrado.',
                'filters.status.in' => 'Status inválido. Use: A (Ativo) ou I (Inativo).',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'validacao' => true,
                    'erros' => $validator->errors()
                ], 422);
            }

            // Query base com eager loading para evitar N+1
            $query = Movimentacao::with([
                'setorOrigem:id,nome,tipo,polo_id',
                'setorOrigem.polo:id,nome',
                'setorDestino:id,nome,tipo,polo_id',
                'setorDestino.polo:id,nome',
                'usuario:id,name,email',
                'aprovador:id,name,email',
                'itens.produto:id,nome,codigo_simpras,codigo_barras,grupo_produto_id,unidade_medida_id',
                'itens.produto.unidadeMedida:id,nome',
                'itens.produto.grupoProduto:id,nome,tipo'
            ]);

            // Restringir aos setores que o usuário autenticado tem acesso
            $user = auth()->user();
            $setoresPermitidos = \Illuminate\Support\Facades\DB::table('usuario_setor')
                ->where('usuario_id', $user->id)
                ->pluck('setor_id');
            $query->where(function ($q) use ($setoresPermitidos) {
                $q->whereIn('setor_origem_id', $setoresPermitidos)
                  ->orWhereIn('setor_destino_id', $setoresPermitidos);
            });

            // Aplicar filtros se fornecidos
            $filters = $data['filters'] ?? [];

            if (!empty($filters['date_from'])) {
                $query->whereDate('created_at', '>=', $filters['date_from']);
            }

            if (!empty($filters['date_to'])) {
                $query->whereDate('created_at', '<=', $filters['date_to']);
            }

            if (!empty($filters['tipo'])) {
                $query->where('tipo', $filters['tipo']);
            }

            // Filtro de polo: considera origem OU destino
            if (!empty($filters['polo_id'])) {
                $query->where(function ($q) use ($filters) {
                    $q->whereHas('setorOrigem', function ($sq) use ($filters) {
                        $sq->where('polo_id', $filters['polo_id']);
                    })->orWhereHas('setorDestino', function ($sq) use ($filters) {
                        $sq->where('polo_id', $filters['polo_id']);
                    });
                });
            }

            // Filtro de setor: considera origem OU destino
            if (!empty($filters['setor_id'])) {
                $query->where(function ($q) use ($filters) {
                    $q->where('setor_origem_id', $filters['setor_id'])
                      ->orWhere('setor_destino_id', $filters['setor_id']);
                });
            }

            if (!empty($filters['setor_origem_id'])) {
                $query->where('setor_origem_id', $filters['setor_origem_id']);
            }

            if (!empty($filters['setor_destino_id'])) {
                $query->where('setor_destino_id', $filters['setor_destino_id']);
            }

            if (!empty($filters['status'])) {
                $query->where('status_solicitacao', $filters['status']);
            }

            // Ordenação: mais recentes primeiro
            $query->orderByDesc('created_at');

            // Buscar todos os resultados
            $results = $query->get();

            // Enriquecer os itens com informações de lote (data_fabricacao e data_vencimento)
            $results->each(function ($movimentacao) {
                $movimentacao->itens->each(function ($item) use ($movimentacao) {
                    if ($item->lote && $movimentacao->setor_destino_id) {
                        // Buscar informações do lote na tabela estoque_lote
                        $loteInfo = \App\Models\EstoqueLote::where('produto_id', $item->produto_id)
                            ->where('lote', $item->lote)
                            ->where('setor_id', $movimentacao->setor_destino_id) // Setor que forneceu
                            ->first(['data_fabricacao', 'data_vencimento']);
                        
                        if ($loteInfo) {
                            $item->data_fabricacao = $loteInfo->data_fabricacao;
                            $item->data_vencimento = $loteInfo->data_vencimento;
                        } else {
                            $item->data_fabricacao = null;
                            $item->data_vencimento = null;
                        }
                    } else {
                        $item->data_fabricacao = null;
                        $item->data_vencimento = null;
                    }
                });
            });

            return response()->json([
                'status' => true,
                'message' => 'Relatório de movimentações recuperado com sucesso',
                'data' => $results,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao gerar relatório de movimentações: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => false,
                'message' => 'Erro ao gerar relatório de movimentações'
            ], 500);
        }
    }

    /**
     * Relatório de Saídas
     * POST /api/relatorios/saidas/list
     * 
     * Retorna lista paginada de movimentações do tipo 'S' (Saída) com filtros por:
     * - Data inicial/final (usando campo data_hora)
     * - Setor origem (quem solicitou a saída)
     * - Produto
     * - Status da solicitação
     */
    public function listSaidasReport(Request $request)
    {
        try {
            $data = $request->all();
            
            // Validação dos filtros
            $validator = Validator::make($data, [
                'filters.date_from' => 'nullable|date',
                'filters.date_to' => 'nullable|date|after_or_equal:filters.date_from',
                'filters.polo_id' => 'nullable|exists:polos,id',
                'filters.setor_id' => 'nullable|exists:setores,id',
                'filters.setor_origem_id' => 'nullable|exists:setores,id',
                'filters.produto_id' => 'nullable|exists:produtos,id',
                'filters.status' => 'nullable|string|in:A,R,P,C,X',
            ], [
                'filters.date_to.after_or_equal' => 'A data final deve ser posterior ou igual à data inicial.',
                'filters.polo_id.exists' => 'Polo não encontrado.',
                'filters.setor_id.exists' => 'Setor não encontrado.',
                'filters.setor_origem_id.exists' => 'Setor de origem não encontrado.',
                'filters.produto_id.exists' => 'Produto não encontrado.',
                'filters.status.in' => 'Status inválido. Use: A (Aprovado), R (Reprovado), P (Pendente), C (Rascunho), X (Cancelado).',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'validacao' => true,
                    'erros' => $validator->errors()
                ], 422);
            }

            // Query base com eager loading para evitar N+1
            // Filtra apenas movimentações do tipo 'S' (Saída)
            $query = Movimentacao::with([
                'setorOrigem:id,nome,tipo,polo_id',
                'setorOrigem.polo:id,nome',
                'setorDestino:id,nome,tipo,polo_id',
                'setorDestino.polo:id,nome',
                'usuario:id,name,email',
                'aprovador:id,name,email',
                'itens.produto:id,nome,codigo_simpras,codigo_barras,grupo_produto_id,unidade_medida_id',
                'itens.produto.unidadeMedida:id,nome',
                'itens.produto.grupoProduto:id,nome,tipo'
            ])->where('tipo', 'S');

            // Restringir aos setores que o usuário autenticado tem acesso
            $user = auth()->user();
            $setoresPermitidos = \Illuminate\Support\Facades\DB::table('usuario_setor')
                ->where('usuario_id', $user->id)
                ->pluck('setor_id');
            $query->where(function ($q) use ($setoresPermitidos) {
                $q->whereIn('setor_origem_id', $setoresPermitidos)
                  ->orWhereIn('setor_destino_id', $setoresPermitidos);
            });

            // Aplicar filtros se fornecidos
            $filters = $data['filters'] ?? [];

            if (!empty($filters['date_from'])) {
                $query->whereDate('data_hora', '>=', $filters['date_from']);
            }

            if (!empty($filters['date_to'])) {
                $query->whereDate('data_hora', '<=', $filters['date_to']);
            }

            // Filtro de polo: considera origem OU destino
            if (!empty($filters['polo_id'])) {
                $query->where(function ($q) use ($filters) {
                    $q->whereHas('setorOrigem', function ($sq) use ($filters) {
                        $sq->where('polo_id', $filters['polo_id']);
                    })->orWhereHas('setorDestino', function ($sq) use ($filters) {
                        $sq->where('polo_id', $filters['polo_id']);
                    });
                });
            }

            // Filtro de setor: considera origem OU destino
            if (!empty($filters['setor_id'])) {
                $query->where(function ($q) use ($filters) {
                    $q->where('setor_origem_id', $filters['setor_id'])
                      ->orWhere('setor_destino_id', $filters['setor_id']);
                });
            }

            if (!empty($filters['setor_origem_id'])) {
                $query->where('setor_origem_id', $filters['setor_origem_id']);
            }

            if (!empty($filters['produto_id'])) {
                // Filtrar por produto através da relação com itens
                $query->whereHas('itens', function ($q) use ($filters) {
                    $q->where('produto_id', $filters['produto_id']);
                });
            }

            if (!empty($filters['status'])) {
                $query->where('status_solicitacao', $filters['status']);
            }

            // Ordenação: mais recentes primeiro
            $query->orderByDesc('data_hora');

            // Buscar todos os resultados
            $results = $query->get();

            // Enriquecer os itens com informações de lote (data_fabricacao e data_vencimento)
            $results->each(function ($movimentacao) {
                $movimentacao->itens->each(function ($item) use ($movimentacao) {
                    if ($item->lote) {
                        // Buscar informações do lote na tabela estoque_lote
                        $loteInfo = \App\Models\EstoqueLote::where('produto_id', $item->produto_id)
                            ->where('lote', $item->lote)
                            ->where('setor_id', $movimentacao->setor_destino_id) // Setor que forneceu
                            ->first(['data_fabricacao', 'data_vencimento']);
                        
                        if ($loteInfo) {
                            $item->data_fabricacao = $loteInfo->data_fabricacao;
                            $item->data_vencimento = $loteInfo->data_vencimento;
                        } else {
                            $item->data_fabricacao = null;
                            $item->data_vencimento = null;
                        }
                    } else {
                        $item->data_fabricacao = null;
                        $item->data_vencimento = null;
                    }
                });
            });

            return response()->json([
                'status' => true,
                'message' => 'Relatório de saídas recuperado com sucesso',
                'data' => $results,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao gerar relatório de saídas: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => false,
                'message' => 'Erro ao gerar relatório de saídas'
            ], 500);
        }
    }

    /**
     * Relatório de Saídas por Data (Agregado por Produto)
     * POST /api/relatorios/saidas-por-data/list
     * 
     * Retorna lista consolidada de saídas agrupadas por data e produto.
     * Calcula a quantidade total de cada produto que saiu em cada dia,
     * somando todas as requisições de todos os setores.
     * 
     * Exemplo: Se saiu 10 dipironas para UTI e 15 para Emergência no mesmo dia,
     * o relatório mostrará 25 dipironas no total.
     * 
     * Filtros opcionais:
     * - Data inicial/final (se não informado, usa data atual)
     * - Setor origem (para filtrar por setor específico)
     * - Produto (para filtrar produto específico)
     */
    public function listSaidasPorData(Request $request)
    {
        try {
            $data = $request->all();
            
            // Validação dos filtros
            $validator = Validator::make($data, [
                'filters.date_from' => 'nullable|date',
                'filters.date_to' => 'nullable|date|after_or_equal:filters.date_from',
                'filters.polo_id' => 'nullable|exists:polos,id',
                'filters.setor_id' => 'nullable|exists:setores,id',
                'filters.produto_id' => 'nullable|exists:produtos,id',
            ], [
                'filters.date_to.after_or_equal' => 'A data final deve ser posterior ou igual à data inicial.',
                'filters.polo_id.exists' => 'Polo não encontrado.',
                'filters.setor_id.exists' => 'Setor não encontrado.',
                'filters.produto_id.exists' => 'Produto não encontrado.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'validacao' => true,
                    'erros' => $validator->errors()
                ], 422);
            }

            $filters = $data['filters'] ?? [];
            
            // Se não informar período, usa data atual
            $dateFrom = $filters['date_from'] ?? date('Y-m-d');
            $dateTo = $filters['date_to'] ?? $dateFrom;

            // Restringir aos setores que o usuário autenticado tem acesso
            $user = auth()->user();
            $setoresPermitidos = \Illuminate\Support\Facades\DB::table('usuario_setor')
                ->where('usuario_id', $user->id)
                ->pluck('setor_id')
                ->toArray();

            // Query agregada: agrupa por data e produto, soma quantidades
            $query = DB::table('item_movimentacao as im')
                ->join('movimentacao as m', 'im.movimentacao_id', '=', 'm.id')
                ->join('produtos as p', 'im.produto_id', '=', 'p.id')
                ->join('unidade_medida as um', 'p.unidade_medida_id', '=', 'um.id')
                ->join('grupo_produto as gp', 'p.grupo_produto_id', '=', 'gp.id')
                ->select(
                    DB::raw('DATE(m.data_hora) as data'),
                    'p.id as produto_id',
                    'p.nome as produto_nome',
                    'p.codigo_simpras',
                    'p.codigo_barras',
                    'um.nome as unidade_medida',
                    'gp.nome as grupo_produto',
                    'gp.tipo as tipo_produto',
                    DB::raw('SUM(im.quantidade_liberada) as quantidade_total'),
                    DB::raw('COUNT(DISTINCT m.id) as total_movimentacoes'),
                    DB::raw('COUNT(DISTINCT m.setor_origem_id) as total_setores')
                )
                ->where('m.tipo', 'S') // Apenas saídas
                ->where('m.status_solicitacao', 'A') // Apenas aprovadas
                ->whereDate('m.data_hora', '>=', $dateFrom)
                ->whereDate('m.data_hora', '<=', $dateTo)
                ->where(function ($q) use ($setoresPermitidos) {
                    $q->whereIn('m.setor_origem_id', $setoresPermitidos)
                      ->orWhereIn('m.setor_destino_id', $setoresPermitidos);
                });

            // Aplicar filtros opcionais
            if (!empty($filters['polo_id'])) {
                $query->where(function ($q) use ($filters) {
                    $q->whereExists(function ($subq) use ($filters) {
                        $subq->select(DB::raw(1))
                             ->from('setores as so')
                             ->whereRaw('so.id = m.setor_origem_id')
                             ->where('so.polo_id', $filters['polo_id']);
                    })->orWhereExists(function ($subq) use ($filters) {
                        $subq->select(DB::raw(1))
                             ->from('setores as sd')
                             ->whereRaw('sd.id = m.setor_destino_id')
                             ->where('sd.polo_id', $filters['polo_id']);
                    });
                });
            }

            if (!empty($filters['setor_id'])) {
                $query->where(function ($q) use ($filters) {
                    $q->where('m.setor_origem_id', $filters['setor_id'])
                      ->orWhere('m.setor_destino_id', $filters['setor_id']);
                });
            }

            if (!empty($filters['produto_id'])) {
                $query->where('p.id', $filters['produto_id']);
            }

            // Agrupar por data e produto
            $query->groupBy(
                DB::raw('DATE(m.data_hora)'),
                'p.id',
                'p.nome',
                'p.codigo_simpras',
                'p.codigo_barras',
                'um.nome',
                'gp.nome',
                'gp.tipo'
            );

            // Buscar todos os resultados com ordenação
            $results = $query
                ->orderByDesc(DB::raw('DATE(m.data_hora)'))
                ->orderByDesc(DB::raw('SUM(im.quantidade_liberada)'))
                ->get();

            // Agrupar resultados por data
            $groupedByDate = [];
            foreach ($results as $item) {
                $data = $item->data;
                
                if (!isset($groupedByDate[$data])) {
                    $groupedByDate[$data] = [
                        'data' => $data,
                        'produtos' => [],
                        'total_produtos' => 0,
                        'quantidade_total_dia' => 0
                    ];
                }
                
                // Buscar movimentações detalhadas (origem e destino) deste produto nesta data
                $movimentacoesDetalhadas = DB::table('item_movimentacao as im')
                    ->join('movimentacao as m', 'im.movimentacao_id', '=', 'm.id')
                    ->join('setores as so', 'm.setor_origem_id', '=', 'so.id')
                    ->leftJoin('setores as sd', 'm.setor_destino_id', '=', 'sd.id')
                    ->select(
                        'm.id as movimentacao_id',
                        'so.id as setor_origem_id',
                        'so.nome as setor_origem_nome',
                        'sd.id as setor_destino_id',
                        'sd.nome as setor_destino_nome',
                        'im.quantidade_liberada as quantidade',
                        'm.data_hora',
                        'm.observacao'
                    )
                    ->where('im.produto_id', $item->produto_id)
                    ->whereDate('m.data_hora', $data)
                    ->where('m.tipo', 'S')
                    ->where('m.status_solicitacao', 'A')
                    ->orderBy('m.data_hora', 'desc')
                    ->get();
                
                $groupedByDate[$data]['produtos'][] = [
                    'produto' => [
                        'id' => $item->produto_id,
                        'nome' => $item->produto_nome,
                        'codigo_simpras' => $item->codigo_simpras,
                        'codigo_barras' => $item->codigo_barras,
                        'unidade_medida' => $item->unidade_medida,
                        'grupo_produto' => $item->grupo_produto,
                        'tipo' => $item->tipo_produto,
                    ],
                    'quantidade_total' => (int) $item->quantidade_total,
                    'total_movimentacoes' => (int) $item->total_movimentacoes,
                    'movimentacoes' => $movimentacoesDetalhadas->map(function($mov) {
                        return [
                            'movimentacao_id' => $mov->movimentacao_id,
                            'quantidade' => (int) $mov->quantidade,
                            'setor_origem' => [
                                'id' => $mov->setor_origem_id,
                                'nome' => $mov->setor_origem_nome
                            ],
                            'setor_destino' => $mov->setor_destino_id ? [
                                'id' => $mov->setor_destino_id,
                                'nome' => $mov->setor_destino_nome
                            ] : null,
                            'data_hora' => $mov->data_hora,
                            'observacao' => $mov->observacao
                        ];
                    })
                ];
                
                $groupedByDate[$data]['total_produtos']++;
                $groupedByDate[$data]['quantidade_total_dia'] += (int) $item->quantidade_total;
            }
            
            // Converter para array indexado e ordenar por data
            $resultsWithDetails = array_values($groupedByDate);

            return response()->json([
                'status' => true,
                'message' => 'Relatório de saídas por data recuperado com sucesso',
                'data' => $resultsWithDetails,
                'periodo' => [
                    'data_inicial' => $dateFrom,
                    'data_final' => $dateTo
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao gerar relatório de saídas por data: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => false,
                'message' => 'Erro ao gerar relatório de saídas por data'
            ], 500);
        }
    }

    /**
     * Relatório de Entradas por Data (Agregado por Produto)
     * POST /api/relatorios/entradas-por-data/list
     * 
     * Retorna lista consolidada de entradas agrupadas por data e produto.
     * Calcula a quantidade total de cada produto que entrou em cada dia,
     * somando todas as entradas de todos os fornecedores.
     * 
     * Exemplo: Se entraram 100 dipironas do Fornecedor A e 50 do Fornecedor B no mesmo dia,
     * o relatório mostrará 150 dipironas no total.
     * 
     * Filtros opcionais:
     * - Data inicial/final (se não informado, usa data atual)
     * - Setor (unidade que recebeu)
     * - Fornecedor (para filtrar por fornecedor específico)
     * - Produto (para filtrar produto específico)
     */
    public function listEntradasPorData(Request $request)
    {
        try {
            $data = $request->all();
            
            // Validação dos filtros
            $validator = Validator::make($data, [
                'filters.date_from' => 'nullable|date',
                'filters.date_to' => 'nullable|date|after_or_equal:filters.date_from',
                'filters.polo_id' => 'nullable|exists:polos,id',
                'filters.setor_id' => 'nullable|exists:setores,id',
                'filters.fornecedor_id' => 'nullable|exists:fornecedores,id',
                'filters.produto_id' => 'nullable|exists:produtos,id',
            ], [
                'filters.date_to.after_or_equal' => 'A data final deve ser posterior ou igual à data inicial.',
                'filters.polo_id.exists' => 'Polo não encontrado.',
                'filters.setor_id.exists' => 'Setor não encontrado.',
                'filters.fornecedor_id.exists' => 'Fornecedor não encontrado.',
                'filters.produto_id.exists' => 'Produto não encontrado.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'validacao' => true,
                    'erros' => $validator->errors()
                ], 422);
            }

            $filters = $data['filters'] ?? [];
            
            // Se não informar período, usa data atual
            $dateFrom = $filters['date_from'] ?? date('Y-m-d');
            $dateTo = $filters['date_to'] ?? $dateFrom;

            // Restringir aos setores que o usuário autenticado tem acesso
            $user = auth()->user();
            $setoresPermitidos = \Illuminate\Support\Facades\DB::table('usuario_setor')
                ->where('usuario_id', $user->id)
                ->pluck('setor_id')
                ->toArray();

            // Query agregada: agrupa por data e produto, soma quantidades
            $query = DB::table('itens_entrada as ie')
                ->join('entrada as e', 'ie.entrada_id', '=', 'e.id')
                ->join('produtos as p', 'ie.produto_id', '=', 'p.id')
                ->join('unidade_medida as um', 'p.unidade_medida_id', '=', 'um.id')
                ->join('grupo_produto as gp', 'p.grupo_produto_id', '=', 'gp.id')
                ->select(
                    DB::raw('DATE(e.created_at) as data'),
                    'p.id as produto_id',
                    'p.nome as produto_nome',
                    'p.codigo_simpras',
                    'p.codigo_barras',
                    'um.nome as unidade_medida',
                    'gp.nome as grupo_produto',
                    'gp.tipo as tipo_produto',
                    DB::raw('SUM(ie.quantidade) as quantidade_total'),
                    DB::raw('COUNT(DISTINCT e.id) as total_entradas'),
                    DB::raw('COUNT(DISTINCT e.fornecedor_id) as total_fornecedores')
                )
                ->whereDate('e.created_at', '>=', $dateFrom)
                ->whereDate('e.created_at', '<=', $dateTo)
                ->whereIn('e.setor_id', $setoresPermitidos);

            // Aplicar filtros opcionais
            if (!empty($filters['polo_id'])) {
                $query->whereExists(function ($subq) use ($filters) {
                    $subq->select(DB::raw(1))
                         ->from('setores as s')
                         ->whereRaw('s.id = e.setor_id')
                         ->where('s.polo_id', $filters['polo_id']);
                });
            }

            if (!empty($filters['setor_id'])) {
                $query->where('e.setor_id', $filters['setor_id']);
            }

            if (!empty($filters['fornecedor_id'])) {
                $query->where('e.fornecedor_id', $filters['fornecedor_id']);
            }

            if (!empty($filters['produto_id'])) {
                $query->where('p.id', $filters['produto_id']);
            }

            // Agrupar por data e produto
            $query->groupBy(
                DB::raw('DATE(e.created_at)'),
                'p.id',
                'p.nome',
                'p.codigo_simpras',
                'p.codigo_barras',
                'um.nome',
                'gp.nome',
                'gp.tipo'
            );

            // Buscar todos os resultados com ordenação
            $results = $query
                ->orderByDesc(DB::raw('DATE(e.created_at)'))
                ->orderByDesc(DB::raw('SUM(ie.quantidade)'))
                ->get();

            // Agrupar resultados por data
            $groupedByDate = [];
            foreach ($results as $item) {
                $data = $item->data;
                
                if (!isset($groupedByDate[$data])) {
                    $groupedByDate[$data] = [
                        'data' => $data,
                        'produtos' => [],
                        'total_produtos' => 0,
                        'quantidade_total_dia' => 0
                    ];
                }
                
                // Buscar entradas detalhadas (fornecedor, nota fiscal, setor) deste produto nesta data
                $entradasDetalhadas = DB::table('itens_entrada as ie')
                    ->join('entrada as e', 'ie.entrada_id', '=', 'e.id')
                    ->join('fornecedores as f', 'e.fornecedor_id', '=', 'f.id')
                    ->join('setores as s', 'e.setor_id', '=', 's.id')
                    ->select(
                        'e.id as entrada_id',
                        'e.nota_fiscal',
                        'f.id as fornecedor_id',
                        'f.razao_social_nome as fornecedor_nome',
                        'f.cnpj as fornecedor_cnpj',
                        's.id as setor_id',
                        's.nome as setor_nome',
                        'ie.quantidade',
                        'ie.lote',
                        'ie.data_vencimento',
                        'ie.data_fabricacao',
                        'e.created_at'
                    )
                    ->where('ie.produto_id', $item->produto_id)
                    ->whereDate('e.created_at', $data)
                    ->orderBy('e.created_at', 'desc')
                    ->get();
                
                $groupedByDate[$data]['produtos'][] = [
                    'produto' => [
                        'id' => $item->produto_id,
                        'nome' => $item->produto_nome,
                        'codigo_simpras' => $item->codigo_simpras,
                        'codigo_barras' => $item->codigo_barras,
                        'unidade_medida' => $item->unidade_medida,
                        'grupo_produto' => $item->grupo_produto,
                        'tipo' => $item->tipo_produto,
                    ],
                    'quantidade_total' => (int) $item->quantidade_total,
                    'total_entradas' => (int) $item->total_entradas,
                    'total_fornecedores' => (int) $item->total_fornecedores,
                    'entradas' => $entradasDetalhadas->map(function($entrada) {
                        return [
                            'entrada_id' => $entrada->entrada_id,
                            'nota_fiscal' => $entrada->nota_fiscal,
                            'quantidade' => (int) $entrada->quantidade,
                            'lote' => $entrada->lote,
                            'data_vencimento' => $entrada->data_vencimento,
                            'data_fabricacao' => $entrada->data_fabricacao,
                            'fornecedor' => [
                                'id' => $entrada->fornecedor_id,
                                'nome' => $entrada->fornecedor_nome,
                                'cnpj' => $entrada->fornecedor_cnpj
                            ],
                            'setor' => [
                                'id' => $entrada->setor_id,
                                'nome' => $entrada->setor_nome
                            ],
                            'created_at' => $entrada->created_at
                        ];
                    })
                ];
                
                $groupedByDate[$data]['total_produtos']++;
                $groupedByDate[$data]['quantidade_total_dia'] += (int) $item->quantidade_total;
            }
            
            // Converter para array indexado e ordenar por data
            $resultsWithDetails = array_values($groupedByDate);

            return response()->json([
                'status' => true,
                'message' => 'Relatório de entradas por data recuperado com sucesso',
                'data' => $resultsWithDetails,
                'periodo' => [
                    'data_inicial' => $dateFrom,
                    'data_final' => $dateTo
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao gerar relatório de entradas por data: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => false,
                'message' => 'Erro ao gerar relatório de entradas por data'
            ], 500);
        }
    }


    public function listEstoqueReport(Request $request)
    {
        try {
            $data = $request->all();
            
            // Validação dos filtros
            $validator = Validator::make($data, [
                'filters.polo_id' => 'nullable|exists:polos,id',
                'filters.setor_id' => 'nullable|exists:setores,id',
                'filters.produto_id' => 'nullable|exists:produtos,id',
                'filters.tipo' => 'nullable|string|in:Medicamento,Material',
                'filters.status_disponibilidade' => 'nullable|string|in:D,I',
                'filters.abaixo_minimo' => 'nullable|boolean',
                'filters.dias_vencimento' => 'nullable|integer|min:1|max:365',
            ], [
                'filters.polo_id.exists' => 'Polo não encontrado.',
                'filters.setor_id.exists' => 'Setor não encontrado.',
                'filters.produto_id.exists' => 'Produto não encontrado.',
                'filters.tipo.in' => 'Tipo inválido. Use: Medicamento ou Material.',
                'filters.status_disponibilidade.in' => 'Status inválido. Use: D (Disponível) ou I (Indisponível).',
                'filters.abaixo_minimo.boolean' => 'Filtro abaixo do mínimo deve ser verdadeiro ou falso.',
                'filters.dias_vencimento.integer' => 'Dias de vencimento deve ser um número inteiro.',
                'filters.dias_vencimento.min' => 'Dias de vencimento deve ser ao menos 1.',
                'filters.dias_vencimento.max' => 'Dias de vencimento não pode exceder 365.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'validacao' => true,
                    'erros' => $validator->errors()
                ], 422);
            }

            // Query base com eager loading para evitar N+1
            $query = \App\Models\Estoque::with([
                'produto:id,nome,codigo_simpras,codigo_barras,grupo_produto_id,unidade_medida_id',
                'produto.grupoProduto:id,nome,tipo',
                'produto.unidadeMedida:id,nome',
                'setor:id,polo_id,nome,tipo',
                'setor.polo:id,nome'
            ]);

            // Restringir aos setores que o usuário autenticado tem acesso
            $user = auth()->user();
            $setoresPermitidos = \Illuminate\Support\Facades\DB::table('usuario_setor')
                ->where('usuario_id', $user->id)
                ->pluck('setor_id');
            // Na tabela estoque, 'setor_id' referencia setores.id
            $query->whereIn('estoque.setor_id', $setoresPermitidos);

            // Aplicar filtros se fornecidos
            $filters = $data['filters'] ?? [];

            // Filtro por polo
            if (!empty($filters['polo_id'])) {
                $query->whereHas('setor', function ($q) use ($filters) {
                    $q->where('polo_id', $filters['polo_id']);
                });
            }

            // Filtro por setor
            if (!empty($filters['setor_id'])) {
                $query->where('setor_id', $filters['setor_id']);
            }

            if (!empty($filters['produto_id'])) {
                $query->where('produto_id', $filters['produto_id']);
            }

            if (!empty($filters['status_disponibilidade'])) {
                $query->where('status_disponibilidade', $filters['status_disponibilidade']);
            }

            // Filtro por tipo de produto
            if (!empty($filters['tipo'])) {
                $query->whereHas('produto.grupoProduto', function ($q) use ($filters) {
                    $q->where('tipo', $filters['tipo']);
                });
            }

            // Filtro por produtos abaixo do mínimo
            if (!empty($filters['abaixo_minimo']) && $filters['abaixo_minimo'] === true) {
                $query->whereRaw('quantidade_atual < quantidade_minima');
            }

            // Ordenação: por nome do produto e depois por setor
            $query->join('setores as s', 'estoque.setor_id', '=', 's.id')
                  ->join('produtos as p', 'estoque.produto_id', '=', 'p.id')
                  ->orderBy('p.nome', 'asc')
                  ->orderBy('s.nome', 'asc')
                  ->select('estoque.*'); // Importante: selecionar apenas campos da tabela estoque

            // Buscar todos os resultados
            $results = $query->get();

            // Buscar lotes para cada item do estoque
            $items = collect($results)->map(function ($estoque) use ($filters) {
                // Buscar lotes deste produto neste setor
                $lotesQuery = \App\Models\EstoqueLote::where('setor_id', $estoque->setor_id)
                    ->where('produto_id', $estoque->produto_id)
                    ->where('quantidade_disponivel', '>', 0)
                    ->orderBy('data_vencimento', 'asc');

                // Filtro por dias de vencimento se fornecido
                if (!empty($filters['dias_vencimento'])) {
                    $dataLimite = now()->addDays($filters['dias_vencimento']);
                    $lotesQuery->whereDate('data_vencimento', '<=', $dataLimite);
                }

                $lotes = $lotesQuery->get();

                // Calcular estatísticas dos lotes
                $loteVencimentoProximo = $lotes->first(); // Primeiro lote (mais próximo de vencer)
                $totalLotes = $lotes->count();
                $quantidadeLotes = $lotes->sum('quantidade_disponivel');

                // Adicionar informações de lotes ao objeto estoque
                $estoque->lotes_info = [
                    'total_lotes' => $totalLotes,
                    'quantidade_total_lotes' => (int) $quantidadeLotes,
                    'lote_proximo_vencimento' => $loteVencimentoProximo ? [
                        'lote' => $loteVencimentoProximo->lote,
                        'quantidade' => (int) $loteVencimentoProximo->quantidade_disponivel,
                        'data_vencimento' => $loteVencimentoProximo->data_vencimento->format('Y-m-d'),
                        'dias_para_vencer' => now()->diffInDays($loteVencimentoProximo->data_vencimento, false)
                    ] : null,
                    'lotes' => $lotes->map(function($lote) {
                        return [
                            'id' => $lote->id,
                            'lote' => $lote->lote,
                            'quantidade_disponivel' => (int) $lote->quantidade_disponivel,
                            'data_vencimento' => $lote->data_vencimento->format('Y-m-d'),
                            'data_fabricacao' => $lote->data_fabricacao ? $lote->data_fabricacao->format('Y-m-d') : null,
                            'dias_para_vencer' => now()->diffInDays($lote->data_vencimento, false),
                            'vencido' => $lote->data_vencimento < now()
                        ];
                    })
                ];

                // Adicionar flag se está abaixo do mínimo
                $estoque->abaixo_minimo = $estoque->quantidade_atual < $estoque->quantidade_minima;

                return $estoque;
            });

            // Calcular totalizadores
            $totalizadores = [
                'total_itens' => $items->count(),
                'total_produtos_disponiveis' => \App\Models\Estoque::where('status_disponibilidade', 'D')->count(),
                'total_produtos_indisponiveis' => \App\Models\Estoque::where('status_disponibilidade', 'I')->count(),
                'total_abaixo_minimo' => \App\Models\Estoque::whereRaw('quantidade_atual < quantidade_minima')->count(),
            ];

            return response()->json([
                'status' => true,
                'message' => 'Relatório de estoque recuperado com sucesso',
                'data' => $items,
                'totalizadores' => $totalizadores
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao gerar relatório de estoque: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => false,
                'message' => 'Erro ao gerar relatório de estoque'
            ], 500);
        }
    }

    /**
     * Relatório de Usuários
     * POST /api/relatorios/usuarios/list
     * 
     * Retorna lista de usuários com seus setores vinculados e filtros por:
     * - Status (A/I)
     * - Tipo de vínculo
     * - Setor
     * - Perfil
     */
    public function listUsuariosReport(Request $request)
    {
        try {
            $data = $request->all();
            
            // Validação dos filtros
            $validator = Validator::make($data, [
                'filters.status' => 'nullable|string|in:A,I',
                'filters.tipo_vinculo_id' => 'nullable|exists:tipo_vinculo,id',
                'filters.setor_id' => 'nullable|exists:setores,id',
                'filters.perfil' => 'nullable|string|in:admin,almoxarife,solicitante',
            ], [
                'filters.status.in' => 'Status inválido. Use: A (Ativo) ou I (Inativo).',
                'filters.tipo_vinculo_id.exists' => 'Tipo de vínculo não encontrado.',
                'filters.setor_id.exists' => 'Setor não encontrado.',
                'filters.perfil.in' => 'Perfil inválido. Use: admin, almoxarife ou solicitante.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'validacao' => true,
                    'erros' => $validator->errors()
                ], 422);
            }

            // Query base com eager loading para evitar N+1
            $query = \App\Models\User::with([
                'tipoVinculo:id,nome,descricao,status',
                'setores:id,nome,tipo,polo_id',
                'setores.polo:id,nome'
            ]);

            // Aplicar filtros se fornecidos
            $filters = $data['filters'] ?? [];

            if (!empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (!empty($filters['tipo_vinculo_id'])) {
                $query->where('tipo_vinculo', $filters['tipo_vinculo_id']);
            }

            // Filtro por setor: usuários que pertencem ao setor especificado
            if (!empty($filters['setor_id'])) {
                $query->whereHas('setores', function ($q) use ($filters) {
                    $q->where('setores.id', $filters['setor_id']);
                });
            }

            // Filtro por perfil: usuários que têm o perfil especificado em algum setor
            if (!empty($filters['perfil'])) {
                $query->whereHas('setores', function ($q) use ($filters) {
                    $q->where('usuario_setor.perfil', $filters['perfil']);
                });
            }

            // Ordenação: por nome do usuário
            $query->orderBy('name', 'asc');

            // Buscar todos os resultados
            $results = $query->get();

            // Transformar os dados para incluir informações do pivot
            $users = $results->map(function ($user) use ($filters) {
                $userData = [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'cpf' => $user->cpf,
                    'telefone' => $user->telefone,
                    'data_nascimento' => $user->data_nascimento,
                    'status' => $user->status,
                    'tipo_vinculo_id' => $user->tipo_vinculo,
                    'tipo_vinculo' => $user->tipoVinculo ? [
                        'id' => $user->tipoVinculo->id,
                        'nome' => $user->tipoVinculo->nome,
                        'descricao' => $user->tipoVinculo->descricao,
                        'status' => $user->tipoVinculo->status
                    ] : null,
                    'usuario_tipo' => $user->usuario_tipo,
                    'created_at' => $user->created_at,
                    'setores' => []
                ];

                // Adicionar setores com informações do pivot (perfil)
                foreach ($user->setores as $setor) {
                    // Se há filtro de setor_id, incluir apenas esse setor
                    if (!empty($filters['setor_id']) && $setor->id != $filters['setor_id']) {
                        continue;
                    }
                    
                    // Se há filtro de perfil, incluir apenas setores com esse perfil
                    if (!empty($filters['perfil']) && $setor->pivot->perfil !== $filters['perfil']) {
                        continue;
                    }

                    $userData['setores'][] = [
                        'id' => $setor->id,
                        'nome' => $setor->nome,
                        'tipo' => $setor->tipo,
                        'unidade' => $setor->unidade ? [
                            'id' => $setor->unidade->id,
                            'nome' => $setor->unidade->nome
                        ] : null,
                        'perfil' => $setor->pivot->perfil,
                        'data_vinculo' => $setor->pivot->created_at ? $setor->pivot->created_at->format('Y-m-d H:i:s') : null,
                    ];
                }

                // Contar total de setores
                $userData['total_setores'] = count($userData['setores']);

                return $userData;
            });

            // Calcular totalizadores
            $totalizadores = [
                'total_usuarios' => $users->count(),
                'total_ativos' => \App\Models\User::where('status', 'A')->count(),
                'total_inativos' => \App\Models\User::where('status', 'I')->count(),
            ];

            return response()->json([
                'status' => true,
                'message' => 'Relatório de usuários recuperado com sucesso',
                'data' => $users,
                'totalizadores' => $totalizadores
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao gerar relatório de usuários: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => false,
                'message' => 'Erro ao gerar relatório de usuários'
            ], 500);
        }
    }
}
