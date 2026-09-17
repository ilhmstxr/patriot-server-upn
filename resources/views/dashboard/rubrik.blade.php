<x-layouts.dashboard>
    <x-slot:title>Form Rubrik</x-slot:title>

    <div x-data="{
        answers: {},
        links: {},
        categories: [],
        loading: true,
        isSaving: false,
        lastSaved: '',
        status: '',
        is_edit_enabled: true,
        lock_reason: '',
        profil: {},
        saveTimers: {},
        saveStatus: {},

        {{-- Cache key untuk lazy loading --}}
        cacheKey: 'rubrik_questions_cache',

        {{-- Toast global state --}}
        toast: { show: false, type: 'info', title: '', message: '' },
        toastTimer: null,
        showToast(type, title, message, duration = 3000) {
            this.toast = { show: true, type, title, message };
            clearTimeout(this.toastTimer);
            if (type !== 'error' && duration > 0) {
                this.toastTimer = setTimeout(() => this.toast.show = false, duration);
            }
        },

        {{-- Floating Drawer State --}}
        drawerOpen: false,

        {{-- Flag State (persisted in sessionStorage) --}}
        flags: {},
       

        toggleFlag(questionId) {
            if (!this.is_edit_enabled) return;
            this.flags[questionId] = !this.flags[questionId];
            try { sessionStorage.setItem('rubrik_flags', JSON.stringify(this.flags)); } catch(e) {}
        },

        isFlagged(questionId) {
            return !!this.flags[questionId];
        },

        isAnswered(questionId) {
            const ans = this.answers[questionId];
            return ans !== null && ans !== undefined && ans !== '';
        },

        scrollToQuestion(qId) {
            const catIdx = this.categories.findIndex(c => c.questions.some(q => q.id == qId));
            if (catIdx !== -1) this.openCategories[catIdx] = true;

            this.$nextTick(() => {
                const el = document.getElementById('q-' + qId);
                if (el) {
                    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    el.classList.add('ring-2', 'ring-[#1b5e20]', 'ring-offset-2');
                    setTimeout(() => el.classList.remove('ring-2', 'ring-[#1b5e20]', 'ring-offset-2'), 1500);
                }
            });
            this.drawerOpen = false;
        },

        get allQuestions() {
            return this.categories.flatMap(c => c.questions);
        },

        get totalAnswered() {
            return this.allQuestions.filter(q => this.isAnswered(q.id)).length;
        },

        get totalFlagged() {
            return this.allQuestions.filter(q => this.isFlagged(q.id)).length;
        },

        async init() {
            {{-- Restore flags from sessionStorage --}}
            try {
                const savedFlags = sessionStorage.getItem('rubrik_flags');
                if (savedFlags) this.flags = JSON.parse(savedFlags);
            } catch(e) {}

            {{-- Load cache jika ada → render LANGSUNG tanpa loading --}}
            const cached = this.readCache();
            if (cached && cached.data) {
                this.applyData(cached.data);
                this.loading = false;

                {{-- Silent version check di background --}}
                this.checkVersionAndRefresh(cached.version);
            } else {
                {{-- Akses pertama: tampilkan loading + fetch full --}}
                this.loading = true;
                await this.fetchAndCache(true);
            }
        },

        readCache() {
            try {
                const raw = localStorage.getItem(this.cacheKey);
                return raw ? JSON.parse(raw) : null;
            } catch (e) { return null; }
        },

        writeCache(data, version) {
            try {
                localStorage.setItem(this.cacheKey, JSON.stringify({
                    data, version, savedAt: Date.now()
                }));
            } catch (e) {}
        },

        applyData(data) {
            this.status = data.status;
            this.is_edit_enabled = data.is_edit_enabled;

            const lockedStatuses = ['SUBMITTED', 'GRADED', 'PUBLISHED'];
            if (lockedStatuses.includes(data.status)) {
                this.is_edit_enabled = false;
            }

            this.lock_reason = data.lock_reason || '';
            this.profil = data.profil || {};
            this.answers = {};
            this.links = {};
            this.categories = this.groupByCategory(data.questions);
            this.initOpenCategories();

            // Pre-initialize all question keys for Alpine.js reactivity
            // This ensures fillStatus() triggers reactive updates in the Floating Quiz Drawer
            this.allQuestions.forEach(q => {
                if (!(q.id in this.answers)) {
                    this.answers[q.id] = '';
                }
                if (!(q.id in this.links)) {
                    this.links[q.id] = '';
                }
            });

            this.$nextTick(() => {
                this.allQuestions
                    .filter(q => q.type === 'otomatis_sistem')
                    .forEach(q => {
                        const val = this.computeAutomatic(q);
                        if (val !== null && (this.answers[q.id] === undefined || this.answers[q.id] === null || this.answers[q.id] === '')) {
                            this.answers[q.id] = val;
                            this.scheduleAutoSave(q.id);
                        }
                    });
                if (window.lucide) window.lucide.createIcons();
            });
        },

        async checkVersionAndRefresh(currentVersion) {
            try {
                const res = await fetch('/api/penugasan/peserta/questions/version', {
                    headers: {
                        'Authorization': 'Bearer ' + localStorage.getItem('auth_token'),
                        'Accept': 'application/json'
                    }
                });
                if (!res.ok) return;
                const result = await res.json();

                if (result.success && result.data.penugasan_status) {
                    const lockedStatuses = ['SUBMITTED', 'GRADED', 'PUBLISHED'];
                    if (lockedStatuses.includes(result.data.penugasan_status)) {
                        this.status = result.data.penugasan_status;
                        this.is_edit_enabled = false;
                        this.lock_reason = 'Formulir dikunci karena data sudah disubmit.';
                        localStorage.removeItem(this.cacheKey);
                    }
                }

                if (result.success && result.data.version && result.data.version !== currentVersion) {
                    await this.fetchAndCache(false);
                }
            } catch (e) { /* silent */ }
        },

        async fetchAndCache(showLoading) {
            if (showLoading) this.loading = true;
            try {
                const response = await fetch('/api/penugasan/peserta/questions', {
                    headers: {
                        'Authorization': 'Bearer ' + localStorage.getItem('auth_token'),
                        'Accept': 'application/json'
                    }
                });

                if (response.status === 401) {
                    localStorage.removeItem(this.cacheKey);
                    window.location.href = '/masuk';
                    return;
                }

                const result = await response.json();
                if (result.success) {
                    this.applyData(result.data);

                    {{-- Versi ikut di payload → data & versi selalu dari momen yang sama --}}
                    this.writeCache(result.data, result.data.version);
                }
            } catch (error) {
                console.error('Gagal mengambil data pertanyaan:', error);
                if (showLoading) this.showToast('error', 'Gagal memuat rubrik', 'Periksa koneksi internet Anda.');
            } finally {
                if (showLoading) this.loading = false;
            }
        },

        groupByCategory(data) {
            const groups = {};
            data.forEach(item => {
                const category = item.kategori || { nama_kategori: 'Tanpa Kategori' };
                const catName = category.nama_kategori;
                
                if (!groups[catName]) {
                    groups[catName] = {
                        category: catName,
                        weight: 0,
                        questions: []
                    };
                }

                const existingJawaban = item.jawaban && item.jawaban.length > 0 ? item.jawaban[0] : null;
                if (existingJawaban) {
                    if (item.tipe === 'pilihan_ganda') {
                        this.answers[item.id] = existingJawaban.jawaban_id;
                    } else {
                        {{-- jawaban_teks dari API bisa berupa object/array (karena cast 'array' di model Laravel) --}}
                        {{-- Ekstrak raw_input jika object, agar input tidak menampilkan [object Object] --}}
                        const jt = existingJawaban.jawaban_teks;
                        const isMultiScale = item.kode_pertanyaan === 'B.13' || item.kode_pertanyaan === 'C.10';
                        if (jt && typeof jt === 'object') {
                            if (isMultiScale) {
                                // Simpan seluruh object sebagai JSON string agar init bisa restore
                                this.answers[item.id] = JSON.stringify(jt);
                            } else if (jt.raw_input !== undefined) {
                                this.answers[item.id] = jt.raw_input;
                            } else {
                                this.answers[item.id] = jt;
                            }
                        } else if (typeof jt === 'string') {
                            try {
                                const parsed = JSON.parse(jt);
                                if (isMultiScale) {
                                    // Simpan seluruh JSON string asli untuk restore multi-scale
                                    this.answers[item.id] = jt;
                                } else {
                                    this.answers[item.id] = parsed.raw_input !== undefined ? parsed.raw_input : jt;
                                }
                            } catch(e) {
                                this.answers[item.id] = jt;
                            }
                        } else {
                            this.answers[item.id] = jt;
                        }
                    }
                    this.links[item.id] = existingJawaban.tautan_bukti_drive || '';
                }

                groups[catName].questions.push({
                    id: item.id,
                    code: item.kode_pertanyaan,
                    title: item.teks_pertanyaan,
                    evidenceRequirementsRaw: item.kebutuhan_bukti,
                    evidenceRequirements: (function(val) {
                        if (Array.isArray(val)) return val;
                        if (typeof val === 'string') return val.split(',').map(s => s.trim()).filter(s => s !== '');
                        return val ? [val] : [];
                    })(item.kebutuhan_bukti),
                    type: item.tipe,
                    keterangan: item.keterangan || '',
                    options: item.tipe === 'pilihan_ganda'
                        ? (item.OpsiJawaban || item.opsi_jawaban || []).map(opt => ({
                            id: opt.id,
                            text: opt.keterangan || opt.opsi_jawaban || opt.OpsiJawaban
                        }))
                        : []
                });
                
                groups[catName].weight += 5;
            });

            return Object.values(groups);
        },

        {{-- ================================================================= --}}
        {{-- 🟢 FILL STATUS — 0:kosong, 1:setengah (1 dari 2), 2:penuh (keduanya) --}}
        {{-- ================================================================= --}}
        fillStatus(qId) {
            const hasAnswer = this.isAnswered(qId);
            const hasLink   = !!(this.links[qId] && String(this.links[qId]).trim() !== '');
            if (hasAnswer && hasLink) return 2;
            if (hasAnswer || hasLink) return 1;
            return 0;
        },

        {{-- ================================================================= --}}
        {{-- 💾 DEBOUNCE AUTO-SAVE PER INDIKATOR                              --}}
        {{-- ================================================================= --}}
        isValidDriveLink(url) {
            if (!url || url.trim() === '') return true;
            return url.includes('drive.google.com') || url.includes('docs.google.com');
        },

        isHtml(val) {
            if (typeof val !== 'string') return false;
            return /<[a-z][\s\S]*>/i.test(val);
        },

        scheduleAutoSave(qId) {
            const url = this.links[qId];
            if (url && url.trim() !== '' && !this.isValidDriveLink(url)) {
                this.saveStatus[qId] = 'invalid_link';
                this.showToast('warning', 'Tautan tidak valid', 'Gunakan tautan Google Drive yang dapat diakses publik.');
                return;
            }

            clearTimeout(this.saveTimers[qId]);
            this.saveStatus[qId] = 'saving';
            this.saveTimers[qId] = setTimeout(() => this.autoSave(qId), 800);
        },

        async autoSave(qId) {
            const question = this.allQuestions.find(q => q.id == qId);
            if (!question) { this.saveStatus[qId] = 'error'; return; }

            const isMulti = question.type === 'pilihan_ganda';
            const rawAns  = this.answers[qId];

            let textAns = !isMulti ? (rawAns != null ? String(rawAns) : null) : null;
            if (!isMulti && question.type === 'isian_singkat') {
                {{-- B.13 & C.10 sudah disimpan sebagai JSON lengkap oleh saveB13()/saveC10() --}}
                if (question.code === 'B.13' || question.code === 'C.10') {
                    textAns = (typeof rawAns === 'string') ? rawAns : JSON.stringify({ raw_input: rawAns, calculated_percentage: null });
                } else {
                    const formula = this.computeFormula(question);
                    const analysis = this.computeAnalysis(question);
                    if (formula) {
                        textAns = JSON.stringify({
                            raw_input: rawAns,
                            calculated_percentage: formula.persen,
                            label: formula.label
                        });
                    } else if (analysis) {
                        textAns = JSON.stringify({
                            raw_input: rawAns,
                            calculated_percentage: null,
                            label: analysis.label
                        });
                    } else {
                        {{-- isian_singkat tanpa formula: simpan nilai mentah saja sebagai JSON minimal --}}
                        textAns = JSON.stringify({ raw_input: rawAns, calculated_percentage: null });
                    }
                }
            }
            {{-- otomatis_sistem: simpan sebagai JSON minimal agar konsisten dengan parsing di atas --}}
            if (!isMulti && question.type === 'otomatis_sistem') {
                textAns = JSON.stringify({ raw_input: rawAns, calculated_percentage: null });
            }

            const payload = {
                pertanyaan_id: parseInt(qId),
                jawaban_id:    isMulti ? (rawAns != null && rawAns !== '' ? parseInt(rawAns) : null) : null,
                jawaban_teks:  textAns,
                tautan_bukti:  this.links[qId] && String(this.links[qId]).trim() !== '' ? this.links[qId] : null,
            };

            try {
                const res = await fetch('/api/penugasan/peserta/save-answer', {
                    method: 'POST',
                    headers: {
                        'Authorization': 'Bearer ' + localStorage.getItem('auth_token'),
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify(payload)
                });
                const result = await res.json();
                if (res.ok && result.success) {
                    this.saveStatus[qId] = 'saved';
                    {{-- Invalidate cache agar version check selanjutnya sinkron --}}
                    localStorage.removeItem(this.cacheKey);
                } else if (res.status === 403) {
                    this.is_edit_enabled = false;
                    this.lock_reason = result.message || 'Formulir dikunci.';
                    localStorage.removeItem(this.cacheKey);
                    this.showToast('error', 'Formulir Dikunci', this.lock_reason, 0);
                    this.saveStatus[qId] = 'error';
                } else {
                    this.saveStatus[qId] = 'error';
                }
                if (this.saveStatus[qId] === 'saved') {
                    setTimeout(() => { if (this.saveStatus[qId] === 'saved') this.saveStatus[qId] = ''; }, 2000);
                }
            } catch(e) {
                this.saveStatus[qId] = 'error';
            }
        },

        {{-- ================================================================= --}}
        {{-- 📐 FORMULA PREVIEW (isian_singkat yang memiliki pembagi profil)   --}}
        {{-- Formula: nilai_input / denominator * 100                          --}}
        {{-- ================================================================= --}}
        computeFormula(q) {
            const val = parseFloat(this.answers[q.id]);
            if (isNaN(val) || val < 0) return null;

            const map = {
                'B.6':  { varKey: 'jml_dosen',       label: 'total dosen' },
                'B.7':  { varKey: 'jml_prodi',       label: 'total prodi' },
                'B.18': { varKey: 'jml_ormawa',      varKey2: 'jml_ukm', label: 'total ormawa & UKM' },
                'B.20': { varKey: 'jml_agama_aktif', label: 'jenis agama yang ada' },
                'C.5':  { varKey: 'jml_mahasiswa',   label: 'total mahasiswa' },
                'C.7':  { varKey: 'jml_mahasiswa',   label: 'total mahasiswa' },
                'C.9':  { varKey: 'jml_mahasiswa',   label: 'total mahasiswa' },
            };

            const entry = map[q.code];
            if (!entry) return null;

            let denom = 0;
            if (q.code === 'B.18') {
                const ormawa = parseFloat(this.profil.jml_ormawa) || 0;
                const ukm = parseFloat(this.profil.jml_ukm) || 0;
                denom = ormawa + ukm;
            } else {
                denom = parseFloat(this.profil[entry.varKey]) || 0;
            }

            if (denom <= 0) return null;

            const persen = (val / denom) * 100;

            return {
                {{-- Nilai > 0 tampil minimal 0,01% — 0,00% terbaca seperti "Tidak ada" --}}
                persen: (val > 0 ? Math.max(persen, 0.01) : 0).toFixed(2),
                label: entry.label,
            };
        },

        {{-- ================================================================= --}}
        {{-- 📊 ANALYSIS PREVIEW (Evaluasi Otomatis untuk C.2)                 --}}
        {{-- ================================================================= --}}
        computeAnalysis(q) {
            const val = parseFloat(this.answers[q.id]);
            if (isNaN(val) || val < 0) return null;

            if (q.code === 'C.2') {
                const f = parseFloat(this.profil.jml_fakultas) || 0;
                const p = parseFloat(this.profil.jml_prodi) || 0;
                
                if (val === 0) return { label: 'Tidak ada', color: 'text-gray-700', bg: 'bg-gray-100' };
                if (val > 0 && val < f) return { label: 'jumlah mahasiswa < jumlah fakultas', color: 'text-orange-600', bg: 'bg-orange-50' };
                if (val > 0 && val === f) return { label: 'jumlah mahasiswa = jumlah fakultas PT', color: 'text-blue-700', bg: 'bg-blue-100' };
                if (val > f && val < p) return { label: 'jumlah mahasiswa yang terlibat > jumlah fakultas dan < dibandingkan jumlah prodi PT', color: 'text-emerald-700', bg: 'bg-emerald-100' };
                if (val > 0 && val === p) return { label: 'jumlah mahasiswa yang terlibat sama banyak dibandingkan jumlah prodi PT', color: 'text-blue-600', bg: 'bg-blue-50' };
                if (val > p) return { label: 'jumlah mahasiswa yang terlibat lebih banyak dibandingkan jumlah prodi PT', color: 'text-purple-700', bg: 'bg-purple-100' };
            }
            return null;
        },

        {{-- ================================================================= --}}
        {{-- ⚙️ AUTO-FILL OTOMATIS SISTEM (C.6 → jml_ukm dari profil)       --}}
        {{-- ================================================================= --}}
        computeAutomatic(q) {
            const map = {
                'C.6': () => this.profil.jml_ukm != null ? String(this.profil.jml_ukm) : null,
            };
            const fn = map[q.code];
            return fn ? fn() : null;
        },

        async saveDraft() {
            this.isSaving = true;
            
            const answersPayload = [];
            for (const qId in this.answers) {
                const question = this.allQuestions.find(q => q.id == qId);
                const isMulti = question && question.type === 'pilihan_ganda';
                const rawAns = this.answers[qId];
                
                let textAns = !isMulti ? (rawAns != null ? String(rawAns) : null) : null;
                if (question && question.type === 'isian_singkat') {
                    if (question.code === 'B.13' || question.code === 'C.10') {
                        textAns = (typeof rawAns === 'string') ? rawAns : JSON.stringify({ raw_input: rawAns, calculated_percentage: null });
                    } else {
                        const formula = this.computeFormula(question);
                        const analysis = this.computeAnalysis(question);
                        if (formula) {
                            textAns = JSON.stringify({
                                raw_input: rawAns,
                                calculated_percentage: formula.persen,
                                label: formula.label
                            });
                        } else if (analysis) {
                            textAns = JSON.stringify({
                                raw_input: rawAns,
                                calculated_percentage: null,
                                label: analysis.label
                            });
                        } else {
                            textAns = JSON.stringify({ raw_input: rawAns, calculated_percentage: null });
                        }
                    }
                }
                if (question && question.type === 'otomatis_sistem') {
                    textAns = JSON.stringify({ raw_input: rawAns, calculated_percentage: null });
                }

                answersPayload.push({
                    pertanyaan_id: parseInt(qId),
                    jawaban_id: isMulti && rawAns != null && rawAns !== '' ? parseInt(rawAns) : null,
                    jawaban_teks: textAns,
                    tautan_bukti: this.links[qId] || null
                });
            }

            try {
                const response = await fetch('/api/penugasan/peserta/save-draft', {
                    method: 'POST',
                    headers: {
                        'Authorization': 'Bearer ' + localStorage.getItem('auth_token'),
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ answers: answersPayload })
                });
                
                const result = await response.json();
                if (response.ok && result.success) {
                    let d = new Date();
                    this.lastSaved = d.getHours().toString().padStart(2, '0') + ':' + d.getMinutes().toString().padStart(2, '0');
                    sessionStorage.removeItem('rubrik_data_cache');
                    sessionStorage.removeItem('hasil_data_cache');
                    sessionStorage.removeItem('rubrik_flags'); {{-- Clear flags on submit --}}
                    this.flags = {};
                    localStorage.removeItem(this.cacheKey); {{-- Invalidate cache pertanyaan --}}
                    this.showToast('success', 'Draft tersubmit', 'Seluruh jawaban berhasil dikirim untuk direview.');
                    this.status = 'SUBMITTED';
                    setTimeout(() => { Livewire.navigate('/dashboard/hasil'); }, 1200);
                } else {
                    this.showToast('error', 'Gagal submit', result.message || 'Periksa kembali jawaban Anda.');
                }
            } catch (error) {
                this.showToast('error', 'Kesalahan jaringan', 'Tidak dapat terhubung ke server.');
            } finally {
                this.isSaving = false;
            }
        },

        openCategories: {},

        initOpenCategories() {
            this.categories.forEach((_, idx) => { this.openCategories[idx] = {{ config('rubrik.accordion_default_open', false) ? 'true' : 'false' }}; });
        },

        toggleCategory(idx) {
            this.openCategories[idx] = !this.openCategories[idx];
        },

        isCategoryOpen(idx) {
            return this.openCategories[idx] !== false;
        }
    }" class="bg-[#f5f5f5] min-h-full font-['Plus_Jakarta_Sans',sans-serif]">

        {{-- Scrollable content area --}}
        <div class="py-5 px-4 md:px-8">
            <div class="max-w-[960px] mx-auto">

                {{-- Form title --}}
                <h1 class="font-bold text-[#1d293d] text-[18px] uppercase tracking-wide mb-5">Form Rubrik</h1>

                <div class="space-y-6">
                    {{-- Banner Lock Timeline --}}
                    <template x-if="!loading && !is_edit_enabled">
                        <div class="bg-red-50 border border-red-200 rounded-lg p-4 flex items-start gap-3 shadow-sm">
                            <div class="w-8 h-8 rounded-full bg-red-100 flex items-center justify-center shrink-0">
                                <i data-lucide="lock" class="w-4 h-4 text-red-600"></i>
                            </div>
                            <div class="flex-1">
                                <p class="text-[14px] font-bold text-red-800">Formulir Dikunci</p>
                                <p class="text-[12px] text-red-700 mt-0.5" x-text="lock_reason || 'Mohon maaf, periode pengisian rubrik saat ini sedang ditutup atau sudah berakhir.'"></p>
                            </div>
                        </div>
                    </template>

                    {{-- Loading State (konsisten) --}}
                    <template x-if="loading">
                        <div>
                            <x-dashboard.loading
                                title="Memuat Form Rubrik..."
                                caption="Mohon tunggu sebentar, sistem sedang menyiapkan pertanyaan rubrik Anda." />
                        </div>
                    </template>

                    <template x-if="!loading">
                        <template x-for="(categoryData, cIdx) in categories" :key="cIdx">
                            <div class="space-y-4">
                            {{-- Category Header --}}
                            <button type="button"
                                @click="toggleCategory(cIdx)"
                                class="w-full flex items-center justify-between border-b border-[#e0e0e0] pb-2 cursor-pointer group">
                                <div class="flex items-center gap-2">
                                    <svg xmlns="http://www.w3.org/2000/svg"
                                         class="w-4 h-4 text-[#62748e] transition-transform duration-300"
                                         :class="isCategoryOpen(cIdx) ? 'rotate-90' : 'rotate-0'"
                                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="9 18 15 12 9 6"/>
                                    </svg>
                                    <h2 class="text-[15px] font-bold text-[#1d293d] uppercase tracking-wide group-hover:text-[#1b5e20] transition-colors" x-text="categoryData.category"></h2>
                                </div>
                                <span class="text-[12px] font-semibold text-[#62748e]" x-text="'Bobot: ' + categoryData.weight"></span>
                            </button>

                            {{-- Questions (Accordion Content) --}}
                            <div x-show="isCategoryOpen(cIdx)"
                                 x-transition:enter="transition ease-out duration-200"
                                 x-transition:enter-start="opacity-0"
                                 x-transition:enter-end="opacity-100"
                                 x-transition:leave="transition ease-in duration-150"
                                 x-transition:leave-start="opacity-100"
                                 x-transition:leave-end="opacity-0"
                                 class="space-y-4">
                            <template x-for="q in categoryData.questions" :key="q.id">
                                {{-- 🏷️ Question Card — id anchor for scroll targeting + relative for flag ribbon --}}
                                <div :id="'q-' + q.id"
                                     class="relative border rounded-lg overflow-hidden transition-all duration-300"
                                     :class="isFlagged(q.id) ? 'border-red-500 ring-1 ring-red-200' : 'border-[#e0e0e0]'"
                                     :style="'background-color:' + ['#ffffff','#f7faf7','#fdfcf9'][cIdx % 3]">

                                    {{-- ===== 🔖 Bookmark Flag ===== --}}
                                    <button type="button"
                                        @click.stop="toggleFlag(q.id)"
                                        :disabled="!is_edit_enabled"
                                        :title="!is_edit_enabled ? 'Tidak dapat diubah' : (isFlagged(q.id) ? 'Hapus flag' : 'Tandai pertanyaan ini')"
                                        class="absolute top-0 right-4 z-10 focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed"
                                        style="width: 24px;">
                                        {{-- Classic bookmark: rectangle + single V-notch at bottom --}}
                                        <span class="block w-full transition-all duration-300"
                                            :style="isFlagged(q.id)
                                                ? 'height:35px; background:#ef4444; clip-path:polygon(0 0,100% 0,100% 100%,50% 80%,0 100%); box-shadow:0 6px 14px rgba(239,68,68,0.45);'
                                                : 'height:20px; background:#cbd5e1; clip-path:polygon(0 0,100% 0,100% 100%,50% 75%,0 100%); box-shadow:none;'">
                                        </span>
                                    </button>

                                    <div class="flex flex-col md:flex-row divide-y md:divide-y-0 md:divide-x divide-[#e0e0e0]">

                                        {{-- LEFT: Question + Evidence --}}
                                        <div class="md:w-[45%] p-5 space-y-4 shrink-0">
                                            {{-- Code + Title --}}
                                            <div class="flex gap-3">
                                                <div class="w-[28px] h-[28px] rounded bg-[#f5f5f5] border border-[#e0e0e0] flex items-center justify-center font-bold text-[#1d293d] text-[12px] shrink-0 mt-0.5"
                                                     x-text="q.code"></div>
                                                <h3 class="font-bold text-[#1d293d] text-[13px] leading-snug pr-6" x-text="q.title"></h3>
                                            </div>

                                            {{-- Evidence requirements --}}
                                            <div class="bg-[#fafafa] border border-[#e0e0e0] rounded p-3 space-y-1.5">
                                                <p class="text-[10px] font-bold text-[#62748e] uppercase tracking-wider mb-2">Syarat Bukti:</p>
                                                <template x-if="isHtml(q.evidenceRequirementsRaw)">
                                                    <div class="text-[12px] font-medium text-[#62748e] leading-snug richtext-content" x-html="q.evidenceRequirementsRaw"></div>
                                                </template>
                                                <template x-if="!isHtml(q.evidenceRequirementsRaw)">
                                                    <div class="space-y-1.5">
                                                        <template x-for="(req, rIdx) in q.evidenceRequirements" :key="rIdx">
                                                            <div class="flex gap-2 items-start">
                                                                <span class="shrink-0 mt-1.5 w-1 h-1 bg-[#90a1b9] rounded-full"></span>
                                                                <span class="text-[12px] font-medium text-[#62748e] leading-snug" x-text="req"></span>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>

                                        {{-- RIGHT: Answers + Link --}}
                                        <div class="flex-1 p-5 space-y-4">
                                            <div class="flex items-center justify-between mb-1">
                                                <p class="text-[10px] font-bold text-[#62748e] uppercase tracking-wider">Jawaban:</p>
                                                {{-- Inline indicator kecil (toast global menangani notifikasi besar) --}}
                                                <span x-show="saveStatus[q.id] === 'saving'" style="display:none;" class="text-[10px] text-orange-500 font-medium inline-flex items-center gap-1">
                                                    <i data-lucide="loader-2" class="w-3 h-3 animate-spin"></i> Menyimpan
                                                </span>
                                                <span x-show="saveStatus[q.id] === 'saved'" style="display:none;" class="text-[10px] text-[#1b5e20] font-semibold inline-flex items-center gap-1">
                                                    <i data-lucide="check" class="w-3 h-3"></i> Tersimpan
                                                </span>
                                            </div>

                                            {{-- Pilihan Ganda --}}
                                            <template x-if="q.type === 'pilihan_ganda'">
                                                <div class="space-y-2">
                                                    <template x-for="(opt, oIdx) in q.options" :key="oIdx">
                                                        <button
                                                            type="button"
                                                            :disabled="!is_edit_enabled || status === 'SUBMITTED' || status === 'GRADED'"
                                                            @click="answers[q.id] = opt.id; scheduleAutoSave(q.id)"
                                                            :class="answers[q.id] === opt.id
                                                                ? 'bg-[#e8f5e9] border-[#1b5e20] text-[#1b5e20] font-semibold'
                                                                : 'bg-white border-[#e0e0e0] text-[#45556c] font-medium hover:border-[#b0b0b0]'"
                                                            class="w-full text-left px-3.5 py-2.5 rounded border text-[12px] leading-snug transition-colors whitespace-pre-line"
                                                            x-text="opt.text">
                                                        </button>
                                                    </template>
                                                </div>
                                            </template>
 
                                            {{-- Isian Singkat --}}
                                            <template x-if="q.type === 'isian_singkat' && q.code !== 'B.13' && q.code !== 'C.10'">
                                                <div class="space-y-2">
                                                    <div class="flex items-center gap-3">
                                                        <input
                                                            type="number"
                                                            min="0"
                                                            placeholder="0"
                                                            :disabled="!is_edit_enabled || status === 'SUBMITTED' || status === 'GRADED'"
                                                            class="w-[100px] px-3.5 py-2.5 rounded border border-[#e0e0e0] text-[12px] font-medium text-[#1d293d] focus:outline-none focus:border-[#1b5e20] bg-white placeholder-[#90a1b9] disabled:bg-[#f5f5f5] disabled:text-[#90a1b9]"
                                                            x-model="answers[q.id]"
                                                            @input="if(answers[q.id] < 0) answers[q.id] = 0; scheduleAutoSave(q.id)"
                                                            @wheel.prevent
                                                        />
                                                        {{-- Gunakan keterangan sebagai unit (Cth: 10 skema KKN) --}}
                                                        <span class="text-[12px] font-semibold text-[#45556c] shrink-0" x-text="q.keterangan" x-show="q.keterangan"></span>
                                                    </div>
                                                    {{-- Formula Preview % --}}
                                                    <template x-if="computeFormula(q) !== null">
                                                        <div class="w-fit flex items-center gap-1.5 bg-emerald-50 border border-emerald-200 rounded px-2.5 py-1.5">
                                                            <span class="text-[10px] text-emerald-700 font-bold">≈</span>
                                                            <span class="text-[11px] font-bold text-emerald-700" x-text="computeFormula(q)?.persen + '%'"></span>
                                                            <span class="text-[10px] text-emerald-600">dari</span>
                                                            <span class="text-[10px] font-semibold text-emerald-700" x-text="computeFormula(q)?.label"></span>
                                                        </div>
                                                    </template>
                                                    {{-- Analysis Preview --}}
                                                    <template x-if="computeAnalysis(q) !== null">
                                                        <div class="w-fit flex items-center gap-1.5 border border-gray-200 rounded px-2.5 py-1.5"
                                                             :class="computeAnalysis(q).bg">
                                                            <i data-lucide="info" class="w-3 h-3" :class="computeAnalysis(q).color"></i>
                                                            <span class="text-[11px] font-semibold" :class="computeAnalysis(q).color" x-text="computeAnalysis(q).label"></span>
                                                        </div>
                                                    </template>
                                                </div>
                                            </template>

                                            {{-- B.13 Khusus: 4 Sub-Field Skala --}}
                                            <template x-if="q.type === 'isian_singkat' && q.code === 'B.13'">
                                                <div x-data="{
                                                    b13: { lokal: '', regional: '', nasional: '', internasional: '' },
                                                    b13Labels: {
                                                        lokal: 'Skala lokal / kota kabupaten / internal institusi',
                                                        regional: 'Skala regional / provinsi',
                                                        nasional: 'Skala nasional',
                                                        internasional: 'Skala internasional'
                                                    },
                                                    get b13Total() {
                                                        const l = parseInt(this.b13.lokal) || 0;
                                                        const r = parseInt(this.b13.regional) || 0;
                                                        const n = parseInt(this.b13.nasional) || 0;
                                                        const i = parseInt(this.b13.internasional) || 0;
                                                        return (l*1) + (r*2) + (n*3) + (i*4);
                                                    },
                                                    initB13(qId) {
                                                        const raw = this.answers[qId];
                                                        const getVal = (obj, key) => {
                                                            if (obj[key] && typeof obj[key] === 'object') return obj[key].nilai || 0;
                                                            return parseInt(obj[key]) || 0;
                                                        };
                                                        if (raw && typeof raw === 'string') {
                                                            try {
                                                                const p = JSON.parse(raw);
                                                                if (p && typeof p === 'object') {
                                                                    this.b13 = { lokal: getVal(p,'lokal'), regional: getVal(p,'regional'), nasional: getVal(p,'nasional'), internasional: getVal(p,'internasional') };
                                                                }
                                                            } catch(e){}
                                                        } else if (raw && typeof raw === 'object') {
                                                            this.b13 = { lokal: getVal(raw,'lokal'), regional: getVal(raw,'regional'), nasional: getVal(raw,'nasional'), internasional: getVal(raw,'internasional') };
                                                        }
                                                    },
                                                    saveB13(qId) {
                                                        const l = parseInt(this.b13.lokal) || 0;
                                                        const r = parseInt(this.b13.regional) || 0;
                                                        const n = parseInt(this.b13.nasional) || 0;
                                                        const i = parseInt(this.b13.internasional) || 0;
                                                        const payload = {
                                                            lokal:      { label: this.b13Labels.lokal,      nilai: l },
                                                            regional:   { label: this.b13Labels.regional,   nilai: r },
                                                            nasional:   { label: this.b13Labels.nasional,   nilai: n },
                                                            internasional: { label: this.b13Labels.internasional, nilai: i },
                                                            total_poin: (l*1)+(r*2)+(n*3)+(i*4)
                                                        };
                                                        this.answers[qId] = JSON.stringify(payload);
                                                        this.scheduleAutoSave(qId);
                                                    }
                                                }" x-init="initB13(q.id)" class="space-y-3">
                                                    <p class="text-[10px] font-bold text-[#62748e] uppercase tracking-wider">Jumlah Kegiatan per Skala:</p>
                                                    <div class="space-y-2.5">
                                                        <div class="flex items-center gap-3">
                                                            <input type="number" min="0" placeholder="0"
                                                                :disabled="!is_edit_enabled || status === 'SUBMITTED' || status === 'GRADED'"
                                                                class="w-[80px] px-2.5 py-2 rounded border border-[#e0e0e0] text-[12px] font-medium text-[#1d293d] focus:outline-none focus:border-[#1b5e20] bg-white placeholder-[#90a1b9] disabled:bg-[#f5f5f5] disabled:text-[#90a1b9]"
                                                                x-model="b13.lokal"
                                                                @input="if(b13.lokal < 0) b13.lokal=0; saveB13(q.id)"
                                                                @wheel.prevent/>
                                                            <span class="text-[12px] text-[#45556c]">Skala lokal / kota kabupaten / internal institusi</span>
                                                        </div>
                                                        <div class="flex items-center gap-3">
                                                            <input type="number" min="0" placeholder="0"
                                                                :disabled="!is_edit_enabled || status === 'SUBMITTED' || status === 'GRADED'"
                                                                class="w-[80px] px-2.5 py-2 rounded border border-[#e0e0e0] text-[12px] font-medium text-[#1d293d] focus:outline-none focus:border-[#1b5e20] bg-white placeholder-[#90a1b9] disabled:bg-[#f5f5f5] disabled:text-[#90a1b9]"
                                                                x-model="b13.regional"
                                                                @input="if(b13.regional < 0) b13.regional=0; saveB13(q.id)"
                                                                @wheel.prevent/>
                                                            <span class="text-[12px] text-[#45556c]">Skala regional / provinsi</span>
                                                        </div>
                                                        <div class="flex items-center gap-3">
                                                            <input type="number" min="0" placeholder="0"
                                                                :disabled="!is_edit_enabled || status === 'SUBMITTED' || status === 'GRADED'"
                                                                class="w-[80px] px-2.5 py-2 rounded border border-[#e0e0e0] text-[12px] font-medium text-[#1d293d] focus:outline-none focus:border-[#1b5e20] bg-white placeholder-[#90a1b9] disabled:bg-[#f5f5f5] disabled:text-[#90a1b9]"
                                                                x-model="b13.nasional"
                                                                @input="if(b13.nasional < 0) b13.nasional=0; saveB13(q.id)"
                                                                @wheel.prevent/>
                                                            <span class="text-[12px] text-[#45556c]">Skala nasional</span>
                                                        </div>
                                                        <div class="flex items-center gap-3">
                                                            <input type="number" min="0" placeholder="0"
                                                                :disabled="!is_edit_enabled || status === 'SUBMITTED' || status === 'GRADED'"
                                                                class="w-[80px] px-2.5 py-2 rounded border border-[#e0e0e0] text-[12px] font-medium text-[#1d293d] focus:outline-none focus:border-[#1b5e20] bg-white placeholder-[#90a1b9] disabled:bg-[#f5f5f5] disabled:text-[#90a1b9]"
                                                                x-model="b13.internasional"
                                                                @input="if(b13.internasional < 0) b13.internasional=0; saveB13(q.id)"
                                                                @wheel.prevent/>
                                                            <span class="text-[12px] text-[#45556c]">Skala internasional</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </template>
 
                                            {{-- C.10 Khusus: 4 Sub-Field Skala Prestasi --}}
                                            <template x-if="q.type === 'isian_singkat' && q.code === 'C.10'">
                                                <div x-data="{
                                                    c10: { lokal: '', regional: '', nasional: '', internasional: '' },
                                                    c10Labels: {
                                                        lokal: 'Skala lokal / kota kabupaten / internal institusi',
                                                        regional: 'Skala regional / provinsi',
                                                        nasional: 'Skala nasional',
                                                        internasional: 'Skala internasional'
                                                    },
                                                    get c10Total() {
                                                        const l = parseInt(this.c10.lokal) || 0;
                                                        const r = parseInt(this.c10.regional) || 0;
                                                        const n = parseInt(this.c10.nasional) || 0;
                                                        const i = parseInt(this.c10.internasional) || 0;
                                                        return (l*1) + (r*2) + (n*3) + (i*4);
                                                    },
                                                    initC10(qId) {
                                                        const raw = this.answers[qId];
                                                        const getVal = (obj, key) => {
                                                            if (obj[key] && typeof obj[key] === 'object') return obj[key].nilai || 0;
                                                            return parseInt(obj[key]) || 0;
                                                        };
                                                        if (raw && typeof raw === 'string') {
                                                            try {
                                                                const p = JSON.parse(raw);
                                                                if (p && typeof p === 'object') {
                                                                    this.c10 = { lokal: getVal(p,'lokal'), regional: getVal(p,'regional'), nasional: getVal(p,'nasional'), internasional: getVal(p,'internasional') };
                                                                }
                                                            } catch(e){}
                                                        } else if (raw && typeof raw === 'object') {
                                                            this.c10 = { lokal: getVal(raw,'lokal'), regional: getVal(raw,'regional'), nasional: getVal(raw,'nasional'), internasional: getVal(raw,'internasional') };
                                                        }
                                                    },
                                                    saveC10(qId) {
                                                        const l = parseInt(this.c10.lokal) || 0;
                                                        const r = parseInt(this.c10.regional) || 0;
                                                        const n = parseInt(this.c10.nasional) || 0;
                                                        const i = parseInt(this.c10.internasional) || 0;
                                                        const payload = {
                                                            lokal:      { label: this.c10Labels.lokal,      nilai: l },
                                                            regional:   { label: this.c10Labels.regional,   nilai: r },
                                                            nasional:   { label: this.c10Labels.nasional,   nilai: n },
                                                            internasional: { label: this.c10Labels.internasional, nilai: i },
                                                            total_poin: (l*1)+(r*2)+(n*3)+(i*4)
                                                        };
                                                        this.answers[qId] = JSON.stringify(payload);
                                                        this.scheduleAutoSave(qId);
                                                    }
                                                }" x-init="initC10(q.id)" class="space-y-3">
                                                    <p class="text-[10px] font-bold text-[#62748e] uppercase tracking-wider">Jumlah Prestasi per Skala:</p>
                                                    <div class="space-y-2.5">
                                                        <div class="flex items-center gap-3">
                                                            <input type="number" min="0" placeholder="0"
                                                                :disabled="!is_edit_enabled || status === 'SUBMITTED' || status === 'GRADED'"
                                                                class="w-[80px] px-2.5 py-2 rounded border border-[#e0e0e0] text-[12px] font-medium text-[#1d293d] focus:outline-none focus:border-[#1b5e20] bg-white placeholder-[#90a1b9] disabled:bg-[#f5f5f5] disabled:text-[#90a1b9]"
                                                                x-model="c10.lokal"
                                                                @input="if(c10.lokal < 0) c10.lokal=0; saveC10(q.id)"
                                                                @wheel.prevent/>
                                                            <span class="text-[12px] text-[#45556c]">Skala lokal / kota kabupaten / internal institusi </span>
                                                        </div>
                                                        <div class="flex items-center gap-3">
                                                            <input type="number" min="0" placeholder="0"
                                                                :disabled="!is_edit_enabled || status === 'SUBMITTED' || status === 'GRADED'"
                                                                class="w-[80px] px-2.5 py-2 rounded border border-[#e0e0e0] text-[12px] font-medium text-[#1d293d] focus:outline-none focus:border-[#1b5e20] bg-white placeholder-[#90a1b9] disabled:bg-[#f5f5f5] disabled:text-[#90a1b9]"
                                                                x-model="c10.regional"
                                                                @input="if(c10.regional < 0) c10.regional=0; saveC10(q.id)"
                                                                @wheel.prevent/>
                                                            <span class="text-[12px] text-[#45556c]">Skala regional / provinsi </span>
                                                        </div>
                                                        <div class="flex items-center gap-3">
                                                            <input type="number" min="0" placeholder="0"
                                                                :disabled="!is_edit_enabled || status === 'SUBMITTED' || status === 'GRADED'"
                                                                class="w-[80px] px-2.5 py-2 rounded border border-[#e0e0e0] text-[12px] font-medium text-[#1d293d] focus:outline-none focus:border-[#1b5e20] bg-white placeholder-[#90a1b9] disabled:bg-[#f5f5f5] disabled:text-[#90a1b9]"
                                                                x-model="c10.nasional"
                                                                @input="if(c10.nasional < 0) c10.nasional=0; saveC10(q.id)"
                                                                @wheel.prevent/>
                                                            <span class="text-[12px] text-[#45556c]">Skala nasional</span>
                                                        </div>
                                                        <div class="flex items-center gap-3">
                                                            <input type="number" min="0" placeholder="0"
                                                                :disabled="!is_edit_enabled || status === 'SUBMITTED' || status === 'GRADED'"
                                                                class="w-[80px] px-2.5 py-2 rounded border border-[#e0e0e0] text-[12px] font-medium text-[#1d293d] focus:outline-none focus:border-[#1b5e20] bg-white placeholder-[#90a1b9] disabled:bg-[#f5f5f5] disabled:text-[#90a1b9]"
                                                                x-model="c10.internasional"
                                                                @input="if(c10.internasional < 0) c10.internasional=0; saveC10(q.id)"
                                                                @wheel.prevent/>
                                                            <span class="text-[12px] text-[#45556c]">Skala internasional </span>
                                                        </div>
                                                    </div>
                                         
                                                </div>
                                            </template>

                                            {{-- Otomatis Sistem --}}
                                            <template x-if="q.type === 'otomatis_sistem'">
                                                <div class="relative">
                                                    <div class="flex items-center gap-3">
                                                        <input
                                                            type="text"
                                                            placeholder="Akan dihitung otomatis oleh sistem"
                                                            disabled
                                                            class="w-full px-3.5 py-2.5 rounded border border-[#e0e0e0] text-[12px] font-bold text-[#62748e] bg-[#f8f9fa] cursor-not-allowed"
                                                            x-model="answers[q.id]"
                                                        />
                                                        <span class="text-[12px] font-semibold text-[#45556c] shrink-0" x-text="q.keterangan" x-show="q.keterangan"></span>
                                                    </div>
                                                    <div class="mt-2 flex items-center gap-1.5 text-[10px] text-[#2e7d32] font-semibold">
                                                        <i data-lucide="cpu" class="w-3 h-3"></i>
                                                        Data diproses otomatis oleh sistem berdasarkan profil institusi
                                                    </div>
                                                </div>
                                            </template>

                                            {{-- Tautan Bukti --}}
                                            <div class="pt-2">
                                                <p class="text-[10px] font-bold text-[#62748e] uppercase tracking-wider flex items-center gap-1.5 mb-2">
                                                    <i data-lucide="link" class="w-[12px] h-[12px]"></i>
                                                    Tautan Bukti / Dokumen
                                                </p>
                                                <input
                                                    type="url"
                                                    placeholder="https://drive.google.com/..."
                                                    :disabled="!is_edit_enabled || status === 'SUBMITTED' || status === 'GRADED'"
                                                    class="w-full px-3.5 py-2.5 rounded border border-[#e0e0e0] text-[12px] font-medium focus:outline-none focus:border-[#1b5e20] bg-[#fafafa] text-[#1d293d] placeholder-[#90a1b9] disabled:bg-[#f5f5f5] disabled:text-[#90a1b9]"
                                                    x-model="links[q.id]"
                                                    @input="scheduleAutoSave(q.id)"
                                                />
                                                <p class="text-[10px] font-medium text-red-500 font-bold mt-1.5">* Pastikan tautan dapat diakses publik (<em>Anyone with the link</em>)</p>
                                            </div>
                                        </div>

                                    </div>
                                </div>
                            </template>
                            </div>
                        </div>
                        </template>
                    </template>
                </div>

            </div>
        </div>

        {{-- ✏️ Sticky Footer → components/dashboard/rubrik/footer.blade.php --}}
        <x-dashboard.rubrik.footer />

        {{-- ================================================================== --}}
        {{-- 🧭 FLOATING QUIZ NAVIGATION DRAWER                                --}}
        {{-- Tombol panah sticky di kanan layar, klik untuk toggle drawer panel --}}
        {{-- ================================================================== --}}
        <div x-show="!loading" style="display:none;">

            {{-- Toggle Arrow Button — follows header show/hide via showBar from parent scope --}}
            <button
                type="button"
                @click="drawerOpen = !drawerOpen"
                class="fixed right-0 z-40 bg-[#1b5e20] text-white shadow-lg flex items-center justify-center"
                :style="{
                    top: showBar ? 'calc(120px + ((100vh - 120px) / 2) - 32px)' : 'calc(50vh - 32px)',
                    width: '28px',
                    height: '64px',
                    borderRadius: '8px 0 0 8px',
                    transition: 'top 0.3s ease'
                }"
                :title="drawerOpen ? 'Tutup Navigator' : 'Buka Navigator'">
                <svg xmlns="http://www.w3.org/2000/svg"
                     class="w-4 h-4 transition-transform duration-300"
                     :class="drawerOpen ? 'rotate-0' : 'rotate-0'"
                     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="15 18 9 12 15 6"/>
                </svg>
            </button>

            {{-- Drawer Backdrop (close on outside click) --}}
            <div
                x-show="drawerOpen"
                x-transition:enter="transition-opacity ease-out duration-200"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition-opacity ease-in duration-150"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                @click="drawerOpen = false"
                class="fixed inset-0 bg-black/20 z-30"
                style="display:none;">
            </div>

            {{-- Drawer Panel — top/height react to showBar from parent body x-data --}}
            <div
                x-show="drawerOpen"
                x-transition:enter="transition ease-out duration-250"
                x-transition:enter-start="translate-x-full"
                x-transition:enter-end="translate-x-0"
                x-transition:leave="transition ease-in duration-200"
                x-transition:leave-start="translate-x-0"
                x-transition:leave-end="translate-x-full"
                class="fixed right-0 w-[280px] bg-white shadow-2xl z-40 flex flex-col"
                :style="{
                    top:        showBar ? '120px' : '0px',
                    height:     showBar ? 'calc(100vh - 120px)' : '100vh',
                    transition: 'top 0.3s ease, height 0.3s ease'
                }">


                {{-- Drawer Header --}}
                <div class="px-4 py-4 border-b border-[#e0e0e0] flex items-center justify-between shrink-0">
                    <div>
                        <h3 class="font-bold text-[#1d293d] text-[13px] uppercase tracking-wide">Navigator</h3>
                        <p class="text-[11px] text-[#62748e] mt-0.5">
                            <span class="font-semibold text-[#1b5e20]" x-text="totalAnswered"></span>
                            / <span x-text="allQuestions.length"></span> terjawab
                            <template x-if="totalFlagged > 0">
                                <span class="ml-2 text-orange-600 font-semibold">
                                    · <span x-text="totalFlagged"></span> flag
                                </span>
                            </template>
                        </p>
                    </div>
                    <button type="button" @click="drawerOpen = false"
                        class="text-[#90a1b9] hover:text-[#45556c] p-1 rounded transition-colors focus:outline-none">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>

                {{-- Legend --}}
                <div class="px-4 pt-3 pb-2 flex flex-wrap items-center gap-3 text-[10px] font-medium text-[#62748e] shrink-0">
                    <span class="flex items-center gap-1.5">
                        <span class="w-3 h-3 rounded-sm bg-[#1b5e20]"></span> Lengkap
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="w-3 h-3 rounded-sm bg-yellow-400"></span> Sebagian
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="w-3 h-3 rounded-sm bg-[#e0e0e0]"></span> Kosong
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="w-3 h-3 rounded-sm bg-red-500"></span> Ditandai
                    </span>
                </div>

                {{-- Question Grid (scrollable) --}}
                <div class="flex-1 overflow-y-auto px-4 py-2 space-y-5">
                    <template x-for="(catData, ci) in categories" :key="ci">
                        <div>
                            {{-- Category Label --}}
                            <p class="text-[10px] font-bold text-[#62748e] uppercase tracking-wider mb-2" x-text="catData.category"></p>
                            {{-- Grid of question blocks --}}
                            <div class="flex flex-wrap gap-1.5">
                                <template x-for="q in catData.questions" :key="q.id">
                                    <button
                                        type="button"
                                        @click="scrollToQuestion(q.id)"
                                        :title="'Soal ' + q.code + (isFlagged(q.id) ? ' (Flag)' : '') + (fillStatus(q.id) === 2 ? ' ✓' : fillStatus(q.id) === 1 ? ' (sebagian)' : '')"
                                        class="relative w-9 h-9 rounded text-[11px] font-bold transition-all duration-150 focus:outline-none hover:scale-110 hover:shadow-md overflow-hidden"
                                        :class="isFlagged(q.id) ? 'text-white' : fillStatus(q.id) === 2 ? 'text-white' : 'text-[#62748e]'"
                                        :style="isFlagged(q.id)
                                            ? 'background:#ef4444;'
                                            : fillStatus(q.id) === 2
                                                ? 'background:#1b5e20;'
                                                : fillStatus(q.id) === 1
                                                    ? 'background:#facc15;'
                                                    : 'background:#e0e0e0;'"
                                        x-text="q.code">
                                    </button>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- Drawer Footer --}}
                <div class="px-4 py-3 border-t border-[#e0e0e0] shrink-0">
                    <div class="flex items-center gap-2 text-[11px] text-[#90a1b9]">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                        Klik nomor soal untuk loncat ke soal tersebut.
                    </div>
                </div>
            </div>
        </div>

        {{-- ================================================================== --}}
        {{-- 🔔 TOAST NOTIFIKASI GLOBAL — pojok kanan atas, tema-konsisten      --}}
        {{-- ================================================================== --}}
        <div x-show="toast.show"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-[-8px]"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="fixed top-[136px] right-4 z-[60] max-w-[340px] shadow-2xl rounded-xl border overflow-hidden flex items-start gap-3 p-3.5 bg-white"
             :class="{
                'border-[#1b5e20]/30': toast.type === 'success',
                'border-orange-200': toast.type === 'warning',
                'border-red-300': toast.type === 'error',
                'border-blue-300': toast.type === 'info'
             }"
             style="display:none; font-family:'Plus Jakarta Sans',sans-serif;">
            <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0"
                 :class="{
                    'bg-[#e8f5e9] text-[#1b5e20]': toast.type === 'success',
                    'bg-orange-50 text-orange-600': toast.type === 'warning',
                    'bg-red-50 text-red-600': toast.type === 'error',
                    'bg-blue-50 text-blue-600': toast.type === 'info'
                 }">
                <i x-show="toast.type === 'success'" data-lucide="check-circle-2" class="w-[18px] h-[18px]"></i>
                <i x-show="toast.type === 'warning'" data-lucide="alert-triangle" class="w-[18px] h-[18px]" style="display:none;"></i>
                <i x-show="toast.type === 'error'" data-lucide="alert-circle" class="w-[18px] h-[18px]" style="display:none;"></i>
                <i x-show="toast.type === 'info'" data-lucide="info" class="w-[18px] h-[18px]" style="display:none;"></i>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-[13px] font-bold text-[#1d293d] leading-tight" x-text="toast.title"></p>
                <p class="text-[12px] text-[#62748e] mt-0.5 leading-snug" x-text="toast.message"></p>
            </div>
            <button type="button" @click="toast.show = false"
                class="text-[#90a1b9] hover:text-[#45556c] transition-colors p-0.5 -mt-1 -mr-1 focus:outline-none">
                <i data-lucide="x" class="w-3.5 h-3.5"></i>
            </button>
        </div>

    </div>
</x-layouts.dashboard>
