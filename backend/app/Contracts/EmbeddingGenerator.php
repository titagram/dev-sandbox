<?php

namespace App\Contracts;

interface EmbeddingGenerator
{
    /**
     * @return list<float>
     */
    public function generate(string $input): array;
}
