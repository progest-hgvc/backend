<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Movimentacao;
use App\Models\ItemMovimentacao;
use App\Models\Estoque;
use App\Models\EstoqueLote;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class MovimentacaoController extends Controller
{
    // Criar movimentação (pode ser rascunho ou pendente)
    public function store(Request $request)
    {
        $data = $request->only(['usuario_id', 'setor_origem_id', 'setor_destino_id', 'tipo', 'observacao', 'status_solicitacao', 'itens']);

        // Normalizar itens: aceitar `quantidade` do front e mapear para `quantidade_solicitada`
        if (!empty($data['itens']) && is_array($data['itens'])) {
            foreach ($data['itens'] as $k => $it) {
                // mapear aliases comuns
                if (isset($it['quantidade']) && !isset($it['quantidade_solicitada'])) {
                    $data['itens'][$k]['quantidade_solicitada'] = $it['quantidade'];
                }
                if (isset($it['produtoId']) && !isset($it['produto_id'])) {
                    $data['itens'][$k]['produto_id'] = $it['produtoId'];
                }
            }
        }

        // Tarefa 1: Pendente de mover para um MovimentacaoRequest no futuro
        $validator = Validator::make($data, [
            'usuario_id' => 'required|integer|exists:users,id',
            'tipo' => 'required|in:T,D,S',
            'status_solicitacao' => 'nullable|in:A,R,P,C',
            'setor_origem_id' => 'nullable|integer|exists:setores,id',
            'setor_destino_id' => 'nullable|integer|exists:setores,id',
            'itens' => 'nullable|array',
            'itens.*.produto_id' => 'required_with:itens|integer|exists:produtos,id',
            'itens.*.quantidade_solicitada' => 'required_with:itens|numeric|min:0.0001'
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()], 422);
        }

        try {
            // tornar criação atômica: se falhar a criação dos itens, rollback
            $mov = DB::transaction(function () use ($data) {
                $mov = Movimentacao::create([
                    'usuario_id' => $data['usuario_id'],
                    'setor_origem_id' => $data['setor_origem_id'] ?? null,
                    'setor_destino_id' => $data['setor_destino_id'] ?? null,
                    'tipo' => $data['tipo'],
                    'data_hora' => now(),
                    'observacao' => $data['observacao'] ?? null,
                    'status_solicitacao' => $data['status_solicitacao'] ?? 'P'
                ]);

                // criar itens (obrigatórios quando enviados)
                if (!empty($data['itens']) && is_array($data['itens'])) {
                    foreach ($data['itens'] as $it) {
                        ItemMovimentacao::create([
                            'movimentacao_id' => $mov->id,
                            'produto_id' => $it['produto_id'],
                            'quantidade_solicitada' => $it['quantidade_solicitada'] ?? 0,
                            'quantidade_liberada' => $it['quantidade_liberada'] ?? 0,
                            'lote' => $it['lote'] ?? null
                        ]);
                    }
                }

                return $mov;
            });

            return response()->json(['status' => true, 'data' => $mov], 201);
        } catch (\Exception $e) {
            Log::error('Erro criando movimentação: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['status' => false, 'message' => 'Erro ao criar movimentação', 'detail' => $e->getMessage()], 500);
        }
    }

    // Listar solicitações por setor (relacionada como origem OU destino)
    public function listBySetor(Request $request)
    {
        // Compatibilidade: aceite 'setor_id' (novo) ou 'unidade_id' (legado do front)
        $setorId = $request->input('setor_id') ?? $request->input('unidade_id');
        if (!$setorId) {
            return response()->json(['status' => false, 'message' => 'setor_id (ou unidade_id) é obrigatório'], 422);
        }

        // Regras: rascunho aparece apenas para quem solicitou (destino). Pendentes aparecem para ambos.
        $movs = Movimentacao::with(['usuario', 'setorOrigem', 'setorDestino', 'itens.produto'])
            ->where(function ($q) use ($setorId) {
                $q->where('setor_origem_id', $setorId)
                    ->orWhere('setor_destino_id', $setorId);
            })
            ->orderBy('data_hora', 'desc')
            ->get()
            ->filter(function ($m) use ($setorId) {
                if ($m->status_solicitacao === 'C') { // rascunho
                    return $m->setor_destino_id == $setorId; // só mostrar rascunho para quem solicitou (destino)
                }
                return true;
            })
            ->values()
            ->map(function ($m) {
                // calcular quantidade de produtos distintos na movimentação
                $distinctCount = 0;
                if ($m->relationLoaded('itens') && $m->itens->isNotEmpty()) {
                    $distinctCount = $m->itens->pluck('produto_id')->unique()->count();
                }
                $m->itens_diferentes_count = $distinctCount;
                return $m;
            });

        return response()->json(['status' => true, 'data' => $movs]);
    }

    // Detalhes / itens da movimentação
    public function show($id)
    {
        $mov = Movimentacao::with(['itens.produto', 'usuario', 'setorOrigem', 'setorDestino'])->find($id);
        if (!$mov) {
            return response()->json(['status' => false, 'message' => 'Movimentação não encontrada'], 404);
        }
        return response()->json(['status' => true, 'data' => $mov]);
    }

    // Processar movimentação: aprovar, reprovar, ou mover rascunho->pendente
    public function process(Request $request, $id)
    {
        $mov = Movimentacao::with('itens.produto')->find($id);
        if (!$mov) return response()->json(['status' => false, 'message' => 'Movimentação não encontrada'], 404);

        $action = $request->input('action');
        if (!$action && $request->has('status')) {
            $statusMap = [
                'A' => 'approve',
                'R' => 'reject',
                'P' => 'submit',
                'X' => 'cancel'
            ];
            $status = $request->input('status');
            $action = $statusMap[$status] ?? null;
        }

        $aprovadorId = $request->input('aprovador_usuario_id') ?? $request->input('usuario_id');
        $itens = $request->input('itens'); // array de itens com quantidade_liberada ajustada

        if (!in_array($action, ['approve', 'reject', 'submit', 'cancel'])) {
            return response()->json(['status' => false, 'message' => "action inválida: '$action'"], 422);
        }

        try {
            DB::beginTransaction();

            if ($action === 'approve') {
                // ... (código existente de approve) ...
                // Para simplificar o diff, mantenho o código existente, 
                // mas na prática vou inserir logs no início e antes do save
            }

            Log::info("Processando ação: $action para Movimentacao ID: $id");

            if ($action === 'approve') {
                // Preparar mapa de quantidades liberadas
                $quantidadesLiberadas = [];
                if (!empty($itens) && is_array($itens)) {
                    foreach ($itens as $itemData) {
                        if (isset($itemData['id']) && isset($itemData['quantidade_liberada'])) {
                            $quantidadesLiberadas[$itemData['id']] = (int) $itemData['quantidade_liberada'];
                        }
                    }
                }
 
                // Validar estoque da origem antes de aprovar
                $errosEstoque = [];
                foreach ($mov->itens as $item) {
                $qtdLiberar = $quantidadesLiberadas[$item->id] ?? $item->quantidade_solicitada;

                    if ($qtdLiberar <= 0) continue; // Pular itens com quantidade zero

                    // Buscar estoque do produto na origem
                    // LOCK FOR UPDATE! 
                    // Se outro processo tentar aprovar no mesmo milissegundo, ele será forçado 
                    // a esperar esta transação terminar antes de conseguir ler o stock.
                    $estoqueOrigem = Estoque::where('produto_id', $item->produto_id)
                        ->where('setor_id', $mov->setor_origem_id)
                        ->lockForUpdate() 
                        ->first();
 
                    if (!$estoqueOrigem) {
                        $nomeProduto = $item->produto?->nome ?? "ID {$item->produto_id}";
                        $errosEstoque[] = "Produto '{$nomeProduto}' não encontrado no estoque.";
                        continue;
                    }

                    if ($estoqueOrigem->quantidade_atual < $qtdLiberar) {
                        $nomeProduto = $item->produto?->nome ?? "ID {$item->produto_id}";
                        $errosEstoque[] = "Estoque insuficiente para '{$nomeProduto}'. Disponível: {$estoqueOrigem->quantidade_atual}, Solicitado: {$qtdLiberar}.";
                    }
                }

                if (!empty($errosEstoque)) {
                    DB::rollBack();
                    return response()->json([
                        'status' => false,
                        'message' => 'Estoque insuficiente.',
                        'erros' => $errosEstoque
                    ], 422);
                }

                // Atualizar quantidades liberadas e transferir estoque
                foreach ($mov->itens as $item) {
                    $qtdLiberar = $quantidadesLiberadas[$item->id] ?? $item->quantidade_solicitada;
                    // Atualizar quantidade_liberada no item
                    $item->quantidade_liberada = $qtdLiberar;
                    $item->save();

                    if ($qtdLiberar <= 0) continue;

                    Log::info("Processando transferência", [
                        'produto_id' => $item->produto_id,
                        'quantidade' => $qtdLiberar,
                        'origem_id' => $mov->setor_origem_id,
                        'destino_id' => $mov->setor_destino_id
                    ]);

                    // 1. DEDUZIR do estoque de ORIGEM
                    $estoqueOrigem = Estoque::where('produto_id', $item->produto_id)
                        ->where('setor_id', $mov->setor_origem_id)
                        ->lockForUpdate()
                        ->first();

                    if (!$estoqueOrigem) {
                        throw new \Exception("Estoque de origem não encontrado para produto {$item->produto_id} no setor {$mov->setor_origem_id}");
                    }

                    $estoqueOrigem->quantidade_atual -= $qtdLiberar;
                    $estoqueOrigem->status_disponibilidade = $estoqueOrigem->quantidade_atual > 0 ? 'D' : 'I';
                    $estoqueOrigem->save();

                    Log::info("Estoque origem atualizado", [
                        'estoque_id' => $estoqueOrigem->id,
                        'nova_quantidade' => $estoqueOrigem->quantidade_atual
                    ]);

                    // 1b. DEDUZIR dos lotes da ORIGEM (FIFO: vencimento mais próximo primeiro)
                    $this->transferirLotesFifo(
                        $item->produto_id,
                        $mov->setor_origem_id,
                        $mov->setor_destino_id,
                        $qtdLiberar
                    );

                    // 2. INCREMENTAR o estoque de DESTINO
                    $estoqueDestino = Estoque::where('produto_id', $item->produto_id)
                        ->where('setor_id', $mov->setor_destino_id)
                        ->lockForUpdate()
                        ->first();

                    if (!$estoqueDestino) {
                        // Criar estoque de destino se não existir
                        Log::info("Criando novo estoque de destino", [
                            'produto_id' => $item->produto_id,
                            'setor_id' => $mov->setor_destino_id
                        ]);

                        $estoqueDestino = Estoque::create([
                            'produto_id' => $item->produto_id,
                            'setor_id' => $mov->setor_destino_id,
                            'quantidade_atual' => $qtdLiberar,
                            'quantidade_minima' => 0,
                            'status_disponibilidade' => 'D'
                        ]);

                        Log::info("Estoque de destino criado", [
                            'estoque_id' => $estoqueDestino->id,
                            'quantidade_inicial' => $estoqueDestino->quantidade_atual
                        ]);
                    } else {
                        // Incrementar estoque existente
                        $estoqueDestino->quantidade_atual += $qtdLiberar;
                        $estoqueDestino->status_disponibilidade = 'D';
                        $estoqueDestino->save();

                        Log::info("Estoque destino atualizado", [
                            'estoque_id' => $estoqueDestino->id,
                            'nova_quantidade' => $estoqueDestino->quantidade_atual
                        ]);
                    }
                }

                $mov->status_solicitacao = 'A';
                $mov->aprovador_usuario_id = $aprovadorId;

            } elseif ($action === 'reject') {
                $mov->status_solicitacao = 'R';
                $mov->aprovador_usuario_id = $aprovadorId;
            } elseif ($action === 'submit') {
                // sair de rascunho para pendente
                $mov->status_solicitacao = 'P';
            } elseif ($action === 'cancel') {
                Log::info("Entrou no bloco cancel. Status atual: " . $mov->status_solicitacao);
                // solicitante cancelando o pedido pendente
                if ($mov->status_solicitacao !== 'P') {
                    Log::warning("Tentativa de cancelar pedido não pendente. Status: " . $mov->status_solicitacao);
                    DB::rollBack();
                    return response()->json(['status' => false, 'message' => 'Apenas pendentes podem ser canceladas.'], 422);
                }
                $mov->status_solicitacao = 'X'; // X = Cancelado pelo solicitante
                Log::info("Status alterado para X");
            }

            $mov->save();
            Log::info("Movimentação salva com sucesso.");
            
            DB::commit();
            Log::info("Transação commitada.");

            return response()->json(['status' => true, 'data' => $mov]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro ao processar: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Erro interno.'], 500);
        }
    }

    // Atualizar status (compatibilidade com frontend)
    public function updateStatus(Request $request, $id)
    {
        Log::info("updateStatus chamado para movimentação ID: $id", [
            'payload' => $request->all()
        ]);

        $statusMap = [
            'A' => 'approve',  // Aprovado
            'R' => 'reject',   // Reprovado
            'P' => 'submit',   // Pendente (enviar rascunho)
            'X' => 'cancel'    // Cancelado
        ];

        $status = $request->input('status');
        if (!isset($statusMap[$status])) {
            Log::warning("Status inválido recebido: $status");
            return response()->json([
                'status' => false,
                'message' => "Status '$status' inválido. Use: A (Aprovar), R (Reprovar), P (Pendente), X (Cancelar)"
            ], 422);
        }

        $request->merge([
            'action' => $statusMap[$status],
            'aprovador_usuario_id' => $request->input('aprovador_usuario_id') ?? $request->input('usuario_id')
        ]);

        return $this->process($request, $id);
    }

    // Deletar apenas rascunhos
    public function destroy($id)
    {
        $mov = Movimentacao::find($id);
        if (!$mov) return response()->json(['status' => false, 'message' => 'Movimentação não encontrada'], 404);
        if ($mov->status_solicitacao !== 'C') {
            return response()->json(['status' => false, 'message' => 'Só é possível deletar movimentações em rascunho'], 403);
        }
        $mov->itens()->delete();
        $mov->delete();
        return response()->json(['status' => true]);
    }

    /**
     * Pré-visualização dos lotes que serão consumidos (FIFO) ao aprovar a movimentação.
     * Chame este endpoint ANTES de confirmar a aprovação para exibir ao usuário
     * qual lote (o de vencimento mais próximo) será descontado de cada produto.
     *
     * GET /api/movimentacao/{id}/preview-lotes
     */
    public function previewLotes($id)
    {
        $mov = Movimentacao::with('itens.produto')->find($id);
        if (!$mov) {
            return response()->json(['status' => false, 'message' => 'Movimentação não encontrada'], 404);
        }

        $preview = [];

        foreach ($mov->itens as $item) {
            $qtdNecessaria = (float) $item->quantidade_solicitada;
            $lotesUsados   = [];
            $restante      = $qtdNecessaria;

            $lotes = EstoqueLote::where('produto_id', $item->produto_id)
                ->where('setor_id', $mov->setor_origem_id)
                ->where('quantidade_disponivel', '>', 0)
                ->orderBy('data_vencimento', 'asc') // FIFO: mais antigo primeiro
                ->get();

            foreach ($lotes as $lote) {
                if ($restante <= 0) break;

                $qtdUsada = min((float) $lote->quantidade_disponivel, $restante);
                $lotesUsados[] = [
                    'lote'                  => $lote->lote,
                    'data_vencimento'       => $lote->data_vencimento,
                    'data_fabricacao'       => $lote->data_fabricacao,
                    'quantidade_disponivel' => (float) $lote->quantidade_disponivel,
                    'quantidade_a_usar'     => $qtdUsada,
                ];
                $restante -= $qtdUsada;
            }

            $preview[] = [
                'produto_id'               => $item->produto_id,
                'produto_nome'             => $item->produto?->nome ?? "ID {$item->produto_id}",
                'quantidade_solicitada'    => $qtdNecessaria,
                'quantidade_sem_cobertura' => max(0, $restante),
                'lotes_a_consumir'         => $lotesUsados,
            ];
        }

        return response()->json(['status' => true, 'data' => $preview]);
    }

    /**
     * Transfere quantidades entre lotes seguindo FIFO (vencimento mais próximo primeiro).
     * Deduz do setor de origem e incrementa no setor de destino (criando o registro se necessário).
     */
    private function transferirLotesFifo(int $produtoId, int $setorOrigemId, ?int $setorDestinoId, float $qtdLiberar): void
    {
        $restante = $qtdLiberar;

        $lotes = EstoqueLote::where('produto_id', $produtoId)
            ->where('setor_id', $setorOrigemId)
            ->where('quantidade_disponivel', '>', 0)
            ->orderBy('data_vencimento', 'asc') // FIFO
            ->lockForUpdate()
            ->get();

        foreach ($lotes as $lote) {
            if ($restante <= 0) break;

            $qtdDeducao = min((float) $lote->quantidade_disponivel, $restante);

            // Deduzir da origem
            $lote->quantidade_disponivel -= $qtdDeducao;
            $lote->save();
            $restante -= $qtdDeducao;

            Log::info('EstoqueLote origem descontado', [
                'lote'            => $lote->lote,
                'data_vencimento' => $lote->data_vencimento,
                'qtd_deduzida'    => $qtdDeducao,
                'qtd_restante'    => $lote->quantidade_disponivel,
            ]);

            // Incrementar no destino (apenas transferências com destino definido)
            if ($setorDestinoId) {
                $loteDestino = EstoqueLote::firstOrCreate(
                    [
                        'setor_id' => $setorDestinoId,
                        'produto_id' => $produtoId,
                        'lote'       => $lote->lote,
                    ],
                    [
                        'data_vencimento'       => $lote->data_vencimento,
                        'data_fabricacao'       => $lote->data_fabricacao,
                        'quantidade_disponivel' => 0,
                    ]
                );
                $loteDestino->quantidade_disponivel += $qtdDeducao;
                $loteDestino->save();

                Log::info('EstoqueLote destino incrementado', [
                    'lote'            => $lote->lote,
                    'data_vencimento' => $lote->data_vencimento,
                    'qtd_adicionada'  => $qtdDeducao,
                    'qtd_total'       => $loteDestino->quantidade_disponivel,
                ]);
            }
        }
    }
}
