<?php

namespace App\Services\Translation;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;

/**
 * 상품명 번역 — Claude API (원본 다담다 inc/lib_translate.php 의 프롬프트 규칙 그대로).
 * 구글 번역은 "가소사(이탈리아 음료)" 를 "휘발유" 로 옮기는 식의 오역이 잦아서, 상품명만은 음식 문맥을 아는 모델로 미리 번역해 둔다.
 * API 키: 환경 변수 ANTHROPIC_API_KEY (config services.anthropic.key). 화면·로그에 키를 남기지 않는다.
 */
class ClaudeProductNameTranslator implements ProductNameTranslator
{
    public const MODEL = 'claude-opus-5-5';

    private const LANG_NAMES = ['en' => 'English', 'ja' => 'Japanese', 'zh' => 'Simplified Chinese (zh-CN)'];

    public function __construct(private readonly ?string $apiKey) {}

    public function ready(): bool
    {
        return (string) $this->apiKey !== '';
    }

    public function translate(array $names, string $lang): array
    {
        if (! $this->ready()) {
            throw new TranslationFailed('ANTHROPIC_API_KEY 가 설정되지 않았어요.');
        }
        if (! isset(self::LANG_NAMES[$lang])) {
            throw new TranslationFailed("지원하지 않는 언어: {$lang}");
        }
        $names = array_values($names);
        if ($names === []) {
            return [];
        }

        $target = self::LANG_NAMES[$lang];
        $count = count($names);
        $list = json_encode($names, JSON_UNESCAPED_UNICODE);
        $prompt = <<<TXT
        You translate Korean menu item names for a concert-venue food court (food and drinks) into {$target}.
        Rules:
        - These are names of food and beverages. Translate by meaning in a food context (e.g. 가소사 is the Italian soda "Gassosa", not gasoline).
        - Brand names and proper nouns: use the brand's official spelling in {$target} if one exists; otherwise romanize.
        - Keep markers such as (HOT), (ICE), sizes, numbers and units exactly as written.
        - Keep each name short, as it would appear on a menu board. No explanations.
        - Return exactly one translation per input, in the same order.

        Input (JSON array of {$count} names):
        {$list}
        TXT;

        try {
            $client = new Client(apiKey: $this->apiKey);
            $message = $client->messages->create(
                model: self::MODEL,
                maxTokens: 16000,
                messages: [['role' => 'user', 'content' => $prompt]],
                outputConfig: ['format' => [
                    'type' => 'json_schema',
                    'schema' => [
                        'type' => 'object',
                        'properties' => ['translations' => ['type' => 'array', 'items' => ['type' => 'string']]],
                        'required' => ['translations'],
                        'additionalProperties' => false,
                    ],
                ]],
            );
        } catch (AnthropicException $e) {
            throw new TranslationFailed('Claude API 호출 실패: '.class_basename($e).' '.mb_substr($e->getMessage(), 0, 200), 0, $e);
        }

        if ($message->stopReason === 'refusal') {
            throw new TranslationFailed('Claude 가 이 묶음의 번역을 거절했어요. 상품명을 확인해 주세요.');
        }
        if ($message->stopReason === 'max_tokens') {
            throw new TranslationFailed('응답이 너무 길어 잘렸어요. 묶음을 줄여 다시 시도해 주세요.');
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        $out = json_decode($text, true)['translations'] ?? null;
        if (! is_array($out) || count($out) !== $count) {
            throw new TranslationFailed("번역 결과 개수가 맞지 않아요 ({$count}개 요청, ".(is_array($out) ? count($out) : 0).'개 응답).');
        }

        return array_map(fn ($s) => mb_substr(trim((string) $s), 0, 250), array_values($out));
    }
}
