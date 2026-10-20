/**
 * Tourfecto - مركز السوشيال ميديا الاحترافي (/social)
 * تبويبات: نظرة عامة / المنشورات والتقويم / صندوق التعليقات / الخطة الشهرية / الحسابات
 * محرر منشور: منشور أو ستوري، كاتب ذكي بـ 3 نسخ، هاشتاجات، أول تعليق، UTM، معاينة حية، فحص جودة، أفضل ميعاد.
 * كل النصوص ثنائية اللغة عبر T(ar, en) حسب لغة اللوحة.
 */
(function () {
    'use strict';
    const P = window.Panel;
    if (!P || !document.getElementById('spRoot')) return;
    const esc = P.esc, fetchJSON = P.fetchJSON;
    const AR = (document.documentElement.lang || 'ar').slice(0, 2) === 'ar';
    const T = (a, e) => (AR ? a : e);
    const $ = id => document.getElementById(id);
    const jsonPost = (url, body) => fetchJSON(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) });
    const nf = n => Number(n || 0).toLocaleString(AR ? 'ar-EG' : 'en-GB');
    const spLocal = s => (P.formatDate ? P.formatDate(s) : (s || '-'));
    const utc = s => new Date(String(s).replace(' ', 'T') + 'Z');
    const isVid = p => /\.(mp4|mov)$/i.test(p || '');

    const PF = { facebook: '📘', instagram: '📸', tiktok: '🎵', youtube: '▶️' };
    const PN = { facebook: 'Facebook', instagram: 'Instagram', tiktok: 'TikTok', youtube: 'YouTube' };
    const DAYS = AR ? ['الأحد', 'الاتنين', 'التلات', 'الأربع', 'الخميس', 'الجمعة', 'السبت'] : ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    const ST = {
        draft: ['pill', T('مسودة', 'Draft')], scheduled: ['pill', T('مجدول', 'Scheduled')], pending: ['pill', T('مجدول', 'Scheduled')],
        publishing: ['pill orange', T('بيتنشر…', 'Publishing…')], partial: ['pill orange', T('اتنشر جزئيًا', 'Partially published')],
        uncertain: ['pill orange', T('محتاج مراجعة', 'Needs review')], published: ['pill green', T('✔ اتنشر', '✔ Published')], failed: ['pill red', T('فشل', 'Failed')]
    };
    const pill = s => ST[s] ? '<span class="' + ST[s][0] + '">' + ST[s][1] + '</span>' : esc(s || '');
    // حدود النص لكل منصة (كابشن)
    const LIMITS = { facebook: 63206, instagram: 2200, tiktok: 2200, youtube: 5000 };

    // ================= التبويبات =================
    const tabs = document.querySelectorAll('.sp-tabs .p-tab');
    const loaded = {};
    function showTab(name) {
        if (!document.querySelector('.sp-panel[data-panel="' + name + '"]')) name = 'overview';
        tabs.forEach(b => { const on = b.dataset.tab === name; b.classList.toggle('active', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); });
        document.querySelectorAll('.sp-panel').forEach(p => { p.hidden = p.dataset.panel !== name; });
        if (!loaded[name]) {
            loaded[name] = true;
            if (name === 'posts') { load(); loadCalendar(); }
            if (name === 'inbox') loadInbox();
            if (name === 'accounts') loadAccounts();
        }
        if (location.hash.slice(1) !== name) history.replaceState(null, '', '#' + name);
    }
    tabs.forEach(b => b.addEventListener('click', () => showTab(b.dataset.tab)));

    // ================= المحرر =================
    let media = null;          // {id, file_path, is_video}
    let libLoaded = false;
    let albumMode = false, album = [];
    let connections = [];
    const format = () => ((document.querySelector('input[name=spFormat]:checked') || {}).value === 'story' ? 'story' : 'feed');
    const checkedTargets = () => Array.from(document.querySelectorAll('.targetCheckbox:checked'));
    const selectedPlatforms = () => [...new Set(checkedTargets().map(cb => cb.dataset.platform))];

    window.openNewPost = function (fmt) {
        const r = document.querySelector('input[name=spFormat][value="' + (fmt === 'story' ? 'story' : 'feed') + '"]');
        if (r) r.checked = true;
        if (!$('utmCampaign').value) { const d = new Date(); $('utmCampaign').value = 'social-' + d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0'); }
        $('newPostModal').classList.add('open');
        applyFormat();
        loadTargets();
    };
    window.closeComposer = () => $('newPostModal').classList.remove('open');
    window.refreshSocialTargets = async function () { await loadTargets(); P.toast(T('تم تحديث قائمة الحسابات', 'Accounts refreshed'), 'success'); };

    function applyFormat() {
        const story = format() === 'story';
        $('spComposerTitle').textContent = story ? T('ستوري جديدة', 'New story') : T('منشور جديد', 'New post');
        document.querySelectorAll('.sp-feed-only').forEach(el => { el.hidden = story; });
        document.querySelectorAll('.sp-story-only').forEach(el => { el.hidden = !story; });
        $('spWhenNum').textContent = story ? '3' : '4';
        $('spAlbumBtn').hidden = story;
        if (story && albumMode) { albumMode = false; setMedia(album[0] || media); }
        document.querySelectorAll('.targetCheckbox').forEach(cb => {
            const off = story && !['facebook', 'instagram'].includes(cb.dataset.platform);
            cb.disabled = off; if (off) cb.checked = false;
            cb.closest('label').classList.toggle('off', off);
        });
        refreshComposer();
    }

    function submitLabel() {
        const later = (document.querySelector('input[name=spWhen]:checked') || {}).value === 'later';
        const any = checkedTargets().length > 0;
        $('spSubmit').textContent = !any ? T('حفظ كمسودة', 'Save as draft') : (later ? T('جدولة', 'Schedule') : T('انشر دلوقتي', 'Publish now'));
    }

    document.addEventListener('change', e => {
        const el = e.target; if (!el) return;
        if (el.name === 'spFormat') applyFormat();
        if (el.name === 'spWhen') {
            const later = el.value === 'later';
            $('spAt').hidden = !later; $('spBestBtn').hidden = !later;
            if (later && !$('spAt').value) { const d = new Date(Date.now() + 3600e3); d.setMinutes(0, 0, 0); $('spAt').value = new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16); }
        }
        if (el.classList && el.classList.contains('targetCheckbox')) { renderTikTok(); }
        if (el.name === 'spWhen' || (el.classList && el.classList.contains('targetCheckbox'))) refreshComposer();
    });
    ['contentInput', 'hashtagsInput'].forEach(id => $(id).addEventListener('input', refreshComposer));

    // ---- الوسائط
    function paintMedia() {
        const prev = $('spPrev');
        prev.innerHTML = (!media ? '🖼️' : (media.is_video ? '<video src="' + esc(media.file_path) + '" muted playsinline></video>' : '<img src="' + esc(media.file_path) + '" alt="">'))
            + (album.length > 1 ? '<span class="sp-count">' + album.length + '</span>' : '');
        const strip = $('spAlbumStrip');
        strip.innerHTML = album.map((m, i) => '<span class="sp-alb"><img src="' + esc(m.file_path) + '" alt=""><b>' + (i + 1) + '</b><i data-i="' + i + '" title="' + T('شيل', 'Remove') + '">×</i></span>').join('');
        strip.hidden = !album.length;
        $('spClear').hidden = !(media || album.length);
        $('spAlbumBtn').classList.toggle('on', albumMode);
        document.querySelectorAll('#spLib button').forEach(b => b.classList.toggle('on', album.length ? album.some(a => a.id === +b.dataset.id) : (!!media && +b.dataset.id === media.id)));
        refreshComposer();
    }
    function setMedia(m) { media = m; album = []; paintMedia(); }
    function setAlbum(list) { album = list.slice(0, 10); media = album[0] || null; paintMedia(); }
    function toggleAlbumItem(item) {
        if (item.is_video) { P.toast(T('الصور المتعددة للصور بس - الفيديو بيتنشر لوحده', 'Multiple photos are images only — videos publish alone'), 'error'); return; }
        const i = album.findIndex(a => a.id === item.id);
        if (i >= 0) setAlbum(album.filter((_, k) => k !== i));
        else if (album.length >= 10) P.toast(T('الحد الأقصى 10 صور', 'Maximum 10 photos'), 'error');
        else setAlbum(album.concat([item]));
    }
    window.toggleAlbumMode = function () {
        albumMode = !albumMode;
        if (albumMode) { album = media && !media.is_video ? [media] : []; media = album[0] || null; }
        else { media = album[0] || media; album = []; }
        paintMedia();
        if (albumMode && $('spLib').hidden) window.toggleLib();
    };
    window.clearMedia = function () { albumMode = false; setMedia(null); $('spFile').value = ''; };
    document.addEventListener('click', e => {
        const x = e.target && e.target.closest ? e.target.closest('#spAlbumStrip i') : null;
        if (x) setAlbum(album.filter((_, k) => k !== +x.dataset.i));
    });
    $('spFile').addEventListener('change', async e => {
        const f = e.target.files && e.target.files[0];
        if (!f) return;
        const note = $('spMediaNote'), old = note.textContent;
        note.textContent = T('جارِ الرفع…', 'Uploading…');
        const fd = new FormData(); fd.append('file', f);
        const res = await fetchJSON('/api/social/media', { method: 'POST', body: fd });
        note.textContent = old;
        if (res.success) { const it = res.data.item; (albumMode && !it.is_video) ? toggleAlbumItem(it) : setMedia(it); P.toast(T('اترفعت ✓', 'Uploaded ✓'), 'success'); libLoaded = false; }
        else P.toast(res.error || T('فشل الرفع', 'Upload failed'), 'error');
    });
    window.toggleLib = async function () {
        const box = $('spLib');
        if (!box.hidden) { box.hidden = true; return; }
        box.hidden = false;
        if (libLoaded) return;
        box.innerHTML = '<span class="p-cell-muted">' + T('جارِ التحميل…', 'Loading…') + '</span>';
        const res = await fetchJSON('/api/creative-studio/media');
        const items = ((res.success && res.data.items) || []).filter(i => i.status === 'completed' && i.file_path);
        libLoaded = true;
        box.innerHTML = items.length ? items.map(i => '<button type="button" data-id="' + i.id + '" data-path="' + esc(i.file_path) + '" title="' + esc(i.prompt || '') + '">' + (isVid(i.file_path) || i.type === 'short_video' ? '<video src="' + esc(i.file_path) + '" muted></video>' : '<img loading="lazy" src="' + esc(i.thumbnail_path || i.file_path) + '" alt="">') + '</button>').join('')
            : '<span class="p-cell-muted">' + T('مفيش صور في المكتبة لسه. <a href="/creative-studio">صمّم من Creative Studio</a> أو ارفع من جهازك.', 'Your library is empty. <a href="/creative-studio">Create in Creative Studio</a> or upload.') + '</span>';
        box.querySelectorAll('button').forEach(b => b.addEventListener('click', () => { const it = { id: +b.dataset.id, file_path: b.dataset.path, is_video: isVid(b.dataset.path) || !!b.querySelector('video') }; albumMode ? toggleAlbumItem(it) : setMedia(it); }));
        paintMedia();
    };

    // ---- الصفحات
    async function loadTargets() {
        const box = $('targetsList');
        box.textContent = T('جارِ تحديث الصفحات المربوطة…', 'Refreshing connected pages…');
        const res = await fetchJSON('/api/social/connections?_=' + Date.now(), { cache: 'no-store' });
        if (!res.success) { box.innerHTML = '<div class="alert alert-danger">' + T('تعذر تحميل الصفحات: ', 'Could not load pages: ') + esc(res.error || '') + '</div>'; return; }
        connections = res.data.connections || [];
        const off = res.data.disconnected || [];
        const offHtml = off.length ? '<div class="alert alert-warning sp-small">⚠️ ' + T('صفحات محتاجة إعادة ربط: ', 'Pages that need reconnecting: ') + off.map(c => (PF[c.platform] || '') + ' <b>' + esc(c.name || c.platform) + '</b>').join(', ') + ' — <a href="/ads/connect/meta?for=social">' + T('اربط تاني', 'Reconnect') + '</a></div>' : '';
        if (connections.length) {
            box.innerHTML = '<div class="sp-chips">' + connections.map(c => '<label class="sp-tchip"><input type="checkbox" class="targetCheckbox" value="' + c.id + '" data-platform="' + esc(c.platform) + '" data-name="' + esc(c.name || '') + '"> ' + (PF[c.platform] || '•') + ' <span>' + esc(c.name || PN[c.platform] || c.platform) + '</span> <small>' + esc(PN[c.platform] || c.platform) + '</small></label>').join('') + '</div>' + offHtml;
        } else {
            box.innerHTML = '<div class="alert alert-warning">' + T('مفيش صفحات قابلة للنشر لسه. اربط حساباتك وانت بتختار الصفحات وبتوافق على كل الصلاحيات.', 'No publishable pages yet. Connect your accounts, select the pages and accept all permissions.') + '</div><div class="sp-row"><a class="p-btn primary sm" href="/ads/connect/meta?for=social">Facebook + Instagram</a><a class="p-btn outline sm" href="/social/connect/tiktok">TikTok</a><a class="p-btn outline sm" href="/social/connect/youtube">YouTube</a></div>' + offHtml;
        }
        applyFormat();
    }

    // ---- الكاتب الذكي
    let variants = [];
    window.generateCaption = async function () {
        const topic = $('topicInput').value.trim();
        if (!topic) { P.toast(T('اكتب موضوع المنشور الأول', 'Write the post topic first'), 'error'); $('topicInput').focus(); return; }
        const btn = $('aiCapBtn'); btn.disabled = true; const lbl = btn.textContent; btn.textContent = T('بيكتب…', 'Writing…');
        const res = await jsonPost('/api/social/generate-caption', { topic, platforms: selectedPlatforms(), tone: $('aiTone').value, goal: $('aiGoal').value, format: format(), website_id: P.getCurrentWebsiteId ? P.getCurrentWebsiteId() : null });
        btn.disabled = false; btn.textContent = lbl;
        if (!res.success) { P.toast(res.error || T('فشل التوليد', 'Generation failed'), 'error'); return; }
        variants = res.data.variants || (res.data.content ? [{ angle: '', content: res.data.content, hashtags: res.data.hashtags || [], first_comment: '' }] : []);
        const box = $('aiVariants');
        box.hidden = !variants.length;
        box.innerHTML = variants.map((v, i) => '<div class="sp-var"><div class="sp-var-h"><b>' + T('نسخة ', 'Version ') + (i + 1) + '</b>' + (v.angle ? ' · <span class="p-cell-muted">' + esc(v.angle) + '</span>' : '') + '<button type="button" class="p-btn primary xs" data-use="' + i + '">' + T('استخدم', 'Use') + '</button></div><div class="sp-var-t">' + esc(v.content) + '</div>' + (v.hashtags && v.hashtags.length ? '<div class="sp-var-tags">' + v.hashtags.map(h => '#' + esc(h)).join(' ') + '</div>' : '') + '</div>').join('');
        box.querySelectorAll('[data-use]').forEach(b => b.addEventListener('click', () => useVariant(+b.dataset.use)));
        if (!$('contentInput').value.trim()) useVariant(0, true);
    };
    function useVariant(i, silent) {
        const v = variants[i]; if (!v) return;
        $('contentInput').value = v.content;
        $('hashtagsInput').value = (v.hashtags || []).map(h => '#' + h).join(' ');
        if (v.first_comment && !$('firstCommentInput').value.trim()) $('firstCommentInput').value = v.first_comment;
        document.querySelectorAll('.sp-var').forEach((el, k) => el.classList.toggle('on', k === i));
        refreshComposer();
        if (!silent) P.toast(T('اتحطت النسخة - عدّل براحتك', 'Version applied — edit freely'), 'success');
    }

    // ---- هاشتاجات
    function hashtagList() {
        return [...new Set(($('hashtagsInput').value || '').split(/[\s,،]+/).map(h => h.replace(/^#+/, '').trim()).filter(h => /^[\p{L}\p{N}_]{1,60}$/u.test(h)))].slice(0, 30);
    }
    function finalText() {
        const tags = hashtagList();
        const c = $('contentInput').value.trim();
        return tags.length ? c + '\n\n' + tags.map(h => '#' + h).join(' ') : c;
    }

    // ---- المعاينة + فحص الجودة
    let previewPlatform = null;
    const CTA = /(احجز|حجز|تواصل|واتس|راسلنا|كلمنا|الخاص|اسأل|رابط|اللينك|سجّل|اطلب|اشترك|علّق|شارك|احفظ|book|reserve|contact|whatsapp|message|dm|link|call|comment|share|save|tag|sign up|learn more|visit)/i;
    function refreshComposer() {
        submitLabel();
        const story = format() === 'story';
        const plats = selectedPlatforms();
        const shown = plats.length ? plats : ['facebook', 'instagram'];
        if (!previewPlatform || !shown.includes(previewPlatform)) previewPlatform = shown[0];
        $('spPrevTabs').innerHTML = shown.map(p => '<button type="button" class="' + (p === previewPlatform ? 'on' : '') + '" data-pp="' + p + '">' + (PF[p] || '') + ' ' + esc(PN[p] || p) + '</button>').join('');
        $('spPrevTabs').querySelectorAll('[data-pp]').forEach(b => b.addEventListener('click', () => { previewPlatform = b.dataset.pp; refreshComposer(); }));

        const cb = checkedTargets().find(x => x.dataset.platform === previewPlatform);
        const name = cb ? cb.dataset.name : T('صفحتك', 'Your page');
        const text = finalText();
        const mediaHtml = album.length > 1
            ? '<div class="sp-mock-media"><img src="' + esc(album[0].file_path) + '" alt=""><span class="sp-count">1/' + album.length + '</span></div>'
            : (media ? '<div class="sp-mock-media' + (story ? ' story' : '') + '">' + (media.is_video ? '<video src="' + esc(media.file_path) + '" muted playsinline loop autoplay></video>' : '<img src="' + esc(media.file_path) + '" alt="">') + '</div>' : '');
        let body;
        if (story) {
            body = '<div class="sp-mock story-frame">' + (mediaHtml || '<div class="sp-mock-empty">9:16</div>') + '<div class="sp-mock-story-head"><span class="sp-av">' + esc((name || '?').charAt(0)) + '</span>' + esc(name) + '</div></div>';
        } else {
            const cut = previewPlatform === 'instagram' ? 125 : (previewPlatform === 'facebook' ? 480 : 150);
            const shortT = text.length > cut ? esc(text.slice(0, cut)) + '… <span class="p-cell-muted">' + T('المزيد', 'more') + '</span>' : esc(text);
            const caption = '<div class="sp-mock-text">' + (previewPlatform === 'instagram' ? '<b>' + esc(name) + '</b> ' : '') + (shortT || '<span class="p-cell-muted">' + T('النص هيظهر هنا', 'Your caption appears here') + '</span>') + '</div>';
            body = '<div class="sp-mock ' + previewPlatform + '"><div class="sp-mock-head"><span class="sp-av">' + esc((name || '?').charAt(0)) + '</span><div><b>' + esc(name) + '</b><small>' + T('دلوقتي', 'Just now') + '</small></div></div>'
                + (previewPlatform === 'instagram' || previewPlatform === 'tiktok' || previewPlatform === 'youtube' ? mediaHtml + caption : caption + mediaHtml) + '</div>';
        }
        $('spMock').innerHTML = body;

        // فحص الجودة
        const checks = [];
        const add = (ok, a, e) => checks.push('<li class="' + (ok === true ? 'ok' : ok === false ? 'bad' : 'warn') + '">' + (ok === true ? '✓' : ok === false ? '✕' : '!') + ' ' + T(a, e) + '</li>');
        const needsMedia = story || plats.some(p => p !== 'facebook');
        add(media ? true : (needsMedia ? false : null), 'صورة أو فيديو' + (needsMedia ? ' (إجباري هنا)' : ' (بتضاعف التفاعل)'), 'Image or video' + (needsMedia ? ' (required here)' : ' (boosts engagement)'));
        if (plats.some(p => p === 'tiktok' || p === 'youtube')) add(!!(media && media.is_video), 'تيك توك ويوتيوب فيديو بس', 'TikTok & YouTube need a video');
        if (!story) {
            const c = $('contentInput').value.trim();
            const first = c.split('\n')[0] || '';
            add(c.length > 0 ? (first.length <= 125 ? true : null) : false, 'أول سطر جذاب وقصير (≤125 حرف)', 'Short, catchy first line (≤125 chars)');
            add(CTA.test(c) ? true : null, 'دعوة واضحة لإجراء (احجز/تواصل/علّق)', 'Clear call to action (book/contact/comment)');
            const tags = hashtagList().length;
            if (plats.includes('instagram') || !plats.length) add(tags >= 3 && tags <= 15 ? true : null, 'هاشتاجات إنستجرام (3–8 مثالي): ' + tags, 'Instagram hashtags (3–8 ideal): ' + tags);
            const over = plats.filter(p => text.length > LIMITS[p]);
            add(over.length ? false : true, over.length ? 'النص أطول من المسموح على ' + over.map(p => PN[p]).join('، ') : 'الطول مناسب لكل المنصات', over.length ? 'Too long for ' + over.map(p => PN[p]).join(', ') : 'Length fits every platform');
            const counter = plats.length ? plats.map(p => (PF[p] || '') + ' ' + nf(text.length) + '/' + nf(LIMITS[p])).join(' · ') : nf(text.length) + ' ' + T('حرف', 'chars');
            $('spCounter').textContent = counter;
        } else if (album.length > 1) add(false, 'الستوري صورة أو فيديو واحد بس', 'A story takes a single image or video');
        add(plats.length ? true : null, plats.length ? 'هيتنشر على ' + plats.length + ' منصة' : 'مختارتش صفحة - هيتحفظ مسودة', plats.length ? 'Publishing to ' + plats.length + ' platform(s)' : 'No page selected — saves as draft');
        $('spChecklist').innerHTML = checks.join('');
    }

    // ---- أفضل ميعاد
    let bestCache = null;
    async function bestTimesData() {
        if (bestCache) return bestCache;
        const res = await fetchJSON('/api/social/best-times');
        if (res.success) bestCache = res.data;
        return bestCache;
    }
    window.pickBestTime = async function () {
        const d = await bestTimesData();
        if (!d) { P.toast(T('تعذر حساب أفضل ميعاد', 'Could not compute the best time'), 'error'); return; }
        const tz = d.timezone || 'UTC';
        const fmt = new Intl.DateTimeFormat('en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23', weekday: 'short' });
        const wd = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 };
        const parts = date => Object.fromEntries(fmt.formatToParts(date).map(p => [p.type, p.value]));
        const nowP = parts(new Date(Date.now() + 30 * 60e3));
        const nowKey = nowP.year + '-' + nowP.month + '-' + nowP.day + 'T' + nowP.hour + ':' + nowP.minute;
        for (let i = 0; i < 8; i++) {
            const p = parts(new Date(Date.now() + i * 86400e3));
            const hm = (d.by_day || {})[wd[p.weekday]] || '19:00';
            const cand = p.year + '-' + p.month + '-' + p.day + 'T' + hm;
            if (cand > nowKey) {
                $('spAt').value = cand;
                P.toast((d.source === 'measured' ? T('أفضل ميعاد من نتايجك: ', 'Best slot from your results: ') : T('ميعاد مرجعي لقطاع السياحة: ', 'Tourism benchmark slot: ')) + DAYS[wd[p.weekday]] + ' ' + hm, 'success');
                return;
            }
        }
    };

    // ---- تيك توك (شروط TikTok Content Posting API)
    let tt = null;
    const TT_PRIV = { PUBLIC_TO_EVERYONE: T('الكل (Public)', 'Everyone (Public)'), MUTUAL_FOLLOW_FRIENDS: T('الأصدقاء', 'Friends'), FOLLOWER_OF_CREATOR: T('المتابعين', 'Followers'), SELF_ONLY: T('أنا بس (Private)', 'Only me (Private)') };
    async function renderTikTok() {
        const panel = $('ttPanel');
        const cb = document.querySelector('.targetCheckbox[data-platform="tiktok"]:checked');
        if (!cb) { panel.hidden = true; tt = null; return; }
        panel.hidden = false;
        if (tt && tt.cid === cb.value) return;
        panel.innerHTML = '<span class="p-cell-muted">' + T('بنجيب بيانات حساب تيك توك…', 'Loading TikTok account…') + '</span>';
        const res = await fetchJSON('/api/social/tiktok/creator-info?connection_id=' + encodeURIComponent(cb.value));
        if (!res.success) { panel.innerHTML = '<div class="alert alert-danger">' + esc(res.error || T('تعذر الوصول لتيك توك', 'Could not reach TikTok')) + ' <a href="/social/connect/tiktok">' + T('اربط تيك توك تاني', 'Reconnect TikTok') + '</a></div>'; tt = null; return; }
        tt = Object.assign({ cid: cb.value }, res.data);
        const opt = (k, label, disabled) => '<label class="' + (disabled ? 'off' : '') + '"><input type="checkbox" id="tt_' + k + '"' + (disabled ? ' disabled' : '') + '> ' + label + (disabled ? ' <span class="p-cell-muted">' + T('(مقفولة من إعدادات حسابك)', '(disabled in your account settings)') + '</span>' : '') + '</label>';
        panel.innerHTML = '<div class="tt-head">' + (tt.avatar ? '<img src="' + esc(tt.avatar) + '" alt="" referrerpolicy="no-referrer">' : '🎵') + '<div><b>' + T('هيتنشر على تيك توك: ', 'Posting to TikTok: ') + esc(tt.nickname) + '</b>' + (tt.username ? '<div class="p-cell-muted" dir="ltr">@' + esc(tt.username) + '</div>' : '') + '</div></div>'
            + '<label class="sp-field">' + T('مين يقدر يشوف الفيديو؟', 'Who can view this video?') + '<select id="tt_privacy" class="p-select"><option value="">' + T('اختار…', 'Select…') + '</option>' + tt.privacy_level_options.map(o => '<option value="' + esc(o) + '">' + esc(TT_PRIV[o] || o) + '</option>').join('') + '</select></label>'
            + '<div class="tt-row">' + opt('comment', T('السماح بالتعليقات', 'Allow comments'), tt.comment_disabled) + opt('duet', T('السماح بالـ Duet', 'Allow Duet'), tt.duet_disabled) + opt('stitch', T('السماح بالـ Stitch', 'Allow Stitch'), tt.stitch_disabled) + '</div>'
            + '<label class="sp-check"><input type="checkbox" id="tt_commercial"> ' + T('الفيديو ده بيروّج لبراند أو منتج أو خدمة (Disclose commercial content)', 'This video promotes a brand, product or service (Disclose commercial content)') + '</label>'
            + '<div id="tt_comm_box" class="tt-sub" hidden><label class="sp-check"><input type="checkbox" id="tt_brand_organic"> ' + T('علامتك التجارية (Your brand) — هيظهر عليه "Promotional content"', 'Your brand — labelled "Promotional content"') + '</label><label class="sp-check"><input type="checkbox" id="tt_brand_content"> ' + T('محتوى مدفوع لبراند تاني (Branded content) — هيظهر عليه "Paid partnership"', 'Branded content — labelled "Paid partnership"') + '</label></div>'
            + '<div class="tt-note p-cell-muted" id="tt_consent"></div>'
            + (tt.max_video_post_duration_sec ? '<div class="tt-note p-cell-muted">' + T('أقصى مدة للفيديو على حسابك: ', 'Max video length on your account: ') + Math.floor(tt.max_video_post_duration_sec / 60) + T(' دقيقة. ممكن ياخد كام دقيقة لحد ما يظهر على البروفايل.', ' min. It may take a few minutes to appear on your profile.') + '</div>' : '');
        ttConsent();
    }
    function ttConsent() {
        const box = $('tt_consent'); if (!box) return;
        const bc = $('tt_brand_content') && $('tt_brand_content').checked;
        box.innerHTML = T('بالنشر انت موافق على ', 'By posting, you agree to TikTok\'s ') + (bc ? '<a href="https://www.tiktok.com/legal/page/global/bc-policy/en" target="_blank" rel="noopener">Branded Content Policy</a> ' + T('و', 'and ') : '') + '<a href="https://www.tiktok.com/legal/page/global/music-usage-confirmation/en" target="_blank" rel="noopener">Music Usage Confirmation</a>' + T(' الخاصة بتيك توك.', '.');
        const priv = $('tt_privacy');
        if (priv) { const self = [...priv.options].find(o => o.value === 'SELF_ONLY'); if (self) { self.disabled = !!bc; self.textContent = TT_PRIV.SELF_ONLY + (bc ? T(' — مش متاح مع المحتوى المدفوع', ' — not available for branded content') : ''); if (bc && priv.value === 'SELF_ONLY') priv.value = ''; } }
    }
    document.addEventListener('change', e => {
        const el = e.target; if (!el) return;
        if (el.id === 'tt_commercial') { $('tt_comm_box').hidden = !el.checked; if (!el.checked) { $('tt_brand_organic').checked = false; $('tt_brand_content').checked = false; } ttConsent(); }
        if (el.id === 'tt_brand_content' || el.id === 'tt_brand_organic') ttConsent();
    });
    function tikTokOptions() {
        if (!document.querySelector('.targetCheckbox[data-platform="tiktok"]:checked')) return { ok: true, value: null };
        if (!tt) return { ok: false, error: T('استنى لحد ما بيانات حساب تيك توك تظهر', 'Wait for the TikTok account details to load') };
        if (!media || !media.is_video) return { ok: false, error: T('تيك توك بيقبل فيديو بس - اختار فيديو', 'TikTok accepts video only — pick a video') };
        if (!$('tt_privacy').value) return { ok: false, error: T('اختار مين يقدر يشوف الفيديو على تيك توك', 'Choose who can view the video on TikTok') };
        const commercial = $('tt_commercial').checked;
        if (commercial && !$('tt_brand_organic').checked && !$('tt_brand_content').checked) return { ok: false, error: T('حدد نوع المحتوى التجاري، أو شيل علامة "بيروّج لبراند"', 'Choose the commercial content type, or untick "promotes a brand"') };
        const prev = document.querySelector('#spPrev video');
        if (prev && tt.max_video_post_duration_sec && prev.duration && prev.duration > tt.max_video_post_duration_sec) return { ok: false, error: T('الفيديو أطول من المسموح على حسابك في تيك توك', 'The video is longer than your TikTok account allows') };
        return { ok: true, value: { privacy_level: $('tt_privacy').value, allow_comment: !!($('tt_comment') || {}).checked, allow_duet: !!($('tt_duet') || {}).checked, allow_stitch: !!($('tt_stitch') || {}).checked, commercial, brand_organic: $('tt_brand_organic').checked, brand_content: $('tt_brand_content').checked } };
    }

    // ---- الإرسال
    window.createPost = async function () {
        const story = format() === 'story';
        const content = $('contentInput').value.trim();
        if (!story && !content) { P.toast(T('اكتب نص المنشور', 'Write the post text'), 'error'); $('contentInput').focus(); return; }
        const later = (document.querySelector('input[name=spWhen]:checked') || {}).value === 'later';
        const atVal = $('spAt').value;
        if (later && !atVal) { P.toast(T('اختار ميعاد النشر', 'Pick a publish time'), 'error'); return; }
        const checked = checkedTargets();
        const albumIds = !story && album.length >= 2 ? album.map(a => a.id) : null;
        if (story && !media) { P.toast(T('الستوري محتاجة صورة أو فيديو', 'A story needs an image or video'), 'error'); return; }
        if (albumIds && checked.some(cb => ['tiktok', 'youtube'].includes(cb.dataset.platform))) { P.toast(T('الصور المتعددة لفيسبوك وإنستجرام بس - شيل تيك توك/يوتيوب أو اختار صورة واحدة', 'Multiple photos are for Facebook & Instagram only — untick TikTok/YouTube or pick one image'), 'error'); return; }
        if (!media && checked.some(cb => cb.dataset.platform !== 'facebook')) { P.toast(T('إنستجرام وتيك توك ويوتيوب محتاجين صورة أو فيديو', 'Instagram, TikTok and YouTube need an image or video'), 'error'); return; }
        if (checked.some(cb => ['tiktok', 'youtube'].includes(cb.dataset.platform)) && !(media && media.is_video)) { P.toast(T('تيك توك ويوتيوب فيديو بس', 'TikTok and YouTube accept video only'), 'error'); return; }
        const ttOpts = story ? { ok: true, value: null } : tikTokOptions();
        if (!ttOpts.ok) { P.toast(ttOpts.error, 'error'); return; }
        const targets = checked.map(cb => ({ platform_connection_id: parseInt(cb.value, 10), scheduled_at: later ? atVal : null }));
        const body = {
            content: story ? (content || 'Story') : content, targets, post_format: format(),
            media_item_id: media ? media.id : null, media_item_ids: albumIds, tiktok: ttOpts.value,
            hashtags: story ? null : hashtagList(), first_comment: story ? null : $('firstCommentInput').value.trim(),
            utm_campaign: !story && $('utmOn').checked ? $('utmCampaign').value.trim() : '',
            website_id: P.getCurrentWebsiteId ? P.getCurrentWebsiteId() : null
        };
        const btn = $('spSubmit'); btn.disabled = true; const lbl = btn.textContent; btn.textContent = targets.length && !later ? T('بينشر…', 'Publishing…') : T('بيحفظ…', 'Saving…');
        const res = await jsonPost('/api/social/posts', body);
        btn.disabled = false; btn.textContent = lbl;
        if (!res.success) { P.toast(res.error || T('فشل الحفظ', 'Save failed'), 'error'); return; }
        closeComposer();
        const st = res.data.post && res.data.post.status;
        P.toast(!targets.length ? T('اتحفظ كمسودة', 'Saved as draft') : (later ? T('اتجدول ✔', 'Scheduled ✔') : st === 'published' ? T('اتنشر ✔', 'Published ✔') : st === 'failed' ? T('النشر فشل - شوف السبب في جدول المنشورات', 'Publishing failed — see the reason in Posts') : T('بيتنشر… هتلاقي النتيجة في جدول المنشورات', 'Publishing… check the result in Posts')), st === 'failed' ? 'error' : 'success');
        ['contentInput', 'topicInput', 'hashtagsInput', 'firstCommentInput'].forEach(id => { $(id).value = ''; });
        $('aiVariants').hidden = true; variants = [];
        clearMedia();
        loaded.posts = false; loadStats();
        if (!document.querySelector('.sp-panel[data-panel="posts"]').hidden) { load(); loadCalendar(); }
    };

    // ================= المنشورات =================
    let postsById = {};
    let autoRefreshed = false;
    function engCell(p) {
        const t = (p.targets || []).filter(x => x.status === 'published');
        if (!t.length) return '<span class="p-cell-muted">-</span>';
        return '<div class="sp-eng">' + t.map(x => {
            if (x.metrics_state === 'ok') return (PF[x.platform] || '•') + ' 👍 ' + nf(x.likes) + ' · 💬 ' + nf(x.comments) + ' · 🔁 ' + nf(x.shares) + (x.reach !== null && x.reach !== undefined ? ' · 👁 ' + nf(x.reach) : '');
            if (x.metrics_state === 'unavailable') return (PF[x.platform] || '•') + ' <span class="p-cell-muted" title="' + esc(x.metrics_note || '') + '">' + T('غير متاح', 'Unavailable') + '</span>';
            return (PF[x.platform] || '•') + ' <span class="p-cell-muted">' + (x.platform === 'facebook' || x.platform === 'instagram' ? T('بيتحدّث…', 'Updating…') : '-') + '</span>';
        }).join('<br>') + '</div>';
    }
    function targetsCell(p) {
        const t = p.targets || [];
        if (!t.length) return '<span class="p-cell-muted">' + T('مسودة', 'Draft') + '</span>';
        return t.map(x => '<div><span class="sp-tgt">' + (PF[x.platform] || '•') + ' ' + esc(x.name || x.platform) + ' ' + pill(x.status)
            + (x.status === 'published' && x.permalink ? ' <a href="' + esc(x.permalink) + '" target="_blank" rel="noopener">' + T('افتح ↗', 'Open ↗') + '</a>' : '')
            + (x.scheduled_at && x.status !== 'published' ? ' <span class="p-cell-muted">' + esc(spLocal(x.scheduled_at)) + '</span>' : '')
            + '</span>' + ((['failed', 'uncertain'].includes(x.status) || x.next_retry_at) && x.last_error ? '<div class="sp-err">' + esc(x.last_error) + '</div>' : '') + '</div>').join('');
    }
    function filterQS() {
        const q = new URLSearchParams();
        if ($('spQ').value.trim()) q.set('q', $('spQ').value.trim());
        if ($('spFStatus').value) q.set('status', $('spFStatus').value);
        if ($('spFPlat').value) q.set('platform', $('spFPlat').value);
        return q.toString();
    }
    let loadTimer = null;
    async function load() {
        clearTimeout(loadTimer);
        const res = await fetchJSON('/api/social/posts?' + filterQS());
        const tbody = document.querySelector('#postsTable tbody');
        if (res.success && res.data.posts && res.data.posts.length) {
            postsById = {};
            tbody.innerHTML = res.data.posts.map(p => {
                postsById[p.id] = p;
                const ts = p.targets || [];
                const failed = ts.some(x => x.status === 'failed'), uncertain = ts.some(x => x.status === 'uncertain'), busy = ts.some(x => x.status === 'publishing');
                const editable = (['draft', 'scheduled', 'failed'].includes(p.status) && !busy) || ts.some(x => x.status === 'published' && x.platform === 'facebook');
                const thumb = p.media_path ? ((p.media_type === 'short_video' || isVid(p.media_path)) ? '🎬 ' : '<img src="' + esc(p.media_path) + '" alt="" class="sp-thumb">') : '';
                return '<tr><td>' + thumb + esc((p.content || '').slice(0, 90)) + '</td><td>' + targetsCell(p) + '</td><td>' + engCell(p) + '</td><td>' + pill(p.status) + '</td><td class="p-cell-muted">' + esc(spLocal(p.created_at)) + '</td><td class="sp-actions">'
                    + (failed ? '<button class="p-btn outline xs" onclick="retryPost(' + p.id + ', this)">↻ ' + T('إعادة', 'Retry') + '</button> ' : '')
                    + (uncertain ? '<button class="p-btn outline xs" onclick="reconcilePost(' + p.id + ', this)" title="' + T('نسأل المنصة: المنشور اتنشر فعلًا؟', 'Ask the platform whether it was published') + '">🔍 ' + T('تحقق', 'Verify') + '</button> <button class="p-btn ghost xs" onclick="resolvePost(' + p.id + ')" title="' + T('حسم يدوي', 'Resolve manually') + '">✋</button> ' : '')
                    + (editable ? '<button class="p-btn ghost xs" onclick="editPost(' + p.id + ')" title="' + T('تعديل', 'Edit') + '">✏️</button>' : '')
                    + '<button class="p-btn ghost xs" onclick="duplicatePost(' + p.id + ')" title="' + T('نسخة كمسودة', 'Duplicate as draft') + '">📄</button>'
                    + (!busy ? '<button class="p-btn ghost xs" onclick="deletePost(' + p.id + ')" title="' + T('مسح', 'Delete') + '">🗑</button>' : '') + '</td></tr>';
            }).join('');
            if (res.data.posts.some(p => p.status === 'publishing')) loadTimer = setTimeout(load, 8000);
            const needs = res.data.posts.some(p => (p.targets || []).some(x => x.status === 'published' && (x.platform === 'facebook' || x.platform === 'instagram') && !x.metrics_at));
            if (needs && !autoRefreshed) { autoRefreshed = true; refreshMetrics(false); }
        } else {
            tbody.innerHTML = '<tr><td colspan="6" class="p-empty">' + (filterQS() ? T('مفيش نتايج بالفلتر ده', 'No results for this filter') : T('لسه مفيش منشورات. ابدأ بأول منشور من الزرار فوق.', 'No posts yet. Create your first one from the button above.')) + '</td></tr>';
        }
    }
    let qTimer = null;
    ['spFStatus', 'spFPlat'].forEach(id => $(id).addEventListener('change', load));
    $('spQ').addEventListener('input', () => { clearTimeout(qTimer); qTimer = setTimeout(load, 350); });

    async function loadCalendar() {
        const res = await fetchJSON('/api/social/calendar');
        const box = $('calendarGrid');
        const rows = (res.success && res.data.items) || [];
        const today = new Date(); today.setHours(0, 0, 0, 0);
        const start = new Date(today); start.setDate(start.getDate() - 7);
        start.setDate(start.getDate() - ((start.getDay() + 1) % 7)); // الأسبوع يبدأ السبت
        const key = d => d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate();
        const byDay = {};
        rows.forEach(r => { const d = utc(r.scheduled_at); if (!isNaN(d)) (byDay[key(d)] = byDay[key(d)] || []).push({ d, r }); });
        const order = [6, 0, 1, 2, 3, 4, 5];
        let html = order.map(n => '<div class="h">' + DAYS[n] + '</div>').join('');
        for (let i = 0; i < 42; i++) {
            const d = new Date(start); d.setDate(start.getDate() + i);
            const ev = (byDay[key(d)] || []).sort((a, b) => a.d - b.d);
            html += '<div class="d' + (+d === +today ? ' today' : '') + (d < today ? ' out' : '') + '"><div class="n">' + d.getDate() + (d.getDate() === 1 ? ' / ' + (d.getMonth() + 1) : '') + '</div>'
                + ev.slice(0, 3).map(x => '<span class="e ' + esc(x.r.status) + '" title="' + esc((x.r.content || '').slice(0, 120)) + '">' + (PF[x.r.platform] || '•') + ' ' + x.d.toLocaleTimeString(AR ? 'ar-EG-u-nu-latn' : 'en-GB', { hour: 'numeric', minute: '2-digit' }) + ' ' + esc((x.r.content || '').slice(0, 14)) + '</span>').join('')
                + (ev.length > 3 ? '<span class="p-cell-muted">+' + (ev.length - 3) + '</span>' : '') + '</div>';
        }
        box.innerHTML = html;
    }

    // ---- تعديل / نسخ / إعادة / حذف / مصالحة
    let editId = null, editMode = 'draft';
    window.editPost = function (id) {
        const p = postsById[id]; if (!p) return;
        editId = id;
        const ts = p.targets || [];
        editMode = ['draft', 'scheduled', 'failed'].includes(p.status) && !ts.some(x => x.status === 'published') ? 'draft' : 'published';
        $('editContent').value = p.content || '';
        $('editAt').value = '';
        $('editWhenBox').hidden = !(editMode === 'draft' && ts.some(x => x.status === 'scheduled'));
        $('editNote').textContent = editMode === 'published'
            ? T('التعديل هيتطبق على فيسبوك مباشرة (إنستجرام وتيك توك ما بيسمحوش بتعديل النص بعد النشر).', 'The edit is applied on Facebook directly (Instagram & TikTok do not allow editing after publishing).')
            : T('التعديل قبل النشر. المواعيد بتوقيت حسابك.', 'Editing before publishing. Times use your account timezone.');
        $('editPostModal').classList.add('open');
    };
    window.saveEdit = async function () {
        const content = $('editContent').value.trim();
        if (!content) { P.toast(T('اكتب نص المنشور', 'Write the post text'), 'error'); return; }
        const btn = $('editSubmit'); btn.disabled = true;
        const res = editMode === 'published'
            ? await jsonPost('/api/social/posts/' + editId + '/edit-published', { content })
            : await jsonPost('/api/social/posts/' + editId + '/update', { content, scheduled_at: $('editAt').value || '' });
        btn.disabled = false;
        if (res.success) { $('editPostModal').classList.remove('open'); P.toast(T('اتحفظ التعديل ✔', 'Saved ✔'), 'success'); load(); loadCalendar(); }
        else P.toast(res.error || T('تعذر الحفظ', 'Could not save'), 'error');
    };
    window.duplicatePost = async function (id) {
        const res = await jsonPost('/api/social/posts/' + id + '/duplicate');
        if (res.success) { P.toast(T('اتعملت نسخة كمسودة - عدّلها وانشرها', 'Duplicated as a draft'), 'success'); load(); loadStats(); } else P.toast(res.error || T('تعذر', 'Failed'), 'error');
    };
    window.retryPost = async function (id, btn) {
        if (btn) { btn.disabled = true; btn.textContent = T('بينشر…', 'Publishing…'); }
        const res = await jsonPost('/api/social/posts/' + id + '/retry');
        if (res.success) P.toast(res.data.status === 'published' ? T('اتنشر ✔', 'Published ✔') : res.data.status === 'failed' ? T('لسه فاشل - شوف السبب', 'Still failing — see the reason') : T('بيتنشر…', 'Publishing…'), res.data.status === 'failed' ? 'error' : 'success');
        else P.toast(res.error || T('تعذر', 'Failed'), 'error');
        load();
    };
    window.deletePost = async function (id) {
        const p = postsById[id] || {};
        const onFb = (p.targets || []).some(x => x.status === 'published' && x.platform === 'facebook');
        let remote = false;
        if (onFb) {
            remote = confirm(T('المنشور منشور على فيسبوك.\nOK = امسحه من فيسبوك ومن المنصة\nCancel = خيارات تانية', 'This post is live on Facebook.\nOK = delete it from Facebook and here\nCancel = more options'));
            if (!remote && !confirm(T('تمسحه من المنصة بس (هيفضل ظاهر على فيسبوك)؟', 'Delete it here only (it stays on Facebook)?'))) return;
        } else if (!confirm(T('مسح المنشور من المنصة؟ (لو اتنشر على إنستجرام هيفضل هناك - Meta ما بتسمحش بحذفه من هنا)', 'Delete this post here? (If it is on Instagram it stays there — Meta does not allow deleting it from here)'))) return;
        const res = await jsonPost('/api/social/posts/' + id + '/delete', { remote });
        if (res.success) { P.toast(T('اتمسح', 'Deleted'), 'success'); load(); loadCalendar(); loadStats(); } else P.toast(res.error || T('تعذر', 'Failed'), 'error');
    };
    window.reconcilePost = async function (id, btn) {
        if (btn) { btn.disabled = true; btn.textContent = '…'; }
        const res = await jsonPost('/api/social/posts/' + id + '/reconcile');
        if (res.success) { const r = (res.data.results || [])[0] || {}; P.toast(r.message || T('تم التحقق', 'Verified'), r.outcome === 'still_uncertain' ? 'error' : 'success'); }
        else P.toast(res.error || T('تعذر', 'Failed'), 'error');
        load();
    };
    window.resolvePost = async function (id) {
        const a = prompt(T('المنشور ظاهر على الصفحة؟\n1 = أيوه اتنشر\n2 = لأ ماتنشرش (هيتسمح بإعادة النشر)', 'Is the post visible on the page?\n1 = Yes, it was published\n2 = No (allows publishing again)'));
        const resolution = a === '1' ? 'published' : (a === '2' ? 'not_published' : null);
        if (!resolution) return;
        const res = await jsonPost('/api/social/posts/' + id + '/resolve', { resolution });
        if (res.success) P.toast(T('تم الحسم', 'Resolved'), 'success'); else P.toast(res.error || T('تعذر', 'Failed'), 'error');
        load();
    };

    // ================= نظرة عامة =================
    const HP_ERR = { token: T('ربط/توكن', 'Token'), permission: T('صلاحيات', 'Permissions'), rate_limit: T('حد استخدام', 'Rate limit'), blocked: T('حظر مؤقت', 'Temporarily blocked'), media: T('وسائط', 'Media'), param: T('بيانات المنشور', 'Post data'), transient: T('عطل مؤقت', 'Transient'), unknown: T('غير معروف', 'Unknown') };
    function healthHtml(pages) {
        if (!pages || !pages.length) return '';
        const relink = ' <a href="/ads/connect/meta?for=social">' + T('اربط تاني', 'Reconnect') + '</a>';
        const rows = pages.map(p => {
            const h = p.connection_health || {};
            const rate = p.success_rate;
            const cls = rate === null || rate === undefined ? '' : (rate >= 95 ? 'ok' : (rate >= 80 ? 'warn' : 'bad'));
            let conn = '';
            if (h.state === 'expired' || h.state === 'invalid') conn = '<span class="sp-hp bad">❌ ' + T('محتاج إعادة ربط', 'Needs reconnecting') + '</span>' + relink;
            else if (h.state === 'missing_permissions') conn = '<span class="sp-hp bad">⚠️ ' + T('صلاحيات ناقصة: ', 'Missing permissions: ') + esc(h.missing_scopes || '') + '</span>' + relink;
            else if (h.state === 'expiring') conn = '<span class="sp-hp warn">⏳ ' + T('الربط قرّب ينتهي', 'Connection expiring soon') + '</span>' + relink;
            else if (h.state === 'ok') conn = '<span class="sp-hp ok">✅ ' + T('الربط سليم', 'Connection healthy') + '</span>';
            const errs = Object.entries(p.top_errors || {}).slice(0, 3).map(([k, v]) => esc(HP_ERR[k] || k) + ' ×' + (+v)).join(AR ? '، ' : ', ');
            const stats = p.attempts > 0
                ? '<span class="sp-hp ' + cls + '">' + rate + '% ' + T('نجاح', 'success') + '</span> <span class="p-cell-muted">(' + (+p.attempts) + ' ' + T('محاولة', 'attempts') + (p.avg_publish_ms ? ' · ' + T('متوسط ', 'avg ') + (p.avg_publish_ms / 1000).toFixed(1) + T(' ث', 's') : '') + ')</span>'
                : '<span class="p-cell-muted">' + T('مفيش محاولات نشر في الفترة دي', 'No publishing attempts in this period') + '</span>';
            return '<div class="sp-hrow' + (p.alert ? ' alert' : '') + '"><span class="nm">' + (p.alert ? '🚨 ' : '') + esc(PN[p.platform] || p.platform || '') + ' · ' + esc(p.name || '') + '</span>' + stats + (errs ? ' <span class="p-cell-muted">' + T('أخطاء: ', 'Errors: ') + errs + '</span>' : '') + (conn ? ' ' + conn : '') + '</div>';
        }).join('');
        return '<div class="sp-pad"><b>🩺 ' + T('صحة النشر', 'Publishing health') + '</b>' + rows + '</div>';
    }
    async function loadHealth() {
        const box = $('spHealth');
        try {
            const res = await fetchJSON('/api/social/health?days=' + (+$('spDays').value || 30));
            const html = res.success ? healthHtml(res.data.pages) : '';
            box.innerHTML = html; box.hidden = !html;
        } catch (e) { box.hidden = true; }
    }
    async function loadBest() {
        const d = await bestTimesData();
        const box = $('spBest');
        if (!d) { box.textContent = '-'; return; }
        $('spBestSub').textContent = d.source === 'measured' ? T('من تفاعل منشوراتك الفعلي', 'From your real engagement') + ' (' + d.timezone + ')' : T('مواعيد مرجعية لحد ما يتجمع ' + d.min_measured + ' منشورات متقاسة', 'Benchmarks until ' + d.min_measured + ' measured posts');
        if (d.source === 'measured' && d.top && d.top.length) {
            box.innerHTML = d.top.map((x, i) => '<div class="sp-prow"><span class="sp-medal">' + ['🥇', '🥈', '🥉'][i] + '</span><b>' + DAYS[x.dow] + '</b><span>' + String(x.hour).padStart(2, '0') + ':00</span><span class="p-cell-muted sp-end">' + T('متوسط تفاعل ', 'avg ') + nf(x.avg) + ' · ' + nf(x.n) + T(' منشور', ' posts') + '</span></div>').join('');
        } else {
            const by = d.by_day || {};
            box.innerHTML = '<div class="sp-week">' + [0, 1, 2, 3, 4, 5, 6].map(i => '<span><b>' + DAYS[i] + '</b>' + esc(by[i] || '') + '</span>').join('') + '</div><p class="p-cell-muted sp-small">' + T('فعّل "الجدولة الذكية" في الخطة الشهرية عشان تتنشر في المواعيد دي تلقائيًا.', 'Turn on Smart timing in the monthly plan to publish at these times automatically.') + '</p>';
        }
    }
    async function loadStats() {
        loadHealth();
        const days = +$('spDays').value || 30;
        const res = await fetchJSON('/api/social/stats?days=' + days);
        const set = (id, v) => { const el = $(id); if (el) el.textContent = v; };
        if (!res.success) {
            ['stTotal', 'stPub', 'stSched', 'stFail', 'stReach', 'stLikes', 'stComments', 'stShares'].forEach(i => set(i, '–'));
            $('spPlat').innerHTML = '<span class="p-cell-muted">' + esc(res.error || T('تعذر تحميل الإحصائيات', 'Could not load statistics')) + '</span>';
            $('spTop').innerHTML = '';
            return;
        }
        const d = res.data, st = d.by_status || {}, e = d.engagement || {};
        set('stTotal', nf(d.total_posts)); set('stPub', nf(st.published)); set('stRate', d.success_rate !== null ? '(' + nf(d.success_rate) + '%)' : '');
        set('stSched', nf((st.scheduled || 0) + (st.publishing || 0)) + ' / ' + nf(st.draft)); set('stFail', nf(st.failed));
        const hasM = (e.measured || 0) > 0;
        set('stReach', hasM ? nf(e.reach) : '–'); set('stLikes', hasM ? nf(e.likes) : '–'); set('stComments', hasM ? nf(e.comments) : '–'); set('stShares', hasM ? nf(e.shares) : '–');
        $('spMetricsNote').textContent = e.last_fetch ? T('آخر تحديث للتفاعل: ', 'Engagement last updated: ') + spLocal(e.last_fetch) : T('إنستجرام لازم صورة أو فيديو. فيسبوك ينفع نص بس.', 'Instagram needs an image or video. Facebook accepts text only.');
        const ser = d.daily || [], max = Math.max(1, ...ser.map(x => x.count));
        $('spDaily').innerHTML = ser.map(x => '<i class="' + (x.count ? '' : 'z') + '" style="height:' + Math.max(2, Math.round(x.count / max * 100)) + '%" title="' + esc(x.date) + ': ' + x.count + '"></i>').join('');
        $('spDailyAxis').innerHTML = ser.length ? '<span>' + esc(ser[0].date.slice(5)) + '</span><span>' + esc(ser[ser.length - 1].date.slice(5)) + '</span>' : '';
        $('spDailySub').textContent = nf(ser.reduce((a, x) => a + x.count, 0)) + T(' نشر', ' published');
        const pl = Object.entries(d.by_platform || {});
        $('spPlat').innerHTML = pl.length ? pl.map(([k, v]) => {
            const tot = Math.max(1, v.total);
            return '<div class="sp-prow"><span class="nm">' + (PF[k] || '•') + ' ' + esc(PN[k] || k) + '</span><div class="sp-meter" title="' + T('اتنشر ', 'Published ') + v.published + ' · ' + T('فشل ', 'Failed ') + v.failed + ' · ' + T('مجدول ', 'Scheduled ') + v.scheduled + '"><b style="width:' + v.published / tot * 100 + '%;background:#22c55e"></b><b style="width:' + v.scheduled / tot * 100 + '%;background:#6366f1"></b><b style="width:' + v.failed / tot * 100 + '%;background:#ef4444"></b></div><span class="sp-num-r">' + nf(v.total) + '</span></div>';
        }).join('') + '<div class="p-cell-muted sp-small">🟢 ' + T('اتنشر', 'Published') + ' &nbsp;🟣 ' + T('مجدول', 'Scheduled') + ' &nbsp;🔴 ' + T('فشل', 'Failed') + '</div>' : '<span class="p-cell-muted">' + T('مفيش بيانات في الفترة دي', 'No data in this period') + '</span>';
        const top = d.top_posts || [];
        $('spTop').innerHTML = top.length ? top.map(x => '<div class="sp-prow"><span>' + (PF[x.platform] || '•') + '</span><span class="sp-ellipsis">' + esc(x.content) + '</span><span class="sp-eng">👍 ' + nf(x.likes) + ' · 💬 ' + nf(x.comments) + ' · 🔁 ' + nf(x.shares) + '</span></div>').join('') : '<span class="p-cell-muted">' + T('لسه مفيش بيانات تفاعل. اضغط «تحديث التفاعل من Meta».', 'No engagement data yet. Press "Refresh engagement from Meta".') + '</span>';
    }
    window.refreshMetrics = async function (manual) {
        const btn = $('spMetricsBtn'); const lbl = btn.textContent;
        if (manual) { btn.disabled = true; btn.textContent = T('جارِ السحب من Meta…', 'Fetching from Meta…'); }
        const res = await jsonPost('/api/social/metrics/refresh', { force: manual ? 1 : 0 });
        if (manual) { btn.disabled = false; btn.textContent = lbl; }
        if (res.success) {
            if (manual) P.toast(res.data.checked ? T('اتحدّث تفاعل ', 'Updated engagement for ') + res.data.updated + T(' منشور', ' posts') + (res.data.unavailable ? T(' (' + res.data.unavailable + ' غير متاح - غالبًا صلاحية read_insights ناقصة)', ' (' + res.data.unavailable + ' unavailable — usually a missing read_insights permission)') : '') : T('مفيش منشورات على فيسبوك/إنستجرام للتحديث', 'No Facebook/Instagram posts to refresh'), res.data.unavailable ? 'error' : 'success');
            loadStats(); if (loaded.posts) load();
        } else if (manual) P.toast(res.error || T('تعذر التحديث', 'Could not refresh'), 'error');
    };
    $('spDays').addEventListener('change', loadStats);

    // ================= الحسابات =================
    async function loadAccounts() {
        const box = $('spAccounts');
        const res = await fetchJSON('/api/social/connections?_=' + Date.now(), { cache: 'no-store' });
        if (!res.success) { box.textContent = res.error || T('تعذر التحميل', 'Could not load'); return; }
        const on = res.data.connections || [], off = res.data.disconnected || [];
        box.innerHTML = (on.length ? on.map(c => '<span class="sp-chip">' + (PF[c.platform] || '•') + ' ' + esc(c.name || c.platform) + ' <span class="sp-dot"></span></span>').join('') : '<span class="p-cell-muted">' + T('مفيش حسابات متصلة. اربط حساباتك من تحت.', 'No connected accounts. Connect below.') + '</span>')
            + off.map(c => '<span class="sp-chip off" title="' + esc(c.last_error || '') + '">' + (PF[c.platform] || '•') + ' ' + esc(c.name || c.platform) + ' — ' + T('محتاج إعادة ربط', 'needs reconnecting') + '</span>').join('');
    }

    // ================= صندوق التعليقات =================
    const INTENT = {
        price: ['🔥', T('سعر', 'Price'), 'hot'], booking: ['📅', T('حجز', 'Booking'), 'hot'], question: ['❓', T('سؤال', 'Question'), 'warm'],
        complaint: ['⚠️', T('شكوى', 'Complaint'), 'bad'], praise: ['💚', T('مدح', 'Praise'), 'ok'], spam: ['🚫', T('سبام', 'Spam'), 'mute'], other: ['💬', T('تعليق', 'Comment'), '']
    };
    async function loadInbox() {
        const list = $('ibList');
        const qs = new URLSearchParams({ status: $('ibStatus').value, intent: $('ibIntent').value });
        const res = await fetchJSON('/api/social/inbox?' + qs);
        if (!res.success) { list.innerHTML = '<div class="alert alert-danger">' + esc(res.error || T('تعذر التحميل', 'Could not load')) + '</div>'; return; }
        const d = res.data;
        paintBadge(d.counts);
        if (!d.installed) {
            list.innerHTML = '<div class="alert alert-warning">' + T('صندوق التعليقات محتاج تشغيل ملف قاعدة البيانات database/migrations/2026_10_20_000001_social_pro.sql مرة واحدة.', 'The comments inbox needs database/migrations/2026_10_20_000001_social_pro.sql to be run once.') + '</div>';
            return;
        }
        $('ibCounts').textContent = T('محتاجة رد: ', 'Need reply: ') + nf(d.counts.open) + (d.counts.hot ? T(' · منها عملاء محتملين/أسئلة: ', ' · leads/questions: ') + nf(d.counts.hot) : '');
        if (!d.comments.length) {
            list.innerHTML = '<div class="p-empty">' + ($('ibStatus').value === 'open' ? T('مفيش تعليقات محتاجة رد 🎉 اضغط «اسحب التعليقات الجديدة» للتأكد.', 'No comments waiting 🎉 Press "Fetch new comments" to check.') : T('مفيش تعليقات هنا.', 'No comments here.')) + '</div>';
            return;
        }
        list.innerHTML = d.comments.map(c => {
            const it = INTENT[c.intent] || INTENT.other;
            const done = c.status !== 'new';
            return '<article class="ib-item ' + (done ? 'done' : '') + '" data-id="' + c.id + '">'
                + '<div class="ib-head"><span class="ib-tag ' + it[2] + '">' + it[0] + ' ' + it[1] + '</span><b>' + esc(c.author_name || T('مستخدم', 'User')) + '</b><span class="p-cell-muted">' + (PF[c.platform] || '') + ' ' + esc(c.page_name || '') + ' · ' + esc(c.commented_at ? spLocal(c.commented_at) : '') + '</span>' + (c.like_count ? '<span class="p-cell-muted">👍 ' + nf(c.like_count) + '</span>' : '') + '</div>'
                + '<div class="ib-msg">' + esc(c.message) + '</div>'
                + (c.post_content ? '<div class="ib-post p-cell-muted">↳ ' + esc(c.post_content) + (c.permalink ? ' <a href="' + esc(c.permalink) + '" target="_blank" rel="noopener">' + T('افتح المنشور ↗', 'Open post ↗') + '</a>' : '') + '</div>' : '')
                + (c.status === 'replied' && c.reply_text ? '<div class="ib-reply-done">✔ ' + T('ردّك: ', 'Your reply: ') + esc(c.reply_text) + '</div>' : '')
                + (c.status === 'new' ? '<div class="ib-compose"><textarea class="p-select" rows="2" maxlength="2000" placeholder="' + T('اكتب ردك…', 'Write your reply…') + '">' + esc(c.ai_suggestion || '') + '</textarea><div class="sp-row">'
                    + '<button type="button" class="p-btn outline xs" data-ib="suggest">✨ ' + T('اقترح رد', 'Suggest reply') + '</button>'
                    + '<button type="button" class="p-btn primary xs" data-ib="reply">' + T('إرسال الرد', 'Send reply') + '</button>'
                    + '<button type="button" class="p-btn ghost xs" data-ib="hide" title="' + T('إخفاء التعليق من الجمهور (سبام/إساءة)', 'Hide from the public (spam/abuse)') + '">🙈 ' + T('إخفاء', 'Hide') + '</button>'
                    + '<button type="button" class="p-btn ghost xs" data-ib="done" title="' + T('اتعامل معاه (مثلًا رديت في الخاص)', 'Handled (e.g. answered privately)') + '">✓ ' + T('تم', 'Done') + '</button></div></div>' : '')
                + '</article>';
        }).join('');
    }
    function paintBadge(counts) {
        const b = $('spInboxBadge');
        const n = counts ? +counts.open : 0;
        b.hidden = !n; b.textContent = n > 99 ? '99+' : String(n);
        b.classList.toggle('hot', !!(counts && counts.hot));
    }
    $('ibList').addEventListener('click', async e => {
        const btn = e.target.closest('[data-ib]'); if (!btn) return;
        const item = btn.closest('.ib-item'); const id = item.dataset.id; const ta = item.querySelector('textarea');
        const act = btn.dataset.ib;
        btn.disabled = true;
        let res;
        if (act === 'suggest') {
            const lbl = btn.textContent; btn.textContent = T('بيفكر…', 'Thinking…');
            res = await jsonPost('/api/social/inbox/' + id + '/suggest');
            btn.textContent = lbl; btn.disabled = false;
            if (res.success) { ta.value = res.data.reply; ta.focus(); } else P.toast(res.error || T('تعذر', 'Failed'), 'error');
            return;
        }
        if (act === 'reply') {
            if (!ta.value.trim()) { btn.disabled = false; P.toast(T('اكتب الرد الأول', 'Write a reply first'), 'error'); return; }
            res = await jsonPost('/api/social/inbox/' + id + '/reply', { text: ta.value.trim() });
        } else if (act === 'hide') {
            if (!confirm(T('إخفاء التعليق؟ هيفضل ظاهر لصاحبه بس.', 'Hide this comment? Only its author will still see it.'))) { btn.disabled = false; return; }
            res = await jsonPost('/api/social/inbox/' + id + '/hide');
        } else {
            res = await jsonPost('/api/social/inbox/' + id + '/done');
        }
        btn.disabled = false;
        if (res.success) { P.toast(act === 'reply' ? T('اتبعت الرد ✔', 'Reply sent ✔') : act === 'hide' ? T('اتخفى', 'Hidden') : T('اتقفل', 'Closed'), 'success'); loadInbox(); }
        else P.toast(res.error || T('تعذر', 'Failed'), 'error');
    });
    ['ibStatus', 'ibIntent'].forEach(id => $(id).addEventListener('change', loadInbox));
    $('ibSync').addEventListener('click', async () => {
        const btn = $('ibSync'); const lbl = btn.textContent; btn.disabled = true; btn.textContent = T('بيسحب من Meta…', 'Fetching from Meta…');
        const res = await jsonPost('/api/social/inbox/sync');
        btn.disabled = false; btn.textContent = lbl;
        const note = $('ibNotice');
        if (!res.success) { P.toast(res.error || T('تعذر', 'Failed'), 'error'); return; }
        const r = res.data;
        note.innerHTML = r.needs_permission
            ? '<div class="alert alert-warning">' + T('Meta رفضت قراءة التعليقات: الصلاحيات ناقصة. شوف تبويب «الحسابات» ← «ليه صندوق التعليقات مش شغال؟».', 'Meta refused to read comments: missing permissions. See Accounts → "Why don\'t the comments inbox… work?".') + (Object.values(r.errors || {})[0] ? '<div class="p-cell-muted sp-small">' + esc(Object.values(r.errors)[0]) + '</div>' : '') + '</div>'
            : (Object.keys(r.errors || {}).length ? '<div class="alert alert-warning sp-small">' + esc(Object.values(r.errors).join(' | ')) + '</div>' : '');
        P.toast(T('اتفحص ', 'Checked ') + nf(r.checked) + T(' منشور · ', ' posts · ') + nf(r.new) + T(' تعليق جديد', ' new comments'), 'success');
        loadInbox();
    });

    // ================= البداية =================
    loadStats();
    loadBest();
    fetchJSON('/api/social/inbox?status=open').then(r => { if (r.success) paintBadge(r.data.counts); });
    const startTab = (location.hash || '').slice(1);
    showTab(startTab || 'overview');
    const qp = new URLSearchParams(location.search);
    if (qp.get('meta') === 'connected') { showTab('accounts'); P.toast(T('تم ربط الحسابات ✓', 'Accounts connected ✓'), 'success'); }
    if (qp.get('new') === 'story' || qp.get('new') === 'post') openNewPost(qp.get('new') === 'story' ? 'story' : 'feed');
})();
