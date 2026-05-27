<?php

namespace App\Http\Controllers\Cadastros;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\Setores;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SetoresController
{
    public function add(Request $request)
    {
        $data = $request->all();

        // Aceitar tanto 'Setores' quanto 'setores' para compatibilidade
        $setoresData = $data['Setores'] ?? $data['setores'] ?? null;

        if (!$setoresData) {
            return response()->json([
                'status' => false,
                'message' => 'Dados do setor não informados.'
            ], 422);
        }

        $validator = Validator::make($setoresData, [
            'polo_id'       => 'required|exists:polos,id',
            'nome'          => 'required|string|max:255',
            'estoque'       => 'sometimes|boolean',
            'tipo'          => 'sometimes|in:Medicamento,Material',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'validacao' => true,
                'erros' => $validator->errors()
            ], 422);
        }

        $setor = new Setores;
        $setor->polo_id   = $setoresData['polo_id'];
        $setor->nome      = mb_strtoupper($setoresData['nome']);
        $setor->descricao = $setoresData['descricao'] ?? '';
        $setor->status    = $setoresData['status'] ?? 'A';
        $setor->estoque   = $setoresData['estoque'] ?? false;
        $setor->tipo      = $setoresData['tipo'] ?? 'Material';

        try {
            DB::beginTransaction();

            $setor->save();

            // Se enviar dados de distribuidor junto com a criação do setor
            // Payload opcional: $data['distribuidor'] => ['setor_distribuidor_id' => <id>]
            if (isset($data['distribuidor']) && is_array($data['distribuidor'])) {
                $distribuidorData = $data['distribuidor'];

                $validatorDistribuidor = Validator::make($distribuidorData, [
                    'setor_distribuidor_id' => 'required|exists:setores,id',
                ]);

                if ($validatorDistribuidor->fails()) {
                    DB::rollBack();
                    return response()->json([
                        'status'   => false,
                        'validacao' => true,
                        'erros'    => $validatorDistribuidor->errors()
                    ], 422);
                }

                $distribuidorSetorId = $distribuidorData['setor_distribuidor_id'];
                $existe = DB::table('setor_distribuidor')
                    ->where('setor_solicitante_id', $setor->id)
                    ->where('setor_distribuidor_id', $distribuidorSetorId)
                    ->exists();

                if (!$existe) {
                    DB::table('setor_distribuidor')->insert([
                        'setor_solicitante_id'  => $setor->id,
                        'setor_distribuidor_id' => $distribuidorSetorId,
                        'created_at'            => now(),
                        'updated_at'            => now(),
                    ]);
                }
            }

            DB::commit();

            return ['status' => true, 'data' => $setor];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro ao criar setor com distribuidor: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Erro ao criar setor'
            ], 500);
        }
    }

    public function listAll(Request $request)
    {
        $data    = $request->all();
        $filters = $data['filters'] ?? [];

        // Eager load distribuidores relacionados
        $query = Setores::with(['polo', 'distribuidoresRelacionados.distribuidor']);

        foreach ($filters as $condition) {
            foreach ($condition as $coluna => $valor) {
                $query->where($coluna, $valor);
            }
        }

        if (!isset($data['paginate'])) {
            $setores = $query
                ->select('id', 'polo_id', 'nome', 'descricao', 'status', 'estoque', 'tipo')
                ->orderBy('nome')
                ->get();
        } else {
            $perPage = $data['per_page'] ?? 50;
            $setores = $query
                ->select('id', 'polo_id', 'nome', 'descricao', 'status', 'estoque', 'tipo')
                ->orderBy('nome')
                ->paginate($perPage);
        }

        return ['status' => true, 'data' => $setores];
    }

    public function update(Request $request)
    {
        $data = $request->all();

        // Aceitar tanto 'Setores' quanto 'setores' para compatibilidade
        $setoresData = $data['Setores'] ?? $data['setores'] ?? null;

        if (!$setoresData) {
            return response()->json([
                'status' => false,
                'message' => 'Dados do setor não informados.'
            ], 422);
        }

        $validator = Validator::make($setoresData, [
            'polo_id'       => 'required|exists:polos,id',
            'nome'          => 'required|string|max:255',
            'estoque'       => 'sometimes|boolean',
            'tipo'          => 'sometimes|in:Medicamento,Material',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'validacao' => true,
                'erros' => $validator->errors()
            ], 422);
        }

        $setor = Setores::find($setoresData['id']);

        if (!$setor) {
            return response()->json([
                'status'  => false,
                'message' => 'Setor não encontrado.'
            ], 404);
        }

        $setor->polo_id   = $setoresData['polo_id'];
        $setor->nome      = mb_strtoupper($setoresData['nome']);
        $setor->descricao = $setoresData['descricao'] ?? '';
        $setor->status    = $setoresData['status'] ?? 'A';
        $setor->estoque   = $setoresData['estoque'] ?? $setor->estoque;
        $setor->tipo      = $setoresData['tipo'] ?? $setor->tipo;

        try {
            DB::beginTransaction();

            $setor->save();

            // Se foram enviados distribuidores para atualizar/definir
            // Payload: $data['distribuidores'] = [ ['setor_distribuidor_id' => <id>], ... ]
            if (isset($data['distribuidores']) && is_array($data['distribuidores'])) {
                Log::info('Atualizando distribuidores do setor ' . $setor->id, [
                    'distribuidores_recebidos' => $data['distribuidores']
                ]);

                $distribuidoresRecebidos = $data['distribuidores'];

                // Buscar relacionamentos atuais
                $relacoes = DB::table('setor_distribuidor')->where('setor_solicitante_id', $setor->id)->get();

                // IDs dos distribuidores enviados
                $idsDistribuidores = array_filter(array_map(function ($d) {
                    return $d['setor_distribuidor_id'] ?? null;
                }, $distribuidoresRecebidos));

                Log::info('Distribuidores atuais vs novos', [
                    'atuais' => $relacoes->pluck('setor_distribuidor_id')->toArray(),
                    'novos'  => $idsDistribuidores
                ]);

                // Deletar relações que não vieram no payload
                foreach ($relacoes as $relacaoAtual) {
                    if (!in_array($relacaoAtual->setor_distribuidor_id, $idsDistribuidores)) {
                        Log::info('Removendo distribuidor', [
                            'relacionamento_id'     => $relacaoAtual->id,
                            'setor_distribuidor_id' => $relacaoAtual->setor_distribuidor_id
                        ]);
                        DB::table('setor_distribuidor')->where('id', $relacaoAtual->id)->delete();
                    }
                }

                // Criar apenas os novos relacionamentos
                foreach ($distribuidoresRecebidos as $item) {
                    $validador = Validator::make($item, [
                        'setor_distribuidor_id' => 'required|exists:setores,id',
                    ]);

                    if ($validador->fails()) {
                        DB::rollBack();
                        return response()->json([
                            'status'   => false,
                            'validacao' => true,
                            'erros'    => $validador->errors()
                        ], 422);
                    }

                    $existe = DB::table('setor_distribuidor')
                        ->where('setor_solicitante_id', $setor->id)
                        ->where('setor_distribuidor_id', $item['setor_distribuidor_id'])
                        ->exists();

                    if (!$existe) {
                        DB::table('setor_distribuidor')->insert([
                            'setor_solicitante_id'  => $setor->id,
                            'setor_distribuidor_id' => $item['setor_distribuidor_id'],
                            'created_at'            => now(),
                            'updated_at'            => now(),
                        ]);
                    }
                }
            }

            DB::commit();

            return ['status' => true, 'data' => Setores::with(['polo', 'distribuidoresRelacionados.distribuidor'])->find($setor->id)];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro ao atualizar setor: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Erro ao atualizar setor'
            ], 500);
        }
    }

    public function listConsumers(Request $request)
    {
        try {
            /** @var array $data */
            $data = $request->all();

            if (!isset($data['id'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'ID do setor não fornecido'
                ], 400);
            }

            /** @var User|null $user */
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            // Apenas admin ou almoxarife podem acessar
            $hasPermission = DB::table('usuario_setor')
                ->where('usuario_id', $user->id)
                ->where('setor_id', $data['id'])
                ->whereIn('perfil', ['admin', 'almoxarife'])
                ->exists();

            if (!$hasPermission && !$user->isSuperAdmin()) {
                // Ao invés de disparar um erro 403 (que cria um popup de erro na UI),
                // retornamos uma lista vazia, pois para o usuário sem permissão,
                // ele simplesmente não tem setores consumidores para gerenciar.
                return response()->json([
                    'status' => true,
                    'data' => []
                ]);
            }

            // Verificar se setor existe
            $setor = Setores::find($data['id']);
            if (!$setor) {
                return response()->json([
                    'status' => false,
                    'message' => 'Setor não encontrado'
                ], 404);
            }

            // Obter todos os consumidores (recursivo)
            $consumers = $this->getConsumersRecursive($data['id']);

            return response()->json([
                'status' => true,
                'data' => $consumers
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao listar consumidores do setor: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Erro ao listar consumidores do setor'
            ], 500);
        }
    }

    private function getConsumersRecursive($setorDistribuidorId, &$visited = [])
    {
        if (in_array($setorDistribuidorId, $visited)) {
            return [];
        }

        $visited[] = $setorDistribuidorId;

        // Buscar setores que têm este setor como distribuidor
        $directConsumers = DB::table('setor_distribuidor')
            ->where('setor_distribuidor_id', $setorDistribuidorId)
            ->pluck('setor_solicitante_id')
            ->toArray();

        $consumers = [];

        // Para cada consumidor direto, buscar seus dados e seus consumidores
        foreach ($directConsumers as $consumerId) {
            $setor = Setores::with(['polo'])->find($consumerId);

            if ($setor) {
                $consumers[] = [
                    'id' => $setor->id,
                    'polo_id' => $setor->polo_id,
                    'nome' => $setor->nome,
                    'descricao' => $setor->descricao,
                    'status' => $setor->status,
                    'estoque' => $setor->estoque,
                    'tipo' => $setor->tipo,
                    'polo' => $setor->polo,
                    'consumers' => $this->getConsumersRecursive($consumerId, $visited)
                ];
            }
        }

        return $consumers;
    }

    public function getDetail(Request $request)
    {
        try {
            /** @var array $data */
            $data = $request->all();

            if (!isset($data['id'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'ID do setor não fornecido'
                ], 400);
            }

            /** @var User|null $user */
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            // Se não for super admin, verificar acesso na tabela usuario_setor
            if (!$user->isSuperAdmin()) {
                $hasAccess = DB::table('usuario_setor')
                    ->where('usuario_id', $user->id)
                    ->where('setor_id', $data['id'])
                    ->exists();

                if (!$hasAccess) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Usuário não tem acesso a este setor'
                    ], 403);
                }
            }

            $setor = Setores::with(['polo', 'distribuidoresRelacionados.distribuidor'])->find($data['id']);

            if (!$setor) {
                return response()->json([
                    'status' => false,
                    'message' => 'Setor não encontrado'
                ], 404);
            }

            // Transformar para garantir o formato esperado pelo frontend
            $result = $setor->toArray();

            // Garantir chave consistente para o front
            $result['distribuidores_relacionados'] = $this->formatarDistribuidores($setor->distribuidoresRelacionados);

            return response()->json([
                'status' => true,
                'data'   => $result
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao obter detalhes do setor: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Erro ao obter detalhes do setor'
            ], 500);
        }
    }

    public function listWithAccess(Request $request)
    {
        try {
            /** @var User|null $user */
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            // Super admin tem acesso a todos os setores
            if ($user->isSuperAdmin()) {
                $setores = Setores::with(['polo'])
                    ->select('id', 'polo_id', 'nome', 'descricao', 'status', 'estoque', 'tipo')
                    ->where('status', 'A')
                    ->orderBy('nome')
                    ->get();

                return response()->json([
                    'status' => true,
                    'data' => $setores
                ]);
            }

            // Usuário comum: busca setores via tabela usuario_setor
            $setores = Setores::with(['polo'])
                ->select('setores.id', 'setores.polo_id', 'setores.nome', 'setores.descricao', 'setores.status', 'setores.estoque', 'setores.tipo')
                ->join('usuario_setor', 'setores.id', '=', 'usuario_setor.setor_id')
                ->where('usuario_setor.usuario_id', $user->id)
                ->where('setores.status', 'A')
                ->orderBy('setores.nome')
                ->get();

            return response()->json([
                'status' => true,
                'data' => $setores
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao listar setores com acesso: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Erro ao listar setores'
            ], 500);
        }
    }

    public function listData(Request $request)
    {
        $data = $request->all();
        $id   = $data['id'];

        $setor = Setores::with(['polo', 'distribuidoresRelacionados.distribuidor'])->find($id);

        if (!$setor) {
            return response()->json([
                'status'  => false,
                'message' => 'Setor não encontrado.'
            ], 404);
        }

        $result = $setor->toArray();
        $result['distribuidores_relacionados'] = $this->formatarDistribuidores($setor->distribuidoresRelacionados);

        return ['status' => true, 'data' => $result];
    }

    public function delete($id)
    {
        $setor = Setores::find($id);

        if (!$setor) {
            return response()->json([
                'status'  => false,
                'message' => 'Setor não encontrado.'
            ], 404);
        }

        // Verificar referências antes de deletar
        $referencias = $this->checkSetoresReferences($id);
        if (!empty($referencias)) {
            return response()->json([
                'status'     => false,
                'message'    => 'Não é possível excluir este setor pois ele possui registros relacionados no sistema.',
                'references' => $referencias
            ], 422);
        }

        $setor->delete();

        return response()->json([
            'status'  => true,
            'message' => 'Setor excluído com sucesso.'
        ], 200);
    }

    public function toggleStatus(Request $request)
    {
        try {
            $data = $request->all();

            if (!isset($data['id'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'ID do setor não fornecido'
                ], 400);
            }

            $setor = Setores::find($data['id']);

            if (!$setor) {
                return response()->json([
                    'status' => false,
                    'message' => 'Setor não encontrado'
                ], 404);
            }

            // Toggle: A -> I ou I -> A
            $setor->status = $setor->status === 'A' ? 'I' : 'A';
            $setor->save();

            return response()->json([
                'status' => true,
                'data' => $setor,
                'message' => 'Status atualizado com sucesso'
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao alterar status do setor: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Erro ao alterar status do setor'
            ], 500);
        }
    }

    private function checkSetoresReferences($id)
    {
        $referencias = [];

        $estoqueCount = DB::table('estoque')->where('polo_id', $id)->count();
        if ($estoqueCount > 0) {
            $referencias[] = 'estoque (' . $estoqueCount . ' itens)';
        }

        $movOrigemCount = DB::table('movimentacao')->where('polo_origem_id', $id)->count();
        if ($movOrigemCount > 0) {
            $referencias[] = 'movimentações de origem (' . $movOrigemCount . ')';
        }

        $movDestinoCount = DB::table('movimentacao')->where('polo_destino_id', $id)->count();
        if ($movDestinoCount > 0) {
            $referencias[] = 'movimentações de destino (' . $movDestinoCount . ')';
        }

        return $referencias;
    }

    /**
     * Formata a coleção de distribuidores relacionados para o formato esperado pelo frontend.
     * Reutilizado em listData() e getDetail().
     */
    private function formatarDistribuidores($distribuidoresRelacionados): array
    {
        $distribuidores = [];
        foreach ($distribuidoresRelacionados as $rel) {
            $distribuidorObj = null;
            if ($rel->distribuidor) {
                $distribuidorObj = [
                    'id'        => $rel->distribuidor->id,
                    'nome'      => $rel->distribuidor->nome ?? null,
                    'descricao' => $rel->distribuidor->descricao ?? null,
                    'tipo'      => $rel->distribuidor->tipo ?? null,
                    'estoque'   => isset($rel->distribuidor->estoque) ? (bool) $rel->distribuidor->estoque : null,
                ];
            }
            $distribuidores[] = [
                'id'                    => $rel->id,
                'setor_distribuidor_id' => $rel->setor_distribuidor_id,
                'created_at'            => $rel->created_at ? $rel->created_at->toDateTimeString() : null,
                'updated_at'            => $rel->updated_at ? $rel->updated_at->toDateTimeString() : null,
                'distribuidor'          => $distribuidorObj,
            ];
        }
        return $distribuidores;
    }
    public function addDistribuidor(Request $request)
    {
        try {
            $data = $request->all();

            $validator = Validator::make($data, [
                'setor_solicitante_id' => 'required|exists:setores,id',
                'setor_distribuidor_id' => 'required|exists:setores,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'validacao' => true,
                    'erros' => $validator->errors()
                ], 422);
            }

            /** @var User|null $user */
            $user = Auth::user();
            if (!$user) {
                return response()->json(['status' => false, 'message' => 'Usuário não autenticado'], 401);
            }

            if (!$user->isSuperAdmin()) {
                $isAdmin = DB::table('usuario_setor')
                    ->where('usuario_id', $user->id)
                    ->where('setor_id', $data['setor_solicitante_id'])
                    ->where('perfil', 'admin')
                    ->exists();

                if (!$isAdmin) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Apenas administradores podem definir setores distribuidores.'
                    ], 403);
                }
            }

            // Verificar se o setor distribuidor tem controle de estoque
            $setorDistribuidor = DB::table('setores')->where('id', $data['setor_distribuidor_id'])->first();
            if (!$setorDistribuidor || !$setorDistribuidor->estoque) {
                return response()->json([
                    'status' => false,
                    'message' => 'O setor selecionado não possui controle de estoque e não pode ser um distribuidor.'
                ], 422);
            }

            // Verificar se já existe
            $exists = DB::table('setor_distribuidor')
                ->where('setor_solicitante_id', $data['setor_solicitante_id'])
                ->where('setor_distribuidor_id', $data['setor_distribuidor_id'])
                ->exists();

            if ($exists) {
                return response()->json([
                    'status' => false,
                    'message' => 'Este distribuidor já está vinculado a este setor.'
                ], 422);
            }

            DB::table('setor_distribuidor')->insert([
                'setor_solicitante_id' => $data['setor_solicitante_id'],
                'setor_distribuidor_id' => $data['setor_distribuidor_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Distribuidor adicionado com sucesso.'
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao adicionar distribuidor: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Erro ao adicionar distribuidor.'
            ], 500);
        }
    }

    public function removeDistribuidor(Request $request)
    {
        try {
            $data = $request->all();

            $setorSolicitanteId = null;
            if (isset($data['id'])) {
                $relacao = DB::table('setor_distribuidor')->where('id', $data['id'])->first();
                if ($relacao) {
                    $setorSolicitanteId = $relacao->setor_solicitante_id;
                }
            } elseif (isset($data['setor_solicitante_id'])) {
                $setorSolicitanteId = $data['setor_solicitante_id'];
            }

            if (!$setorSolicitanteId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Dados insuficientes ou relação não encontrada para remoção.'
                ], 400);
            }

            /** @var User|null $user */
            $user = Auth::user();
            if (!$user) {
                return response()->json(['status' => false, 'message' => 'Usuário não autenticado'], 401);
            }

            if (!$user->isSuperAdmin()) {
                $isAdmin = DB::table('usuario_setor')
                    ->where('usuario_id', $user->id)
                    ->where('setor_id', $setorSolicitanteId)
                    ->where('perfil', 'admin')
                    ->exists();

                if (!$isAdmin) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Apenas administradores podem remover setores distribuidores.'
                    ], 403);
                }
            }

            // Aceita ID do relacionamento OU par de IDs
            if (isset($data['id'])) {
                DB::table('setor_distribuidor')->where('id', $data['id'])->delete();
            } elseif (isset($data['setor_solicitante_id']) && isset($data['setor_distribuidor_id'])) {
                DB::table('setor_distribuidor')
                    ->where('setor_solicitante_id', $data['setor_solicitante_id'])
                    ->where('setor_distribuidor_id', $data['setor_distribuidor_id'])
                    ->delete();
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'Dados insuficientes para remoção.'
                ], 400);
            }

            return response()->json([
                'status' => true,
                'message' => 'Distribuidor removido com sucesso.'
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao remover distribuidor: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Erro ao remover distribuidor.'
            ], 500);
        }
    }

    /**
     * Lista os setores distribuidores disponíveis para um setor solicitante.
     * Usado no formulário de movimentações para popular o dropdown de origem.
     */
    public function listDistribuidoresParaSetor(Request $request)
    {
        try {
            $data = $request->all();

            if (!isset($data['setor_id'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'ID do setor não fornecido'
                ], 400);
            }

            $setorId = $data['setor_id'];

            // Verificar se o setor existe
            $setor = Setores::find($setorId);
            if (!$setor) {
                return response()->json([
                    'status' => false,
                    'message' => 'Setor não encontrado'
                ], 404);
            }

            // Buscar distribuidores relacionados a este setor (como solicitante)
            $distribuidores = DB::table('setor_distribuidor')
                ->join('setores', 'setores.id', '=', 'setor_distribuidor.setor_distribuidor_id')
                ->where('setor_distribuidor.setor_solicitante_id', $setorId)
                ->where('setores.status', 'A')
                ->select(
                    'setores.id',
                    'setores.nome',
                    'setores.tipo',
                    'setores.estoque',
                    'setor_distribuidor.id as relacao_id'
                )
                ->orderBy('setores.nome')
                ->get();

            return response()->json([
                'status' => true,
                'data' => $distribuidores
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao listar distribuidores para setor: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Erro ao listar distribuidores'
            ], 500);
        }
    }
}
