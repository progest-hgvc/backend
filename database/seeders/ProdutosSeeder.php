<?php

namespace Database\Seeders;

use App\Models\GrupoProduto;
use App\Models\Produto;
use App\Models\UnidadeMedida;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class ProdutosSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $catalogo = [
            'Analgésico' => [
                ['nome' => 'Paracetamol 500mg', 'marca' => 'Genérico', 'unidade' => 'Comprimido'],
                ['nome' => 'Dipirona Sódica 1g', 'marca' => 'Farmaco', 'unidade' => 'Ampulheta'],
            ],
            'Material de uso coletivo' => [
                ['nome' => 'Soro fisiológico 0,9%', 'marca' => 'HospClean', 'unidade' => 'Ml'],
                ['nome' => 'Kit curativo básico', 'marca' => 'HealthCare', 'unidade' => 'Pacote'],
            ],
            'Antibióticos' => [
                ['nome' => 'Amoxicilina 500mg', 'marca' => 'VidaFarma', 'unidade' => 'Comprimido'],
                ['nome' => 'Ceftriaxona 1g', 'marca' => 'BioLab', 'unidade' => 'Ampulheta'],
            ],
            'Vacina' => [
                ['nome' => 'Vacina Influenza Trivalente', 'marca' => 'ImunoPlus', 'unidade' => 'Ml'],
                ['nome' => 'Vacina Hepatite B', 'marca' => 'ImunoPlus', 'unidade' => 'Ml'],
            ],
            'Antidepressivo' => [
                ['nome' => 'Sertralina 50mg', 'marca' => 'PharmaLife', 'unidade' => 'Comprimido'],
                ['nome' => 'Fluoxetina 20mg', 'marca' => 'PharmaLife', 'unidade' => 'Comprimido'],
            ],
            'Material de escritório' => [
                ['nome' => 'Papel A4 500 folhas', 'marca' => 'OfficeMax', 'unidade' => 'Pacote'],
                ['nome' => 'Caneta esferográfica azul', 'marca' => 'EscritaPro', 'unidade' => 'Pacote'],
            ],
            'Material de limpeza' => [
                ['nome' => 'Detergente neutro 5L', 'marca' => 'LimpaMais', 'unidade' => 'Ml'],
                ['nome' => 'Rolo de papel toalha', 'marca' => 'LimpaMais', 'unidade' => 'Rolo'],
            ],
            'Outros materiais' => [
                ['nome' => 'Luvas descartáveis', 'marca' => 'SafeHands', 'unidade' => 'Pacote'],
                ['nome' => 'Máscara cirúrgica tripla', 'marca' => 'SafeHands', 'unidade' => 'Pacote'],
            ],
        ];

        foreach ($catalogo as $nomeGrupo => $produtos) {
            $grupo = GrupoProduto::firstWhere('nome', $nomeGrupo);

            if (!$grupo) {
                Log::warning('Grupo de produto não encontrado durante seeding de produtos', [
                    'grupo' => $nomeGrupo,
                ]);
                continue;
            }

            foreach ($produtos as $dadosProduto) {
                $unidade = UnidadeMedida::firstWhere('nome', $dadosProduto['unidade']);

                if (!$unidade) {
                    Log::warning('Unidade de medida não encontrada durante seeding de produtos', [
                        'produto' => $dadosProduto['nome'],
                        'unidade' => $dadosProduto['unidade'],
                    ]);
                    continue;
                }

                Produto::updateOrCreate(
                    [
                        'nome' => $dadosProduto['nome'],
                        'grupo_produto_id' => $grupo->id,
                    ],
                    [
                        'marca' => $dadosProduto['marca'] ?? null,
                        'codigo_simpas' => $dadosProduto['codigo_simpas'] ?? null,
                        'codigo_barras' => $dadosProduto['codigo_barras'] ?? null,
                        'unidade_medida_id' => $unidade->id,
                        'status' => 'A',
                    ]
                );
            }
        }
    }
}
