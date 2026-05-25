<?php

namespace App\Http\Controllers\Cadastros;

use App\Models\Polo;
use Illuminate\Http\Request;
use App\Http\Requests\PoloRequest;
use Illuminate\Support\Facades\Log;

class PoloController
{
    /**
     * Criar novo polo
     */
    public function add(PoloRequest $request)
    {
        try {
            $data = $request->validated();

            $polo = Polo::create([
                'nome' => mb_strtoupper($data['nome']),
                'status' => $data['status'] ?? 'A'
            ]);

            return response()->json([
                'status' => true,
                'data' => $polo,
                'message' => 'Polo criado com sucesso'
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Erro ao criar polo: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Erro interno ao salvar polo: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Listar todos os polos
     */
    public function listAll(Request $request)
    {
        try {
            $data = $request->all();
            $filters = $data['filters'] ?? [];
            $perPage = $data['per_page'] ?? 15;

            $query = Polo::query();

            // Aplicar filtros
            foreach ($filters as $condition) {
                foreach ($condition as $column => $value) {
                    if ($value !== null && $value !== '') {
                        if ($column === 'status') {
                            $query->where($column, $value);
                        } else {
                            $query->where($column, 'like', '%' . $value . '%');
                        }
                    }
                }
            }

            $polos = $query
                ->orderBy('nome')
                ->paginate($perPage);

            return response()->json([
                'status' => true,
                'data' => $polos
            ]);
        } catch (\Throwable $e) {
            Log::error('Erro ao listar polos: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Obter dados de um polo específico
     */
    public function listData(Request $request)
    {
        try {
            $id = $request->input('id');

            if (!$id) {
                return response()->json([
                    'status' => false,
                    'message' => 'ID do polo é obrigatório'
                ], 400);
            }

            $polo = Polo::find($id);

            if (!$polo) {
                return response()->json([
                    'status' => false,
                    'message' => 'Polo não encontrado'
                ], 404);
            }

            return response()->json([
                'status' => true,
                'data' => $polo
            ]);
        } catch (\Throwable $e) {
            Log::error('Erro ao buscar polo: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Atualizar polo existente
     */
    public function update(PoloRequest $request)
    {
        try {
            $data = $request->validated();

            $polo = Polo::find($data['id']);

            if (!$polo) {
                return response()->json([
                    'status' => false,
                    'message' => 'Polo não encontrado'
                ], 404);
            }

            $polo->update([
                'nome' => mb_strtoupper($data['nome']),
                'status' => $data['status'] ?? $polo->status
            ]);

            return response()->json([
                'status' => true,
                'data' => $polo,
                'message' => 'Polo atualizado com sucesso'
            ]);
        } catch (\Throwable $e) {
            Log::error('Erro ao atualizar polo: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Erro interno ao atualizar polo: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Deletar polo (soft delete via status)
     */
    public function delete(Request $request, $id)
    {
        try {
            $polo = Polo::find($id);

            if (!$polo) {
                return response()->json([
                    'status' => false,
                    'message' => 'Polo não encontrado'
                ], 404);
            }

            // Verificar se há setores vinculados e retornar 422 para o Interceptor
            $setoresCount = $polo->setores()->count();
            if ($setoresCount > 0) {
                return response()->json([
                    'status' => false,
                    'message' => 'Não é possível deletar este polo pois possui setores vinculados.',
                    'references' => ['setores (' . $setoresCount . ')']
                ], 422);
            }

            $polo->delete();

            return response()->json([
                'status' => true,
                'message' => 'Polo deletado com sucesso'
            ]);
        } catch (\Throwable $e) {
            Log::error('Erro ao deletar polo: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Alternar status do polo (A/I)
     */
    public function toggleStatus(Request $request)
    {
        try {
            $id = $request->input('id');

            if (!$id) {
                return response()->json([
                    'status' => false,
                    'message' => 'ID do polo é obrigatório'
                ], 400);
            }

            $polo = Polo::find($id);

            if (!$polo) {
                return response()->json([
                    'status' => false,
                    'message' => 'Polo não encontrado'
                ], 404);
            }

            $polo->status = $polo->status === 'A' ? 'I' : 'A';
            $polo->save();

            return response()->json([
                'status' => true,
                'data' => $polo,
                'message' => 'Status alterado com sucesso'
            ]);
        } catch (\Throwable $e) {
            Log::error('Erro ao alternar status do polo: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }
}