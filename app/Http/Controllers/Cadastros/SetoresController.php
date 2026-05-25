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
use Illuminate\Support\Facades\Http;

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

        $Setores = new Setores;
        $Setores->polo_id        = $setoresData['polo_id'];
        $Setores->nome           = mb_strtoupper($setoresData['nome']);
        $Setores->descricao      = $setoresData['descricao'] ?? '';
        $Setores->status         = $setoresData['status'] ?? 'A';
        $Setores->estoque        = $setoresData['estoque'] ?? false;
        $Setores->tipo           = $setoresData['tipo'] ?? 'Material';

        try {
            DB::beginTransaction();

            $Setores->save();

            // Se enviar dados de distribuidor junto com a criação do setor
            // Esperamos um payload opcional: $data['distribuidor'] => ['setor_distribuidor_id' => <id do setor distribuidor>]
            if (isset($data['distribuidor']) && is_array($data['distribuidor'])) {
                $distribuidorData = $data['distribuidor'];

                $validatorDistribuidor = Validator::make($distribuidorData, [
                    'setor_distribuidor_id' => 'required|exists:setores,id',
                ]);

                if ($validatorDistribuidor->fails()) {
                    DB::rollBack();
                    return response()->json([
                        'status' => false,
                        'validacao' => true,
                        'erros' => $validatorDistribuidor->errors()
                    ], 422);
                }

                $distribuidorSetorId = $distribuidorData['setor_distribuidor_id'];
                $exists = DB::table('setor_distribuidor')
                    ->where('setor_solicitante_id', $Setores->id)
                    ->where('setor_distribuidor_id', $distribuidorSetorId)
                    ->exists();

                if (!$exists) {
                    DB::table('setor_distribuidor')->insert([
                        'setor_solicitante_id' => $Setores->id,
                        'setor_distribuidor_id' => $distribuidorSetorId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::commit();

            return ['status' => true, 'data' => $Setores];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro ao criar setor com distribuidor: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Erro ao criar setor'
            ], 500);
        }
    }

    public function listAll(Request $request)
    {
        $data = $request->all();
        $filters = $data['filters'] ?? [];

        // Eager load distribuidores relacionados
        $SetoresQuery = Setores::with(['polo', 'distribuidoresRelacionados.distribuidor']);

        foreach ($filters as $condition) {
            foreach ($condition as $column => $value) {
                $SetoresQuery->where($column, $value);
            }
        }

        if (!isset($data['paginate'])) {
            $Setores = $SetoresQuery
                ->select('id', 'polo_id', 'nome', 'descricao', 'status', 'estoque', 'tipo')
                ->orderBy('nome')
                ->get();
        } else {
            $per_page = $data['per_page'] ?? 50;
            $Setores = $SetoresQuery
                ->select('id', 'polo_id', 'nome', 'descricao', 'status', 'estoque', 'tipo')
                ->orderBy('nome')
                ->paginate($per_page);
        }

        return ['status' => true, 'data' => $Setores];
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

        $Setores = Setores::find($setoresData['id']);

        if (!$Setores) {
            return response()->json([
                'status' => false,
                'message' => 'Setor não encontrado.'
            ], 404);
        }

        $Setores->polo_id        = $setoresData['polo_id'];
        $Setores->nome           = mb_strtoupper($setoresData['nome']);
        $Setores->descricao      = $setoresData['descricao'] ?? '';
        $Setores->status         = $setoresData['status'] ?? 'A';
        $Setores->estoque        = $setoresData['estoque'] ?? $Setores->estoque;
        $Setores->tipo           = $setoresData['tipo'] ?? $Setores->tipo;

        try {
            DB::beginTransaction();

            $Setores->save();

            // Se foram enviados distribuidores para atualizar/definir
            // Esperamos: $data['distribuidores'] = [ ['setor_distribuidor_id' => <setor id distribuidor>], ... ]
            if (isset($data['distribuidores']) && is_array($data['distribuidores'])) {
                Log::info('Atualizando distribuidores do setor ' . $Setores->id, [
                    'distribuidores_recebidos' => $data['distribuidores']
                ]);

                $incoming = $data['distribuidores'];

                // Buscar relacionamentos atuais
                $current = DB::table('setor_distribuidor')->where('setor_solicitante_id', $Setores->id)->get();

                // Mapear distribuidores enviados
                $incomingDistribuidorIds = array_map(function ($f) {
                    return $f['setor_distribuidor_id'] ?? null;
                }, $incoming);
                $incomingDistribuidorIds = array_filter($incomingDistribuidorIds);

                Log::info('Distribuidores atuais vs novos', [
                    'atuais' => $current->pluck('setor_distribuidor_id')->toArray(),
                    'novos' => $incomingDistribuidorIds
                ]);

                // Deletar relações que não foram enviadas (removidas pelo cliente)
                foreach ($current as $cur) {
                    if (!in_array($cur->setor_distribuidor_id, $incomingDistribuidorIds)) {
                        Log::info('Removendo distribuidor', [
                            'relacionamento_id' => $cur->id,
                            'setor_distribuidor_id' => $cur->setor_distribuidor_id
                        ]);
                        DB::table('setor_distribuidor')->where('id', $cur->id)->delete();
                    }
                }

                // Processar incoming: criar apenas os novos (duplicatas serão ignoradas pela constraint unique)
                foreach ($incoming as $f) {
                    /** @var array $f */
                    $validatorF = Validator::make($f, [
                        'setor_distribuidor_id' => 'required|exists:setores,id',
                    ]);

                    if ($validatorF->fails()) {
                        DB::rollBack();
                        return response()->json([
                            'status' => false,
                            'validacao' => true,
                            'erros' => $validatorF->errors()
                        ], 422);
                    }

                    // Verificar se já existe esse relacionamento
                    $exists = DB::table('setor_distribuidor')
                        ->where('setor_solicitante_id', $Setores->id)
                        ->where('setor_distribuidor_id', $f['setor_distribuidor_id'])
                        ->exists();

                    if (!$exists) {
                        // Criar novo relacionamento
                        DB::table('setor_distribuidor')->insert([
                            'setor_solicitante_id' => $Setores->id,
                            'setor_distribuidor_id' => $f['setor_distribuidor_id'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }

            DB::commit();

            return ['status' => true, 'data' => Setores::with(['polo', 'distribuidoresRelacionados.distribuidor'])->find($Setores->id)];
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
                return response()->json([
                    'status' => false,
                    'message' => 'Usuário não tem permissão para acessar esta lista'
                ], 403);
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

            $distribuidores = [];
            foreach ($setor->distribuidoresRelacionados as $rel) {
                $distribuidorObj = null;
                if ($rel->distribuidor) {
                    $distribuidorObj = [
                        'id' => $rel->distribuidor->id,
                        'nome' => $rel->distribuidor->nome ?? null,
                        'descricao' => $rel->distribuidor->descricao ?? null,
                        'tipo' => $rel->distribuidor->tipo ?? null,
                        'estoque' => isset($rel->distribuidor->estoque) ? (bool) $rel->distribuidor->estoque : null,
                    ];
                }

                $distribuidores[] = [
                    'id' => $rel->id,
                    'setor_distribuidor_id' => $rel->setor_distribuidor_id,
                    'created_at' => $rel->created_at ? $rel->created_at->toDateTimeString() : null,
                    'updated_at' => $rel->updated_at ? $rel->updated_at->toDateTimeString() : null,
                    'distribuidor' => $distribuidorObj,
                ];
            }

            // Garantir chave consistente para o front
            $result['distribuidores_relacionados'] = $distribuidores;

            return response()->json([
                'status' => true,
                'data' => $result
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao obter detalhes do setor: ' . $e->getMessage());
            return response()->json([
                'status' => false,
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
        $dataID = $data['id'];

        // Carregar setor com distribuidores relacionados e dados do distribuidor
        $Setores = Setores::with(['polo', 'distribuidoresRelacionados.distribuidor'])->find($dataID);

        if (!$Setores) {
            return response()->json([
                'status' => false,
                'message' => 'Setor não encontrado.'
            ], 404);
        }

        // Transformar para garantir o formato esperado pelo frontend
        $result = $Setores->toArray();

        $distribuidores = [];
        foreach ($Setores->distribuidoresRelacionados as $rel) {
            $distribuidorObj = null;
            if ($rel->distribuidor) {
                $distribuidorObj = [
                    'id' => $rel->distribuidor->id,
                    'nome' => $rel->distribuidor->nome ?? null,
                    'descricao' => $rel->distribuidor->descricao ?? null,
                    'tipo' => $rel->distribuidor->tipo ?? null,
                    'estoque' => isset($rel->distribuidor->estoque) ? (bool) $rel->distribuidor->estoque : null,
                ];
            }

            $distribuidores[] = [
                'id' => $rel->id,
                'setor_distribuidor_id' => $rel->setor_distribuidor_id,
                'created_at' => $rel->created_at ? $rel->created_at->toDateTimeString() : null,
                'updated_at' => $rel->updated_at ? $rel->updated_at->toDateTimeString() : null,
                'distribuidor' => $distribuidorObj,
            ];
        }

        // Garantir chave consistente para o front
        $result['distribuidores_relacionados'] = $distribuidores;

        return ['status' => true, 'data' => $result];
    }

    public function delete($id)
    {
        $Setores = Setores::find($id);

        if (!$Setores) {
            return response()->json([
                'status' => false,
                'message' => 'Setor não encontrado.'
            ], 404);
        }

        // Verificar referências antes de deletar
        $references = $this->checkSetoresReferences($id);
        if (!empty($references)) {
            return response()->json([
                'status' => false,
                'message' => 'Não é possível excluir este setor pois ele possui registros relacionados no sistema.',
                'references' => $references
            ], 422);
        }

        $Setores->delete();

        return response()->json([
            'status' => true,
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
        $references = [];

        // Verificar estoque vinculado ao setor
        $estoqueCount = DB::table('estoque')->where('polo_id', $id)->count();
        if ($estoqueCount > 0) {
            $references[] = 'estoque (' . $estoqueCount . ' itens)';
        }

        // Verificar movimentações como origem
        $movOrigemCount = DB::table('movimentacao')->where('polo_origem_id', $id)->count();
        if ($movOrigemCount > 0) {
            $references[] = 'movimentações de origem (' . $movOrigemCount . ')';
        }

        // Verificar movimentações como destino
        $movDestinoCount = DB::table('movimentacao')->where('polo_destino_id', $id)->count();
        if ($movDestinoCount > 0) {
            $references[] = 'movimentações de destino (' . $movDestinoCount . ')';
        }

        return $references;
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
