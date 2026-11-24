<x-layouts.reviewer>
    <x-slot:title>DASHBOARD UTAMA</x-slot:title>

    <div x-data="{
        isLoading: true,
        tasks: [],
        filter: 'all',
        summary: {
            total_tugas: 0,
            menunggu_review: 0,
            selesai_review: 0
        },

        async init() {
            const cacheKey = 'reviewer_tasks_cache';
            try {
                const cached = sessionStorage.getItem(cacheKey);
                if (cached) {
                    this.applyData(JSON.parse(cached));
                    this.isLoading = false;
                    this.fetchData(false); {{-- background refresh --}}
                    return;
                }
            } catch(e) {}
            await this.fetchData(true);
        },

        applyData(data) {
            this.summary = {
                total_tugas:     data.total_tugas     || 0,
                menunggu_review: data.menunggu_review || 0,
                selesai_review:  data.selesai_review  || 0,
            };
            this.tasks = data.daftar_penugasan || [];
        },

        async fetchData(showLoading = true) {
            if (showLoading) this.isLoading = true;
            try {
                const res  = await fetch('/api/penugasan/reviewer/tasks', {
                    headers: {
                        'Accept':           'application/json',
                        'Authorization':    'Bearer ' + localStorage.getItem('auth_token'),
                        'X-Requested-With': 'XMLHttpRequest',
                    }
                });
                if (!res.ok) {
                    if (res.status === 401) { window.location.href = '/masuk'; return; }
                    const errJson = await res.json().catch(() => null);
                    throw new Error(errJson?.message || ('Server error: ' + res.status));
                }
                const json = await res.json();
                if (json.success) {
                    try { sessionStorage.setItem('reviewer_tasks_cache', JSON.stringify(json.data)); } catch(e) {}
                    this.applyData(json.data);
                }
            } catch (error) {
                console.error('Error fetching tasks:', error);
            } finally {
                this.isLoading = false;
                this.$nextTick(() => { if (typeof lucide !== 'undefined') lucide.createIcons(); });
            }
        },

        async refreshData() { await this.fetchData(false); },

        get pendingTasks() {
            return this.tasks.filter(t => t.status === 'SUBMITTED');
        },

        get filteredTasks() {
            if (this.filter === 'active')   return this.tasks.filter(t => t.status === 'ACTIVE');
            if (this.filter === 'progress') return this.tasks.filter(t => t.status === 'IN_PROGRESS');
            if (this.filter === 'pending')  return this.tasks.filter(t => t.status === 'SUBMITTED');
            if (this.filter === 'done')     return this.tasks.filter(t => t.status === 'GRADED' || t.status === 'VALIDATING' || t.status === 'FINALIZED' || t.status === 'PUBLISHED');
            return this.tasks;
        },

        formatDate(dateString) {
            if (!dateString) return '-';
            return new Date(dateString).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
        },

        getInitials(name) {
            if (!name) return 'NA';
            const words = name.split(' ');
            if (words.length >= 2) return (words[0][0] + words[1][0]).toUpperCase();
            return name.substring(0, 2).toUpperCase();
        }
    }"
    class="bg-[#f5f5f5] min-h-full py-5 px-4 md:px-8">

        <div class="max-w-[1000px] mx-auto space-y-5">

            {{-- Header Greeting --}}
            <div class="flex items-center justify-between bg-white p-5 md:p-6 rounded-lg border border-[#e0e0e0]">
                <div>
                    <h1 class="text-[18px] md:text-[20px] font-bold text-[#1d293d]">Selamat Datang, Tim Penilai!</h1>
                    <p class="text-[13px] text-[#62748e] mt-1">
                        Anda memiliki
                        <span x-text="summary.menunggu_review" class="font-bold text-violet-600">0</span>
                        tugas review yang belum diselesaikan. Mari selesaikan tenggat waktu penilaian.
                    </p>
                </div>
                {{-- Refresh button --}}
                <button type="button" @click="refreshData()"
                    class="ml-4 shrink-0 p-2 rounded-lg border border-[#e0e0e0] text-[#62748e] hover:border-[#1b5e20] hover:text-[#1b5e20] transition-colors focus:outline-none"
                    title="Perbarui data">
                    <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                </button>
            </div>

            {{-- ===== LOADING STATE ===== --}}
            <div x-show="isLoading">
                <x-dashboard.loading
                    title="Memuat Data Plotting..."
                    caption="Sistem sedang mengambil daftar plottingan dan ringkasan tugas Anda." />
            </div>

            {{-- ===== KONTEN UTAMA ===== --}}
            <div x-show="!isLoading" x-cloak class="space-y-5">

                {{-- Summary Cards --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                    <div class="bg-white rounded-lg p-5 border border-[#e0e0e0] flex items-center gap-4">
                        <div class="w-12 h-12 rounded-lg bg-purple-50 border border-purple-100 text-purple-500 flex items-center justify-center shrink-0">
                            <i data-lucide="clock" class="w-6 h-6"></i>
                        </div>
                        <div>
                            <p class="text-[13px] font-semibold text-[#64748b]">Belum Dinilai</p>
                            <h3 class="text-[24px] font-bold text-[#1d293d] mt-0.5 leading-none" x-text="summary.menunggu_review">0</h3>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg p-5 border border-[#e0e0e0] flex items-center gap-4">
                        <div class="w-12 h-12 rounded-lg bg-blue-50 border border-blue-100 text-blue-500 flex items-center justify-center shrink-0">
                            <i data-lucide="users" class="w-6 h-6"></i>
                        </div>
                        <div>
                            <p class="text-[13px] font-semibold text-[#64748b]">Total Di-Plotting</p>
                            <h3 class="text-[24px] font-bold text-[#1d293d] mt-0.5 leading-none" x-text="summary.total_tugas">0</h3>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg p-5 border border-[#e0e0e0] flex items-center gap-4">
                        <div class="w-12 h-12 rounded-lg bg-emerald-50 border border-emerald-100 text-emerald-500 flex items-center justify-center shrink-0">
                            <i data-lucide="check-circle" class="w-6 h-6"></i>
                        </div>
                        <div>
                            <p class="text-[13px] font-semibold text-[#64748b]">Selesai Dinilai</p>
                            <h3 class="text-[24px] font-bold text-[#1d293d] mt-0.5 leading-none" x-text="summary.selesai_review">0</h3>
                        </div>
                    </div>
                </div>

                {{-- TABEL 1: Urgent — Belum Dinilai (SUBMITTED) --}}
                <div class="bg-white rounded-lg border border-[#e0e0e0] overflow-hidden">
                    <div class="px-5 md:px-6 py-4 md:py-5 border-b border-[#e2e8f0] flex justify-between items-center bg-[#f8fafc]">
                        <h2 class="font-bold text-[#1b5e20] text-[15px] flex items-center gap-2">
                            <i data-lucide="alert-circle" class="text-purple-500 w-[18px] h-[18px]"></i>
                            Peserta Belum Direview <span class="text-purple-600">(Perlu Tindakan)</span>
                        </h2>
                        <span class="text-[12px] font-bold bg-purple-50 text-purple-600 px-2.5 py-1 rounded-full" x-text="pendingTasks.length + ' peserta'"></span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-[#f8fafc] border-b border-[#e2e8f0]">
                                    <th class="py-4 px-6 font-semibold text-[#64748b] text-[12px] uppercase tracking-wider w-12">No</th>
                                    <th class="py-4 px-6 font-semibold text-[#64748b] text-[12px] uppercase tracking-wider">Nama Perguruan Tinggi</th>
                                    <th class="py-4 px-6 font-semibold text-[#64748b] text-[12px] uppercase tracking-wider">Tgl Submit</th>
                                    <th class="py-4 px-6 font-semibold text-[#64748b] text-[12px] uppercase tracking-wider text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#e2e8f0]">
                                <template x-for="(task, index) in pendingTasks" :key="task.id">
                                    <tr class="hover:bg-[#f1f5f9] transition-colors">
                                        <td class="py-4 px-6 text-[14px] text-[#1d293d] font-medium" x-text="index + 1"></td>
                                        <td class="py-4 px-6">
                                            <div class="flex items-center gap-3">
                                                <div class="w-9 h-9 rounded-full bg-purple-50 text-purple-600 flex items-center justify-center font-bold text-[13px] shrink-0"
                                                     x-text="getInitials(task.institusi?.nama_institusi)"></div>
                                                <span class="text-[14px] font-semibold text-[#1d293d]" x-text="task.institusi?.nama_institusi || 'Perguruan Tinggi Tidak Diketahui'"></span>
                                            </div>
                                        </td>
                                        <td class="py-4 px-6 text-[13px] text-[#64748b]" x-text="formatDate(task.updated_at)"></td>
                                        <td class="py-4 px-6 text-right">
                                            <a :href="'/reviewer/peserta/' + task.id" wire:navigate
                                               class="inline-flex items-center gap-1.5 bg-[#1b5e20] text-white px-4 py-2 rounded-lg text-[13px] font-semibold hover:bg-[#15461c] transition-colors">
                                                <i data-lucide="edit" class="w-3.5 h-3.5"></i> Nilai Sekarang
                                            </a>
                                        </td>
                                    </tr>
                                </template>
                                <tr x-show="pendingTasks.length === 0">
                                    <td colspan="4" class="py-8 text-center text-[#64748b]">
                                        <i data-lucide="check-circle-2" class="w-8 h-8 mx-auto text-emerald-500 mb-2"></i>
                                        <p class="text-[13px] font-medium">Hebat! Tidak ada tugas review yang tertunda.</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- TABEL 2: Keseluruhan Plotting --}}
                <div class="bg-white rounded-lg border border-[#e0e0e0] overflow-hidden">
                    <div class="px-5 md:px-6 py-4 md:py-5 border-b border-[#e2e8f0] flex flex-col md:flex-row justify-between items-start md:items-center gap-4 bg-[#f8fafc]">
                        <h2 class="font-bold text-[#1b5e20] text-[15px] flex items-center gap-2">
                            <i data-lucide="list" class="text-blue-500 w-[18px] h-[18px]"></i>
                            Daftar Plotting Peserta Anda
                        </h2>
                        <div class="flex items-center gap-3">
                            <span class="text-[13px] font-medium text-[#64748b]">Filter Status:</span>
                            <select x-model="filter"
                                class="border border-[#cbd5e1] rounded-lg px-3 py-2 text-[13px] font-semibold text-[#45556c] focus:outline-none focus:border-[#1b5e20]">
                                <option value="all">Semua Status</option>
                                <option value="active">Belum Mengisi</option>
                                <option value="progress">Sedang Mengisi</option>
                                <option value="pending">Belum Dinilai (Submitted)</option>
                                <option value="done">Selesai Dinilai</option>
                            </select>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-[#f8fafc] border-b border-[#e2e8f0]">
                                    <th class="py-4 px-6 font-semibold text-[#64748b] text-[12px] uppercase tracking-wider">Nama Perguruan Tinggi</th>
                                    <th class="py-4 px-6 font-semibold text-[#64748b] text-[12px] uppercase tracking-wider">Tgl Submit</th>
                                    <th class="py-4 px-6 font-semibold text-[#64748b] text-[12px] uppercase tracking-wider">Status</th>
                                    <th class="py-4 px-6 font-semibold text-[#64748b] text-[12px] uppercase tracking-wider text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#e2e8f0]">
                                <template x-for="task in filteredTasks" :key="task.id">
                                    <tr class="hover:bg-[#f1f5f9] transition-colors">
                                        <td class="py-4 px-6">
                                            <div class="flex items-center gap-3">
                                                <div class="w-9 h-9 rounded-full flex items-center justify-center font-bold text-[13px] shrink-0"
                                                     :class="task.status === 'SUBMITTED' ? 'bg-purple-50 text-purple-600' : (task.status === 'GRADED' || task.status === 'VALIDATING' || task.status === 'FINALIZED' || task.status === 'PUBLISHED') ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-500'"
                                                     x-text="getInitials(task.institusi?.nama_institusi)"></div>
                                                <div>
                                                    <p class="text-[14px] font-semibold text-[#1d293d]" x-text="task.institusi?.nama_institusi || '-'"></p>
                                                    <p class="text-[12px] text-[#64748b]" x-text="task.institusi?.jenis_institusi || '-'"></p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="py-4 px-6 text-[13px] text-[#64748b]" x-text="formatDate(task.updated_at)"></td>
                                        <td class="py-4 px-6">
                                            <span x-show="task.status === 'ACTIVE'"
                                                  class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-slate-100 text-slate-600 text-[11px] font-bold">
                                                <i data-lucide="circle" class="w-3 h-3"></i> Belum Mengisi
                                            </span>
                                            <span x-show="task.status === 'IN_PROGRESS'"
                                                  class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-blue-50 text-blue-600 text-[11px] font-bold">
                                                <i data-lucide="loader-2" class="w-3 h-3 animate-spin"></i> Sedang Mengisi
                                            </span>
                                            <span x-show="task.status === 'SUBMITTED'"
                                                  class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-purple-50 text-purple-600 text-[11px] font-bold">
                                                <i data-lucide="clock" class="w-3 h-3"></i> Belum Dinilai
                                            </span>
                                            <span x-show="task.status === 'GRADED'"
                                                  class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-600 text-[11px] font-bold">
                                                <i data-lucide="check-circle-2" class="w-3 h-3"></i> Selesai
                                            </span>
                                            <span x-show="task.status === 'VALIDATING'"
                                                  class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-indigo-50 text-indigo-600 text-[11px] font-bold">
                                                <i data-lucide="clipboard-check" class="w-3 h-3"></i> Validating
                                            </span>
                                            <span x-show="task.status === 'FINALIZED'"
                                                  class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-amber-50 text-amber-600 text-[11px] font-bold">
                                                <i data-lucide="check-square" class="w-3 h-3"></i> Finalized
                                            </span>
                                            <span x-show="task.status === 'PUBLISHED'"
                                                  class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-cyan-50 text-cyan-600 text-[11px] font-bold">
                                                <i data-lucide="globe" class="w-3 h-3"></i> Published
                                            </span>
                                        </td>
                                        <td class="py-4 px-6 text-right">
                                            <a :href="'/reviewer/peserta/' + task.id" wire:navigate
                                               class="inline-flex items-center gap-1.5 bg-white border border-[#cbd5e1] text-[#45556c] px-4 py-2 rounded-lg text-[13px] font-semibold hover:bg-[#f1f5f9] transition-colors">
                                                <i data-lucide="eye" class="w-3.5 h-3.5"></i> Lihat Detail
                                            </a>
                                        </td>
                                    </tr>
                                </template>
                                <tr x-show="filteredTasks.length === 0">
                                    <td colspan="4" class="py-6 text-center text-[#64748b] text-[13px] font-medium">
                                        Tidak ada data untuk status ini.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>{{-- end !isLoading --}}

        </div>
    </div>
</x-layouts.reviewer>