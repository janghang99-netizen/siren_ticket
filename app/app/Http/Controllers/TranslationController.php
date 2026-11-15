<?php

namespace App\Http\Controllers;

use App\Models\TranslationFix;
use App\Services\Audit;
use App\Services\Translation\ProductTranslationService;
use App\Services\Translation\TranslationFailed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * 번역 (원본 다담다 방식)
 *  - 공개: /translate/dict/{lang} — 화면 스크립트가 받아 상품명·오역을 바꾼다 (ajax_translate_dict.php)
 *  - 운영사: /admin/translations — 현황·연결 테스트·일괄 번역·교정 사전 (admin/translate_products.php)
 */
class TranslationController extends Controller
{
    public function __construct(private readonly ProductTranslationService $translations) {}

    public function dict(string $lang): JsonResponse
    {
        $d = $this->translations->dict($lang);

        return response()->json(['products' => (object) $d['products'], 'fixes' => $d['fixes']], 200, [], JSON_UNESCAPED_UNICODE)
            ->header('Cache-Control', 'public, max-age=600');
    }

    public function index(): View
    {
        return view('admin.translations', [
            'langs' => ProductTranslationService::LANGS,
            'status' => $this->translations->status(),
            'ready' => $this->translations->ready(),
            'model' => \App\Services\Translation\ClaudeProductNameTranslator::MODEL,
            'fixes' => TranslationFix::orderBy('lang')->orderBy('wrong')->get(),
        ]);
    }

    public function test(Request $request): RedirectResponse
    {
        $lang = $request->validate(['lang' => ['required', Rule::in(array_keys(ProductTranslationService::LANGS))]])['lang'];
        try {
            $sample = $this->translations->test($lang);
        } catch (TranslationFailed $e) {
            return back()->withErrors(['translate' => $e->getMessage()]);
        }

        return back()->with('status', '연결 테스트 성공 ('.ProductTranslationService::LANGS[$lang].')')->with('sample', $sample);
    }

    public function run(Request $request): RedirectResponse
    {
        $lang = $request->validate(['lang' => ['required', Rule::in(array_keys(ProductTranslationService::LANGS))]])['lang'];
        if (! $this->translations->ready()) {
            return back()->withErrors(['translate' => 'ANTHROPIC_API_KEY 가 설정되지 않았어요. 서버 환경 변수에 넣은 뒤 다시 시도해 주세요.']);
        }
        $r = $this->translations->run($lang);
        Audit::log('translation.run', null, ProductTranslationService::LANGS[$lang]." 상품명 {$r['translated']}개 번역", ['lang' => $lang, 'errors' => $r['errors']]);

        $back = back()->with('status', ProductTranslationService::LANGS[$lang]." 상품명 {$r['translated']}개를 번역했어요.");

        return $r['errors'] ? $back->withErrors(['translate' => implode(' / ', $r['errors'])]) : $back;
    }

    public function fixStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'lang' => ['required', Rule::in(array_keys(ProductTranslationService::LANGS))],
            'wrong' => ['required', 'string', 'max:200'],
            'right' => ['required', 'string', 'max:200', 'different:wrong'],
        ]);
        $fix = TranslationFix::updateOrCreate(
            ['lang' => $data['lang'], 'wrong' => trim($data['wrong'])],
            ['right' => trim($data['right']), 'user_id' => $request->user()->id],
        );
        $this->translations->forget($data['lang']);
        Audit::log('translation.fix_saved', $fix, "교정 사전 [{$data['lang']}] {$fix->wrong} → {$fix->right}");

        return back()->with('status', '교정 사전에 넣었어요. 새로 여는 화면부터 바로 적용돼요.');
    }

    public function fixDestroy(TranslationFix $fix): RedirectResponse
    {
        $fix->delete();
        $this->translations->forget($fix->lang);
        Audit::log('translation.fix_deleted', $fix, "교정 사전 [{$fix->lang}] {$fix->wrong} 삭제");

        return back()->with('status', '교정 사전에서 뺐어요.');
    }
}
