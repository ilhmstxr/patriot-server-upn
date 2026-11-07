<x-dynamic-component :component="isset($adminReadonly) && $adminReadonly ? 'layouts.app' : 'layouts.reviewer'" :hideNav="isset($adminReadonly) && $adminReadonly" :hideFooter="isset($adminReadonly) && $adminReadonly">
    @php
    $reqId = isset($id) ? $id : request('id', '1');
    @endphp
    <x-slot:title>DETAIL PENILAIAN PESERTA</x-slot:title>
    <div x-data="{
        activeTab: 'penilaian',
        isSaving: false,
        lastSaved: '',
        isDone: false,
        adminReadonly: {{ isset($adminReadonly) && $adminReadonly ? 'true' : 'false' }},
        loading: true,
        isLocking: false,
        showLockConfirm: false,
        pesertaId: {{ $reqId }},
        
        // Data dari API
        reviewerKey: null,
        Penugasan: {},
        institusi: {},
        profil_peserta: {},
        nama_pic: '',
        jabatan_pic: '',
        email_pic: '',
        no_hp_pic: '',
        rubrikData: [],

        // Jawaban dan Link (Diambil dari mapping rubrikData)
        answers: {},
        links: {},
        
        // Data Penilaian Reviewer
        reviewerScores: {},
        reviewerNotes: {},
        unlockedQuestions: {},

        // Floating Drawer & Flags
        drawerOpen: false,
        flags: {},
        saveStatus: {}, // 'saving' | 'saved' | '' per question
      
        toggleFlag(questionId) {
            if (this.isDone) return;
            this.flags[questionId] = !this.flags[questionId];
            try { sessionStorage.setItem('reviewer_flags_' + this.pesertaId, JSON.stringify(this.flags)); } catch(e) {}
        },
        isFlagged(questionId) {
            return !!this.flags[questionId];
        },
        isHtml(val) {
            if (typeof val !== 'string') return false;
            return /<[a-z][\s\S]*>/i.test(val);
        },
        scrollToQuestion(qId) {
            const catIdx = this.rubrikData.findIndex(c => c.pertanyaan.some(q => q.id == qId));
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
        fillStatus(qId) {
            const score = this.reviewerScores[qId];
            const note  = this.reviewerNotes[qId];
            const hasScore = (score !== null && score !== undefined && score !== '');
            const hasNote  = !!(note && String(note).trim() !== '');
            if (hasScore) return 2;       // hijau: skor sudah diisi
            if (hasNote)  return 1;       // kuning: baru catatan, belum skor
            return 0;                     // kosong: belum ada apa-apa
        },
        get allQuestions() {
            return this.rubrikData.flatMap(c => c.pertanyaan);
        },
        get totalAnswered() {
            return this.allQuestions.filter(q => this.fillStatus(q.id) === 2).length;
        },
        get totalFlagged() {
            return this.allQuestions.filter(q => this.isFlagged(q.id)).length;
        },
        
        openCategories: {},
        get isReviewerEvaluating() {
            // Jika halaman dibuka oleh reviewer yang ditugaskan (bukan admin readonly)
            return !this.adminReadonly;
        },
        get showReviewScore() {
            return this.isReviewerEvaluating || ['GRADED', 'VALIDATING', 'FINALIZED', 'PUBLISHED'].includes(this.Penugasan.status);
        },
        get hasilCategories() {
            return this.rubrikData.map(cat => {
                let items = cat.pertanyaan.map(q => {
                    // Skor dari form penilaian reviewer lokal/tersimpan
                    let localRevScore = (this.reviewerScores[q.id] !== undefined && this.reviewerScores[q.id] !== null && this.reviewerScores[q.id] !== '')
                        ? parseInt(this.reviewerScores[q.id], 10)
                        : null;
                    let dbRevScore = (q.jawaban_peserta && q.jawaban_peserta.skor_validasi_reviewer !== null)
                        ? parseInt(q.jawaban_peserta.skor_validasi_reviewer, 10)
                        : null;
                    let revScore = localRevScore !== null ? localRevScore : (dbRevScore !== null ? dbRevScore : 0);
                    let hasReviewerInput = localRevScore !== null || dbRevScore !== null;

                    // Estimasi = skor sistem dari jawaban peserta
                    let sysScore = q.jawaban_peserta ? parseFloat(q.jawaban_peserta.skor_sistem || 0) : 0;
                    
                    let jawabanText = this.answers[q.id] || 'Belum diisi';
                    try {
                        const parsed = JSON.parse(jawabanText);
                        if (parsed && typeof parsed === 'object') {
                            if (parsed.calculated_percentage !== undefined) {
                                jawabanText = `Input: ${parsed.raw_input || '-'} | Sistem: ${parsed.calculated_percentage}% ${parsed.label || ''}`;
                            } else if (parsed.total_poin !== undefined) {
                                jawabanText = `Total Poin: ${parsed.total_poin} (L:${parsed.lokal?.nilai||0}, R:${parsed.regional?.nilai||0}, N:${parsed.nasional?.nilai||0}, I:${parsed.internasional?.nilai||0})`;
                            }
                        }
                    } catch(e) {}

                    // Tampilkan revScore jika reviewer login / status sudah GRADED+, jika belum ada input fallback ke sysScore
                    let displayScore = this.showReviewScore
                        ? (hasReviewerInput ? Number(revScore) : Number(sysScore))
                        : Number(sysScore);

                    return {
                        no: q.kode_pertanyaan,
                        title: q.teks_pertanyaan,
                        score: displayScore,
                        max: 5,
                        jawaban: jawabanText,
                        tautan: this.links[q.id] || '',
                        catatan: this.reviewerNotes[q.id] || q.jawaban_peserta?.note_reviewer || '',
                        is_validated: this.showReviewScore && hasReviewerInput
                    };
                });
                let totalScore = items.reduce((sum, item) => sum + item.score, 0);
                let maxScore = items.length * 5;
                let bobot = cat.bobot_persentase || 0;
                let capaianSkor = maxScore > 0 ? (totalScore / maxScore) * bobot : 0;

                return {
                    name: cat.kategori,
                    score: totalScore,
                    max: maxScore,
                    bobot: bobot,
                    capaian_skor: capaianSkor,
                    items: items
                };
            });
        },
        get hasilTotalScore() {
            return this.hasilCategories.reduce((sum, cat) => sum + cat.score, 0);
        },
        get hasilTotalCapaian() {
            return this.hasilCategories.reduce((sum, cat) => sum + cat.capaian_skor, 0);
        },
        get hasilTotalMax() {
            return this.hasilCategories.reduce((sum, cat) => sum + cat.max, 0);
        },

        initOpenCategories() {
            this.rubrikData.forEach((_, idx) => { this.openCategories[idx] = {{ config('rubrik.accordion_default_open', false) ? 'true' : 'false' }}; });
        },

        toggleCategory(idx) {
            this.openCategories[idx] = !this.openCategories[idx];
        },

        isCategoryOpen(idx) {
            return this.openCategories[idx] !== false;
        },

        async init() {
            try {
                const savedFlags = sessionStorage.getItem('reviewer_flags_' + this.pesertaId);
                if (savedFlags) this.flags = JSON.parse(savedFlags);
            } catch(e) {}
            const cacheKey = 'reviewer_detail_cache_v2_' + this.pesertaId;
            try {
                const cached = sessionStorage.getItem(cacheKey);
                if (cached) {
                    this.applyData(JSON.parse(cached));
                    this.loading = false;
                    this.fetchData(); // background refresh
                } else {
                    await this.fetchData();
                }
            } catch(e) {
                await this.fetchData();
            }

            let timeout = null;
            const autoSave = () => {
                if (this.loading || this.isDone) return;
                this.isSaving = true;
                clearTimeout(timeout);
                timeout = setTimeout(async () => {
                    await this.saveScores();
                    this.isSaving = false;
                    let d = new Date();
                    this.lastSaved = d.getHours().toString().padStart(2, '0') + ':' + d.getMinutes().toString().padStart(2, '0');
                }, 1000);
            };

            // No global $watch — save is triggered per question via @blur
        },

        async saveQuestionScore(questionId) {
            if (this.loading || this.isDone) return;
            this.isSaving = true;
            this.saveStatus[questionId] = 'saving';
            const scores = {};
            const notes = {};
            const skor = this.reviewerScores[questionId];
            const note = this.reviewerNotes[questionId];
            
            scores[questionId] = (skor !== null && skor !== undefined) ? skor : '';
            // Hanya simpan catatan jika sudah memenuhi syarat minimal 20 karakter
            const trimmedNote = note ? note.trim() : '';
            notes[questionId] = trimmedNote.length >= 20 ? note : '';

            try {
                await fetch(`/api/penugasan/reviewer/tasks/${this.pesertaId}/save-scores`, {
                    method: 'POST',
                    headers: {
                        'Authorization': 'Bearer ' + localStorage.getItem('auth_token'),
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ scores, notes })
                });
                sessionStorage.removeItem('reviewer_detail_cache_v2_' + this.pesertaId);
                this.saveStatus[questionId] = trimmedNote.length >= 20 ? 'saved' : '';
                setTimeout(() => { if (this.saveStatus[questionId] === 'saved') this.saveStatus[questionId] = ''; }, 2000);
            } catch (e) {
                console.error('Gagal menyimpan skor:', e);
                this.saveStatus[questionId] = '';
            } finally {
                this.isSaving = false;
                let d = new Date();
                this.lastSaved = d.getHours().toString().padStart(2, '0') + ':' + d.getMinutes().toString().padStart(2, '0');
            }
        },

        async saveScores() {
            const scores = {};
            const notes  = {};
            for (const [qId, skor] of Object.entries(this.reviewerScores)) {
                scores[qId] = (skor !== null && skor !== undefined) ? skor : '';
            }
            for (const [qId, note] of Object.entries(this.reviewerNotes)) {
                notes[qId] = note || '';
            }
            try {
                await fetch(`/api/penugasan/reviewer/tasks/${this.pesertaId}/save-scores`, {
                    method: 'POST',
                    headers: {
                        'Authorization': 'Bearer ' + localStorage.getItem('auth_token'),
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ scores, notes })
                });
                sessionStorage.removeItem('reviewer_detail_cache_v2_' + this.pesertaId);
            } catch (e) {
                console.error('Gagal menyimpan skor reviewer:', e);
            }
        },

        get submissionStatus() {
            const s = this.Penugasan.status;
            const map = {
                'ACTIVE'      : { label: 'Belum Submit',     color: 'bg-slate-100 text-slate-600',       icon: 'clock' },
                'IN_PROGRESS' : { label: 'Dalam Pengerjaan', color: 'bg-blue-50 text-blue-600',          icon: 'pen-line' },
                'SUBMITTED'   : { label: 'Menunggu Review',  color: 'bg-purple-50 text-purple-600',      icon: 'hourglass' },
                'GRADED'      : { label: 'Sudah Dinilai',    color: 'bg-emerald-50 text-emerald-600',    icon: 'check-circle-2' },
                'VALIDATING'  : { label: 'Validating',       color: 'bg-indigo-50 text-indigo-600',      icon: 'clipboard-check' },
                'FINALIZED'   : { label: 'Finalized',        color: 'bg-amber-50 text-amber-600',        icon: 'check-square' },
                'PUBLISHED'   : { label: 'Published',        color: 'bg-cyan-50 text-cyan-600',          icon: 'globe' },
                'REJECTED'    : { label: 'Ditolak',          color: 'bg-rose-50 text-rose-600',          icon: 'x-circle' },
            };
            return map[s] || { label: s || '...', color: 'bg-slate-100 text-slate-500', icon: 'info' };
        },

        validateBeforeFinalize() {
            const unanswered = this.allQuestions.filter(q => {
                const score = this.reviewerScores[q.id];
                const note = this.reviewerNotes[q.id];
                const hasScore = (score !== null && score !== undefined && score !== '');
                const hasNote = (note !== null && note !== undefined && note.trim() !== '' && note.trim().length >= 20);
                return !hasScore || !hasNote;
            });
            if (unanswered.length > 0) {
                const firstQ = unanswered[0];
                const el = document.getElementById('q-' + firstQ.id);
                if (el) {
                    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    el.classList.add('ring-2', 'ring-red-400', 'ring-offset-2');
                    setTimeout(() => el.classList.remove('ring-2', 'ring-red-400', 'ring-offset-2'), 3000);
                }
                Swal.fire({
                    icon: 'warning',
                    title: 'Belum Lengkap',
                    text: 'Masih ada ' + unanswered.length + ' pertanyaan yang belum dinilai. Silakan lengkapi terlebih dahulu.',
                    confirmButtonColor: '#1b5e20'
                });
                return;
            }
            this.showLockConfirm = true;
        },

        async lockReview() {
            this.isLocking = true;
            this.showLockConfirm = false;
            try {
                await this.saveScores();
                const res = await fetch(`/api/penugasan/reviewer/tasks/${this.pesertaId}/finalize`, {
                    method: 'POST',
                    headers: {
                        'Authorization': 'Bearer ' + localStorage.getItem('auth_token'),
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    }
                });
                const result = await res.json();
                if (res.ok && result.success) {
                    sessionStorage.removeItem('reviewer_detail_cache_v2_' + this.pesertaId);
                    sessionStorage.removeItem('reviewer_tasks_cache');
                    sessionStorage.removeItem('reviewer_flags_' + this.pesertaId);
                    this.flags = {};
                    await this.fetchData();
                } else {
                    alert(result.message || 'Gagal memfinalisasi penilaian.');
                }
            } catch(e) {
                console.error(e);
                alert('Terjadi kesalahan jaringan.');
            } finally {
                this.isLocking = false;
            }
        },

        applyData(data) {
            this.reviewerKey    = data.reviewer_key || null;
            this.Penugasan    = data.Penugasan    || {};
            this.institusi      = data.institusi      || {};
            this.profil_peserta = data.profil_peserta || {};
            this.rubrikData     = data.rubrik         || [];
            this.initOpenCategories();
            this.nama_pic       = data.nama_pic;
            this.jabatan_pic    = data.jabatan_pic;
            this.email_pic      = data.email_pic;
            this.no_hp_pic      = data.no_hp_pic;
            // isDone = true jika status GRADED, VALIDATING, FINALIZED, PUBLISHED, atau REJECTED
            this.isDone = ['GRADED', 'VALIDATING', 'FINALIZED', 'PUBLISHED', 'REJECTED'].includes(this.Penugasan.status);
            if (this.adminReadonly) this.isDone = true;
            this.rubrikData.forEach(kategori => {
                kategori.pertanyaan.forEach(q => {
                    if (q.jawaban_peserta) {
                        this.answers[q.id] = q.jawaban_peserta.opsi_dipilih
                            ? q.jawaban_peserta.opsi_dipilih.keterangan
                            : q.jawaban_peserta.jawaban_teks;
                        this.links[q.id] = q.jawaban_peserta.tautan_bukti_drive;
                        if (q.jawaban_peserta.skor_validasi_reviewer !== null) {
                            this.reviewerScores[q.id] = parseInt(q.jawaban_peserta.skor_validasi_reviewer, 10);
                        }
                        // Load catatan reviewer dari database
                        if (q.jawaban_peserta.note_reviewer) {
                            this.reviewerNotes[q.id] = q.jawaban_peserta.note_reviewer;
                        }
                    }
                });
            });
        },

        async fetchData() {
            const cacheKey = 'reviewer_detail_cache_v2_' + this.pesertaId;
            const apiUrl = this.adminReadonly
                ? `/admin/api/penugasan/${this.pesertaId}`
                : `/api/penugasan/reviewer/tasks/detail/${this.pesertaId}`;
            try {
                const token = localStorage.getItem('auth_token');
                // Token hanya disertakan jika ada, Admin menggunakan session cookies bawaan browser
                const headers = { 'Accept': 'application/json' };
                if (token) headers['Authorization'] = 'Bearer ' + token;
                const response = await fetch(apiUrl, { headers });
                const result = await response.json();
                if (response.ok && result.success) {
                    try { sessionStorage.setItem(cacheKey, JSON.stringify(result.data)); } catch(e) {}
                    this.applyData(result.data);
                }
            } catch (error) {
                console.error('Error fetching data:', error);
            } finally {
                this.loading = false;
                this.$nextTick(() => { if (typeof lucide !== 'undefined') lucide.createIcons(); });
            }
        },

        get agamaData() {
            if (!this.profil_peserta || !this.profil_peserta.agama) return [];
            const labels = {
                'islam': 'Islam',
                'kristen': 'Kristen',
                'katolik': 'Katolik',
                'hindu': 'Hindu',
                'buddha': 'Buddha',
                'konghucu': 'Konghucu'
            };
            return Object.entries(this.profil_peserta.agama).map(([name, count]) => ({
                name: labels[name.toLowerCase()] || (name.charAt(0).toUpperCase() + name.slice(1)),
                count: count
            }));
        },

        get dokumenPendukung() {
            if (!this.profil_peserta || !this.profil_peserta.berkas_pendukung) return {};
            try {
                return typeof this.profil_peserta.berkas_pendukung === 'string' 
                    ? JSON.parse(this.profil_peserta.berkas_pendukung) 
                    : this.profil_peserta.berkas_pendukung;
            } catch (e) {
                return {};
            }
        },

        get logoUrl() {
            const docs = this.dokumenPendukung;
            return docs.logo_pt || docs.logo || this.institusi.logo_url || null;
        },

        parseEvidence(val) {
            if (Array.isArray(val)) return val;
            if (typeof val === 'string') return val.split(',').map(s => s.trim()).filter(s => s !== '');
            return val ? [val] : [];
        },

        parseAnswerJson(val) {
            if (!val) return null;
            if (typeof val === 'object') return val;
            try {
                const parsed = JSON.parse(val);
                return (parsed && typeof parsed === 'object') ? parsed : null;
            } catch (e) {
                return null;
            }
        },
    }" class="flex-1 flex flex-col min-h-[calc(100vh-120px)] bg-[#f8fafc] font-['Plus_Jakarta_Sans',sans-serif]">

        {{-- Loading State — konsisten dengan dashboard peserta --}}
        <div x-show="loading">
            <x-dashboard.loading
                title="Memuat Data Peserta..."
                caption="Sistem sedang menyiapkan data profil dan rubrik penilaian." />
        </div>

        {{-- Konten — pakai x-show agar tab switch instan tanpa re-render DOM --}}
        <div x-show="!loading" x-cloak class="flex-1 flex flex-col h-full">
            {{-- Page Header --}}
            <div class="bg-white border-b border-[#e2e8f0] px-[20px] md:px-[40px] pt-[20px] md:pt-[28px] shadow-sm">
                <template x-if="!adminReadonly">
                    <a href="{{ route('reviewer.dashboard') }}" wire:navigate class="inline-flex items-center gap-[6px] text-[#62748e] hover:text-[#1b5e20] text-[13px] font-semibold mb-[8px] transition-colors">
                        <i data-lucide="arrow-left" class="w-[14px] h-[14px]"></i> Kembali ke Daftar Plotting
                    </a>
                </template>
                <template x-if="adminReadonly">
                    <a href="/admin/penugasans" class="inline-flex items-center gap-[6px] text-[#62748e] hover:text-[#1b5e20] text-[13px] font-semibold mb-[8px] transition-colors">
                        <i data-lucide="arrow-left" class="w-[14px] h-[14px]"></i> Kembali ke Admin Panel
                    </a>
                </template>

                <div class="flex flex-col md:flex-row items-start justify-between md:items-end mb-[20px]">
                    <div>
                        <h1 class="font-bold text-[#1d293d] text-[20px] md:text-[26px] tracking-tight flex flex-col md:flex-row items-start md:items-center gap-[8px] md:gap-[12px]">
                            Menu Penilaian - <span x-text="institusi.nama_institusi"></span>
                            {{-- Dynamic Status Badge --}}
                            <span class="inline-flex items-center gap-[6px] px-[12px] py-[4px] rounded-full text-[13px] md:text-[14px] font-bold mt-1 md:mt-0"
                                :class="submissionStatus.color">
                                <i :data-lucide="submissionStatus.icon" class="w-[16px] h-[16px]"
                                    x-init="$watch('submissionStatus', () => $nextTick(() => { if(typeof lucide !== 'undefined') lucide.createIcons(); }))"></i>
                                <span x-text="submissionStatus.label"></span>
                            </span>
                        </h1>
                        <p class="text-[#62748e] text-[13px] md:text-[15px] mt-[6px]">Review isian data, periksa dokumen, dan berikan skor final (1-5).</p>
                    </div>
                </div>

                {{-- Tabs --}}
                <div class="flex gap-[24px]">
                    <button @click="activeTab = 'profil'"
                        class="py-[16px] text-[14px] font-bold transition-colors border-b-[3px]"
                        :class="activeTab === 'profil' ? 'text-[#1b5e20] border-[#1b5e20]' : 'text-[#64748b] border-transparent hover:text-[#1b5e20]'">
                        <i data-lucide="user" class="w-[16px] h-[16px] inline-block mr-1 mb-0.5"></i> Data Profil Peserta
                    </button>
                    <button @click="activeTab = 'penilaian'"
                        class="py-[16px] text-[14px] font-bold transition-colors border-b-[3px]"
                        :class="activeTab === 'penilaian' ? 'text-[#1b5e20] border-[#1b5e20]' : 'text-[#64748b] border-transparent hover:text-[#1b5e20]'">
                        <i data-lucide="file-text" class="w-[16px] h-[16px] inline-block mr-1 mb-0.5"></i> Form Penilaian Rubrik
                    </button>
                    <button @click="activeTab = 'hasil'"
                        class="py-[16px] text-[14px] font-bold transition-colors border-b-[3px]"
                        :class="activeTab === 'hasil' ? 'text-[#1b5e20] border-[#1b5e20]' : 'text-[#64748b] border-transparent hover:text-[#1b5e20]'">
                        <i data-lucide="bar-chart-2" class="w-[16px] h-[16px] inline-block mr-1 mb-0.5"></i> Hasil Penilaian
                    </button>
                </div>
            </div>

            {{-- Content Area --}}
            <div class="flex-1 overflow-y-auto px-[20px] md:px-[40px] py-[32px]">
                <div class="max-w-[1000px] mx-auto">

                    {{-- TAB: DATA PROFIL PESERTA --}}
                    <div x-show="activeTab === 'profil'" x-transition.opacity.duration.300ms style="display: none;">

                        {{-- Skor Overview --}}
                        @if(isset($adminReadonly) && $adminReadonly)
                        <div class="bg-white p-[24px] rounded-[12px] border border-[#cbd5e1] mb-6">
                            <h3 class="font-bold text-[#1b5e20] text-[15px] mb-4 border-b border-[#e2e8f0] pb-2">Ringkasan Skor</h3>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-[24px]">
                                <div class="bg-[#f8fafc] p-4 rounded-[8px] border border-[#e2e8f0] text-center">
                                    <p class="text-[12px] font-bold text-[#64748b] uppercase tracking-wider mb-1">Status</p>
                                    <span class="inline-flex items-center gap-[6px] px-[12px] py-[4px] rounded-full text-[13px] font-bold" :class="submissionStatus.color">
                                        <span x-text="submissionStatus.label"></span>
                                    </span>
                                </div>
                                <div class="bg-[#f8fafc] p-4 rounded-[8px] border border-[#e2e8f0] text-center">
                                    <p class="text-[12px] font-bold text-[#64748b] uppercase tracking-wider mb-1">Total Skor Sistem</p>
                                    <p class="text-[22px] font-bold text-[#1d293d]" x-text="Penugasan.total_skor_sistem ?? 'N/A'"></p>
                                </div>
                                <div class="bg-[#f8fafc] p-4 rounded-[8px] border border-[#e2e8f0] text-center">
                                    <p class="text-[12px] font-bold text-[#64748b] uppercase tracking-wider mb-1">Total Skor Akhir (Reviewer)</p>
                                    <p class="text-[22px] font-bold text-[#1b5e20]" x-text="Penugasan.total_skor_akhir ?? 'N/A'"></p>
                                </div>
                            </div>
                        </div>
                        @endif

                        {{-- Group A: Identitas Institusi --}}
                        <div class="bg-white p-[24px] rounded-[12px] border border-[#cbd5e1] mb-6">
                            <div class="flex flex-col md:flex-row justify-between items-start gap-4 mb-4 border-b border-[#e2e8f0] pb-2">
                                <h3 class="font-bold text-[#1b5e20] text-[15px]">A. Identitas Perguruan Tinggi</h3>
                                <template x-if="logoUrl">
                                    <a :href="logoUrl" target="_blank" class="block w-16 h-16 overflow-hidden rounded-full bg-white border-2 border-slate-200 p-1 hover:border-[#1b5e20] transition-colors shadow-sm">
                                        <img :src="logoUrl" class="w-full h-full object-contain" alt="Logo Perguruan Tinggi">
                                    </a>
                                </template>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-[24px] mb-6">
                                <div>
                                    <p class="text-[13px] font-bold text-[#64748b] uppercase tracking-wider mb-1">Nama Perguruan Tinggi</p>
                                    <p class="text-[15px] font-semibold text-[#1d293d]" x-text="institusi.nama_institusi"></p>
                                </div>
                                <div>
                                    <p class="text-[13px] font-bold text-[#64748b] uppercase tracking-wider mb-1">Jenis Perguruan Tinggi</p>
                                    <p class="text-[15px] font-semibold text-[#1d293d]" x-text="institusi.jenis_institusi"></p>
                                </div>
                            </div>
                            <div class="mb-6">
                                <p class="text-[13px] font-bold text-[#64748b] uppercase tracking-wider mb-1">Visi</p>
                                <p class="text-[15px] font-medium text-[#45556c] leading-relaxed bg-[#f8fafc] p-4 rounded-[8px] border border-[#e2e8f0]" x-text="profil_peserta.visi || '-'"></p>
                            </div>
                            <div>
                                <p class="text-[13px] font-bold text-[#64748b] uppercase tracking-wider mb-1">Misi</p>
                                <p class="text-[15px] font-medium text-[#45556c] leading-relaxed bg-[#f8fafc] p-4 rounded-[8px] border border-[#e2e8f0] whitespace-pre-line" x-text="profil_peserta.misi || '-'"></p>
                            </div>
                        </div>

                        {{-- Group B & C: Akademik & Kemahasiswaan --}}
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div class="bg-white p-[24px] rounded-[12px] border border-[#cbd5e1]">
                                <h3 class="font-bold text-[#1b5e20] text-[15px] mb-4 border-b border-[#e2e8f0] pb-2">B. Akademik & SDM</h3>
                                <div class="space-y-4">
                                    <div class="flex justify-between items-center border-b border-dashed border-[#e2e8f0] pb-2">
                                        <span class="text-[14px] text-[#64748b] font-medium">Jumlah Fakultas</span>
                                        <span class="text-[15px] text-[#1d293d] font-bold" x-text="profil_peserta.jml_fakultas || 0"></span>
                                    </div>
                                    <div class="flex justify-between items-center border-b border-dashed border-[#e2e8f0] pb-2">
                                        <span class="text-[14px] text-[#64748b] font-medium">Program Studi</span>
                                        <span class="text-[15px] text-[#1d293d] font-bold" x-text="profil_peserta.jml_prodi || 0"></span>
                                    </div>
                                    <div class="flex justify-between items-center border-b border-dashed border-[#e2e8f0] pb-2">
                                        <span class="text-[14px] text-[#64748b] font-medium">Dosen</span>
                                        <span class="text-[15px] text-[#1d293d] font-bold" x-text="profil_peserta.jml_dosen || 0"></span>
                                    </div>
                                    <div class="flex justify-between items-center pb-2">
                                        <span class="text-[14px] text-[#64748b] font-medium">Tenaga Akademik (Tendik)</span>
                                        <span class="text-[15px] text-[#1d293d] font-bold" x-text="profil_peserta.jml_tendik || 0"></span>
                                    </div>
                                </div>
                            </div>

                            <div class="bg-white p-[24px] rounded-[12px] border border-[#cbd5e1]">
                                <h3 class="font-bold text-[#1b5e20] text-[15px] mb-4 border-b border-[#e2e8f0] pb-2">C. Data Kemahasiswaan</h3>
                                <div class="space-y-4">
                                    <div class="flex justify-between items-center border-b border-dashed border-[#e2e8f0] pb-2">
                                        <span class="text-[14px] text-[#64748b] font-medium">Mahasiswa Aktif</span>
                                        <span class="text-[15px] text-[#1d293d] font-bold" x-text="profil_peserta.jml_mhs || 0"></span>
                                    </div>
                                    <div class="flex justify-between items-center border-b border-dashed border-[#e2e8f0] pb-2">
                                        <span class="text-[14px] text-[#64748b] font-medium">Organisasi Mahasiswa (Ormawa)</span>
                                        <span class="text-[15px] text-[#1d293d] font-bold" x-text="profil_peserta.jml_ormawa || 0"></span>
                                    </div>
                                    <div class="flex justify-between items-center pb-2">
                                        <span class="text-[14px] text-[#64748b] font-medium">Unit Kegiatan Mahasiswa (UKM)</span>
                                        <span class="text-[15px] text-[#1d293d] font-bold" x-text="profil_peserta.jml_ukm || 0"></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Group D: Kontak PIC --}}
                        <div class="bg-white p-[24px] rounded-[12px] border border-[#cbd5e1] mb-6">
                            <h3 class="font-bold text-[#1b5e20] text-[15px] mb-4 border-b border-[#e2e8f0] pb-2">D. Kontak PIC</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <p class="text-[13px] font-bold text-[#64748b] uppercase tracking-wider mb-1">Nama PIC</p>
                                    <p class="text-[15px] font-semibold text-[#1d293d]" x-text="nama_pic || '-'"></p>
                                </div>
                                <div>
                                    <p class="text-[13px] font-bold text-[#64748b] uppercase tracking-wider mb-1">Jabatan PIC</p>
                                    <p class="text-[15px] font-semibold text-[#1d293d]" x-text="jabatan_pic || '-'"></p>
                                </div>
                                <div>
                                    <p class="text-[13px] font-bold text-[#64748b] uppercase tracking-wider mb-1">Email Resmi</p>
                                    <p class="text-[15px] font-semibold text-blue-600" x-text="email_pic || '-'"></p>
                                </div>
                                <div>
                                    <p class="text-[13px] font-bold text-[#64748b] uppercase tracking-wider mb-1">No. WhatsApp</p>
                                    <p class="text-[15px] font-semibold text-[#1d293d]" x-text="no_hp_pic || '-'"></p>
                                </div>
                            </div>
                        </div>

                        {{-- Group E: Demografi --}}
                        <div class="bg-white p-[24px] rounded-[12px] border border-[#cbd5e1] mb-6">
                            <h3 class="font-bold text-[#1b5e20] text-[15px] mb-4 border-b border-[#e2e8f0] pb-2">E. Demografi Agama Mahasiswa</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-12 gap-y-0">
                                <!-- Kolom Kiri: 4 baris -->
                                <div class="space-y-4 md:border-r md:border-dashed md:border-[#e2e8f0] md:pr-6">
                                    <template x-for="(agama, i) in agamaData.slice(0, 4)" :key="'l-' + i">
                                        <div class="flex justify-between items-center border-b border-dashed border-[#e2e8f0] pb-2">
                                            <span class="text-[14px] text-[#64748b] font-medium" x-text="agama.name"></span>
                                            <span class="text-[15px] text-[#1d293d] font-bold" x-text="agama.count"></span>
                                        </div>
                                    </template>
                                </div>
                                <!-- Kolom Kanan: 3 baris -->
                                <div class="space-y-4 md:pl-6">
                                    <template x-for="(agama, i) in agamaData.slice(4, 7)" :key="'r-' + i">
                                        <div class="flex justify-between items-center border-b border-dashed border-[#e2e8f0] pb-2">
                                            <span class="text-[14px] text-[#64748b] font-medium" x-text="agama.name"></span>
                                            <span class="text-[15px] text-[#1d293d] font-bold" x-text="agama.count"></span>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>

                        {{-- Group F: Dokumen Pendukung --}}
                        <div class="bg-white p-[24px] rounded-[12px] border border-[#cbd5e1] mb-6">
                            <h3 class="font-bold text-[#1b5e20] text-[15px] mb-4 border-b border-[#e2e8f0] pb-2">F. Dokumen Pendukung</h3>
                            <div class="space-y-4">
                                <div class="flex justify-between items-center border-b border-dashed border-[#e2e8f0] pb-2">
                                    <span class="text-[14px] text-[#64748b] font-medium">Surat Pernyataan</span>
                                    <template x-if="dokumenPendukung.surat_pernyataan">
                                        <a :href="dokumenPendukung.surat_pernyataan" target="_blank" class="text-[14px] font-semibold text-blue-600 hover:text-blue-800 hover:underline inline-flex items-center gap-1">
                                            <i data-lucide="external-link" class="w-[14px] h-[14px]"></i> Lihat Dokumen
                                        </a>
                                    </template>
                                    <template x-if="!dokumenPendukung.surat_pernyataan">
                                        <span class="text-[14px] text-[#94a3b8] italic">Belum diunggah</span>
                                    </template>
                                </div>
                                <div class="flex justify-between items-center border-b border-dashed border-[#e2e8f0] pb-2">
                                    <span class="text-[14px] text-[#64748b] font-medium">SK Pendirian</span>
                                    <template x-if="dokumenPendukung.sk_pendirian">
                                        <a :href="dokumenPendukung.sk_pendirian" target="_blank" class="text-[14px] font-semibold text-blue-600 hover:text-blue-800 hover:underline inline-flex items-center gap-1">
                                            <i data-lucide="external-link" class="w-[14px] h-[14px]"></i> Lihat Dokumen
                                        </a>
                                    </template>
                                    <template x-if="!dokumenPendukung.sk_pendirian">
                                        <span class="text-[14px] text-[#94a3b8] italic">Belum diunggah</span>
                                    </template>
                                </div>
                                {{-- SK Akreditasi AIPT - dinonaktifkan
                        <div class="flex justify-between items-center border-b border-dashed border-[#e2e8f0] pb-2">
                            <span class="text-[14px] text-[#64748b] font-medium">SK Akreditasi</span>
                            <template x-if="dokumenPendukung.sk_akreditasi">
                                <a :href="dokumenPendukung.sk_akreditasi" target="_blank" class="text-[14px] font-semibold text-blue-600 hover:text-blue-800 hover:underline inline-flex items-center gap-1">
                                    <i data-lucide="external-link" class="w-[14px] h-[14px]"></i> Lihat Dokumen
                                </a>
                            </template>
                            <template x-if="!dokumenPendukung.sk_akreditasi">
                                <span class="text-[14px] text-[#94a3b8] italic">Belum diunggah</span>
                            </template>
                        </div>
                        --}}
                                <div class="flex justify-between items-center border-b border-dashed border-[#e2e8f0] pb-2">
                                    <span class="text-[14px] text-[#64748b] font-medium">Profil PT</span>
                                    <template x-if="dokumenPendukung.profil_pt">
                                        <a :href="dokumenPendukung.profil_pt" target="_blank" class="text-[14px] font-semibold text-blue-600 hover:text-blue-800 hover:underline inline-flex items-center gap-1">
                                            <i data-lucide="external-link" class="w-[14px] h-[14px]"></i> Lihat Dokumen
                                        </a>
                                    </template>
                                    <template x-if="!dokumenPendukung.profil_pt">
                                        <span class="text-[14px] text-[#94a3b8] italic">Belum diunggah</span>
                                    </template>
                                </div>
                                <div class="flex justify-between items-center pb-2">
                                    <span class="text-[14px] text-[#64748b] font-medium">Struktur Organisasi</span>
                                    <template x-if="dokumenPendukung.struktur_organisasi">
                                        <a :href="dokumenPendukung.struktur_organisasi" target="_blank" class="text-[14px] font-semibold text-blue-600 hover:text-blue-800 hover:underline inline-flex items-center gap-1">
                                            <i data-lucide="external-link" class="w-[14px] h-[14px]"></i> Lihat Dokumen
                                        </a>
                                    </template>
                                    <template x-if="!dokumenPendukung.struktur_organisasi">
                                        <span class="text-[14px] text-[#94a3b8] italic">Belum diunggah</span>
                                    </template>
                                </div>
                            </div>
                        </div>

                    </div>

                    {{-- TAB: FORM PENILAIAN RUBRIK --}}
                    <div x-show="activeTab === 'penilaian'" x-transition.opacity.duration.300ms class="space-y-[24px] md:space-y-[32px]">
                        <template x-for="(categoryData, cIdx) in rubrikData" :key="cIdx">
                            <div class="space-y-[16px]">
                                {{-- Category Header --}}
                                <button type="button"
                                    @click="toggleCategory(cIdx)"
                                    class="w-full flex items-center justify-between border-b-[2px] border-[#e2e8f0] pb-[8px] mb-[16px] cursor-pointer group">
                                    <div class="flex items-center gap-3">
                                        <svg xmlns="http://www.w3.org/2000/svg"
                                            class="w-5 h-5 text-[#1b5e20] transition-transform duration-300"
                                            :class="isCategoryOpen(cIdx) ? 'rotate-90' : 'rotate-0'"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                            <polyline points="9 18 15 12 9 6" />
                                        </svg>
                                        <h2 class="text-[18px] font-bold text-[#1b5e20] uppercase group-hover:text-[#15461c] transition-colors" x-text="categoryData.kategori"></h2>
                                    </div>
                                    <span class="text-[14px] font-bold text-[#62748e] bg-white border border-[#e2e8f0] px-[12px] py-[4px] rounded-full shadow-sm" x-text="'Max: ' + categoryData.bobot_maksimal + ' skor'"></span>
                                </button>

                                {{-- Questions (Accordion Content) --}}
                                <div x-show="isCategoryOpen(cIdx)"
                                    x-transition:enter="transition ease-out duration-200"
                                    x-transition:enter-start="opacity-0"
                                    x-transition:enter-end="opacity-100"
                                    x-transition:leave="transition ease-in duration-150"
                                    x-transition:leave-start="opacity-100"
                                    x-transition:leave-end="opacity-0">
                                    <template x-for="q in categoryData.pertanyaan" :key="q.id">
                                        <div :id="'q-' + q.id" class="relative border rounded-[12px] p-[20px] md:p-[28px] flex flex-col md:flex-row md:items-start gap-[20px] md:gap-[32px] overflow-hidden mb-[16px] transition-all duration-300" :class="isFlagged(q.id) ? 'border-red-500 ring-1 ring-red-100' : 'border-[#cbd5e1]'" :style="'background-color:' + ['#eff6ff','#f0f0ff','#ecfeff'][cIdx % 3]">

                                            {{-- ===== 🔖 Bookmark Flag ===== --}}
                                            <button type="button"
                                                @click.stop="toggleFlag(q.id)"
                                                :disabled="isDone"
                                                :title="isDone ? 'Tidak dapat diubah' : (isFlagged(q.id) ? 'Hapus flag' : 'Tandai pertanyaan ini')"
                                                class="absolute top-0 right-4 z-10 focus:outline-none"
                                                :class="isDone ? 'cursor-not-allowed opacity-60' : ''"
                                                style="width: 24px;">
                                                <span class="block w-full transition-all duration-300"
                                                    :style="isFlagged(q.id)
                                  ? 'height:35px; background:#ef4444; clip-path:polygon(0 0,100% 0,100% 100%,50% 80%,0 100%); box-shadow:0 6px 14px rgba(239,68,68,0.45);'
                                  : 'height:20px; background:#cbd5e1; clip-path:polygon(0 0,100% 0,100% 100%,50% 75%,0 100%); box-shadow:none;'">
                                                </span>
                                            </button>

                                            {{-- Left Column: Question & Evidence --}}
                                            <div class="flex-1 space-y-[16px]">
                                                <div class="flex gap-[16px]">
                                                    <div class="w-[36px] h-[36px] rounded-full bg-[#f2fcf3] border border-[#1b5e20]/30 flex items-center justify-center font-bold text-[#1b5e20] text-[15px] shrink-0" x-text="q.kode_pertanyaan">
                                                    </div>
                                                    <div>
                                                        <h3 class="font-bold text-[#1d293d] text-[16px] leading-[24px]" x-text="q.teks_pertanyaan"></h3>
                                                    </div>
                                                </div>

                                                <div class="bg-amber-50 border border-amber-200 rounded-[8px] p-[16px] ml-[52px]">
                                                    <h4 class="text-[12px] font-bold text-amber-800 mb-[8px] uppercase tracking-[0.4px]">Syarat Bukti Valid:</h4>
                                                    <template x-if="isHtml(q.kebutuhan_bukti)">
                                                        <div class="text-[13px] font-semibold text-amber-900/80 leading-[18px] richtext-content" x-html="q.kebutuhan_bukti"></div>
                                                    </template>
                                                    <template x-if="!isHtml(q.kebutuhan_bukti)">
                                                        <ul class="text-[13px] font-semibold text-amber-900/80 space-y-[6px]">
                                                            <template x-for="(req, rIdx) in parseEvidence(q.kebutuhan_bukti)" :key="rIdx">
                                                                <li class="flex gap-[6px] items-start">
                                                                    <span class="mt-[2px] w-[4px] h-[4px] bg-amber-500 rounded-full shrink-0"></span>
                                                                    <span class="leading-[18px]" x-text="req"></span>
                                                                </li>
                                                            </template>
                                                        </ul>
                                                    </template>

                                                    <template x-if="q.opsi_jawaban && q.opsi_jawaban.length > 0">
                                                        <div class="mt-[16px] pt-[12px] border-t border-orange-100/50">
                                                            <h4 class="text-[12px] font-bold text-orange-700 mb-[8px] uppercase tracking-[0.4px]">Daftar Panduan / Skor:</h4>
                                                            <ul class="text-[13px] font-medium text-orange-800/80 space-y-[4px]">
                                                                <template x-for="(opt, oIdx) in q.opsi_jawaban" :key="oIdx">
                                                                    <li class="leading-[18px] flex gap-[6px] items-start">
                                                                        <span class="shrink-0 mt-[2px] inline-flex items-center justify-center min-w-[44px] px-[6px] py-[1px] rounded-full text-[11px] font-bold bg-orange-100 text-orange-700"
                                                                            x-text="(q.tipe === 'isian_singkat' ? Number(opt.opsi_jawaban) : Math.min(opt.value !== null && opt.value !== undefined ? Number(opt.value) : Number(opt.opsi_jawaban), 5)) + ' Poin'"></span>
                                                                        <span x-text="opt.keterangan || opt.opsi_jawaban || '-'"></span>
                                                                    </li>
                                                                </template>
                                                            </ul>
                                                        </div>
                                                    </template>
                                                </div>
                                            </div>

                                            {{-- Right Column: Answers & Scoring Form --}}
                                            <div class="flex-1 md:self-start flex flex-col space-y-[20px] bg-[#f8fafc] border border-[#e2e8f0] rounded-[8px] p-[20px]">

                                                {{-- Peserta's Answer --}}
                                                <div>
                                                    <h4 class="text-[12px] font-bold text-[#64748b] uppercase tracking-[0.5px] mb-[6px]">Jawaban Klaim Peserta:</h4>

                                                    {{-- Standard Display (Non-JSON) --}}
                                                    <template x-if="!parseAnswerJson(answers[q.id])">
                                                        <div class="bg-white border border-[#cbd5e1] rounded-[8px] p-[12px] text-[#1d293d] font-bold text-[14px] flex items-start gap-2 shadow-sm">
                                                            <i data-lucide="check-circle-2" class="w-[18px] h-[18px] text-blue-600 shrink-0 mt-0.5"></i>
                                                            <span x-text="answers[q.id] || 'Belum diisi / Belum disubmit'"></span>
                                                        </div>
                                                    </template>

                                                    {{-- Structured JSON Display --}}
                                                    <template x-if="parseAnswerJson(answers[q.id])">
                                                        <div class="space-y-3">
                                                            {{-- B.13 & C.10 Special Case --}}
                                                            <template x-if="q.kode_pertanyaan === 'B.13' || q.kode_pertanyaan === 'C.10'">
                                                                <div class="bg-white border border-[#cbd5e1] rounded-[8px] p-4 shadow-sm space-y-3">
                                                                    <div class="grid grid-cols-2 gap-3">
                                                                        <div class="p-2 bg-slate-50 rounded border border-slate-200">
                                                                            <p class="text-[10px] text-slate-500 font-bold uppercase">Lokal</p>
                                                                            <p class="text-[14px] font-bold text-slate-700" x-text="parseAnswerJson(answers[q.id]).lokal?.nilai || 0"></p>
                                                                        </div>
                                                                        <div class="p-2 bg-slate-50 rounded border border-slate-200">
                                                                            <p class="text-[10px] text-slate-500 font-bold uppercase">Regional</p>
                                                                            <p class="text-[14px] font-bold text-slate-700" x-text="parseAnswerJson(answers[q.id]).regional?.nilai || 0"></p>
                                                                        </div>
                                                                        <div class="p-2 bg-slate-50 rounded border border-slate-200">
                                                                            <p class="text-[10px] text-slate-500 font-bold uppercase">Nasional</p>
                                                                            <p class="text-[14px] font-bold text-slate-700" x-text="parseAnswerJson(answers[q.id]).nasional?.nilai || 0"></p>
                                                                        </div>
                                                                        <div class="p-2 bg-slate-50 rounded border border-slate-200">
                                                                            <p class="text-[10px] text-slate-500 font-bold uppercase">Internasional</p>
                                                                            <p class="text-[14px] font-bold text-slate-700" x-text="parseAnswerJson(answers[q.id]).internasional?.nilai || 0"></p>
                                                                        </div>
                                                                    </div>
                                                                    <div class="pt-2 border-t border-slate-100 flex justify-between items-center">
                                                                        <span class="text-[12px] font-bold text-slate-600">Total Poin:</span>
                                                                        <span class="text-[16px] font-extrabold text-[#1b5e20]" x-text="parseAnswerJson(answers[q.id]).total_poin || 0"></span>
                                                                    </div>
                                                                </div>
                                                            </template>

                                                            {{-- System Calculated Display (C.2, B.18, etc.) --}}
                                                            <template x-if="q.kode_pertanyaan !== 'B.13' && q.kode_pertanyaan !== 'C.10' && parseAnswerJson(answers[q.id])">
                                                                <div class="space-y-2">
                                                                    <div class="bg-white border border-[#cbd5e1] rounded-[8px] p-[12px] text-[#1d293d] font-bold text-[14px] flex items-start gap-2 shadow-sm">
                                                                        <i data-lucide="hash" class="w-[18px] h-[18px] text-slate-500 shrink-0 mt-0.5"></i>
                                                                        <div>
                                                                            <p class="text-[10px] text-slate-500 font-bold uppercase mb-0.5">Input Mentah</p>
                                                                            <span x-text="parseAnswerJson(answers[q.id]).raw_input || '-'"></span>
                                                                        </div>
                                                                    </div>
                                                                    <template x-if="parseAnswerJson(answers[q.id]).calculated_percentage !== null && parseAnswerJson(answers[q.id]).calculated_percentage !== undefined || parseAnswerJson(answers[q.id]).label">
                                                                        <div class="bg-[#f0fdf4] border border-[#bbf7d0] rounded-[8px] p-[12px] text-[#166534] font-bold text-[14px] flex items-start gap-2 shadow-sm border-l-4">
                                                                            <i data-lucide="cpu" class="w-[18px] h-[18px] text-[#166534] shrink-0 mt-0.5"></i>
                                                                            <div>
                                                                                <p class="text-[10px] text-[#166534] font-bold uppercase mb-0.5">Kalkulasi Sistem</p>
                                                                                <span x-text="((parseAnswerJson(answers[q.id]).calculated_percentage !== null && parseAnswerJson(answers[q.id]).calculated_percentage !== undefined) ? parseAnswerJson(answers[q.id]).calculated_percentage + '%' : '') + ((parseAnswerJson(answers[q.id]).calculated_percentage !== null && parseAnswerJson(answers[q.id]).calculated_percentage !== undefined && parseAnswerJson(answers[q.id]).label) ? ' ' : '') + (parseAnswerJson(answers[q.id]).label || '')"></span>
                                                                            </div>
                                                                        </div>
                                                                    </template>
                                                                </div>
                                                            </template>
                                                        </div>
                                                    </template>
                                                </div>

                                                {{-- Peserta's Link Evidence --}}
                                                <div>
                                                    <h4 class="text-[12px] font-bold text-[#64748b] uppercase tracking-[0.5px] mb-[6px]">Tautan BUKTI / Dokumen:</h4>
                                                    <a :href="links[q.id] || '#'" target="_blank"
                                                        :title="links[q.id] || 'Tidak ada tautan'"
                                                        class="inline-flex items-center gap-[10px] w-full min-w-0 bg-white border border-[#cbd5e1] text-[#1b5e20] hover:border-[#1b5e20] hover:bg-[#f2fcf3] px-[16px] py-[12px] rounded-[8px] transition-colors group shadow-sm overflow-hidden">
                                                        <i data-lucide="link-2" class="w-[15px] h-[15px] text-[#90a1b9] group-hover:text-[#1b5e20] shrink-0"></i>
                                                        <span class="text-[13px] font-semibold truncate flex-1 min-w-0"
                                                            x-text="links[q.id] ? (links[q.id].length > 55 ? links[q.id].substring(0, 55) + '...' : links[q.id]) : 'Tidak ada tautan'"></span>
                                                        <i data-lucide="external-link" class="w-[14px] h-[14px] text-[#90a1b9] group-hover:text-[#1b5e20] shrink-0"></i>
                                                    </a>
                                                </div>

                                                {{-- Reviewer Form Input --}}
                                                <div class="pt-[16px] border-t border-[#cbd5e1] relative">

                                                    {{-- Unlock overlay --}}
                                                    <div x-show="!isDone && !unlockedQuestions[q.id] && !reviewerScores[q.id]"
                                                        @click="unlockedQuestions[q.id] = true"
                                                        class="absolute inset-0 z-10 bg-white/80 backdrop-blur-[1px] rounded-[8px] flex items-center justify-center cursor-pointer hover:bg-white/60 transition-all group">
                                                        <div class="flex items-center gap-2 px-4 py-2 bg-white border border-[#cbd5e1] rounded-lg shadow-sm group-hover:border-[#1b5e20] group-hover:shadow-md transition-all">
                                                            <i data-lucide="mouse-pointer-click" class="w-4 h-4 text-[#64748b] group-hover:text-[#1b5e20]"></i>
                                                            <span class="text-[13px] font-semibold text-[#64748b] group-hover:text-[#1b5e20]">Klik untuk mulai menilai</span>
                                                        </div>
                                                    </div>
                                                    {{-- Input Score --}}
                                                    <div class="mb-[16px]">
                                                        <h4 class="text-[13px] font-bold text-[#1d293d] mb-[8px] flex items-center justify-between gap-[6px]">
                                                            <span class="flex items-center gap-[6px]">Berikan Score Final <span class="text-red-500">*</span></span>
                                                            {{-- Inline save indicator kecil --}}
                                                            <span x-show="saveStatus[q.id] === 'saving'" style="display:none;" class="text-[10px] text-orange-500 font-medium inline-flex items-center gap-1">
                                                                <i data-lucide="loader-2" class="w-3 h-3 animate-spin"></i> Menyimpan
                                                            </span>
                                                            <span x-show="saveStatus[q.id] === 'saved'" style="display:none;" class="text-[10px] text-[#1b5e20] font-semibold inline-flex items-center gap-1">
                                                                <i data-lucide="check" class="w-3 h-3"></i> Tersimpan
                                                            </span>
                                                        </h4>
                                                        <div class="flex items-center gap-[12px]">
                                                            <template x-if="isDone && (!reviewerScores[q.id] && reviewerScores[q.id] !== 0)">
                                                                <span class="w-[80px] px-[12px] py-[10px] rounded-[8px] border-2 bg-[#f1f5f9] border-[#cbd5e1] text-[#94a3b8] text-[18px] font-bold text-center">N/A</span>
                                                            </template>
                                                            <template x-if="!isDone || reviewerScores[q.id] || reviewerScores[q.id] === 0">
                                                                <input
                                                                    type="number"
                                                                    min="0"
                                                                    max="5"
                                                                    step="1"
                                                                    placeholder="0-5"
                                                                    class="w-[80px] px-[12px] py-[10px] rounded-[8px] border-2 text-[18px] font-bold focus:outline-none focus:border-[#1b5e20] hover:border-[#1b5e20]/60 transition-colors text-center"
                                                                    :class="isDone ? 'bg-[#f1f5f9] border-[#cbd5e1] text-[#94a3b8]' : 'border-[#cbd5e1] text-[#1b5e20] bg-white'"
                                                                    :disabled="isDone"
                                                                    x-model.number="reviewerScores[q.id]"
                                                                    @input="if(reviewerScores[q.id] < 0) reviewerScores[q.id] = 0; if(reviewerScores[q.id] > 5) reviewerScores[q.id] = 5; reviewerScores[q.id] = parseInt(reviewerScores[q.id], 10) || 0;"
                                                                    @blur="saveQuestionScore(q.id)"
                                                                    @wheel.prevent />
                                                            </template>
                                                            <span class="text-[13px] text-[#64748b] font-medium leading-tight max-w-[200px]">Maks 5. Ketik 0 jika bukti tidak valid.</span>
                                                        </div>
                                                    </div>

                                                    {{-- Input Catatan --}}
                                                    <div>
                                                        <div class="flex items-center justify-between mb-[8px]">
                                                            <h4 class="text-[13px] font-bold text-[#1d293d]">
                                                                Catatan Penilai / Alasan: <span class="text-red-500">*</span>
                                                            </h4>
                                                            <span class="text-[11px] text-[#94a3b8] font-medium">min. 20 karakter</span>
                                                        </div>
                                                        <template x-if="isDone && (!reviewerNotes[q.id] || reviewerNotes[q.id].trim() === '')">
                                                            <div class="w-full text-[14px] p-[12px] rounded-[8px] border-2 bg-[#f1f5f9] border-[#cbd5e1] text-[#94a3b8] italic">Belum diisi</div>
                                                        </template>
                                                        <template x-if="!isDone || (reviewerNotes[q.id] && reviewerNotes[q.id].trim() !== '')">
                                                            <textarea
                                                                rows="3"
                                                                placeholder="Tuliskan alasan mengapa skor diberikan, atau hal yang kurang dari bukti validasi..."
                                                                class="w-full text-[14px] p-[12px] rounded-[8px] border-2 focus:outline-none focus:border-[#1b5e20] hover:border-[#1b5e20]/60 transition-colors resize-y"
                                                                :class="isDone ? 'bg-[#f1f5f9] border-[#cbd5e1] text-[#94a3b8]' : (
                                        !reviewerNotes[q.id] || (reviewerNotes[q.id] || '').trim() === '' ? 'border-red-300 bg-white text-[#1d293d]' :
                                        (reviewerNotes[q.id] || '').trim().length < 20 ? 'border-amber-400 bg-white text-[#1d293d]' :
                                        'border-emerald-400 bg-white text-[#1d293d]'
                                    )"
                                                                :disabled="isDone"
                                                                x-model="reviewerNotes[q.id]"
                                                                @blur="saveQuestionScore(q.id)"></textarea>
                                                        </template>
                                                        {{-- Character counter + warning --}}
                                                        <div class="flex items-center justify-between mt-[4px]">
                                                            {{-- Pesan: wajib diisi --}}
                                                            <div x-show="!isDone && (!reviewerNotes[q.id] || reviewerNotes[q.id].trim() === '')"
                                                                style="display:none;"
                                                                class="flex items-center gap-[4px] text-red-500">
                                                                <svg class="w-[12px] h-[12px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                                                </svg>
                                                                <span class="text-[11px] font-medium">Catatan wajib diisi (min. 20 karakter)</span>
                                                            </div>
                                                            {{-- Pesan: kurang dari 20 --}}
                                                            <div x-show="!isDone && reviewerNotes[q.id] && reviewerNotes[q.id].trim() !== '' && (reviewerNotes[q.id] || '').trim().length < 20"
                                                                style="display:none;"
                                                                class="flex items-center gap-[4px] text-orange-600">
                                                                <svg class="w-[12px] h-[12px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                                                </svg>
                                                                <span class="text-[11px] font-medium" x-text="'Masih kurang ' + (20 - (reviewerNotes[q.id] || '').trim().length) + ' karakter lagi'"></span>
                                                            </div>
                                                            <span class="text-[11px] font-medium ml-auto"
                                                                :class="
                                            !reviewerNotes[q.id] || (reviewerNotes[q.id] || '').trim() === '' ? 'text-red-400' :
                                            (reviewerNotes[q.id] || '').trim().length < 20 ? 'text-orange-500' :
                                            'text-emerald-600'
                                          "
                                                                x-text="(reviewerNotes[q.id] ? (reviewerNotes[q.id] || '').trim().length : 0) + ' / 20 min'"></span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>

                    {{-- TAB: HASIL PENILAIAN --}}
                    <div x-show="activeTab === 'hasil'" x-transition.opacity.duration.300ms style="display: none;" class="space-y-5">

                        {{-- Banner Total Penilaian --}}
                        <div class="rounded-lg p-6 md:p-8 flex flex-col md:flex-row items-start md:items-center justify-between gap-5 relative overflow-hidden"
                            :class="showReviewScore ? 'bg-[#1b5e20]' : 'bg-orange-500'">
                            <div class="relative z-10 flex-1">
                                <template x-if="!showReviewScore">
                                    <div class="flex items-start gap-2 bg-white/20 border border-white/30 rounded-lg px-3 py-2 mb-3 max-w-[420px]">
                                        <svg class="w-3.5 h-3.5 text-white shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        <p class="text-[11px] text-white/90 font-medium leading-relaxed">Nilai estimasi dari peserta</p>
                                    </div>
                                </template>
                                <template x-if="showReviewScore">
                                    <div class="flex items-center gap-2 bg-white/20 border border-white/30 rounded-lg px-3 py-2 mb-3 max-w-[320px]">
                                        <svg class="w-3.5 h-3.5 text-white shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        <p class="text-[11px] text-white/90 font-semibold" x-text="isDone ? 'Nilai Final — Telah Divalidasi Reviewer' : 'Skor Sementara Hasil Penilaian Reviewer'"></p>
                                    </div>
                                </template>
                                <h2 class="text-white font-bold text-[22px] md:text-[28px] leading-tight tracking-tight">
                                    Total Penilaian <span x-text="new Date().getFullYear()"></span>
                                </h2>
                                <p class="text-white/70 text-[13px] md:text-[14px] font-medium mt-1" x-text="institusi.nama_institusi"></p>
                            </div>
                            <div class="relative z-10 shrink-0">
                                <div class="relative w-[170px] h-[170px] md:w-[190px] md:h-[190px]">
                                    <svg class="w-full h-full -rotate-90" viewBox="0 0 120 120">
                                        <circle cx="60" cy="60" r="52" fill="none" stroke="rgba(255,255,255,0.15)" stroke-width="10" />
                                    </svg>
                                    <div class="absolute inset-0 flex flex-col items-center justify-center">
                                        <span class="text-white font-extrabold text-[28px] md:text-[32px] leading-none tracking-tight whitespace-nowrap"
                                            x-text="Number(hasilTotalCapaian).toFixed(0)"></span>
                                        <span class="text-white/80 text-[10px] font-bold tracking-[0.2em] uppercase mt-2"
                                            x-text="showReviewScore ? 'Skor Final' : 'Estimasi Skor'"></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Rincian Poin per Kategori --}}
                        <div class="space-y-4">
                            <div class="flex items-center gap-3 px-1 py-2">
                                <div class="w-[34px] h-[34px] bg-white border border-[#e0e0e0] rounded-lg flex items-center justify-center shrink-0 shadow-sm">
                                    <i data-lucide="bar-chart-2" class="w-[17px] h-[17px] text-[#1b5e20]"></i>
                                </div>
                                <h2 class="font-bold text-[#1d293d] text-[14px] tracking-wider uppercase">Rincian Poin per Kategori</h2>
                            </div>

                            <template x-for="(cat, idx) in hasilCategories" :key="idx">
                                <div class="bg-white border border-[#e0e0e0] rounded-xl overflow-hidden shadow-sm hover:shadow-md transition-shadow duration-300">
                                    <button @click="toggleCategory(idx)"
                                        class="w-full flex items-center justify-between px-6 py-5 hover:bg-[#fcfdfd] transition-colors text-left group">
                                        <div class="flex items-center gap-4">
                                            <div class="w-10 h-10 rounded-full flex items-center justify-center transition-colors duration-300 border border-[#e2e8f0]"
                                                :class="openCategories[idx] ? 'bg-[#e8f5e9] text-[#1b5e20] border-[#c8e6c9]' : 'bg-[#f1f5f9] text-[#475569]'">
                                                <i data-lucide="folder" class="w-5 h-5" x-show="!openCategories[idx]"></i>
                                                <i data-lucide="folder-open" class="w-5 h-5" x-show="openCategories[idx]" style="display:none;"></i>
                                            </div>
                                            <div>
                                                <h3 class="font-bold text-[#1d293d] text-[15px] group-hover:text-[#1b5e20] transition-colors" x-text="cat.name"></h3>
                                                <p class="text-[11px] text-[#64748b] font-medium uppercase tracking-wider mt-0.5">Klik untuk melihat detail pertanyaan</p>
                                            </div>
                                        </div>

                                        <div class="flex items-center gap-6">
                                            <div class="text-right hidden md:block border-r border-[#e2e8f0] pr-6">
                                                <p class="text-[10px] font-bold text-[#94a3b8] uppercase tracking-widest mb-1">Capaian Skor</p>
                                                <div class="flex items-baseline gap-1 justify-end">
                                                    <span class="text-[18px] font-extrabold text-[#1b5e20]"
                                                        x-text="Number(cat.capaian_skor || 0).toFixed(0)"></span>
                                                </div>
                                                <p class="text-[10px] text-[#64748b] font-medium mt-0.5">
                                                    dari bobot <span x-text="Number(cat.bobot || 0).toFixed(0)"></span>
                                                </p>
                                            </div>
                                            <div class="w-9 h-9 rounded-full flex items-center justify-center bg-[#f1f5f9] border border-[#e2e8f0] group-hover:bg-[#e8f5e9] group-hover:border-[#c8e6c9] transition-all duration-300">
                                                <span class="transition-transform duration-300 inline-block" :class="openCategories[idx] ? 'rotate-180' : 'rotate-0'">
                                                    <i data-lucide="chevron-down" class="w-[20px] h-[20px] text-[#475569] group-hover:text-[#1b5e20]"></i>
                                                </span>
                                            </div>
                                        </div>
                                    </button>

                                    <div x-show="openCategories[idx]"
                                        style="display: none;"
                                        class="bg-[#fcfdfd] border-t border-[#f1f5f9]">
                                        <div class="divide-y divide-[#f1f5f9]">
                                            <template x-for="(item, iIdx) in cat.items" :key="iIdx">
                                                <div class="px-6 py-5 hover:bg-white transition-colors">
                                                    <div class="flex items-start justify-between gap-4 mb-4">
                                                        <div class="flex items-start gap-4">
                                                            <div class="w-7 h-7 rounded-lg bg-[#f1f5f9] flex items-center justify-center shrink-0 mt-0.5">
                                                                <span class="text-[12px] font-bold text-[#475569]" x-text="item.no"></span>
                                                            </div>
                                                            <p class="text-[14px] font-semibold text-[#1e293b] leading-relaxed max-w-[500px]" x-text="item.title"></p>
                                                        </div>
                                                        <div class="shrink-0 flex flex-col items-end gap-1.5">
                                                            <template x-if="item.is_validated">
                                                                <div class="flex flex-col items-end">
                                                                    <span class="text-[9px] font-bold text-[#059669] uppercase tracking-[0.1em] mb-1">Skor Akhir</span>
                                                                    <div class="flex items-center gap-1.5 bg-[#ecfdf5] border border-[#10b981]/20 px-3 py-1.5 rounded-lg">
                                                                        <i data-lucide="check-circle" class="w-3.5 h-3.5 text-[#059669]"></i>
                                                                        <span class="text-[13px] font-bold text-[#047857]" x-text="parseInt(item.score) + ' / ' + item.max"></span>
                                                                    </div>
                                                                </div>
                                                            </template>
                                                            <template x-if="!item.is_validated">
                                                                <div class="flex flex-col items-end">
                                                                    <span class="text-[9px] font-bold text-[#d97706] uppercase tracking-[0.1em] mb-1">Estimasi Skor</span>
                                                                    <div class="flex items-center gap-1.5 bg-[#fffbeb] border border-[#f59e0b]/20 px-3 py-1.5 rounded-lg">
                                                                        <span class="text-[13px] font-bold text-[#b45309]" x-text="parseInt(item.score) + ' / ' + item.max"></span>
                                                                    </div>
                                                                </div>
                                                            </template>
                                                        </div>
                                                    </div>

                                                    <div class="ml-[44px] space-y-4">
                                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                            <div class="space-y-1.5">
                                                                <p class="text-[11px] font-bold text-[#64748b] uppercase tracking-wider flex items-center gap-1.5">
                                                                    <i data-lucide="message-square" class="w-3.5 h-3.5"></i> Jawaban Peserta
                                                                </p>
                                                                <div class="bg-white border border-[#f1f5f9] rounded-lg p-3 text-[13px] text-[#334155] font-medium leading-relaxed shadow-sm whitespace-pre-line" x-text="item.jawaban"></div>
                                                            </div>
                                                            <div class="space-y-1.5">
                                                                <p class="text-[11px] font-bold text-[#64748b] uppercase tracking-wider flex items-center gap-1.5">
                                                                    <i data-lucide="link" class="w-3.5 h-3.5"></i> Tautan Bukti
                                                                </p>
                                                                <div class="bg-white border border-[#f1f5f9] rounded-lg p-3 shadow-sm overflow-hidden">
                                                                    <template x-if="item.tautan">
                                                                        <a :href="item.tautan" target="_blank"
                                                                            class="text-[13px] font-bold text-[#1b5e20] hover:text-[#15461c] hover:underline flex items-center gap-2 truncate">
                                                                            <i data-lucide="external-link" class="w-3.5 h-3.5 shrink-0"></i>
                                                                            <span class="truncate" x-text="item.tautan"></span>
                                                                        </a>
                                                                    </template>
                                                                    <template x-if="!item.tautan">
                                                                        <span class="text-[13px] text-[#94a3b8] italic">Tidak ada lampiran</span>
                                                                    </template>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <template x-if="item.catatan">
                                                            <div class="bg-gradient-to-r from-[#fffbeb] to-[#fffde0] border-l-4 border-[#f59e0b] rounded-r-lg p-4 shadow-sm mt-3">
                                                                <p class="text-[11px] font-bold text-[#92400e] uppercase tracking-widest mb-1.5 flex items-center gap-2">
                                                                    <i data-lucide="info" class="w-4 h-4"></i> Catatan Reviewer
                                                                </p>
                                                                <p class="text-[13px] font-semibold text-[#78350f] leading-relaxed whitespace-pre-line" x-text="item.catatan"></p>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                </div>
            </div>

            {{-- Footer sticky area --}}
            <div x-show="!isDone && activeTab === 'penilaian'" class="bg-white border-t border-[#e2e8f0] px-[20px] md:px-[40px] py-[16px] flex flex-col sm:flex-row justify-between items-center gap-[16px] shrink-0 z-10 shadow-[0_-2px_10px_rgba(0,0,0,0.05)]">
                <div class="flex flex-col sm:flex-row items-start sm:items-center gap-[4px] sm:gap-[12px]">
                    <div class="flex items-center gap-[8px] text-[13px] font-bold text-[#1d293d]">
                        <span class="w-[8px] h-[8px] rounded-full bg-green-400 animate-pulse shrink-0"></span>
                        Pastikan Seluruh Nilai Sudah Terisi Sebelum Finalisasi
                    </div>
                    <span class="text-[12px] font-medium text-[#64748b]">Data tersimpan otomatis. Klik Finalisasi jika sudah yakin.</span>
                </div>
                <div class="flex items-center gap-[10px]">
                    {{-- Finalisasi Penilaian --}}
                    <button
                        @click="validateBeforeFinalize()"
                        :disabled="isLocking"
                        class="w-full sm:w-auto bg-[#1b5e20] hover:bg-[#15461c] disabled:opacity-60 text-white px-[24px] py-[10px] rounded-[8px] text-[14px] font-bold flex items-center justify-center gap-[8px] transition-colors shadow-sm focus:ring-4 focus:ring-[#1b5e20]/30 outline-none">
                        <i data-lucide="lock" class="w-[16px] h-[16px]"></i>
                        <span x-text="isLocking ? 'Menyimpan...' : 'Finalisasi Penilaian'"></span>
                    </button>
                </div>
            </div>

            {{-- Lock Confirmation Modal --}}
            <div x-show="showLockConfirm" x-cloak
                class="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 px-4"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0">
                <div class="bg-white rounded-[16px] shadow-2xl w-full max-w-[420px] p-[28px] border border-[#e2e8f0]">
                    <div class="flex items-center gap-3 mb-[16px]">
                        <div class="w-[44px] h-[44px] rounded-full bg-orange-50 flex items-center justify-center shrink-0">
                            <i data-lucide="alert-triangle" class="w-[22px] h-[22px] text-orange-500"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-[#1d293d] text-[16px]">Finalisasi Penilaian?</h3>
                            <p class="text-[13px] text-[#64748b] mt-[2px]">Tindakan ini tidak dapat dibatalkan.</p>
                        </div>
                    </div>
                    <p class="text-[14px] text-[#45556c] leading-relaxed mb-[20px]">
                        Setelah Anda mengkonfirmasi, status peserta akan berubah menjadi <strong class="text-green-700">GRADED</strong> dan peserta dapat melihat hasil penilaian final dari Anda. Pastikan semua skor sudah benar.
                    </p>
                    <div class="flex gap-[10px] justify-end">
                        <button @click="showLockConfirm = false"
                            class="px-[20px] py-[10px] rounded-[8px] border-2 border-slate-200 text-[14px] font-semibold text-slate-600 hover:border-slate-300 transition-colors">
                            Batal
                        </button>
                        <button @click="lockReview()"
                            class="px-[20px] py-[10px] rounded-[8px] bg-[#1b5e20] text-white text-[14px] font-bold hover:bg-[#15461c] transition-colors flex items-center gap-2">
                            <i data-lucide="check" class="w-[16px] h-[16px]"></i>
                            Ya, Finalisasi
                        </button>
                    </div>
                </div>
            </div>

            {{-- ================================================================== --}}
            {{-- 🧭 FLOATING QUIZ NAVIGATION DRAWER                                --}}
            {{-- ================================================================== --}}
            <div x-show="!loading && activeTab === 'penilaian'" style="display:none;" x-cloak>
                {{-- Toggle Arrow Button --}}
                <button
                    type="button"
                    @click="drawerOpen = !drawerOpen"
                    class="fixed right-0 z-40 bg-[#1b5e20] text-white shadow-lg flex items-center justify-center"
                    :style="{
                  top: (typeof showBar !== 'undefined' && showBar) ? 'calc(120px + ((100vh - 120px) / 2) - 32px)' : 'calc(50vh - 32px)',
                  width: '28px',
                  height: '64px',
                  borderRadius: '8px 0 0 8px',
                  transition: 'top 0.3s ease'
              }"
                    :title="drawerOpen ? 'Tutup Navigator' : 'Buka Navigator'">
                    <svg xmlns="http://www.w3.org/2000/svg"
                        class="w-4 h-4 transition-transform duration-300"
                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="15 18 9 12 15 6" />
                    </svg>
                </button>

                {{-- Drawer Backdrop --}}
                <div
                    x-show="drawerOpen"
                    x-transition.opacity.duration.200ms
                    @click="drawerOpen = false"
                    class="fixed inset-0 bg-black/20 z-30"
                    style="display:none;">
                </div>

                {{-- Drawer Panel --}}
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
                  top: (typeof showBar !== 'undefined' && showBar) ? '120px' : '0px',
                  height: (typeof showBar !== 'undefined' && showBar) ? 'calc(100vh - 120px)' : '100vh',
                  transition: 'top 0.3s ease, height 0.3s ease'
              }">

                    {{-- Drawer Header --}}
                    <div class="px-4 py-4 border-b border-[#e0e0e0] flex items-center justify-between shrink-0">
                        <div>
                            <h3 class="font-bold text-[#1d293d] text-[13px] uppercase tracking-wide">Navigator</h3>
                            <p class="text-[11px] text-[#62748e] mt-0.5">
                                <span class="font-semibold text-[#1b5e20]" x-text="totalAnswered"></span>
                                / <span x-text="allQuestions.length"></span> dinilai
                                <template x-if="totalFlagged > 0">
                                    <span class="ml-2 text-red-500 font-semibold">
                                        · <span x-text="totalFlagged"></span> flag
                                    </span>
                                </template>
                            </p>
                        </div>
                        <button type="button" @click="drawerOpen = false" class="text-[#90a1b9] hover:text-[#45556c] p-1 rounded">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="18" y1="6" x2="6" y2="18" />
                                <line x1="6" y1="6" x2="18" y2="18" />
                            </svg>
                        </button>
                    </div>

                    {{-- Legend --}}
                    <div class="px-4 pt-3 pb-2 flex flex-wrap items-center gap-3 text-[10px] font-medium text-[#62748e] shrink-0">
                        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm bg-[#1b5e20]"></span> Dinilai</span>
                        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm bg-yellow-400"></span> Sebagian</span>
                        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm bg-[#e0e0e0]"></span> Kosong</span>
                        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm bg-red-500"></span> Ditandai</span>
                    </div>

                    {{-- Question Grid --}}
                    <div class="flex-1 overflow-y-auto px-4 py-2 space-y-5 pb-8">
                        <template x-for="(catData, ci) in rubrikData" :key="ci">
                            <div>
                                <p class="text-[10px] font-bold text-[#62748e] uppercase tracking-wider mb-2" x-text="catData.kategori"></p>
                                <div class="flex flex-wrap gap-1.5">
                                    <template x-for="q in catData.pertanyaan" :key="q.id">
                                        <button
                                            type="button"
                                            @click="scrollToQuestion(q.id)"
                                            :title="'Indikator ' + q.kode_pertanyaan + (isFlagged(q.id) ? ' (Flag)' : '') + (fillStatus(q.id) === 2 ? ' ✓' : fillStatus(q.id) === 1 ? ' (sebagian)' : '')"
                                            class="relative w-9 h-9 rounded text-[11px] font-bold transition-all duration-150 focus:outline-none hover:scale-110 hover:shadow-md overflow-hidden"
                                            :class="isFlagged(q.id) ? 'text-white' : fillStatus(q.id) === 2 ? 'text-white' : 'text-[#62748e]'"
                                            :style="isFlagged(q.id) ? 'background:#ef4444;' : fillStatus(q.id) === 2 ? 'background:#1b5e20;' : fillStatus(q.id) === 1 ? 'background:#facc15;' : 'background:#e0e0e0;'"
                                            x-text="q.kode_pertanyaan">
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>{{-- end !loading --}}
    </div>
</x-dynamic-component>