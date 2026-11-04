{{-- ===================================================== --}}
{{-- DASHBOARD HEADER - Baris 1: Logo + Info User --}}
{{-- Customize: nama user, institusi, avatar di sini --}}
{{-- ===================================================== --}}



    {{-- Header Row --}}
    <div class="flex items-center justify-between px-6 md:px-10 h-[72px] border-b border-[#e0e0e0]">

    {{-- Logo kiri --}}
    @php
        $logoLink = request()->is('reviewer*') || request()->routeIs('reviewer.*') ? route('reviewer.index') : route('dashboard.index');
    @endphp
    <a href="{{ $logoLink }}" wire:navigate class="flex items-center shrink-0">
        <div class="h-[48px] lg:h-[73px] w-[81px] lg:w-[124px] relative shrink-0">
            <img alt="Patriot Metric"
                class="absolute inset-0 max-w-none object-cover lg:object-contain pointer-events-none size-full"
                src="{{ asset('assets/images/b89aca8b9cc2d0494234bedd13382da054b48ab6.webp') }}" />
        </div>
    </a>

    {{-- User Info kanan + Avatar Dropdown --}}
    <div class="flex items-center gap-3 shrink-0" x-data="{ 
                 userMenuOpen: false,
                 userData: (function() {
                     // Baca cache synchronous sebelum Alpine init agar tidak ada flash '...'
                     try {
                         const cached = localStorage.getItem('profile_data_cache');
                         if (cached) {
                             const result = JSON.parse(cached);
                             const user = result.user || {};
                             const p = result.pengumpulan;
                             const role = (user.role || '').toLowerCase();
                             if (p) {
                                 const namaPt = p.institusi ? p.institusi.nama_institusi : 'Perguruan Tinggi Terdaftar';
                                 return {
                                     nama_pic: p.nama_pic || user.email || '',
                                     nama_pt: namaPt,
                                     avatar: namaPt.substring(0, 2).toUpperCase(),
                                     logo_url: (p.institusi && p.institusi.logo_url_full) ? p.institusi.logo_url_full : null,
                                     is_reviewer: false
                                 };
                             } else if (role === 'reviewer') {
                                 const reviewerName = user.nama_lengkap || user.name || 'Nama Reviewer';
                                 return {
                                     nama_pic: reviewerName,
                                     nama_pt: 'Reviewer Patriot Metric',
                                     avatar: reviewerName.substring(0, 2).toUpperCase(),
                                     logo_url: null,
                                     is_reviewer: true
                                 };
                             }
                         }
                     } catch(e) {}
                     return { nama_pic: '', nama_pt: '', avatar: '', logo_url: null, is_reviewer: false };
                 })(),
                    processUserData(user, p) {
                        const role = (user.role || '').toLowerCase();
                        // 1. Check if redirection is needed for Peserta
                        if (role === 'peserta') {
                            const isAtVerifikasi = window.location.pathname.includes('/verifikasi');
                            if (!p || p.status === 'UNVERIFIED') {
                                if (!isAtVerifikasi) {
                                    window.location.href = '/verifikasi';
                                    return true;
                                }
                            } else if (isAtVerifikasi) {
                                window.location.href = '/dashboard';
                                return true;
                            }
                        }

                     // 2. Map user data for display
                        if (p) {
                            this.userData.nama_pic = p.nama_pic || user.email;
                            this.userData.nama_pt = p.institusi ? p.institusi.nama_institusi : 'Perguruan Tinggi Terdaftar';
                            this.userData.avatar = this.userData.nama_pt.substring(0, 2).toUpperCase();
                            this.userData.logo_url = (p.institusi && p.institusi.logo_url_full) ? p.institusi.logo_url_full : null;
                            this.userData.is_reviewer = false;
                        } else {
                           const reviewerName = user.nama_lengkap || user.name || 'Nama Reviewer';
                           this.userData.nama_pic = reviewerName;
                           if (role === 'reviewer') {
                               this.userData.nama_pt = 'Reviewer Patriot Metric';
                               this.userData.avatar = reviewerName.substring(0, 2).toUpperCase();
                               this.userData.logo_url = null;
                               this.userData.is_reviewer = true;
                           } else {
                               this.userData.is_reviewer = false;
                           }
                        }
                     return false;
                 },

                 async init() {
                     try {
                         const token = localStorage.getItem('auth_token');
                         if (!token) {
                             if (!window.location.pathname.includes('/reviewer')) {
                                 window.location.href = '/masuk';
                             }
                             return;
                         }

                         // Selalu tampilkan data dari cache terlebih dahulu (tidak ada flash)
                         const cached = localStorage.getItem('profile_data_cache');
                         if (cached) {
                             try {
                                 const result = JSON.parse(cached);
                                 this.processUserData(result.user, result.penugasan || result.Penugasan);
                             } catch (e) {}
                         }

                         // Hanya fetch API sekali per sesi browser (skip saat wire:navigate)
                         if (window._headerApiInitDone) return;
                         window._headerApiInitDone = true;

                         const res = await fetch('/api/auth/me', {
                             headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' }
                         });
                         const result = await res.json();
                         if (res.ok && result.success) {
                             localStorage.setItem('profile_data_cache', JSON.stringify(result.data));
                             this.processUserData(result.data.user, result.data.penugasan);
                         } else {
                             if (!window.location.pathname.includes('/reviewer')) {
                                 localStorage.removeItem('auth_token');
                                 localStorage.removeItem('auth_user');
                                 localStorage.removeItem('profile_data_cache');
                                 localStorage.removeItem('profile_data_cache_at');
                                 sessionStorage.clear();
                                 window.location.href = '/masuk';
                             }
                         }
                     } catch (e) { console.error(e); }
                 }
             }" @click.outside="userMenuOpen = false">
        <template x-if="userData.is_reviewer">
            <div
                class="h-[24px] px-[8px] bg-[#1b5e20] text-white text-[11px] font-bold rounded flex items-center justify-center uppercase tracking-wider hidden sm:flex">
                Reviewer
            </div>
        </template>
        <div class="text-right hidden sm:block">
            <p class="font-bold text-[#1d293d] text-[14px] leading-[20px]"
                x-text="userData.is_reviewer ? userData.nama_pic : userData.nama_pt"></p>
        </div>

        {{-- Avatar Dropdown Trigger --}}
        <div class="relative">
            <button @click="userMenuOpen = !userMenuOpen"
                class="w-[40px] h-[40px] bg-[#e8f5e9] rounded-full flex items-center justify-center shrink-0 hover:opacity-80 transition-opacity ring-2 ring-[#1b5e20]/20 focus:outline-none overflow-hidden">
                <img :src="userData.logo_url" alt="Logo Instansi" class="w-full h-full object-cover"
                    x-show="userData.logo_url">
                <span x-show="!userData.logo_url"
                    class="w-full h-full flex items-center justify-center bg-[#1b5e20] text-white font-bold text-[14px] rounded-full"
                    x-text="userData.avatar ? userData.avatar.substring(0, 2).toUpperCase() : 'RV'"></span>
            </button>

            {{-- Dropdown Menu --}}
            <div x-show="userMenuOpen" x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 scale-95 translate-y-1"
                x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                x-transition:leave="transition ease-in duration-100"
                x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                x-transition:leave-end="opacity-0 scale-95 translate-y-1"
                class="absolute right-0 top-full mt-2 w-[200px] bg-white rounded-xl shadow-xl border border-[#f1f5f9] py-1.5 z-[60]"
                style="display:none;">
                {{-- User info in dropdown (mobile) --}}
                <div class="sm:hidden px-4 py-2.5 border-b border-[#f1f5f9] mb-1">
                    <p class="font-bold text-[#1d293d] text-[13px] leading-[18px]"
                        x-text="userData.is_reviewer ? userData.nama_pic : userData.nama_pt"></p>
                </div>

                    {{-- Ganti Password Option --}}
                    <button @click="userMenuOpen = false; $dispatch('open-password-modal')"
                            class="w-full flex items-center gap-2.5 px-4 py-2.5 text-[13px] font-medium text-[#45556c] hover:text-[#1b5e20] hover:bg-[#f0fdf4] transition-colors">
                        <i data-lucide="key-round" class="w-4 h-4"></i>
                        Ganti Password
                    </button>

                    <div class="border-t border-[#f1f5f9] mx-3 my-1"></div>

                {{-- Keluar Option --}}
                <button @click="
                            fetch('/api/auth/logout', { method: 'POST', headers: { 'Authorization': 'Bearer ' + localStorage.getItem('auth_token'), 'Accept': 'application/json' } }).finally(() => {
                                localStorage.removeItem('auth_token');
                                localStorage.removeItem('auth_user');
                                localStorage.removeItem('user_status');
                                localStorage.removeItem('penugasan_status');
                                localStorage.removeItem('rubrik_data_cache');
                                localStorage.removeItem('profile_data_cache');
                                localStorage.removeItem('profile_data_cache_at');
                                sessionStorage.clear();
                                window.location.href = '/masuk';
                            });
                        "
                        class="w-full flex items-center gap-2.5 px-4 py-2.5 text-[13px] font-medium text-[#e53935] hover:text-[#b71c1c] hover:bg-red-50 transition-colors">
                        <i data-lucide="log-out" class="w-4 h-4"></i>
                        Keluar
                    </button>
                </div>
            </div>
        </div>
    </div>
