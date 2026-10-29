<?php
/**
 * FinLife — Camada de comunicação com a API de IA.
 *
 * - A API key fica APENAS no backend (.env), nunca no navegador
 * - Suporta qualquer provedor compatível com "chat/completions"
 *   (OpenAI, DeepSeek, Groq, OpenRouter, Together...)
 * - Timeout, tratamento de falhas e log sem segredos
 * - Nunca executa SQL: apenas devolve texto/JSON validado depois
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

final class AIService
{
    private array $cfg;

    public function __construct()
    {
        $this->cfg = require CONFIG_PATH . '/ai.php';
    }

    /** Indica se há provedor externo de IA configurado. */
    public function isConfigured(): bool
    {
        return (bool) ($this->cfg['configured'] ?? false);
    }

    public function model(): string
    {
        return (string) ($this->cfg['model'] ?? '');
    }

    /**
     * Envia mensagens ao provedor e retorna o texto de resposta.
     *
     * @return array{ok: bool, content?: string, error?: string}
     */
    public function chat(array $messages, ?float $temperature = null): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'not_configured'];
        }

        $payload = [
            'model'       => $this->cfg['model'],
            'messages'    => $messages,
            'temperature' => $temperature ?? $this->cfg['temperature'],
            'max_tokens'  => $this->cfg['max_tokens'],
        ];

        // Modelos de reasoning (Nemotron etc.): desativa o raciocínio interno
        // para o content final vir direto (mais rápido e economiza cota).
        if (!empty($this->cfg['disable_thinking'])) {
            $payload['chat_template_kwargs'] = ['thinking' => false];
        }

        $res = $this->httpPost($this->cfg['api_url'], $payload);
        if (!$res['ok']) {
            return $res;
        }

        $data = json_decode($res['body'] ?? '', true);
        if (!is_array($data)) {
            log_error('IA: resposta não-JSON', ['http' => $res['http'] ?? 0]);
            return ['ok' => false, 'error' => 'resposta_invalida'];
        }

        $content = $data['choices'][0]['message']['content']
            ?? $data['choices'][0]['text']
            ?? null;

        // Modelos de reasoning: se o content veio null mas o raciocínio
        // interno existiu, é falha de tokens — tratamos como resposta vazia
        // (o orquestrador cai no modo local com dados reais).
        if (!is_string($content) || trim($content) === '') {
            $hadReasoning = isset($data['choices'][0]['message']['reasoning_content']);
            log_error('IA: resposta sem conteúdo', ['reasoning_only' => $hadReasoning]);
            return ['ok' => false, 'error' => 'resposta_vazia'];
        }

        return ['ok' => true, 'content' => trim($content)];
    }

    /**
     * Extrai o primeiro objeto JSON válido de uma resposta textual.
     * Aceita JSON puro, blocos ```json ...``` ou o primeiro {...} balanceado.
     */
    public static function extractJson(string $content): ?array
    {
        $content = trim($content);

        // 1) a resposta inteira é um JSON?
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // 2) bloco de código ```json ... ```
        if (preg_match('~```(?:json)?\s*(\{.*?\})\s*```~s', $content, $m)) {
            $decoded = json_decode($m[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // 3) primeiro objeto balanceado {...}
        if (preg_match('~\{[^{}]*(?:[^{}]*\{[^{}]*\})*[^{}]*\}~s', $content, $m)) {
            $decoded = json_decode($m[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /** POST HTTP com cURL, timeout e log de falhas sem segredos. */
    private function httpPost(string $url, array $payload): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'curl_init'];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST            => true,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_POSTFIELDS      => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER      => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->cfg['api_key'],
            ],
            CURLOPT_TIMEOUT         => $this->cfg['timeout'],
            CURLOPT_CONNECTTIMEOUT  => 10,
            CURLOPT_SSL_VERIFYPEER  => true,
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $errno !== 0) {
            log_error('IA: falha de rede', ['errno' => $errno, 'msg' => $errmsg]);
            return ['ok' => false, 'error' => 'rede'];
        }
        if ($http >= 400) {
            log_error('IA: erro HTTP', ['http' => $http]);
            return ['ok' => false, 'error' => 'http_' . $http];
        }
        return ['ok' => true, 'body' => $body, 'http' => $http];
    }
}
