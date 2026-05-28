<?php

namespace Tests\Feature;

use App\Models\Estoque;
use App\Models\GrupoProduto;
use App\Models\ItemMovimentacao;
use App\Models\Movimentacao;
use App\Models\Produto;
use App\Models\Setores;
use App\Models\Unidade;
use App\Models\UnidadeMedida;
use App\Models\User;
use Tests\TestCase;

class MovimentacaoSimpleTest extends TestCase
{
    // NOTA: RefreshDatabase removido - testes rodam no banco real
    // Para criar dados de teste, usar seeders ou factories manualmente

    /**
     * Teste simplificado: apenas verificar se a correção funciona
     */
    public function test_movimentacao_corrige_estoque_destino()
    {
        // 1. CRIAR DADOS
        $unidade = Unidade::factory()->create(['nome' => 'UNIDADE TESTE', 'status' => 'A']);
        $usuario = User::factory()->create(['name' => 'Usuario Teste']);

        $grupo = GrupoProduto::create([
            'nome' => 'MEDICAMENTOS',
            'tipo' => 'Medicamento',
            'status' => 'A'
        ]);

        $unidadeMedida = UnidadeMedida::create([
            'nome' => 'UNIDADE',
            'sigla' => 'UN',
            'status' => 'A'
        ]);

        $setorOrigem = Setores::create([
            'nome' => 'FARMÁCIA',
            'tipo' => 'Medicamento',
            'estoque' => true,
            'status' => 'A',
            'polo_id' => $unidade->id
        ]);

        $setorDestino = Setores::create([
            'nome' => 'ALMO​XARIFADO',
            'tipo' => 'Medicamento',
            'estoque' => true,
            'status' => 'A',
            'polo_id' => $unidade->id
        ]);

        $produto = Produto::create([
            'nome' => 'DIPIRONA',
            'codigo_simpas' => '001',
            'codigo_barras' => '123',
            'grupo_produto_id' => $grupo->id,
            'unidade_medida_id' => $unidadeMedida->id,
            'status' => 'A'
        ]);

        // 2. DELETAR estoque auto-criado pelos observers e criar com quantidade 100
        \DB::table('estoque')->where('produto_id', $produto->id)
            ->where('setor_id', $setorOrigem->id)
            ->delete();
        
        \DB::table('estoque')->insert([
            'produto_id' => $produto->id,
            'setor_id' => $setorOrigem->id,
            'quantidade_atual' => 100,
            'quantidade_minima' => 10,
            'status_disponibilidade' => 'D',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Buscar o estoque criado
        $estoqueOrigem = Estoque::where('produto_id', $produto->id)
            ->where('setor_id', $setorOrigem->id)
            ->first();
        
        // Verificar que foi criado com 100
        $this->assertEquals(100, $estoqueOrigem->quantidade_atual, 
            "Estoque inicial deve ser 100");

        // 3. CRIAR MOVIMENTAÇÃO
        $mov = Movimentacao::create([
            'usuario_id' => $usuario->id,
            'setor_origem_id' => $setorOrigem->id,
            'setor_destino_id' => $setorDestino->id,
            'tipo' => 'T',
            'data_hora' => now(),
            'status_solicitacao' => 'P'
        ]);

        $item = ItemMovimentacao::create([
            'movimentacao_id' => $mov->id,
            'produto_id' => $produto->id,
            'quantidade_solicitada' => 20,
            'quantidade_liberada' => 0
        ]);

        // 4. APROVAR MOVIMENTAÇÃO
        $response = $this->postJson("/api/movimentacao/{$mov->id}/process", [
            'action' => 'approve',
            'aprovador_usuario_id' => $usuario->id,
            'itens' => [
                [
                    'id' => $item->id,
                    'quantidade_liberada' => 20
                ]
            ]
        ]);

        if ($response->status() !== 200) {
            // Verificar estoque diretamente no banco antes da aprovação
            $estoqueAntes = \DB::table('estoque')
                ->where('produto_id', $produto->id)
                ->where('setor_id', $setorOrigem->id)
                ->first();
            
            $this->fail("Falhou na aprovação. Erro: " . json_encode($response->json()) . "\n\nEstoque no banco: " . json_encode($estoqueAntes));
        }

        $response->assertStatus(200);
        $response->assertJson(['status' => true]);

        // 5. VERIFICAR ESTOQUE ORIGEM (deve ter decrementado)
        $estoqueOrigem->refresh();
        $this->assertEquals(80, $estoqueOrigem->quantidade_atual, 
            'Estoque de ORIGEM deve ter diminuí​do de 100 para 80');

        // 6. VERIFICAR ESTOQUE DESTINO (deve ter sido criado e incrementado)
        $estoqueDestino = Estoque::where('produto_id', $produto->id)
            ->where('setor_id', $setorDestino->id)
            ->first();
        
        $this->assertNotNull($estoqueDestino, 
            'Estoque de DESTINO deve ter sido criado');
        $this->assertEquals(20, $estoqueDestino->quantidade_atual, 
            'Estoque de DESTINO deve ter 20 unidades - BUG CORRIGIDO!');

        // ✅ SE ESTE TESTE PASSAR, A CORREÇÃO ESTÁ FUNCIONANDO!
    }
}
