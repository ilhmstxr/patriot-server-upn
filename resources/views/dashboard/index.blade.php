<x-layouts.dashboard>
    <x-slot:title>Data Profil</x-slot:title>

    <div class="bg-[#f5f5f5] min-h-full py-5 px-4 md:px-8"
         x-data="{
            isLoading: true,
            is_peserta_profile_edit_enabled: false,
            isEditMode: false,
            isSavingProfile: false,
            saveProfileError: '',
            saveProfileSuccess: '',

            profileData: {
                Penugasan: null,
                institusi: null,
                identitas: null,
                agamas: {}
            },

            {{-- editForm: snapshot data saat masuk edit mode --}}
            editForm: {
                nama_pic: '',
                jabatan_pic: '',
                no_hp_pic: '',
                email: '',
                visi: '',
                misi: '',
                jml_fakultas: '',
                jml_prodi: '',
                jml_dosen: '',
                jml_tendik: '',
                jml_mahasiswa: '',
                jml_ormawa: '',
                jml_ukm: '',
                agamas: {}
            },

            async init() {
                try {
                    const cacheKey = 'profile_data_cache';
                    const cachedData = localStorage.getItem(cacheKey);

                    if (cachedData) {
                        const data = JSON.parse(cachedData);
                        this.applyProfileData(data);
                        this.isLoading = false;
                        this.fetchData(false);
                        return;
                    }
                    await this.fetchData(true);
                } catch (e) { console.error(e); }
            },

            applyProfileData(data) {
                this.profileData.Penugasan = data.penugasan;
                this.profileData.institusi   = data.penugasan?.institusi;
                this.profileData.identitas   = data.penugasan?.identitas;
                this.is_peserta_profile_edit_enabled = data.is_peserta_profile_edit_enabled ?? false;

                const lockedStatuses = ['SUBMITTED', 'GRADED', 'PUBLISHED'];
                if (data.penugasan && lockedStatuses.includes(data.penugasan.status)) {
                    this.is_peserta_profile_edit_enabled = false;
                }

                this.profileData.agamas = {};
                if (data.penugasan?.agamas) {
                    data.penugasan.agamas.forEach(a => {
                        this.profileData.agamas[a.agama.toLowerCase()] = a.jumlah;
                    });
                }
            },

            async fetchData(showLoading = true) {
                if (showLoading) this.isLoading = true;
                try {
                    const res    = await fetch('/api/auth/me', {
                        headers: { 'Authorization': 'Bearer ' + localStorage.getItem('auth_token'), 'Accept': 'application/json' }
                    });
                    const result = await res.json();
                    if (res.ok && result.success) {
                        localStorage.setItem('profile_data_cache', JSON.stringify(result.data));
                        this.applyProfileData(result.data);
                    }
                } catch (e) { console.error(e); } finally { this.isLoading = false; }
            },

            enterEditMode() {
                const p  = this.profileData.Penugasan;
                const id = this.profileData.identitas;
                this.editForm = {
                    nama_pic:      p?.nama_pic      || '',
                    jabatan_pic:   p?.jabatan_pic   || '',
                    no_hp_pic:     p?.no_hp_pic     || '',
                    email:         p?.email_pic     || '',
                    visi:          id?.visi          || '',
                    misi:          id?.misi          || '',
                    jml_fakultas:  id?.jml_fakultas  || '',
                    jml_prodi:     id?.jml_prodi     || '',
                    jml_dosen:     id?.jml_dosen     || '',
                    jml_tendik:    id?.jml_tendik    || '',
                    jml_mahasiswa: id?.jml_mahasiswa || '',
                    jml_ormawa:    id?.jml_ormawa    || '',
                    jml_ukm:       id?.jml_ukm       || '',
                    agamas: {
                        'islam':           this.profileData.agamas['islam']           || '',
                        'kristen':         this.profileData.agamas['kristen']         || '',
                        'katolik':         this.profileData.agamas['katolik']         || '',
                        'hindu':           this.profileData.agamas['hindu']           || '',
                        'buddha':          this.profileData.agamas['buddha']          || '',
                        'konghucu':        this.profileData.agamas['konghucu']        || ''
                    }
                };
                this.saveProfileError   = '';
                this.saveProfileSuccess = '';
                this.isEditMode = true;
            },

            cancelEditMode() {
                this.isEditMode       = false;
                this.saveProfileError = '';
            },

            async saveProfile() {
                if (!this.editForm.nama_pic || !this.editForm.no_hp_pic) {
                    this.saveProfileError = 'Nama PIC dan No HP wajib diisi.';
                    return;
                }
                if (this.editForm.email) {
                    const re = /@[a-z0-9.-]+\.ac\.id$/i;
                    if (!re.test(this.editForm.email)) {
                        this.saveProfileError = 'Email harus menggunakan domain institusi resmi (.ac.id).';
                        return;
                    }
                }
                this.isSavingProfile  = true;
                this.saveProfileError = '';

                try {
                    const res    = await fetch('/api/auth/profile', {
                        method: 'PUT',
                        headers: {
                            'Authorization': 'Bearer ' + localStorage.getItem('auth_token'),
                            'Content-Type':  'application/json',
                            'Accept':        'application/json'
                        },
                        body: JSON.stringify(this.editForm)
                    });
                    const result = await res.json();

                    if (res.ok && result.success) {
                        {{-- Update local reactive data immediately --}}
                        if (this.profileData.Penugasan) {
                            this.profileData.Penugasan.nama_pic    = this.editForm.nama_pic;
                            this.profileData.Penugasan.jabatan_pic = this.editForm.jabatan_pic;
                            this.profileData.Penugasan.no_hp_pic   = this.editForm.no_hp_pic;
                            if (this.editForm.email) {
                                this.profileData.Penugasan.email_pic = this.editForm.email;
                                {{-- Sync localStorage auth_user --}}
                                try {
                                    const u = JSON.parse(localStorage.getItem('auth_user') || '{}');
                                    u.email = this.editForm.email;
                                    localStorage.setItem('auth_user', JSON.stringify(u));
                                } catch (e) {}
                            }
                        }
                        if (this.profileData.identitas) {
                            this.profileData.identitas.visi          = this.editForm.visi;
                            this.profileData.identitas.misi          = this.editForm.misi;
                            this.profileData.identitas.jml_fakultas  = this.editForm.jml_fakultas;
                            this.profileData.identitas.jml_prodi     = this.editForm.jml_prodi;
                            this.profileData.identitas.jml_dosen     = this.editForm.jml_dosen;
                            this.profileData.identitas.jml_tendik    = this.editForm.jml_tendik;
                            this.profileData.identitas.jml_mahasiswa = this.editForm.jml_mahasiswa;
                            this.profileData.identitas.jml_ormawa    = this.editForm.jml_ormawa;
                            this.profileData.identitas.jml_ukm       = this.editForm.jml_ukm;
                        }
                        this.profileData.agamas = { ...this.editForm.agamas };
                        localStorage.removeItem('profile_data_cache');
                        // Rubrik ikut direset: angka profil (jml_mahasiswa, dll) dipakai
                        // sebagai input hitungan rubrik, jadi cache rubrik lama harus dibuang.
                        localStorage.removeItem('rubrik_questions_cache');
                        this.isEditMode = false;
                    } else {
                        this.saveProfileError = result.message || 'Gagal menyimpan perubahan.';
                    }
                } catch (e) {
                    this.saveProfileError = 'Terjadi kesalahan jaringan.';
                } finally {
                    this.isSavingProfile = false;
                }
            }
         }"
         x-effect="$nextTick(() => { if(typeof lucide !== 'undefined') lucide.createIcons(); })">

        <div class="max-w-[860px] mx-auto space-y-5" x-show="!isLoading" x-cloak>

            {{-- ✏️ Periode Bar + Tombol Edit / Simpan --}}
            <x-dashboard.profil.periode-bar />

            {{-- Error / Success inline --}}
            <div x-show="saveProfileError" style="display:none;"
                 class="bg-red-50 border border-red-200 rounded-lg px-4 py-3 text-[13px] text-red-600 font-medium flex items-center gap-2">
                <i data-lucide="alert-circle" class="w-4 h-4 shrink-0"></i>
                <span x-text="saveProfileError"></span>
            </div>

            {{-- Visi & Misi --}}
            <x-dashboard.profil.visi-misi />

            {{-- Data Institusi --}}
            <x-dashboard.profil.institusi />

            {{-- Data SDM --}}
            <x-dashboard.profil.sdm />

            {{-- Data Mahasiswa --}}
            <x-dashboard.profil.mahasiswa />

            {{-- Demografi Agama --}}
            <x-dashboard.profil.demografi />

            {{-- Data PIC --}}
            <x-dashboard.profil.pic />

        </div>

        {{-- Loading State --}}
        <div class="max-w-[860px] mx-auto" x-show="isLoading">
            <x-dashboard.loading
                title="Memuat Data Profil..."
                caption="Mohon tunggu sebentar, sistem sedang menyiapkan data profil Anda." />
        </div>

    </div>
</x-layouts.dashboard>
