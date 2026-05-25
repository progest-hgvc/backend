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
            'unidade_id'       => 'required|exists:unidades,id',
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
        $Setores->unidade_id        = $setoresData['unidade_id'];
        $Setores->nome           = mb_strtoupper($setoresData['nome']);
        $Setores->descricao      = $setoresData['descricao'] ?? '';
        $Setores->status         = $setoresData['status'] ?? 'A';
        $Setores->estoque        = $setoresData['estoque'] ?? false;
        $Setores->tipo           = $setoresData['tipo'] ?? 'Material';

        try {
            DB::beginTransaction();

            $Setores->save();

            // Se enviar dados de fornecedor junto com a criação do setor
            // Esperamos um payload opcional: $data['fornecedor'] => ['setor_fornecedor_id' => <id do setor fornecedor>]
            if (isset($data['fornecedor']) && is_array($data['fornecedor'])) {
                $fornecedorData = $data['fornecedor'];

                $validatorFornecedor = Validator::make($fornecedorData, [
                    'setor_fornecedor_id' => 'required|exists:setores,id',
                ]);

                if ($validatorFornecedor->fails()) {
                    DB::rollBack();
                    return response()->json([
                        'status' => false,
                        'validacao' => true,
                        'erros' => $validatorFornecedor->errors()
                    ], 422);
                }

                $fornecedorSetorId = $fornecedorData['setor_fornecedor_id'];
                $exists = DB::table('setor_fornecedor')
                    ->where('setor_solicitante_id', $Setores->id)
                    ->where('setor_fornecedor_id', $fornecedorSetorId)
                    ->exists();

                if (!$exists) {
                    DB::table('setor_fornecedor')->insert([
                        'setor_solicitante_id' => $Setores->id,
                        'setor_fornecedor_id' => $fornecedorSetorId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::commit();

            return ['status' => true, 'data' => $Setores];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro ao criar setor com fornecedor: ' . $e->getMessage());
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

        // Eager load fornecedores relacionados
        $SetoresQuery = Setores::with(['unidade', 'fornecedoresRelacionados.fornecedor']);

        foreach ($filters as $condition) {
            foreach ($condition as $column => $value) {
                $SetoresQuery->where($column, $value);
            }
        }

        if (!isset($data['paginate'])) {
            $Setores = $SetoresQuery
                ->select('id', 'unidade_id', 'nome', 'descricao', 'status', 'estoque', 'tipo')
                ->orderBy('nome')
                ->get();
        } else {
            $per_page = $data['per_page'] ?? 50;
            $Setores = $SetoresQuery
                ->select('id', 'unidade_id', 'nome', 'descricao', 'status', 'estoque', 'tipo')
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
            'unidade_id'       => 'required|exists:unidades,id',
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

        $Setores->unidade_id        = $setoresData['unidade_id'];
        $Setores->nome           = mb_strtoupper($setoresData['nome']);
        $Setores->descricao      = $setoresData['descricao'] ?? '';
        $Setores->status         = $setoresData['status'] ?? 'A';
        $Setores->estoque        = $setoresData['estoque'] ?? $Setores->estoque;
        $Setores->tipo           = $setoresData['tipo'] ?? $Setores->tipo;

        try {
            DB::beginTransaction();

            $Setores->save();

            // Se foram enviados fornecedores para atualizar/definir
            // Esperamos: $data['fornecedores'] = [ ['setor_fornecedor_id' => <setor id fornecedor>], ... ]
            if (isset($data['fornecedores']) && is_array($data['fornecedores'])) {
                Log::info('Atualizando fornecedores do setor ' . $Setores->id, [
                    'fornecedores_recebidos' => $data['fornecedores']
                ]);

                $incoming = $data['fornecedores'];

                // Buscar relacionamentos atuais
                $current = DB::table('setor_fornecedor')->where('setor_solicitante_id', $Setores->id)->get();

                // Mapear fornecedores enviados
                $incomingFornecedorIds = array_map(function ($f) {
                    return $f['setor_fornecedor_id'] ?? null;
                }, $incoming);
                $incomingFornecedorIds = array_filter($incomingFornecedorIds);

                Log::info('Fornecedores atuais vs novos', [
                    'atuais' => $current->pluck('setor_fornecedor_id')->toArray(),
                    'novos' => $incomingFornecedorIds
                ]);

                // Deletar relações que não foram enviadas (removidas pelo cliente)
                foreach ($current as $cur) {
                    if (!in_array($cur->setor_fornecedor_id, $incomingFornecedorIds)) {
                        Log::info('Removendo fornecedor', [
                            'relacionamento_id' => $cur->id,
                            'setor_fornecedor_id' => $cur->setor_fornecedor_id
                        ]);
                        DB::table('setor_fornecedor')->where('id', $cur->id)->delete();
                    }
                }

                // Processar incoming: criar apenas os novos (duplicatas serão ignoradas pela constraint unique)
                foreach ($incoming as $f) {
                    /** @var array $f */
                    $validatorF = Validator::make($f, [
                        'setor_fornecedor_id' => 'required|exists:setores,id',
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
                    $exists = DB::table('setor_fornecedor')
                        ->where('setor_solicitante_id', $Setores->id)
                        ->where('setor_fornecedor_id', $f['setor_fornecedor_id'])
                        ->exists();

                    if (!$exists) {
                        // Criar novo relacionamento
                        DB::table('setor_fornecedor')->insert([
                            'setor_solicitante_id' => $Setores->id,
                            'setor_fornecedor_id' => $f['setor_fornecedor_id'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }

            DB::commit();

            return ['status' => true, 'data' => Setores::with(['unidade', 'fornecedoresRelacionados.fornecedor'])->find($Setores->id)];
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

    private function getConsumersRecursive($setorFornecedorId, &$visited = [])
    {
        if (in_array($setorFornecedorId, $visited)) {
            return [];
        }

        $visited[] = $setorFornecedorId;

        // Buscar setores que têm este setor como fornecedor
        $directConsumers = DB::table('setor_fornecedor')
            ->where('setor_fornecedor_id', $setorFornecedorId)
            ->pluck('setor_solicitante_id')
            ->toArray();

        $consumers = [];

        // Para cada consumidor direto, buscar seus dados e seus consumidores
        foreach ($directConsumers as $consumerId) {
            $setor = Setores::with(['unidade'])->find($consumerId);

            if ($setor) {
                $consumers[] = [
                    'id' => $setor->id,
                    'unidade_id' => $setor->unidade_id,
                    'nome' => $setor->nome,
                    'descricao' => $setor->descricao,
                    'status' => $setor->status,
                    'estoque' => $setor->estoque,
                    'tipo' => $setor->tipo,
                    'unidade' => $setor->unidade,
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

            $setor = Setores::with(['unidade', 'fornecedoresRelacionados.fornecedor'])->find($data['id']);

            if (!$setor) {
                return response()->json([
                    'status' => false,
                    'message' => 'Setor não encontrado'
                ], 404);
            }

            // Transformar para garantir o formato esperado pelo frontend
            $result = $setor->toArray();

            $fornecedores = [];
            foreach ($setor->fornecedoresRelacionados as $rel) {
                $fornecedorObj = null;
                if ($rel->fornecedor) {
                    $fornecedorObj = [
                        'id' => $rel->fornecedor->id,
                        'nome' => $rel->fornecedor->nome ?? null,
                        'descricao' => $rel->fornecedor->descricao ?? null,
                        'tipo' => $rel->fornecedor->tipo ?? null,
                        'estoque' => isset($rel->fornecedor->estoque) ? (bool) $rel->fornecedor->estoque : null,
                    ];
                }

                $fornecedores[] = [
                    'id' => $rel->id,
                    'setor_fornecedor_id' => $rel->setor_fornecedor_id,
                    'created_at' => $rel->created_at ? $rel->created_at->toDateTimeString() : null,
                    'updated_at' => $rel->updated_at ? $rel->updated_at->toDateTimeString() : null,
                    'fornecedor' => $fornecedorObj,
                ];
            }

            // Garantir chave consistente para o front
            $result['fornecedores_relacionados'] = $fornecedores;

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
                $setores = Setores::with(['unidade'])
                    ->select('id', 'unidade_id', 'nome', 'descricao', 'status', 'estoque', 'tipo')
                    ->where('status', 'A')
                    ->orderBy('nome')
                    ->get();

                return response()->json([
                    'status' => true,
                    'data' => $setores
                ]);
            }

            // Usuário comum: busca setores via tabela usuario_setor
            $setores = Setores::with(['unidade'])
                ->select('setores.id', 'setores.unidade_id', 'setores.nome', 'setores.descricao', 'setores.status', 'setores.estoque', 'setores.tipo')
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

        // Carregar setor com fornecedores relacionados e dados do fornecedor
        $Setores = Setores::with(['unidade', 'fornecedoresRelacionados.fornecedor'])->find($dataID);

        if (!$Setores) {
            return response()->json([
                'status' => false,
                'message' => 'Setor não encontrado.'
            ], 404);
        }

        // Transformar para garantir o formato esperado pelo frontend
        $result = $Setores->toArray();

        $fornecedores = [];
        foreach ($Setores->fornecedoresRelacionados as $rel) {
            $fornecedorObj = null;
            if ($rel->fornecedor) {
                $fornecedorObj = [
                    'id' => $rel->fornecedor->id,
                    'nome' => $rel->fornecedor->nome ?? null,
                    'descricao' => $rel->fornecedor->descricao ?? null,
                    'tipo' => $rel->fornecedor->tipo ?? null,
                    'estoque' => isset($rel->fornecedor->estoque) ? (bool) $rel->fornecedor->estoque : null,
                ];
            }

            $fornecedores[] = [
                'id' => $rel->id,
                'setor_fornecedor_id' => $rel->setor_fornecedor_id,
                'created_at' => $rel->created_at ? $rel->created_at->toDateTimeString() : null,
                'updated_at' => $rel->updated_at ? $rel->updated_at->toDateTimeString() : null,
                'fornecedor' => $fornecedorObj,
            ];
        }

        // Garantir chave consistente para o front
        $result['fornecedores_relacionados'] = $fornecedores;

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
        $estoqueCount = DB::table('estoque')->where('unidade_id', $id)->count();
        if ($estoqueCount > 0) {
            $references[] = 'estoque (' . $estoqueCount . ' itens)';
        }

        // Verificar movimentações como origem
        $movOrigemCount = DB::table('movimentacao')->where('unidade_origem_id', $id)->count();
        if ($movOrigemCount > 0) {
            $references[] = 'movimentações de origem (' . $movOrigemCount . ')';
        }

        // Verificar movimentações como destino
        $movDestinoCount = DB::table('movimentacao')->where('unidade_destino_id', $id)->count();
        if ($movDestinoCount > 0) {
            $references[] = 'movimentações de destino (' . $movDestinoCount . ')';
        }

        return $references;
    }
    public function addFornecedor(Request $request)
    {
        try {
            $data = $request->all();

            $validator = Validator::make($data, [
                'setor_solicitante_id' => 'required|exists:setores,id',
                'setor_fornecedor_id' => 'required|exists:setores,id',
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

            // Verificar se o setor fornecedor tem controle de estoque
            $setorFornecedor = DB::table('setores')->where('id', $data['setor_fornecedor_id'])->first();
            if (!$setorFornecedor || !$setorFornecedor->estoque) {
                return response()->json([
                    'status' => false,
                    'message' => 'O setor selecionado não possui controle de estoque e não pode ser um fornecedor.'
                ], 422);
            }

            // Verificar se já existe
            $exists = DB::table('setor_fornecedor')
                ->where('setor_solicitante_id', $data['setor_solicitante_id'])
                ->where('setor_fornecedor_id', $data['setor_fornecedor_id'])
                ->exists();

            if ($exists) {
                return response()->json([
                    'status' => false,
                    'message' => 'Este fornecedor já está vinculado a este setor.'
                ], 422);
            }

            DB::table('setor_fornecedor')->insert([
                'setor_solicitante_id' => $data['setor_solicitante_id'],
                'setor_fornecedor_id' => $data['setor_fornecedor_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Fornecedor adicionado com sucesso.'
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao adicionar fornecedor: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Erro ao adicionar fornecedor.'
            ], 500);
        }
    }

    public function removeFornecedor(Request $request)
    {
        try {
            $data = $request->all();

            $setorSolicitanteId = null;
            if (isset($data['id'])) {
                $relacao = DB::table('setor_fornecedor')->where('id', $data['id'])->first();
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
                DB::table('setor_fornecedor')->where('id', $data['id'])->delete();
            } elseif (isset($data['setor_solicitante_id']) && isset($data['setor_fornecedor_id'])) {
                DB::table('setor_fornecedor')
                    ->where('setor_solicitante_id', $data['setor_solicitante_id'])
                    ->where('setor_fornecedor_id', $data['setor_fornecedor_id'])
                    ->delete();
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'Dados insuficientes para remoção.'
                ], 400);
            }

            return response()->json([
                'status' => true,
                'message' => 'Fornecedor removido com sucesso.'
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao remover fornecedor: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Erro ao remover fornecedor.'
            ], 500);
        }
    }

    /**
     * Lista os setores fornecedores disponíveis para um setor solicitante.
     * Usado no formulário de movimentações para popular o dropdown de origem.
     */
    public function listFornecedoresParaSetor(Request $request)
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

            // Buscar fornecedores relacionados a este setor (como solicitante)
            $fornecedores = DB::table('setor_fornecedor')
                ->join('setores', 'setores.id', '=', 'setor_fornecedor.setor_fornecedor_id')
                ->where('setor_fornecedor.setor_solicitante_id', $setorId)
                ->where('setores.status', 'A')
                ->select(
                    'setores.id',
                    'setores.nome',
                    'setores.tipo',
                    'setores.estoque',
                    'setor_fornecedor.id as relacao_id'
                )
                ->orderBy('setores.nome')
                ->get();

            return response()->json([
                'status' => true,
                'data' => $fornecedores
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao listar fornecedores para setor: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Erro ao listar fornecedores'
            ], 500);
        }
    }
}
