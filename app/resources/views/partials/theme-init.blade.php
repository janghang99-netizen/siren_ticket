{{-- 화면을 그리기 전에 다크/라이트를 정한다 (깜빡임 없이). 기본은 다크, 고른 모드는 이 브라우저에 기억 --}}
<script>
    (function () {
        var t = 'dark';
        try { t = localStorage.getItem('theme') === 'light' ? 'light' : 'dark'; } catch (e) {}
        document.documentElement.dataset.theme = t;
        window.toggleTheme = function () {
            var next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
            document.documentElement.dataset.theme = next;
            try { localStorage.setItem('theme', next); } catch (e) {}
        };
    })();
</script>
