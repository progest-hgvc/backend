<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\TipoVinculo;
use App\Models\Setores;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\UserRequest;

class UserController extends Controller
{
    public function add(UserRequest $request)
    {
        $dadosValidados = $request->validated()['user'];
        $dadosBrutos    = $request->all();

        DB::beginTransaction();
        try {
            $user                  = new User;
            $user->status          = $dadosValidados['status'] ?? 'A';
            $user->name            = mb_strtoupper($dadosValidados['name']);
            $user->email           = mb_strtolower($dadosValidados['email']);
            $user->telefone        = isset($dadosValidados['telefone']) ? preg_replace('/\D/', '', $dadosValidados['telefone']) : null;
            $user->data_nascimento = $dadosValidados['data_nascimento'] ?? null;
            $user->cpf             = preg_replace('/\D/', '', $dadosValidados['cpf']);
            $user->tipo_vinculo    = $dadosValidados['tipo_vinculo'] ?? null;
            $user->password        = Hash::make($dadosValidados['password']);

            $user->save();

            $this->sincronizarSetores($user, $dadosBrutos);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro ao salvar usuário e setores: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Erro ao salvar usuário.'], 500);
        }

        $user = User::with(['setores' => function ($q) {
            $q->select('setores.id', 'setores.polo_id', 'setores.nome', 'setores.descricao', 'setores.status', 'setores.estoque', 'setores.tipo');
        }])->find($user->id);

        return response()->json(['status' => true, 'data' => $user]);
    }

    public function update(UserRequest $request)
    {
        $dadosValidados = $request->validated()['user'];
        $dadosBrutos    = $request->all();
        $id             = $dadosValidados['id'] ?? null;

        $user = User::find($id);
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'Usuário não encontrado.'], 404);
        }

        DB::beginTransaction();
        try {
            $user->name            = mb_strtoupper($dadosValidados['name']);
            $user->email           = mb_strtolower($dadosValidados['email']);
            $user->telefone        = isset($dadosValidados['telefone']) ? preg_replace('/\D/', '', $dadosValidados['telefone']) : null;
            $user->data_nascimento = $dadosValidados['data_nascimento'] ?? null;
            $user->cpf             = preg_replace('/\D/', '', $dadosValidados['cpf']);
            $user->status          = $dadosValidados['status'] ?? $user->status;
            $user->tipo_vinculo    = $dadosValidados['tipo_vinculo'] ?? null;

            if (!empty($dadosValidados['password'])) {
                $user->password = Hash::make($dadosValidados['password']);
            }

            $user->save();

            $this->sincronizarSetores($user, $dadosBrutos);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro ao atualizar usuário e setores: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Erro ao atualizar usuário.'], 500);
        }

        $user = User::with(['setores' => function ($q) {
            $q->select('setores.id', 'setores.polo_id', 'setores.nome', 'setores.descricao', 'setores.status', 'setores.estoque', 'setores.tipo');
        }])->find($user->id);

        return response()->json(['status' => true, 'data' => $user]);
    }

    public function listAll(Request $request)
    {
        $query = User::query();

        // Busca textual multi-campo (name, email, cpf, telefone)
        $search = $request->input('search');
        if (!empty($search)) {
            $termoBusca = '%' . $search . '%';
            $query->where(function ($q) use ($termoBusca) {
                $q->where('name', 'LIKE', $termoBusca)
                  ->orWhere('email', 'LIKE', $termoBusca)
                  ->orWhere('cpf', 'LIKE', $termoBusca)
                  ->orWhere('telefone', 'LIKE', $termoBusca);
            });
        }

        // Filtro por tipo_vinculo
        $tipoVinculo = $request->input('tipo_vinculo');
        if (!empty($tipoVinculo)) {
            $query->where('tipo_vinculo', $tipoVinculo);
        }

        // Filtro por status
        $status = $request->input('status');
        if (!empty($status)) {
            $query->where('status', $status);
        }

        // Filtros legados (compatibilidade com chamadas anteriores)
        $filters = $request->input('filters', []);
        foreach ($filters as $condition) {
            if (is_array($condition)) {
                foreach ($condition as $coluna => $valor) {
                    $colunasPermitidas = ['name', 'email', 'cpf', 'telefone', 'status', 'tipo_vinculo'];
                    if (in_array($coluna, $colunasPermitidas)) {
                        $query->where($coluna, 'LIKE', '%' . $valor . '%');
                    }
                }
            }
        }

        // Ordenação dinâmica
        $sortBy  = $request->input('sort_by', 'name');
        $sortDir = $request->input('sort_dir', 'asc');
        $colunasOrdenacao = ['id', 'name', 'email', 'cpf', 'status', 'tipo_vinculo'];
        if (in_array($sortBy, $colunasOrdenacao) && in_array(strtolower($sortDir), ['asc', 'desc'])) {
            $query->orderBy($sortBy, $sortDir);
        } else {
            $query->orderBy('name', 'asc');
        }

        $users = $query->select(
            'id', 'name', 'email', 'cpf', 'telefone', 'data_nascimento', 'status', 'tipo_vinculo'
        )->get();

        return response()->json(['status' => true, 'data' => $users]);
    }

    public function listData(Request $request)
    {
        $user = User::with(['setores' => function ($q) {
            $q->select('setores.id', 'setores.polo_id', 'setores.nome', 'setores.descricao', 'setores.status', 'setores.estoque', 'setores.tipo');
        }])->find($request->id);

        if (!$user) {
            return response()->json(['status' => false, 'message' => 'Usuário não encontrado']);
        }

        $tipoVinculo = $user->tipo_vinculo ? TipoVinculo::find($user->tipo_vinculo) : null;

        return response()->json([
            'status'       => true,
            'data'         => $user,
            'tipo_vinculo' => $tipoVinculo,
            'setores'      => $user->setores,
        ]);
    }

    public function delete($id)
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'Usuário não encontrado.'], 404);
        }
        if ($user->email === 'admin@admin.com') {
            return response()->json(['status' => false, 'message' => 'O usuário Admin não pode ser alterado.'], 403);
        }

        // Toggle: se ativo → inativa, se inativo → ativa
        $user->status = $user->status === 'A' ? 'I' : 'A';
        $user->save();

        $acao = $user->status === 'A' ? 'ativado' : 'desativado';
        return response()->json(['status' => true, 'message' => "Usuário {$acao} com sucesso.", 'data' => $user]);
    }

    public function countUsers()
    {
        $total = User::where('status', 'A')->count();
        return response()->json(['count' => $total]);
    }

    /**
     * Sincroniza os setores e perfis do usuário.
     * Aceita os dados de setores dentro de 'setores' ou 'setores_ids' (snake_case).
     */
    private function sincronizarSetores(User $user, array $dados)
    {
        $setoresRecebidos = $dados['setores_ids'] ?? $dados['setores'] ?? [];
        if (empty($setoresRecebidos) && isset($dados['user']) && is_array($dados['user'])) {
            $setoresRecebidos = $dados['user']['setores_ids'] ?? $dados['user']['setores'] ?? [];
        }

        if (!is_array($setoresRecebidos) || empty($setoresRecebidos)) {
            return;
        }

        $dadosSync = [];
        foreach ($setoresRecebidos as $item) {
            $setorId = null;
            $perfil  = null;

            if (is_array($item)) {
                $setorId = $item['id'] ?? ($item['setor_id'] ?? null);
                $perfil  = $item['perfil'] ?? null;
            } elseif (is_object($item)) {
                $setorId = $item->id ?? ($item->setor_id ?? null);
                $perfil  = $item->perfil ?? null;
            } else {
                $setorId = $item;
            }

            if (!is_numeric($setorId) || $setorId <= 0) {
                continue;
            }

            $perfil            = $perfil ?? 'solicitante';
            $dadosSync[$setorId] = ['perfil' => $perfil];
        }

        if (!empty($dadosSync)) {
            $idsValidos     = Setores::whereIn('id', array_keys($dadosSync))->pluck('id')->toArray();
            $dadosFiltrados = [];
            foreach ($idsValidos as $idValido) {
                $dadosFiltrados[$idValido] = $dadosSync[$idValido];
            }
            $user->setores()->sync($dadosFiltrados);
        }
    }
}