<?php

namespace App\Services\SeatMap;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Messages\Base64ImageSource;
use Anthropic\Messages\ImageBlockParam;
use Anthropic\Messages\TextBlockParam;

/**
 * 좌석 배치도 읽기 — Claude 비전. 범례(등급·색·가격·좌석 수)와 구역 박스(이미지 대비 비율 좌표)를 JSON 으로 받는다.
 * 박스 위치는 대략값이라 운영사가 편집 화면에서 끌어서 맞춘다.
 * API 키: 환경 변수 ANTHROPIC_API_KEY (config services.anthropic.key). 화면·로그에 키를 남기지 않는다.
 */
class ClaudeSeatMapReader implements SeatMapReader
{
    public const MODEL = 'claude-opus-5-5';

    private const PROMPT = <<<'TXT'
    This image is a seating chart for a concert, made by the promoter. Read it and fill in the JSON.

    tiers — the seat/price categories, usually shown in a legend (in legend order).
    - name: exactly as written (e.g. "Ground", "하층부 (1F)"). Two tiers may share a name but differ in color: keep both.
    - color: hex color (#RRGGBB) of that tier's swatch, matching the fill of its blocks on the map.
    - price: price in KRW as an integer (0 if not shown).
    - seats: number of seats listed for that tier (0 if not shown).
    If there is no legend, make one tier per distinct block color, named after the color.

    sections — every block on the map where the audience sits (the colored blocks).
    - code: the label printed on the block (A, B, E12, W1-1, N3 ...). Codes must be unique: if the same label
      appears on several blocks (for example "W1" on different floors), add "-2", "-3" ... to the later ones,
      going from the stage outward. If a block has no label, make one from the nearest labeled block plus a suffix.
      At most 10 characters.
    - tier: 0-based index into tiers of the tier whose color matches the block.
    - x, y, w, h: the block's bounding box as fractions of the whole image width and height (0 to 1);
      x, y is the top-left corner. Be as precise as you can.
    Do not include the stage, B-stage, FOH/mixing booths, gates, aisles, the legend, summary boxes or titles.

    map_area — the bounding box (fractions 0 to 1, x, y = top-left) of the venue drawing itself: the stage, every seat
    block and the gate/zone labels around it. Leave out the title, the legend/price list, summary or revenue boxes
    and any other panels, so that cropping the image to map_area shows only the venue.
    TXT;

    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'tiers' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'], 'color' => ['type' => 'string'],
                    'price' => ['type' => 'integer'], 'seats' => ['type' => 'integer'],
                ],
                'required' => ['name', 'color', 'price', 'seats'],
                'additionalProperties' => false,
            ]],
            'sections' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => [
                    'code' => ['type' => 'string'], 'tier' => ['type' => 'integer'],
                    'x' => ['type' => 'number'], 'y' => ['type' => 'number'],
                    'w' => ['type' => 'number'], 'h' => ['type' => 'number'],
                ],
                'required' => ['code', 'tier', 'x', 'y', 'w', 'h'],
                'additionalProperties' => false,
            ]],
            'map_area' => [
                'type' => 'object',
                'properties' => ['x' => ['type' => 'number'], 'y' => ['type' => 'number'], 'w' => ['type' => 'number'], 'h' => ['type' => 'number']],
                'required' => ['x', 'y', 'w', 'h'],
                'additionalProperties' => false,
            ],
        ],
        'required' => ['tiers', 'sections', 'map_area'],
        'additionalProperties' => false,
    ];

    public function __construct(private readonly ?string $apiKey) {}

    public function ready(): bool
    {
        return (string) $this->apiKey !== '';
    }

    public function read(string $image, string $mediaType): array
    {
        if (! $this->ready()) {
            throw new SeatMapReadFailed('ANTHROPIC_API_KEY 가 설정되지 않았어요. 구역을 직접 그려 주세요.');
        }

        try {
            $client = new Client(apiKey: $this->apiKey);
            $message = $client->messages->create(
                model: self::MODEL,
                maxTokens: 16000,
                messages: [['role' => 'user', 'content' => [
                    ImageBlockParam::with(source: Base64ImageSource::with(data: base64_encode($image), mediaType: $mediaType)),
                    TextBlockParam::with(text: self::PROMPT),
                ]]],
                outputConfig: ['format' => ['type' => 'json_schema', 'schema' => self::SCHEMA]],
            );
        } catch (AnthropicException $e) {
            throw new SeatMapReadFailed('Claude API 호출 실패: '.class_basename($e).' '.mb_substr($e->getMessage(), 0, 200), 0, $e);
        }

        if ($message->stopReason === 'refusal') {
            throw new SeatMapReadFailed('이 이미지는 읽지 못했어요. 구역을 직접 그려 주세요.');
        }
        if ($message->stopReason === 'max_tokens') {
            throw new SeatMapReadFailed('구역이 너무 많아 결과가 잘렸어요. 이미지를 나눠 올리거나 직접 그려 주세요.');
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        $out = json_decode($text, true);
        if (! is_array($out) || ! is_array($out['tiers'] ?? null) || ! is_array($out['sections'] ?? null)) {
            throw new SeatMapReadFailed('읽은 결과 형식이 맞지 않아요. 다시 시도하거나 직접 그려 주세요.');
        }

        return ['tiers' => $out['tiers'], 'sections' => $out['sections'], 'map_area' => is_array($out['map_area'] ?? null) ? $out['map_area'] : null];
    }
}
