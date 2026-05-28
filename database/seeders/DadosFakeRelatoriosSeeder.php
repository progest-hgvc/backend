<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Entrada;
use App\Models\ItensEntrada;
use App\Models\Movimentacao;
use App\Models\ItemMovimentacao;
use App\Models\Setores;
use App\Models\SetorFornecedor;
use App\Models\Fornecedor;
use App\Models\Produto;
use App\Models\GrupoProduto;
use App\Models\UnidadeMedida;
use App\Models\Unidade;
use App\Models\TipoVinculo;
use App\Models\User;
use App\Models\Estoque;
use App\Models\EstoqueLote;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DadosFakeRelatoriosSeeder extends Seeder
{
    private $unidades = [];
    private $tiposVinculo = [];
    private $gruposProduto = [];
    private $unidadesMedida = [];
    private $setores = [];
    private $fornecedores = [];
    private $produtos = [];
    private $usuarios = [];

    public function run()
    {
        $this->command->info('🚀 Iniciando geração completa de dados fake...');

        DB::transaction(function () {
            // 1. Tipos de Vínculo
            $this->gerarTiposVinculo();

            // 2. Unidades (Polos)
            $this->gerarUnidades();

            // 3. Grupos de Produto
            $this->gerarGruposProduto();

            // 4. Unidades de Medida
            $this->gerarUnidadesMedida();

            // 5. Setores
            $this->gerarSetores();

            // 6. Fornecedores
            $this->gerarFornecedores();

            // 7. Setor-Fornecedor
            $this->gerarSetorFornecedor();

            // 8. Produtos
            $this->gerarProdutos();

            // 9. Usuários (usando IDs altos para não conflitar)
            $this->gerarUsuarios();

            // 10. Usuario-Setor
            $this->gerarUsuarioSetor();

            // 11. Estoque (auto-criado pelos observers, mas vamos garantir)
            $this->garantirEstoque();

            // 12. Entradas
            $this->gerarEntradas();

            // 13. Estoque Lote (criado pelas entradas, mas vamos adicionar mais)
            $this->gerarEstoqueLote();

            // 14. Movimentações
            $this->gerarMovimentacoes();

            // 15. Garantir que o admin padrão tenha acesso a tudo
            $this->vincularAdminAosSetores();
        });

        $this->command->info('✅ Todos os dados fake foram gerados com sucesso!');
    }

    private function vincularAdminAosSetores()
    {
        $admin = User::where('email', 'admin@admin.com')->first();
        if ($admin) {
            $this->command->info('🛡️  Vinculando o admin@admin.com a todos os setores fakes...');
            $syncData = [];
            foreach ($this->setores as $setor) {
                $syncData[$setor->id] = ['perfil' => 'almoxarife'];
            }
            $admin->setores()->syncWithoutDetaching($syncData);
        }
    }

    private function gerarTiposVinculo()
    {
        $this->command->info('📋 Gerando tipos de vínculo...');

        $tipos = [
            ['nome' => 'EFETIVO', 'descricao' => 'Servidor efetivo', 'status' => 'A'],
            ['nome' => 'TEMPORÁRIO', 'descricao' => 'Contrato temporário', 'status' => 'A'],
            ['nome' => 'TERCEIRIZADO', 'descricao' => 'Funcionário terceirizado', 'status' => 'A'],
            ['nome' => 'ESTAGIÁRIO', 'descricao' => 'Estagiário', 'status' => 'A'],
            ['nome' => 'VOLUNTÁRIO', 'descricao' => 'Voluntário', 'status' => 'A'],
            ['nome' => 'RESIDENTE', 'descricao' => 'Médico residente', 'status' => 'A'],
        ];

        foreach ($tipos as $tipo) {
            $this->tiposVinculo[] = TipoVinculo::firstOrCreate(
                ['nome' => $tipo['nome']],
                $tipo
            );
        }

        $this->command->info('  ✓ ' . count($this->tiposVinculo) . ' tipos de vínculo criados');
    }

    private function gerarUnidades()
    {
        $this->command->info('🏥 Gerando unidades (polos)...');

        $unidades = [
            ['nome' => 'HOSPITAL CENTRAL', 'status' => 'A'],
            ['nome' => 'POSTO DE SAÚDE NORTE', 'status' => 'A'],
            ['nome' => 'UPA SUL', 'status' => 'A'],
        ];

        foreach ($unidades as $unidade) {
            $this->unidades[] = Unidade::firstOrCreate(
                ['nome' => $unidade['nome']],
                $unidade
            );
        }

        $this->command->info('  ✓ ' . count($this->unidades) . ' unidades criadas');
    }

    private function gerarGruposProduto()
    {
        $this->command->info('📦 Gerando grupos de produto...');

        $grupos = [
            ['nome' => 'ANTIBIÓTICOS', 'tipo' => 'Medicamento', 'status' => 'A'],
            ['nome' => 'ANALGÉSICOS', 'tipo' => 'Medicamento', 'status' => 'A'],
            ['nome' => 'ANTITÉRMICOS', 'tipo' => 'Medicamento', 'status' => 'A'],
            ['nome' => 'MATERIAL CIRÚRGICO', 'tipo' => 'Material', 'status' => 'A'],
            ['nome' => 'MATERIAL DE CURATIVOS', 'tipo' => 'Material', 'status' => 'A'],
            ['nome' => 'EQUIPAMENTOS DESCARTÁVEIS', 'tipo' => 'Material', 'status' => 'A'],
        ];

        foreach ($grupos as $grupo) {
            $this->gruposProduto[] = GrupoProduto::firstOrCreate(
                ['nome' => $grupo['nome']],
                $grupo
            );
        }

        $this->command->info('  ✓ ' . count($this->gruposProduto) . ' grupos de produto criados');
    }

    private function gerarUnidadesMedida()
    {
        $this->command->info('📏 Gerando unidades de medida...');

        $unidades = [
            ['nome' => 'UNIDADE', 'status' => 'A'],
            ['nome' => 'CAIXA', 'status' => 'A'],
            ['nome' => 'FRASCO', 'status' => 'A'],
            ['nome' => 'AMPOLA', 'status' => 'A'],
            ['nome' => 'COMPRIMIDO', 'status' => 'A'],
            ['nome' => 'MILILITRO', 'status' => 'A'],
        ];

        foreach ($unidades as $unidade) {
            $this->unidadesMedida[] = UnidadeMedida::firstOrCreate(
                ['nome' => $unidade['nome']],
                $unidade
            );
        }

        $this->command->info('  ✓ ' . count($this->unidadesMedida) . ' unidades de medida criadas');
    }

    private function gerarSetores()
    {
        $this->command->info('🏢 Gerando setores...');

        $setores = [
            ['nome' => 'ALMOXARIFADO CENTRAL', 'tipo' => 'Medicamento', 'estoque' => true, 'status' => 'A'],
            ['nome' => 'FARMÁCIA HOSPITALAR', 'tipo' => 'Medicamento', 'estoque' => true, 'status' => 'A'],
            ['nome' => 'UTI ADULTO', 'tipo' => 'Medicamento', 'estoque' => false, 'status' => 'A'],
            ['nome' => 'EMERGÊNCIA', 'tipo' => 'Medicamento', 'estoque' => true, 'status' => 'A'],
            ['nome' => 'CENTRO CIRÚRGICO', 'tipo' => 'Material', 'estoque' => true, 'status' => 'A'],
            ['nome' => 'ENFERMARIA GERAL', 'tipo' => 'Material', 'estoque' => false, 'status' => 'A'],
        ];

        foreach ($setores as $setorData) {
            $unidade = $this->unidades[array_rand($this->unidades)];
            $setorData['polo_id'] = $unidade->id;
            
            $this->setores[] = Setores::firstOrCreate(
                ['nome' => $setorData['nome'], 'polo_id' => $unidade->id],
                $setorData
            );
        }

        $this->command->info('  ✓ ' . count($this->setores) . ' setores criados');
    }

    private function gerarFornecedores()
    {
        $this->command->info('🏪 Gerando fornecedores...');

        $fornecedores = [
            ['tipo_pessoa' => 'J', 'razao_social_nome' => 'DISTRIBUIDORA PHARMA LTDA', 'cnpj' => '11222333000144', 'status' => 'A'],
            ['tipo_pessoa' => 'J', 'razao_social_nome' => 'MEDIC SUPPLY SA', 'cnpj' => '22333444000155', 'status' => 'A'],
            ['tipo_pessoa' => 'J', 'razao_social_nome' => 'BRASIL HOSPITALAR LTDA', 'cnpj' => '33444555000166', 'status' => 'A'],
            ['tipo_pessoa' => 'J', 'razao_social_nome' => 'SAÚDE TOTAL DISTRIBUIDORA', 'cnpj' => '44555666000177', 'status' => 'A'],
            ['tipo_pessoa' => 'J', 'razao_social_nome' => 'EQUIPAMENTOS MÉDICOS DO NORDESTE', 'cnpj' => '55666777000188', 'status' => 'A'],
        ];

        foreach ($fornecedores as $fornecedor) {
            $this->fornecedores[] = Fornecedor::firstOrCreate(
                ['cnpj' => $fornecedor['cnpj']],
                $fornecedor
            );
        }

        $this->command->info('  ✓ ' . count($this->fornecedores) . ' fornecedores criados');
    }

    private function gerarSetorFornecedor()
    {
        $this->command->info('🔗 Vinculando setores a fornecedores...');

        $count = 0;
        foreach ($this->setores as $setor) {
            // Cada setor terá 2-3 fornecedores
            $numFornecedores = rand(2, 3);
            $fornecedoresSelecionados = array_rand(array_flip(array_keys($this->fornecedores)), $numFornecedores);
            
            if (!is_array($fornecedoresSelecionados)) {
                $fornecedoresSelecionados = [$fornecedoresSelecionados];
            }

            foreach ($fornecedoresSelecionados as $index) {
                SetorFornecedor::firstOrCreate([
                    'setor_solicitante_id' => $setor->id,
                    'setor_fornecedor_id' => $this->fornecedores[$index]->id,
                ]);
                $count++;
            }
        }

        $this->command->info('  ✓ ' . $count . ' vínculos setor-fornecedor criados');
    }

    private function gerarProdutos()
    {
        $this->command->info('💊 Gerando produtos...');

        $produtosMedicamento = [
            'PARACETAMOL 500MG', 'DIPIRONA 500MG', 'IBUPROFENO 400MG',
            'AMOXICILINA 500MG', 'AZITROMICINA 500MG', 'CEFALEXINA 500MG',
            'OMEPRAZOL 20MG', 'RANITIDINA 150MG', 'DEXAMETASONA 4MG',
        ];

        $produtosMaterial = [
            'LUVA PROCEDIMENTO M', 'LUVA CIRÚRGICA 7.5', 'MÁSCARA CIRÚRGICA',
            'SERINGA 10ML', 'AGULHA 40X12', 'CATETER VENOSO 22G',
            'GAZE ESTERILIZADA', 'ESPARADRAPO 5CM', 'ALGODÃO 500G',
        ];

        foreach ($produtosMedicamento as $nome) {
            $grupo = $this->gruposProduto[array_rand(array_filter($this->gruposProduto, fn($g) => $g->tipo === 'Medicamento'))];
            $unidade = $this->unidadesMedida[array_rand($this->unidadesMedida)];

            $this->produtos[] = Produto::firstOrCreate(
                ['nome' => $nome],
                [
                    'codigo_simpas' => 'MED-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT),
                    'grupo_produto_id' => $grupo->id,
                    'unidade_medida_id' => $unidade->id,
                    'status' => 'A',
                ]
            );
        }

        foreach ($produtosMaterial as $nome) {
            $grupo = $this->gruposProduto[array_rand(array_filter($this->gruposProduto, fn($g) => $g->tipo === 'Material'))];
            $unidade = $this->unidadesMedida[array_rand($this->unidadesMedida)];

            $this->produtos[] = Produto::firstOrCreate(
                ['nome' => $nome],
                [
                    'codigo_simpas' => 'MAT-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT),
                    'grupo_produto_id' => $grupo->id,
                    'unidade_medida_id' => $unidade->id,
                    'status' => 'A',
                ]
            );
        }

        $this->command->info('  ✓ ' . count($this->produtos) . ' produtos criados');
    }

    private function gerarUsuarios()
    {
        $this->command->info('👥 Gerando usuários...');

        $nomes = [
            'Carlos Silva', 'Maria Santos', 'João Oliveira', 'Ana Costa',
            'Pedro Almeida', 'Julia Ferreira', 'Lucas Rodrigues', 'Fernanda Lima',
            'Roberto Souza', 'Patricia Gomes', 'Ricardo Martins', 'Amanda Pereira',
        ];

        $baseId = User::max('id') ?? 1000; // Usar ID alto para não conflitar

        foreach ($nomes as $index => $nome) {
            $cpf = '900' . str_pad($baseId + $index, 8, '0', STR_PAD_LEFT);
            $email = strtolower(str_replace(' ', '.', $nome)) . '@fake.hospital.com';
            $tipoVinculo = $this->tiposVinculo[array_rand($this->tiposVinculo)];

            $this->usuarios[] = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => mb_strtoupper($nome),
                    'cpf' => $cpf,
                    'telefone' => '71999' . str_pad(rand(100000, 999999), 6, '0', STR_PAD_LEFT),
                    'data_nascimento' => Carbon::now()->subYears(rand(25, 55))->format('Y-m-d'),
                    'tipo_vinculo' => $tipoVinculo->id,
                    'status' => 'A',
                    'password' => bcrypt('password'),
                    'email_verified_at' => now(),
                ]
            );
        }

        $this->command->info('  ✓ ' . count($this->usuarios) . ' usuários criados');
    }

    private function gerarUsuarioSetor()
    {
        $this->command->info('👤 Vinculando usuários a setores...');

        $count = 0;
        $perfis = ['almoxarife', 'solicitante'];

        foreach ($this->usuarios as $usuario) {
            // Cada usuário terá 1-3 setores
            $numSetores = rand(1, 3);
            $setoresSelecionados = array_rand(array_flip(array_keys($this->setores)), min($numSetores, count($this->setores)));
            
            if (!is_array($setoresSelecionados)) {
                $setoresSelecionados = [$setoresSelecionados];
            }

            foreach ($setoresSelecionados as $index) {
                DB::table('usuario_setor')->insertOrIgnore([
                    'usuario_id' => $usuario->id,
                    'setor_id' => $this->setores[$index]->id,
                    'perfil' => $perfis[array_rand($perfis)],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $count++;
            }
        }

        $this->command->info('  ✓ ' . $count . ' vínculos usuário-setor criados');
    }

    private function garantirEstoque()
    {
        $this->command->info('📊 Garantindo registros de estoque...');

        $count = 0;
        $setoresComEstoque = array_filter($this->setores, fn($s) => $s->estoque);

        foreach ($setoresComEstoque as $setor) {
            foreach ($this->produtos as $produto) {
                // Verificar compatibilidade de tipo
                if ($produto->grupoProduto->tipo !== $setor->tipo) {
                    continue;
                }

                Estoque::firstOrCreate(
                    [
                        'setor_id' => $setor->id,
                        'produto_id' => $produto->id,
                    ],
                    [
                        'quantidade_atual' => 0,
                        'quantidade_minima' => rand(10, 50),
                        'status_disponibilidade' => 'I',
                    ]
                );
                $count++;
            }
        }

        $this->command->info('  ✓ ' . $count . ' registros de estoque garantidos');
    }

    private function gerarEntradas()
    {
        $this->command->info('📥 Gerando entradas de estoque (últimos 12 meses)...');

        $setoresComEstoque = array_filter($this->setores, fn($s) => $s->estoque);

        for ($i = 1; $i <= 150; $i++) {
            $setor = $setoresComEstoque[array_rand($setoresComEstoque)];
            $fornecedor = $this->fornecedores[array_rand($this->fornecedores)];
            
            // Datas aleatórias dos últimos 12 meses
            $dataEntrada = Carbon::now()->subDays(rand(0, 365));

            $entrada = Entrada::create([
                'nota_fiscal' => 'NF-FAKE-' . date('Y', strtotime($dataEntrada)) . '-' . str_pad($i, 6, '0', STR_PAD_LEFT),
                'setor_id' => $setor->id,
                'fornecedor_id' => $fornecedor->id,
                'created_at' => $dataEntrada,
                'updated_at' => $dataEntrada,
            ]);

            // Gerar de 1 a 5 itens por entrada
            $numItens = rand(1, 5);
            $produtosCompativeis = array_filter($this->produtos, fn($p) => $p->grupoProduto->tipo === $setor->tipo);
            
            for ($j = 0; $j < $numItens; $j++) {
                if (empty($produtosCompativeis)) continue;
                
                $produto = $produtosCompativeis[array_rand($produtosCompativeis)];
                $quantidade = rand(50, 500);
                
                ItensEntrada::create([
                    'entrada_id' => $entrada->id,
                    'produto_id' => $produto->id,
                    'quantidade' => $quantidade,
                    'lote' => 'L' . date('Y', strtotime($dataEntrada)) . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT),
                    'data_fabricacao' => $dataEntrada->copy()->subMonths(rand(1, 6)),
                    'data_vencimento' => $dataEntrada->copy()->addMonths(rand(12, 36)),
                    'created_at' => $dataEntrada,
                    'updated_at' => $dataEntrada,
                ]);

                // Atualizar estoque
                $estoque = Estoque::where('setor_id', $setor->id)
                    ->where('produto_id', $produto->id)
                    ->first();
                    
                if ($estoque) {
                    $estoque->quantidade_atual += $quantidade;
                    $estoque->status_disponibilidade = 'D';
                    $estoque->save();
                }
            }

            if ($i % 30 == 0) {
                $this->command->info("  ✓ {$i}/150 entradas criadas");
            }
        }
    }

    private function gerarEstoqueLote()
    {
        $this->command->info('📦 Gerando lotes de estoque adicionais...');

        $count = 0;
        $estoques = Estoque::where('quantidade_atual', '>', 0)->limit(50)->get();

        foreach ($estoques as $estoque) {
            // Gerar 1-3 lotes por estoque
            $numLotes = rand(1, 3);
            
            for ($i = 0; $i < $numLotes; $i++) {
                $dataFabricacao = Carbon::now()->subMonths(rand(1, 12));
                $dataVencimento = Carbon::now()->addMonths(rand(6, 24));
                
                EstoqueLote::firstOrCreate(
                    [
                        'setor_id' => $estoque->setor_id,
                        'produto_id' => $estoque->produto_id,
                        'lote' => 'L' . date('Y') . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT),
                    ],
                    [
                        'quantidade_disponivel' => rand(10, 200),
                        'data_fabricacao' => $dataFabricacao,
                        'data_vencimento' => $dataVencimento,
                    ]
                );
                $count++;
            }
        }

        $this->command->info('  ✓ ' . $count . ' lotes de estoque criados');
    }

    private function gerarMovimentacoes()
    {
        $this->command->info('🔄 Gerando movimentações (últimos 12 meses)...');

        $tipos = ['T', 'S', 'D']; // T = Transferência, D = Devolução, S = Saída

        for ($i = 1; $i <= 100; $i++) {
            $setorOrigem = $this->setores[array_rand($this->setores)];
            $setoresDestino = array_filter($this->setores, fn($s) => $s->id !== $setorOrigem->id);
            
            if (empty($setoresDestino)) continue;
            
            $setorDestino = $setoresDestino[array_rand($setoresDestino)];
            $usuario = $this->usuarios[array_rand($this->usuarios)];
            $tipo = $tipos[array_rand($tipos)];
            
            // Datas aleatórias dos últimos 12 meses
            $dataMovimentacao = Carbon::now()->subDays(rand(0, 365));

            $movimentacao = Movimentacao::create([
                'usuario_id' => $usuario->id,
                'setor_origem_id' => $setorOrigem->id,
                'setor_destino_id' => $setorDestino->id,
                'tipo' => $tipo,
                'data_hora' => $dataMovimentacao,
                'status_solicitacao' => rand(0, 10) > 2 ? 'A' : 'P', // 80% aprovadas
                'observacao' => 'Movimentação fake para testes - tipo ' . $tipo,
                'created_at' => $dataMovimentacao,
                'updated_at' => $dataMovimentacao,
            ]);

            // Gerar de 1 a 4 itens por movimentação
            $numItens = rand(1, 4);
            
            for ($j = 0; $j < $numItens; $j++) {
                $produto = $this->produtos[array_rand($this->produtos)];
                $quantidadeSolicitada = rand(5, 50);
                
                ItemMovimentacao::create([
                    'movimentacao_id' => $movimentacao->id,
                    'produto_id' => $produto->id,
                    'quantidade_solicitada' => $quantidadeSolicitada,
                    'quantidade_liberada' => $movimentacao->status_solicitacao === 'A' ? $quantidadeSolicitada : 0,
                    'lote' => 'L' . date('Y', strtotime($dataMovimentacao)) . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT),
                    'created_at' => $dataMovimentacao,
                    'updated_at' => $dataMovimentacao,
                ]);
            }

            if ($i % 25 == 0) {
                $this->command->info("  ✓ {$i}/100 movimentações criadas");
            }
        }
    }
}
