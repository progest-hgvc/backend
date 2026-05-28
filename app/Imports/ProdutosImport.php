<?php

namespace App\Imports;

use App\Models\Produto;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;

class ProdutosImport implements ToCollection
{
    public function collection(Collection $rows)
    {
        // Se a primeira linha for cabeçalho, ignora
        unset($rows[0]);

        foreach ($rows as $row) {

            Produto::create([
                'nome' => $row[1], // coluna nome
                'codigo_simpas' => $row[0], // coluna código
                'grupo_produto_id' => 1, // ajustar depois
                'unidade_medida_id' => 1, // ajustar depois
                'status' => 'A',
            ]);
        }
    }
}
