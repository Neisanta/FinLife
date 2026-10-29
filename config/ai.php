<?php
/**
 * FinLife — Configuração da camada de IA.
 *
 * A chave fica APENAS aqui (lida do .env), no backend.
 * Nenhum arquivo público (HTML/JS) tem acesso a estes valores.
 * O provedor é intercambiável: qualquer API compatível com
 * "chat/completions" (OpenAI, DeepSeek, Groq, OpenRouter...) funciona
 * apenas trocando URL, chave e modelo no .env.
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

$apiKey = Env::get('AI_API_KEY', '');
$isPlaceholder = ($apiKey === null || $apiKey === '' || stripos($apiKey, 'SUA_CHAVE') !== false);

return [
    'provider'    => Env::get('AI_PROVIDER', 'openai_compatible'),
    'api_url'     => Env::get('AI_API_URL', ''),
    'api_key'     => $apiKey ?? '',
    'model'       => Env::get('AI_MODEL', ''),
    'timeout'     => max(5, Env::int('AI_TIMEOUT', 30)),
    'max_tokens'  => max(200, Env::int('AI_MAX_TOKENS', 800)),
    'temperature' => min(2.0, max(0.0, Env::float('AI_TEMPERATURE', 0.4))),
    'disable_thinking' => Env::bool('AI_DISABLE_THINKING', false),
    'configured'  => !$isPlaceholder
        && Env::get('AI_API_URL') !== null
        && Env::get('AI_MODEL') !== null,
];
