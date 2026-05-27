<?php

namespace App\Http\Controllers;

use App\Models\EstoqueLote;
use App\Models\Estoque;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class EstoqueLoteController extends Controller
{
    /**
     * Listar lotes de um estoque específico pelo ID do estoque
     */
    public function list(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'estoque_id' => 'required|exists:estoque,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'validacao' => true,
                    'erros' => $validator->errors()
                ], 422);
            }

            // Buscar o estoque para pegar produto_id e setor_id
            $estoque = Estoque::find($request->estoque_id);

            $lotes = EstoqueLote::with([
                // 'codigo_unidade' não existe na tabela 'setores', removido
                'setor:id,nome,tipo',
                'produto:id,nome,marca,grupo_produto_id,unidade_medida_id',
                'produto.grupoProduto:id,nome,tipo',
                // 'abreviacao' não existe em unidade_medida (migration/model), removido
                'produto.unidadeMedida:id,nome',
            ])
                ->where('produto_id', $estoque->produto_id)
                ->where('setor_id', $estoque->setor_id)
                ->orderBy('data_vencimento', 'asc')
                ->get();

            return response()->json([
                'status' => true,
                'data' => $lotes,
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao listar lotes do estoque: ' . $e->getMessage(), [
                'payload' => $request->all(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Erro interno ao listar lotes do estoque.'
            ], 500);
        }
    }

    /**
     * Atualizar quantidade disponível de um lote específico
     */
    public function updateQuantidade(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'id' => 'required|exists:estoque_lote,id',
                'quantidade_disponivel' => 'required|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'validacao' => true,
                    'erros' => $validator->errors()
                ], 422);
            }

            $lote = EstoqueLote::find($request->id);
            $quantidadeAnterior = $lote->quantidade_disponivel;
            $diferenca = $request->quantidade_disponivel - $quantidadeAnterior;

            // Atualiza o lote
            $lote->quantidade_disponivel = $request->quantidade_disponivel;
            $lote->save();

            // Atualiza a quantidade geral do estoque
            $estoque = Estoque::where('produto_id', $lote->produto_id)
                ->where('setor_id', $lote->setor_id)
                ->first();

            if ($estoque) {
                $estoque->quantidade_atual += $diferenca;

                if ($estoque->quantidade_atual < 0) {
                    $estoque->quantidade_atual = 0;
                }

                $estoque->status_disponibilidade = $estoque->quantidade_atual > 0 ? 'D' : 'I';
                $estoque->save();
            }

            $lote->load(['setor', 'produto.grupoProduto', 'produto.unidadeMedida']);

            return response()->json([
                'status' => true,
                'message' => 'Quantidade do lote atualizada com sucesso.',
                'data' => $lote,
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao atualizar quantidade do lote: ' . $e->getMessage(), [
                'payload' => $request->all(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Erro interno ao atualizar quantidade do lote.'
            ], 500);
        }
    }
}
