<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Http\Requests\UsuarioSetorRequest;

class UsuarioSetorController extends Controller
{
    /**
     * Vincular usuário a um setor com perfil.
     * Equivalente ao padrão `add` dos demais controllers.
     */
    public function add(UsuarioSetorRequest $request)
    {
        try {
            $dados = $request->validated();

            if (empty($dados['perfil'])) {
                return response()->json(['status' => false, 'message' => 'O perfil é obrigatório para criar um vínculo.'], 400);
            }

            $usuarioId = $dados['usuario_id'];
            $setorId   = $dados['setor_id'];
            $perfil    = $dados['perfil'];

            // Verificar permissão: somente admin do setor pode criar vínculo
            /** @var User $usuario */
            $usuario = Auth::user();
            $isAdmin = $usuario->isSuperAdmin() || $usuario->setores()->where('setores.id', $setorId)->wherePivot('perfil', 'admin')->exists();
            if (!$isAdmin) {
                return response()->json(['status' => false, 'message' => 'Ação permitida apenas para administradores deste setor.'], 403);
            }

            // Checar duplicidade
            $existe = DB::table('usuario_setor')->where('usuario_id', $usuarioId)->where('setor_id', $setorId)->exists();
            if ($existe) {
                return response()->json(['status' => false, 'message' => 'Este usuário já está vinculado a este setor.'], 422);
            }

            // Regra de Negócio: CAF (Setor raiz sem distribuidor) não pode ter 'solicitante'
            // Regra de Negócio: Setor sem estoque não pode ter 'almoxarife'
            $setorObj = DB::table('setores')->where('id', $setorId)->first();
            if ($setorObj) {
                // Se não tem ninguém como fornecedor dele, ele é a raiz (CAF)
                $hasDistribuidor = DB::table('setor_distribuidor')->where('setor_solicitante_id', $setorId)->exists();
                if (!$hasDistribuidor && $perfil === 'solicitante') {
                    return response()->json(['status' => false, 'message' => 'Operação negada: Uma CAF (setor centralizador) não pode ter usuários com perfil de solicitante.'], 422);
                }

                if (!$setorObj->estoque && $perfil === 'almoxarife') {
                    return response()->json(['status' => false, 'message' => 'Operação negada: Um setor sem estoque próprio não pode ter usuários almoxarifes.'], 422);
                }
            }

            $id = DB::table('usuario_setor')->insertGetId([
                'usuario_id' => $usuarioId,
                'setor_id'   => $setorId,
                'perfil'     => $perfil,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json(['status' => true, 'data' => ['id' => $id, 'usuario_id' => $usuarioId, 'setor_id' => $setorId, 'perfil' => $perfil]]);
        } catch (\Throwable $e) {
            Log::error('Erro ao criar vinculo usuario_setor: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Erro interno ao criar vínculo.'], 500);
        }
    }

    /**
     * Atualizar perfil do vínculo usuário-setor.
     */
    public function update(UsuarioSetorRequest $request)
    {
        try {
            $dados = $request->validated();

            if (empty($dados['perfil'])) {
                return response()->json(['status' => false, 'message' => 'O perfil é obrigatório para atualizar.'], 400);
            }

            $usuarioId = $dados['usuario_id'];
            $setorId   = $dados['setor_id'];
            $perfil    = $dados['perfil'];

            /** @var User $usuario */
            $usuario = Auth::user();
            $isAdmin = $usuario->isSuperAdmin() || $usuario->setores()->where('setores.id', $setorId)->wherePivot('perfil', 'admin')->exists();
            if (!$isAdmin) {
                return response()->json(['status' => false, 'message' => 'Ação permitida apenas para administradores deste setor.'], 403);
            }

            $registro = DB::table('usuario_setor')->where('usuario_id', $usuarioId)->where('setor_id', $setorId)->first();
            if (!$registro) {
                return response()->json(['status' => false, 'message' => 'Vínculo não encontrado.'], 404);
            }

            // Regra de Negócio: CAF (Setor raiz sem distribuidor) não pode ter 'solicitante'
            // Regra de Negócio: Setor sem estoque não pode ter 'almoxarife'
            $setorObj = DB::table('setores')->where('id', $setorId)->first();
            if ($setorObj) {
                $hasDistribuidor = DB::table('setor_distribuidor')->where('setor_solicitante_id', $setorId)->exists();
                if (!$hasDistribuidor && $perfil === 'solicitante') {
                    return response()->json(['status' => false, 'message' => 'Operação negada: Uma CAF (setor centralizador) não pode ter usuários com perfil de solicitante.'], 422);
                }

                if (!$setorObj->estoque && $perfil === 'almoxarife') {
                    return response()->json(['status' => false, 'message' => 'Operação negada: Um setor sem estoque próprio não pode ter usuários almoxarifes.'], 422);
                }
            }

            DB::table('usuario_setor')->where('id', $registro->id)->update(['perfil' => $perfil, 'updated_at' => now()]);

            return response()->json(['status' => true, 'data' => ['usuario_id' => $usuarioId, 'setor_id' => $setorId, 'perfil' => $perfil]]);
        } catch (\Throwable $e) {
            Log::error('Erro ao atualizar vinculo usuario_setor: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Erro interno ao atualizar vínculo.'], 500);
        }
    }

    /**
     * Deletar vínculo usuário-setor.
     */
    public function delete(UsuarioSetorRequest $request)
    {
        try {
            $dados = $request->validated();

            $usuarioId = $dados['usuario_id'];
            $setorId   = $dados['setor_id'];

            /** @var User $usuario */
            $usuario = Auth::user();
            $isAdmin = $usuario->isSuperAdmin() || $usuario->setores()->where('setores.id', $setorId)->wherePivot('perfil', 'admin')->exists();
            if (!$isAdmin) {
                return response()->json(['status' => false, 'message' => 'Ação permitida apenas para administradores deste setor.'], 403);
            }

            $removidos = DB::table('usuario_setor')->where('usuario_id', $usuarioId)->where('setor_id', $setorId)->delete();

            if ($removidos) {
                return response()->json(['status' => true, 'message' => 'Vínculo removido com sucesso.']);
            }

            return response()->json(['status' => false, 'message' => 'Vínculo não encontrado.'], 404);
        } catch (\Throwable $e) {
            Log::error('Erro ao deletar vinculo usuario_setor: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Erro interno ao deletar vínculo.'], 500);
        }
    }

    /**
     * Listar usuários vinculados a um setor.
     */
    public function listarPorSetor(Request $request)
    {
        try {
            $setorId = $request->input('setor_id');

            if (!$setorId) {
                return response()->json(['status' => false, 'message' => 'ID do setor é obrigatório.'], 400);
            }

            $registros = DB::table('usuario_setor')
                ->join('users', 'usuario_setor.usuario_id', '=', 'users.id')
                ->where('usuario_setor.setor_id', $setorId)
                ->select('users.id', 'users.name', 'users.email', 'usuario_setor.perfil')
                ->get();

            return response()->json(['status' => true, 'data' => $registros]);
        } catch (\Throwable $e) {
            Log::error('Erro ao listar usuarios por setor: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Erro interno ao carregar a lista.'], 500);
        }
    }

    /**
     * Listar setores vinculados a um usuário (com polo em eager loading).
     */
    public function listarPorUsuario(Request $request)
    {
        try {
            $usuarioId = $request->input('usuario_id');

            if (!$usuarioId) {
                return response()->json(['status' => false, 'message' => 'ID do usuário é obrigatório.'], 400);
            }

            $registros = DB::table('usuario_setor')
                ->where('usuario_setor.usuario_id', $usuarioId)
                ->join('setores', 'usuario_setor.setor_id', '=', 'setores.id')
                ->leftJoin('polos', 'setores.polo_id', '=', 'polos.id')
                ->select(
                    'usuario_setor.setor_id',
                    'usuario_setor.perfil',
                    'setores.nome as setor_nome',
                    'polos.id as polo_id',
                    'polos.nome as polo_nome',
                    'polos.sigla as polo_sigla'
                )
                ->get()
                ->map(function ($row) {
                    return [
                        'setor_id' => $row->setor_id,
                        'perfil'   => $row->perfil,
                        'setor'    => [
                            'nome' => $row->setor_nome,
                            'polo' => [
                                'id'    => $row->polo_id,
                                'nome'  => $row->polo_nome,
                                'sigla' => $row->polo_sigla,
                            ],
                        ],
                    ];
                });

            return response()->json(['status' => true, 'data' => $registros]);
        } catch (\Throwable $e) {
            Log::error('Erro ao listar setores por usuario: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Erro interno ao carregar a lista.'], 500);
        }
    }
}