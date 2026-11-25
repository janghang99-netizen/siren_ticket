{{--
    번역 — 원본 다담다 방식 (html/uasns_header2.html · allmenu_btn_login2.html)
     1) 화면 전체: 구글 번역 위젯 (한국어 → English / 日本語 / 中文)
     2) 상품명(.product-name, 원문은 data-ko): Claude 로 미리 번역한 사전 (/translate/dict/{lang}) 으로 바꾸고
        구글이 다시 건드리지 않게 translate="no". 사전에 없는 상품명은 구글에 맡긴다.
     3) 교정 사전: 구글이 틀리게 옮긴 말을 화면에서 바로 고친다 (원본 FIX_DICT)
     4) 첫 방문: 브라우저 언어로 자동 선택 (lang_auto_done 쿠키, 이미 고른 언어가 있으면 건너뜀)
     5) 구글이 막힌 곳(중국 본토 등): 위젯 없이도 상품명·교정 사전은 적용
--}}
<div id="google_translate_element" class="hidden" aria-hidden="true"></div>
<style>
    /* 구글 위젯의 상단 배너·툴팁 숨김 */
    body > .skiptranslate, .goog-te-banner-frame, #goog-gt-tt, .goog-te-balloon-frame { display: none !important; }
    body { top: 0 !important; }
    .goog-text-highlight { background: none !important; box-shadow: none !important; }
</style>
<script>
(function () {
    var DICT_URL = @json(url('/translate/dict'));
    var DICT_LANG = { en: 'en', ja: 'ja', 'zh-CN': 'zh' };   // 구글 언어 코드 → 사전 언어
    var NO_TRANSLATE = ['.translation-links'];                // 번역하지 않을 곳 (원본과 같이 언어 버튼만)
    var PRODUCT_SEL = '.product-name';                        // 상품명
    var dict = null, dictLang = null, timer = null;

    function getCookie(n) { var m = document.cookie.match('(?:^|; )' + n + '=([^;]*)'); return m ? decodeURIComponent(m[1]) : ''; }
    function setCookie(n, v, days) {
        var exp = days ? '; expires=' + new Date(Date.now() + days * 864e5).toUTCString() : '';
        document.cookie = n + '=' + v + exp + '; path=/; SameSite=Lax';
    }
    function clearGoogtrans() {
        // 구글 위젯은 googtrans 를 상위 도메인(.mycafe24.com 등)에도 심는다. 그것까지 지우지 않으면
        // 옛 언어 쿠키가 남아 두 번째 전환부터 먹지 않는다 → 호스트와 모든 상위 도메인에서 지운다.
        var past = '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/', parts = location.hostname.split('.');
        document.cookie = 'googtrans' + past;
        for (var i = 0; i < parts.length - 1; i++) {
            var d = parts.slice(i).join('.');
            document.cookie = 'googtrans' + past + '; domain=' + d;
            document.cookie = 'googtrans' + past + '; domain=.' + d;
        }
    }

    // 지금 언어: googtrans 쿠키 (/ko/en) 기준
    function current() {
        var m = getCookie('googtrans').match(/^\/ko\/(.+)$/);
        return m && DICT_LANG[m[1]] ? m[1] : 'ko';
    }

    function lookup(ko) {
        if (!dict || !ko) return null;
        if (dict.products[ko]) return dict.products[ko];
        // 장바구니 "상품 · 옵션" 처럼 합쳐진 이름: 앞쪽 상품명만이라도
        var i = ko.indexOf(' · ');
        if (i > 0 && dict.products[ko.slice(0, i)]) return dict.products[ko.slice(0, i)] + ko.slice(i);
        return null;
    }

    function applyProducts(lang) {
        document.querySelectorAll(PRODUCT_SEL).forEach(function (el) {
            var ko = el.getAttribute('data-ko');
            if (ko === null) return;
            var tr = lang === 'ko' ? null : lookup(ko);
            if (tr) {
                if (el.textContent !== tr) el.textContent = tr;
                el.setAttribute('translate', 'no'); el.classList.add('notranslate');
            } else if (el.classList.contains('notranslate')) {
                // 사전에서 빠졌거나 한국어로 돌아옴 → 원문으로 되돌리고 구글에 맡긴다
                el.textContent = ko;
                el.removeAttribute('translate'); el.classList.remove('notranslate');
            }
        });
    }

    function applyFixes(lang) {
        if (!dict || !dict.fixes.length || lang === 'ko') return;
        var skip = NO_TRANSLATE.concat([PRODUCT_SEL, 'script', 'style', 'textarea']).join(',');
        var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null), node;
        while ((node = walker.nextNode())) {
            if (!node.nodeValue.trim() || (node.parentElement && node.parentElement.closest(skip))) continue;
            var v = node.nodeValue;
            dict.fixes.forEach(function (f) { if (v.indexOf(f[0]) !== -1) v = v.split(f[0]).join(f[1]); });
            if (v !== node.nodeValue) node.nodeValue = v;
        }
    }

    function apply() {
        var lang = current();
        document.querySelectorAll(NO_TRANSLATE.join(',')).forEach(function (el) {
            el.setAttribute('translate', 'no'); el.classList.add('notranslate');
        });
        applyProducts(lang);
        applyFixes(lang);
        var notice = document.getElementById('translate-notice');
        if (notice) notice.hidden = lang === 'ko';
        document.querySelectorAll('.translation-links [data-lang]').forEach(function (a) {
            var on = a.dataset.lang === lang;
            a.classList.toggle('bg-ink', on); a.classList.toggle('text-white', on); a.classList.toggle('text-mist', !on);
            if (on) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current');
        });
    }

    function schedule() { clearTimeout(timer); timer = setTimeout(apply, 150); }

    function loadDict() {
        var lang = current();
        if (lang === 'ko') { dict = null; dictLang = null; return; }
        var dl = DICT_LANG[lang];
        if (dictLang === dl) return;
        dictLang = dl;
        try {
            var c = JSON.parse(sessionStorage.getItem('siren_dict_' + dl) || 'null');
            if (c && c.t > Date.now() - 6e5) { dict = c.d; schedule(); return; }
        } catch (e) {}
        fetch(DICT_URL + '/' + dl, { headers: { Accept: 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d) return;
                dict = d; schedule();
                try { sessionStorage.setItem('siren_dict_' + dl, JSON.stringify({ t: Date.now(), d: d })); } catch (e) {}
            })
            .catch(function () {});
    }

    // 언어 바꾸기 — googtrans 쿠키를 바꾸고 다시 연다. 구글 위젯은 열릴 때 쿠키의 언어로 번역한다.
    // (원본은 위젯 선택 상자에 change 를 보냈지만, 한 번 번역을 되돌린 뒤엔 반응하지 않는 경우가 있어 다시 열기로 통일)
    // 구글이 막힌 곳에서도 다시 열면 쿠키 언어로 상품명·교정 사전이 적용된다.
    window.sirenApplyLang = function (lang) {
        if (lang === current()) return;
        clearGoogtrans();
        if (lang !== 'ko') setCookie('googtrans', '/ko/' + lang);
        location.reload();
    };

    window.googleTranslateElementInit = function () {
        new google.translate.TranslateElement({ pageLanguage: 'ko', includedLanguages: 'en,ja,zh-CN', autoDisplay: true }, 'google_translate_element');
    };

    // 첫 방문: 브라우저 언어로 자동 선택
    function autoLang() {
        if (getCookie('lang_auto_done') || getCookie('googtrans')) return;
        setCookie('lang_auto_done', '1', 365);
        var nav = ((navigator.languages && navigator.languages[0]) || navigator.language || 'ko').toLowerCase();
        var lang = nav.indexOf('ko') === 0 ? 'ko' : nav.indexOf('ja') === 0 ? 'ja' : nav.indexOf('zh') === 0 ? 'zh-CN' : 'en';
        if (lang !== 'ko') window.sirenApplyLang(lang);
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (current() !== 'ko') document.documentElement.lang = current();
        document.querySelectorAll('.translation-links [data-lang]').forEach(function (a) {
            a.addEventListener('click', function (e) { e.preventDefault(); window.sirenApplyLang(a.dataset.lang); });
        });
        autoLang();
        loadDict();
        apply();
        new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true, characterData: true });
    });
})();
</script>
<script src="https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit" async></script>
