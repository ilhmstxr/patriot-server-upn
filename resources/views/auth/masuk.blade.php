<x-layouts.app :hideNav="true" :hideFooter="true">
    {{-- Guard: jika sudah login, redirect sesuai status --}}
    <script>
        (function() {
            // If coming from email verification, clear old session
            const params = new URLSearchParams(window.location.search);
            if (params.get('verified') === '1') {
                localStorage.removeItem('auth_token');
                localStorage.removeItem('auth_user');
                localStorage.removeItem('user_status');
                localStorage.removeItem('penugasan_status');
                localStorage.removeItem('token_expires_at');
                return;
            }

            const token = localStorage.getItem('auth_token');
            if (token) {
                // Cek apakah token sudah expired
                const expiresAt = localStorage.getItem('token_expires_at');
                if (expiresAt) {
                    const now = new Date().getTime();
                    const exp = new Date(expiresAt).getTime();
                    if (now > exp) {
                        localStorage.removeItem('auth_token');
                        localStorage.removeItem('auth_user');
                        localStorage.removeItem('user_status');
                        localStorage.removeItem('penugasan_status');
                        localStorage.removeItem('token_expires_at');
                        return;
                    }
                }
                const user = JSON.parse(localStorage.getItem('auth_user') || '{}');
                const role = (user.role || '').toLowerCase();
                if (role === 'reviewer') {
                    window.location.replace('/reviewer');
                    return;
                }

                const userStatus = localStorage.getItem('user_status') || 'ACTIVE';
                const penugasanStatus = localStorage.getItem('penugasan_status') || 'UNVERIFIED';
                if (userStatus === 'UNVERIFIED') {
                    window.location.replace('/cek-email');
                } else if (penugasanStatus === 'UNVERIFIED') {
                    window.location.replace('/verifikasi');
                } else {
                    window.location.replace('/dashboard');
                }
            }
        })();
    </script>

    <div class="min-h-screen flex items-center justify-center relative overflow-hidden py-12">
        {{-- Full Background --}}
        <img src="{{ asset('assets/images/mhs.webp') }}" class="absolute inset-0 w-full h-full object-cover" alt="Background" />
        <div class="absolute inset-0 bg-black/40 backdrop-blur-[2px]"></div>

        {{-- Anchor: Kembali ke Halaman Utama --}}
        <a href="{{ url('/') }}" class="fixed top-5 left-5 z-[50] inline-flex items-center gap-2 bg-white/15 hover:bg-white/25 backdrop-blur-sm border border-white/30 text-white px-4 py-2 rounded-full text-[13px] font-semibold transition-all duration-200 hover:shadow-lg">
            <i data-lucide="arrow-left" class="w-4 h-4"></i>
            Halaman Utama
        </a>

        <div class="relative z-10 flex flex-col lg:flex-row w-full max-w-[1300px] mx-auto items-center justify-between px-6 lg:px-12 gap-12">
            {{-- Left Content --}}
            <div class="w-full lg:w-1/2 flex flex-col justify-center text-center lg:text-left">
                <div class="-mb-12 mt-4 flex justify-center lg:justify-start">
                    <img src="{{ asset('assets/images/b89aca8b9cc2d0494234bedd13382da054b48ab6.webp') }}" alt="Logo Patriot Metric" class="h-100 w-auto object-contain" />
                </div>
                <h1 class="font-['Plus_Jakarta_Sans',sans-serif] font-bold text-[36px] md:text-[50px] leading-[1.1] text-white max-w-[500px] mx-auto lg:mx-0">
                    Selamat Datang di Patriot Metric
                </h1>
                <p class="mt-6 md:mt-8 font-['Plus_Jakarta_Sans',sans-serif] font-normal text-[18px] md:text-[22px] leading-relaxed text-[rgba(255,255,255,0.9)] max-w-[500px] mx-auto lg:mx-0">
                    Masuk untuk mengakses dashboard perguruan tinggi Anda, memperbarui data rubrik, dan memantau perkembangan nilai bela negara perguruan tinggi.
                </p>
            </div>

            {{-- Right Floating Form --}}
            <div class="w-full lg:w-[480px] bg-white rounded-[32px] shadow-[0_25px_50px_-12px_rgba(0,0,0,0.5)] p-8 md:p-10 border border-white/20"
                x-data="{
                    email: '',
                    password: '',
                    showPassword: false,
                    isLoading: false,
                    errorMessage: '',
                    successMessage: '',
                    init() {
                        // Handle ?verified=1 query param
                        const params = new URLSearchParams(window.location.search);
                        if (params.get('verified') === '1') {
                            Swal.fire({
                                icon: 'success',
                                title: 'Email Terverifikasi!',
                                text: 'Email berhasil diverifikasi! Silakan login.',
                                confirmButtonColor: '#1b5e20'
                            });
                            // Clean URL
                            window.history.replaceState({}, document.title, '/masuk');
                        }
                    },
                    async login() {
                        this.isLoading = true;
                        this.errorMessage = '';
                        this.successMessage = '';
                        try {
                            const response = await fetch('/api/auth/login', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json'
                                },
                                body: JSON.stringify({
                                    email: this.email,
                                    password: this.password
                                })
                            });

                            const result = await response.json();

                            if (response.ok && result.success) {
                                this.successMessage = result.message;

                                // Reset cache bersih menyeluruh: sisa sesi/cache user sebelumnya
                                // (profil, rubrik, hasil) tidak boleh terbawa ke akun yang baru login.
                                localStorage.clear();
                                sessionStorage.clear();

                                // Simpan token untuk API calls selanjutnya
                                localStorage.setItem('auth_token', result.data.token);
                                localStorage.setItem('auth_user', JSON.stringify(result.data.user));
                                localStorage.setItem('user_status', result.data.user_status || 'ACTIVE');
                                localStorage.setItem('penugasan_status', result.data.penugasan_status || 'UNVERIFIED');
                                if (result.data.token_expires_at) {
                                    localStorage.setItem('token_expires_at', result.data.token_expires_at);
                                }

                                // Redirect berdasarkan status
                                let redirectTo = result.data.redirect_to || '/dashboard';

                                Swal.fire({
                                    icon: 'success',
                                    title: 'Login Berhasil!',
                                    text: result.message || 'Selamat datang kembali.',
                                    confirmButtonColor: '#1b5e20'
                                }).then(() => {
                                    window.location.href = redirectTo;
                                });
                            } else {
                                this.errorMessage = result.message || 'Login gagal. Periksa kembali kredensial Anda.';
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Login Gagal',
                                    text: this.errorMessage,
                                    confirmButtonColor: '#1b5e20'
                                });
                            }
                        } catch (error) {
                            this.errorMessage = 'Terjadi kesalahan jaringan. Silakan coba lagi.';
                            Swal.fire({
                                icon: 'error',
                                title: 'Kesalahan Jaringan',
                                text: this.errorMessage,
                                confirmButtonColor: '#1b5e20'
                            });
                        } finally {
                            this.isLoading = false;
                        }
                    }
                }">
                <div class="w-full">
                    <h2 class="font-['Plus_Jakarta_Sans',sans-serif] font-bold text-[24px] md:text-[30px] leading-[36px] text-[#1d293d]">Masuk Akun</h2>
                    <p class="mt-2 font-['Plus_Jakarta_Sans',sans-serif] font-normal text-[16px] leading-[24px] text-[#62748e]">
                        Silakan masukkan kredensial perguruan tinggi Anda.
                    </p>

                    <form class="mt-8 space-y-6" @submit.prevent="login">
                        {{-- Email --}}
                        <div class="relative">
                            <div class="absolute left-4 top-1/2 -translate-y-1/2 pointer-events-none">
                                <i data-lucide="mail" class="w-5 h-5 text-[#90A1B9]"></i>
                            </div>
                            <input
                                type="email"
                                name="email"
                                x-model="email"
                                required
                                placeholder=" "
                                class="peer w-full bg-[#f8fafc] border border-[#e2e8f0] rounded-[20px] pl-12 pr-4 pt-6 pb-2 font-['Plus_Jakarta_Sans',sans-serif] font-normal text-[16px] text-[#1d293d] focus:outline-none focus:border-[#1b5e20] transition" />
                            <label class="absolute left-12 top-4 font-['Plus_Jakarta_Sans',sans-serif] font-medium text-[14px] text-[#62748e] transition-all peer-placeholder-shown:top-5 peer-focus:top-2 peer-focus:text-[12px] peer-[:not(:placeholder-shown)]:top-2 peer-[:not(:placeholder-shown)]:text-[12px] pointer-events-none">
                                Email Resmi Perguruan Tinggi / PIC
                            </label>
                        </div>

                        {{-- Password --}}
                        <div class="relative">
                            <div class="absolute left-4 top-1/2 -translate-y-1/2 pointer-events-none">
                                <i data-lucide="lock" class="w-5 h-5 text-[#90A1B9]"></i>
                            </div>
                            <input
                                :type="showPassword ? 'text' : 'password'"
                                name="password"
                                x-model="password"
                                required
                                placeholder=" "
                                class="peer w-full bg-[#f8fafc] border border-[#e2e8f0] rounded-[20px] pl-12 pr-12 pt-6 pb-2 font-['Plus_Jakarta_Sans',sans-serif] font-normal text-[16px] text-[#1d293d] focus:outline-none focus:border-[#1b5e20] transition" />
                            <label class="absolute left-12 top-4 font-['Plus_Jakarta_Sans',sans-serif] font-medium text-[14px] text-[#62748e] transition-all peer-placeholder-shown:top-5 peer-focus:top-2 peer-focus:text-[12px] peer-[:not(:placeholder-shown)]:top-2 peer-[:not(:placeholder-shown)]:text-[12px] pointer-events-none">
                                Kata Sandi
                            </label>
                            <button type="button" @click="showPassword = !showPassword"
                                class="absolute right-4 top-1/2 -translate-y-1/2 text-[#90A1B9] hover:text-[#45556c] transition-colors focus:outline-none">
                                <i x-show="!showPassword" data-lucide="eye-closed" class="w-5 h-5"></i>
                                <i x-show="showPassword" style="display: none;" data-lucide="eye" class="w-5 h-5"></i>
                            </button>
                        </div>

                        {{-- Forgot Password --}}
                        <div class="flex items-center justify-end">
                            <a href="/lupa-sandi" class="font-['Plus_Jakarta_Sans',sans-serif] font-semibold text-[14px] text-[#1b5e20] hover:underline">
                                Lupa sandi?
                            </a>
                        </div>

                        {{-- Submit --}}
                        <button
                            type="submit"
                            x-bind:disabled="isLoading"
                            class="w-full bg-[#1b5e20] text-white font-['Plus_Jakarta_Sans',sans-serif] font-bold text-[18px] leading-[28px] py-4 rounded-[20px] shadow-[0px_20px_25px_0px_rgba(27,94,32,0.2)] hover:bg-[#174d1a] transition flex items-center justify-center gap-2 disabled:opacity-50">
                            <span x-show="!isLoading">Masuk Sekarang</span>
                            <span x-show="isLoading" style="display: none;">Memproses...</span>
                        </button>
                    </form>

                    <p class="mt-6 text-center font-['Plus_Jakarta_Sans',sans-serif] font-normal text-[14px] text-[#45556c]">
                        Belum mendaftarkan Perguruan Tinggi?
                        <a href="{{ url('/daftar') }}" class="font-bold text-[#1b5e20] hover:underline">Daftar di sini</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
