<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            /*
             * O domínio usa a raiz do CNPJ,
             * portanto precisamos exatamente
             * de 8 posições e valor único.
             */
            'cnpj_root' => fake()
                ->unique()
                ->numerify(
                    '########'
                ),

            'corporate_name' => fake()->company(),

            'source' => 'test',

            'metadata' => [],
        ];
    }
}
