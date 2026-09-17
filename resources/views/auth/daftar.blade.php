<x-layouts.app :hideNav="true" :hideFooter="true">
    {{-- Guard: jika sudah login, redirect sesuai status --}}
    <script>
        (function() {
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
                const penugasanStatus = localStorage.getItem('penugasan_status') || localStorage.getItem('penugasan_status') || 'UNVERIFIED';
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
    <div class="min-h-screen flex relative" x-data="{ 
        agree: false, 
        isFormValid: false,
        isLoading: false,
        showPassword: false,
        errorMessage: '',
        successMessage: '',
        emailDomainError: '',
        institusiCheckMessage: '',
        institusiCheckExists: false,
        institusiCheckTimer: null,
        passwordChecks: { hasUpper: false, hasLower: false, hasNumber: false },
        get passwordValid() { return this.passwordChecks.hasUpper && this.passwordChecks.hasLower && this.passwordChecks.hasNumber; },
        formData: {
            nama_pt: '',
            jenis_pt: '',
            nama_pic: '',
            jabatan_pic: '',
            no_hp_pic: '',
            email: '',
            password: '',
            password_confirmation: ''
        },
        validateEmailDomain() {
            const re = /@[a-z0-9.-]+\.ac\.id$/i;
            if (this.formData.email && !re.test(this.formData.email)) {
                this.emailDomainError = 'Email harus menggunakan domain institusi resmi (.ac.id).';
            } else {
                this.emailDomainError = '';
            }
        },
        validatePassword() {
            const p = this.formData.password || '';
            this.passwordChecks.hasUpper  = /[A-Z]/.test(p);
            this.passwordChecks.hasLower  = /[a-z]/.test(p);
            this.passwordChecks.hasNumber = /[0-9]/.test(p);
        },
        scheduleInstitusiCheck() {
            this.validateEmailDomain();
            clearTimeout(this.institusiCheckTimer);
            this.institusiCheckTimer = setTimeout(() => this.checkInstitusi(), 600);
        },
        async checkInstitusi() {
            const nama = (this.formData.nama_pt || '').trim();
            const email = (this.formData.email || '').trim();
            if (!nama && !email) {
                this.institusiCheckMessage = '';
                this.institusiCheckExists = false;
                return;
            }
            try {
                const params = new URLSearchParams({ nama, email });
                const res = await fetch('/api/auth/check-institusi?' + params.toString(), {
                    headers: { 'Accept': 'application/json' }
                });
                const result = await res.json();
                if (res.ok && result.success) {
                    this.institusiCheckExists = !!result.data.exists;
                    this.institusiCheckMessage = this.institusiCheckExists ? result.data.message : '';
                }
            } catch (e) { /* silent */ }
        },
        validateAndScroll() {
            const fields = [
                { key: 'nama_pt', label: 'Nama Perguruan Tinggi', name: 'nama_institusi' },
                { key: 'jenis_pt', label: 'Jenis Perguruan Tinggi', name: 'jenis_institusi' },
                { key: 'nama_pic', label: 'Nama PIC Peserta', name: 'nama_pic' },
                { key: 'jabatan_pic', label: 'Jabatan PIC Peserta', name: 'jabatan_pic' },
                { key: 'no_hp_pic', label: 'No HP/WhatsApp PIC', name: 'no_hp' },
                { key: 'email', label: 'Email Institusi', name: 'email' },
                { key: 'password', label: 'Password', name: 'password' },
            ];
            for (const field of fields) {
                if (!this.formData[field.key] || this.formData[field.key].trim() === '') {
                    this.errorMessage = 'Mohon isi field ' + field.label;
                    const el = this.$refs.form.querySelector(`[name='${field.name}']`);
                    if (el) {
                        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        el.classList.add('!border-red-400', 'ring-2', 'ring-red-200');
                        setTimeout(() => el.classList.remove('!border-red-400', 'ring-2', 'ring-red-200'), 3000);
                    }
                    return false;
                }
            }
            if (!this.agree) {
                this.errorMessage = 'Mohon centang persetujuan terlebih dahulu.';
                return false;
            }
            return true;
        },
        async register() {
            if (!this.validateAndScroll()) return;

            this.validateEmailDomain();
            if (this.emailDomainError) {
                this.errorMessage = this.emailDomainError;
                return;
            }
            if (!this.passwordValid) {
                this.errorMessage = 'Password harus mengandung huruf besar, huruf kecil, dan angka.';
                return;
            }
            if (this.institusiCheckExists) {
                this.errorMessage = this.institusiCheckMessage || 'Institusi atau email sudah terdaftar.';
                return;
            }

            // Bypass confirmasi password jika form tidak memiliki field tersebut
            this.formData.password_confirmation = this.formData.password;

            this.isLoading = true;
            this.errorMessage = '';
            this.successMessage = '';

            try {
                const response = await fetch('/api/auth/register', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify(this.formData)
                });

                const result = await response.json();

                if (response.ok && result.success) {
                    this.successMessage = result.message;

                    // Reset cache bersih menyeluruh sebelum menyimpan sesi akun baru
                    localStorage.clear();
                    sessionStorage.clear();

                    // Simpan token, user, dan status ke localStorage
                    if (result.data && result.data.token) {
                        localStorage.setItem('auth_token', result.data.token);
                    }
                    if (result.data && result.data.user) {
                        localStorage.setItem('auth_user', JSON.stringify(result.data.user));
                    }
                    localStorage.setItem('user_status', (result.data && result.data.user_status) || 'UNVERIFIED');
                    localStorage.setItem('penugasan_status', (result.data && result.data.penugasan_status) || 'UNVERIFIED');
                    if (result.data && result.data.token_expires_at) {
                        localStorage.setItem('token_expires_at', result.data.token_expires_at);
                    }

                    Swal.fire({
                        icon: 'success',
                        title: 'Registrasi Berhasil!',
                        text: result.message || 'Akun institusi Anda telah terdaftar. Silakan cek email untuk verifikasi.',
                        confirmButtonColor: '#1b5e20'
                    }).then(() => {
                        window.location.href = '/cek-email';
                    });
                } else {
                    if (result.errors) {
                        this.errorMessage = Object.values(result.errors)[0][0];
                    } else {
                        this.errorMessage = result.message || 'Registrasi gagal. Periksa kembali data Anda.';
                    }
                    Swal.fire({
                        icon: 'error',
                        title: 'Registrasi Gagal',
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
        {{-- Anchor: Kembali ke Halaman Utama — pojok kiri atas layar --}}
        <a href="{{ url('/') }}" class="fixed top-5 left-5 z-[50] inline-flex items-center gap-2 bg-white/15 hover:bg-white/25 backdrop-blur-sm border border-white/30 text-white px-4 py-2 rounded-full text-[13px] font-semibold transition-all duration-200 hover:shadow-lg">
            <i data-lucide="arrow-left" class="w-4 h-4"></i>
            Halaman Utama
        </a>

        {{-- Left Panel --}}
        <div class="hidden lg:flex w-[45%] relative sticky top-0 h-screen overflow-hidden items-center">
            <img src="{{ asset('assets/images/mhs.webp') }}" class="absolute inset-0 w-full h-full object-cover" alt="Background" />
            <div class="absolute inset-0 bg-black/30"></div>
            <div class="relative px-16 py-16 z-10 w-full">
                <div class="-mb-12 mt-4">
                    <img src="{{ asset('assets/images/b89aca8b9cc2d0494234bedd13382da054b48ab6.webp') }}" alt="Logo Patriot Metric" class="h-100 w-auto object-contain object-left" />
                </div>
                <h1 class="font-['Plus_Jakarta_Sans',sans-serif] font-bold text-[50px] leading-[45px] text-white max-w-[500px]">
                    Jadilah Bagian dari Perubahan
                </h1>
                <p class="mt-8 font-['Plus_Jakarta_Sans',sans-serif] font-normal text-[25px] leading-[29.25px] text-[rgba(255,255,255,0.8)] max-w-[500px]">
                    Dengan mendaftarkan Perguruan Tinggi Anda, Anda telah mengambil langkah nyata dalam membina karakter bela negara generasi penerus bangsa.
                </p>
            </div>
        </div>

        {{-- Right Panel --}}
        <div class="flex-1 flex items-start justify-center px-6 md:px-8 py-10 md:py-12 bg-white overflow-y-auto">
            <div class="w-full max-w-[576px]">
                <a href="{{ url('/masuk') }}" class="font-['Plus_Jakarta_Sans',sans-serif] font-semibold text-[14px] text-[#1B5E20] hover:underline">
                    Sudah punya akun? Masuk
                </a>

                <h2 class="mt-6 font-['Plus_Jakarta_Sans',sans-serif] font-bold text-[24px] md:text-[30px] leading-[36px] text-[#1d293d]">Daftarkan Perguruan Tinggi</h2>
                <p class="mt-2 font-['Plus_Jakarta_Sans',sans-serif] font-normal text-[14px] md:text-[16px] leading-[24px] text-[#62748e]">
                    Lengkapi data berikut untuk membuat akun perguruan tinggi.
                </p>

                <form class="mt-8 space-y-8" @submit.prevent="register" x-ref="form" @input="isFormValid = $refs.form.checkValidity()" @change="isFormValid = $refs.form.checkValidity()">
                    {{-- Data Institusi --}}
                    <div>
                        <div class="flex items-center gap-2 pb-3 border-b border-[#f1f5f9] mb-6">
                            <i data-lucide="building-2" class="w-5 h-5 text-[#1B5E20]"></i>
                            <span class="font-['Plus_Jakarta_Sans',sans-serif] font-bold text-[18px] text-[#1d293d]">Data Institusi</span>
                        </div>
                        <div class="space-y-4">
                            {{-- Field Nama Institusi --}}
                            <div class="relative">
                                <div class="absolute left-4 top-1/2 -translate-y-1/2 pointer-events-none">
                                    <i data-lucide="building-2" class="w-5 h-5 text-[#90A1B9]"></i>
                                </div>
                                <input name="nama_institusi" type="text" x-model="formData.nama_pt" @input.debounce.600ms="scheduleInstitusiCheck()" required placeholder=" " class="peer w-full bg-[#f8fafc] border border-[#e2e8f0] rounded-[20px] pl-12 pr-4 pt-6 pb-2 font-['Plus_Jakarta_Sans',sans-serif] font-normal text-[16px] text-[#1d293d] focus:outline-none focus:border-[#1b5e20] transition" />
                                <label class="absolute left-12 top-4 font-['Plus_Jakarta_Sans',sans-serif] font-medium text-[14px] text-[#62748e] transition-all peer-placeholder-shown:top-5 peer-focus:top-2 peer-focus:text-[12px] peer-[:not(:placeholder-shown)]:top-2 peer-[:not(:placeholder-shown)]:text-[12px] pointer-events-none">
                                    Nama Perguruan Tinggi
                                </label>
                            </div>
                            {{-- Inline warning institusi --}}
                            <p x-show="institusiCheckExists && institusiCheckMessage" style="display:none;" x-text="institusiCheckMessage" class="text-[12px] text-amber-600 font-medium mt-1 ml-2 flex items-center gap-1.5">
                            </p>

                            {{-- Field Jenis Institusi --}}
                            <div class="relative">
                                <div class="absolute left-4 top-1/2 -translate-y-1/2 pointer-events-none">
                                    <i data-lucide="building-2" class="w-5 h-5 text-[#90A1B9]"></i>
                                </div>
                                <select name="jenis_institusi" x-model="formData.jenis_pt" required class="peer w-full bg-[#f8fafc] border border-[#e2e8f0] rounded-[20px] pl-12 pr-4 pt-6 pb-2 font-['Plus_Jakarta_Sans',sans-serif] font-normal text-[16px] text-[#1d293d] focus:outline-none focus:border-[#1b5e20] transition appearance-none">
                                    <option value="" disabled selected hidden></option>
                                    <option value="PTN">PTN</option>
                                    <option value="PTS">PTS</option>
                                    <option value="PTK">PTK</option>
                                    <option value="PT Keagamaan">PT Keagamamaan</option>
                                </select>
                                <label class="absolute left-12 top-4 font-['Plus_Jakarta_Sans',sans-serif] font-medium transition-all pointer-events-none text-[14px] text-[#62748e] peer-valid:top-2 peer-valid:text-[12px] peer-invalid:top-5 peer-invalid:text-[14px] peer-focus:top-2 peer-focus:text-[12px]">
                                    Jenis Perguruan Tinggi
                                </label>
                            </div>
                        </div>
                    </div>

                    {{-- Data PIC --}}
                    <div>
                        <div class="flex items-center gap-2 pb-3 border-b border-[#f1f5f9] mb-6">
                            <i data-lucide="user" class="w-5 h-5 text-[#1B5E20]"></i>
                            <span class="font-['Plus_Jakarta_Sans',sans-serif] font-bold text-[18px] text-[#1d293d]">Data PIC</span>
                        </div>
                        <div class="space-y-4">
                            {{-- Field Nama PIC --}}
                            <div class="relative">
                                <div class="absolute left-4 top-1/2 -translate-y-1/2 pointer-events-none">
                                    <i data-lucide="user" class="w-5 h-5 text-[#90A1B9]"></i>
                                </div>
                                <input name="nama_pic" type="text" x-model="formData.nama_pic" required placeholder=" " class="peer w-full bg-[#f8fafc] border border-[#e2e8f0] rounded-[20px] pl-12 pr-4 pt-6 pb-2 font-['Plus_Jakarta_Sans',sans-serif] font-normal text-[16px] text-[#1d293d] focus:outline-none focus:border-[#1b5e20] transition" />
                                <label class="absolute left-12 top-4 font-['Plus_Jakarta_Sans',sans-serif] font-medium text-[14px] text-[#62748e] transition-all peer-placeholder-shown:top-5 peer-focus:top-2 peer-focus:text-[12px] peer-[:not(:placeholder-shown)]:top-2 peer-[:not(:placeholder-shown)]:text-[12px] pointer-events-none">
                                    Nama PIC Peserta
                                </label>
                            </div>

                            {{-- Field Jabatan PIC --}}
                            <div class="relative">
                                <div class="absolute left-4 top-1/2 -translate-y-1/2 pointer-events-none">
                                    <i data-lucide="briefcase" class="w-5 h-5 text-[#90A1B9]"></i>
                                </div>
                                <input name="jabatan_pic" type="text" x-model="formData.jabatan_pic" required placeholder=" " class="peer w-full bg-[#f8fafc] border border-[#e2e8f0] rounded-[20px] pl-12 pr-4 pt-6 pb-2 font-['Plus_Jakarta_Sans',sans-serif] font-normal text-[16px] text-[#1d293d] focus:outline-none focus:border-[#1b5e20] transition" />
                                <label class="absolute left-12 top-4 font-['Plus_Jakarta_Sans',sans-serif] font-medium text-[14px] text-[#62748e] transition-all peer-placeholder-shown:top-5 peer-focus:top-2 peer-focus:text-[12px] peer-[:not(:placeholder-shown)]:top-2 peer-[:not(:placeholder-shown)]:text-[12px] pointer-events-none">
                                    Jabatan PIC Peserta
                                </label>
                            </div>

                            {{-- Field No HP --}}
                            <div class="relative">
                                <div class="absolute left-4 top-1/2 -translate-y-1/2 pointer-events-none">
                                    <i data-lucide="phone" class="w-5 h-5 text-[#90A1B9]"></i>
                                </div>
                                <input name="no_hp" type="tel" x-model="formData.no_hp_pic" required placeholder=" " class="peer w-full bg-[#f8fafc] border border-[#e2e8f0] rounded-[20px] pl-12 pr-4 pt-6 pb-2 font-['Plus_Jakarta_Sans',sans-serif] font-normal text-[16px] text-[#1d293d] focus:outline-none focus:border-[#1b5e20] transition" />
                                <label class="absolute left-12 top-4 font-['Plus_Jakarta_Sans',sans-serif] font-medium text-[14px] text-[#62748e] transition-all peer-placeholder-shown:top-5 peer-focus:top-2 peer-focus:text-[12px] peer-[:not(:placeholder-shown)]:top-2 peer-[:not(:placeholder-shown)]:text-[12px] pointer-events-none">
                                    No HP/ WhatsApp Aktif PIC Peserta
                                </label>
                            </div>

                            {{-- Field Email --}}
                            <div class="relative">
                                <div class="absolute left-4 top-1/2 -translate-y-1/2 pointer-events-none">
                                    <i data-lucide="mail" class="w-5 h-5 text-[#90A1B9]"></i>
                                </div>
                                <input name="email" type="email" x-model="formData.email"
                                    @input="scheduleInstitusiCheck()"
                                    pattern="^[^@\s]+@[A-Za-z0-9.-]+\.ac\.id$"
                                    title="Gunakan email berdomain .ac.id"
                                    required placeholder="dosen@upnjatim.ac.id" class="peer w-full bg-[#f8fafc] border border-[#e2e8f0] rounded-[20px] pl-12 pr-4 pt-6 pb-2 font-['Plus_Jakarta_Sans',sans-serif] font-normal text-[16px] text-[#1d293d] focus:outline-none focus:border-[#1b5e20] placeholder-hide-on-blur transition" />
                                <label class="absolute left-12 top-4 font-['Plus_Jakarta_Sans',sans-serif] font-medium text-[14px] text-[#62748e] transition-all peer-placeholder-shown:top-5 peer-focus:top-2 peer-focus:text-[12px] peer-[:not(:placeholder-shown)]:top-2 peer-[:not(:placeholder-shown)]:text-[12px] pointer-events-none">
                                    Email PIC (institusi .ac.id)
                                </label>
                            </div>
                            {{-- Inline warning email --}}
                            <p x-show="emailDomainError" style="display:none;" x-text="emailDomainError" class="text-[12px] text-red-500 font-medium mt-1 ml-2"></p>

                            {{-- Field Password --}}
                            <div class="relative">
                                <div class="absolute left-4 top-1/2 -translate-y-1/2 pointer-events-none">
                                    <i data-lucide="lock" class="w-5 h-5 text-[#90A1B9]"></i>
                                </div>
                                <input name="password" :type="showPassword ? 'text' : 'password'" x-model="formData.password" @input="validatePassword()" required placeholder=" " class="peer w-full bg-[#f8fafc] border border-[#e2e8f0] rounded-[20px] pl-12 pr-12 pt-6 pb-2 font-['Plus_Jakarta_Sans',sans-serif] font-normal text-[16px] text-[#1d293d] focus:outline-none focus:border-[#1b5e20] transition" />
                                <label class="absolute left-12 top-4 font-['Plus_Jakarta_Sans',sans-serif] font-medium text-[14px] text-[#62748e] transition-all peer-placeholder-shown:top-5 peer-focus:top-2 peer-focus:text-[12px] peer-[:not(:placeholder-shown)]:top-2 peer-[:not(:placeholder-shown)]:text-[12px] pointer-events-none">
                                    Password
                                </label>
                                <button type="button" @click="showPassword = !showPassword"
                                    class="absolute right-4 top-1/2 -translate-y-1/2 text-[#90A1B9] hover:text-[#45556c] transition-colors focus:outline-none">
                                    <i x-show="!showPassword" data-lucide="eye-closed" class="w-5 h-5"></i>
                                    <i x-show="showPassword" style="display: none;" data-lucide="eye" class="w-5 h-5"></i>
                                </button>
                            </div>
                            {{-- Password Strength Checklist --}}
                            <div x-show="formData.password.length > 0" style="display:none;" class="mt-2 space-y-1.5 px-1">
                                <p class="text-[11px] font-bold text-[#62748e] uppercase tracking-wider mb-1">Password harus mengandung:</p>
                                <div class="flex items-center gap-2">
                                    <span class="w-4 h-4 rounded-full flex items-center justify-center shrink-0"
                                        :class="passwordChecks.hasUpper ? 'bg-[#1b5e20]' : 'bg-[#e2e8f0]'">
                                        <svg x-show="passwordChecks.hasUpper" class="w-2.5 h-2.5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                                        </svg>
                                    </span>
                                    <span class="text-[12px] font-medium" :class="passwordChecks.hasUpper ? 'text-[#1b5e20]' : 'text-[#94a3b8]'">Huruf besar (A-Z)</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="w-4 h-4 rounded-full flex items-center justify-center shrink-0"
                                        :class="passwordChecks.hasLower ? 'bg-[#1b5e20]' : 'bg-[#e2e8f0]'">
                                        <svg x-show="passwordChecks.hasLower" class="w-2.5 h-2.5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                                        </svg>
                                    </span>
                                    <span class="text-[12px] font-medium" :class="passwordChecks.hasLower ? 'text-[#1b5e20]' : 'text-[#94a3b8]'">Huruf kecil (a-z)</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="w-4 h-4 rounded-full flex items-center justify-center shrink-0"
                                        :class="passwordChecks.hasNumber ? 'bg-[#1b5e20]' : 'bg-[#e2e8f0]'">
                                        <svg x-show="passwordChecks.hasNumber" class="w-2.5 h-2.5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                                        </svg>
                                    </span>
                                    <span class="text-[12px] font-medium" :class="passwordChecks.hasNumber ? 'text-[#1b5e20]' : 'text-[#94a3b8]'">Angka (0-9)</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Agreement --}}
                    <div class="bg-[#f8fafc] border border-[#e2e8f0] rounded-2xl p-5 flex gap-4 items-start">
                        <input
                            type="checkbox"
                            x-model="agree"
                            required
                            class="size-5 mt-0.5 accent-[#1b5e20] shrink-0" />
                        <label class="font-['Plus_Jakarta_Sans',sans-serif] text-[14px] leading-[22px] text-[#45556c] font-medium">
                            Ya, data yang saya isi sudah benar & lengkap serta sudah membaca <a href="{{ asset('assets/documents/PEDOMAN-UPN PATRIOT METRIC 2026 v2.pdf') }}" target="_blank" class="font-bold text-[#1b5e20] hover:underline">panduan Patriot Metric</a>.
                        </label>
                    </div>

                    {{-- Submit --}}
                    <button
                        type="submit"
                        class="w-full bg-[#1b5e20] text-white font-['Plus_Jakarta_Sans',sans-serif] font-bold text-[18px] leading-[28px] py-4 rounded-[20px] shadow-[0px_20px_25px_0px_rgba(27,94,32,0.2)] hover:bg-[#174d1a] transition flex items-center justify-center gap-2 disabled:opacity-50"
                        x-bind:disabled="!agree || !isFormValid || isLoading || institusiCheckExists || emailDomainError !== '' || !passwordValid">
                        <span x-show="!isLoading">Kirim</span>
                        <span x-show="isLoading" style="display: none;">Memproses...</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-layouts.app>