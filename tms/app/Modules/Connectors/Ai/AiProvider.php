<?php

namespace App\Modules\Connectors\Ai;

enum AiProvider: string
{
    case Anthropic = 'anthropic';
    case OpenAiCompatible = 'openai_compatible';

    public function label(): string
    {
        return match ($this) {
            self::Anthropic => 'Anthropic (Claude)',
            self::OpenAiCompatible => 'Compatible OpenAI',
        };
    }
}
